<script lang="ts">
  import { onDestroy, onMount } from "svelte";
  import { aiBootstrap, emptyAiData, translator } from "../shared/bootstrap";
  import { jsonRequest } from "../shared/api";
  import type { AiAgent, AiData, AiDocument } from "../shared/types";
  import DocumentsView from "./DocumentsView.svelte";
  import PiView from "./PiView.svelte";
  import AgentsView from "./AgentsView.svelte";

  export let root: HTMLElement;

  type Tab = "documents" | "pi" | "agents";
  type AiActionResponse = { ok?: boolean; result?: unknown };

  const config = aiBootstrap(root);
  const t = translator(config.labels);

  let data: AiData = emptyAiData();
  let selectedDocument: AiDocument | null = null;
  let selectedAgent: AiAgent | null = null;
  let tab: Tab = "documents";
  let busy = false;
  let loading = true;
  let feedback = "";
  let feedbackError = false;
  let feedbackTimer: number | undefined;

  const tabs: Array<{ key: Tab; index: string }> = [
    { key: "documents", index: "01" },
    { key: "pi", index: "02" },
    { key: "agents", index: "03" },
  ];

  function showFeedback(message: string, error = false): void {
    window.clearTimeout(feedbackTimer);
    feedback = message;
    feedbackError = error;
    if (!error && message !== t("loading")) {
      feedbackTimer = window.setTimeout(() => {
        feedback = "";
      }, 5000);
    }
  }

  async function request<T>(payload?: Record<string, unknown>): Promise<T> {
    if (busy) throw new Error(t("loading"));
    busy = true;
    try {
      return await jsonRequest<T>(
        payload ? config.action : config.url,
        config.csrf,
        {
          method: payload ? "POST" : "GET",
          body: payload ? JSON.stringify(payload) : undefined,
        },
      );
    } finally {
      busy = false;
    }
  }

  async function load(): Promise<void> {
    const next = await request<AiData>();
    data = next;
    selectedDocument = next.documents.length
      ? (next.documents.find((item) => item.key === selectedDocument?.key) ??
        next.documents[0])
      : null;
    selectedAgent = next.agents.length
      ? (next.agents.find((item) => item.key === selectedAgent?.key) ??
        next.agents[0])
      : null;
  }

  async function perform(action: () => Promise<void>): Promise<void> {
    try {
      showFeedback(t("loading"));
      await action();
    } catch (error) {
      showFeedback(
        error instanceof Error ? error.message : String(error),
        true,
      );
    }
  }

  function newDocument(): void {
    selectedDocument = {
      key: "",
      revision: 0,
      draft: { name: "novo.md", body: "# ", scope: "agent" },
      versions: [],
    };
  }

  async function saveDocument(
    document: AiDocument,
    publish: boolean,
  ): Promise<void> {
    if (publish && !window.confirm(t("confirm"))) {
      feedback = "";
      return;
    }
    const response = await request<AiActionResponse>({
      action: "document",
      key: document.key,
      revision: document.revision,
      name: document.draft.name,
      body: document.draft.body,
      scope: document.draft.scope,
      publish,
    });
    selectedDocument = response.result as AiDocument;
    await load();
    showFeedback(t("saved"));
  }

  function newAgent(): void {
    selectedAgent = {
      key: "",
      revision: 0,
      name: "",
      profile: "macro-support",
      enabled: false,
      limit: 0,
      documents: [],
      permissions: [],
    };
  }

  async function saveAgent(agent: AiAgent): Promise<void> {
    // The legacy form omitted checked values disabled by the global policy.
    const permissions = agent.permissions.filter((permission) =>
      data.config.permissions.includes(permission),
    );
    await request<AiActionResponse>({ ...agent, permissions, action: "agent" });
    await load();
    showFeedback(t("saved"));
  }

  async function saveConfig(): Promise<void> {
    await request<AiActionResponse>({
      action: "config",
      model: data.config.model,
      enabled: data.config.enabled,
      limit: data.config.limit,
      permissions: data.config.permissions,
    });
    await load();
    showFeedback(t("saved"));
  }

  async function runPiAction(action: string): Promise<void> {
    if (
      ["install", "import-auth", "validate"].includes(action) &&
      !window.confirm(t("confirm"))
    ) {
      feedback = "";
      return;
    }
    await request<AiActionResponse>({ action });
    await load();
    showFeedback(t("saved"));
  }

  onMount(() => {
    root.dataset.svelteInboxMounted = "1";
    void perform(async () => {
      await load();
      loading = false;
      feedback = "";
    });
  });

  onDestroy(() => {
    window.clearTimeout(feedbackTimer);
    root.removeAttribute("data-svelte-inbox-mounted");
  });
</script>

<header class="ai-header">
  <div class="ai-heading">
    <span class="ai-mark" aria-hidden="true"
      ><i class="ri-sparkling-2-line"></i></span
    >
    <div>
      <span class="ai-kicker">{t("eyebrow")}</span>
      <h2>{t("title")}</h2>
      <p>{t("subtitle")}</p>
    </div>
  </div>
  <div class="ai-header-actions">
    <span class="ai-live-pill"><i></i>{t("workspace_ready")}</span>
    <a class="btn btn-default" href={config.inboxUrl}>← {t("back")}</a>
  </div>
</header>

<nav class="ai-tabs" aria-label={t("title")}>
  {#each tabs as item}
    <button
      type="button"
      data-ai-tab={item.key}
      class="btn btn-default"
      class:active={tab === item.key}
      aria-current={tab === item.key ? "page" : undefined}
      disabled={busy}
      on:click={() => (tab = item.key)}
    >
      <span class="ai-tab-index">{item.index}</span>{t(item.key)}
    </button>
  {/each}
</nav>

<div
  id="ai-feedback"
  class:error={feedbackError}
  role={feedbackError ? "alert" : "status"}
>
  {feedback}
</div>

{#if tab === "documents"}
  <DocumentsView
    documents={data.documents}
    document={selectedDocument}
    {busy}
    {loading}
    {t}
    onSelect={(document) => (selectedDocument = document)}
    onNew={newDocument}
    onSave={(document, publish) =>
      perform(() => saveDocument(document, publish))}
  />
{:else if tab === "pi"}
  <PiView
    bind:data
    {busy}
    {loading}
    {t}
    onSave={() => perform(saveConfig)}
    onAction={(action) => perform(() => runPiAction(action))}
  />
{:else}
  <AgentsView
    documents={data.documents}
    assets={data.assets}
    globalPermissions={data.config.permissions}
    agent={selectedAgent}
    agents={data.agents}
    {busy}
    {loading}
    {t}
    onSelect={(agent) => (selectedAgent = agent)}
    onNew={newAgent}
    onSave={(agent) => perform(() => saveAgent(agent))}
  />
{/if}
