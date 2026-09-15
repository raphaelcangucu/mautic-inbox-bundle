<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Mcp;
use MauticPlugin\MauticMcpBundle\Mcp\Tool\AbstractMcpTool;
use MauticPlugin\MauticMcpBundle\Application\Management\MutationExecutor;
use Mcp\Capability\Attribute\{McpTool,Schema};
use Mcp\Schema\ToolAnnotations;
#[McpTool(name:'mautic_read_inbox', annotations:new ToolAnnotations(readOnlyHint:true, destructiveHint:false, openWorldHint:false), outputSchema: \MauticPlugin\MauticMcpBundle\OutputSchemas::OBJECT)]
final class ReadInboxTool extends AbstractMcpTool
{
    public function __construct(private InboxToolService $service, private MutationExecutor $mutations) {}
    /** Read the multichannel support inbox. IDs are inbox state IDs, not Meta conversation IDs. filters: queue mine/unassigned/all, channel whatsapp/instagram/facebook, kind private/comments, lifecycle active/open/snoozed/resolved/all, search, limit (max 50), cursor; timeline uses before/limit. */
    #[McpTool(name:'mautic_read_inbox', annotations:new ToolAnnotations(readOnlyHint:true, destructiveHint:false, openWorldHint:false), outputSchema: \MauticPlugin\MauticMcpBundle\OutputSchemas::OBJECT)]
    public function __invoke(#[Schema(enum: ['conversations','conversation','timeline','templates','agents','users','canned_responses'])] string $resource, ?int $id=null, #[Schema(type:'object',additionalProperties:true)] array $filters=[]): array
    {
        $this->bootstrapExecution();
        return $this->service->read($resource,$id,$filters);
    }
}
