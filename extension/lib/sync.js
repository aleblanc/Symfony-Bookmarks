"use strict";

/**
 * Phase-1 sync: one-way import, Symfony Bookmarks -> Firefox.
 *
 * Consumes the single /api/v1/tree endpoint (no pagination) and mirrors the
 * whole structure under one dedicated folder:
 *
 *   Symfony Bookmarks / <Dashboard> / <Collection> / <sub-collection> / links…
 *
 * Scope (deliberately conservative — see docs/ROADMAP.md item 6):
 *   - creates missing folders and links only,
 *   - deduplicates links by NORMALISED url (a url already bookmarked ANYWHERE
 *     in Firefox is not recreated),
 *   - never deletes or moves anything,
 *   - Symfony is the source of truth; Firefox-side edits are left untouched.
 *
 * The symfonyLinkId -> firefoxBookmarkGuid map is persisted for later phases.
 */
const SfbSync = (() => {
  const ROOT_TITLE = "Symfony Bookmarks";
  const MAP_KEY = "guidMap";

  /** Same normalisation as the app's duplicate detection: lowercase host,
   *  strip a leading www., drop a trailing slash. */
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

  /** Find (by title, among the given parent's children) or create a folder.
   *  With no parentId we look at top-level folders matching the title. */
  async function ensureFolder(parentId, title) {
    const candidates = parentId
      ? await browser.bookmarks.getChildren(parentId)
      : await browser.bookmarks.search({ title });
    const match = candidates.find((b) => !b.url && b.title === title);
    if (match) return match;
    return browser.bookmarks.create(parentId ? { parentId, title } : { title });
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

  /** Recurse a collection node: ensure its folder, import links, then children. */
  async function importCollection(node, parentFolderId, ctx) {
    const folder = await ensureFolder(parentFolderId, node.name || "Untitled");
    ctx.folders++;

    for (const link of node.links || []) {
      if (!link.url) {
        ctx.skipped++;
        continue;
      }
      const key = normaliseUrl(link.url);
      const existing = ctx.byUrl.get(key);
      if (existing) {
        if (ctx.map[link.id] !== existing.id) {
          ctx.map[link.id] = existing.id;
          ctx.linked++;
        }
        continue;
      }
      const created = await browser.bookmarks.create({
        parentId: folder.id,
        title: (link.name && link.name.trim()) || link.url,
        url: link.url,
      });
      ctx.map[link.id] = created.id;
      ctx.byUrl.set(key, created);
      ctx.created++;
    }

    for (const child of node.children || []) {
      await importCollection(child, folder.id, ctx);
    }
  }

  /**
   * Run one import. Returns a small report for the popup.
   * @param {(msg:string)=>void} [log]
   */
  async function pull(log = () => {}) {
    log("Fetching tree…");
    const data = await SfbApi.tree();
    const dashboards = (data && data.dashboards) || [];

    const root = await ensureFolder(null, ROOT_TITLE);
    const ctx = {
      byUrl: await indexExistingByUrl(),
      map: await getMap(),
      created: 0,
      linked: 0,
      skipped: 0,
      folders: 0,
    };

    for (const dash of dashboards) {
      const dashFolder = await ensureFolder(root.id, dash.name || "Dashboard");
      ctx.folders++;
      for (const col of dash.collections || []) {
        await importCollection(col, dashFolder.id, ctx);
      }
    }

    await setMap(ctx.map);
    await browser.storage.local.set({ lastSync: new Date().toISOString(), lastError: null });

    const report = {
      created: ctx.created,
      linked: ctx.linked,
      skipped: ctx.skipped,
      folders: ctx.folders,
    };
    log(`Done — ${report.created} new, ${report.linked} linked, ${report.folders} folders.`);
    return report;
  }

  return { pull, normaliseUrl, ROOT_TITLE };
})();

if (typeof module !== "undefined") module.exports = SfbSync;
