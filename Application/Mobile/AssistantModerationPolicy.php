<?php
declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Mobile;
use MauticPlugin\MauticInboxBundle\Application\InboxException;

final class AssistantModerationPolicy
{
    public static function snapshot(array $source,array $flags,string $operation): string
    {
        if(!in_array($source['channel']??'', ['instagram','facebook'],true)||empty($source['comment_id'])||($source['recipient']??'')!=='comment:'.$source['comment_id']||empty($source['body'])||!empty($source['deleted'])||!empty($source['own_comment']))throw new InboxException('Comentário de origem indisponível para moderação.',422);
        if(in_array($operation,['hide','show'],true)&&($source['channel']!=='instagram'||empty($source['publication_id'])||empty($source['asset_active'])))throw new InboxException('Ocultação disponível somente para comentários Instagram acessíveis pela conexão.',422);
        if(!in_array($operation,['spam','restore','block','unblock','hide','show'],true))throw new InboxException('Moderação inválida.',422);
        return hash('sha256',json_encode([$source['state_id'],$source['state_version'],$source['asset_id'],$source['recipient'],$source['comment_id'],$source['publication_id'],$source['inbound_id'],$source['body'],$flags],JSON_THROW_ON_ERROR));
    }
    public static function clientTools(array $tools,array $payload): array
    {
        return in_array('inbox_moderate',(array)($payload['supported_actions']??[]),true)?$tools:array_values(array_diff($tools,['inbox_moderate']));
    }
}
