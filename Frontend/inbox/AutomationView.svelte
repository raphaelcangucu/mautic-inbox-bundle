<script lang="ts">
  import type { AutomationRule } from "../shared/types";
  export let rules: AutomationRule[] = [];
  export let t: (key: string) => string;
</script>

<section id="inbox-automation" class="inbox-automation">
  <div class="panel panel-default">
    <div class="panel-heading">
      <h3 class="panel-title">
        {t("mautic.inbox.ui.instagram_comment_rules_b6e5bf")}
      </h3>
    </div>
    <div class="panel-body">
      <p>
        {t(
          "mautic.inbox.ui.complex_rules_remain_in_mautic_campaigns_this_page_shows_existing_7c55a6",
        )}
      </p>
      <div class="table-responsive">
        <table class="table table-hover">
          <thead
            ><tr
              ><th>{t("mautic.inbox.ui.campaign_df4fbe")}</th><th
                >{t("mautic.inbox.ui.rule_e6ee04")}</th
              ><th>{t("mautic.inbox.ui.meta_asset_d48602")}</th><th
                >{t("mautic.inbox.ui.media_01fec9")}</th
              ><th>{t("mautic.inbox.ui.status_87cf2a")}</th><th></th></tr
            ></thead
          ><tbody
            >{#each rules as rule}<tr
                ><td>{rule.campaign}</td><td>{rule.name}</td><td
                  >{rule.asset_id}</td
                ><td><code>{rule.media_id}</code></td><td
                  >{rule.published
                    ? t("mautic.inbox.ui.published_9d32b0")
                    : t("mautic.inbox.ui.paused_144ffd")}</td
                ><td
                  ><a class="btn btn-default btn-xs" href={rule.url}
                    >{t("mautic.inbox.ui.open_campaign_cde8e4")}</a
                  ></td
                ></tr
              >{:else}<tr
                ><td colspan="6" class="text-muted"
                  >{t("mautic.inbox.ui.no_comment_rules_configured_afcbbb")}</td
                ></tr
              >{/each}</tbody
          >
        </table>
      </div>
    </div>
  </div>
</section>
