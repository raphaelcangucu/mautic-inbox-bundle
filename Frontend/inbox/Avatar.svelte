<script lang="ts">
  import Icon from "../shared/Icon.svelte";
  export let name = "?";
  export let id = 0;
  export let elementId: string | undefined = undefined;
  export let channel: string | null = null;
  export let photo: string | null = null;
  export let large = false;
  export let photoLabel = "Photo of ";
  let failed = false;
  let photoOwner = photo;
  $: if (photo !== photoOwner) {
    photoOwner = photo;
    failed = false;
  }
  $: initials = (name || "?")
    .split(/\s+/)
    .slice(0, 2)
    .map((word) => word.charAt(0))
    .join("")
    .toUpperCase();
  $: safePhoto = (() => {
    if (!photo || failed) return null;
    try {
      const url = new URL(photo, location.origin);
      return url.protocol === "https:" || url.origin === location.origin
        ? url.href
        : null;
    } catch {
      return null;
    }
  })();
</script>

<div
  id={elementId}
  class:large
  class="inbox-avatar"
  data-tone={Number(id || 0) % 5}
>
  {initials}
  {#if safePhoto}<img
      src={safePhoto}
      alt={photoLabel + name}
      loading="lazy"
      referrerpolicy="no-referrer"
      on:error={() => (failed = true)}
    />{/if}
  {#if channel}<span class="inbox-channel-dot {channel}"
      ><Icon name={channel} /></span
    >{/if}
</div>
