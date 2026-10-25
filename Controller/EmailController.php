<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\MauticInboxBundle\Application\Email\EmailMessages;
use MauticPlugin\MauticInboxBundle\Application\Forms\FormLinks;
use MauticPlugin\MauticInboxBundle\Application\InboxException;
use MauticPlugin\MauticInboxBundle\Security\ConversationAccess;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * O canal de e-mail no Inbox web: anexos, original e a lista de formularios do composer.
 * As rotas ficam em /s/inbox/api, atras da sessao do Mautic, com a mesma checagem de acesso a
 * conversa que o historico usa.
 */
final class EmailController extends CommonController
{
    public function attachment(int $messageId, int $index, CorePermissions $permissions, EmailMessages $messages, ConversationAccess $access): Response
    {
        $this->grant($permissions, 'view');
        $found = $messages->find($messageId);
        if (null === $found || !$this->visible($access, $found['state'])) {
            return new Response('Arquivo indisponível.', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Type' => 'text/plain; charset=UTF-8']);
        }

        return $messages->attachment($found['message'], $index);
    }

    public function original(int $messageId, CorePermissions $permissions, EmailMessages $messages, ConversationAccess $access): JsonResponse
    {
        $this->grant($permissions, 'view');
        $found = $messages->find($messageId);
        $html = null !== $found && $this->visible($access, $found['state']) ? $messages->original($found['message'], false) : null;
        if (null === $html) {
            return $this->respond(['error' => $this->translator->trans('mautic.inbox.ui.email_original_unavailable')], Response::HTTP_NOT_FOUND);
        }

        return $this->respond(['html' => $html]);
    }

    public function forms(CorePermissions $permissions, FormLinks $forms): JsonResponse
    {
        $this->grant($permissions, 'create');

        return $this->respond($forms->published());
    }

    private function visible(ConversationAccess $access, \MauticPlugin\MauticInboxBundle\Entity\ConversationState $state): bool
    {
        try {
            $access->assertView($state);

            return true;
        } catch (InboxException) {
            return false;
        }
    }

    private function grant(CorePermissions $permissions, string $level): void
    {
        $metaLevel = 'create' === $level ? 'create' : 'view';
        if (!$permissions->isGranted(['inbox:conversations:'.$level, 'meta:messages:'.$metaLevel])) { throw $this->createAccessDeniedException(); }
    }

    /** @param array<mixed> $data */
    private function respond(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, $headers + ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
