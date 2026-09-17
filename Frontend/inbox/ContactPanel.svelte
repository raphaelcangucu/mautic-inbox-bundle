<script lang="ts">
  import Avatar from "./Avatar.svelte";
  import Icon from "../shared/Icon.svelte";
  import type { AiInfo, Conversation, UserOption } from "../shared/types";
  export let selected: Conversation;
  export let users: UserOption[];
  export let ai: AiInfo | null;
  export let aiBusy = false;
  export let panelOpen = true;
  export let snoozeDuration = "60";
  export let t: (key: string) => string;
  export let aiUrl = "";
  export let onTransfer: (value: string) => void;
  export let onAiAssign: (value: string) => void;
  export let onAiReset: () => void;
  export let onSnooze: () => void;
  export let onRelated: (id: number, kind: string) => void;
  const channelLabel = (value: string) =>
    (
      ({
        whatsapp: "WhatsApp",
        instagram: "Instagram",
        facebook: "Facebook",
      }) as Record<string, string>
    )[value] || value;
  const safeSocial = (value?: string) => {
    if (!value) return null;
    try {
      const url = new URL(value);
      return url.protocol === "https:" &&
        [
          "instagram.com",
          "www.instagram.com",
          "facebook.com",
          "www.facebook.com",
        ].includes(url.hostname)
        ? url.href
        : null;
    } catch {
      return null;
    }
  };
  $: assignment = ai?.assignment || null;
  $: allowed = ai?.agents?.filter((agent) => agent.allowed) || [];
  $: lifecycle =
    (
      {
        open: t("mautic.inbox.ui.open_5c072d"),
        snoozed: t("mautic.inbox.ui.snoozed_435da9"),
        resolved: t("mautic.inbox.ui.resolved_0d46ce"),
      } as Record<string, string>
    )[selected.lifecycle] || selected.lifecycle;
</script>

<aside
  id="inbox-contact-panel"
  class="inbox-contact-panel"
  class:closed={!panelOpen}
  class:open={panelOpen}
  aria-label={t("mautic.inbox.ui.contact_details_1ee714")}
>
  <div class="inbox-profile">
    <Avatar
      elementId="inbox-profile-avatar"
      name={selected.contact_name}
      id={selected.id}
      photo={selected.avatar_url || null}
      large
      photoLabel={t("mautic.inbox.ui.photo_of_5bece3")}
    />
    <h3 id="inbox-profile-name">{selected.contact_name}</h3>
    <span id="inbox-profile-handle"
      >{selected.contact_handle ||
        (selected.channel === "whatsapp"
          ? selected.recipient
          : t("mautic.inbox.ui.network_id_f461ad") + selected.recipient)}</span
    >
    <div id="inbox-contact-card">
      {#if selected.contact}<a href={selected.contact.url} class="text-primary"
          >{selected.contact.name ===
          t("mautic.inbox.ui.unnamed_contact_666b99")
            ? t("mautic.inbox.ui.view_contact_in_mautic_199894")
            : selected.contact.name}</a
        >{#if selected.contact.email}<div>
            {selected.contact.email}
          </div>{/if}{#if selected.contact.phone}<div>
            {selected.contact.phone}
          </div>{/if}{:else}{t(
          "mautic.inbox.ui.contact_not_linked_yet_e9a529",
        )}{/if}
    </div>
  </div>
  <section class="inbox-detail-section">
    <h4>{t("mautic.inbox.ui.conversation_b70a33")}</h4>
    <div class="inbox-detail-row">
      <span>{t("mautic.inbox.ui.status_2d3abf")}</span><span
        class="inbox-pill"
        id="inbox-lifecycle-badge">{lifecycle}</span
      >
    </div>
    <label class="inbox-field-label" for="inbox-transfer"
      >{t("mautic.inbox.ui.assignee_b1ce0b")}</label
    ><select
      id="inbox-transfer"
      class="not-chosen form-control"
      aria-label={t("mautic.inbox.ui.transfer_to_fb6a4c")}
      on:change={(event) => {
        const value = event.currentTarget.value;
        if (value) onTransfer(value);
        event.currentTarget.value = "";
      }}
      ><option value=""
        >{t("mautic.inbox.ui.transfer_conversation_0194f9")}</option
      ><option value="unassign"
        >{t("mautic.inbox.ui.return_to_queue_a86b9c")}</option
      >{#each users as user}<option value={user.id}>{user.name}</option
        >{/each}</select
    >
    <div
      id="inbox-assignee-label"
      class="inbox-secondary inbox-assignee-label"
      class:is-ai={Boolean(
        assignment &&
          ["active", "queued", "finishing"].includes(assignment.status),
      )}
    >
      {assignment &&
      ["active", "queued", "finishing"].includes(assignment.status)
        ? `${assignment.name} · AI`
        : selected.assignee?.name || t("mautic.inbox.ui.unassigned_5c8896")}
    </div>
    <div class="inbox-ai-transfer">
      <label class="inbox-field-label" for="inbox-ai-select"
        >{t("mautic.inbox.ai.ai_assign")}</label
      ><select
        id="inbox-ai-select"
        class="form-control not-chosen"
        aria-label={t("mautic.inbox.ai.ai_assign")}
        aria-describedby="inbox-ai-status"
        disabled={aiBusy || !ai?.can_assign || !allowed.length}
        on:change={(event) => {
          const value = event.currentTarget.value;
          if (value) onAiAssign(value);
          event.currentTarget.value = "";
        }}
        ><option value="">{t("mautic.inbox.ai.choose")}</option
        >{#each ai?.agents || [] as agent}<option
            value={agent.key}
            disabled={!agent.allowed}
            title={agent.reason_label || ""}
            >{agent.name}{agent.reason_label
              ? ` — ${agent.reason_label}`
              : ""}</option
          >{/each}</select
      >
      {#if assignment}<div
          id="inbox-ai-agent-card"
          class="inbox-ai-agent-card"
          class:processing={assignment.processing}
          class:paused={assignment.status === "paused"}
          role="status"
        >
          <span class="inbox-ai-avatar" aria-hidden="true">✦</span><span
            class="inbox-ai-agent-copy"
            ><strong id="inbox-ai-agent-name">{assignment.name}</strong><small
              id="inbox-ai-agent-activity">{assignment.status_label}</small
            ></span
          ><span class="inbox-ai-agent-actions"
            ><span
              id="inbox-ai-agent-progress"
              class="inbox-ai-progress"
              title={assignment.limit
                ? `${assignment.count} / ${assignment.limit} · ${t("mautic.inbox.ai.limit")}`
                : `${assignment.count} · ${t("mautic.inbox.ai.unlimited")}`}
              >{assignment.limit
                ? `${assignment.count}/${assignment.limit}`
                : "∞"}</span
            ><button
              type="button"
              id="inbox-ai-reset"
              class="inbox-ai-reset"
              title={t("mautic.inbox.ai.reset")}
              aria-label={t("mautic.inbox.ai.reset")}
              disabled={aiBusy}
              on:click={onAiReset}>↻</button
            ></span
          >
        </div>{/if}
      <div id="inbox-ai-status" class="inbox-secondary" role="status">
        {assignment?.reason_label ||
          (!ai?.can_assign
            ? t("mautic.inbox.ai.read_only")
            : !allowed.length
              ? t("mautic.inbox.ai.none_available")
              : "")}
      </div>
      <a class="inbox-ai-settings-link" href={aiUrl}
        >{t("mautic.inbox.ai.pi")}</a
      >
    </div>
    <div class="inbox-detail-row inbox-detail-row-spaced">
      <span>{t("mautic.inbox.ui.inbox_227cf7")}</span>
    </div>
    <div id="inbox-channel-card" class="inbox-channel-card">
      {selected.asset.name}{selected.asset.handle
        ? ` · @${selected.asset.handle}`
        : selected.asset.phone
          ? ` · ${selected.asset.phone}`
          : ""}
    </div>
  </section>
  <section class="inbox-detail-section">
    <h4>{t("mautic.inbox.ui.next_action_dd848a")}</h4>
    <div class="inbox-snooze-controls">
      <select
        id="inbox-snooze-duration"
        bind:value={snoozeDuration}
        class="not-chosen form-control"
        aria-label={t("mautic.inbox.ui.snooze_duration_38b154")}
        ><option value="60">{t("mautic.inbox.ui.in_1_hour_f47829")}</option
        ><option value="240">{t("mautic.inbox.ui.in_4_hours_9f8243")}</option
        ><option value="tomorrow"
          >{t("mautic.inbox.ui.tomorrow_9_am_b2eccf")}</option
        ></select
      ><button
        id="inbox-snooze"
        class="btn btn-default"
        title={t("mautic.inbox.ui.snooze_conversation_cd8362")}
        on:click={onSnooze}>{t("mautic.inbox.ui.snooze_2d407d")}</button
      >
    </div>
    <div class="inbox-takeover">
      <Icon name="shield" /><span id="inbox-takeover-text"
        >{selected.human_takeover
          ? t("mautic.inbox.ui.automation_paused_for_this_conversation_5e3144")
          : t("mautic.inbox.ui.automation_available_a68946")}</span
      >
    </div>
  </section>
  {#if selected.origins?.length}<section
      id="inbox-origin-section"
      class="inbox-detail-section"
    >
      <h4>{t("mautic.inbox.ui.conversation_source_229634")}</h4>
      <div id="inbox-origin-card">
        {#each selected.origins as origin}{#if origin.image}<img
              src={origin.image}
              alt={t("mautic.inbox.ui.source_post_eed81e")}
              class="inbox-origin-image"
              loading="lazy"
              referrerpolicy="no-referrer"
              on:error={(event) => event.currentTarget.remove()}
            />{/if}<strong
            >{origin.title || t("mautic.inbox.ui.source_post_eed81e")}</strong
          >
          <p>
            {t("mautic.inbox.ui.comment_by_8b43f4")}{origin.author ||
              selected.contact_name}
          </p>
          <blockquote>{origin.body}</blockquote>
          {#if safeSocial(origin.permalink)}<a
              href={safeSocial(origin.permalink) || ""}
              target="_blank"
              rel="noopener noreferrer"
              >{t("mautic.inbox.ui.view_post_on_b9e2d7")}{channelLabel(
                selected.channel,
              )}</a
            >{/if}{#if origin.related_state_id}<button
              class="btn btn-default"
              on:click={() =>
                onRelated(origin.related_state_id!, origin.related_kind || "")}
              >{origin.related_kind === "comments"
                ? t("mautic.inbox.ui.view_original_comment_0d3156")
                : t("mautic.inbox.ui.open_private_conversation_edf027")}</button
            >{/if}{/each}
      </div>
    </section>{/if}
</aside>
