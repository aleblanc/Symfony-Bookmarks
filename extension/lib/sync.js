"use strict";

/**
 * Sync core — Symfony Bookmarks <-> Firefox.
 *
 * Phase 1 + 2a: one-way import (Symfony -> Firefox) with a computed diff
 * (adds / updates / deletes) that can be reviewed and applied selectively.
 * The reconciliation layer (guidMap, URL matching, diff) is shared and stays
 * relevant for the reverse direction (push) later.
 *
 * ⚠️ The browser.bookmarks API does NOT exist on Firefox for Android. Every
 * function that touches it is guarded; call `bookmarksAvailable()` first. On
 * Android the diff/apply are simply not run (the UI offers a viewer instead).
 *
 * Scope: "managed" == present in guidMap (Firefox GUID), NOT a folder — so
 * importing at the Firefox root never touches personal bookmarks.
 */
const SfbSync = (() => {
  const ROOT_TITLE = "Symfony Bookmarks";
  const MAP_KEY = "guidMap"; // { [symfonyLinkId]: firefoxBookmarkGuid }

  /** The bookmarks API is missing on Firefox Android — feature-detect it. */
  function bookmarksAvailable() {
    return typeof browser !== "undefined" && !!browser.bookmarks;
  }

  /** Same normalisation as the app's duplicate detection. */
  function normaliseUrl(url) {
    try {
      const u = new URL(url);
      u.hostname = u.hostname.toLowerCase().replace(/^www\./, "");
      let s = u.toString();
      if (u.pathname !== "/") s = s.replace(/\/$/, "");
      return s;
    } catch {
      return String(url || "").trim().toLowerCase().replace(/\/+$/, "");
    }
  }

  async function getMap() {
    const { [MAP_KEY]: m } = await browser.storage.local.get(MAP_KEY);
    return m || {};
  }
  async function setMap(m) {
    await browser.storage.local.set({ [MAP_KEY]: m });
  }

  /** Get a bookmark node by guid, or null if it no longer exists. */
  async function getNode(guid) {
    try {
      const r = await browser.bookmarks.get(guid);
      return r && r[0] ? r[0] : null;
    } catch {
      return null;
    }
  }

  /** Find (by title among a parent's children) or create a folder. */
  async function ensureFolder(parentId, title) {
    const children = await browser.bookmarks.getChildren(parentId);
    const match = children.find((b) => !b.url && b.title === title);
    if (match) return match;
    return browser.bookmarks.create({ parentId, title });
  }

  /** Ensure a nested folder path under rootId; returns the deepest folder id. */
  async function ensureFolderPath(rootId, names) {
    let parent = rootId;
    for (const name of names) {
      parent = (await ensureFolder(parent, name || "Untitled")).id;
    }
    return parent;
  }

  /** Index every existing bookmark by normalised url -> node (global dedupe). */
  async function indexExistingByUrl() {
    const tree = await browser.bookmarks.getTree();
    const byUrl = new Map();
    const walk = (nodes) => {
      for (const n of nodes) {
        if (n.url) byUrl.set(normaliseUrl(n.url), n);
        if (n.children) walk(n.children);
      }
    };
    walk(tree);
    return byUrl;
  }

  /**
   * Flatten the Symfony tree into { link, folderPath } entries, computing the
   * target Firefox folder path per config (wrap folder, per-dashboard folder,
   * nested collections).
   */
  function flattenTree(dashboards, cfg) {
    const wrap = cfg.wrap ? [ROOT_TITLE] : [];
    const multiDash = dashboards.length > 1;
    const out = [];
    const walkCol = (node, parentPath) => {
      const path = parentPath.concat(node.name || "Untitled");
      for (const link of node.links || []) out.push({ link, folderPath: path });
      for (const child of node.children || []) walkCol(child, path);
    };
    for (const dash of dashboards) {
      const dashPath = wrap.concat(multiDash ? [dash.name || "Dashboard"] : []);
      for (const col of dash.collections || []) walkCol(col, dashPath);
    }
    return out;
  }

  /**
   * Compute the pull plan (Symfony -> Firefox): what to add, update, delete.
   * Reads the whole tree once and diffs it against the current Firefox state
   * and the guidMap.
   * @returns {Promise<{adds:Array, updates:Array, deletes:Array, toLink:Array}>}
   */
  async function computePullPlan(cfg) {
    const data = await SfbApi.tree();
    let dashboards = (data && data.dashboards) || [];
    if (cfg.dashboard && cfg.dashboard !== "all") {
      dashboards = dashboards.filter((d) => String(d.id) === String(cfg.dashboard));
    }

    const flat = flattenTree(dashboards, cfg);
    const map = await getMap();
    const byUrl = await indexExistingByUrl();
    const seen = new Set();

    const adds = [];
    const updates = [];
    const toLink = []; // already in Firefox by URL — silently map, not shown

    for (const { link, folderPath } of flat) {
      seen.add(Number(link.id));
      const title = (link.name && String(link.name).trim()) || link.url;
      const guid = map[link.id];
      if (guid) {
        const node = await getNode(guid);
        if (node) {
          if (node.title !== title || node.url !== link.url) {
            updates.push({ symfonyId: link.id, guid, title, url: link.url, oldTitle: node.title });
          }
        } else {
          // mapped but the Firefox bookmark is gone → re-add
          adds.push({ symfonyId: link.id, title, url: link.url, folderPath });
        }
      } else {
        const existing = byUrl.get(normaliseUrl(link.url));
        if (existing) toLink.push({ symfonyId: link.id, guid: existing.id });
        else adds.push({ symfonyId: link.id, title, url: link.url, folderPath });
      }
    }

    const deletes = [];
    for (const [symfonyId, guid] of Object.entries(map)) {
      if (!seen.has(Number(symfonyId))) {
        const node = await getNode(guid);
        if (node) deletes.push({ symfonyId, guid, title: node.title, url: node.url });
        // node missing → mapping is stale; cleaned during apply
      }
    }

    return { adds, updates, deletes, toLink };
  }

  /**
   * Apply a (possibly filtered) pull plan to Firefox. `toLink` is always
   * committed (it only records existing matches). Returns a small report.
   */
  async function applyPull(selected, cfg) {
    const map = await getMap();
    const rootId = cfg.location || "menu________";
    let created = 0;
    let updated = 0;
    let deleted = 0;
    let linked = 0;

    for (const t of selected.toLink || []) {
      map[t.symfonyId] = t.guid;
      linked++;
    }
    for (const a of selected.adds || []) {
      const folderId = await ensureFolderPath(rootId, a.folderPath || []);
      const node = await browser.bookmarks.create({ parentId: folderId, title: a.title, url: a.url });
      map[a.symfonyId] = node.id;
      created++;
    }
    for (const u of selected.updates || []) {
      await browser.bookmarks.update(u.guid, { title: u.title, url: u.url });
      updated++;
    }
    for (const d of selected.deletes || []) {
      try {
        await browser.bookmarks.remove(d.guid);
      } catch {
        /* already gone */
      }
      delete map[d.symfonyId];
      deleted++;
    }

    await setMap(map);
    await browser.storage.local.set({ lastSync: new Date().toISOString(), lastError: null });

    return { created, updated, deleted, linked };
  }

  /**
   * Compute the push plan (Firefox -> Symfony). Phase 2b: additions only.
   * An "add" is a Firefox bookmark that is NOT mapped and whose URL is absent
   * from Symfony (any dashboard). Per decision (option B), these are listed but
   * unticked by default so personal bookmarks aren't dumped into Symfony.
   * @returns {Promise<{adds:Array, updates:Array, deletes:Array, toLink:Array}>}
   */
  async function computePushPlan(_cfg) {
    const data = await SfbApi.tree();
    const dashboards = (data && data.dashboards) || [];
    const symfonyUrls = new Set();
    const walkCols = (nodes) => {
      for (const c of nodes) {
        for (const l of c.links || []) symfonyUrls.add(normaliseUrl(l.url));
        walkCols(c.children || []);
      }
    };
    for (const d of dashboards) walkCols(d.collections || []);

    const map = await getMap();
    const mappedGuids = new Set(Object.values(map));

    const tree = await browser.bookmarks.getTree();
    const adds = [];
    const walkFf = (nodes) => {
      for (const n of nodes) {
        if (n.url && /^(https?|ftps?):/i.test(n.url)) {
          if (!mappedGuids.has(n.id) && !symfonyUrls.has(normaliseUrl(n.url))) {
            adds.push({ guid: n.id, title: n.title || n.url, url: n.url });
          }
        }
        if (n.children) walkFf(n.children);
      }
    };
    walkFf(tree);

    return { adds, updates: [], deletes: [], toLink: [] };
  }

  /** Apply a (filtered) push plan: create selected links in Symfony via the API. */
  async function applyPush(selected, cfg) {
    const map = await getMap();
    let created = 0;
    for (const a of selected.adds || []) {
      const res = await SfbApi.createLink({
        url: a.url,
        name: a.title,
        collectionId: cfg.pushCollectionId || null,
      });
      if (res && res.id) {
        map[res.id] = a.guid; // symfonyId -> firefoxGuid
        created++;
      }
    }
    await setMap(map);
    await browser.storage.local.set({ lastSync: new Date().toISOString(), lastError: null });
    return { created, updated: 0, deleted: 0, linked: 0 };
  }

  /**
   * Non-interactive full pull (used by the background alarm / startup): compute
   * the plan and apply ALL of it. Throws if the bookmarks API is unavailable.
   */
  async function pull(log = () => {}) {
    if (!bookmarksAvailable()) {
      throw new Error("The bookmarks API is unavailable (Firefox for Android). Native sync is desktop-only.");
    }
    const cfg = await SfbApi.getConfig();
    log("Computing changes…");
    const plan = await computePullPlan(cfg);
    log(`+${plan.adds.length} ~${plan.updates.length} -${plan.deletes.length}. Applying…`);
    const report = await applyPull(plan, cfg);
    log(`Done — ${report.created} new, ${report.updated} updated, ${report.deleted} removed.`);
    return report;
  }

  return {
    bookmarksAvailable,
    normaliseUrl,
    computePullPlan,
    applyPull,
    computePushPlan,
    applyPush,
    pull,
    ROOT_TITLE,
  };
})();

if (typeof module !== "undefined") module.exports = SfbSync;
