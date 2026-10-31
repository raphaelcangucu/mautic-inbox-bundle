<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Controller;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Application\AssetVersion;
use MauticPlugin\MauticInboxBundle\Application\ContactLinking;
use MauticPlugin\MauticInboxBundle\Application\ConversationActions;
use MauticPlugin\MauticInboxBundle\Application\InboxException;
use MauticPlugin\MauticInboxBundle\Application\InboxQuery;
use MauticPlugin\MauticInboxBundle\Application\Mobile\AudioStore;
use MauticPlugin\MauticInboxBundle\Application\Mobile\ModerationStore;
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
    private \MauticPlugin\MauticInboxBundle\Security\ConversationAccess $conversationAccess;
    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setConversationAccess(\MauticPlugin\MauticInboxBundle\Security\ConversationAccess $access): void { $this->conversationAccess=$access; }

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
                'assetVersion' => AssetVersion::current(),
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

    public function conversations(Request $request, CorePermissions $permissions, UserHelper $users, InboxQuery $query, ModerationStore $moderation): JsonResponse
    {
        $this->grant($permissions, 'view');

        return $this->respond(function () use ($request, $permissions, $users, $query, $moderation): array {
            $data = $query->conversations($this->user($users), $request->query->all());
            // A lista precisa do estado tanto quanto a conversa aberta: e nela que o operador ve
            // de uma vez o que ja foi marcado, sem abrir um por um.
            $data['items'] = array_map(fn (array $row): array => $this->withModeration($row, $moderation, $permissions), $data['items'] ?? []);

            return $data;
        });
    }

    public function detail(int $stateId, CorePermissions $permissions, UserHelper $users, InboxQuery $query, ConversationStateRepository $states, ModerationStore $moderation, \MauticPlugin\MauticInboxBundle\Application\ContactDoNotContact $dnc, AudioStore $audioStore): JsonResponse
    {
        $this->grant($permissions, 'view');

        return $this->respond(function () use ($stateId, $permissions, $users, $query, $states, $moderation, $dnc, $audioStore): array {
            $state = $this->requireState($states, $stateId);
            $row = $this->withModeration($query->detail($state, $this->user($users)), $moderation, $permissions);
            // Só na conversa aberta: o estado sai do contato ligado, e a lista não tem contato
            // em todas as linhas. Instalação sem o serviço devolve `editable: false` e a tela
            // esconde o controle, em vez de oferecer um que não grava.
            $row['do_not_contact'] = $dnc->state($state->getConversation()->getContact());
            $row['audio_available'] = $this->audioAvailable($state, $audioStore);

            return $row;
        });
    }

    /**
     * Liga e desliga o "não enviar e-mails" do contato desta conversa.
     *
     * Exige edição do contato, não só do atendimento: ver uma conversa nunca foi permissão para
     * silenciar quem está do outro lado.
     */
    public function doNotContact(int $stateId, Request $request, CorePermissions $permissions, UserHelper $users, ConversationStateRepository $states, InboxQuery $query, ModerationStore $moderation, \MauticPlugin\MauticInboxBundle\Application\ContactDoNotContact $dnc): JsonResponse
    {
        $this->grantMutation($request, $permissions, 'edit');

        return $this->respond(function () use ($stateId, $request, $permissions, $users, $states, $query, $moderation, $dnc): array {
            if (!$permissions->isGranted('lead:leads:editother') && !$permissions->isGranted('lead:leads:editown')) {
                throw new InboxException('mautic.inbox.ui.dnc_forbidden', 403);
            }
            $payload = $this->payload($request);
            if (!is_bool($payload['blocked'] ?? null)) { throw new InboxException('mautic.inbox.ui.dnc_invalid'); }
            $state = $this->requireState($states, $stateId);
            $contact = $state->getConversation()->getContact();
            if (null === $contact) { throw new InboxException('mautic.inbox.ui.contact_not_linked_yet_e9a529'); }
            $user = $this->user($users);
            $dnc->set($contact, $payload['blocked'], $user);
            $row = $this->withModeration($query->detail($state, $user), $moderation, $permissions);
            $row['do_not_contact'] = $dnc->state($contact);

            return $row;
        });
    }

    /**
     * O estado de moderação de um comentário, do jeito que o aplicativo já o recebe.
     *
     * A marcação mora fora do Mautic (ModerationStore), então ela não vem de InboxQuery junto
     * com o resto: quem monta a resposta é que a acrescenta. O aplicativo faz isto desde que
     * ganhou moderação; o web nunca tinha pedido, e por isso a tela não tinha como mostrar que
     * um comentário está oculto nem oferecer o contrário.
     *
     * `moderation_available` é separado de `moderation` de propósito: ver que um comentário foi
     * marcado como spam é leitura, mudar isso é edição, e são permissões diferentes.
     */
    private function withModeration(array $row, ModerationStore $moderation, CorePermissions $permissions): array
    {
        $moderatable = str_starts_with((string) ($row['recipient'] ?? ''), 'comment:') || 'webchat' === ($row['channel'] ?? '');
        $row['moderation_available'] = $moderatable && (bool) $permissions->isGranted(['inbox:conversations:edit', 'meta:messages:edit']);
        $row['moderation'] = $moderatable ? $moderation->flags($row) : ['spam' => false, 'hidden' => false, 'blockedAuthor' => false];

        return $row;
    }

    /**
     * Marcar spam, ocultar, bloquear o autor e desfazer — a mesma operação que o aplicativo faz.
     *
     * Ocultar é o único caso que sai daqui para a Meta: `hideInstagram` fala com a API e o
     * servidor só grava a marcação se a Meta confirmar. Por isso ele vem antes do `apply`, e não
     * depois: gravar primeiro deixaria a tela dizendo "oculto" sobre um comentário que continua
     * visível para todo mundo no post.
     */
    public function moderation(int $stateId, Request $request, CorePermissions $permissions, UserHelper $users, ConversationStateRepository $states, InboxQuery $query, ModerationStore $moderation, \MauticPlugin\MauticInboxBundle\Application\Mobile\PublicationContext $publications, EntityManagerInterface $entityManager): JsonResponse
    {
        $this->grantMutation($request, $permissions, 'edit');

        return $this->respond(function () use ($stateId, $request, $users, $states, $query, $moderation, $publications, $entityManager, $permissions): array {
            $payload = $this->payload($request);
            $user = $this->user($users);
            $state = $this->requireState($states, $stateId);
            if (!str_starts_with($state->getConversation()->getRecipient(), 'comment:') && 'webchat' !== $state->getConversation()->getChannel()) {
                throw new InboxException('mautic.inbox.ui.moderation_not_comment');
            }
            $entityManager->refresh($state);
            if ($state->getVersion() !== (int) ($payload['version'] ?? 0)) {
                throw new InboxException('mautic.inbox.ui.conversation_changed_refresh');
            }
            $action = (string) ($payload['action'] ?? '');
            if (in_array($action, ['hide', 'show'], true)) {
                $detail = $query->detail($state, $user);
                $publications->hideInstagram($state, $detail['origins'] ?? [], 'hide' === $action);
            }
            $moderation->apply($query->summary($state), $action, (int) $user->getId());

            return $this->withModeration($query->detail($state, $user), $moderation, $permissions);
        });
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
        $state=$entityManager->getRepository(ConversationState::class)->findOneBy(['conversation'=>$message->getConversation()]);
        if (!$state) return new Response('Arquivo indisponível.',404);
        $this->conversationAccess->assertView($state);
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

    public function state(int $stateId, Request $request, CorePermissions $permissions, UserHelper $users, ConversationStateRepository $states, ConversationActions $actions, InboxQuery $query, EntityManagerInterface $entityManager, ConversationManager $metaConversations, \MauticPlugin\MauticInboxBundle\Application\ChannelTransportRegistry $channelTransports): JsonResponse
    {
        $this->grantMutation($request, $permissions, 'edit');
        return $this->respond(function () use ($stateId, $request, $users, $states, $actions, $query, $entityManager, $metaConversations, $channelTransports, $permissions): array {
            $payload = $this->payload($request);
            $actor = $this->user($users);
            $state = $this->requireState($states, $stateId);
            $before=$query->detail($state,$this->user($users));
            if ('read' === ($payload['action'] ?? null)) {
                $transport = $channelTransports->for($state->getConversation());
                null === $transport ? $metaConversations->markRead($state->getConversation()) : $transport->markRead($state);
                return $query->afterTransition($state,$actor,$before);
            }
            $target = null;
            if (isset($payload['target_user_id'])) {
                $target = $entityManager->find(User::class, (int) $payload['target_user_id']);
                if (!$target instanceof User) { throw new InboxException('mautic.inbox.ui.person_not_found_603a16'); }
                $permission = $permissions->getPermissionObject('inbox');
                $metaPermission = $permissions->getPermissionObject('meta');
                if (!$this->conversationAccess->canViewInbox($target)) {
                    throw new InboxException('mautic.inbox.ui.this_person_does_not_have_access_to_the_support_inbox_b3f445');
                }
            }
            $until = null;
            if (isset($payload['until']) && '' !== $payload['until']) {
                try { $until = new \DateTimeImmutable((string) $payload['until']); } catch (\Throwable) { throw new InboxException('mautic.inbox.ui.invalid_snooze_date_13f58e'); }
            }
            $state = $actions->transition($state, $actor, (int) ($payload['version'] ?? 0), (string) ($payload['action'] ?? ''), $target, $until);
            return $query->afterTransition($state,$actor,$before);
        });
    }

    public function reply(int $stateId, Request $request, CorePermissions $permissions, UserHelper $users, ConversationStateRepository $states, ConversationActions $actions, InboxQuery $query, AudioStore $audioStore, ModerationStore $moderation): JsonResponse
    {
        $this->grantMutation($request, $permissions, 'create');
        return $this->respond(function () use ($stateId, $request, $users, $states, $actions, $query, $audioStore, $moderation, $permissions): array {
            $payload = $this->payload($request);
            $state = $this->requireState($states, $stateId);
            $user = $this->user($users);
            // O áudio chega pelo id que o upload devolveu, e o store confere que ele é desta
            // conversa, deste operador e deste envio. Mandar o id de outro envio não passa.
            $audio = null;
            if (isset($payload['audio_id'])) {
                if (!$this->audioAvailable($state, $audioStore) || !is_string($payload['audio_id'])) { throw new InboxException('mautic.inbox.ui.audio_unsupported'); }
                try { $audio = $audioStore->forReply($payload['audio_id'], (int) $state->getId(), (int) $user->getId(), (string) ($payload['request_id'] ?? '')); }
                catch (\DomainException) { throw new InboxException('mautic.inbox.ui.audio_not_yours'); }
            }
            $outbound = $actions->reply($state, $user, (string) ($payload['body'] ?? ''), (string) ($payload['request_id'] ?? ''), isset($payload['template_id']) ? ['id' => (int) $payload['template_id'], 'variables' => $payload['variables'] ?? []] : null, null, null, $audio, is_string($payload['subject'] ?? null) ? $payload['subject'] : null);

            // O envio devolve o que acabou de criar porque senao ele custa mais duas idas ao
            // servidor: uma para o historico mostrar a mensagem e outra para a lista nao ficar
            // velha. `request_id` e `status` continuam saindo iguais para quem ja os lia.
            //
            // O resumo passa pela moderacao como a listagem passa: o aplicativo grava este
            // resumo por cima da conversa que tinha, entao um resumo sem `moderation_available`
            // apaga os controles de moderacao de uma conversa de webchat ate alguem recarregar.
            return ['request_id' => $outbound->getRequestId(), 'status' => $outbound->getStatus(), 'item' => $query->outboundItem($outbound), 'summary' => $this->withModeration($query->summary($state), $moderation, $permissions)];
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

    /**
     * O que oferecer quando o atendente toca num e-mail que apareceu na conversa. Uma ida so:
     * o contato ligado, os homonimos por e-mail, e as listas de campanha e segmento.
     */
    public function emailOptions(int $stateId, Request $request, CorePermissions $permissions, UserHelper $users, ConversationStateRepository $states, ContactLinking $linking): JsonResponse
    {
        $this->grant($permissions, 'view');
        return $this->respond(function () use ($stateId, $request, $users, $states, $linking): array {
            $state = $this->requireState($states, $stateId);
            return $linking->options($state->getConversation(), $linking->email((string) $request->query->get('email', '')), $this->user($users));
        });
    }

    /**
     * Executa o que foi escolhido. O contato alvo vem decidido da tela de proposito: quando ha
     * um homonimo por e-mail, escolher entre ele e o contato da conversa e julgamento de quem
     * atende, e adivinhar aqui moveria a conversa de dono sem ninguem ver.
     */
    public function emailApply(int $stateId, Request $request, CorePermissions $permissions, UserHelper $users, ConversationStateRepository $states, ContactLinking $linking): JsonResponse
    {
        $this->grantMutation($request, $permissions, 'edit');
        return $this->respond(function () use ($stateId, $request, $users, $states, $linking): array {
            $state = $this->requireState($states, $stateId);
            $payload = $this->payload($request);
            $campaign = (int) ($payload['campaign_id'] ?? 0);
            $segment = (int) ($payload['segment_id'] ?? 0);
            return $linking->apply(
                $state->getConversation(),
                $linking->email((string) ($payload['email'] ?? '')),
                (int) ($payload['contact_id'] ?? 0),
                (bool) ($payload['save_email'] ?? false),
                $campaign > 0 ? $campaign : null,
                $segment > 0 ? $segment : null,
                $this->user($users)
            );
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
         $this->conversationAccess->assertView($state);
        return $state;
    }
    /** @param callable():array<string,mixed> $callback */
    /**
     * As sessões de IA vivas, de todas as conversas de uma vez.
     *
     * Até aqui o estado do agente só existia conversa a conversa: para saber que um agente
     * parou e está esperando uma pessoa, era preciso abrir a conversa onde isso aconteceu — ou
     * seja, saber antes o que só se descobre depois. Esta é a fila que faltava.
     *
     * A ordem não é por relógio: quem parou pedindo gente vem primeiro, porque é o único grupo
     * em que alguém está esperando. Uma sessão cuja conversa sumiu, ou que este operador não
     * pode ver, simplesmente não entra — a verificação de acesso é a mesma da conversa.
     */
    public function aiSessions(CorePermissions $permissions, UserHelper $users, \MauticPlugin\MauticInboxBundle\Application\Ai\AiStore $store, ConversationStateRepository $states, InboxQuery $query, \MauticPlugin\MauticInboxBundle\Application\Ai\AiService $ai): JsonResponse
    {
        $this->grant($permissions, 'view');

        return $this->respond(function () use ($users, $store, $states, $query, $ai): array {
            $user = $this->user($users);
            $items = [];
            foreach ($store->all('assignment') as $record) {
                $state = $states->find((int) ($record['key'] ?? 0));
                if (!$state instanceof ConversationState) { continue; }
                try { $ai->assertConversationAccess($state, $user); } catch (\Throwable) { continue; }
                $summary = $query->summary($state);
                $reason = (string) ($record['reason'] ?? '');
                $items[] = [
                    'id' => (int) $state->getId(),
                    'contact_name' => $summary['contact_name'] ?? '',
                    'channel' => $summary['channel'] ?? '',
                    'asset' => $summary['asset'] ?? null,
                    'avatar_url' => $summary['avatar_url'] ?? null,
                    'agent' => (string) ($record['name'] ?? $record['agent'] ?? ''),
                    'status' => (string) ($record['status'] ?? ''),
                    'count' => (int) ($record['count'] ?? 0),
                    'limit' => (int) ($record['limit'] ?? 0),
                    'reason' => $reason,
                    // O rótulo do motivo é o mesmo que a conversa aberta mostra, para a fila e a
                    // conversa não chamarem a mesma coisa por dois nomes.
                    'reason_label' => in_array($reason, ['delivery_failed', 'execution_failed', 'limit', 'human', 'offtopic', 'close'], true)
                        ? $this->translator->trans('mautic.inbox.ai.state.'.$reason) : $reason,
                    'status_label' => $this->translator->trans('mautic.inbox.ai.state.'.($record['status'] ?? 'paused')),
                    'needs_human' => '' !== $reason,
                    'assigned_at' => (string) ($record['assigned_at'] ?? ''),
                    'last_message_at' => $summary['last_message_at'] ?? null,
                ];
            }
            // Quem pede gente primeiro; dentro de cada grupo, a mais recente antes.
            usort($items, static fn (array $a, array $b): int => [$b['needs_human'], $b['last_message_at'] ?? ''] <=> [$a['needs_human'], $a['last_message_at'] ?? '']);

            return ['items' => $items, 'waiting' => count(array_filter($items, static fn (array $row): bool => $row['needs_human']))];
        });
    }

    /**
     * O canal aceita áudio? As condições são as mesmas que o aplicativo já aplica.
     *
     * O arquivo `enabled` no diretório do store é o interruptor da instalação; fora de uma
     * sessão de WhatsApp por QR a Meta não aceita o envio, e comentário não é conversa.
     */
    private function audioAvailable(ConversationState $state, AudioStore $audioStore): bool
    {
        return $audioStore->enabled()
            && 'whatsapp_qr_session' === $state->getConversation()->getAsset()->getType()->value
            && !str_starts_with($state->getConversation()->getRecipient(), 'comment:');
    }

    /**
     * Recebe o áudio gravado no navegador e o guarda até o envio.
     *
     * Guardar e enviar são dois passos de propósito: o envio precisa do `request_id` para não
     * duplicar numa reconexão, e o store usa o mesmo par para reconhecer o reenvio do MESMO
     * áudio em vez de gravar outro. O formato é conferido pelo store, que só aceita MPEG-4
     * nativo — o navegador que não souber gravar nesse formato não mostra o botão.
     */
    public function audio(int $stateId, Request $request, CorePermissions $permissions, UserHelper $users, ConversationStateRepository $states, AudioStore $audioStore): JsonResponse
    {
        $this->grantMutation($request, $permissions, 'create');

        return $this->respond(function () use ($stateId, $request, $users, $states, $audioStore): array {
            $state = $this->requireState($states, $stateId);
            $user = $this->user($users);
            if (!$this->audioAvailable($state, $audioStore)) { throw new InboxException('mautic.inbox.ui.audio_unsupported'); }
            // Enviar em nome de quem não assumiu é o mesmo problema de sempre: a conversa é de
            // alguém, e o áudio sai com a voz de uma pessoa.
            if ($state->getAssignee()?->getId() !== $user->getId()) { throw new InboxException('mautic.inbox.ui.audio_take_first', 403); }
            $payload = $this->payload($request);
            if (!is_string($payload['data'] ?? null) || !is_string($payload['request_id'] ?? null)) { throw new InboxException('mautic.inbox.ui.audio_invalid'); }
            try { return $audioStore->store((int) $state->getId(), (int) $user->getId(), (int) $state->getConversation()->getAsset()->getId(), $payload['request_id'], $payload['data']); }
            catch (\DomainException $error) { throw new InboxException('audio_too_large' === $error->getMessage() ? 'mautic.inbox.ui.audio_too_large' : 'mautic.inbox.ui.audio_invalid'); }
        }, Response::HTTP_CREATED);
    }

    private function respond(callable $callback, int $status = 200): JsonResponse
    {
        try { return new JsonResponse($callback(), $status); } catch (InboxException $e) { return new JsonResponse(['error' => $this->translator->trans($e->getMessage())], $e->httpStatus); }
    }
}
