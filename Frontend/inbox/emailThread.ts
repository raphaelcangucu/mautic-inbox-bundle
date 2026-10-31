import type { Conversation, TimelineItem } from "../shared/types";

/**
 * As regras da thread de e-mail estilo Gmail, sem DOM: o que vira cartao, o que vira linha
 * centralizada, o que abre sozinho e quando uma sequencia de recolhidos vira "N mensagens
 * anteriores". Ficam aqui, e nao nos componentes, para o teste unitario alcancar.
 */

export type EmailRow =
  | { type: "card"; key: string; item: TimelineItem; expanded: boolean }
  | { type: "group"; key: string; items: TimelineItem[] }
  | { type: "line"; key: string; item: TimelineItem };

/** Acima disto, recolhidos seguidos viram uma linha so. */
export const GROUP_AFTER = 4;

export const itemKey = (item: TimelineItem): string =>
  `${item.kind}:${item.display_id ?? item.id}`;

/** Resposta automatica e devolucao nao sao conversa: entram como linha, nao como cartao. */
export function isCard(item: TimelineItem): boolean {
  if (!["message", "automatic", "outbound"].includes(item.kind)) return false;
  const kind = item.email?.kind;
  return kind !== "auto_reply" && kind !== "bounce" && !item.email?.auto_reply;
}

/**
 * @param unread quantas mensagens recebidas ainda nao foram lidas: as ultimas N recebidas
 *               abrem junto com a ultima mensagem
 * @param toggled cartoes e grupos que o atendente abriu ou fechou a mao
 */
export function emailRows(
  items: TimelineItem[],
  unread = 0,
  toggled: Set<string> = new Set(),
): EmailRow[] {
  const cards = items.filter(isCard);
  const last = cards.length ? itemKey(cards[cards.length - 1]) : "";
  const inbound = cards.filter((item) => item.direction === "inbound");
  const unreadKeys = new Set(
    unread > 0 ? inbound.slice(-unread).map(itemKey) : [],
  );
  const rows: EmailRow[] = [];
  let run: TimelineItem[] = [];
  const flush = (): void => {
    if (!run.length) return;
    const groupKey = `group:${itemKey(run[0])}`;
    if (run.length > GROUP_AFTER && !toggled.has(groupKey))
      rows.push({ type: "group", key: groupKey, items: run });
    else
      for (const item of run)
        rows.push({
          type: "card",
          key: itemKey(item),
          item,
          expanded: false,
        });
    run = [];
  };
  for (const item of items) {
    if (!isCard(item)) {
      flush();
      rows.push({ type: "line", key: itemKey(item), item });
      continue;
    }
    const key = itemKey(item);
    const open = key === last || unreadKeys.has(key);
    const expanded = toggled.has(key) ? !open : open;
    if (expanded) {
      flush();
      rows.push({ type: "card", key, item, expanded: true });
    } else run.push(item);
  }
  flush();
  return rows;
}

/** "Re: <assunto>", sem empilhar prefixos. Igual a regra do servidor. */
export function replySubject(subject?: string | null): string {
  const clean = String(subject || "")
    .replace(/[\u0000-\u001f\u007f]+/g, " ")
    .replace(/\s+/g, " ")
    .trim();
  if (!clean) return "";
  return /^(re|res|aw|sv|antw|r)\s*:/i.test(clean) ? clean : `Re: ${clean}`;
}

/** O assunto do topo: o do resumo, senao o da ultima mensagem que tiver um. */
export function threadSubject(
  selected: Pick<Conversation, "subject">,
  items: TimelineItem[],
): string {
  if (selected.subject) return selected.subject;
  for (let index = items.length - 1; index >= 0; index--)
    if (items[index].subject) return String(items[index].subject);
  return "";
}

/** Uma linha so do texto, sem a marcacao do markdown, para o cartao recolhido. */
export function snippet(text?: string | null, length = 140): string {
  const plain = String(text || "")
    .replace(/\[([^\]]+)\]\([^)]+\)/g, "$1")
    .replace(/[*_~`>#]+/g, "")
    .replace(/\s+/g, " ")
    .trim();
  return plain.length > length ? `${plain.slice(0, length - 1)}…` : plain;
}

export function formatSize(bytes?: number): string {
  const size = Number(bytes || 0);
  if (size <= 0) return "";
  if (size < 1024) return `${size} B`;
  if (size < 1024 * 1024) return `${Math.round(size / 1024)} KB`;
  return `${(size / 1024 / 1024).toFixed(1)} MB`;
}

/**
 * O documento do iframe. O HTML ja vem saneado do servidor; esta e a segunda camada: sem
 * script (o sandbox nao tem allow-scripts), sem formulario, sem base, e imagem so de data: e
 * do proprio Mautic (os anexos cid:) ate o atendente pedir as remotas. O pixel de rastreio do
 * remetente nao fica sabendo quando nem de onde a mensagem foi lida.
 */
export function emailDocument(
  html: string,
  origin: string,
  showImages: boolean,
): string {
  const images = ["data:", origin, showImages ? "https: http:" : ""]
    .filter(Boolean)
    .join(" ");
  const policy = `default-src 'none'; style-src 'unsafe-inline'; img-src ${images}; font-src data:; base-uri 'none'; form-action 'none'`;
  return (
    `<!doctype html><html><head><meta charset="utf-8">` +
    `<meta http-equiv="Content-Security-Policy" content="${policy}">` +
    `<meta name="referrer" content="no-referrer">` +
    `<style>html,body{margin:0;padding:0}body{font:14px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#303642;overflow-wrap:anywhere}img{max-width:100%;height:auto}table{max-width:100%}a{color:#4e5e9e}blockquote{margin:0 0 0 8px;padding-left:10px;border-left:2px solid #dfe3ea;color:#6b7482}</style>` +
    `</head><body>${html}</body></html>`
  );
}

/**
 * As imagens remotas que o CSP vai segurar: e o que decide mostrar o botao. As do proprio
 * Mautic (anexo embutido, pelo proxy) ja carregam e nao contam.
 */
export function hasRemoteImages(html: string, origin: string): boolean {
  for (const match of html.matchAll(/<img[^>]+src="(https?:\/\/[^"]+)"/gi))
    if (!match[1].startsWith(`${origin}/`)) return true;
  return false;
}
