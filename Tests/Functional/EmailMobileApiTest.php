<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\FormBundle\Entity\Form;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\MauticInboxBundle\Application\Mobile\SessionStore;
use MauticPlugin\MauticInboxBundle\Controller\MobileAuthController;
use MauticPlugin\MauticMetaBundle\Domain\AssetType;
use MauticPlugin\MauticMetaBundle\Entity\MetaAsset;
use MauticPlugin\MauticMetaBundle\Entity\MetaConnection;
use MauticPlugin\MauticMetaBundle\Entity\MetaOutboundJob;

/**
 * O contrato do app (plano, secao 1.6) pela API mobile de verdade, com Bearer: a opcao de
 * e-mail no sheet, o inicio da conversa, a resposta com assunto, os formularios e o link do
 * original sem provider.
 */
final class EmailMobileApiTest extends MauticMysqlTestCase
{
    private string $token = '';

    public function testTheAppOpensAnEmailConversationAndRepliesWithASubject(): void
    {
        $this->client->disableReboot();
        $connection = (new MetaConnection())->setName('E-mail (Multi Mail)')->setAppId('multimail-email')->setStatus('inactive');
        $asset = (new MetaAsset())->setConnection($connection)->setName('Macro Markets')->setExternalId(str_repeat('b', 32))->setType(AssetType::EmailAccount)->setStatus('active');
        $asset->setIsPublished(true);
        $asset->setSettings(['email_address' => 'contato@macromarkets.com', 'email_from_name' => 'Macro Markets', 'email_inbound_status' => 'ok', 'email_inbound_since' => null]);
        $contact = (new Lead())->setFirstname('João')->setEmail('Joao@Example.com');
        $form = (new Form())->setName('Pesquisa')->setAlias('pesquisamobile')->setFormType('standalone');
        $form->setIsPublished(true);
        foreach ([$connection, $asset, $contact, $form] as $entity) { $this->em->persist($entity); }
        $this->em->flush();
        $this->signIn();

        $this->client->request('GET', '/inbox/mobile/config');
        self::assertContains('email', $this->json()['capabilities']['channels']);

        $this->api('GET', 'contacts/'.$contact->getId());
        self::assertResponseIsSuccessful();
        $option = current(array_filter($this->json()['channels'], static fn (array $row): bool => 'email:'.$asset->getId() === $row['key']));
        self::assertSame(['key' => 'email:'.$asset->getId(), 'state_id' => null, 'asset_id' => $asset->getId(), 'channel' => 'email', 'name' => 'Macro Markets · contato@macromarkets.com', 'address' => 'contato@macromarkets.com', 'available' => true, 'reason' => null], $option);

        $this->api('POST', 'contacts/'.$contact->getId().'/start', ['channel_key' => 'email:'.$asset->getId()]);
        self::assertResponseIsSuccessful();
        $summary = $this->json();
        self::assertSame('email', $summary['channel']);
        self::assertSame('joao@example.com', $summary['recipient']);
        self::assertSame('contato@macromarkets.com', $summary['asset']['address']);
        self::assertSame('email_account', $summary['asset']['type']);
        self::assertNull($summary['subject']);
        self::assertSame(['inbound' => 'ok', 'inbound_since' => null], $summary['email']);
        self::assertTrue($summary['can_reply'], (string) $summary['reply_blocked_reason']);

        $this->api('POST', 'conversations/'.$summary['id'].'/reply', ['body' => 'Oi João, tudo bem? [[form:'.$form->getId().']]', 'request_id' => 'mobile_email_reply_00002', 'subject' => 'Por que você ainda não depositou?']);
        self::assertResponseStatusCodeSame(202, (string) $this->client->getResponse()->getContent());
        self::assertSame('Por que você ainda não depositou?', $this->json()['item']['subject']);
        self::assertArrayNotHasKey('html', $this->json()['item']['email'], 'O app nao recebe o HTML.');
        $job = $this->em->getRepository(MetaOutboundJob::class)->findOneBy(['idempotencyKey' => 'inbox:mobile_email_reply_00002']);
        self::assertSame('email_message', $job?->getOperation());
        self::assertStringContainsString('/inbox/f/', $job->getPayload()['text']);

        $this->api('GET', 'conversations/'.$summary['id'].'/history');
        self::assertResponseIsSuccessful();
        $outbound = current(array_filter($this->json()['items'], static fn (array $row): bool => 'outbound' === $row['kind']));
        self::assertSame('Por que você ainda não depositou?', $outbound['subject']);

        $this->api('GET', 'forms');
        self::assertResponseIsSuccessful();
        self::assertContains(['id' => $form->getId(), 'name' => 'Pesquisa'], $this->json());

        $this->api('POST', 'messages/999999/original-link');
        self::assertResponseStatusCodeSame(404);
        $this->api('GET', 'email/999999/attachments/0');
        self::assertResponseStatusCodeSame(404);

        // Por ultimo: a recusa acontece dentro da transacao do envio, que fecha o
        // EntityManager deste kernel sem reboot (numa requisicao real, o proximo pedido e outro).
        $second = (new Lead())->setFirstname('Maria')->setEmail('maria@example.com');
        $this->em->persist($second);
        $this->em->flush();
        $this->api('POST', 'contacts/'.$second->getId().'/start', ['channel_key' => 'email:'.$asset->getId()]);
        $this->api('POST', 'conversations/'.$this->json()['id'].'/reply', ['body' => 'Oi Maria', 'request_id' => 'mobile_email_reply_00001']);
        self::assertResponseStatusCodeSame(422, 'O primeiro e-mail da conversa precisa de assunto.');
        self::assertNotEmpty($this->json()['error']);
    }

    protected function beforeTearDown(): void
    {
        if ('' !== $this->token) { static::getContainer()->get(SessionStore::class)->revoke($this->token); }
    }

    private function signIn(): void
    {
        $admin = $this->em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        $store = static::getContainer()->get(SessionStore::class);
        $verifier = str_repeat('v', 64);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $code = $store->authorize((int) $admin->getId(), hash('sha256', (string) $admin->getPassword()), $challenge, MobileAuthController::REDIRECT);
        $this->token = $store->exchange($code, $verifier, MobileAuthController::REDIRECT)['access_token'];
    }

    /** @param array<string,mixed>|null $body */
    private function api(string $method, string $resource, ?array $body = null): void
    {
        $this->client->request($method, '/inbox/mobile/api/'.$resource, [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.$this->token, 'CONTENT_TYPE' => 'application/json'], null === $body ? null : json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string,mixed> */
    private function json(): array
    {
        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
