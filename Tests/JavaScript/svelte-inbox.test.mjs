import assert from "node:assert/strict";
import { access, readFile } from "node:fs/promises";
import test from "node:test";
import { JSDOM } from "jsdom";

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

test("Inbox Twig exposes every API contract to the shared Svelte bundle", async () => {
  const read = (path) =>
    readFile(new URL(`../../${path}`, import.meta.url), "utf8");
  // O contrato mora no parcial, compartilhado pela tela do Mautic e pelo shell do app —
  // ler os tres garante que nenhum dos dois caminhos perca um atributo.
  const twig = await read("Resources/views/Inbox/_root.html.twig");
  const shell = await read("Resources/views/App/shell.html.twig");
  assert.match(
    shell,
    /_root\.html\.twig/,
    "o shell precisa incluir o mesmo parcial",
  );
  assert.match(
    shell,
    /PwaAssetsSubscriber/,
    "o shell precisa apontar para o manifest",
  );
  assert.match(
    await read("Resources/views/Inbox/index.html.twig"),
    /Assets\/dist\/inbox-app\.js/,
  );
  assert.match(shell, /Assets\/dist\/inbox-app\.js/);
  assert.doesNotMatch(
    await read("Resources/views/Inbox/index.html.twig"),
    /Assets\/js\/inbox\.js/,
  );
  for (const endpoint of [
    "ai",
    "ai-retry",
    "list",
    "detail",
    "timeline",
    "poll",
    "take",
    "state",
    "templates",
    "reply",
    "retry",
    "note",
    "draft",
    "canned",
    "canned-item",
    "push-config",
    "push-subscriptions",
  ]) {
    assert.match(twig, new RegExp(`data-${endpoint}-url=`));
  }
  await assert.rejects(
    access(new URL("../../Assets/js/inbox.js", import.meta.url)),
  );

  const source = (
    await Promise.all(
      [
        "InboxApp",
        "AutomationView",
        "Composer",
        "ContactPanel",
        "ConversationList",
        "MediaAttachment",
        "MessageBubble",
        "SettingsView",
        "Timeline",
      ].map((name) => read(`Frontend/inbox/${name}.svelte`)),
    )
  ).join("\n");
  const keys = new Set(
    [...source.matchAll(/["'](mautic\.inbox\.[A-Za-z0-9_.]+)["']/g)].map(
      (match) => match[1],
    ),
  );
  const [english, portuguese] = await Promise.all([
    read("Translations/en_US/messages.ini"),
    read("Translations/pt_BR/messages.ini"),
  ]);
  for (const key of keys) {
    assert.ok(twig.includes(`'${key}'`), `Twig does not expose ${key}`);
    const definition = new RegExp(
      `^${key.replace(/[.*+?^${}()|[\]\\]/g, "\\$&")}\\s*=`,
      "m",
    );
    assert.match(english, definition, `en_US does not define ${key}`);
    assert.match(portuguese, definition, `pt_BR does not define ${key}`);
  }
  for (const hook of [
    'id="inbox-composer-text"',
    'id="inbox-template-fields"',
    'id="inbox-template-status"',
    'class="inbox-secondary inbox-composer-channel"',
    'class="inbox-secondary inbox-assignee-label"',
  ]) {
    assert.ok(source.includes(hook), `legacy visual hook missing: ${hook}`);
  }
});

test("the compiled Svelte inbox mounts, loads, selects and releases its root", async () => {
  const { window } = new JSDOM(
    `<!doctype html><html><head><link rel="icon" href="/favicon.ico"></head><body>
    <div id="app-wrapper"></div>
    <div id="inbox-app" class="inbox-app"
      data-index-url="https://mautic.test/inbox"
      data-conversation-url="https://mautic.test/inbox/conversations/0"
      data-list-url="https://mautic.test/inbox/api/conversations"
      data-detail-url="https://mautic.test/inbox/api/conversations/0"
      data-timeline-url="https://mautic.test/inbox/api/conversations/0/history"
      data-poll-url="https://mautic.test/inbox/api/updates"
      data-state-url="https://mautic.test/inbox/api/conversations/0/state"
      data-draft-url="https://mautic.test/inbox/api/conversations/0/draft"
      data-ai-url="https://mautic.test/inbox/api/conversations/0/ai"
      data-stream-url=""
      data-csrf="csrf-token"
      data-current-user="7"
      data-bootstrap='{"users":[{"id":7,"name":"Operator"}],"automationRules":[],"channelNotices":[],"canManageCanned":true,"isAdmin":true}'
    ></div>
  </body></html>`,
    { url: "https://mautic.test/inbox" },
  );

  const { location, history } = window;
  const requests = [];
  const conversation = {
    id: 11,
    version: 2,
    lifecycle: "open",
    channel: "instagram",
    recipient: "123",
    contact_name: "Maria Silva",
    asset: { id: 3, name: "Conta" },
    last_message_at: new Date().toISOString(),
    assignee: null,
    drafts: {},
    origins: [],
    can_reply: false,
    can_take_and_reply: true,
    reply_hint: "Responder atribui a conversa.",
    human_takeover: false,
  };
  const response = (body, status = 200) =>
    new Response(JSON.stringify(body), {
      status,
      headers: { "Content-Type": "application/json" },
    });
  const fetch = async (input, options = {}) => {
    const url = String(input);
    requests.push([url, options.method || "GET", new Headers(options.headers)]);
    if (url.includes("/updates"))
      return response({
        next_since: new Date().toISOString(),
        notification_cursor: 0,
        notifications: [],
      });
    if (/\/conversations\/11\/history/.test(url))
      return response({ items: [], next_cursor: null });
    if (/\/conversations\/11\/ai/.test(url))
      return response({
        agents: [],
        can_assign: false,
        assignment: null,
        pending_reply: null,
        version: 2,
      });
    if (/\/conversations\/11\/state/.test(url)) return response(conversation);
    if (/\/conversations\/11$/.test(url)) return response(conversation);
    if (url.includes("/api/conversations?"))
      return response({
        items: [conversation],
        next_cursor: null,
        counts: { mine: 0, unassigned: 1, all: 1 },
      });
    return response({});
  };

  Object.assign(window, {
    fetch,
    scrollTo() {},
    matchMedia: () => ({
      matches: false,
      addEventListener() {},
      removeEventListener() {},
    }),
  });
  class AudioContextStub {
    state = "suspended";
    currentTime = 0;
    destination = {};
    async resume() {
      this.state = "running";
    }
    async close() {
      this.state = "closed";
    }
    createOscillator() {
      return {
        frequency: { value: 0 },
        connect() {},
        start() {},
        stop() {},
      };
    }
    createGain() {
      return {
        gain: {
          setValueAtTime() {},
          linearRampToValueAtTime() {},
          exponentialRampToValueAtTime() {},
        },
        connect() {},
      };
    }
  }
  Object.defineProperty(window, "AudioContext", {
    value: AudioContextStub,
    configurable: true,
  });
  Object.assign(globalThis, {
    window,
    document: window.document,
    location,
    history,
    fetch,
    Node: window.Node,
    Element: window.Element,
    HTMLElement: window.HTMLElement,
    Text: window.Text,
    Comment: window.Comment,
    DocumentFragment: window.DocumentFragment,
    HTMLOListElement: window.HTMLOListElement,
    HTMLMediaElement: window.HTMLMediaElement,
    HTMLInputElement: window.HTMLInputElement,
    HTMLSelectElement: window.HTMLSelectElement,
    HTMLTextAreaElement: window.HTMLTextAreaElement,
    Event: window.Event,
    CustomEvent: window.CustomEvent,
    MutationObserver: window.MutationObserver,
    Image: window.Image,
    localStorage: window.localStorage,
    matchMedia: window.matchMedia,
  });
  Object.defineProperty(globalThis, "navigator", {
    value: window.navigator,
    configurable: true,
  });

  await import(`../../Assets/dist/inbox-app.js?test=${Date.now()}`);
  await tick();
  await tick();

  const root = document.getElementById("inbox-app");
  assert.equal(root.dataset.svelteInboxMounted, "1");
  assert.match(root.textContent, /Maria Silva/);
  root
    .querySelector(".inbox-list-item")
    .dispatchEvent(new window.Event("click", { bubbles: true }));
  await tick();
  await tick();
  await tick();
  assert.equal(location.pathname, "/inbox/conversations/11");
  assert.ok(root.classList.contains("has-selection"));
  assert.ok(requests.some(([url]) => /\/conversations\/11\/history/.test(url)));
  assert.ok(
    requests.some(
      ([url, method]) =>
        /\/conversations\/11\/state/.test(url) && method === "POST",
    ),
  );
  const mutation = requests.find(
    ([url, method]) =>
      /\/conversations\/11\/state/.test(url) && method === "POST",
  );
  assert.equal(mutation[2].get("X-CSRF-Token"), "csrf-token");

  root
    .querySelector(".inbox-settings-tab")
    .dispatchEvent(new window.Event("click", { bubbles: true }));
  await tick();
  const sound = root.querySelector("#inbox-sound");
  sound.dispatchEvent(new window.Event("pointerdown", { bubbles: true }));
  sound.dispatchEvent(new window.Event("click", { bubbles: true }));
  await tick();
  await tick();
  assert.equal(sound.getAttribute("aria-pressed"), "true");

  root
    .querySelector(".inbox-tabs button")
    .dispatchEvent(new window.Event("click", { bubbles: true }));
  await tick();
  root
    .querySelector(".inbox-list-item")
    .dispatchEvent(new window.Event("click", { bubbles: true }));
  await tick();
  await tick();
  const composer = root.querySelector("#inbox-composer-text");
  composer.value = "Rascunho ainda no debounce";
  composer.dispatchEvent(new window.Event("input", { bubbles: true }));

  root.remove();
  await tick();
  await tick();
  assert.equal(root.dataset.svelteInboxMounted, undefined);
  assert.ok(
    requests.some(
      ([url, method]) =>
        /\/conversations\/11\/draft/.test(url) && method === "PUT",
    ),
    "unmount flushes the draft that has not reached its debounce yet",
  );
  window.close();
});
