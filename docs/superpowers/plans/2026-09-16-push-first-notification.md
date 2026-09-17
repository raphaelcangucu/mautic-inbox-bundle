# First Notification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A primeira fatia vertical que funciona: um atendente liga a notificação em `/s/inbox`, e uma notificação de teste chega no navegador dele.

**Architecture:** A fase 1 entregou a criptografia pura. Esta fase a conecta ao mundo: uma entidade por aparelho, um cofre para a chave VAPID, uma API de inscrição sob sessão e CSRF, um service worker mínimo e um comando que dispara uma notificação de teste. Nada ainda reage a mensagem recebida — isso é a fase 3.

**Tech Stack:** PHP 8.2+, Doctrine, Symfony HttpClient, Svelte 5, Vite, service worker.

**Esta é a fase 2 de 4** do spec `docs/superpowers/specs/2026-09-16-inbox-pwa-push-design.md`, e depende da fase 1 (`docs/superpowers/plans/2026-09-16-web-push-crypto.md`) estar concluída.

---

## Contexto que o implementador precisa

**O que já existe da fase 1.** Em `Application/Push/`: `Ec`, `Hkdf`, `WebPushCrypto` e `VapidKeys`. As assinaturas que esta fase consome:

- `WebPushCrypto::encrypt(string $plaintext, string $userAgentPoint, string $authSecret, ?string $salt = null, ?string $serverPrivatePem = null): string`
- `WebPushCrypto::authorizationHeader(string $endpoint, string $subject, VapidKeys $keys, int $lifetime = 43200): string`
- `VapidKeys::generate(): self`, `VapidKeys::fromStorage(string $privatePem, string $publicKey): self`, `->privatePem()`, `->publicKey()`

O `VapidKeys` da fase 1 é objeto de valor puro: **não persiste nada e não criptografa nada**. Guardar é trabalho desta fase.

**Convenções deste repositório, confirmadas no código:**

- Entidades estendem `Mautic\CoreBundle\Entity\CommonEntity` e declaram schema em `loadMetadata` com `ClassMetadataBuilder`. Tabelas levam prefixo `inbox_`. Veja `Entity/CannedResponse.php`.
- **Não há diretório `Migrations/`.** O schema nasce de `mautic:plugins:reload`, que lê os metadados da entidade. Adicionar a entidade e recarregar cria a tabela.
- Serviços são autowired por `Config/services.php`; basta criar a classe.
- CSRF nos controllers: `$this->isCsrfTokenValid('mautic_inbox', $request->headers->get('X-CSRF-Token', ''))`.
- Permissões: `inbox:conversations:view` e afins, via `$permissions->isGranted(...)`. Definidas em `Security/Permissions/InboxPermissions.php`.
- Criptografia em repouso: `Mautic\CoreBundle\Helper\EncryptionHelper`, com `encrypt($data): string` e `decrypt($data)`. O padrão a seguir é `Security/CredentialVault.php` do Meta bundle.
- Rotas públicas existem: `Config/config.php` aceita um grupo `routes.public`, usado por nove plugins desta instalação. É o que serve o service worker fora da sessão.

**A regra dura.** Nenhum teste com banco contra instalação implantada. Os testes com banco desta fase rodam só onde `MAUTIC_TEST_DATABASE_ALLOW_DESTRUCTIVE` aponta para um banco descartável. Na dúvida, escreva teste unitário e verifique o resto pelo navegador.

**O ciclo de teste** é o `bin/dev-test.sh` da fase 1.

---

## Estrutura de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `Entity/PushDevice.php` | Um registro por aparelho inscrito |
| `Entity/PushDeviceRepository.php` | Consultas por usuário e por endpoint |
| `Application/Push/VapidKeyStore.php` | Lê e grava o par, privada criptografada |
| `Application/Push/PushSubscriptions.php` | Inscrever, cancelar, listar, reativar |
| `Application/Push/PushSender.php` | O POST no endpoint e a classificação da resposta |
| `Controller/PushController.php` | Chave pública, inscrição e cancelamento |
| `Controller/PwaAssetController.php` | `/inbox-sw.js`, público |
| `Command/PushSetupCommand.php` | Gera e guarda o par VAPID |
| `Command/PushTestCommand.php` | Dispara notificação de teste |
| `Frontend/sw/sw.ts` | `push` e `notificationclick` |
| `Frontend/shared/push.ts` | Registra o service worker e inscreve |

`PushSender` é separado de `PushSubscriptions` porque um fala HTTP com o mundo e o outro fala com o banco. Juntar os dois produziria uma classe que não dá para testar sem rede.

---

## Tarefa 1: `PushDevice`

**Files:**
- Create: `Entity/PushDevice.php`, `Entity/PushDeviceRepository.php`
- Test: `Tests/Unit/Entity/PushDeviceTest.php`

- [ ] **Passo 1: Escrever o teste que falha**

```php
public function testANewDeviceStartsActiveWithoutFailures(): void
{
    $device = (new PushDevice())->setEndpoint('https://fcm.googleapis.com/fcm/send/abc')->setKeys('p256dh-cru', 'auth-cru');

    self::assertTrue($device->isActive());
    self::assertSame(0, $device->getConsecutiveFailures());
}

public function testTenConsecutiveFailuresRetireTheDevice(): void
{
    $device = new PushDevice();

    for ($i = 0; $i < 9; ++$i) {
        $device->recordFailure();
    }
    self::assertTrue($device->isActive(), 'nove falhas ainda nao aposentam');

    $device->recordFailure();
    self::assertFalse($device->isActive(), 'a decima aposenta');
}

public function testASuccessClearsTheFailureCountAndRevives(): void
{
    $device = new PushDevice();
    for ($i = 0; $i < 10; ++$i) {
        $device->recordFailure();
    }

    $device->recordSuccess();

    self::assertTrue($device->isActive());
    self::assertSame(0, $device->getConsecutiveFailures());
    self::assertNotNull($device->getLastDeliveredAt());
}
```

- [ ] **Passo 2: Rodar e ver falhar**

```bash
./bin/dev-test.sh plugins/MauticInboxBundle/Tests/Unit/Entity
```

Esperado: FAIL com "Class PushDevice not found".

- [ ] **Passo 3: Implementar**

Siga `Entity/CannedResponse.php` como molde: `CommonEntity`, `loadMetadata` com `ClassMetadataBuilder`, tabela `inbox_push_devices`. Campos: `user` (ManyToOne para `Mautic\UserBundle\Entity\User`, `onDelete: CASCADE` — apagar o usuário apaga os aparelhos, como o spec exige), `endpoint` (TEXT), `endpointHash` (STRING 64, índice único — o endpoint é longo demais para índice direto), `p256dh` (STRING 255), `auth` (STRING 255), `userAgent` (STRING 255, anulável), `active` (BOOLEAN, padrão `true`), `consecutiveFailures` (INTEGER, padrão `0`), `dateAdded` e `lastDeliveredAt`.

`setKeys(string $p256dh, string $auth)` guarda os dois juntos porque não fazem sentido separados. `setEndpoint` calcula o `endpointHash` com `hash('sha256', $endpoint)`. `recordFailure()` incrementa e aposenta na décima. `recordSuccess()` zera, reativa e carimba `lastDeliveredAt`.

A constante `public const RETIREMENT_THRESHOLD = 10;` existe para o teste e o código lerem o mesmo número.

- [ ] **Passo 4: Rodar e ver passar**

- [ ] **Passo 5: Commit**

```bash
git add Entity/PushDevice.php Entity/PushDeviceRepository.php Tests/Unit/Entity/PushDeviceTest.php
git commit -m "Add the push device record with its retirement rule"
```

---

## Tarefa 2: `VapidKeyStore` e `mautic:inbox:push:setup`

O par VAPID é único da instalação. Perder a privada invalida todas as inscrições.

**Files:**
- Create: `Application/Push/VapidKeyStore.php`, `Command/PushSetupCommand.php`
- Test: `Tests/Unit/Application/Push/VapidKeyStoreTest.php`

- [ ] **Passo 1: Escrever o teste que falha**

```php
public function testTheStoredPairComesBackIdentical(): void
{
    $encryption = $this->createMock(EncryptionHelper::class);
    $encryption->method('encrypt')->willReturnCallback(static fn (string $v): string => 'selado:'.$v);
    $encryption->method('decrypt')->willReturnCallback(static fn (string $v): string => substr($v, 7));

    $coreParams = new FakeCoreParameters();
    $store      = new VapidKeyStore($encryption, $coreParams);
    $generated  = $store->generate();

    self::assertStringStartsWith('selado:', $coreParams->written['inbox_vapid_private'], 'a privada nunca vai para o disco em claro');

    $restored = $store->load();
    self::assertSame($generated->publicKey(), $restored->publicKey());
    self::assertSame($generated->privatePem(), $restored->privatePem());
}

public function testLoadingWithoutAPairReportsThatPushIsOff(): void
{
    $store = new VapidKeyStore($this->createMock(EncryptionHelper::class), new FakeCoreParameters());

    self::assertFalse($store->isConfigured());
    $this->expectException(\RuntimeException::class);
    $store->load();
}
```

- [ ] **Passo 2: Rodar e ver falhar**

- [ ] **Passo 3: Implementar**

`VapidKeyStore` grava dois parâmetros na configuração do Mautic: `inbox_vapid_private` (PEM passado pelo `EncryptionHelper::encrypt`) e `inbox_vapid_public` (base64url cru, não é segredo — o navegador recebe de qualquer forma). `isConfigured()` responde sem lançar. `load()` lança `RuntimeException` quando não há par, e a mensagem diz o comando a rodar. `generate()` cria com `VapidKeys::generate()` e grava.

Siga `Security/CredentialVault.php` do Meta bundle no trato com o `EncryptionHelper`.

`PushSetupCommand`, nome `mautic:inbox:push:setup`: gera o par, grava e imprime a chave pública. Com `--force` regenera, **avisando em voz alta que toda inscrição existente morre** e pedindo confirmação interativa quando não houver `--no-interaction`.

- [ ] **Passo 4: Rodar e ver passar**

- [ ] **Passo 5: Commit**

```bash
git add Application/Push/VapidKeyStore.php Command/PushSetupCommand.php Tests/Unit/Application/Push/VapidKeyStoreTest.php
git commit -m "Store the VAPID pair with the private key encrypted at rest"
```

---

## Tarefa 3: `PushSubscriptions`

**Files:**
- Create: `Application/Push/PushSubscriptions.php`
- Test: `Tests/Unit/Application/Push/PushSubscriptionsTest.php`

- [ ] **Passo 1: Escrever o teste que falha**

```php
public function testSubscribingTwiceFromTheSameBrowserKeepsOneRecord(): void
public function testResubscribingRevivesARetiredDevice(): void
public function testUnsubscribingRemovesOnlyThatEndpoint(): void
public function testAUserCannotRemoveAnotherUsersDevice(): void
```

Use um repositório falso em memória — esta classe não precisa de banco para ser provada.

- [ ] **Passo 2: Rodar e ver falhar**

- [ ] **Passo 3: Implementar**

`subscribe(User $user, string $endpoint, string $p256dh, string $auth, ?string $userAgent): PushDevice` procura por `endpointHash`; se existir, atualiza chaves, reativa e devolve; se não, cria. `unsubscribe(User $user, string $endpoint): bool` só apaga quando o registro é daquele usuário. `activeFor(User $user): array` devolve apenas ativos.

- [ ] **Passo 4: Rodar e ver passar**

- [ ] **Passo 5: Commit**

---

## Tarefa 4: `PushSender`

A resposta do serviço de push vira decisão. Sem fila ainda — isso é a fase 3.

**Files:**
- Create: `Application/Push/PushSender.php`, `Application/Push/PushResult.php`
- Test: `Tests/Unit/Application/Push/PushSenderTest.php`

- [ ] **Passo 1: Escrever o teste que falha**

Use `Symfony\Component\HttpClient\MockHttpClient`. Um caso por resposta:

```php
public function testATwoHundredOneCountsAsDelivered(): void
public function testATwoHundredTwoAlsoCountsAsDelivered(): void
public function testAFourHundredFourRetiresTheSubscription(): void
public function testAFourHundredTenRetiresTheSubscription(): void
public function testAFourHundredThirteenIsDiscardedNotRetried(): void
public function testAFourHundredTwentyNineIsRetryableAndCarriesRetryAfter(): void
public function testAFiveHundredIsRetryable(): void
public function testATransportErrorIsRetryable(): void
public function testTheRequestCarriesTheEncodingTtlAndUrgencyHeaders(): void
```

O último asserta os cabeçalhos: `Content-Encoding: aes128gcm`, `Content-Type: application/octet-stream`, `TTL: 3600`, `Urgency: high`, e um `Authorization` começando em `vapid t=`.

- [ ] **Passo 2: Rodar e ver falhar**

- [ ] **Passo 3: Implementar**

`PushResult` é um objeto de valor pequeno: `delivered`, `retryable`, `retireDevice`, `retryAfter`, `statusCode`, `message`. `PushSender::send(PushDevice $device, string $payload): PushResult` cifra com `WebPushCrypto::encrypt`, monta o cabeçalho com `authorizationHeader`, faz o POST e classifica. **Nunca lança** por resposta do servidor — erro de rede também vira `PushResult` retryable. O `413` é descartado, não reenfileirado, porque repetir um corpo grande demais só repete a falha.

O `subject` do VAPID vem do parâmetro `inbox_vapid_subject`, com `mailto:` do e-mail do administrador como padrão.

- [ ] **Passo 4: Rodar e ver passar**

- [ ] **Passo 5: Commit**

---

## Tarefa 5: `PushController`

**Files:**
- Create: `Controller/PushController.php`
- Modify: `Config/config.php` — três rotas em `routes.main`
- Test: `Tests/Functional/PushSubscriptionTest.php`

- [ ] **Passo 1: Escrever o teste que falha**

```php
public function testAnAnonymousRequestIsRefused(): void
public function testAMissingCsrfTokenIsRefused(): void
public function testAValidSubscriptionIsStoredForTheSignedInUser(): void
public function testTheConfigEndpointReturnsThePublicKeyAndNeverThePrivateOne(): void
public function testOneUserCannotUnsubscribeAnotherUsersEndpoint(): void
```

- [ ] **Passo 2: Rodar e ver falhar**

- [ ] **Passo 3: Implementar**

Rotas em `routes.main`, ou seja, sob `/s/` e sob a sessão:

- `GET /inbox/api/push/config` → `{publicKey, configured, subscribed}`
- `POST /inbox/api/push/subscriptions` → corpo `{endpoint, keys:{p256dh, auth}}`
- `DELETE /inbox/api/push/subscriptions` → corpo `{endpoint}`

Todas exigem `inbox:conversations:view`. As duas de escrita exigem o CSRF `mautic_inbox` no cabeçalho `X-CSRF-Token`, como o `InboxController` já faz. A rota de configuração **nunca** devolve a chave privada — o teste existe para travar isso.

- [ ] **Passo 4: Rodar e ver passar**

- [ ] **Passo 5: Commit**

---

## Tarefa 6: O service worker

**Files:**
- Create: `Frontend/sw/sw.ts`, `Controller/PwaAssetController.php`
- Modify: `vite.config.ts` — segunda entrada de build; `Config/config.php` — `routes.public`
- Test: `Tests/JavaScript/service-worker.test.mjs`

- [ ] **Passo 1: Escrever o teste que falha**

Teste o worker compilado num escopo falso, como `svelte-inbox.test.mjs` já faz com o bundle:

```js
test('push event shows a notification with the conversation tag', ...)
test('a malformed payload still shows something instead of throwing', ...)
test('notificationclick focuses an open window instead of opening another', ...)
test('notificationclick opens a window when none is open', ...)
```

- [ ] **Passo 2: Rodar e ver falhar**

```bash
npm run build && node --test Tests/JavaScript/service-worker.test.mjs
```

- [ ] **Passo 3: Implementar**

`sw.ts` trata `push` e `notificationclick`. A `tag` é o ID da conversa, para que mensagem nova substitua a anterior em vez de empilhar. Payload corrompido cai num texto de reserva — **nunca** deixe o handler lançar, porque o navegador pune com uma notificação genérica de "site atualizado em segundo plano".

`PwaAssetController::serviceWorker()` devolve o arquivo compilado com `Content-Type: application/javascript` e **`Cache-Control: no-cache`**. O `no-cache` não é detalhe: a instância está atrás da Cloudflare, e um service worker retido na borda deixa a equipe presa numa versão que ninguém consegue atualizar, sem erro visível.

Rota pública `/inbox-sw.js`, fora da sessão, para que a revalidação em segundo plano não esbarre em redirecionamento de login. O escopo registrado é `/s/`.

- [ ] **Passo 4: Rodar e ver passar**

- [ ] **Passo 5: Commit**

---

## Tarefa 7: Ligar a notificação em `/s/inbox`

**Files:**
- Create: `Frontend/shared/push.ts`
- Modify: `Frontend/inbox/SettingsView.svelte`, `Translations/` (pt_BR e en_US)
- Test: `Tests/JavaScript/push-subscription.test.mjs`

- [ ] **Passo 1: Escrever o teste que falha**

```js
test('the toggle reflects the real permission state', ...)
test('a denied permission explains that only the system settings can undo it', ...)
test('subscribing posts the endpoint and both keys with the csrf header', ...)
test('the toggle stays off when the server reports push is not configured', ...)
```

- [ ] **Passo 2: Rodar e ver falhar**

- [ ] **Passo 3: Implementar**

`push.ts` registra o service worker no escopo `/s/`, converte a chave pública de base64url para `Uint8Array` — o `applicationServerKey` não aceita string —, chama `subscribe` e envia ao servidor.

No `SettingsView.svelte`, um controle que mostra o estado real: `granted`, `denied` ou `default`. Em `denied`, explique que o navegador não permite pedir de novo e que a reversão é nos ajustes do sistema. **Não peça permissão na carga da página** — só num toque explícito, que é requisito no iOS e boa educação em todo lugar.

Sem par VAPID configurado, o controle aparece desligado e diz qual comando rodar.

- [ ] **Passo 4: Rodar e ver passar**

- [ ] **Passo 5: Commit**

---

## Tarefa 8: `mautic:inbox:push:test`

**Files:**
- Create: `Command/PushTestCommand.php`
- Test: `Tests/Unit/Command/PushTestCommandTest.php`

- [ ] **Passo 1: Escrever o teste que falha**

```php
public function testItReportsWhenTheUserHasNoDevice(): void
public function testItSendsToEveryActiveDeviceAndSummarisesEachResult(): void
public function testItNamesTheSetupCommandWhenNoPairIsConfigured(): void
```

- [ ] **Passo 2: Rodar e ver falhar**

- [ ] **Passo 3: Implementar**

`mautic:inbox:push:test --user=<id ou e-mail>` envia para cada aparelho ativo e imprime uma linha por aparelho, com resultado e motivo. É a ferramenta que separa defeito de entrega de defeito de fluxo, e é o portão desta fase.

- [ ] **Passo 4: Rodar e ver passar**

- [ ] **Passo 5: Commit**

---

## Tarefa 9: O portão da fase — notificação real

Aqui a corrente inteira é exercitada pela primeira vez. Nada disto é automatizável.

- [ ] **Passo 1: Publicar no banco de provas e criar o par**

```bash
./bin/dev-test.sh plugins/MauticInboxBundle/Tests/Unit
ssh $INBOX_TEST_HOST 'cd <banco de provas> && php8.4 bin/console mautic:plugins:reload && php8.4 bin/console mautic:inbox:push:setup'
```

Esperado: a tabela `inbox_push_devices` nasce e o comando imprime a chave pública.

- [ ] **Passo 2: Inscrever um navegador de desktop**

Abra `/s/inbox`, vá em ajustes, ligue a notificação, autorize.

Esperado: registro em `inbox_push_devices` com `p256dh` e `auth` preenchidos.

- [ ] **Passo 3: Disparar**

```bash
php8.4 bin/console mautic:inbox:push:test --user=<seu id>
```

Esperado: **a notificação aparece na central do sistema operacional.** Se o comando diz entregue e nada aparece, o defeito está no service worker; se o comando diz `401`, está no VAPID; se diz `400`, está na cifra.

- [ ] **Passo 4: Repetir no celular**

Mesmo caminho pelo Chrome do Android. Feche o Chrome por inteiro e dispare de novo: a notificação tem que chegar com o navegador fechado. É isso que separa esta arquitetura de uma aba aberta.

- [ ] **Passo 5: Registrar o resultado no CHANGELOG e commitar**

---

## O que vem depois

A fase 3 liga o gatilho real: `PushAudience` decide quem recebe, `PushPayload` monta o conteúdo, o gancho em `MetaInboxIntegration::persistInbound()` dispara em `kernel.terminate`, e a fila de reentrega absorve falha. A fase 4 transforma `/s/inbox` em app instalável e fecha a matriz de ponta a ponta.
