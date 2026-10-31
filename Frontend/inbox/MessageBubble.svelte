<script lang="ts">
  import type { Conversation, TimelineItem } from "../shared/types";
  import MediaAttachment from "./MediaAttachment.svelte";
  import { renderMessage } from "../shared/markdown";
  export let item: TimelineItem;
  export let selected: Conversation;
  export let locale: string;
  export let t: (key: string) => string;
  export let retry: (item: TimelineItem) => void;
  export let retryBusy = false;
  export let favorite = false;
  export let onFavorite: (item: TimelineItem) => void = () => {};
  export let onCopy: (item: TimelineItem) => void = () => {};
  const time = (iso: string) =>
    new Intl.DateTimeFormat(locale, {
      day: "2-digit",
      month: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
    }).format(new Date(iso));
  const outbound = (status?: string) =>
    (
      ({
        pending: t("mautic.inbox.ui.queued_a3ecf1"),
        queued: t("mautic.inbox.ui.queued_a3ecf1"),
        processing: t("mautic.inbox.ui.sending_569978"),
        waiting: t("mautic.inbox.ui.waiting_to_retry_5ea365"),
        accepted: t("mautic.inbox.ui.accepted_by_meta_212288"),
        sent: t("mautic.inbox.ui.sent_e1be83"),
        delivered: t("mautic.inbox.ui.delivered_e0ca94"),
        read: t("mautic.inbox.ui.read_5ac9d4"),
        uncertain: t("mautic.inbox.ui.unconfirmed_send_3c9317"),
        failed: t("mautic.inbox.ui.not_sent_3f0e80"),
      }) as Record<string, string>
    )[status || ""] ||
    status ||
    t("mautic.inbox.ui.unconfirmed_90d72f");
  /** Os eventos do formulario e da campanha dizem qual; o resto continua so com o tipo. */
  const named = (entry: TimelineItem): string | null =>
    entry.event === "form_opened"
      ? t("mautic.inbox.ui.form_opened").replace(
          "%form%",
          entry.form?.name || "",
        )
      : entry.event === "form_submitted"
        ? t("mautic.inbox.ui.form_submitted").replace(
            "%form%",
            entry.form?.name || "",
          )
        : entry.event === "campaign_email_started"
          ? t("mautic.inbox.ui.campaign_email_started").replace(
              "%campaign%",
              entry.campaign?.name || "",
            )
          : entry.event === "email_unsubscribed"
            ? t("mautic.inbox.ui.email_unsubscribed")
            : null;
  const event = (name?: string) =>
    (
      ({
        created: t("mautic.inbox.ui.conversation_created_fa0fd7"),
        reopened: t(
          "mautic.inbox.ui.conversation_reopened_by_a_new_message_306e4e",
        ),
        taken: t("mautic.inbox.ui.conversation_assigned_e9e32d"),
        transfer: t("mautic.inbox.ui.conversation_transferred_f5ab14"),
        resolve: t("mautic.inbox.ui.conversation_resolved_973a89"),
        reopen: t("mautic.inbox.ui.conversation_reopened_5108d0"),
        snooze: t("mautic.inbox.ui.conversation_snoozed_2489e7"),
        unassign: t(
          "mautic.inbox.ui.conversation_returned_to_the_queue_dee084",
        ),
        woken: t("mautic.inbox.ui.snooze_period_ended_360c38"),
        reply_queued: t("mautic.inbox.ui.reply_queued_8a4e0a"),
        reply_requested: t("mautic.inbox.ui.reply_requested_immediate"),
        reply_retried: t("mautic.inbox.ui.sent_again"),
      }) as Record<string, string>
    )[name || ""] || t("mautic.inbox.ui.conversation_updated_f74900");
  $: isOutbound = item.direction === "outbound" || item.kind === "outbound";
  $: label =
    item.kind === "note"
      ? t("mautic.inbox.ui.internal_note_010aa1")
      : item.kind === "comment"
        ? t("mautic.inbox.ui.public_comment_4a1398")
        : isOutbound
          ? outbound(item.status)
          : t("mautic.inbox.ui.message_received_0d110d");
  $: author = item.ai ? `${item.ai.agent || "AI"} · AI` : item.author;
</script>

<article
  class="inbox-message {item.kind}"
  class:outbound={isOutbound}
  class:ai-authored={Boolean(item.ai)}
>
  {#if item.kind === "event"}{named(item) ?? event(item.event)} · {time(
      item.timestamp,
    )}
    {#if item.answers?.length}<dl class="inbox-form-answers">
        {#each item.answers as answer}<dt>{answer.label}</dt>
          <dd>{answer.value}</dd>{/each}
      </dl>{/if}
  {:else}
    {#if item.ai}<div class="inbox-ai-message-badge">
        ✦ {item.ai.agent || "AI"} · AI
      </div>{/if}
    {#if item.content_label}<div class="inbox-content-label">
        {item.content_label}
      </div>{/if}
    <div
      use:renderMessage={{
        value: item.body || "",
        whatsapp: selected.channel === "whatsapp" && item.kind !== "note",
        unsupported: t("mautic.inbox.ui.unsupported_link_9a5896"),
      }}
    ></div>
    {#each item.attachments || [] as attachment}<MediaAttachment
        {attachment}
        {t}
      />{/each}
    <small class:inbox-status-failed={item.status === "failed"}
      >{item.kind === "automatic" && !item.ai
        ? t("mautic.inbox.ui.channel_message_f3ffdf")
        : ""}{label}{author ? ` · ${author}` : ""} · {time(
        item.timestamp,
      )}</small
    >
    <!-- As duas acoes que o aplicativo tem por mensagem e o web nao tinha. Favoritar e do
         navegador, como la e do aparelho: nao existe campo disso no servidor. -->
    <div class="inbox-message-actions">
      <button
        type="button"
        class:marked={favorite}
        aria-pressed={favorite}
        aria-label={t("mautic.inbox.ui.message_favorite")}
        title={t("mautic.inbox.ui.message_favorite")}
        on:click={() => onFavorite(item)}
      >
        <svg
          viewBox="0 0 24 24"
          aria-hidden="true"
          style="fill:{favorite ? 'currentColor' : 'none'}"
          ><path
            d="m12 3.6 2.5 5.2 5.7.8-4.1 4 1 5.7-5.1-2.7-5.1 2.7 1-5.7-4.1-4 5.7-.8z"
          ></path></svg
        >
      </button>
      <button
        type="button"
        aria-label={t("mautic.inbox.ui.message_copy")}
        title={t("mautic.inbox.ui.message_copy")}
        on:click={() => onCopy(item)}
      >
        <svg viewBox="0 0 24 24" aria-hidden="true"
          ><rect x="9" y="9" width="11" height="11" rx="2"></rect><path
            d="M5 15V5a2 2 0 0 1 2-2h8"
          ></path></svg
        >
      </button>
    </div>
    {#if item.kind === "comment" && item.context}<div class="inbox-list-meta">
        {t("mautic.inbox.ui.post_linked_to_the_original_comment_405f5e")}
      </div>{/if}
    {#if (item.attempt_count ?? 1) > 1}<small class="inbox-status"
        >{t("mautic.inbox.ui.delivery_attempts").replace(
          "%count%",
          String(item.attempt_count),
        )}</small
      >{/if}
    {#if item.failure}<div class="inbox-status-failed">{item.failure}</div>{/if}
    {#if item.retryable}<button
        type="button"
        class="btn btn-default btn-sm inbox-retry"
        disabled={retryBusy ||
          selected.lifecycle === "resolved" ||
          (!selected.can_reply && !selected.can_take_and_reply)}
        on:click={() => retry(item)}
        >{retryBusy
          ? t("mautic.inbox.ui.sending_again")
          : t("mautic.inbox.ui.send_again")}</button
      >{/if}
  {/if}
</article>
