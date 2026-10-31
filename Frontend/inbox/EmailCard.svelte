<script lang="ts">
  import Avatar from "./Avatar.svelte";
  import EmailBody from "./EmailBody.svelte";
  import { renderMessage } from "../shared/markdown";
  import { formatSize, snippet } from "./emailThread";
  import type { EmailAddress, TimelineItem } from "../shared/types";
  export let item: TimelineItem;
  export let expanded = false;
  export let threadSubject = "";
  export let locale: string;
  export let t: (key: string) => string;
  export let onToggle: () => void;
  export let retry: (item: TimelineItem) => void = () => {};
  export let retryBusy = false;
  export let canRetry = false;
  export let onOriginal: (item: TimelineItem) => void = () => {};
  let quotedOpen = false;
  let detailsOpen = false;
  const when = (iso: string) =>
    new Intl.DateTimeFormat(locale, {
      day: "2-digit",
      month: "short",
      hour: "2-digit",
      minute: "2-digit",
    }).format(new Date(iso));
  const person = (value?: EmailAddress | null): string =>
    value ? value.name || value.address : "";
  /** O proxy do anexo e uma URL do proprio Mautic; qualquer outro esquema fica de fora. */
  const safeUrl = (value?: string | null): string | null =>
    value && /^https?:\/\//i.test(value) ? value : null;
  const list = (values?: EmailAddress[]): string =>
    (values || []).map((value) => person(value)).join(", ");
  const status = (value?: string) =>
    (
      ({
        pending: t("mautic.inbox.ui.queued_a3ecf1"),
        queued: t("mautic.inbox.ui.queued_a3ecf1"),
        processing: t("mautic.inbox.ui.sending_569978"),
        waiting: t("mautic.inbox.ui.waiting_to_retry_5ea365"),
        sent: t("mautic.inbox.ui.sent_e1be83"),
        delivered: t("mautic.inbox.ui.delivered_e0ca94"),
        read: t("mautic.inbox.ui.read_5ac9d4"),
        uncertain: t("mautic.inbox.ui.unconfirmed_send_3c9317"),
        failed: t("mautic.inbox.ui.not_sent_3f0e80"),
      }) as Record<string, string>
    )[value || ""] || "";
  $: outbound = item.direction === "outbound" || item.kind === "outbound";
  $: sender =
    person(item.email?.from) || item.author || t("mautic.inbox.ui.email_me");
  $: ownSubject =
    item.subject && item.subject !== threadSubject ? item.subject : "";
  $: html = item.email?.has_html && item.email?.html ? item.email.html : "";
</script>

<article
  class="inbox-email-card"
  class:expanded
  class:outbound
  data-email-card={item.id}
>
  <button
    type="button"
    class="inbox-email-card-head"
    aria-expanded={expanded}
    on:click={onToggle}
  >
    <Avatar name={sender} id={item.id} />
    <span class="inbox-email-sender">
      <strong>{sender}</strong>
      {#if expanded && item.email?.from?.address && item.email.from.name}<small
          >&lt;{item.email.from.address}&gt;</small
        >{/if}
      {#if !expanded}<span class="inbox-email-snippet"
          >{snippet(item.body)}</span
        >{/if}
    </span>
    <time datetime={item.timestamp}>{when(item.timestamp)}</time>
  </button>
  {#if expanded}
    <div class="inbox-email-card-body">
      {#if item.email}<div class="inbox-email-recipients">
          {t("mautic.inbox.ui.email_to")}: {list(item.email.to)}
          {#if item.email.cc?.length || item.email.to?.length}<button
              type="button"
              class="btn btn-link btn-sm inbox-email-details-toggle"
              aria-expanded={detailsOpen}
              on:click={() => (detailsOpen = !detailsOpen)}
              >{t("mautic.inbox.ui.email_show_details")}</button
            >{/if}
          {#if detailsOpen}<dl class="inbox-email-details">
              <dt>{t("mautic.inbox.ui.email_from")}</dt>
              <dd>
                {person(item.email.from)}
                {item.email.from?.address ? `<${item.email.from.address}>` : ""}
              </dd>
              <dt>{t("mautic.inbox.ui.email_to")}</dt>
              <dd>
                {(item.email.to || []).map((value) => value.address).join(", ")}
              </dd>
              {#if item.email.cc?.length}<dt>
                  {t("mautic.inbox.ui.email_cc")}
                </dt>
                <dd>
                  {item.email.cc.map((value) => value.address).join(", ")}
                </dd>{/if}
              {#if item.subject}<dt>{t("mautic.inbox.ui.email_subject")}</dt>
                <dd>{item.subject}</dd>{/if}
            </dl>{/if}
        </div>{/if}
      {#if ownSubject}<div class="inbox-email-own-subject">
          {ownSubject}
        </div>{/if}
      {#if item.email?.unverified_sender}<div
          class="inbox-email-warning"
          role="note"
        >
          {t("mautic.inbox.ui.email_unverified")}
        </div>{/if}
      {#if item.content_label}<div class="inbox-content-label">
          {item.content_label}
        </div>{/if}
      {#if html}<EmailBody
          {html}
          {t}
          title={item.subject || sender}
        />{:else}<div
          class="inbox-email-text"
          use:renderMessage={{
            value: item.body || "",
            whatsapp: false,
            unsupported: t("mautic.inbox.ui.unsupported_link_9a5896"),
          }}
        ></div>{/if}
      {#if item.email?.quoted}<button
          type="button"
          class="inbox-email-quoted-toggle"
          aria-expanded={quotedOpen}
          title={quotedOpen
            ? t("mautic.inbox.ui.email_hide_quoted")
            : t("mautic.inbox.ui.email_show_quoted")}
          aria-label={quotedOpen
            ? t("mautic.inbox.ui.email_hide_quoted")
            : t("mautic.inbox.ui.email_show_quoted")}
          on:click={() => (quotedOpen = !quotedOpen)}>•••</button
        >
        {#if quotedOpen}<div
            class="inbox-email-quoted"
            use:renderMessage={{
              value: item.email.quoted,
              whatsapp: false,
              unsupported: t("mautic.inbox.ui.unsupported_link_9a5896"),
            }}
          ></div>{/if}{/if}
      {#if item.attachments?.length}<ul class="inbox-email-attachments">
          {#each item.attachments as attachment}<li
              class:unavailable={!attachment.available ||
                !safeUrl(attachment.url)}
            >
              {#if attachment.available && safeUrl(attachment.url)}{#if attachment.type === "image"}<a
                    href={attachment.url}
                    target="_blank"
                    rel="noopener noreferrer"
                    ><img
                      src={attachment.url}
                      alt={attachment.label}
                      loading="lazy"
                      referrerpolicy="no-referrer"
                    /></a
                  >{/if}<a
                  href={attachment.url}
                  target="_blank"
                  rel="noopener noreferrer">{attachment.label}</a
                >{:else}<span>{attachment.label}</span>
                <small
                  >{t("mautic.inbox.ui.email_attachment_unavailable")}</small
                >{/if}
              {#if attachment.size}<small>{formatSize(attachment.size)}</small
                >{/if}
            </li>{/each}
        </ul>{/if}
      <div class="inbox-email-card-foot">
        {#if outbound && item.status}<small
            class:inbox-status-failed={item.status === "failed"}
            >{status(item.status)}</small
          >{/if}
        {#if item.failure}<span class="inbox-status-failed">{item.failure}</span
          >{/if}
        {#if item.retryable}<button
            type="button"
            class="btn btn-default btn-sm inbox-retry"
            disabled={retryBusy || !canRetry}
            on:click={() => retry(item)}
            >{retryBusy
              ? t("mautic.inbox.ui.sending_again")
              : t("mautic.inbox.ui.send_again")}</button
          >{/if}
        {#if item.email?.has_original}<button
            type="button"
            class="btn btn-link btn-sm inbox-email-original"
            on:click={() => onOriginal(item)}
            >{t("mautic.inbox.ui.email_view_original")}</button
          >{/if}
      </div>
    </div>
  {/if}
</article>
