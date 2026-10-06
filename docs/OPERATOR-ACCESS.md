# Acesso de operadores e avaliação Apple

As permissões existentes de Conversas e Mensagens Meta continuam obrigatórias. O novo grupo **Visibilidade das conversas**, no papel do usuário, limita os registros antes de paginação, busca e contagem:

- **Atribuídas a mim**: somente conversas cujo responsável é o usuário.
- **Fila sem responsável, aguardando atendimento**: conversa sem responsável, aberta e com necessidade de resposta. Conversas de outro operador, adiadas, resolvidas ou sem resposta pendente não entram nessa fila.
- **Todas as conversas**: supervisão explícita. Administradores mantêm acesso completo.

Um papel antigo sem configuração de visibilidade assume próprias + fila aguardando atendimento. Uma configuração explícita substitui esse padrão; um papel com visibilidade zero não recebe conversas. Permissões de CRM continuam independentes e seguem a propriedade do contato/campanha/segmento.

A regra protege detalhes, histórico, notificações, mídia, links internos, ações de atendimento, contexto do assistente e destinatários de push. Abrir um ID sem acesso devolve 404. Os caminhos antigos globais do conector Meta e o transporte MCP externo ficam indisponíveis aos papéis restritos; eles usam o Inbox com escopo. As ferramentas locais do assistente continuam aplicando as permissões do usuário.

A atribuição existente libera uma conversa para o operador. Não há nova tabela nem migração. Ao transferir/devolver uma conversa, uma confirmação pode trazer `access_revoked=true`, sem consultar dados posteriores à perda de acesso. O aplicativo deve retirar esse atendimento de seu cache ativo e voltar à lista.

## Criar um usuário limitado

Use `mautic:inbox:operator:create --credentials-file=/caminho/privado/usuario.json --env=prod`. O arquivo JSON, com modo 0600, contém `username`, `email`, `password` (mínimo 16 caracteres) e `scope` (`own` ou `operator`). Nome/sobrenome são opcionais. O comando recusa usuário/email/papel existente, usa os modelos e o hash de senha do Mautic, confere o papel gravado e não envia e-mail. Nunca versionar o arquivo de credenciais.

O avaliador Apple usa `scope=own`, sem Administrator, com contatos fictícios próprios e conversas QA atribuídas. No app, selecionar **Entrar pela página do Mautic**, informar usuário/senha e confirmar a conexão. A sessão do aplicativo continua usando PKCE e tokens opacos; a senha não é armazenada no aplicativo. Não compartilhar o usuário Administrator.

## Validação

`Tests/Standalone/conversation-scope.php` testa a matriz de visibilidade sem banco. `Tests/Integration/operator-access.php` inicia o kernel real e compara `SELECT DATABASE()` ao nome exato de `MAUTIC_TEST_DATABASE_ALLOW_DESTRUCTIVE`, exigindo nome descartável e host `review_db`, antes de qualquer escrita. Executar somente na instalação descartável criada para os testes. Nunca executar na produção nem copiar a base real para rodar estes testes.

## Página WebChat para avaliação

Após criar o operador own-only, executar `mautic:inbox:review-webchat:create --username=apple-review --base-url=https://seu-mautic.example --env=prod`. O comando cria um contato fictício pertencente ao operador, um widget sem coleta de nome/email/telefone e um asset exclusivo. Não reutiliza canais ou contatos de clientes e recusa duplicatas. A associação Meta existe apenas por exigência do modelo atual: conexão e asset ficam inativos para o Graph; o transporte WebChat permanece disponível.

A saída contém o endereço público `/inbox/review/webchat/{publicKey}`. Essa página inicia o widget oficial, explica login, envio/resposta e push. Novas conversas desse widget são atribuídas automaticamente ao operador. O endpoint de sessão ignora identidade informada/signed identity apenas nesse widget, evitando vinculação a um contato real. Os widgets existentes continuam com sua política atual. Credenciais nunca aparecem nessa página; devem ser informadas em App Review Notes.
