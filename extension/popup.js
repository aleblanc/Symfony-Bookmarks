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
    ? SfbI18n.t("popup.lastSync", { date: new Date(lastSync).toLocaleString() })
    : SfbI18n.t("popup.neverSynced");
  if (lastError) msg("err", SfbI18n.t("popup.lastError", { err: lastError }));
}

// Firefox Android has no bookmarks API — disable native sync there.
const canSync = typeof browser !== "undefined" && !!browser.bookmarks;
if (!canSync) {
  $("unavailable").hidden = false;
  $("pull").disabled = true;
  $("push").disabled = true;
}

function openReview(dir) {
  browser.tabs.create({ url: browser.runtime.getURL("review.html?dir=" + dir) });
  window.close();
}

$("pull").addEventListener("click", () => openReview("pull"));
$("push").addEventListener("click", () => openReview("push"));

// Save the active tab: capture its url/title HERE (before opening a new tab makes
// save.html the active tab), then hand them to the save form as query params.
$("save").addEventListener("click", async () => {
  let params = "";
  try {
    const [tab] = await browser.tabs.query({ active: true, currentWindow: true });
    if (tab && tab.url && /^https?:/i.test(tab.url)) {
      params =
        "?url=" +
        encodeURIComponent(tab.url) +
        "&title=" +
        encodeURIComponent(tab.title || "");
    }
  } catch (e) {
    /* fall back to an empty form */
  }
  browser.tabs.create({ url: browser.runtime.getURL("save.html" + params) });
  window.close();
});

$("dashboard").addEventListener("click", () => {
  browser.tabs.create({ url: browser.runtime.getURL("dashboard.html") });
  window.close();
});

$("opts").addEventListener("click", (e) => {
  e.preventDefault();
  browser.runtime.openOptionsPage();
});

(async () => {
  await SfbI18n.init();
  SfbI18n.apply();
  refreshMeta();
})();
