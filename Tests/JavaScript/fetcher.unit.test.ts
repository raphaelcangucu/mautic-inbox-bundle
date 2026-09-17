import assert from "node:assert/strict";
import test from "node:test";
import { RequestError } from "../../Frontend/shared/api";
import { criar } from "../../Frontend/shared/store/fetcher";
import type { ListFilter } from "../../Frontend/shared/store/types";
import type { Conversation } from "../../Frontend/shared/types";

type Tipo = "lista" | "detalhe" | "historico" | "ia" | "read";

const conversa = (
  id: number,
  extra: Partial<Conversation> = {},
): Conversation =>
  ({
    id,
    version: 1,
    lifecycle: "open",
    channel: "whatsapp",
    recipient: "5511999999999",
    contact_name: `contato ${id}`,
    asset: { name: "conta" },
    last_message_at: "2026-09-17T00:00:00Z",
    ...extra,
  }) as Conversation;

const filtro = (p: Partial<ListFilter> = {}): ListFilter => ({
  queue: "mine",
  kind: "private",
  lifecycle: "active",
  channel: "",
  search: "",
  needsResponse: false,
  ...p,
});

function tipoDaUrl(url: string): Tipo {
  const caminho = url.split("?")[0];
  if (caminho.endsWith("/history")) return "historico";
  if (caminho.endsWith("/ai")) return "ia";
  if (caminho.endsWith("/state")) return "read";
  return /\/\d+$/.test(caminho) ? "detalhe" : "lista";
}

const idDaUrl = (url: string): number =>
  Number(url.split("?")[0].match(/\/(\d+)(?:\/|$)/)?.[1] ?? 0);

const cursorDaUrl = (url: string): string =>
  new URLSearchParams(url.split("?")[1] ?? "").get("cursor") ?? "";

/**
 * Um servidor de mentira que devolve o suficiente para cada rota. `responder` ganha a vez
 * primeiro: devolver `undefined` deixa o padrao valer, e lancar simula a falha do servidor.
 */
function criarFalso(
  responder: (tipo: Tipo, url: string, id: number) => unknown = () => undefined,
) {
  const pedidas: string[] = [];
  const tipos: Tipo[] = [];
  const padrao = (tipo: Tipo, url: string, id: number): unknown => {
    if ("historico" === tipo)
      return {
        items: [{ id: 7, kind: "inbound", timestamp: "2026-09-17T00:00:01Z" }],
        next_cursor: null,
      };
    if ("ia" === tipo) return { agents: [], can_assign: false, version: 1 };
    if ("read" === tipo) return {};
    if ("detalhe" === tipo) return conversa(id, { can_reply: true });
    return { items: [conversa(1), conversa(2)], next_cursor: null, counts: {} };
  };
  const buscar = async (url: string): Promise<unknown> => {
    const tipo = tipoDaUrl(url);
    pedidas.push(url);
    tipos.push(tipo);
    const dado = responder(tipo, url, idDaUrl(url));
    return undefined === dado ? padrao(tipo, url, idDaUrl(url)) : dado;
  };

  return { fetcher: criar({ buscar }), pedidas, tipos };
}

test("abrir uma conversa dispara as TRES chamadas antes de qualquer resposta", async () => {
  // Promessas adiadas que nunca resolvem. Se a implementacao encadear, a segunda e a
  // terceira URL nunca chegam a ser pedidas e o teste falha.
  const pedidas: string[] = [];
  const adiada = () => new Promise<never>(() => {});
  const fetcher = criar({
    buscar: (url) => {
      pedidas.push(url);
      return adiada();
    },
  });

  void fetcher.abrirConversa(481);
  await Promise.resolve();

  assert.equal(pedidas.length, 3, "detalhe, historico e ia saem juntos");
  assert.ok(pedidas.some((u) => u.includes("/481")));
  assert.ok(
    pedidas.some((u) => u.includes("/history")),
    "o historico nao espera o detalhe",
  );
  assert.ok(
    pedidas.some((u) => u.endsWith("/ai")),
    "a ia nao espera o detalhe",
  );
  assert.equal(fetcher.carregando(481), true, "o esqueleto esta girando");
});

test("abrir uma conversa ja em cache nao pede nada", async () => {
  const { fetcher, pedidas } = criarFalso();

  const primeira = await fetcher.abrirConversa(481);
  await primeira.ai;
  assert.equal(pedidas.length, 3);

  pedidas.length = 0;
  const segunda = await fetcher.abrirConversa(481);

  assert.deepEqual(pedidas, [], "a segunda visita nao custa rede");
  assert.equal(segunda.conversation?.id, 481);
  assert.equal(segunda.timeline?.items.length, 1);
  assert.equal((await segunda.ai)?.version, 1);
  assert.equal(fetcher.carregando(481), false);
});

test("a conversa nao lida manda o read fora do caminho critico", async () => {
  const { fetcher, tipos } = criarFalso((tipo, _url, id) =>
    "detalhe" === tipo ? conversa(id, { unread: 3 }) : undefined,
  );

  const aberta = await fetcher.abrirConversa(481);

  assert.equal(aberta.conversation?.unread, 3);
  assert.equal(tipos.indexOf("read"), 3, "o read sai depois das tres, nao com");
});

test("a lista e cacheada por filtro, nao globalmente", async () => {
  const { fetcher, pedidas } = criarFalso();
  const minhas = filtro({ queue: "mine" });
  const todas = filtro({ queue: "all" });
  const buscada = filtro({ queue: "mine", search: "ana" });

  await fetcher.ensureList(minhas);
  await fetcher.ensureList(todas);
  await fetcher.ensureList(buscada);
  await fetcher.ensureList(minhas);

  assert.equal(pedidas.length, 3, "so o repetido veio do cache");
  assert.equal(fetcher.estado().lists.size, 3);
  assert.notEqual(fetcher.chaveDaLista(minhas), fetcher.chaveDaLista(todas));
  assert.notEqual(fetcher.chaveDaLista(minhas), fetcher.chaveDaLista(buscada));
  assert.equal(
    fetcher.chaveDaLista(minhas),
    fetcher.chaveDaLista(filtro({ queue: "mine" })),
    "o mesmo conjunto de filtros da a mesma chave",
  );
});

test("o cursor da lista NAO entra na chave: paginar nao cria entrada nova", async () => {
  const { fetcher } = criarFalso((tipo, url) =>
    "lista" === tipo
      ? "" === cursorDaUrl(url)
        ? {
            items: [conversa(1), conversa(2)],
            next_cursor: "p2",
            counts: { mine: 9 },
          }
        : {
            items: [conversa(3), conversa(4)],
            next_cursor: null,
            counts: { mine: 9 },
          }
      : undefined,
  );
  const f = filtro();

  const primeira = await fetcher.ensureList(f);
  const chave = fetcher.chaveDaLista(f);
  assert.equal(primeira.cursor, "p2", "o cursor mora DENTRO da entrada");

  const depois = await fetcher.carregarMaisDaLista(f);

  assert.equal(
    fetcher.estado().lists.size,
    1,
    "a pagina 2 nao e outra entrada",
  );
  assert.equal(fetcher.chaveDaLista(f), chave, "a chave nao muda com o cursor");
  assert.deepEqual(
    depois.items.map((i) => i.id),
    [1, 2, 3, 4],
    "carregar mais acrescenta na mesma entrada",
  );
  assert.equal(depois.cursor, null);
  assert.deepEqual(depois.counts, { mine: 9 });
});

test("abertura fria sem resumo na lista tambem dispara as tres", async () => {
  const { fetcher, pedidas, tipos } = criarFalso();
  const f = filtro();

  const lista = await fetcher.ensureList(f);
  assert.ok(
    !lista.items.some((i) => 481 === i.id),
    "a conversa aberta nao esta na pagina cacheada",
  );
  pedidas.length = 0;
  tipos.length = 0;

  const aberta = await fetcher.abrirConversa(481);

  assert.equal(pedidas.length, 3, "sem resumo, as tres saem do mesmo jeito");
  assert.deepEqual([...tipos].sort(), ["detalhe", "historico", "ia"]);
  assert.equal(aberta.error, null);
  assert.equal(aberta.conversation?.id, 481);
  assert.equal(aberta.conversation?.can_reply, true, "o detalhe, nao o resumo");
  assert.equal(aberta.timeline?.items.length, 1);
});

test("404 no detalhe nao deixa esqueleto girando e nao entra no cache", async () => {
  const { fetcher } = criarFalso((tipo) => {
    if ("detalhe" === tipo)
      throw new RequestError("conversa nao encontrada", 404);
    return undefined;
  });
  const f = filtro();
  await fetcher.ensureList(f);

  const aberta = await fetcher.abrirConversa(481);

  assert.equal(fetcher.carregando(481), false, "o esqueleto parou");
  assert.equal(aberta.error?.status, 404);
  assert.equal(aberta.error?.message, "conversa nao encontrada");
  assert.equal(aberta.conversation, null);
  assert.equal(await aberta.ai, null, "a ia de uma conversa morta nao entra");
  assert.equal(fetcher.estado().conversations.size, 0, "nada cacheado");
  assert.equal(
    fetcher.estado().timelines.size,
    0,
    "nem o historico que voltou",
  );
  assert.deepEqual(
    fetcher
      .estado()
      .lists.get(fetcher.chaveDaLista(f))
      ?.items.map((i) => i.id),
    [1, 2],
    "a conversa morta nao entra na lista cacheada",
  );
});
