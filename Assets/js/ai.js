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
      if (m === "preview") {
        $("ai-doc-preview").replaceChildren();
        $("ai-doc-body")
          .value.split("\n")
          .forEach((line) => {
            var e = el(
              line.startsWith("# ")
                ? "h3"
                : line.startsWith("## ")
                  ? "h4"
                  : "p",
              line.replace(/^#{1,2} /, ""),
            );
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
      var table = el("table"),
        head = el("tr");
      [t("name"), t("messages"), t("comments")].forEach((x) =>
        head.appendChild(el("th", x)),
      );
      table.appendChild(head);
      data.assets.forEach((a) => {
        if (!["instagram", "facebook", "whatsapp"].includes(a.channel)) return;
        var row = el("tr");
        row.appendChild(el("td", a.channel + " · " + a.name));
        ["message", "comment"].forEach((kind) => {
          var td = el("td");
          if (kind === "comment" && a.channel === "whatsapp") {
            td.textContent = "—";
            row.appendChild(td);
            return;
          }
          var box = el("input");
          box.type = "checkbox";
          box.value = a.id + ":" + kind;
          box.checked = values.includes(box.value);
          box.setAttribute("aria-label", a.name + " " + kind);
          box.disabled = local && !data.config.permissions.includes(box.value);
          td.appendChild(box);
          if (box.disabled) td.appendChild(el("small", t("blocked")));
          row.appendChild(td);
        });
        table.appendChild(row);
      });
      target.appendChild(table);
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
          b = el("input");
        b.type = "checkbox";
        b.value = d.key;
        b.disabled = !v || v.scope === "global";
        b.checked =
          !!v && (v.scope === "global" || (a.documents || []).includes(d.key));
        b.onchange = context;
        l.appendChild(b);
        l.appendChild(
          document.createTextNode(
            " " +
              d.draft.name +
              (v ? " · v" + v.version : " · " + t("unpublished")),
          ),
        );
        $("ai-agent-docs").appendChild(l);
      });
      permissions($("ai-agent-permissions"), a.permissions || [], true);
      context();
    }
    function context() {
      var keys = Array.from(
        $("ai-agent-docs").querySelectorAll("input:checked"),
      ).map((b) => b.value);
      $("ai-agent-context").textContent = data.documents
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
    }
    function render() {
      var oldKey = doc && doc.key;
      $("ai-doc-list").replaceChildren();
      data.documents.forEach((d) => {
        var b = el("button", d.draft.name);
        b.dataset.docKey = d.key;
        b.appendChild(
          el(
            "small",
            d.published ? "v" + d.published.version : t("unpublished"),
          ),
        );
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
