import assert from "node:assert/strict";
import test from "node:test";
import { readFile } from "node:fs/promises";
import { JSDOM } from "jsdom";
import { compile } from "svelte/compiler";

const read = (path) =>
  readFile(new URL(`../../${path}`, import.meta.url), "utf8");
const clientUrl = new URL(
  "src/index-client.js",
  new URL(".", import.meta.resolve("svelte/package.json")),
).href;
const { mount, unmount, flushSync, tick } = await import(clientUrl);

async function componentUrl(path, replacements = {}) {
  const { js } = compile(await read(path), {
    generate: "client",
    filename: path,
  });
  let code = js.code;
  for (const [from, to] of Object.entries({
    svelte: clientUrl,
    "svelte/internal/disclose-version": import.meta.resolve(
      "svelte/internal/disclose-version",
    ),
    "svelte/internal/flags/legacy": import.meta.resolve(
      "svelte/internal/flags/legacy",
    ),
    "svelte/internal/client": import.meta.resolve("svelte/internal/client"),
    ...replacements,
  })) {
    code = code
      .replaceAll(`'${from}'`, `'${to}'`)
      .replaceAll(`"${from}"`, `"${to}"`);
  }
  return `data:text/javascript;charset=utf-8,${encodeURIComponent(code)}`;
}

async function editor(props = {}) {
  const { window } = new JSDOM(
    '<div class="inbox-app"><div id="host"></div></div>',
  );
  window.matchMedia = () => ({
    matches: false,
    addEventListener() {},
    removeEventListener() {},
  });
  Object.assign(globalThis, {
    window,
    document: window.document,
    Node: window.Node,
    Element: window.Element,
    HTMLElement: window.HTMLElement,
    Text: window.Text,
    Comment: window.Comment,
    DocumentFragment: window.DocumentFragment,
    HTMLInputElement: window.HTMLInputElement,
    HTMLSelectElement: window.HTMLSelectElement,
    HTMLTextAreaElement: window.HTMLTextAreaElement,
    HTMLMediaElement: window.HTMLMediaElement,
    MutationObserver: window.MutationObserver,
    Event: window.Event,
    CustomEvent: window.CustomEvent,
  });
  const ini = await read("Translations/pt_BR/messages.ini");
  const labels = Object.fromEntries(
    [...ini.matchAll(/^(mautic\.inbox\.[\w.]+)="([^"]*)"/gm)].map((m) => [
      m[1],
      m[2],
    ]),
  );
  const Composer = (
    await import(
      await componentUrl("Frontend/inbox/Composer.svelte", {
        "../shared/Icon.svelte": await componentUrl(
          "Frontend/shared/Icon.svelte",
        ),
      })
    )
  ).default;
  const host = window.document.getElementById("host");
  const app = mount(Composer, {
    target: host,
    props: {
      selected: {
        id: 11,
        channel: "whatsapp",
        lifecycle: "open",
        can_reply: true,
      },
      currentUser: 1,
      t: (key) => {
        assert.ok(labels[key], `Missing translation: ${key}`);
        return labels[key];
      },
      onInput() {},
      onMode() {},
      onSend() {},
      onLoadTemplates() {},
      onTemplateSend: async () => true,
      ...props,
    },
  });
  flushSync();
  await tick();
  return {
    window,
    host,
    close: async () => {
      await unmount(app);
      window.close();
    },
  };
}

async function click(view, selector) {
  view.host.querySelector(selector).click();
  flushSync();
  await tick();
}

test("the plus menu preserves modes and canned insertion, and Escape restores focus", async () => {
  const modes = [];
  let inputs = 0;
  const view = await editor({
    body: "Olá",
    canned: [{ id: 7, name: "Saudação", body: "Como posso ajudar?" }],
    onMode: (mode) => modes.push(mode),
    onInput: () => inputs++,
  });
  await click(view, "#inbox-composer-options");
  assert.equal(
    view.host
      .querySelector("#inbox-composer-options")
      .getAttribute("aria-expanded"),
    "true",
  );
  const responses = view.host.querySelector("#inbox-canned");
  responses.value = "7";
  responses.dispatchEvent(new view.window.Event("change", { bubbles: true }));
  flushSync();
  await tick();
  assert.equal(
    view.host.querySelector("textarea").value,
    "Olá\nComo posso ajudar?",
  );
  assert.equal(inputs, 1);
  assert.equal(
    view.host
      .querySelector("#inbox-composer-options")
      .getAttribute("aria-expanded"),
    "false",
  );
  await click(view, "#inbox-composer-options");
  await click(view, ".inbox-composer-menu-modes button:last-child");
  assert.deepEqual(modes, ["note"]);
  await click(view, "#inbox-composer-options");
  view.window.dispatchEvent(
    new view.window.KeyboardEvent("keydown", { key: "Escape", bubbles: true }),
  );
  flushSync();
  assert.equal(
    view.host
      .querySelector("#inbox-composer-options")
      .getAttribute("aria-expanded"),
    "false",
  );
  assert.equal(view.window.document.activeElement.id, "inbox-composer-options");
  await view.close();
});

test("an expired WhatsApp window still permits only approved templates and requires every variable", async () => {
  const sends = [];
  const template = {
    id: 2,
    name: "relatorio",
    language: "pt_BR",
    supported: true,
    fields: [{ key: "BODY:1", component: "BODY", token: "1" }],
    parts: [{ type: "BODY", text: "Olá {{1}}" }],
  };
  const view = await editor({
    selected: {
      id: 11,
      channel: "whatsapp",
      lifecycle: "open",
      can_reply: false,
      reply_blocked_reason: "window_expired",
    },
    body: "Texto livre",
    templates: [template],
    onTemplateSend: async (item, values) => {
      sends.push({ item, values });
      return true;
    },
  });
  assert.equal(view.host.querySelector("#inbox-send").disabled, true);
  await click(view, "#inbox-composer-options");
  assert.equal(view.host.querySelector("#inbox-canned-group").disabled, true);
  assert.equal(
    view.host.querySelector("#inbox-template-group").disabled,
    false,
  );
  const responses = view.host.querySelector("#inbox-canned");
  responses.value = "template:2";
  responses.dispatchEvent(new view.window.Event("change", { bubbles: true }));
  flushSync();
  await tick();
  assert.equal(view.host.querySelector("#inbox-template-send").disabled, true);
  const variable = view.host.querySelector("#inbox-template-fields input");
  variable.value = "Raphael";
  variable.dispatchEvent(new view.window.Event("input", { bubbles: true }));
  flushSync();
  assert.equal(
    view.host.querySelector("#inbox-template-preview").textContent.trim(),
    "Olá Raphael",
  );
  assert.equal(view.host.querySelector("#inbox-template-send").disabled, false);
  await click(view, "#inbox-template-send");
  assert.deepEqual(sends, [
    { item: template, values: { "BODY:1": "Raphael" } },
  ]);
  assert.equal(
    view.host.querySelector("textarea").value,
    "Texto livre",
    "Template selection preserves the text draft",
  );
  await view.close();
});

test("Ctrl+Enter preserves sending and ignores IME composition; notes retain their own action", async () => {
  let sends = 0;
  const view = await editor({ body: "Mensagem", onSend: () => sends++ });
  const field = view.host.querySelector("textarea");
  field.dispatchEvent(
    new view.window.KeyboardEvent("keydown", {
      key: "Enter",
      ctrlKey: true,
      isComposing: true,
      bubbles: true,
    }),
  );
  assert.equal(sends, 0);
  field.dispatchEvent(
    new view.window.KeyboardEvent("keydown", {
      key: "Enter",
      ctrlKey: true,
      bubbles: true,
    }),
  );
  assert.equal(sends, 1);
  await click(view, "#inbox-send");
  assert.equal(sends, 2);
  await view.close();
  const note = await editor({
    mode: "note",
    body: "Registro interno",
    selected: {
      id: 11,
      channel: "whatsapp",
      lifecycle: "open",
      can_reply: false,
      reply_blocked_reason: "window_expired",
    },
  });
  assert.equal(note.host.querySelector("#inbox-send").disabled, false);
  assert.equal(
    note.host.querySelector("#inbox-send").getAttribute("aria-label"),
    "Adicionar nota",
  );
  await click(note, "#inbox-composer-options");
  assert.equal(note.host.querySelector("#inbox-canned"), null);
  await note.close();
});
