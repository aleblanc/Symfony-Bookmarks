# Symfony Bookmarks Sync — Firefox extension

One-way, on-device sync from your **Symfony Bookmarks** server into Firefox
bookmarks. Built to run on **Firefox for Android** as well as desktop.

> Status: **Phase 1** — mirror *Symfony → Firefox* only (creates missing folders
> and links, matches by normalised URL, never deletes or moves). Later phases add
> the reverse direction, deletions and conflict handling. See `docs/ROADMAP.md`
> item 6 in the main repo.

## What it does

- Pulls the whole tree in one request from **`/api/v1/tree`** (no pagination).
- Mirrors the structure under a dedicated folder:
  `Symfony Bookmarks / <Dashboard> / <Collection> / <sub-collection> / links…`.
- Deduplicates by normalised URL (lowercase host, no leading `www.`, no trailing
  `/`): a URL already bookmarked anywhere in Firefox is not recreated.
- Remembers the `symfonyLinkId → firefoxBookmarkGuid` map in `storage.local`.
- Syncs shortly after launch, every 30 min via `alarms`, and on demand
  (**Sync now** in the toolbar popup).

## Auth model (v1)

The whole server is behind **HTTP basic-auth** (htpasswd), so the API needs no
token of its own. You enter the basic-auth **user/password** once in the options
page; the extension attaches `Authorization: Basic …` on every request — no
password prompt ever appears. Credentials live in `storage.local`
(**unencrypted** — fine for a personal device; prefer revocable creds otherwise).

## Self-signed certificate / access by IP

An extension **cannot bypass a TLS error**. If your server uses a self-signed
cert you must make Firefox trust it *first*:

- best: a local CA (e.g. [`mkcert`](https://github.com/FiloSottile/mkcert)) whose
  root you import into Firefox → *Settings → Privacy & Security → Certificates →
  Authorities*;
- the interactive “Accept the risk” exception is unreliable for background fetches.

If you connect **by IP**, the certificate must list the IP in its
`subjectAltName` (`IP:192.168.x.x`), not just the CN.

## Develop & test

Requires Node + [`web-ext`](https://github.com/mozilla/web-ext) (`npm i -g web-ext`).

```bash
cd extension

# Lint
web-ext lint

# Run on desktop Firefox with auto-reload
web-ext run

# Run on a USB-connected Android device
#  - install Firefox on the phone, enable Settings → Remote debugging via USB
#  - have adb installed on the PC
web-ext run -t firefox-android --adb-device <DEVICE_ID> --firefox-apk org.mozilla.firefox
```

Load manually instead: `about:debugging` → *This Firefox* → *Load Temporary
Add-on* → pick `extension/manifest.json`. Inspect Android from desktop Firefox
via `about:debugging` → your device.

## Build & install (signed)

Android stable refuses unsigned `.xpi`, so sign via AMO:

```bash
web-ext sign --api-key=<AMO_JWT_ISSUER> --api-secret=<AMO_JWT_SECRET> --channel=unlisted
```

Install the resulting signed `.xpi` by opening its URL on the phone (unlisted),
or publish it (listed) on addons.mozilla.org.

## Files

| File | Role |
|---|---|
| `manifest.json` | MV2 manifest (Android-friendly) |
| `lib/api.js` | API client (basic-auth header, envelope unwrap, pagination) |
| `lib/sync.js` | Phase-1 reconciliation (Symfony → Firefox) |
| `background.js` | launch/alarm sync + message handling |
| `options.html` / `options.js` | server URL + credentials, connection test |
| `popup.html` / `popup.js` | “Sync now” + status |
