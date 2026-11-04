<script lang="ts">
  import { onMount } from "svelte";
  import Icon from "../shared/Icon.svelte";

  /**
   * A folha de filtros do layout aprovado: fila, situacao, canais e "Mostrar N conversas".
   *
   * Cada escolha vale na hora e a lista atras acompanha, entao o numero do botao e o da lista
   * real, e nao uma estimativa. O botao so fecha. Fila, situacao e canais continuam donos do
   * InboxApp; esta folha desenha e avisa.
   */
  export let queue = "all";
  export let lifecycle = "active";
  export let needsResponse = false;
  export let channels: string[] = [];
  /** Os canais que esta aba oferece: comentario so existe em Instagram e Facebook. */
  export let available: string[] = [];
  export let channelCounts: Record<string, number> = {};
  export let total = 0;
  export let t: (key: string) => string;
  export let onQueue: (value: string) => void;
  export let onChange: () => void;
  export let onClear: () => void;
  export let onClose: () => void;

  const queues = [
    ["all", "mautic.inbox.ui.all_8a3c34"],
    ["mine", "mautic.inbox.ui.mine_1380c1"],
    ["unassigned", "mautic.inbox.ui.unassigned_a121ae"],
  ];
  const lifecycles = [
    ["active", "mautic.inbox.ui.in_progress_5ca373"],
    ["open", "mautic.inbox.ui.open_bcd313"],
    ["snoozed", "mautic.inbox.ui.snoozed_ae3ae2"],
    ["resolved", "mautic.inbox.ui.resolved_6d70e1"],
    ["all", "mautic.inbox.ui.all_8a3c34"],
  ];
  const channelLabel = (value: string) =>
    (
      ({
        whatsapp: "WhatsApp",
        instagram: "Instagram",
        facebook: "Messenger",
        webchat: "Web Chat",
        email: t("mautic.inbox.ui.email_channel"),
      }) as Record<string, string>
    )[value] || value;

  function setLifecycle(value: string): void {
    lifecycle = value;
    onChange();
  }
  function toggleNeedsResponse(): void {
    needsResponse = !needsResponse;
    onChange();
  }
  /** O ultimo canal nao desmarca: uma lista sem canal nenhum nao responde pergunta alguma. */
  function toggleChannel(value: string): void {
    const on = channels.includes(value);
    const marked = channels.filter((item) => available.includes(item));
    if (on && marked.length === 1) return;
    channels = on
      ? channels.filter((item) => item !== value)
      : [...channels, value];
    onChange();
  }

  let sheet: HTMLElement;
  onMount(() => sheet?.focus());
</script>

<svelte:window
  on:keydown={(event) => {
    if (event.key === "Escape") onClose();
  }}
/>

<!-- svelte-ignore a11y-no-static-element-interactions a11y-click-events-have-key-events -->
<div class="inbox-sheet-backdrop" on:click={onClose}></div>
<div
  bind:this={sheet}
  id="inbox-filter-panel"
  class="inbox-sheet inbox-filter-sheet"
  role="dialog"
  aria-modal="true"
  aria-label={t("mautic.inbox.ui.filters_title")}
  tabindex="-1"
>
  <header class="inbox-filter-head">
    <strong>{t("mautic.inbox.ui.filters_title")}</strong>
    <button type="button" class="inbox-filter-clear" on:click={onClear}
      >{t("mautic.inbox.ui.filters_clear")}</button
    >
  </header>
  <div class="inbox-filter-body">
    <section>
      <h4>{t("mautic.inbox.ui.filters_queue")}</h4>
      <div class="inbox-filter-segments" role="group">
        {#each queues as item}<button
            type="button"
            class:active={queue === item[0]}
            aria-pressed={queue === item[0]}
            data-filter-queue={item[0]}
            on:click={() => onQueue(item[0])}>{t(item[1])}</button
          >{/each}
      </div>
    </section>
    <section>
      <h4>{t("mautic.inbox.ui.status_2d3abf")}</h4>
      <div class="inbox-filter-chips" role="group">
        {#each lifecycles as item}<button
            type="button"
            class:active={lifecycle === item[0]}
            aria-pressed={lifecycle === item[0]}
            data-filter-lifecycle={item[0]}
            on:click={() => setLifecycle(item[0])}>{t(item[1])}</button
          >{/each}<button
          type="button"
          id="inbox-needs-response"
          class:active={needsResponse}
          aria-pressed={needsResponse}
          on:click={toggleNeedsResponse}
          >{t("mautic.inbox.ui.awaiting_reply_f6a6ba")}</button
        >
      </div>
    </section>
    <section>
      <h4>{t("mautic.inbox.ui.filters_channels")}</h4>
      <div class="inbox-filter-channels">
        {#each available as item}<label
            class="inbox-filter-channel"
            data-channel={item}
          >
            <span class="inbox-filter-channel-icon {item}"
              ><Icon name={item} /></span
            >
            <span class="inbox-filter-channel-name">{channelLabel(item)}</span>
            <span class="inbox-filter-channel-count"
              >{channelCounts[item] ?? 0}</span
            >
            <input
              type="checkbox"
              checked={channels.includes(item)}
              on:change={(event) => {
                toggleChannel(item);
                // O ultimo canal nao desmarca: o aviso visual e a caixa voltar sozinha.
                event.currentTarget.checked = channels.includes(item);
              }}
            />
          </label>{/each}
      </div>
    </section>
  </div>
  <button
    type="button"
    id="inbox-filter-show"
    class="btn btn-primary inbox-filter-show"
    on:click={onClose}
    >{(total === 1
      ? t("mautic.inbox.ui.filters_show_one")
      : t("mautic.inbox.ui.filters_show")
    ).replace("%count%", String(total))}</button
  >
</div>
