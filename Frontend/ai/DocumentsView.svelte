<script lang="ts">
  import { renderMessage } from "../shared/markdown";
  import type { AiDocument, AiDocumentVersion } from "../shared/types";

  export let documents: AiDocument[] = [];
  export let document: AiDocument | null = null;
  export let busy = false;
  export let loading = false;
  export let t: (key: string) => string;
  export let onSelect: (document: AiDocument) => void;
  export let onNew: () => void;
  export let onSave: (document: AiDocument, publish: boolean) => void;

  type EditorMode = "edit" | "preview" | "versions";
  let editorMode: EditorMode = "edit";

  function select(item: AiDocument): void {
    editorMode = "edit";
    onSelect(item);
  }
  function create(): void {
    editorMode = "edit";
    onNew();
  }
  function restore(version: AiDocumentVersion): void {
    if (!document) return;
    document = {
      ...document,
      draft: { name: version.name, body: version.body, scope: version.scope },
    };
    editorMode = "edit";
    onSelect(document);
  }
</script>

<section data-ai-panel="documents" aria-busy={busy}>
  <div class="ai-panel-heading">
    <div>
      <h3>{t("documents")}</h3>
      <p>{t("documents_hint")}</p>
    </div>
    <button
      id="ai-new-doc"
      type="button"
      class="btn btn-primary"
      disabled={busy}
      on:click={create}><span aria-hidden="true">＋</span> {t("new")}</button
    >
  </div>

  <div class="ai-doc-layout">
    <aside class="ai-doc-sidebar">
      <div id="ai-doc-list" aria-label={t("documents")}>
        {#each documents as item (item.key)}
          <button
            type="button"
            class:active={document?.key === item.key}
            disabled={busy}
            on:click={() => select(item)}
          >
            <span class="ai-doc-icon" aria-hidden="true">MD</span>
            <span class="ai-doc-copy">
              <span class="ai-doc-name">{item.draft.name}</span>
              <span class="ai-doc-meta">
                <small
                  >{item.published
                    ? `v${item.published.version}`
                    : t("unpublished")}</small
                >
                <span class="ai-scope-badge"
                  >{item.draft.scope === "global"
                    ? t("global_short")
                    : t("agent_short")}</span
                >
              </span>
            </span>
          </button>
        {/each}
        {#if !loading && documents.length === 0}<p class="ai-empty">
            {t("unpublished")}
          </p>{/if}
      </div>
    </aside>

    {#if document}
      <div class="ai-card ai-editor-card">
        <div class="ai-grid">
          <label
            >{t("name")}<input
              id="ai-doc-name"
              class="form-control"
              bind:value={document.draft.name}
              maxlength="100"
              disabled={busy}
            /></label
          >
          <label
            >{t("scope")}<select
              id="ai-doc-scope"
              bind:value={document.draft.scope}
              class="form-control not-chosen"
              disabled={busy}
              ><option value="global">{t("global")}</option><option
                value="agent">{t("specific")}</option
              ></select
            ></label
          >
        </div>
        <div class="ai-editor-tabs">
          {#each ["edit", "preview", "versions"] as mode}
            <button
              id={mode === "edit"
                ? "ai-doc-edit"
                : mode === "preview"
                  ? "ai-doc-preview-button"
                  : "ai-doc-versions-button"}
              type="button"
              class="btn btn-default"
              class:active={editorMode === mode}
              aria-pressed={editorMode === mode}
              disabled={busy}
              on:click={() => (editorMode = mode as EditorMode)}
              >{t(mode)}</button
            >
          {/each}
          <span id="ai-doc-version" class="ai-badge"
            >{document.published
              ? `${t("published")} · v${document.published.version}`
              : t("unpublished")}</span
          >
        </div>

        {#if editorMode === "edit"}
          <textarea
            id="ai-doc-body"
            bind:value={document.draft.body}
            class="form-control"
            rows="17"
            maxlength="60000"
            aria-label="Markdown"
            disabled={busy}
          ></textarea>
        {:else if editorMode === "preview"}
          <div
            id="ai-doc-preview"
            use:renderMessage={{ value: document.draft.body, whatsapp: false }}
          ></div>
        {:else}
          <div id="ai-doc-versions">
            {#each [...(document.versions ?? [])].reverse() as version}
              <p>
                <span>v{version.version} · {version.date}</span>
                <button
                  type="button"
                  class="btn btn-default"
                  disabled={busy}
                  on:click={() => restore(version)}>{t("restore")}</button
                >
              </p>
            {:else}<p class="ai-empty">{t("unpublished")}</p>{/each}
          </div>
        {/if}

        <div class="ai-savebar">
          <p class="ai-muted">{t("publish_hint")}</p>
          <div>
            <button
              id="ai-doc-save"
              type="button"
              class="btn btn-default"
              disabled={busy}
              on:click={() => onSave(document!, false)}>{t("draft")}</button
            ><button
              id="ai-doc-publish"
              type="button"
              class="btn btn-primary"
              disabled={busy}
              on:click={() => onSave(document!, true)}>{t("publish")}</button
            >
          </div>
        </div>
      </div>
    {/if}
  </div>
</section>
