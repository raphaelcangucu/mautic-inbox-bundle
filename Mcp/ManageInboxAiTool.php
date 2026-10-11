<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Mcp;
use MauticPlugin\MauticMcpBundle\Mcp\Tool\AbstractMcpTool;
use MauticPlugin\MauticMcpBundle\Application\Management\MutationExecutor;
use Mcp\Capability\Attribute\{McpTool,Schema};
use Mcp\Schema\ToolAnnotations;
#[McpTool(name:'mautic_manage_inbox_ai', annotations:new ToolAnnotations(readOnlyHint:false, destructiveHint:false, openWorldHint:true), outputSchema: \MauticPlugin\MauticMcpBundle\OutputSchemas::OBJECT)]
final class ManageInboxAiTool extends AbstractMcpTool
{
    public function __construct(private InboxToolService $service, private MutationExecutor $mutations) {}
    /** Admin-only AI configuration. document: key/revision/name/body/scope(global or agent)/publish. agent: key/revision/name/profile/limit/enabled/documents/permissions/audience(customer or internal). Internal agents: mcp_connection=current_mautic, mcp_tools (read-only catalog in overview), role_ids (empty means all eligible logged-in roles). Tools run as the logged-in user and cannot grant permissions. config: model/limit/limit_action/enabled/permissions. comment_config: key/revision/mode(off,review,automatic)/asset_ids/publication_ids/document_key/document_version/mark_spam_enabled/public_reply_enabled/classify_limit/reply_limit/min_interval/notify. Disabled by default; active modes require exact published rule and account permissions. comment_review: run_key/revision/decision(approve,reject); only original validated proposal can execute. comment_runs: page. Read before updating; send full settings. health checks installation; validate calls model and CMS; install uses pinned Pi; import-auth uses server Codex login. Secrets must not be passed in data. Explicit confirm=true required. */
    #[McpTool(name:'mautic_manage_inbox_ai', annotations:new ToolAnnotations(readOnlyHint:false, destructiveHint:false, openWorldHint:true), outputSchema: \MauticPlugin\MauticMcpBundle\OutputSchemas::OBJECT)]
    public function __invoke(#[Schema(enum:['document','agent','config','health','validate','install','import-auth','comment_config','comment_review','comment_runs'])] string $action, #[Schema(type:'object',additionalProperties:true)] array $data=[], bool $confirm=false, ?string $idempotencyKey=null): array
    {
        $this->bootstrapExecution();
        $this->service->authorize('edit',true);
        $payload=compact('action','data');
        if(!$confirm)return $this->mutations->dryRun('inbox_ai',$action,$payload);
        return $this->mutations->execute('inbox_ai',$idempotencyKey,$payload,fn()=> $this->service->manageAi($action,$data));
    }
}
