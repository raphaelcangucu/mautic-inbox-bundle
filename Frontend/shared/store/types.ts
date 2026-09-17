import type { Conversation, TimelineItem } from "../types";

/**
 * Uma mensagem que o atendente mandou e o servidor ainda nao registrou.
 *
 * NAO e um TimelineItem e nao deve virar um. Sao dois tipos distintos de proposito: e o que
 * torna impossivel um componente desenhar uma pendente como se fosse mensagem enviada.
 */
export interface PendingMessage {
  /** Identificador local, so para a interface. */
  readonly localId: string;
  readonly conversationId: number;
  readonly mode: "reply" | "note";
  readonly body: string;
  readonly createdAt: string;
  /**
   * A chave de reconciliacao de uma resposta. O cliente a gera, e por isso a ordem de chegada
   * nao importa. Nota nao tem: ver noteId.
   */
  readonly requestId?: string;
  /** Preenchido quando a resposta do /note volta. Antes disso a nota nao tem chave. */
  noteId?: number;
  /** Dois estados, e so dois. Aceita pelo servidor a pendente DEIXA DE EXISTIR. */
  state: "sending" | "failed";
  /** O texto de falha que o atendente le. Rede e recusa do canal sao coisas diferentes. */
  failure?: string;
  /** Falha de rede se resolve tentando de novo; recusa do canal, nao. */
  retryable?: boolean;
}

/**
 * A lista e cacheada por FILTRO, e o cursor mora DENTRO da entrada.
 *
 * O cursor nao pode entrar na chave: paginar viraria uma entrada nova por pagina e o
 * "carregar mais" pararia de funcionar, porque a pagina 2 nao encontraria a 1.
 */
export interface ListEntry {
  readonly key: string;
  items: Conversation[];
  cursor: string | null;
  /** Os numeros das abas de fila. Sem casa aqui, servir do cache deixa os selos velhos. */
  counts: Record<string, number>;
}

/** O conjunto que compoe a chave. Sem cursor, de proposito. */
export interface ListFilter {
  queue: string;
  kind: string;
  lifecycle: string;
  channel: string;
  search: string;
  needsResponse: boolean;
}

/**
 * A carga INTEIRA do poll, nao uma projecao.
 *
 * O componente ainda precisa de next_since e do cursor de notificacao para alimentar os avisos
 * sonoros; recortar o tipo deixaria esse caminho orfao sem ninguem perceber.
 */
export interface PollResult {
  version: string;
  conversations?: Conversation[];
  timeline?: TimelineItem[];
  next_since?: string;
  notification_cursor?: string;
  notifications?: unknown[];
  has_more?: boolean;
  notifications_more?: boolean;
}

/**
 * O historico tem cursor pelo mesmo motivo que a lista tem.
 *
 * Sem ele, assim que abrir do cache parar de buscar — que e o objetivo 1 —, nada mais preenche
 * o cursor de "carregar anteriores", e as mensagens antigas ficam inalcancaveis a partir da
 * segunda visita a qualquer conversa.
 */
export interface TimelineEntry {
  items: TimelineItem[];
  older: string | null;
}

export interface InboxState {
  lists: Map<string, ListEntry>;
  conversations: Map<number, Conversation>;
  timelines: Map<number, TimelineEntry>;
  pending: Map<number, PendingMessage[]>;
}
