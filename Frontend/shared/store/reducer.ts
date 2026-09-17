import type { Conversation, TimelineItem } from "../types";
import { reconcile } from "./reconcile";
import type { InboxState, PendingMessage, TimelineEntry } from "./types";

/**
 * As transicoes de um envio. Funcoes puras: sem fetch, sem Date.now() aqui dentro — tempo e
 * identificador entram por parametro. Toda funcao devolve um estado NOVO; nenhuma altera o
 * que recebeu.
 *
 * Uma pendente tem dois estados, "sending" e "failed", e nao um terceiro. Aceita pelo
 * servidor ela DEIXA DE EXISTIR, e no lugar dela entra um item de historico com o status que
 * o servidor mandou.
 */

/**
 * A chave de um item de historico e o kind mais o id, nunca o id sozinho.
 *
 * Cada item usa o id da propria entidade e o poll emite os quatro tipos no mesmo vetor, entao
 * o id 42 pode ser uma nota, um envio e um evento ao mesmo tempo. Mesclar pelo id puro
 * sobrescreve um tipo com outro — e faz isso no caminho incremental, que so aparece sob
 * trafego real.
 */
const chaveDoItem = (item: TimelineItem): string => `${item.kind}:${item.id}`;

/**
 * O servidor ordena por [timestamp, rank, sort] e remove rank e sort antes de enviar: do lado
 * do cliente so sobra o timestamp. Empate mantem a ordem de insercao.
 */
function compararInstantes(a: string, b: string): number {
  const ta = Date.parse(a);
  const tb = Date.parse(b);

  if (!Number.isNaN(ta) && !Number.isNaN(tb)) {
    return ta === tb ? 0 : ta < tb ? -1 : 1;
  }

  return a === b ? 0 : a < b ? -1 : 1;
}

function ordenarPorInstante(items: TimelineItem[]): TimelineItem[] {
  return items
    .map((item, indice) => ({ item, indice }))
    .sort((a, b) => {
      const ordem = compararInstantes(a.item.timestamp, b.item.timestamp);
      return 0 !== ordem ? ordem : a.indice - b.indice;
    })
    .map((par) => par.item);
}

/** Devolve um estado novo com as pendentes daquela conversa trocadas. */
function comPendentes(
  state: InboxState,
  conversationId: number,
  pendentes: PendingMessage[],
): InboxState {
  const pending = new Map(state.pending);

  if (0 === pendentes.length) {
    pending.delete(conversationId);
  } else {
    pending.set(conversationId, pendentes);
  }

  return { ...state, pending };
}

/** Localiza a conversa dona de uma pendente. A acao traz so o localId. */
function acharPendente(
  state: InboxState,
  localId: string,
): { conversationId: number; indice: number } | null {
  for (const [conversationId, lista] of state.pending) {
    const indice = lista.findIndex((p) => p.localId === localId);
    if (-1 !== indice) {
      return { conversationId, indice };
    }
  }

  return null;
}

/**
 * A pendente inteira, pelo localId.
 *
 * Tentar de novo precisa do texto, do modo e da chave — e a acao de retentativa so carrega o
 * localId. Sem esta porta, quem orquestra o reenvio varreria o mapa de pendentes por fora e
 * manteria uma segunda copia desta busca.
 */
export function findPending(
  state: InboxState,
  localId: string,
): PendingMessage | undefined {
  const achada = acharPendente(state, localId);

  return null === achada
    ? undefined
    : state.pending.get(achada.conversationId)?.[achada.indice];
}

/** Aplica uma transformacao a uma pendente, sem tocar na lista original. */
function trocarPendente(
  state: InboxState,
  localId: string,
  trocar: (p: PendingMessage) => PendingMessage,
): InboxState {
  const achada = acharPendente(state, localId);
  if (null === achada) {
    return state;
  }

  const lista = state.pending.get(achada.conversationId) ?? [];
  const nova = lista.map((p, i) => (i === achada.indice ? trocar(p) : p));

  return comPendentes(state, achada.conversationId, nova);
}

/**
 * Cria a pendente que o atendente ve na hora em que toca enviar. Nada de rede acontece aqui:
 * a mensagem aparece no fim da conversa e o servidor confirma depois.
 */
export function startSend(
  state: InboxState,
  acao: {
    conversationId: number;
    mode: "reply" | "note";
    body: string;
    requestId?: string;
    localId: string;
    now: string;
  },
): InboxState {
  const pendente: PendingMessage = {
    localId: acao.localId,
    conversationId: acao.conversationId,
    mode: acao.mode,
    body: acao.body,
    createdAt: acao.now,
    requestId: acao.requestId,
    state: "sending",
  };
  const lista = state.pending.get(acao.conversationId) ?? [];

  return comPendentes(state, acao.conversationId, [...lista, pendente]);
}

/**
 * O servidor aceitou. Aceito NAO e enviado: o /reply responde 202 e o status dentro pode vir
 * failed ou uncertain. O item entra no historico com o status que veio, sem promocao nenhuma.
 *
 * A pendente nao transita para "confirmada" — ela sai. A nota e o caso sem item: a resposta
 * do /note so traz o id, que fica guardado na pendente para a reconciliacao casar quando o
 * item chegar pelo historico.
 */
export function acceptSend(
  state: InboxState,
  acao: {
    localId: string;
    item?: TimelineItem;
    noteId?: number;
    summary?: Conversation;
  },
): InboxState {
  const achada = acharPendente(state, acao.localId);
  let proximo = state;

  if (undefined !== acao.summary) {
    const conversations = new Map(state.conversations);
    conversations.set(acao.summary.id, acao.summary);
    proximo = { ...proximo, conversations };
  }

  if (null === achada) {
    return proximo;
  }

  if (undefined === acao.item) {
    // Nota: sem item, a pendente continua "sending" ate o historico trazer a nota. Guardar o
    // id e o que da a ela uma chave para casar — antes disso ela nao tem nenhuma.
    return undefined === acao.noteId
      ? proximo
      : trocarPendente(proximo, acao.localId, (p) => ({
          ...p,
          noteId: acao.noteId,
        }));
  }

  const chegou = acao.item;
  const lista = proximo.pending.get(achada.conversationId) ?? [];
  const semAPendente = lista.filter((p) => p.localId !== acao.localId);
  const entrada = proximo.timelines.get(achada.conversationId);
  const anteriores = entrada?.items ?? [];
  const chave = chaveDoItem(chegou);
  const jaEstava = anteriores.some((i) => chaveDoItem(i) === chave);
  const items = jaEstava
    ? anteriores.map((i) => (chaveDoItem(i) === chave ? chegou : i))
    : [...anteriores, chegou];

  const timelines = new Map(proximo.timelines);
  timelines.set(achada.conversationId, {
    items,
    older: entrada?.older ?? null,
  });

  return comPendentes(
    { ...proximo, timelines },
    achada.conversationId,
    semAPendente,
  );
}

/**
 * Falhou antes do servidor registrar. A pendente fica, com o texto dentro: e dele que a
 * retentativa sai, e e ele que o atendente veria sumir se a pendente fosse embora.
 */
export function failSend(
  state: InboxState,
  acao: { localId: string; failure: string; retryable: boolean },
): InboxState {
  return trocarPendente(state, acao.localId, (p) => ({
    ...p,
    state: "failed",
    failure: acao.failure,
    retryable: acao.retryable,
  }));
}

/**
 * De "failed" de volta para "sending", com o mesmo requestId e o mesmo texto.
 *
 * O reuso da chave e o que impede a duplicata quando o servidor processou a resposta e o
 * retorno se perdeu. Uma nota nao tem chave nenhuma para reusar: tentar de novo grava uma
 * nota nova, e o teste registra isso.
 */
export function beginRetry(
  state: InboxState,
  acao: { localId: string },
): InboxState {
  return trocarPendente(state, acao.localId, (p) => {
    const { failure: _falha, retryable: _retentavel, ...resto } = p;
    return { ...resto, state: "sending" };
  });
}

/**
 * A porta por onde TODO historico vindo do servidor entra — resposta de envio, poll,
 * carregamento inicial, "carregar anteriores", qualquer um. E o unico lugar que chama
 * reconcile().
 *
 * Sem essa porta unica o caminho do poll atribui o historico direto e reproduz exatamente a
 * duplicata que o desenho existe para impedir.
 *
 * O modo e explicito porque as tres origens tem semanticas diferentes, e adivinhar pela forma
 * dos dados seria fragil:
 *
 *   replace  pagina completa (abrir conversa): substitui o que havia
 *   merge    incremento do poll: junta por chave, mantendo o que ja estava
 *   prepend  "carregar anteriores": acrescenta no comeco
 */
export function applyServerItems(
  state: InboxState,
  acao: {
    conversationId: number;
    items: TimelineItem[];
    mode: "replace" | "merge" | "prepend";
    cursor?: string | null;
  },
): InboxState {
  const entrada = state.timelines.get(acao.conversationId);
  const anteriores = entrada?.items ?? [];
  let items: TimelineItem[];

  if ("replace" === acao.mode) {
    items = [...acao.items];
  } else if ("prepend" === acao.mode) {
    const presentes = new Set(anteriores.map(chaveDoItem));
    items = [
      ...acao.items.filter((i) => !presentes.has(chaveDoItem(i))),
      ...anteriores,
    ];
  } else {
    const porChave = new Map<string, number>();
    items = [...anteriores];
    items.forEach((i, indice) => porChave.set(chaveDoItem(i), indice));

    for (const novo of acao.items) {
      const indice = porChave.get(chaveDoItem(novo));
      if (undefined === indice) {
        porChave.set(chaveDoItem(novo), items.length);
        items.push(novo);
      } else {
        items[indice] = novo;
      }
    }

    items = ordenarPorInstante(items);
  }

  const proxima: TimelineEntry = {
    items,
    older: undefined === acao.cursor ? (entrada?.older ?? null) : acao.cursor,
  };
  const timelines = new Map(state.timelines);
  timelines.set(acao.conversationId, proxima);

  // O filtro vale contra o historico resultante, e nao so contra o incremento que chegou:
  // uma pendente cuja mensagem ja esta na tela por outro caminho tambem precisa sair.
  const pendentes = state.pending.get(acao.conversationId) ?? [];

  return comPendentes(
    { ...state, timelines },
    acao.conversationId,
    reconcile(pendentes, items),
  );
}
