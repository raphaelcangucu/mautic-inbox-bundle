# Automação de comentários no Inbox

## Configuração

Disponível em `/s/inbox/ai`, aba **Automação**, somente para administradores. Reutiliza documentos publicados, Pi, contas Meta, moderação local e fila atuais. Persiste em `AiRecord`: não há nova tabela ou migração. O comando existente `mautic:inbox:ai:work` processa também esta pipeline, com bloqueio independente e até dez comentários por execução.

**Padrão desligado.** A instalação desta versão e a publicação do documento não ativam moderação nem respostas. A regra é selecionada especificamente para a automação; não altera o contexto do atendimento normal do agente.

| Controle | Padrão | Comportamento |
| --- | --- | --- |
| Modo | Desligado | Desligado, revisão humana, automático |
| Contas | Nenhuma | Instagram e Facebook autorizados globalmente e pelo agente |
| Publicações | Todas as novas nas contas selecionadas | IDs opcionais; histórico anterior à ativação não é processado |
| Documento | Nenhum | Versão publicada exata da [regra](ai/automacao-comentarios.md) |
| Marcar spam | Desligado | Marcação local do comentário no Inbox |
| Resposta pública | Desligado | Ação independente de marcar spam |
| Classificações | 100/hora | Orçamento por configuração/agente |
| Respostas | 20/hora | Orçamento por configuração/agente |
| Intervalo | 30 segundos | Intervalo mínimo entre respostas da configuração |
| Avisos | Habilitados | Revisões e pausas nesta tela; não há push novo nesta versão |

Ativar exige agente de atendimento habilitado, permissão de comentários, contas selecionadas, regra publicada e Pi validado. Automático exige uma ação ligada. Uma conta não pode pertencer a duas automações ativas. Salvamento com revisão otimista, CSRF e bloqueio administrativo. Limites inválidos e IDs malformados são rejeitados.

## Executor e segurança

1. Seleciona comentários novos pelo cursor do servidor e resolve conta, conversa e comentário exatos.
2. Ignora atendimento humano, comentários da própria conta, já respondidos, apagados, spam, autor bloqueado, conversa encerrada e atribuição de IA em andamento.
3. Busca a legenda da publicação correta e os documentos publicados autorizados. O Pi recebe somente esse contexto e o texto público; não recebe CRM, contato, notas ou histórico privado. Não há ferramentas disponíveis para este classificador.
4. Valida JSON estruturado fora do modelo. Spam exige confiança mínima de 90% e evidência literal do comentário. Resposta exige 75%, contexto de publicação, até 900 caracteres e validação de dados sensíveis e links autorizados. Reclamação e menção isolada não são spam por si só.
5. Revisão humana grava uma proposta; o administrador pode aprovar a decisão validada original, rejeitar ou abrir a conversa. Edição da proposta não está disponível nesta versão. A ação correspondente precisa estar habilitada mesmo na aprovação.
6. Automático marca spam localmente ou enfileira resposta pública ao comentário exato. Instagram utiliza `instagram_public_reply`; Facebook utiliza `facebook_public_reply`. Não há fallback para mensagem privada.
7. Revalida configuração, permissões, versão dos documentos, comentário, estado da conversa e disponibilidade antes da ação e novamente na entrega da fila.
8. Atualiza a mesma execução, com auditoria, sem reenvio automático. Chave idempotente por conta e comentário, independente da versão da regra. Fila aceita não equivale a envio confirmado.

A marcação local não denuncia, oculta, exclui nem bloqueia o autor na Meta. A auditoria preserva a identidade do comentário e a versão da regra. A restauração continua disponível pelos controles de moderação existentes do Inbox.

Ao desligar, novas classificações param e trabalhos pendentes são impedidos pelo guard na entrega. Não desfaz envio já confirmado. Falha da IA ou resultado remoto incerto pausa a configuração; não repete um envio cuja confirmação se perdeu. Retirada de permissão, documento atualizado, edição do comentário ou tomada humana cancela o trabalho incompatível. Falta de disponibilidade ou limite leva a revisão sem tentar enviar.

Registros `comment_config`, `comment_run` e `comment_budget` ficam no armazenamento atual. Histórico da interface paginado em 25 execuções, com comentário de origem, decisão, motivo, proposta e status. Avisos são da tela administrativa, sem APNs adicional. Não há varredura retroativa nem ferramentas de campanha ou alteração de contatos.

## API administrativa

- Ação `comment_config`: `key` é a chave do agente; `revision`, `mode`, `asset_ids`, `publication_ids`, `document_key`, `document_version`, flags das ações e avisos, `classify_limit`, `reply_limit`, `min_interval`. Cursor e autor são definidos pelo servidor.
- Ação `comment_review`: `run_key`, `revision`, `decision` (`approve` ou `reject`).
- Ação `comment_runs`: `page`, resposta `items`, `page`, `hasMore`.
- MCP: `mautic_read_inbox_ai` com `comment_configs` / `comment_runs`; `mautic_manage_inbox_ai` com as ações acima, confirmação e idempotência do mecanismo existente.

## Validação antes da ativação

Testes standalone não iniciam kernel, conexão ou banco: política/executor, adaptadores de produção com persistência em memória, arquivos de moderação descartáveis e entrega remota simulada. Cobrem desligado, revisão, spam exato, resposta pública IG/FB, aprovação, deduplicação, limites, perda de confirmação, desligar durante geração, mudanças de autorização/contexto e cancelamento da fila.

Testes Svelte/Node verificam controles, flags independentes, CSRF, proposta/revisão e traduções; build e checagem de tipos verificam o frontend. Classificador Pi real validado separadamente com propaganda, dúvida, reclamação e tentativa de extrair segredos. Esses testes de IA não moderam comentários nem publicam na Meta.

Envio externo real e moderação no Inbox de um comentário de teste dependem de uma publicação de teste indicada pelo operador. Até esse ensaio e sua aprovação, manter o modo desligado. Nunca executar PHPUnit, fixtures ou testes de banco na produção; testes de persistência em banco exigem uma cópia comprovadamente descartável conforme AGENTS.md.

### Resultado desta entrega (2026-10-11)

- 63 verificações da política/executor e 24 dos adaptadores reais, sem banco nem rede.
- 94 testes JavaScript/TypeScript, build e checagem Svelte aprovados.
- Pi/CMS reais validados; quatro casos do classificador real aprovados.
- Documento `automacao-comentarios-v1`, versão publicada 1, selecionado no agente `macro` apenas para automação.
- Configuração de produção salva como desligada, sem contas selecionadas e com spam/respostas desabilitados.
- Ensaio externo na Meta ainda depende da publicação de teste escolhida pelo operador; não houve ativação nem moderação em massa.
