"use strict";

const $ = (id) => document.getElementById(id);

function msg(kind, text) {
  const el = $("msg");
  el.className = kind;
  el.textContent = text;
}

async function refreshMeta() {
  const { lastSync, lastError } = await browser.storage.local.get(["lastSync", "lastError"]);
  $("last").textContent = lastSync
    ? "Last sync: " + new Date(lastSync).toLocaleString()
    : "Never synced yet";
  if (lastError) msg("err", "Last error: " + lastError);
}

$("sync").addEventListener("click", async () => {
  const btn = $("sync");
  btn.disabled = true;
  msg("ok", "Syncing…");
  try {
    const res = await browser.runtime.sendMessage({ type: "sync-now" });
    if (res && res.ok) {
      const r = res.report;
      msg("ok", `Done ✓ ${r.created} new, ${r.linked} linked, ${r.folders} folders.`);
    } else {
      msg("err", (res && res.error) || "Sync failed.");
    }
  } catch (e) {
    msg("err", String(e && e.message ? e.message : e));
  } finally {
    btn.disabled = false;
    refreshMeta();
  }
});

$("opts").addEventListener("click", (e) => {
  e.preventDefault();
  browser.runtime.openOptionsPage();
});

refreshMeta();
