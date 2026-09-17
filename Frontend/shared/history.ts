export interface InboxHistory {
  current(): number | null;
  open(stateId: number, replace?: boolean): void;
  clear(replace?: boolean): void;
  dispose(): void;
}

export function createInboxHistory(
  indexUrl: string,
  conversationUrl: string,
  onOpen: (id: number) => void,
  onClear: () => void,
): InboxHistory {
  const baseUrl = new URL(indexUrl, window.location.origin);
  const template = new URL(conversationUrl, window.location.origin);
  const escaped = template.pathname.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
  const pattern = new RegExp(`^${escaped.replace(/0\/?$/, "(\\d+)/?")}$`);
  const normalize = (path: string) =>
    path.length > 1 ? path.replace(/\/+$/, "") : path;
  const basePath = normalize(baseUrl.pathname);
  function current(): number | null {
    const path = normalize(window.location.pathname);
    if (path === basePath) return 0;
    const match = pattern.exec(path);
    return match ? Number(match[1]) : null;
  }
  function write(stateId: number, replace = false): void {
    const target = stateId
      ? template.pathname.replace(/0\/?$/, String(stateId))
      : baseUrl.pathname;
    if (normalize(window.location.pathname) !== normalize(target))
      window.history[replace ? "replaceState" : "pushState"](
        { mauticInbox: true, stateId: stateId || null },
        document.title,
        target,
      );
  }
  function pop(event: PopStateEvent): void {
    const id = current();
    if (id === null) return;
    event.stopImmediatePropagation();
    id > 0 ? onOpen(id) : onClear();
  }
  window.addEventListener("popstate", pop, true);
  return {
    current,
    open: (id, replace) => write(Number(id), replace),
    clear: (replace) => write(0, replace),
    dispose: () => window.removeEventListener("popstate", pop, true),
  };
}
