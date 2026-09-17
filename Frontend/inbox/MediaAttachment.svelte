<script lang="ts">
  import { onDestroy } from "svelte";
  import type { Attachment } from "../shared/types";
  export let attachment: Attachment;
  export let t: (key: string) => string;
  let retries = 0;
  let failed = false;
  let cacheBust = "";
  let retryTimer: number | undefined;
  let mediaOwner = `${attachment.type}:${attachment.url || ""}`;
  $: if (`${attachment.type}:${attachment.url || ""}` !== mediaOwner) {
    mediaOwner = `${attachment.type}:${attachment.url || ""}`;
    window.clearTimeout(retryTimer);
    retries = 0;
    failed = false;
    cacheBust = "";
  }
  $: safe = (() => {
    try {
      const url = new URL(attachment.url || "");
      return url.protocol === "https:" ? url.href : null;
    } catch {
      return null;
    }
  })();
  $: mediaUrl = (() => {
    if (!safe) return "";
    const url = new URL(safe);
    if (cacheBust) url.searchParams.set("_media_retry", cacheBust);
    if (attachment.type === "video" && !url.hash) url.hash = "t=0.001";
    return url.href;
  })();
  function error(): void {
    if (retries === 0) {
      retries = 1;
      retryTimer = window.setTimeout(
        () => (cacheBust = String(Date.now())),
        650,
      );
    } else failed = true;
  }
  function retry(): void {
    window.clearTimeout(retryTimer);
    failed = false;
    retries = 0;
    cacheBust = String(Date.now());
  }
  onDestroy(() => window.clearTimeout(retryTimer));
</script>

<div class="inbox-attachment">
  {#if safe}
    {#if !failed && ["image", "sticker"].includes(attachment.type)}<a
        href={safe}
        target="_blank"
        rel="noopener noreferrer"
        aria-label={t("mautic.inbox.ui.open_full_size_image_44fa9f")}
        ><img
          class="inbox-message-media"
          src={mediaUrl}
          alt={attachment.label}
          loading="lazy"
          referrerpolicy="no-referrer"
          on:error={error}
        /></a
      >{/if}
    <!-- Video attachments contain user supplied material and cannot provide a captions track here. -->
    <!-- svelte-ignore a11y_media_has_caption -->
    {#if !failed && attachment.type === "video"}<video
        class="inbox-message-media"
        src={mediaUrl}
        controls
        preload="metadata"
        playsinline
        aria-label={t("mautic.inbox.ui.video_preview_b48d9b")}
        on:error={error}
      ></video>{/if}
    {#if !failed && attachment.type === "audio"}<audio
        class="inbox-message-media"
        src={mediaUrl}
        controls
        preload="metadata"
        on:error={error}
      ></audio>{/if}
    {#if failed}<span class="inbox-media-unavailable"
        >{t("mautic.inbox.ui.could_not_load_the_preview_9f03de")}</span
      ><button
        type="button"
        class="btn btn-link btn-sm inbox-media-retry"
        on:click={retry}>{t("mautic.inbox.ui.media_retry")}</button
      >{/if}
    <a href={safe} target="_blank" rel="noopener noreferrer"
      >{t("mautic.inbox.ui.open_825b3a")}{attachment.label}</a
    >
  {:else}{attachment.label}{t(
      "mautic.inbox.ui.the_file_was_not_stored_request_it_again_if_needed_1cc4cb",
    )}{/if}
</div>
