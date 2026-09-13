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
  const SNAP_KEY = "snapshot"; // { [symfonyLinkId]: {url, title, updatedAt} } at last sync

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

  async function getSnap() {
    const { [SNAP_KEY]: s } = await browser.storage.local.get(SNAP_KEY);
    return s || {};
  }

  /**
   * Rebuild the snapshot from the current Symfony state (for every mapped link).
   * The snapshot is the shared baseline used to tell which side changed since the
   * last sync (Firefox edit vs Symfony edit → conflict).
   */
  async function refreshSnapshot() {
    const list = await SfbApi.listLinks();
    const map = await getMap();
    const snap = {};
    for (const l of list) {
      if (map[l.id] !== undefined) {
        snap[l.id] = { url: l.url, title: (l.name && String(l.name).trim()) || l.url, updatedAt: l.updatedAt || null };
      }
    }
    await browser.storage.local.set({ [SNAP_KEY]: snap });
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
    // folderPath = full target Firefox path (wrap + dashboard + collections);
    // collectionPath = collection names only, for detecting folder moves.
    const walkCol = (node, parentFull, parentColl) => {
      const name = node.name || "Untitled";
      const full = parentFull.concat(name);
      const coll = parentColl.concat(name);
      for (const link of node.links || []) out.push({ link, folderPath: full, collectionPath: coll });
      for (const child of node.children || []) walkCol(child, full, coll);
    };
    for (const dash of dashboards) {
      const dashFull = wrap.concat(multiDash ? [dash.name || "Dashboard"] : []);
      for (const col of dash.collections || []) walkCol(col, dashFull, []);
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

    // Current Firefox collection path (containers + wrapper + dashboard stripped)
    // of each bookmark, to detect Symfony-side folder moves.
    const stripNames = new Set([ROOT_TITLE, ...((data && data.dashboards) || []).map((d) => d.name)]);
    const toCollectionPath = (p) => {
      const a = [...p];
      while (a.length && stripNames.has(a[0])) a.shift();
      return a;
    };
    const ffColByGuid = new Map();
    const walkFfCols = (nodes, path) => {
      for (const n of nodes) {
        if (n.url) {
          ffColByGuid.set(n.id, toCollectionPath(path).join("/"));
        } else if (n.children) {
          walkFfCols(n.children, CONTAINER_IDS.has(n.id) || !n.title ? path : path.concat(n.title));
        }
      }
    };
    walkFfCols(await browser.bookmarks.getTree(), []);

    const adds = [];
    const updates = [];
    const toLink = []; // already in Firefox by URL — silently map, not shown

    for (const { link, folderPath, collectionPath } of flat) {
      seen.add(Number(link.id));
      const title = (link.name && String(link.name).trim()) || link.url;
      const guid = map[link.id];
      if (guid) {
        const node = await getNode(guid);
        if (node) {
          // Compare URLs normalised: Firefox stores a trailing slash it adds
          // itself, which would otherwise flag an endless bogus "update".
          const titleUrlChanged = node.title !== title || normaliseUrl(node.url) !== normaliseUrl(link.url);
          const symCol = (collectionPath || []).join("/");
          const moved = (ffColByGuid.get(guid) ?? symCol) !== symCol; // folder changed in Symfony
          if (titleUrlChanged || moved) {
            updates.push({
              symfonyId: link.id, guid, title, url: link.url, folderPath,
              oldTitle: node.title, moved, fromPath: ffColByGuid.get(guid) || "", toPath: symCol,
            });
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
      if (u.moved) {
        const folderId = await ensureFolderPath(rootId, u.folderPath || []);
        await browser.bookmarks.move(u.guid, { parentId: folderId });
      }
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
    await refreshSnapshot();
    await browser.storage.local.set({ lastSync: new Date().toISOString(), lastError: null });

    return { created, updated, deleted, linked };
  }

  /**
   * Compute the push plan (Firefox -> Symfony): adds + updates + deletes.
   * - add: an unmapped Firefox bookmark whose URL is absent from Symfony.
   *   Per decision (option B) these are listed but unticked by default.
   * - update: a mapped bookmark whose title/url changed on the Firefox side
   *   (vs the snapshot) and still differs from Symfony. `conflict: true` when
   *   Symfony also changed since the snapshot (updatedAt) → shown, unticked.
   * - delete: a mapped link whose Firefox bookmark no longer exists → delete in
   *   Symfony (destructive → unticked by default).
   * @returns {Promise<{adds:Array, updates:Array, deletes:Array, toLink:Array}>}
   */
  // Firefox top-level containers — their titles must NOT become collections.
  const CONTAINER_IDS = new Set([
    "root________", "menu________", "toolbar_____", "unfiled_____", "mobile______",
  ]);

  async function computePushPlan(_cfg) {
    const list = await SfbApi.listLinks(); // v2: id, url, name, updatedAt…
    const symById = new Map();
    const symUrls = new Set();
    for (const l of list) {
      symById.set(Number(l.id), l);
      symUrls.add(normaliseUrl(l.url));
    }

    // Path segments to strip from a Firefox folder path so it maps back to a
    // Symfony collection hierarchy: the "Symfony Bookmarks" wrapper and any
    // dashboard-name folder created by the pull.
    const dashList = await SfbApi.dashboards();
    const stripNames = new Set([ROOT_TITLE, ...(Array.isArray(dashList) ? dashList.map((d) => d.name) : [])]);
    const toCollectionPath = (path) => {
      const p = [...path];
      while (p.length && stripNames.has(p[0])) p.shift();
      return p;
    };

    // Full path (by names) of each Symfony collection, to detect folder moves.
    const cols = await SfbApi.collections();
    const colPathById = buildCollectionPaths(cols);

    const map = await getMap();
    const snap = await getSnap();
    const mappedGuids = new Set(Object.values(map));

    // Skip Firefox's built-in "Mozilla Firefox" folder (Get Help, Customize…).
    const IGNORE_FOLDER = /mozilla firefox/i;

    const tree = await browser.bookmarks.getTree();
    const adds = [];
    const ffByGuid = new Map(); // mapped bookmark guid -> {folderPath, title, url}
    const walkFf = (nodes, path) => {
      for (const n of nodes) {
        if (n.url) {
          if (/^(https?|ftps?):/i.test(n.url)) {
            const colPath = toCollectionPath(path);
            if (mappedGuids.has(n.id)) {
              ffByGuid.set(n.id, { folderPath: colPath, title: n.title || n.url, url: n.url });
            } else if (!symUrls.has(normaliseUrl(n.url))) {
              adds.push({ guid: n.id, title: n.title || n.url, url: n.url, folderPath: colPath });
            }
          }
        } else if (n.children) {
          if (IGNORE_FOLDER.test(n.title || "")) continue; // skip the whole subtree
          const nextPath = CONTAINER_IDS.has(n.id) || !n.title ? path : path.concat(n.title);
          walkFf(n.children, nextPath);
        }
      }
    };
    walkFf(tree, []);

    const updates = [];
    const deletes = [];
    for (const [symIdStr, guid] of Object.entries(map)) {
      const symId = Number(symIdStr);
      const sym = symById.get(symId);
      if (!sym) continue; // gone from Symfony → the pull side handles that
      const ff = ffByGuid.get(guid);
      if (!ff) {
        deletes.push({ symfonyId: symId, guid, title: sym.name || sym.url, url: sym.url, collectionId: sym.collectionId });
        continue;
      }
      const s = snap[symId];
      const symTitle = (sym.name && String(sym.name).trim()) || sym.url;
      // URLs compared normalised (Firefox adds a trailing slash on store).
      const ffChanged = s ? ff.title !== s.title || normaliseUrl(ff.url) !== normaliseUrl(s.url) : false;
      const differsFromSym = ff.title !== symTitle || normaliseUrl(ff.url) !== normaliseUrl(sym.url);
      // Folder move: the FF folder maps to a different collection path than the
      // link's current one. Skip when we can't resolve the current path.
      const currentPath = colPathById.has(sym.collectionId) ? colPathById.get(sym.collectionId) : null;
      const desiredPath = ff.folderPath.join("/");
      const moved = null !== currentPath && desiredPath !== currentPath;
      if ((ffChanged && differsFromSym) || moved) {
        const symChanged = !!(s && s.updatedAt && sym.updatedAt && sym.updatedAt > s.updatedAt);
        updates.push({
          symfonyId: symId,
          guid,
          title: ff.title,
          url: ff.url,
          folderPath: ff.folderPath,
          oldTitle: symTitle,
          conflict: symChanged,
          moved,
          fromPath: currentPath || "",
          toPath: desiredPath,
        });
      }
    }

    return { adds, updates, deletes, toLink: [] };
  }

  /** Target dashboard for push: the configured one, else Perso (1). */
  function pushDashboardId(cfg) {
    const d = cfg.dashboard;
    return d && d !== "all" && !Number.isNaN(Number(d)) ? Number(d) : 1;
  }

  /** Index existing collections by `dashboardId|parentId|name` → id. */
  function buildCollectionIndex(cols) {
    const idx = new Map();
    for (const c of Array.isArray(cols) ? cols : []) {
      idx.set(c.dashboardId + "|" + (c.parentId ?? "") + "|" + c.name, c.id);
    }
    return idx;
  }

  /** Map each collection id to its full path by names ("A/B/C"). */
  function buildCollectionPaths(cols) {
    const byId = new Map();
    for (const c of Array.isArray(cols) ? cols : []) byId.set(c.id, c);
    const paths = new Map();
    for (const c of Array.isArray(cols) ? cols : []) {
      const parts = [];
      let cur = c;
      let guard = 0;
      while (cur && guard++ < 50) {
        parts.unshift(cur.name);
        cur = null != cur.parentId ? byId.get(cur.parentId) : null;
      }
      paths.set(c.id, parts.join("/"));
    }
    return paths;
  }

  /**
   * Delete collections that became empty (no links, no children) as a result of
   * a push deletion — walking up to ancestors that empty out too. Only touches
   * the given `affected` collection ids (and their emptied ancestors), never
   * pre-existing empty collections the user may want to keep.
   */
  async function cleanupEmptyCollections(affected) {
    let removed = 0;
    let changed = true;
    while (changed && affected.size) {
      changed = false;
      const [links, cols] = await Promise.all([SfbApi.listLinks(), SfbApi.collections()]);
      const linkCount = new Map();
      for (const l of links) linkCount.set(l.collectionId, (linkCount.get(l.collectionId) || 0) + 1);
      const childCount = new Map();
      for (const c of cols) if (null != c.parentId) childCount.set(c.parentId, (childCount.get(c.parentId) || 0) + 1);
      const byId = new Map(cols.map((c) => [c.id, c]));
      for (const id of [...affected]) {
        const c = byId.get(id);
        if (!c) {
          affected.delete(id);
          continue;
        }
        if (!(linkCount.get(id) > 0) && !(childCount.get(id) > 0)) {
          try {
            await SfbApi.deleteCollection(id);
            removed++;
            changed = true;
            if (null != c.parentId) affected.add(c.parentId); // parent may now be empty
          } catch {
            /* best-effort */
          }
          affected.delete(id);
        }
      }
    }
    return removed;
  }

  /** Find-or-create the nested collection path in $dashId; returns the leaf id. */
  async function ensureCollectionPath(pathNames, dashId, index) {
    let parentId = null;
    for (const name of pathNames) {
      const key = dashId + "|" + (parentId ?? "") + "|" + name;
      let id = index.get(key);
      if (id === undefined) {
        const res = await SfbApi.createCollection({ name, parentId, dashboardId: dashId });
        id = res && res.id ? res.id : null;
        if (null != id) index.set(key, id);
      }
      if (null == id) return parentId; // creation failed → stop at current level
      parentId = id;
    }
    return parentId;
  }

  /**
   * Apply a (filtered) push plan: create/update/delete links in Symfony.
   * Additions mirror their Firefox folder path into nested Symfony collections
   * (find-or-create) under the configured dashboard; links at the Firefox root
   * fall back to the configured target collection (or the server default).
   */
  async function applyPush(selected, cfg) {
    const map = await getMap();
    const dashId = pushDashboardId(cfg);
    const index = buildCollectionIndex(await SfbApi.collections());
    let created = 0;
    let updated = 0;
    let deleted = 0;

    for (const a of selected.adds || []) {
      const path = a.folderPath || [];
      const collectionId = path.length
        ? await ensureCollectionPath(path, dashId, index)
        : cfg.pushCollectionId || null;
      const res = await SfbApi.createLink({ url: a.url, name: a.title, collectionId });
      if (res && res.id) {
        map[res.id] = a.guid; // symfonyId -> firefoxGuid
        created++;
      }
    }
    for (const u of selected.updates || []) {
      const patch = { name: u.title, url: u.url };
      // Only relocate when the folder actually changed, to avoid accidental
      // cross-dashboard moves on plain title/url edits.
      if (u.moved) {
        const path = u.folderPath || [];
        const collectionId = path.length
          ? await ensureCollectionPath(path, dashId, index)
          : cfg.pushCollectionId || null;
        if (null != collectionId) patch.collectionId = collectionId;
      }
      await SfbApi.updateLink(u.symfonyId, patch);
      updated++;
    }
    const affectedCols = new Set();
    for (const d of selected.deletes || []) {
      await SfbApi.deleteLink(d.symfonyId);
      delete map[d.symfonyId];
      if (null != d.collectionId) affectedCols.add(d.collectionId);
      deleted++;
    }

    // Remove collections emptied by those deletions (folder deletion propagates).
    const collectionsRemoved = deleted > 0 ? await cleanupEmptyCollections(affectedCols) : 0;

    await setMap(map);
    await refreshSnapshot();
    await browser.storage.local.set({ lastSync: new Date().toISOString(), lastError: null });
    return { created, updated, deleted, collectionsRemoved, linked: 0 };
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
    // Background/auto sync never deletes silently — deletions are destructive and
    // stay reserved for the manual, reviewable "Receive" flow.
    const auto = { ...plan, deletes: [] };
    log(`+${plan.adds.length} ~${plan.updates.length} (skipping ${plan.deletes.length} deletion(s)). Applying…`);
    const report = await applyPull(auto, cfg);
    if (plan.deletes.length) {
      log(`${plan.deletes.length} deletion(s) skipped — review them via "Receive".`);
    }
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
