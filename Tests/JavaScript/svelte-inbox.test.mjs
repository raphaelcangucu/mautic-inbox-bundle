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
      data-take-url="https://mautic.test/inbox/api/conversations/0/take"
      data-reply-url="https://mautic.test/inbox/api/conversations/0/reply"
      data-note-url="https://mautic.test/inbox/api/conversations/0/note"
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
    // Com nao lidas: abrir precisa marcar como lida. Sem elas o POST nao sai mais, de
    // proposito — era uma ida ao servidor a toa em toda reabertura.
    unread: 2,
    reply_hint: "Responder atribui a conversa.",
    human_takeover: false,
  };
  const response = (body, status = 200) =>
    new Response(JSON.stringify(body), {
      status,
      headers: { "Content-Type": "application/json" },
    });
  // O envio fica pendurado ate o teste soltar. Sem isto nao da para distinguir "a bolha
  // aparece na hora" de "a bolha aparece quando o servidor responde", que e a mudanca inteira.
  let soltarEnvio = () => {};
  const respostaDoEnvio = () =>
    new Promise((ok) => {
      soltarEnvio = () =>
        ok(
          response({
            request_id: "req-1",
            status: "pending",
            item: {
              id: 92,
              kind: "outbound",
              direction: "outbound",
              body: "Resposta confirmada pelo servidor",
              status: "pending",
              request_id: "req-1",
              timestamp: "2026-09-17T12:05:00Z",
            },
          }),
        );
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
      return response({
        items: [
          {
            id: 91,
            kind: "message",
            direction: "inbound",
            body: "Mensagem que precisa chegar a tela",
            timestamp: "2026-09-17T12:00:00Z",
          },
        ],
        next_cursor: null,
      });
    if (/\/conversations\/11\/ai/.test(url))
      return response({
        agents: [],
        can_assign: false,
        assignment: null,
        pending_reply: null,
        version: 2,
      });
    // A conversa do teste tem can_take_and_reply, entao o envio passa pelo take antes de sair.
    // Sem esta rota o stub devolvia {} e o componente trocava a conversa por um objeto vazio.
    if (/\/conversations\/11\/take/.test(url))
      return response({
        ...conversation,
        assignee: { id: 7, name: "Operator" },
      });
    if (/\/conversations\/11\/reply/.test(url)) return respostaDoEnvio();
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
  // O historico agora e LIDO da store em vez de escrito numa variavel do componente, e esta
  // afirmacao e o que prova que aquele caminho chega ao DOM. Sem ela a store poderia guardar
  // tudo certo e a tela ficar em branco, com a suite verde — que foi exatamente o estado em
  // que este teste passou por um tempo, afirmando so o nome do contato.
  //
  // A espera e por prazo, e nao por um numero de ticks: a abertura dispara varias idas e
  // contar voltas do laco daria um teste que passa ou falha conforme o dia.
  for (let volta = 0; volta < 200; volta += 1) {
    if (/Mensagem que precisa chegar a tela/.test(root.textContent)) break;
    await tick();
  }
  assert.match(
    root.textContent,
    /Mensagem que precisa chegar a tela/,
    "o historico da store precisa ser renderizado, e nao so guardado",
  );
  assert.ok(requests.some(([url]) => /\/conversations\/11\/history/.test(url)));
  for (let volta = 0; volta < 60; volta += 1) {
    if (
      requests.some(
        ([url, method]) =>
          /\/conversations\/11\/state/.test(url) && method === "POST",
      )
    )
      break;
    await tick();
  }
  assert.ok(
    requests.some(
      ([url, method]) =>
        /\/conversations\/11\/state/.test(url) && method === "POST",
    ),
    "abrir uma conversa com nao lidas precisa marca-la como lida",
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

  // O envio otimista, que e a razao de tudo isto existir: a bolha na tela e o composer vazio
  // enquanto o servidor ainda nem respondeu.
  composer.value = "Mensagem otimista";
  composer.dispatchEvent(new window.Event("input", { bubbles: true }));
  await tick();
  console.log(
    "ANTES DO CLIQUE",
    JSON.stringify({
      valor: root.querySelector("#inbox-composer-text").value,
      desabilitado: root.querySelector("#inbox-send").disabled,
    }),
  );
  root
    .querySelector("#inbox-send")
    .dispatchEvent(new window.Event("click", { bubbles: true }));
  await tick();

  console.log(
    "DEBUG",
    JSON.stringify({
      temSend: !!root.querySelector("#inbox-send"),
      desabilitado: root.querySelector("#inbox-send")?.disabled,
      valorComposer: root.querySelector("#inbox-composer-text")?.value,
      temTimeline: !!root.querySelector(".inbox-timeline"),
      pedidosReply: requests.filter(([u]) => /reply/.test(u)).length,
    }),
  );
  assert.ok(
    root.querySelector(".inbox-pending"),
    "com o /reply ainda pendurado, a mensagem ja precisa estar na conversa",
  );
  assert.match(root.textContent, /Mensagem otimista/);
  assert.equal(
    root.querySelector("#inbox-composer-text").value,
    "",
    "e o composer esvazia junto, senao o atendente manda duas vezes",
  );
  // A trava saiu. O botao esta desabilitado agora porque o campo ficou vazio — e nao porque um
  // envio esta em voo. Escrever de novo, com o primeiro /reply ainda pendurado, e o que
  // distingue as duas coisas.
  const campo = root.querySelector("#inbox-composer-text");
  campo.value = "E outra por cima";
  campo.dispatchEvent(new window.Event("input", { bubbles: true }));
  await tick();
  assert.equal(
    root.querySelector("#inbox-send").disabled,
    false,
    "com um envio em voo o botao continua disponivel: e a premissa do desenho",
  );
  campo.value = "";
  campo.dispatchEvent(new window.Event("input", { bubbles: true }));
  await tick();

  soltarEnvio();
  for (let volta = 0; volta < 200; volta += 1) {
    if (/Resposta confirmada pelo servidor/.test(root.textContent)) break;
    await tick();
  }
  assert.match(
    root.textContent,
    /Resposta confirmada pelo servidor/,
    "o item que o servidor devolveu entra no historico",
  );
  assert.equal(
    root.querySelector(".inbox-pending"),
    null,
    "e a pendente SAI — ficar seria a mensagem duas vezes na tela",
  );

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
