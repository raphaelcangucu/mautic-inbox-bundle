<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Application\Ai;

use MauticPlugin\MauticInboxBundle\Application\InboxException;

/** Configuration caps access; it never grants a Mautic permission to a user. */
final class InternalAgentPolicy
{
    public const LEGACY_TOOLS = ['mautic_search_campaigns', 'mautic_fetch_campaign', 'mautic_search_contacts', 'mautic_fetch_contact', 'inbox_context'];

    public static function catalog(): array
    {
        return [
            ['name'=>'mautic_search_campaigns', 'label'=>'tool_campaign_search', 'permissions'=>['campaign:campaigns:viewown','campaign:campaigns:viewother']],
            ['name'=>'mautic_fetch_campaign', 'label'=>'tool_campaign_fetch', 'permissions'=>['campaign:campaigns:viewown','campaign:campaigns:viewother']],
            ['name'=>'mautic_search_contacts', 'label'=>'tool_contact_search', 'permissions'=>['lead:leads:viewown','lead:leads:viewother']],
            ['name'=>'mautic_fetch_contact', 'label'=>'tool_contact_fetch', 'permissions'=>['lead:leads:viewown','lead:leads:viewother']],
            ['name'=>'inbox_context', 'label'=>'tool_inbox_context', 'permissions'=>['inbox:conversations:view']],
            ['name'=>'mautic_read_inbox', 'label'=>'tool_inbox_read', 'permissions'=>['inbox:conversations:view']],
            ['name'=>'mautic_read_campaign_flow', 'label'=>'tool_campaign_flow', 'permissions'=>['campaign:campaigns:viewown','campaign:campaigns:viewother']],
            ['name'=>'campaign_report', 'label'=>'tool_campaign_report', 'permissions'=>['campaign:campaigns:viewown','campaign:campaigns:viewother']],
            ['name'=>'campaign_comments', 'label'=>'tool_campaign_comments', 'permissions'=>['campaign:campaigns:viewown','campaign:campaigns:viewother'], 'required'=>['inbox:conversations:view','meta:messages:view']],
            ['name'=>'mautic_reply_inbox', 'label'=>'tool_inbox_reply', 'permissions'=>['inbox:conversations:create'], 'required'=>['meta:messages:create','inbox:conversations:view','meta:messages:view'], 'write'=>true],
            ['name'=>'campaign_update', 'label'=>'tool_campaign_update', 'permissions'=>['campaign:campaigns:editown','campaign:campaigns:editother'], 'required_any'=>[['campaign:campaigns:viewown','campaign:campaigns:viewother']], 'write'=>true],
            ['name'=>'campaign_add_contacts', 'label'=>'tool_campaign_contacts', 'permissions'=>['campaign:campaigns:editown','campaign:campaigns:editother'], 'required_any'=>[['campaign:campaigns:viewown','campaign:campaigns:viewother'],['lead:leads:viewown','lead:leads:viewother'],['lead:leads:editown','lead:leads:editother']], 'write'=>true],
            ['name'=>'inbox_transfer', 'label'=>'tool_inbox_transfer', 'permissions'=>['inbox:conversations:edit'], 'required'=>['meta:messages:edit','inbox:conversations:view','meta:messages:view'], 'write'=>true],
        ];
    }

    public static function writes(): array { return array_column(array_filter(self::catalog(),static fn($t)=>!empty($t['write'])),'name'); }

    public static function internal(array $agent): bool { return ($agent['audience'] ?? 'customer') === 'internal'; }

    public static function normalize(array $input, array $previous = []): array
    {
        $audience=$input['audience']??$previous['audience']??'customer';
        if (!in_array($audience,['customer','internal'],true)) throw new InboxException('mautic.inbox.ai.invalid_action',422);
        if ($audience === 'customer') return ['audience'=>'customer','mcp_connection'=>null,'mcp_tools'=>[],'role_ids'=>[]];
        $connection=$input['mcp_connection']??$previous['mcp_connection']??'current_mautic';
        if ($connection !== 'current_mautic') throw new InboxException('mautic.inbox.ai.invalid_action',422);
        $tools=$input['mcp_tools']??$previous['mcp_tools']??[];
        $roles=$input['role_ids']??$previous['role_ids']??[];
        if (!is_array($tools)||!is_array($roles)) throw new InboxException('mautic.inbox.ai.invalid_action',422);
        foreach ($tools as $tool) if (!is_string($tool)||!in_array($tool,array_column(self::catalog(),'name'),true)) throw new InboxException('mautic.inbox.ai.invalid_action',422);
        foreach ($roles as $role) if (!is_int($role)||$role<1) throw new InboxException('mautic.inbox.ai.invalid_action',422);
        return ['audience'=>'internal','mcp_connection'=>'current_mautic','mcp_tools'=>array_values(array_unique($tools)),'role_ids'=>array_values(array_unique($roles))];
    }

    public static function configured(array $agents): array
    {
        return array_values(array_filter($agents,self::internal(...)));
    }

    public static function select(array $items, array $payload): array
    {
        if (array_key_exists('agent_key',$payload)) {
            if (!is_string($payload['agent_key'])) throw new InboxException('mautic.inbox.ai.not_allowed',403);
            foreach ($items as $item) if ($item['key']===$payload['agent_key']) return $item;
            throw new InboxException('mautic.inbox.ai.not_allowed',403);
        }
        if (count($items)!==1) throw new InboxException('mautic.inbox.ai.not_allowed',403);
        return $items[0];
    }

    public static function visible(array $agent, int $userId, ?int $roleId, bool $published): bool
    {
        return $published && $userId>0 && self::internal($agent) && !empty($agent['enabled']) && ($agent['mcp_connection']??'')==='current_mautic'
            && (empty($agent['role_ids']) || in_array($roleId,$agent['role_ids'],true));
    }

    /** MCP handlers additionally enforce own/other entity access at execution. */
    public static function effectiveTools(array $agent, callable $granted): array
    {
        $out=[];
        foreach (self::catalog() as $tool) {
            if (!in_array($tool['name'],$agent['mcp_tools']??[],true)) continue;
            if (array_filter($tool['required']??[],fn($p)=>!$granted($p))) continue;
            foreach ($tool['required_any']??[] as $group) if (!array_filter($group,$granted)) continue 2;
            foreach ($tool['permissions'] as $permission) if ($granted($permission)) { $out[]=$tool['name']; break; }
        }
        return $out;
    }
}
