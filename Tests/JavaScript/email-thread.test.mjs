import assert from "node:assert/strict";
import { mkdtemp, readFile, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";
import { JSDOM } from "jsdom";

/**
 * A thread de e-mail montada de verdade: o vite compila os quatro componentes e o que eles
 * importam (as regras puras em emailThread.ts tem teste unitario proprio), e o teste olha o
 * que o atendente ve e o que sai no envio.
 */
const root = new URL("../../", import.meta.url);
const read = (path) => readFile(new URL(path, root), "utf8");

const { window } = new JSDOM(
  `<!doctype html><body><div class="inbox-app"><div id="host"></div></div></body>`,
  { url: "https://mautic.test/s/inbox" },
);
window.matchMedia = () => ({
  matches: false,
  addEventListener() {},
  removeEventListener() {},
});
Object.assign(globalThis, {
  window,
  document: window.document,
  location: window.location,
  Node: window.Node,
  Element: window.Element,
  HTMLElement: window.HTMLElement,
  HTMLIFrameElement: window.HTMLIFrameElement,
  HTMLInputElement: window.HTMLInputElement,
  HTMLSelectElement: window.HTMLSelectElement,
  HTMLTextAreaElement: window.HTMLTextAreaElement,
  HTMLOListElement: window.HTMLOListElement,
  HTMLMediaElement: window.HTMLMediaElement,
  Text: window.Text,
  Comment: window.Comment,
  DocumentFragment: window.DocumentFragment,
  MutationObserver: window.MutationObserver,
  Event: window.Event,
  CustomEvent: window.CustomEvent,
  KeyboardEvent: window.KeyboardEvent,
  requestAnimationFrame: (callback) => setTimeout(callback, 0),
});

async function compile() {
  const [{ build }, { svelte }] = await Promise.all([
    import("vite"),
    import("@sveltejs/vite-plugin-svelte"),
  ]);
  const out = await mkdtemp(join(tmpdir(), "inbox-email-"));
  await build({
    configFile: false,
    logLevel: "silent",
    plugins: [svelte({ emitCss: false })],
    build: {
      outDir: out,
      emptyOutDir: true,
      minify: false,
      lib: {
        entry: fileURLToPath(
          new URL("fixtures/email-components.ts", import.meta.url),
        ),
        formats: ["es"],
        fileName: () => "email.js",
      },
    },
  });
  return {
    module: await import(join(out, "email.js")),
    clean: () => rm(out, { recursive: true, force: true }),
  };
}

const ini = await read("Translations/pt_BR/messages.ini");
const labels = new Map(
  [...ini.matchAll(/^(mautic\.inbox\.[A-Za-z0-9_.]+)="([^"]*)"/gm)].map(
    (match) => [match[1], match[2]],
  ),
);
const t = (key) => {
  assert.ok(labels.has(key), `pt_BR does not define ${key}`);
  return labels.get(key);
};
const { module: ui, clean } = await compile();
test.after(clean);

const host = () => {
  const element = window.document.getElementById("host");
  element.replaceChildren();
  return element;
};
const settle = async () => {
  ui.flushSync();
  await ui.tick();
};

const conversation = (extra = {}) => ({
  id: 812,
  version: 3,
  lifecycle: "open",
  channel: "email",
  recipient: "joao@example.com",
  contact_name: "João Lead",
  asset: {
    id: 31,
    name: "Macro Markets",
    type: "email_account",
    address: "contato@macromarkets.com",
  },
  last_message_at: "2026-10-24T10:00:00Z",
  subject: "Re: Por que você ainda não depositou?",
  email: { inbound: "ok", inbound_since: null },
  can_reply: true,
  reply_hint: "Sua resposta será enviada pela conta Macro Markets",
  unread: 0,
  ...extra,
});

const email = (id, extra = {}) => ({
  id,
  kind: "message",
  direction: "inbound",
  status: "received",
  body: `Mensagem ${id} do lead`,
  subject: "Re: Por que você ainda não depositou?",
  timestamp: `2026-10-2${Math.min(id, 4)}T10:00:00Z`,
  email: {
    from: { name: "João Lead", address: "joao@example.com" },
    to: [{ name: "Macro Markets", address: "contato@macromarkets.com" }],
    cc: [],
    quoted: "> Em 23/10, Ana escreveu: ...",
    has_html: false,
    has_original: false,
    auto_reply: false,
    unverified_sender: false,
    kind: "message",
  },
  attachments: [],
  ...extra,
});

test("the thread shows the subject, the account and folds earlier messages behind one line", async () => {
  const target = host();
  const items = [1, 2, 3, 4, 5, 6].map((id) => email(id));
  const app = ui.mount(ui.EmailThread, {
    target,
    props: {
      items,
      selected: conversation(),
      locale: "pt-BR",
      t,
      onOlder: async () => {},
      retry() {},
    },
  });
  await settle();
  assert.equal(
    target.querySelector("#inbox-email-subject").textContent.trim(),
    "Re: Por que você ainda não depositou?",
  );
  assert.equal(
    target.querySelector("#inbox-email-account").textContent.trim(),
    "Macro Markets · contato@macromarkets.com",
  );
  const group = target.querySelector(".inbox-email-group");
  assert.equal(group.textContent.trim(), "5 mensagens anteriores");
  assert.equal(target.querySelectorAll(".inbox-email-card").length, 1);
  assert.equal(
    target.querySelectorAll(".inbox-email-card.expanded").length,
    1,
    "so a ultima abre",
  );
  group.click();
  await settle();
  assert.equal(target.querySelectorAll(".inbox-email-card").length, 6);
  assert.equal(target.querySelector("#inbox-email-delayed"), null);
  ui.unmount(app);
});

test("a delayed mailbox shows a discreet banner, and form events sit between the cards", async () => {
  const target = host();
  const app = ui.mount(ui.EmailThread, {
    target,
    props: {
      items: [
        email(1),
        {
          id: 50,
          kind: "event",
          event: "form_submitted",
          form: { id: 12, name: "Pesquisa" },
          answers: [{ label: "Por quê?", value: "Ainda estou decidindo" }],
          timestamp: "2026-10-24T11:00:00Z",
        },
        email(2),
      ],
      selected: conversation({
        email: { inbound: "delayed", inbound_since: "2026-10-24T09:00:00Z" },
      }),
      locale: "pt-BR",
      t,
      onOlder: async () => {},
      retry() {},
    },
  });
  await settle();
  assert.match(
    target.querySelector("#inbox-email-delayed").textContent,
    /leitura desta caixa está atrasada/,
  );
  const event = target.querySelector(".inbox-message.event");
  assert.match(event.textContent, /Respondeu o formulário Pesquisa/);
  assert.match(event.textContent, /Ainda estou decidindo/);
  ui.unmount(app);
});

test("a card opens and closes, reveals the quoted text, and lists every attachment", async () => {
  const target = host();
  const originals = [];
  let expanded = false;
  const props = {
    item: email(7, {
      email: {
        ...email(7).email,
        has_original: true,
        unverified_sender: true,
        cc: [{ name: "", address: "socio@example.com" }],
      },
      attachments: [
        {
          type: "document",
          label: "extrato.pdf",
          url: "https://mautic.test/s/inbox/api/email/7/attachments/0",
          available: true,
          size: 183422,
          mime: "application/pdf",
        },
        {
          type: "document",
          label: "virus.exe",
          url: null,
          available: false,
          size: 10,
          mime: "application/octet-stream",
        },
      ],
    }),
    expanded,
    threadSubject: "Re: Por que você ainda não depositou?",
    locale: "pt-BR",
    t,
    onToggle: () => {
      expanded = !expanded;
    },
    onOriginal: (item) => originals.push(item.id),
  };
  let app = ui.mount(ui.EmailCard, { target, props });
  await settle();
  assert.match(
    target.querySelector(".inbox-email-snippet").textContent,
    /Mensagem 7 do lead/,
    "recolhido mostra o trecho",
  );
  target.querySelector(".inbox-email-card-head").click();
  assert.equal(expanded, true);
  ui.unmount(app);

  app = ui.mount(ui.EmailCard, { target, props: { ...props, expanded: true } });
  await settle();
  assert.match(
    target.querySelector(".inbox-email-warning").textContent,
    /Não foi possível verificar o remetente/,
  );
  assert.equal(target.querySelector(".inbox-email-quoted"), null);
  target.querySelector(".inbox-email-quoted-toggle").click();
  await settle();
  assert.match(
    target.querySelector(".inbox-email-quoted").textContent,
    /Ana escreveu/,
  );
  const attachments = target.querySelectorAll(".inbox-email-attachments li");
  assert.equal(attachments.length, 2);
  assert.equal(
    attachments[0].querySelector("a").getAttribute("href"),
    "https://mautic.test/s/inbox/api/email/7/attachments/0",
  );
  assert.match(attachments[0].textContent, /179 KB/);
  assert.equal(attachments[1].classList.contains("unavailable"), true);
  assert.equal(attachments[1].querySelector("a"), null);
  target.querySelector(".inbox-email-original").click();
  assert.deepEqual(originals, [7]);
  target.querySelector(".inbox-email-details-toggle").click();
  await settle();
  assert.match(
    target.querySelector(".inbox-email-details").textContent,
    /socio@example\.com/,
  );
  ui.unmount(app);
});

test("the HTML body is a scriptless sandboxed iframe with remote images held back", async () => {
  const target = host();
  const app = ui.mount(ui.EmailBody, {
    target,
    props: {
      html: '<p>Oi</p><img src="https://tracker.test/pixel.gif">',
      t,
      title: "Proposta",
    },
  });
  await settle();
  const frame = target.querySelector("iframe");
  assert.equal(
    frame.getAttribute("sandbox"),
    "allow-same-origin allow-popups allow-popups-to-escape-sandbox",
  );
  assert.doesNotMatch(frame.getAttribute("sandbox"), /allow-scripts/);
  assert.match(
    frame.getAttribute("srcdoc"),
    /img-src data: https:\/\/mautic\.test;/,
  );
  target.querySelector(".inbox-email-images button").click();
  await settle();
  assert.match(frame.getAttribute("srcdoc"), /https: http:/);
  assert.equal(target.querySelector(".inbox-email-images"), null);
  ui.unmount(app);
});

test("replying opens the Gmail-style composer with a Re: subject, inserts a form and sends the subject", async () => {
  const target = host();
  const sent = [];
  let inputs = 0;
  const app = ui.mount(ui.EmailComposer, {
    target,
    props: {
      selected: conversation(),
      body: "",
      threadSubject: "Por que você ainda não depositou?",
      canned: [{ id: 3, name: "Saudação", body: "Olá!" }],
      t,
      onMode() {},
      onInput: () => inputs++,
      onSend: (subject) =>
        sent.push({
          subject,
          body: target.querySelector("#inbox-composer-text").value,
        }),
      loadForms: async () => [{ id: 12, name: "Pesquisa" }],
    },
  });
  await settle();
  assert.equal(target.querySelector("#inbox-composer-text"), null, "fechado");
  target.querySelector("#inbox-email-reply").click();
  await settle();
  assert.equal(
    target
      .querySelector("#inbox-email-to")
      .textContent.replace(/\s+/g, " ")
      .trim(),
    "João Lead <joao@example.com>",
  );
  assert.equal(
    target.querySelector("#inbox-email-from").textContent.trim(),
    "Macro Markets · contato@macromarkets.com",
  );
  assert.equal(
    target.querySelector("#inbox-email-subject-input").value,
    "Re: Por que você ainda não depositou?",
  );
  const field = target.querySelector("#inbox-composer-text");
  field.value = "Oi João";
  field.dispatchEvent(new window.Event("input", { bubbles: true }));
  target.querySelector("#inbox-email-insert").click();
  await settle();
  await new Promise((resolve) => setTimeout(resolve, 0));
  await settle();
  const forms = target.querySelector("#inbox-email-forms");
  assert.equal(forms.disabled, false);
  forms.value = "12";
  forms.dispatchEvent(new window.Event("change", { bubbles: true }));
  await settle();
  assert.equal(
    target.querySelector("#inbox-composer-text").value,
    "Oi João\n[[form:12]]",
  );
  const subject = target.querySelector("#inbox-email-subject-input");
  subject.value = "Assunto novo";
  subject.dispatchEvent(new window.Event("input", { bubbles: true }));
  await settle();
  target.querySelector("#inbox-send").click();
  assert.deepEqual(sent, [
    { subject: "Assunto novo", body: "Oi João\n[[form:12]]" },
  ]);
  ui.unmount(app);
});

test("a conversation the agent cannot answer keeps Reply closed and says why", async () => {
  const target = host();
  const app = ui.mount(ui.EmailComposer, {
    target,
    props: {
      selected: conversation({
        can_reply: false,
        can_take_and_reply: false,
        reply_blocked_reason: "O contato pediu para não receber e-mails.",
        reply_hint: "O contato pediu para não receber e-mails.",
      }),
      body: "",
      threadSubject: "Proposta",
      t,
      onMode() {},
      onInput() {},
      onSend() {},
    },
  });
  await settle();
  assert.equal(target.querySelector("#inbox-email-reply").disabled, true);
  assert.match(
    target.querySelector("#inbox-reply-hint").textContent,
    /não receber e-mails/,
  );
  ui.unmount(app);
});

test("the list draws the envelope, the channel label, the subject and offers the email filter", async () => {
  const target = host();
  const app = ui.mount(ui.ConversationList, {
    target,
    props: {
      rows: [
        {
          ...conversation(),
          preview: "Oi Ana, ainda estou decidindo.",
        },
      ],
      filtersOpen: true,
      locale: "pt-BR",
      t,
      onSelect() {},
      onQueue() {},
      onFilters() {},
      onFilter() {},
      onSearch() {},
      onMore() {},
    },
  });
  await settle();
  assert.ok(
    target.querySelector('.inbox-channel-dot.email [data-icon="email"] path'),
  );
  assert.match(
    target.querySelector(".inbox-list-meta").textContent,
    /E-mail · contato@macromarkets\.com/,
  );
  assert.equal(
    target.querySelector(".inbox-list-subject").textContent.trim(),
    "Re: Por que você ainda não depositou?",
  );
  assert.ok(target.querySelector('#inbox-channel option[value="email"]'));
  ui.unmount(app);
});
