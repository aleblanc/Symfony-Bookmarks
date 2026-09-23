"use strict";

/**
 * Cached, read-mostly view of the Symfony bookmark tree for the mobile dashboard.
 *
 * Why a cache: on Firefox for Android the `browser.bookmarks` API does not exist,
 * so the dashboard cannot read native bookmarks — it reads OUR tree from the API
 * (`GET /api/v1/tree`) instead. To avoid a network round-trip on every open, the
 * whole tree JSON is stored in `storage.local` and served instantly, refreshed in
 * the background only when stale (stale-while-revalidate).
 *
 * Everything here is pure/deterministic except the storage + fetch wrappers, so
 * the flatten/search helpers can be unit-tested in isolation.
 *
 * Tree shape (see src/Controller/Api/TreeController.php):
 *   { dashboards: [ { id, name, color, collections: [ collection ] } ] }
 *   collection: { id, name, description, color, icon, parentId, links[], children[] }
 *   link:       { id, name, url, description, tags[], createdAt }
 */
const SfbStore = (() => {
  const TREE_KEY = "treeCache"; // { data, fetchedAt, v }
  const SCHEMA = 3; // bump when the cached shape/ordering changes → invalidates old caches
  // (v2 added favorite/clickCount/lastClickedAt; v3 needs the position-ordered tree).
  const TTL_MS = 24 * 60 * 60 * 1000; // stale after 24h — clicks are patched locally
  // in between, so the "recently/most clicked" sections stay fresh without a refetch.

  /** @returns {Promise<{data:object, fetchedAt:number}|null>} */
  async function getCached() {
    const { [TREE_KEY]: rec } = await browser.storage.local.get(TREE_KEY);
    return rec || null;
  }

  async function setCached(data) {
    const rec = { data, fetchedAt: Date.now(), v: SCHEMA };
    await browser.storage.local.set({ [TREE_KEY]: rec });
    return rec;
  }

  function isStale(rec) {
    // A cache from an older schema is always stale → forces one refetch after upgrade.
    return !rec || rec.v !== SCHEMA || Date.now() - rec.fetchedAt > TTL_MS;
  }

  /** Fetch a fresh tree from the API and persist it. */
  async function refresh() {
    const data = await SfbApi.tree(); // { dashboards: [...] }
    // Guard against a transient bad-but-200 response (empty body, proxy error page,
    // non-JSON decoded to {}) clobbering a good cache with nothing. Throwing keeps the
    // existing cache and lets the caller show an offline hint instead of an empty page.
    if (!data || !Array.isArray(data.dashboards)) {
      throw new Error("Invalid tree response (no dashboards)");
    }
    return setCached(data);
  }

  /**
   * Stale-while-revalidate: return the cached record immediately (or null when
   * nothing is cached yet). If it's missing or stale, kick off a background
   * refresh and call `onFresh(rec)` once the network lands. `onError` gets any
   * refresh failure so the UI can show an offline hint without losing the cache.
   * @returns {Promise<{data:object, fetchedAt:number}|null>}
   */
  async function load({ onFresh, onError } = {}) {
    const cached = await getCached();
    if (isStale(cached)) {
      refresh()
        .then((rec) => onFresh && onFresh(rec))
        .catch((e) => onError && onError(e));
    }
    return cached;
  }

  // ---- pure helpers over a tree object (data), all null-safe -------------------

  /**
   * Flatten every link across all dashboards into a searchable list, each link
   * annotated with its dashboard + folder path (for display and grouping).
   */
  function flattenLinks(tree) {
    const out = [];
    for (const dash of tree?.dashboards || []) {
      walkLinks(dash.collections || [], [], dash, out);
    }
    return out;
  }

  function walkLinks(collections, path, dash, out) {
    for (const col of collections) {
      const here = path.concat(col.name);
      for (const link of col.links || []) {
        out.push({
          ...link,
          dashboardId: dash.id,
          dashboardName: dash.name,
          collectionId: col.id,
          collectionName: col.name,
          folderPath: here.join(" / "),
        });
      }
      if (col.children && col.children.length) {
        walkLinks(col.children, here, dash, out);
      }
    }
  }

  /**
   * Flatten every collection into a depth-tagged list in tree order — for the
   * folder <select> in the "save this page" picker.
   */
  function flattenFolders(tree) {
    const out = [];
    for (const dash of tree?.dashboards || []) {
      walkFolders(dash.collections || [], 0, dash, out);
    }
    return out;
  }

  function walkFolders(collections, depth, dash, out) {
    for (const col of collections) {
      out.push({
        id: col.id,
        name: col.name,
        // 'BM' (and '') is the server's "no custom icon" sentinel — treat as none.
        icon: col.icon && col.icon !== "BM" ? col.icon : "",
        depth,
        dashboardId: dash.id,
        dashboardName: dash.name,
      });
      if (col.children && col.children.length) {
        walkFolders(col.children, depth + 1, dash, out);
      }
    }
  }

  /**
   * Case-insensitive AND-of-terms search over name/url/folder/tags. No network.
   * @param {Array<object>} links flattened links (from flattenLinks)
   */
  function searchLinks(links, query) {
    const q = String(query || "").trim().toLowerCase();
    if (!q) return links;
    const terms = q.split(/\s+/);
    return links.filter((l) => {
      const hay = (
        (l.name || "") +
        " " +
        (l.url || "") +
        " " +
        (l.folderPath || "") +
        " " +
        (l.tags || []).join(" ")
      ).toLowerCase();
      return terms.every((t) => hay.includes(t));
    });
  }

  // ---- home sections, computed over a flattened link list -----------------------

  const byIdDesc = (a, b) => (b.id || 0) - (a.id || 0);
  // ISO 8601 strings compare lexicographically = chronologically; null sorts last.
  const byClickedDesc = (a, b) =>
    (b.lastClickedAt || "") < (a.lastClickedAt || "")
      ? -1
      : (b.lastClickedAt || "") > (a.lastClickedAt || "")
        ? 1
        : 0;

  function favorites(flat, limit = 12) {
    return flat.filter((l) => l.favorite).sort(byIdDesc).slice(0, limit);
  }

  function recentlyClicked(flat, limit = 8) {
    return flat.filter((l) => l.lastClickedAt).sort(byClickedDesc).slice(0, limit);
  }

  function mostClicked(flat, limit = 8) {
    return flat
      .filter((l) => (l.clickCount || 0) > 0)
      .sort((a, b) => (b.clickCount || 0) - (a.clickCount || 0) || byClickedDesc(a, b))
      .slice(0, limit);
  }

  function newest(flat, limit = 8) {
    return [...flat].sort(byIdDesc).slice(0, limit);
  }

  /** All links inside a collection subtree (self + descendants), unsorted. */
  function subtreeLinks(col) {
    const out = [];
    (function walk(c) {
      for (const l of c.links || []) out.push(l);
      for (const ch of c.children || []) walk(ch);
    })(col);
    return out;
  }

  // ---- click tracking -----------------------------------------------------------

  function findLink(tree, id) {
    for (const dash of tree?.dashboards || []) {
      const hit = findLinkIn(dash.collections || [], id);
      if (hit) return hit;
    }
    return null;
  }

  function findLinkIn(collections, id) {
    for (const col of collections) {
      for (const l of col.links || []) if (Number(l.id) === id) return l;
      if (col.children && col.children.length) {
        const hit = findLinkIn(col.children, id);
        if (hit) return hit;
      }
    }
    return null;
  }

  /**
   * Record a click: patch the cache optimistically (clickCount+1, lastClickedAt=now)
   * so the sections reorder instantly, then ping the server fire-and-forget.
   */
  async function registerClick(id) {
    const cached = await getCached();
    if (cached) {
      const link = findLink(cached.data, Number(id));
      if (link) {
        link.clickCount = (link.clickCount || 0) + 1;
        link.lastClickedAt = new Date().toISOString();
        await browser.storage.local.set({ [TREE_KEY]: cached });
      }
    }
    SfbApi.registerClick(id).catch(() => {});
  }

  /** Optimistically prepend a freshly-created link into the cached tree. */
  async function addLinkToCache(collectionId, link) {
    const cached = await getCached();
    if (!cached) return;
    const col = findCollection(cached.data, Number(collectionId));
    if (!col) return;
    col.links = col.links || [];
    col.links.unshift(link);
    await browser.storage.local.set({ [TREE_KEY]: cached });
  }

  function findCollection(tree, id) {
    for (const dash of tree?.dashboards || []) {
      const hit = findIn(dash.collections || [], id);
      if (hit) return hit;
    }
    return null;
  }

  function findIn(collections, id) {
    for (const col of collections) {
      if (Number(col.id) === id) return col;
      if (col.children && col.children.length) {
        const hit = findIn(col.children, id);
        if (hit) return hit;
      }
    }
    return null;
  }

  return {
    TREE_KEY,
    TTL_MS,
    getCached,
    setCached,
    isStale,
    refresh,
    load,
    flattenLinks,
    flattenFolders,
    searchLinks,
    favorites,
    recentlyClicked,
    mostClicked,
    newest,
    subtreeLinks,
    registerClick,
    addLinkToCache,
  };
})();

if (typeof module !== "undefined") module.exports = SfbStore;
