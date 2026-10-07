import type { TimelineItem } from "../shared/types";
export function collapseRetries(items: TimelineItem[]): TimelineItem[] {
  const byRequest = new Map(
    items
      .filter((m) => m.kind === "outbound" && m.request_id)
      .map((m) => [m.request_id!, m]),
  );
  const groups = new Map<string, TimelineItem[]>();
  const rootOf = (message: TimelineItem) => {
    let root = message;
    const seen = new Set<string>();
    while (root.retry_of) {
      if (seen.has(root.retry_of)) return message;
      seen.add(root.retry_of);
      const parent = byRequest.get(root.retry_of);
      if (!parent || parent.body !== message.body) break;
      root = parent;
    }
    return root;
  };
  for (const m of items) {
    const root = rootOf(m);
    const key = root.kind + ":" + root.id;
    groups.set(key, [...(groups.get(key) || []), m]);
  }
  const emitted = new Set<string>();
  const rows: TimelineItem[] = [];
  for (const m of items) {
    const root = rootOf(m);
    const key = root.kind + ":" + root.id;
    if (emitted.has(key)) continue;
    emitted.add(key);
    const attempts = groups.get(key)!;
    const latest = attempts.reduce((a, b) =>
      Date.parse(b.timestamp) > Date.parse(a.timestamp) ||
      (b.timestamp === a.timestamp && Number(b.id) > Number(a.id))
        ? b
        : a,
    );
    rows.push(
      attempts.length > 1
        ? {
            ...latest,
            display_id: root.id,
            timestamp: root.timestamp,
            attempt_count: attempts.length,
          }
        : m,
    );
  }
  return rows;
}
