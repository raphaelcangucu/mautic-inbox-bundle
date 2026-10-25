# Handover: canal de e-mail com threads — continuar no macOS e testar no celular

Data: 2026-10-25
Estado: implementado, testado na bancada local (Windows/WSL), PRs abertos, nada publicado em produção.
Spec: `docs/superpowers/specs/2026-10-24-canal-email-threads-design.md` ·
Plano: `docs/superpowers/plans/2026-10-24-canal-email-threads.md`

## 1. Onde está tudo

| Repo | Branch | PR | Tag | Versão |
|---|---|---|---|---|
| mautic-meta-bundle | `feat/canal-email` | raphaelcangucu/mautic-meta-bundle#3 | `v0.15.0` | 0.15.0 |
| mautic-multi-mail-bundle | `feat/canal-email` | raphaelcangucu/mautic-multi-mail-bundle#3 | `v0.5.0` | 0.5.0 |
| mautic-inbox-bundle | `feat/canal-email` | raphaelcangucu/mautic-inbox-bundle#21 | `v1.9.0` | 1.9.0 |
| mautic-inbox-mobile | `feat/canal-email` | raphaelcangucu/mautic-inbox-mobile#11 | `v1.1.0` | 1.1.0 (Android 18, iOS 43) |

- **As tags apontam para a ponta das branches.** Faça o merge com merge commit, como os PRs anteriores; assim as tags continuam no histórico da `main`. Squash ou rebase deixariam as tags fora dela, e aí é preciso recriá-las.
- **Ordem de merge e de deploy:** Meta → Multi Mail → Inbox → app.
  - O Inbox exige `raphaelcangucu/mautic-meta-bundle ^0.15.0`.
  - O app 1.1.0 continua funcionando com um Inbox antigo, só sem o canal e-mail.
- **O que entrou em cada repo:** está na descrição de cada PR e nos CHANGELOGs (Meta, Multi Mail e Inbox). O app usa as releases do GitHub e o README.

## 2. Preparar o Mac

```bash
for r in mautic-meta-bundle mautic-multi-mail-bundle mautic-inbox-bundle mautic-inbox-mobile; do
  git -C ~/code/$r fetch --tags origin && git -C ~/code/$r checkout feat/canal-email && git -C ~/code/$r pull
done
```

App:
- Node 22.22.2 (`.nvmrc`), depois `npm ci`.
- Antes de qualquer build: `npm test` (180 testes) e `npm run typecheck`.
- Xcode, CocoaPods e `bundle install` para o Fastlane, como nas versões anteriores.
- Android: Android Studio com SDK 36, que é o que `scripts/build-apk.sh` espera em `~/Library/Android/sdk`.

## 3. Servidor — sem isso o celular não tem com quem conversar

O teste real depende do Inbox 1.9 em `mkt-macro.on-forge.com`. Faça o deploy do jeito de sempre (Forge; ver `docs/INSTALACAO.md` e `docs/OPERACAO.md`), com backup do banco antes.

1. **Meta 0.15.0** e **Inbox 1.9.0**, depois `php bin/console cache:clear` e `php bin/console mautic:plugins:reload`. **Nenhuma das quatro releases muda schema.**
2. **Multi Mail 0.5.0**:
   - Gere o ZIP a partir do commit da tag, com `python3 build/package.py`. O ZIP só leva arquivos do git e inclui o vendor com `zbateson/mail-mime-parser`.
   - Substitua o plugin e rode `cache:clear` e `mautic:plugins:reload`.
3. **Cron do Forge** (a cada minuto): `php bin/console mautic:multimail:imap:poll`. O `mautic:meta:queue:process` já existe por causa do WhatsApp; confira que continua ativo.
4. **Antivírus dos anexos de entrada** (opcional, mas sem ele todo anexo aparece "indisponível"):
   - O WhatsQR já exige ClamAV; se o clamd estiver no mesmo servidor, use o mesmo socket.
   - Em `config/local.php`: `'multimail_clamd_socket' => 'unix:///var/run/clamav/clamd.ctl'` (ajuste o caminho), depois `cache:clear`.
5. **Conferência:** `GET https://mkt-macro.on-forge.com/inbox/mobile/config` tem que listar `"email"` em `capabilities.channels`.

## 4. Conta de e-mail de teste

Comece com uma **caixa dedicada a teste**, não a caixa principal.

1. Em `/s/mail-connections`, crie ou edite uma conexão **SMTP**:
   - SMTP: 465 com ssl ou 587 com tls.
   - IMAP: host, 993, pasta `INBOX`. Pasta de enviados é opcional; no Gmail é `[Gmail]/Sent Mail`.
   - Senha IMAP, se for diferente da SMTP.
   - Marque **"Conversa por e-mail"**.
   - Google Workspace/Gmail: IMAP ligado e senha de app quando houver 2FA.
2. Clique em **"Testar ida e volta"**. O resultado esperado é a chegada por `+tag`.
   - Se a caixa não aceitar `+tag`, o teste grava isso e o canal segue funcionando, encadeando pelos cabeçalhos e pelo remetente.
3. **Não** use a mesma caixa como "Monitored Inbox" do Mautic: ele move e marca mensagens.
4. Deixe um **limite por hora baixo** nessa conexão (por exemplo, 30).
5. O asset `email_account` aparece sozinho. O usuário que vai testar precisa de `inbox:conversations:create` e `meta:messages:create` para iniciar conversa.

## 5. Build do app para o celular

### iPhone

Teste rápido no aparelho, com assinatura de desenvolvimento:

```bash
npx expo prebuild --platform ios --clean && (cd ios && pod install)
open ios/MauticInbox.xcworkspace
```

No Xcode, escolha o iPhone, o time `SB6QYUH97U` e o esquema **Release**, e dê Run.

Pelo TestFlight, interno:

```bash
bundle exec fastlane ios archive
IOS_IPA_PATH=<ipa gerado> bundle exec fastlane ios internal_testflight
```

- `archive` precisa de `IOS_PROVISIONING_PROFILE_NAME`; o upload precisa de `APP_STORE_CONNECT_KEY_ID`, `APP_STORE_CONNECT_ISSUER_ID` e `APP_STORE_CONNECT_KEY_PATH`, como nas versões anteriores.
- O build já está em 43 e o preflight de fonte e IPA passa. Os screenshots da loja só são exigidos no `--listing` e no `--submission`.
- O iOS **não foi compilado** nesta rodada. É a primeira verificação nativa no Mac.

### Android físico

```bash
bash scripts/build-apk.sh
adb install -r artifacts/Mautic-Inbox-v1.1.0.apk
```

- O APK sai em release arm64 com a assinatura padrão do prebuild.
- Se o aparelho tem a versão da Play Store, a assinatura diverge: desinstale antes, o que apaga a sessão local, ou use o caminho da loja (`fastlane android internal_test` com o AAB auditado).

**Nunca use `MAUTIC_E2E_USER_CA=1` num build para aparelho real.** Essa flag só existe para o e2e contra a bancada local e faz o app confiar em CAs instaladas pelo usuário.

## 6. Roteiro do teste real no celular

Use um contato de teste com o **seu e-mail pessoal**.

1. Entre no app com o código por e-mail (seu usuário do Mautic).
2. Abra Contatos → contato de teste → **Iniciar conversa** → opção **E-mail · conta de teste**. A thread abre vazia e nada é enviado.
3. **Responder:**
   - assunto "Por que você ainda não depositou?";
   - um texto;
   - no "+", escolha **Formulário** e um formulário publicado;
   - **Enviar**. O cartão passa de "enviando" para "Enviada".
4. **No seu e-mail pessoal**, confira:
   - From: "Seu nome · nome da conexão";
   - Reply-To: `conta+r…@`;
   - a linha "Responda acima desta linha" no topo;
   - o link do formulário.
5. **Abra o link e responda o formulário.** Na thread do app aparecem "abriu o formulário" e "respondeu o formulário · resposta".
6. **Responda do Gmail ou do Mail do celular**, citando o e-mail e com um anexo. Em até 1 minuto (cron), a resposta chega com:
   - push;
   - a citação cortada atrás do "•••";
   - o anexo (disponível só com clamd configurado);
   - o contador de não lidas.
7. **Responda de novo pelo app.** No seu cliente, a mensagem cai na mesma conversa (`In-Reply-To`/`References`).
8. **Responda de outro endereço seu.** A mensagem continua na mesma conversa pelo token, e o cartão mostra o endereço real.
9. Toque em **Ver original**: abre a página saneada no navegador do sistema, com imagens remotas bloqueadas até o toque.
10. Abra o **Inbox web**: a mesma conversa aparece no estilo Gmail, e dá para responder com assunto.
11. **Campanha:**
    - crie uma campanha com a ação **"Iniciar conversa por e-mail"** para um segmento só com o contato de teste;
    - confira o e-mail, a conversa e o evento "iniciada pela campanha";
    - o link de descadastro do cabeçalho (no Gmail, "Cancelar inscrição") grava DNC de e-mail, e o sheet do contato passa a mostrar o motivo.

## 7. Testes automatizados

| O quê | Onde roda | Como |
|---|---|---|
| Meta / Inbox PHPUnit | bancada Mautic (WSL; no Mac via DDEV, `docs/DESENVOLVIMENTO.md`) | `bin/phpunit -c app/phpunit.xml.dist plugins/<Bundle>/Tests/Unit` |
| Inbox JS | qualquer lugar | `npm ci && npm test` |
| Multi Mail | Linux ou macOS (o guard de permissão recusa o Windows) | `for t in Tests/*.php; do php "$t"; done` e `node Tests/*.cjs` |
| Multi Mail + GreenMail | Linux com sudo (portas 465/993) | `bin/greenmail.sh start` + `GREENMAIL=1 php Tests/greenmail-e2e.php` |
| e2e de servidor completo | bancada com HTTPS confiável | `Tests/e2e/run.sh` (Multi Mail) |
| App unitário | qualquer lugar | `npm test && npm run typecheck` |
| App e2e demo | emulador Android | `e2e/flows/email/run.sh` (o balanço é injetado via `adb emu`; no iOS Simulator, entrar no demo à mão) |
| App e2e ao vivo | emulador Android + bancada | `e2e/flows/email-live/run.sh` com `SUMMARY` do e2e de servidor |

Bancada atual no Windows/WSL, para referência:
- Mautic 7.2.1 em `~/mautic-dev`, servido por nginx com TLS em `https://localhost:8443`, à frente do `php -S` na porta 8080. A CA está em `~/.cc/ca`.
- Plugins ligados por symlink.
- Scripts: `~/.cc/e2e/live-e2e.sh` (servidor + app ao vivo) e `~/.cc/e2e/all-tests.sh` (todas as suítes).
- Evidências (capturas e vídeos) em `~/.cc/artifacts/demo/`; há uma cópia em `C:\Users\raphaelcangucu\code\mautic\artifacts-canal-email`.

Resultado da última rodada:
- Meta: 175 testes; só as 3 falhas que já existiam na `main`.
- Inbox: 171 unitários, 0 falhas (com o WhatsQR); 11 funcionais de e-mail; JS 47 + 65.
- Multi Mail: 21 scripts e GreenMail.
- App: 180 testes.
- e2e de servidor: 11 etapas.
- Maestro: demo 6/6; ao vivo 3/3, mais a checagem da caixa do lead.

## 8. Pendências conhecidas

- **Teclado:** sobra um espaço de cerca de 120 px sob a barra Responder/Encaminhar depois que o teclado fecha. Parece ser o `KeyboardAvoidingView` com `behavior="height"` no Android; ver no aparelho.
- **Bounce:** marca a mensagem de saída como `failed` e aparece como evento ("Falha na entrega"), mas o selo do cartão de saída continua "Enviada". Follow-up: o Inbox ler o status da `MetaMessage` para e-mail.
- **Recebimento:** a latência é de até 1 minuto (cron); IMAP IDLE fica para depois. Não há anexo de saída nem IA no canal e-mail (fora de escopo da v1).
- **Mautic com `/api` na URL:** o Mautic trata como chamada de API qualquer URL que contenha `/api` e exige a API ligada (`api_enabled`). Em produção já está ligada; numa instalação nova, ligue antes de usar o app.
- **Limite do código mágico:** um pedido por minuto e cinco por hora por e-mail. Em testes repetidos, espere o minuto.
- **Despachante do Meta:** o desenho final usa uma tag por `asset_type` (`mautic_meta.channel_dispatcher`), não um alias sobrescrito; o spec foi atualizado.
- **Clones antigos:** os clones em `C:\Users\raphaelcangucu\code\mautic` ficaram na `main`. O trabalho foi feito nos clones do WSL e está nas branches acima.
