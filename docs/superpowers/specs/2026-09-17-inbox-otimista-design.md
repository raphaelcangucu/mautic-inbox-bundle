# Design: Atendimento com resposta imediata

Data: 2026-09-17
Status: Aprovado, não implementado
Escopo: `MauticInboxBundle` — store em memória, envio otimista e abertura imediata de conversa

## Problema

O atendimento parece lento porque é lento, e dá para medir. Do moto g56 contra produção,
mediana de três execuções:

| Chamada | Mediana |
|---|---|
| Lista de conversas | 757 ms |
| Detalhe da conversa | 778 ms |
| Histórico de mensagens | 762 ms |

O problema não são os 750 ms de cada ida — é que o código as **encadeia**:

- **Abrir uma conversa** faz `detalhe` e, só depois dele, `histórico` e `ia`: ~1,5 s de tela parada.
- **Enviar uma mensagem** faz `take` (quando a conversa não é sua), depois `reply`, depois
  `histórico`, depois `lista`: até quatro idas, ~3 s com o botão preso em "enviando".

Um aplicativo de mensagens mostra o que a pessoa escreveu no quadro seguinte ao toque e resolve
o resto depois. É essa a diferença que a equipe sente.

## Objetivos

1. Abrir conversa já visitada sem nenhuma requisição.
2. Abrir conversa nova com resposta visual imediata e as três chamadas em paralelo.
3. Enviar mostrando a mensagem no mesmo quadro do toque.
4. Tirar o estado de dados de dentro do `InboxApp.svelte`, hoje com 1022 linhas.

## Fora de escopo

- Persistir cache no aparelho. Decisão registrada abaixo.
- Envio offline com fila e Background Sync.
- Reescrever o caminho de dados inteiro numa camada genérica de consulta e mutação.
- Qualquer mudança no workspace de IA.

## Decisões

| Questão | Decisão | Motivo |
|---|---|---|
| Falha no envio otimista | A mensagem fica na conversa, marcada, com botão de tentar de novo | É o padrão que a equipe já reconhece de outros aplicativos |
| Cache sobrevive ao app fechar? | Não, só memória | Cache persistido poria histórico de cliente num aparelho que a empresa não controla |
| Primeiro carregamento | Esqueleto no lugar das mensagens | Tela vazia por 780 ms parece travamento |
| Biblioteca de estado | Stores nativas do Svelte 5 | Zustand e Pinia são de React e Vue; e o plugin precisa instalar sem Node.js em produção |
| Conflito entre envio e atualização | Reconciliação por `request_id`, não por posição | A resposta enviada **é** um item de histórico e volta por mais de um caminho; sem chave, a mesma mensagem aparece duas vezes |
| Ordem de limpeza do rascunho | **Mantida como está hoje** | Inverter removeria duas linhas que protegem contra recriar um rascunho já enviado — caminho direto para duplicata no cliente |
| Onde mora o texto de uma pendente | Na própria pendente, em memória | Não precisa do rascunho para sobreviver a uma falha, e não mexe no que já funciona |
| Forma da store | Lógica em TS puro, runes só como casca | `$state` é construção de compilador; o `tsx` do pipeline de testes não compila Svelte |

## Arquitetura

Um arquivo novo, `Frontend/shared/inboxStore.svelte.ts`, passa a ser a única fonte de verdade do
cliente. Ele guarda quatro coleções:

- `list` — as conversas da lista, como vieram do servidor
- `conversations` — o detalhe de cada conversa já aberta, por id
- `timelines` — as mensagens **confirmadas** de cada conversa, por id
- `pending` — as mensagens enviadas que o servidor ainda não confirmou

**A separação entre `timelines` e `pending` é a peça de segurança do desenho.** Não é a mesma
lista com um campo a mais: são coleções distintas, com tipos distintos. Assim é impossível um
componente tratar por acidente uma mensagem não enviada como enviada — que é o erro que faria um
atendente jurar ter respondido um cliente que nunca recebeu nada.

A store é quem fala com o servidor. Os componentes param de chamar `api()` para lista, detalhe e
histórico: pedem à store e leem o que ela tem.

```
ensureList(filtro)            // lista, do cache ou da rede; o filtro E a chave do cache
ensureConversation(id)        // detalhe, do cache ou da rede
ensureTimeline(id)            // historico, do cache ou da rede
sendReply(id, modo, texto)    // otimista: aparece agora, o servidor aceita depois
retryPending(pendingId)       // tenta de novo, com o MESMO request_id
applyPoll(resultado)          // o resultado do poll entra por aqui
```

O que ela não faz: não persiste, não desenha, não conhece rota.

### `applyPoll`, e não `applyServerEvent`

O evento do SSE **não traz dados**: ele carrega só um token de versão, e a única reação do cliente
é disparar um `poll()`. Quem traz dado é o poll. Descrever a entrada como "o que o SSE traz"
levaria alguém a projetar uma fusão para uma carga que não existe.

### A chave do cache da lista

A lista real é filtrada por fila, tipo, ciclo de vida, canal, busca e "precisa resposta", mais um
cursor. `ensureList` recebe esse conjunto e o usa como chave — duas filtragens diferentes são duas
entradas de cache, não uma sobrescrevendo a outra.

### Abertura fria

O passo 1 de "abrir uma conversa" assume o resumo já em memória porque veio na lista. **Isso é
falso em quatro caminhos**: link direto, voltar e avançar do navegador, entrada por notificação, e
conversa fora da página de filtro atual. Nesses casos não há resumo: a moldura aparece com
esqueleto também no cabeçalho, e as três chamadas saem em paralelo como no caminho comum.

## Os dois fluxos

### Abrir uma conversa

1. No toque, a store já tem o resumo daquela conversa, porque veio na lista. Cabeçalho e moldura
   do chat aparecem no mesmo quadro, sem rede.
2. Se o histórico está em `timelines`, renderiza inteiro. Zero requisição.
3. Se não está, mostra esqueleto no lugar das mensagens e dispara as chamadas **em paralelo**.

### A abertura são três chamadas, não duas

O código de hoje faz `detalhe` e, **depois** dele, `Promise.all([histórico, ia])`. O estado de IA
está no caminho crítico da abertura, e o meu texto anterior o ignorava — o ganho de ~780 ms só
existe se ele entrar no paralelo também.

A store passa a disparar **`detalhe`, `histórico` e `ia` juntos**. O `ia` sai do caminho crítico:
a conversa renderiza sem esperar por ele, e o painel de IA preenche quando chegar.

Isso não conflita com "qualquer mudança no workspace de IA" estar fora de escopo: o workspace em
`/s/inbox/ai` não é tocado. O que muda é **quando** o inbox busca o estado de IA de uma conversa,
não o que ele faz com ele.

O POST de leitura que hoje sai sem espera continua saindo sem espera, fora do caminho crítico.

### Quando a conversa não existe mais

Numa abertura fria, `detalhe` pode responder 404 ou 403 — link antigo, conversa apagada, permissão
que mudou. Nesse caso o esqueleto **não pode ficar girando para sempre**: ele dá lugar à mensagem
de erro que o componente já sabe mostrar, e a store não guarda nada no cache. Uma conversa aberta
por link direto e não presente na lista **não** é inserida na lista em cache — ela existe em
`conversations` e `timelines`, e a lista continua sendo o que o filtro atual devolveu.

### Enviar uma mensagem

1. No toque, a store cria uma pendente **com seu próprio `request_id`** e com o texto dentro
   dela, põe no fim da conversa e limpa o composer. A tela atualiza num quadro.
2. Em segundo plano, **na ordem que o código de hoje já usa e que é carregada de significado**:
   cancelar a escrita debounced do rascunho, esperar a que estiver em voo, `take` se necessário,
   `reply`. Inverter essa ordem recria um rascunho que o servidor acabou de apagar.
3. **Quando o servidor aceita**, a pendente sai e o item de histórico entra — com o `status` que o
   servidor devolveu, não como "enviada".
4. Na falha antes de chegar ao servidor, a pendente fica marcada, com botão de tentar de novo, e
   o texto continua nela.

### O que "aceita" significa, e o que não significa

O `reply` responde **202**, e o `status` que ele devolve pode já ser `failed` ou `uncertain` — o
vocabulário do servidor tem nove estados: `pending`, `processing`, `waiting`, `uncertain`, `sent`,
`accepted`, `delivered`, `read`, `failed`.

Então "aceita" quer dizer **o nosso servidor recebeu e registrou**, e nada além disso. O destino da
mensagem no canal continua sendo o `status` do item de histórico, que o `MessageBubble` já sabe
desenhar, incluindo o caso de retentativa. Promover um 2xx a "enviada" pintaria um envio falho como
entregue — exatamente a falha que este documento existe para impedir.

### Reconciliação: por `request_id`, nunca por posição

**Uma resposta enviada é, ela própria, um item de histórico.** Ela volta pela resposta do envio e
volta de novo no próximo poll. Sem chave, o item confirmado chega enquanto a pendente ainda está na
tela e a mesma mensagem aparece duas vezes, uma delas dizendo "enviando". A regra não depende de
qual caminho trouxe o item, e por isso continua valendo depois que o recarregamento periódico sair.

O item de histórico já carrega `request_id`. A regra, **para resposta e modelo**, é uma só: quando um item de histórico com o
mesmo `request_id` de uma pendente aparece, a pendente some — venha ele da resposta do `reply`,
de um poll ou do recarregamento periódico. Não importa a ordem de chegada, e é isso que torna a
regra segura.

### Desenho da pendente

Uma pendente **não** é desenhada pelo `MessageBubble`. Reaproveitá-lo exigiria moldá-la no formato
de `TimelineItem`, que é precisamente a confusão que a separação de tipos existe para impedir. Ela
tem componente próprio, e é por isso que ela nunca consegue se parecer com uma mensagem enviada.

### Envio concorrente

Com o botão livre, o atendente pode disparar um segundo envio com o primeiro ainda em voo. Cada
pendente carrega o próprio `request_id` — o slot único por conversa e modo que existe hoje não
serve mais. As pendentes aparecem na ordem em que foram criadas.

### Modo nota: a exceção, dita por inteiro

A regra do `request_id` **não alcança a nota**, e isso precisa estar escrito em vez de subentendido.
O item de histórico de uma nota é `{kind: 'note', id, body, author, timestamp}` — sem
`request_id`, porque o servidor nunca atribui um. Sem chave, a nota real chega ao lado de uma
pendente que diz "enviando" e fica ali até a conversa sair do cache.

A nota reconcilia por **`kind: 'note'` mais o `id` que o próprio `/note` devolve**. Mas a
propriedade que torna a regra da resposta segura **não vale aqui**: na resposta, quem gera a chave
é o cliente, então a ordem de chegada não importa; na nota, a chave só existe depois que a resposta
dela volta. **Resposta de nota perdida deixa uma pendente que nunca reconcilia.**

E a nota **não tem idempotência**: o servidor grava uma nova a cada chamada, sem comparar nada.
Tentar de novo uma nota cria uma segunda nota. O dano é interno — nota não vai para o cliente —,
mas o plano não pode supor o contrário.

Envio por modelo passa pelo mesmo `reply` e recebe a nova resposta, então segue a regra da resposta.

### Conflito no `take`

O `take` pode responder com conflito de versão. Hoje o componente se recupera reselecionando a
conversa; a store precisa do equivalente antes de tentar o `reply`.

### A mudança que isso exige no servidor

O `reply` hoje devolve `{request_id, status}`. Passa a devolver também **o item de histórico
criado** e **o resumo atualizado da conversa**.

Sem isso o passo 3 custa duas idas extras: a tela responderia rápido, mas a mensagem confirmada
apareceria segundos depois e a lista ficaria velha. A mudança é aditiva — quem consome os campos
de hoje continua recebendo os mesmos.

## Bordas e falhas

- **Uma pendente tem dois estados: *enviando* e *falhou*.** Não existe estado "confirmada": quando
  o servidor aceita, a pendente **deixa de existir** e um item de histórico toma o lugar dela,
  carregando o status que o servidor deu. Escrever um terceiro estado produz exatamente o objeto
  que este desenho proíbe — uma pendente capaz de se desenhar como enviada.
- Tentar de novo **reusa o mesmo `request_id`**. É o que impede a duplicata no caso mais
  traiçoeiro: o servidor processou, a resposta se perdeu, e o atendente toca em tentar de novo.
- Pendentes aparecem **depois** das confirmadas na tela. Isso é regra de **ordem de exibição**, e
  não de reconciliação: quem decide se uma pendente sumiu é a chave, nunca a posição.
- **A ordem de limpeza do rascunho fica como está.** A primeira versão deste documento propunha
  invertê-la, e estava errada. O `replyLocked` apaga o rascunho no servidor dentro da transação de
  envio, e o código de hoje protege isso cancelando a escrita debounced e esperando a que estiver
  em voo. Sem essas duas linhas, um PUT atrasado pousa depois da exclusão e **recria** o texto já
  enviado; o atendente reabre a conversa, vê o texto, manda de novo, e como o `request_id` original
  foi limpo, sai com um novo. Mensagem duplicada no cliente.
- **O texto de uma pendente mora nela, não no rascunho.** É o que dá a recuperação sem tocar num
  mecanismo que funciona: falhou, o texto está na pendente, tentar de novo o reusa.
- **Limpar o rascunho na aceitação alcança três lugares**, não um: o `draftCache` do cliente, o
  `drafts` da conversa selecionada e a escrita debounced pendente.
- Erro de rede e recusa do WhatsApp são diferentes e a mensagem dirá qual: sem conexão é "tente de
  novo"; janela de 24 horas fechada é "use um modelo aprovado". Tentar de novo resolve o primeiro
  e nunca resolve o segundo.

## Testes

### A forma que torna o teste possível

`$state` é construção de compilador: um módulo `.svelte.ts` não pode ser importado pelo `tsx` do
pipeline atual, que não roda o compilador Svelte. Por isso a lógica vive em **TS puro**, em
`Frontend/shared/inboxStore.ts`, e as runes entram como casca fina em
`Frontend/shared/inboxStore.svelte.ts`. Os testes miram o TS puro, sem tocar no pipeline.

**A store, sem navegador.** É lógica pura sobre estruturas em memória, e é onde mora o risco:

- Enviar insere a pendente no fim e limpa o composer, sem rede.
- Aceita pelo servidor, a pendente **deixa de existir** e um item de histórico com o status que
  o servidor deu toma o lugar dela, sem buscar nada.
- Falhar mantém a pendente e a marca.
- Tentar de novo **numa resposta ou modelo** reusa o mesmo `request_id` — o teste que impede a
  duplicata. Nota não tem `request_id` e não tem idempotência; ver a seção da nota.
- **Um item de histórico com o mesmo `request_id` faz a pendente sumir**, seja qual for a origem
  daquele item. O teste entrega o item à store diretamente, sem depender de qual caminho o trouxe —
  assim ele continua válido depois que o recarregamento periódico deixar de existir.
- **Uma pendente de nota reconcilia por `kind` e `id`**, e uma resposta de nota perdida deixa uma
  pendente órfã. O teste registra o comportamento em vez de fingir que ele não acontece.
- Um `status` de `failed` ou `uncertain` numa resposta 202 **não** vira mensagem enviada.
- Dois envios seguidos na mesma conversa produzem duas pendentes com `request_id` distintos.
- Abrir conversa em cache faz zero requisição.
- Abrir conversa nova dispara as **três** chamadas — `detalhe`, `histórico` e `ia` — em paralelo.
  Prova-se com promessas adiadas que nunca resolvem: chama-se a abertura e verifica-se que **as
  três URLs já foram pedidas** antes de qualquer uma ser resolvida. Afirmar duas seria um falso
  positivo: uma implementação que dispara duas em paralelo e encadeia a terceira passaria no teste
  e perderia justamente o ganho que este desenho promete. Contar chamadas também não serve — uma
  abertura sequencial termina com o mesmo total.

**No servidor.** Teste funcional de que o `reply` devolve o item de histórico e o resumo, e de que
quem consumia os campos antigos continua recebendo.

**No aparelho.** Repetir a medição desta especificação: hoje 1,5 s para abrir e ~3 s para enviar;
depois, um quadro para ambos, com as confirmações chegando atrás.

## Os intervalos que contradizem o objetivo

Hoje o componente recarrega o histórico a cada 2 segundos e o estado de IA a cada 1,5 s. Sozinhos,
eles derrubam o objetivo 1: nenhuma conversa fica sem requisição por muito tempo.

**O recarregamento periódico do histórico deixa de existir**, e o SSE com o poll passa a ser o
gatilho único. Isso é seguro, e não é suposição: o token de versão que o SSE publica inclui a soma
dos `status` de todas as entidades que têm esse campo, incluindo `OutboundRequest` e `MetaMessage`.
Uma entrega que muda de `pending` para `failed` **move o token e dispara o poll sozinha**. Remover
o intervalo não cega a interface para uma falha de entrega — só para de perguntar a cada dois
segundos por algo que o servidor já avisa.

**O intervalo de 1,5 s do estado de IA fica onde está**, e a primeira versão deste documento
errou ao dizer que ele sairia "pelo mesmo motivo". O motivo não transfere.

O token de versão cobre seis classes: `MetaConversation`, `ConversationState`, `MetaMessage`,
`OutboundRequest`, `Note` e `EventLog`. O estado vivo do painel de IA não está em nenhuma delas —
ele mora no `AiRecord`, que guarda `id`, `kind`, `recordKey`, `data` e `revision`, **sem campo de
status**. Uma atribuição indo de ativa para gerando, uma resposta pronta esperando aprovação, uma
execução que falhou: nada disso move o token, nada dispara evento, nada gera poll.

Remover o intervalo faria o atendente **parar de ser avisado de que há uma resposta de IA
esperando por ele**. E enganaria quem testasse: algumas transições de IA movem o token por
acidente — a pausa por limite bumpa a versão da conversa e grava um evento —, então pareceria
funcionar justamente nos casos que não importam.

Fazer o token cobrir o `AiRecord` resolveria, mas aquela tabela também guarda documentos, agentes
e configuração global: uma edição de documento passaria a mover o token de **todos** os usuários.
Isso merece decisão própria, e não cabe nesta mudança.

Então esta rodada remove um intervalo, não dois. O do histórico sai; o da IA fica, com o motivo
registrado para quem for mexer nisso depois.

## Entrega

A mudança no servidor é aditiva e sobe sem risco. O frontend merece cuidado: é o caminho de dados
do inbox que a equipe usa todo dia, e ele passa a nascer da store em vez do componente. Release
própria, como foi feito com o push, mantendo a anterior intacta para a reversão continuar sendo a
troca de um symlink.
