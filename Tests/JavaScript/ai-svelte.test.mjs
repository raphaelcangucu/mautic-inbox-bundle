import assert from "node:assert/strict";
import test from "node:test";
import { readFile } from "node:fs/promises";
import { JSDOM } from "jsdom";

const root = new URL("../../", import.meta.url);
const read = (path) => readFile(new URL(path, root), "utf8");

test("AI Twig mounts the shared Svelte bundle without the legacy controller", async () => {
  const twig = await read("Resources/views/Ai/index.html.twig");
  assert.match(twig, /id="inbox-ai"/);
  assert.match(twig, /Assets\/dist\/inbox-app\.js/);
  assert.doesNotMatch(twig, /Assets\/js\/ai\.js/);
  assert.match(twig, /data-inbox-url=/);
  assert.match(twig, /'eyebrow'/);
  assert.match(twig, /'documents_hint'/);
  assert.match(twig, /'permissions_hint'/);
});

test("AI Svelte workspace keeps every admin action and active production hook", async () => {
  const [
    app,
    documents,
    pi,
    agents,
    permissions,
    styles,
    twig,
    english,
    portuguese,
  ] = await Promise.all([
    read("Frontend/ai/AiApp.svelte"),
    read("Frontend/ai/DocumentsView.svelte"),
    read("Frontend/ai/PiView.svelte"),
    read("Frontend/ai/AgentsView.svelte"),
    read("Frontend/ai/PermissionGrid.svelte"),
    read("Assets/css/ai.css"),
    read("Resources/views/Ai/index.html.twig"),
    read("Translations/en_US/messages.ini"),
    read("Translations/pt_BR/messages.ini"),
  ]);
  const source = [app, documents, pi, agents, permissions].join("\n");

  for (const action of [
    "document",
    "agent",
    "config",
    "install",
    "import-auth",
    "health",
    "validate",
  ]) {
    assert.match(
      source,
      new RegExp(`['\"]${action}['\"]`),
      `missing ${action} action`,
    );
  }
  for (const id of [
    "ai-new-doc",
    "ai-doc-list",
    "ai-doc-name",
    "ai-doc-scope",
    "ai-doc-body",
    "ai-doc-preview",
    "ai-doc-versions",
    "ai-doc-save",
    "ai-doc-publish",
    "ai-model",
    "ai-global-limit",
    "ai-global-enabled",
    "ai-global-permissions",
    "ai-agent-select",
    "ai-agent-name",
    "ai-agent-profile",
    "ai-agent-limit",
    "ai-agent-enabled",
    "ai-agent-docs",
    "ai-agent-context",
    "ai-agent-permissions",
    "ai-agent-save-top",
  ]) {
    assert.match(source, new RegExp(`id=['\"]${id}['\"]`), `missing #${id}`);
  }
  for (const id of [
    "ai-doc-edit",
    "ai-doc-preview-button",
    "ai-doc-versions-button",
  ]) {
    assert.ok(source.includes(`"${id}"`), `missing #${id}`);
  }
  assert.match(permissions, /globalPermissions\.includes/);
  assert.match(app, /data-ai-tab=/);
  assert.match(
    app,
    /permissions\.filter\(\(permission\) =>\s*data\.config\.permissions\.includes/,
  );
  assert.match(
    styles,
    /\.ai-agent-toolbar-actions\{display:flex;align-items:center;justify-content:flex-end/,
    "the responsive top save action from the active plugin remains styled",
  );
  const labelKeys = new Set(
    [...source.matchAll(/\bt\(["']([A-Za-z0-9_]+)["']\)/g)].map(
      (match) => match[1],
    ),
  );
  for (const key of labelKeys) {
    assert.ok(twig.includes(`'${key}'`), `AI Twig does not expose ${key}`);
    const translationKey = `mautic.inbox.ai.${key}`.replace(
      /[.*+?^${}()|[\]\\]/g,
      "\\$&",
    );
    assert.match(
      english,
      new RegExp(`^${translationKey}\\s*=`, "m"),
      `en_US does not define mautic.inbox.ai.${key}`,
    );
    assert.match(
      portuguese,
      new RegExp(`^${translationKey}\\s*=`, "m"),
      `pt_BR does not define mautic.inbox.ai.${key}`,
    );
  }
});

test("the compiled AI workspace loads data and sends authenticated admin actions", async () => {
  const { window } = new JSDOM(
    `<!doctype html><html><body><div id="inbox-ai"
      data-url="https://mautic.test/inbox/ai/data"
      data-action="https://mautic.test/inbox/ai/action"
      data-inbox-url="https://mautic.test/inbox"
      data-csrf="csrf-token"></div></body></html>`,
    { url: "https://mautic.test/inbox/ai" },
  );
  const data = {
    documents: [
      {
        key: "support",
        revision: 1,
        draft: { name: "support.md", body: "# Help", scope: "global" },
        published: {
          version: 1,
          date: "2026-09-15T12:00:00Z",
          name: "support.md",
          body: "# Help",
          scope: "global",
        },
        versions: [],
      },
    ],
    agents: [
      {
        key: "agent-one",
        revision: 1,
        name: "Agent One",
        profile: "macro-support",
        enabled: true,
        limit: 0,
        documents: [],
        permissions: ["9:message"],
      },
    ],
    assets: [{ id: 9, name: "WhatsApp", channel: "whatsapp" }],
    installed: true,
    config: {
      enabled: true,
      model: "gpt-test",
      limit: 0,
      permissions: ["9:message"],
    },
    health: {
      authenticated: true,
      validated: true,
      models: [{ id: "gpt-test", name: "GPT Test" }],
    },
  };
  const requests = [];
  const response = (body) =>
    new Response(JSON.stringify(body), {
      headers: { "Content-Type": "application/json" },
    });
  const fetch = async (input, options = {}) => {
    const url = String(input);
    requests.push({
      url,
      method: options.method || "GET",
      headers: new Headers(options.headers),
      body: options.body ? JSON.parse(String(options.body)) : null,
    });
    return url.endsWith("/action") ? response({ ok: true }) : response(data);
  };
  Object.assign(window, { fetch, confirm: () => true });
  Object.assign(globalThis, {
    window,
    document: window.document,
    location: window.location,
    history: window.history,
    fetch,
    Node: window.Node,
    Element: window.Element,
    HTMLElement: window.HTMLElement,
    HTMLInputElement: window.HTMLInputElement,
    HTMLSelectElement: window.HTMLSelectElement,
    HTMLTextAreaElement: window.HTMLTextAreaElement,
    HTMLMediaElement: window.HTMLMediaElement,
    HTMLOListElement: window.HTMLOListElement,
    Text: window.Text,
    Comment: window.Comment,
    DocumentFragment: window.DocumentFragment,
    Event: window.Event,
    CustomEvent: window.CustomEvent,
    MutationObserver: window.MutationObserver,
    Image: window.Image,
    localStorage: window.localStorage,
  });

  await import(`../../Assets/dist/inbox-app.js?ai-test=${Date.now()}`);
  await new Promise((resolve) => setTimeout(resolve, 0));
  await new Promise((resolve) => setTimeout(resolve, 0));

  const app = document.getElementById("inbox-ai");
  assert.equal(app.dataset.svelteInboxMounted, "1");
  assert.match(app.textContent, /support\.md/);
  app
    .querySelector('[data-ai-tab="agents"]')
    .dispatchEvent(new window.Event("click", { bubbles: true }));
  await new Promise((resolve) => setTimeout(resolve, 0));
  app
    .querySelector("#ai-agent-save-top")
    .dispatchEvent(new window.Event("click", { bubbles: true }));
  await new Promise((resolve) => setTimeout(resolve, 0));
  await new Promise((resolve) => setTimeout(resolve, 0));
  const agentSave = requests.find(
    (request) => request.body?.action === "agent",
  );
  assert.ok(
    agentSave,
    "the active top save button persists the selected agent",
  );
  assert.equal(agentSave.headers.get("X-CSRF-Token"), "csrf-token");
  assert.equal(agentSave.body.limit, 0);
  app
    .querySelector('[data-ai-tab="pi"]')
    .dispatchEvent(new window.Event("click", { bubbles: true }));
  await new Promise((resolve) => setTimeout(resolve, 0));
  const limit = app.querySelector("#ai-global-limit");
  limit.value = "4";
  limit.dispatchEvent(new window.Event("input", { bubbles: true }));
  app
    .querySelector("#ai-config-save")
    .dispatchEvent(new window.Event("click", { bubbles: true }));
  await new Promise((resolve) => setTimeout(resolve, 0));
  await new Promise((resolve) => setTimeout(resolve, 0));
  const configSave = requests.find(
    (request) => request.body?.action === "config",
  );
  assert.equal(configSave?.body.limit, 4);
  app
    .querySelector('[data-pi-action="health"]')
    .dispatchEvent(new window.Event("click", { bubbles: true }));
  await new Promise((resolve) => setTimeout(resolve, 0));
  await new Promise((resolve) => setTimeout(resolve, 0));

  const action = requests.find((request) => request.body?.action === "health");
  assert.ok(action);
  assert.equal(action.body.action, "health");
  assert.equal(action.headers.get("X-CSRF-Token"), "csrf-token");
  assert.ok(
    requests.filter((request) => request.method === "GET").length >= 2,
    "the workspace refreshes after an admin action",
  );

  app.remove();
  await new Promise((resolve) => setTimeout(resolve, 0));
  assert.equal(app.dataset.svelteInboxMounted, undefined);
  window.close();
});
