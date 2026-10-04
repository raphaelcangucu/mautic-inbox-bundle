import type { TimelineItem } from "../shared/types";

export interface WebChatMessageEvent {
  id?: number;
  inbox_message_id?: number | null;
  outbound_request_id?: number | null;
  direction?: "visitor" | "agent" | "ai" | string;
  body?: string;
  status?: string;
  author?: string | null;
  timestamp?: string;
}

/**
 * Turn the durable identifiers carried by a Web Chat event into the same item
 * keys returned by the Inbox timeline endpoint. The event can then be rendered
 * immediately and the background history refresh reconciles it instead of
 * creating a duplicate.
 */
export function webchatTimelineItem(
  message: WebChatMessageEvent,
): TimelineItem | null {
  const timestamp = message.timestamp || new Date().toISOString();

  if (message.direction === "visitor" && message.inbox_message_id) {
    return {
      id: message.inbox_message_id,
      kind: "message",
      direction: "inbound",
      status: message.status || "received",
      body: message.body || "",
      author: message.author || undefined,
      timestamp,
    };
  }

  if (message.direction === "agent" && message.outbound_request_id) {
    return {
      id: message.outbound_request_id,
      kind: "outbound",
      direction: "outbound",
      status: message.status || "sent",
      body: message.body || "",
      author: message.author || undefined,
      timestamp,
    };
  }

  if (message.direction === "ai" && message.inbox_message_id) {
    return {
      id: message.inbox_message_id,
      kind: "automatic",
      direction: "outbound",
      status: message.status || "sent",
      body: message.body || "",
      author: message.author || undefined,
      timestamp,
      ai: { agent: message.author || "AI" },
    };
  }

  return null;
}
