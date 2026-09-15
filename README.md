# Atendimento omnicanal para Mautic

Este bundle adiciona ao Mautic uma caixa nativa de atendimento para WhatsApp, Instagram e Facebook. O `MauticMetaBundle` continua responsável por ativos, identidades, contatos, mensagens, webhooks e envios; o `MauticInboxBundle` organiza o atendimento humano, respostas imediatas, mídia recebida e agentes de IA executados pelo Pi/Codex.

## Recursos principais

- Conversas privadas e comentários Meta em uma interface responsiva dentro do Mautic.
- Atribuição humana, transferência, resolução, adiamento, notas internas, rascunhos e respostas prontas.
- Envio imediato com idempotência, reenvio explícito de falhas e acompanhamento dos estados reais da Meta.
- Imagens, áudios, vídeos, documentos e figurinhas do WhatsApp por proxy autenticado, sem expor tokens ou URLs temporárias da Meta ao navegador.
- Atualização em tempo real por SSE, com recuperação por polling e preservação do editor.
- Agentes de IA com contexto versionado, fontes controladas, indicação visual de autoria e execução e retomada humana segura.
- Sessões de IA sem teto de mensagens. O contador é somente telemetria e pode ser reiniciado pela interface.

## Instalação

Pré-requisitos: Mautic 7, PHP compatível com a versão do Mautic instalada e `MauticMetaBundle` 0.10.4 ou compatível instalado. Em uma instalação DDEV:

```bash
ddev start
ddev composer install
ddev exec php bin/console mautic:plugins:reload
ddev exec php bin/console cache:clear
```

O recarregamento de plugins usa os metadados Doctrine do bundle, que é a convenção de instalação de schema para plugins Mautic. Ele cria:

- `inbox_conversation_states`
- `inbox_notes`
- `inbox_drafts`
- `inbox_event_log`
- `inbox_canned_responses`
- `inbox_outbound_requests`
- `inbox_comment_contexts`
- `inbox_ai_records`

Conceda as permissões de **Atendimento / Conversas** e **Atendimento / Respostas prontas** aos papéis apropriados. A leitura exige também permissão Meta de mensagens; o envio exige ativo publicado e ativo. As permissões deste MVP são por papel, sem isolamento por conta individual.

Nenhuma conversa antiga é migrada automaticamente. Confira um ativo por vez e aplique explicitamente:

```bash
# prévia, sem escrita
ddev exec php bin/console mautic:inbox:reconcile --asset-id=12 --limit=500

# aplica somente ao ativo informado
ddev exec php bin/console mautic:inbox:reconcile --asset-id=12 --limit=500 --apply
```

O comando também separa cada comentário público por conta, mídia e comentário. A conversa privada posterior fica vinculada ao contexto do comentário, sem copiar nem mesclar o contato por nome.

Processe os envios Meta, as conversas atribuídas à IA e as conversas adiadas a cada minuto:

```cron
* * * * * cd /caminho/do/mautic && ddev exec php bin/console mautic:meta:queue:process --limit=100
* * * * * cd /caminho/do/mautic && ddev exec php bin/console mautic:inbox:ai:work --env=prod
* * * * * cd /caminho/do/mautic && ddev exec php bin/console mautic:inbox:wake --limit=500
```

As leituras da caixa também acordam um lote pequeno de conversas vencidas, então uma falha curta do cron não prende conversas indefinidamente.

## Agentes de IA

A administração fica em `/s/atendimento/ia`. Antes de atribuir conversas, instale e valide o runtime Pi, confira os modelos realmente disponíveis para a autenticação Codex do servidor, publique os documentos e libere explicitamente as contas e os canais de cada agente.

```bash
php bin/console mautic:inbox:ai:setup --env=prod
php bin/console mautic:inbox:ai:work --env=prod
```

O agente não possui limite de respostas por conversa. O contador exibido ajuda a observar a sessão, mas não pausa, transfere ou encerra o atendimento. A ação de reinício invalida trabalhos antigos, cria uma nova sessão e zera a telemetria sem ampliar permissões. Falha de entrega, retomada humana, desativação administrativa, restrições do canal e ações explícitas do agente continuam interrompendo a automação de forma segura.

O modelo só recebe ferramentas e fontes autorizadas. Credenciais do Pi e do CMS ficam fora do banco de documentos, das respostas HTTP e do repositório. Consulte [Agentes de atendimento com Pi](docs/AI-AGENTS.md) para instalação, permissões, fontes e diagnóstico.

## Segurança e comportamento

- Toda mutação exige permissão e token CSRF.
- O ativo e o destinatário vêm da conversa persistida. O cliente envia apenas texto, ação, versão e identificador de requisição.
- Assumir e transferir usam versão e atualização condicional. A resposta trava a linha da conversa antes de validar o responsável e enfileirar.
- Uma resposta humana usa um identificador idempotente e uma tentativa. Falhas de transporte com resultado incerto ficam retidas para revisão e não são reenviadas cegamente.
- Assumir ou responder ativa a tomada humana. Ela bloqueia automações enfileiradas no instante do envio e ações diretas de campanha. Resolver, transferir, adiar ou devolver à fila nunca religa a automação.
- Uma nova entrada reabre uma conversa resolvida, encerra o adiamento e marca **Aguardando resposta**, sem alterar a tomada humana.
- Notas e rascunhos nunca entram na fila externa. Rascunhos são separados por conversa, usuário e modo.
- O polling usa cursores, limites e janelas de no máximo 24 horas. A atualização não escreve no editor.
- A API da caixa retorna apenas campos de apresentação; tokens, respostas Meta completas e payloads brutos não são expostos.
- Imagens, áudios, vídeos, documentos e figurinhas recebidos pelo WhatsApp são servidos por uma rota autenticada. Uma falha temporária ganha nova tentativa automática e uma ação manual de recarga.
- Agentes de IA não têm teto de mensagens. O contador é apenas informativo; um reinício explícito invalida o trabalho anterior, zera a telemetria da sessão e mantém as permissões do canal.

## Testes

Neste checkout, siga a regra do repositório e não execute PHP ou Composer no host. Com DDEV disponível:

```bash
ddev exec php bin/phpunit -c app/phpunit.xml.dist plugins/MauticInboxBundle/Tests/Unit
ddev exec php bin/phpunit -c app/phpunit.xml.dist plugins/MauticInboxBundle/Tests/Functional/InboxBundleTest.php
ddev exec php bin/phpunit -c app/phpunit.xml.dist plugins/MauticMetaBundle/Tests/Unit/Application/Queue/OutboundQueueTest.php
ddev composer phpstan
ddev composer cs
ddev exec php bin/console lint:twig plugins/MauticInboxBundle/Resources/views
```

Os testes automatizados não acessam serviços Meta nem enviam mensagens reais. A validação de mídia e entrega em produção deve usar uma conversa de teste autorizada e confirmar os estados recebidos pelo webhook.

## Reversão

Antes de remover o bundle, pare os crons `mautic:inbox:wake` e `mautic:inbox:ai:work`, retire as permissões e faça backup apenas das tabelas `inbox_*`. Remova o diretório do bundle e recarregue os plugins. O reload não apaga dados automaticamente. Se a remoção definitiva dos dados foi aprovada, descarte as oito tabelas `inbox_*` listadas acima em ordem inversa. Não remova tabelas `meta_*`: elas pertencem ao conector e contêm as mensagens e vínculos com contatos.

As alterações opcionais no `MauticMetaBundle` podem permanecer: sem este bundle, o `NoopInboxIntegration` mantém o comportamento independente do conector.

## Limites conhecidos

- As permissões de conversas e Meta são concedidas no nível do papel. Não há ACL por ativo para cada atendente.
- “Enviada” significa que o worker obteve confirmação de aceitação da operação. A interface não afirma entrega ou leitura; esses estados só existem no log Meta quando o webhook do canal os fornece.
- A API do Instagram determina quando uma resposta privada a comentário ou uma mensagem direta ainda é permitida. Uma rejeição aparece como falha para revisão.
- A Cloud API não fornece a foto pessoal do contato WhatsApp. O avatar do usuário vem do contato vinculado no Mautic; a mídia enviada dentro da conversa é baixada sob demanda pelo proxy autenticado.
- A aba Automação lista regras de comentário já configuradas e abre a campanha correspondente. Ela não edita, publica ou executa campanhas.
- O vínculo entre comentário e diálogo privado é feito pela identidade externa exata dentro do mesmo ativo. Nenhum contato é unido por nome.

## Compatibilidade e versão

A versão `1.0.12` requer Mautic 7, PHP 8.2 ou superior e `raphaelcangucu/mautic-meta-bundle ^0.10.4`. O conector pode operar sem o Inbox; o Inbox depende do conector para comunicação com a Meta. O projeto usa a licença [GPL-3.0-or-later](LICENSE). Veja o [histórico da versão](CHANGELOG.md).
