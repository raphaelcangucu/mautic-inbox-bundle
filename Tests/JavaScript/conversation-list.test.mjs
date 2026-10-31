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

const groupHeadings = (host) =>
  [...host.querySelectorAll(".inbox-list-group")].map((node) =>
    node.getAttribute("data-group"),
  );
const rowGroups = (host) =>
  [...host.querySelectorAll(".inbox-list-item")].map((node) =>
    node.getAttribute("data-group"),
  );
const actionLabels = (host) =>
  [...host.querySelectorAll(".inbox-list-actions button")].map((node) =>
    node.textContent.trim(),
  );

test("the column groups by what each conversation is waiting for, and skips empty groups", async () => {
  // Ordenada por relogio, a coluna nao dizia onde a fila travava. O agrupamento sai de
  // `needs_response` e `lifecycle`, que o payload ja trazia.
  const ConversationList = await loadConversationList();
  const asset = { id: 7, name: "Suporte", phone: "+55 11 90000-0000" };
  const { window, host, rows } = render([
    { ...row(1, asset), needs_response: false },
    { ...row(2, asset), needs_response: true },
    { ...row(3, asset), lifecycle: "snoozed", needs_response: true },
  ]);
  const app = mount(ConversationList, { target: host, props: props(rows) });

  assert.deepEqual(
    groupHeadings(host),
    ["waiting_you", "snoozed", "waiting_contact"],
    "quem espera uma pessoa vem primeiro; o que espera o contato, por ultimo",
  );
  assert.deepEqual(rowGroups(host), [
    "waiting_you",
    "snoozed",
    "waiting_contact",
  ]);
  assert.equal(
    [...host.querySelectorAll(".inbox-list-item")].length,
    3,
    "agrupar nao pode esconder conversa nenhuma",
  );

  unmount(app);
  window.close();
});

test("a snoozed conversation leaves 'waiting for you' even owing a reply", async () => {
  // Ela volta sozinha na hora marcada: manter no topo o que alguem ja decidiu adiar seria
  // pedir a mesma decisao duas vezes.
  const ConversationList = await loadConversationList();
  const asset = { id: 7, name: "Suporte", phone: "+55 11 90000-0000" };
  const { window, host, rows } = render([
    { ...row(1, asset), lifecycle: "snoozed", needs_response: true },
  ]);
  const app = mount(ConversationList, { target: host, props: props(rows) });

  assert.deepEqual(groupHeadings(host), ["snoozed"]);
  assert.ok(
    host.querySelector(".inbox-pill.response"),
    "fora do grupo que ja diz isso, o selo de aguardando resposta acrescenta algo e fica: esta adiada ainda deve resposta",
  );

  unmount(app);
  window.close();
});

test("the row offers only the actions the server will accept", async () => {
  // ConversationActions exige ser o responsavel em tudo menos `reopen`. Um botao que responde
  // erro e pior do que botao nenhum, entao a coluna nao o desenha.
  const ConversationList = await loadConversationList();
  const asset = { id: 7, name: "Suporte", phone: "+55 11 90000-0000" };
  const mine = { id: 4, name: "Eu" };
  const theirs = { id: 9, name: "Outra pessoa" };

  for (const [conversation, expected, why] of [
    [{ assignee: null }, ["take"], "sem dono, assumir"],
    [{ assignee: mine }, ["snooze", "resolve"], "minha, adiar e resolver"],
    [{ assignee: theirs }, [], "de outra pessoa, nenhuma"],
    [
      { assignee: theirs, lifecycle: "resolved" },
      ["reopen"],
      "resolvida, reabrir vale para qualquer um",
    ],
  ]) {
    const { window, host, rows } = render([
      { ...row(1, asset), ...conversation },
    ]);
    const app = mount(ConversationList, {
      target: host,
      props: { ...props(rows), currentUser: 4 },
    });

    assert.deepEqual(
      actionLabels(host),
      expected.map(
        (key) =>
          `mautic.inbox.ui.row_${key === "snooze" ? "snooze_hour" : key}`,
      ),
      why,
    );

    unmount(app);
    window.close();
  }
});

test("the list shows what moderation already did to a comment", async () => {
  // Antes isto so existia no aplicativo: o web mostrava o comentario sem dizer que ele ja
  // estava oculto, e o operador ocultava de novo.
  const ConversationList = await loadConversationList();
  const asset = { id: 8, name: "@loja.macro", handle: "loja.macro" };
  const { window, host, rows } = render([
    {
      ...row(1, asset),
      channel: "instagram",
      recipient: "comment:17841400000011",
      moderation: { spam: true, hidden: true, blockedAuthor: true },
      moderation_available: true,
    },
  ]);
  const app = mount(ConversationList, { target: host, props: props(rows) });

  assert.deepEqual(
    [...host.querySelectorAll(".inbox-pill.moderated")].map((n) =>
      n.textContent.trim(),
    ),
    [
      "mautic.inbox.ui.moderation_spam_mark",
      "mautic.inbox.ui.moderation_hidden_mark",
      "mautic.inbox.ui.moderation_blocked_mark",
    ],
  );

  unmount(app);
  window.close();
});

test("the web payload carries the moderation the list reads", async () => {
  // As duas pontas moram em linguagens diferentes e esta suite nao sobe o PHP. Sem o campo no
  // controlador do web, a tela acima desenharia sempre vazio — e passaria neste arquivo.
  const controller = await read("Controller/InboxController.php");
  assert.match(
    controller,
    /\$row\['moderation_available'\]/,
    "a resposta do web precisa dizer se este operador pode moderar",
  );
  assert.match(
    controller,
    /\$row\['moderation'\] =/,
    "e precisa dizer o que ja foi marcado",
  );
  assert.match(
    controller,
    /public function moderation\(/,
    "sem rota de moderacao no web, os botoes nao teriam para onde escrever",
  );
});

test("selecting rows raises the bulk bar, and clearing it takes the bar away", async () => {
  // A barra so existe quando ha selecao: barra vazia e altura perdida na coluna.
  const ConversationList = await loadConversationList();
  const asset = { id: 7, name: "Suporte", phone: "+55 11 90000-0000" };
  const { window, host, rows } = render([row(1, asset), row(2, asset)]);
  const app = mount(ConversationList, {
    target: host,
    props: { ...props(rows), selection: new Set() },
  });
  assert.equal(
    host.querySelector(".inbox-bulk"),
    null,
    "sem selecao, sem barra",
  );
  unmount(app);

  const comSelecao = mount(ConversationList, {
    target: host,
    props: { ...props(rows), selection: new Set([1, 2]) },
  });
  const barra = host.querySelector(".inbox-bulk");
  assert.ok(barra, "com selecao, a barra aparece");
  assert.equal(barra.querySelector("strong").textContent.trim(), "2");
  assert.equal(
    host.querySelectorAll(".inbox-list-check:checked").length,
    2,
    "as linhas selecionadas aparecem marcadas",
  );
  unmount(comSelecao);
  window.close();
});

test("the bulk bar offers moderation on comments and lifecycle on conversations", async () => {
  // Marcar spam em lote so faz sentido onde moderar existe. Na aba de conversas a barra traz
  // adiar e resolver, que e o que o servidor aceita para varias de uma vez.
  const ConversationList = await loadConversationList();
  const asset = { id: 7, name: "Suporte", phone: "+55 11 90000-0000" };
  const { window, host, rows } = render([row(1, asset)]);
  const rotulos = (node) =>
    [...node.querySelectorAll(".inbox-bulk .btn")].map((n) =>
      n.textContent.trim(),
    );

  const conversas = mount(ConversationList, {
    target: host,
    props: { ...props(rows), selection: new Set([1]), view: "inbox" },
  });
  assert.deepEqual(rotulos(host), [
    "mautic.inbox.ui.row_snooze_hour",
    "mautic.inbox.ui.row_resolve",
    "mautic.inbox.ui.bulk_clear",
  ]);
  unmount(conversas);

  const comentarios = mount(ConversationList, {
    target: host,
    props: { ...props(rows), selection: new Set([1]), view: "comments" },
  });
  assert.deepEqual(rotulos(host), [
    "mautic.inbox.ui.moderation_spam",
    "mautic.inbox.ui.moderation_block",
    "mautic.inbox.ui.bulk_clear",
  ]);
  unmount(comentarios);
  window.close();
});

test("the web serves the agent session queue and the contact's e-mail switch", async () => {
  // A aba de sessoes e o nao perturbe dependem de endpoints que esta suite nao sobe. Sem o
  // contrato aqui, a tela existiria apontando para rota nenhuma.
  const [controller, config] = await Promise.all([
    read("Controller/InboxController.php"),
    read("Config/config.php"),
  ]);
  assert.match(controller, /public function aiSessions\(/);
  assert.match(controller, /public function doNotContact\(/);
  assert.match(
    controller,
    /'needs_human' => '' !== \$reason/,
    "a fila precisa dizer quais sessoes pararam esperando uma pessoa",
  );
  assert.match(config, /mautic_inbox_ai_sessions/);
  assert.match(config, /mautic_inbox_dnc/);
});
