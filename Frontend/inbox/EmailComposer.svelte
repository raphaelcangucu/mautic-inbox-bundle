<script lang="ts">
  import { tick } from "svelte";
  import Icon from "../shared/Icon.svelte";
  import { replySubject } from "./emailThread";
  import type { CannedResponse, Conversation } from "../shared/types";
  /**
   * A composicao do e-mail, estilo Gmail: fechada no fim da thread com "Responder",
   * "Encaminhar" e "Nota interna"; aberta com Para (o lead, fixo), De (a conta), Assunto
   * ("Re: ...", editavel), corpo e o "+" com respostas prontas e formulario.
   */
  export let selected: Conversation;
  export let mode = "reply";
  export let body = "";
  export let threadSubject = "";
  export let feedback = "";
  export let feedbackError = false;
  export let draftState = "";
  export let canned: CannedResponse[] = [];
  export let t: (key: string) => string;
  export let onMode: (mode: string) => void;
  export let onInput: () => void;
  export let onSend: (subject: string) => void;
  export let onForward: () => void = () => {};
  export let loadForms: () => Promise<
    Array<{ id: number; name: string }>
  > = async () => [];
  let open = Boolean(body.trim());
  let subject = replySubject(threadSubject);
  let subjectEdited = false;
  let owner = selected.id;
  let insertOpen = false;
  let forms: Array<{ id: number; name: string }> | null = null;
  let formsLoading = false;
  let formChoice = "";
  let cannedChoice = "";
  let field: HTMLTextAreaElement | null = null;
  $: note = mode === "note";
  $: if (selected.id !== owner) {
    owner = selected.id;
    open = Boolean(body.trim());
    subjectEdited = false;
    subject = replySubject(threadSubject);
    insertOpen = false;
  }
  $: if (!subjectEdited) subject = replySubject(threadSubject);
  $: disabled =
    (!note && !selected.can_reply && !selected.can_take_and_reply) ||
    selected.lifecycle === "resolved";
  $: sendDisabled =
    disabled || !body.trim() || (!note && !subject.trim() && !threadSubject);
  async function start(nextMode: string): Promise<void> {
    if (nextMode !== mode) onMode(nextMode);
    open = true;
    await tick();
    field?.focus();
  }
  async function toggleInsert(): Promise<void> {
    insertOpen = !insertOpen;
    if (insertOpen && null === forms && !formsLoading) {
      formsLoading = true;
      try {
        forms = await loadForms();
      } catch {
        forms = [];
      } finally {
        formsLoading = false;
      }
    }
  }
  function append(text: string): void {
    body += `${body && !body.endsWith("\n") ? "\n" : ""}${text}`;
    onInput();
    insertOpen = false;
    tick().then(() => field?.focus());
  }
  /**
   * O valor sai do proprio select, e nao da variavel ligada: o ouvinte do change pode rodar
   * antes de o bind:value atualizar a variavel, e a escolha se perderia.
   */
  const chosen = (event: Event): string =>
    (event.currentTarget as HTMLSelectElement | null)?.value || "";
  function chooseCanned(event: Event): void {
    const choice = chosen(event);
    const response = canned.find((item) => String(item.id) === choice);
    if (response) append(response.body);
    cannedChoice = "";
  }
  function chooseForm(event: Event): void {
    const choice = chosen(event);
    if (/^[1-9][0-9]*$/.test(choice)) append(`[[form:${choice}]]`);
    formChoice = "";
  }
  function send(): void {
    if (sendDisabled) return;
    onSend(note ? "" : subject.trim());
  }
  function discard(): void {
    body = "";
    onInput();
    open = false;
    insertOpen = false;
  }
</script>

<footer
  class="inbox-composer inbox-email-composer"
  class:note-mode={note}
  class:open
>
  {#if !open}<div class="inbox-email-actions">
      <button
        id="inbox-email-reply"
        type="button"
        class="btn btn-default"
        disabled={disabled && !note}
        on:click={() => void start("reply")}
        ><Icon name="reply" />{t("mautic.inbox.ui.email_reply")}</button
      ><button
        id="inbox-email-forward"
        type="button"
        class="btn btn-default"
        on:click={onForward}
        ><Icon name="send" />{t("mautic.inbox.ui.email_forward")}</button
      ><button
        id="inbox-email-note"
        type="button"
        class="btn btn-link"
        on:click={() => void start("note")}
        ><Icon name="note" />{t("mautic.inbox.ui.internal_note_010aa1")}</button
      >
    </div>
    <div
      id="inbox-reply-hint"
      class="inbox-reply-hint"
      class:blocked={Boolean(selected.reply_blocked_reason)}
      role="status"
    >
      {selected.reply_hint || ""}
    </div>
  {:else}
    {#if note}<div class="inbox-composer-top">
        <span class="inbox-composer-mode"
          ><Icon name="note" />{t("mautic.inbox.ui.internal_note_010aa1")}</span
        >
      </div>{:else}<div class="inbox-email-fields">
        <div class="inbox-email-field">
          <span>{t("mautic.inbox.ui.email_to")}</span><strong
            id="inbox-email-to"
            >{selected.contact_name}
            &lt;{selected.recipient}&gt;</strong
          >
        </div>
        <div class="inbox-email-field">
          <span>{t("mautic.inbox.ui.email_from")}</span><span
            id="inbox-email-from"
            >{selected.asset.name}{selected.asset.address
              ? ` · ${selected.asset.address}`
              : ""}</span
          >
        </div>
        <label class="inbox-email-field">
          <span>{t("mautic.inbox.ui.email_subject")}</span><input
            id="inbox-email-subject-input"
            class="form-control"
            bind:value={subject}
            maxlength="255"
            placeholder={t("mautic.inbox.ui.email_subject")}
            on:input={() => (subjectEdited = true)}
          />
        </label>
      </div>{/if}
    <div
      id="inbox-reply-hint"
      class="inbox-reply-hint"
      class:blocked={Boolean(selected.reply_blocked_reason && !note)}
      role="status"
    >
      {note
        ? t(
            "mautic.inbox.ui.internal_note_only_your_team_will_see_this_text_6f8a19",
          )
        : selected.reply_hint || ""}
    </div>
    <div id="inbox-feedback" role="alert">
      {#if feedback}
        {#if feedbackError}<div class="inbox-error">{feedback}</div>{:else}<div
            class="inbox-feedback-success"
          >
            {feedback}
          </div>{/if}
      {/if}
    </div>
    <textarea
      id="inbox-composer-text"
      class="inbox-email-textarea"
      bind:this={field}
      bind:value={body}
      maxlength="4000"
      rows="6"
      placeholder={note
        ? t("mautic.inbox.ui.write_a_note_visible_only_to_your_team_10eb89")
        : t("mautic.inbox.ui.email_compose_placeholder")}
      aria-label={t("mautic.inbox.ui.reply_text_680d6f")}
      on:input={onInput}
      on:keydown={(event) => {
        if (
          (event.metaKey || event.ctrlKey) &&
          event.key === "Enter" &&
          !event.isComposing
        ) {
          event.preventDefault();
          send();
        }
      }}
    ></textarea>
    {#if insertOpen && !note}<div
        id="inbox-email-insert-panel"
        class="inbox-email-insert"
      >
        <label
          ><span>{t("mautic.inbox.ui.canned_responses_f45beb")}</span><select
            id="inbox-canned"
            class="not-chosen form-control"
            bind:value={cannedChoice}
            on:change={chooseCanned}
            ><option value=""
              >{t("mautic.inbox.ui.insert_canned_response_97c0df")}</option
            >{#each canned as item}<option value={String(item.id)}
                >{item.name}</option
              >{/each}</select
          ></label
        >
        <label
          ><span>{t("mautic.inbox.ui.email_form")}</span><select
            id="inbox-email-forms"
            class="not-chosen form-control"
            bind:value={formChoice}
            disabled={formsLoading || !forms?.length}
            on:change={chooseForm}
            ><option value=""
              >{formsLoading
                ? t("mautic.inbox.ui.email_forms_loading")
                : forms?.length
                  ? t("mautic.inbox.ui.email_insert")
                  : t("mautic.inbox.ui.email_forms_empty")}</option
            >{#each forms || [] as item}<option value={String(item.id)}
                >{item.name}</option
              >{/each}</select
          ></label
        >
      </div>{/if}
    <div class="inbox-email-compose-actions">
      <button
        id="inbox-send"
        class="btn btn-primary"
        type="button"
        disabled={sendDisabled}
        on:click={send}
        ><Icon name="send" />{note
          ? t("mautic.inbox.ui.add_note_344d88")
          : t("mautic.inbox.ui.email_send")}</button
      >
      {#if !note}<button
          id="inbox-email-insert"
          class="inbox-icon-button"
          type="button"
          aria-expanded={insertOpen}
          aria-label={t("mautic.inbox.ui.composer_options")}
          title={t("mautic.inbox.ui.composer_options")}
          on:click={() => void toggleInsert()}><Icon name="plus" /></button
        >{/if}
      <button
        id="inbox-email-discard"
        class="btn btn-link"
        type="button"
        on:click={discard}>{t("mautic.inbox.ui.email_discard")}</button
      >
      <span id="inbox-draft-state" class="inbox-email-draft"
        >{draftState ||
          t("mautic.inbox.ui.draft_saved_automatically_da72a0")}</span
      >
    </div>
  {/if}
</footer>
