import { mount, unmount } from "svelte";
import InboxApp from "./inbox/InboxApp.svelte";
import AiApp from "./ai/AiApp.svelte";

type MountedRoot = HTMLElement & { __svelteInbox?: ReturnType<typeof mount> };
const mountedRoots = new Set<MountedRoot>();

function mountRoot(
  id: string,
  component: typeof InboxApp | typeof AiApp,
): void {
  const root = document.getElementById(id) as MountedRoot | null;
  if (!root || root.__svelteInbox) return;
  root.__svelteInbox = mount(component, { target: root, props: { root } });
  mountedRoots.add(root);
}

export function bootInbox(): void {
  mountRoot("inbox-app", InboxApp);
}
export function bootAi(): void {
  mountRoot("inbox-ai", AiApp);
}
export function boot(): void {
  bootInbox();
  bootAi();
}

if (window.Mautic) {
  window.Mautic.inboxOnLoad = bootInbox;
  window.Mautic.inboxaiOnLoad = bootAi;
}

if (document.readyState === "loading")
  document.addEventListener("DOMContentLoaded", boot);
else boot();
document.addEventListener("mauticPageContentLoaded", boot);

// Mautic replaces page fragments without a full navigation. Release Svelte
// effects promptly when their mount point is removed from the document.
const observer = new MutationObserver(() => {
  mountedRoots.forEach((root) => {
    if (!root.isConnected && root.__svelteInbox) {
      void unmount(root.__svelteInbox);
      delete root.__svelteInbox;
      mountedRoots.delete(root);
    }
  });
});
observer.observe(document.documentElement, { childList: true, subtree: true });

declare global {
  interface Window {
    Mautic?: Record<string, unknown>;
  }
}
