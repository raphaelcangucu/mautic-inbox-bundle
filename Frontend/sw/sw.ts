/// <reference lib="webworker" />

/**
 * Service worker do atendimento.
 *
 * Faz duas coisas e nada mais: exibe a notificacao que chega e trata o toque nela. Nao ha
 * cache de conversa nem fila de envio offline — resposta de atendimento que sai atrasada pode
 * furar a janela de 24 horas do WhatsApp, e essa e a pior classe de bug deste dominio.
 */

declare const self: ServiceWorkerGlobalScope;

type PushPayload = {
  title?: string;
  body?: string;
  conversationId?: number | string;
  url?: string;
  icon?: string;
};

const FALLBACK_TITLE = "Nova mensagem no atendimento";

self.addEventListener("install", () => {
  // Assume o controle sem esperar a aba antiga fechar: o atendente que acabou de autorizar
  // a notificacao espera que ela funcione agora, nao no proximo carregamento.
  void self.skipWaiting();
});

self.addEventListener("activate", (event: ExtendableEvent) => {
  event.waitUntil(self.clients.claim());
});

self.addEventListener("push", (event: PushEvent) => {
  event.waitUntil(show(event));
});

async function show(event: PushEvent): Promise<void> {
  const payload = parse(event);
  const conversationId =
    payload.conversationId != null ? String(payload.conversationId) : null;

  // Se a conversa ja esta visivel numa janela aberta, o SSE acabou de atualizar a tela.
  // Vibrar o aparelho que a pessoa esta segurando e ruido, nao aviso.
  if (conversationId && (await isConversationVisible(conversationId))) {
    return;
  }

  await self.registration.showNotification(payload.title ?? FALLBACK_TITLE, {
    body: payload.body ?? "",
    icon: payload.icon,
    // A tag faz a mensagem nova substituir a anterior da mesma conversa, em vez de empilhar
    // cinco avisos do mesmo cliente.
    tag: conversationId ? `inbox-conversation-${conversationId}` : "inbox",
    data: { url: payload.url ?? "/s/inbox" },
    renotify: Boolean(conversationId),
  } as NotificationOptions);
}

function parse(event: PushEvent): PushPayload {
  // Payload corrompido nao pode derrubar o handler: o navegador pune um push sem
  // showNotification com um aviso generico de "site atualizado em segundo plano".
  try {
    return (event.data?.json() ?? {}) as PushPayload;
  } catch {
    return {};
  }
}

async function isConversationVisible(conversationId: string): Promise<boolean> {
  const clients = await self.clients.matchAll({
    type: "window",
    includeUncontrolled: true,
  });

  return clients.some(
    (client) =>
      client.visibilityState === "visible" &&
      client.url.includes(`/inbox/conversations/${conversationId}`),
  );
}

self.addEventListener("notificationclick", (event: NotificationEvent) => {
  event.notification.close();
  event.waitUntil(
    open(
      String((event.notification.data as { url?: string })?.url ?? "/s/inbox"),
    ),
  );
});

async function open(url: string): Promise<void> {
  const clients = await self.clients.matchAll({
    type: "window",
    includeUncontrolled: true,
  });

  // Reaproveita a janela que ja existe em vez de abrir uma segunda. Atendente com duas abas
  // do mesmo inbox perde o fio da conversa.
  for (const client of clients) {
    if (client.url.includes("/inbox")) {
      await client.focus();
      if ("navigate" in client) {
        await (client as WindowClient).navigate(url).catch(() => undefined);
      }

      return;
    }
  }

  await self.clients.openWindow(url);
}

export {};
