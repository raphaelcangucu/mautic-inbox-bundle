<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Mcp;
use MauticPlugin\MauticMcpBundle\Mcp\Tool\AbstractMcpTool;
use MauticPlugin\MauticMcpBundle\Application\Management\MutationExecutor;
use Mcp\Capability\Attribute\{McpTool,Schema};
use Mcp\Schema\ToolAnnotations;
#[McpTool(name:'mautic_reply_inbox', annotations:new ToolAnnotations(readOnlyHint:false, destructiveHint:false, openWorldHint:true), outputSchema: \MauticPlugin\MauticMcpBundle\OutputSchemas::OBJECT)]
final class ReplyInboxTool extends AbstractMcpTool
{
    public function __construct(private InboxToolService $service, private MutationExecutor $mutations) {}
    /** Reply through the support inbox as the authenticated operator. Preserves ownership, channel windows and safety limits. Read templates for WhatsApp outside the reply window. Requires a unique request_id and explicit confirm=true. Accepted means queued, not delivered. */
    #[McpTool(name:'mautic_reply_inbox', annotations:new ToolAnnotations(readOnlyHint:false, destructiveHint:false, openWorldHint:true), outputSchema: \MauticPlugin\MauticMcpBundle\OutputSchemas::OBJECT)]
    public function __invoke(int $id, string $request_id, string $body='', ?int $template_id=null, #[Schema(type:'object',additionalProperties:true)] array $variables=[], bool $confirm=false): array
    {
        $this->bootstrapExecution();
        $this->service->authorize('create');
        $data=compact('request_id','body','variables');if($template_id!==null)$data['template_id']=$template_id;
        if(!$confirm)return $this->mutations->dryRun('inbox','reply',['id'=>$id]+$data);
        return $this->service->manage('reply',$id,$data);
    }
}
