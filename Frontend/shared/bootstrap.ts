import type { AiData, InboxBootstrap, Labels } from "./types";

export function parseJson<T>(value: string | undefined, fallback: T): T {
  if (!value) return fallback;
  try {
    return JSON.parse(value) as T;
  } catch {
    return fallback;
  }
}

export function inboxBootstrap(root: HTMLElement): InboxBootstrap {
  const data = root.dataset;
  const raw = parseJson<Partial<InboxBootstrap>>(data.bootstrap, {});
  return {
    labels: parseJson<Labels>(data.translations, raw.labels ?? {}),
    locale: (data.locale || raw.locale || "en_US").replace(/_/g, "-"),
    currentUser: Number(data.currentUser || raw.currentUser || 0),
    initialStateId: Number(data.initialStateId || raw.initialStateId || 0),
    urls: {
      ai: data.aiUrl || "",
      aiRetry: data.aiRetryUrl || "",
      index: data.indexUrl || "",
      conversation: data.conversationUrl || "",
      stream: data.streamUrl || "",
      list: data.listUrl || "",
      detail: data.detailUrl || "",
      timeline: data.timelineUrl || "",
      poll: data.pollUrl || "",
      take: data.takeUrl || "",
      state: data.stateUrl || "",
      templates: data.templatesUrl || "",
      reply: data.replyUrl || "",
      retry: data.retryUrl || "",
      note: data.noteUrl || "",
      draft: data.draftUrl || "",
      canned: data.cannedUrl || "",
      cannedItem: data.cannedItemUrl || "",
      pushConfig: data.pushConfigUrl || "",
      pushSubscriptions: data.pushSubscriptionsUrl || "",
    },
    canned: parseJson(data.cannedSettings, raw.canned ?? []),
    users: raw.users ?? [],
    automationRules: raw.automationRules ?? [],
    channelNotices: raw.channelNotices ?? [],
    canManageCanned: Boolean(raw.canManageCanned),
    isAdmin: Boolean(raw.isAdmin),
  };
}

export function aiBootstrap(root: HTMLElement): {
  labels: Labels;
  url: string;
  action: string;
  csrf: string;
  inboxUrl: string;
} {
  return {
    labels: parseJson(root.dataset.labels, {}),
    url: root.dataset.url || "",
    action: root.dataset.action || "",
    csrf: root.dataset.csrf || "",
    inboxUrl: root.dataset.inboxUrl || "/s/inbox",
  };
}

export const emptyAiData = (): AiData => ({
  documents: [],
  agents: [],
  assets: [],
  installed: false,
  config: { enabled: false, model: "", limit: 0, permissions: [] },
  health: {},
});

export function translator(
  labels: Labels,
): (key: string, parameters?: Record<string, unknown>) => string {
  return (key, parameters = {}) => {
    let text = labels[key] || key;
    Object.entries(parameters).forEach(([name, value]) => {
      text = text.split(`%${name}%`).join(String(value));
    });
    return text;
  };
}
