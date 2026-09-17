import assert from "node:assert/strict";
import { mkdtemp, rm } from "node:fs/promises";
import { tmpdir } from "node:os";
import { join } from "node:path";
import test from "node:test";
import { fileURLToPath } from "node:url";

/**
 * A casca vive num `.svelte.ts` e `$state` so existe depois do compilador, entao o `tsx` que
 * roda os `.unit.test.ts` nao alcanca este arquivo. Compilar aqui com o mesmo vite do build e
 * o que faz o teste exercitar o modulo de verdade em vez de uma copia da logica dele.
 *
 * O caminho do reducer ja tem teste proprio para "merge junta mantendo o que ja estava". O que
 * falta cobrir, e so existe aqui, e a ESCOLHA do modo: o historico que vem no poll e um
 * incremento da conversa selecionada, e entra por merge. Um "replace" nesse ponto apagaria o
 * historico inteiro a cada tique, e nenhum teste de reducer veria isso acontecer.
 */
async function compilarStore() {
  const [{ build }, { svelte }] = await Promise.all([
    import("vite"),
    import("@sveltejs/vite-plugin-svelte"),
  ]);
  const saida = await mkdtemp(join(tmpdir(), "inbox-store-"));
  await build({
    configFile: false,
    logLevel: "silent",
    plugins: [svelte({ emitCss: false })],
    build: {
      outDir: saida,
      emptyOutDir: true,
      minify: false,
      lib: {
        entry: fileURLToPath(
          new URL(
            "../../Frontend/shared/inboxStore.svelte.ts",
            import.meta.url,
          ),
        ),
        formats: ["es"],
        fileName: () => "inboxStore.js",
      },
    },
  });

  return {
    modulo: await import(join(saida, "inboxStore.js")),
    limpar: () => rm(saida, { recursive: true, force: true }),
  };
}

const URLS = {
  list: "https://mautic.test/inbox/api/conversations",
  detail: "https://mautic.test/inbox/api/conversations/0",
  timeline: "https://mautic.test/inbox/api/conversations/0/history",
  ai: "https://mautic.test/inbox/api/conversations/0/ai",
  state: "https://mautic.test/inbox/api/conversations/0/state",
  reply: "https://mautic.test/inbox/api/conversations/0/reply",
  note: "https://mautic.test/inbox/api/conversations/0/note",
};

const item = (id, body) => ({
  id,
  kind: "message",
  body,
  timestamp: `2026-09-17T00:00:0${id}Z`,
});

test("itens incrementais do poll mesclam, nao substituem o historico", async () => {
  const { modulo, limpar } = await compilarStore();
  try {
    const store = modulo.criarInboxStore({
      csrf: "csrf-token",
      urls: URLS,
      buscar: async () => ({
        items: [item(1, "primeira"), item(2, "segunda")],
        next_cursor: "cursor-antigo",
      }),
    });

    await store.ensureTimeline(7);
    assert.equal(store.timelines.get(7).items.length, 2);

    store.applyPoll({ version: "v2", timeline: [item(3, "terceira")] }, 7);

    const historico = store.timelines.get(7);
    assert.deepEqual(
      historico.items.map((i) => i.body),
      ["primeira", "segunda", "terceira"],
      "o incremento do poll precisa somar ao historico, nunca substitui-lo",
    );
    assert.equal(
      historico.older,
      "cursor-antigo",
      "o poll nao traz cursor e nao pode apagar o que a abertura guardou",
    );
  } finally {
    await limpar();
  }
});

test("resumos do poll entram sem uma segunda ida a lista", async () => {
  const { modulo, limpar } = await compilarStore();
  try {
    const chamadas = [];
    const store = modulo.criarInboxStore({
      csrf: "csrf-token",
      urls: URLS,
      buscar: async (url) => {
        chamadas.push(url);
        return {
          items: [{ id: 7, version: 1, last_message_preview: "velho" }],
          next_cursor: null,
          counts: { mine: 1 },
        };
      },
    });
    const filtro = {
      queue: "mine",
      kind: "",
      lifecycle: "",
      channel: "",
      search: "",
      needsResponse: false,
    };

    await store.ensureList(filtro);
    const antes = chamadas.length;

    store.applyPoll({
      version: "v2",
      conversations: [{ id: 7, version: 2, last_message_preview: "novo" }],
    });

    assert.equal(chamadas.length, antes, "o poll ja trouxe o resumo");
    assert.equal(store.conversations.get(7).last_message_preview, "novo");
    assert.deepEqual(
      [...store.lists.values()][0].items.map((i) => i.last_message_preview),
      ["novo"],
      "a linha que ja estava na lista precisa refletir o resumo novo",
    );
  } finally {
    await limpar();
  }
});

test("tentar de novo reenvia com a mesma chave, e o aceite tira a pendente", async () => {
  const { modulo, limpar } = await compilarStore();
  try {
    const enviados = [];
    let derrubar = true;
    const store = modulo.criarInboxStore({
      csrf: "csrf-token",
      urls: URLS,
      buscar: async (url, options) => {
        enviados.push(JSON.parse(options.body).request_id);
        if (derrubar) {
          throw new Error("network down");
        }

        return { item: { ...item(9, "ola"), request_id: enviados.at(-1) } };
      },
    });

    await store.sendReply(7, "reply", "ola");
    const falha = store.pending.get(7)[0];
    assert.equal(falha.state, "failed");
    assert.equal(
      falha.retryable,
      true,
      "cair a rede se resolve tentando de novo",
    );

    derrubar = false;
    await store.retryPending(falha.localId);

    assert.deepEqual(
      enviados,
      [enviados[0], enviados[0]],
      "a chave reusada e o que impede a duplicata quando so o retorno se perdeu",
    );
    assert.equal(store.pending.get(7), undefined, "aceita, a pendente sai");
    assert.deepEqual(
      store.timelines.get(7).items.map((i) => i.body),
      ["ola"],
    );
  } finally {
    await limpar();
  }
});
