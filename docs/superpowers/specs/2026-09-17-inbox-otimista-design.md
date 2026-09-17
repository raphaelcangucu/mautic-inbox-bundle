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
| Conflito entre envio e SSE | Servidor ganha nas confirmadas; pendentes sempre no fim | Mensagem em trânsito não pode ser sobrescrita nem duplicada |

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
ensureList()                  // lista, do cache ou da rede
ensureConversation(id)        // detalhe, do cache ou da rede
ensureTimeline(id)            // historico, do cache ou da rede
sendReply(id, modo, texto)    // otimista: aparece agora, confirma depois
retryPending(pendingId)       // tenta de novo a que falhou
applyServerEvent(evento)      // o que o SSE traz entra por aqui
```

Seis funções. Uma sétima que não seja sobre dado de conversa é sinal de que a store está virando
outra coisa.

O que ela não faz: não persiste, não desenha, não conhece rota.

## Os dois fluxos

### Abrir uma conversa

1. No toque, a store já tem o resumo daquela conversa, porque veio na lista. Cabeçalho e moldura
   do chat aparecem no mesmo quadro, sem rede.
2. Se o histórico está em `timelines`, renderiza inteiro. Zero requisição.
3. Se não está, mostra esqueleto no lugar das mensagens e dispara `detalhe` e `histórico`
   **em paralelo**. ~780 ms em vez de 1540.

### Enviar uma mensagem

1. No toque, a store cria uma pendente com id de cliente, põe no fim da conversa e limpa o
   composer. A tela atualiza num quadro.
2. Em segundo plano: `take` se necessário, depois `reply`. A idempotência por `request_id` que já
   existe continua igual.
3. Na confirmação, a pendente sai e a confirmada entra — vinda da própria resposta.
4. Na falha, a pendente fica marcada, com botão de tentar de novo.

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
- **O rascunho passa a ser limpo na confirmação, não antes do envio.** Hoje o `send()` limpa
  antes. Com cache em memória, app encerrado no meio de um envio que falhou perderia o texto
  escrito; limpando só na confirmação, o atendente reabre e o texto está lá, vindo do rascunho que
  já é salvo no servidor. Uma linha de mudança que troca "perdi o que escrevi" por "está como eu
  deixei".
- Erro de rede e recusa do WhatsApp são diferentes e a mensagem dirá qual: sem conexão é "tente de
  novo"; janela de 24 horas fechada é "use um modelo aprovado". Tentar de novo resolve o primeiro
  e nunca resolve o segundo.

## Testes

**A store, sem navegador.** É lógica pura sobre estruturas em memória, e é onde mora o risco:

- Enviar insere a pendente no fim e limpa o composer, sem rede.
- Confirmar tira a pendente e põe a confirmada, sem buscar nada.
- Falhar mantém a pendente e a marca.
- Tentar de novo reusa o mesmo `request_id` — o teste que impede a duplicata.
- Atualização do SSE no meio de um envio não sobrescreve nem duplica a pendente.
- Abrir conversa em cache faz zero requisição.
- Abrir conversa nova dispara as duas chamadas **em paralelo**, não em sequência. Prova-se
  contando chamadas e verificando que a segunda não espera a primeira; é o teste que trava a
  regressão mais provável, porque reencadear é o caminho natural de quem mexer nisso depois.

**No servidor.** Teste funcional de que o `reply` devolve o item de histórico e o resumo, e de que
quem consumia os campos antigos continua recebendo.

**No aparelho.** Repetir a medição desta especificação: hoje 1,5 s para abrir e ~3 s para enviar;
depois, um quadro para ambos, com as confirmações chegando atrás.

## Entrega

A mudança no servidor é aditiva e sobe sem risco. O frontend merece cuidado: é o caminho de dados
do inbox que a equipe usa todo dia, e ele passa a nascer da store em vez do componente. Release
própria, como foi feito com o push, mantendo a anterior intacta para a reversão continuar sendo a
troca de um symlink.
