"use strict";

const $ = (sel) => document.querySelector(sel);

// Direction: "pull" (Symfony -> Firefox, default) or "push" (Firefox -> Symfony).
const DIR = new URLSearchParams(location.search).get("dir") === "push" ? "push" : "pull";

const UI = {
  pull: {
    heading: "⬇️ Receive from Symfony Bookmarks",
    subtitle: "Review the changes to apply to your Firefox bookmarks. Untick anything you want to skip.",
    delLabel: "🗑️ To remove from Firefox",
    // pull: everything ticked by default (deleting a local favourite is low-risk).
    defaultChecked: () => true,
  },
  push: {
    heading: "⬆️ Send to Symfony Bookmarks",
    subtitle: "Firefox bookmarks not yet in Symfony. Tick the ones to send (all unticked by default).",
    delLabel: "🗑️ To delete in Symfony",
    // push: only updates ticked by default. Additions unticked (don't dump
    // personal bookmarks); deletions unticked (destructive in Symfony).
    defaultChecked: (kind) => kind === "updates",
  },
};

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
  // Conflicts (both sides changed) start unticked so the user decides.
  cb.checked = UI[DIR].defaultChecked(kind) && !item.conflict;

  const label = document.createElement("label");
  label.htmlFor = cb.id;
  label.append(div("it-title", item.title || item.url), div("it-url", item.url));
  if (Array.isArray(item.folderPath) && item.folderPath.length) {
    const prefix = DIR === "push" ? "📁 in Firefox: " : "📁 into: ";
    label.append(div("it-folder", prefix + item.folderPath.join(" / ")));
  }
  if (kind === "updates" && item.oldTitle && item.oldTitle !== item.title) {
    label.append(div("it-note", "was: " + item.oldTitle));
  }
  if (item.conflict) {
    label.append(div("it-note", "⚠ conflict — Symfony also changed since last sync"));
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

  document.title = UI[DIR].heading;
  $("#heading").textContent = UI[DIR].heading;
  $("#subtitle").textContent = UI[DIR].subtitle;
  $("[data-del-label]").textContent = UI[DIR].delLabel;

  // On push, show where new links will be created so the target is never a surprise.
  if (DIR === "push" && cfg.baseUrl) {
    try {
      const cols = await SfbApi.collections();
      const target = cfg.pushCollectionId
        ? (Array.isArray(cols) ? cols.find((c) => c.id === cfg.pushCollectionId) : null)
        : null;
      $("#subtitle").textContent += target
        ? ` New links → “${target.name}”.`
        : " New links → default collection (set a target in the options).";
    } catch {
      /* leave the base subtitle */
    }
  }

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
    plan = DIR === "push" ? await SfbSync.computePushPlan(cfg) : await SfbSync.computePullPlan(cfg);
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
    const sel = collectSelection();
    const report = DIR === "push"
      ? await SfbSync.applyPush(sel, cfg)
      : await SfbSync.applyPull(sel, cfg);
    summary("ok", `Done ✓ ${report.created} added, ${report.updated} updated, ${report.deleted} removed, ${report.linked} linked.`);
    // Recompute so the lists reflect the new state.
    plan = DIR === "push" ? await SfbSync.computePushPlan(cfg) : await SfbSync.computePullPlan(cfg);
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
