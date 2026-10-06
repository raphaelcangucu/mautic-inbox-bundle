<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Application\{ContactLinking,ConversationActions,InboxException,InboxQuery,WhatsAppTemplates,ChannelTransportRegistry};
use MauticPlugin\MauticInboxBundle\Application\Ai\{AiService,AiStore};
use MauticPlugin\MauticInboxBundle\Application\Mobile\{SessionStore,CannedResponses,ModerationStore,OperatorAssistant};
use MauticPlugin\MauticInboxBundle\Entity\{ConversationState,ConversationStateRepository,CannedResponseRepository};
use MauticPlugin\MauticMetaBundle\Application\Conversation\ConversationManager;
use Symfony\Component\HttpFoundation\{JsonResponse,Request,Response};
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/** Native bearer boundary. Business rules remain in the existing Inbox services. */
final class MobileApiController extends CommonController
{
    public function api(string $resource, Request $request, SessionStore $sessions, EntityManagerInterface $em, TokenStorageInterface $tokens, CorePermissions $permissions, InboxQuery $query, ConversationStateRepository $states, ConversationActions $actions, WhatsAppTemplates $templates, ContactLinking $linking, AiService $ai, AiStore $aiStore, ConversationManager $metaConversations, CannedResponses $canned, CannedResponseRepository $cannedRepository, ModerationStore $moderation, OperatorAssistant $assistant, ChannelTransportRegistry $transports, \MauticPlugin\MauticInboxBundle\Application\Mobile\Push\NativePushRegistry $push, \MauticPlugin\MauticInboxBundle\Application\Mobile\PublicationContext $publications, \MauticPlugin\MauticInboxBundle\Application\Mobile\ContactDirectory $directory, \MauticPlugin\MauticInboxBundle\Application\Mobile\QrPairing $pairing, \MauticPlugin\MauticInboxBundle\Security\ConversationAccess $access): Response
    {
        $previous = $tokens->getToken();
        try {
            $header = $request->headers->get('Authorization', '');
            if (!preg_match('/^Bearer ([a-f0-9]{64})$/D', $header, $match)) { return $this->error('Sessão necessária.', 'unauthorized', 401); }
            try { $grant = $sessions->authenticate($match[1]); } catch (\DomainException) { return $this->error('Sessão expirada. Entre novamente.', 'unauthorized', 401); }
            $user = $em->find(User::class, $grant['user']);
            if (!$user instanceof User || !$user->isPublished() || !hash_equals($grant['fingerprint'], hash('sha256', (string) $user->getPassword()))) { return $this->error('Sessão revogada. Entre novamente.', 'unauthorized', 401); }
            $access->hydrate($user);
            $tokens->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));
            if (!$permissions->isGranted(['inbox:conversations:view','meta:messages:view'])) { return $this->error('Seu usuário não tem acesso ao atendimento.', 'forbidden', 403); }
            $method = $request->getMethod();
            if (in_array($resource, ['push/device','push/test'], true)) {
                try {
                    if ($resource === 'push/device' && $method === 'GET') { return $this->data($push->status($grant,$request->query->getString('installation'))); }
                    if (strlen($request->getContent()) > 4096) { throw new \DomainException('invalid_request'); }
                    $p=json_decode($request->getContent(),true,16,JSON_THROW_ON_ERROR);
                    if (!is_array($p) || array_is_list($p)) { throw new \DomainException('invalid_request'); }
                    if ($resource === 'push/device' && $method === 'PUT') { return $this->data($push->register($grant,$p)); }
                    if ($resource === 'push/device' && $method === 'DELETE') { $push->remove($grant,(string)($p['installation'] ?? '')); return $this->data(['removed'=>true]); }
                    if ($resource === 'push/test' && $method === 'POST') { $push->test($grant,(string)($p['installation'] ?? '')); return $this->data(['queued'=>true],202); }
                    return $this->error('Método não permitido.','method_not_allowed',405);
                } catch (\DomainException|\JsonException $error) { return $this->error('Registro de push inválido ou indisponível.',$error instanceof \JsonException ? 'invalid_request' : $error->getMessage(),400); }
            }

            $decorate = function(array $raw) use ($user,$states,$query,$aiStore,$moderation,$permissions,$actions): array {
                $state = $states->find((int) $raw['id']);
                if (!$state) { return $raw; }
                $raw = $query->detail($state,$user);
                unset($raw['realtime'],$raw['webchat']['realtime']);
                $raw['kind'] = str_starts_with($state->getConversation()->getRecipient(),'comment:') ? 'comments' : 'inbox';
                $a=$aiStore->get('assignment',(string)$state->getId());
                $raw['agent']=$a ? ['key'=>$a['agent']??'', 'name'=>$a['name']??'', 'status'=>$a['status']??'paused', 'count'=>$a['count']??0] : null;
                $raw['moderation']=$raw['kind'] === 'comments' ? $moderation->flags($raw) : ['spam'=>false,'hidden'=>false,'blockedAuthor'=>false];
                $raw['can_reply']=$raw['can_reply'] && $permissions->isGranted(['inbox:conversations:create','meta:messages:create']) && !$raw['moderation']['spam'] && !$raw['moderation']['blockedAuthor'];
                $raw['can_take']=$raw['can_take'] && $permissions->isGranted(['inbox:conversations:edit','meta:messages:edit']);
                $raw['can_take_and_reply']=$raw['can_take_and_reply'] && $raw['can_take'] && $permissions->isGranted(['inbox:conversations:create','meta:messages:create']) && !$raw['moderation']['spam'] && !$raw['moderation']['blockedAuthor'];
                if ($raw['kind'] === 'comments' && $state->getConversation()->getChannel() === 'instagram') {
                    $allowed=$permissions->isGranted(['inbox:conversations:create','meta:messages:create']) && !$raw['moderation']['spam'] && !$raw['moderation']['blockedAuthor'];
                    $publicReason=$actions->publicReplyBlockedReason($state);
                    $raw['reply_modes']=[
                        'public'=>['available'=>$allowed && $publicReason === null,'can_reply'=>$allowed && $publicReason === null && $state->getAssignee()?->getId() === $user->getId(),'blocked_reason'=>$publicReason],
                        'private'=>['available'=>$allowed && $raw['reply_blocked_reason'] === null,'can_reply'=>$raw['can_reply'],'blocked_reason'=>$raw['reply_blocked_reason']],
                    ];
                    $raw['can_take_and_reply']=$raw['can_take'] && $state->getAssignee()?->getId() !== $user->getId() && ($raw['reply_modes']['public']['available'] || $raw['reply_modes']['private']['available']);
                }
                return $raw;
            };
            if ($method === 'GET' && $resource === 'assistant/privacy') { return $this->data($assistant->privacy()); }
            if ($method === 'POST' && $resource === 'assistant/messages') {
                if(strlen($request->getContent())>32768){return $this->error('Requisição inválida.','invalid_request',400);}
                try{$payload=json_decode($request->getContent(),true,16,JSON_THROW_ON_ERROR);}catch(\JsonException){return $this->error('JSON inválido.','invalid_request',400);}
                if(!is_array($payload)){return $this->error('JSON inválido.','invalid_request',400);}
                return $this->data($assistant->reply($payload,$user));
            }
            if ($method === 'POST' && $resource === 'canned-responses') {
                if (!$permissions->isGranted('inbox:templates:edit')) { return $this->error('Ação não autorizada.','forbidden',403); }
                if (strlen($request->getContent()) > 16384) { return $this->error('Requisição inválida.','invalid_request',400); }
                try { $payload=json_decode($request->getContent(),true,16,JSON_THROW_ON_ERROR); } catch (\JsonException) { return $this->error('JSON inválido.','invalid_request',400); }
                if (!is_array($payload)) { return $this->error('JSON inválido.','invalid_request',400); }
                return $this->data($canned->create($payload,$user,$cannedRepository,$em),201);
            }
            if ($method === 'GET' && preg_match('#^media/([1-9][0-9]*)$#D',$resource,$media)) {
                $message=$em->find(\MauticPlugin\MauticMetaBundle\Entity\MetaMessage::class,(int)$media[1]);
                $mediaState=$message instanceof \MauticPlugin\MauticMetaBundle\Entity\MetaMessage ? $states->findOneBy(['conversation'=>$message->getConversation()]) : null;
                if (!$mediaState) return $this->error('Arquivo indisponível.','not_found',404);
                $access->assertView($mediaState,$user);
                $qrController='MauticPlugin\\MauticWhatsQrBundle\\Controller\\MediaController';
                $controller=$message instanceof \MauticPlugin\MauticMetaBundle\Entity\MetaMessage && $message->getAsset()->getType()->value === 'whatsapp_qr_session' && class_exists($qrController) ? $qrController.'::show' : InboxController::class.'::media';
                $response=$this->forward($controller,['messageId'=>(int)$media[1]]);$response->headers->set('Cache-Control','no-store, private');return $response;
            }

            if ($method === 'DELETE' && $resource === 'session') { $sessions->revoke($match[1]); return $this->data(['revoked' => true]); }
            if ($method === 'GET' && $resource === 'me') { return $this->data(['user' => ['id' => (int) $user->getId(), 'name' => $user->getName(), 'email' => $user->getEmail()]]); }
            if ($method === 'GET' && $resource === 'operator-options') {
                $agents = array_map(static fn (array $agent): array => ['key' => $agent['key'], 'name' => $agent['name']], $aiStore->all('agent'));
                return $this->data(['users' => $query->users(), 'agents' => $agents]);
            }
            if ($method === 'GET' && $resource === 'canned-responses') { return $this->data(['items' => $query->cannedResponses()]); }
            if ($method === 'GET' && $resource === 'conversations') { $list=$query->conversations($user,$request->query->all()); $list['items']=array_map($decorate,$list['items']); return $this->data($list); }
            if ($method === 'GET' && $resource === 'notifications') {
                $batch=$query->notifications($request->query->has('cursor')?$request->query->getInt('cursor'):null,$user);
                foreach($batch['notifications'] as &$notification){$state=$states->find((int)$notification['state_id']);if($state){$c=$decorate($query->summary($state));$notification['conversation']=$c;$notification['suppressed']=$c['moderation']['spam']||$c['moderation']['blockedAuthor'];}}unset($notification);
                return $this->data($batch);
            }
            if ($method === 'GET' && $resource === 'updates') {
                $id = $request->query->getInt('state_id'); $selected = $id ? $states->find($id) : null;
                if ($id && !$selected) { return $this->error('Conversa não encontrada.', 'not_found', 404); }
                $since = $request->query->getString('since') ?: gmdate(DATE_ATOM, time() - 60);
                $updates=$query->poll($user,$since,$selected,$request->query->has('notification_cursor') ? $request->query->getInt('notification_cursor') : null); $updates['conversations']=array_map($decorate,$updates['conversations']); $updates['timeline']=\MauticPlugin\MauticInboxBundle\Application\Mobile\MediaRoutes::items($updates['timeline'],$request->getSchemeAndHttpHost()); return $this->data($updates);
            }
            if ($resource === 'whatsqr' || preg_match('#^whatsqr/([1-9][0-9]*)(?:/(start))?$#D',$resource,$qrRoute)) {
                if ($method==='GET' && $resource==='whatsqr') { return $this->data($pairing->connections()); }
                if ($method==='GET' && empty($qrRoute[2])) { return $this->data($pairing->status((int)$qrRoute[1])); }
                if ($method==='POST' && ($qrRoute[2]??'')==='start') {
                    if(strlen($request->getContent())>1024){return $this->error('Requisição inválida.','invalid_request',400);}
                    try{$payload=json_decode($request->getContent(),true,4,JSON_THROW_ON_ERROR);}catch(\JsonException){return $this->error('JSON inválido.','invalid_request',400);}
                    if(!is_array($payload)||array_is_list($payload)||!is_bool($payload['regenerate']??null)){return $this->error('Requisição inválida.','invalid_request',400);}
                    return $this->data($pairing->start((int)$qrRoute[1],$payload['regenerate']));
                }
                return $this->error('Método não permitido.','method_not_allowed',405);
            }
            if ($method === 'GET' && $resource === 'contacts') { return $this->data($directory->search($user,$request->query->all())); }
            if ($method === 'GET' && $resource === 'crm-options') { return $this->data($linking->catalogue($user,$request->query->all())); }
            if ($method === 'GET' && $resource === 'contacts/campaigns') { return $this->data(['items'=>$directory->campaigns($user)]); }
            if (preg_match('#^contacts/([1-9][0-9]*)(?:/(start))?$#D',$resource,$contactRoute)) {
                $contactId=(int)$contactRoute[1];
                if ($method === 'GET' && empty($contactRoute[2])) { return $this->data($directory->detail($contactId,$user)); }
                if ($method === 'POST' && ($contactRoute[2]??'') === 'start') {
                    if(strlen($request->getContent())>4096){return $this->error('Requisição inválida.','invalid_request',400);}
                    try{$payload=json_decode($request->getContent(),true,8,JSON_THROW_ON_ERROR);}catch(\JsonException){return $this->error('JSON inválido.','invalid_request',400);}
                    if(!is_array($payload)||array_is_list($payload)){return $this->error('Requisição inválida.','invalid_request',400);}
                    $started=$directory->start($contactId,$user,$payload);
                    return $this->data($decorate($query->summary($started)));
                }
                return $this->error('Método não permitido.','method_not_allowed',405);
            }
            if (!preg_match('#^conversations/([1-9][0-9]*)(?:/(history|templates|take|state|reply|note|draft|ai|email-options|email-actions|moderation|publication))?$#D', $resource, $parts)) {
                return $this->error('Recurso não disponível nesta versão da API.', 'unsupported', 404);
            }
            $id = (int) $parts[1]; $operation = $parts[2] ?? ''; $state = $states->find($id);
            if ($state) $access->assertView($state,$user);
            if (!$state) { return $this->error('Conversa não encontrada.', 'not_found', 404); }
            if ($method === 'GET') {
                return match ($operation) {
                    '' => $this->data($decorate($query->summary($state))),
                    'history' => $this->data((function() use ($query,$state,$request): array { $timeline=$query->timeline($state,$request->query->get('before'),$request->query->getInt('limit',40));$timeline['items']=\MauticPlugin\MauticInboxBundle\Application\Mobile\MediaRoutes::items($timeline['items'],$request->getSchemeAndHttpHost());return $timeline; })()),
                    'publication' => $this->data($publications->resolve($state,$query->origins($state),$request->query->getBoolean('refresh'))),
                    'templates' => $this->data(['items' => $templates->catalog($state), 'blocked_reason' => ($reason = $templates->blockedReason($state)) ? $this->translator->trans($reason) : null]),
                    'email-options' => $this->data($linking->options($state->getConversation(), (!$request->query->getBoolean('include_catalog', true) && '' === trim($request->query->getString('email'))) ? '' : $linking->email($request->query->getString('email')), $user, $request->query->getBoolean('include_catalog', true))),
                    'ai' => $this->forward(AiController::class.'::available', ['stateId' => $id]),
                    default => $this->error('Método não permitido.', 'method_not_allowed', 405),
                };
            }
            if (!in_array($method, ['POST','PUT'], true) || strlen($request->getContent()) > 65536) { return $this->error('Requisição inválida.', 'invalid_request', 400); }
            try { $p = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR); } catch (\JsonException) { return $this->error('JSON inválido.', 'invalid_request', 400); }
            if (!is_array($p)) { return $this->error('JSON inválido.', 'invalid_request', 400); }
            $level = $operation === 'reply' ? 'create' : 'edit';
            if (!$permissions->isGranted(['inbox:conversations:'.$level,'meta:messages:'.$level])) { return $this->error('Ação não autorizada para seu usuário.', 'forbidden', 403); }
            if ($operation === 'reply') {
                if ($moderation->flags($query->summary($state))['spam'] || $moderation->flags($query->summary($state))['blockedAuthor']) { return $this->error('Restaure o atendimento antes de responder.','moderated',422); }
                if (isset($p['attachment'])) { return $this->error('Upload de mídia não disponível nesta instância.', 'unsupported_media', 422); }
                $conversation = $state->getConversation();
                $comment = str_starts_with($conversation->getRecipient(), 'comment:');
                if (isset($p['reply_mode']) && !is_string($p['reply_mode'])) { return $this->error('Modo de resposta inválido.', 'unsupported_reply_mode', 422); }
                $replyMode=\MauticPlugin\MauticInboxBundle\Application\ReplyMode::resolve($conversation->getChannel(),$conversation->getRecipient(),$p['reply_mode']??null);
                $out = $actions->reply($state, $user, (string) ($p['body'] ?? ''), (string) ($p['request_id'] ?? ''), isset($p['template_id']) ? ['id' => (int) $p['template_id'], 'variables' => $p['variables'] ?? []] : null, $replyMode);
                return $this->data(['request_id' => $out->getRequestId(), 'status' => $out->getStatus(), 'item' => $query->outboundItem($out), 'summary' => $decorate($query->summary($state))], 202);
            }
            if ($operation === 'moderation') {
                if (!str_starts_with($state->getConversation()->getRecipient(),'comment:')) { return $this->error('Moderação disponível em comentários.','not_comment',422); }
                $em->refresh($state);
                if ($state->getVersion() !== (int)($p['version']??0)) { return $this->error('A conversa mudou. Atualize antes de continuar.','version_conflict',409); }
                try {
                    $action=(string)($p['action']??'');
                    if (in_array($action,['hide','show'],true)) {
                        $detail=$query->detail($state,$user);
                        $publications->hideInstagram($state,$detail['origins'] ?? [],$action === 'hide');
                    }
                    $moderation->apply($query->summary($state),$action,(int)$user->getId());
                } catch (\DomainException $error) {
                    $reasons=[
                        'moderation_scope_mismatch'=>'A publicação registrada não coincide com a origem atual do comentário. Revise o vínculo antes de moderar.',
                        'moderation_not_confirmed'=>'O Instagram recebeu a solicitação, mas ainda não confirmou a alteração. Atualize e tente novamente.',
                        'unsupported_moderation'=>'Esta moderação não está disponível para o comentário selecionado.',
                    ];
                    $code=isset($reasons[$error->getMessage()]) ? $error->getMessage() : 'unsupported_moderation';
                    return $this->error($reasons[$code],$code,422);
                }
                catch (\MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphApiException $error) {
                    $details=$error->details();
                    if (($details['code'] ?? null) === 100 && ($details['error_subcode'] ?? null) === 33) {
                        return $this->error('O Instagram não disponibiliza este comentário para a conexão atual. Ele permanece no Spam.','social_comment_unavailable',422);
                    }
                    return $this->error('O Instagram não confirmou esta moderação. Confira a conexão antes de tentar novamente.','moderation_unavailable',502);
                }
                catch (\Throwable) { return $this->error('Não foi possível confirmar a moderação na rede. Atualize antes de tentar novamente.','moderation_unavailable',502); }
            }
            elseif ($operation === 'take') { $actions->take($state, $user, (int) ($p['version'] ?? 0)); }
            elseif ($operation === 'state') {
                $before=$query->detail($state,$user);
                if (($p['action'] ?? '') === 'read') { $transport=$transports->for($state->getConversation());null === $transport ? $metaConversations->markRead($state->getConversation()) : $transport->markRead($state); }
                else {
                    $target = isset($p['target_user_id']) ? $em->find(User::class, (int) $p['target_user_id']) : null;
                    if (isset($p['target_user_id']) && (!$target instanceof User || !in_array((int) $target->getId(), array_column($query->users(), 'id'), true))) { throw new InboxException('mautic.inbox.ui.person_not_found_603a16'); }
                    try { $until = !empty($p['until']) ? new \DateTimeImmutable((string) $p['until']) : null; } catch (\Exception) { return $this->error('Data inválida.', 'invalid_date', 422); }
                    $state=$actions->transition($state, $user, (int) ($p['version'] ?? 0), (string) ($p['action'] ?? ''), $target, $until);
                    if (!$access->canView($state,$user)) return $this->data($query->afterTransition($state,$user,$before));
                }
            }
            elseif ($operation === 'note') { $note = $actions->note($state, $user, (string) ($p['body'] ?? '')); return $this->data(['id' => $note->getId(), 'saved' => true], 201); }
            elseif ($operation === 'draft' && $method === 'PUT') { $draft = $actions->saveDraft($state, $user, (string) ($p['mode'] ?? ''), (string) ($p['body'] ?? '')); return $this->data(['saved' => true, 'updated_at' => $draft->getDateModified()->format(DATE_ATOM)]); }
            elseif ($operation === 'ai') {
                if (!$permissions->isGranted(['inbox:conversations:create','meta:messages:create'])) { return $this->error('Ação não autorizada.', 'forbidden', 403); }
                if (($p['action'] ?? '') === 'reset') { $ai->reset($state, $user, (int) ($p['version'] ?? 0)); }
                else { $ai->assign($state, $user, (string) ($p['agent'] ?? $p['key'] ?? ''), (int) ($p['version'] ?? 0)); }
            }
            elseif ($operation === 'email-actions') {
                $linking->apply($state->getConversation(), $linking->email((string) ($p['email'] ?? '')), (int) ($p['contact_id'] ?? 0), (bool) ($p['save_email'] ?? false), !empty($p['campaign_id']) ? (int) $p['campaign_id'] : null, !empty($p['segment_id']) ? (int) $p['segment_id'] : null, $user);
            }
            else { return $this->error('Método não permitido.', 'method_not_allowed', 405); }
            return $this->data($decorate($query->summary($state)));
        } catch (InboxException $e) { return $this->error($this->translator->trans($e->getMessage()), $e->httpStatus === 409 ? 'version_conflict' : 'inbox_error', $e->httpStatus); }
        finally { $tokens->setToken($previous); }
    }

    private function data(array $value, int $status = 200): JsonResponse { return new JsonResponse($value, $status, ['Cache-Control' => 'no-store','X-Content-Type-Options' => 'nosniff']); }
    private function error(string $message, string $code, int $status): JsonResponse { return $this->data(['error' => $message, 'code' => $code], $status); }
}
