"use strict";

const $ = (sel) => document.querySelector(sel);

let cfg = null;
let plan = null;

function div(cls, text) {
  const el = document.createElement("div");
  el.className = cls;
  el.textContent = text;
  return el;
}

function buildItem(kind, item, idx) {
  const li = document.createElement("li");
  const cb = document.createElement("input");
  cb.type = "checkbox";
  cb.id = `${kind}-${idx}`;
  cb.dataset.kind = kind;
  cb.dataset.idx = String(idx);
  cb.checked = true;

  const label = document.createElement("label");
  label.htmlFor = cb.id;
  label.append(div("it-title", item.title || item.url), div("it-url", item.url));
  if (kind === "updates" && item.oldTitle && item.oldTitle !== item.title) {
    label.append(div("it-note", "was: " + item.oldTitle));
  }

  li.append(cb, label);
  return li;
}

function renderGroup(kind) {
  const box = document.querySelector(`.group[data-group="${kind}"]`);
  const ul = box.querySelector("ul");
  const items = plan[kind] || [];
  box.querySelector("[data-count]").textContent = `(${items.length})`;
  ul.replaceChildren();
  if (!items.length) {
    const li = document.createElement("li");
    li.className = "empty";
    li.textContent = "Nothing.";
    ul.append(li);
    return;
  }
  for (let i = 0; i < items.length; i++) ul.append(buildItem(kind, items[i], i));
}

async function init() {
  cfg = await SfbApi.getConfig();

  if (!SfbSync.bookmarksAvailable()) {
    $("#loading").hidden = true;
    const el = $("#unavailable");
    el.hidden = false;
    el.textContent =
      "Firefox for Android does not provide the bookmarks API, so native sync " +
      "isn't available here. Use Firefox on desktop for bookmark sync.";
    return;
  }
  if (!cfg.baseUrl) {
    $("#loading").hidden = true;
    const el = $("#unavailable");
    el.hidden = false;
    el.textContent = "No server configured yet — open the extension options first.";
    return;
  }

  try {
    plan = await SfbSync.computePullPlan(cfg);
  } catch (e) {
    $("#loading").hidden = true;
    const el = $("#unavailable");
    el.hidden = false;
    el.textContent = "Failed to compute changes: " + (e && e.message ? e.message : e);
    return;
  }

  $("#loading").hidden = true;
  $("#plan").hidden = false;
  renderGroup("adds");
  renderGroup("updates");
  renderGroup("deletes");
}

function setAll(checked) {
  document.querySelectorAll('#plan input[type="checkbox"]').forEach((c) => {
    c.checked = checked;
  });
}

function collectSelection() {
  const sel = { adds: [], updates: [], deletes: [], toLink: plan.toLink || [] };
  document.querySelectorAll('#plan input[type="checkbox"]:checked').forEach((c) => {
    const kind = c.dataset.kind;
    const idx = Number(c.dataset.idx);
    sel[kind].push(plan[kind][idx]);
  });
  return sel;
}

function summary(kind, text) {
  const el = $("#summary");
  el.className = kind;
  el.textContent = text;
}

$("#all").addEventListener("click", () => setAll(true));
$("#none").addEventListener("click", () => setAll(false));

$("#apply").addEventListener("click", async () => {
  const btn = $("#apply");
  btn.disabled = true;
  summary("", "Applying…");
  try {
    const report = await SfbSync.applyPull(collectSelection(), cfg);
    summary("ok", `Done ✓ ${report.created} added, ${report.updated} updated, ${report.deleted} removed, ${report.linked} linked.`);
    // Recompute so the lists reflect the new state.
    plan = await SfbSync.computePullPlan(cfg);
    renderGroup("adds");
    renderGroup("updates");
    renderGroup("deletes");
  } catch (e) {
    summary("err", "Failed: " + (e && e.message ? e.message : e));
  } finally {
    btn.disabled = false;
  }
});

init();
