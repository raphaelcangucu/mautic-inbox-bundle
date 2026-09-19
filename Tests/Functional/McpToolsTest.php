<?php
namespace MauticPlugin\MauticInboxBundle\Tests\Functional;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use MauticPlugin\MauticInboxBundle\Mcp\{ReadInboxTool,ManageInboxTool,ReadInboxAiTool,ManageInboxAiTool};
use MauticPlugin\MauticInboxBundle\Entity\{ConversationState,Note};
use MauticPlugin\MauticMetaBundle\Entity\{MetaConnection,MetaAsset,MetaConversation};
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
final class McpToolsTest extends MauticMysqlTestCase
{
    private function fixture(): ConversationState
    {
        $this->client->disableReboot();
        $c=(new MetaConnection())->setName('MCP test')->setStatus('active');
        $a=(new MetaAsset())->setConnection($c)->setName('MCP Facebook')->setExternalId('123')->setType(AssetType::FacebookPage)->setStatus('active')->setIsPublished(true);
        $conversation=(new MetaConversation())->setAsset($a)->setChannel('facebook')->setRecipient('456');
        $s=(new ConversationState())->setConversation($conversation);
        foreach([$c,$a,$conversation,$s]as$e)$this->em->persist($e);$this->em->flush();return $s;
    }
    public function testProviderServicesAndReadCatalog(): void
    {
        $s=$this->fixture();$c=static::getContainer();
        $dirs=$c->getParameter('mcp.discovery.scan_dirs');
        self::assertContains('plugins/MauticInboxBundle/Mcp',$dirs);
        self::assertContains('plugins/MauticMetaBundle/Mcp',$dirs);
        $result=$c->get(ReadInboxTool::class)('conversations',null,['queue'=>'all','channel'=>'facebook']);
        self::assertSame(200,$result['httpStatus']);self::assertCount(1,$result['items']);
        self::assertSame($s->getId(),$result['items'][0]['id']);
        self::assertInstanceOf(\MauticPlugin\MauticMetaBundle\Mcp\Tool\SendMetaMessageTool::class,$c->get(\MauticPlugin\MauticMetaBundle\Mcp\Tool\SendMetaMessageTool::class));
    }
    public function testPreviewCannotMutateAndTakeChecksVersion():void
    {
        $s=$this->fixture();$tool=static::getContainer()->get(ManageInboxTool::class);
        self::assertTrue($tool('take',$s->getId(),['version'=>$s->getVersion()])['dryRun']);
        $this->em->refresh($s);self::assertNull($s->getAssignee());$version=$s->getVersion();
        $taken=$tool('take',$s->getId(),['version'=>$version],true);
        self::assertSame(200,$taken['httpStatus']);
        $stale=$tool('resolve',$s->getId(),['version'=>$version],true);self::assertSame(409,$stale['httpStatus']);
        // A chave precisa ser nova a cada execucao. O executor guarda o par
        // chave->hash-do-payload num cache de 24 horas, e o payload carrega o id da
        // conversa, que muda de uma execucao para a outra. Com uma chave fixa a segunda
        // execucao do dia batia em "chave ja usada com outro payload" e a suite so voltava
        // a passar depois de alguem apagar o cache -- uma falha que parecia intermitente e
        // nao era.
        //
        // As duas chamadas continuam dividindo a MESMA chave, que e o que este teste
        // afirma: repetir a mesma nota nao cria duas.
        $once='test-note-'.bin2hex(random_bytes(8));
        $note=$tool('note',$s->getId(),['body'=>'MCP test note'],true,$once);
        $again=$tool('note',$s->getId(),['body'=>'MCP test note'],true,$once);
        self::assertSame($note['id'],$again['id']);self::assertCount(1,$this->em->getRepository(Note::class)->findAll());
    }
    public function testDocumentsUseDraftPublicationAndDoNotExposeCredentials():void
    {
        $tool=static::getContainer()->get(ManageInboxAiTool::class);
        $preview=$tool('document',['name'=>'test.md','body'=>'# Test','scope'=>'agent']);self::assertTrue($preview['dryRun']);
        $saved=$tool('document',['key'=>'mcp-test','name'=>'test.md','body'=>'# Test','scope'=>'agent','revision'=>0],true);
        self::assertSame(200,$saved['httpStatus']);
        $read=static::getContainer()->get(ReadInboxAiTool::class)('documents','mcp-test');self::assertSame('# Test',$read['item']['draft']['body']);self::assertEmpty($read['item']['published']??null);
        $health=static::getContainer()->get(ReadInboxAiTool::class)('health');self::assertStringNotContainsString('access_token',json_encode($health));
    }
    public function testActualRegistryContainsPluginToolsWithoutDuplicateNames():void
    {
        $c=static::getContainer();$c->get('mcp.server');$registry=$c->get('mcp.registry');
        foreach(['mautic_read_inbox','mautic_manage_inbox','mautic_reply_inbox','mautic_read_inbox_ai','mautic_manage_inbox_ai','mautic_send_meta_message','mautic_read_meta']as$name)self::assertSame($name,$registry->getTool($name)->tool->name);
        $send=$registry->getTool('mautic_send_meta_message')->tool;
        self::assertContains('facebook',$send->inputSchema['properties']['channel']['enum']);
    }
    public function testAiConfigurationRejectsNonAdmin():void
    {
        $user=static::getContainer()->get(\Mautic\CoreBundle\Helper\UserHelper::class)->getUser(true);
        $user->setRole((new \Mautic\UserBundle\Entity\Role())->setName('No permissions')->setIsAdmin(false));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);
        static::getContainer()->get(ReadInboxAiTool::class)('overview');
    }

    public function testFacebookSendQueuesAndRejectsInternalOverrides():void
    {
        $state=$this->fixture();$service=static::getContainer()->get(\MauticPlugin\MauticMetaBundle\Mcp\Application\MetaService::class);
        $result=$service->send('facebook','direct_message',$state->getConversation()->getAsset()->getId(),'456',['text'=>'Fixture only'],null,true,1);
        self::assertSame('facebook_direct_message',$result['operation']);self::assertSame('queued',$result['status']);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\BadRequestHttpException::class);
        $service->send('facebook','direct_message',$state->getConversation()->getAsset()->getId(),'456',['text'=>'Fixture only','_origin'=>'inbox_human'],null,true,1);
    }

}
