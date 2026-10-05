# API Mautic Inbox mobile · v1 / app 0.3.0

## Implantação e autenticação

Extensão do `MauticInboxBundle` implantada em `https://mkt-macro.on-forge.com`. Usa o Inbox 1.3 existente e a API de canais já configurada. Não houve migração/schema nem teste de banco de dados de produção.

| Rota | Finalidade |
|---|---|
| GET `/inbox/mobile/config` | Descoberta: origem, endpoints e capacidades |
| GET/POST `/s/inbox/mobile/authorize` | Login normal + consentimento com CSRF, código vinculado a PKCE S256 |
| POST `/inbox/mobile/device` | Código para conectar pelo navegador autenticado |
| GET/POST `/s/inbox/mobile/device-authorize` | Confirmação explícita do código e usuário |
| POST `/inbox/mobile/token` | Troca de código, device grant e renovação |
| GET `/inbox/mobile/api/me` | Identidade real; acesso condicionado às permissões |
| DELETE `/inbox/mobile/api/session` | Revogação da sessão do aparelho |

Redirect permitido: `mautic-inbox-demo://oauth/callback`. Access token opaco de 1h; refresh token de 30 dias, rotacionado. Código PKCE dura 90s; device code dura 10min. A senha do usuário e o bearer MCP não são enviados ao aplicativo. Grant ligado ao usuário ativo e ao fingerprint da senha; alteração de senha invalida a sessão. O arquivo do servidor guarda hashes dos tokens, protegido por permissão 0600 e lock; não usa tabelas novas.

O app aceita HTTPS e endpoints na mesma origem da instância. SecureStore guarda a sessão. O backend aplica as permissões normais do operador, sem substituir o operador pelo administrador.

## Gateway autenticado

Todas as rotas abaixo começam em `/inbox/mobile/api/`. Header: `Authorization: Bearer <sessão-do-aparelho>`. Nenhum token real está incluído nos artefatos.

| Método / recurso | Serviço reaproveitado e comportamento |
|---|---|
| GET `conversations?kind=private|comments&queue=all&lifecycle=all&limit=50&cursor=…` | InboxQuery, paginação existente; resumo enriquecido com IA/moderação |
| GET `conversations/{id}` | Contexto real e capacidades do operador |
| GET `conversations/{id}/history?limit=40&before=…` | Timeline por identidade `kind:id`, recibos reais |
| GET `updates?since=…&state_id=…` | Poll legado; o chat mobile atualiza o histórico para captar recibos alterados |
| POST `conversations/{id}/reply` | `body`, `request_id`, template/variáveis ou `reply_mode`; exige responsável/permissão e janela do canal |
| POST `conversations/{id}/take` | `version`; assumir e pausar IA |
| POST `conversations/{id}/state` | `version`, `action`: read/resolve/reopen/transfer/unassign/snooze; alvo/prazo conforme ação |
| POST `conversations/{id}/note` | Nota interna; não há retry automático |
| PUT `conversations/{id}/draft` | Rascunho remoto existente; app prioriza rascunho local |
| GET `conversations/{id}/templates` | Templates WhatsApp reais e indicação de suporte |
| GET `conversations/{id}/email-options` | Contato, correspondências, campanhas/segmentos e permissão |
| POST `conversations/{id}/email-actions` | Revisão de vínculo e alterações; IDs reais escolhidos no app |
| GET/POST `conversations/{id}/ai` | Agentes existentes; assign/reset respeitando capacidades |
| GET `operator-options` | Usuários ativos e catálogo real de agentes |
| GET/POST `canned-responses` | Respostas prontas persistidas; edição exige permissão |
| GET `notifications?cursor=…` | Eventos reais com state_id e flags de supressão |
| GET `media/{id}` | Proxy privado existente sob identidade do operador |
| POST `conversations/{id}/moderation` | Spam/restore/block/unblock, versionamento e registro do ator |
| POST `assistant/messages` | Pi e MCP de leitura sob permissões do operador |

Rejeições mantêm status HTTP de autenticação/permissão/conflito/validação. O app não converte erro de rede em sucesso. `202` indica aceitação; `pending/sent/delivered/read` vêm do histórico. Um `request_id` estável permite reconciliar resultados incertos. Foi corrigido o replay sequencial no transporte externo para não redisparar um outbound já processado. Concorrência simultânea de dois clientes com o mesmo ID ainda exige teste específico.

Comentários conservam o tipo mesmo quando o `recipient` público é o participante. Facebook: público; Instagram: privado, conforme implementação existente. Templates não abrem a janela de texto livre até nova resposta do usuário. Upload é recusado com capacidade explicitamente indisponível.

## Assistente Pi + MCP

Runtime separado `mobile-assistant.mjs` usa o Pi já instalado no servidor. Até 3 ferramentas por pergunta, apenas `mautic_search_campaigns`, `mautic_search_contacts`, `mautic_fetch_campaign`, `mautic_fetch_contact` e contexto do Inbox. Sem shell, ferramentas de escrita ou envio automático. Timeout e lock limitam concorrência. Tokens/realtime/segredos são retirados antes do modelo.

Busca de campanhas retorna uma página de 10 itens. Como o normalizador MCP original omite estado publicado, a ponte enriquece IDs reais com `is_published` e atividade efetiva no Mautic. `publish_up` nulo não prova rascunho. Em 05/10/2026, a ponte HTTP autenticada foi validada pelo app iOS: “Quais campanhas estão ativas?” consultou `mautic_search_campaigns` e respondeu com 7 campanhas ativas na página de 10/21, declarando que o total de ativas não foi determinado.

## O que falta implementar

| Capacidade | Estado / trabalho restante |
|---|---|
| Upload, áudio/fotos/PDF outbound | Implementar armazenamento, autorização, limites e envio pelo transporte de cada canal; `media_upload=false` |
| Push APNs/FCM | Registro/remoção por aparelho e usuário, fila, recibos, preferências/horários no servidor; `push_remote=false` |
| Ocultar/excluir comentário ou banir perfil social | Permissões e transporte específicos Meta; não oferecido como ação real |
| Moderação global/IA inbound | Propagar a política mobile aos demais consumidores; hoje só fila/reply/notificações mobile |
| Filas grandes e deltas completos | Paginação contínua, contadores globais, tombstones e sincronização consistente de permissões |
| Retenção de cache e mídia | Orçamento/LRU/TTL, criptografia de arquivo e revogação de acesso já cacheado |
| Retry outbound do canal | UX e API explicitamente idempotentes, incluindo concorrência e falhas de transporte |
| Assistente streaming | Opcional; hoje resposta única e leitura |

## Validação real

WebChat de teste state232: a validação anterior via MCP confirmou inbound317/outbound33 e replay sequencial sem duplicata. Em 05/10/2026, o app iOS autenticado por código de e-mail enviou outbound40, exibido ao visitante no navegador e confirmado `read`; a resposta inbound335 apareceu no app. Um segundo envio outbound41 também chegou e ficou `read`, com o campo de resposta acima do teclado do iOS. A conversa técnica foi transferida pelo painel existente do Mautic Admin para o usuário 8 antes do envio; as tentativas do app sem responsabilidade foram recusadas. Na consulta atual, o contato vinculado é 3673; o vínculo anterior era 3644. Nenhum envio foi feito a contatos de clientes. WhatsApp/Instagram/Facebook precisam de destino próprio de teste e capacidade/janela válidas; não houve envio real nesses canais.

## Instalar/revisar esta extensão

Base snapshot do Inbox 1.3 em commit70e2364. Esta extensão precisa do Mautic7, MetaBundle e WebChatBundle compatíveis com a instalação existente, MCPBundle0.17 e Pi já configurado no servidor para o assistente. Não é uma API standalone genérica para qualquer instalação Mautic.

Arquivos/rotas PHP ficam no plugin; `Runtime/mobile-assistant.mjs` deve ir ao diretório configurado no PiClient. Não copie `auth.json` ou bearer do servidor para o app. O diretório `var/inbox-mobile` deve apontar para storage persistente protegido do servidor, fora do webroot público, com permissões0700/arquivos0600. Nenhum schema foi adicionado.

Preserve backup dos arquivos/cache anteriores e use o processo normal de deploy/PHP-FPM para carregar os novos serviços. Não use instalação/teste como justificativa para executar fixtures ou testes destrutivos no banco de produção.

## Login por código de e-mail (0.3.1)

GET /inbox/mobile/config anuncia magic_code_login e magic_code_endpoint. POST /inbox/mobile/magic-code recebe email/code_challenge S256 e retorna request_id secreto, expires_in=300/resend_after=60, nunca o código. MagicCodeMailer envia para um único operador ativo encontrado pelo e-mail, com nome da instância e aviso de sessão 30 dias. POST /inbox/mobile/token com grant_type=email_code, request_id, code, code_verifier troca o código por sessão normal. Verifica operador ativo, fingerprint de senha e e-mail atual. Código é de uso único, 5 tentativas; reenvio no mesmo PKCE invalida o anterior. Não cria contato/usuário. Resposta genérica também para e-mail desconhecido/inativo/ambíguo e falha de mailer. Limites 5/h e-mail, 20/h IP, 1000/h global. Solicitações/limites e hashes protegidos no mesmo storage privado com flock. HMAC do código usa o segredo de solicitação não persistido; PKCE e secreto ficam em memória no app. Sem esquema ou testes no banco de produção.

28 contratos do app e testes PHP puros passaram. Descoberta e respostas públicas foram verificadas na instância implantada. Usuário ID 8 criado pelo operador e conferido ativo (Raphael Cangucu / raphael.cangucu / me@raphaelcangucu.com / Administrator). O SMTP global recusou inicialmente o envio por quota. Em 05/10/2026, depois de autorização explícita, o app iOS concluiu o login real por código de e-mail com esse usuário e carregou as conversas existentes.

## SMTP temporário do login mobile

Por padrão, MagicCodeMailer usa o MailHelper normal. Para os testes autorizados de 05/10/2026, um override exclusivo do login usa support@macro.markets em smtp.dreamhost.com:587, com STARTTLS obrigatório e validação de certificado. As campanhas e o SMTP global não foram alterados.

O override é lido em tempo de execução de `dirname(realpath(kernel.project_dir))/inbox-mobile-private/mailer.json`. A pasta precisa ser privada (0700), o arquivo 0600, sem symlinks. Campos: host, port (587), username, password, from_email (igual a username), from_name e expires_at (Unix timestamp inteiro). Não incluir credenciais no Git, artefatos, logs ou container compilado. Configuração existente inválida/expirada falha sem recorrer ao SMTP global; remover o override restaura o MailHelper. Na instalação atual, o arquivo fica fora do document root, em `/home/forge/mkt-macro.on-forge.com/releases/inbox-mobile-private/mailer.json`, e expira em 06/10/2026 às 02:59:53 UTC (05/10 às 23:59:53 em São Paulo). Ao trocar a senha na DreamHost, atualizar esse arquivo privado ou remover o override conforme o destino aprovado; a expiração impede uso, mas não apaga o arquivo.

Autenticação e envio reais das contas reports@macro.markets e support@macro.markets foram aceitos desde o servidor do Mautic, e ambos os e-mails chegaram a me@raphaelcangucu.com. O endpoint de código retornou 202/300 segundos, sem expor o código, e a mensagem chegou pelo remetente support. A solicitação do próprio app foi usada no login; o desafio da sondagem HTTP não foi trocado por uma sessão.

`Tests/Mobile/temporary-smtp.php` é um teste PHP puro, sem kernel, banco ou rede: verifica permissões, expiração, identidade do remetente, porta TLS, JSON inválido, caracteres de controle e recusa de symlinks. Os testes puros de sessão/PKCE e a sintaxe dos arquivos também passaram. Backup verificado do controller/cache anterior foi preservado antes do deploy. Nenhum teste de banco, migração ou alteração de schema foi executado em produção.

## Perfil temporário Resend — 05/10/2026

Após autorização para salvar a chave temporária do Resend, a configuração do login passou a aceitar dois perfis privados: DreamHost (`mailer.json`, formato anterior preservado) e Resend (`resend.json`). O arquivo opcional `mailer-selection.json` contém `login` (`dreamhost` ou `resend`) e `expires_at` (Unix timestamp inteiro). Sem seletor, o comportamento anterior permanece; com seletor explícito, perfil ausente/inválido/expirado falha sem mudar automaticamente para outro provedor. Todos os arquivos ficam em `inbox-mobile-private`, fora do webroot, com permissão 0600/pasta 0700 e sem symlinks.

O perfil Resend contém `provider=resend`, `host=smtp.resend.com`, `port=587`, `username=resend`, `password` (chave privada fornecida), `from_email=support@updates.macro.markets`, `from_name`, `reply_to=support@macro.markets` e `expires_at`. Usa STARTTLS obrigatório e validação de certificado, conforme o transporte SMTP oficial do Resend; a API key nunca vai para o app. O domínio updates.macro.markets já foi verificado no Resend/São Paulo. O perfil e a seleção expiram em 06/10/2026 às 03:17:19 UTC (06/10 às 00:17:19 em São Paulo). A expiração bloqueia o uso nesta integração, mas não revoga a chave no provedor nem apaga os arquivos: ao concluir, o operador deve trocar/revogar a chave no Resend e atualizar/remover o perfil privado conforme o destino aprovado.

Validação desde o servidor do Mautic: API Resend retornou 200 e email_id `01a10a11-7a29-7ecc-a62a-e76045f77eac`, com reports@updates.macro.markets/Reply-To reports@macro.markets. SMTP autenticou e aceitou um teste com support@updates.macro.markets/Reply-To support@macro.markets, em TLS1.3. Ambos chegaram à caixa me@raphaelcangucu.com. Envio limitado a um destinatário de teste, com chaves de idempotência estáveis nos dois protocolos. O endpoint real de código do Mautic retornou 202/300 segundos sem retornar o código; a mensagem foi recebida na caixa de entrada às 00:21 de 05/10, pelo remetente support@updates.macro.markets. Nenhuma nova sessão foi criada pela sondagem; a sessão iOS validada anteriormente permanece com seu usuário.

O SMTP global de campanhas permanece intacto. Esta extensão implementa a seleção privada do transporte do login mobile; ainda não é o plugin completo de painel/cadastro de múltiplas contas nem a seleção por e-mail/campanha. Para o envio normal do Mautic via Resend, o mesmo transporte SMTP é compatível com host smtp.resend.com/porta587/usuário resend/API key, usando remetente no domínio verificado; a troca do transporte global deve preservar filas, rastreamento, remetentes e retornos de bounce. A chave não foi inserida no painel público, documentação, Git, logs ou pacotes.

Os testes PHP puros passaram para seleção dos dois perfis, compatibilidade do formato anterior, porta TLS, username especial do Resend, chave malformada, Reply-To inválido, expiração, arquivos privados, symlinks e ausência de fallback silencioso. Sintaxe e diff também passaram. Deploy dos dois serviços foi precedido de backup verificado; nenhuma migração, teste de banco ou schema foi executado em produção.
