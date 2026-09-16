<script lang="ts">
  import Icon from "../shared/Icon.svelte";
  import type { CannedResponse } from "../shared/types";
  export let canned: CannedResponse[] = [];
  export let canManage = false;
  export let isAdmin = false;
  export let aiUrl = "";
  export let soundLabel = "";
  export let soundTitle = "";
  export let soundPressed = false;
  export let t: (key: string) => string;
  export let toggleSound: () => void;
  export let saveCanned: (item: {
    id?: number;
    name: string;
    body: string;
  }) => Promise<void>;
  export let deleteCanned: (item: CannedResponse) => Promise<boolean>;
  let editing: { id?: number; name: string; body: string } | null = null;
  let feedback = "";
  let feedbackError = false;
  let saving = false;
  const open = (item?: CannedResponse) =>
    (editing = {
      id: item?.id,
      name: item?.name || "",
      body: item?.body || "",
    });
  async function save(): Promise<void> {
    if (!editing || saving) return;
    saving = true;
    feedback = "";
    try {
      await saveCanned(editing);
      editing = null;
      feedback = t("mautic.inbox.settings.canned_saved");
      feedbackError = false;
    } catch (error) {
      feedback = (error as Error).message;
      feedbackError = true;
    } finally {
      saving = false;
    }
  }
  async function remove(item: CannedResponse): Promise<void> {
    feedback = "";
    try {
      if (!(await deleteCanned(item))) return;
      editing = null;
      feedback = t("mautic.inbox.settings.canned_deleted");
      feedbackError = false;
    } catch (error) {
      feedback = (error as Error).message;
      feedbackError = true;
    }
  }
</script>

<section
  id="inbox-settings"
  class="inbox-settings"
  aria-labelledby="inbox-settings-title"
>
  <header class="inbox-settings-heading">
    <div>
      <h2 id="inbox-settings-title">{t("mautic.inbox.settings.title")}</h2>
      <p>{t("mautic.inbox.settings.subtitle")}</p>
    </div>
  </header>
  <div class="inbox-settings-grid">
    <article class="inbox-settings-card">
      <div class="inbox-settings-card-icon"><Icon name="sound" /></div>
      <div>
        <h3>{t("mautic.inbox.settings.notifications")}</h3>
        <p>{t("mautic.inbox.settings.notifications_hint")}</p>
        <button
          id="inbox-sound"
          type="button"
          class="btn btn-default btn-sm"
          aria-pressed={soundPressed}
          title={soundTitle}
          on:click={toggleSound}>{soundLabel}</button
        >
      </div>
    </article>
    {#if isAdmin}<article class="inbox-settings-card">
        <div class="inbox-settings-card-icon"><Icon name="agent" /></div>
        <div>
          <h3>{t("mautic.inbox.ai.agents")}</h3>
          <p>{t("mautic.inbox.settings.ai_hint")}</p>
          <a class="btn btn-default btn-sm" href={aiUrl}
            >{t("mautic.inbox.settings.ai_manage")}</a
          >
        </div>
      </article>{/if}
  </div>
  {#if canManage}<article class="inbox-settings-canned">
      <div class="inbox-settings-section-heading">
        <div>
          <h3>{t("mautic.inbox.ui.canned_responses_f45beb")}</h3>
          <p>{t("mautic.inbox.settings.canned_hint")}</p>
        </div>
        <button
          id="inbox-canned-new"
          class="btn btn-primary btn-sm"
          type="button"
          on:click={() => {
            feedback = "";
            open();
          }}>{t("mautic.inbox.settings.canned_new")}</button
        >
      </div>
      <div
        id="inbox-canned-feedback"
        class:error={feedbackError}
        class="inbox-settings-feedback"
        role="status"
        aria-live="polite"
      >
        {feedback}
      </div>
      <div id="inbox-canned-list" class="inbox-canned-list">
        {#if !canned.length}<p class="inbox-canned-empty">
            {t("mautic.inbox.settings.canned_empty")}
          </p>{:else}{#each canned as item (item.id)}<div
              class="inbox-canned-item"
            >
              <div>
                <strong>{item.name}</strong>
                <p>{item.body}</p>
              </div>
              <div class="inbox-canned-item-actions">
                <button
                  type="button"
                  class="btn btn-default btn-sm"
                  on:click={() => open(item)}
                  >{t("mautic.inbox.settings.canned_edit")}</button
                ><button
                  type="button"
                  class="btn btn-link btn-sm inbox-canned-delete"
                  on:click={() => void remove(item)}
                  >{t("mautic.inbox.settings.canned_delete")}</button
                >
              </div>
            </div>{/each}{/if}
      </div>
      {#if editing}<form
          id="inbox-canned-form"
          class="inbox-canned-form"
          on:submit|preventDefault={save}
        >
          <h4 id="inbox-canned-form-title">
            {editing.id
              ? t("mautic.inbox.settings.canned_edit")
              : t("mautic.inbox.settings.canned_new")}
          </h4>
          <label
            >{t("mautic.inbox.ui.name_13030d")}<input
              bind:value={editing.name}
              class="form-control"
              maxlength="100"
              required
            /></label
          ><label
            >{t("mautic.inbox.ui.reply_text_680d6f")}<textarea
              bind:value={editing.body}
              class="form-control"
              maxlength="4000"
              rows="6"
              required
            ></textarea></label
          >
          <div class="inbox-canned-form-actions">
            <button
              id="inbox-canned-save"
              class="btn btn-primary"
              type="submit"
              disabled={saving}
              >{editing.id
                ? t("mautic.inbox.settings.canned_save_changes")
                : t("mautic.inbox.ui.save_response_8d37d6")}</button
            ><button
              id="inbox-canned-cancel"
              class="btn btn-default"
              type="button"
              disabled={saving}
              on:click={() => (editing = null)}
              >{t("mautic.inbox.settings.canned_cancel")}</button
            >
          </div>
        </form>{/if}
    </article>{/if}
</section>
