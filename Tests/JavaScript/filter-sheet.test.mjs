import assert from "node:assert/strict";
import test from "node:test";
import { readFile } from "node:fs/promises";
import { JSDOM } from "jsdom";
import { compile } from "svelte/compiler";

const root = new URL("../../", import.meta.url);
const read = (path) => readFile(new URL(path, root), "utf8");
const svelteDir = new URL(".", import.meta.resolve("svelte/package.json"));
const clientEntry = new URL("src/index-client.js", svelteDir).href;
const { mount, unmount, tick } = await import(clientEntry);

const dataModule = (code) =>
  `data:text/javascript;charset=utf-8,${encodeURIComponent(code)}`;

async function compileToUrl(path, replacements = {}) {
  const { js } = compile(await read(path), {
    generate: "client",
    filename: path,
  });
  const code = Object.entries({
    "svelte/internal/disclose-version": import.meta.resolve(
      "svelte/internal/disclose-version",
    ),
    "svelte/internal/flags/legacy": import.meta.resolve(
      "svelte/internal/flags/legacy",
    ),
    "svelte/internal/client": import.meta.resolve("svelte/internal/client"),
    svelte: clientEntry,
    ...replacements,
  }).reduce(
    (source, [from, to]) =>
      source
        .split(`'${from}'`)
        .join(`'${to}'`)
        .split(`"${from}"`)
        .join(`"${to}"`),
    js.code,
  );
  return dataModule(code);
}

async function loadSheet() {
  const icon = await compileToUrl("Frontend/shared/Icon.svelte");
  const url = await compileToUrl("Frontend/inbox/FilterSheet.svelte", {
    "../shared/Icon.svelte": icon,
  });
  return (await import(url)).default;
}

// Um JSDOM so: o Svelte guarda o window da primeira montagem, e o <svelte:window> de um segundo
// documento ficaria escutando o primeiro.
const { window } = new JSDOM(`<!doctype html><body><div id="host"></div>`);

function setup(props = {}) {
  Object.assign(globalThis, {
    window,
    document: window.document,
    Node: window.Node,
    Element: window.Element,
    HTMLElement: window.HTMLElement,
    Text: window.Text,
    Comment: window.Comment,
    DocumentFragment: window.DocumentFragment,
    HTMLMediaElement: window.HTMLMediaElement,
    HTMLInputElement: window.HTMLInputElement,
    HTMLSelectElement: window.HTMLSelectElement,
    HTMLTextAreaElement: window.HTMLTextAreaElement,
    HTMLOListElement: window.HTMLOListElement,
    Event: window.Event,
    CustomEvent: window.CustomEvent,
    requestAnimationFrame: (callback) => setTimeout(callback, 0),
  });
  const calls = { queue: [], change: 0, clear: 0, close: 0 };
  return {
    window,
    calls,
    target: window.document.getElementById("host"),
    props: {
      queue: "all",
      lifecycle: "active",
      needsResponse: false,
      channels: ["whatsapp", "instagram", "facebook", "webchat", "email"],
      available: ["whatsapp", "instagram", "facebook", "webchat", "email"],
      channelCounts: { whatsapp: 31, instagram: 12, email: 8 },
      total: 64,
      t: (key) =>
        ({
          "mautic.inbox.ui.filters_show": "Mostrar %count% conversas",
          "mautic.inbox.ui.filters_show_one": "Mostrar 1 conversa",
          "mautic.inbox.ui.all_8a3c34": "Todas",
          "mautic.inbox.ui.email_channel": "E-mail",
        })[key] ?? key,
      onQueue: (value) => calls.queue.push(value),
      onChange: () => calls.change++,
      onClear: () => calls.clear++,
      onClose: () => calls.close++,
      ...props,
    },
  };
}

test("a folha mostra fila, situacao, canais com contagem e o total", async () => {
  const Sheet = await loadSheet();
  const { target, props, calls } = setup();
  const app = mount(Sheet, { target, props });
  await tick();
  assert.equal(target.querySelectorAll("[data-filter-queue]").length, 3);
  assert.ok(
    target.querySelector('[data-filter-lifecycle="active"]').matches(".active"),
  );
  assert.equal(target.querySelectorAll(".inbox-filter-channel").length, 5);
  assert.equal(
    target.querySelector(
      '[data-channel="whatsapp"] .inbox-filter-channel-count',
    ).textContent,
    "31",
  );
  // Canal sem conversa nenhuma mostra zero, nao um buraco.
  assert.equal(
    target.querySelector('[data-channel="webchat"] .inbox-filter-channel-count')
      .textContent,
    "0",
  );
  assert.equal(
    target.querySelector("#inbox-filter-show").textContent.trim(),
    "Mostrar 64 conversas",
  );
  target.querySelector('[data-filter-queue="mine"]').click();
  assert.deepEqual(calls.queue, ["mine"]);
  unmount(app);
});

test("o botao fala no singular e so fecha", async () => {
  const Sheet = await loadSheet();
  const { target, props, calls } = setup({ total: 1 });
  const app = mount(Sheet, { target, props });
  await tick();
  const show = target.querySelector("#inbox-filter-show");
  assert.equal(show.textContent.trim(), "Mostrar 1 conversa");
  show.click();
  assert.equal(calls.close, 1);
  assert.equal(calls.change, 0);
  unmount(app);
});

test("o ultimo canal marcado nao desmarca", async () => {
  const Sheet = await loadSheet();
  const { target, props, calls } = setup({ channels: ["email"] });
  const app = mount(Sheet, { target, props });
  await tick();
  const box = target.querySelector('[data-channel="email"] input');
  assert.equal(box.checked, true);
  box.click();
  await tick();
  assert.equal(box.checked, true);
  assert.equal(calls.change, 0);
  const other = target.querySelector('[data-channel="whatsapp"] input');
  other.click();
  await tick();
  assert.equal(other.checked, true);
  assert.equal(calls.change, 1);
  unmount(app);
});

test("situacao e aguardando resposta avisam a lista, e Esc fecha", async () => {
  const Sheet = await loadSheet();
  const { window, target, props, calls } = setup();
  const app = mount(Sheet, { target, props });
  await tick();
  target.querySelector('[data-filter-lifecycle="resolved"]').click();
  target.querySelector("#inbox-needs-response").click();
  assert.equal(calls.change, 2);
  await tick();
  assert.ok(
    target
      .querySelector('[data-filter-lifecycle="resolved"]')
      .matches(".active"),
  );
  assert.ok(target.querySelector("#inbox-needs-response").matches(".active"));
  window.dispatchEvent(new window.KeyboardEvent("keydown", { key: "Escape" }));
  assert.equal(calls.close, 1);
  unmount(app);
});
