# Design: Canal de e-mail com threads no Inbox

Data: 2026-10-24
Status: Aprovado, não implementado
Escopo: `MauticMultiMailBundle`, `MauticMetaBundle`, `MauticInboxBundle` e o app `mautic-inbox-mobile`
— ler e responder no Inbox e no app os e-mails que a plataforma iniciou, em threads

## Problema

O projeto Macro Markets precisa falar com quem se cadastrou e ainda não depositou: "por que você
ainda não depositou?". A conversa deve abrir por todo canal em que o lead foi identificado —
e-mail, WhatsApp por QR e, no futuro, Telegram — e continuar na mesma thread, inclusive quando a
plataforma manda um formulário de pesquisa do Mautic.

O WhatsApp já funciona assim. O e-mail não: o Mautic manda e-mail, mas a resposta do lead cai numa
caixa que ninguém lê dentro da plataforma, e não há como responder de volta mantendo a thread. O
atendente que quer conversar por e-mail sai do Inbox, abre outro cliente e perde o histórico junto
das outras conversas do lead.

## Objetivos

1. Iniciar uma conversa por e-mail no app e no Inbox web, escolhendo o contato **e** a conta que
   envia, pelo mesmo sheet de canais que hoje abre conversa no WhatsApp por QR.
2. Receber as respostas do lead na mesma conversa, com threading que sobreviva a cliente de e-mail
   que reescreve assunto, corta cabeçalho ou responde de outro endereço.
3. Ler e responder com visual de cliente de e-mail (estilo Gmail), não de chat.
4. Abrir conversa por campanha, como escolha explícita (opt-in) do autor da campanha.
5. Mandar formulário de pesquisa dentro da thread, com link identificado por contato.
6. Multi Mail continua instalável sozinho, como transporte; o canal só liga quando o Meta existe.

## Fora de escopo

- **Anexo de saída.** O Inbox não tem envio genérico de anexo (só o áudio do QR); anexo de entrada
  está dentro.
- **IA respondendo e-mail.** O canal entra bloqueado para a IA (ver "Guardas").
- **IMAP IDLE.** A v1 lê por cron a cada minuto; IDLE exige processo permanente.
- **Importar o histórico da caixa.** Ao ligar, a leitura começa do próximo e-mail que chegar.
- **Ler a pasta de spam** e **gravar cópia em Enviados**. Ficam como follow-up.
- **Provedores por API no modo conversacional.** Só SMTP na v1 (ver "Decisões").
- **Telegram.** O desenho de campanha e formulário fica neutro de canal para recebê-lo depois.

## Decisões

| Questão | Decisão | Motivo |
|---|---|---|
| Por onde chega o e-mail | IMAP, configurado na mesma conexão do Multi Mail que envia, com flag `conversational` | Uma conta é um par envio-recebimento; separar os dois deixaria o atendente responder por uma caixa e ler outra |
| Onde mora o código do canal | Multi Mail: IMAP, parse, threading, ingestão, montagem do MIME | Escolha do dono. O Multi Mail já conhece as conexões e os segredos |
| Multi Mail sem o Meta | Ponte opcional com Noop; sem o Meta ele é só transporte e o IMAP não roda | O bundle tem instalações que não usam o Inbox |
| Mudanças no Meta | Só aditivas: `Channel::Email`, `AssetType::EmailAccount` (`isGraphAsset` false), operação `email_message`, `ChannelDispatcherInterface` + Noop | Mesmo caminho que o WhatsQR abriu |
| Um asset por conta | Um `MetaAsset` por conexão conversacional, `externalId` = id da conexão, sob uma `MetaConnection` interna inativa "E-mail (Multi Mail)" | `MetaAsset` exige `MetaConnection`; QR e WebChat já reaproveitam conexão inativa |
| Granularidade da conversa | **Uma conversa por (conta, e-mail do lead)** | A chave única do Meta é (asset, channel, recipient). Todas as threads com o lead naquela conta caem na mesma conversa, como no WhatsApp; o assunto é da mensagem |
| Rota do envio | Fila do Meta (`email_message`), não `ChannelTransportInterface` do Inbox | A fila dá envio assíncrono, retentativa, estado incerto e o retorno `outboundJobChanged` que o Inbox já trata. O transporte do Inbox envia dentro da requisição e não tenta de novo |
| Âncora do threading | Reply-To com +tag e token HMAC; depois In-Reply-To/References; depois a conversa (conta, remetente) | Padrão Zendesk/Front/Help Scout. Com uma conversa por par, o "remetente + assunto" vira só "remetente" |
| Message-ID no `externalId` | `email:` + sha256(asset, Message-ID); o Message-ID cru vai no payload | `meta_message_external_id` é único no banco todo, não por asset. O WhatsQR faz o mesmo com `qr:` |
| Provedor da conexão conversacional | Só `smtp` na v1 | O Multi Mail nunca define Message-ID; nas pontes de API o id devolvido é do provedor e algumas descartam cabeçalhos (MailerSend) |
| Conexão no envio conversacional | Sempre a escolhida, sem fallback e sem `auto` | A rotação `auto` reescreve From e Reply-To por mensagem; uma thread que muda de remetente deixa de ser thread |
| Biblioteca IMAP | Cliente mínimo próprio sobre `stream_socket_client` | `ext-imap` saiu do núcleo no PHP 8.4 e o CI testa 8.2 a 8.5. São poucos comandos, e o bundle já testa transporte com stream simulado |
| Parser MIME de entrada | `zbateson/mail-mime-parser` | Charset, RFC 2047 e e-mail malformado são onde parser caseiro quebra |
| Remetente desconhecido | Casa com Lead pelo e-mail só se houver **exatamente um**; senão fica sem vínculo até um atendente ligar | Mesma regra de resultado único do `ContactMatcher`; criar contato sozinho encheria a base de spam |
| Opt-in de campanha | Ação de campanha **"Iniciar conversa por e-mail"**, com texto próprio | Escolha do dono. Um campo no e-mail do Mautic levaria pixel, link reescrito e rodapé de marketing, e mexeria no formulário do core |
| Onde mora a ação de campanha | **No Inbox**, não no Multi Mail | Iniciar conversa já é do Inbox (`ContactDirectory::start`); a ação só enfileira `email_message` e precisa expandir o link do formulário, que também é do Inbox. Fica pronta para receber QR e Telegram |
| Formulário de pesquisa | No Inbox, neutro de canal | Não é código de e-mail; serve igual para WhatsApp e Telegram |
| Visual | **Estilo Gmail** no app e no web: cartões empilhados, assunto no topo, "Responder" abre composição | Escolha do dono |
| Corpo no app | **Rich text nativo** a partir de markdown que o servidor produz; "Ver original" no `expo-web-browser` | Escolha do dono. O app não tem WebView, `expo-dev-client` nem OTA; uma dependência nativa nova exige refazer o build de debug e o iOS só compila no Mac |
| Corpo no web | HTML saneado em iframe `sandbox` sem scripts, imagens remotas bloqueadas até o clique | Fidelidade de Gmail sem dependência nova |
| Limite do corpo de saída | 4000 caracteres, o mesmo de hoje | O limite já está em `body()`, nos rascunhos e no app; o que sai são os 2000 do Facebook e os 1000 do Instagram |

## Arquitetura

### Quem faz o quê

| Repositório | Versão | Responsabilidade |
|---|---|---|
| `mautic-meta-bundle` | 0.15.0 | Enums, operação `email_message` na fila e no executor, `ChannelDispatcherInterface` + Noop, despacho imediato de `email_message` humano, guardas nos pontos que tratam canal por string |
| `mautic-multi-mail-bundle` | 0.5.0 | Campos IMAP e flag na conexão, asset por conexão, montagem e envio do MIME, token, poller IMAP, parse, corte de citação, threading, ingestão, anexos e HTML original em arquivo privado |
| `mautic-inbox-bundle` | 1.9.0 | Canal `email` em consulta, disponibilidade, ações, apresentação e mobile; visual Gmail no web; ação de campanha; formulário de pesquisa; páginas do original |
| `mautic-inbox-mobile` | 1.1.0 | Opção de e-mail no sheet, tela de thread estilo Gmail, composição com assunto, contrato |
| `mautic-whatsqr-bundle` | — | Nenhuma mudança. É o precedente |

### As pontes opcionais

Multi Mail declara `ConversationBridgeInterface` com uma implementação `NoopConversationBridge`
como padrão. A `MetaConversationBridge` é registrada só quando as classes do Meta existem, no
padrão do WhatsQR (`Config/services.php` exclui a pasta e registra atrás de `class_exists`).
Com o Noop:

- o flag `conversational` aparece desabilitado na tela de conexões, com a explicação;
- o comando de leitura IMAP sai sem fazer nada;
- o despachante não existe, então nenhum asset de e-mail é criado.

No Meta, `ChannelDispatchers` escolhe o despachante pelo `asset_type` do job entre os serviços com
a tag `mautic_meta.channel_dispatcher` — o mesmo desenho do `TransportResolver` do WhatsApp, e não
um alias sobrescrito, para que um segundo canal (Telegram) entre sem disputar o apelido. Sem
despachante para o tipo, vale o `NoopChannelDispatcher`, que lança `DomainException`: a fila trata
isso como falha definitiva. Uma `RuntimeException` deixaria o job `uncertain`, e incerto não se
tenta de novo.

O anexo e o HTML original entram no Inbox por uma interface própria,
`EmailContentProviderInterface` (tag `mautic.inbox.email_content`), com o provider registrado no
Multi Mail atrás de `interface_exists`, como o `AttachmentProvider` do WhatsQR. A
`AttachmentProviderInterface` existente não serve: devolve uma URL por mensagem e proíbe IO, e um
e-mail tem vários anexos e um HTML original guardado em arquivo.

### A conexão conversacional

Campos novos na conexão, todos opcionais, visíveis só para provedor `smtp`:

- `imap_host`, `imap_port` (993, ou 143 com STARTTLS obrigatório), `imap_username`, `imap_folder`
  (padrão `INBOX`), `imap_sent_folder` (opcional), `conversational` (bool);
- `imap_password` em `secrets`, com tratamento explícito: o laço de segredos descarta chave que
  não está em `PROVIDERS`, e `publicData()` só esconde `secrets` — senha guardada em outro lugar
  vazaria para a página e para o `ExampleSendExtension`.

O `ConnectionStore::save()` ganha validadores opcionais: o `text()` de hoje exige valor não vazio.
Registros antigos recebem os padrões no mesmo ponto em que hoje recebem os campos de cota.

Ao salvar uma conexão com `conversational` ligado, a ponte garante o `MetaAsset` dela (tipo
`email_account`, nome da conexão, ativo). Desligar ou remover a conexão **inativa** o asset; não
apaga, para o histórico continuar legível. O poller repete a garantia a cada passada, então um
asset apagado à mão volta sozinho.

O teste da conexão ganha um botão **"Testar ida e volta"**: envia pela SMTP para
`local+teste<aleatório>@domínio`, lê a pasta por até 60 s e diz se a mensagem chegou e por qual
endereço. É a prova do +tag em cada caixa. Se a caixa não aceitar +tag, a conexão grava
`plus_addressing: false` e o Reply-To sai sem token; o threading continua pelos cabeçalhos e pelo
remetente.

### Arquivos privados

Tudo no diretório privado que o Multi Mail já usa (fora do web root, 0700/0600, guarda de
symlink), com os nomes que `PrivateStorage` aceita (`/^[a-z-]+\.json$/`):

- `imap-state.json` — por conexão: `uidvalidity`, `last_uid`, último sucesso, último erro, falhas
  seguidas, próxima tentativa;
- `mail-blobs/` — anexos e HTML original, por sha256 do conteúdo, com descritor JSON ao lado.

Os dois entram no `.gitignore` e na exclusão do `build/package.py`.

## Os dois fluxos

### Saída: atendente → lead

1. **Iniciar.** O sheet de canais ganha uma opção `email:{assetId}` por conta conversacional
   ativa. `start` cria `MetaConversation(channel 'email', recipient = e-mail do lead em
   minúsculas)`, liga o contato e cria o `ConversationState` atribuído a quem iniciou, com
   `humanTakeover`. **Não envia nada**, igual ao QR.
2. **Responder.** `ConversationActions` enfileira `email_message` com
   `{recipient, subject, text, author_name, _origin: 'inbox_human', _inbox_conversation_id}` e
   chave `inbox:<request_id>`. O assunto vazio vira `Re: <assunto da última mensagem>`. O
   marcador de formulário é expandido aqui (ver "Formulário de pesquisa").
3. **Despacho imediato.** `ImmediateOutboundDispatcher` passa a aceitar `email_message` com
   `_origin` humano, como já aceita `whatsapp_text`. O SMTP leva 1 a 3 s dentro da requisição; se
   falhar de forma temporária, a fila do cron cobre.
4. **Executor.** O `match` do `OutboundOperationExecutor` ganha `email_message` →
   `ChannelDispatcherInterface::dispatch($job)`. O retorno é `MetaMessage`, que já está no tipo.
5. **Montagem**, no Multi Mail:
   - **DNC.** Contato com DNC de e-mail → `DomainException` com o motivo.
   - **Message-ID.** Na primeira tentativa, gera `<{16 bytes hex}.{assetId}@{domínio do From}>` e
     grava em `_email_message_id` **no payload do job**, antes de enviar. Toda retentativa —
     automática, manual no Meta ou pelo "tentar de novo" do Inbox, que copia o payload — usa o
     mesmo. Gmail e afins descartam a cópia se uma mensagem sair duas vezes.
   - **`MetaMessage` de saída**, `pending`, com o `externalId` de escopo, gravada antes do envio.
   - **From:** `"{nome do atendente} · {from_name da conexão}" <from_email>`. Campanha usa o nome
     da ação.
   - **Reply-To:** `local+r{token}@domínio` (ver "O token").
   - **In-Reply-To e References:** a última mensagem de entrada da conversa e a cadeia dela,
     limitada às 10 mais recentes.
   - **Corpo:** `multipart/alternative` com texto e HTML. O HTML vem do mesmo subconjunto de
     markdown que o composer mostra (parágrafos, quebras, negrito, itálico, links, listas), em
     modelo simples. No topo, a sentinela `##- Responda acima desta linha -##` em cinza.
     **Sem pixel e sem reescrever link.**
   - **Campanha** acrescenta `List-Unsubscribe` e `List-Unsubscribe-Post` de um clique; resposta
     humana não.
6. **Envio** pela conexão do asset, pelo caminho de baixo nível que o `ConnectionTester` já usa:
   `transportChain($id)[0]`, reserva de cota, `build`, `send`, `finishReservation`. **Sem
   fallback.**
7. **Resultado.** Aceito → `MetaMessage` `sent`, `ConversationManager::record()`, job
   `completed`, `outboundJobChanged` → o Inbox marca enviado. Falhas em "Bordas e falhas".

### Entrada: lead → atendente

1. **Comando** `mautic:multimail:imap:poll`, no cron do Forge a cada minuto. Um `flock` por
   conexão, então duas passadas nunca leem a mesma caixa. Conexão em espera (ver falhas) é pulada.
2. **Leitura sem efeito colateral.** `EXAMINE` da pasta, `UID SEARCH UID {last+1}:*`,
   `UID FETCH BODY.PEEK[]` em lotes de 50. Nunca altera flag: convive com quem lê a mesma caixa no
   celular. Mensagem acima de 25 MiB entra só com cabeçalhos e o aviso "mensagem grande demais".
3. **Posição.** O `last_uid` só avança depois que a mensagem foi gravada. Ler duas vezes é
   possível; gravar duas vezes, não (deduplicação no passo 6). Na primeira passada de uma conexão
   o `last_uid` nasce do `UIDNEXT` atual.
4. **Triagem**, antes de qualquer threading:
   - **Eco.** Message-ID que é nosso → ignora.
   - **Bounce** (`multipart/report; report-type=delivery-status`) → acha a mensagem de saída pelo
     Message-ID original, marca `failed` com o diagnóstico, grava evento na conversa. Não abre
     conversa nem grava DNC.
   - **Resposta automática** (`Auto-Submitted` diferente de `no`, `X-Autoreply`,
     `X-Autorespond`, assunto de férias) → entra como evento na conversa, sem somar não lida e sem
     `needsResponse`.
   - **Lista ou massa** (`List-Id`, `Precedence: bulk|list|junk`) **sem** vínculo com conversa →
     ignora.
5. **Threading**, nesta ordem:
   1. token válido no +tag de `To`, `Cc`, `Delivered-To` ou `X-Original-To` → conversa do token;
   2. `In-Reply-To` ou `References` batendo com um Message-ID nosso → conversa daquela mensagem;
   3. conversa existente (asset, remetente);
   4. conversa nova (asset, remetente), com o contato ligado pela regra do resultado único.

   Se o lead responde de outro endereço, o token mantém a mensagem na conversa original; o
   `recipient` não muda, e o cartão mostra o endereço real.
6. **Deduplicação.** `externalId` = `email:` + sha256(asset, Message-ID em minúsculas). Sem
   Message-ID, sha256(remetente, `Date`, assunto, sha256 do corpo).
7. **Corpo.**
   - decodifica charset e transferência; prefere `text/plain`, senão converte o HTML em markdown;
   - corta na sentinela; sem ela, nos padrões de citação de Gmail, Outlook, Apple Mail e
     Thunderbird em português e inglês (`Em … escreveu:`, `On … wrote:`, bloco `De:/Enviado:`,
     `div.gmail_quote`, `blockquote type=cite`, `#divRplyFwdMsg`), nas linhas com `>` e na
     assinatura `-- `;
   - faz o mesmo corte no HTML, pelo DOM;
   - o HTML completo vai para `mail-blobs/`; o payload guarda só a parte nova (até 64 KiB) e a
     referência. **O HTML inteiro no payload pesaria em toda leitura de linha do tempo.**
8. **Gravação.** `MetaMessage(direction 'inbound', channel 'email', messageType 'email',
   status 'received')`, com `dateAdded` = `INTERNALDATE` da caixa (nunca no futuro), para que uma
   caixa lida com atraso não embaralhe a ordem. Depois `ConversationManager::record()` e
   **depois** `InboxIntegrationInterface::messagePersisted()` — o `record` só notifica saída. Não
   lida, SSE e push seguem o caminho atual.
9. **Anexos.** Para `mail-blobs/`, com as regras do WhatsQR 0.3.0: lista de MIME por tipo,
   extensão compatível, 25 MiB por arquivo, 2 GiB no total, antivírus pelo clamd (socket
   configurável). **Sem antivírus configurado, o anexo aparece como indisponível.** `cid:` inline
   vira referência de anexo.

### O payload

```json
{
  "email": {
    "message_id": "<CAF3x…@mail.gmail.com>",
    "in_reply_to": "<9f2c….31@macromarkets.com>",
    "references": ["<9f2c….31@macromarkets.com>"],
    "from": {"name": "João Lead", "address": "joao@example.com"},
    "to": [{"name": "Macro Markets", "address": "contato+rk3…@macromarkets.com"}],
    "cc": [],
    "subject": "Re: Por que você ainda não depositou?",
    "text": "Oi Ana, ainda estou decidindo. **Qual o depósito mínimo?**",
    "quoted": "> Em 23/10, Ana escreveu: …",
    "html": "<div dir=\"ltr\">Oi Ana, ainda estou decidindo…</div>",
    "original": "blob:3b1e…",
    "auto_reply": false,
    "unverified_sender": false,
    "attachments": [{"blob": "9a0c…", "type": "document", "name": "extrato.pdf", "size": 183422, "cid": null}]
  }
}
```

`unverified_sender` é true quando o `Authentication-Results` que a própria caixa grava diz
`dmarc=fail` para um From que casou com um Lead. O cartão mostra o aviso; nada é bloqueado.

### O token

`r` + base32 minúsculo, sem padding, de 15 bytes: versão (1) + id da conversa (4) + os 10
primeiros bytes de `HMAC-SHA256(chave, "multimail-thread|v1|{assetId}|{conversationId}")`. A chave
vem do `secret_key` do Mautic por HKDF com o rótulo `multimail-thread-v1`. Dá 25 caracteres:
`contato+rk3x…@` cabe folgado nos 64 da parte local.

- **Minúsculo** porque há servidor que dobra a caixa da parte local.
- **O asset entra no HMAC**: token de uma conta que chega na caixa de outra é ignorado.
- Token inválido é ignorado e registrado; a regra seguinte decide.
- Trocar o `secret_key` invalida os tokens antigos e o threading cai para os cabeçalhos. Aceitável.

## Opt-in de campanha

Ação **"Iniciar conversa por e-mail"**, registrada pelo Inbox em `CAMPAIGN_ON_BUILD` com evento
em lote, como as ações do Meta. Configuração:

- conta (assets `email_account` ativos);
- nome no From (padrão: `from_name` da conexão);
- assunto e texto, com tokens de contato (`{contactfield=firstname}`) e o marcador de formulário.

Para cada contato do lote:

1. sem e-mail válido → falha "sem e-mail";
2. DNC de e-mail → falha "não quer receber e-mails";
3. conversa (asset, e-mail) **aberta, com atendente e em `humanTakeover`** → falha "conversa em
   atendimento" — campanha não fala por cima de uma pessoa;
4. senão garante a conversa, liga o contato e enfileira `email_message` com
   `_origin: 'inbox_campaign'` e chave `campaign:{id do log do evento}`. Reexecutar o mesmo evento
   não duplica.

O evento passa quando o job é enfileirado; entrega e falha de entrega aparecem no Inbox. A cota
por hora da conexão vale aqui: estourou, o despachante lança `ChannelTemporarilyUnavailable` e o
job reprograma. **Caixa conversacional mandando volume de campanha queima reputação**; a tela de
conexões passa a recomendar limite por hora baixo quando `conversational` está ligado.

O link de descadastro (`List-Unsubscribe`) aponta para uma rota pública assinada do Inbox que grava
DNC de e-mail e responde 200 ao POST de um clique (RFC 8058).

## Formulário de pesquisa na thread

- **Escolher.** No menu "+" do composer, web e app, "Formulário": lista os formulários publicados
  (`GET …/forms`) e insere o marcador `[[form:12]]` no texto.
- **Expandir.** No enfileiramento — resposta humana ou campanha — o Inbox troca o marcador por
  `[Nome do formulário](https://…/inbox/f/{token})`. O token é HMAC sobre (formulário, contato,
  conversa, validade de 30 dias). Formulário despublicado → 422 antes de enfileirar.
- **Abrir.** `/inbox/f/{token}` é pública. Ela confere o token, marca o contato como rastreado
  (`ContactTracker::setTrackedContact`), grava "abriu o formulário X" na conversa e redireciona
  para a página pública do formulário. Campos com auto-preenchimento já vêm preenchidos. Token
  vencido ou adulterado → página neutra "link expirado", sem revelar nada.
- **Responder.** Um assinante de `FormEvents::FORM_ON_SUBMIT` acha a conversa pelo contato e pelo
  formulário aberto nos últimos 30 dias e grava "respondeu o formulário X" com as respostas na
  linha do tempo. A decisão nativa "Envia formulário" das campanhas continua funcionando.

O desenho não depende de e-mail: o mesmo marcador funciona numa resposta de WhatsApp.

## Visual estilo Gmail

Vale para o web e para o app quando `conversation.channel === 'email'`. Os outros canais não mudam.

- **Topo:** assunto da última mensagem e um chip com a conta (`Macro Markets · contato@…`).
- **Cartões empilhados**, não bolhas:
  - **recolhido:** avatar, remetente, trecho de uma linha, data;
  - **aberto:** a última mensagem e as não lidas; o resto abre ao toque;
  - com mais de quatro recolhidos seguidos, uma linha **"N mensagens anteriores"** os agrupa.
- **Cartão aberto:** cabeçalho com remetente, "para" (expansível com Cc), data; corpo; anexos em
  chips ou miniaturas; botão **"•••"** que mostra o texto citado, que é guardado, não descartado.
  Aviso de remetente não verificado, quando houver.
- **Notas internas e eventos** do Inbox (atribuição, "abriu o formulário", resposta automática)
  aparecem entre os cartões como linha centralizada, no estilo de evento que já existe.
- **Fim da thread:** "Responder" e "Encaminhar".
  - **Responder** abre a composição: Para (o lead, fixo), De (a conta), Assunto (`Re: …`,
    editável), corpo, menu "+" com respostas prontas e formulário.
  - **Encaminhar** reaproveita a ação de hoje: copia o texto para o rascunho de outra conversa.
- **Lista de conversas:** a linha de e-mail mostra remetente, **assunto** e trecho.
- **Push:** título com o nome do contato, corpo com assunto e trecho.

### No web

- Componentes novos `EmailThread.svelte`, `EmailCard.svelte`, `EmailBody.svelte`,
  `EmailComposer.svelte`. **O prefixo é `EmailThread*`/`EmailCard*`** porque `EmailActions.svelte`
  e as rotas `email-options`/`email-actions` já existem para o chip de e-mail do CRM.
- O corpo aberto roda num `<iframe sandbox="allow-same-origin allow-popups
  allow-popups-to-escape-sandbox" srcdoc=…>` **sem `allow-scripts`**. `allow-same-origin` existe
  só para o pai medir a altura; sem script, o conteúdo não executa nada. Junto, um CSP no
  `srcdoc`: `default-src 'none'; style-src 'unsafe-inline'; img-src data: {proxy}`. Links abrem em
  nova aba com `rel=noopener noreferrer`.
- **Imagens remotas** ficam bloqueadas até "mostrar imagens" no cartão. Pixel de rastreio do
  remetente não deve revelar o IP do atendente.
- O HTML chega já saneado pelo servidor (ver "Segurança"). O iframe é a segunda camada, não a
  única.
- Mensagem sem HTML renderiza o markdown pelo `messageBody()` que já existe.
- "Ver original" abre o HTML completo saneado num modal com o mesmo iframe.
- Pontos que hoje listam canal à mão e ganham `email`: `Icon.svelte` (ícone de envelope),
  `Avatar.svelte`, `ConversationList.svelte` (rótulo e opção do filtro), `ContactPanel.svelte`,
  `InboxApp.svelte` (cabeçalho), `inbox.css` (cor do canal), `Frontend/shared/types.ts`. O build em
  `Assets/dist/` é versionado e precisa subir junto.

### No app

- `src/screens/Chat.tsx` troca para `EmailThread` quando o canal é e-mail; `EmailCard.tsx` e uma
  tela `EmailCompose.tsx`.
- **O corpo é o markdown que o servidor manda**, pelo `messageDocument`/`MessageBody` que já
  existem (`html:false`). Duas extensões:
  - imagem passa a renderizar, só por URL do proxy autenticado (anexo `cid:`); imagem remota fica
    atrás de "Mostrar imagens";
  - o texto citado vem num campo separado e abre no "•••".
- **Tabela e layout de newsletter** não cabem em markdown: o cartão mostra o texto e
  "Ver original" abre o HTML saneado no `expo-web-browser` (já instalado), por um link assinado de
  5 minutos — o navegador do sistema não manda o cabeçalho Bearer.
- Ajustes que o canal novo exige:
  - **migração do filtro salvo `ui:view`**: quem já usa o app tem a lista de canais salva sem
    `email`, e `Inbox.tsx` esconderia as conversas novas;
  - o atalho "salvar e-mail" do `ChatMessage` não aparece no canal e-mail (dispararia em toda
    assinatura);
  - `messageFromApi` mantém **todos** os anexos, não só o primeiro;
  - `Channel` e o mapa de rótulos ganham `email`; `Icon.tsx` ganha o envelope;
    `conversation-connection.ts` mostra o endereço da conta;
  - copiar, compartilhar e encaminhar usam o texto, nunca o HTML.

## Contrato entre os repositórios

O app avança contra estes exemplos com o transporte de mock enquanto o servidor é construído. Os
campos abaixo **somam-se** aos que já existem; nada existente muda de forma.

**Opção no sheet** — `GET /inbox/mobile/api/contacts/{id}`, item de `channels[]`:

```json
{
  "key": "email:31",
  "state_id": null,
  "asset_id": 31,
  "channel": "email",
  "name": "Macro Markets · contato@macromarkets.com",
  "address": "contato@macromarkets.com",
  "available": true,
  "reason": null
}
```

Motivos de indisponível: "Cadastre um e-mail válido no contato.", "O contato pediu para não
receber e-mails.", "Esta conta de e-mail está desativada." Conversa de e-mail já existente aparece
como hoje, com `key: "state:{id}"` e `channel: "email"`.

**Iniciar** — `POST /inbox/mobile/api/contacts/{id}/start` com `{"channel_key": "email:31"}`.
Resposta: o resumo da conversa, como hoje.

**Resumo da conversa:**

```json
{
  "channel": "email",
  "asset": {"id": 31, "name": "Macro Markets", "type": "email_account", "address": "contato@macromarkets.com"},
  "recipient": "joao@example.com",
  "subject": "Re: Por que você ainda não depositou?",
  "email": {"inbound": "ok", "inbound_since": null}
}
```

`inbound` vira `"delayed"`, com `inbound_since`, quando a leitura IMAP da conta falha há mais de
30 minutos. O envio continua disponível; o app e o web mostram uma faixa discreta.

**Item da linha do tempo** (entrada ou saída):

```json
{
  "body": "Oi Ana, ainda estou decidindo. **Qual o depósito mínimo?**",
  "subject": "Re: Por que você ainda não depositou?",
  "email": {
    "from": {"name": "João Lead", "address": "joao@example.com"},
    "to": [{"name": "Macro Markets", "address": "contato@macromarkets.com"}],
    "cc": [],
    "quoted": "> Em 23/10, Ana escreveu: …",
    "has_html": true,
    "auto_reply": false,
    "unverified_sender": false
  },
  "attachments": [
    {"type": "document", "label": "extrato.pdf", "url": "https://mkt-macro.on-forge.com/s/multimail/media/…", "available": true, "size": 183422}
  ]
}
```

O `to` mostra o endereço da conta sem o +tag: o token não aparece na interface.

**Responder** — `POST /inbox/api/conversations/{id}/reply` (web e mobile) com
`{"body": "…", "request_id": "…", "subject": "Re: …"}`. `subject` é opcional e só vale para e-mail.
A resposta 202 de hoje ganha `subject` no item de saída.

**Original:**

- web: `GET /s/inbox/api/messages/{id}/original` → `{"html": "…saneado…"}`;
- mobile: `POST /inbox/mobile/api/messages/{id}/original-link` →
  `{"url": "https://…/inbox/mobile/original/{token}", "expires_at": "…"}`.

**Formulários** — `GET /inbox/api/forms` e `GET /inbox/mobile/api/forms` →
`[{"id": 12, "name": "Pesquisa: por que não depositou"}]`.

**Capacidades** — `capabilities.channels` do `MobileAuthController` inclui `"email"`.

## Guardas

Pontos que hoje tratam qualquer canal desconhecido como Instagram e precisam recusar `email`
explicitamente:

- `ConversationActions::outboundTarget` (cai em `instagram_direct_message`) → `email_message`;
- `AiWorker` (padrão instagram) e o `AiService` → **canal e-mail fora da IA na v1**;
- no Meta, `ConversationController` e `AdapterReplyController` (qualquer canal que não seja
  WhatsApp vira `instagram_direct_message`) → 422 para `email`;
- `ReplyAvailability`: ramo explícito de e-mail **antes** da janela de 24 horas, que hoje cobriria
  o canal novo com o texto do Instagram;
- `InboxQuery`: `email` na lista fixa de canais aceitos.

O plano percorre a lista completa de comparações de canal por string que existe no Meta e no Inbox
(levantada nesta fase) e decide cada uma.

## Bordas e falhas

### Envio

- **Recusa confirmada** (antes do DATA, ou 4xx) → `ChannelTemporarilyUnavailable` → retentativa
  com espera crescente, até 2 h.
- **5xx** → `DomainException` com o texto do servidor ("550 mailbox not found") → falha, motivo
  visível no cartão.
- **Sem cota** → `ChannelTemporarilyUnavailable` com o `retry_at` do ledger.
- **Incerto** (timeout depois do DATA) → `uncertain`, sem repetição automática.
  - Se a conta tem `imap_sent_folder`, o poller procura o Message-ID lá
    (`UID SEARCH HEADER Message-ID`) por até 24 h e promove a mensagem a enviada. Gmail e
    Microsoft 365 gravam o que sai por SMTP em Enviados; hospedagens comuns não.
  - Sem confirmação, o atendente decide pelo "tentar de novo" do Inbox. O Message-ID é o mesmo, e
    o cliente do lead descarta a cópia se ela chegar duas vezes.
- **Nunca troca de conexão.** From e Reply-To pertencem àquela caixa.

### Leitura IMAP

- **Falha de rede ou servidor** → espera crescente por conexão: 1, 2, 4… até 30 min.
- **Senha recusada** → para de tentar até a conexão ser salva de novo; erro na tela de conexões.
- **30 min sem sucesso** → notificação do Mautic para administradores, selo na conexão e
  `email.inbound: "delayed"` no resumo. O envio segue: o SMTP é independente.
- **UIDVALIDITY mudou** → relê os últimos 7 dias por `SINCE`; a deduplicação descarta o que já
  entrou.
- **Mensagem que não parseia** → entra como evento "e-mail ilegível de {remetente}", com o blob
  bruto guardado, e o UID avança. Uma mensagem ruim não trava a caixa.

### Duplicatas

- Entrada: `externalId` de escopo (passo 6 da entrada).
- Saída: chave `inbox:<request_id>` ou `campaign:{log}`, a ordem FIFO por (contato, asset) que
  `findDue` já garante, e o Message-ID estável no payload do job.

### Convivência

- **Não configurar a mesma caixa como "Monitored Inbox" do Mautic**, que move e marca mensagens. A
  tela de conexões avisa quando o endereço coincide com o configurado no Mautic.
- Resposta que a caixa joga no spam não entra na v1.
- Uma pessoa lendo a caixa no celular não interfere: a leitura é `PEEK`, e o Inbox não espelha
  lido/não lido para a caixa.

## Segurança e privacidade

- **HTML de entrada nunca vai cru para a tela.** O Inbox saneia no servidor por lista de permissão
  (biblioteca definida no plano; candidata `symfony/html-sanitizer`): sem `script`, `iframe`,
  `object`, `form`, `on*`, `javascript:`, `data:` fora de imagem, CSS externo ou `@import`. O
  iframe sem scripts e o CSP são a segunda camada.
- **Anexo** só pelo proxy autenticado, com `nosniff`, `Content-Security-Policy: sandbox`, documento
  como `attachment` e a checagem de acesso à conversa que o proxy mobile já faz. O
  `MediaRoutes` do mobile passa a reescrever também o caminho do Multi Mail.
- **Senha IMAP** em `secrets`, com a mesma proteção de arquivo das senhas SMTP; TLS obrigatório
  (993 ou STARTTLS), nunca texto puro.
- **Token do Reply-To** não carrega o id cru: sem a chave, ninguém fabrica um token que encaixe
  mensagem em conversa alheia.
- **Link de formulário identifica o contato.** Se o lead encaminhar o e-mail, quem abrir o link
  passa como ele — o mesmo risco dos links rastreados do Mautic. A validade de 30 dias limita.
- **Link do original no app**: 5 minutos, amarrado à mensagem e ao usuário da sessão.
- **LGPD**: DNC de e-mail respeitado em toda saída; campanha leva descadastro de um clique.

## Testes

### Multi Mail — no WSL, sem kernel, no estilo atual

O `PrivateStorage` recusa o Windows (o `fileperms` dali reporta 0666), então os testes rodam no
WSL ou no CI Linux.

- **Token:** ida e volta, adulterado, de outra conta, maiúsculo, versão desconhecida.
- **Corte de citação** com um corpus de `.eml` reais em `Tests/Fixtures/mail/`: Gmail web e iOS,
  Outlook web e desktop, Apple Mail, Thunderbird, em português e inglês; com e sem sentinela;
  resposta no topo e resposta intercalada.
- **MIME de saída:** cabeçalhos presentes, Message-ID estável entre tentativas, sentinela, as duas
  partes, `List-Unsubscribe` só em campanha, nada de token sem `plus_addressing`.
- **Threading:** a ordem das quatro regras, resposta de outro endereço, token inválido caindo para
  cabeçalho.
- **Triagem:** bounce casando a mensagem original, auto-resposta, lista sem vínculo, eco.
- **Cliente IMAP** com stream simulado, como `Tests/transports.php`: login, `EXAMINE`, busca por
  UID, `FETCH` literal, erro no meio do lote.
- **Poller:** UID só avança depois da gravação; UIDVALIDITY nova; duas passadas concorrentes com
  `proc_open`, como os testes de cota.
- **Conexão:** campos opcionais, senha IMAP fora do `publicData`, registro antigo com padrões.

### GreenMail

Um container GreenMail (SMTP e IMAP) faz o ciclo real: envia pela SMTP, lê pelo IMAP, parseia,
confere o token, responde com `In-Reply-To` e vê a resposta cair na conversa certa; troca o
UIDVALIDITY. Roda no WSL com Docker e como *service container* no GitHub Actions do Multi Mail.

### Meta

- `AssetTypeTest` já percorre `cases()` e pega o `match` que faltar.
- O executor despacha `email_message` para o despachante; o Noop dá `failed`, não `uncertain`.
- A lista de operações da fila aceita `email_message`; o despacho imediato aceita só o de origem
  humana.

### Inbox

PHPUnit via DDEV num checkout de dev do Mautic 7 **que ainda não existe nesta máquina**: montá-lo
no WSL é a primeira tarefa do plano. Casos:

- `ReplyAvailability`: e-mail sem janela de 24 h; DNC; asset inativo.
- `ContactDirectory`: opção `email:` por conta, motivos, `start` criando conversa em minúsculas e o
  409 de contato diferente.
- `ConversationActions`: `email_message` com assunto, `Re:` automático, marcador de formulário
  expandido, 422 de formulário despublicado.
- `MessagePresentation`: texto, citado, anexos múltiplos, `has_html`.
- **Saneamento** com corpus hostil: `script`, `on*`, `javascript:`, `svg` com script, CSS remoto,
  `meta refresh`, `base`.
- Ação de campanha: as quatro saídas por contato e a idempotência por log.
- Formulário: token vencido, adulterado, e o registro do envio.
- JS: composição com assunto, cartão recolhido e aberto, filtro e ícone.

### App

`node:test`, como hoje: chave `email:` no sheet, assunto no envio, vários anexos no
`messageFromApi`, **migração do `ui:view`**, ausência do atalho "salvar e-mail", fixtures do
contrato no transporte de mock. O teste manual é no emulador Android com `npx expo run:android`
(build debug ligado ao Metro); não há `expo-dev-client` nem `eas.json`. iOS fica para o build no
Mac.

### Produção

Não há staging. O flag `conversational` é o portão:

1. "Testar ida e volta" na caixa real, para confirmar o +tag;
2. uma conta e um contato de teste durante a primeira semana;
3. só então a conta real e a primeira campanha, com limite por hora baixo.

## Ordem de implementação e paralelismo

1. **Prova do +tag** na caixa real e confirmação de que o IMAP está habilitado nela.
2. **Mautic 7 de dev no WSL com DDEV** (Docker Desktop com integração WSL), com Meta, Inbox e
   Multi Mail montados como plugins.
3. **Meta 0.15.0.** É pequeno e destrava todos os outros.
4. **Em paralelo**, contra o contrato deste documento:
   - Multi Mail 0.5.0;
   - Inbox 1.9.0, servidor e web;
   - app 1.1.0, com o mock.
5. **Integração em produção** com a conta de teste; depois a conta real.

O `Config/config.php` e o `CHANGELOG.md` do Inbox ainda dizem 1.5.2 com tags até v1.8.2; o release
1.9.0 corrige isso.

## Follow-ups

- IMAP IDLE com processo supervisionado.
- Anexo de saída no web e no app.
- Gravar cópia em Enviados para hospedagem que não grava sozinha.
- Ler a pasta de spam da caixa.
- IA respondendo e-mail.
- Atendente fixo na ação de campanha.
- Decisão de campanha "respondeu o e-mail".
- Expurgo de `mail-blobs/` por idade.
- Telegram no mesmo sheet, na mesma ação de campanha e no mesmo formulário.
