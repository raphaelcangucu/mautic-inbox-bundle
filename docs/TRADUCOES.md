# Traduções da interface

Os plugins usam os catálogos nativos do Mautic em `Translations/pt_BR/messages.ini` e `Translations/en_US/messages.ini`. O idioma da sessão segue a preferência do usuário e o idioma padrão do Mautic. Após mudar a preferência, uma nova sessão pode ser necessária.

## Cobertura

- Menus, botões, filtros, paginação, situações, tipos de mensagem e acessibilidade.
- Formulários e mensagens de confirmação do conector Meta.
- Editor e prévia de modelos; avisos sonoros, rascunhos e estados de conexão do Atendimento.
- Avisos e erros da API do Atendimento, incluindo permissões e janelas de resposta.
- Datas visíveis seguem o idioma. Valores de API, filtros, identificadores e timestamps de transporte permanecem estáveis.

As mensagens de usuários, nomes, respostas prontas e conteúdo dos modelos não são traduzidos. Diagnósticos técnicos retornados pela Meta podem continuar no idioma original.

## Manutenção

Mantenha as mesmas chaves nos dois catálogos. As chaves `mautic.meta.ui.*` e `mautic.inbox.ui.*` são identificadores estáveis: altere o texto traduzido sem renomeá-las. Use parâmetros para mensagens com valores dinâmicos.

Twig usa `|trans` e escapa os atributos. O JavaScript recebe apenas as traduções necessárias à página em um atributo JSON escapado; insere os textos com `textContent`. Os serviços de apresentação usam o tradutor Symfony. A recuperação de conflitos usa o status HTTP 409, sem depender do idioma do erro.

## Verificação

Os testes de integração verificam sessões em português e inglês, navegação, catálogos JavaScript e erros da API. Também validam a preservação do conteúdo original das mensagens e dos modelos. O teste JavaScript do conector verifica a paridade dos catálogos e os avisos de JSON inválido nos dois idiomas.

Validação desta implementação: 34 testes do Atendimento (214 asserções), 2 testes da interface Meta (42 asserções), 19 templates Twig e testes JavaScript. Conferência no navegador: idioma en_US, alertas e datas traduzidos, sem chaves de tradução visíveis; testes de sessão pt_BR e en_US no ambiente isolado.
