import type { Conversation } from "../shared/types";
type ChatMessage = { id: number };
type SessionData = {
  realtime: NonNullable<Conversation["realtime"]>;
  visitor_last_read_message_id?: number | null;
};

export interface WebChatRealtimeHandlers {
  event(event: Record<string, unknown>): void;
  status(status: "connecting" | "online" | "offline"): void;
}

/** EventSource receives updates. A bounded serial HTTP queue sends user actions. */
export class WebChatRealtime {
  private source: EventSource | null = null;
  private online = false;
  private queue: Record<string, unknown>[] = [];
  private sending = false;
  private readId = 0;
  private deliveredId = 0;
  private typingActive = false;
  private typingAt = 0;
  private session: SessionData;
  constructor(
    conversation: Conversation,
    private handlers: WebChatRealtimeHandlers,
  ) {
    this.session = { realtime: conversation.realtime! };
  }
  connect(): void {
    this.close();
    this.handlers.status("connecting");
    const url = this.session.realtime.url.replace(/^ws/, "http");
    const separator = url.includes("?") ? "&" : "?";
    const source = (this.source = new EventSource(
      `${url}${separator}token=${encodeURIComponent(this.session.realtime.token)}`,
    ));
    source.onopen = () => {
      this.online = true;
      this.handlers.status("online");
    };
    source.onmessage = (message) => {
      try {
        const event = JSON.parse(message.data);
        if (event.type === "auth.expired") this.close();
        this.handlers.event(event);
      } catch {
        /* malformed event */
      }
    };
    source.onerror = () => {
      this.online = false;
      this.handlers.status("offline");
      // Native EventSource reconnects with Last-Event-ID; renew expired credentials.
      if (Date.parse(this.session.realtime.expires_at) <= Date.now()) {
        this.close();
        this.handlers.event({ type: "auth.expired" });
      }
    };
  }
  updateSession(session: SessionData): void {
    this.session = session;
    this.readId = Math.max(
      this.readId,
      Number(session.visitor_last_read_message_id || 0),
    );
  }
  send(event: Record<string, unknown>): boolean {
    if (!this.online || this.queue.length >= 32) return false;
    this.queue.push(event);
    void this.drain();
    return true;
  }
  typing(active: boolean, name = "Atendimento"): void {
    if (
      active === this.typingActive &&
      (!active || Date.now() - this.typingAt < 2000)
    )
      return;
    if (
      this.send({ type: active ? "typing.started" : "typing.stopped", name })
    ) {
      this.typingActive = active;
      this.typingAt = Date.now();
    }
  }
  sendMessage(body: string, clientId: string): boolean {
    return this.send({
      type: "message.send",
      body,
      client_id: clientId,
      request_id: clientId,
    });
  }
  receipt(kind: "delivered" | "read", message: ChatMessage): void {
    if (
      message.id <= 0 ||
      message.id <= this.readId ||
      (kind === "delivered" && message.id <= this.deliveredId)
    )
      return;
    const previous = kind === "read" ? this.readId : this.deliveredId;
    if (
      !this.send({
        type: `message.${kind}`,
        message_id: message.id,
        previous,
        request_id: `${kind}-${message.id}`,
      })
    )
      return;
    if (kind === "read") this.readId = message.id;
    else this.deliveredId = message.id;
  }
  read(messageId: number): void {
    this.receipt("read", { id: messageId });
  }
  close(): void {
    this.source?.close();
    this.source = null;
    this.online = false;
    this.typingActive = false;
  }
  private async drain(): Promise<void> {
    if (this.sending) return;
    this.sending = true;
    try {
      while (this.queue.length) {
        const event = this.queue.shift()!;
        try {
          const { previous: _previous, ...payload } = event;
          const url =
            this.session.realtime.event_url ||
            this.session.realtime.url
              .replace(/^ws/, "http")
              .replace(/\/chat\/realtime$/, "/chat/api/realtime/events");
          const response = await fetch(url, {
            method: "POST",
            headers: {
              "content-type": "application/json",
              authorization: `Bearer ${this.session.realtime.token}`,
            },
            body: JSON.stringify(payload),
            signal: AbortSignal.timeout(8000),
          });
          if (!response.ok) throw new Error(`Event failed: ${response.status}`);
          const result = await response.json();
          // The POST acknowledgement also confirms sending if the stream was interrupted.
          if (result.message)
            this.handlers.event({
              type: "message.created",
              message: result.message,
            });
        } catch {
          if (
            event.type === "message.read" &&
            this.readId === Number(event.message_id)
          )
            this.readId = Number(event.previous || 0);
          if (
            event.type === "message.delivered" &&
            this.deliveredId === Number(event.message_id)
          )
            this.deliveredId = Number(event.previous || 0);
          if (event.type === "message.send")
            this.handlers.event({
              type: "event.failed",
              request_id: event.request_id,
            });
        }
      }
    } finally {
      this.sending = false;
    }
  }
}
