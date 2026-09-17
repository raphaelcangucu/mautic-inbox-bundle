# Inbox Otimista Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fazer o atendimento responder no quadro seguinte ao toque — abrir conversa e enviar mensagem deixam de esperar a rede.

**Architecture:** Uma store em memória vira a fonte de verdade do cliente. A lógica mora em TypeScript puro, testável sem navegador; as runes do Svelte 5 entram como casca fina. Mensagens pendentes e confirmadas vivem em coleções separadas, com tipos separados, e reconciliam por chave — nunca por posição.

**Tech Stack:** Svelte 5, TypeScript, PHP 8.2+, `node --test` e `tsx --test`.

**Spec:** `docs/superpowers/specs/2026-09-17-inbox-otimista-design.md`. Leia-o antes da tarefa 1; ele explica *por que* cada regra existe, e três delas existem porque a alternativa entregava mensagem duplicada a um cliente real.

---

## Contexto que o implementador precisa

**O número que justifica tudo.** Medido do aparelho contra produção: cada ida ao servidor custa ~750 ms. Abrir uma conversa encadeia três (`detalhe`, depois `histórico` e `ia`) — ~1,5 s de tela parada. Enviar encadeia até quatro — ~3 s com o botão preso.

**A regra que não pode ser quebrada.** Uma mensagem pendente nunca pode se parecer com uma enviada. Por isso são coleções diferentes com tipos diferentes, e por isso a pendente tem componente próprio em vez de reusar o `MessageBubble`. Se em algum momento você se pegar moldando uma pendente no formato de `TimelineItem`, pare: esse é o caminho que faz um atendente jurar ter respondido um cliente que não recebeu nada.

**O que já existe e você vai usar:**
- `Frontend/shared/api.ts` — `jsonRequest`, `endpoint`, `requestId`, `RequestError`
- `Frontend/shared/types.ts` — `TimelineItem`, `Conversation`
- `Frontend/inbox/InboxApp.svelte` — 1022 linhas, onde a lógica de dados está hoje
- O servidor **já envia** `request_id` em item de envio (`Application/InboxQuery.php:335`); só o tipo TS não declara

**Rodar os testes:**
```bash
npm test                                   # formato, tipos, build, testes JS
export INBOX_TEST_HOST=<usuario@servidor>
export INBOX_TEST_BENCH=<release de provas>
export INBOX_TEST_DATABASE=<banco descartavel>
./bin/dev-test.sh plugins/MauticInboxBundle/Tests/Unit                       # PHP unitario
./bin/dev-test.sh plugins/MauticInboxBundle/Tests/Functional/InboxReplyResponseTest.php
```

O teste funcional exige o banco descartável provisionado na tarefa 0 de
`docs/superpowers/plans/2026-09-16-push-first-notification.md`.

**O `npm test` começa pelo `format:check`.** Os trechos deste plano não estão no formato do
Prettier — rode `npx prettier --plugin=prettier-plugin-svelte --write` no que você criar, antes de
rodar a suíte, ou o primeiro passo reprova por vírgula.

**Os arquivos de teste ficam fora do `include` do `tsconfig`**, e o `tsx` remove tipos sem checar.
Erro de tipo num auxiliar de teste **não aparece** no `npm run check`; confie no teste, não no
compilador, para essa parte.

---

## Estrutura de arquivos

| Arquivo | Responsabilidade |
|---|---|
| `Frontend/shared/store/types.ts` | `PendingMessage` e o formato do estado |
| `Frontend/shared/store/reconcile.ts` | A regra de chave: quando uma pendente deixa de existir |
| `Frontend/shared/store/reducer.ts` | Transições puras do estado, sem rede |
| `Frontend/shared/store/fetcher.ts` | As chamadas, e quais saem em paralelo |
| `Frontend/shared/inboxStore.svelte.ts` | Casca de runes: expõe o estado reativo aos componentes |
| `Frontend/inbox/PendingBubble.svelte` | Desenha uma pendente. **Nunca** desenha uma confirmada |
| `Frontend/shared/Icon.svelte` | Ganha o avião de papel |
| `Controller/InboxController.php` | `reply` passa a devolver o item criado e o resumo |

Quatro arquivos pequenos em vez de uma store única: a regra de chave é onde mora o risco, e ela merece um arquivo que caiba na cabeça de quem for revisá-la.

---

## Tarefa 1: Os tipos

**Files:**
- Create: `Frontend/shared/store/types.ts`
- Modify: `Frontend/shared/types.ts`

- [ ] **Passo 1: Declarar o `request_id` que o servidor já envia**

Em `Frontend/shared/types.ts`, dentro de `TimelineItem`:

```ts
  /** Só em itens de envio. O servidor já manda; era o tipo que não declarava. */
  request_id?: string;
```

- [ ] **Passo 2: Escrever o tipo da pendente**

`Frontend/shared/store/types.ts`:

```ts
import type { Conversation, TimelineItem } from "../types";

/**
 * Uma mensagem que o atendente mandou e o servidor ainda nao registrou.
 *
 * NAO e um TimelineItem e nao deve virar um. Sao dois tipos distintos de proposito: e o que
 * torna impossivel um componente desenhar uma pendente como se fosse mensagem enviada.
 */
export interface PendingMessage {
  /** Identificador local, so para a interface. */
  readonly localId: string;
  readonly conversationId: number;
  readonly mode: "reply" | "note";
  readonly body: string;
  readonly createdAt: string;
  /**
   * A chave de reconciliacao de uma resposta. O cliente a gera, e por isso a ordem de chegada
   * nao importa. Nota nao tem: ver noteId.
   */
  readonly requestId?: string;
  /** Preenchido quando a resposta do /note volta. Antes disso a nota nao tem chave. */
  noteId?: number;
  /** Dois estados, e so dois. Aceita pelo servidor a pendente DEIXA DE EXISTIR. */
  state: "sending" | "failed";
  /** O texto de falha que o atendente le. Rede e recusa do canal sao coisas diferentes. */
  failure?: string;
  /** Falha de rede se resolve tentando de novo; recusa do canal, nao. */
  retryable?: boolean;
}

/**
 * A lista e cacheada por FILTRO, e o cursor mora DENTRO da entrada.
 *
 * O cursor nao pode entrar na chave: paginar viraria uma entrada nova por pagina e o
 * "carregar mais" pararia de funcionar, porque a pagina 2 nao encontraria a 1.
 */
export interface ListEntry {
  readonly key: string;
  items: Conversation[];
  cursor: string | null;
}

/** O conjunto que compoe a chave. Sem cursor, de proposito. */
export interface ListFilter {
  queue: string; kind: string; lifecycle: string;
  channel: string; search: string; needsResponse: boolean;
}

/** O que o poll devolve, e que hoje o cliente joga fora. */
export interface PollResult {
  version: string;
  conversations?: Conversation[];
  timeline?: TimelineItem[];
  selectedId?: number;
}

export interface InboxState {
  lists: Map<string, ListEntry>;
  conversations: Map<number, Conversation>;
  timelines: Map<number, TimelineItem[]>;
  pending: Map<number, PendingMessage[]>;
}
```

- [ ] **Passo 3: Conferir tipos**

```bash
npm run check
```
Esperado: 0 erros.

- [ ] **Passo 4: Commit**

```bash
git add Frontend/shared/types.ts Frontend/shared/store/types.ts
git commit -m "Give a pending message a type that cannot pass for a sent one"
```

---

## Tarefa 2: A regra de chave

Este é o arquivo mais importante do plano. A regra errada aqui produz mensagem duplicada na tela do atendente.

**Files:**
- Create: `Frontend/shared/store/reconcile.ts`
- Test: `Tests/JavaScript/reconcile.unit.test.ts`

- [ ] **Passo 1: Escrever os testes que falham**

```ts
import assert from "node:assert/strict";
import test from "node:test";
import { reconcile } from "../../Frontend/shared/store/reconcile";
import type { PendingMessage } from "../../Frontend/shared/store/types";
import type { TimelineItem } from "../../Frontend/shared/types";

const pendente = (p: Partial<PendingMessage> = {}): PendingMessage => ({
  localId: "l1", conversationId: 1, mode: "reply", body: "oi",
  createdAt: "2026-09-17T00:00:00Z", requestId: "r1", state: "sending", ...p,
});
const item = (p: Partial<TimelineItem> = {}): TimelineItem => ({
  id: 10, kind: "outbound", timestamp: "2026-09-17T00:00:01Z", ...p,
});

test("um item com o mesmo request_id remove a pendente", () => {
  const restantes = reconcile([pendente()], [item({ request_id: "r1" })]);
  assert.deepEqual(restantes, []);
});

test("um item com outro request_id nao remove nada", () => {
  const restantes = reconcile([pendente()], [item({ request_id: "outro" })]);
  assert.equal(restantes.length, 1);
});

test("a origem do item nao importa", () => {
  // A regra vale para a resposta do envio, para o poll e para qualquer recarregamento.
  // Ela nao pode depender de qual caminho trouxe o item, senao deixa de valer quando
  // o recarregamento periodico for removido.
  const vindoDoPoll = [item({ id: 99, request_id: "r1" })];
  assert.deepEqual(reconcile([pendente()], vindoDoPoll), []);
});

test("uma nota reconcilia por kind e id, nao por request_id", () => {
  const nota = pendente({ mode: "note", requestId: undefined, noteId: 42 });
  const restantes = reconcile([nota], [item({ kind: "note", id: 42 })]);
  assert.deepEqual(restantes, []);
});

test("um envio e uma nota com o mesmo id nao se confundem", () => {
  // timelineItem usa o id da propria entidade, entao id 42 pode existir como nota E
  // como envio ao mesmo tempo. Sem o kind na chave, um remove a pendente do outro.
  const nota = pendente({ mode: "note", requestId: undefined, noteId: 42 });
  const restantes = reconcile([nota], [item({ kind: "outbound", id: 42, request_id: "x" })]);
  assert.equal(restantes.length, 1, "um envio nao pode reconciliar uma nota");
});

test("uma nota sem resposta ainda nao tem chave e sobrevive", () => {
  // Resposta do /note perdida: a nota real chega, mas a pendente nao tem noteId para casar.
  // Fica orfa. E o comportamento, nao um defeito — esta registrado no spec.
  const semChave = pendente({ mode: "note", requestId: undefined, noteId: undefined });
  const restantes = reconcile([semChave], [item({ kind: "note", id: 42 })]);
  assert.equal(restantes.length, 1);
});

test("uma pendente que falhou E removida pelo item com a mesma chave", () => {
  const falha = pendente({ state: "failed", requestId: "r1" });
  // Se o servidor registrou, a falha era do transporte: a mensagem existe e a pendente sai.
  assert.deepEqual(reconcile([falha], [item({ request_id: "r1" })]), []);
});
```

- [ ] **Passo 2: Rodar e ver falhar**

```bash
npx tsx --test Tests/JavaScript/reconcile.unit.test.ts
```
Esperado: FAIL, módulo não encontrado.

- [ ] **Passo 3: Implementar**

```ts
import type { PendingMessage } from "./types";
import type { TimelineItem } from "../types";

/**
 * Devolve as pendentes que AINDA nao foram registradas pelo servidor.
 *
 * A chave, nunca a posicao. Uma resposta enviada e, ela propria, um item de historico: ela volta
 * pela resposta do envio e volta de novo no proximo poll. Sem chave, o item confirmado chega
 * enquanto a pendente ainda esta na tela e a mesma mensagem aparece duas vezes, uma delas
 * dizendo "enviando".
 *
 * A funcao e pura e nao sabe de onde os itens vieram — e o que a mantem valida quando o
 * recarregamento periodico for removido.
 */
export function reconcile(
  pending: readonly PendingMessage[],
  items: readonly TimelineItem[],
): PendingMessage[] {
  const enviados = new Set<string>();
  const notas = new Set<number>();

  for (const item of items) {
    if ("note" === item.kind) {
      notas.add(item.id);
    } else if (item.request_id) {
      enviados.add(item.request_id);
    }
  }

  return pending.filter((p) => {
    // Nota: a chave so existe depois que a resposta do /note volta. Sem noteId, nao ha como
    // casar, e a pendente fica orfa. Assimetria conhecida e registrada no spec.
    if ("note" === p.mode) {
      return undefined === p.noteId || !notas.has(p.noteId);
    }

    return undefined === p.requestId || !enviados.has(p.requestId);
  });
}
```

- [ ] **Passo 4: Rodar e ver passar**

Esperado: os sete testes verdes.

- [ ] **Passo 5: Commit**

```bash
git add Frontend/shared/store/reconcile.ts Tests/JavaScript/reconcile.unit.test.ts
git commit -m "Reconcile pendings by key, never by position"
```

---

## Tarefa 3: As transições do envio

**Files:**
- Create: `Frontend/shared/store/reducer.ts`
- Test: `Tests/JavaScript/reducer.unit.test.ts`

- [ ] **Passo 1: Escrever os testes que falham**

```ts
test("enviar cria uma pendente no fim, sem rede", ...)
test("cada envio tem seu proprio request_id", ...)         // dois envios seguidos
test("aceito pelo servidor, a pendente deixa de existir", ...)
test("um status failed numa resposta aceita NAO vira mensagem enviada", ...)
test("falha antes do servidor mantem a pendente com o texto dentro", ...)
test("tentar de novo reusa o mesmo request_id", ...)
test("tentar de novo uma nota gera uma nota nova, e o teste diz isso", ...)
```

O quarto merece atenção: o `reply` responde **202** e o `status` pode vir `failed` ou `uncertain` — são nove estados possíveis. O teste entrega uma resposta 202 com `status: "failed"` e verifica que o item entra no histórico **com esse status**, e não como enviado.

O sétimo registra uma verdade desconfortável: `/note` grava uma nota nova a cada chamada, sem idempotência nenhuma. Tentar de novo cria uma segunda nota. O dano é interno, mas o teste existe para ninguém supor o contrário.

- [ ] **Passo 2: Rodar e ver falhar**

- [ ] **Passo 3: Implementar**

Funções puras, sem `fetch`, sem `Date.now()` direto — tempo e identificador entram por parâmetro, para o teste ser determinístico:

Assinaturas **com tipos explícitos** — o `tsconfig` está em `strict`, e parâmetro implícito reprova
no `npm run check`:

```ts
import type { InboxState, PendingMessage } from "./types";
import type { Conversation, TimelineItem } from "../types";

export function startSend(state: InboxState, acao: {
  conversationId: number; mode: "reply" | "note"; body: string;
  requestId?: string; localId: string; now: string;
}): InboxState

export function acceptSend(state: InboxState, acao: {
  localId: string; item?: TimelineItem; noteId?: number; summary?: Conversation;
}): InboxState

export function failSend(state: InboxState, acao: {
  localId: string; failure: string; retryable: boolean;
}): InboxState

export function beginRetry(state: InboxState, acao: { localId: string }): InboxState

/**
 * A porta por onde TODO historico vindo do servidor entra — resposta de envio, poll,
 * carregamento inicial, qualquer um. E o unico lugar que chama reconcile().
 */
export function applyServerItems(state: InboxState, acao: {
  conversationId: number; items: TimelineItem[];
}): InboxState
```

`acceptSend` remove a pendente e insere o `item` recebido no histórico **preservando o `status` que
veio**. Não existe transição para "confirmada": é remoção.

### Onde `reconcile` é chamado

Em `applyServerItems`, e **em nenhum outro lugar**. Essa é a razão de ela existir: toda vez que uma
lista de itens do servidor entra no estado, as pendentes daquela conversa passam pelo filtro.

Sem essa porta única, a função da tarefa 2 vira código morto e o caminho do poll reproduz
exatamente a duplicata que o desenho existe para impedir — porque o poll atribui o histórico
direto, sem olhar para as pendentes.

Acrescente aos testes desta tarefa:

```ts
test("applyServerItems e a unica porta: itens do poll removem a pendente correspondente", ...)
test("applyServerItems preserva as pendentes que ainda nao tem item correspondente", ...)
```

- [ ] **Passo 4: Rodar e ver passar**

- [ ] **Passo 5: Commit**

```bash
git commit -m "Move a send through two states and no third"
```

---

## Tarefa 4: As chamadas, e o que sai junto

**Files:**
- Create: `Frontend/shared/store/fetcher.ts`
- Test: `Tests/JavaScript/fetcher.unit.test.ts`

- [ ] **Passo 1: Escrever o teste que falha**

O teste do paralelo é o que trava a regressão mais provável, e precisa ser escrito com cuidado:

```ts
test("abrir uma conversa dispara as TRES chamadas antes de qualquer resposta", async () => {
  // Promessas adiadas que nunca resolvem. Se a implementacao encadear, a segunda e a
  // terceira URL nunca chegam a ser pedidas e o teste falha.
  const pedidas: string[] = [];
  const adiada = () => new Promise<never>(() => {});
  const fetcher = criar({ buscar: (url) => { pedidas.push(url); return adiada(); } });

  void fetcher.abrirConversa(481);
  await Promise.resolve();   // deixa o microtask rodar, sem resolver nada

  assert.equal(pedidas.length, 3, "detalhe, historico e ia saem juntos");
  assert.ok(pedidas.some((u) => u.includes("/481")));
});

test("abrir uma conversa ja em cache nao pede nada", ...)
test("a lista e cacheada por filtro, nao globalmente", ...)
test("abertura fria sem resumo na lista tambem dispara as tres", ...)
test("404 no detalhe nao deixa esqueleto girando e nao entra no cache", ...)
```

**Contar chamadas não basta** e o teste não deve fazê-lo: uma abertura sequencial também termina com três. O que prova o paralelo é as três já terem sido **pedidas** antes de qualquer uma resolver.

Afirmar **duas** seria um falso positivo: uma implementação que dispara `detalhe` e `histórico` juntos e encadeia `ia` passaria, e perderia o ganho que este plano promete.

- [ ] **Passo 2: Rodar e ver falhar**

- [ ] **Passo 3: Implementar**

`abrirConversa(id)` dispara `detalhe`, `histórico` e `ia` **juntos**. A conversa renderiza sem esperar o `ia`; o painel de IA preenche quando chegar. O POST de leitura continua saindo sem espera, fora do caminho crítico.

`ensureList(filtro)` usa **só o conjunto de filtros** — fila, tipo, ciclo de vida, canal, busca,
precisa-resposta — como chave. O cursor **não entra na chave**: ele vive dentro da entrada, porque
paginar criaria uma entrada nova por página e o "carregar mais" deixaria de encontrar a anterior.

Numa abertura fria, `detalhe` pode responder 404 ou 403: o esqueleto dá lugar à mensagem de erro que o componente já mostra, nada entra no cache, e a conversa **não** é inserida na lista em cache.

- [ ] **Passo 4: Rodar e ver passar**

- [ ] **Passo 5: Commit**

```bash
git commit -m "Send the three opening calls together"
```

---

## Tarefa 5: A casca de runes

**Files:**
- Create: `Frontend/shared/inboxStore.svelte.ts`

Este é o módulo que **todo componente vai consumir**, então ele precisa estar escrito aqui e não
ser inventado na hora. Casca fina e sem lógica: `$state` sobre o `InboxState`, e métodos que
delegam ao reducer e ao fetcher.

- [ ] **Passo 1: Escrever a casca, com esta superfície e nenhuma outra**

```ts
export function criarInboxStore(deps: { csrf: string; urls: Record<string, string> }) {
  let estado = $state<InboxState>(vazio());

  return {
    get lists() { return estado.lists; },
    get conversations() { return estado.conversations; },
    get timelines() { return estado.timelines; },
    get pending() { return estado.pending; },

    ensureList(filtro: ListFilter): Promise<void>,
    ensureConversation(id: number): Promise<void>,
    ensureTimeline(id: number): Promise<void>,
    sendReply(id: number, mode: "reply" | "note", body: string): Promise<void>,
    retryPending(localId: string): Promise<void>,
    applyPoll(resultado: PollResult): void,
  };
}
```

O bloco acima é um **contrato**, não TypeScript compilável — declare as assinaturas na forma que o
Svelte aceitar. `ListFilter` e `PollResult` vêm da tarefa 1.

Seis métodos, exatamente os que o spec nomeia. `applyPoll` desmonta o resultado do poll e chama
`applyServerItems` por conversa — é assim que o caminho do poll passa a respeitar as pendentes.

### Duas idas a menos por tique, de graça

O `poll()` do servidor **já devolve** `conversations` — os resumos que mudaram — e `timeline` — os
itens novos da conversa selecionada. O cliente de hoje **joga os dois fora** e busca tudo de novo
com `loadList` e `refreshSelected`: duas idas extras a cada tique.

`applyPoll` passa a consumir o que já veio. Com o SSE disparando um poll por mudança, isso é a
diferença entre três idas e uma, em cada atualização.

**Uma ressalva que muda o código:** o `timeline` do poll é **incremental** e só da conversa
selecionada. Então `applyServerItems` precisa **mesclar** por id, e não substituir, quando a origem
for o poll. Um teste para isso:

```ts
test("itens incrementais do poll mesclam, nao substituem o historico", ...)
```

A lógica fica no TS puro porque `$state` é construção de compilador e o `tsx` do pipeline de testes
não compila Svelte. **Se você se pegar escrevendo uma condição aqui, ela pertence ao reducer.**

- [ ] **Passo 2: `npm run check` — 0 erros**
- [ ] **Passo 3: Commit**

---

## Tarefa 6: O servidor devolve o que criou

**Files:**
- Modify: `Controller/InboxController.php`
- Test: `Tests/Functional/InboxReplyResponseTest.php`

- [ ] **Passo 1: Escrever o teste que falha**

```php
public function testReplyReturnsTheCreatedTimelineItem(): void
public function testReplyReturnsTheUpdatedConversationSummary(): void
public function testReplyStillReturnsTheFieldsItAlwaysReturned(): void
```

O terceiro é o que torna a mudança aditiva: quem consome `request_id` e `status` hoje continua recebendo.

- [ ] **Passo 2: Rodar e ver falhar**

Depende do banco descartável; ver o plano da fase 2 do push.

- [ ] **Passo 3: Implementar**

O `reply` hoje devolve `['request_id' => ..., 'status' => ...]`. Passa a devolver também `item` — o item de histórico do envio criado, na mesma forma que o `InboxQuery` produz — e `summary`, o resumo atualizado da conversa. Sem isso o envio custa duas idas extras e a lista fica velha.

- [ ] **Passo 4: Rodar e ver passar**

- [ ] **Passo 5: Commit**

---

## Tarefa 7: Ligar o envio

**Files:**
- Modify: `Frontend/inbox/InboxApp.svelte`

- [ ] **Passo 1: Trocar o corpo do `send()`**

A **ordem importa e é carregada de significado**. O código de hoje cancela a escrita debounced do rascunho e espera a que estiver em voo **antes** de chamar o `reply`, porque o servidor apaga o rascunho dentro da transação de envio. Um PUT atrasado pousando depois recria o texto já enviado — e o atendente manda de novo, com identificador novo, duplicando no cliente.

Então: insere a pendente e limpa o composer **primeiro** (é o ganho visual), e depois mantém a sequência existente — cancelar debounce, esperar o PUT em voo, `take` se necessário, `reply`.

Na aceitação, limpar o rascunho alcança **três** lugares: `draftCache[id][mode]`,
`selected.drafts[mode]` e o debounce pendente.

- [ ] **Passo 2: Tratar o `take` que falha**

A linha de hoje é `if (!isNote && selected.can_take_and_reply && !(await take())) return;` — um
`return` **dentro do `try`**, então o `catch` nunca roda. Com a pendente já inserida e o composer
já limpo, esse caminho deixaria uma pendente presa em *enviando* para sempre: sem botão de tentar
de novo, que exige o estado *falhou*, e sem o texto no composer.

Troque o `return` por `failSend` com o motivo da recusa. Teste:

```ts
test("take recusado marca a pendente como falha, com o texto dentro dela", ...)
```

**E cuidado com a corrida que a própria mudança cria.** O `take()` envia a `version` lida no
momento da chamada. Sem a trava de envio, dois envios disparados dentro da mesma ida de 750 ms leem
a **mesma** versão: o primeiro a incrementa, o segundo leva 409 — e, com o passo acima, marcaria a
segunda pendente como falha **embora a conversa tenha sido tomada com sucesso**.

A janela é estreita, mas envios em sequência rápida são justamente a premissa desta mudança. A
solução é **um `take` em voo por conversa**: o segundo envio espera o primeiro terminar em vez de
disparar o seu. Teste:

```ts
test("dois envios numa conversa nao assumida disparam um unico take", ...)
```

- [ ] **Passo 3: Soltar a trava de envio**

A premissa do desenho inteiro é que o botão nunca mais fica preso — é o que permite o ícone da
tarefa 9 e o que o spec chama de envio concorrente. Três coisas precisam sair juntas:

- A guarda `if (... || sending || ...)` no início do `send()`
- O `disabled={disabled || ...}` do `Composer.svelte`, que computa a partir de `sending`
- **O rótulo do botão**, que hoje lê `{sending ? ... : ...}` no `Composer.svelte`, e o `{sending}`
  passado pelo `InboxApp.svelte`
- O `sendAttempts[key]`, que é **um slot único por conversa e modo** — o `request_id` passa a
  morar em cada pendente

O rótulo entra **nesta tarefa**, não na 9. Remover a prop aqui e trocar o rótulo lá deixaria o
`npm run check` reprovando no meio, entre duas tarefas — e o passo de teste desta tarefa falharia
por uma mudança que ainda não aconteceu. A tarefa 9 cuida do ícone e do alvo de toque; o texto sai
junto com a trava que o alimentava.

`sendTemplate()` compartilha a mesma flag `sending`, então isto não é uma deleção de uma linha.

- [ ] **Passo 4: Decidir o envio por modelo, explicitamente**

`sendTemplate()` passa o próprio `request_id` e chama `loadTimeline` direto. Ele **não** ganha o
caminho otimista nesta rodada — modelo tem confirmação e pré-visualização, e o ganho de percepção
ali é pequeno. Mas ele **passa a entregar seu histórico por `applyServerItems`**, senão reintroduz
a duplicata por outro caminho.

- [ ] **Passo 5: `npm test`**
- [ ] **Passo 6: Commit**

---

## Tarefa 8: Ligar a abertura e remover o intervalo do histórico

**Files:**
- Modify: `Frontend/inbox/InboxApp.svelte`

- [ ] **Passo 1: Todos os caminhos que escrevem o histórico passam pela store**

Não é só o `select()`. **Sete** lugares escrevem `timeline` hoje, e deixar cinco de fora faz o
cache da store divergir do que está na tela:

| Caminho | O que faz hoje |
|---|---|
| `select()` | abre a conversa |
| `refreshSelected()` | o que o poll chama |
| `loadOlder()` | carrega mensagens anteriores |
| `sendTemplate()` | recarrega depois do modelo |
| `retryOutbound()` | recarrega depois da retentativa |
| `mutate()` | soneca, resolver, reabrir |
| `clearSelection()` | esvazia ao sair da conversa |

Todos passam a entregar itens por `applyServerItems`. O `loadOlder` **acrescenta** em vez de
substituir, e o `clearSelection` não apaga o cache — só deixa de renderizar.

- [ ] **Passo 2: A conversa selecionada também precisa voltar para a store**

Esta é a **mesma armadilha do histórico, em outro lugar**, e é fácil não ver. Sete caminhos
escrevem `selected`, e dois deles produzem um sintoma concreto e feio:

| Caminho | Sintoma se não voltar à store |
|---|---|
| `take()` | substitui `selected` com o detalhe pós-tomada; o cache guarda o **anterior** |
| `mutate()` | idem, depois de soneca ou resolução |
| `refreshSelected()`, `select()`, `loadAi()`, `draftInput()`, `clearSelection()` | cache envelhece em silêncio |

O `take()` é o pior: o cache fica com a `version` antiga da conversa. Reabrir do cache — que é o
objetivo 1, zero requisição — renderiza `assignee` e `can_reply` velhos, e **`version` velha é o
que produz 409 na próxima tomada ou transição**. O ganho de velocidade viraria uma fonte de erro.

Todos escrevem em `conversations` pela store. `rows` idem, pela entrada de lista do filtro atual.

- [ ] **Passo 3: Remover o recarregamento periódico do histórico**

É seguro, e não é suposição: o token de versão que o SSE publica soma os `status` de `OutboundRequest` e `MetaMessage`. Uma entrega que muda de `pending` para `failed` move o token e dispara o poll sozinha.

**O intervalo de 1,5 s do estado de IA FICA.** O mesmo raciocínio não transfere: o `AiRecord` não tem campo de status e não está entre as seis classes do token. Removê-lo faria o atendente parar de ser avisado de que há resposta de IA esperando aprovação — e pareceria funcionar em teste, porque algumas transições movem o token por acidente.

- [ ] **Passo 4: `npm test`**
- [ ] **Passo 5: Commit**

---

## Tarefa 9: A pendente na tela, e o avião de papel

**Files:**
- Create: `Frontend/inbox/PendingBubble.svelte`
- Modify: `Frontend/inbox/Timeline.svelte`, `Frontend/inbox/Composer.svelte`, `Frontend/shared/Icon.svelte`, `Assets/css/inbox.css`, `Translations/pt_BR/messages.ini`, `Translations/en_US/messages.ini`
- Test: `Tests/JavaScript/pending-bubble.test.mjs`

**O estilo vai no `Assets/css/inbox.css`.** Não existe um único bloco `<style>` sob `Frontend/` —
todo o visual do plugin mora naquele arquivo. Cuidado: ele está commitado minificado numa linha só,
então acrescente ao fim e não reformate o que já está lá.

**`Timeline.svelte` já tem uma prop chamada `pending`** — é a resposta pendente da IA. Use outro
nome para a nova, `pendingMessages`, ou o componente quebra de um jeito confuso.

- [ ] **Passo 1: O componente da pendente**

Componente próprio, **não** o `MessageBubble`. Reaproveitá-lo exigiria moldar a pendente no formato de `TimelineItem`, que é a confusão que o desenho inteiro existe para impedir.

Estado *enviando*: a bolha com opacidade reduzida e um relógio. Estado *falhou*: marcada, com o botão de tentar de novo e o motivo — e o motivo distingue **rede** de **recusa do canal**, porque tentar de novo resolve o primeiro e nunca resolve o segundo.

- [ ] **Passo 2: Rolar até a mensagem nova**

O `afterUpdate` do `Timeline.svelte` rola para o fim observando `items.length`. Uma pendente vive
em **outra coleção**, então ela não dispara a rolagem — e a bolha otimista pode nascer abaixo da
dobra, que derruba justamente o ganho que este plano promete. A condição passa a observar as duas.

- [ ] **Passo 3: O ícone**

Em `Icon.svelte`, ao lado dos dezoito que já existem. Note que `.inbox-app svg` usa
`fill: none; stroke: currentColor`, então o desenho é **contornado**, não sólido — o traçado abaixo
já assume isso:

```ts
    send: "M4 12l16-8-6 8 6 8-16-8z M4 12h10",
```

Confira no aparelho: um avião contornado a 18 pixels precisa de traço legível, e é o tipo de coisa
que só a tela mostra.

- [ ] **Passo 4: O botão**

O rótulo de texto vira ícone. Duas exigências que não são estética:

- **Nome acessível preservado:** o rótulo de hoje vira `aria-label`, e a chave de tradução permanece. Ícone sozinho não diz nada a leitor de tela nem aparece em teste.
- **Alvo de toque de 44 pixels no mínimo.** O composer é usado com o polegar.

- [ ] **Passo 5: `npm test`**
- [ ] **Passo 6: Commit**

---

## Tarefa 10: Medir de novo, no aparelho

- [ ] **Passo 1: Publicar em release própria**

Como foi feito com o push, mantendo a anterior intacta para a reversão continuar sendo a troca de um symlink.

- [ ] **Passo 2: Repetir a medição do spec**

Antes: ~1,5 s para abrir, ~3 s para enviar. Depois: um quadro para ambos, com as confirmações chegando atrás.

- [ ] **Passo 3: Conferir o que nenhum teste pega**

Enviar uma resposta de verdade e ver a pendente virar mensagem com o status do servidor. Abrir a mesma conversa duas vezes e ver a segunda sem rede. Deixar o aparelho em modo avião, enviar, e ver a pendente marcada com o motivo certo.

- [ ] **Passo 4: Atualizar o CHANGELOG e commitar**
