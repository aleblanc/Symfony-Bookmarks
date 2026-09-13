"use strict";

const $ = (id) => document.getElementById(id);

function show(kind, msg) {
  const el = $("status");
  el.className = kind;
  el.textContent = msg;
}

async function load() {
  const cfg = await SfbApi.getConfig();
  $("baseUrl").value = cfg.baseUrl || "";
  $("username").value = cfg.username || "";
  $("password").value = cfg.password || "";
}

function readForm() {
  return {
    baseUrl: SfbApi.normaliseBase($("baseUrl").value),
    username: $("username").value.trim(),
    password: $("password").value,
  };
}

/** Validate the URL early so we fail with a clear message, not a fetch error. */
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
    const me = await SfbApi.me();
    show("ok", "Connection OK ✓\n" + JSON.stringify(me, null, 2));
  } catch (e) {
    show("err", "Failed: " + String(e && e.message ? e.message : e));
  }
});

load();
