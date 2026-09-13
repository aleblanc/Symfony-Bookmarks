"use strict";

const $ = (id) => document.getElementById(id);

function show(kind, msg) {
  const el = $("status");
  el.className = kind;
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
    pushCollectionId: $("pushCollection").value ? Number($("pushCollection").value) : null,
  };
}

function assertValidUrl(baseUrl) {
  try {
    new URL(baseUrl);
  } catch {
    throw new Error("Invalid server URL.");
  }
}

$("save").addEventListener("click", async () => {
  const cfg = readForm();
  if (!cfg.baseUrl) return show("err", "Please enter the server URL.");
  try {
    assertValidUrl(cfg.baseUrl);
    await SfbApi.setConfig(cfg);
    show("ok", "Saved.");
  } catch (e) {
    show("err", String(e && e.message ? e.message : e));
  }
});

$("test").addEventListener("click", async () => {
  const cfg = readForm();
  if (!cfg.baseUrl) return show("err", "Please enter the server URL first.");
  try {
    assertValidUrl(cfg.baseUrl);
    await SfbApi.setConfig(cfg);
    show("ok", "Testing…");
    await SfbApi.me();
    await loadRemotePickers(cfg);
    await SfbApi.setConfig(readForm()); // persist the (re-validated) picker choices
    show("ok", "Connection OK ✓ — dashboards and collections loaded.");
  } catch (e) {
    show("err", "Failed: " + String(e && e.message ? e.message : e));
  }
});

load();
