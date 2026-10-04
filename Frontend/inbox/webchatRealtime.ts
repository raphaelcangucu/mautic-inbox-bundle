import type { Conversation } from "../shared/types";

export interface WebChatRealtimeHandlers {
  event(event: Record<string, unknown>): void;
  status(status: "connecting" | "online" | "offline"): void;
}

export class WebChatRealtime {
  private socket: WebSocket | null = null;
  private timer = 0;
  private retries = 0;
  constructor(
    private conversation: Conversation,
    private handlers: WebChatRealtimeHandlers,
  ) {}
  connect(): void {
    const realtime = this.conversation.realtime;
    if (!realtime) return;
    this.close();
    this.handlers.status("connecting");
    const separator = realtime.url.includes("?") ? "&" : "?";
    this.socket = new WebSocket(
      `${realtime.url}${separator}token=${encodeURIComponent(realtime.token)}`,
    );
    this.socket.onopen = () => {
      this.retries = 0;
      this.handlers.status("online");
    };
    this.socket.onmessage = (message) => {
      try {
        this.handlers.event(JSON.parse(message.data));
      } catch {
        /* malformed event */
      }
    };
    this.socket.onclose = () => {
      this.handlers.status("offline");
      this.timer = window.setTimeout(
        () => this.connect(),
        Math.min(1000 * 2 ** this.retries++, 15000),
      );
    };
    this.socket.onerror = () => this.socket?.close();
  }
  send(event: Record<string, unknown>): boolean {
    if (this.socket?.readyState !== WebSocket.OPEN) return false;
    this.socket.send(JSON.stringify(event));
    return true;
  }
  typing(active: boolean): void {
    this.send({
      type: active ? "typing.started" : "typing.stopped",
      name: "Atendimento",
    });
  }
  read(messageId: number): void {
    this.send({
      type: "message.read",
      message_id: messageId,
      request_id: `agent-read-${messageId}`,
    });
  }
  close(): void {
    clearTimeout(this.timer);
    if (this.socket) {
      this.socket.onclose = null;
      this.socket.close();
    }
    this.socket = null;
  }
}
