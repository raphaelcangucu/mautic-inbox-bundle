<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Mcp;
use MauticPlugin\MauticMcpBundle\Mcp\Tool\AbstractMcpTool;
use MauticPlugin\MauticMcpBundle\Application\Management\MutationExecutor;
use Mcp\Capability\Attribute\{McpTool,Schema};
use Mcp\Schema\ToolAnnotations;
#[McpTool(name:'mautic_manage_inbox', annotations:new ToolAnnotations(readOnlyHint:false, destructiveHint:false, openWorldHint:true), outputSchema: \MauticPlugin\MauticMcpBundle\OutputSchemas::OBJECT)]
final class ManageInboxTool extends AbstractMcpTool
{
    public function __construct(private InboxToolService $service, private MutationExecutor $mutations) {}
    /** Take, transfer, resolve, reopen, snooze, return to queue, mark read, add a note or assign an AI agent. Read the conversation first. data.version is required for transitions/AI assignment; target_user_id for transfer, until ISO date for snooze, body for note, agent key for assign_ai. AI assignment may produce external replies. Requires explicit confirm=true; default only previews. */
    #[McpTool(name:'mautic_manage_inbox', annotations:new ToolAnnotations(readOnlyHint:false, destructiveHint:false, openWorldHint:true), outputSchema: \MauticPlugin\MauticMcpBundle\OutputSchemas::OBJECT)]
    public function __invoke(#[Schema(enum: ['take','transfer','resolve','reopen','snooze','unassign','read','note','assign_ai'])] string $action, int $id, #[Schema(type:'object',additionalProperties:true)] array $data=[], bool $confirm=false, ?string $idempotencyKey=null): array
    {
        $this->bootstrapExecution();
        $this->service->authorize('edit');
        $payload=compact('action','id','data');
        if(!$confirm)return $this->mutations->dryRun('inbox',$action,$payload);
        return $this->mutations->execute('inbox', $idempotencyKey,$payload,fn()=> $this->service->manage($action,$id,$data));
    }
}
