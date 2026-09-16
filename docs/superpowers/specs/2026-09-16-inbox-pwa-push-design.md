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
| Aba em foco | Suprimir a notificação | O SSE já atualizou a tela |

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
| `Entity/PushDevice` | Um registro por aparelho: usuário, endpoint, `p256dh`, `auth`, user agent, última entrega, falhas |
| `Entity/PushDelivery` | Fila de reentrega: aparelho, payload, tentativas, próxima tentativa |
| `Application/Push/PushAudience` | A regra de quem recebe, isolada e testável sem banco |
| `Application/Push/PushPayload` | Monta e trunca o conteúdo dentro do limite de 4 KB |
| `Application/Push/PushDispatcher` | Envia, classifica o erro, decide entre apagar e reenfileirar |
| `Application/Push/WebPushCrypto` | VAPID e RFC 8291 sobre `ext-openssl` |
| `Application/Push/VapidKeys` | Lê e grava o par, com a privada criptografada |
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

Duas camadas independentes, ambas sobre `ext-openssl`, `gmp` e `bcmath`, já presentes
no servidor.

### VAPID (RFC 8292) — identidade do servidor

JWT ES256 com `aud` igual à origem do endpoint, `exp` de 12 horas e `sub` configurável.
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
9. Corpo: `salt(16) || rs(4, big-endian) || tamanho_da_chave(1) || chave_efêmera(65) || cifra`.

Cabeçalhos: `Content-Encoding: aes128gcm`, `Content-Type: application/octet-stream`,
`TTL`, `Urgency`.

**Verificação.** A RFC 8291, seção 5, publica vetor de teste com entrada e saída
conhecidas. `WebPushCrypto` aceita `salt` e chave efêmera injetados exatamente para que
o teste reproduza o vetor byte a byte. A implementação passa ou não passa — não fica no
julgamento de quem revisa.

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
- `404` e `410` apagam o aparelho. `429` respeita o `Retry-After`. `500` e timeout reenfileiram.
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
- Nome e texto do cliente trafegam cifrados; o serviço de push transporta sem conseguir ler.

## Testes

**Sem banco.** `PushAudience` nas cinco situações: dono, fila sem dono, conversa alheia,
usuário sem permissão, usuário desativado. `PushPayload` truncando em 4 KB sem partir emoji
nem caractere acentuado, e montando título para contato sem nome e corpo para mensagem só
com mídia. Backoff da fila.

**`WebPushCrypto`.** Vetor de teste da RFC 8291 seção 5, byte a byte. Conversão DER para
`R||S`, incluindo os casos de `R` ou `S` com byte inicial zero.

**`PushDispatcher` com cliente falso.** Cada resposta vira uma decisão verificável:
`201`, `404`, `410`, `429` com `Retry-After`, `500`, timeout.

**Funcional, em banco descartável.** As rotas de inscrição sob sessão, permissão e CSRF;
isolamento entre usuários; manifest e service worker respondendo 200 sem sessão, com
`Content-Type` correto.

**JavaScript.** Handlers do service worker num escopo falso: push válido, payload corrompido,
clique com e sem janela aberta, supressão com a conversa visível.

**Aparelho real.** `mautic:inbox:push:test --user=X` fecha o ciclo de ponta a ponta.

A regra do `AGENTS.md` vale: nada de teste com banco contra instalação implantada.

## Entrega

- HTTPS já existe, requisito absoluto para service worker.
- Release nova em `releases/`, com troca do symlink `current`.
- Bundle e service worker compilados no desenvolvimento e versionados no plugin.
- Ícones de 192px, 512px e *maskable* de verdade, senão o Android recorta sobre o conteúdo.
- Duas migrações, aplicadas por `mautic:plugins:reload` e doctrine.
- `mautic:inbox:push:setup` gera o par VAPID antes do primeiro uso.

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
