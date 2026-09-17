import assert from "node:assert/strict";
import test from "node:test";
import { readFile } from "node:fs/promises";
import { JSDOM } from "jsdom";
import { compile } from "svelte/compiler";

const root = new URL("../../", import.meta.url);
const read = (path) => readFile(new URL(path, root), "utf8");

// Node resolve a condicao "default" do pacote svelte, que e a build de servidor — e nela mount()
// so sabe lancar erro. O caminho direto para a build de browser e o que deixa um componente
// avulso ser montado no JSDOM sem passar pelo bundle inteiro do plugin.
const svelteDir = new URL(".", import.meta.resolve("svelte/package.json"));
const { mount, unmount, flushSync } = await import(
  new URL("src/index-client.js", svelteDir).href
);

const dataModule = (code) =>
  `data:text/javascript;charset=utf-8,${encodeURIComponent(code)}`;

/**
 * Compila um .svelte e devolve a URL do modulo. Todo especificador "nu" vira URL absoluta
 * porque um modulo carregado de data: nao tem pasta a partir da qual resolver coisa alguma.
 */
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

/** O componente da pendente com o Icon de verdade: o relogio faz parte do que se testa. */
async function loadPendingBubble() {
  const url = await compileToUrl("Frontend/inbox/PendingBubble.svelte", {
    "../shared/Icon.svelte": await compileToUrl("Frontend/shared/Icon.svelte"),
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

const translations = async () => {
  const ini = await read("Translations/pt_BR/messages.ini");
  const table = new Map(
    [...ini.matchAll(/^(mautic\.inbox\.[A-Za-z0-9_.]+)="([^"]*)"/gm)].map(
      (match) => [match[1], match[2]],
    ),
  );
  return (key) => {
    assert.ok(table.has(key), `pt_BR does not define ${key}`);
    return table.get(key);
  };
};

const pending = (extra = {}) => ({
  localId: "local-1",
  conversationId: 11,
  mode: "reply",
  body: "Bom dia, ja estou vendo isso",
  createdAt: "2026-09-17T12:30:00.000Z",
  requestId: "req-1",
  state: "sending",
  ...extra,
});

test("a pending draws itself, and never as a sent message", async () => {
  const [bubble, timeline, message] = await Promise.all([
    read("Frontend/inbox/PendingBubble.svelte"),
    read("Frontend/inbox/Timeline.svelte"),
    read("Frontend/inbox/MessageBubble.svelte"),
  ]);
  assert.doesNotMatch(
    bubble,
    /MessageBubble/,
    "a pendente nao pode ser desenhada pelo componente da mensagem confirmada",
  );
  assert.doesNotMatch(
    message,
    /PendingMessage/,
    "e o componente da mensagem confirmada nao pode aprender a desenhar pendente",
  );
  assert.match(
    timeline,
    /import PendingBubble from "\.\/PendingBubble\.svelte"/,
  );
  assert.match(
    timeline,
    /#each pendingMessages as message \(message\.localId\)/,
  );
});

test("the sending state is dimmed, clocked, and offers nothing to retry", async () => {
  const window = dom();
  const t = await translations();
  const PendingBubble = await loadPendingBubble();
  const host = window.document.getElementById("host");
  const app = mount(PendingBubble, {
    target: host,
    props: { message: pending(), locale: "pt-BR", t },
  });
  const article = host.querySelector(".inbox-pending");
  assert.ok(article.classList.contains("sending"));
  assert.ok(!article.classList.contains("failed"));
  assert.ok(
    host.querySelector('[data-icon="clock"]'),
    "enviando mostra o relogio",
  );
  assert.match(host.textContent, /Bom dia, ja estou vendo isso/);
  assert.match(host.textContent, /Enviando/);
  assert.equal(
    host.querySelector("button"),
    null,
    "nao existe o que tentar de novo enquanto o envio esta em voo",
  );
  unmount(app);
  window.close();
});

test("a network failure says retrying helps, and retrying reaches the caller", async () => {
  const window = dom();
  const t = await translations();
  const PendingBubble = await loadPendingBubble();
  const host = window.document.getElementById("host");
  const message = pending({
    state: "failed",
    retryable: true,
    failure: "Falha de rede",
  });
  const retried = [];
  const app = mount(PendingBubble, {
    target: host,
    props: { message, locale: "pt-BR", t, retry: (item) => retried.push(item) },
  });
  const article = host.querySelector(".inbox-pending");
  assert.ok(article.classList.contains("failed"));
  assert.ok(!article.classList.contains("sending"));
  assert.equal(
    host.querySelector('[data-icon="clock"]'),
    null,
    "o relogio some quando o envio parou de estar a caminho",
  );
  assert.match(
    host.querySelector(".inbox-pending-reason").textContent,
    /Tentar de novo costuma resolver/,
  );
  assert.match(
    host.querySelector(".inbox-pending-reason").textContent,
    /Falha de rede/,
  );
  const button = host.querySelector("button.inbox-retry");
  assert.equal(button.disabled, false);
  button.dispatchEvent(new window.Event("click", { bubbles: true }));
  flushSync();
  assert.deepEqual(retried, [message]);
  unmount(app);
  window.close();
});

test("a channel refusal says retrying will not help, and the button agrees", async () => {
  const window = dom();
  const t = await translations();
  const PendingBubble = await loadPendingBubble();
  const host = window.document.getElementById("host");
  const app = mount(PendingBubble, {
    target: host,
    props: {
      message: pending({
        state: "failed",
        retryable: false,
        failure: "Fora da janela de 24 horas",
      }),
      locale: "pt-BR",
      t,
    },
  });
  const reason = host.querySelector(".inbox-pending-reason").textContent;
  assert.match(reason, /Tentar de novo não resolve/);
  assert.match(reason, /Fora da janela de 24 horas/);
  const button = host.querySelector("button.inbox-retry");
  assert.equal(
    button.disabled,
    true,
    "recusa do canal nao pode oferecer um reenvio que nunca vai passar",
  );
  unmount(app);
  window.close();
});

test("a note pending is labelled as a note", async () => {
  const window = dom();
  const t = await translations();
  const PendingBubble = await loadPendingBubble();
  const host = window.document.getElementById("host");
  const app = mount(PendingBubble, {
    target: host,
    props: {
      message: pending({ mode: "note", requestId: undefined, noteId: 9 }),
      locale: "pt-BR",
      t,
    },
  });
  assert.ok(host.querySelector(".inbox-pending").classList.contains("note"));
  assert.match(host.textContent, /Nota interna/);
  unmount(app);
  window.close();
});

test("the timeline scrolls for a pending too, and stays inert until it is given one", async () => {
  const timeline = await read("Frontend/inbox/Timeline.svelte");
  assert.match(
    timeline,
    /export let pendingMessages: PendingMessage\[\] = \[\];/,
    "a prop nova precisa de padrao vazio para nao mudar nada antes de ser ligada",
  );
  assert.match(
    timeline,
    /export let pending: AiPendingReply \| null = null;/,
    "a pendente da IA continua sendo outra prop, com outro nome",
  );
  assert.match(
    timeline,
    /visibleCount = items\.length \+ pendingMessages\.length/,
    "a rolagem precisa observar as duas colecoes",
  );
  assert.match(timeline, /visibleCount >= previousCount/);
  assert.match(timeline, /previousCount = visibleCount;/);
  assert.doesNotMatch(
    timeline,
    /previousCount = items\.length;/,
    "o caminho de carregar anteriores tambem conta as duas",
  );
});

test("the send button is a paper plane that still says its name", async () => {
  const [composer, pendingBubble, icon, css, twig, english, portuguese] =
    await Promise.all([
      read("Frontend/inbox/Composer.svelte"),
      read("Frontend/inbox/PendingBubble.svelte"),
      read("Frontend/shared/Icon.svelte"),
      read("Assets/css/inbox.css"),
      read("Resources/views/Inbox/_root.html.twig"),
      read("Translations/en_US/messages.ini"),
      read("Translations/pt_BR/messages.ini"),
    ]);
  assert.match(composer, /id="inbox-send"/);
  assert.match(composer, /aria-label=\{sendLabel\}/);
  assert.match(composer, /<Icon name="send" \/>/);
  // O botao nomeia a ACAO. Ele nao diz mais "enviando": desde que o envio virou otimista nao ha
  // trava, o composer fica livre no instante do toque, e quem carrega o estado do envio e a
  // bolha. Um botao dizendo "enviando" enquanto o atendente ja digita a proxima mensagem
  // estaria falando da mensagem errada.
  for (const key of [
    "mautic.inbox.ui.add_note_344d88",
    "mautic.inbox.ui.assign_to_me_and_send_509661",
    "mautic.inbox.ui.send_reply_c50a43",
  ]) {
    assert.ok(
      composer.includes(key),
      `o icone nao pode custar a chave de traducao ${key}`,
    );
  }
  assert.ok(
    !composer.includes("mautic.inbox.ui.sending_5e91dc"),
    "o composer nao volta a ter trava de envio",
  );
  assert.ok(
    pendingBubble.includes("mautic.inbox.ui.sending_5e91dc"),
    "e a bolha que diz que a mensagem esta saindo",
  );
  // O aviao aponta para cima e para a direita, com a ponta em 21,5. Espelhado, o botao de
  // enviar passa a parecer um botao de voltar.
  assert.match(icon, /send: "M21 5L3 11l7 3 3 7z M21 5l-11 9"/);
  assert.match(icon, /clock: "/);
  assert.match(
    css,
    /#inbox-send\{[^}]*min-width:44px;min-height:44px/,
    "o composer e usado com o polegar",
  );
  for (const key of [
    "mautic.inbox.ui.pending_network_failure",
    "mautic.inbox.ui.pending_channel_refused",
  ]) {
    assert.ok(twig.includes(`'${key}'`), `Twig does not expose ${key}`);
    const definition = new RegExp(`^${key.replace(/\./g, "\\.")}\\s*=`, "m");
    assert.match(english, definition, `en_US does not define ${key}`);
    assert.match(portuguese, definition, `pt_BR does not define ${key}`);
  }
});
