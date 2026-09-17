# Instalação e dependências

## Preparação

Use uma instalação Mautic 7 funcional, com acesso à linha de comando, banco compatível e o mesmo ambiente PHP usado pela aplicação. O conjunto foi validado em Mautic 7.2.0-rc; isso não equivale a uma matriz de testes de todas as versões 7.x.

O conector precisa ser instalado **antes ou junto** ao Inbox, na mesma aplicação. A versão 0.14.0 fornece `InboxIntegrationInterface`, a integração opcional, perfis de participantes, as operações Facebook usadas pelo atendimento, o Tech Provider e a interface administrativa Svelte compatível. A restrição Composer `^0.14.0` aceita atualizações compatíveis da série 0.14.x.

Faça backup do banco antes de instalar ou atualizar. Execute os comandos a seguir na raiz do Mautic e somente se os diretórios de destino ainda não existirem:

```bash
git clone --branch v0.14.0 --depth 1 https://github.com/raphaelcangucu/mautic-meta-bundle.git plugins/MauticMetaBundle
git clone --branch v1.1.1 --depth 1 https://github.com/raphaelcangucu/mautic-inbox-bundle.git plugins/MauticInboxBundle
```

Em ambiente DDEV:

```bash
ddev exec php bin/console mautic:plugins:reload
ddev exec php bin/console cache:clear
```

Em servidor sem DDEV, execute os mesmos comandos de console com o PHP e usuário da aplicação. O recarregamento instala as tabelas descritas no [guia de operação](OPERACAO.md). O usuário da aplicação deve ter acesso aos arquivos e ao cache.

### Instalação gerenciada por Composer

O pacote tem tipo `mautic-plugin` e diretório de instalação `MauticInboxBundle`. Não pressupõe publicação no Packagist. Em uma instalação Mautic já gerenciada por Composer, registre os dois repositórios VCS no projeto principal e use seu instalador de plugins:

```bash
ddev composer config repositories.meta vcs https://github.com/raphaelcangucu/mautic-meta-bundle.git
ddev composer config repositories.inbox vcs https://github.com/raphaelcangucu/mautic-inbox-bundle.git
ddev composer require raphaelcangucu/mautic-meta-bundle:^0.14.0 raphaelcangucu/mautic-inbox-bundle:^1.1.1
```

Verifique a resolução das dependências e os diretórios resultantes em homologação. Não misture instalação por clone com Composer nos mesmos diretórios. A implantação validada utilizou os diretórios dos bundles; o fluxo Composer acima depende da configuração do projeto principal.

## Configurar o conector

Credenciais, contas e webhooks são configurados no Meta Bundle, nunca neste plugin. Siga a documentação do conector para o tipo de login e as permissões apropriadas de cada canal. Configure ativos publicados e habilitados; a conexão precisa permitir a leitura e a resposta desejadas. Assinaturas de mensagens e comentários são distintas. O Inbox não contorna janelas de atendimento, permissões ou aprovação de aplicativo da Meta.

## Permissões e conversas existentes

Conceda ao papel do atendente permissões de Atendimento/Conversas e, quando necessário, Respostas prontas. A leitura também exige a permissão Meta de mensagens. Entre novamente ou atualize a sessão se o menu não aparecer.

Para trazer conversas já existentes, substitua `12` pelo ID do ativo conferido no conector:

```bash
ddev exec php bin/console mautic:inbox:reconcile --asset-id=12 --limit=500
ddev exec php bin/console mautic:inbox:reconcile --asset-id=12 --limit=500 --apply
```

A primeira chamada é prévia. A segunda grava o estado de atendimento para o ativo selecionado. Novas mensagens processadas pelo conector passam pela integração automaticamente.

## Processamento recorrente

Exemplo de cron no servidor; substitua o caminho e o executável PHP conforme a instalação. Não duplique um worker Meta já existente:

```cron
* * * * * cd /caminho/do/mautic && php bin/console mautic:meta:queue:process --limit=100
* * * * * cd /caminho/do/mautic && php bin/console mautic:inbox:wake --limit=500
```

A primeira tarefa envia mensagens enfileiradas. A segunda reabre conversas cujo adiamento venceu. Com cron por minuto, uma resposta pode aguardar até o próximo processamento; volumes maiores exigem acompanhar a fila.

## Atualização e reversão

Mantenha as versões dos dois plugins compatíveis, atualize os arquivos por seu método de implantação, recarregue plugins e limpe o cache. Preserve as tabelas `inbox_*` e `meta_*`. Para reversão de código, use a versão anterior dos dois bundles e verifique compatibilidade do esquema antes de restaurar banco. A remoção do Inbox não deve apagar mensagens do conector. Consulte [Reversão](OPERACAO.md#reversão).
