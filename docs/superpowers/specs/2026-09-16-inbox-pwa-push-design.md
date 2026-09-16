# Design: Inbox como PWA com Web Push

Data: 2026-09-16
Status: Aprovado, não implementado
Escopo: `MauticInboxBundle` — shell instalável, service worker e notificação de mensagem recebida

## Problema

O atendimento só existe enquanto alguém mantém `/s/inbox` aberto no navegador. O SSE
atualiza a tela em tempo real, mas não acorda ninguém: fora da aba, uma mensagem de
WhatsApp fica esperando até que um atendente volte a olhar. Com 3 a 10 pessoas
revezando, a primeira resposta depende de quem por acaso estava com a aba aberta.

O objetivo é um app instalável no celular que notifique com o app fechado, sem
duplicar a interface nem criar um segundo caminho de autenticação para o Mautic.

## Objetivos (v1)

1. Shell instalável em `/s/inbox/app`, cobrindo atendimento e workspace de IA, montando
   os componentes Svelte que já existem sem alterá-los.
2. Web Push com service worker e VAPID, entregando em iPhone, Android e desktop.
3. Notificação de **mensagem recebida**, roteada por dono da conversa.
4. Conteúdo do push com nome do contato e prévia da mensagem.
5. Toque na notificação abre a conversa, reaproveitando a janela já aberta.
6. Nenhuma dependência de Composer nova.

## Fora de escopo (v1)

- Envio offline, fila de rascunhos offline, Background Sync.
- Cache de conversas em IndexedDB.
- Notificação de transferência, de agente de IA pausado e de soneca encerrada.
- Preferências por usuário: canais, horário de silêncio, desligar a fila.
- Contador de não lidas no ícone (Badging API).
- Empacotar o Mautic inteiro como PWA.

## Decisões

| Questão | Decisão | Motivo |
|---|---|---|
| Público | 3–10 atendentes, iPhone e Android | Exige roteamento por usuário, não fan-out |
| Escopo do app | Inbox + IA, navegação inferior | Os dois módulos do plugin, nada além |
| Rotas | `/s/inbox/app/*`, separadas das atuais | Isolamento total do que está em produção |
| Quem é notificado | Dono sempre; fila sem dono notifica todos; conversa alheia não notifica | Evita o ruído que torna a notificação ignorável |
| Sessão | Sessão Mautic com `remember-me` | Já configurado para 90 dias; não cria segundo caminho de auth |
| Conteúdo | Nome e prévia | Aceitável porque a carga é cifrada ponta a ponta |
| Disparo | `kernel.terminate` | Latência de ~1s sem somar tempo à resposta do webhook |
| Criptografia | Implementação própria sobre `ext-openssl` | Plugin autocontido, sem árvore transitiva a conflitar |
| Janela visível naquela conversa | Suprimir a notificação | O SSE já atualizou a tela. Mensagem de outra conversa notifica normalmente |

## Arquitetura

```
  Meta ──webhook──► MetaBundle ──persiste MetaMessage
                        │
                        ▼  InboxIntegrationInterface (fronteira já existente)
              MetaInboxIntegration::persistInbound()
                        │ registra intenção em memória
                        ▼
  ─────────────────── kernel.terminate ───────────────────
                        │
                  PushAudience ──► destinatários
                        │
                  PushPayload ──► {título, corpo, tag, url}
                        │
                  PushDispatcher ──► WebPushCrypto ──► serviço de push
                        ▲                                      │
                  push_devices                                 ▼
                  push_deliveries (reentrega)          service worker
                        ▲                                      │
                        └── mautic:inbox:wake            showNotification
```

## Backend

| Unidade | Papel |
|---|---|
| `Entity/PushDevice` | Um registro por aparelho: usuário, endpoint, `p256dh`, `auth`, user agent, última entrega, falhas consecutivas, ativo |
| `Entity/PushDelivery` | Fila de reentrega: aparelho, payload, tentativas, próxima tentativa |
| `Application/Push/PushAudience` | A regra de quem recebe, isolada e testável sem banco |
| `Application/Push/PushPayload` | Monta e trunca o texto claro em 3993 octetos (ver *Orçamento de tamanho*) |
| `Application/Push/PushDispatcher` | Envia, classifica o erro, decide entre apagar, reenfileirar e descartar |
| `Application/Push/WebPushCrypto` | VAPID e RFC 8291 sobre `ext-openssl` |
| `Application/Push/VapidKeys` | Lê e grava o par: privada em PEM criptografado, pública em base64url cru |
| `Controller/PushController` | Inscrição, cancelamento e chave pública, sob sessão, permissão e CSRF |
| `Controller/PwaShellController` | `/s/inbox/app`, `/s/inbox/app/conversations/{id}`, `/s/inbox/app/ai` |
| `Controller/PwaAssetController` | `/inbox-manifest.webmanifest` e `/inbox-sw.js`, públicos |
| `Command/PushSetupCommand` | Gera e guarda o par VAPID |
| `Command/PushTestCommand` | Envia notificação de teste para os aparelhos de um usuário |

O gancho é `MetaInboxIntegration::persistInbound()`, que já é chamado pela interface
`InboxIntegrationInterface` do Meta Bundle. **Nenhum arquivo do Meta Bundle é alterado.**

## Frontend

| Unidade | Papel |
|---|---|
| `Frontend/app/AppShell.svelte` | Casca com navegação inferior entre Atendimento e IA |
| `Frontend/app/InstallGuide.svelte` | Detecta o aparelho e ensina a instalar; pede a permissão num toque explícito |
| `Frontend/app/push.ts` | Registra o service worker, inscreve e sincroniza com a API |
| `Frontend/sw/sw.ts` | Precache da casca, `push`, `notificationclick` |
| `Resources/views/App/shell.html.twig` | Layout mínimo, sem sidebar nem topbar do Mautic |

`InboxApp.svelte` e `AiApp.svelte` são montados como estão. Escopo do service worker: `/s/`.
O bundle e o service worker são compilados pelo Vite e versionados, mantendo a
propriedade de instalar o plugin sem Node.js em produção.

## Criptografia, sem dependência

Duas camadas independentes, ambas sobre `ext-openssl`, já presente no servidor. Uma vez
que o ECDH e o ECDSA são delegados ao OpenSSL e o empacotamento de chaves usa prefixos DER
fixos, **não há aritmética de inteiros grandes** e nem `gmp` nem `bcmath` são necessários.

### Conversão de chaves — o que o OpenSSL não faz sozinho

O PHP não aceita ponto EC cru em nenhuma direção, e isso aparece em três lugares. Sem
resolver esta parte, o resto da seção não roda:

- **Entrada.** O `p256dh` do aparelho chega como ponto não comprimido de 65 octetos em
  base64url. Para chegar ao `openssl_pkey_derive`, precisa ser embrulhado à mão num
  `SubjectPublicKeyInfo` DER — prefixo fixo de 26 octetos para P-256 — e convertido a PEM.
- **Saída.** As chaves pública efêmera e VAPID precisam voltar à forma crua de 65 octetos
  para o `key_info`, para o cabeçalho do corpo e para o parâmetro `k=`. Vêm de
  `openssl_pkey_get_details()['ec']['x'|'y']`, e **cada coordenada precisa ser preenchida
  com zeros à esquerda até 32 octetos** — a mesma armadilha do byte inicial zero que já
  vale para `R` e `S`, e que aqui produz erro silencioso em vez de falha visível.
- **Releitura.** A `VapidKeys` guarda a chave privada em **PEM**, criptografado, e não o
  escalar cru. Isso dispensa reconstruir um `ECPrivateKey` DER e evita a mesma armadilha de
  preenchimento que ela traria — a RFC 5915 exige o escalar em exatamente 32 octetos, e
  `openssl_pkey_get_details()['ec']['d']` devolve menos quando há zero à esquerda. Só o lado
  público precisa mesmo da forma crua, para o `k=` e para o `key_info`.

### VAPID (RFC 8292) — identidade do servidor

JWT ES256 com `aud` igual à **origem do endpoint**, `exp` de 12 horas e `sub` configurável,
restrito pela RFC 8292 a `mailto:` ou `https:` — o FCM recusa outras formas com um `401` de
diagnóstico difícil. O JWT é reaproveitável dentro da validade, mas **por origem**: um token
gerado para o endpoint do FCM não vale para o da Mozilla. Reaproveitar entre origens é o erro
clássico desta implementação.
Assinatura por `openssl_sign` com `OPENSSL_ALGO_SHA256`, seguida da conversão da
assinatura DER para os 64 bytes crus `R||S` que o padrão exige — este é o passo que
implementações ingênuas erram. Vai no cabeçalho
`Authorization: vapid t=<jwt>, k=<chave pública base64url>`.

### Conteúdo (RFC 8291) — sigilo ponta a ponta

1. Par efêmero P-256; segredo ECDH com o `p256dh` do aparelho via `openssl_pkey_derive`.
2. `PRK_key = HMAC-SHA256(auth_secret, ecdh_secret)`.
3. `key_info = "WebPush: info" || 0x00 || chave_do_aparelho || chave_efêmera`.
4. `IKM = HKDF-Expand(PRK_key, key_info, 32)`.
5. `salt` aleatório de 16 bytes; `PRK = HMAC-SHA256(salt, IKM)`.
6. `CEK = HKDF-Expand(PRK, "Content-Encoding: aes128gcm" || 0x00, 16)`.
7. `NONCE = HKDF-Expand(PRK, "Content-Encoding: nonce" || 0x00, 12)`.
8. Registro: payload, delimitador `0x02`, enchimento; AES-128-GCM.
9. Corpo: `salt(16) || rs(4, big-endian, valor 4096) || tamanho_da_chave(1) || chave_efêmera(65) || cifra`.

Cabeçalhos: `Content-Encoding: aes128gcm`, `Content-Type: application/octet-stream`,
`TTL: 3600` e `Urgency: high`.

Uma hora de TTL é decisão de produto, não detalhe: notificação de mensagem recebida perde
valor rápido, e um aparelho que ficou desligado a manhã inteira não deve vibrar com uma
conversa que já foi resolvida.

### Orçamento de tamanho

O limite de 4096 octetos da RFC 8030 vale para o **corpo cifrado**, não para o texto claro.
Com o enquadramento do item 9, o custo fixo é de 103 octetos: 86 de cabeçalho — 16 de `salt`,
4 de `rs`, 1 de tamanho e 65 de chave efêmera — mais 1 octeto de delimitador e 16 de etiqueta
GCM. O teto do texto claro é portanto **3993 octetos**, e é esse o número que a `PushPayload`
aplica. Truncar em 4096 gera corpos de até 4199 octetos e devolve `413` do serviço de push.

### Verificação

A RFC 8291, seção 5, publica vetor de teste com entrada e saída conhecidas. A `WebPushCrypto` aceita `salt` e chave efêmera injetados exatamente para que o teste
reproduza o vetor byte a byte. A implementação passa ou não passa — não fica no julgamento de
quem revisa.

## Fluxo de uma mensagem

1. A Meta entrega o webhook.
2. O Meta Bundle valida, deduplica e persiste a `MetaMessage`.
3. `MetaInboxIntegration::persistInbound()` cria ou atualiza a `ConversationState`.
4. A intenção de notificar é registrada em memória. Nenhuma rede, nenhuma criptografia.
5. Depois do `kernel.terminate`, `PushAudience` resolve os destinatários.
6. `PushPayload` e `PushDispatcher` enviam para cada aparelho.
7. O service worker exibe, ou suprime se a conversa já estiver visível.

## Bordas e falhas

- Só `inbound` notifica. A resposta do próprio atendente nunca volta como notificação.
- `clients.matchAll()` suprime a notificação quando existe janela visível naquela conversa.
- A `tag` com o ID da conversa faz a mensagem nova substituir a anterior, em vez de empilhar.
- `201` e `202` são sucesso e zeram o contador de falhas do aparelho.
- `404` e `410` apagam o aparelho: a inscrição morreu com a desinstalação.
- `413` é defeito nosso, não do aparelho. A entrega é descartada e registrada como erro —
  reenfileirar um corpo grande demais só repete a falha.
- `429` respeita o `Retry-After` e **não consome tentativa**: é instrução do serviço, não falha nossa.
- `500`, `503` e timeout reenfileiram, consumindo tentativa.
- **Reentrega:** três tentativas, em 1, 5 e 15 minutos. Esgotadas, a entrega é descartada com
  log — a mensagem continua no inbox, que é a fonte da verdade; só o aviso se perde.
- **Validade absoluta:** entrega mais velha que o próprio `TTL` é descartada, tenha ou não
  consumido as três tentativas. Sem isso, um endpoint permanentemente limitado devolveria `429`
  para sempre sem nunca esgotar tentativa, e a linha viveria na fila indefinidamente. Um aviso
  que o próprio spec considera sem valor depois de uma hora não deve sobreviver a ela.
- **Aposentadoria do aparelho:** dez falhas consecutivas sem nenhum sucesso viram `ativo = false`.
  O registro não é apagado, para que a tela de ajustes possa mostrar o que aconteceu e a pessoa
  reinscrever com um toque. **A `PushAudience` só devolve aparelhos ativos**, e a reinscrição
  reativa o registro — sem isso, um aparelho aposentado seguiria recebendo envio para sempre.
- A fila de reentrega é drenada por `mautic:inbox:wake`, que já roda a cada minuto. Nenhum cron novo.
- Sessão expirada: o Mautic guarda o destino e devolve à conversa certa depois do login.
- Permissão negada é definitiva; a tela de ajustes mostra o estado real e não insiste.
- Sem chaves VAPID, o push fica desligado em silêncio e os ajustes mostram o comando a rodar.
- Usuário desativado ou removido tem os aparelhos apagados. Sair do app remove a inscrição daquele aparelho.
- Todo o caminho de push roda em bloco que captura qualquer exceção, com canal de log próprio.
  Push quebrado não pode impedir a gravação da mensagem nem atrasar o `200` para a Meta.

## Segurança e privacidade

- Inscrição exige sessão, permissão de inbox e CSRF. Um usuário não apaga aparelho de outro.
- A chave VAPID privada é guardada criptografada com o mesmo helper que o Meta Bundle usa
  para as credenciais da Meta, e entra na rotina de backup. Perdê-la invalida todas as
  inscrições existentes.
- O manifest e o service worker são públicos de propósito, para que a revalidação em segundo
  plano não esbarre em redirecionamento de login. Nenhum dos dois expõe dado algum.
- **A instância está atrás da Cloudflare.** O `/inbox-sw.js` precisa responder com
  `Cache-Control: no-cache` explícito. Um service worker retido na borda é a falha clássica
  desta arquitetura: o navegador pede a versão nova, a Cloudflare devolve a antiga, e a equipe
  fica presa numa versão que ninguém consegue atualizar — sem erro visível em lugar nenhum.
  O mesmo vale para o manifest. O bundle versionado por query string pode ser cacheado à vontade.
- Nome e texto do cliente trafegam cifrados; o serviço de push transporta sem conseguir ler.

## Testes

**Sem banco.** `PushAudience` nas seis situações: dono, fila sem dono, conversa alheia,
usuário sem permissão, usuário desativado, aparelho aposentado. `PushPayload` truncando no teto
de 3993 octetos sem partir emoji
nem caractere acentuado, e montando título para contato sem nome e corpo para mensagem só
com mídia. O caso de borda que trava a regressão: um texto claro de 3993 octetos passa e um de
3994 é truncado. Curva de reentrega — 1, 5, 15 e descarte — e aposentadoria na décima falha.

**`WebPushCrypto`.** Vetor de teste da RFC 8291 seção 5, byte a byte, com `salt` e chave
efêmera injetados. Conversão DER para `R||S`, incluindo `R` ou `S` com byte inicial zero, e
coordenada de 31 octetos que precisa de preenchimento à esquerda. Ida e volta do embrulho
`SubjectPublicKeyInfo`: ponto cru de 65 octetos vira PEM e volta idêntico.

**VAPID.** O ES256 não é determinístico, então não existe vetor de bytes. O portão equivalente
é a verificação de ida e volta: assinar e conferir com `openssl_verify` sobre o `R||S`
remontado, mais a asserção de que o `aud` acompanha a origem do endpoint e que `sub` fora de
`mailto:` ou `https:` é recusado antes do envio.

**`PushDispatcher` com cliente falso.** Cada resposta vira uma decisão verificável:
`201`, `202`, `404`, `410`, `413`, `429` com `Retry-After`, `500`, timeout — mais a asserção
de que o `429` não consome tentativa.

**Funcional, em banco descartável.** As rotas de inscrição sob sessão, permissão e CSRF;
isolamento entre usuários; reinscrição reativando aparelho aposentado; manifest e service worker
respondendo 200 sem sessão, com `Content-Type` correto.

**JavaScript.** Handlers do service worker num escopo falso: push válido, payload corrompido,
clique com e sem janela aberta, supressão com a conversa visível.

**Aparelho real.** `mautic:inbox:push:test --user=X` fecha o ciclo de ponta a ponta.

A regra do `AGENTS.md` vale: nada de teste com banco contra instalação implantada.

## Teste de ponta a ponta

Os testes acima provam as peças. Nenhum deles prova a cadeia: manifest válido, service worker
registrado no escopo certo, inscrição gravada, push assinado aceito pelo serviço, notificação
exibida no aparelho e toque abrindo a conversa. Essa cadeia só quebra em integração, e é onde
Web Push costuma falhar.

### O que precisa ser provado

| # | Asserção |
|---|---|
| 1 | O manifest é servido, é válido e o navegador reconhece o app como instalável |
| 2 | O service worker registra no escopo `/s/`, ativa e sobrevive a recarga |
| 3 | A inscrição grava um `PushDevice` com `p256dh` e `auth` que decifram de volta |
| 4 | Uma mensagem recebida produz notificação no aparelho, com o app fechado |
| 5 | O toque abre a conversa certa e reaproveita a janela existente |
| 6 | Com a conversa visível, a notificação é suprimida e o SSE atualiza a tela |
| 7 | Cancelar a inscrição e aposentar o aparelho interrompem a entrega |

### Como o cenário é disparado

Em instância de teste, com banco descartável — **nunca em produção**, pela regra do `AGENTS.md`.
O gatilho não depende da Meta: um `POST` no webhook com assinatura válida, montado pelo próprio
teste, percorre o caminho real de ponta a ponta. `mautic:inbox:push:test --user=X` cobre o
caminho de push isolado quando se quer separar um defeito de entrega de um defeito de fluxo.

### Superfícies

**Chrome de desktop — automatizável, é o portão de CI.** Cobre 1, 2, 3, 5, 6 e 7 e **entrega
real**, porque o Chrome de desktop usa o mesmo FCM do celular. Roda em Chrome com interface, não
em `--headless` antigo, que não registra push; a permissão de notificação é concedida por CDP
(`Browser.grantPermissions`) em vez de clique manual. É este o conjunto que precisa passar antes
de qualquer release.

**Android, emulador com imagem `google_apis_playstore` — semiautomatizável.** É a única imagem
que traz Play Services e, portanto, a única que registra no FCM; imagens `google_apis` puras ou
AOSP falham na inscrição, e a falha parece bug do código. Cobre o que o desktop não prova:
instalação de verdade pelo banner, entrega **com o Chrome fechado**, e o comportamento do
`notificationclick` no Android. Dirigível por `adb` para abrir URL, conceder permissão e ler a
sombra de notificações.

**iPhone — manual, sem alternativa.** O Simulador do iOS não implementa Web Push; não existe
caminho automatizado. O iPhone entra como checklist manual de release: instalar pela tela de
início no Safari, permitir no toque explícito, fechar o app, receber, tocar e cair na conversa.
Quatro itens, feitos à mão, uma vez por release.

### Critério de aprovação

As sete asserções verdes no Chrome de desktop e no emulador Android, e o checklist do iPhone
assinado. Falha em qualquer superfície reprova a release — inclusive o iPhone, que é metade da
equipe e a plataforma com mais restrições.

## Entrega

- HTTPS já existe e foi verificado: certificado válido da Google Trust Services para
  `on-forge.com`, o que satisfaz o contexto seguro que o service worker exige.
- O `try_files` do nginx já encaminha caminho desconhecido ao front controller, então as rotas
  públicas na raiz funcionam sem tocar na configuração do servidor. Verificado.
- O PHP-FPM que serve o site é o 8.4. O cron mistura `php` e `php8.4`; vale uniformizar antes,
  para que o worker e a web não rodem em versões diferentes.
- Release nova em `releases/`, com troca do symlink `current`.
- Bundle e service worker compilados no desenvolvimento e versionados no plugin.
- Ícones de 192px, 512px e *maskable* de verdade, senão o Android recorta sobre o conteúdo.
- Duas migrações, aplicadas por `mautic:plugins:reload` e doctrine.
- `mautic:inbox:push:setup` gera o par VAPID antes do primeiro uso.

## Ordem de implementação

O trabalho é coeso, mas grande para um plano só. A ordem que isola o risco:

1. `Ec`, `Hkdf`, `WebPushCrypto` e `VapidKeys`, contra os vetores da RFC 8291. É a parte mais
   arriscada e a única testável inteiramente sozinha: sem banco, sem rota, sem navegador e sem
   nenhuma dependência do Mautic.
2. `PushDevice`, `PushController`, **um service worker mínimo**, a inscrição na tela de ajustes
   do `/s/inbox` que já existe, o envio síncrono e o `mautic:inbox:push:test`. O service worker
   entra aqui, e não na fase 4, por uma razão dura: sem ele não existe inscrição, e sem
   inscrição nenhuma fase seguinte tem o que verificar. Portão: uma notificação de teste chega
   num navegador real. É a primeira fatia vertical que funciona de ponta a ponta.
3. `PushAudience`, `PushPayload`, o gancho no `persistInbound` com despacho em `kernel.terminate`
   e a fila de reentrega. Portão: uma mensagem recebida de verdade notifica a pessoa certa.
4. Shell instalável, navegação inferior, guia de instalação, deep link e supressão. Portão: a
   matriz de ponta a ponta nas três superfícies.

## Pendências conhecidas, anteriores a este trabalho

1. **O repositório está atrás da produção.** `main` está em 1.0.15 e não contém o diretório
   `Frontend/`. A migração para Svelte 5 (1.1.0) e o limite de respostas de IA (1.1.1) existem
   apenas no servidor. Este desenho assume os componentes Svelte de 1.1.1 como base, então o
   histórico precisa ser reconciliado antes da implementação.
2. **Versão divergente entre arquivo e banco.** Inbox 1.1.1 em disco contra 1.1.0 na tabela
   `plugins`; Meta 0.13.0 em `Config/config.php` contra 0.14.0 no `package.json` e no banco.
   Vale corrigir antes, para a release do PWA sair de base consistente.

## Follow-ups

- Preferências por usuário: canais, horário de silêncio, desligar a fila. A `PushAudience`
  foi isolada exatamente para isso.
- Notificação de transferência, de agente de IA pausado e de soneca encerrada.
- Contador de não lidas no ícone.
- Reavaliar o modo offline depois de uso real, com a ressalva de que resposta atrasada em
  atendimento pode furar a janela de 24 horas do WhatsApp.
