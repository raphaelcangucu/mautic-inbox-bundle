export class RequestError extends Error {
  constructor(
    message: string,
    public status: number,
  ) {
    super(message);
  }
}

export function endpoint(template: string, id?: string | number): string {
  return id === undefined
    ? template
    : template.replace(/\/0(?=\/|$)/, `/${id}`);
}

export async function jsonRequest<T>(
  url: string,
  csrf: string,
  options: RequestInit = {},
  fallback = "",
): Promise<T> {
  const headers = new Headers(options.headers);
  headers.set("Accept", "application/json");
  if (options.body) {
    headers.set("Content-Type", "application/json");
    headers.set("X-CSRF-Token", csrf);
  }
  const response = await fetch(url, {
    ...options,
    headers,
    credentials: "same-origin",
  });
  let data: Record<string, unknown> = {};
  try {
    data = (await response.json()) as Record<string, unknown>;
  } catch {
    /* error below has a stable fallback */
  }
  if (!response.ok)
    throw new RequestError(
      String(data.error || fallback || response.statusText || "Request failed"),
      response.status,
    );
  return data as T;
}

export function requestId(): string {
  return (
    globalThis.crypto?.randomUUID?.().replace(/-/g, "") ||
    `${Date.now()}${Math.random().toString(36).slice(2)}`
  ).slice(0, 64);
}
