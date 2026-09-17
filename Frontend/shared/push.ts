/**
 * Inscricao de notificacao do navegador.
 *
 * Nada aqui pede permissao sozinho. O navegador so entrega o pedido quando ele nasce de um
 * gesto explicito da pessoa, e no iOS isso e obrigatorio — alem de ser boa educacao em todo
 * lugar. Quem chama e o botao nos ajustes.
 */

export type PushState =
  | { kind: "unsupported"; reason: string }
  | { kind: "unconfigured" }
  | { kind: "denied" }
  | { kind: "off" }
  | { kind: "on" };

type Config = {
  publicKey: string | null;
  configured: boolean;
  subscribed: boolean;
};

const SCOPE = "/s/";
const WORKER = "/inbox-sw.js";

export function supported(): { ok: boolean; reason: string } {
  if (typeof window === "undefined") return { ok: false, reason: "sem janela" };
  if (!("serviceWorker" in navigator))
    return { ok: false, reason: "navegador sem service worker" };
  if (!("PushManager" in window))
    return { ok: false, reason: "navegador sem Web Push" };
  if (!window.isSecureContext)
    return { ok: false, reason: "a pagina precisa estar em HTTPS" };

  return { ok: true, reason: "" };
}

export async function currentState(configUrl: string): Promise<PushState> {
  const able = supported();
  if (!able.ok) return { kind: "unsupported", reason: able.reason };

  const config = await readConfig(configUrl);
  if (!config.configured || !config.publicKey) return { kind: "unconfigured" };
  if (Notification.permission === "denied") return { kind: "denied" };

  const registration = await navigator.serviceWorker.getRegistration(SCOPE);
  const existing = await registration?.pushManager.getSubscription();

  return existing && config.subscribed ? { kind: "on" } : { kind: "off" };
}

export async function enable(
  urls: { config: string; subscribe: string },
  csrf: string,
): Promise<PushState> {
  const able = supported();
  if (!able.ok) return { kind: "unsupported", reason: able.reason };

  const config = await readConfig(urls.config);
  if (!config.configured || !config.publicKey) return { kind: "unconfigured" };

  // Um "nao" do navegador e definitivo: nenhum codigo consegue pedir de novo, e insistir a
  // cada abertura so irrita.
  const permission = await Notification.requestPermission();
  if (permission !== "granted") return { kind: "denied" };

  const registration = await navigator.serviceWorker.register(WORKER, {
    scope: SCOPE,
  });
  await navigator.serviceWorker.ready;

  const subscription =
    (await registration.pushManager.getSubscription()) ??
    (await registration.pushManager.subscribe({
      userVisibleOnly: true,
      // applicationServerKey nao aceita string: o navegador quer os octetos crus.
      applicationServerKey: decodeBase64Url(config.publicKey),
    }));

  const raw = subscription.toJSON();
  await send(urls.subscribe, "POST", csrf, {
    endpoint: subscription.endpoint,
    keys: { p256dh: raw.keys?.p256dh ?? "", auth: raw.keys?.auth ?? "" },
  });

  return { kind: "on" };
}

export async function disable(
  unsubscribeUrl: string,
  csrf: string,
): Promise<PushState> {
  const registration = await navigator.serviceWorker.getRegistration(SCOPE);
  const subscription = await registration?.pushManager.getSubscription();

  if (subscription) {
    const endpoint = subscription.endpoint;
    await subscription.unsubscribe().catch(() => undefined);
    await send(unsubscribeUrl, "DELETE", csrf, { endpoint });
  }

  return { kind: "off" };
}

async function readConfig(url: string): Promise<Config> {
  const response = await fetch(url, {
    headers: { Accept: "application/json" },
    credentials: "same-origin",
  });
  if (!response.ok)
    return { publicKey: null, configured: false, subscribed: false };

  return (await response.json()) as Config;
}

async function send(
  url: string,
  method: string,
  csrf: string,
  body: unknown,
): Promise<void> {
  const response = await fetch(url, {
    method,
    credentials: "same-origin",
    headers: { "Content-Type": "application/json", "X-CSRF-Token": csrf },
    body: JSON.stringify(body),
  });

  if (!response.ok) {
    throw new Error(`O servidor recusou a inscricao (${response.status}).`);
  }
}

function decodeBase64Url(value: string): Uint8Array<ArrayBuffer> {
  const padded =
    value.replace(/-/g, "+").replace(/_/g, "/") +
    "=".repeat((4 - (value.length % 4)) % 4);
  const binary = atob(padded);
  // O buffer nasce explicito para que o tipo seja Uint8Array<ArrayBuffer>: o
  // applicationServerKey recusa ArrayBufferLike, que poderia ser compartilhado.
  const bytes = new Uint8Array(new ArrayBuffer(binary.length));
  for (let i = 0; i < binary.length; i += 1) bytes[i] = binary.charCodeAt(i);

  return bytes;
}

/** iOS so entrega push quando o app foi instalado na tela de inicio pelo Safari. */
export function iosNeedsInstall(): boolean {
  if (typeof navigator === "undefined") return false;
  const ios =
    /iPad|iPhone|iPod/.test(navigator.userAgent) ||
    (navigator.platform === "MacIntel" &&
      (navigator as unknown as { maxTouchPoints: number }).maxTouchPoints > 1);
  const installed =
    window.matchMedia?.("(display-mode: standalone)").matches ||
    (navigator as unknown as { standalone?: boolean }).standalone === true;

  return ios && !installed;
}
