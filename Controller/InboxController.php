<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Application\ConversationActions;
use MauticPlugin\MauticInboxBundle\Application\InboxException;
use MauticPlugin\MauticInboxBundle\Application\InboxQuery;
use MauticPlugin\MauticInboxBundle\Entity\CannedResponse;
use MauticPlugin\MauticInboxBundle\Entity\CannedResponseRepository;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticInboxBundle\Entity\ConversationStateRepository;
use MauticPlugin\MauticMetaBundle\Application\Conversation\ConversationManager;
use Mautic\CoreBundle\Controller\CommonController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class InboxController extends CommonController
{
    public function index(CorePermissions $permissions, UserHelper $users, InboxQuery $query): Response
    {
        $this->grant($permissions, 'view');
        $user = $this->user($users);

        return $this->delegateView([
            'contentTemplate' => '@MauticInbox/Inbox/index.html.twig',
            'passthroughVars' => ['mauticContent' => 'inbox', 'route' => $this->generateUrl('mautic_inbox_index')],
            'viewParameters' => [
            'currentUserId' => $user->getId(),
            'users' => $query->users(),
            'cannedResponses' => $query->cannedResponses(),
            'automationRules' => $query->automationRules(),
            'channelNotices' => $query->channelNotices(),
            ],
        ]);
    }

    public function updatesStream(Request $request, CorePermissions $permissions, \MauticPlugin\MauticInboxBundle\Application\InboxUpdates $updates): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->grant($permissions, 'view');
        if ($request->hasSession()) { $request->getSession()->save(); }
        $previous = $request->headers->get('Last-Event-ID', '');
        $once = $request->query->getBoolean('once');
        return new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($updates, $previous, $once): void {
            $deadline = microtime(true) + 15;
            echo "retry: 3000\n\n";
            do {
                $token = $updates->token();
                if ($token !== $previous) {
                    echo "id: ".$token."\nevent: inbox\ndata: ".json_encode(['version' => $token])."\n\n";
                    $previous = $token;
                } else { echo ": heartbeat\n\n"; }
                if (ob_get_level() > 0) { @ob_flush(); }
                flush();
                if ($once || connection_aborted()) { break; }
                usleep(3000000);
            } while (microtime(true) < $deadline);
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'private, no-cache, no-store', 'X-Accel-Buffering' => 'no']);
    }

    public function conversations(Request $request, CorePermissions $permissions, UserHelper $users, InboxQuery $query): JsonResponse
    {
        $this->grant($permissions, 'view');
        return $this->respond(fn (): array => $query->conversations($this->user($users), $request->query->all()));
    }

    public function detail(int $stateId, CorePermissions $permissions, UserHelper $users, InboxQuery $query, ConversationStateRepository $states): JsonResponse
    {
        $this->grant($permissions, 'view');
        return $this->respond(fn (): array => $query->detail($this->requireState($states, $stateId), $this->user($users)));
    }

    public function timeline(int $stateId, Request $request, CorePermissions $permissions, InboxQuery $query, ConversationStateRepository $states): JsonResponse
    {
        $this->grant($permissions, 'view');
        return $this->respond(fn (): array => $query->timeline($this->requireState($states, $stateId), $request->query->getString('before') ?: null, $request->query->getInt('limit', 40)));
    }

    public function poll(Request $request, CorePermissions $permissions, UserHelper $users, InboxQuery $query, ConversationStateRepository $states): JsonResponse
    {
        $this->grant($permissions, 'view');
        return $this->respond(function () use ($request, $users, $query, $states): array {
            $selectedId = $request->query->getInt('state_id');
            return $query->poll($this->user($users), $request->query->getString('since'), $selectedId > 0 ? $this->requireState($states, $selectedId) : null, $request->query->has('notification_cursor') ? max(0, $request->query->getInt('notification_cursor')) : null);
        });
    }

    public function take(int $stateId, Request $request, CorePermissions $permissions, UserHelper $users, ConversationStateRepository $states, ConversationActions $actions, InboxQuery $query): JsonResponse
    {
        $this->grantMutation($request, $permissions, 'edit');
        return $this->respond(function () use ($stateId, $request, $users, $states, $actions, $query): array {
            $user = $this->user($users);
            $state = $actions->take($this->requireState($states, $stateId), $user, (int) $this->payload($request)['version']);
            return $query->detail($state, $user);
        });
    }

    public function state(int $stateId, Request $request, CorePermissions $permissions, UserHelper $users, ConversationStateRepository $states, ConversationActions $actions, InboxQuery $query, EntityManagerInterface $entityManager, ConversationManager $metaConversations): JsonResponse
    {
        $this->grantMutation($request, $permissions, 'edit');
        return $this->respond(function () use ($stateId, $request, $users, $states, $actions, $query, $entityManager, $metaConversations, $permissions): array {
            $payload = $this->payload($request);
            $actor = $this->user($users);
            $state = $this->requireState($states, $stateId);
            if ('read' === ($payload['action'] ?? null)) {
                $metaConversations->markRead($state->getConversation());
                return $query->detail($state, $actor);
            }
            $target = null;
            if (isset($payload['target_user_id'])) {
                $target = $entityManager->find(User::class, (int) $payload['target_user_id']);
                if (!$target instanceof User) { throw new InboxException('Pessoa não encontrada.'); }
                $permission = $permissions->getPermissionObject('inbox');
                $metaPermission = $permissions->getPermissionObject('meta');
                if (!$target->isAdmin() && (!$permission->isGranted($target->getActivePermissions()['inbox'] ?? [], 'conversations', 'view') || !$metaPermission->isGranted($target->getActivePermissions()['meta'] ?? [], 'messages', 'view'))) {
                    throw new InboxException('Essa pessoa não tem acesso ao Atendimento.');
                }
            }
            $until = null;
            if (isset($payload['until']) && '' !== $payload['until']) {
                try { $until = new \DateTimeImmutable((string) $payload['until']); } catch (\Throwable) { throw new InboxException('Data para adiar inválida.'); }
            }
            $state = $actions->transition($state, $actor, (int) ($payload['version'] ?? 0), (string) ($payload['action'] ?? ''), $target, $until);
            return $query->detail($state, $actor);
        });
    }

    public function reply(int $stateId, Request $request, CorePermissions $permissions, UserHelper $users, ConversationStateRepository $states, ConversationActions $actions): JsonResponse
    {
        $this->grantMutation($request, $permissions, 'create');
        return $this->respond(function () use ($stateId, $request, $users, $states, $actions): array {
            $payload = $this->payload($request);
            $outbound = $actions->reply($this->requireState($states, $stateId), $this->user($users), (string) ($payload['body'] ?? ''), (string) ($payload['request_id'] ?? ''));
            return ['request_id' => $outbound->getRequestId(), 'status' => $outbound->getStatus()];
        }, Response::HTTP_ACCEPTED);
    }

    public function note(int $stateId, Request $request, CorePermissions $permissions, UserHelper $users, ConversationStateRepository $states, ConversationActions $actions): JsonResponse
    {
        $this->grantMutation($request, $permissions, 'edit');
        return $this->respond(function () use ($stateId, $request, $users, $states, $actions): array {
            $note = $actions->note($this->requireState($states, $stateId), $this->user($users), (string) ($this->payload($request)['body'] ?? ''));
            return ['id' => $note->getId(), 'saved' => true];
        }, Response::HTTP_CREATED);
    }

    public function draft(int $stateId, Request $request, CorePermissions $permissions, UserHelper $users, ConversationStateRepository $states, ConversationActions $actions): JsonResponse
    {
        $this->grantMutation($request, $permissions, 'edit');
        return $this->respond(function () use ($stateId, $request, $users, $states, $actions): array {
            $payload = $this->payload($request);
            $draft = $actions->saveDraft($this->requireState($states, $stateId), $this->user($users), (string) ($payload['mode'] ?? ''), (string) ($payload['body'] ?? ''));
            return ['saved' => true, 'updated_at' => $draft->getDateModified()->format(DATE_ATOM)];
        });
    }

    public function canned(Request $request, CorePermissions $permissions, UserHelper $users, CannedResponseRepository $responses, EntityManagerInterface $entityManager, InboxQuery $query): JsonResponse
    {
        if (!$permissions->isGranted('inbox:templates:edit') || !$this->isCsrfTokenValid('mautic_inbox', $request->headers->get('X-CSRF-Token', ''))) {
            throw $this->createAccessDeniedException();
        }
        return $this->respond(function () use ($request, $users, $responses, $entityManager, $query): array {
            $payload = $this->payload($request);
            $response = isset($payload['id']) ? $responses->find((int) $payload['id']) : new CannedResponse();
            if (!$response instanceof CannedResponse) { throw new InboxException('Resposta pronta não encontrada.', 404); }
            $name = mb_substr(trim((string) ($payload['name'] ?? '')), 0, 100);
            $body = trim((string) ($payload['body'] ?? ''));
            if ('' === $name || '' === $body || mb_strlen($body) > 4000) { throw new InboxException('Informe nome e texto de até 4.000 caracteres.'); }
            $response->setName($name)->setBody($body)->setEnabled((bool) ($payload['enabled'] ?? true))->setCreatedBy($response->getCreatedBy() ?? $this->user($users));
            $entityManager->persist($response);
            $entityManager->flush();
            return ['items' => $query->cannedResponses()];
        });
    }

    private function grant(CorePermissions $permissions, string $level): void
    {
        $metaLevel = 'create' === $level ? 'create' : ('view' === $level ? 'view' : 'edit');
        if (!$permissions->isGranted(['inbox:conversations:'.$level, 'meta:messages:'.$metaLevel])) { throw $this->createAccessDeniedException(); }
    }
    private function grantMutation(Request $request, CorePermissions $permissions, string $level): void
    {
        $this->grant($permissions, $level);
        if (!$this->isCsrfTokenValid('mautic_inbox', $request->headers->get('X-CSRF-Token', ''))) { throw $this->createAccessDeniedException(); }
    }
    /** @return array<string,mixed> */
    private function payload(Request $request): array
    {
        try { $payload = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new InboxException('Solicitação inválida.'); }
        if (!is_array($payload)) { throw new InboxException('Solicitação inválida.'); }
        return $payload;
    }
    private function user(UserHelper $helper): User
    {
        $user = $helper->getUser(true);
        if (!$user instanceof User || null === $user->getId()) { throw $this->createAccessDeniedException(); }
        return $user;
    }
    private function requireState(ConversationStateRepository $states, int $id): ConversationState
    {
        $state = $states->find($id);
        if (!$state instanceof ConversationState) { throw new InboxException('Conversa não encontrada.', 404); }
        return $state;
    }
    /** @param callable():array<string,mixed> $callback */
    private function respond(callable $callback, int $status = 200): JsonResponse
    {
        try { return new JsonResponse($callback(), $status); } catch (InboxException $e) { return new JsonResponse(['error' => $e->getMessage()], $e->httpStatus); }
    }
}
