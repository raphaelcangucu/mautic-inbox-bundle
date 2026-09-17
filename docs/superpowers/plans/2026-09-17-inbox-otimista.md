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
./bin/dev-test.sh plugins/MauticInboxBundle/Tests/Unit   # PHP
```

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
import type { TimelineItem } from "../types";

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

export interface InboxState {
  conversations: Map<number, unknown>;
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

test("uma pendente que falhou nao e removida por um item qualquer", () => {
  const falha = pendente({ state: "failed", requestId: "r1" });
  assert.deepEqual(reconcile([falha], [item({ request_id: "r1" })]), [],
    "mas SIM pelo item com a mesma chave: se o servidor registrou, a falha era do transporte");
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

```ts
export function startSend(state, { conversationId, mode, body, requestId, localId, now }): InboxState
export function acceptSend(state, { localId, item, summary }): InboxState
export function failSend(state, { localId, failure, retryable }): InboxState
export function beginRetry(state, { localId }): InboxState
```

`acceptSend` remove a pendente e insere o `item` recebido no histórico **preservando o `status` que veio**. Não existe transição para "confirmada": é remoção.

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

`ensureList(filtro)` usa o conjunto de filtros — fila, tipo, ciclo de vida, canal, busca, precisa-resposta — mais o cursor como **chave de cache**. Duas filtragens diferentes são duas entradas, não uma sobrescrevendo a outra.

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

Casca fina e sem lógica: `$state` sobre o `InboxState`, e métodos que chamam o reducer e o fetcher. A lógica fica no TS puro porque `$state` é construção de compilador e o `tsx` do pipeline de testes não compila Svelte.

- [ ] **Passo 1: Escrever a casca**
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

Na aceitação, limpar o rascunho alcança **três** lugares: `draftCache[id][mode]`, `selected.drafts[mode]` e o debounce pendente.

- [ ] **Passo 2: `npm test`**
- [ ] **Passo 3: Commit**

---

## Tarefa 8: Ligar a abertura e remover o intervalo do histórico

**Files:**
- Modify: `Frontend/inbox/InboxApp.svelte`

- [ ] **Passo 1: `select()` passa a pedir à store**

- [ ] **Passo 2: Remover o recarregamento periódico do histórico**

É seguro, e não é suposição: o token de versão que o SSE publica soma os `status` de `OutboundRequest` e `MetaMessage`. Uma entrega que muda de `pending` para `failed` move o token e dispara o poll sozinha.

**O intervalo de 1,5 s do estado de IA FICA.** O mesmo raciocínio não transfere: o `AiRecord` não tem campo de status e não está entre as seis classes do token. Removê-lo faria o atendente parar de ser avisado de que há resposta de IA esperando aprovação — e pareceria funcionar em teste, porque algumas transições movem o token por acidente.

- [ ] **Passo 3: `npm test`**
- [ ] **Passo 4: Commit**

---

## Tarefa 9: A pendente na tela, e o avião de papel

**Files:**
- Create: `Frontend/inbox/PendingBubble.svelte`
- Modify: `Frontend/inbox/Timeline.svelte`, `Frontend/inbox/Composer.svelte`, `Frontend/shared/Icon.svelte`, `Translations/pt_BR/messages.ini`, `Translations/en_US/messages.ini`
- Test: `Tests/JavaScript/pending-bubble.test.mjs`

- [ ] **Passo 1: O componente da pendente**

Componente próprio, **não** o `MessageBubble`. Reaproveitá-lo exigiria moldar a pendente no formato de `TimelineItem`, que é a confusão que o desenho inteiro existe para impedir.

Estado *enviando*: a bolha com opacidade reduzida e um relógio. Estado *falhou*: marcada, com o botão de tentar de novo e o motivo — e o motivo distingue **rede** de **recusa do canal**, porque tentar de novo resolve o primeiro e nunca resolve o segundo.

- [ ] **Passo 2: O ícone**

Em `Icon.svelte`, ao lado dos dezoito que já existem:

```ts
    send: "M3 20l18-8L3 4v6l12 2-12 2z",
```

- [ ] **Passo 3: O botão**

O rótulo de texto vira ícone. Duas exigências que não são estética:

- **Nome acessível preservado:** o rótulo de hoje vira `aria-label`, e a chave de tradução permanece. Ícone sozinho não diz nada a leitor de tela nem aparece em teste.
- **Alvo de toque de 44 pixels no mínimo.** O composer é usado com o polegar.

- [ ] **Passo 4: `npm test`**
- [ ] **Passo 5: Commit**

---

## Tarefa 10: Medir de novo, no aparelho

- [ ] **Passo 1: Publicar em release própria**

Como foi feito com o push, mantendo a anterior intacta para a reversão continuar sendo a troca de um symlink.

- [ ] **Passo 2: Repetir a medição do spec**

Antes: ~1,5 s para abrir, ~3 s para enviar. Depois: um quadro para ambos, com as confirmações chegando atrás.

- [ ] **Passo 3: Conferir o que nenhum teste pega**

Enviar uma resposta de verdade e ver a pendente virar mensagem com o status do servidor. Abrir a mesma conversa duas vezes e ver a segunda sem rede. Deixar o aparelho em modo avião, enviar, e ver a pendente marcada com o motivo certo.

- [ ] **Passo 4: Atualizar o CHANGELOG e commitar**
