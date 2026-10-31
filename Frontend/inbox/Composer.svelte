<script lang="ts">
  import { onMount, tick } from "svelte";
  import Icon from "../shared/Icon.svelte";
  import type {
    CannedResponse,
    Conversation,
    WhatsAppTemplate,
  } from "../shared/types";
  export let selected: Conversation;
  export let currentUser: number;
  export let locale = "pt-BR";
  export let audioAvailable = false;
  export let audioBusy = false;
  export let onAudio: (data: string) => void = () => {};
  /**
   * Gravar um audio no navegador.
   *
   * So aparece onde o canal aceita (sessao de WhatsApp por QR) E o navegador sabe gravar em
   * MPEG-4 — o servidor so aceita esse formato, e um botao que grava WebM produziria um envio
   * recusado depois da pessoa ter falado. Preferir mostrar botao nenhum a mostrar um que falha.
   */
  const audioFormato = "audio/mp4";
  const podeGravar =
    typeof window !== "undefined" &&
    typeof MediaRecorder !== "undefined" &&
    Boolean(navigator.mediaDevices?.getUserMedia) &&
    MediaRecorder.isTypeSupported(audioFormato);
  let gravando = false;
  let gravador: MediaRecorder | null = null;
  async function alternarGravacao(): Promise<void> {
    if (gravando) {
      gravador?.stop();
      return;
    }
    let trilha: MediaStream;
    try {
      trilha = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch {
      return; // permissao negada: o navegador ja avisou a pessoa
    }
    const pedacos: Blob[] = [];
    const sessao = new MediaRecorder(trilha, { mimeType: audioFormato });
    sessao.ondataavailable = (evento) => {
      if (evento.data.size) pedacos.push(evento.data);
    };
    sessao.onstop = () => {
      gravando = false;
      gravador = null;
      trilha.getTracks().forEach((t) => t.stop());
      if (!pedacos.length) return;
      const leitor = new FileReader();
      leitor.onload = () => {
        // O servidor quer os bytes em base64, sem o prefixo do dataURL.
        const texto = String(leitor.result || "");
        const corte = texto.indexOf(",");
        if (corte > 0) onAudio(texto.slice(corte + 1));
      };
      leitor.readAsDataURL(new Blob(pedacos, { type: audioFormato }));
    };
    gravador = sessao;
    gravando = true;
    sessao.start();
  }
  export let mode = "reply";
  export let body = "";
  /**
   * Ditar a resposta, que no aplicativo e um dos dois caminhos do VoiceComposer.
   *
   * O outro — gravar e enviar um audio — nao vem junto: o servidor so aceita audio em sessao
   * de WhatsApp por QR (AudioStore), e o editor do navegador nao tem caminho de upload. Fazer
   * so o botao seria desenhar algo que nao envia. O ditado nao precisa de servidor nenhum:
   * acontece no navegador e cai no mesmo campo de texto, entao ele vem agora.
   */
  let ditando = false;
  let ditado: { stop: () => void } | null = null;
  const ditadoDisponivel =
    typeof window !== "undefined" &&
    Boolean(
      (window as unknown as Record<string, unknown>).SpeechRecognition ||
        (window as unknown as Record<string, unknown>).webkitSpeechRecognition,
    );
  function alternarDitado(): void {
    if (ditando) {
      ditado?.stop();
      return;
    }
    const Reconhecimento = ((window as unknown as Record<string, unknown>)
      .SpeechRecognition ||
      (window as unknown as Record<string, unknown>)
        .webkitSpeechRecognition) as
      | (new () => {
          lang: string;
          interimResults: boolean;
          continuous: boolean;
          start: () => void;
          stop: () => void;
          onresult:
            | ((event: {
                results: ArrayLike<ArrayLike<{ transcript: string }>>;
              }) => void)
            | null;
          onend: (() => void) | null;
          onerror: (() => void) | null;
        })
      | undefined;
    if (!Reconhecimento) return;
    const sessao = new Reconhecimento();
    sessao.lang = locale;
    sessao.interimResults = false;
    sessao.continuous = true;
    // O ditado ACRESCENTA ao que ja esta escrito; trocar o campo inteiro apagaria o que a
    // pessoa digitou antes de resolver falar o resto.
    sessao.onresult = (event) => {
      let texto = "";
      for (let i = 0; i < event.results.length; i++)
        texto += event.results[i][0].transcript;
      body = (body ? body.trimEnd() + " " : "") + texto.trim();
    };
    sessao.onend = () => {
      ditando = false;
      ditado = null;
    };
    sessao.onerror = () => {
      ditando = false;
      ditado = null;
    };
    ditado = sessao;
    ditando = true;
    sessao.start();
  }
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
  let campo: HTMLTextAreaElement | null = null;
  let toolsButton: HTMLButtonElement | null = null;
  let toolsPanel: HTMLDivElement | null = null;
  let toolsOpen = false;
  let toolsLeft = 0;
  let toolsTop = 0;
  let toolsHeight = 320;
  const consulta =
    "undefined" === typeof window
      ? null
      : window.matchMedia("(max-width: 760px)");
  /**
   * Reativo, e nao lido uma vez: o mesmo componente atende telefone e desktop, e girar o aparelho
   * ou arrastar a janela atravessa o limite sem recarregar nada.
   */
  let estreita = Boolean(consulta?.matches);
  function ajustarAltura(): void {
    const field = campo;
    if (!field) return;
    const limit = Math.max(72, Math.min(168, window.innerHeight * 0.22));
    field.style.height = "0px";
    field.style.height = `${Math.min(Math.max(field.scrollHeight, 44), limit)}px`;
    field.style.overflowY = field.scrollHeight > limit ? "auto" : "hidden";
  }
  async function resizeForBody(_body: string): Promise<void> {
    await tick();
    ajustarAltura();
  }
  function positionTools(): void {
    if (!toolsButton) return;
    const rect = toolsButton.getBoundingClientRect();
    const width = Math.min(320, window.innerWidth - 24);
    toolsLeft = Math.max(
      12,
      Math.min(rect.left, window.innerWidth - width - 12),
    );
    toolsHeight = Math.max(72, Math.min(360, rect.top - 20));
    toolsTop = Math.max(
      12,
      rect.top - Math.min(toolsPanel?.scrollHeight || 320, toolsHeight) - 8,
    );
  }
  async function toggleTools(): Promise<void> {
    if (toolsOpen) {
      closeTools(true);
      return;
    }
    if (
      !note &&
      selected.channel === "whatsapp" &&
      !templatesLoading &&
      !templates.length &&
      !templateBlocked &&
      !templateError
    )
      onLoadTemplates();
    toolsOpen = true;
    positionTools();
    await tick();
    toolsPanel?.showPopover?.();
    positionTools();
    toolsPanel?.querySelector<HTMLButtonElement>("button")?.focus();
  }
  function closeTools(restoreFocus = false): void {
    toolsPanel?.hidePopover?.();
    toolsOpen = false;
    if (restoreFocus) toolsButton?.focus();
  }
  function outsideTools(event: PointerEvent): void {
    if (!toolsOpen || !(event.target instanceof Node)) return;
    if (
      !toolsPanel?.contains(event.target) &&
      !toolsButton?.contains(event.target)
    )
      closeTools();
  }
  function escapeTools(event: KeyboardEvent): void {
    if (event.key === "Escape" && toolsOpen) {
      event.preventDefault();
      closeTools(true);
    }
  }
  onMount(() => {
    const updateWidth = (event: MediaQueryListEvent) => {
      estreita = event.matches;
    };
    consulta?.addEventListener("change", updateWidth);
    let lastWidth = 0;
    const observer =
      typeof ResizeObserver === "undefined"
        ? null
        : new ResizeObserver((entries) => {
            const width = entries[0]?.contentRect.width || 0;
            if (Math.abs(width - lastWidth) > 1) {
              lastWidth = width;
              ajustarAltura();
              if (toolsOpen) positionTools();
            }
          });
    if (campo) observer?.observe(campo);
    ajustarAltura();
    return () => {
      observer?.disconnect();
      consulta?.removeEventListener("change", updateWidth);
      closeTools();
    };
  });
  let templateOpen = false;
  let templateId = "";
  let values: Record<string, string> = {};
  let templateSending = false;
  let owner = selected.id;
  $: note = mode === "note";
  /**
   * O aviao nao diz nada a leitor de tela nem a um teste, entao o rotulo que estava escrito no
   * botao continua existindo — como nome acessivel, e com as mesmas chaves de traducao.
   */
  $: sendLabel = note
    ? t("mautic.inbox.ui.add_note_344d88")
    : selected.can_take_and_reply
      ? t("mautic.inbox.ui.assign_to_me_and_send_509661")
      : t("mautic.inbox.ui.send_reply_c50a43");
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
    templateSending ||
    Boolean(templateBlocked) ||
    !selectedTemplate ||
    !selectedTemplate.supported ||
    selectedTemplate.fields.some(
      (field) => !(values[field.key] || "").trim(),
    ) ||
    selected.lifecycle === "resolved" ||
    Boolean(selected.assignee && selected.assignee.id !== currentUser);
  $: resizeForBody(body);
  $: if (selected.id !== owner) {
    owner = selected.id;
    closeTemplate();
    closeTools();
    menuValue = "";
  }
  function closeTemplate(): void {
    templateOpen = false;
    templateId = "";
    values = {};
    templateError = "";
  }
  function changeMode(nextMode: string): void {
    closeTemplate();
    closeTools();
    onMode(nextMode);
    tick().then(() => campo?.focus());
  }
  function choose(): void {
    const choice = String(menuValue);
    if (choice.startsWith("template:")) {
      templateOpen = true;
      templateId = choice.slice(9);
      values = {};
      templateError = "";
      onLoadTemplates();
    } else {
      const response = canned.find((item) => String(item.id) === choice);
      if (response && !selected.reply_blocked_reason) {
        body += `${body ? "\n" : ""}${response.body}`;
        onInput();
      }
    }
    menuValue = "";
    closeTools();
    tick().then(() => {
      if (!templateOpen) campo?.focus();
    });
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

<svelte:window
  on:pointerdown={outsideTools}
  on:keydown={escapeTools}
  on:resize={() => {
    ajustarAltura();
    if (toolsOpen) positionTools();
  }}
/>

<footer
  class="inbox-composer"
  class:note-mode={note}
  class:template-mode={templateOpen && !note}
>
  <div class="inbox-composer-top">
    <span class="inbox-composer-mode">
      <Icon name={note ? "note" : "reply"} />{note
        ? t("mautic.inbox.ui.internal_note_010aa1")
        : publicReply
          ? t("mautic.inbox.ui.public_reply_42dc43")
          : t("mautic.inbox.ui.private_reply_ecd924")}
    </span>
    <span
      id="inbox-composer-channel"
      class="inbox-secondary inbox-composer-channel"
      >{{
        whatsapp: "WhatsApp",
        instagram: "Instagram",
        facebook: "Facebook",
        webchat: "Web Chat",
      }[selected.channel] || selected.channel}</span
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
  <div class="inbox-editor-row">
    <!-- A grade do editor tem tres faixas: ferramentas, texto, enviar. Os botoes da esquerda
         ficam dentro de um so filho para continuarem cabendo na primeira. -->
    <div class="inbox-editor-tools">
      <button
        id="inbox-composer-options"
        class="inbox-icon-button inbox-composer-plus"
        type="button"
        bind:this={toolsButton}
        aria-label={t("mautic.inbox.ui.composer_options")}
        title={t("mautic.inbox.ui.composer_options")}
        aria-expanded={toolsOpen}
        aria-controls="inbox-composer-menu"
        aria-haspopup="dialog"
        popovertarget="inbox-composer-menu"
        on:click={(event) => {
          event.preventDefault();
          void toggleTools();
        }}><Icon name="plus" /></button
      >{#if audioAvailable && podeGravar}<button
          id="inbox-composer-audio"
          class="inbox-icon-button inbox-composer-audio"
          class:recording={gravando}
          type="button"
          aria-pressed={gravando}
          disabled={audioBusy}
          aria-label={t(
            gravando
              ? "mautic.inbox.ui.audio_stop"
              : "mautic.inbox.ui.audio_record",
          )}
          title={t(
            gravando
              ? "mautic.inbox.ui.audio_stop"
              : "mautic.inbox.ui.audio_record",
          )}
          on:click={() => void alternarGravacao()}
          ><svg viewBox="0 0 24 24" aria-hidden="true"
            >{#if gravando}<rect x="7" y="7" width="10" height="10" rx="2"
              ></rect>{:else}<rect x="9" y="3" width="6" height="11" rx="3"
              ></rect><path d="M5 11a7 7 0 0 0 14 0M12 18v3"></path>{/if}</svg
          ></button
        >{/if}{#if ditadoDisponivel}<button
          id="inbox-composer-dictate"
          class="inbox-icon-button inbox-composer-dictate"
          class:dictating={ditando}
          type="button"
          aria-pressed={ditando}
          aria-label={t("mautic.inbox.ui.dictate")}
          title={t("mautic.inbox.ui.dictate")}
          on:click={alternarDitado}
          ><svg viewBox="0 0 24 24" aria-hidden="true"
            ><rect x="9" y="3" width="6" height="11" rx="3"></rect><path
              d="M5 11a7 7 0 0 0 14 0M12 18v3"
            ></path></svg
          ></button
        >{/if}
    </div>
    <textarea
      id="inbox-composer-text"
      bind:this={campo}
      bind:value={body}
      maxlength={maximum}
      rows="1"
      placeholder={estreita
        ? t(
            note
              ? "mautic.inbox.ui.note_placeholder_short"
              : "mautic.inbox.ui.message_placeholder_short",
          )
        : note
          ? t("mautic.inbox.ui.write_a_note_visible_only_to_your_team_10eb89")
          : publicReply
            ? t("mautic.inbox.ui.write_a_public_reply_to_the_comment_251f01")
            : t("mautic.inbox.ui.write_a_private_reply_394bc1")}
      aria-label={t("mautic.inbox.ui.reply_text_680d6f")}
      on:input={() => {
        ajustarAltura();
        onInput();
      }}
      on:keydown={(event) => {
        if (
          (event.metaKey || event.ctrlKey) &&
          event.key === "Enter" &&
          !event.isComposing &&
          !disabled
        ) {
          event.preventDefault();
          onSend();
        }
      }}
    ></textarea>
    <button
      id="inbox-send"
      class="btn btn-primary inbox-send-icon"
      type="button"
      disabled={disabled || !body.trim()}
      aria-label={sendLabel}
      title={sendLabel}
      on:click={onSend}><Icon name="send" /></button
    >
  </div>
  <div
    id="inbox-composer-menu"
    class="inbox-composer-menu"
    class:tools-open={toolsOpen}
    bind:this={toolsPanel}
    popover="auto"
    role="dialog"
    aria-label={t("mautic.inbox.ui.composer_options")}
    tabindex="-1"
    style:left={`${toolsLeft}px`}
    style:top={`${toolsTop}px`}
    style:max-height={`${toolsHeight}px`}
    on:toggle={(event) => {
      toolsOpen = (event as ToggleEvent).newState === "open";
    }}
  >
    <div class="inbox-composer-menu-heading">
      <strong>{t("mautic.inbox.ui.composer_options")}</strong>
      <button
        type="button"
        class="inbox-icon-button"
        aria-label={t("mautic.inbox.ui.close_options")}
        on:click={() => closeTools(true)}><Icon name="close" /></button
      >
    </div>
    <div class="inbox-composer-menu-modes">
      <button
        type="button"
        class:active={!note}
        aria-pressed={!note}
        on:click={() => changeMode("reply")}
        ><Icon name="reply" />{publicReply
          ? t("mautic.inbox.ui.public_reply_42dc43")
          : t("mautic.inbox.ui.private_reply_ecd924")}</button
      >
      <button
        type="button"
        class:active={note}
        aria-pressed={note}
        on:click={() => changeMode("note")}
        ><Icon name="note" />{t("mautic.inbox.ui.internal_note_010aa1")}</button
      >
    </div>
    {#if !note}
      <label class="inbox-composer-menu-responses">
        <span
          ><Icon name="bolt" />{t(
            "mautic.inbox.ui.canned_responses_f45beb",
          )}</span
        >
        <select
          id="inbox-canned"
          bind:value={menuValue}
          class="not-chosen form-control"
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
        >
      </label>
    {/if}
  </div>
  <div class="inbox-editor-status">
    <span id="inbox-draft-state"
      >{draftState ||
        t("mautic.inbox.ui.draft_saved_automatically_da72a0")}</span
    ><span>{t("mautic.inbox.ui.ctrl_enter_to_send_0d34b5")}</span>
  </div>
</footer>
