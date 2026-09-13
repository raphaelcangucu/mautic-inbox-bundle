# Arquitetura e contrato com o conector

## Divisão de responsabilidades

| Dados ou operação | Responsável |
| --- | --- |
| Tokens, conexões, ativos e permissões Meta | Meta Bundle |
| Recepção de webhooks, identidades e vínculo com contato | Meta Bundle |
| Mensagens, conversas externas e fila de envio | Meta Bundle |
| Responsável, resolução, adiamento e tomada humana | Inbox Bundle |
| Notas internas, rascunhos, respostas prontas e log de atendimento | Inbox Bundle |
| Contexto de comentários e pedidos de resposta humana | Inbox Bundle, referenciando entidades Meta |

O Inbox não replica um segundo cadastro de credenciais ou transporte Meta. Usa serviços do conector no mesmo container Symfony e referências às entidades persistidas pelo conector.

## Fluxo de entrada e saída

```mermaid
sequenceDiagram
    participant Meta as Canal Meta
    participant Connector as Meta Bundle
    participant DB as Banco Mautic
    participant Inbox as Inbox Bundle
    participant Agent as Atendente
    Meta->>Connector: Webhook
    Connector->>DB: Mensagem e identidade
    Connector->>Inbox: messagePersisted
    Inbox->>DB: Estado e contexto de atendimento
    Inbox-->>Agent: SSE sinaliza mudança; interface busca dados
    Agent->>Inbox: Assumir e responder
    Inbox->>DB: Tomada humana e pedido idempotente
    Inbox->>Connector: Enfileirar resposta
    Connector->>Meta: Worker envia
    Connector->>Inbox: outboundJobChanged
    Inbox-->>Agent: Atualização do estado do envio
```

## Integração opcional

O Meta Bundle declara `Application/Support/InboxIntegrationInterface` e uma implementação `NoopInboxIntegration`. O compiler pass `MetaInboxIntegrationPass` do Inbox troca o alias pela implementação `Integration/MetaInboxIntegration` quando ambos estão registrados.

Os pontos de integração incluem `messagePersisted`, `outboundJobChanged`, `automationAllowed`, `runAutomationGuarded` e `runHumanTransition`. A tomada humana bloqueia automações diretas e enfileiradas, com coordenação de locks por ativo/destinatário. Resolver, transferir, adiar ou desatribuir uma conversa não reativa automaticamente a automação.

Conversas não são unidas por nome. O vínculo entre comentário e mensagem privada exige a identidade externa exata no mesmo ativo. Os comentários preservam também seu contexto de publicação e comentário.

## Persistência e segurança

As sete entidades do Inbox ficam em `Entity/`: ConversationState, Note, Draft, EventLog, CannedResponse, OutboundRequest e CommentContext. Mensagens e identidades continuam no Meta Bundle. Os nomes das tabelas e comandos de instalação estão no guia de operação.

Mutações exigem sessão autenticada, permissões e CSRF. A API interna recebe o ID do estado; ativo e destinatário vêm do banco. Respostas usam chave idempotente, e a transação verifica a propriedade da conversa antes do enfileiramento. Envios com resultado incerto ficam para revisão, evitando repetição automática de resposta possivelmente aceita.

As permissões atuais são por papel, não por ativo. A API de apresentação não deve expor tokens nem payloads Meta brutos. Notas internas e rascunhos nunca são enviados ao canal.

## Interface e atualização

`Controller/InboxController.php` atende rotas internas autenticadas declaradas em `Config/config.php`. `InboxQuery` monta a apresentação; `ConversationActions` aplica transições; `MessagePresentation` prepara anexos. Twig, CSS e JavaScript estão em `Resources/views` e `Assets`.

O SSE sinaliza alterações; o navegador busca o conteúdo pela API interna. O stream dura aproximadamente 15 segundos, libera a sessão e usa heartbeats, com reconexão e fallback silencioso. Não é um servidor WebSocket dedicado: cada conexão de stream ocupa capacidade do servidor PHP enquanto está aberta. Dimensione workers e proxy conforme a quantidade de atendentes.

Alertas usam cursor de mensagens recebidas separado dos filtros. A primeira carga estabelece uma referência silenciosa. O favicon acumula alertas até a conversa ser aberta; áudio requer interação e respeita a preferência local por usuário. Deduplicação entre abas usa recursos do navegador quando disponíveis.
