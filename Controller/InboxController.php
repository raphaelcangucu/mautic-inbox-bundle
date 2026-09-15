<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
use MauticPlugin\MauticInboxBundle\Entity\OutboundRequest;
use MauticPlugin\MauticInboxBundle\Entity\OutboundRequestRepository;
use MauticPlugin\MauticMetaBundle\Application\Conversation\ConversationManager;
use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class InboxController extends CommonController
{
    public function index(CorePermissions $permissions, UserHelper $users, InboxQuery $query, ?int $stateId = null): Response
    {
        $this->grant($permissions, 'view');
        $user = $this->user($users);
        $stateId = null !== $stateId && $stateId > 0 ? $stateId : null;
        $route = null !== $stateId
            ? $this->generateUrl('mautic_inbox_conversation', ['stateId' => $stateId])
            : $this->generateUrl('mautic_inbox_index');

        return $this->delegateView([
            'contentTemplate' => '@MauticInbox/Inbox/index.html.twig',
            'passthroughVars' => ['mauticContent' => 'inbox', 'route' => $route],
            'viewParameters' => [
                'currentUserId' => $user->getId(),
                'initialStateId' => $stateId,
                'users' => $query->users(),
                'cannedResponses' => $query->cannedResponses(),
                'automationRules' => $query->automationRules(),
                'channelNotices' => $query->channelNotices(),
                'canManageCannedResponses' => $permissions->isGranted('inbox:templates:edit'),
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

    public function media(int $messageId, CorePermissions $permissions, EntityManagerInterface $entityManager, MetaGraphClientInterface $graph): Response
    {
        $this->grant($permissions, 'view');
        $message = $entityManager->find(MetaMessage::class, $messageId);
        if (!$message instanceof MetaMessage || 'whatsapp' !== $message->getChannel() || 'inbound' !== $message->getDirection()) {
            return new Response('Arquivo indisponível.', Response::HTTP_NOT_FOUND);
        }
        $type = $message->getMessageType();
        if (!in_array($type, ['image', 'audio', 'video', 'document', 'sticker'], true)) {
            return new Response('Arquivo indisponível.', Response::HTTP_NOT_FOUND);
        }
        $payload = $message->getPayload();
        $content = is_array($payload['message'] ?? null) ? $payload['message'] : $payload;
        $media = is_array($content[$type] ?? null) ? $content[$type] : [];
        $mediaId = trim((string) ($media['id'] ?? ''));
        if (1 !== preg_match('/^[0-9]{5,40}$/', $mediaId)) {
            return new Response('Arquivo indisponível.', Response::HTTP_NOT_FOUND);
        }

        try {
            $download = $graph->downloadWhatsAppMedia($message->getAsset()->getConnection(), $mediaId);
        } catch (\Throwable) {
            return new Response('Arquivo indisponível.', Response::HTTP_NOT_FOUND, ['Cache-Control' => 'private, no-store']);
        }

        $mimeType = $this->safeMediaMimeType($type, (string) $download['mimeType']);
        $extension = $this->mediaExtension($mimeType);
        $providedName = trim((string) ($media['filename'] ?? ''));
        $fileName = '' !== $providedName ? basename($providedName) : $type.'.'.$extension;
        $inline = in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'video/mp4', 'video/3gpp', 'audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/amr', 'audio/ogg', 'application/pdf'], true);
        $response = new Response((string) $download['contents'], Response::HTTP_OK, [
            'Content-Type' => $mimeType,
            'Content-Length' => (string) $download['fileSize'],
            'Content-Disposition' => HeaderUtils::makeDisposition($inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT, $fileName, 'media.'.$extension),
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setEtag(hash('sha256', (string) $download['contents']));

        return $response;
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
                if (!$target instanceof User) { throw new InboxException('mautic.inbox.ui.person_not_found_603a16'); }
                $permission = $permissions->getPermissionObject('inbox');
                $metaPermission = $permissions->getPermissionObject('meta');
                if (!$target->isAdmin() && (!$permission->isGranted($target->getActivePermissions()['inbox'] ?? [], 'conversations', 'view') || !$metaPermission->isGranted($target->getActivePermissions()['meta'] ?? [], 'messages', 'view'))) {
                    throw new InboxException('mautic.inbox.ui.this_person_does_not_have_access_to_the_support_inbox_b3f445');
                }
            }
            $until = null;
            if (isset($payload['until']) && '' !== $payload['until']) {
                try { $until = new \DateTimeImmutable((string) $payload['until']); } catch (\Throwable) { throw new InboxException('mautic.inbox.ui.invalid_snooze_date_13f58e'); }
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
            $outbound = $actions->reply($this->requireState($states, $stateId), $this->user($users), (string) ($payload['body'] ?? ''), (string) ($payload['request_id'] ?? ''), isset($payload['template_id']) ? ['id' => (int) $payload['template_id'], 'variables' => $payload['variables'] ?? []] : null);
            return ['request_id' => $outbound->getRequestId(), 'status' => $outbound->getStatus()];
        }, Response::HTTP_ACCEPTED);
    }

    public function retry(int $outboundId, Request $request, CorePermissions $permissions, UserHelper $users, OutboundRequestRepository $requests, ConversationActions $actions): JsonResponse
    {
        $this->grantMutation($request, $permissions, 'create');

        return $this->respond(function () use ($outboundId, $request, $users, $requests, $actions): array {
            $failedRequest = $requests->find($outboundId);
            if (!$failedRequest instanceof OutboundRequest) {
                throw new InboxException('mautic.inbox.ui.retry_source_not_found', Response::HTTP_NOT_FOUND);
            }
            $payload = $this->payload($request);
            $outbound = $actions->retry($failedRequest, $this->user($users), (string) ($payload['request_id'] ?? ''));

            return ['request_id' => $outbound->getRequestId(), 'status' => $outbound->getStatus()];
        }, Response::HTTP_ACCEPTED);
    }

    public function templates(int $stateId, CorePermissions $permissions, ConversationStateRepository $states, \MauticPlugin\MauticInboxBundle\Application\WhatsAppTemplates $templates): JsonResponse
    {
        $this->grant($permissions, 'create');
        return $this->respond(function () use ($templates, $states, $stateId): array {
            $state = $this->requireState($states, $stateId); $reason = $templates->blockedReason($state);
            return ['items' => $templates->catalog($state), 'blocked_reason' => $reason ? $this->translator->trans($reason) : null];
        });
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
        $this->grantCannedMutation($request, $permissions);

        return $this->respond(function () use ($request, $users, $responses, $entityManager, $query): array {
            $this->saveCannedResponse(new CannedResponse(), $this->payload($request), $this->user($users), $responses, $entityManager);

            return ['items' => $query->cannedResponses()];
        }, Response::HTTP_CREATED);
    }

    public function updateCanned(int $responseId, Request $request, CorePermissions $permissions, UserHelper $users, CannedResponseRepository $responses, EntityManagerInterface $entityManager, InboxQuery $query): JsonResponse
    {
        $this->grantCannedMutation($request, $permissions);

        return $this->respond(function () use ($responseId, $request, $users, $responses, $entityManager, $query): array {
            $response = $responses->find($responseId);
            if (!$response instanceof CannedResponse || !$response->isEnabled()) {
                throw new InboxException('mautic.inbox.ui.canned_response_not_found_e4a0e0', Response::HTTP_NOT_FOUND);
            }
            $this->saveCannedResponse($response, $this->payload($request), $this->user($users), $responses, $entityManager);

            return ['items' => $query->cannedResponses()];
        });
    }

    public function deleteCanned(int $responseId, Request $request, CorePermissions $permissions, CannedResponseRepository $responses, EntityManagerInterface $entityManager, InboxQuery $query): JsonResponse
    {
        $this->grantCannedMutation($request, $permissions);

        return $this->respond(function () use ($responseId, $responses, $entityManager, $query): array {
            $response = $responses->find($responseId);
            if (!$response instanceof CannedResponse || !$response->isEnabled()) {
                throw new InboxException('mautic.inbox.ui.canned_response_not_found_e4a0e0', Response::HTTP_NOT_FOUND);
            }
            $response->setEnabled(false);
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
    private function safeMediaMimeType(string $type, string $mimeType): string
    {
        $mimeType = strtolower(trim(explode(';', $mimeType, 2)[0]));
        $allowed = [
            'image' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
            'sticker' => ['image/webp', 'image/png'],
            'video' => ['video/mp4', 'video/3gpp'],
            'audio' => ['audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/amr', 'audio/ogg'],
            'document' => ['application/pdf', 'text/plain', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        ];

        return in_array($mimeType, $allowed[$type] ?? [], true) ? $mimeType : 'application/octet-stream';
    }
    private function mediaExtension(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
            'video/mp4', 'audio/mp4' => 'mp4', 'video/3gpp' => '3gp', 'audio/aac' => 'aac', 'audio/mpeg' => 'mp3', 'audio/amr' => 'amr', 'audio/ogg' => 'ogg',
            'application/pdf' => 'pdf', 'text/plain' => 'txt', 'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx', 'application/vnd.ms-excel' => 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            default => 'bin',
        };
    }
    private function grantMutation(Request $request, CorePermissions $permissions, string $level): void
    {
        $this->grant($permissions, $level);
        if (!$this->isCsrfTokenValid('mautic_inbox', $request->headers->get('X-CSRF-Token', ''))) { throw $this->createAccessDeniedException(); }
    }
    private function grantCannedMutation(Request $request, CorePermissions $permissions): void
    {
        if (!$permissions->isGranted('inbox:templates:edit') || !$this->isCsrfTokenValid('mautic_inbox', $request->headers->get('X-CSRF-Token', ''))) {
            throw $this->createAccessDeniedException();
        }
    }
    /** @param array<string,mixed> $payload */
    private function saveCannedResponse(CannedResponse $response, array $payload, User $actor, CannedResponseRepository $responses, EntityManagerInterface $entityManager): CannedResponse
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));
        if ('' === $name || mb_strlen($name) > 100 || '' === $body || mb_strlen($body) > 4000) {
            throw new InboxException('mautic.inbox.ui.enter_a_name_and_text_of_up_to_4_000_characters_2f496b');
        }
        $existing = $responses->findOneBy(['name' => $name]);
        if ($existing instanceof CannedResponse && $existing->getId() !== $response->getId()) {
            if (null === $response->getId() && !$existing->isEnabled()) {
                $response = $existing;
            } else {
                throw new InboxException('mautic.inbox.settings.canned_duplicate', Response::HTTP_CONFLICT);
            }
        }
        $response->setName($name)->setBody($body)->setEnabled(true)->setCreatedBy($response->getCreatedBy() ?? $actor);
        $entityManager->persist($response);
        try {
            $entityManager->flush();
        } catch (UniqueConstraintViolationException) {
            throw new InboxException('mautic.inbox.settings.canned_duplicate', Response::HTTP_CONFLICT);
        }

        return $response;
    }
    /** @return array<string,mixed> */
    private function payload(Request $request): array
    {
        try { $payload = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR); } catch (\JsonException) { throw new InboxException('mautic.inbox.ui.invalid_request_43c865'); }
        if (!is_array($payload)) { throw new InboxException('mautic.inbox.ui.invalid_request_43c865'); }
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
        if (!$state instanceof ConversationState) { throw new InboxException('mautic.inbox.ui.conversation_not_found_61bc81', 404); }
        return $state;
    }
    /** @param callable():array<string,mixed> $callback */
    private function respond(callable $callback, int $status = 200): JsonResponse
    {
        try { return new JsonResponse($callback(), $status); } catch (InboxException $e) { return new JsonResponse(['error' => $this->translator->trans($e->getMessage())], $e->httpStatus); }
    }
}
