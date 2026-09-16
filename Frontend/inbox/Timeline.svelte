<script lang="ts">
  import { afterUpdate, tick } from "svelte";
  import Icon from "../shared/Icon.svelte";
  import MessageBubble from "./MessageBubble.svelte";
  import type {
    AiPendingReply,
    Conversation,
    TimelineItem,
  } from "../shared/types";
  import { renderMessage } from "../shared/markdown";
  export let items: TimelineItem[] = [];
  export let selected: Conversation;
  export let older: string | null = null;
  export let locale: string;
  export let t: (key: string) => string;
  export let onOlder: () => Promise<void>;
  export let retry: (item: TimelineItem) => void;
  export let retryBusy: Set<number>;
  export let pending: AiPendingReply | null = null;
  export let aiCanAssign = false;
  export let aiRetryBusy = false;
  export let onAiSend: () => void;
  export let onAiRegenerate: () => void;
  let scroller: HTMLElement;
  let lastOwner = 0;
  let previousCount = 0;
  let preserving = false;
  const day = (iso: string) =>
    new Intl.DateTimeFormat(locale, { day: "numeric", month: "long" }).format(
      new Date(iso),
    );
  $: grouped = items.map((item, index) => ({
    item,
    showDay:
      index === 0 || day(items[index - 1].timestamp) !== day(item.timestamp),
    day: day(item.timestamp),
  }));
  afterUpdate(() => {
    if (!scroller || preserving) return;
    if (lastOwner !== selected.id) {
      scroller.scrollTop = scroller.scrollHeight;
      lastOwner = selected.id;
    } else if (
      items.length >= previousCount &&
      scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 180
    )
      scroller.scrollTop = scroller.scrollHeight;
    previousCount = items.length;
  });
  async function loadOlder(): Promise<void> {
    const oldTop = scroller.scrollTop,
      oldHeight = scroller.scrollHeight;
    preserving = true;
    try {
      await onOlder();
      await tick();
      scroller.scrollTop = oldTop + scroller.scrollHeight - oldHeight;
      previousCount = items.length;
    } finally {
      preserving = false;
    }
  }
</script>

<section
  bind:this={scroller}
  class="inbox-messages-wrap"
  aria-label={t("mautic.inbox.ui.conversation_history_87eaf7")}
>
  <div class="inbox-history-heading">
    <Icon name="lock" />
    {t("mautic.inbox.ui.conversation_history_87eaf7")}
  </div>
  {#if older}<button
      id="inbox-older"
      class="btn btn-link btn-sm"
      on:click={loadOlder}
      >{t("mautic.inbox.ui.load_earlier_messages_3d0b6c")}</button
    >{/if}
  <div id="inbox-timeline" class="inbox-timeline" aria-live="polite">
    {#each grouped as row (row.item.kind + ":" + row.item.id)}{#if row.showDay}<div
          class="inbox-date-separator"
        >
          {row.day}
        </div>{/if}<MessageBubble
        item={row.item}
        {selected}
        {locale}
        {t}
        {retry}
        retryBusy={retryBusy.has(row.item.id)}
      />{/each}
  </div>
  {#if pending}<article
      id="inbox-ai-pending"
      class="inbox-ai-pending {[
        'queued',
        'retrying',
        'processing',
        'blocked',
        'failed',
        'uncertain',
      ].includes(pending.status)
        ? pending.status
        : 'queued'}"
      aria-live="polite"
    >
      <div class="inbox-ai-pending-heading">
        <span class="inbox-ai-avatar" aria-hidden="true">✦</span><span
          ><strong id="inbox-ai-pending-title"
            >{t("mautic.inbox.ai.pending_title")}</strong
          ><small id="inbox-ai-pending-agent">{pending.agent || ""}</small
          ></span
        ><span id="inbox-ai-pending-status" class="inbox-pill"
          >{pending.status_label || pending.status}</span
        >
      </div>
      <div
        id="inbox-ai-pending-body"
        class="inbox-ai-pending-body"
        use:renderMessage={{
          value: pending.text,
          whatsapp: selected.channel === "whatsapp",
        }}
      ></div>
      {#if pending.reason_label || pending.error}<div
          id="inbox-ai-pending-reason"
          class="inbox-ai-pending-reason"
        >
          {pending.reason_label || ""}{pending.error &&
          pending.reason !== "new_message_received"
            ? `${pending.reason_label ? " · " : ""}${pending.error}`
            : ""}
        </div>{/if}
      <div class="inbox-ai-pending-actions">
        <button
          id="inbox-ai-send-now"
          class="btn btn-primary btn-sm"
          disabled={aiRetryBusy || !aiCanAssign || !pending.retryable}
          on:click={onAiSend}
          >{aiRetryBusy
            ? t("mautic.inbox.ai.sending_now")
            : t("mautic.inbox.ai.send_now")}</button
        ><button
          id="inbox-ai-regenerate"
          class="btn btn-default btn-sm"
          disabled={aiRetryBusy || !aiCanAssign}
          on:click={onAiRegenerate}>{t("mautic.inbox.ai.regenerate")}</button
        >
      </div>
    </article>{/if}
</section>
