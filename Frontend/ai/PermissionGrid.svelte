<script lang="ts">
  import type { AiAsset } from "../shared/types";

  export let assets: AiAsset[] = [];
  export let values: string[] = [];
  export let globalPermissions: string[] = [];
  export let local = false;
  export let busy = false;
  export let t: (key: string) => string;

  const supported = (asset: AiAsset): boolean =>
    ["instagram", "facebook", "whatsapp"].includes(asset.channel);
  function toggle(value: string, checked: boolean): void {
    const current = values.filter((item) => item !== value);
    values = checked ? [...current, value] : current;
  }
</script>

<div class="ai-permission-grid">
  {#each assets.filter(supported) as asset (asset.id)}
    <div class="ai-permission-card">
      <div class="ai-permission-head">
        <span class="ai-channel-icon {asset.channel}" aria-hidden="true"
          >{asset.channel.slice(0, 2)}</span
        >
        <span class="ai-channel-copy"
          ><strong>{asset.name}</strong><span>{asset.channel}</span></span
        >
      </div>
      <div class="ai-permission-options">
        {#each ["message", "comment"] as kind}
          {#if !(kind === "comment" && asset.channel === "whatsapp")}
            {@const value = `${asset.id}:${kind}`}
            {@const globallyAllowed =
              !local || globalPermissions.includes(value)}
            <label class="ai-permission-option">
              <input
                type="checkbox"
                {value}
                checked={values.includes(value)}
                disabled={busy || !globallyAllowed}
                aria-label={`${asset.name} ${kind}`}
                on:change={(event) =>
                  toggle(value, event.currentTarget.checked)}
              />
              {kind === "message" ? t("messages") : t("comments")}
              {#if !globallyAllowed}<small>{t("blocked")}</small>{/if}
            </label>
          {/if}
        {/each}
      </div>
    </div>
  {/each}
</div>
