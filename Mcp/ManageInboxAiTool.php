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
    /** Admin-only AI configuration. document: key/revision/name/body/scope(global or agent)/publish. agent: key/revision/name/profile/limit/enabled/documents/permissions. config: model/limit/limit_action/enabled/permissions. Read before updating; send full settings. health checks installation; validate calls model and CMS; install uses pinned Pi; import-auth uses server Codex login. Secrets must not be passed in data. Explicit confirm=true required. */
    #[McpTool(name:'mautic_manage_inbox_ai', annotations:new ToolAnnotations(readOnlyHint:false, destructiveHint:false, openWorldHint:true), outputSchema: \MauticPlugin\MauticMcpBundle\OutputSchemas::OBJECT)]
    public function __invoke(#[Schema(enum:['document','agent','config','health','validate','install','import-auth'])] string $action, #[Schema(type:'object',additionalProperties:true)] array $data=[], bool $confirm=false, ?string $idempotencyKey=null): array
    {
        $this->bootstrapExecution();
        $this->service->authorize('edit',true);
        $payload=compact('action','data');
        if(!$confirm)return $this->mutations->dryRun('inbox_ai',$action,$payload);
        return $this->mutations->execute('inbox_ai',$idempotencyKey,$payload,fn()=> $this->service->manageAi($action,$data));
    }
}
