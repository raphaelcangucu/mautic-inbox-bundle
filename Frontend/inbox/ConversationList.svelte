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
  export let searchOpen = false;
  export let view = "inbox";
  export let locale: string;
  export let t: (key: string) => string;
  export let onSelect: (id: number) => void;
  export let onQueue: (q: string) => void;
  export let onFilters: () => void;
  export let onFilter: () => void;
  export let onSearch: () => void;
  export let onMore: () => void;
  export let currentUser = 0;
  export let busy: Set<number> = new Set();
  export let onAction: (item: Conversation, action: string) => void = () => {};
  export let selection: Set<number> = new Set();
  export let bulkBusy = false;
  export let onToggleSelect: (id: number) => void = () => {};
  export let onBulk: (action: string) => void = () => {};
  export let onClearSelection: () => void = () => {};
  /** Quem abre a busca pelo icone quer digitar: o campo ja chega com o cursor. */
  let searchInput: HTMLInputElement | undefined;
  let searchWasOpen = false;
  $: {
    if (searchOpen && !searchWasOpen)
      queueMicrotask(() => searchInput?.focus());
    searchWasOpen = searchOpen;
  }
  /** Quantos filtros fogem do padrao, para o chip de filtro mostrar o numero como no app. */
  $: activeFilters =
    Number(lifecycle !== "active") +
    Number(channel !== "") +
    Number(needsResponse);

  /**
   * No celular a caixa de selecao so aparece depois que a selecao comeca, e ela comeca pelo
   * toque longo — o gesto de lista do iOS e do Android. Um toque curto continua abrindo a
   * conversa. O toque longo que vira selecao engole o clique que viria em seguida, senao a
   * mesma conversa seria marcada e aberta de uma vez.
   */
  let pressTimer = 0;
  let pressSelected = false;
  const touchList = () => matchMedia("(max-width:760px)").matches;
  function pressStart(id: number): void {
    pressSelected = false;
    clearTimeout(pressTimer);
    if (!touchList()) return;
    pressTimer = window.setTimeout(() => {
      pressSelected = true;
      onToggleSelect(id);
      navigator.vibrate?.(10);
    }, 450);
  }
  function pressEnd(): void {
    clearTimeout(pressTimer);
  }
  function rowClick(id: number): void {
    if (pressSelected) {
      pressSelected = false;
      return;
    }
    onSelect(id);
  }
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
  const channelLabel = (value: string, assetType?: string) =>
    assetType === "whatsapp_qr_session"
      ? "WhatsApp · QR"
      : (
          {
            whatsapp: "WhatsApp",
            instagram: "Instagram",
            facebook: "Facebook",
            webchat: "Web Chat",
            email: t("mautic.inbox.ui.email_channel"),
          } as Record<string, string>
        )[value] || value;

  /**
   * A lista para de ser uma pilha por relogio e passa a dizer o que cada conversa espera.
   *
   * Os tres grupos saem de campos que o servidor ja manda — `needs_response` e `lifecycle` —,
   * entao isto nao pede nada de novo a nenhuma ponta. Uma conversa adiada sai do "esperando
   * voce" mesmo precisando de resposta: ela volta sozinha na hora marcada, e manter no topo
   * algo que alguem ja decidiu adiar seria pedir a mesma decisao duas vezes.
   */
  const groupLabels: Record<string, string> = {
    waiting_you: "mautic.inbox.ui.group_waiting_you",
    snoozed: "mautic.inbox.ui.group_snoozed",
    waiting_contact: "mautic.inbox.ui.group_waiting_contact",
  };
  const groupIcons: Record<string, string> = {
    waiting_you: "clock",
    snoozed: "reload",
    waiting_contact: "reply",
  };
  const groupOrder = ["waiting_you", "snoozed", "waiting_contact"];
  const groupOf = (item: Conversation) =>
    item.lifecycle === "snoozed"
      ? "snoozed"
      : item.needs_response
        ? "waiting_you"
        : "waiting_contact";
  /**
   * As acoes que a linha oferece sem abrir a conversa — o que o arrastar faz no aplicativo.
   *
   * A lista so desenha o que o servidor vai aceitar. Em `ConversationActions`, toda transicao
   * menos `reopen` exige que quem pede seja o responsavel, entao adiar e resolver aparecem so
   * nas minhas, assumir so no que nao tem dono, e reabrir em qualquer resolvida. Oferecer o
   * resto seria desenhar um botao que responde erro.
   */
  const rowActions = (item: Conversation) =>
    item.lifecycle === "resolved"
      ? [["reopen", "mautic.inbox.ui.row_reopen"]]
      : !item.assignee
        ? [["take", "mautic.inbox.ui.row_take"]]
        : item.assignee.id === currentUser
          ? [
              ["snooze", "mautic.inbox.ui.row_snooze_hour"],
              ["resolve", "mautic.inbox.ui.row_resolve"],
            ]
          : [];
  /** Grupo vazio nao vira cabecalho: a coluna nunca mostra um titulo sem nada embaixo. */
  $: groups = groupOrder
    .map((key) => ({
      key,
      items: rows.filter((item) => groupOf(item) === key),
    }))
    .filter((group) => group.items.length > 0);
</script>

<section
  class="inbox-list-column"
  class:selecting={selection.size > 0}
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
  <div class="inbox-search-wrap" class:open={searchOpen || search !== ""}>
    <Icon name="search" /><input
      id="inbox-search"
      type="search"
      bind:value={search}
      bind:this={searchInput}
      placeholder={t("mautic.inbox.ui.search_conversations_519789")}
      aria-label={t("mautic.inbox.ui.search_conversations_519789")}
      on:input={onSearch}
    />
  </div>
  <nav class="inbox-queues" aria-label={t("mautic.inbox.ui.queues_8e5d55")}>
    <!-- No celular o filtro vira o primeiro chip da fileira, como no app; no desktop ele segue
         no cabecalho da coluna e este some. -->
    <button
      type="button"
      class="inbox-queue-filter"
      class:active={filtersOpen || activeFilters > 0}
      aria-label={t("mautic.inbox.ui.filter_conversations_9d8aa3")}
      aria-expanded={filtersOpen}
      on:click={onFilters}
      ><Icon name="filter" />{#if activeFilters}<b
          title={t("mautic.inbox.ui.filters_active")}>{activeFilters}</b
        >{/if}</button
    >
    {#each [["mine", "mautic.inbox.ui.mine_1380c1"], ["unassigned", "mautic.inbox.ui.unassigned_a121ae"], ["all", "mautic.inbox.ui.all_8a3c34"]] as item}<button
        class:active={queue === item[0]}
        data-queue={item[0]}
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
          ><option value="facebook">Facebook / Messenger</option><option
            value="webchat">Web Chat</option
          ><option value="email">{t("mautic.inbox.ui.email_channel")}</option
          ></select
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
      {#each groups as group (group.key)}<h3
          class="inbox-list-group"
          data-group={group.key}
        >
          <Icon name={groupIcons[group.key]} />{t(groupLabels[group.key])}<b
            >{group.items.length}</b
          >
        </h3>
        {#each group.items as item (item.id)}<div
            class="inbox-list-row"
            class:selected={selection.has(item.id)}
          >
            <input
              class="inbox-list-check"
              type="checkbox"
              checked={selection.has(item.id)}
              aria-label={item.contact_name}
              on:change={() => onToggleSelect(item.id)}
            />
            <button
              type="button"
              class="inbox-list-item"
              data-group={group.key}
              data-channel={item.channel}
              class:active={selected?.id === item.id}
              class:unread={(item.unread ?? 0) > 0}
              on:pointerdown={() => pressStart(item.id)}
              on:pointerup={pressEnd}
              on:pointercancel={pressEnd}
              on:pointerleave={pressEnd}
              on:contextmenu={(event) => touchList() && event.preventDefault()}
              on:click={() => rowClick(item.id)}
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
                  {channelLabel(item.channel, item.asset.type)} · {item.asset
                    .handle
                    ? `@${item.asset.handle}`
                    : item.asset.phone || item.asset.address || item.asset.name}
                </div>
                {#if item.channel === "email"}<div class="inbox-list-subject">
                    {item.subject || t("mautic.inbox.ui.email_no_subject")}
                  </div>{/if}
                <div class="inbox-list-preview">
                  {item.preview || item.last_message_preview || item.asset.name}
                </div>
                <!-- O selo repete o cabecalho do grupo quando os dois dizem a mesma coisa; nesse
                 caso ele some, e fica so onde acrescenta algo (adiada que ainda deve resposta). -->
                <div class="inbox-list-flags">
                  {#if item.needs_response && group.key !== "waiting_you"}<span
                      class="inbox-pill response"
                      >{t("mautic.inbox.ui.awaiting_reply_f6a6ba")}</span
                    >{/if}{#if item.unread}<span class="inbox-pill unread"
                      >{item.unread}</span
                    >{/if}{#if item.lifecycle === "snoozed" && group.key !== "snoozed"}<span
                      class="inbox-pill"
                      >{t("mautic.inbox.ui.snoozed_435da9")}</span
                    >{/if}{#if item.moderation?.spam}<span
                      class="inbox-pill moderated"
                      >{t("mautic.inbox.ui.moderation_spam_mark")}</span
                    >{/if}{#if item.moderation?.hidden}<span
                      class="inbox-pill moderated"
                      >{t("mautic.inbox.ui.moderation_hidden_mark")}</span
                    >{/if}{#if item.moderation?.blockedAuthor}<span
                      class="inbox-pill moderated"
                      >{t("mautic.inbox.ui.moderation_blocked_mark")}</span
                    >{/if}
                </div>
              </div>
            </button>{#if rowActions(item).length}<div
                class="inbox-list-actions"
              >
                {#each rowActions(item) as action (action[0])}<button
                    type="button"
                    disabled={busy.has(item.id)}
                    on:click={() => onAction(item, action[0])}
                    >{t(action[1])}</button
                  >{/each}
              </div>{/if}
          </div>{/each}{/each}
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

  {#if selection.size}<div
      class="inbox-bulk"
      role="group"
      aria-label={t("mautic.inbox.ui.bulk_selected")}
    >
      <strong>{selection.size}</strong>
      <span>{t("mautic.inbox.ui.bulk_selected")}</span>
      <!-- A barra so oferece o que vale para varias conversas de uma vez e nao precisa de
       formulario: transferir pede escolher uma pessoa, e isso e decisao de uma por vez. -->
      {#if view === "comments"}<button
          type="button"
          class="btn btn-default btn-sm"
          disabled={bulkBusy}
          on:click={() => onBulk("spam")}
          >{t("mautic.inbox.ui.moderation_spam")}</button
        ><button
          type="button"
          class="btn btn-default btn-sm"
          disabled={bulkBusy}
          on:click={() => onBulk("block")}
          >{t("mautic.inbox.ui.moderation_block")}</button
        >{:else}<button
          type="button"
          class="btn btn-default btn-sm"
          disabled={bulkBusy}
          on:click={() => onBulk("snooze")}
          >{t("mautic.inbox.ui.row_snooze_hour")}</button
        ><button
          type="button"
          class="btn btn-default btn-sm"
          disabled={bulkBusy}
          on:click={() => onBulk("resolve")}
          >{t("mautic.inbox.ui.row_resolve")}</button
        >{/if}
      <button
        type="button"
        class="btn btn-link btn-sm"
        disabled={bulkBusy}
        on:click={onClearSelection}>{t("mautic.inbox.ui.bulk_clear")}</button
      >
    </div>{/if}
</section>
