<script lang="ts">
  import PermissionGrid from "./PermissionGrid.svelte";
  import ReplyLimitField from "./ReplyLimitField.svelte";
  import type {
    AiAgent,
    AiAsset,
    AiDocument,
    AiTool,
    AiRole,
  } from "../shared/types";

  export let tools: AiTool[] = [];
  export let roles: AiRole[] = [];
  export let documents: AiDocument[] = [];
  export let assets: AiAsset[] = [];
  export let globalPermissions: string[] = [];
  export let agent: AiAgent | null = null;
  export let agents: AiAgent[] = [];
  export let busy = false;
  export let loading = false;
  export let t: (key: string) => string;
  export let onSelect: (agent: AiAgent) => void;
  export let onNew: () => void;
  export let onSave: (agent: AiAgent) => void;

  $: selectedDocuments = documents.filter((item) =>
    Boolean(
      item.published &&
        (item.published.scope === "global" ||
          agent?.documents.includes(item.key)),
    ),
  );
  $: contextBody = selectedDocuments
    .map(
      (item) =>
        `# ${item.published!.name} · v${item.published!.version}\n${item.published!.body}`,
    )
    .join("\n\n");
  $: internal = agent?.audience === "internal";
  $: selectedPermissionCount = internal
    ? (agent?.mcp_tools?.length ?? 0)
    : (agent?.permissions.filter((permission) =>
        globalPermissions.includes(permission),
      ).length ?? 0);

  function audience(value: string): void {
    if (!agent) return;
    agent = {
      ...agent,
      audience: value === "internal" ? "internal" : "customer",
      mcp_connection: "current_mautic",
      mcp_tools: agent.mcp_tools ?? [],
      role_ids: agent.role_ids ?? [],
    };
    onSelect(agent);
  }
  function toggleTool(name: string, checked: boolean): void {
    if (!agent) return;
    const current = (agent.mcp_tools ?? []).filter((item) => item !== name);
    agent = { ...agent, mcp_tools: checked ? [...current, name] : current };
    onSelect(agent);
  }
  function toggleRole(id: number, checked: boolean): void {
    if (!agent) return;
    const current = (agent.role_ids ?? []).filter((item) => item !== id);
    agent = { ...agent, role_ids: checked ? [...current, id] : current };
    onSelect(agent);
  }
  function choose(key: string): void {
    const selected = agents.find((item) => item.key === key);
    if (selected) onSelect(selected);
  }
  function toggleDocument(key: string, checked: boolean): void {
    if (!agent) return;
    const current = agent.documents.filter((item) => item !== key);
    agent = { ...agent, documents: checked ? [...current, key] : current };
    onSelect(agent);
  }
</script>

<section data-ai-panel="agents" aria-busy={busy || loading}>
  <div class="ai-agent-toolbar">
    <div>
      <span class="ai-section-label">{t("agent_selected")}</span>
      <select
        id="ai-agent-select"
        class="form-control not-chosen"
        value={agent?.key ?? ""}
        disabled={busy || agents.length === 0}
        on:change={(event) => choose(event.currentTarget.value)}
      >
        {#if agent && !agent.key}<option value="">{t("new")}</option>{/if}
        {#each agents as item}<option value={item.key}>{item.name}</option
          >{/each}
      </select>
    </div>
    <div class="ai-agent-toolbar-actions">
      <button
        id="ai-new-agent"
        type="button"
        class="btn btn-default"
        disabled={busy}
        on:click={onNew}><span aria-hidden="true">＋</span> {t("new")}</button
      >
      <button
        id="ai-agent-save-top"
        type="button"
        class="btn btn-primary"
        disabled={busy || !agent}
        on:click={() => agent && onSave(agent)}>{t("save")}</button
      >
    </div>
  </div>

  {#if agent}
    <div class="ai-agent-summary">
      <div class="ai-agent-avatar" aria-hidden="true">AI</div>
      <div class="ai-agent-summary-name">
        <strong id="ai-agent-summary-name">{agent.name || t("new")}</strong
        ><span id="ai-agent-summary-profile"
          >{internal ? t("audience_internal") : agent.profile}</span
        >
      </div>
      <div class="ai-summary-stat">
        <strong id="ai-agent-summary-docs">{selectedDocuments.length}</strong
        ><span>{t("documents")}</span>
      </div>
      <div class="ai-summary-stat">
        <strong id="ai-agent-summary-permissions"
          >{selectedPermissionCount}</strong
        ><span>{internal ? t("mcp_tools") : t("channels")}</span>
      </div>
      {#if !internal}<div class="ai-summary-stat">
          <strong id="ai-agent-summary-limit">{agent.limit || "∞"}</strong><span
            >{t("limit")}</span
          >
        </div>{/if}
      <span
        id="ai-agent-status"
        class="ai-status-pill"
        class:active={agent.enabled}
        >{agent.enabled ? t("active") : t("inactive")}</span
      >
    </div>

    <div class="ai-grid ai-agent-grid">
      <div class="ai-card">
        <div class="ai-card-heading">
          <span class="ai-card-icon" aria-hidden="true">◎</span>
          <div>
            <h3>{t("behavior")}</h3>
            <p>{t("behavior_hint")}</p>
          </div>
        </div>
        <label
          >{t("name")}<input
            id="ai-agent-name"
            bind:value={agent.name}
            class="form-control"
            maxlength="100"
            disabled={busy}
          /></label
        >
        <label
          >{t("audience")}
          <select
            id="ai-agent-audience"
            class="form-control not-chosen"
            value={agent.audience ?? "customer"}
            disabled={busy}
            on:change={(event) => audience(event.currentTarget.value)}
          >
            <option value="customer">{t("audience_customer")}</option>
            <option value="internal">{t("audience_internal")}</option>
          </select>
        </label>
        <p class="ai-field-hint">
          {internal ? t("internal_hint") : t("customer_hint")}
        </p>
        {#if !internal}<label
            >{t("profile")}<select
              id="ai-agent-profile"
              bind:value={agent.profile}
              class="form-control not-chosen"
              disabled={busy}
              ><option value="macro-support">macro-support</option><option
                value="macro-sports">macro-sports</option
              ></select
            ></label
          >
          <p id="ai-agent-profile-hint" class="ai-field-hint">
            {agent.profile === "macro-sports"
              ? t("profile_sports_hint")
              : t("profile_support_hint")}
          </p>
        {/if}
        <div class="ai-inline-options">
          {#if !internal}<ReplyLimitField
              id="ai-agent-limit"
              bind:value={agent.limit}
              {busy}
              {t}
            />{/if}
          <label class="ai-switch-row"
            ><span
              ><strong>{t("enabled")}</strong><small>{t("enabled_hint")}</small
              ></span
            ><input
              id="ai-agent-enabled"
              type="checkbox"
              bind:checked={agent.enabled}
              disabled={busy}
            /></label
          >
        </div>
      </div>

      <div class="ai-card">
        <div class="ai-card-heading">
          <span class="ai-card-icon" aria-hidden="true">≡</span>
          <div>
            <h3>{t("context")}</h3>
            <p>{t("inherit")}</p>
          </div>
        </div>
        <div id="ai-agent-docs" class="ai-document-checklist">
          {#each documents as item (item.key)}
            {@const published = item.published}
            <label class="ai-doc-check">
              <input
                type="checkbox"
                value={item.key}
                disabled={busy || !published || published.scope === "global"}
                checked={Boolean(
                  published &&
                    (published.scope === "global" ||
                      agent.documents.includes(item.key)),
                )}
                on:change={(event) =>
                  toggleDocument(item.key, event.currentTarget.checked)}
              />
              <span class="ai-doc-check-copy"
                ><strong>{item.draft.name}</strong><small
                  >{published
                    ? `${t("published")} · v${published.version}`
                    : t("unpublished")}</small
                ></span
              >
              <span class="ai-scope-badge"
                >{published?.scope === "global"
                  ? t("global_short")
                  : t("agent_short")}</span
              >
            </label>
          {/each}
        </div>
        <details class="ai-context-details">
          <summary
            ><span>{t("context_preview")}</span><small
              id="ai-agent-context-size"
              >{contextBody.length.toLocaleString()} {t("characters")}</small
            ></summary
          >
          <pre id="ai-agent-context">{contextBody}</pre>
        </details>
      </div>
    </div>

    <div class="ai-card ai-permissions-card">
      <div class="ai-card-heading">
        <span class="ai-card-icon" aria-hidden="true">⌁</span>
        <div>
          <h3>{t("permissions")}</h3>
          <p>{internal ? t("mcp_identity_hint") : t("permissions_hint")}</p>
        </div>
      </div>
      <div id="ai-agent-permissions">
        {#if internal}
          <label
            >{t("mcp_connection")}<select
              id="ai-agent-mcp-connection"
              class="form-control not-chosen"
              disabled
              ><option value="current_mautic">{t("mcp_current")}</option
              ></select
            ></label
          >
          <p class="ai-field-hint">{t("mcp_identity_hint")}</p>
          <h4>{t("mcp_tools")}</h4>
          <p class="ai-field-hint">{t("mcp_read_only")}</p>
          <div class="ai-document-checklist" id="ai-agent-mcp-tools">
            {#each tools as tool (tool.name)}
              <label class="ai-doc-check"
                ><input
                  type="checkbox"
                  value={tool.name}
                  disabled={busy}
                  checked={agent.mcp_tools?.includes(tool.name) ?? false}
                  on:change={(event) =>
                    toggleTool(tool.name, event.currentTarget.checked)}
                /><span class="ai-doc-check-copy"
                  ><strong>{t(tool.label)}</strong><small>{tool.name}</small
                  ></span
                ></label
              >
            {/each}
          </div>
          <h4>{t("internal_roles")}</h4>
          <p class="ai-field-hint">{t("internal_roles_hint")}</p>
          <div class="ai-document-checklist" id="ai-agent-roles">
            {#each roles as role (role.id)}
              <label class="ai-doc-check"
                ><input
                  type="checkbox"
                  value={role.id}
                  disabled={busy}
                  checked={agent.role_ids?.includes(role.id) ?? false}
                  on:change={(event) =>
                    toggleRole(role.id, event.currentTarget.checked)}
                /><span class="ai-doc-check-copy"
                  ><strong>{role.name}</strong></span
                ></label
              >
            {/each}
          </div>
        {:else}
          <PermissionGrid
            {assets}
            bind:values={agent.permissions}
            {globalPermissions}
            local
            {busy}
            {t}
          />
        {/if}
      </div>
    </div>

    <div class="ai-savebar ai-agent-savebar">
      <p>
        <strong id="ai-save-summary"
          >{selectedDocuments.length}
          {t("documents").toLowerCase()} · {selectedPermissionCount}
          {t("selected").toLowerCase()}</strong
        ><span>{t("save_hint")}</span>
      </p>
      <button
        id="ai-agent-save"
        type="button"
        class="btn btn-primary"
        disabled={busy}
        on:click={() => onSave(agent!)}>{t("save")}</button
      >
    </div>
  {:else if !loading}
    <div class="ai-card ai-empty-agent">
      <p>{t("new")}</p>
      <button
        type="button"
        class="btn btn-primary"
        disabled={busy}
        on:click={onNew}><span aria-hidden="true">＋</span> {t("new")}</button
      >
    </div>
  {/if}
</section>
