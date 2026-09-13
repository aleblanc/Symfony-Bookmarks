"use strict";

/**
 * Background entry point.
 *  - runs a sync shortly after launch (mobile browsers suspend background
 *    pages, so we can't rely on the periodic alarm alone),
 *  - schedules a periodic sync via alarms,
 *  - answers "sync now" / "test connection" messages from the popup.
 */

const ALARM_NAME = "sfb-periodic-sync";
const SYNC_PERIOD_MINUTES = 30;

let syncing = false;

async function runSync() {
  if (syncing) return { ok: false, error: "A sync is already running." };
  syncing = true;
  try {
    const report = await SfbSync.pull((m) => console.log("[sfb-sync]", m));
    return { ok: true, report };
  } catch (e) {
    console.error("[sfb-sync] failed:", e);
    await browser.storage.local.set({ lastError: String(e && e.message ? e.message : e) });
    return { ok: false, error: String(e && e.message ? e.message : e) };
  } finally {
    syncing = false;
  }
}

/** Only auto-sync once the user has configured a server URL. */
async function maybeAutoSync() {
  const cfg = await SfbApi.getConfig();
  if (cfg.baseUrl) runSync();
}

browser.alarms.create(ALARM_NAME, { periodInMinutes: SYNC_PERIOD_MINUTES });
browser.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === ALARM_NAME) maybeAutoSync();
});

browser.runtime.onStartup.addListener(maybeAutoSync);
browser.runtime.onInstalled.addListener(maybeAutoSync);

browser.runtime.onMessage.addListener((msg) => {
  if (msg && msg.type === "sync-now") return runSync();
  if (msg && msg.type === "test-connection") {
    return SfbApi.me().then(
      (me) => ({ ok: true, me }),
      (e) => ({ ok: false, error: String(e && e.message ? e.message : e) })
    );
  }
  return false;
});
