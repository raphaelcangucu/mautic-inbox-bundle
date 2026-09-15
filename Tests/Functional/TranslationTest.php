<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Functional;

use Mautic\CoreBundle\Test\MauticMysqlTestCase;
use Mautic\UserBundle\Entity\User;
use PHPUnit\Framework\Attributes\DataProvider;

final class TranslationTest extends MauticMysqlTestCase
{
    public static function locales(): iterable
    {
        yield 'Portuguese' => ['pt_BR', 'Conversas', 'Conexões e contas', 'Fila inválida.'];
        yield 'English' => ['en_US', 'Conversations', 'Connections and accounts', 'Invalid queue.'];
    }

    #[DataProvider('locales')]
    public function testUserLocaleAppliesToPagesJavaScriptAndApi(string $locale, string $conversations, string $connections, string $error): void
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['username' => 'admin']);
        $user->setLocale($locale);
        $this->em->flush();
        $this->loginUser($user);
        // KernelBrowser bypasses interactive login, which normally populates _locale.
        $session = static::getContainer()->get('session.factory')->createSession();
        $session->setId($this->client->getCookieJar()->get($session->getName())->getValue());
        $session->start();
        $session->set('_locale', $locale);
        $session->save();
        $crawler = $this->client->request('GET', '/s/atendimento');
        self::assertResponseIsSuccessful();
        $app = $crawler->filter('#inbox-app');
        self::assertSame($locale, $app->attr('data-locale'));
        self::assertStringContainsString($conversations, $app->text());
        $catalog = json_decode($app->attr('data-translations'), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains($conversations, $catalog);
        self::assertStringNotContainsString('mautic.inbox.ui.', $app->text());
        $crawler = $this->client->request('GET', '/s/meta/connections');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($connections, $crawler->filter('.meta-ui')->text());
        self::assertStringNotContainsString('mautic.meta.ui.', $crawler->filter('.meta-ui')->text());
        $this->client->request('GET', '/s/atendimento/api/conversas?queue=invalid');
        self::assertResponseStatusCodeSame(422);
        self::assertSame($error, json_decode($this->client->getResponse()->getContent(), true)['error']);
    }
}
