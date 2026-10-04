import assert from "node:assert/strict";
import test from "node:test";
import { readFile } from "node:fs/promises";

const root = new URL("../../", import.meta.url);
const read = (path) => readFile(new URL(path, root), "utf8");

test("AI mutations refresh durable state instead of reopening the conversation cache", async () => {
  const source = await read("Frontend/inbox/InboxApp.svelte");
  for (const name of ["assignAi", "resetAi", "sendAiPending"]) {
    const start = source.indexOf(`async function ${name}`);
    const end = source.indexOf("\n  }", start) + 4;
    const body = source.slice(start, end);
    assert.match(body, /await refreshSelected\(\)/, `${name} must refresh`);
    assert.doesNotMatch(body, /await select\(/, `${name} must bypass cache`);
  }
});

test("the Pi runtime preloads an explicit Brasileirão round before model turns", async () => {
  const source = await read("Runtime/runner.mjs");
  assert.match(source, /brasileirao-serie-a-rodada-/);
  assert.match(source, /Fonte CMS pré-carregada para esta rodada/);
  assert.match(source, /turn_start[\s\S]{0,80}turns > 6/);
});

test("an AI realtime reply refreshes the assignment counter and state", async () => {
  const source = await read("Frontend/inbox/InboxApp.svelte");
  const start = source.indexOf('event.type === "message.created"');
  const end = source.indexOf('event.type === "message.delivered"', start);
  const body = source.slice(start, end);
  assert.match(
    body,
    /message\?\.direction === "ai"[\s\S]*loadAi\(detail\.id, false\)/,
  );
  assert.match(
    body,
    /message\?\.direction !== "visitor"[\s\S]*window\.setTimeout\([\s\S]*loadAi\(detail\.id, false\)[\s\S]*900/,
  );
  assert.match(
    source,
    /event\.type === "typing\.stopped" && event\.role === "agent"[\s\S]*loadAi\(detail\.id, false\)/,
  );
});
