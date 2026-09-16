export interface InboxAlerts {
  receive(items: Array<{ id: number; state_id: number }>): void;
  acknowledge(stateId: number): void;
  toggle(): void;
  unlock(): void;
  dispose(): void;
}
export interface AlertState {
  enabled: boolean;
  ready: boolean;
  unavailable: boolean;
  pending: number;
}

export function createInboxAlerts(
  currentUser: number,
  onState: (state: AlertState) => void,
): InboxAlerts {
  const pending = new Map<string, number>();
  let audio: AudioContext | null = null;
  let disposed = false;
  const key = `mautic-inbox-sound-${currentUser}`;
  let enabled = true;
  try {
    enabled = localStorage.getItem(key) !== "off";
  } catch {
    /* private browser storage */
  }
  const icons = Array.from(
    document.querySelectorAll<HTMLLinkElement>('link[rel~="icon"]'),
  ).map((el) => ({
    el,
    href: el.getAttribute("href"),
    type: el.getAttribute("type"),
  }));
  const badge = document.createElement("link");
  badge.rel = "icon";
  badge.type = "image/png";
  const base = new Image();
  if (icons.length) base.src = icons[0].el.href;
  base.onload = paint;
  const snapshot = (): AlertState => ({
    enabled,
    ready: Boolean(audio && audio.state === "running"),
    unavailable: !(
      window.AudioContext ||
      (window as typeof window & { webkitAudioContext?: typeof AudioContext })
        .webkitAudioContext
    ),
    pending: pending.size,
  });
  function emit(): void {
    onState(snapshot());
  }
  function paint(): void {
    if (disposed) return;
    if (!pending.size) {
      badge.remove();
      icons.forEach((i) => {
        if (i.href === null) i.el.removeAttribute("href");
        else i.el.setAttribute("href", i.href);
        if (i.type === null) i.el.removeAttribute("type");
        else i.el.setAttribute("type", i.type);
      });
      emit();
      return;
    }
    const canvas = document.createElement("canvas");
    canvas.width = canvas.height = 32;
    const ctx = canvas.getContext("2d");
    if (!ctx) return;
    ctx.fillStyle = "#535ca0";
    ctx.fillRect(0, 0, 32, 32);
    try {
      if (base.complete && base.naturalWidth) ctx.drawImage(base, 0, 0, 32, 32);
    } catch {
      /* cross-origin favicon */
    }
    ctx.fillStyle = "#dc3545";
    ctx.beginPath();
    ctx.arc(23, 9, 9, 0, Math.PI * 2);
    ctx.fill();
    ctx.fillStyle = "#fff";
    ctx.font = "bold 12px sans-serif";
    ctx.textAlign = "center";
    ctx.textBaseline = "middle";
    ctx.fillText(pending.size > 9 ? "9+" : String(pending.size), 23, 10);
    try {
      badge.href = canvas.toDataURL();
      icons.forEach((i) => {
        i.el.href = badge.href;
        i.el.type = "image/png";
      });
      document.head.appendChild(badge);
    } catch {
      /* favicon remains unchanged */
    }
    emit();
  }
  async function unlock(): Promise<void> {
    if (!enabled || disposed) return;
    const Audio =
      window.AudioContext ||
      (window as typeof window & { webkitAudioContext?: typeof AudioContext })
        .webkitAudioContext;
    if (!Audio) {
      emit();
      return;
    }
    try {
      if (!audio) audio = new Audio();
      await audio.resume();
    } catch {
      /* browser gesture can be required */
    }
    emit();
  }
  function sound(): void {
    if (!enabled || !audio || audio.state !== "running" || disposed) return;
    [660, 880].forEach((frequency, index) => {
      const oscillator = audio!.createOscillator();
      const gain = audio!.createGain();
      const start = audio!.currentTime + index * 0.13;
      oscillator.frequency.value = frequency;
      gain.gain.setValueAtTime(0, start);
      gain.gain.linearRampToValueAtTime(0.08, start + 0.015);
      gain.gain.exponentialRampToValueAtTime(0.001, start + 0.15);
      oscillator.connect(gain);
      gain.connect(audio!.destination);
      oscillator.start(start);
      oscillator.stop(start + 0.16);
    });
  }
  const storage = (event: StorageEvent) => {
    if (event.key === key) {
      enabled = event.newValue !== "off";
      emit();
    }
  };
  window.addEventListener("storage", storage);
  emit();
  return {
    receive(items) {
      const fresh = items.filter((item) => !pending.has(String(item.id)));
      fresh.forEach((item) =>
        pending.set(String(item.id), Number(item.state_id)),
      );
      if (!fresh.length) return;
      paint();
      if (!enabled || !audio || audio.state !== "running") return;
      const last = Math.max(...fresh.map((i) => Number(i.id)));
      const play = () => {
        if (disposed) return;
        const marker = `${key}-last`;
        try {
          if (Number(localStorage.getItem(marker) || 0) >= last) return;
          localStorage.setItem(marker, String(last));
        } catch {
          /* still play in this tab */
        }
        sound();
      };
      if (navigator.locks)
        void navigator.locks.request(key, play).catch(() => undefined);
      else play();
    },
    acknowledge(stateId) {
      pending.forEach((id, message) => {
        if (id === Number(stateId)) pending.delete(message);
      });
      paint();
    },
    toggle() {
      enabled = !(enabled && audio?.state === "running");
      try {
        localStorage.setItem(key, enabled ? "on" : "off");
      } catch {
        /* private storage */
      }
      if (enabled) void unlock();
      emit();
    },
    unlock() {
      void unlock();
    },
    dispose() {
      pending.clear();
      paint();
      disposed = true;
      window.removeEventListener("storage", storage);
      base.onload = null;
      if (audio) void audio.close();
    },
  };
}
