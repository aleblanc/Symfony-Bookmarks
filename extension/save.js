"use strict";

/**
 * "Save this page" form. Prefilled from ?url=&title= when opened from the popup
 * (which reads the active tab before navigating away). The folder picker is built
 * from the cached tree so it opens instantly and works offline; saving does a
 * single POST /api/v2/links and optimistically patches the cache.
 */
const $ = (s) => document.querySelector(s);

function msg(kind, text) {
  const el = $("#msg");
  el.className = kind;
  el.textContent = text;
}

function fillFromQuery() {
  const p = new URLSearchParams(location.search);
  const url = p.get("url");
  const title = p.get("title");
  if (url) $("#url").value = url;
  if (title) $("#name").value = title;
}

async function loadFolders() {
  const sel = $("#folder");
  sel.innerHTML = "";
  const def = document.createElement("option");
  def.value = "";
  def.textContent = SfbI18n.t("save.defaultFolder");
  sel.append(def);

  // Prefer the cache; fall back to a fresh fetch if nothing is cached yet.
  let rec = await SfbStore.getCached();
  if (!rec) {
    try {
      rec = await SfbStore.refresh();
    } catch (e) {
      msg("err", SfbI18n.t("save.loadFoldersFailed", { err: e.message || e }));
      return;
    }
  }
  const folders = SfbStore.flattenFolders(rec.data);
  const multiDash =
    new Set(folders.map((f) => f.dashboardId)).size > 1;
  for (const f of folders) {
    const opt = document.createElement("option");
    opt.value = String(f.id);
    const indent = "  ".repeat(f.depth);
    const icon = f.icon ? f.icon + " " : "";
    const dash = multiDash ? `[${f.dashboardName}] ` : "";
    opt.textContent = dash + indent + icon + f.name;
    sel.append(opt);
  }
}

$("#cancel").addEventListener("click", () => {
  window.close();
});

$("#form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const url = $("#url").value.trim();
  if (!url) return;
  $("#submit").disabled = true;
  msg("", SfbI18n.t("save.saving"));
  try {
    const collectionId = $("#folder").value ? Number($("#folder").value) : null;
    const created = await SfbApi.createLink({
      url,
      name: $("#name").value.trim() || null,
      description: $("#desc").value.trim() || null,
      collectionId,
    });
    if (collectionId && created && created.id) {
      await SfbStore.addLinkToCache(collectionId, {
        id: created.id,
        name: created.name || $("#name").value.trim() || url,
        url,
        description: $("#desc").value.trim() || null,
        tags: [],
        createdAt: new Date().toISOString(),
      });
    }
    msg("ok", SfbI18n.t("save.saved"));
    setTimeout(() => window.close(), 700);
  } catch (err) {
    $("#submit").disabled = false;
    msg("err", SfbI18n.t("save.failed", { err: err.message || err }));
  }
});

async function init() {
  const cfg = await SfbApi.getConfig();
  SfbI18n.setLang(cfg.lang || "");
  SfbI18n.apply();
  if (!cfg.baseUrl) {
    msg("err", SfbI18n.t("save.notConfigured"));
    $("#submit").disabled = true;
    return;
  }
  fillFromQuery();
  await loadFolders();
}

init();
