# Canal de E-mail com Threads — Implementation Plan

> **For agentic workers:** Steps use checkbox (`- [ ]`) syntax for tracking. Cada repositório tem a
> sua trilha; as trilhas do Multi Mail, do Inbox e do app rodam em paralelo contra o contrato da
> seção 1, depois que o Meta 0.15.0 (trilha 0) está pronto.

**Goal:** Ler e responder, no Inbox web e no app, e-mails que a plataforma iniciou, em threads, com
visual estilo Gmail; abrir conversa por campanha; mandar formulário de pesquisa na thread.

**Architecture:** O Multi Mail é dono do canal (IMAP, parse, threading, ingestão, MIME de saída). O
Meta ganha o canal `email`, o asset `email_account` e a operação de fila `email_message`, entregue por
um despachante marcado por tag. O Inbox apresenta, responde, abre conversa por campanha e manda
formulário. O app consome o mesmo contrato.

**Tech Stack:** PHP 8.2+ (Mautic 7.2), Symfony 7.4, Doctrine; Svelte 5 + TypeScript; Expo 55 / React
Native 0.83; GreenMail (SMTP+IMAP) e Maestro para e2e.

**Spec:** `docs/superpowers/specs/2026-10-24-canal-email-threads-design.md`. Leia antes de começar.

---

## Contexto que o implementador precisa

**Onde está o código.** Os repositórios canônicos estão no WSL (Ubuntu-24.04), em
`/home/raphaelcangucu/<repo>`, na branch `feat/canal-email`. Do Windows, edite pelo caminho UNC
`\\wsl.localhost\Ubuntu-24.04\home\raphaelcangucu\<repo>\...`. **Não edite os clones em
`C:\Users\raphaelcangucu\code\mautic`**: eles ficaram em `main` e não são usados.

**Bancada local** (já montada): Mautic 7.2.1 em `~/mautic-dev`, MariaDB local (`mautic`/`mautic`,
bancos `mautic` e `mautic_test`), os plugins ligados por symlink em `~/mautic-dev/plugins/`
(Meta, Inbox, Multi Mail, MCP). PHP 8.4 com todas as extensões. Node 22.22.2 via nvm. GreenMail em
`~/.cc/tools/greenmail-standalone.jar`. Maestro em `~/.maestro/bin`. Android SDK em `~/Android/Sdk`.

**Rodar comandos no WSL a partir do PowerShell.** O PowerShell 5.1 estraga aspas ao repassar para o
`wsl.exe`. Escreva um script em `\\wsl.localhost\Ubuntu-24.04\home\raphaelcangucu\.cc\<nome>.sh` e
rode `wsl.exe -d Ubuntu-24.04 --cd /home/raphaelcangucu -- bash /home/raphaelcangucu/.cc/<nome>.sh`.
Comandos sem aspas nem `$` podem ir direto: `wsl.exe -d Ubuntu-24.04 --cd /home/raphaelcangucu/<repo> -- git status -s`.

Scripts prontos em `~/.cc/`:

| Script | Uso |
|---|---|
| `phpunit.sh <caminho sob plugins/> [args]` | PHPUnit na bancada; log completo em `~/.cc/phpunit.log` |
| `console.sh <comando>` | `bin/console` da bancada; log em `~/.cc/console.log` |
| `app-build.sh` | APK release x86_64 do app; log em `~/.cc/app-build.log`, APK em `~/.cc/artifacts/` |
| `emu-start.sh` | sobe o emulador headless `mautic_inbox_api35` e espera o boot |
| `maestro.sh <fluxo ou pasta>` | roda Maestro no emulador; relatório em `~/.cc/maestro-report.xml` |

**Falhas que já existiam na `main` do Meta** (não são regressão): `MetaSetupServiceTest` (2 erros,
mock de repositório final) e `IdentityManagerTest::testRejectsContactOnMauticDnc`.

**Commits.** Não faça commit nem push; deixe as mudanças na árvore de trabalho da branch.

---

## 1. Contrato entre os repositórios

### 1.1 Meta 0.15.0 (pronto)

- `Domain\Channel::Email` = `'email'`; `Domain\AssetType::EmailAccount` = `'email_account'`
  (`channel()` → Email, `isGraphAsset()` → false).
- Operação de fila `email_message`, aceita por `OutboundQueue::enqueue` e despachada por
  `OutboundOperationExecutor` para `Application\Queue\ChannelDispatchers`, que escolhe pelo
  `asset_type` do job entre os serviços com a tag `mautic_meta.channel_dispatcher`
  (atributo `asset_type`). Interface: `Application\Queue\ChannelDispatcherInterface::dispatch(MetaOutboundJob): MetaMessage`.
- Sem despachante: `NoopChannelDispatcher` lança `DomainException` → job `failed`.
- Taxonomia de erro da fila: `DomainException`/`InvalidArgumentException` → `failed`;
  `Application\Exception\ChannelTemporarilyUnavailable` → `retry`; qualquer outra → `uncertain`.
- `ImmediateOutboundDispatcher` despacha `email_message` com `_origin: 'inbox_human'` logo após o
  commit.

### 1.2 Asset e conexão interna

Criados pelo Multi Mail quando uma conexão tem `conversational: true`:

- `MetaConnection`: `name` "E-mail (Multi Mail)", `appId` `multimail-email`, `businessId` `''`,
  `status` `inactive`, segredos vazios. Uma só para todas as contas.
- `MetaAsset`: `type` `email_account`, `externalId` = id da conexão do Multi Mail (32 hex), `name` =
  nome da conexão, `status` `active` (ou `inactive` quando o flag desliga/conexão some),
  `isPublished` true. Grave com o EntityManager direto (`AssetManager::update` substitui settings).
- `MetaAsset.settings` (chaves que o Inbox lê; o Multi Mail é dono):

```json
{
  "email_address": "contato@macromarkets.com",
  "email_from_name": "Macro Markets",
  "email_plus_addressing": true,
  "email_inbound_status": "ok",
  "email_inbound_since": null
}
```

`email_inbound_status`: `ok` | `delayed` (sem sucesso há mais de 30 min) | `auth_failed`.
`email_inbound_since`: ISO-8601 do início do problema, ou null.

### 1.3 Conversa e mensagens

- `MetaConversation`: `channel` `email`, `recipient` = e-mail do lead **em minúsculas**, `asset` = o
  asset da conta. Uma por (asset, recipient).
- `MetaMessage`: `channel` `email`, `messageType` `email`, `direction` `inbound|outbound`,
  `externalId` = `'email:'.hash('sha256', $assetId."\0".strtolower($messageId))`, `recipient` igual
  ao da conversa. Entrada: `status` `received`, `dateAdded` = INTERNALDATE (nunca no futuro). Saída:
  `pending` → `sent` | `failed`.
- `payload` (entrada e saída), chave única `email`:

```json
{
  "email": {
    "message_id": "<CAF3x...@mail.gmail.com>",
    "in_reply_to": "<9f2c....31@macromarkets.com>",
    "references": ["<9f2c....31@macromarkets.com>"],
    "from": {"name": "João Lead", "address": "joao@example.com"},
    "to": [{"name": "Macro Markets", "address": "contato@macromarkets.com"}],
    "cc": [],
    "subject": "Re: Por que você ainda não depositou?",
    "text": "Oi Ana, ainda estou decidindo. **Qual o depósito mínimo?**",
    "quoted": "> Em 23/10, Ana escreveu: ...",
    "html": "<div dir=\"ltr\">Oi Ana, ...</div>",
    "has_original": true,
    "auto_reply": false,
    "unverified_sender": false,
    "kind": "message",
    "attachments": [{"index": 0, "type": "document", "name": "extrato.pdf", "mime": "application/pdf", "size": 183422, "cid": null, "available": true}]
  }
}
```

  - `text`: a parte **nova** em markdown (o subconjunto que o app e o web já renderizam), até 64 KiB.
  - `quoted`: o texto citado cortado, em markdown, até 64 KiB.
  - `html`: a parte nova em HTML **cru** (não saneado), até 64 KiB; vazio se a mensagem era só texto.
    O Inbox saneia antes de mostrar.
  - `to` mostra o endereço da conta **sem o +tag**.
  - `kind`: `message` | `auto_reply` | `bounce` | `unreadable`.
  - `attachments[].available` false quando o antivírus não está configurado, recusou ou o tipo não é
    permitido.
  - Saída: `from` = conta (nome do atendente · nome da conexão), `to` = lead, `text` = corpo enviado,
    `html` = HTML gerado.

### 1.4 Job `email_message`

Payload enfileirado (Inbox → Meta → Multi Mail):

```json
{
  "recipient": "joao@example.com",
  "subject": "Re: Por que você ainda não depositou?",
  "text": "Corpo em markdown, com o link do formulário já expandido",
  "author_name": "Ana",
  "from_name": null,
  "unsubscribe_url": null,
  "_origin": "inbox_human",
  "_inbox_conversation_id": 812
}
```

- `_origin`: `inbox_human` | `inbox_campaign`. Campanha manda `from_name` e `unsubscribe_url`.
- O despachante grava `_email_message_id` no payload do job **antes** do primeiro envio; toda
  tentativa seguinte (inclusive o "tentar de novo" do Inbox, que copia o payload) reusa.

### 1.5 Conteúdo do e-mail para o Inbox

Interface nova no Inbox, implementada pelo Multi Mail (registrada só quando a interface existe):

```php
namespace MauticPlugin\MauticInboxBundle\Contract;

use MauticPlugin\MauticMetaBundle\Entity\MetaMessage;

/** Tag: mautic.inbox.email_content */
interface EmailContentProviderInterface
{
    public function supports(MetaMessage $message): bool;

    /** HTML original completo, cru (o Inbox saneia), ou null. Pode fazer IO. */
    public function originalHtml(MetaMessage $message): ?string;

    /**
     * Arquivo local verificado de um anexo. Null se não existir ou não estiver disponível.
     *
     * @return array{path: string, mime: string, name: string, size: int}|null
     */
    public function attachment(MetaMessage $message, int $index): ?array;
}
```

O Inbox serve o arquivo com seus próprios cabeçalhos de segurança e checagem de acesso.

### 1.6 API do Inbox (web e mobile)

Os campos abaixo **somam-se** aos atuais. Caminhos mobile como o app escreve (`/inbox/mobile/...`,
que o transporte troca para `api_base`).

- `GET /inbox/mobile/contacts/{id}` → `channels[]` ganha, por conta `email_account` ativa e publicada:
  `{"key":"email:31","state_id":null,"asset_id":31,"channel":"email","name":"Macro Markets · contato@macromarkets.com","address":"contato@macromarkets.com","available":true,"reason":null}`.
  Motivos: "Cadastre um e-mail válido no contato.", "O contato pediu para não receber e-mails.",
  "Esta conta de e-mail está desativada.".
- `POST /inbox/mobile/contacts/{id}/start` `{"channel_key":"email:31"}` → resumo da conversa.
- Resumo de conversa de e-mail ganha:
  `"subject": "<assunto da última mensagem>"`, `"asset": {..., "type": "email_account", "address": "..."}`,
  `"email": {"inbound": "ok|delayed", "inbound_since": null}`.
- Item de linha do tempo (mensagem de e-mail, entrada ou saída) ganha: `subject`, `email` (`from`,
  `to`, `cc`, `quoted`, `has_html`, `has_original`, `auto_reply`, `unverified_sender`, `kind`) e
  `attachments[]` com **vários** itens `{type, label, url, available, size, mime}`. `body` é o `text`.
  - URL web do anexo: `/s/inbox/api/email/{messageId}/attachments/{index}`.
  - URL mobile: `/inbox/mobile/api/email/{messageId}/attachments/{index}` (Bearer).
- Responder: `POST .../conversations/{id}/reply` aceita `subject` opcional (só e-mail). O item de
  saída devolve `subject`.
- Original: web `GET /s/inbox/api/messages/{id}/original` → `{"html":"<saneado>"}`; mobile
  `POST /inbox/mobile/api/messages/{id}/original-link` → `{"url":"https://.../inbox/mobile/original/{token}","expires_at":"..."}`
  (token HMAC de 5 min, página com `Content-Security-Policy: sandbox`).
- Formulários: `GET /inbox/api/forms` e `GET /inbox/mobile/api/forms` → `[{"id":12,"name":"..."}]`.
- `capabilities.channels` do `MobileAuthController` inclui `"email"`.
- Marcador de formulário no corpo: `[[form:12]]`.

---

## Trilha 0 — Meta 0.15.0 (concluída)

- [x] Enums, `ChannelDispatcherInterface`, `ChannelDispatchers` (tag por `asset_type`), Noop.
- [x] `email_message` na fila, no executor e no despacho imediato.
- [x] Guardas em `ConversationController` e `AdapterReplyController`; e-mail na linha do tempo do
  contato.
- [x] Testes: `AssetTypeTest`, `ChannelDispatchersTest`, `OutboundQueueTest`,
  `LeadTimelineSubscriberTest`. CHANGELOG, README e versão.

## Trilha A — Multi Mail 0.5.0

Testes no estilo atual (`php Tests/<nome>.php`, sem kernel), **sempre no WSL**.

- [x] **A1. Conexão.** Campos opcionais `imap_host`, `imap_port` (993 ou 143+STARTTLS),
  `imap_username`, `imap_folder` (padrão `INBOX`), `imap_sent_folder`, `conversational`, e o segredo
  `imap_password`, só para `smtp`. Validadores opcionais (o `text()` exige não vazio). Senha fora do
  `publicData`. Padrões no backfill. Tela: fieldset "Conversa por e-mail" com JS e traduções
  `en_US`/`pt_BR`; flag desabilitado com explicação quando a ponte é Noop. Teste em `Tests/connections.php`
  e no teste de template.
- [x] **A2. Ponte opcional.** `ConversationBridgeInterface` + `NoopConversationBridge`;
  `MetaConversationBridge` registrada só com o Meta (padrão `class_exists` do WhatsQR). Garante a
  `MetaConnection` interna e o `MetaAsset` por conexão (1.2), inclusive ao salvar e a cada passada do
  poller; desliga o asset quando o flag sai.
- [x] **A3. Token.** `ThreadToken`: `r` + base32 minúsculo de versão+id(4 bytes)+HMAC-SHA256 truncado
  em 10 bytes sobre `multimail-thread|v1|{assetId}|{conversationId}`, chave por HKDF do
  `secret_key` do Mautic. Testes: ida e volta, adulterado, de outro asset, maiúsculas, versão.
- [x] **A4. MIME de saída.** `OutboundEmailBuilder` (Symfony Mime): From, Reply-To com token (sem
  token se `plus_addressing` false), Message-ID estável, In-Reply-To/References (até 10), assunto,
  `multipart/alternative` com a sentinela `##- Responda acima desta linha -##`, markdown → HTML
  mínimo, `List-Unsubscribe` + `List-Unsubscribe-Post` só em campanha. Sem pixel. Testes.
- [x] **A5. Despachante.** `EmailChannelDispatcher implements ChannelDispatcherInterface`, tag
  `mautic_meta.channel_dispatcher` / `asset_type: email_account`. DNC de e-mail → `DomainException`.
  Grava `_email_message_id` no job, cria a `MetaMessage` `pending`, envia pela conexão sem fallback
  (`transportChain($id)[0]`, reserva de cota, `build`, `send`, `finishReservation`), `sent` +
  `ConversationManager::record()`. Recusa confirmada/4xx/cota → `ChannelTemporarilyUnavailable`;
  5xx → `DomainException`; incerto → `RuntimeException`. Só provedor `smtp`.
- [x] **A6. Cliente IMAP mínimo** sobre `stream_socket_client` (TLS 993 ou STARTTLS 143):
  `LOGIN`, `EXAMINE`, `UID SEARCH`, `UID FETCH (UID INTERNALDATE RFC822.SIZE BODY.PEEK[])`,
  `UID SEARCH HEADER Message-ID`, `LOGOUT`; literais; resposta `NO/BAD`. Teste com stream simulado.
- [x] **A7. Parse e corte.** `zbateson/mail-mime-parser` (nos dois `composer.json` e no vendor do
  build). `InboundParser` → objeto com cabeçalhos, texto, HTML, anexos. `QuoteCutter` (sentinela,
  padrões pt/en de Gmail, Outlook, Apple Mail, Thunderbird, `>`, `-- `; também no HTML via DOM).
  `HtmlToMarkdown` mínimo. Corpus `.eml` em `Tests/Fixtures/mail/`. Testes.
- [x] **A8. Triagem e threading.** Eco, bounce (casa a original e marca `failed`), auto-resposta,
  lista sem vínculo; ordem token → In-Reply-To/References → (asset, remetente) → nova, com contato
  só por resultado único. Testes.
- [x] **A9. Ingestão e poller.** Comando `mautic:multimail:imap:poll`, `flock` por conexão,
  `imap-state.json` (UIDVALIDITY, `last_uid` que só avança depois de gravar, falhas, espera
  crescente, `auth_failed`), primeira passada no `UIDNEXT`, lotes de 50, 25 MiB.
  `ConversationManager::record()` **e depois** `InboxIntegrationInterface::messagePersisted()`.
  Atualiza `email_inbound_status` no asset. Procura Message-ID incerto na pasta Enviados. Teste com
  `proc_open` para concorrência.
- [x] **A10. Blobs e anexos.** `mail-blobs/` no diretório privado (sha256), HTML original completo e
  anexos; lista de MIME, 25 MiB por arquivo, 2 GiB no total, clamd por socket configurável (sem
  clamd → `available: false`). `EmailContentProvider implements EmailContentProviderInterface`
  registrado só com o Inbox. `.gitignore` e `build/package.py`.
- [x] **A11. "Testar ida e volta"** na tela de conexões: envia para `local+teste<rand>@` e lê o IMAP
  por até 60 s; grava `plus_addressing`.
- [x] **A12. GreenMail e2e** (`Tests/greenmail-e2e.php`, opt-in por `GREENMAIL=1`): envia pela SMTP,
  lê pelo IMAP, parseia, confere token, responde com In-Reply-To, troca UIDVALIDITY. Script de
  subida do GreenMail em `bin/greenmail.sh`. Job no GitHub Actions com o GreenMail.
- [x] **A13.** README, versão 0.5.0 no `config.php`, CHANGELOG novo.

## Trilha B — Inbox 1.9.0

PHPUnit na bancada (`~/.cc/phpunit.sh MauticInboxBundle/Tests/Unit`), JS com `npm test` no WSL.

- [x] **B1. Contrato `EmailContentProviderInterface`** (1.5) e registro por tag
  `mautic.inbox.email_content`.
- [x] **B2. Consulta.** `email` na lista de `InboxQuery`; resumo com `subject`, `asset.address`,
  `email.inbound`; item de linha do tempo com os campos de 1.6; saída lê `subject` do payload do job.
  `MobileAuthController` com `email` nas capacidades.
- [x] **B3. Disponibilidade.** Ramo de e-mail em `ReplyAvailability` **antes** da janela de 24 h:
  resolvida, asset inativo, contato com DNC de e-mail, recipient sem e-mail válido. Testes.
- [x] **B4. Ações.** `outboundTarget` → `email_message`; `reply` aceita `subject` (web e mobile);
  `Re:` automático; expande `[[form:ID]]`; `author_name`; limite de 4000. Guardas: `AiWorker` e
  `AiService` fora do canal e-mail. Testes.
- [x] **B5. Apresentação.** `MessagePresentation` para `payload.email`; anexos múltiplos com as URLs
  de 1.6; saneamento HTML por lista de permissão (biblioteca escolhida aqui; candidata
  `symfony/html-sanitizer`) com corpus hostil; rotas de anexo (web e mobile) com `nosniff`,
  `CSP: sandbox`, `attachment` para documento e checagem de acesso; original web e link mobile
  assinado.
- [x] **B6. Abrir conversa.** `ContactDirectory`: opções `email:{assetId}`, motivos, `start` criando a
  conversa em minúsculas, 409 de contato diferente. Testes.
- [x] **B7. Formulário.** `GET forms` (web e mobile); token `/inbox/f/{token}` (HMAC: formulário,
  contato, conversa, 30 dias); rota pública que rastreia o contato, grava evento e redireciona;
  assinante de `FormEvents::FORM_ON_SUBMIT` grava "respondeu o formulário" com as respostas. Testes.
- [x] **B8. Ação de campanha** "Iniciar conversa por e-mail" (conta, nome no From, assunto, texto),
  as quatro saídas por contato da spec, chave `campaign:{logId}`, `List-Unsubscribe` com rota
  pública assinada que grava DNC (RFC 8058). Testes.
- [x] **B9. Web estilo Gmail.** `EmailThread.svelte`, `EmailCard.svelte`, `EmailBody.svelte` (iframe
  `sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"`, CSP no `srcdoc`, imagens
  remotas sob clique), `EmailComposer.svelte` (Para, De, Assunto, corpo, "+" com respostas prontas e
  formulário); lista com assunto; ícone, rótulo, filtro, cor; faixa de leitura atrasada. Testes JS;
  rebuild de `Assets/dist/` versionado.
- [x] **B10.** Traduções, `docs/mobile-api.md`, CHANGELOG, versão 1.9.0 em `config.php`
  (corrige o 1.5.2 defasado).

## Trilha C — App 1.1.0

`npm test` e `npm run typecheck` no WSL; APK com `~/.cc/app-build.sh`; e2e com Maestro.

- [x] **C1. Tipos e contrato.** `Channel` com `email`, rótulo "E-mail", `Icon` com envelope;
  `messageFromApi` mantém todos os anexos; tipos de `subject`, `email`, `attachments[]`; migração do
  `ui:view` salvo (acrescenta `email`). Testes.
- [x] **C2. Sheet de canais.** Opção `email:` com endereço da conta; motivo; abrir a conversa depois
  do `start`. Teste do transporte.
- [x] **C3. Thread estilo Gmail.** `EmailThread`, `EmailCard` (recolhido/aberto, "N mensagens
  anteriores", "•••" do citado, anexos múltiplos, aviso de remetente não verificado), eventos entre
  cartões, cabeçalho com assunto e conta. Markdown com imagem do proxy e "Mostrar imagens". Sem o
  atalho "salvar e-mail".
- [x] **C4. Composição.** Tela `EmailCompose` (Para fixo, De, Assunto `Re:` editável, corpo, "+" com
  respostas prontas e formulário); envio com `subject`; rascunho do corpo como hoje.
- [x] **C5. Original e anexos.** "Ver original" via `original-link` + `expo-web-browser`; anexo pelo
  proxy autenticado.
- [x] **C6. Lista e push.** Linha de e-mail com assunto; notificação com assunto e trecho.
- [x] **C7. Demo.** Contas mockadas com conversas de e-mail realistas (thread com 4+ mensagens,
  citado, anexo, auto-resposta, formulário respondido) e o sheet com opção de e-mail; o mock aceita
  `subject` no envio.
- [x] **C8. e2e Maestro** em `e2e/flows/email/` (versionado — ajustar o `.gitignore`): entrar no modo
  demo, abrir conversa de e-mail, expandir/recolher, ver citado, responder com assunto, iniciar
  conversa por e-mail pelo sheet. Rodar no emulador com o APK release.
- [x] **C9.** Versão 1.1.0 (`package.json`, `app.json` versionCode), README.

## Trilha D — Integração e e2e de ponta a ponta

- [x] **D1.** Bancada: Meta + Inbox + Multi Mail juntos; `mautic:plugins:reload`; container compila.
- [x] **D2.** e2e de servidor (`mautic-multi-mail-bundle/Tests/e2e/run.sh` + `email-thread.php`): GreenMail,
  conexão conversacional, conversa iniciada pela API mobile, resposta com assunto, worker, e-mail no
  GreenMail com token, resposta do lead pelo GreenMail, poller, mensagem na conversa com o citado
  cortado, formulário aberto e respondido.
- [x] **D3.** APK release 1.1.0 x86_64 no emulador; fluxos Maestro verdes (demo em `e2e/flows/email/` e ao vivo contra a bancada em `e2e/flows/email-live/`); capturas e vídeo do demo
  em `~/.cc/artifacts/demo/`.

## O que vem depois

Os follow-ups da spec: IDLE, anexo de saída, Enviados, spam, IA no e-mail, atendente na ação de
campanha, decisão "respondeu o e-mail", expurgo de blobs, Telegram.
