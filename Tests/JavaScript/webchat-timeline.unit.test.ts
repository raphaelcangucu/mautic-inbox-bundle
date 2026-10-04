import assert from "node:assert/strict";
import test from "node:test";
import { webchatTimelineItem } from "../../Frontend/inbox/webchatTimeline";

test("maps a live visitor message to the durable Inbox timeline key", () => {
  assert.deepEqual(
    webchatTimelineItem({
      id: 3,
      inbox_message_id: 300,
      direction: "visitor",
      body: "Mensagem ao vivo",
      status: "sent",
      author: "Visitante",
      timestamp: "2026-10-04T16:32:24Z",
    }),
    {
      id: 300,
      kind: "message",
      direction: "inbound",
      status: "sent",
      body: "Mensagem ao vivo",
      author: "Visitante",
      timestamp: "2026-10-04T16:32:24Z",
    },
  );
});

test("maps agent and AI events to their canonical Inbox entities", () => {
  assert.equal(
    webchatTimelineItem({
      direction: "agent",
      outbound_request_id: 29,
      body: "Resposta",
    })?.kind,
    "outbound",
  );
  const ai = webchatTimelineItem({
    direction: "ai",
    inbox_message_id: 301,
    body: "Resposta da IA",
    author: "Agente Macro",
  });
  assert.equal(ai?.kind, "automatic");
  assert.equal(ai?.ai?.agent, "Agente Macro");
});

test("ignores events that do not yet carry a durable Inbox identifier", () => {
  assert.equal(
    webchatTimelineItem({ id: 4, direction: "visitor", body: "pending" }),
    null,
  );
});
