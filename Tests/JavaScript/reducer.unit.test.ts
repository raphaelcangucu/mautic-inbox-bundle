import assert from "node:assert/strict";
import test from "node:test";
import {
  acceptSend,
  applyServerItems,
  beginRetry,
  failSend,
  startSend,
} from "../../Frontend/shared/store/reducer";
import type {
  InboxState,
  PendingMessage,
} from "../../Frontend/shared/store/types";
import type { TimelineItem } from "../../Frontend/shared/types";

const vazio = (): InboxState => ({
  lists: new Map(),
  conversations: new Map(),
  timelines: new Map(),
  pending: new Map(),
});

const item = (p: Partial<TimelineItem> = {}): TimelineItem => ({
  id: 10,
  kind: "outbound",
  timestamp: "2026-09-17T00:00:01Z",
  ...p,
});

const pendentes = (s: InboxState, id = 1): PendingMessage[] =>
  s.pending.get(id) ?? [];
const historico = (s: InboxState, id = 1): TimelineItem[] =>
  s.timelines.get(id)?.items ?? [];

const enviar = (
  s: InboxState,
  p: Partial<Parameters<typeof startSend>[1]> = {},
): InboxState =>
  startSend(s, {
    conversationId: 1,
    mode: "reply",
    body: "oi",
    requestId: "r1",
    localId: "l1",
    now: "2026-09-17T00:00:00Z",
    ...p,
  });

test("enviar cria uma pendente no fim, sem rede", () => {
  const antes = vazio();
  const depois = enviar(antes);

  assert.equal(pendentes(antes).length, 0, "o estado recebido nao e mutado");
  assert.deepEqual(pendentes(depois), [
    {
      localId: "l1",
      conversationId: 1,
      mode: "reply",
      body: "oi",
      createdAt: "2026-09-17T00:00:00Z",
      requestId: "r1",
      state: "sending",
    },
  ]);
  // Sem rede: nada entrou no historico, e nenhuma pendente nasce "enviada".
  assert.deepEqual(historico(depois), []);

  // "No fim": a segunda pendente vai depois da primeira.
  const comDuas = enviar(depois, {
    localId: "l2",
    requestId: "r2",
    body: "ola",
  });
  assert.deepEqual(
    pendentes(comDuas).map((p) => p.localId),
    ["l1", "l2"],
  );
});

test("cada envio tem seu proprio request_id", () => {
  const s = enviar(enviar(vazio()), {
    localId: "l2",
    requestId: "r2",
    body: "ola",
  });
  const chaves = pendentes(s).map((p) => p.requestId);

  assert.deepEqual(chaves, ["r1", "r2"]);
  assert.equal(new Set(chaves).size, 2, "duas chaves distintas");
});

test("aceito pelo servidor, a pendente deixa de existir", () => {
  const s = acceptSend(enviar(vazio()), {
    localId: "l1",
    item: item({ id: 77, request_id: "r1", status: "sent", body: "oi" }),
  });

  assert.deepEqual(pendentes(s), [], "nao existe estado 'confirmada'");
  assert.deepEqual(
    historico(s).map((i) => [i.kind, i.id, i.status]),
    [["outbound", 77, "sent"]],
  );
});

test("um status failed numa resposta aceita NAO vira mensagem enviada", () => {
  // O /reply responde 202 e o status dentro pode ser failed ou uncertain. Aceito significa
  // apenas que o nosso servidor registrou: promover isso a "enviada" faria o atendente
  // acreditar que respondeu um cliente que nao recebeu nada.
  const s = acceptSend(enviar(vazio()), {
    localId: "l1",
    item: item({
      id: 77,
      request_id: "r1",
      status: "failed",
      failure: "canal recusou",
      retryable: false,
    }),
  });

  assert.equal(historico(s).length, 1);
  assert.equal(
    historico(s)[0].status,
    "failed",
    "o status que veio e preservado",
  );
  assert.equal(historico(s)[0].failure, "canal recusou");
  assert.equal(
    historico(s).filter((i) => "sent" === i.status).length,
    0,
    "nenhum item entra como enviado",
  );
  assert.deepEqual(pendentes(s), [], "aceito e aceito: a pendente sai");
});

test("falha antes do servidor mantem a pendente com o texto dentro", () => {
  const s = failSend(enviar(vazio()), {
    localId: "l1",
    failure: "sem conexao",
    retryable: true,
  });

  assert.equal(pendentes(s).length, 1);
  assert.equal(pendentes(s)[0].state, "failed");
  assert.equal(pendentes(s)[0].body, "oi", "o texto nao se perde");
  assert.equal(pendentes(s)[0].failure, "sem conexao");
  assert.equal(pendentes(s)[0].retryable, true);
  assert.deepEqual(historico(s), [], "falhar nao escreve no historico");
});

test("tentar de novo reusa o mesmo request_id", () => {
  // O reuso e o que impede a duplicata quando o servidor processou a resposta e o
  // retorno se perdeu: a segunda chamada chega com a mesma chave.
  const falhou = failSend(enviar(vazio()), {
    localId: "l1",
    failure: "sem conexao",
    retryable: true,
  });
  const s = beginRetry(falhou, { localId: "l1" });

  assert.equal(pendentes(s).length, 1);
  assert.equal(pendentes(s)[0].state, "sending");
  assert.equal(pendentes(s)[0].requestId, "r1", "a mesma chave, nao outra");
  assert.equal(pendentes(s)[0].body, "oi");
  assert.equal(
    pendentes(s)[0].failure,
    undefined,
    "a falha antiga sai da tela",
  );
  assert.equal(
    falhou.pending.get(1)?.[0].state,
    "failed",
    "o estado recebido nao e mutado",
  );
});

test("tentar de novo uma nota gera uma nota nova, e o teste diz isso", () => {
  // /note grava uma nota a cada chamada, sem idempotencia nenhuma: nao ha requestId para
  // o servidor casar. O dano e interno — nota nao chega ao cliente —, mas esta registrado
  // aqui para ninguem supor o contrario.
  const nota = enviar(vazio(), {
    mode: "note",
    body: "obs",
    requestId: undefined,
  });
  const falhou = failSend(nota, {
    localId: "l1",
    failure: "sem conexao",
    retryable: true,
  });
  const retentando = beginRetry(falhou, { localId: "l1" });

  assert.equal(
    pendentes(retentando)[0].requestId,
    undefined,
    "uma nota nao tem chave de idempotencia para reusar",
  );

  // A primeira chamada tinha gravado a nota 42 e a resposta se perdeu; a retentativa grava
  // a 43. A pendente casa com a 43 e sai — a 42 fica no historico do mesmo jeito.
  const aceita = acceptSend(retentando, { localId: "l1", noteId: 43 });
  const s = applyServerItems(aceita, {
    conversationId: 1,
    items: [
      item({ kind: "note", id: 42, body: "obs" }),
      item({ kind: "note", id: 43, body: "obs" }),
    ],
    mode: "replace",
  });

  assert.deepEqual(pendentes(s), []);
  assert.equal(
    historico(s).filter((i) => "note" === i.kind).length,
    2,
    "duas notas: tentar de novo nao deduplica",
  );
});

test("applyServerItems e a unica porta: itens do poll removem a pendente correspondente", () => {
  const s = applyServerItems(enviar(vazio()), {
    conversationId: 1,
    items: [item({ id: 77, request_id: "r1", status: "delivered" })],
    mode: "merge",
  });

  assert.deepEqual(
    pendentes(s),
    [],
    "o poll passa pelo mesmo filtro que a resposta do envio",
  );
  assert.equal(historico(s).length, 1);
  assert.equal(historico(s)[0].status, "delivered");
});

test("applyServerItems preserva as pendentes que ainda nao tem item correspondente", () => {
  const dois = enviar(enviar(vazio()), { localId: "l2", requestId: "r2" });
  const s = applyServerItems(dois, {
    conversationId: 1,
    items: [item({ id: 77, request_id: "r1" })],
    mode: "merge",
  });

  assert.deepEqual(
    pendentes(s).map((p) => p.localId),
    ["l2"],
  );
  assert.equal(pendentes(s)[0].state, "sending");
});

test("replace substitui o que havia", () => {
  const inicial = applyServerItems(vazio(), {
    conversationId: 1,
    items: [item({ id: 1 }), item({ id: 2 })],
    mode: "replace",
    cursor: "c1",
  });
  const s = applyServerItems(inicial, {
    conversationId: 1,
    items: [item({ id: 3 })],
    mode: "replace",
    cursor: "c2",
  });

  assert.deepEqual(
    historico(s).map((i) => i.id),
    [3],
  );
  assert.equal(s.timelines.get(1)?.older, "c2");
  assert.deepEqual(
    historico(inicial).map((i) => i.id),
    [1, 2],
    "o estado recebido nao e mutado",
  );
});

test("merge junta mantendo o que ja estava", () => {
  const inicial = applyServerItems(vazio(), {
    conversationId: 1,
    items: [
      item({ id: 1, timestamp: "2026-09-17T00:00:01Z", body: "um" }),
      item({ id: 2, timestamp: "2026-09-17T00:00:02Z", body: "dois" }),
    ],
    mode: "replace",
    cursor: "c1",
  });
  const s = applyServerItems(inicial, {
    conversationId: 1,
    items: [
      item({ id: 2, timestamp: "2026-09-17T00:00:02Z", body: "dois editado" }),
      item({ id: 3, timestamp: "2026-09-17T00:00:03Z", body: "tres" }),
    ],
    mode: "merge",
  });

  assert.deepEqual(
    historico(s).map((i) => [i.id, i.body]),
    [
      [1, "um"],
      [2, "dois editado"],
      [3, "tres"],
    ],
  );
  assert.equal(s.timelines.get(1)?.older, "c1", "o poll nao mexe no cursor");
});

test("merge usa kind mais id, nunca o id sozinho", () => {
  // O poll emite os quatro tipos no mesmo vetor: o id 42 pode ser nota, envio e evento ao
  // mesmo tempo. Mesclar pelo id puro sobrescreve um tipo com outro.
  const inicial = applyServerItems(vazio(), {
    conversationId: 1,
    items: [item({ kind: "note", id: 42, body: "nota" })],
    mode: "replace",
  });
  const s = applyServerItems(inicial, {
    conversationId: 1,
    items: [item({ kind: "outbound", id: 42, body: "envio" })],
    mode: "merge",
  });

  assert.deepEqual(
    historico(s).map((i) => [i.kind, i.id]),
    [
      ["note", 42],
      ["outbound", 42],
    ],
  );
});

test("merge reordena por timestamp e empate mantem a ordem de insercao", () => {
  const inicial = applyServerItems(vazio(), {
    conversationId: 1,
    items: [item({ id: 1, timestamp: "2026-09-17T00:00:03Z" })],
    mode: "replace",
  });
  const s = applyServerItems(inicial, {
    conversationId: 1,
    items: [
      item({ id: 2, timestamp: "2026-09-17T00:00:01Z" }),
      item({ id: 3, timestamp: "2026-09-17T00:00:03Z" }),
    ],
    mode: "merge",
  });

  assert.deepEqual(
    historico(s).map((i) => i.id),
    [2, 1, 3],
  );
});

test("prepend acrescenta no comeco", () => {
  const inicial = applyServerItems(vazio(), {
    conversationId: 1,
    items: [item({ id: 5, timestamp: "2026-09-17T00:00:05Z" })],
    mode: "replace",
    cursor: "c1",
  });
  const s = applyServerItems(inicial, {
    conversationId: 1,
    items: [
      item({ id: 3, timestamp: "2026-09-17T00:00:03Z" }),
      item({ id: 4, timestamp: "2026-09-17T00:00:04Z" }),
    ],
    mode: "prepend",
    cursor: "c2",
  });

  assert.deepEqual(
    historico(s).map((i) => i.id),
    [3, 4, 5],
  );
  assert.equal(s.timelines.get(1)?.older, "c2");
});

test("aceitar uma nota guarda o noteId para a reconciliacao casar depois", () => {
  const nota = enviar(vazio(), {
    mode: "note",
    body: "obs",
    requestId: undefined,
  });
  const aceita = acceptSend(nota, { localId: "l1", noteId: 42 });

  assert.equal(
    pendentes(aceita).length,
    1,
    "a nota so sai quando o item chegar",
  );
  assert.equal(
    pendentes(aceita)[0].state,
    "sending",
    "dois estados, e so dois",
  );
  assert.equal(pendentes(aceita)[0].noteId, 42);
  assert.deepEqual(historico(aceita), [], "a resposta do /note nao traz item");

  const s = applyServerItems(aceita, {
    conversationId: 1,
    items: [item({ kind: "note", id: 42, body: "obs" })],
    mode: "merge",
  });
  assert.deepEqual(pendentes(s), []);
});
