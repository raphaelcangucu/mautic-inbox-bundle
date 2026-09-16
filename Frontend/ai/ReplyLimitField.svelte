<script lang="ts">
  export let id: string;
  export let value = 0;
  export let busy = false;
  export let t: (key: string) => string;

  $: normalized = Math.max(0, Math.min(10000, Number(value) || 0));
  $: unlimited = normalized === 0;

  function normalize(): void {
    value = normalized;
  }
</script>

<div class="ai-limit-control" class:unlimited>
  <span class="ai-limit-icon" aria-hidden="true"
    >{unlimited ? "∞" : normalized}</span
  >
  <div class="ai-limit-copy">
    <label for={id}>{t("limit")}</label>
    <input
      {id}
      class="form-control"
      type="number"
      min="0"
      max="10000"
      step="1"
      bind:value
      disabled={busy}
      on:blur={normalize}
      aria-describedby={`${id}-hint`}
    />
    <small id={`${id}-hint`}>
      {unlimited ? t("unlimited_hint") : t("limit_hint")}
    </small>
  </div>
</div>
