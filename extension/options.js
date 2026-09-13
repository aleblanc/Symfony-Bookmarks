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

async function load() {
  const cfg = await SfbApi.getConfig();
  $("baseUrl").value = cfg.baseUrl || "";
  $("username").value = cfg.username || "";
  $("password").value = cfg.password || "";
  $("location").value = cfg.location || "menu________";
  $("wrap").checked = !!cfg.wrap;
  // Try to populate dashboards if we already have a server configured.
  if (cfg.baseUrl) {
    try {
      const list = await SfbApi.dashboards();
      if (Array.isArray(list)) fillDashboards(list, cfg.dashboard);
    } catch {
      // ignore — the user can hit "Test connection" to (re)load them
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
    const list = await SfbApi.dashboards();
    if (Array.isArray(list)) fillDashboards(list, cfg.dashboard);
    // persist the (possibly re-validated) dashboard choice
    await SfbApi.setConfig(readForm());
    show("ok", `Connection OK ✓ — ${Array.isArray(list) ? list.length : 0} dashboard(s) loaded.`);
  } catch (e) {
    show("err", "Failed: " + String(e && e.message ? e.message : e));
  }
});

load();
