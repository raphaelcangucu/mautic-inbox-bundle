<?php
declare(strict_types=1);
require __DIR__.'/../../Security/ConversationAccess.php';
use MauticPlugin\MauticInboxBundle\Security\ConversationAccess;
$count=0;
foreach ([['own'=>true,'waiting'=>false,'all'=>false],['own'=>true,'waiting'=>true,'all'=>false],['own'=>false,'waiting'=>false,'all'=>true],['own'=>false,'waiting'=>false,'all'=>false]] as $scope) {
 foreach ([null,7,8] as $assignee) foreach ([true,false] as $needs) foreach (['open','snoozed','resolved'] as $life) {
  $expected=$scope['all'] || ($scope['own'] && $assignee===7) || ($scope['waiting'] && null===$assignee && $needs && $life==='open');
  if (ConversationAccess::allows($scope,7,$assignee,$needs,$life)!==$expected) throw new RuntimeException('Scope matrix failed');
  ++$count;
 }
}
if (ConversationAccess::allows(['all'=>true],0,null,true,'open')) throw new RuntimeException('Anonymous actor granted');
echo "Conversation scope matrix passed ($count cases, no database)\n";
