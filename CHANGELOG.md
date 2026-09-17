# Changelog

## Nao lancado

- Adiciona a fundacao criptografica do Web Push sobre ext-openssl, sem dependencia de Composer.
- Verifica a cifra de conteudo contra os vetores publicados na RFC 8291.

## 1.1.1

- Adiciona limite global e por agente para respostas de IA em cada conversa.
- Mantém `0` como padrão ilimitado e aplica o menor limite positivo entre as duas configurações.
- Envia a última resposta permitida, pausa o agente e mantém a conversa aguardando atendimento humano ao atingir a trava.
- Preserva como ilimitadas as configurações globais e os agentes antigos cujo campo de limite existia apenas como dado informativo.

## 1.1.0

- Migra o atendimento e o workspace de agentes de IA para componentes Svelte 5 com TypeScript.
- Preserva os contratos PHP, rotas, permissões, CSRF, SSE, filas, rascunhos, mídia, modelos WhatsApp, respostas prontas e ações de atendimento existentes.
- Compartilha componentes de avatar, lista, timeline, mensagens, mídia, composer, contato, configurações, documentos, permissões e agentes.
- Mantém o visual do Mautic e o ciclo de navegação AJAX com montagem e desmontagem seguras.
- Preserva o salvamento do agente pela barra superior e seu comportamento responsivo, evolução que já estava ativa em produção.
- Publica um bundle autônomo em `Assets/dist/inbox-app.js`, permitindo instalar o plugin por Composer sem Node.js em produção.
- Adiciona verificações de TypeScript/Svelte e testes do bundle compilado, traduções, Markdown, histórico, notificações, CSRF e persistência de rascunhos.
- Alinha a dependência do conector com o Meta Bundle 0.14.0, que inclui a interface Svelte e o Tech Provider preservado.

## 1.0.14

- Adiciona uma URL compartilhável e estável para cada conversa do atendimento.
- Atualiza a barra de endereço com History API sem recarregar ou desmontar o Inbox.
- Mantém Voltar e Avançar entre conversas dentro da interface, neutralizando o recarregamento global do shell apenas nas rotas do Inbox.
- Abre diretamente a conversa indicada após autenticação e verificação das permissões já existentes.
- Padroniza todas as URLs do plugin em inglês, começando por `/s/inbox`.

## 1.0.13

- Detecta conversas WhatsApp equivalentes quando a Meta alterna números móveis brasileiros com ou sem o nono dígito.
- Adiciona uma prévia segura e um comando explícito para consolidar duplicidades existentes.
- Preserva mensagens, eventos, filas, rascunhos, notas, contato vinculado, tomada humana e sessão do agente de IA durante a união.
- Bloqueia automaticamente grupos com contatos conflitantes e executa cada união em uma única transação.

## 1.0.12

- Remove o teto de respostas dos agentes de IA; o contador passa a ser apenas telemetria.
- Adiciona reinício seguro da sessão do agente, com novo nonce e invalidação de trabalhos antigos.
- Mantém pausas causadas por falha de entrega, retomada humana, restrições do canal e desativação administrativa.
- Exibe estado e autoria do agente no atendimento e simplifica a configuração para modelos realmente disponíveis no Pi/Codex.
- Serve mídia recebida pelo WhatsApp por proxy autenticado e adiciona nova tentativa automática e manual de carregamento.
- Amplia o histórico inicial para evitar que anexos recentes fiquem ocultos pela paginação.
- Consolida configurações, documentos, fontes controladas, gerenciamento de respostas prontas e atualização em tempo real da interface.
- Atualiza testes e documentação operacional.

## 1.0.2

- Adiciona captura atual do atendimento ao README.
- Atualiza instruções de instalação para Inbox 1.0.2 e Meta 0.12.1.
- Vincula a documentação visual do conector e esclarece a compatibilidade entre os plugins.
- Mantém o comportamento do atendimento e a dependência mínima Meta ^0.12.0.

## 1.0.1

- Publica o Atendimento como repositório independente, extraído da tag `inbox-v1.0.0` do Mautic.
- Define o pacote Composer `raphaelcangucu/mautic-inbox-bundle`.
- Corrige a dependência do Meta Bundle para `^0.12.0`.
- Documenta instalação, arquitetura, operação, diagnóstico, limites e validação.
- Preserva o comportamento do código previamente validado.

## 1.0.0

- Primeira implementação validada: atendimento WhatsApp, Instagram e Facebook/Messenger.
- Atribuição, transferência, resolução, adiamento, notas e rascunhos.
- Integração com a fila Meta e bloqueio de automações durante a tomada humana.
- Nomes e fotos, prévias de mídia, formatação segura, SSE e alertas sonoros/visuais.
