<?php
namespace MauticPlugin\MauticInboxBundle\Application\Ai;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
final class FunnelContext {
 public function read(ConversationState $state): array {
  if(str_starts_with($state->getConversation()->getRecipient(),'comment:'))return ['source'=>'mautic','identity'=>'public_conversation','stage'=>null,'next_action'=>'private_conversation'];
  $contact=$state->getConversation()->getContact();
  if(!$contact)return ['source'=>'mautic','identity'=>'unlinked','stage'=>null,'next_action'=>'identify','checked_at'=>gmdate(DATE_ATOM)];
  $stage=$contact->getStage();$name=$stage?->getName();
  $next=match(strtolower(trim((string)$name))){'waitlist'=>'registration','registered'=>'first_deposit','first deposit'=>'support','first order'=>'support',default=>'clarify'};
  return ['source'=>'mautic','identity'=>'linked_contact','stage'=>$name,'next_action'=>$next,'checked_at'=>gmdate(DATE_ATOM),'limitation'=>'CRM stage, not a live financial account verification. Never disclose account stage in public comments.'];
 }
}
