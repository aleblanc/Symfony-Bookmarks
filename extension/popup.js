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

$("opts").addEventListener("click", (e) => {
  e.preventDefault();
  browser.runtime.openOptionsPage();
});

refreshMeta();
