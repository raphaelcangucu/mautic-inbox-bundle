# Assistentes internos e agentes de atendimento

A tela **Agentes** permite escolher o uso: **Atendimento ao cliente** ou **Assistente interno**. Agentes antigos, sem `audience`, continuam sendo de atendimento. O tipo interno não pode ser atribuído a clientes, nem automaticamente nem pelo operador; o worker também confere isso antes de entregar uma resposta.

## Configurar um assistente interno

1. Em Agentes, crie um agente e selecione Assistente interno.
2. Selecione os documentos publicados que devem compor seu contexto. Documentos globais continuam compartilhados entre agentes; instruções internas devem ficar em documentos específicos deste agente.
3. A conexão MCP é o Mautic da conexão autenticada no aplicativo. O servidor reutiliza os adaptadores MCP locais, sem credencial administrativa compartilhada ou uma URL MCP externa.
4. Selecione as ferramentas de consulta e execução e, opcionalmente, os perfis de usuário autorizados. Nenhum perfil selecionado significa todos os usuários autenticados que já têm acesso ao Inbox. Nenhuma ferramenta selecionada significa nenhuma consulta, nunca acesso irrestrito.
5. Habilite e salve o agente. No aplicativo, selecione o assistente desejado quando houver mais de um disponível.

A lista de ferramentas é um teto: **ferramentas configuradas ∩ permissões atuais do usuário**. Os próprios adaptadores MCP validam acesso a cada campanha, contato e conversa. Campanhas e contatos respeitam `viewown`/`viewother`; conversas respeitam o escopo próprio/em espera/todas. Ser Administrator não contorna uma ferramenta desmarcada ou um agente desativado.

## API e compatibilidade

- `GET /inbox/mobile/api/assistant/agents`: metadados dos assistentes internos habilitados e autorizados, com ferramentas efetivas. Não retorna documentos nem credenciais.
- `POST /inbox/mobile/api/assistant/messages`: aceita `agent_key`; revalida agente, perfil e ferramentas no servidor em cada execução.
- Clientes antigos sem `agent_key` funcionam quando há exatamente uma opção autorizada. Com várias opções, precisam atualizar e selecionar um agente.
- Sem nenhum agente interno configurado, a consulta antiga continua funcionando. Existindo algum agente interno, mesmo desativado, não há fallback para o assistente antigo.
- O histórico local do aplicativo fica separado por conexão e agente. A autorização de compartilhamento com o provedor continua sendo necessária.

## Ferramentas desta versão

Busca/detalhe de campanhas e contatos, contexto de conversa e `mautic_read_inbox`, com consultas paginadas de conversas e comentários. O modelo recebe somente a lista efetiva; uma ferramenta inventada ou não autorizada não é executada. Consultas de comentários devem informar a limitação da página, sem alegar varredura completa.

As consultas são de leitura; as ações geram propostas, sem executar durante a pergunta. A versão 1.0.3 acrescenta:

- `campaign_report`: criação e atividade do dia em America/Sao_Paulo, com limites UTC e cobertura da página. Canais são classificados pelos tipos dos eventos do fluxo. Métricas de atividade do adaptador legado global são usadas apenas com `viewother`; um usuário limitado a `viewown` não recebe agregados globais.
- `mautic_read_campaign_flow`: fluxo real de uma campanha permitida.
- `campaign_comments`: conta contextos imutáveis de comentários recebidos, pelo par exato conta/publicação configurado na decisão Instagram. Respeita o escopo do Inbox. É contagem de comentários registrados nas publicações, não de conversões nem de correspondências à palavra-chave. Comentários sem contexto histórico não entram; campanhas com o mesmo post podem compartilhar contagens.
- `mautic_reply_inbox`: proposta individual com corpo exato e modo público/privado. A tela mostra se precisará assumir um atendimento não atribuído. Nunca transfere silenciosamente uma conversa de outro operador.
- `campaign_update`: nome, descrição e permitir reinício; não publica campanhas nem substitui seus eventos.
- `campaign_add_contacts`: até dez IDs de contatos explicitamente identificados, com leitura/edição de contato e campanha. Mostra o risco de iniciar automações numa campanha publicada. Releitura verifica o vínculo após executar.
- `inbox_transfer`: transfere um atendimento próprio para operador elegível, sem administrar contas ou papéis do Mautic.

`POST /inbox/mobile/api/assistant/actions/confirm` aceita apenas `proposal_id`, `agent_key` e `confirm: true`. A proposta fica no diretório privado fora da raiz pública, com arquivo 0600, ator, agente, alvo e revisão, e expira em dez minutos. O cliente não define uma ação executável. Papéis, ferramentas, acesso ao alvo e revisão são revalidados. Confirmações simultâneas ficam sob lock; uma confirmação concluída é reapresentada sem repetir o efeito. Resultado incerto/falho exige conferir o atendimento, sem reenvio automático. O envio inclui uma revisão verificada sob o lock do domínio. O identificador de requisição é estável.

`GET /inbox/mobile/api/assistant/actions/status` consulta somente o resultado de uma ação já executada, vinculada ao mesmo usuário e agente, com permissões atuais. Não executa propostas pendentes. Para respostas, relê o pedido de envio para mostrar falhas ocorridas na fila; o aplicativo oferece “Atualizar status do envio”.

`read_only: true` nos metadados mantém a compatibilidade de clientes antigos: a pergunta continua sem efeito de escrita. `confirmation_required` indica ferramentas de execução disponíveis em clientes novos. O app mostra cada proposta com alvo, canal, corpo e antes/depois. Status pending/sent não é comprovante de recebimento no dispositivo.

Ocultar/marcar spam pelo assistente, substituição integral de fluxo e administração global de usuários não fazem parte desta lista. A moderação manual e o editor de campanhas existentes permanecem disponíveis. O agente de atendimento mantém seu fluxo existente de resposta nos canais autorizados.

## Instalação e validação

Atualizar o plugin e copiar `Runtime/mobile-assistant.mjs` para o diretório privado utilizado pelo `PiClient`, depois limpar/recompilar o cache normal do Mautic. O servidor deve ser atualizado antes de distribuir o novo aplicativo. Os campos ficam no registro JSON existente do agente; não há migração nem mudança de schema.

O teste `Tests/Standalone/internal-agent-policy.php` carrega apenas as classes de política e exceção, sem kernel, banco ou rede. O teste do frontend compilado verifica seleção de tipo, ferramentas, perfis e persistência autenticada. Testes do app verificam validação da resposta da API, restauração somente de agentes autorizados e separação do histórico. Esses testes não comprovam implantação ou funcionamento no servidor de produção.

Testes puros de propostas: `Tests/Standalone/assistant-actions.php` verifica allowlist, ator/agente, expiração, revogação, permissões compostas, replay, falha incerta e arquivos privados. Não usa kernel, banco nem rede.
