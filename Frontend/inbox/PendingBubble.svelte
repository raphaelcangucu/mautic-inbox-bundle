<script lang="ts">
  import Icon from "../shared/Icon.svelte";
  import type { PendingMessage } from "../shared/store/types";
  export let message: PendingMessage;
  export let locale: string;
  export let t: (key: string) => string;
  export let retry: (message: PendingMessage) => void = () => {};
  const time = (iso: string) =>
    new Intl.DateTimeFormat(locale, {
      day: "2-digit",
      month: "2-digit",
      hour: "2-digit",
      minute: "2-digit",
    }).format(new Date(iso));
  $: note = message.mode === "note";
  $: failed = message.state === "failed";
  /**
   * Rede e recusa do canal pedem reacoes opostas do atendente, e o unico campo que as separa e
   * o retryable. Sem essa frase ele reenvia para sempre algo que o canal jamais vai aceitar —
   * e um botao sozinho convida exatamente a isso.
   */
  $: retryable = failed && message.retryable !== false;
  $: reason = retryable
    ? t("mautic.inbox.ui.pending_network_failure")
    : t("mautic.inbox.ui.pending_channel_refused");
</script>

<article
  class="inbox-pending"
  class:note
  class:sending={!failed}
  class:failed
  data-pending={message.localId}
>
  <!-- Texto puro, sem o renderMessage do historico: dar a pendente o mesmo acabamento da
       mensagem confirmada e o primeiro passo para ela conseguir se passar por uma. -->
  <p class="inbox-pending-body">{message.body}</p>
  <small class="inbox-pending-status" class:inbox-status-failed={failed}>
    {#if !failed}<Icon name="clock" />{/if}{note
      ? `${t("mautic.inbox.ui.internal_note_010aa1")} · `
      : ""}{failed
      ? t("mautic.inbox.ui.not_sent_3f0e80")
      : t("mautic.inbox.ui.sending_5e91dc")} · {time(message.createdAt)}
  </small>
  {#if failed}
    <div class="inbox-pending-reason">
      {reason}{message.failure ? ` · ${message.failure}` : ""}
    </div>
    <button
      type="button"
      class="btn btn-default btn-sm inbox-retry inbox-pending-retry"
      disabled={!retryable}
      on:click={() => retry(message)}>{t("mautic.inbox.ui.send_again")}</button
    >
  {/if}
</article>
