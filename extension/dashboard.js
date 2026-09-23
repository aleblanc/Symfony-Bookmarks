"use strict";

/**
 * The mobile bookmark dashboard — mirrors the web app's home:
 *   ⭐ Favourites · 🕘 Recently clicked · 🔥 Most clicked · 🆕 Newest · then each
 *   folder, all rendered as horizontal swipable strips (~3 cards visible).
 *
 * Everything is computed from the cached tree (24h TTL), so it opens instantly and
 * works offline. Clicking a link pings the server (fire-and-forget) and patches the
 * cache optimistically so "recently/most clicked" reorder immediately.
 *
 * Search always runs over ALL dashboards; the home sections respect the dashboard
 * chosen in the options.
 */
const $ = (s) => document.querySelector(s);

let TREE = null; // raw cached tree data { dashboards: [...] }
let FLAT_ALL = []; // every link, all dashboards — for search
let FLAT_HOME = []; // links of the selected dashboard(s) — for the home sections
let CONFIG = null;
let NAV_FOLDER = null; // drill-down: collection id currently open, null = home

const fmtDate = (ms) => new Date(ms).toLocaleString();

function banner(text, hide = false) {
  const el = $("#banner");
  el.hidden = hide || !text;
  if (text) el.textContent = text;
}

function hostOf(url) {
  try {
    return new URL(url).host.replace(/^www\./, "");
  } catch {
    return url;
  }
}

// The server stores 'BM' (and '') as "no custom icon" — show a folder emoji, like the web UI.
function folderIcon(col) {
  return col.icon && col.icon !== "BM" ? col.icon : "📁";
}

// Record the click (optimistic cache patch + fire-and-forget ping), then navigate.
function trackClick(a, id) {
  a.addEventListener("click", (e) => {
    const plain =
      e.button === 0 && !e.metaKey && !e.ctrlKey && !e.shiftKey && !e.altKey;
    if (id == null) return; // no id → let the browser handle it normally
    if (plain) {
      e.preventDefault();
      SfbStore.registerClick(id).finally(() => {
        location.href = a.href;
      });
    } else {
      SfbStore.registerClick(id); // opens in a new tab; dashboard stays put
    }
  });
}

function cardEl(link) {
  const a = document.createElement("a");
  a.className = "card";
  a.href = link.url;
  const name = document.createElement("span");
  name.className = "cname";
  name.textContent = link.name || link.url;
  const host = document.createElement("span");
  host.className = "chost";
  host.textContent = hostOf(link.url);
  a.append(name, host);
  trackClick(a, link.id);
  return a;
}

// A strip section. If folderId is given, the title is clickable → opens that folder.
function stripEl(title, links, folderId) {
  if (!links.length) return null;
  const sec = document.createElement("section");
  sec.className = "section";
  const h = document.createElement("h2");
  const label = document.createElement("span");
  label.textContent = title;
  h.append(label);
  if (folderId != null) {
    h.classList.add("clickable");
    h.title = SfbI18n.t("dash.openFolder");
    const chev = document.createElement("span");
    chev.className = "chev";
    chev.textContent = "›";
    h.append(chev);
    h.addEventListener("click", () => navTo(folderId));
  } else {
    const c = document.createElement("span");
    c.className = "scount";
    c.textContent = String(links.length);
    h.append(c);
  }
  const strip = document.createElement("div");
  strip.className = "strip";
  for (const l of links) strip.append(cardEl(l));
  sec.append(h, strip);
  return sec;
}

// full-width row link (search results + folder view), with clickable tag chips.
function linkEl(link) {
  const row = document.createElement("div");
  row.className = "linkrow";
  const a = document.createElement("a");
  a.className = "link";
  a.href = link.url;
  const name = document.createElement("span");
  name.className = "name";
  name.textContent = link.name || link.url;
  const sub = document.createElement("span");
  sub.className = "sub";
  sub.textContent = link.folderPath
    ? hostOf(link.url) + " · " + link.folderPath
    : hostOf(link.url);
  a.append(name, sub);
  trackClick(a, link.id);
  row.append(a);
  if (Array.isArray(link.tags) && link.tags.length) {
    const tg = document.createElement("div");
    tg.className = "tags";
    for (const tag of link.tags) {
      const chip = document.createElement("button");
      chip.className = "tag";
      chip.type = "button";
      chip.textContent = "#" + tag;
      chip.addEventListener("click", () => searchTag(tag));
      tg.append(chip);
    }
    row.append(tg);
  }
  return row;
}

function dashboardsForConfig() {
  let list = TREE?.dashboards || [];
  if (CONFIG.dashboard && CONFIG.dashboard !== "all") {
    list = list.filter((d) => String(d.id) === String(CONFIG.dashboard));
  }
  return list;
}

function renderHome() {
  const out = $("#out");
  out.textContent = "";

  const sections = [
    stripEl(SfbI18n.t("dash.favorites"), SfbStore.favorites(FLAT_HOME)),
    stripEl(SfbI18n.t("dash.recentClicked"), SfbStore.recentlyClicked(FLAT_HOME)),
    stripEl(SfbI18n.t("dash.mostClicked"), SfbStore.mostClicked(FLAT_HOME)),
    stripEl(SfbI18n.t("dash.recentAdded"), SfbStore.newest(FLAT_HOME)),
  ];
  for (const s of sections) if (s) out.append(s);

  const dashboards = dashboardsForConfig();
  const multi = dashboards.length > 1;
  for (const dash of dashboards) {
    if (multi) {
      const h = document.createElement("div");
      h.className = "dash";
      h.textContent = dash.name;
      out.append(h);
    }
    for (const col of dash.collections || []) {
      // Each ROOT folder, in position order, previewing its recent links (newest
      // first). Prefer DIRECT links; if the folder has none but its subfolders do,
      // preview the whole subtree so folders that only contain subfolders still show.
      const byIdDesc = (a, b) => (b.id || 0) - (a.id || 0);
      let links = (col.links || []).slice().sort(byIdDesc).slice(0, 10);
      if (!links.length) {
        links = SfbStore.subtreeLinks(col).sort(byIdDesc).slice(0, 10);
      }
      // clickable title → open the folder (its own strip is a preview of 10)
      const strip = stripEl(folderIcon(col) + " " + col.name, links, col.id);
      if (strip) out.append(strip);
    }
  }

  if (!out.children.length) {
    const e = document.createElement("p");
    e.className = "empty";
    e.textContent = SfbI18n.t("dash.empty");
    out.append(e);
  }
}

function renderResults(results) {
  const out = $("#out");
  out.textContent = "";
  if (!results.length) {
    const e = document.createElement("p");
    e.className = "empty";
    e.textContent = SfbI18n.t("dash.noMatch");
    out.append(e);
    return;
  }
  const box = document.createElement("details");
  box.open = true;
  const sum = document.createElement("summary");
  sum.textContent = SfbI18n.t("dash.results");
  const count = document.createElement("span");
  count.className = "count";
  count.textContent = String(results.length);
  sum.append(count);
  box.append(sum);
  for (const link of results.slice(0, 500)) box.append(linkEl(link));
  out.append(box);
}

function applyQuery(q) {
  // Search spans ALL dashboards; folder view / home respect the selected dashboard.
  const query = q.trim();
  if (query) {
    if (query.startsWith("#")) {
      const tag = query.slice(1).toLowerCase();
      const results = tag
        ? FLAT_ALL.filter((l) => (l.tags || []).some((t) => String(t).toLowerCase().includes(tag)))
        : FLAT_ALL;
      renderResults(results);
    } else {
      renderResults(SfbStore.searchLinks(FLAT_ALL, query));
    }
    return;
  }
  if (NAV_FOLDER != null) renderFolder(NAV_FOLDER);
  else renderHome();
}

// Jump to a tag search from a clicked chip.
function searchTag(tag) {
  $("#q").value = "#" + tag;
  applyQuery("#" + tag);
  window.scrollTo(0, 0);
}

// Open a folder (drill-down), or go home with null. Clears any active search.
function navTo(id) {
  NAV_FOLDER = id;
  if ($("#q").value) $("#q").value = "";
  applyQuery("");
  window.scrollTo(0, 0);
}

// The chain of collection nodes from a root down to `id` (within the shown
// dashboards), or null if not found.
function locateFolder(id) {
  let found = null;
  const walk = (cols, trail) => {
    for (const c of cols || []) {
      const here = trail.concat([c]);
      if (Number(c.id) === Number(id)) {
        found = here;
        return true;
      }
      if (c.children && c.children.length && walk(c.children, here)) return true;
    }
    return false;
  };
  for (const dash of dashboardsForConfig()) {
    if (walk(dash.collections || [], [])) break;
  }
  return found;
}

function breadcrumbEl(trail) {
  const nav = document.createElement("nav");
  nav.className = "crumbs";
  const home = document.createElement("button");
  home.className = "crumb";
  home.type = "button";
  home.textContent = "🏠 " + SfbI18n.t("dash.home");
  home.addEventListener("click", () => navTo(null));
  nav.append(home);
  trail.forEach((c, i) => {
    const sep = document.createElement("span");
    sep.className = "sep";
    sep.textContent = "›";
    nav.append(sep);
    const b = document.createElement("button");
    b.className = "crumb";
    b.type = "button";
    b.textContent = c.name;
    if (i < trail.length - 1) b.addEventListener("click", () => navTo(c.id));
    else b.disabled = true;
    nav.append(b);
  });
  return nav;
}

function folderRowEl(col) {
  const b = document.createElement("button");
  b.className = "folderrow";
  b.type = "button";
  const label = document.createElement("span");
  label.textContent = folderIcon(col) + " " + col.name;
  const count = document.createElement("span");
  count.className = "fcount";
  count.textContent = String(SfbStore.subtreeLinks(col).length);
  b.append(label, count);
  b.addEventListener("click", () => navTo(col.id));
  return b;
}

function sectionWithHeader(titleText, count) {
  const sec = document.createElement("section");
  sec.className = "section";
  const h = document.createElement("h2");
  h.textContent = titleText;
  if (count != null) {
    const c = document.createElement("span");
    c.className = "scount";
    c.textContent = String(count);
    h.append(c);
  }
  sec.append(h);
  return sec;
}

function renderFolder(id) {
  const out = $("#out");
  out.textContent = "";
  const trail = locateFolder(id);
  if (!trail) {
    NAV_FOLDER = null;
    renderHome();
    return;
  }
  const col = trail[trail.length - 1];
  out.append(breadcrumbEl(trail));

  const kids = col.children || [];
  if (kids.length) {
    const sec = sectionWithHeader(SfbI18n.t("dash.subfolders"), kids.length);
    const list = document.createElement("div");
    list.className = "folderlist";
    for (const child of kids) list.append(folderRowEl(child));
    sec.append(list);
    out.append(sec);
  }

  const links = (col.links || []).slice().sort((a, b) => (b.id || 0) - (a.id || 0));
  const sec2 = sectionWithHeader(SfbI18n.t("dash.folderLinks"), links.length);
  if (links.length) {
    for (const l of links) sec2.append(linkEl(l));
  } else {
    const e = document.createElement("p");
    e.className = "empty";
    e.textContent = SfbI18n.t("dash.empty");
    sec2.append(e);
  }
  out.append(sec2);
}

function ingest(rec, { fromNetwork = false } = {}) {
  TREE = rec.data;
  FLAT_ALL = SfbStore.flattenLinks(TREE);
  FLAT_HOME = SfbStore.flattenLinks({ dashboards: dashboardsForConfig() });
  $("#meta").textContent =
    SfbI18n.t("dash.cache") + ": " + fmtDate(rec.fetchedAt) + " · " + FLAT_ALL.length + " " + SfbI18n.t("dash.links");
  if (fromNetwork) banner(null, true);
  applyQuery($("#q").value);
}

function showSetup() {
  $("#out").innerHTML = "";
  const box = document.createElement("div");
  box.className = "setup";
  const p = document.createElement("p");
  p.textContent = SfbI18n.t("dash.notConfigured");
  const btn = document.createElement("button");
  btn.textContent = SfbI18n.t("common.openSettings");
  btn.addEventListener("click", () => browser.runtime.openOptionsPage());
  box.append(p, btn);
  $("#out").append(box);
}

function googleSearch(q) {
  location.href = "https://www.google.com/search?q=" + encodeURIComponent(q);
}

// New-tab pages fight the address bar for focus — Firefox focuses the URL bar on a
// fresh tab. Retry a few times and re-grab focus whenever the page regains it.
function focusSearch() {
  const q = $("#q");
  if (q) q.focus({ preventScroll: true });
}
window.addEventListener("focus", focusSearch);
window.addEventListener("pageshow", focusSearch);
setTimeout(focusSearch, 60);
setTimeout(focusSearch, 250);

// debounce live filtering
let t = null;
$("#q").addEventListener("input", (e) => {
  clearTimeout(t);
  const v = e.target.value;
  t = setTimeout(() => applyQuery(v), 100);
});

// Enter: open the first matching bookmark (tracked); if nothing matches, Google it.
$("#q").addEventListener("keydown", (e) => {
  if (e.key !== "Enter") return;
  e.preventDefault();
  const q = e.target.value.trim();
  if (!q) return;
  if (/^https?:\/\//i.test(q)) {
    location.href = q;
    return;
  }
  const results = SfbStore.searchLinks(FLAT_ALL, q);
  if (results.length) {
    const first = results[0];
    SfbStore.registerClick(first.id).finally(() => {
      location.href = first.url;
    });
  } else {
    googleSearch(q);
  }
});

$("#gbtn").addEventListener("click", () => {
  googleSearch($("#q").value.trim() || "");
});

$("#opts").addEventListener("click", () => browser.runtime.openOptionsPage());
$("#save").addEventListener("click", () => {
  browser.tabs.create({ url: browser.runtime.getURL("save.html") });
});
$("#refresh").addEventListener("click", async () => {
  banner(SfbI18n.t("dash.refreshing"));
  try {
    const rec = await SfbStore.refresh();
    ingest(rec, { fromNetwork: true });
  } catch (e) {
    banner(SfbI18n.t("dash.offline", { err: e.message || e }));
  }
});

// Native bookmark sync (pull/push) opens the diff-review page. It's desktop-only —
// Firefox Android has no bookmarks API — so hide the shortcuts where they can't work.
function openReview(dir) {
  browser.tabs.create({ url: browser.runtime.getURL("review.html?dir=" + dir) });
}
if (typeof browser !== "undefined" && browser.bookmarks) {
  $("#pull").addEventListener("click", () => openReview("pull"));
  $("#push").addEventListener("click", () => openReview("push"));
} else {
  $("#pull").hidden = true;
  $("#push").hidden = true;
}

// Compare dotted version strings: >0 if a newer than b, <0 if older, 0 if equal.
function cmpVersions(a, b) {
  const pa = String(a).split("."), pb = String(b).split(".");
  for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
    const d = (parseInt(pa[i] || "0", 10)) - (parseInt(pb[i] || "0", 10));
    if (d) return d > 0 ? 1 : -1;
  }
  return 0;
}

// Sideloaded APKs don't auto-update. Best-effort: once a day, compare the installed
// Firefox version to the latest GitHub release and, if newer, show a download banner.
async function checkForUpdate() {
  try {
    // Android-only: on desktop the add-on updates itself via AMO and the APK is irrelevant.
    const platform = await browser.runtime.getPlatformInfo();
    if (!platform || platform.os !== "android") return;
    const { lastUpdateCheck } = await browser.storage.local.get("lastUpdateCheck");
    if (lastUpdateCheck && Date.now() - lastUpdateCheck < 24 * 60 * 60 * 1000) return;
    const info = await browser.runtime.getBrowserInfo();
    const rel = await fetch(
      "https://api.github.com/repos/aleblanc/firefox-bookmarks/releases/latest",
      { headers: { Accept: "application/vnd.github+json" } },
    ).then((r) => (r.ok ? r.json() : null));
    await browser.storage.local.set({ lastUpdateCheck: Date.now() });
    if (!rel || !rel.tag_name || !info || !info.version) return;
    const latest = rel.tag_name.replace(/^v/, "");
    if (cmpVersions(latest, info.version) <= 0) return;
    const apk = (rel.assets || []).find((a) => a.name && a.name.endsWith(".apk"));
    const el = $("#update");
    el.href = apk ? apk.browser_download_url : rel.html_url;
    el.textContent = "⬆️ Mise à jour disponible : " + latest + " (installée : " + info.version + ") — télécharger";
    el.hidden = false;
  } catch (e) {
    /* best-effort: ignore update-check failures */
  }
}

async function init() {
  CONFIG = await SfbApi.getConfig();
  SfbI18n.setLang(CONFIG.lang || "");
  SfbI18n.apply();
  if (!CONFIG.baseUrl) {
    showSetup();
    return;
  }
  focusSearch(); // land in the search box straight away
  const cached = await SfbStore.load({
    onFresh: (rec) => ingest(rec, { fromNetwork: true }),
    onError: (e) => banner(SfbI18n.t("dash.offline", { err: e.message || e })),
  });
  if (cached) ingest(cached);
  else banner(SfbI18n.t("dash.loading"));

  checkForUpdate();
}

init();
