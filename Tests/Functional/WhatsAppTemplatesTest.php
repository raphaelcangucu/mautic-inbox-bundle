<?php

declare(strict_types=1);
namespace MauticPlugin\MauticInboxBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Application\{WhatsAppTemplates,ConversationActions,InboxException,ReplyAvailability};
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticMetaBundle\Entity\{MetaConnection,MetaAsset,MetaConversation,WhatsAppTemplate,MetaOutboundJob};
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Infrastructure\MetaGraphClientInterface;
use MauticPlugin\MauticMetaBundle\Application\Contact\IdentityManager;
use MauticPlugin\MauticMetaBundle\Application\WhatsApp\PhoneNormalizer;

final class WhatsAppTemplatesTest extends MauticMysqlTestCase
{
    private function fixture(bool $consent = true): array
    {
        $graph = $this->createMock(MetaGraphClientInterface::class);
        $graph->expects(self::never())->method('post');
        $graph->method('get')->willReturnCallback(static fn ($connection, $path): array => ['data' => str_starts_with($path, 'waba-one/') ? [['id' => 'phone-one']] : []]);
        $identities = static::getContainer()->get(IdentityManager::class);
        $service = new WhatsAppTemplates($this->em, $graph, $identities, new PhoneNormalizer());
        static::getContainer()->set(WhatsAppTemplates::class, $service);
        $connection = (new MetaConnection())->setName('Template test')->setStatus('active');
        $phone = (new MetaAsset())->setConnection($connection)->setName('Phone')->setExternalId('phone-one')->setType(AssetType::WhatsAppPhoneNumber)->setStatus('active')->setIsPublished(true);
        $waba = (new MetaAsset())->setConnection($connection)->setName('WABA')->setExternalId('waba-one')->setType(AssetType::WhatsAppBusinessAccount)->setIsPublished(true);
        $other = (new MetaAsset())->setConnection($connection)->setName('Other WABA')->setExternalId('waba-other')->setType(AssetType::WhatsAppBusinessAccount)->setIsPublished(true);
        $conversation = (new MetaConversation())->setAsset($phone)->setChannel('whatsapp')->setRecipient('5511999999999');
        $actor = $this->em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        $state = (new ConversationState())->setConversation($conversation)->setAssignee($actor)->setHumanTakeover(true);
        $template = (new WhatsAppTemplate())->setBusinessAccount($waba)->setName('weekly_report')->setLanguage('pt_BR')->setStatus('APPROVED')->setComponents([['type'=>'HEADER','format'=>'TEXT','text'=>'Rodada {{1}}'],['type'=>'BODY','text'=>'Olá {{1}}'],['type'=>'FOOTER','text'=>'Macro Markets']]);
        $foreign = (new WhatsAppTemplate())->setBusinessAccount($other)->setName('weekly_report')->setLanguage('pt_BR')->setStatus('APPROVED')->setComponents([['type'=>'BODY','text'=>'Other account']]);
        foreach ([$connection,$phone,$waba,$other,$conversation,$state,$template,$foreign] as $entity) { $this->em->persist($entity); }
        $this->em->flush();
        if ($consent) { $identity=$identities->registerInteraction($phone,'5511999999999'); $identities->optIn($identity,'test-explicit-consent'); $this->em->flush(); }
        return [$service,$state,$actor,$template,$foreign];
    }

    public function testScopesCatalogAndQueuesTemplateOutsideWindowExactlyOnce(): void
    {
        [$service,$state,$actor,$template] = $this->fixture();
        self::assertNotNull(static::getContainer()->get(ReplyAvailability::class)->reason($state));
        $catalog=$service->catalog($state);self::assertCount(1,$catalog);self::assertSame($template->getId(),$catalog[0]['id']);
        $actions=static::getContainer()->get(ConversationActions::class);
        $input=['id'=>$template->getId(),'variables'=>['HEADER:1'=>'26','BODY:1'=>'Raphael']];
        $first=$actions->reply($state,$actor,'','template-request-123456',$input);
        $second=$actions->reply($state,$actor,'','template-request-123456',$input);
        self::assertSame($first->getId(),$second->getId());self::assertSame('whatsapp_template',$first->getJob()->getOperation());
        self::assertSame('5511999999999',$first->getJob()->getPayload()['recipient']);
        self::assertSame($template->getId(),$first->getJob()->getPayload()['_template_id']);
        self::assertStringContainsString('Rodada 26',$first->getBody());self::assertStringContainsString('Olá Raphael',$first->getBody());
        self::assertCount(1,$this->em->getRepository(MetaOutboundJob::class)->findAll());
        self::assertNotNull(static::getContainer()->get(ReplyAvailability::class)->reason($state));
    }

    public function testForeignAccountAndMissingVariablesAreRejected(): void
    {
        [$service,$state,$actor,$template,$foreign]=$this->fixture();
        foreach ([[$foreign->getId(),[]],[$template->getId(),['BODY:1'=>'Raphael']]] as [$id,$variables]) {
            try { $service->prepare($state,$id,$variables);self::fail('Invalid template input accepted'); } catch (InboxException) {}
        }
        self::assertCount(0,$this->em->getRepository(MetaOutboundJob::class)->findAll());
        $template->setComponents([['type'=>'HEADER','format'=>'IMAGE'],['type'=>'BODY','text'=>'Report']]);
        self::assertFalse($service->describe($template)['supported']);
    }

    public function testCatalogHttpRouteReturnsPreviewWithoutSending(): void
    {
        $this->client->disableReboot();
        [$service,$state,$actor,$template]=$this->fixture();
        $this->client->request('GET','/s/atendimento/api/conversas/'.$state->getId().'/modelos');
        self::assertResponseIsSuccessful();
        $data=json_decode($this->client->getResponse()->getContent(),true);
        self::assertNull($data['blocked_reason']);
        self::assertSame($template->getId(),$data['items'][0]['id']);
        self::assertCount(0,$this->em->getRepository(MetaOutboundJob::class)->findAll());
    }

    public function testConsentIsRequiredBeforeTemplateCanBeQueued(): void
    {
        [$service,$state,$actor,$template]=$this->fixture(false);
        self::assertSame('mautic.inbox.template.consent_required',$service->blockedReason($state));
        $this->expectException(InboxException::class);
        $service->prepare($state,$template->getId(),['HEADER:1'=>'26','BODY:1'=>'Raphael']);
    }
}
