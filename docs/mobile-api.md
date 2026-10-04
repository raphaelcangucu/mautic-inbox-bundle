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

Busca de campanhas retorna uma página de 10 itens. Como o normalizador MCP original omite estado publicado, a ponte enriquece IDs reais com `is_published` e atividade efetiva no Mautic. `publish_up` nulo não prova rascunho. O runtime foi testado com dados reais; o endpoint autenticado completo aguarda consentimento do aparelho.

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

WebChat de teste state232/contact3644: inbound317 recebido, outbound33 exibido no navegador e confirmado `read`. Repetição do mesmo request_id manteve 1 item. Ações foram realizadas pelos serviços reais via MCP; envio autenticado **pelo app** ainda depende de consentimento. WhatsApp/Instagram/Facebook precisam de destino próprio de teste e capacidade/janela válidas; não foram enviados a contatos de clientes.

## Instalar/revisar esta extensão

Base snapshot do Inbox 1.3 em commit70e2364. Esta extensão precisa do Mautic7, MetaBundle e WebChatBundle compatíveis com a instalação existente, MCPBundle0.17 e Pi já configurado no servidor para o assistente. Não é uma API standalone genérica para qualquer instalação Mautic.

Arquivos/rotas PHP ficam no plugin; `Runtime/mobile-assistant.mjs` deve ir ao diretório configurado no PiClient. Não copie `auth.json` ou bearer do servidor para o app. O diretório `var/inbox-mobile` deve apontar para storage persistente protegido do servidor, fora do webroot público, com permissões0700/arquivos0600. Nenhum schema foi adicionado.

Preserve backup dos arquivos/cache anteriores e use o processo normal de deploy/PHP-FPM para carregar os novos serviços. Não use instalação/teste como justificativa para executar fixtures ou testes destrutivos no banco de produção.
