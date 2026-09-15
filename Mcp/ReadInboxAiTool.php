<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Mcp;
use MauticPlugin\MauticMcpBundle\Mcp\Tool\AbstractMcpTool;
use MauticPlugin\MauticMcpBundle\Application\Management\MutationExecutor;
use Mcp\Capability\Attribute\{McpTool,Schema};
use Mcp\Schema\ToolAnnotations;
#[McpTool(name:'mautic_read_inbox_ai', annotations:new ToolAnnotations(readOnlyHint:true, destructiveHint:false, openWorldHint:false), outputSchema: \MauticPlugin\MauticMcpBundle\OutputSchemas::OBJECT)]
final class ReadInboxAiTool extends AbstractMcpTool
{
    public function __construct(private InboxToolService $service, private MutationExecutor $mutations) {}
    /** Admin-only read of AI agents, published/draft Markdown documents and history, global configuration, account permissions and last Pi health validation. Credentials are never returned. */
    #[McpTool(name:'mautic_read_inbox_ai', annotations:new ToolAnnotations(readOnlyHint:true, destructiveHint:false, openWorldHint:false), outputSchema: \MauticPlugin\MauticMcpBundle\OutputSchemas::OBJECT)]
    public function __invoke(#[Schema(enum:['overview','documents','agents','config','assets','health'])] string $resource='overview', ?string $key=null): array
    {
        $this->bootstrapExecution();
        return $this->service->readAi($resource,$key);
    }
}
