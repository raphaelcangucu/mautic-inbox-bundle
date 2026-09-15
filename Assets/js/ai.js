(function () {
  "use strict";
  function boot() {
    var root = document.getElementById("inbox-ai");
    if (!root || root.dataset.ready) return;
    root.dataset.ready = "1";
    var labels = JSON.parse(root.dataset.labels),
      data = {},
      doc = null,
      agent = null,
      busy = false;
    var $ = (id) => root.querySelector("#" + id),
      t = (k) => labels[k] || k;
    function el(tag, text) {
      var e = document.createElement(tag);
      if (text) e.textContent = text;
      return e;
    }
    function feedback(v) {
      $("ai-feedback").textContent = v;
    }
    async function request(payload) {
      if (busy) throw Error(t("loading"));
      busy = true;
      root.querySelectorAll("button").forEach((b) => (b.disabled = true));
      try {
        var r = await fetch(payload ? root.dataset.action : root.dataset.url, {
          method: payload ? "POST" : "GET",
          credentials: "same-origin",
          headers: {
            "Content-Type": "application/json",
            "X-CSRF-Token": root.dataset.csrf,
          },
          body: payload ? JSON.stringify(payload) : undefined,
        });
        var d = await r.json();
        if (!r.ok) throw Error(d.error || r.statusText);
        return d;
      } finally {
        busy = false;
        root.querySelectorAll("button").forEach((b) => (b.disabled = false));
      }
    }
    async function load() {
      data = await request();
      render();
    }
    function tab(key) {
      root
        .querySelectorAll("[data-ai-panel]")
        .forEach((e) => (e.hidden = e.dataset.aiPanel !== key));
      root
        .querySelectorAll("[data-ai-tab]")
        .forEach((e) => e.classList.toggle("active", e.dataset.aiTab === key));
    }
    root
      .querySelectorAll("[data-ai-tab]")
      .forEach((b) => (b.onclick = () => tab(b.dataset.aiTab)));
    function pickDoc(d) {
      doc = d;
      $("ai-doc-name").value = d.draft.name;
      $("ai-doc-body").value = d.draft.body;
      $("ai-doc-scope").value = d.draft.scope;
      $("ai-doc-version").textContent = d.published
        ? t("published") + " · v" + d.published.version
        : t("unpublished");
      mode("edit");
      root
        .querySelectorAll("[data-doc-key]")
        .forEach((b) =>
          b.classList.toggle("active", b.dataset.docKey === d.key),
        );
    }
    function mode(m) {
      $("ai-doc-body").hidden = m !== "edit";
      $("ai-doc-preview").hidden = m !== "preview";
      $("ai-doc-versions").hidden = m !== "versions";
      ["edit", "preview", "versions"].forEach((name) => {
        var button = $("ai-doc-" + (name === "preview" ? "preview-button" : name === "versions" ? "versions-button" : "edit"));
        button.classList.toggle("active", name === m);
        button.setAttribute("aria-pressed", name === m ? "true" : "false");
      });
      if (m === "preview") {
        $("ai-doc-preview").replaceChildren();
        var list = null;
        $("ai-doc-body").value.split("\n").forEach((line) => {
          if (/^- /.test(line)) {
            if (!list) {
              list = el("ul");
              $("ai-doc-preview").appendChild(list);
            }
            list.appendChild(el("li", line.slice(2)));
            return;
          }
          list = null;
          if (!line.trim()) return;
          var e = el(line.startsWith("# ") ? "h3" : line.startsWith("## ") ? "h4" : "p", line.replace(/^#{1,2} /, ""));
          $("ai-doc-preview").appendChild(e);
        });
      }
      if (m === "versions") {
        $("ai-doc-versions").replaceChildren();
        (doc.versions || [])
          .slice()
          .reverse()
          .forEach((v) => {
            var row = el("p", "v" + v.version + " · " + v.date + " "),
              b = el("button", t("restore"));
            b.className = "btn btn-default";
            b.onclick = () => {
              $("ai-doc-body").value = v.body;
              $("ai-doc-name").value = v.name;
              $("ai-doc-scope").value = v.scope;
              mode("edit");
            };
            row.appendChild(b);
            $("ai-doc-versions").appendChild(row);
          });
      }
    }
    $("ai-doc-edit").onclick = () => mode("edit");
    $("ai-doc-preview-button").onclick = () => mode("preview");
    $("ai-doc-versions-button").onclick = () => mode("versions");
    $("ai-new-doc").onclick = () =>
      pickDoc({
        key: "",
        revision: 0,
        draft: { name: "novo.md", body: "# ", scope: "agent" },
        versions: [],
      });
    async function saveDoc(publish) {
      if (publish && !confirm(t("confirm"))) return;
      var r = await request({
        action: "document",
        key: doc.key,
        revision: doc.revision,
        name: $("ai-doc-name").value,
        body: $("ai-doc-body").value,
        scope: $("ai-doc-scope").value,
        publish: publish,
      });
      doc = r.result;
      await load();
      feedback(t("saved"));
    }
    function run(fn) {
      return async () => {
        try {
          feedback(t("loading"));
          await fn();
        } catch (e) {
          feedback(e.message);
        }
      };
    }
    $("ai-doc-save").onclick = run(() => saveDoc(false));
    $("ai-doc-publish").onclick = run(() => saveDoc(true));
    function permissions(target, values, local) {
      target.replaceChildren();
      var grid = el("div");
      grid.className = "ai-permission-grid";
      data.assets.forEach((a) => {
        if (!["instagram", "facebook", "whatsapp"].includes(a.channel)) return;
        var card = el("div"), head = el("div"), icon = el("span", a.channel.slice(0, 2)), copy = el("span"), options = el("div");
        card.className = "ai-permission-card";
        head.className = "ai-permission-head";
        icon.className = "ai-channel-icon " + a.channel;
        copy.className = "ai-channel-copy";
        copy.appendChild(el("strong", a.name));
        copy.appendChild(el("span", a.channel));
        head.appendChild(icon);
        head.appendChild(copy);
        options.className = "ai-permission-options";
        card.appendChild(head);
        ["message", "comment"].forEach((kind) => {
          if (kind === "comment" && a.channel === "whatsapp") return;
          var option = el("label"), box = el("input"), label = kind === "message" ? t("messages") : t("comments");
          option.className = "ai-permission-option";
          box.type = "checkbox";
          box.value = a.id + ":" + kind;
          box.checked = values.includes(box.value);
          box.setAttribute("aria-label", a.name + " " + kind);
          box.disabled = local && !data.config.permissions.includes(box.value);
          box.onchange = updateAgentSummary;
          option.appendChild(box);
          option.appendChild(document.createTextNode(label));
          if (box.disabled) option.appendChild(el("small", t("blocked")));
          options.appendChild(option);
        });
        card.appendChild(options);
        grid.appendChild(card);
      });
      target.appendChild(grid);
    }
    function checked(id) {
      return Array.from(
        $(id).querySelectorAll("input:checked:not(:disabled)"),
      ).map((b) => b.value);
    }
    function pickAgent(a) {
      agent = a;
      $("ai-agent-name").value = a.name;
      $("ai-agent-profile").value = a.profile;
      $("ai-agent-enabled").checked = a.enabled;
      $("ai-agent-docs").replaceChildren();
      data.documents.forEach((d) => {
        var v = d.published,
          l = el("label"),
          b = el("input"),
          copy = el("span"),
          scope = el("span", v && v.scope === "global" ? t("global_short") : t("agent_short"));
        l.className = "ai-doc-check";
        b.type = "checkbox";
        b.value = d.key;
        b.disabled = !v || v.scope === "global";
        b.checked =
          !!v && (v.scope === "global" || (a.documents || []).includes(d.key));
        b.onchange = context;
        l.appendChild(b);
        copy.className = "ai-doc-check-copy";
        copy.appendChild(el("strong", d.draft.name));
        copy.appendChild(el("small", v ? t("published") + " · v" + v.version : t("unpublished")));
        scope.className = "ai-scope-badge";
        l.appendChild(copy);
        l.appendChild(scope);
        $("ai-agent-docs").appendChild(l);
      });
      permissions($("ai-agent-permissions"), a.permissions || [], true);
      $("ai-agent-profile").onchange = updateAgentSummary;
      $("ai-agent-enabled").onchange = updateAgentSummary;
      $("ai-agent-name").oninput = updateAgentSummary;
      context();
    }
    function updateAgentSummary() {
      if (!agent) return;
      var enabled = $("ai-agent-enabled").checked,
        profile = $("ai-agent-profile").value,
        documentCount = $("ai-agent-docs").querySelectorAll("input:checked").length,
        permissionCount = $("ai-agent-permissions").querySelectorAll("input:checked").length;
      $("ai-agent-summary-name").textContent = $("ai-agent-name").value || t("new");
      $("ai-agent-summary-profile").textContent = profile;
      $("ai-agent-summary-docs").textContent = documentCount;
      $("ai-agent-summary-permissions").textContent = permissionCount;
      $("ai-agent-status").textContent = enabled ? t("active") : t("inactive");
      $("ai-agent-status").classList.toggle("active", enabled);
      $("ai-agent-profile-hint").textContent = profile === "macro-sports" ? t("profile_sports_hint") : t("profile_support_hint");
      $("ai-save-summary").textContent = documentCount + " " + t("documents").toLowerCase() + " · " + permissionCount + " " + t("selected").toLowerCase();
    }
    function context() {
      var keys = Array.from(
        $("ai-agent-docs").querySelectorAll("input:checked"),
      ).map((b) => b.value);
      var body = data.documents
        .filter((d) => keys.includes(d.key) && d.published)
        .map(
          (d) =>
            "# " +
            d.published.name +
            " · v" +
            d.published.version +
            "\n" +
            d.published.body,
        )
        .join("\n\n");
      $("ai-agent-context").textContent = body;
      $("ai-agent-context-size").textContent = body.length.toLocaleString() + " " + t("characters");
      updateAgentSummary();
    }
    function render() {
      var oldKey = doc && doc.key;
      $("ai-doc-list").replaceChildren();
      data.documents.forEach((d) => {
        var b = el("button"), icon = el("span", "MD"), copy = el("span"), meta = el("span"), scope = el("span", d.draft.scope === "global" ? t("global_short") : t("agent_short"));
        b.dataset.docKey = d.key;
        icon.className = "ai-doc-icon";
        copy.className = "ai-doc-copy";
        copy.appendChild(el("span", d.draft.name)).className = "ai-doc-name";
        meta.className = "ai-doc-meta";
        meta.appendChild(el("small", d.published ? "v" + d.published.version : t("unpublished")));
        scope.className = "ai-scope-badge";
        meta.appendChild(scope);
        copy.appendChild(meta);
        b.appendChild(icon);
        b.appendChild(copy);
        b.onclick = () => pickDoc(d);
        $("ai-doc-list").appendChild(b);
      });
      if (data.documents.length)
        pickDoc(
          data.documents.find((d) => d.key === oldKey) || data.documents[0],
        );
      $("ai-global-enabled").checked = data.config.enabled;
      $("ai-model").replaceChildren();
      var models = data.health.models || [
        { id: data.config.model, name: data.config.model },
      ];
      models.forEach((m) => {
        var o = el("option", m.name || m.id);
        o.value = m.id;
        $("ai-model").appendChild(o);
      });
      $("ai-model").value = data.config.model;
      permissions($("ai-global-permissions"), data.config.permissions, false);
      $("ai-health").replaceChildren();
      [
        data.installed ? t("installed") : t("missing"),
        data.health.authenticated ? t("auth") + " ✓" : t("auth") + " —",
        data.health.validated ? t("ready") : t("pending"),
        data.health.cms && data.health.cms.configured
          ? t("cms") + " · " + data.health.cms.url
          : t("cms") + " —",
      ].forEach((s) => {
        var b = el("span", s);
        b.className = "ai-badge";
        $("ai-health").appendChild(b);
      });
      $("ai-install").hidden = data.installed;
      $("ai-agent-select").replaceChildren();
      data.agents.forEach((a) => {
        var o = el("option", a.name);
        o.value = a.key;
        $("ai-agent-select").appendChild(o);
      });
      if (data.agents.length) {
        agent =
          data.agents.find((a) => agent && a.key === agent.key) ||
          data.agents[0];
        $("ai-agent-select").value = agent.key;
        pickAgent(agent);
      } else {
        $("ai-agent-summary-name").textContent = t("new");
      }
    }
    $("ai-agent-select").onchange = () =>
      pickAgent(data.agents.find((a) => a.key === $("ai-agent-select").value));
    $("ai-new-agent").onclick = () =>
      pickAgent({
        key: "",
        revision: 0,
        name: "",
        profile: "macro-support",
        enabled: false,
        documents: [],
        permissions: [],
      });
    $("ai-agent-save").onclick = run(async () => {
      await request({
        action: "agent",
        key: agent.key,
        revision: agent.revision,
        name: $("ai-agent-name").value,
        profile: $("ai-agent-profile").value,
        enabled: $("ai-agent-enabled").checked,
        documents: checked("ai-agent-docs"),
        permissions: checked("ai-agent-permissions"),
      });
      await load();
      feedback(t("saved"));
    });
    $("ai-config-save").onclick = run(async () => {
      await request({
        action: "config",
        model: $("ai-model").value,
        enabled: $("ai-global-enabled").checked,
        permissions: checked("ai-global-permissions"),
      });
      await load();
      feedback(t("saved"));
    });
    root.querySelectorAll("[data-pi-action]").forEach(
      (b) =>
        (b.onclick = run(async () => {
          if (
            ["validate", "import-auth"].includes(b.dataset.piAction) &&
            !confirm(t("confirm"))
          )
            return;
          await request({ action: b.dataset.piAction });
          await load();
          feedback(t("saved"));
        })),
    );
    $("ai-install").onclick = run(async () => {
      if (!confirm(t("confirm"))) return;
      await request({ action: "install" });
      await load();
      feedback(t("saved"));
    });
    tab("documents");
    load().catch((e) => feedback(e.message));
  }
  if (window.Mautic) window.Mautic.inboxaiOnLoad = boot;
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", boot);
  else boot();
})();
