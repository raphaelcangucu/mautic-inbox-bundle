import type { TimelineItem } from "../types";
import type { PendingMessage } from "./types";

/**
 * Devolve as pendentes que AINDA nao foram registradas pelo servidor.
 *
 * A chave, nunca a posicao. Uma resposta enviada e, ela propria, um item de historico: ela volta
 * pela resposta do envio e volta de novo no proximo poll. Sem chave, o item confirmado chega
 * enquanto a pendente ainda esta na tela e a mesma mensagem aparece duas vezes, uma delas
 * dizendo "enviando".
 *
 * A funcao e pura e nao sabe de onde os itens vieram — e o que a mantem valida quando o
 * recarregamento periodico for removido.
 */
export function reconcile(
  pending: readonly PendingMessage[],
  items: readonly TimelineItem[],
): PendingMessage[] {
  const enviados = new Set<string>();
  const notas = new Set<number>();

  for (const item of items) {
    if ("note" === item.kind) {
      notas.add(item.id);
    } else if (item.request_id) {
      enviados.add(item.request_id);
    }
  }

  return pending.filter((p) => {
    // Nota: a chave so existe depois que a resposta do /note volta. Sem noteId, nao ha como
    // casar, e a pendente fica orfa. Assimetria conhecida e registrada no spec.
    if ("note" === p.mode) {
      return undefined === p.noteId || !notas.has(p.noteId);
    }

    return undefined === p.requestId || !enviados.has(p.requestId);
  });
}
