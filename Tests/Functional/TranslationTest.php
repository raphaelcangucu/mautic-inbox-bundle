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
        $crawler = $this->client->request('GET', '/s/inbox');
        self::assertResponseIsSuccessful();
        $app = $crawler->filter('#inbox-app');
        self::assertSame($locale, $app->attr('data-locale'));
        // O texto de `#inbox-app` esta vazio no servidor: o Svelte preenche o ponto de
        // montagem no navegador, e este cliente nao executa JavaScript. Afirmar sobre
        // `$app->text()` afirmava sobre string vazia -- a primeira linha era impossivel
        // de passar, e a de "nao contem chave crua" passava sem olhar nada.
        //
        // O que o servidor de fato promete e o catalogo: ele viaja no atributo, ja
        // traduzido, e e dele que a tela inteira sai. E por isso a afirmacao mudou de
        // alvo em vez de sumir.
        $catalog = json_decode($app->attr('data-translations'), true, 512, JSON_THROW_ON_ERROR);
        self::assertContains($conversations, $catalog);
        foreach ($catalog as $key => $value) {
            // Chave que chega ao cliente sem traducao aparece na tela como
            // "mautic.inbox.ui.alguma_coisa" -- e o modo mais comum de um idioma novo
            // entrar quebrado sem ninguem perceber.
            self::assertStringNotContainsString('mautic.inbox.ui.', (string) $value, sprintf('a chave "%s" chegou sem traducao', $key));
        }
        $crawler = $this->client->request('GET', '/s/meta/connections');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($connections, $crawler->filter('.meta-ui')->text());
        self::assertStringNotContainsString('mautic.meta.ui.', $crawler->filter('.meta-ui')->text());
        $this->client->request('GET', '/s/inbox/api/conversations?queue=invalid');
        self::assertResponseStatusCodeSame(422);
        self::assertSame($error, json_decode($this->client->getResponse()->getContent(), true)['error']);
    }
}
