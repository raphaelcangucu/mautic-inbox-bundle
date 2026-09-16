<script lang="ts">
  import PermissionGrid from "./PermissionGrid.svelte";
  import ReplyLimitField from "./ReplyLimitField.svelte";
  import type { AiData } from "../shared/types";

  export let data: AiData;
  export let busy = false;
  export let loading = false;
  export let t: (key: string) => string;
  export let onSave: () => void;
  export let onAction: (action: string) => void;

  $: health = [
    data.installed ? t("installed") : t("missing"),
    data.health.authenticated ? `${t("auth")} ✓` : `${t("auth")} —`,
    data.health.validated ? t("ready") : t("pending"),
    data.health.cms?.configured
      ? `${t("cms")} · ${data.health.cms.url ?? ""}`
      : `${t("cms")} —`,
  ];
  $: models = data.health.models?.length
    ? data.health.models
    : data.config.model
      ? [{ id: data.config.model, name: data.config.model }]
      : [];
</script>

<section data-ai-panel="pi" aria-busy={busy || loading}>
  <div class="ai-card">
    <h3>{t("pi")}</h3>
    <div id="ai-health" class="ai-health">
      {#each health as status}<span class="ai-badge">{status}</span>{/each}
    </div>
    <div class="ai-toolbar">
      {#if !data.installed}<button
          id="ai-install"
          type="button"
          class="btn btn-default"
          disabled={busy}
          on:click={() => onAction("install")}>{t("install")}</button
        >{/if}
      <button
        type="button"
        data-pi-action="health"
        class="btn btn-default"
        disabled={busy}
        on:click={() => onAction("health")}>{t("detect")}</button
      >
      <button
        type="button"
        data-pi-action="import-auth"
        class="btn btn-default"
        disabled={busy}
        on:click={() => onAction("import-auth")}>{t("authenticate")}</button
      >
      <button
        type="button"
        data-pi-action="validate"
        class="btn btn-primary"
        disabled={busy}
        on:click={() => onAction("validate")}>{t("validate")}</button
      >
    </div>
    <p class="ai-muted">{t("test_hint")}</p>
  </div>

  <div class="ai-card">
    <div class="ai-grid">
      <label
        >{t("model")}<select
          id="ai-model"
          bind:value={data.config.model}
          class="form-control not-chosen"
          disabled={busy || models.length === 0}
          >{#each models as model}<option value={model.id}
              >{model.name || model.id}</option
            >{/each}</select
        ></label
      >
      <ReplyLimitField
        id="ai-global-limit"
        bind:value={data.config.limit}
        {busy}
        {t}
      />
      <label
        ><input
          id="ai-global-enabled"
          type="checkbox"
          bind:checked={data.config.enabled}
          disabled={busy}
        />
        {t("enabled")}</label
      >
    </div>
    <h3>{t("permissions")}</h3>
    <div id="ai-global-permissions">
      <PermissionGrid
        assets={data.assets}
        bind:values={data.config.permissions}
        {busy}
        {t}
      />
    </div>
    <button
      id="ai-config-save"
      type="button"
      class="btn btn-primary"
      disabled={busy || models.length === 0}
      on:click={onSave}>{t("save")}</button
    >
  </div>
</section>
