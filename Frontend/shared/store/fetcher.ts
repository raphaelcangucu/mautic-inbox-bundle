import { endpoint, jsonRequest, RequestError } from "../api";
import type { AiInfo, Conversation, TimelineItem } from "../types";
import { applyServerItems } from "./reducer";
import type { InboxState, ListEntry, ListFilter, TimelineEntry } from "./types";

/**
 * Quem faz as chamadas e o que fica guardado.
 *
 * Medido de um celular contra producao, cada chamada custa uns 750 ms. Abrir uma conversa
 * pedia o detalhe e so DEPOIS dele o historico e a ia: um segundo e meio de tela parada. As
 * tres saem juntas, e a segunda visita a uma conversa ja aberta nao custa rede nenhuma.
 *
 * Este modulo so busca e guarda. Transicao de estado e do reducer: o historico entra por
 * applyServerItems, que e a unica porta.
 */

/** A funcao de rede entra por parametro, e nao por import, para o teste poder adia-la. */
export type Buscar = (url: string, options?: RequestInit) => Promise<unknown>;

export interface UrlsDoFetcher {
  list: string;
  detail: string;
  timeline: string;
  ai: string;
  state: string;
}

export interface OpcoesDoFetcher {
  buscar?: Buscar;
  urls?: Partial<UrlsDoFetcher>;
  csrf?: string;
  /** Ganchos para quem ja e dono do estado; sem eles o fetcher guarda o proprio. */
  lerEstado?: () => InboxState;
  gravarEstado?: (proximo: InboxState) => void;
  limiteDaLista?: number;
  limiteDoHistorico?: number;
}

export interface FalhaDaAbertura {
  message: string;
  status: number;
}

export interface Abertura {
  conversation: Conversation | null;
  timeline: TimelineEntry | null;
  /**
   * A conversa desenha sem esperar a ia; o painel preenche quando ela chega. Nunca rejeita:
   * uma ia que falha nao derruba a abertura, e ninguem precisa lembrar de dar catch.
   */
  ai: Promise<AiInfo | null>;
  /** Falha do detalhe. Com ela preenchida nada foi guardado. */
  error: FalhaDaAbertura | null;
  /** Falha do historico, que nao impede a conversa de abrir. */
  timelineError: string | null;
}

interface RespostaDaLista {
  items?: Conversation[];
  next_cursor?: string | null;
  counts?: Record<string, number>;
}

interface RespostaDoHistorico {
  items?: TimelineItem[];
  next_cursor?: string | null;
}

/**
 * So valem quando ninguem passa urls — o caminho real vem dos data-attributes do root. Sao os
 * mesmos caminhos declarados em Config/config.php.
 */
const URLS_PADRAO: UrlsDoFetcher = {
  list: "/s/inbox/api/conversations",
  detail: "/s/inbox/api/conversations/0",
  timeline: "/s/inbox/api/conversations/0/history",
  ai: "/s/inbox/api/conversations/0/ai",
  state: "/s/inbox/api/conversations/0/state",
};

const estadoVazio = (): InboxState => ({
  lists: new Map(),
  conversations: new Map(),
  timelines: new Map(),
  pending: new Map(),
});

function mensagemDe(erro: unknown): string {
  return erro instanceof Error ? erro.message : String(erro);
}

function statusDe(erro: unknown): number {
  return erro instanceof RequestError ? erro.status : 0;
}

export function criar(opcoes: OpcoesDoFetcher = {}) {
  const urls: UrlsDoFetcher = { ...URLS_PADRAO, ...opcoes.urls };
  const csrf = opcoes.csrf ?? "";
  const buscar: Buscar =
    opcoes.buscar ??
    ((url, options) => jsonRequest<unknown>(url, csrf, options));
  const limiteDaLista = opcoes.limiteDaLista ?? 25;
  const limiteDoHistorico = opcoes.limiteDoHistorico ?? 100;

  let proprio = estadoVazio();
  const atual = (): InboxState => opcoes.lerEstado?.() ?? proprio;
  const gravar = (proximo: InboxState): void => {
    proprio = proximo;
    opcoes.gravarEstado?.(proximo);
  };

  /**
   * A ia nao tem casa no InboxState — nem deveria ter, porque nada nela e reconciliado. Fica
   * aqui, junto do resto do que e so cache.
   */
  const ias = new Map<number, AiInfo>();
  /** Quem esta com o esqueleto girando. Sai no finally, inclusive quando o detalhe falha. */
  const abrindo = new Set<number>();
  /** Duas telas pedindo a mesma coisa ao mesmo tempo dividem um pedido so. */
  const voos = new Map<string, Promise<unknown>>();

  const url = (nome: keyof UrlsDoFetcher, id?: number): string =>
    endpoint(urls[nome], id);

  /** O `buscar` sai SINCRONO daqui: e o que deixa as tres chamadas partirem juntas. */
  function pedir<T>(chave: string, endereco: string): Promise<T> {
    const emVoo = voos.get(chave);
    if (undefined !== emVoo) {
      return emVoo as Promise<T>;
    }

    const pedido = buscar(endereco).finally(() => {
      if (voos.get(chave) === pedido) {
        voos.delete(chave);
      }
    });
    voos.set(chave, pedido);

    return pedido as Promise<T>;
  }

  const pedirDetalhe = (id: number): Promise<Conversation> =>
    pedir<Conversation>(`detalhe:${id}`, url("detail", id));

  const pedirHistorico = (id: number): Promise<RespostaDoHistorico> =>
    pedir<RespostaDoHistorico>(
      `historico:${id}`,
      `${url("timeline", id)}?limit=${limiteDoHistorico}`,
    );

  const pedirIa = (id: number): Promise<AiInfo> =>
    pedir<AiInfo>(`ia:${id}`, url("ai", id));

  /**
   * A chave e o CONJUNTO DE FILTROS, e nada mais. O cursor nao entra: se entrasse, a pagina 2
   * viraria uma entrada propria e o "carregar mais" nunca reencontraria a pagina 1.
   */
  function chaveDaLista(filtro: ListFilter): string {
    return JSON.stringify([
      filtro.queue,
      filtro.kind,
      filtro.lifecycle,
      filtro.channel,
      filtro.search.trim(),
      filtro.needsResponse ? 1 : 0,
    ]);
  }

  function consultaDaLista(filtro: ListFilter, cursor: string | null): string {
    const q = new URLSearchParams({
      queue: filtro.queue,
      kind: filtro.kind,
      lifecycle: filtro.lifecycle,
      channel: filtro.channel,
      search: filtro.search.trim(),
      needs_response: filtro.needsResponse ? "1" : "0",
      limit: String(limiteDaLista),
    });
    if (cursor) {
      q.set("cursor", cursor);
    }

    return q.toString();
  }

  async function pedirLista(
    filtro: ListFilter,
    cursor: string | null,
  ): Promise<RespostaDaLista> {
    const consulta = consultaDaLista(filtro, cursor);

    return pedir<RespostaDaLista>(
      `lista:${chaveDaLista(filtro)}:${cursor ?? ""}`,
      `${url("list")}?${consulta}`,
    );
  }

  function guardarLista(chave: string, entrada: ListEntry): ListEntry {
    const lists = new Map(atual().lists);
    lists.set(chave, entrada);
    gravar({ ...atual(), lists });

    return entrada;
  }

  function guardarConversa(conversa: Conversation): Conversation {
    const conversations = new Map(atual().conversations);
    conversations.set(conversa.id, conversa);

    // A linha que JA esta numa lista cacheada se atualiza; nenhuma linha nova e inventada.
    // Uma conversa aberta por link direto pode nao pertencer ao filtro que esta em cache, e
    // enfia-la la mostraria ao atendente uma linha que o servidor nunca devolveria.
    const lists = new Map(atual().lists);
    for (const [chave, entrada] of lists) {
      if (entrada.items.some((i) => i.id === conversa.id)) {
        lists.set(chave, {
          ...entrada,
          items: entrada.items.map((i) =>
            i.id === conversa.id ? conversa : i,
          ),
        });
      }
    }

    gravar({ ...atual(), conversations, lists });

    return conversa;
  }

  function guardarHistorico(
    id: number,
    dados: RespostaDoHistorico,
  ): TimelineEntry {
    // Pagina inteira: "replace". O modo e explicito porque poll e "carregar anteriores"
    // entram pela mesma porta com semantica diferente.
    gravar(
      applyServerItems(atual(), {
        conversationId: id,
        items: dados.items ?? [],
        mode: "replace",
        cursor: dados.next_cursor ?? null,
      }),
    );

    return atual().timelines.get(id) ?? { items: [], older: null };
  }

  /** Fora do caminho critico: ninguem espera o read para ver a conversa. */
  function marcarLida(id: number): void {
    void Promise.resolve()
      .then(() =>
        buscar(url("state", id), {
          method: "POST",
          body: JSON.stringify({ action: "read" }),
        }),
      )
      .catch(() => undefined);
  }

  async function ensureConversation(id: number): Promise<Conversation> {
    const emCache = atual().conversations.get(id);

    return undefined !== emCache
      ? emCache
      : guardarConversa(await pedirDetalhe(id));
  }

  async function ensureTimeline(id: number): Promise<TimelineEntry> {
    const emCache = atual().timelines.get(id);

    return undefined !== emCache
      ? emCache
      : guardarHistorico(id, await pedirHistorico(id));
  }

  async function ensureAi(id: number): Promise<AiInfo> {
    const emCache = ias.get(id);
    if (undefined !== emCache) {
      return emCache;
    }

    const dados = await pedirIa(id);
    ias.set(id, dados);

    return dados;
  }

  async function ensureList(filtro: ListFilter): Promise<ListEntry> {
    const chave = chaveDaLista(filtro);
    const emCache = atual().lists.get(chave);
    if (undefined !== emCache) {
      return emCache;
    }

    const dados = await pedirLista(filtro, null);

    return guardarLista(chave, {
      key: chave,
      items: dados.items ?? [],
      cursor: dados.next_cursor ?? null,
      counts: dados.counts ?? {},
    });
  }

  /** A pagina seguinte cai na MESMA entrada — e por isso que o cursor mora dentro dela. */
  async function carregarMaisDaLista(filtro: ListFilter): Promise<ListEntry> {
    const entrada = await ensureList(filtro);
    if (null === entrada.cursor) {
      return entrada;
    }

    const dados = await pedirLista(filtro, entrada.cursor);
    const chave = chaveDaLista(filtro);
    const anterior = atual().lists.get(chave) ?? entrada;
    const presentes = new Set(anterior.items.map((i) => i.id));

    return guardarLista(chave, {
      key: chave,
      items: [
        ...anterior.items,
        ...(dados.items ?? []).filter((i) => !presentes.has(i.id)),
      ],
      cursor: dados.next_cursor ?? null,
      counts: dados.counts ?? anterior.counts,
    });
  }

  /**
   * As tres saem juntas, e nenhuma delas espera a outra.
   *
   * O que volta so e guardado se o detalhe deu certo: um 404 — link velho, conversa apagada,
   * permissao mudada — nao pode deixar nem historico nem ia de uma conversa que o atendente
   * nao pode ver. E o esqueleto para de girar mesmo nesse caminho.
   */
  async function abrirConversa(id: number): Promise<Abertura> {
    abrindo.add(id);

    const conversaEmCache = atual().conversations.get(id);
    const historicoEmCache = atual().timelines.get(id);
    const iaEmCache = ias.get(id);

    // Tudo o que falta e pedido AQUI, antes de qualquer await.
    const pDetalhe =
      undefined === conversaEmCache ? pedirDetalhe(id) : Promise.resolve(null);
    const pHistorico =
      undefined === historicoEmCache
        ? pedirHistorico(id)
        : Promise.resolve(null);
    const pIa = undefined === iaEmCache ? pedirIa(id) : Promise.resolve(null);

    // allSettled porque um historico que falha nao pode virar rejeicao solta, e a ia nem
    // sequer e esperada aqui.
    const iaSegura = pIa.then(
      (dados) => dados,
      () => null,
    );

    try {
      const [detalhe, historico] = await Promise.allSettled([
        pDetalhe,
        pHistorico,
      ]);

      if ("rejected" === detalhe.status) {
        return {
          conversation: null,
          timeline: null,
          ai: Promise.resolve(null),
          error: {
            message: mensagemDe(detalhe.reason),
            status: statusDe(detalhe.reason),
          },
          timelineError: null,
        };
      }

      const conversa =
        null === detalhe.value
          ? conversaEmCache!
          : guardarConversa(detalhe.value);
      let timeline = historicoEmCache ?? null;
      let timelineError: string | null = null;

      if ("rejected" === historico.status) {
        timelineError = mensagemDe(historico.reason);
      } else if (null !== historico.value) {
        timeline = guardarHistorico(id, historico.value);
      }

      if ((conversa.unread ?? 0) > 0) {
        marcarLida(id);
      }

      return {
        conversation: conversa,
        timeline,
        ai: iaSegura.then((dados) => {
          if (null !== dados) {
            ias.set(id, dados);
          }

          return ias.get(id) ?? null;
        }),
        error: null,
        timelineError,
      };
    } finally {
      abrindo.delete(id);
    }
  }

  return {
    abrirConversa,
    ensureConversation,
    ensureTimeline,
    ensureAi,
    ensureList,
    carregarMaisDaLista,
    chaveDaLista,
    carregando: (id: number): boolean => abrindo.has(id),
    estado: (): InboxState => atual(),
  };
}

export type Fetcher = ReturnType<typeof criar>;
