import assert from "node:assert/strict";
import test from "node:test";
import { readFile } from "node:fs/promises";
import { JSDOM } from "jsdom";
import { compile } from "svelte/compiler";

const root = new URL("../../", import.meta.url);
const read = (path) => readFile(new URL(path, root), "utf8");

// Node resolve a condicao "default" do pacote svelte, que e a build de servidor — e nela
// mount() so sabe lancar erro. O caminho direto para a build de browser e o que deixa um
// componente avulso ser montado no JSDOM sem passar pelo bundle inteiro do plugin.
const svelteDir = new URL(".", import.meta.resolve("svelte/package.json"));
const { mount, unmount } = await import(
  new URL("src/index-client.js", svelteDir).href
);

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

async function loadConversationList() {
  const icon = await compileToUrl("Frontend/shared/Icon.svelte");
  const avatar = await compileToUrl("Frontend/inbox/Avatar.svelte", {
    "../shared/Icon.svelte": icon,
  });
  const url = await compileToUrl("Frontend/inbox/ConversationList.svelte", {
    "../shared/Icon.svelte": icon,
    "./Avatar.svelte": avatar,
  });
  return (await import(url)).default;
}

function dom() {
  const { window } = new JSDOM(
    `<!doctype html><body><div class="inbox-app"><div id="host"></div></div></body>`,
  );
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
  return window;
}

const row = (id, asset) => ({
  id,
  version: 1,
  lifecycle: "open",
  channel: "whatsapp",
  recipient: "5511999999999",
  contact_name: `Contato ${id}`,
  asset,
  preview: "Bom dia",
  last_message_at: "2026-09-17T12:00:00Z",
});

function render(rows) {
  const window = dom();
  const host = window.document.getElementById("host");
  return { window, host, rows };
}

const props = (rows) => ({
  rows,
  locale: "pt-BR",
  t: (key) => key,
  onSelect: () => {},
  onQueue: () => {},
  onFilters: () => {},
  onFilter: () => {},
  onSearch: () => {},
  onMore: () => {},
});

const metaLines = (host) =>
  [...host.querySelectorAll(".inbox-list-meta")].map((node) =>
    node.textContent.replace(/\s+/g, " ").trim(),
  );

test("a QR session wears its own badge, and the homologated number does not", async () => {
  const ConversationList = await loadConversationList();
  const { window, host, rows } = render([
    row(1, {
      id: 7,
      name: "Suporte",
      phone: "+55 11 90000-0000",
      type: "whatsapp_qr_session",
    }),
    row(2, {
      id: 8,
      name: "Vendas",
      phone: "+55 11 91111-1111",
      type: "whatsapp_phone_number",
    }),
  ]);
  const app = mount(ConversationList, { target: host, props: props(rows) });

  const [session, official] = metaLines(host);
  assert.match(
    session,
    /^WhatsApp · QR ·/,
    "o canal por QR nao e homologado e nao se comporta como o oficial: quem olha a lista precisa ver de qual dos dois a conversa veio",
  );
  assert.match(official, /^WhatsApp ·/);
  assert.doesNotMatch(
    official,
    /QR/,
    "o numero homologado nunca pode aparecer como sessao por QR",
  );

  unmount(app);
  window.close();
});

test("an asset with no type is still drawn, and as the homologated channel", async () => {
  // Conversa que ja estava em cache no navegador antes desta versao: o payload dela nao
  // tem `type`. Sem este caso, uma reabertura offline desenharia a lista sem o canal.
  const ConversationList = await loadConversationList();
  const { window, host, rows } = render([
    row(1, { id: 7, name: "Suporte", phone: "+55 11 90000-0000" }),
  ]);
  const app = mount(ConversationList, { target: host, props: props(rows) });

  assert.deepEqual(metaLines(host), ["WhatsApp · +55 11 90000-0000"]);

  unmount(app);
  window.close();
});

test("the conversation payload carries the asset type the badge reads", async () => {
  // O selo so existe se o servidor mandar o tipo. As duas pontas moram em linguagens
  // diferentes e nenhum teste desta suite sobe o PHP, entao o contrato e conferido aqui.
  const query = await read("Application/InboxQuery.php");
  const asset = query.match(/'asset' => \[[^\]]*\]/);
  assert.ok(asset, "InboxQuery precisa montar o bloco `asset` da conversa");
  assert.match(
    asset[0],
    /'type' =>/,
    "sem o tipo do asset no payload a lista nao tem como distinguir QR de numero homologado",
  );
});
