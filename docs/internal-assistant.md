# Assistentes internos e agentes de atendimento

A tela **Agentes** permite escolher o uso: **Atendimento ao cliente** ou **Assistente interno**. Agentes antigos, sem `audience`, continuam sendo de atendimento. O tipo interno não pode ser atribuído a clientes, nem automaticamente nem pelo operador; o worker também confere isso antes de entregar uma resposta.

## Configurar um assistente interno

1. Em Agentes, crie um agente e selecione Assistente interno.
2. Selecione os documentos publicados que devem compor seu contexto. Documentos globais continuam compartilhados entre agentes; instruções internas devem ficar em documentos específicos deste agente.
3. A conexão MCP é o Mautic da conexão autenticada no aplicativo. O servidor reutiliza os adaptadores MCP locais, sem credencial administrativa compartilhada ou uma URL MCP externa.
4. Selecione as ferramentas de consulta e, opcionalmente, os perfis de usuário autorizados. Nenhum perfil selecionado significa todos os usuários autenticados que já têm acesso ao Inbox. Nenhuma ferramenta selecionada significa nenhuma consulta, nunca acesso irrestrito.
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

Todos esses adaptadores são de leitura. Enviar respostas, ocultar comentários ou marcar spam pelo assistente ainda exige um fluxo separado de ferramentas de execução e confirmação; não está habilitado por estas configurações. O agente de atendimento mantém seu fluxo existente de resposta nos canais autorizados.

## Instalação e validação

Atualizar o plugin e copiar `Runtime/mobile-assistant.mjs` para o diretório privado utilizado pelo `PiClient`, depois limpar/recompilar o cache normal do Mautic. O servidor deve ser atualizado antes de distribuir o novo aplicativo. Os campos ficam no registro JSON existente do agente; não há migração nem mudança de schema.

O teste `Tests/Standalone/internal-agent-policy.php` carrega apenas as classes de política e exceção, sem kernel, banco ou rede. O teste do frontend compilado verifica seleção de tipo, ferramentas, perfis e persistência autenticada. Testes do app verificam validação da resposta da API, restauração somente de agentes autorizados e separação do histórico. Esses testes não comprovam implantação ou funcionamento no servidor de produção.
