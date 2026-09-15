# Agentes de atendimento com Pi

A configuração fica em `/s/inbox/ai`, acessível a administradores. O atendimento permite atribuir explicitamente uma conversa a um agente. Não há atribuição automática de todos os contatos.

## Dependências

- Mautic 7 e MauticMetaBundle com `OutboundOperationExecutor` integrado a `runAiGuarded`. Instalar as versões correspondentes dos dois plugins: uma versão antiga do conector não executa envios de IA.
- Node.js 22 e npm; Pi `@earendil-works/pi-coding-agent` fixado em `0.85.1`.
- Runtime privado em `/home/forge/inbox-pi` (alternativa: variável `INBOX_PI_HOME`). Usuário do PHP e worker precisa ler/escrever esse diretório. Não servir pela web.
- Autenticação OpenAI/Codex em `auth.json` do Pi, importável do login Codex existente no servidor. Credenciais não ficam em documentos, respostas HTTP ou Git. Renovar a autenticação quando necessário.
- `cms.json` privado, modo 0600, com `url` HTTPS e `token` da API do CMS. Copiar por canal seguro; não registrar o token. A configuração atual foi obtida do Distribution Machine.

O botão de instalação instala a versão fixada, sem scripts npm, e copia o runner do plugin. **Verificar modelos disponíveis** executa uma chamada mínima em paralelo para cada modelo do catálogo Codex e mantém no seletor somente os que responderem. A validação testa o modelo escolhido e a leitura do CMS, sem enviar mensagens a clientes. A configuração atual usa GPT-5.6 Luna; não implica custo gratuito. Modelos disponíveis apenas por chave da API OpenAI não aparecem nessa conexão OAuth.

## Instalação e execução

Após instalar os dois plugins, limpar o cache do Mautic e executar:

```sh
php bin/console mautic:inbox:ai:setup --env=prod
php bin/console mautic:inbox:ai:work --env=prod
```

O primeiro comando cria somente a tabela `inbox_ai_records` e documentos/agente iniciais ausentes. É repetível e não sobrescreve documentos editados. O segundo processa até dez atribuições ativas com bloqueio de execução concorrente. Agendar a cada minuto; manter também o processador normal da fila Meta. O tempo de resposta inclui o intervalo desses workers e a geração do modelo.

Na primeira instalação, a IA vem globalmente desativada, com permissões vazias. Validar o Pi, escolher contas/canais e ativar globalmente e por agente. Contas WABA não são caixas de mensagens e não aparecem como destinos. Instagram/Facebook separam mensagens e comentários; WhatsApp permite mensagens.

## Documentos e agentes

Markdown fica no banco: rascunho, versão publicada e histórico com autor/data. Documentos globais são herdados; os demais são selecionados por agente. A publicação vale para novas atribuições. Uma conversa em andamento retém a versão do contexto da atribuição, inclusive na reativação do mesmo agente. Transferir para outro agente carrega o contexto desse agente sem zerar o contador.

Os perfis Pi disponíveis são suporte e análise esportiva, com documentos configuráveis. O modelo é escolhido globalmente no Pi e herdado pelos agentes. Os documentos iniciais incluem identidade, atendimento, fontes, análise esportiva, guia da plataforma e conversão.

## Fontes e funil

Ferramentas permitidas: busca/leitura de artigos publicados, busca/detalhe/categorias de mercados públicos ativos e consulta da etapa do contato vinculado no Mautic. Não há terminal, arquivos, extensões Pi, ferramentas arbitrárias ou escrita na API.

CMS: GET em `/api/v1/blog`, artigo por slug, `/api/v1/market-predictions/search`, mercado por slug e categorias. Filtra rascunhos, previews e itens desativados; remove campos internos. As buscas são limitadas às primeiras páginas e nunca devem ser apresentadas como inventário completo. Cada geração permite até duas consultas CMS.

`contact_funnel` recebe exclusivamente o contato já vinculado à conversa; não aceita IDs ou emails fornecidos ao modelo. Consulta atualizada a cada geração: Waitlist → cadastro, Registered → orientação de primeiro depósito, First Deposit/First Order → suporte. A etapa desconhecida não comprova ausência de cadastro. Em comentários públicos, a ferramenta não fornece a etapa, apenas indica continuação privada. A origem é o CRM, não uma verificação financeira ao vivo. A plataforma deve manter as etapas sincronizadas; o agente não as altera. O documento `conversao.md` permite editar a conduta e os convites, respeitando os limites fixos de privacidade.

## Continuidade e falhas

Não existe limite de respostas por conversa. O contador exibido é somente telemetria e nunca pausa, transfere ou encerra o agente. O botão de reinício gera uma nova sessão segura, zera contador e reincidência fora do escopo e invalida gerações ou envios antigos. Permite-se uma troca entre agentes por sessão para evitar alternância acidental.

Cada geração continua limitada a três turnos de modelo e 65 segundos; a saída tem até 900 caracteres. Não há repetição automática de uma geração ou envio de IA que falhou. A ausência de teto de mensagens não elimina custo: documentos, histórico e consultas também consomem tokens.

A retomada humana invalida a geração e a resposta ainda na fila. Antes do envio, o conector verifica novamente atribuição, permissões e a mensagem mais recente sob o mesmo bloqueio da retomada humana. Falhas pausam o agente e devolvem a necessidade de resposta à equipe. As restrições de janela do canal e consentimento continuam aplicáveis; IA não dispara templates automaticamente fora da janela. Instagram usa resposta privada ao comentário; Facebook usa resposta pública.

Desativar globalmente ou por agente impede os próximos envios. Nenhuma configuração de prompt pode ampliar as ferramentas ou permissões. O prompt restringe o conteúdo ao projeto, mas ainda exige avaliação de qualidade, especialmente para análises esportivas. Não prometer resultados nem pressionar depósitos.

## Validação

Testes funcionais verificam publicação de documentos/conflito de edição, interseção de permissões, contador sem teto, reinício seguro, deduplicação, retomada humana, falha de entrega, consulta ao funil e proteção de comentários públicos. Testes Node verificam filtragem dos dados públicos do CMS. A validação real do Pi/CMS é isolada e não envia mensagens a clientes.
