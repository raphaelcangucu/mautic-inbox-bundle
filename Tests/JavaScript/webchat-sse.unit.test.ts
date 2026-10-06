import assert from "node:assert/strict";
import test from "node:test";
import { WebChatRealtime } from "../../Frontend/inbox/webchatRealtime";
import type { Conversation } from "../../Frontend/shared/types";
class Stream {
  static instances: Stream[] = [];
  onopen: (() => void) | null = null;
  onmessage: ((e: { data: string }) => void) | null = null;
  onerror: (() => void) | null = null;
  closed = false;
  constructor(public url: string) {
    Stream.instances.push(this);
  }
  close() {
    this.closed = true;
  }
}
test("operator typing uses SSE + scoped HTTP; repeated typing and read receipts are bounded", async () => {
  Object.assign(globalThis, { EventSource: Stream, window: globalThis });
  const original = globalThis.fetch;
  const posts: Record<string, unknown>[] = [];
  globalThis.fetch = async (_url, init) => {
    posts.push(JSON.parse(String(init?.body)));
    return new Response('{"ok":true}');
  };
  try {
    const c = {
      realtime: {
        url: "https://example.test/chat/realtime",
        token: "operator-token",
        expires_at: "2030-01-01T00:00:00Z",
      },
    } as Conversation;
    const events: Record<string, unknown>[] = [];
    const client = new WebChatRealtime(c, {
      status: () => {},
      event: (e) => events.push(e),
    });
    client.connect();
    const stream = Stream.instances.at(-1)!;
    stream.onopen?.();
    for (let i = 0; i < 1000; i++) {
      client.read(12);
      client.typing(true);
    }
    client.typing(false);
    await new Promise((r) => setTimeout(r, 20));
    assert.equal(posts.filter((e) => e.type === "message.read").length, 1);
    assert.equal(posts.filter((e) => e.type === "typing.started").length, 1);
    assert.equal(posts.filter((e) => e.type === "typing.stopped").length, 1);
    stream.onmessage?.({
      data: JSON.stringify({ type: "typing.started", role: "visitor" }),
    });
    assert.equal(events[0].role, "visitor");
    client.close();
    assert.equal(stream.closed, true);
  } finally {
    globalThis.fetch = original;
  }
});
