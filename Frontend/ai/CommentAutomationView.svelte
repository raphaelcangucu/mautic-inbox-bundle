<script lang="ts">
  import type {
    AiData,
    CommentAutomationConfig,
    CommentAutomationRun,
  } from "../shared/types";
  export let data: AiData;
  export let busy = false;
  export let t: (key: string) => string;
  export let onSave: (value: CommentAutomationConfig) => void;
  export let onReview: (
    run: CommentAutomationRun,
    decision: "approve" | "reject",
  ) => void;
  export let onPage: (page: number) => void;

  let agentKey = "";
  let lastAgent = "";
  let previous: CommentAutomationConfig | undefined;
  let value: CommentAutomationConfig;
  let publications = "";
  $: agents = data.agents.filter((agent) => agent.audience !== "internal");
  $: if (!agentKey && agents.length) agentKey = agents[0].key;
  $: agent = agents.find((item) => item.key === agentKey);
  $: stored = data.comment_configs?.find((item) => item.key === agentKey);
  $: if (stored !== previous || lastAgent !== agentKey || !value) {
    value = {
      key: agentKey,
      revision: 0,
      mode: "off",
      asset_ids: [],
      publication_ids: [],
      document_key: "",
      document_version: 0,
      mark_spam_enabled: false,
      public_reply_enabled: false,
      classify_limit: 100,
      reply_limit: 20,
      min_interval: 30,
      notify: true,
      ...stored,
    };
    publications = value.publication_ids.join(", ");
    previous = stored;
    lastAgent = agentKey;
  }
  $: documents = data.documents.filter((doc) => Boolean(doc.published));
  $: assets = data.assets.filter((asset) =>
    ["instagram", "facebook"].includes(asset.channel),
  );
  $: runs = data.comment_runs ?? { items: [], page: 1, hasMore: false };
  $: notices = runs.items.filter((run) =>
    ["review", "failed", "uncertain"].includes(run.status),
  );

  function toggleAsset(id: number, checked: boolean) {
    value = {
      ...value,
      asset_ids: checked
        ? [...value.asset_ids, id]
        : value.asset_ids.filter((item) => item !== id),
    };
  }
  function save() {
    const document = documents.find((doc) => doc.key === value.document_key);
    onSave({
      ...value,
      key: agentKey,
      publication_ids: publications.split(/[\s,]+/).filter(Boolean),
      document_version: document?.published?.version ?? value.document_version,
    });
  }
</script>

<section data-ai-panel="automation" aria-busy={busy}>
  <div class="ai-panel-heading">
    <div>
      <h3>{t("automation")}</h3>
      <p>{t("automation_hint")}</p>
    </div>
    <button
      type="button"
      class="btn btn-default"
      disabled={busy}
      on:click={() => onPage(runs.page)}>{t("automation_refresh")}</button
    >
  </div>
  {#if value?.notify && (notices.length || value.paused)}
    <p role="status" class="ai-muted">
      {value.paused ? t("automation_paused") : t("automation_review_pending")} ·
      {notices.length}
    </p>
  {/if}
  {#if value}
    <div class="ai-grid">
      <div class="ai-card">
        <label
          >{t("agent_selected")}<select
            id="ai-comment-agent"
            class="form-control not-chosen"
            bind:value={agentKey}
            disabled={busy || !agents.length}
            >{#each agents as item}<option value={item.key}>{item.name}</option
              >{/each}</select
          ></label
        >
        <label
          >{t("automation_mode")}<select
            id="ai-comment-mode"
            class="form-control not-chosen"
            bind:value={value.mode}
            disabled={busy}
            ><option value="off">{t("automation_off")}</option><option
              value="review">{t("automation_review")}</option
            ><option value="automatic">{t("automation_automatic")}</option
            ></select
          ></label
        >
        <p class="ai-field-hint">{t("automation_mode_hint")}</p>
        <label
          >{t("automation_rule")}<select
            id="ai-comment-document"
            class="form-control not-chosen"
            bind:value={value.document_key}
            disabled={busy}
            ><option value="">{t("automation_choose_rule")}</option
            >{#each documents as doc}<option value={doc.key}
                >{doc.published!.name} · v{doc.published!.version}</option
              >{/each}</select
          ></label
        >
        <p class="ai-field-hint">{t("automation_rule_hint")}</p>
        <label class="ai-switch-row"
          ><span>{t("automation_mark_spam")}</span><input
            id="ai-comment-spam"
            type="checkbox"
            bind:checked={value.mark_spam_enabled}
            disabled={busy}
          /></label
        >
        <label class="ai-switch-row"
          ><span>{t("automation_public_reply")}</span><input
            id="ai-comment-reply"
            type="checkbox"
            bind:checked={value.public_reply_enabled}
            disabled={busy}
          /></label
        >
        <p class="ai-field-hint">{t("automation_actions_hint")}</p>
      </div>
      <div class="ai-card">
        <h3>{t("automation_accounts")}</h3>
        <div id="ai-comment-assets">
          {#each assets as asset}
            <label class="ai-switch-row"
              ><span>{asset.name} · {asset.channel}</span><input
                type="checkbox"
                value={asset.id}
                checked={value.asset_ids.includes(asset.id)}
                disabled={busy ||
                  !data.config.permissions.includes(`${asset.id}:comment`) ||
                  !agent?.permissions.includes(`${asset.id}:comment`)}
                on:change={(event) =>
                  toggleAsset(asset.id, event.currentTarget.checked)}
              /></label
            >
          {/each}
        </div>
        <label
          >{t("automation_publications")}<input
            id="ai-comment-publications"
            class="form-control"
            bind:value={publications}
            disabled={busy}
            maxlength="2000"
          /></label
        >
        <p class="ai-field-hint">{t("automation_new_only")}</p>
        <div class="ai-grid">
          <label
            >{t("automation_classify_limit")}<input
              id="ai-comment-classify-limit"
              class="form-control"
              type="number"
              min="1"
              max="5000"
              bind:value={value.classify_limit}
              disabled={busy}
            /></label
          >
          <label
            >{t("automation_reply_limit")}<input
              id="ai-comment-reply-limit"
              class="form-control"
              type="number"
              min="1"
              max="1000"
              bind:value={value.reply_limit}
              disabled={busy}
            /></label
          >
        </div>
        <label
          >{t("automation_interval")}<input
            id="ai-comment-interval"
            class="form-control"
            type="number"
            min="0"
            max="3600"
            bind:value={value.min_interval}
            disabled={busy}
          /></label
        >
        <label class="ai-switch-row"
          ><span>{t("automation_notices")}</span><input
            id="ai-comment-notify"
            type="checkbox"
            bind:checked={value.notify}
            disabled={busy}
          /></label
        >
        <button
          id="ai-comment-save"
          type="button"
          class="btn btn-primary"
          disabled={busy || !agentKey}
          on:click={save}>{t("save")}</button
        >
      </div>
    </div>
  {/if}
  <div class="ai-card">
    <h3>{t("automation_history")}</h3>
    {#if !runs.items.length}<p class="ai-muted">{t("automation_empty")}</p>{/if}
    {#each runs.items as run (run.key)}
      <div class="ai-card">
        <a
          href={`${location.pathname.replace(/\/ai\/?$/, "")}/conversations/${run.state}`}
          >{data.assets.find((item) => item.id === run.asset_id)?.name ??
            run.asset_id} · {run.comment_id}</a
        >
        <span class="ai-badge">{t(`automation_status_${run.status}`)}</span>
        {#if run.comment_excerpt}<blockquote>
            {run.comment_excerpt}
          </blockquote>{/if}
        <p class="ai-muted">{run.reason ?? ""}</p>
        {#if run.text}<p>{run.text}</p>{/if}
        {#if run.status === "review"}
          <div class="ai-toolbar">
            <button
              type="button"
              class="btn btn-primary"
              disabled={busy ||
                !["spam", "legitimate"].includes(run.decision ?? "")}
              on:click={() => onReview(run, "approve")}
              >{run.decision === "spam"
                ? t("automation_approve_spam")
                : t("automation_approve_reply")}</button
            >
            <button
              type="button"
              class="btn btn-default"
              disabled={busy}
              on:click={() => onReview(run, "reject")}
              >{t("automation_reject")}</button
            >
          </div>
        {/if}
      </div>
    {/each}
    <div class="ai-toolbar">
      <button
        type="button"
        class="btn btn-default"
        disabled={busy || runs.page === 1}
        on:click={() => onPage(runs.page - 1)}
        >{t("automation_previous")}</button
      >
      <span>{runs.page}</span>
      <button
        type="button"
        class="btn btn-default"
        disabled={busy || !runs.hasMore}
        on:click={() => onPage(runs.page + 1)}>{t("automation_next")}</button
      >
    </div>
  </div>
</section>
