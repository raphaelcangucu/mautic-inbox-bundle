import assert from "node:assert/strict";
import test from "node:test";
import {
  emailDocument,
  emailRows,
  formatSize,
  hasRemoteImages,
  replySubject,
  snippet,
  threadSubject,
} from "../../Frontend/inbox/emailThread";
import type { TimelineItem } from "../../Frontend/shared/types";

const message = (
  id: number,
  extra: Partial<TimelineItem> = {},
): TimelineItem => ({
  id,
  kind: "message",
  direction: "inbound",
  body: `Mensagem ${id}`,
  subject: "Proposta",
  timestamp: `2026-10-24T10:0${id % 10}:00Z`,
  ...extra,
});

test("only the last message opens, and a long run of earlier ones folds into one line", () => {
  const items = [1, 2, 3, 4, 5, 6].map((id) => message(id));
  const rows = emailRows(items);

  assert.deepEqual(
    rows.map((row) => row.type),
    ["group", "card"],
  );
  assert.equal(rows[0].type === "group" && rows[0].items.length, 5);
  assert.equal(rows[1].type === "card" && rows[1].expanded, true);
});

test("four collapsed messages stay as cards: the fold starts after four", () => {
  const rows = emailRows([1, 2, 3, 4, 5].map((id) => message(id)));

  assert.deepEqual(
    rows.map((row) => (row.type === "card" ? row.expanded : row.type)),
    [false, false, false, false, true],
  );
});

test("unread messages open with the last one", () => {
  const items = [1, 2, 3].map((id) => message(id));
  const rows = emailRows(items, 2);

  assert.deepEqual(
    rows.map((row) => row.type === "card" && row.expanded),
    [false, true, true],
  );
});

test("notes, events and automatic replies sit between the cards and break the fold", () => {
  const items = [
    message(1),
    message(2),
    { id: 3, kind: "note", body: "nota", timestamp: "2026-10-24T10:03:00Z" },
    message(4, {
      email: {
        from: { name: "", address: "ferias@example.com" },
        to: [],
        cc: [],
        quoted: null,
        has_html: false,
        has_original: false,
        auto_reply: true,
        unverified_sender: false,
        kind: "auto_reply",
      },
    }),
    {
      id: 5,
      kind: "event",
      event: "form_opened",
      timestamp: "2026-10-24T10:05:00Z",
    },
    message(6),
  ] as TimelineItem[];

  assert.deepEqual(
    emailRows(items).map((row) => `${row.type}:${row.key}`),
    [
      "card:message:1",
      "card:message:2",
      "line:note:3",
      "line:message:4",
      "line:event:5",
      "card:message:6",
    ],
  );
});

test("a card or group the agent toggled flips its state", () => {
  const items = [1, 2, 3, 4, 5, 6].map((id) => message(id));
  const opened = emailRows(items, 0, new Set(["group:message:1"]));
  assert.equal(opened.filter((row) => row.type === "card").length, 6);

  const flipped = emailRows(items, 0, new Set(["message:6"]));
  assert.equal(
    flipped.some((row) => row.type === "card" && row.expanded),
    false,
  );
});

test("the reply subject does not pile up prefixes", () => {
  assert.equal(replySubject("Proposta"), "Re: Proposta");
  assert.equal(replySubject("Re: Proposta"), "Re: Proposta");
  assert.equal(replySubject("RES: Proposta"), "RES: Proposta");
  assert.equal(replySubject("  "), "");
  assert.equal(replySubject("Oi\r\nBcc: x@y"), "Re: Oi Bcc: x@y");
});

test("the thread subject prefers the summary and falls back to the newest message", () => {
  assert.equal(
    threadSubject({ subject: "Do resumo" }, [message(1)]),
    "Do resumo",
  );
  assert.equal(
    threadSubject({ subject: null }, [
      message(1, { subject: "Antigo" }),
      message(2, { subject: "Novo" }),
    ]),
    "Novo",
  );
  assert.equal(threadSubject({}, []), "");
});

test("the iframe document blocks remote images until asked, and never runs a script", () => {
  const blocked = emailDocument("<p>Oi</p>", "https://mautic.test", false);
  assert.match(blocked, /default-src 'none'/);
  assert.match(blocked, /img-src data: https:\/\/mautic\.test;/);
  assert.match(blocked, /form-action 'none'/);
  assert.doesNotMatch(blocked, /script-src/);

  const shown = emailDocument("<p>Oi</p>", "https://mautic.test", true);
  assert.match(shown, /img-src data: https:\/\/mautic\.test https: http:;/);
});

test("only images outside the Mautic origin count as remote", () => {
  assert.equal(
    hasRemoteImages(
      '<img src="https://mautic.test/s/inbox/api/email/7/attachments/0">',
      "https://mautic.test",
    ),
    false,
  );
  assert.equal(
    hasRemoteImages(
      '<img src="https://tracker.test/p.gif">',
      "https://mautic.test",
    ),
    true,
  );
});

test("the collapsed line is plain text and sizes read like a mail client", () => {
  assert.equal(
    snippet("**Oi** [Ana](https://x.test), tudo\nbem?"),
    "Oi Ana, tudo bem?",
  );
  assert.equal(formatSize(183422), "179 KB");
  assert.equal(formatSize(0), "");
  assert.equal(formatSize(3 * 1024 * 1024), "3.0 MB");
});
