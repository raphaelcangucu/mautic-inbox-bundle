<script lang="ts">
  import { onMount } from "svelte";
  import { endpoint, jsonRequest } from "../shared/api";

  export let email: string;
  export let stateId: number;
  export let csrf: string;
  export let optionsUrl: string;
  export let applyUrl: string;
  export let t: (key: string) => string;
  export let onClose: () => void;
  export let onApplied: (resumo: string) => void;

  type Card = { id: number; name: string; email: string };
  type Alvo = { id: number; name: string };
  type Opcoes = {
    email: string;
    contact: Card | null;
    matches: Card[];
    campaigns: Alvo[];
    segments: Alvo[];
    can_edit: boolean;
  };

  let dados: Opcoes | null = null;
  let erro = "";
  let aplicando = false;
  /** 0 = o contato da conversa, ou um novo quando ela nao tem nenhum. */
  let contactId = 0;
  let saveEmail = true;
  let campaignId = "";
  let segmentId = "";

  onMount(async () => {
    try {
      dados = await jsonRequest<Opcoes>(
        `${endpoint(optionsUrl, stateId)}?email=${encodeURIComponent(email)}`,
        csrf,
      );
    } catch (problema) {
      erro = problema instanceof Error ? problema.message : String(problema);
    }
  });

  $: nada = !saveEmail && "" === campaignId && "" === segmentId;

  async function aplicar(): Promise<void> {
    if (nada || aplicando) return;
    aplicando = true;
    erro = "";
    try {
      const feito = await jsonRequest<{ applied: string[]; contact: Card }>(
        endpoint(applyUrl, stateId),
        csrf,
        {
          method: "POST",
          body: JSON.stringify({
            email,
            contact_id: contactId,
            save_email: saveEmail,
            campaign_id: campaignId ? Number(campaignId) : 0,
            segment_id: segmentId ? Number(segmentId) : 0,
          }),
        },
      );
      onApplied(feito.contact.name);
    } catch (problema) {
      erro = problema instanceof Error ? problema.message : String(problema);
      aplicando = false;
    }
  }
</script>

<!-- svelte-ignore a11y-no-static-element-interactions a11y-click-events-have-key-events -->
<div class="inbox-sheet-backdrop" on:click={onClose}></div>
<div
  class="inbox-sheet"
  role="dialog"
  aria-modal="true"
  aria-label={t("mautic.inbox.contact.title")}
>
  <header class="inbox-sheet-head">
    <strong>{t("mautic.inbox.contact.title")}</strong>
    <button class="btn btn-link btn-sm" on:click={onClose}
      >{t("mautic.inbox.contact.cancel")}</button
    >
  </header>
  <p class="inbox-sheet-email">{email}</p>

  {#if erro}<div class="inbox-error">{erro}</div>{/if}

  {#if null === dados}
    {#if !erro}<div class="inbox-state">
        {t("mautic.inbox.contact.loading")}
      </div>{/if}
  {:else if !dados.can_edit}
    <div class="inbox-state">{t("mautic.inbox.contact.not_allowed")}</div>
  {:else}
    <!-- O conflito e mostrado, nunca resolvido sozinho: escolher entre o contato da conversa e
         um homonimo por e-mail move a conversa de dono, e isso e julgamento de quem atende. -->
    {#if dados.matches.length}
      <fieldset class="inbox-sheet-block">
        <legend>{t("mautic.inbox.contact.choose_contact")}</legend>
        <label class="inbox-sheet-choice">
          <input type="radio" bind:group={contactId} value={0} />
          <span
            >{dados.contact
              ? dados.contact.name
              : t("mautic.inbox.contact.new_contact")}<small
              >{dados.contact
                ? t("mautic.inbox.contact.conversation_contact")
                : ""}</small
            ></span
          >
        </label>
        {#each dados.matches as achado}
          <label class="inbox-sheet-choice">
            <input type="radio" bind:group={contactId} value={achado.id} />
            <span
              >{achado.name}<small>{t("mautic.inbox.contact.existing")}</small
              ></span
            >
          </label>
        {/each}
      </fieldset>
    {:else if dados.contact}
      <p class="inbox-secondary">
        {t("mautic.inbox.contact.conversation_contact")}: {dados.contact.name}
      </p>
    {:else}
      <p class="inbox-secondary">{t("mautic.inbox.contact.no_contact")}</p>
    {/if}

    <label class="inbox-sheet-check">
      <input type="checkbox" bind:checked={saveEmail} />
      <span>{t("mautic.inbox.contact.save_email")}</span>
    </label>

    {#if dados.campaigns.length}
      <label class="inbox-sheet-block"
        ><span class="inbox-field-label"
          >{t("mautic.inbox.contact.campaign")}</span
        ><select class="form-control" bind:value={campaignId}
          ><option value="">{t("mautic.inbox.contact.none")}</option
          >{#each dados.campaigns as item}<option value={String(item.id)}
              >{item.name}</option
            >{/each}</select
        ></label
      >
    {/if}

    {#if dados.segments.length}
      <label class="inbox-sheet-block"
        ><span class="inbox-field-label"
          >{t("mautic.inbox.contact.segment")}</span
        ><select class="form-control" bind:value={segmentId}
          ><option value="">{t("mautic.inbox.contact.none")}</option
          >{#each dados.segments as item}<option value={String(item.id)}
              >{item.name}</option
            >{/each}</select
        ></label
      >
    {/if}

    <div class="inbox-sheet-foot">
      {#if nada}<span class="inbox-secondary"
          >{t("mautic.inbox.contact.nothing_chosen")}</span
        >{/if}
      <button
        class="btn btn-primary"
        disabled={nada || aplicando}
        on:click={aplicar}
        >{aplicando
          ? t("mautic.inbox.contact.applying")
          : t("mautic.inbox.contact.apply")}</button
      >
    </div>
  {/if}
</div>
