<script lang="ts">
  import { afterUpdate, onMount, tick } from "svelte";
  import { collapseRetries } from "./retry-timeline";
  import { decidirRolagem, rolagemInicial } from "./autoscroll";
  import { emailRows, snippet, threadSubject } from "./emailThread";
  import EmailCard from "./EmailCard.svelte";
  import MessageBubble from "./MessageBubble.svelte";
  import PendingBubble from "./PendingBubble.svelte";
  import type { Conversation, TimelineItem } from "../shared/types";
  import type { PendingMessage } from "../shared/store/types";
  /**
   * A conversa de e-mail como cliente de e-mail: assunto no topo, cartoes empilhados, as
   * notas e os eventos do Inbox entre eles como linha centralizada. Os outros canais seguem no
   * Timeline de bolhas.
   */
  export let items: TimelineItem[] = [];
  export let selected: Conversation;
  export let older: string | null = null;
  export let locale: string;
  export let t: (key: string) => string;
  export let onOlder: () => Promise<void>;
  export let retry: (item: TimelineItem) => void;
  export let retryBusy: Set<number> = new Set();
  export let pendingMessages: PendingMessage[] = [];
  export let onRetryPending: (message: PendingMessage) => void = () => {};
  export let onEmail: (email: string) => void = () => {};
  export let onOriginal: (item: TimelineItem) => void = () => {};
  let scroller: HTMLElement;
  let toggled = new Set<string>();
  let owner = selected.id;
  let rolagem = rolagemInicial();
  let preserving = false;
  $: if (selected.id !== owner) {
    owner = selected.id;
    toggled = new Set();
  }
  $: visibleItems = collapseRetries(items);
  $: subject = threadSubject(selected, visibleItems);
  $: rows = emailRows(visibleItems, Number(selected.unread || 0), toggled);
  $: canRetry =
    selected.lifecycle !== "resolved" &&
    Boolean(selected.can_reply || selected.can_take_and_reply);
  $: delayedSince = selected.email?.inbound_since
    ? new Intl.DateTimeFormat(locale, {
        day: "2-digit",
        month: "short",
        hour: "2-digit",
        minute: "2-digit",
      }).format(new Date(selected.email.inbound_since))
    : "";
  const when = (iso: string) =>
    new Intl.DateTimeFormat(locale, {
      day: "2-digit",
      month: "short",
      hour: "2-digit",
      minute: "2-digit",
    }).format(new Date(iso));
  function toggle(key: string): void {
    const next = new Set(toggled);
    if (next.has(key)) next.delete(key);
    else next.add(key);
    toggled = next;
  }
  onMount(() => {
    // Mesmo motivo do Timeline: o chip de e-mail do CRM nasce em DOM puro, no markdown.
    const ouvir = (event: Event): void => {
      const alvo = (event.target as HTMLElement | null)?.closest?.(
        "[data-email]",
      ) as HTMLElement | null;
      if (alvo?.dataset.email) onEmail(alvo.dataset.email);
    };
    scroller.addEventListener("click", ouvir);
    return () => scroller.removeEventListener("click", ouvir);
  });
  afterUpdate(() => {
    if (!scroller || preserving) return;
    const decisao = decidirRolagem(rolagem, {
      conversationId: selected.id,
      visiveis: visibleItems.length + pendingMessages.length,
      distanciaDoFim:
        scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight,
    });
    rolagem = decisao.estado;
    if (decisao.aoFim) scroller.scrollTop = scroller.scrollHeight;
  });
  async function loadOlder(): Promise<void> {
    const oldTop = scroller.scrollTop,
      oldHeight = scroller.scrollHeight;
    preserving = true;
    try {
      await onOlder();
      await tick();
      scroller.scrollTop = oldTop + scroller.scrollHeight - oldHeight;
      rolagem = {
        ...rolagem,
        vistos: visibleItems.length + pendingMessages.length,
      };
    } finally {
      preserving = false;
    }
  }
</script>

<section
  bind:this={scroller}
  class="inbox-messages-wrap inbox-email-thread"
  aria-label={t("mautic.inbox.ui.conversation_history_87eaf7")}
>
  <header class="inbox-email-thread-header">
    <h2 id="inbox-email-subject">
      {subject || t("mautic.inbox.ui.email_no_subject")}
    </h2>
    <span id="inbox-email-account" class="inbox-email-account"
      >{selected.asset.name}{selected.asset.address
        ? ` · ${selected.asset.address}`
        : ""}</span
    >
  </header>
  {#if selected.email?.inbound === "delayed"}<div
      id="inbox-email-delayed"
      class="inbox-email-delayed"
      role="status"
    >
      {t("mautic.inbox.ui.email_inbound_delayed")}
      {#if delayedSince}{t(
          "mautic.inbox.ui.email_inbound_delayed_since",
        ).replace("%since%", delayedSince)}{/if}
    </div>{/if}
  {#if older}<button
      id="inbox-older"
      class="btn btn-link btn-sm"
      on:click={loadOlder}
      >{t("mautic.inbox.ui.load_earlier_messages_3d0b6c")}</button
    >{/if}
  <div id="inbox-timeline" class="inbox-email-cards" aria-live="polite">
    {#each rows as row (row.key)}
      {#if row.type === "group"}<button
          type="button"
          class="inbox-email-group"
          on:click={() => toggle(row.key)}
          ><span
            >{t("mautic.inbox.ui.email_earlier").replace(
              "%count%",
              String(row.items.length),
            )}</span
          ></button
        >
      {:else if row.type === "line"}
        {#if row.item.kind === "note" || row.item.kind === "event"}<MessageBubble
            item={row.item}
            {selected}
            {locale}
            {t}
            {retry}
            retryBusy={retryBusy.has(row.item.id)}
          />{:else}<div class="inbox-email-line" data-email-line={row.item.id}>
            {row.item.content_label || t("mautic.inbox.ui.email_auto_reply")} · {row
              .item.email?.from?.name ||
              row.item.email?.from?.address ||
              ""} · {snippet(row.item.body, 80)} · {when(row.item.timestamp)}
          </div>{/if}
      {:else}<EmailCard
          item={row.item}
          expanded={row.expanded}
          threadSubject={subject}
          {locale}
          {t}
          onToggle={() => toggle(row.key)}
          {retry}
          retryBusy={retryBusy.has(row.item.id)}
          {canRetry}
          {onOriginal}
        />{/if}
    {/each}
    {#each pendingMessages as message (message.localId)}<PendingBubble
        {message}
        {locale}
        {t}
        retry={onRetryPending}
      />{/each}
  </div>
</section>
