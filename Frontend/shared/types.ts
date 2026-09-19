export type Labels = Record<string, string>;
export type Channel = "whatsapp" | "instagram" | "facebook" | string;

export interface Asset {
  id?: number;
  name: string;
  handle?: string;
  phone?: string;
  channel?: Channel;
  // Opcional porque uma conversa guardada no cache antes desta versao volta sem ele, e a
  // lista precisa continuar desenhando o que ja tinha.
  type?: string;
}
export interface Assignee {
  id: number;
  name: string;
}
export interface Contact {
  id: number;
  name: string;
  email?: string;
  phone?: string;
  url: string;
}
export interface Origin {
  image?: string;
  title?: string;
  author?: string;
  body: string;
  permalink?: string;
  related_state_id?: number;
  related_kind?: string;
}
export interface Conversation {
  id: number;
  version: number;
  lifecycle: string;
  channel: Channel;
  recipient: string;
  contact_name: string;
  contact_handle?: string;
  avatar_url?: string;
  asset: Asset;
  last_message_at: string;
  last_message_preview?: string;
  preview?: string;
  unread?: number;
  needs_response?: boolean;
  conversation_kind?: string;
  assignee?: Assignee | null;
  contact?: Contact | null;
  origins?: Origin[];
  drafts?: Record<string, string>;
  can_reply?: boolean;
  can_take_and_reply?: boolean;
  reply_blocked_reason?: string | null;
  reply_hint?: string;
  reply_public?: boolean;
  human_takeover?: boolean;
}
export interface Attachment {
  type: string;
  label: string;
  url?: string | null;
  available?: boolean;
}
export interface TimelineItem {
  id: number;
  kind: string;
  direction?: string;
  status?: string;
  body?: string;
  author?: string;
  timestamp: string;
  event?: string;
  content_label?: string;
  attachments?: Attachment[];
  context?: unknown;
  failure?: string;
  retryable?: boolean;
  ai?: { agent?: string; key?: string };
  /** Só em itens de envio. O servidor já manda; era o tipo que não declarava. */
  request_id?: string;
}
export interface CannedResponse {
  id: number;
  name: string;
  body: string;
  enabled?: boolean;
}
export interface UserOption {
  id: number;
  name: string;
}
export interface AutomationRule {
  id: number;
  campaign: string;
  name: string;
  asset_id: number;
  media_id: string;
  keyword?: string;
  published: boolean;
  url: string;
}
export interface AiAgentOption {
  key: string;
  name: string;
  allowed: boolean;
  reason?: string;
  reason_label?: string;
}
export interface AiAssignment {
  agent: string;
  name: string;
  status: string;
  activity?: string;
  processing?: boolean;
  count: number;
  limit?: number;
  reason?: string;
  status_label: string;
  reason_label?: string;
}
export interface AiPendingReply {
  run_key: string;
  status: string;
  status_label?: string;
  agent?: string;
  text: string;
  reason?: string;
  reason_label?: string;
  error?: string;
  retryable?: boolean;
}
export interface AiInfo {
  agents: AiAgentOption[];
  can_assign: boolean;
  assignment?: AiAssignment | null;
  pending_reply?: AiPendingReply | null;
  version: number;
}
export interface TemplateField {
  key: string;
  component: string;
  token: string;
}
export interface TemplatePart {
  type: string;
  text: string;
}
export interface WhatsAppTemplate {
  id: string | number;
  name: string;
  language: string;
  supported: boolean;
  fields: TemplateField[];
  parts: TemplatePart[];
}
export interface InboxBootstrap {
  labels: Labels;
  locale: string;
  currentUser: number;
  initialStateId: number;
  urls: Record<string, string>;
  canned: CannedResponse[];
  users: UserOption[];
  automationRules: AutomationRule[];
  channelNotices: string[];
  canManageCanned: boolean;
  isAdmin: boolean;
  /** Rodando no shell instalavel, sem o menu do Mautic em volta. */
  standalone: boolean;
}

export interface AiDocumentVersion {
  version: number;
  date: string;
  name: string;
  body: string;
  scope: string;
}
export interface AiDocument {
  key: string;
  revision: number;
  draft: { name: string; body: string; scope: string };
  published?: AiDocumentVersion | null;
  versions?: AiDocumentVersion[];
}
export interface AiAgent {
  key: string;
  revision: number;
  name: string;
  profile: string;
  enabled: boolean;
  limit: number;
  documents: string[];
  permissions: string[];
}
export interface AiAsset {
  id: number;
  name: string;
  channel: string;
}
export interface AiData {
  documents: AiDocument[];
  agents: AiAgent[];
  assets: AiAsset[];
  installed: boolean;
  config: {
    enabled: boolean;
    model: string;
    limit: number;
    permissions: string[];
  };
  health: {
    models?: Array<{ id: string; name?: string }>;
    authenticated?: boolean;
    validated?: boolean;
    cms?: { configured?: boolean; url?: string };
  };
}

/** O que a tela de ajustes precisa saber sobre a notificacao do navegador. */
export type PushUiState =
  | { kind: "unsupported"; reason: string }
  | { kind: "unconfigured" }
  | { kind: "denied" }
  | { kind: "off" }
  | { kind: "on" };
