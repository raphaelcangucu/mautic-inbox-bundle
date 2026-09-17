import assert from "node:assert/strict";
import test from "node:test";
import { reconcile } from "../../Frontend/shared/store/reconcile";
import type { PendingMessage } from "../../Frontend/shared/store/types";
import type { TimelineItem } from "../../Frontend/shared/types";

const pendente = (p: Partial<PendingMessage> = {}): PendingMessage => ({
  localId: "l1",
  conversationId: 1,
  mode: "reply",
  body: "oi",
  createdAt: "2026-09-17T00:00:00Z",
  requestId: "r1",
  state: "sending",
  ...p,
});

const item = (p: Partial<TimelineItem> = {}): TimelineItem => ({
  id: 10,
  kind: "outbound",
  timestamp: "2026-09-17T00:00:01Z",
  ...p,
});

test("um item com o mesmo request_id remove a pendente", () => {
  assert.deepEqual(reconcile([pendente()], [item({ request_id: "r1" })]), []);
});

test("um item com outro request_id nao remove nada", () => {
  assert.equal(
    reconcile([pendente()], [item({ request_id: "outro" })]).length,
    1,
  );
});

test("a origem do item nao importa", () => {
  // A regra vale para a resposta do envio, para o poll e para qualquer recarregamento.
  // Ela nao pode depender de qual caminho trouxe o item, senao deixa de valer quando
  // o recarregamento periodico for removido.
  assert.deepEqual(
    reconcile([pendente()], [item({ id: 99, request_id: "r1" })]),
    [],
  );
});

test("uma nota reconcilia por kind e id, nao por request_id", () => {
  const nota = pendente({ mode: "note", requestId: undefined, noteId: 42 });
  assert.deepEqual(reconcile([nota], [item({ kind: "note", id: 42 })]), []);
});

test("um envio e uma nota com o mesmo id nao se confundem", () => {
  // timelineItem usa o id da propria entidade, entao id 42 pode existir como nota E
  // como envio ao mesmo tempo. Sem o kind na chave, um remove a pendente do outro.
  const nota = pendente({ mode: "note", requestId: undefined, noteId: 42 });
  const restantes = reconcile(
    [nota],
    [item({ kind: "outbound", id: 42, request_id: "x" })],
  );
  assert.equal(restantes.length, 1, "um envio nao pode reconciliar uma nota");
});

test("uma nota sem resposta ainda nao tem chave e sobrevive", () => {
  // Resposta do /note perdida: a nota real chega, mas a pendente nao tem noteId para casar.
  // Fica orfa. E o comportamento, nao um defeito — esta registrado no spec.
  const semChave = pendente({
    mode: "note",
    requestId: undefined,
    noteId: undefined,
  });
  assert.equal(
    reconcile([semChave], [item({ kind: "note", id: 42 })]).length,
    1,
  );
});

test("uma pendente que falhou E removida pelo item com a mesma chave", () => {
  // Se o servidor registrou, a falha era do transporte: a mensagem existe e a pendente sai.
  const falha = pendente({ state: "failed", requestId: "r1" });
  assert.deepEqual(reconcile([falha], [item({ request_id: "r1" })]), []);
});
