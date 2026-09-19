<script lang="ts">
  import Icon from "../shared/Icon.svelte";
  import Avatar from "./Avatar.svelte";
  import type { Conversation } from "../shared/types";
  export let rows: Conversation[] = [];
  export let selected: Conversation | null = null;
  export let queue = "all";
  export let counts: Record<string, number> = {
    mine: 0,
    unassigned: 0,
    all: 0,
  };
  export let total = 0;
  export let loading = false;
  export let error = "";
  export let next: string | null = null;
  export let filtersOpen = false;
  export let lifecycle = "active";
  export let channel = "";
  export let needsResponse = false;
  export let search = "";
  export let view = "inbox";
  export let locale: string;
  export let t: (key: string) => string;
  export let onSelect: (id: number) => void;
  export let onQueue: (q: string) => void;
  export let onFilters: () => void;
  export let onFilter: () => void;
  export let onSearch: () => void;
  export let onMore: () => void;
  const shortTime = (iso: string) => {
    const date = new Date(iso),
      now = new Date();
    return date.toDateString() === now.toDateString()
      ? new Intl.DateTimeFormat(locale, {
          hour: "2-digit",
          minute: "2-digit",
        }).format(date)
      : new Intl.DateTimeFormat(locale, {
          day: "2-digit",
          month: "short",
        }).format(date);
  };
  // O canal por QR nao e homologado: nao tem janela de 24h nem modelos, e cai e volta
  // sozinho. Ele chega no mesmo `channel` do numero oficial, entao so o tipo do asset
  // separa os dois — e quem trabalha na lista o dia inteiro precisa ver de qual deles a
  // conversa veio antes de abrir.
  const QR_SESSION = "whatsapp_qr_session";
  const channelLabel = (value: string, assetType?: string) =>
    assetType === QR_SESSION
      ? "WhatsApp · QR"
      : (
          {
            whatsapp: "WhatsApp",
            instagram: "Instagram",
            facebook: "Facebook",
          } as Record<string, string>
        )[value] || value;
</script>

<section
  class="inbox-list-column"
  aria-label={t("mautic.inbox.ui.conversation_list")}
>
  <div class="inbox-list-heading">
    <h2 id="inbox-list-heading">
      {view === "comments"
        ? t("mautic.inbox.ui.comments_6fe305")
        : t("mautic.inbox.ui.conversations_86d0e6")}
    </h2>
    <span id="inbox-list-total" class="inbox-counter">{total}</span><button
      id="inbox-filter-toggle"
      class="inbox-icon-button"
      title={t("mautic.inbox.ui.filter_conversations_9d8aa3")}
      aria-label={t("mautic.inbox.ui.filter_conversations_9d8aa3")}
      aria-expanded={filtersOpen}
      on:click={onFilters}><Icon name="filter" /></button
    >
  </div>
  <div class="inbox-search-wrap">
    <Icon name="search" /><input
      id="inbox-search"
      type="search"
      bind:value={search}
      placeholder={t("mautic.inbox.ui.search_conversations_519789")}
      aria-label={t("mautic.inbox.ui.search_conversations_519789")}
      on:input={onSearch}
    />
  </div>
  <nav class="inbox-queues" aria-label={t("mautic.inbox.ui.queues_8e5d55")}>
    {#each [["mine", "mautic.inbox.ui.mine_1380c1"], ["unassigned", "mautic.inbox.ui.unassigned_a121ae"], ["all", "mautic.inbox.ui.all_8a3c34"]] as item}<button
        class:active={queue === item[0]}
        on:click={() => onQueue(item[0])}
        ><span>{t(item[1])}</span><b>{counts[item[0]] || 0}</b></button
      >{/each}
  </nav>
  {#if filtersOpen}<div id="inbox-filter-panel" class="inbox-filters">
      <label
        >{t("mautic.inbox.ui.status_2d3abf")}<select
          id="inbox-lifecycle"
          bind:value={lifecycle}
          class="not-chosen form-control"
          on:change={onFilter}
          ><option value="active"
            >{t("mautic.inbox.ui.in_progress_5ca373")}</option
          ><option value="open">{t("mautic.inbox.ui.open_bcd313")}</option
          ><option value="snoozed">{t("mautic.inbox.ui.snoozed_ae3ae2")}</option
          ><option value="resolved"
            >{t("mautic.inbox.ui.resolved_6d70e1")}</option
          ><option value="all">{t("mautic.inbox.ui.all_8a3c34")}</option
          ></select
        ></label
      >
      <label
        >{t("mautic.inbox.ui.channel_61f21e")}<select
          id="inbox-channel"
          bind:value={channel}
          class="not-chosen form-control"
          on:change={onFilter}
          ><option value="">{t("mautic.inbox.ui.all_channels_44265e")}</option
          ><option value="whatsapp">WhatsApp</option><option value="instagram"
            >Instagram</option
          ><option value="facebook">Facebook / Messenger</option></select
        ></label
      >
      <label class="inbox-check"
        ><input
          id="inbox-needs-response"
          bind:checked={needsResponse}
          type="checkbox"
          on:change={onFilter}
        />
        {t("mautic.inbox.ui.awaiting_reply_f6a6ba")}</label
      >
    </div>{/if}
  <div class="inbox-list-scroll">
    {#if loading}<div id="inbox-list-status" class="inbox-state">
        {t("mautic.inbox.ui.loading_conversations_cb1a50")}
      </div>{:else if error}<div id="inbox-list-status" class="inbox-error">
        {error}
      </div>{:else if !rows.length}<div
        id="inbox-list-status"
        class="inbox-state"
      >
        {t("mautic.inbox.ui.no_conversations_found_12522a")}
      </div>{/if}
    <div id="inbox-list" class="inbox-list">
      {#each rows as item (item.id)}<button
          type="button"
          class="inbox-list-item"
          class:active={selected?.id === item.id}
          on:click={() => onSelect(item.id)}
        >
          <Avatar
            name={item.contact_name}
            id={item.id}
            channel={item.channel}
            photo={item.avatar_url || null}
            photoLabel={t("mautic.inbox.ui.photo_of_5bece3")}
          />
          <div class="inbox-list-copy">
            <div class="inbox-list-title">
              <span>{item.contact_name}</span><span class="inbox-list-time"
                >{shortTime(item.last_message_at)}</span
              >
            </div>
            <div class="inbox-list-meta">
              {channelLabel(item.channel, item.asset.type)} · {item.asset.handle
                ? `@${item.asset.handle}`
                : item.asset.phone || item.asset.name}
            </div>
            <div class="inbox-list-preview">
              {item.preview || item.last_message_preview || item.asset.name}
            </div>
            <div class="inbox-list-flags">
              {#if item.needs_response}<span class="inbox-pill response"
                  >{t("mautic.inbox.ui.awaiting_reply_f6a6ba")}</span
                >{/if}{#if item.unread}<span class="inbox-pill unread"
                  >{item.unread}</span
                >{/if}{#if item.lifecycle === "snoozed"}<span class="inbox-pill"
                  >{t("mautic.inbox.ui.snoozed_435da9")}</span
                >{/if}
            </div>
          </div>
        </button>{/each}
    </div>
    {#if next}<button
        id="inbox-more"
        class="btn btn-default btn-sm"
        disabled={loading}
        on:click={onMore}
        >{loading
          ? t("mautic.inbox.ui.loading_more_cfca50")
          : t("mautic.inbox.ui.load_more_eb48bb")}</button
      >{/if}
  </div>
</section>
