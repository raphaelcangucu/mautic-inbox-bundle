# Atendimento omnicanal para Mautic

Este bundle adiciona a caixa nativa **Atendimento** para WhatsApp, Instagram e Facebook/Messenger. O `MauticMetaBundle` continua responsável por ativos, identidades, contatos, mensagens, webhooks e envios; o `MauticInboxBundle` guarda apenas o estado do trabalho humano.

## Instalação

Pré-requisitos: Mautic 7, PHP compatível com a versão do Mautic instalada e `MauticMetaBundle` 0.14.0 ou compatível instalado. Em uma instalação DDEV:

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

Conceda as permissões de **Atendimento / Conversas** e **Atendimento / Respostas prontas** aos papéis apropriados. A leitura exige também permissão Meta de mensagens; o envio exige ativo publicado e ativo. As permissões deste MVP são por papel, sem isolamento por conta individual.

Nenhuma conversa antiga é migrada automaticamente. Confira um ativo por vez e aplique explicitamente:

```bash
# prévia, sem escrita
ddev exec php bin/console mautic:inbox:reconcile --asset-id=12 --limit=500

# aplica somente ao ativo informado
ddev exec php bin/console mautic:inbox:reconcile --asset-id=12 --limit=500 --apply
```

O comando também separa cada comentário público por conta, mídia e comentário. A conversa privada posterior fica vinculada ao contexto do comentário, sem copiar nem mesclar o contato por nome.

Processe os envios Meta com o worker já existente e acorde conversas adiadas a cada minuto:

```cron
* * * * * cd /caminho/do/mautic && ddev exec php bin/console mautic:meta:queue:process --limit=100
* * * * * cd /caminho/do/mautic && ddev exec php bin/console mautic:inbox:wake --limit=500
```

As leituras da caixa também acordam um lote pequeno de conversas vencidas, então uma falha curta do cron não prende conversas indefinidamente.

## Segurança e comportamento

- Toda mutação exige permissão e token CSRF.
- O ativo e o destinatário vêm da conversa persistida. O cliente envia apenas texto, ação, versão e identificador de requisição.
- Assumir e transferir usam versão e atualização condicional. A resposta trava a linha da conversa antes de validar o responsável e enfileirar.
- Uma resposta humana usa um identificador idempotente e uma tentativa. Falhas de transporte com resultado incerto ficam retidas para revisão e não são reenviadas cegamente.
- Assumir ou responder ativa a tomada humana. Ela bloqueia automações enfileiradas no instante do envio e ações diretas de campanha. Resolver, transferir, adiar ou devolver à fila nunca religa a automação.
- Uma nova entrada reabre uma conversa resolvida, encerra o adiamento e marca **Aguardando resposta**, sem alterar a tomada humana.
- Notas e rascunhos nunca entram na fila externa. Rascunhos são separados por conversa, usuário e modo.
- As atualizações usam SSE autenticado, com atualização silenciosa como alternativa. Cursores e limites preservam o editor e a posição de leitura.
- A API da caixa retorna apenas campos de apresentação; tokens, respostas Meta completas e payloads brutos não são expostos.

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

Os testes automatizados usam banco isolado e não enviam mensagens reais. A versão 1.0.0 foi validada com 32 testes e 190 asserções do Inbox, além dos testes JavaScript de formatação e notificações. O conector Meta passou em 79 testes e 218 asserções, com sete avisos de depreciação preexistentes.

## Reversão

Antes de remover o bundle, pare o cron `mautic:inbox:wake`, retire as permissões e faça backup apenas das tabelas `inbox_*`. Remova o diretório do bundle e recarregue os plugins. O reload não apaga dados automaticamente. Se a remoção definitiva dos dados foi aprovada, descarte as sete tabelas `inbox_*` listadas acima em ordem inversa. Não remova tabelas `meta_*`: elas pertencem ao conector e contêm as mensagens e vínculos com contatos.

As alterações opcionais no `MauticMetaBundle` podem permanecer: sem este bundle, o `NoopInboxIntegration` mantém o comportamento independente do conector.

## Limites conhecidos

- O MVP usa as permissões de conversas e Meta no nível do papel. Não cria ACL por ativo para cada atendente.
- “Enviada” significa que o worker obteve confirmação de aceitação da operação. A interface não afirma entrega ou leitura; esses estados só existem no log Meta quando o webhook do canal os fornece.
- A API do Instagram determina quando uma resposta privada a comentário ou uma mensagem direta ainda é permitida. Uma rejeição aparece como falha para revisão.
- Imagens e stickers têm prévia; vídeos e áudios usam controles nativos. A mídia é exibida por URL HTTPS, sem armazenamento ou proxy local. URLs expiradas ou indisponíveis mostram uma alternativa de acesso.
- A aba Automação apenas lista regras de comentário já configuradas e abre a campanha correspondente. Ela não edita, publica ou executa campanhas.
- O vínculo entre comentário e diálogo privado é feito pela identidade externa exata dentro do mesmo ativo. Nenhum contato é unido por nome.

## Interface

Layout com lista de conversas à esquerda, histórico central e contexto do contato à direita, com as cores do Mautic. Nomes, handles e fotos vêm dos dados disponíveis no canal; iniciais são a alternativa quando a foto não está disponível. Inclui contadores, filtros, separadores de data, formatação segura de Markdown e WhatsApp, notas internas, respostas prontas, rascunhos e atalho Ctrl/Cmd+Enter. Em telas estreitas, a conversa ocupa a tela e oferece retorno à lista.

A aba Comentários preserva o contexto da publicação e o vínculo com o atendimento privado pela identidade exata no mesmo ativo. Comentários do Facebook permitem resposta pública; respostas privadas a comentários do Instagram respeitam as permissões e janelas da Meta. A identificação de publicações e reels depende do contexto entregue pela API.

O navegador preserva rascunhos por conversa e modo, serializa gravações e reutiliza a chave de envio após falhas. As atualizações em tempo real preservam o histórico anterior carregado e a posição de leitura.

## Alertas de novas mensagens

O botão de som permite ativar ou silenciar o alerta e mantém a preferência por usuário. O navegador exige uma interação para liberar o áudio. Novas mensagens recebidas também acrescentam um contador ao favicon; abrir a conversa correspondente limpa seu alerta. A carga inicial, notas, mensagens enviadas e alterações de filtros não disparam o som. A detecção é independente dos filtros visíveis, com deduplicação entre abas quando o navegador oferece suporte.

Os alertas funcionam enquanto a caixa está aberta e conectada; não são notificações do sistema operacional.

## Validação de integração

Foram verificados recebimento e resposta em contas próprias de teste no Instagram e Messenger, resposta pública a comentário do Facebook, identificação e fotos dos participantes, prévias de imagens e alerta visual de nova mensagem. O áudio e a deduplicação foram verificados por testes JavaScript. Vídeos têm controles e tratamento de falhas implementados; reels continuam dependentes dos eventos e permissões fornecidos pela Meta.
