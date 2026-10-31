<script lang="ts">
  import Icon from "../shared/Icon.svelte";
  import Avatar from "./Avatar.svelte";
  import type { AiSessionRow } from "../shared/types";
  export let rows: AiSessionRow[] = [];
  export let loading = false;
  export let error = "";
  export let locale: string;
  export let t: (key: string) => string;
  export let onOpen: (id: number) => void;
  export let onReload: () => void;
  const channelLabel = (value: string) =>
    (
      ({
        whatsapp: "WhatsApp",
        instagram: "Instagram",
        facebook: "Facebook",
        webchat: "Web Chat",
        email: t("mautic.inbox.ui.email_channel"),
      }) as Record<string, string>
    )[value] || value;
  const moment = (iso?: string | null) =>
    !iso
      ? ""
      : new Intl.DateTimeFormat(locale, {
          day: "2-digit",
          month: "short",
          hour: "2-digit",
          minute: "2-digit",
        }).format(new Date(iso));
  /**
   * Duas secoes, nao uma ordenada: quem parou pedindo gente e um trabalho que esta na fila de
   * alguem, e quem esta respondendo e so informacao. Juntar os dois numa lista ordenada faria
   * o operador reler tudo para achar os que importam.
   */
  $: esperando = rows.filter((row) => row.needs_human);
  $: andando = rows.filter((row) => !row.needs_human);
</script>

<section class="inbox-sessions" aria-label={t("mautic.inbox.ui.ai_sessions")}>
  <header class="inbox-sessions-head">
    <div>
      <h2>{t("mautic.inbox.ui.ai_sessions")}</h2>
      <p>{t("mautic.inbox.ui.ai_sessions_hint")}</p>
    </div>
    <button
      type="button"
      class="btn btn-default btn-sm"
      disabled={loading}
      on:click={onReload}
      ><Icon name="reload" />{t("mautic.inbox.ui.reload")}</button
    >
  </header>

  {#if error}<div class="inbox-error">{error}</div>{/if}

  {#if loading && !rows.length}<div class="inbox-state">
      {t("mautic.inbox.ui.loading_conversations_cb1a50")}
    </div>{:else if !rows.length}<div class="inbox-state">
      {t("mautic.inbox.ui.ai_sessions_empty")}
    </div>{:else}
    {#each [["waiting", esperando], ["running", andando]] as group (group[0])}
      {#if (group[1] as AiSessionRow[]).length}<div
          class="inbox-sessions-group"
          data-group={group[0]}
        >
          <h3>
            <Icon name={group[0] === "waiting" ? "clock" : "agent"} />{t(
              group[0] === "waiting"
                ? "mautic.inbox.ui.ai_sessions_waiting"
                : "mautic.inbox.ui.ai_sessions_running",
            )}<b>{(group[1] as AiSessionRow[]).length}</b>
          </h3>
          <ul>
            {#each group[1] as AiSessionRow[] as row (row.id)}<li>
                <Avatar
                  name={row.contact_name}
                  id={row.id}
                  channel={row.channel}
                  photo={row.avatar_url || null}
                  photoLabel={t("mautic.inbox.ui.photo_of_5bece3")}
                />
                <div class="inbox-sessions-copy">
                  <strong>{row.contact_name}</strong>
                  <span class="inbox-list-meta"
                    >{channelLabel(row.channel)} · {row.agent} · {moment(
                      row.last_message_at,
                    )}</span
                  >
                  {#if row.reason_label}<span class="inbox-sessions-reason"
                      >{row.reason_label}</span
                    >{/if}
                </div>
                <div class="inbox-sessions-count">
                  {#if row.limit > 0}<b>{row.count}/{row.limit}</b><small
                      >{t("mautic.inbox.ui.ai_replies_used")}</small
                    >{:else}<small>{row.status_label}</small>{/if}
                </div>
                <button
                  type="button"
                  class="btn {group[0] === 'waiting'
                    ? 'btn-primary'
                    : 'btn-default'} btn-sm"
                  on:click={() => onOpen(row.id)}
                  >{t(
                    group[0] === "waiting"
                      ? "mautic.inbox.ui.ai_take_over"
                      : "mautic.inbox.ui.ai_sessions_open",
                  )}</button
                >
              </li>{/each}
          </ul>
        </div>{/if}
    {/each}
  {/if}
</section>
