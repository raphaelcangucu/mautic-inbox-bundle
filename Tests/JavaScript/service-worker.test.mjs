import assert from "node:assert/strict";
import { readFile } from "node:fs/promises";
import test from "node:test";

/**
 * Exercita o worker COMPILADO num escopo falso — o mesmo padrao dos outros testes de bundle
 * deste repositorio. Testar a fonte deixaria passar um erro de build, e o arquivo que o
 * navegador executa e este.
 */
async function loadWorker() {
  const source = await readFile(
    new URL("../../Assets/dist/inbox-sw.js", import.meta.url),
    "utf8",
  );

  const listeners = new Map();
  const shown = [];
  const opened = [];
  const focused = [];
  let clients = [];

  const scope = {
    addEventListener: (name, handler) => listeners.set(name, handler),
    skipWaiting: async () => undefined,
    registration: {
      showNotification: async (title, options) => {
        shown.push({ title, options });
      },
    },
    clients: {
      claim: async () => undefined,
      matchAll: async () => clients,
      openWindow: async (url) => {
        opened.push(url);
      },
    },
  };

  const fn = new Function("self", `${source}\nreturn self;`);
  fn(scope);

  return {
    fire: (name, event) => listeners.get(name)?.(event),
    shown,
    opened,
    focused,
    setClients: (value) => {
      clients = value;
    },
  };
}

const settle = (waited) => Promise.all(waited);

test("push shows a notification tagged with the conversation", async () => {
  const worker = await loadWorker();
  const waited = [];

  worker.fire("push", {
    data: {
      json: () => ({
        title: "Ana Paula",
        body: "Bom dia",
        conversationId: 481,
      }),
    },
    waitUntil: (p) => waited.push(p),
  });
  await settle(waited);

  assert.equal(worker.shown.length, 1);
  assert.equal(worker.shown[0].title, "Ana Paula");
  assert.equal(worker.shown[0].options.body, "Bom dia");
  assert.match(worker.shown[0].options.tag, /481/);
});

test("a malformed payload still notifies instead of throwing", async () => {
  const worker = await loadWorker();
  const waited = [];

  worker.fire("push", {
    data: {
      json: () => {
        throw new SyntaxError("carga corrompida");
      },
    },
    waitUntil: (p) => waited.push(p),
  });
  await settle(waited);

  assert.equal(
    worker.shown.length,
    1,
    "sem showNotification o navegador exibe um aviso generico",
  );
  assert.ok(worker.shown[0].title.length > 0);
});

test("a visible window on that conversation suppresses the notification", async () => {
  const worker = await loadWorker();
  worker.setClients([
    {
      visibilityState: "visible",
      url: "https://exemplo.test/s/inbox/conversations/481",
      focus: async () => {},
    },
  ]);
  const waited = [];

  worker.fire("push", {
    data: { json: () => ({ title: "Ana Paula", conversationId: 481 }) },
    waitUntil: (p) => waited.push(p),
  });
  await settle(waited);

  assert.equal(
    worker.shown.length,
    0,
    "o SSE ja atualizou a tela; vibrar seria ruido",
  );
});

test("a visible window on a different conversation still notifies", async () => {
  const worker = await loadWorker();
  worker.setClients([
    {
      visibilityState: "visible",
      url: "https://exemplo.test/s/inbox/conversations/999",
      focus: async () => {},
    },
  ]);
  const waited = [];

  worker.fire("push", {
    data: { json: () => ({ title: "Ana Paula", conversationId: 481 }) },
    waitUntil: (p) => waited.push(p),
  });
  await settle(waited);

  assert.equal(
    worker.shown.length,
    1,
    "supressao vale so para a conversa que esta aberta",
  );
});

test("clicking focuses an open window instead of opening a second", async () => {
  const worker = await loadWorker();
  let focusedCount = 0;
  let navigatedTo = null;
  worker.setClients([
    {
      visibilityState: "hidden",
      url: "https://exemplo.test/s/inbox",
      focus: async () => {
        focusedCount += 1;
      },
      navigate: async (url) => {
        navigatedTo = url;
      },
    },
  ]);
  const waited = [];

  worker.fire("notificationclick", {
    notification: {
      close: () => {},
      data: { url: "/s/inbox/conversations/481" },
    },
    waitUntil: (p) => waited.push(p),
  });
  await settle(waited);

  assert.equal(focusedCount, 1);
  assert.equal(navigatedTo, "/s/inbox/conversations/481");
  assert.equal(
    worker.opened.length,
    0,
    "duas abas do mesmo inbox perdem o fio da conversa",
  );
});

test("clicking opens a window when none is open", async () => {
  const worker = await loadWorker();
  worker.setClients([]);
  const waited = [];

  worker.fire("notificationclick", {
    notification: {
      close: () => {},
      data: { url: "/s/inbox/conversations/481" },
    },
    waitUntil: (p) => waited.push(p),
  });
  await settle(waited);

  assert.deepEqual(worker.opened, ["/s/inbox/conversations/481"]);
});
