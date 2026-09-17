# Web Push Crypto Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construir a fundação criptográfica do Web Push — VAPID e RFC 8291 — sobre `ext-openssl`, sem nenhuma dependência de Composer e sem nenhuma dependência do Mautic.

**Architecture:** Quatro classes puras em `Application/Push/`, sem estado e sem serviço injetado. `Ec` converte entre a forma crua que o padrão Web Push usa e a forma DER que o OpenSSL exige. `Hkdf` implementa a derivação da RFC 5869. `WebPushCrypto` monta o corpo cifrado e o cabeçalho de autorização. `VapidKeys` guarda o par. Tudo é verificado contra os vetores publicados na RFC 8291 — entrada conhecida, saída conhecida, sem julgamento.

**Tech Stack:** PHP 8.2+, `ext-openssl`, PHPUnit. Nenhuma biblioteca externa.

**Esta é a fase 1 de 4** do spec `docs/superpowers/specs/2026-09-16-inbox-pwa-push-design.md`. Ela não produz nada visível ao usuário; produz a peça que todas as outras fases consomem, e é a única que se verifica isoladamente.

---

## Contexto que o implementador precisa

**O problema que estas classes resolvem.** Para notificar um celular, o servidor faz um POST numa URL que o navegador forneceu. O corpo desse POST precisa estar cifrado de um jeito que só aquele navegador abre, e o POST precisa estar assinado de um jeito que prove que é o nosso servidor. São duas camadas independentes, com chaves diferentes, e a confusão entre elas é o erro mais comum nesta área.

**Por que não usamos uma biblioteca.** Decisão registrada no spec: o plugin precisa ser instalável por Composer sem arrastar uma árvore de dependências que conflite com a do Mautic. O algoritmo é fechado e tem vetores de teste oficiais, então a implementação não fica no "confia que está certo".

**A armadilha central.** O PHP não aceita ponto de curva elíptica na forma crua em direção nenhuma. Tudo que entra precisa ser embrulhado em DER; tudo que sai precisa ser desembrulhado e preenchido com zeros à esquerda até 32 octetos. Preenchimento esquecido não dá erro — dá resultado errado em silêncio, e só aparece quando o navegador não consegue decifrar a notificação. Três das oito tarefas existem por causa disso.

**Como ler os blocos de teste.** Da tarefa 2 em diante os testes aparecem como métodos soltos,
para não repetir cabeçalho em dez tarefas. Cada um entra numa classe `final` que estende
`PHPUnit\Framework\TestCase`, no namespace
`MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Push`, com os `use` correspondentes —
o mesmo formato de `Tests/Unit/Entity/CommentContextTest.php`, que já existe no repositório.

**Regra de banco de dados.** Nenhuma tarefa desta fase toca banco. Se você se pegar precisando de banco, parou de seguir o plano.

---

## Estrutura de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `Application/Push/Ec.php` | Conversões entre ponto cru e DER/PEM, e entre assinatura DER e `R\|\|S` |
| `Application/Push/Hkdf.php` | `extract` e `expand` da RFC 5869 |
| `Application/Push/WebPushCrypto.php` | Corpo cifrado `aes128gcm` e cabeçalho `Authorization` do VAPID |
| `Application/Push/VapidKeys.php` | O par de chaves: privada em PEM, pública em base64url cru |
| `Tests/Unit/Application/Push/RfcVectors.php` | Os valores da RFC 8291 §5 e o auxiliar que monta PEM a partir de escalar cru |
| `Tests/Unit/Application/Push/*Test.php` | Um arquivo de teste por classe |
| `bin/dev-test.sh` | Sincroniza com o banco de provas do servidor e roda o PHPUnit |

`Ec` e `Hkdf` são separadas de `WebPushCrypto` porque cada uma tem vetores próprios e falha por motivos próprios. Juntá-las produziria um arquivo em que um erro de preenchimento e um erro de derivação são indistinguíveis.

---

## Tarefa 0: O ciclo de teste

O PHP local desta máquina está quebrado — Homebrew sem `libcapstone`. Os testes rodam no servidor, num checkout que **não** atende tráfego. `releases/inbox-review-20260913-2213` é uma release antiga, não apontada pelo symlink `current`, e serve de banco de provas. Nenhum teste desta fase toca banco, então não há risco à produção.

**Files:**
- Create: `bin/dev-test.sh`

- [ ] **Passo 1: Escrever o script**

```bash
#!/usr/bin/env bash
# Sincroniza o plugin com o banco de provas do servidor e roda o PHPUnit.
# NUNCA aponta para releases/tech-provider-20260914 nem para current: aquilo é produção.
set -euo pipefail

HOST="$INBOX_TEST_HOST"
BENCH="<release de provas>"
TARGET="${1:-plugins/MauticInboxBundle/Tests/Unit}"

# Guarda real: pergunta ao servidor para onde current aponta e recusa se for o mesmo lugar.
# Comparar com um literal fixo nao protegeria nada, porque BENCH tambem e literal.
# readlink -f canonicaliza: um symlink relativo faria a comparacao nunca casar, e a guarda
# passaria a mentir em silencio — que e exatamente o que ela existe para evitar.
CURRENT="$(ssh "$HOST" 'readlink -f <release em producao>')"
if [[ "$BENCH" == "$CURRENT" ]]; then
  echo "RECUSADO: o banco de provas e a release que atende producao." >&2
  exit 1
fi

rsync -az --delete \
  --exclude='.git/' --exclude='node_modules/' --exclude='docs/' \
  ./ "$HOST:$BENCH/plugins/MauticInboxBundle/"

# php8.4 explicito: e o que o PHP-FPM do site usa. O `php` do PATH e 8.5, e divergencia de
# versao entre o teste e a producao e exatamente o tipo de surpresa que nao queremos aqui.
ssh "$HOST" "cd $BENCH && php8.4 bin/phpunit -c app/phpunit.xml.dist $TARGET --testdox"
```

- [ ] **Passo 2: Torná-lo executável e confirmar que o banco de provas responde**

```bash
chmod +x bin/dev-test.sh
ssh $INBOX_TEST_HOST 'ls <release de provas>/bin/phpunit && php8.4 -v'
```

Esperado: o caminho existe e o PHP 8.4 responde. Confira os dois aqui: toda tarefa seguinte
depende do `php8.4` pelo nome, e descobrir que ele não está no PATH durante a tarefa 1 produz uma
falha confusa no lugar de uma falha clara.

- [ ] **Passo 3: Commit**

```bash
git add bin/dev-test.sh
git commit -m "Add a test loop that never targets the production release"
```

---

## Tarefa 1: Os vetores da RFC como fixture

Todos os testes subsequentes consomem estes valores. Eles são copiados da RFC 8291, seção 5 e apêndice A — **não os altere e não os reescreva de memória**.

**Files:**
- Create: `Tests/Unit/Application/Push/RfcVectors.php`

- [ ] **Passo 1: Escrever a fixture**

```php
<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Push;

/**
 * Vetores da RFC 8291, secao 5 e apendice A. Copiados literalmente da especificacao.
 */
final class RfcVectors
{
    public const PLAINTEXT      = 'V2hlbiBJIGdyb3cgdXAsIEkgd2FudCB0byBiZSBhIHdhdGVybWVsb24';
    public const UA_PUBLIC      = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
    public const AUTH_SECRET    = 'BTBZMqHH6r4Tts7J_aSIgg';
    public const AS_PRIVATE     = 'yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw';
    public const AS_PUBLIC      = 'BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8';
    public const SALT           = 'DGv6ra1nlYgDCS1FRnbzlw';
    public const ECDH_SECRET    = 'kyrL1jIIOHEzg3sM2ZWRHDRB62YACZhhSlknJ672kSs';
    public const CEK            = 'oIhVW04MRdy2XN9CiKLxTg';
    public const NONCE          = '4h_95klXJ5E_qnoN';
    public const BODY           = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

    public static function decode(string $base64Url): string
    {
        return base64_decode(strtr($base64Url, '-_', '+/').str_repeat('=', (4 - strlen($base64Url) % 4) % 4), true);
    }

    public static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * Monta um PEM de chave privada a partir do escalar cru de 32 octetos e do ponto publico.
     *
     * Existe somente aqui, no teste: a RFC publica a chave do servidor como escalar cru, e o
     * OpenSSL nao aceita essa forma. O codigo de producao guarda PEM justamente para nao
     * precisar disto.
     */
    public static function privatePem(string $scalar32, string $point65): string
    {
        $der = "\x30\x77\x02\x01\x01\x04\x20".$scalar32
            ."\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
            ."\xa1\x44\x03\x42\x00".$point65;

        return "-----BEGIN EC PRIVATE KEY-----\n"
            .chunk_split(base64_encode($der), 64, "\n")
            ."-----END EC PRIVATE KEY-----\n";
    }
}
```

- [ ] **Passo 2: Provar que a fixture produz uma chave que o OpenSSL aceita**

```php
<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Tests\Unit\Application\Push;

use PHPUnit\Framework\TestCase;

final class RfcVectorsTest extends TestCase
{
    public function testThePrivateKeyFixtureLoadsInOpenSsl(): void
    {
        $pem = RfcVectors::privatePem(
            RfcVectors::decode(RfcVectors::AS_PRIVATE),
            RfcVectors::decode(RfcVectors::AS_PUBLIC),
        );

        $key = openssl_pkey_get_private($pem);
        self::assertNotFalse($key, 'o PEM montado a partir do escalar cru precisa carregar');

        $details = openssl_pkey_get_details($key);
        self::assertSame('prime256v1', $details['ec']['curve_name']);
    }
}
```

- [ ] **Passo 3: Rodar**

```bash
./bin/dev-test.sh plugins/MauticInboxBundle/Tests/Unit/Application/Push
```

Esperado: PASS. Se falhar aqui, o problema é o layout DER — confira os comprimentos: `0x77` = 119 octetos de conteúdo, `0x20` = 32 do escalar, `0x42` = 66 da BIT STRING (1 de padding + 65 do ponto).

- [ ] **Passo 4: Commit**

```bash
git add Tests/Unit/Application/Push/
git commit -m "Add RFC 8291 test vectors and a private key fixture"
```

---

## Tarefa 2: `Ec` — ponto cru para PEM

O `p256dh` que o navegador envia é um ponto de 65 octetos. O `openssl_pkey_derive` precisa de uma chave pública carregada. Esta é a ponte.

**Files:**
- Create: `Application/Push/Ec.php`
- Test: `Tests/Unit/Application/Push/EcTest.php`

- [ ] **Passo 1: Escrever o teste que falha**

```php
public function testARawPointBecomesAKeyOpenSslAccepts(): void
{
    $point = RfcVectors::decode(RfcVectors::UA_PUBLIC);
    self::assertSame(65, strlen($point));

    $pem = Ec::publicPemFromPoint($point);
    $key = openssl_pkey_get_public($pem);

    self::assertNotFalse($key, 'o ponto cru precisa virar chave publica carregavel');
    self::assertSame('prime256v1', openssl_pkey_get_details($key)['ec']['curve_name']);
}

public function testAPointThatIsNotUncompressedIsRejected(): void
{
    $this->expectException(\InvalidArgumentException::class);
    Ec::publicPemFromPoint(str_repeat("\x00", 65));
}
```

- [ ] **Passo 2: Rodar e ver falhar**

```bash
./bin/dev-test.sh plugins/MauticInboxBundle/Tests/Unit/Application/Push
```

Esperado: FAIL com "Class Ec not found".

- [ ] **Passo 3: Implementar o mínimo**

```php
<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Push;

/**
 * Conversoes entre a forma crua do Web Push e a forma DER que o OpenSSL exige.
 */
final class Ec
{
    /**
     * Prefixo DER de um SubjectPublicKeyInfo de P-256 com ponto nao comprimido.
     * SEQUENCE(89) { SEQUENCE(19) { OID ecPublicKey, OID prime256v1 }, BIT STRING(66) { 0x00 } }
     */
    private const SPKI_PREFIX = "\x30\x59\x30\x13\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
        ."\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07\x03\x42\x00";

    public static function publicPemFromPoint(string $point): string
    {
        if (65 !== strlen($point) || "\x04" !== $point[0]) {
            throw new \InvalidArgumentException('Ponto P-256 nao comprimido precisa de 65 octetos comecando em 0x04.');
        }

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode(self::SPKI_PREFIX.$point), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }
}
```

- [ ] **Passo 4: Rodar e ver passar**

Esperado: PASS nos dois testes.

- [ ] **Passo 5: Commit**

```bash
git add Application/Push/Ec.php Tests/Unit/Application/Push/EcTest.php
git commit -m "Convert a raw P-256 point into a key OpenSSL can load"
```

---

## Tarefa 3: `Ec` — chave para ponto cru, com preenchimento

O caminho inverso, e a armadilha do preenchimento. O teste é forte porque a RFC publica o par completo: carregar a privada e derivar a pública tem que dar exatamente o valor publicado.

**Files:**
- Modify: `Application/Push/Ec.php`
- Test: `Tests/Unit/Application/Push/EcTest.php`

- [ ] **Passo 1: Escrever o teste que falha**

```php
public function testThePublicPointMatchesTheValueTheRfcPublishes(): void
{
    $pem = RfcVectors::privatePem(
        RfcVectors::decode(RfcVectors::AS_PRIVATE),
        RfcVectors::decode(RfcVectors::AS_PUBLIC),
    );

    $point = Ec::pointFromKey(openssl_pkey_get_private($pem));

    self::assertSame(RfcVectors::AS_PUBLIC, RfcVectors::encode($point));
}

public function testCoordinatesShorterThanThirtyTwoOctetsArePaddedOnTheLeft(): void
{
    // Uma coordenada com zero a esquerda volta do OpenSSL com menos de 32 octetos.
    // Sem preenchimento, o ponto sai deslocado e a cifra fica errada em silencio.
    $point = Ec::assemblePoint(str_repeat("\x11", 31), str_repeat("\x22", 32));

    self::assertSame(65, strlen($point));
    self::assertSame("\x04\x00\x11", substr($point, 0, 3));
}

public function testTheSpkiWrappingRoundTripsBackToTheSamePoint(): void
{
    // Fecha o outro lado da tarefa 2: carregar sem erro nao prova que o prefixo DER esta certo,
    // porque um prefixo errado que ainda assim parseia passaria naquele teste. A ida e volta e
    // o que fecha, e so da para escrever aqui, onde pointFromKey existe.
    $point = RfcVectors::decode(RfcVectors::UA_PUBLIC);

    $restored = Ec::pointFromKey(openssl_pkey_get_public(Ec::publicPemFromPoint($point)));

    self::assertSame($point, $restored);
}
```

- [ ] **Passo 2: Rodar e ver falhar**

Esperado: FAIL com "Call to undefined method".

- [ ] **Passo 3: Implementar**

```php
    public static function pointFromKey(\OpenSSLAsymmetricKey $key): string
    {
        $details = openssl_pkey_get_details($key);
        if (false === $details || !isset($details['ec']['x'], $details['ec']['y'])) {
            throw new \RuntimeException('A chave nao expoe coordenadas de curva eliptica.');
        }

        return self::assemblePoint($details['ec']['x'], $details['ec']['y']);
    }

    public static function assemblePoint(string $x, string $y): string
    {
        return "\x04".self::pad32($x).self::pad32($y);
    }

    private static function pad32(string $coordinate): string
    {
        if (strlen($coordinate) > 32) {
            throw new \RuntimeException('Coordenada P-256 maior que 32 octetos.');
        }

        return str_pad($coordinate, 32, "\x00", STR_PAD_LEFT);
    }
```

- [ ] **Passo 4: Rodar e ver passar**

Esperado: PASS nos três testes. O primeiro é a prova real do preenchimento — se estiver errado,
o valor não bate com o publicado na RFC. O terceiro fecha o embrulho DER da tarefa 2, que só
podia ser verificado aqui, depois que `pointFromKey` passou a existir.

- [ ] **Passo 5: Commit**

```bash
git add Application/Push/Ec.php Tests/Unit/Application/Push/EcTest.php
git commit -m "Extract raw coordinates with left padding"
```

---

## Tarefa 4: `Ec` — assinatura DER para `R||S`

O `openssl_sign` devolve DER. O JWT ES256 exige 64 octetos crus. Mesma armadilha de zero à esquerda, agora nos inteiros do DER.

**Files:**
- Modify: `Application/Push/Ec.php`
- Test: `Tests/Unit/Application/Push/EcTest.php`

- [ ] **Passo 1: Escrever o teste que falha**

```php
public function testADerSignatureBecomesSixtyFourRawOctets(): void
{
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    openssl_sign('mensagem', $der, $key, OPENSSL_ALGO_SHA256);

    self::assertSame(64, strlen(Ec::signatureToRaw($der)));
}

public function testIntegersWithALeadingZeroKeepTheirValue(): void
{
    // DER assina inteiros: um valor cujo primeiro bit e 1 ganha um 0x00 na frente.
    // Removido sem cuidado, R ou S saem com 31 octetos e a assinatura e recusada.
    $r   = "\x00".str_repeat("\xff", 32);
    $s   = str_repeat("\x11", 32);
    $der = "\x30".chr(4 + strlen($r) + strlen($s))
        ."\x02".chr(strlen($r)).$r
        ."\x02".chr(strlen($s)).$s;

    $raw = Ec::signatureToRaw($der);

    self::assertSame(64, strlen($raw));
    self::assertSame(str_repeat("\xff", 32), substr($raw, 0, 32));
    self::assertSame($s, substr($raw, 32, 32));
}
```

- [ ] **Passo 2: Rodar e ver falhar**

- [ ] **Passo 3: Implementar**

```php
    public static function signatureToRaw(string $der): string
    {
        $offset = 0;
        if ("\x30" !== ($der[$offset++] ?? '')) {
            throw new \RuntimeException('Assinatura DER precisa comecar com SEQUENCE.');
        }
        $offset++; // comprimento da sequencia, sempre curto para P-256

        $read = static function () use ($der, &$offset): string {
            if ("\x02" !== ($der[$offset++] ?? '')) {
                throw new \RuntimeException('Esperado INTEGER na assinatura DER.');
            }
            $length = ord($der[$offset++]);
            $value  = substr($der, $offset, $length);
            $offset += $length;

            return str_pad(ltrim($value, "\x00"), 32, "\x00", STR_PAD_LEFT);
        };

        return $read().$read();
    }
```

- [ ] **Passo 4: Rodar e ver passar**

- [ ] **Passo 5: Commit**

```bash
git add Application/Push/Ec.php Tests/Unit/Application/Push/EcTest.php
git commit -m "Convert a DER signature into the raw form JWT requires"
```

---

## Tarefa 5: `Hkdf`

Duas funções pequenas. A validação de verdade vem na tarefa 6, contra os valores derivados que a RFC publica.

**Files:**
- Create: `Application/Push/Hkdf.php`
- Test: `Tests/Unit/Application/Push/HkdfTest.php`

- [ ] **Passo 1: Escrever o teste que falha**

```php
public function testExtractProducesThirtyTwoOctets(): void
{
    self::assertSame(32, strlen(Hkdf::extract('sal', 'material')));
}

public function testExpandHonoursTheRequestedLength(): void
{
    $prk = Hkdf::extract('sal', 'material');

    self::assertSame(16, strlen(Hkdf::expand($prk, 'info', 16)));
    self::assertSame(12, strlen(Hkdf::expand($prk, 'info', 12)));
}

public function testDifferentInfoProducesDifferentKeys(): void
{
    $prk = Hkdf::extract('sal', 'material');

    self::assertNotSame(Hkdf::expand($prk, 'a', 16), Hkdf::expand($prk, 'b', 16));
}
```

- [ ] **Passo 2: Rodar e ver falhar**

- [ ] **Passo 3: Implementar**

```php
<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Push;

/**
 * Derivacao HKDF-SHA256 da RFC 5869, no subconjunto que o Web Push usa.
 */
final class Hkdf
{
    public static function extract(string $salt, string $inputKeyMaterial): string
    {
        return hash_hmac('sha256', $inputKeyMaterial, $salt, true);
    }

    public static function expand(string $pseudoRandomKey, string $info, int $length): string
    {
        if ($length < 1 || $length > 255 * 32) {
            throw new \InvalidArgumentException('Comprimento fora da faixa do HKDF.');
        }

        $output   = '';
        $previous = '';
        for ($counter = 1; strlen($output) < $length; ++$counter) {
            $previous = hash_hmac('sha256', $previous.$info.chr($counter), $pseudoRandomKey, true);
            $output .= $previous;
        }

        return substr($output, 0, $length);
    }
}
```

- [ ] **Passo 4: Rodar e ver passar**

- [ ] **Passo 5: Commit**

```bash
git add Application/Push/Hkdf.php Tests/Unit/Application/Push/HkdfTest.php
git commit -m "Add the HKDF derivation web push needs"
```

---

## Tarefa 6: `WebPushCrypto` — a derivação, contra os intermediários da RFC

Aqui a corrente inteira é verificada passo a passo. O apêndice A publica o segredo ECDH, a CEK e o NONCE, então um erro em qualquer etapa aponta exatamente para a etapa errada em vez de produzir um corpo cifrado inexplicavelmente inválido.

**Files:**
- Create: `Application/Push/WebPushCrypto.php`
- Test: `Tests/Unit/Application/Push/WebPushCryptoTest.php`

- [ ] **Passo 1: Escrever o teste que falha**

```php
public function testEachDerivedValueMatchesTheRfc(): void
{
    $crypto = new WebPushCrypto();

    $derived = $crypto->derive(
        RfcVectors::decode(RfcVectors::UA_PUBLIC),
        RfcVectors::decode(RfcVectors::AUTH_SECRET),
        RfcVectors::privatePem(
            RfcVectors::decode(RfcVectors::AS_PRIVATE),
            RfcVectors::decode(RfcVectors::AS_PUBLIC),
        ),
        RfcVectors::decode(RfcVectors::SALT),
    );

    self::assertSame(RfcVectors::ECDH_SECRET, RfcVectors::encode($derived['sharedSecret']), 'segredo ECDH');
    self::assertSame(RfcVectors::CEK, RfcVectors::encode($derived['contentEncryptionKey']), 'CEK');
    self::assertSame(RfcVectors::NONCE, RfcVectors::encode($derived['nonce']), 'NONCE');
}
```

- [ ] **Passo 2: Rodar e ver falhar**

- [ ] **Passo 3: Implementar**

```php
<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Push;

/**
 * Cifra de conteudo aes128gcm da RFC 8291 e cabecalho de autorizacao VAPID da RFC 8292.
 */
final class WebPushCrypto
{
    private const RECORD_SIZE = 4096;

    /**
     * @return array{sharedSecret:string, contentEncryptionKey:string, nonce:string, serverPoint:string}
     */
    public function derive(string $userAgentPoint, string $authSecret, string $serverPrivatePem, string $salt): array
    {
        $serverKey = openssl_pkey_get_private($serverPrivatePem);
        if (false === $serverKey) {
            throw new \RuntimeException('Chave privada do servidor invalida.');
        }

        $serverPoint  = Ec::pointFromKey($serverKey);
        $sharedSecret = openssl_pkey_derive(Ec::publicPemFromPoint($userAgentPoint), $serverKey, 32);
        if (false === $sharedSecret) {
            throw new \RuntimeException('Falha ao derivar o segredo ECDH.');
        }

        // A ordem e obrigatoria: ponto do aparelho primeiro, ponto do servidor depois.
        $keyInfo = "WebPush: info\x00".$userAgentPoint.$serverPoint;
        $ikm     = Hkdf::expand(Hkdf::extract($authSecret, $sharedSecret), $keyInfo, 32);
        $prk     = Hkdf::extract($salt, $ikm);

        return [
            'sharedSecret'         => $sharedSecret,
            'contentEncryptionKey' => Hkdf::expand($prk, "Content-Encoding: aes128gcm\x00", 16),
            'nonce'                => Hkdf::expand($prk, "Content-Encoding: nonce\x00", 12),
            'serverPoint'          => $serverPoint,
        ];
    }
}
```

- [ ] **Passo 4: Rodar e ver passar**

Esperado: PASS nas três asserções. Se o segredo ECDH bate mas a CEK não, o erro está no `key_info` — confira a ordem dos pontos e o `\x00`.

- [ ] **Passo 5: Commit**

```bash
git add Application/Push/WebPushCrypto.php Tests/Unit/Application/Push/WebPushCryptoTest.php
git commit -m "Derive the content key against the RFC intermediate values"
```

---

## Tarefa 7: `WebPushCrypto` — o corpo cifrado completo

Com a derivação provada, o corpo inteiro tem que reproduzir o vetor byte a byte.

**Files:**
- Modify: `Application/Push/WebPushCrypto.php`
- Test: `Tests/Unit/Application/Push/WebPushCryptoTest.php`

- [ ] **Passo 1: Escrever o teste que falha**

```php
public function testTheEncryptedBodyReproducesTheRfcVector(): void
{
    $body = (new WebPushCrypto())->encrypt(
        RfcVectors::decode(RfcVectors::PLAINTEXT),
        RfcVectors::decode(RfcVectors::UA_PUBLIC),
        RfcVectors::decode(RfcVectors::AUTH_SECRET),
        RfcVectors::decode(RfcVectors::SALT),
        RfcVectors::privatePem(
            RfcVectors::decode(RfcVectors::AS_PRIVATE),
            RfcVectors::decode(RfcVectors::AS_PUBLIC),
        ),
    );

    self::assertSame(RfcVectors::BODY, RfcVectors::encode($body));
}

public function testARandomlyKeyedBodyStaysWithinTheRecordSize(): void
{
    $body = (new WebPushCrypto())->encrypt(
        str_repeat('a', 3993),
        RfcVectors::decode(RfcVectors::UA_PUBLIC),
        RfcVectors::decode(RfcVectors::AUTH_SECRET),
    );

    self::assertSame(4096, strlen($body), 'o teto de 3993 octetos existe para o corpo fechar em 4096');
}

public function testAPlaintextOverTheCeilingIsRefused(): void
{
    $this->expectException(\InvalidArgumentException::class);
    (new WebPushCrypto())->encrypt(
        str_repeat('a', 3994),
        RfcVectors::decode(RfcVectors::UA_PUBLIC),
        RfcVectors::decode(RfcVectors::AUTH_SECRET),
    );
}
```

- [ ] **Passo 2: Rodar e ver falhar**

- [ ] **Passo 3: Implementar**

```php
    public const MAX_PLAINTEXT = 3993; // 4096 - 86 de cabecalho - 1 de delimitador - 16 de etiqueta

    public function encrypt(
        string $plaintext,
        string $userAgentPoint,
        string $authSecret,
        ?string $salt = null,
        ?string $serverPrivatePem = null,
    ): string {
        if (strlen($plaintext) > self::MAX_PLAINTEXT) {
            throw new \InvalidArgumentException('Texto claro acima de '.self::MAX_PLAINTEXT.' octetos nao cabe num registro.');
        }

        $salt ??= random_bytes(16);
        if (16 !== strlen($salt)) {
            throw new \InvalidArgumentException('O salt do aes128gcm tem exatamente 16 octetos.');
        }

        // Par efemero, gerado por mensagem. NAO e o par VAPID: aquele identifica o servidor e
        // vive para sempre; este existe para cifrar uma notificacao e e descartado. Sao a mesma
        // curva, o que torna a confusao facil e cara.
        $serverPrivatePem ??= self::ephemeralPem();

        $derived = $this->derive($userAgentPoint, $authSecret, $serverPrivatePem, $salt);

        $tag    = '';
        $cipher = openssl_encrypt(
            $plaintext."\x02", // 0x02 marca o ultimo registro
            'aes-128-gcm',
            $derived['contentEncryptionKey'],
            OPENSSL_RAW_DATA,
            $derived['nonce'],
            $tag,
        );
        if (false === $cipher) {
            throw new \RuntimeException('Falha na cifra AES-128-GCM.');
        }

        return $salt.pack('N', self::RECORD_SIZE).chr(65).$derived['serverPoint'].$cipher.$tag;
    }

    private static function ephemeralPem(): string
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (false === $key || !openssl_pkey_export($key, $pem)) {
            throw new \RuntimeException('Nao foi possivel gerar o par efemero.');
        }

        return $pem;
    }
```

- [ ] **Passo 4: Rodar e ver passar**

Esperado: PASS. O primeiro teste é o portão desta fase inteira — se ele passa, a implementação está correta contra a especificação, não contra a nossa opinião.

- [ ] **Passo 5: Commit**

```bash
git add Application/Push/WebPushCrypto.php Tests/Unit/Application/Push/WebPushCryptoTest.php
git commit -m "Produce the encrypted body byte for byte against RFC 8291"
```

---

## Tarefa 8: `VapidKeys`

O par de chaves. Privada em PEM pelo motivo registrado no spec: guardar o escalar cru traria de volta a armadilha de preenchimento, agora na releitura.

**Files:**
- Create: `Application/Push/VapidKeys.php`
- Modify: `Config/services.php`
- Test: `Tests/Unit/Application/Push/VapidKeysTest.php`

**Antes de escrever a classe, leia isto.** O `Config/services.php` deste bundle registra como
serviço tudo que está sob `../`, exceto o que aparece na lista de exclusões — e `Application/`
**não** está nela. Prova disso está no próprio arquivo, que já precisou excluir
`Application/InboxException.php` à mão. O `VapidKeys` tem construtor privado, então é
não-instanciável: deixá-lo visível ao autowiring quebra a compilação do contêiner inteiro.

Nada nesta fase carrega o kernel, então nenhum teste daqui pegaria isso. O estouro aconteceria
na fase 2, no primeiro teste funcional, com uma mensagem que não aponta para lugar nenhum perto
da causa. Por isso a exclusão entra junto com a classe, no mesmo commit.

- [ ] **Passo 1: Escrever o teste que falha**

```php
public function testAGeneratedPairRoundTripsThroughStorage(): void
{
    $generated = VapidKeys::generate();
    $restored  = VapidKeys::fromStorage($generated->privatePem(), $generated->publicKey());

    self::assertSame($generated->privatePem(), $restored->privatePem());
    self::assertSame($generated->publicKey(), $restored->publicKey());
}

public function testThePublicKeyIsTheRawPointInBase64Url(): void
{
    $keys = VapidKeys::generate();

    self::assertSame(87, strlen($keys->publicKey()), '65 octetos em base64url dao 87 caracteres');
    self::assertStringStartsWith("\x04", RfcVectors::decode($keys->publicKey()));
}

public function testAPublicKeyThatDoesNotMatchThePrivateOneIsRefused(): void
{
    $this->expectException(\InvalidArgumentException::class);
    VapidKeys::fromStorage(VapidKeys::generate()->privatePem(), VapidKeys::generate()->publicKey());
}
```

- [ ] **Passo 2: Rodar e ver falhar**

- [ ] **Passo 3: Implementar**

```php
<?php

declare(strict_types=1);

namespace MauticPlugin\MauticInboxBundle\Application\Push;

/**
 * O par VAPID. A privada vive em PEM; a publica, no ponto cru que o navegador espera.
 */
final class VapidKeys
{
    private function __construct(
        private readonly string $privatePem,
        private readonly string $publicKey,
    ) {
    }

    public static function generate(): self
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (false === $key) {
            throw new \RuntimeException('Nao foi possivel gerar o par VAPID.');
        }

        if (!openssl_pkey_export($key, $pem)) {
            throw new \RuntimeException('Nao foi possivel exportar a chave privada VAPID.');
        }

        return new self($pem, self::encode(Ec::pointFromKey($key)));
    }

    public static function fromStorage(string $privatePem, string $publicKey): self
    {
        $key = openssl_pkey_get_private($privatePem);
        if (false === $key) {
            throw new \InvalidArgumentException('Chave privada VAPID invalida.');
        }

        if (self::encode(Ec::pointFromKey($key)) !== $publicKey) {
            throw new \InvalidArgumentException('A chave publica guardada nao corresponde a privada.');
        }

        return new self($privatePem, $publicKey);
    }

    public function privatePem(): string
    {
        return $this->privatePem;
    }

    public function publicKey(): string
    {
        return $this->publicKey;
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
```

- [ ] **Passo 4: Excluir a classe do autowiring**

Em `Config/services.php`, ao lado da exclusão que já existe:

```php
    $excludes[] = 'Application/InboxException.php';
    $excludes[] = 'Application/Push/VapidKeys.php'; // construtor privado: nao e servico
    $excludes[] = 'DependencyInjection/Compiler';
```

- [ ] **Passo 5: Rodar e ver passar**

- [ ] **Passo 6: Commit**

```bash
git add Application/Push/VapidKeys.php Config/services.php Tests/Unit/Application/Push/VapidKeysTest.php
git commit -m "Hold the VAPID pair with the private key as PEM"
```

---

## Tarefa 9: `WebPushCrypto` — o cabeçalho VAPID

A segunda camada, independente da primeira. O ES256 não é determinístico, então não existe vetor de bytes: o portão é a verificação de ida e volta.

**Files:**
- Modify: `Application/Push/WebPushCrypto.php`
- Test: `Tests/Unit/Application/Push/WebPushCryptoTest.php`

- [ ] **Passo 1: Escrever o teste que falha**

```php
public function testTheAuthorizationHeaderVerifiesAgainstItsOwnKey(): void
{
    $keys   = VapidKeys::generate();
    $header = (new WebPushCrypto())->authorizationHeader(
        'https://fcm.googleapis.com/fcm/send/abc123',
        'mailto:suporte@exemplo.com',
        $keys,
    );

    self::assertStringStartsWith('vapid t=', $header);
    self::assertStringContainsString(', k='.$keys->publicKey(), $header);

    [$header64, $claims64, $signature64] = explode('.', substr($header, 8, strpos($header, ', k=') - 8));
    $claims = json_decode(RfcVectors::decode($claims64), true);

    self::assertSame('https://fcm.googleapis.com', $claims['aud'], 'aud e a origem do endpoint, nao a URL inteira');
    self::assertSame('mailto:suporte@exemplo.com', $claims['sub']);
    self::assertGreaterThan(time(), $claims['exp']);
    self::assertLessThanOrEqual(time() + 86400, $claims['exp']);

    $der = self::rawSignatureToDer(RfcVectors::decode($signature64));
    self::assertSame(1, openssl_verify(
        $header64.'.'.$claims64,
        $der,
        openssl_pkey_get_public(Ec::publicPemFromPoint(RfcVectors::decode($keys->publicKey()))),
        OPENSSL_ALGO_SHA256,
    ));
}

public function testTwoEndpointsOnDifferentOriginsGetDifferentAudiences(): void
{
    $keys   = VapidKeys::generate();
    $crypto = new WebPushCrypto();

    $google  = $crypto->authorizationHeader('https://fcm.googleapis.com/fcm/send/a', 'mailto:a@b.c', $keys);
    $mozilla = $crypto->authorizationHeader('https://updates.push.services.mozilla.com/wpush/v2/a', 'mailto:a@b.c', $keys);

    self::assertNotSame($google, $mozilla, 'reaproveitar um token entre origens e o erro classico');
}

public function testASubjectThatIsNotMailtoOrHttpsIsRefused(): void
{
    $this->expectException(\InvalidArgumentException::class);
    (new WebPushCrypto())->authorizationHeader('https://fcm.googleapis.com/fcm/send/a', 'suporte@exemplo.com', VapidKeys::generate());
}
```

O auxiliar `rawSignatureToDer` vive no arquivo de teste — é o caminho inverso do de produção e
só existe para verificar. **Escreva-o exatamente assim**, porque a versão ingênua esquece o
`0x00` quando o bit alto do inteiro está ligado, produz um DER malformado e faz o
`openssl_verify` devolver `0`. Como o ES256 não é determinístico, isso falharia em cerca de três
execuções em quatro e apareceria como bug intermitente — justamente na área que esta fase existe
para tornar previsível.

```php
private static function rawSignatureToDer(string $raw): string
{
    $integer = static function (string $value): string {
        $value = ltrim($value, "\x00");
        if ('' === $value) {
            $value = "\x00";
        }
        if (ord($value[0]) >= 0x80) {
            $value = "\x00".$value; // DER assina inteiros: bit alto ligado exige o zero na frente
        }

        return "\x02".chr(strlen($value)).$value;
    };

    $body = $integer(substr($raw, 0, 32)).$integer(substr($raw, 32, 32));

    return "\x30".chr(strlen($body)).$body;
}
```

- [ ] **Passo 2: Rodar e ver falhar**

- [ ] **Passo 3: Implementar**

```php
    public function authorizationHeader(string $endpoint, string $subject, VapidKeys $keys, int $lifetime = 43200): string
    {
        if (!str_starts_with($subject, 'mailto:') && !str_starts_with($subject, 'https:')) {
            throw new \InvalidArgumentException('A RFC 8292 admite apenas mailto: ou https: em sub.');
        }

        $parts = parse_url($endpoint);
        if (!isset($parts['scheme'], $parts['host'])) {
            throw new \InvalidArgumentException('Endpoint sem esquema ou host.');
        }
        $audience = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        $signing = $this->encode('{"typ":"JWT","alg":"ES256"}')
            .'.'.$this->encode((string) json_encode([
                'aud' => $audience,
                'exp' => time() + $lifetime,
                'sub' => $subject,
            ], JSON_UNESCAPED_SLASHES));

        $key = openssl_pkey_get_private($keys->privatePem());
        if (false === $key || !openssl_sign($signing, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('Falha ao assinar o token VAPID.');
        }

        return 'vapid t='.$signing.'.'.$this->encode(Ec::signatureToRaw($der)).', k='.$keys->publicKey();
    }

    private function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
```

- [ ] **Passo 4: Rodar e ver passar**

- [ ] **Passo 5: Commit**

```bash
git add Application/Push/WebPushCrypto.php Tests/Unit/Application/Push/WebPushCryptoTest.php
git commit -m "Sign the VAPID authorization header per endpoint origin"
```

---

## Tarefa 10: Fechamento da fase

- [ ] **Passo 1: Rodar a suíte inteira do plugin**

```bash
./bin/dev-test.sh plugins/MauticInboxBundle/Tests/Unit
```

Esperado: todos verdes, incluindo os testes que já existiam. Se algum teste antigo quebrou, esta fase encostou em algo que não devia.

- [ ] **Passo 2: Confirmar que nada aqui depende do Mautic**

```bash
grep -rn "Mautic\\\\" Application/Push/ || echo "nenhuma dependencia do Mautic — correto"
```

Esperado: a mensagem. Estas quatro classes precisam continuar puras; é o que permite testá-las sem kernel, sem banco e sem rota.

- [ ] **Passo 3: Atualizar o CHANGELOG**

```markdown
## Nao lancado

- Adiciona a fundacao criptografica do Web Push sobre ext-openssl, sem dependencia de Composer.
- Verifica a cifra de conteudo contra os vetores publicados na RFC 8291.
```

- [ ] **Passo 4: Commit**

```bash
git add CHANGELOG.md
git commit -m "Record the web push cryptography foundation"
```

---

## O que vem depois

A fase 2 do spec consome `WebPushCrypto` e `VapidKeys` para a primeira fatia vertical:
`PushDevice`, a API de inscrição, um service worker mínimo e o `mautic:inbox:push:test`. É lá que
uma notificação chega num navegador real pela primeira vez. Ela tem plano próprio.

**Uma coisa que esta fase deliberadamente não faz:** o `VapidKeys` daqui é objeto de valor puro,
sem persistência e sem criptografia em repouso. O spec descreve a chave privada guardada em PEM
criptografado — isso é responsabilidade da fase 2, junto com o `mautic:inbox:push:setup`. A fase
2 não deve assumir que já está resolvido.
