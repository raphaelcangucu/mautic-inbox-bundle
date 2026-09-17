<script lang="ts">
  import { onDestroy, onMount } from "svelte";
  import { comoApp, puxarParaAtualizar } from "../shared/pullToRefresh";
  import { criarInboxStore } from "../shared/inboxStore.svelte";
  import { umPorVez } from "../shared/umPorVez";
  import Icon from "../shared/Icon.svelte";
  import Avatar from "./Avatar.svelte";
  import ConversationList from "./ConversationList.svelte";
  import Timeline from "./Timeline.svelte";
  import EmailActions from "./EmailActions.svelte";
  import ContactPanel from "./ContactPanel.svelte";
  import Composer from "./Composer.svelte";
  import SettingsView from "./SettingsView.svelte";
  import * as push from "../shared/push";
  import type { PendingMessage } from "../shared/store/types";
  import type { PushUiState } from "../shared/types";
  import AutomationView from "./AutomationView.svelte";
  import { inboxBootstrap, translator } from "../shared/bootstrap";
  import {
    endpoint,
    jsonRequest,
    RequestError,
    requestId,
  } from "../shared/api";
  import { createInboxHistory, type InboxHistory } from "../shared/history";
  import {
    createInboxAlerts,
    type AlertState,
    type InboxAlerts,
  } from "../shared/alerts";
  import type {
    AiInfo,
    CannedResponse,
    Conversation,
    TimelineItem,
    WhatsAppTemplate,
  } from "../shared/types";
  export let root: HTMLElement;
  const config = inboxBootstrap(root);
  const t = translator(config.labels);
  const csrf = root.dataset.csrf || "";
  let view = "inbox",
    queue = "all",
    lifecycle = "active",
    channel = "",
    needsResponse = false,
    search = "",
    filtersOpen = false;
  let rows: Conversation[] = [];
  let counts: Record<string, number> = { mine: 0, unassigned: 0, all: 0 };
  let next: string | null = null;
  let listLoading = true;
  let listError = "";
  let selected: Conversation | null = null;

  /**
   * O estado do inbox mora na store, e nao em variaveis soltas do componente. Os sete caminhos
   * que escreviam historico e os sete que escreviam a conversa entram todos por ela — e e por
   * isso que reabrir uma conversa passa a custar zero requisicao.
   */
  const loja = criarInboxStore({ csrf, urls: config.urls });
  /** Um take em voo por conversa. Ver umPorVez.ts: sem isto, dois envios seguidos dao 409. */
  const filaDeTake = umPorVez<void>();

  let timeline: TimelineItem[] = [];
  let older: string | null = null;
  let pendentes: PendingMessage[] = [];

  /**
   * Copia da store para o que a tela le. Chamada depois de cada escrita, de proposito.
   *
   * O caminho automatico nao existe aqui: este componente esta na sintaxe antiga, onde um `$:`
   * recompoe a partir das variaveis que o COMPILADOR enxerga, e nao dos sinais lidos em tempo
   * de execucao. Escrito como `$: timeline = loja.timelines.get(...)`, ele rodava uma vez na
   * selecao — com o historico ainda vazio — e nunca mais. O teste que monta o componente pegou
   * isso: a store guardava a mensagem e a tela ficava em branco.
   *
   * Converter as 1022 linhas para runes resolveria sozinho, e e mudanca para outro dia: seria
   * reescrever a tela que a equipe usa todo dia para ganhar o que estas tres atribuicoes ja
   * garantem.
   */
  function sincronizar(): void {
    const entrada = selected ? loja.timelines.get(selected.id) : undefined;
    timeline = entrada?.items ?? [];
    older = entrada?.older ?? null;
    pendentes = selected ? (loja.pending.get(selected.id) ?? []) : [];
  }
  let selectionRevision = 0;
  let listRevision = 0;
  let searchTimer: number | undefined;
  let mode = "reply",
    composerBody = "",
    enviandoModelo = false,
    feedback = "",
    feedbackError = false,
    feedbackTimer: number | undefined,
    draftState = "";
  let canned = config.canned.filter((item) => item.enabled !== false);
  let templates: WhatsAppTemplate[] = [];
  let templatesLoading = false;
  let templatesOwner = 0;
  let templateBlocked: string | null = null;
  let templateError = "";
  let ai: AiInfo | null = null;
  let aiRequest = 0;
  let aiRetryBusy = false;
  let aiMutationBusy = false;
  let retryBusy = new Set<number>();
  let panelOpen = true;
  let snoozeDuration = "60";
  let since = new Date().toISOString(),
    notificationCursor: number | null = null,
    updating = false,
    pendingUpdate = false,
    lastVersion: string | number | null = null;
  let history: InboxHistory;
  let alerts: InboxAlerts;
  let alertState: AlertState = {
    enabled: true,
    ready: false,
    unavailable: false,
    pending: 0,
  };
  const timers: number[] = [];
  let stream: EventSource | null = null;
  let fallbackTimer: number | undefined;
  let reconnectTimer: number | undefined;
  let liveStatusKey = "mautic.inbox.ui.connecting_dc8abc";
  let timelineRequest = 0;
  const draftCache: Record<number, Record<string, string>> = {};
  const draftTimers: Record<string, number> = {};
  const draftPending: Record<
    string,
    { id: number; mode: string; body: string }
  > = {};
  const draftWrites: Partial<Record<string, Promise<unknown>>> = {};
  const retryAttempts: Record<number, string> = {};
  let templateAttempt: { signature: string; id: string } | null = null;
  const api = <T,>(url: string, options: RequestInit = {}) =>
    jsonRequest<T>(
      url,
      csrf,
      options,
      t("mautic.inbox.ui.could_not_complete_the_action_0cb5b3"),
    );
  const url = (name: string, id?: number | string) =>
    endpoint(config.urls[name] || "", id);
  const kind = () => (view === "comments" ? "comments" : "private");
  function query(cursor?: string | null): string {
    const q = new URLSearchParams({
      queue,
      kind: kind(),
      lifecycle,
      channel,
      search: search.trim(),
      needs_response: needsResponse ? "1" : "0",
      limit: "25",
    });
    if (cursor) q.set("cursor", cursor);
    return q.toString();
  }
  async function loadList(append = false, silent = false): Promise<void> {
    const revision = ++listRevision;
    if (!silent) {
      listLoading = true;
      listError = "";
    }
    try {
      const data = await api<{
        items: Conversation[];
        next_cursor: string | null;
        counts: Record<string, number>;
      }>(`${url("list")}?${query(append ? next : null)}`);
      if (revision !== listRevision) return;
      rows = append ? [...rows, ...data.items] : data.items;
      next = data.next_cursor;
      counts = data.counts;
    } catch (error) {
      if (revision === listRevision) listError = (error as Error).message;
    } finally {
      if (revision === listRevision) listLoading = false;
    }
  }
  function clearSelection(update = true): void {
    ++selectionRevision;
    // O cache da conversa NAO e apagado: sair dela e parar de renderizar, e e justamente o que
    // faz a proxima abertura nao custar rede nenhuma.
    selected = null;
    ai = null;
    sincronizar();
    root.classList.remove("has-selection");
    if (update) history?.clear(false);
  }
  /** Tudo o que trocar de conversa muda na tela. Separado porque o cache chama primeiro. */
  function aplicarConversa(
    detail: Conversation,
    id: number,
    update: boolean,
  ): void {
    detail.drafts = { ...(detail.drafts || {}), ...(draftCache[id] || {}) };
    selected = detail;
    root.classList.add("has-selection");
    if (update) history?.open(id, false);
    sincronizar();
  }

  /**
   * Abrir uma conversa.
   *
   * Se ela ja foi aberta antes, aparece AGORA, do cache, sem rede nenhuma. Se e a primeira
   * vez, as tres chamadas — detalhe, historico e ia — saem juntas em vez de encadeadas, e a
   * conversa desenha sem esperar a ia.
   */
  async function select(id: number, update = true): Promise<void> {
    alerts?.acknowledge(id);
    const revision = ++selectionRevision;
    feedback = "";
    delete root.dataset.feedbackSource;

    const emCache = loja.conversations.get(id);
    if (emCache) aplicarConversa(emCache, id, update);

    // A troca de composer acontece uma vez so, e antes da rede: e o que evita o atendente ver
    // por um instante o rascunho da conversa anterior dentro da nova.
    mode = "reply";
    composerBody = (emCache?.drafts || draftCache[id] || {})[mode] || "";
    templates = [];
    templatesOwner = 0;
    templateBlocked = null;
    templateError = "";
    templateAttempt = null;

    const aberta = await loja.abrir(id);
    if (revision !== selectionRevision) return;
    if (aberta.error) {
      showError(aberta.error.message);
      return;
    }
    if (aberta.conversation) {
      aplicarConversa(aberta.conversation, id, update);
      composerBody = aberta.conversation.drafts?.[mode] || composerBody;
    }
    sincronizar();

    void aberta.ai.then((dados) => {
      if (dados && revision === selectionRevision) aplicarIa(dados, id);
    });

    if (matchMedia("(max-width:760px)").matches) {
      window.scrollTo(0, 0);
      document.getElementById("app-wrapper")?.scrollTo(0, 0);
    }
  }

  async function loadTimeline(
    id = selected?.id,
    revision = selectionRevision,
  ): Promise<void> {
    if (!id) return;
    const request = ++timelineRequest;
    const data = await api<{
      items: TimelineItem[];
      next_cursor: string | null;
    }>(`${url("timeline", id)}?limit=100`);
    if (
      request !== timelineRequest ||
      revision !== selectionRevision ||
      selected?.id !== id
    )
      return;
    loja.aplicarItens({
      conversationId: id,
      items: data.items,
      mode: "replace",
      cursor: data.next_cursor,
    });
    sincronizar();
  }
  async function loadOlder(): Promise<void> {
    if (!selected || !older) return;
    const id = selected.id,
      revision = selectionRevision,
      before = older;
    const data = await api<{
      items: TimelineItem[];
      next_cursor: string | null;
    }>(`${url("timeline", id)}?limit=100&before=${encodeURIComponent(before)}`);
    if (revision !== selectionRevision || selected?.id !== id) return;
    loja.aplicarItens({
      conversationId: id,
      items: data.items,
      mode: "prepend",
      cursor: data.next_cursor,
    });
    sincronizar();
  }
  async function refreshSelected(): Promise<void> {
    if (!selected) return;
    const id = selected.id,
      revision = selectionRevision;
    const detail = await api<Conversation>(url("detail", id));
    if (revision !== selectionRevision || selected?.id !== id) return;
    loja.guardarConversa(detail);
    detail.drafts = { ...(detail.drafts || {}), ...(draftCache[id] || {}) };
    selected = detail;
    await Promise.all([loadTimeline(id, revision), loadAi(id, false)]);
  }
  /**
   * Assume a conversa. LANCA quando o servidor recusa, em vez de devolver falso.
   *
   * O motivo importa: o envio otimista escreve a recusa dentro da pendente, e e ele que o
   * atendente le para saber se adianta tentar de novo. Um booleano nao carrega motivo.
   */
  async function take(): Promise<void> {
    if (!selected) throw new Error(t("mautic.inbox.ui.conversation_b70a33"));
    const id = selected.id;
    const revision = selectionRevision;
    const version = selected.version;
    try {
      const detail = await api<Conversation>(url("take", id), {
        method: "POST",
        body: JSON.stringify({ version }),
      });
      // O detalhe pos-tomada precisa entrar no cache. Sem isto, reabrir do cache traz a
      // version anterior — e version velha e o que produz 409 na proxima tomada ou transicao.
      loja.guardarConversa(detail);
      detail.drafts = { ...(detail.drafts || {}), ...(draftCache[id] || {}) };
      if (revision === selectionRevision && selected?.id === id)
        selected = detail;
      await Promise.all([loadList(false, true), loadAi(id, true)]);
    } catch (error) {
      showError((error as Error).message);
      throw error;
    }
  }

  /** Para os chamadores que so precisam do sim ou nao. O erro ja foi mostrado pelo take. */
  async function takeDeuCerto(): Promise<boolean> {
    try {
      await take();
      return true;
    } catch {
      return false;
    }
  }
  async function mutate(
    action: string,
    extra: Record<string, unknown> = {},
  ): Promise<void> {
    if (!selected) return;
    try {
      const id = selected.id,
        revision = selectionRevision,
        detail = await api<Conversation>(url("state", id), {
          method: "POST",
          body: JSON.stringify({ action, version: selected.version, ...extra }),
        });
      if (revision !== selectionRevision) return;
      loja.guardarConversa(detail);
      detail.drafts = { ...(detail.drafts || {}), ...(draftCache[id] || {}) };
      selected = detail;
      await Promise.all([
        loadList(false, true),
        loadTimeline(id, revision),
        loadAi(id, true),
      ]);
    } catch (error) {
      showError((error as Error).message);
      if (error instanceof RequestError && error.status === 409 && selected)
        void select(selected.id, false);
    }
  }
  /**
   * O que a resposta da ia muda na tela. Chamada pelo intervalo E pela abertura — que recebe a
   * ia junto com as outras duas chamadas e nao deve pedi-la de novo.
   */
  function aplicarIa(data: AiInfo, id: number): void {
    if (selected?.id !== id) return;
    ai = data;
    selected = { ...selected, version: data.version };
    if (
      data.assignment &&
      feedbackError &&
      root.dataset.feedbackSource === "ai" &&
      feedback.trim() === t("mautic.inbox.ai.validate_first").trim()
    ) {
      feedback = "";
      feedbackError = false;
      delete root.dataset.feedbackSource;
    }
  }

  async function loadAi(id = selected?.id, force = false): Promise<void> {
    if (!id) return;
    const request = ++aiRequest;
    try {
      const data = await api<AiInfo>(url("ai", id));
      if (request !== aiRequest || selected?.id !== id) return;
      aplicarIa(data, id);
    } catch (error) {
      if (request === aiRequest && force)
        showError((error as Error).message, "ai");
    }
  }
  async function assignAi(agent: string): Promise<void> {
    if (!selected || aiMutationBusy) return;
    const id = selected.id;
    aiMutationBusy = true;
    try {
      await api(url("ai", id), {
        method: "POST",
        body: JSON.stringify({ agent, version: selected.version }),
      });
      await select(id, false);
    } catch (error) {
      showError((error as Error).message, "ai");
    } finally {
      aiMutationBusy = false;
    }
  }
  async function resetAi(): Promise<void> {
    if (!selected || aiMutationBusy) return;
    const id = selected.id;
    aiMutationBusy = true;
    try {
      await api(url("ai", id), {
        method: "POST",
        body: JSON.stringify({ action: "reset", version: selected.version }),
      });
      await select(id, false);
      showSuccess(t("mautic.inbox.ai.reset_success"));
    } catch (error) {
      showError((error as Error).message, "ai");
    } finally {
      aiMutationBusy = false;
    }
  }
  async function sendAiPending(): Promise<void> {
    if (!selected || !ai?.pending_reply || aiRetryBusy || aiMutationBusy)
      return;
    aiRetryBusy = true;
    const id = selected.id;
    try {
      await api(url("aiRetry", id), {
        method: "POST",
        body: JSON.stringify({
          run_key: ai.pending_reply.run_key,
          version: selected.version,
        }),
      });
      await select(id, false);
      showSuccess(t("mautic.inbox.ai.sent_now"));
      await loadList(false, true);
    } catch (error) {
      showError((error as Error).message, "ai");
      await loadAi(id, true);
    } finally {
      aiRetryBusy = false;
    }
  }
  /** O endereco em que o atendente tocou. null enquanto a folha esta fechada. */
  let emailAberto: string | null = null;
  function showError(message: string, source = ""): void {
    feedback = message;
    feedbackError = true;
    if (source) root.dataset.feedbackSource = source;
    else delete root.dataset.feedbackSource;
  }
  function showSuccess(message: string): void {
    feedback = message;
    feedbackError = false;
    delete root.dataset.feedbackSource;
    clearTimeout(feedbackTimer);
    feedbackTimer = window.setTimeout(() => {
      feedback = "";
    }, 3500);
  }
  function changeMode(nextMode: string): void {
    if (!selected) return;
    selected.drafts = selected.drafts || {};
    selected.drafts[mode] = composerBody;
    mode = nextMode;
    composerBody = selected.drafts[mode] || "";
    draftState = "";
  }
  function draftInput(): void {
    if (!selected) return;
    const id = selected.id,
      draftMode = mode,
      key = `${id}:${draftMode}`,
      body = composerBody;
    draftCache[id] = draftCache[id] || {};
    draftCache[id][draftMode] = body;
    selected.drafts = selected.drafts || {};
    selected.drafts[draftMode] = body;
    selected = { ...selected };
    draftState = t("mautic.inbox.ui.saving_draft_1493aa");
    clearTimeout(draftTimers[key]);
    draftPending[key] = { id, mode: draftMode, body };
    draftTimers[key] = window.setTimeout(async () => {
      delete draftPending[key];
      try {
        draftWrites[key] = (draftWrites[key] || Promise.resolve())
          .catch(() => undefined)
          .then(() =>
            api(url("draft", id), {
              method: "PUT",
              body: JSON.stringify({ mode: draftMode, body }),
            }),
          );
        await draftWrites[key];
        if (selected?.id === id && mode === draftMode)
          draftState = t("mautic.inbox.ui.draft_saved_a76c6d");
      } catch {
        if (selected?.id === id && mode === draftMode)
          draftState = t("mautic.inbox.ui.could_not_save_the_draft_a70018");
      }
    }, 700);
  }
  /**
   * O envio otimista.
   *
   * A ordem e carregada de significado. A pendente entra e o composer esvazia PRIMEIRO — e o
   * ganho inteiro do desenho. So depois vem a sequencia que ja existia: cancelar o debounce do
   * rascunho, esperar o PUT em voo, assumir a conversa se preciso, e so entao despachar.
   *
   * Esperar o PUT antes do despacho nao e zelo: o servidor apaga o rascunho dentro da
   * transacao de envio, e um PUT atrasado pousando depois recria o texto ja enviado. O
   * atendente manda de novo, com identificador novo, e a mensagem sai duas vezes.
   *
   * Nao ha mais trava. Dois envios seguidos criam duas pendentes, e e o `filaDeTake` que
   * impede que eles disputem a mesma versao da conversa no take.
   */
  async function send(): Promise<void> {
    if (!selected || !composerBody.trim()) return;
    const text = composerBody.trim(),
      id = selected.id,
      sendMode = mode,
      isNote = sendMode === "note",
      key = `${id}:${sendMode}`,
      precisaAssumir = !isNote && Boolean(selected.can_take_and_reply);

    clearTimeout(draftTimers[key]);
    delete draftPending[key];
    feedback = "";

    // O rascunho e limpo junto com a bolha aparecendo, nos tres lugares em que ele vive. Se o
    // envio falhar, o texto nao se perde: ele esta dentro da pendente, e e de la que a
    // retentativa sai.
    draftCache[id] = draftCache[id] || {};
    if ((draftCache[id][sendMode] || "").trim() === text)
      draftCache[id][sendMode] = "";
    if (selected.drafts && (selected.drafts[sendMode] || "").trim() === text)
      selected.drafts[sendMode] = "";
    if (mode === sendMode && composerBody.trim() === text) composerBody = "";

    const envio = loja.sendReply(
      id,
      isNote ? "note" : "reply",
      text,
      async () => {
        if (precisaAssumir) await filaDeTake(id, () => take());
        const pendingDraft = draftWrites[key];
        if (pendingDraft) await pendingDraft.catch(() => undefined);
      },
    );

    // A pendente ja esta no estado neste ponto: `sendReply` a insere antes do primeiro await.
    sincronizar();
    await envio;
    sincronizar();
    await loadList(false, true);
  }

  /**
   * Tentar de novo a partir da bolha. O texto e a chave saem da propria pendente — reusar o
   * `requestId` e o que impede a duplicata quando o servidor ja processou e so o retorno se
   * perdeu. Uma nota nao tem chave para reusar, e por isso tentar de novo grava outra nota.
   */
  async function reenviarPendente(mensagem: PendingMessage): Promise<void> {
    feedback = "";
    const tentativa = loja.retryPending(mensagem.localId);
    sincronizar();
    await tentativa;
    sincronizar();
    await loadList(false, true);
  }

  async function retryOutbound(item: TimelineItem): Promise<void> {
    if (!selected || !item.retryable || retryBusy.has(item.id)) return;
    const id = selected.id;
    retryAttempts[item.id] = retryAttempts[item.id] || requestId();
    retryBusy = new Set(retryBusy).add(item.id);
    try {
      feedback = "";
      if (selected.can_take_and_reply && !(await takeDeuCerto())) return;
      await api(url("retry", item.id), {
        method: "POST",
        body: JSON.stringify({ request_id: retryAttempts[item.id] }),
      });
      delete retryAttempts[item.id];
      showSuccess(t("mautic.inbox.ui.sent_again"));
      await Promise.all([select(id, false), loadList(false, true)]);
    } catch (error) {
      showError((error as Error).message);
    } finally {
      const nextSet = new Set(retryBusy);
      nextSet.delete(item.id);
      retryBusy = nextSet;
    }
  }
  async function loadTemplates(): Promise<void> {
    if (!selected || (templatesOwner === selected.id && templates.length))
      return;
    const id = selected.id;
    templatesOwner = id;
    templatesLoading = true;
    try {
      const data = await api<{
        items: WhatsAppTemplate[];
        blocked_reason?: string;
      }>(url("templates", id));
      if (selected?.id !== id) return;
      templates = data.items;
      templateBlocked = data.blocked_reason || null;
    } catch (error) {
      if (selected?.id === id) templateBlocked = (error as Error).message;
    } finally {
      templatesLoading = false;
    }
  }
  async function sendTemplate(
    template: WhatsAppTemplate,
    variables: Record<string, string>,
  ): Promise<boolean> {
    if (
      !selected ||
      enviandoModelo ||
      !confirm(t("mautic.inbox.template.confirm"))
    )
      return false;
    const id = selected.id,
      payload = { template_id: template.id, variables },
      signature = JSON.stringify([id, template.id, variables]);
    if (!templateAttempt || templateAttempt.signature !== signature)
      templateAttempt = { signature, id: requestId() };
    enviandoModelo = true;
    templateError = "";
    try {
      if (!selected.assignee && !(await takeDeuCerto())) return false;
      await api(url("reply", id), {
        method: "POST",
        body: JSON.stringify({ ...payload, request_id: templateAttempt.id }),
      });
      templateAttempt = null;
      showSuccess(t("mautic.inbox.template.queued"));
      await Promise.all([
        loadTimeline(id, selectionRevision),
        loadList(false, true),
      ]);
      return true;
    } catch (error) {
      templateError = (error as Error).message;
      return false;
    } finally {
      enviandoModelo = false;
    }
  }
  async function saveCanned(item: {
    id?: number;
    name: string;
    body: string;
  }): Promise<void> {
    const data = await api<{ items: CannedResponse[] }>(
      item.id ? url("cannedItem", item.id) : url("canned"),
      {
        method: item.id ? "PUT" : "POST",
        body: JSON.stringify({ name: item.name, body: item.body }),
      },
    );
    canned = data.items.filter((value) => value.enabled !== false);
  }
  async function deleteCanned(item: CannedResponse): Promise<boolean> {
    if (!confirm(t("mautic.inbox.settings.canned_confirm_delete")))
      return false;
    const data = await api<{ items: CannedResponse[] }>(
      url("cannedItem", item.id),
      { method: "DELETE", body: "{}" },
    );
    canned = data.items.filter((value) => value.enabled !== false);
    return true;
  }
  function snooze(): void {
    const until = new Date();
    if (snoozeDuration === "tomorrow") {
      until.setDate(until.getDate() + 1);
      until.setHours(9, 0, 0, 0);
    } else until.setTime(until.getTime() + Number(snoozeDuration) * 60000);
    void mutate("snooze", { until: until.toISOString() });
  }
  function related(id: number, relatedKind: string): void {
    view = relatedKind === "comments" ? "comments" : "inbox";
    void loadList(false);
    void select(id);
  }
  async function poll(): Promise<void> {
    if (!root.isConnected) return;
    if (updating) {
      pendingUpdate = true;
      return;
    }
    updating = true;
    try {
      const selectedId = selected?.id,
        revision = selectionRevision,
        q = new URLSearchParams({ since });
      if (notificationCursor !== null)
        q.set("notification_cursor", String(notificationCursor));
      if (selectedId) q.set("state_id", String(selectedId));
      const data = await api<{
        next_since: string;
        notification_cursor: number;
        notifications: Array<{ id: number; state_id: number }>;
        has_more?: boolean;
        notifications_more?: boolean;
      }>(`${url("poll")}?${q}`);
      since = data.next_since;
      notificationCursor = data.notification_cursor;
      alerts.receive(data.notifications || []);
      if (data.has_more || data.notifications_more) pendingUpdate = true;
      await loadList(false, true);
      if (selectedId && revision === selectionRevision) await refreshSelected();
    } catch {
      /* the next event retries without interrupting the editor */
    } finally {
      updating = false;
      if (pendingUpdate) {
        pendingUpdate = false;
        void poll();
      }
    }
  }
  function setView(nextView: string): void {
    view = nextView;
    if (nextView === "inbox" || nextView === "comments") {
      clearSelection(true);
      void loadList(false);
    } else if (selected) history.clear(true);
  }
  function soundLabel(): string {
    return alertState.unavailable
      ? t("mautic.inbox.ui.sound_unavailable_029b22")
      : !alertState.enabled
        ? t("mautic.inbox.ui.sound_off_95de2d")
        : alertState.ready
          ? t("mautic.inbox.ui.sound_on_ff7013")
          : t("mautic.inbox.ui.enable_sound_e0b13b");
  }
  function soundTitle(): string {
    return alertState.enabled
      ? t(
          "mautic.inbox.ui.sound_notifications_for_new_messages_click_to_enable_or_mute_5f2dc2",
        )
      : t("mautic.inbox.ui.enable_sound_notifications_9d9271");
  }
  function startFallback(): void {
    if (!fallbackTimer)
      fallbackTimer = window.setInterval(() => {
        if (root.isConnected) void poll();
      }, 20000);
  }
  /**
   * As duas bordas da moldura: quanto do alto da tela nao pertence ao inbox, e quanto o menu de
   * rodape ocupa embaixo.
   *
   * Nenhuma das duas o CSS descobre sozinho. Em cima, /s/inbox tem uma faixa do Mautic acima do
   * app e o shell instalavel nao tem nenhuma. Embaixo, a altura do menu depende da fonte do
   * aparelho e de quantos itens ele mostra. Numero fixo errou as duas vezes, e as duas vezes o
   * erro apareceu como conteudo escondido atras do menu: no iPhone o botao de enviar inteiro,
   * de 665 a 709 contra um menu que comecava em 660; no Android dez pixels, com o menu medindo
   * 69 onde o CSS dizia 58.
   */
  /** Quanto o indicador de atualizar desceu. Zero quer dizer escondido. */
  let puxada = 0;
  let recarregando = false;
  let puxadaDispose: (() => void) | null = null;
  const LIMITE_DA_PUXADA = 72;

  function medirMoldura(): void {
    root.style.setProperty(
      "--ib-app-top",
      `${Math.max(0, Math.round(root.getBoundingClientRect().top + window.scrollY))}px`,
    );

    const menu = root.querySelector<HTMLElement>(".inbox-tabs");
    // So quando o menu esta destacado embaixo. No desktop ele e uma faixa dentro da barra de
    // cima, ja contada pela coluna, e descontar a altura dele de novo encolheria a tela a toa.
    if (menu && "fixed" === window.getComputedStyle(menu).position) {
      root.style.setProperty("--ib-bottom-nav", `${menu.offsetHeight}px`);
    } else {
      root.style.removeProperty("--ib-bottom-nav");
    }
  }

  onMount(() => {
    root.dataset.svelteInboxMounted = "1";
    medirMoldura();
    window.addEventListener("resize", medirMoldura);
    window.addEventListener("orientationchange", medirMoldura);

    // So dentro do app instalado: no navegador o Android ja tem o gesto nativo e o Safari tem o
    // botao, e dois puxoes concorrendo no mesmo dedo e pior que nenhum.
    const soltarPuxada = comoApp()
      ? puxarParaAtualizar(root, {
          limite: LIMITE_DA_PUXADA,
          aoMover: (distancia) => (puxada = distancia),
          aoSoltar: () => {
            recarregando = true;
            puxada = LIMITE_DA_PUXADA;
            // Recarrega a pagina inteira, que e o que traz o pacote novo: a etiqueta de versao
            // do shell vem da data dos arquivos compilados, entao o navegador nao reusa o velho.
            window.location.reload();
          },
        })
      : () => undefined;
    puxadaDispose = soltarPuxada;
    panelOpen = window.innerWidth >= 1200;
    history = createInboxHistory(
      config.urls.index,
      config.urls.conversation,
      (id) => void select(id, false),
      () => clearSelection(false),
    );
    alerts = createInboxAlerts(
      config.currentUser,
      (state) => (alertState = state),
    );
    const unlock = (event: Event) => {
      const target = event.target;
      if (target instanceof Element && target.closest("#inbox-sound")) return;
      alerts.unlock();
    };
    root.addEventListener("pointerdown", unlock);
    root.addEventListener("keydown", unlock);
    void loadList(false);
    const initial = config.initialStateId || history.current() || 0;
    if (initial > 0) void select(initial, false);
    void poll();
    timers.push(
      // O intervalo do historico saiu. Nao e suposicao: o token de versao que o SSE publica
      // soma os status de OutboundRequest e MetaMessage, entao uma entrega que muda de pending
      // para failed move o token e dispara o poll sozinha.
      //
      // O da IA FICA. O mesmo raciocinio nao transfere: AiRecord nao tem campo de status e nao
      // esta entre as seis classes do token. Tirar este faria o atendente parar de ser avisado
      // de que ha resposta de IA esperando aprovacao.
      window.setInterval(() => {
        if (selected && !document.hidden) void loadAi(selected.id, false);
      }, 1500),
    );
    if (window.EventSource && config.urls.stream) {
      stream = new EventSource(config.urls.stream);
      stream.onopen = () => {
        liveStatusKey = "mautic.inbox.ui.live_c81a1f";
        clearTimeout(reconnectTimer);
        reconnectTimer = undefined;
        clearInterval(fallbackTimer);
        fallbackTimer = undefined;
      };
      stream.addEventListener("inbox", (event) => {
        try {
          const data = JSON.parse((event as MessageEvent).data);
          if (data.version !== lastVersion) void poll();
          lastVersion = data.version;
        } catch {
          /* invalid stream event is ignored */
        }
      });
      stream.onerror = () => {
        if (!reconnectTimer)
          reconnectTimer = window.setTimeout(() => {
            reconnectTimer = undefined;
            if (stream?.readyState !== EventSource.OPEN) {
              liveStatusKey = "mautic.inbox.ui.reconnecting_5c2ce7";
              startFallback();
            }
          }, 15000);
      };
    } else {
      liveStatusKey = "mautic.inbox.ui.automatic_updates_8a484f";
      startFallback();
    }
    return () => {
      root.removeEventListener("pointerdown", unlock);
      root.removeEventListener("keydown", unlock);
    };
  });
  onDestroy(() => {
    puxadaDispose?.();
    window.removeEventListener("resize", medirMoldura);
    window.removeEventListener("orientationchange", medirMoldura);
    root.style.removeProperty("--ib-app-top");
    root.style.removeProperty("--ib-bottom-nav");
    root.classList.remove("has-selection");
    root.removeAttribute("data-svelte-inbox-mounted");
    delete root.dataset.feedbackSource;
    history?.dispose();
    alerts?.dispose();
    stream?.close();
    timers.forEach(clearInterval);
    Object.values(draftTimers).forEach(clearTimeout);
    Object.entries(draftPending).forEach(([key, pending]) => {
      draftWrites[key] = (draftWrites[key] || Promise.resolve())
        .catch(() => undefined)
        .then(() =>
          api(url("draft", pending.id), {
            method: "PUT",
            body: JSON.stringify({ mode: pending.mode, body: pending.body }),
            keepalive: true,
          }),
        )
        .catch(() => undefined);
    });
    clearTimeout(searchTimer);
    clearTimeout(feedbackTimer);
    clearTimeout(reconnectTimer);
    clearInterval(fallbackTimer);
  });
  $: if (
    selected &&
    templatesOwner !== selected.id &&
    selected.channel === "whatsapp"
  )
    void loadTemplates();

  // Notificacao do navegador. O estado real vem do servidor e do proprio navegador — nunca
  // de um palpite guardado localmente, que e como estes controles costumam mentir.
  let pushState: PushUiState = { kind: "off" };
  let pushBusy = false;

  async function refreshPush(): Promise<void> {
    if (!config.urls.pushConfig) return;
    try {
      pushState = await push.currentState(config.urls.pushConfig);
    } catch {
      pushState = { kind: "off" };
    }
  }

  async function togglePush(): Promise<void> {
    if (pushBusy) return;
    pushBusy = true;
    try {
      pushState =
        pushState.kind === "on"
          ? await push.disable(config.urls.pushSubscriptions, csrf)
          : await push.enable(
              {
                config: config.urls.pushConfig,
                subscribe: config.urls.pushSubscriptions,
              },
              csrf,
            );
    } catch (problem) {
      pushState = { kind: "off" };
      showError(problem instanceof Error ? problem.message : String(problem));
    } finally {
      pushBusy = false;
    }
  }

  void refreshPush();
</script>

{#if puxada > 0}<div
    class="inbox-pull"
    class:pronto={puxada >= LIMITE_DA_PUXADA}
    class:girando={recarregando}
    style={`transform: translateY(${puxada}px)`}
    role="status"
    aria-label={t("mautic.inbox.ui.pull_to_refresh")}
  >
    <Icon name="reload" />
  </div>{/if}
<div class="inbox-toolbar">
  <div class="inbox-product-heading">
    <span class="inbox-product-icon" data-icon="inbox"
      ><Icon name="inbox" /></span
    >
    <div>
      <strong>{t("mautic.inbox.ui.support_inbox_fff9ea")}</strong><span
        >{t("mautic.inbox.ui.shared_inbox_822665")}
        <span id="inbox-live-status">{t(liveStatusKey)}</span></span
      >
    </div>
  </div>
  <nav
    class="inbox-tabs"
    aria-label={t("mautic.inbox.ui.support_workspace_d55134")}
  >
    {#each [["inbox", "chat", "mautic.inbox.ui.conversations_86d0e6"], ["comments", "comment", "mautic.inbox.ui.comments_6fe305"], ["automation", "bolt", "mautic.inbox.ui.automations_3714d9"]] as tab}<button
        class:active={view === tab[0]}
        on:click={() => setView(tab[0])}
        ><Icon name={tab[1]} />{t(tab[2])}</button
      >{/each}<button
      class="inbox-settings-tab"
      class:active={view === "settings"}
      title={t("mautic.inbox.settings.title")}
      aria-label={t("mautic.inbox.settings.open")}
      on:click={() => setView("settings")}
      ><Icon name="settings" /><span class="sr-only"
        >{t("mautic.inbox.settings.title")}</span
      ></button
    >{#if config.standalone && config.urls.aiPage}<a
        class="inbox-tabs-link"
        href={config.urls.aiPage}
        title={t("mautic.inbox.ai.agents")}
        ><Icon name="agent" />{t("mautic.inbox.ui.agents_short")}</a
      >{/if}
  </nav>
</div>
{#each config.channelNotices as notice}<div
    class="inbox-channel-notice"
    role="status"
  >
    {notice}
  </div>{/each}
{#if view === "inbox" || view === "comments"}<div
    class="inbox-workspace"
    data-inbox-only
  >
    <ConversationList
      bind:queue
      bind:lifecycle
      bind:channel
      bind:needsResponse
      bind:search
      {rows}
      {selected}
      {counts}
      total={counts[queue] || rows.length}
      loading={listLoading}
      error={listError}
      {next}
      bind:filtersOpen
      {view}
      locale={config.locale}
      {t}
      onSelect={(id) => void select(id)}
      onQueue={(value) => {
        queue = value;
        next = null;
        void loadList(false);
      }}
      onFilters={() => (filtersOpen = !filtersOpen)}
      onFilter={() => void loadList(false)}
      onSearch={() => {
        clearTimeout(searchTimer);
        searchTimer = window.setTimeout(() => void loadList(false), 350);
      }}
      onMore={() => void loadList(true)}
    />
    <main class="inbox-thread">
      {#if !selected}<div id="inbox-empty" class="inbox-empty">
          <div class="inbox-empty-symbol" data-icon="chat">
            <Icon name="chat" />
          </div>
          <h3>
            {t(
              "mautic.inbox.ui.your_support_conversations_in_one_place_b05a85",
            )}
          </h3>
          <p>
            {t(
              "mautic.inbox.ui.select_a_conversation_to_view_its_history_a1750d",
            )}<br />{t(
              "mautic.inbox.ui.and_continue_helping_the_customer_ae8878",
            )}
          </p>
        </div>{:else}<div id="inbox-selected">
          <header class="inbox-thread-header">
            <button
              id="inbox-back"
              class="inbox-icon-button"
              aria-label={t("mautic.inbox.ui.back_to_conversations_5a51a8")}
              on:click={() => clearSelection(true)}><Icon name="back" /></button
            ><Avatar
              elementId="inbox-header-avatar"
              name={selected.contact_name}
              id={selected.id}
              photo={selected.avatar_url || null}
              photoLabel={t("mautic.inbox.ui.photo_of_5bece3")}
            />
            <div class="inbox-thread-identity">
              <h3 id="inbox-contact-name">{selected.contact_name}</h3>
              <p
                id="inbox-context"
                title={`${selected.asset.name} · ${selected.recipient}`}
              >
                {selected.conversation_kind || ""} · {{
                  whatsapp: "WhatsApp",
                  instagram: "Instagram",
                  facebook: "Facebook",
                }[selected.channel] || selected.channel} · {selected.asset
                  .handle
                  ? `@${selected.asset.handle}`
                  : selected.asset.phone ||
                    selected.asset.name}{selected.assignee
                  ? ` · ${selected.assignee.name}`
                  : t("mautic.inbox.ui.unassigned_3986c8")}
              </p>
            </div>
            {#if ai?.assignment}<div
                id="inbox-ai-header"
                class="inbox-ai-header"
                class:processing={ai.assignment.processing}
                class:paused={ai.assignment.status === "paused"}
                role="status"
              >
                <span class="inbox-ai-live-dot"></span><span
                  ><strong id="inbox-ai-header-name"
                    >{ai.assignment.name}</strong
                  ><small id="inbox-ai-header-status"
                    >{ai.assignment.status_label}</small
                  ></span
                >
              </div>{/if}
            <div class="inbox-actions">
              {#if !selected.assignee}<button
                  id="inbox-take"
                  class="btn btn-primary btn-sm"
                  on:click={() => void takeDeuCerto()}
                  >{t("mautic.inbox.ui.assign_to_me_96f796")}</button
                >{/if}{#if selected.lifecycle !== "resolved"}<button
                  id="inbox-resolve"
                  class="btn btn-primary btn-sm"
                  on:click={() => void mutate("resolve")}
                  >{t("mautic.inbox.ui.resolve_3628ac")}</button
                >{:else}<button
                  id="inbox-reopen"
                  class="btn btn-default btn-sm"
                  on:click={() => void mutate("reopen")}
                  >{t("mautic.inbox.ui.reopen_3a7b78")}</button
                >{/if}<button
                id="inbox-contact-toggle"
                class="inbox-icon-button"
                aria-expanded={panelOpen}
                title={t("mautic.inbox.ui.contact_details_1ee714")}
                aria-label={t("mautic.inbox.ui.contact_details_1ee714")}
                on:click={() => (panelOpen = !panelOpen)}
                ><Icon name="panel" /></button
              >
            </div>
          </header>
          <div class="inbox-thread-body">
            <Timeline
              {selected}
              items={timeline}
              {older}
              locale={config.locale}
              {t}
              onOlder={loadOlder}
              retry={(item) => void retryOutbound(item)}
              {retryBusy}
              pendingMessages={pendentes}
              onRetryPending={(mensagem) => void reenviarPendente(mensagem)}
              pending={ai?.pending_reply || null}
              aiCanAssign={Boolean(ai?.can_assign)}
              aiRetryBusy={aiRetryBusy || aiMutationBusy}
              onAiSend={() => void sendAiPending()}
              onAiRegenerate={() => void resetAi()}
              onEmail={(endereco) => (emailAberto = endereco)}
            /><ContactPanel
              {selected}
              users={config.users}
              {ai}
              aiBusy={aiMutationBusy}
              {panelOpen}
              bind:snoozeDuration
              {t}
              aiUrl={config.urls.ai.replace(
                /\/api\/conversations\/0\/ai$/,
                "/ai",
              )}
              onTransfer={(value) =>
                value === "unassign"
                  ? void mutate("unassign")
                  : void mutate("transfer", { target_user_id: Number(value) })}
              onAiAssign={(value) => void assignAi(value)}
              onAiReset={() => void resetAi()}
              onSnooze={snooze}
              onRelated={related}
            />
          </div>
          <Composer
            {selected}
            currentUser={config.currentUser}
            bind:mode
            bind:body={composerBody}
            {feedback}
            {feedbackError}
            {draftState}
            {canned}
            {templates}
            {templatesLoading}
            {templateBlocked}
            bind:templateError
            {t}
            onMode={changeMode}
            onInput={draftInput}
            onSend={() => void send()}
            onLoadTemplates={() => void loadTemplates()}
            onTemplateSend={sendTemplate}
          />
        </div>{/if}
    </main>
  </div>
{:else if view === "automation"}<AutomationView
    rules={config.automationRules}
    {t}
  />{:else}<SettingsView
    {canned}
    canManage={config.canManageCanned}
    isAdmin={config.isAdmin}
    aiUrl={config.urls.ai.replace(/\/api\/conversations\/0\/ai$/, "/ai")}
    soundLabel={soundLabel()}
    soundTitle={soundTitle()}
    soundPressed={alertState.enabled && alertState.ready}
    {t}
    toggleSound={() => alerts.toggle()}
    {pushState}
    {pushBusy}
    {togglePush}
    {saveCanned}
    {deleteCanned}
  />{/if}

<!-- Fora do bloco de abas: a folha cobre a tela, e prende-la ao ramo da conversa a faria
     desmontar no instante em que a acao mudasse a conversa selecionada. -->
{#if null !== emailAberto && selected}<EmailActions
    email={emailAberto}
    stateId={selected.id}
    {csrf}
    optionsUrl={config.urls.emailOptions}
    applyUrl={config.urls.emailApply}
    {t}
    onClose={() => (emailAberto = null)}
    onApplied={(nome) => {
      emailAberto = null;
      showSuccess(`${t("mautic.inbox.contact.done")} · ${nome}`);
      void refreshSelected();
    }}
  />{/if}
