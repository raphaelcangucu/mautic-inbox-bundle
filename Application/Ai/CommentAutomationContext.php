<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Ai;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\MauticInboxBundle\Entity\{CommentContext, ConversationState};
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticInboxBundle\Application\Mobile\ModerationStore;

/** Only the originating public comment is exposed to the classifier; no CRM/private history. */
final class CommentAutomationContext
{
    public function __construct(private EntityManagerInterface $em, private AiStore $store, private ModerationStore $moderation) {}

    /** Attach this rule without changing the normal chat agent context. */
    public function documents(array $config,array $agent): array
    {
        $documents=$this->store->context($agent);
        $rule=$this->store->get('document',$config['document_key']??'')['published']??[];
        if($rule && !in_array($config['document_key'],array_column($documents,'key'),true))$documents[]=['key'=>$config['document_key']]+$rule;
        if(mb_strlen(implode('',array_column($documents,'body')))>60000)throw new \MauticPlugin\MauticInboxBundle\Application\InboxException('mautic.inbox.ai.context_large');
        return $documents;
    }

    public function read(ConversationState $state): ?array
    {
        $this->em->refresh($state);
        $conversation=$state->getConversation(); $asset=$conversation->getAsset(); $this->em->refresh($asset);
        if (!in_array($conversation->getChannel(),['instagram','facebook'],true) || !str_starts_with($conversation->getRecipient(),'comment:')) return null;
        $id=substr($conversation->getRecipient(),8);
        $context=$this->em->getRepository(CommentContext::class)->findOneBy(['publicConversation'=>$conversation,'commentId'=>$id]);
        if (!$context || $context->getMessage()->getId()!==$state->getLastInboundMessageId()) return null;
        $message=$context->getMessage(); $this->em->refresh($message); $payload=$message->getPayload();
        $flags=$this->moderation->flags(['id'=>$state->getId(),'channel'=>$conversation->getChannel(),'asset'=>['id'=>$asset->getId()],'recipient'=>$conversation->getRecipient()]);
        $assignment=$this->store->get('assignment',(string)$state->getId());
        return ['state_id'=>(int)$state->getId(),'state_version'=>$state->getVersion(),'asset_id'=>(int)$asset->getId(),
            'channel'=>$conversation->getChannel(),'recipient'=>$conversation->getRecipient(),'comment_id'=>$context->getCommentId(),
            'publication_id'=>$context->getMediaId(),'inbound_id'=>(int)$message->getId(),'body'=>mb_substr((string)($payload['text']??''),0,8000),
            'human'=>null!==$state->getAssignee() || $state->isHumanTakeover(), 'lifecycle'=>$state->getLifecycle(),'needs_response'=>$state->needsResponse(),
            'deleted'=>!empty($payload['removed']), 'own_comment'=>in_array($context->getParticipantId(),[$context->getAccountId(),$asset->getExternalId()],true),
            'ai_assigned'=>in_array($assignment['status']??'', ['active','queued','finishing'],true),
            'already_replied'=>null!==$this->em->getRepository(MetaMessage::class)->findOneBy(['conversation'=>$conversation,'direction'=>'outbound']),
            'spam'=>!empty($flags['spam']), 'blocked'=>!empty($flags['blockedAuthor']),
            'asset_active'=>$asset->isPublished() && $asset->getStatus()==='active' && ($conversation->getChannel()!=='facebook' || ($asset->getSettings()['facebook_reply_enabled']??true)!==false),
            'origin'=>['comment_id'=>$id,'media_id'=>$context->getMediaId(),'caption'=>$payload['origin_media']['caption']??null,'permalink'=>$context->getPermalink()]];
    }
}
