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

function stripEl(title, links) {
  if (!links.length) return null;
  const sec = document.createElement("section");
  sec.className = "section";
  const h = document.createElement("h2");
  h.textContent = title;
  const c = document.createElement("span");
  c.className = "scount";
  c.textContent = String(links.length);
  h.append(c);
  const strip = document.createElement("div");
  strip.className = "strip";
  for (const l of links) strip.append(cardEl(l));
  sec.append(h, strip);
  return sec;
}

// full-width row link, used for search results
function linkEl(link) {
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
  return a;
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
      const links = SfbStore.subtreeLinks(col)
        .sort((a, b) => (b.id || 0) - (a.id || 0))
        .slice(0, 12);
      const strip = stripEl(folderIcon(col) + " " + col.name, links);
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
  // Search spans ALL dashboards; the home view respects the selected dashboard.
  if (q.trim()) renderResults(SfbStore.searchLinks(FLAT_ALL, q));
  else renderHome();
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
}

init();
