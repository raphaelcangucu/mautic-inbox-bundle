<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Functional;

use Doctrine\Common\Collections\ArrayCollection;
use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Entity\LeadEventLog;
use Mautic\CampaignBundle\Event\PendingEvent;
use Mautic\CampaignBundle\EventCollector\Accessor\Event\ActionAccessor;
use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\FormBundle\Entity\Form;
use Mautic\LeadBundle\Entity\DoNotContact as DNC;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\DoNotContact;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Application\Email\InboxSigner;
use MauticPlugin\MauticInboxBundle\Application\Forms\FormActivity;
use MauticPlugin\MauticInboxBundle\Application\Forms\FormLinks;
use MauticPlugin\MauticInboxBundle\Application\Mobile\ContactDirectory;
use MauticPlugin\MauticInboxBundle\Entity\ConversationState;
use MauticPlugin\MauticInboxBundle\Entity\EventLog;
use MauticPlugin\MauticInboxBundle\Entity\OutboundRequest;
use MauticPlugin\MauticInboxBundle\EventListener\EmailCampaignSubscriber;
use MauticPlugin\MauticInboxBundle\Integration\MetaInboxIntegration;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaConversation;
use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;

/**
 * O canal de e-mail de ponta a ponta no Inbox, sem o Multi Mail: conversa, resposta com
 * assunto, formulario, abertura pelo sheet de canais, acao de campanha e descadastro. O envio
 * em si e do despachante do Multi Mail; aqui o que importa e o que o Inbox poe na fila.
 */
final class EmailChannelTest extends MauticMysqlTestCase
{
    /** @var array<string,string> */
    private array $headers = [];
    private MetaAsset $asset;
    private Lead $contact;
    private ConversationState $state;

    public function testAnEmailConversationIsListedWithItsSubjectAccountAndInboundHealth(): void
    {
        $this->emailConversation();

        $this->client->request('GET', '/s/inbox/api/conversations?queue=all&channel=email');
        self::assertResponseIsSuccessful();
        $item = current(array_filter($this->json()['items'], fn (array $row): bool => $row['id'] === $this->state->getId()));
        self::assertIsArray($item);
        self::assertSame('email', $item['channel']);
        self::assertSame('Por que você ainda não depositou?', $item['subject']);
        self::assertSame('email_account', $item['asset']['type']);
        self::assertSame('contato@macromarkets.com', $item['asset']['address']);
        self::assertSame(['inbound' => 'delayed', 'inbound_since' => '2026-10-24T10:00:00+00:00'], $item['email']);
        self::assertSame('Oi Ana, ainda estou decidindo. **Qual o depósito mínimo?**', $item['preview']);
        self::assertSame('João Lead', $item['contact_name']);
    }

    public function testTheTimelineCarriesTheEmailFieldsAndNoMissingFileIsEverServed(): void
    {
        $this->emailConversation();

        $this->client->request('GET', '/s/inbox/api/conversations/'.$this->state->getId().'/history');
        self::assertResponseIsSuccessful();
        $item = current(array_filter($this->json()['items'], static fn (array $row): bool => 'message' === $row['kind']));
        self::assertSame('Por que você ainda não depositou?', $item['subject']);
        self::assertSame(['name' => 'João Lead', 'address' => 'joao@example.com'], $item['email']['from']);
        self::assertSame([['name' => 'Macro Markets', 'address' => 'contato@macromarkets.com']], $item['email']['to'], 'O token do +tag nunca aparece.');
        self::assertSame('> Em 23/10, Ana escreveu: ...', $item['email']['quoted']);
        self::assertTrue($item['email']['has_html']);
        self::assertStringNotContainsString('<script', $item['email']['html']);
        self::assertCount(2, $item['attachments']);
        // Sem o Multi Mail nao ha provider e nada esta disponivel; com ele, o provider responde
        // pela mensagem mesmo que o arquivo nao exista nesta base de teste.
        $message = $this->em->find(MetaMessage::class, $item['id']);
        $stored = static::getContainer()->get(\MauticPlugin\MauticInboxBundle\Application\Email\EmailContentRegistry::class)->supports($message);
        self::assertSame($stored, $item['email']['has_original']);
        self::assertSame($stored, $item['attachments'][0]['available']);
        self::assertSame($stored, null !== $item['attachments'][0]['url']);
        self::assertFalse($item['attachments'][1]['available'], 'O que o canal marcou indisponivel continua indisponivel.');

        $messageId = $item['id'];
        $this->client->request('GET', '/s/inbox/api/email/'.$messageId.'/attachments/0');
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/s/inbox/api/messages/'.$messageId.'/original');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnOldEmailConversationCanStillBeAnsweredAndTheSubjectRepliesToTheLastOne(): void
    {
        $this->emailConversation('-10 days');
        $this->take();

        $sent = $this->reply('Oi João, o depósito mínimo é R$ 100.', 'email_reply_subject_0001');

        $job = $this->job('email_reply_subject_0001');
        self::assertSame('email_message', $job->getOperation());
        self::assertSame('joao@example.com', $job->getPayload()['recipient']);
        self::assertSame('Re: Por que você ainda não depositou?', $job->getPayload()['subject']);
        self::assertSame('Oi João, o depósito mínimo é R$ 100.', $job->getPayload()['text']);
        self::assertSame('Ana', $job->getPayload()['author_name']);
        self::assertSame('inbox_human', $job->getPayload()['_origin']);
        self::assertSame(9, $job->getMaxAttempts());
        self::assertSame('Re: Por que você ainda não depositou?', $sent['item']['subject']);
        self::assertSame('joao@example.com', $sent['item']['email']['to'][0]['address']);

        $this->reply('Segunda mensagem', 'email_reply_subject_0002', 'Proposta nova');
        self::assertSame('Proposta nova', $this->job('email_reply_subject_0002')->getPayload()['subject']);
    }

    public function testAFormMarkerBecomesASignedLinkAndAnUnpublishedFormIsRefused(): void
    {
        $this->emailConversation();
        $this->take();
        $form = $this->form('Pesquisa: por que não depositou');

        $this->reply("Responda, por favor:\n[[form:".$form->getId().']]', 'email_reply_form_000001');
        $text = $this->job('email_reply_form_000001')->getPayload()['text'];
        self::assertMatchesRegularExpression('#\[Pesquisa: por que não depositou\]\(https?://[^/)]+/inbox/f/[A-Za-z0-9_-]+\)#', $text);

        $form->setIsPublished(false);
        $this->em->flush();
        $this->client->request('POST', '/s/inbox/api/conversations/'.$this->state->getId().'/reply', [], [], $this->headers, json_encode(['body' => '[[form:'.$form->getId().']]', 'request_id' => 'email_reply_form_000002'], JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->em->getRepository(MetaOutboundJob::class)->findOneBy(['idempotencyKey' => 'inbox:email_reply_form_000002']), 'Formulario despublicado recusa antes de enfileirar.');
    }

    public function testTheFormLinkTracksTheContactLogsTheOpeningAndTheAnswer(): void
    {
        $this->emailConversation();
        $form = $this->form('Pesquisa');
        $links = static::getContainer()->get(FormLinks::class);
        $url = $links->url((int) $form->getId(), (int) $this->contact->getId(), (int) $this->state->getConversation()->getId());

        $this->client->followRedirects(false);
        $this->client->request('GET', (string) parse_url($url, PHP_URL_PATH));
        self::assertResponseStatusCodeSame(302);
        self::assertStringEndsWith('/form/'.$form->getId(), (string) $this->client->getResponse()->headers->get('Location'));
        self::assertSame(1, $this->events('form_opened'));

        $this->client->request('GET', '/inbox/f/'.str_repeat('A', 43));
        self::assertResponseStatusCodeSame(410);

        static::getContainer()->get(FormActivity::class)->submitted($this->contact, $form, []);
        self::assertSame(1, $this->events('form_submitted'));
        $this->client->request('GET', '/s/inbox/api/conversations/'.$this->state->getId().'/history');
        $answered = current(array_filter($this->json()['items'], static fn (array $row): bool => 'form_submitted' === ($row['event'] ?? null)));
        self::assertSame(['id' => (int) $form->getId(), 'name' => 'Pesquisa'], $answered['form']);
    }

    public function testAnAutomaticReplyEntersTheThreadWithoutAskingForAnAnswer(): void
    {
        $this->emailConversation();
        $this->take();
        // O take limpa ConversationState do EntityManager; a entidade em memoria fica solta. A
        // conversa ja foi respondida: sem o Multi Mail o envio de verdade falharia na fila.
        $this->state = $this->em->getRepository(ConversationState::class)->find($this->state->getId());
        $this->state->setNeedsResponse(false);
        $this->em->persist($this->state);
        $this->em->flush();

        $away = (new MetaMessage())->setAsset($this->asset)->setConversation($this->state->getConversation())->setChannel('email')->setDirection('inbound')
            ->setMessageType('email')->setRecipient('joao@example.com')->setExternalId('email:'.hash('sha256', 'functional-away'))->setStatus('received')
            ->setPayload(['email' => ['from' => ['name' => '', 'address' => 'joao@example.com'], 'subject' => 'Ausente', 'text' => 'Estou de férias', 'kind' => 'auto_reply', 'auto_reply' => true, 'attachments' => []]]);
        $this->em->persist($away);
        $this->em->flush();
        static::getContainer()->get(MetaInboxIntegration::class)->messagePersisted($away);
        $this->em->refresh($this->state);

        self::assertFalse($this->state->needsResponse(), 'Ninguem escreveu: a conversa nao volta para a fila.');
        $this->client->request('GET', '/s/inbox/api/conversations/'.$this->state->getId().'/history');
        $line = current(array_filter($this->json()['items'], static fn (array $row): bool => 'message' === $row['kind'] && 'auto_reply' === ($row['email']['kind'] ?? null)));
        self::assertTrue($line['email']['auto_reply']);
    }

    public function testTheOriginalLinkOfTheAppIsSignedAndNeverShowsAnythingWithoutAProvider(): void
    {
        $this->emailConversation();
        $message = $this->em->getRepository(MetaMessage::class)->findOneBy(['conversation' => $this->state->getConversation(), 'direction' => 'inbound']);
        $admin = $this->em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        $link = static::getContainer()->get(\MauticPlugin\MauticInboxBundle\Application\Email\EmailMessages::class)->originalLink($message, $admin);
        self::assertMatchesRegularExpression('#/inbox/mobile/original/[A-Za-z0-9_-]+$#', $link['url']);
        self::assertGreaterThan(time(), strtotime($link['expires_at']));

        $path = (string) parse_url($link['url'], PHP_URL_PATH);
        $this->client->request('GET', $path);
        self::assertResponseStatusCodeSame(404, 'Sem o Multi Mail nao ha original para mostrar.');
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $tampered = substr($path, 0, -2).('AA' === substr($path, -2) ? 'BB' : 'AA');
        $this->client->request('GET', $tampered);
        self::assertResponseStatusCodeSame(410);
    }

    public function testThePublicPagesSpeakTheLanguageOfTheBrowserThatOpensThem(): void
    {
        $this->emailConversation();
        $message = $this->em->getRepository(MetaMessage::class)->findOneBy(['conversation' => $this->state->getConversation(), 'direction' => 'inbound']);
        $admin = $this->em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        $path = (string) parse_url(static::getContainer()->get(\MauticPlugin\MauticInboxBundle\Application\Email\EmailMessages::class)->originalLink($message, $admin)['url'], PHP_URL_PATH);

        $this->client->request('GET', $path, [], [], ['HTTP_ACCEPT_LANGUAGE' => 'pt-BR,pt;q=0.9,en;q=0.8']);
        self::assertStringContainsString('Mensagem original', (string) $this->client->getResponse()->getContent());
        $this->client->request('GET', $path, [], [], ['HTTP_ACCEPT_LANGUAGE' => 'de-DE,en;q=0.5']);
        self::assertStringContainsString('Original message', (string) $this->client->getResponse()->getContent(), 'A primeira lingua traduzida da lista vale.');
        $this->client->request('GET', '/inbox/f/'.str_repeat('A', 43), [], [], ['HTTP_ACCEPT_LANGUAGE' => 'pt-PT']);
        self::assertStringContainsString('Link expirado', (string) $this->client->getResponse()->getContent(), 'O lead tambem le na lingua dele.');
    }

    public function testABouncedReplyShowsAsFailedAndOffersNoRetry(): void
    {
        $this->emailConversation();
        $this->take();
        $this->reply('Oi João, segue o link.', 'email_reply_bounce_0001');
        // O que o despachante do Multi Mail deixa: a MetaMessage de saida ligada ao job e o envio como "sent".
        $sent = (new MetaMessage())->setAsset($this->asset)->setConversation($this->state->getConversation())->setChannel('email')->setDirection('outbound')
            ->setMessageType('email')->setRecipient('joao@example.com')->setExternalId('email:'.hash('sha256', 'functional-bounced'))->setStatus('sent')
            ->setPayload(['email' => ['from' => ['name' => 'Ana · Macro Markets', 'address' => 'contato@macromarkets.com'], 'to' => [['name' => 'João', 'address' => 'joao@example.com']], 'subject' => 'Re: Por que você ainda não depositou?', 'text' => 'Oi João, segue o link.', 'kind' => 'message', 'attachments' => []]]);
        $this->em->persist($sent);
        $this->em->flush();
        $job = $this->job('email_reply_bounce_0001');
        $job->setMessageLogId((int) $sent->getId());
        $request = $this->em->getRepository(OutboundRequest::class)->findOneBy(['requestId' => 'email_reply_bounce_0001']);
        $request->setStatus('sent');
        $this->em->persist($job);
        $this->em->persist($request);
        $this->em->flush();
        self::assertSame('sent', $this->outbound('email_reply_bounce_0001')['status']);

        // O DSN chega minutos depois e marca a mensagem de saida, nao o envio.
        $payload = $sent->getPayload();
        $payload['email']['bounce'] = ['status' => '5.1.1', 'diagnostic' => 'smtp; 550 5.1.1 User unknown', 'recipient' => 'joao@example.com', 'at' => '2026-10-25T05:00:00+00:00'];
        $sent->setStatus('failed')->setPayload($payload);
        $this->em->persist($sent);
        $this->em->flush();

        $item = $this->outbound('email_reply_bounce_0001');
        self::assertSame('failed', $item['status']);
        self::assertSame('email_bounced', $item['failure_code']);
        self::assertStringContainsString('5.1.1 550 5.1.1 User unknown', (string) $item['failure']);
        self::assertFalse($item['retryable'], 'Reenviar ao mesmo endereco volta do mesmo jeito.');
    }

    public function testEmailDncClosesTheComposerWithTheReason(): void
    {
        $this->emailConversation();
        $this->take();
        static::getContainer()->get(DoNotContact::class)->addDncForContact($this->contact, 'email', DNC::UNSUBSCRIBED);

        $this->client->request('GET', '/s/inbox/api/conversations/'.$this->state->getId());
        self::assertResponseIsSuccessful();
        self::assertFalse($this->json()['can_reply']);
        self::assertNotSame('mautic.inbox.ui.email_contact_unsubscribed', $this->json()['reply_blocked_reason']);
        self::assertNotEmpty($this->json()['reply_blocked_reason']);
    }

    public function testTheChannelSheetOffersEachEmailAccountAndStartsALowercasedConversation(): void
    {
        $this->client->disableReboot();
        $this->account();
        $contact = (new Lead())->setFirstname('Maria')->setEmail('Maria.Silva@Example.com');
        $other = (new Lead())->setFirstname('Outra')->setEmail('outra@example.com');
        $this->em->persist($contact);
        $this->em->persist($other);
        $this->em->flush();
        $admin = $this->em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        $directory = static::getContainer()->get(ContactDirectory::class);

        $option = current(array_filter($directory->detail((int) $contact->getId(), $admin)['channels'], static fn (array $row): bool => str_starts_with($row['key'], 'email:')));
        self::assertSame([
            'key' => 'email:'.$this->asset->getId(),
            'state_id' => null,
            'asset_id' => $this->asset->getId(),
            'channel' => 'email',
            'name' => 'Macro Markets · contato@macromarkets.com',
            'address' => 'contato@macromarkets.com',
            'available' => true,
            'reason' => null,
        ], $option);

        $state = $directory->start((int) $contact->getId(), $admin, ['channel_key' => 'email:'.$this->asset->getId()]);
        self::assertSame('maria.silva@example.com', $state->getConversation()->getRecipient());
        self::assertSame('email', $state->getConversation()->getChannel());
        self::assertSame($admin->getId(), $state->getAssignee()?->getId());
        self::assertTrue($state->isHumanTakeover());
        self::assertSame(0, $this->em->getRepository(MetaOutboundJob::class)->count([]), 'Abrir a conversa nao envia nada.');

        $other->setEmail('maria.silva@example.com');
        $this->em->flush();
        try {
            $directory->start((int) $other->getId(), $admin, ['channel_key' => 'email:'.$this->asset->getId()]);
            self::fail('O e-mail ja pertence a outro contato.');
        } catch (\MauticPlugin\MauticInboxBundle\Application\InboxException $error) {
            self::assertSame(409, $error->httpStatus);
        }

        // Uma conversa que o Multi Mail abriu sem contato (remetente ambiguo) ganha o vinculo
        // quando o atendente a abre pelo sheet -- e o vinculo chega ao banco.
        $third = (new Lead())->setFirstname('Carla')->setEmail('carla@example.com');
        $this->em->persist($third);
        $unlinked = (new MetaConversation())->setAsset($this->asset)->setChannel('email')->setRecipient('carla@example.com');
        $this->em->persist($unlinked);
        $this->em->flush();
        $directory->start((int) $third->getId(), $admin, ['channel_key' => 'email:'.$this->asset->getId()]);
        $conversationId = $unlinked->getId();
        $this->em->clear();
        self::assertSame($third->getId(), $this->em->find(MetaConversation::class, $conversationId)?->getContact()?->getId());
    }

    public function testTheCampaignActionHasFourOutcomesAndNeverDuplicates(): void
    {
        $this->client->disableReboot();
        $this->account();
        $ok = (new Lead())->setFirstname('Rafa')->setEmail('rafa@example.com');
        $noEmail = (new Lead())->setFirstname('Sem');
        $unsubscribed = (new Lead())->setFirstname('Dnc')->setEmail('dnc@example.com');
        $busy = (new Lead())->setFirstname('Ocupado')->setEmail('ocupado@example.com');
        foreach ([$ok, $noEmail, $unsubscribed, $busy] as $lead) { $this->em->persist($lead); }
        $this->em->flush();
        static::getContainer()->get(DoNotContact::class)->addDncForContact($unsubscribed, 'email', DNC::UNSUBSCRIBED);
        $admin = $this->em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        $busyConversation = (new MetaConversation())->setAsset($this->asset)->setChannel('email')->setRecipient('ocupado@example.com')->setContact($busy);
        $this->em->persist($busyConversation);
        $this->em->persist((new ConversationState())->setConversation($busyConversation)->setAssignee($admin)->setHumanTakeover(true));

        $campaign = (new Campaign())->setName('Depósito');
        $this->em->persist($campaign);
        $event = (new Event())->setName('Iniciar conversa')->setType(EmailCampaignSubscriber::ACTION)->setEventType('action')->setCampaign($campaign)
            ->setProperties(['asset_id' => $this->asset->getId(), 'from_name' => '', 'subject' => 'Oi {contactfield=firstname}', 'message' => 'Por que você ainda não depositou?']);
        $this->em->persist($event);
        $logs = [];
        foreach ([$ok, $noEmail, $unsubscribed, $busy] as $lead) {
            $log = (new LeadEventLog())->setLead($lead)->setCampaign($campaign)->setEvent($event);
            $this->em->persist($log);
            $logs[] = $log;
        }
        $this->em->flush();

        $pending = $this->runCampaign($event, $logs);
        // Sem e-mail e DNC nao mudam sozinhos: fecham com o motivo, sem nova tentativa. So a
        // conversa em atendimento falha, para o Mautic tentar de novo depois.
        $passed = array_values(array_map(static fn (LeadEventLog $log): int => (int) $log->getId(), $pending->getSuccessful()->toArray()));
        sort($passed);
        self::assertSame([$logs[0]->getId(), $logs[1]->getId(), $logs[2]->getId()], $passed);
        self::assertArrayNotHasKey('failed', $logs[0]->getMetadata());
        self::assertSame(1, $logs[1]->getMetadata()['failed'] ?? null);
        self::assertSame(1, $logs[2]->getMetadata()['failed'] ?? null);
        self::assertSame([$logs[3]->getId()], array_values(array_map(static fn (LeadEventLog $log): int => (int) $log->getId(), $pending->getFailures()->toArray())));
        $job = $this->em->getRepository(MetaOutboundJob::class)->findOneBy(['idempotencyKey' => 'campaign:'.$logs[0]->getId()]);
        self::assertInstanceOf(MetaOutboundJob::class, $job);
        self::assertSame('Oi Rafa', $job->getPayload()['subject']);
        self::assertSame('inbox_campaign', $job->getPayload()['_origin']);
        self::assertMatchesRegularExpression('#/inbox/u/[A-Za-z0-9_-]+$#', $job->getPayload()['unsubscribe_url']);
        $conversation = $this->em->getRepository(MetaConversation::class)->findOneBy(['asset' => $this->asset, 'recipient' => 'rafa@example.com']);
        self::assertSame($ok->getId(), $conversation?->getContact()?->getId());

        $again = $this->runCampaign($event, [$logs[0]]);
        self::assertCount(1, $again->getSuccessful());
        self::assertSame(1, $this->em->getRepository(MetaOutboundJob::class)->count(['idempotencyKey' => 'campaign:'.$logs[0]->getId()]), 'Reexecutar o mesmo evento nao duplica.');

        // O descadastro de um clique (RFC 8058): GET nao grava, POST grava.
        $path = (string) parse_url($job->getPayload()['unsubscribe_url'], PHP_URL_PATH);
        $this->client->request('GET', $path);
        self::assertResponseIsSuccessful();
        self::assertSame(DNC::IS_CONTACTABLE, static::getContainer()->get(DoNotContact::class)->isContactable($ok, 'email'));
        $this->client->request('POST', $path, ['List-Unsubscribe' => 'One-Click']);
        self::assertResponseIsSuccessful();
        self::assertSame(DNC::UNSUBSCRIBED, static::getContainer()->get(DoNotContact::class)->isContactable($this->em->find(Lead::class, $ok->getId()), 'email'));
    }

    /** @param list<LeadEventLog> $logs */
    private function runCampaign(Event $event, array $logs): PendingEvent
    {
        $collection = new ArrayCollection();
        foreach ($logs as $log) { $collection->set((int) $log->getId(), $log); }
        $pending = new PendingEvent(new ActionAccessor(['label' => 'x', 'batchEventName' => EmailCampaignSubscriber::BATCH_EVENT]), $event, $collection);
        static::getContainer()->get(EmailCampaignSubscriber::class)->onStart($pending);

        return $pending;
    }

    private function account(): void
    {
        $connection = (new MetaConnection())->setName('E-mail (Multi Mail)')->setAppId('multimail-email')->setStatus('inactive');
        $this->asset = (new MetaAsset())->setConnection($connection)->setName('Macro Markets')->setExternalId(str_repeat('a', 32))->setType(AssetType::EmailAccount)->setStatus('active');
        $this->asset->setIsPublished(true);
        $this->asset->setSettings(['email_address' => 'contato@macromarkets.com', 'email_from_name' => 'Macro Markets', 'email_plus_addressing' => true, 'email_inbound_status' => 'delayed', 'email_inbound_since' => '2026-10-24T10:00:00+00:00']);
        $this->em->persist($connection);
        $this->em->persist($this->asset);
        $this->em->flush();
    }

    private function emailConversation(string $receivedAt = '-5 minutes'): void
    {
        $this->client->disableReboot();
        $this->account();
        $admin = $this->em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        $admin->setFirstName('Ana');
        $this->contact = (new Lead())->setFirstname('João')->setEmail('joao@example.com');
        $this->em->persist($this->contact);
        $conversation = (new MetaConversation())->setAsset($this->asset)->setChannel('email')->setRecipient('joao@example.com')->setContact($this->contact);
        $message = (new MetaMessage())->setAsset($this->asset)->setConversation($conversation)->setChannel('email')->setDirection('inbound')
            ->setMessageType('email')->setRecipient('joao@example.com')->setExternalId('email:'.hash('sha256', 'functional-inbound'))->setStatus('received')
            ->setDateAdded(new \DateTimeImmutable($receivedAt))
            ->setPayload(['email' => [
                'message_id' => '<CAF3x@mail.gmail.com>',
                'from' => ['name' => 'João Lead', 'address' => 'joao@example.com'],
                'to' => [['name' => 'Macro Markets', 'address' => 'contato+rk3xabcdefghijklmnopqrstu@macromarkets.com']],
                'cc' => [],
                'subject' => 'Por que você ainda não depositou?',
                'text' => 'Oi Ana, ainda estou decidindo. **Qual o depósito mínimo?**',
                'quoted' => '> Em 23/10, Ana escreveu: ...',
                'html' => '<div dir="ltr">Oi Ana<script>alert(1)</script></div>',
                'has_original' => true,
                'auto_reply' => false,
                'unverified_sender' => false,
                'kind' => 'message',
                'attachments' => [
                    ['index' => 0, 'type' => 'document', 'name' => 'extrato.pdf', 'mime' => 'application/pdf', 'size' => 183422, 'cid' => null, 'available' => true],
                    ['index' => 1, 'type' => 'image', 'name' => 'foto.png', 'mime' => 'image/png', 'size' => 2048, 'cid' => null, 'available' => false],
                ],
            ]]);
        foreach ([$conversation, $message] as $entity) { $this->em->persist($entity); }
        $this->em->flush();
        static::getContainer()->get(MetaInboxIntegration::class)->messagePersisted($message);
        $this->state = $this->em->getRepository(ConversationState::class)->findOneBy(['conversation' => $conversation]);
        self::assertInstanceOf(ConversationState::class, $this->state);

        $crawler = $this->client->request('GET', '/s/inbox');
        self::assertResponseIsSuccessful();
        $this->headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $crawler->filter('#inbox-app')->attr('data-csrf')];
    }

    private function take(): void
    {
        $this->em->refresh($this->state);
        $this->client->request('POST', '/s/inbox/api/conversations/'.$this->state->getId().'/take', [], [], $this->headers, json_encode(['version' => $this->state->getVersion()], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();
        self::assertTrue($this->json()['can_reply'], (string) ($this->json()['reply_blocked_reason'] ?? ''));
    }

    /** @return array<string,mixed> */
    private function reply(string $body, string $requestId, ?string $subject = null): array
    {
        $this->client->request('POST', '/s/inbox/api/conversations/'.$this->state->getId().'/reply', [], [], $this->headers, json_encode(['body' => $body, 'request_id' => $requestId] + (null === $subject ? [] : ['subject' => $subject]), JSON_THROW_ON_ERROR));
        self::assertResponseStatusCodeSame(202, (string) $this->client->getResponse()->getContent());

        return $this->json();
    }

    private function job(string $requestId): MetaOutboundJob
    {
        $job = $this->em->getRepository(MetaOutboundJob::class)->findOneBy(['idempotencyKey' => 'inbox:'.$requestId]);
        self::assertInstanceOf(MetaOutboundJob::class, $job);

        return $job;
    }

    /** @return array<string,mixed> */
    private function outbound(string $requestId): array
    {
        $this->client->request('GET', '/s/inbox/api/conversations/'.$this->state->getId().'/history');
        self::assertResponseIsSuccessful();
        $item = current(array_filter($this->json()['items'], static fn (array $row): bool => 'outbound' === $row['kind'] && $requestId === $row['request_id']));
        self::assertIsArray($item);

        return $item;
    }

    private function form(string $name): Form
    {
        $form = (new Form())->setName($name)->setAlias('pesquisa'.substr(md5($name), 0, 6))->setFormType('standalone');
        $form->setIsPublished(true);
        $this->em->persist($form);
        $this->em->flush();

        return $form;
    }

    private function events(string $type): int
    {
        return $this->em->getRepository(EventLog::class)->count(['conversation' => $this->state->getConversation(), 'eventType' => $type]);
    }

    /** @return array<string,mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
