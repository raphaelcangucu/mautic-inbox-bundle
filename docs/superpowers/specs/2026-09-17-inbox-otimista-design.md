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

- **Abrir uma conversa** faz `detalhe` e depois `histórico`, em sequência: ~1,5 s de tela parada.
- **Enviar uma mensagem** faz `take` (quando a conversa não é sua), depois `reply`, depois
  `histórico`, depois `lista`: até quatro idas, ~3 s com o botão preso em "enviando".

Um aplicativo de mensagens mostra o que a pessoa escreveu no quadro seguinte ao toque e resolve
o resto depois. É essa a diferença que a equipe sente.

## Objetivos

1. Abrir conversa já visitada sem nenhuma requisição.
2. Abrir conversa nova com resposta visual imediata e as duas chamadas em paralelo.
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
| Conflito entre envio e SSE | Reconciliação por `request_id`, não por posição | O histórico é recarregado a cada 2 s e a resposta enviada **é** um item de histórico; sem chave, a mesma mensagem aparece duas vezes |
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
esqueleto também no cabeçalho, e `detalhe` e `histórico` saem em paralelo como no caminho comum.

## Os dois fluxos

### Abrir uma conversa

1. No toque, a store já tem o resumo daquela conversa, porque veio na lista. Cabeçalho e moldura
   do chat aparecem no mesmo quadro, sem rede.
2. Se o histórico está em `timelines`, renderiza inteiro. Zero requisição.
3. Se não está, mostra esqueleto no lugar das mensagens e dispara `detalhe` e `histórico`
   **em paralelo**. ~780 ms em vez de 1540.

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

O histórico é recarregado a cada 2 segundos e a cada poll, e **uma resposta enviada é um item de
histórico**. Sem chave, o item confirmado chega enquanto a pendente ainda está na tela e a mesma
mensagem aparece duas vezes, uma delas dizendo "enviando".

O item de histórico já carrega `request_id`. A regra é uma só: **quando um item de histórico com o
mesmo `request_id` de uma pendente aparece, a pendente some** — venha ele da resposta do `reply`,
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

### Modo nota e modelo

`sendReply` cobre resposta e nota, mas elas confirmam diferente: a nota não tem `request_id` e o
endpoint devolve `{id, saved}`, sem item de histórico. Envio por modelo passa pelo mesmo `reply` e
recebe a nova resposta. As três variações precisam estar no plano.

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

- Uma pendente tem três estados e só três: *enviando*, *confirmada*, *falhou*. Não existe estado
  ambíguo, e é isso que permite a interface ser honesta sem precisar de julgamento.
- Tentar de novo **reusa o mesmo `request_id`**. É o que impede a duplicata no caso mais
  traiçoeiro: o servidor processou, a resposta se perdeu, e o atendente toca em tentar de novo.
- Atualização do SSE durante um envio: servidor ganha nas confirmadas, pendentes seguem no fim.
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
- Confirmar tira a pendente e põe a confirmada, sem buscar nada.
- Falhar mantém a pendente e a marca.
- Tentar de novo reusa o mesmo `request_id` — o teste que impede a duplicata.
- **Um item de histórico com o mesmo `request_id` faz a pendente sumir**, venha ele da resposta do
  envio, de um poll ou do recarregamento periódico. Este é o teste da duplicata na tela.
- Um `status` de `failed` ou `uncertain` numa resposta 202 **não** vira mensagem enviada.
- Dois envios seguidos na mesma conversa produzem duas pendentes com `request_id` distintos.
- Abrir conversa em cache faz zero requisição.
- Abrir conversa nova dispara as duas chamadas **em paralelo**. Prova-se com promessas adiadas que
  nunca resolvem: chama-se a abertura e verifica-se que **as duas URLs já foram pedidas** antes de
  qualquer uma ser resolvida. Contar chamadas não serve — uma abertura sequencial também termina
  com duas. É o teste que trava a regressão mais provável, porque reencadear é o caminho natural
  de quem mexer nisso depois.

**No servidor.** Teste funcional de que o `reply` devolve o item de histórico e o resumo, e de que
quem consumia os campos antigos continua recebendo.

**No aparelho.** Repetir a medição desta especificação: hoje 1,5 s para abrir e ~3 s para enviar;
depois, um quadro para ambos, com as confirmações chegando atrás.

## Os intervalos que contradizem o objetivo

Hoje o componente recarrega o histórico a cada 2 segundos e o estado de IA a cada 1,5 s. Sozinhos,
eles derrubam o objetivo 1: nenhuma conversa fica sem requisição por muito tempo.

O plano precisa decidir explicitamente o que fazer com eles. A direção proposta é que o
recarregamento periódico do histórico deixe de existir e o SSE com o poll passe a ser o único
gatilho de atualização — o que também devolve bateria e dados ao aparelho da equipe. Se algum
comportamento depender daquele intervalo, isso tem que aparecer antes de removê-lo.

## Entrega

A mudança no servidor é aditiva e sobe sem risco. O frontend merece cuidado: é o caminho de dados
do inbox que a equipe usa todo dia, e ele passa a nascer da store em vez do componente. Release
própria, como foi feito com o push, mantendo a anterior intacta para a reversão continuar sendo a
troca de um symlink.
