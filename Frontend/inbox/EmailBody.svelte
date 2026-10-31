<script lang="ts">
  import { emailDocument, hasRemoteImages } from "./emailThread";
  /** HTML ja saneado pelo servidor. O iframe e a segunda camada, nao a unica. */
  export let html: string;
  export let t: (key: string) => string;
  export let title = "";
  let frame: HTMLIFrameElement | null = null;
  let showImages = false;
  let height = 120;
  const origin =
    "undefined" === typeof location ? "" : String(location.origin || "");
  $: remote = hasRemoteImages(html, origin);
  $: source = emailDocument(html, origin, showImages);
  /**
   * allow-same-origin existe so para isto: o pai le a altura do documento. Sem allow-scripts
   * o conteudo nao executa nada, entao a mesma origem nao da a ele acesso a coisa alguma.
   */
  function measure(): void {
    try {
      const root = frame?.contentDocument?.documentElement;
      if (root) height = Math.max(48, Math.ceil(root.scrollHeight) + 4);
    } catch {
      height = 320;
    }
  }
</script>

<div class="inbox-email-body">
  {#if remote && !showImages}<div class="inbox-email-images" role="status">
      <span>{t("mautic.inbox.ui.email_images_blocked")}</span><button
        type="button"
        class="btn btn-link btn-sm"
        on:click={() => (showImages = true)}
        >{t("mautic.inbox.ui.email_show_images")}</button
      >
    </div>{/if}
  <iframe
    bind:this={frame}
    class="inbox-email-frame"
    sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
    referrerpolicy="no-referrer"
    {title}
    srcdoc={source}
    style:height={`${height}px`}
    on:load={measure}
  ></iframe>
</div>
