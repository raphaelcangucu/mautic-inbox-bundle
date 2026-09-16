<script lang="ts">
  import Icon from "../shared/Icon.svelte";
  import type {
    CannedResponse,
    Conversation,
    WhatsAppTemplate,
  } from "../shared/types";
  export let selected: Conversation;
  export let currentUser: number;
  export let mode = "reply";
  export let body = "";
  export let sending = false;
  export let feedback = "";
  export let feedbackError = false;
  export let draftState = "";
  export let canned: CannedResponse[] = [];
  export let templates: WhatsAppTemplate[] = [];
  export let templatesLoading = false;
  export let templateBlocked: string | null = null;
  export let templateError = "";
  export let t: (key: string) => string;
  export let onMode: (mode: string) => void;
  export let onInput: () => void;
  export let onSend: () => void;
  export let onLoadTemplates: () => void;
  export let onTemplateSend: (
    template: WhatsAppTemplate,
    values: Record<string, string>,
  ) => Promise<boolean>;
  let menuValue = "";
  let templateOpen = false;
  let templateId = "";
  let values: Record<string, string> = {};
  let templateSending = false;
  let owner = selected.id;
  $: note = mode === "note";
  $: publicReply = Boolean(selected.reply_public);
  $: selectedTemplate = templates.find(
    (item) => String(item.id) === templateId,
  );
  $: maximum =
    !note && selected.channel === "instagram"
      ? 1000
      : !note && selected.channel === "facebook"
        ? 2000
        : 4000;
  $: disabled =
    sending ||
    (!note && !selected.can_reply && !selected.can_take_and_reply) ||
    selected.lifecycle === "resolved";
  $: preview = selectedTemplate
    ? selectedTemplate.parts
        .map((part) =>
          part.text.replace(
            /\{\{([A-Za-z0-9_]+)\}\}/g,
            (match, token) => values[`${part.type}:${token}`] || match,
          ),
        )
        .join("\n\n")
    : "";
  $: templateDisabled =
    sending ||
    templateSending ||
    Boolean(templateBlocked) ||
    !selectedTemplate ||
    !selectedTemplate.supported ||
    selectedTemplate.fields.some(
      (field) => !(values[field.key] || "").trim(),
    ) ||
    selected.lifecycle === "resolved" ||
    Boolean(selected.assignee && selected.assignee.id !== currentUser);
  $: if (selected.id !== owner) {
    owner = selected.id;
    closeTemplate();
    menuValue = "";
  }
  function closeTemplate(): void {
    templateOpen = false;
    templateId = "";
    values = {};
    templateError = "";
  }
  function changeMode(nextMode: string): void {
    if (nextMode === "note") closeTemplate();
    onMode(nextMode);
  }
  function choose(): void {
    if (menuValue.startsWith("template:")) {
      templateOpen = true;
      templateId = menuValue.slice(9);
      values = {};
      templateError = "";
      onLoadTemplates();
    } else {
      const response = canned.find((item) => String(item.id) === menuValue);
      if (response && !selected.reply_blocked_reason) {
        body += `${body ? "\n" : ""}${response.body}`;
        onInput();
      }
    }
    menuValue = "";
  }
  function selectTemplate(): void {
    values = {};
    templateError = "";
  }
  async function sendTemplate(): Promise<void> {
    if (!selectedTemplate || templateDisabled) return;
    templateSending = true;
    try {
      if (await onTemplateSend(selectedTemplate, values)) {
        templateOpen = false;
        templateId = "";
        values = {};
      }
    } finally {
      templateSending = false;
    }
  }
</script>

<footer
  class="inbox-composer"
  class:note-mode={note}
  class:template-mode={templateOpen && !note}
>
  <div class="inbox-composer-top">
    <div class="inbox-composer-tabs">
      <button class:active={!note} on:click={() => changeMode("reply")}
        ><Icon name="reply" />{publicReply
          ? t("mautic.inbox.ui.public_reply_42dc43")
          : t("mautic.inbox.ui.private_reply_ecd924")}</button
      ><button class:active={note} on:click={() => changeMode("note")}
        ><Icon name="note" />{t("mautic.inbox.ui.internal_note_010aa1")}</button
      >
    </div>
    <span
      id="inbox-composer-channel"
      class="inbox-secondary inbox-composer-channel"
      >{{ whatsapp: "WhatsApp", instagram: "Instagram", facebook: "Facebook" }[
        selected.channel
      ] || selected.channel}</span
    >
  </div>
  {#if templateOpen && !note}<section
      id="inbox-template-panel"
      class="inbox-template-panel"
      aria-label={t("mautic.inbox.template.title")}
    >
      <div class="inbox-template-heading">
        <strong>{t("mautic.inbox.template.title")}</strong><button
          id="inbox-template-close"
          class="btn btn-link btn-sm"
          type="button"
          on:click={closeTemplate}>{t("mautic.inbox.template.close")}</button
        >
      </div>
      <select
        id="inbox-template-select"
        bind:value={templateId}
        class="not-chosen form-control"
        aria-label={t("mautic.inbox.template.select")}
        on:change={selectTemplate}
        ><option value="">{t("mautic.inbox.template.select")}</option
        >{#each templates as item}<option value={item.id}
            >{item.name} · {item.language}</option
          >{/each}</select
      >
      <div id="inbox-template-status" role="status">
        {templateError ||
          templateBlocked ||
          (templatesLoading
            ? t("mautic.inbox.template.loading")
            : !templates.length
              ? t("mautic.inbox.template.empty")
              : selectedTemplate && !selectedTemplate.supported
                ? t("mautic.inbox.template.unsupported")
                : "")}
      </div>
      {#if selectedTemplate}<div id="inbox-template-fields">
          {#each selectedTemplate.fields as field}<label
              >{t("mautic.inbox.template.variable")}
              {t(
                field.component === "HEADER"
                  ? "mautic.inbox.template.header"
                  : "mautic.inbox.template.body",
              )}
              {"{{"}{field.token}{"}}"}<input
                class="form-control"
                bind:value={values[field.key]}
                maxlength="1024"
                required
              /></label
            >{/each}
        </div>
        <div id="inbox-template-preview" class="inbox-template-preview">
          {preview}
        </div>{/if}<button
        id="inbox-template-send"
        class="btn btn-primary"
        type="button"
        disabled={templateDisabled}
        on:click={sendTemplate}
        >{templateSending
          ? t("mautic.inbox.template.sending")
          : t("mautic.inbox.template.send")}</button
      >
    </section>{/if}
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
      : `${publicReply ? t("mautic.inbox.ui.public_reply_on_facebook_38b969") : ""}${selected.reply_hint || ""}`}
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
    bind:value={body}
    maxlength={maximum}
    rows="3"
    placeholder={note
      ? t("mautic.inbox.ui.write_a_note_visible_only_to_your_team_10eb89")
      : publicReply
        ? t("mautic.inbox.ui.write_a_public_reply_to_the_comment_251f01")
        : t("mautic.inbox.ui.write_a_private_reply_394bc1")}
    aria-label={t("mautic.inbox.ui.reply_text_680d6f")}
    on:input={onInput}
    on:keydown={(event) => {
      if (
        (event.metaKey || event.ctrlKey) &&
        event.key === "Enter" &&
        !disabled
      ) {
        event.preventDefault();
        onSend();
      }
    }}
  ></textarea>
  <div class="inbox-composer-footer">
    <div class="inbox-composer-tools">
      <Icon name="bolt" />{#if !note}<select
          id="inbox-canned"
          bind:value={menuValue}
          class="not-chosen"
          aria-label={t("mautic.inbox.ui.insert_canned_response_97c0df")}
          on:change={choose}
          ><option value=""
            >{t("mautic.inbox.ui.canned_responses_f45beb")}</option
          ><optgroup
            id="inbox-canned-group"
            label={t("mautic.inbox.ui.canned_responses_f45beb")}
            disabled={Boolean(selected.reply_blocked_reason)}
            >{#each canned as item}<option value={item.id}>{item.name}</option
              >{/each}</optgroup
          >{#if selected.channel === "whatsapp"}<optgroup
              id="inbox-template-group"
              label={`WhatsApp · ${t("mautic.inbox.template.select")}`}
              >{#if templatesLoading}<option disabled
                  >{t("mautic.inbox.template.loading")}</option
                >{:else if !templates.length}<option value="template:"
                  >{t("mautic.inbox.template.choose")}</option
                >{:else}{#each templates as item}<option
                    value={`template:${item.id}`}
                    disabled={!item.supported}
                    >{item.name} · {item.language}</option
                  >{/each}{/if}</optgroup
            >{/if}</select
        >{/if}
    </div>
    <button
      id="inbox-send"
      class="btn btn-primary"
      disabled={disabled || !body.trim()}
      on:click={onSend}
      >{sending
        ? t("mautic.inbox.ui.sending_5e91dc")
        : note
          ? t("mautic.inbox.ui.add_note_344d88")
          : selected.can_take_and_reply
            ? t("mautic.inbox.ui.assign_to_me_and_send_509661")
            : t("mautic.inbox.ui.send_reply_c50a43")}</button
    >
  </div>
  <div class="inbox-editor-status">
    <span id="inbox-draft-state"
      >{draftState ||
        t("mautic.inbox.ui.draft_saved_automatically_da72a0")}</span
    ><span>{t("mautic.inbox.ui.ctrl_enter_to_send_0d34b5")}</span>
  </div>
</footer>
