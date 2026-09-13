"use strict";

/**
 * Tiny client for the Symfony Bookmarks API (/api/v1/*).
 *
 * Auth model (v1): the whole server sits behind HTTP basic-auth, so the API
 * itself needs no token — we just attach `Authorization: Basic ...` on every
 * request from the credentials saved in the options page. No password prompt
 * ever appears because we set the header ourselves.
 *
 * The API wraps every payload in a Linkwarden-style envelope: { "response": ... }.
 */
const SfbApi = (() => {
  const CONFIG_KEY = "config";

  /**
   * @returns {Promise<{baseUrl:string, username:string, password:string,
   *   dashboard:string, location:string, wrap:boolean}>}
   * dashboard: "all" or a dashboard id (as string).
   * location: a Firefox root folder id — "menu________", "toolbar_____" or "unfiled_____".
   * wrap: put everything inside a "Symfony Bookmarks" folder instead of at the root.
   */
  async function getConfig() {
    const { [CONFIG_KEY]: cfg } = await browser.storage.local.get(CONFIG_KEY);
    return {
      baseUrl: "",
      username: "",
      password: "",
      dashboard: "all",
      location: "menu________",
      wrap: false,
      pushCollectionId: null, // target Symfony collection for push adds (null = default)
      ...(cfg || {}),
    };
  }

  async function setConfig(cfg) {
    await browser.storage.local.set({ [CONFIG_KEY]: cfg });
  }

  /** Normalise the base URL: strip a trailing slash so we can append /api/v1/... */
  function normaliseBase(baseUrl) {
    return String(baseUrl || "").trim().replace(/\/+$/, "");
  }

  function authHeader(cfg) {
    if (!cfg.username) return {};
    // btoa is latin1-only; encode first so accented passwords survive.
    const raw = `${cfg.username}:${cfg.password}`;
    const b64 = btoa(unescape(encodeURIComponent(raw)));
    return { Authorization: "Basic " + b64 };
  }

  async function request(path, { method = "GET", body = null, patch = false } = {}) {
    const cfg = await getConfig();
    const base = normaliseBase(cfg.baseUrl);
    if (!base) throw new Error("Server URL not configured");

    const headers = { Accept: "application/json", ...authHeader(cfg) };
    if (body != null) {
      // API Platform requires the JSON Merge Patch content type for PATCH.
      headers["Content-Type"] = patch ? "application/merge-patch+json" : "application/json";
    }

    let res;
    try {
      res = await fetch(base + path, {
        method,
        headers,
        credentials: "include",
        body: body != null ? JSON.stringify(body) : undefined,
      });
    } catch (e) {
      // TLS failures (self-signed cert not trusted) land here as a generic
      // network error — surface a hint rather than a bare "Failed to fetch".
      throw new Error(
        "Network/TLS error. If the server uses a self-signed certificate, " +
          "Firefox must trust it first (import your CA / mkcert). " +
          "Original: " + (e && e.message ? e.message : e)
      );
    }

    if (res.status === 401) {
      throw new Error("401 Unauthorized — check the basic-auth user/password.");
    }
    if (!res.ok) {
      throw new Error(`HTTP ${res.status} ${res.statusText}`);
    }
    const json = await res.json().catch(() => ({}));
    return json.response !== undefined ? json.response : json;
  }

  /** GET /api/v1/users/me — used by the "Test connection" button. */
  function me() {
    return request("/api/v1/users/me");
  }

  /**
   * GET /api/v1/tree — the whole bookmark tree in one shot (no pagination):
   * { dashboards: [ { id, name, collections: [ { …, links:[], children:[] } ] } ] }
   */
  function tree() {
    return request("/api/v1/tree");
  }

  /** GET /api/v1/dashboards — [{ id, name, color }] for the picker. */
  function dashboards() {
    return request("/api/v1/dashboards");
  }

  /** GET /api/v2/collections — [{ id, name, dashboardId, parentId, … }]. */
  function collections() {
    return request("/api/v2/collections");
  }

  /** POST /api/v2/collections — create a collection. Returns the created resource. */
  function createCollection({ name, parentId = null, dashboardId = null }) {
    const body = { name };
    if (parentId != null) body.parentId = parentId;
    if (dashboardId != null) body.dashboardId = dashboardId;
    return request("/api/v2/collections", { method: "POST", body });
  }

  /** DELETE /api/v2/collections/{id} (cascades to descendants server-side). */
  function deleteCollection(id) {
    return request(`/api/v2/collections/${id}`, { method: "DELETE" });
  }

  /** GET /api/v2/links — flat list with id/url/name/updatedAt (for push diff). */
  function listLinks() {
    return request("/api/v2/links");
  }

  /** POST /api/v2/links — create a link. Returns the created resource (with id). */
  function createLink({ url, name = null, description = null, collectionId = null }) {
    const body = { url };
    if (name != null) body.name = name;
    if (description != null) body.description = description;
    if (collectionId != null) body.collectionId = collectionId;
    return request("/api/v2/links", { method: "POST", body });
  }

  /** PATCH /api/v2/links/{id} — update (merge-patch). */
  function updateLink(id, patch) {
    return request(`/api/v2/links/${id}`, { method: "PATCH", body: patch, patch: true });
  }

  /** DELETE /api/v2/links/{id}. */
  function deleteLink(id) {
    return request(`/api/v2/links/${id}`, { method: "DELETE" });
  }

  /**
   * Walk the cursor-paginated /api/v1/links endpoint until it runs dry.
   * The endpoint returns links with id < cursor (page size 20), so we advance
   * the cursor to the smallest id seen on each page.
   * @returns {Promise<Array<object>>} every link
   */
  async function allLinks() {
    const seen = new Map(); // id -> link (dedupe across pages)
    let cursor = 0;
    for (let guard = 0; guard < 10000; guard++) {
      const q = cursor > 0 ? `?cursor=${cursor}` : "";
      const page = await request(`/api/v1/links${q}`);
      if (!Array.isArray(page) || page.length === 0) break;

      let minId = Infinity;
      let added = 0;
      for (const link of page) {
        const id = Number(link.id);
        if (!seen.has(id)) {
          seen.set(id, link);
          added++;
        }
        if (id < minId) minId = id;
      }
      // No progress (all ids already seen, or cursor didn't move) → stop.
      if (added === 0 || !isFinite(minId) || minId >= cursor && cursor > 0) break;
      cursor = minId;
    }
    return Array.from(seen.values());
  }

  return {
    getConfig, setConfig, normaliseBase, me, tree, dashboards, collections, createCollection, deleteCollection, listLinks,
    createLink, updateLink, deleteLink, allLinks, request,
  };
})();

if (typeof module !== "undefined") module.exports = SfbApi;
