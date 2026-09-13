"use strict";

/**
 * Phase-1 sync: one-way mirror, Symfony Bookmarks -> Firefox.
 *
 * Scope (deliberately conservative — see docs/ROADMAP.md item 6):
 *   - creates missing links under a single dedicated Firefox folder,
 *   - matches existing bookmarks by NORMALISED url so re-runs don't duplicate,
 *   - never deletes or moves anything (no propagation of deletions yet),
 *   - Symfony is the source of truth; Firefox-side edits are left untouched.
 *
 * Later phases (Firefox -> Symfony, deletions, real-time events, conflicts)
 * build on the guid<->linkId map persisted in storage.local.
 */
const SfbSync = (() => {
  const FOLDER_TITLE = "Symfony Bookmarks";
  const MAP_KEY = "guidMap"; // { [symfonyLinkId]: firefoxBookmarkGuid }

  /** Same normalisation as the app's duplicate detection: lowercase host,
   *  strip a leading www., drop a trailing slash. */
  function normaliseUrl(url) {
    try {
      const u = new URL(url);
      u.hostname = u.hostname.toLowerCase().replace(/^www\./, "");
      let s = u.toString();
      // drop a single trailing slash on the path (but keep "https://host/")
      s = s.replace(/\/(?=$)/, (m, off) => (u.pathname === "/" ? "/" : ""));
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

  /** Find or create the dedicated top-level folder (under the toolbar). */
  async function ensureFolder() {
    const found = await browser.bookmarks.search({ title: FOLDER_TITLE });
    const folder = found.find((b) => !b.url); // a folder has no url
    if (folder) return folder;
    return browser.bookmarks.create({ title: FOLDER_TITLE });
  }

  /** Index every existing bookmark by normalised url -> node. */
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
   * Run one pull. Returns a small report for the popup.
   * @param {(msg:string)=>void} [log]
   */
  async function pull(log = () => {}) {
    log("Fetching links…");
    const links = await SfbApi.allLinks();
    log(`Got ${links.length} link(s). Reconciling…`);

    const folder = await ensureFolder();
    const byUrl = await indexExistingByUrl();
    const map = await getMap();

    let created = 0;
    let linked = 0;
    let skipped = 0;

    for (const link of links) {
      if (!link.url) {
        skipped++;
        continue;
      }
      const key = normaliseUrl(link.url);
      const existing = byUrl.get(key);
      if (existing) {
        // Already present in Firefox — just remember the mapping.
        if (map[link.id] !== existing.id) {
          map[link.id] = existing.id;
          linked++;
        }
        continue;
      }
      const node = await browser.bookmarks.create({
        parentId: folder.id,
        title: (link.name && link.name.trim()) || link.url,
        url: link.url,
      });
      map[link.id] = node.id;
      byUrl.set(key, node);
      created++;
    }

    await setMap(map);
    await browser.storage.local.set({ lastSync: new Date().toISOString() });

    const report = { total: links.length, created, linked, skipped };
    log(`Done — created ${created}, linked ${linked}, skipped ${skipped}.`);
    return report;
  }

  return { pull, normaliseUrl, FOLDER_TITLE };
})();

if (typeof module !== "undefined") module.exports = SfbSync;
