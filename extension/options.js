"use strict";

const $ = (id) => document.getElementById(id);

function show(kind, msg, id = "status") {
  const el = $(id);
  el.className = "status " + kind;
  el.textContent = msg;
}

/** Fill the dashboard <select>, keeping "All dashboards" first. */
function fillDashboards(list, selected) {
  const sel = $("dashboard");
  sel.length = 1; // keep the "all" option
  for (const d of list) {
    const opt = document.createElement("option");
    opt.value = String(d.id);
    opt.textContent = d.name;
    sel.appendChild(opt);
  }
  sel.value = selected && [...sel.options].some((o) => o.value === selected) ? selected : "all";
}

/** Fill the push-target <select>, keeping "(Default collection)" first. */
function fillCollections(list, dashById, selected) {
  const sel = $("pushCollection");
  sel.length = 1; // keep the default option
  for (const c of list) {
    const opt = document.createElement("option");
    opt.value = String(c.id);
    const prefix = dashById[c.dashboardId] ? dashById[c.dashboardId] + " / " : "";
    opt.textContent = prefix + c.name;
    sel.appendChild(opt);
  }
  const want = selected != null ? String(selected) : "";
  sel.value = [...sel.options].some((o) => o.value === want) ? want : "";
}

/** Load dashboards + collections from the server and populate both pickers. */
async function loadRemotePickers(cfg) {
  const [dl, cl] = await Promise.all([SfbApi.dashboards(), SfbApi.collections()]);
  if (Array.isArray(dl)) fillDashboards(dl, cfg.dashboard);
  const dashById = {};
  (Array.isArray(dl) ? dl : []).forEach((d) => (dashById[d.id] = d.name));
  if (Array.isArray(cl)) fillCollections(cl, dashById, cfg.pushCollectionId);
}

async function load() {
  const cfg = await SfbApi.getConfig();
  $("baseUrl").value = cfg.baseUrl || "";
  $("username").value = cfg.username || "";
  $("password").value = cfg.password || "";
  $("location").value = cfg.location || "menu________";
  $("wrap").checked = !!cfg.wrap;
  $("lang").value = cfg.lang || "";
  if (cfg.baseUrl) {
    try {
      await loadRemotePickers(cfg);
    } catch {
      // ignore — "Test connection" can (re)load them
      $("dashboard").value = cfg.dashboard || "all";
    }
  }
}

function readForm() {
  return {
    baseUrl: SfbApi.normaliseBase($("baseUrl").value),
    username: $("username").value.trim(),
    password: $("password").value,
    dashboard: $("dashboard").value || "all",
    location: $("location").value || "menu________",
    wrap: $("wrap").checked,
    lang: $("lang").value || "",
    pushCollectionId: $("pushCollection").value ? Number($("pushCollection").value) : null,
  };
}

function assertValidUrl(baseUrl) {
  try {
    new URL(baseUrl);
  } catch {
    throw new Error(SfbI18n.t("opt.invalidUrl"));
  }
}

$("save").addEventListener("click", async () => {
  const cfg = readForm();
  if (!cfg.baseUrl) return show("err", SfbI18n.t("opt.enterUrl"));
  try {
    assertValidUrl(cfg.baseUrl);
    await SfbApi.setConfig(cfg);
    show("ok", SfbI18n.t("opt.saved"));
  } catch (e) {
    show("err", String(e && e.message ? e.message : e));
  }
});

$("savepull").addEventListener("click", async () => {
  const cfg = readForm();
  if (!cfg.baseUrl) return show("err", SfbI18n.t("opt.enterUrl"));
  try {
    assertValidUrl(cfg.baseUrl);
    await SfbApi.setConfig(cfg);
    // Open the pull review page directly.
    await browser.tabs.create({ url: browser.runtime.getURL("review.html?dir=pull") });
  } catch (e) {
    show("err", String(e && e.message ? e.message : e));
  }
});

$("test").addEventListener("click", async () => {
  const cfg = readForm();
  if (!cfg.baseUrl) return show("err", SfbI18n.t("opt.enterUrlFirst"), "teststatus");
  try {
    assertValidUrl(cfg.baseUrl);
    await SfbApi.setConfig(cfg);
    show("ok", SfbI18n.t("opt.testing"), "teststatus");
    await SfbApi.me();
    await loadRemotePickers(cfg);
    await SfbApi.setConfig(readForm()); // persist the (re-validated) picker choices
    show("ok", SfbI18n.t("opt.connOk"), "teststatus");
  } catch (e) {
    show("err", SfbI18n.t("opt.testFailed", { err: String(e && e.message ? e.message : e) }), "teststatus");
  }
});

// Live language switch: re-localize the page immediately on change.
$("lang").addEventListener("change", () => {
  SfbI18n.setLang($("lang").value || "");
  SfbI18n.apply();
});

(async () => {
  await SfbI18n.init();
  SfbI18n.apply();
  await load();
})();
