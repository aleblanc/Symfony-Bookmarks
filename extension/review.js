"use strict";

const $ = (sel) => document.querySelector(sel);

// Direction: "pull" (Symfony -> Firefox, default) or "push" (Firefox -> Symfony).
const DIR = new URLSearchParams(location.search).get("dir") === "push" ? "push" : "pull";

const UI = {
  pull: {
    headingKey: "review.pullHeading",
    subtitleKey: "review.pullSubtitle",
    delKey: "review.pullDelLabel",
    // pull: everything ticked by default (deleting a local favourite is low-risk).
    defaultChecked: () => true,
  },
  push: {
    headingKey: "review.pushHeading",
    subtitleKey: "review.pushSubtitle",
    delKey: "review.pushDelLabel",
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
    const prefix = SfbI18n.t(DIR === "push" ? "review.intoPush" : "review.intoPull");
    label.append(div("it-folder", prefix + item.folderPath.join(" / ")));
  }
  if (kind === "updates" && item.oldTitle && item.oldTitle !== item.title) {
    label.append(div("it-note", SfbI18n.t("review.was") + item.oldTitle));
  }
  if (kind === "updates" && item.moved) {
    label.append(
      div(
        "it-note",
        SfbI18n.t("review.moved", {
          from: item.fromPath || SfbI18n.t("review.root"),
          to: item.toPath || SfbI18n.t("review.root"),
        })
      )
    );
  }
  if (item.conflict) {
    label.append(div("it-note", SfbI18n.t("review.conflict")));
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
    li.textContent = SfbI18n.t("review.nothing");
    ul.append(li);
    return;
  }
  for (let i = 0; i < items.length; i++) ul.append(buildItem(kind, items[i], i));
}

async function init() {
  cfg = await SfbApi.getConfig();
  SfbI18n.setLang(cfg.lang || "");
  SfbI18n.apply();

  const heading = SfbI18n.t(UI[DIR].headingKey);
  document.title = heading;
  $("#heading").textContent = heading;
  $("#subtitle").textContent = SfbI18n.t(UI[DIR].subtitleKey);
  $("[data-del-label]").textContent = SfbI18n.t(UI[DIR].delKey);

  // On push, show the fallback used for links sitting at the Firefox root.
  if (DIR === "push" && cfg.baseUrl) {
    try {
      const cols = await SfbApi.collections();
      const target = cfg.pushCollectionId
        ? (Array.isArray(cols) ? cols.find((c) => c.id === cfg.pushCollectionId) : null)
        : null;
      $("#subtitle").textContent += target
        ? SfbI18n.t("review.rootLinksTarget", { name: target.name })
        : SfbI18n.t("review.rootLinksDefault");
    } catch {
      /* leave the base subtitle */
    }
  }

  if (!SfbSync.bookmarksAvailable()) {
    $("#loading").hidden = true;
    const el = $("#unavailable");
    el.hidden = false;
    el.textContent = SfbI18n.t("review.unavailableAndroid");
    return;
  }
  if (!cfg.baseUrl) {
    $("#loading").hidden = true;
    const el = $("#unavailable");
    el.hidden = false;
    el.textContent = SfbI18n.t("review.noServer");
    return;
  }

  try {
    plan = DIR === "push" ? await SfbSync.computePushPlan(cfg) : await SfbSync.computePullPlan(cfg);
  } catch (e) {
    $("#loading").hidden = true;
    const el = $("#unavailable");
    el.hidden = false;
    el.textContent = SfbI18n.t("review.computeFailed", { err: e && e.message ? e.message : e });
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
  summary("", SfbI18n.t("review.applying"));
  try {
    const sel = collectSelection();
    const report = DIR === "push"
      ? await SfbSync.applyPush(sel, cfg)
      : await SfbSync.applyPull(sel, cfg);
    const removedEmpty = (report.collectionsRemoved || 0) + (report.foldersRemoved || 0);
    const extra = removedEmpty ? SfbI18n.t("review.emptyRemoved", { n: removedEmpty }) : "";
    summary("ok", SfbI18n.t("review.done", {
      created: report.created, updated: report.updated, deleted: report.deleted, extra,
    }));
    // Recompute so the lists reflect the new state.
    plan = DIR === "push" ? await SfbSync.computePushPlan(cfg) : await SfbSync.computePullPlan(cfg);
    renderGroup("adds");
    renderGroup("updates");
    renderGroup("deletes");
  } catch (e) {
    summary("err", SfbI18n.t("review.failed", { err: e && e.message ? e.message : e }));
  } finally {
    btn.disabled = false;
  }
});

init();
