# Symfony Bookmarks

A lightweight, self-hosted, single-user bookmark manager built in **Symfony 8.2** + **SQLite**, designed to run on a Raspberry Pi 4 (~200 MB RAM) and stay **compatible with the official Linkwarden browser extensions** for Firefox and Chrome.

Goals:
- Replace the full Linkwarden stack (Next.js + PostgreSQL + Chromium workers, ~1.5–3 GB RAM) with a lean PHP-only stack.
- No Docker required — plain `apt install` + `composer install` + a cron entry.
- No user accounts — protection is handled upstream (nginx `auth_basic`, VPN, or LAN-only).
- Two independent dashboards (**Perso** / **Pro**) with fully isolated collections, tags and search.
- Optional per-collection **encrypted vault** (libsodium Argon2id + `crypto_secretbox`).
- Optional **AI auto-tagging** via a remote [LM Studio](https://lmstudio.ai) instance, through the official `symfony/ai-bundle`.
- Archival (readable text always, single-file HTML / screenshot / PDF if Chromium is installed) driven by a periodic command — no message broker.

---

## Table of contents

- [Features](#features)
- [Architecture](#architecture)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [First run](#first-run)
- [Browser extension setup](#browser-extension-setup)
- [Cron / scheduled jobs](#cron--scheduled-jobs)
- [Production deployment (nginx + php-fpm)](#production-deployment-nginx--php-fpm)
- [AI features (LM Studio)](#ai-features-lm-studio)
- [Encrypted vault](#encrypted-vault)
- [Import existing bookmarks](#import-existing-bookmarks)
- [Development](#development)
- [Testing and quality gates](#testing-and-quality-gates)
- [Troubleshooting](#troubleshooting)
- [License](#license)

---

## Features

- Linkwarden-compatible REST API at `/api/v1/*` (envelope `{"response": ...}`, `Authorization: Bearer <token>`).
- SQLite storage with **FTS5** full-text search on links.
- Two dashboards (Perso / Pro) — every list / search is scoped to the active dashboard.
- Collections, tags, favicons, "readable" text extraction (pure PHP, no browser).
- Runtime detection of Chromium / `single-file-cli`: features gracefully disable in the UI when tooling is absent.
- Encrypted vault protecting selected collections (URL + title + description + text ciphered at rest).
- Web UI in Twig (no npm build required — CSS is inline).
- Netscape HTML bookmarks import (Firefox / Chrome / Linkwarden exports).
- AI auto-tagging via any OpenAI-compatible endpoint (LM Studio, Ollama, vLLM, cloud API…).

---

## Architecture

```
Browser extension (Linkwarden) ──┐
                                 │  Bearer token
Web UI (Twig)  ──────────────────┼──► /api/v1/* (Linkwarden envelope)  ──► SQLite + FTS5
                                 │       │                                   │
                                 │       │ writes Link(status=pending)       │
Cron (simple-cron-scheduler)     │       ▼                                   │
  ├── app:archive-pending  ──────┼──► ArchiveRunner ──► ReadableExtractor    │
  │                              │                  └── SingleFile/PNG/PDF ──┼── skipped if no Chrome
  └── app:ai-tag-pending   ──────┼──► AutoTagger ──► LM Studio (remote)      │
                                 │                                           │
                                 ▼                                           │
                            Session-scoped:                                  │
                            - CurrentDashboard (Perso / Pro)                 │
                            - VaultSession (unlocked keys, 15 min TTL)  ─────┘
```

No Symfony Messenger, no broker: background work polls `WHERE status='pending'` in SQLite. Two Symfony commands run on cron.

---

## Requirements

**Runtime:**
- PHP **8.2 or newer** with extensions: `ctype`, `iconv`, `mbstring`, `xml`, `curl`, `sqlite3` (`pdo_sqlite`), `sodium`
- SQLite **3.35+** (bundled with the `sqlite3` package on Debian/Ubuntu/Raspberry Pi OS Bookworm)
- Composer 2
- A web server (nginx / Apache / `symfony local:server` in dev)

**Optional (unlock features):**
- `chromium` (system package) — enables screenshot + PDF archival
- `single-file-cli` (`npm i -g single-file-cli`) — enables self-contained HTML archival
- An LM Studio (or Ollama / vLLM) instance reachable over HTTP — enables AI auto-tagging

**Target machine (my setup):** Raspberry Pi 4 (4 or 8 GB) with Raspberry Pi OS Bookworm (PHP 8.2 in default apt). SQLite file should live on an SSD (USB3) — not the SD card — for durability and performance.

---

## Installation

### 1. System packages (Debian / Ubuntu / Raspberry Pi OS Bookworm)

```bash
sudo apt update
sudo apt install -y \
  php8.2-cli php8.2-fpm php8.2-sqlite3 php8.2-mbstring php8.2-xml \
  php8.2-curl php8.2-intl php8.2-dom \
  sqlite3 composer git

# Optional — unlock archival features:
sudo apt install -y chromium
sudo npm install -g single-file-cli   # requires nodejs
```

### 2. Clone and install

```bash
git clone https://github.com/aleblanc/Symfony-Bookmarks.git bookmarks
cd bookmarks
composer install --no-dev --optimize-autoloader   # add --dev if you want tests + phpstan
```

### 3. Local environment overrides

```bash
cp .env .env.local     # if you want to override anything
```

Edit `.env.local` — see the [Configuration](#configuration) section for what each variable does.

### 4. Create the SQLite database

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

This creates `var/data_prod.db` (or `var/data_dev.db` in dev), the FTS5 index, and seeds the two dashboards **Perso** and **Pro**.

### 5. Generate an API token (for the browser extension)

```bash
php bin/console app:generate-secrets
```

Copy the printed line into `.env.local`:

```
APP_API_TOKEN=<the-token>
```

You're done. In dev you can now:

```bash
php -S 127.0.0.1:8000 -t public
# or:
symfony server:start
```

Open http://localhost:8000.

---

## Configuration

All settings live in `.env` (defaults) and `.env.local` (your overrides, git-ignored).

| Variable | Default | What it does |
|---|---|---|
| `APP_ENV` | `dev` | `prod` in production |
| `APP_SECRET` | *(empty)* | Framework secret — auto-generated on first run if empty |
| `DATABASE_URL` | `sqlite:///%kernel.project_dir%/var/data_%kernel.environment%.db` | SQLite file path (per env). Point to your SSD in prod, e.g. `sqlite:///mnt/ssd/bookmarks/data.db` |
| `APP_API_TOKEN` | *(empty)* | Bearer token the extension must send. **If empty, any Bearer token is accepted** — leave empty only for LAN-only setups. Generate via `app:generate-secrets`. |
| `APP_INSTANCE_URL` | `http://localhost:8000` | External URL of your instance (used by the extension when you configure it) |
| `APP_ARCHIVE_DIR` | `var/archives` | Where single-file / screenshot / PDF files are stored. Point to your SSD in prod |
| `APP_CHROME_PATH` | *(empty)* | Override the auto-detected Chromium path. Leave empty for auto-detect (`/usr/bin/chromium`, `/usr/bin/google-chrome`, …) |
| `APP_AI_ENABLED` | `false` | Set to `true` to activate the AI cron (`app:ai-tag-pending`) |
| `APP_AI_TAG_MODEL` | `qwen2.5-7b-instruct` | Model ID loaded in LM Studio |
| `APP_AI_SUMMARY_MODEL` | `qwen2.5-7b-instruct` | Model ID for summaries |
| `LM_STUDIO_HOST_URL` | `http://192.168.1.50:1234` | Your LM Studio server URL (put your AI-machine IP here) |

---

## First run

1. Go to http://localhost:8000/ — the dashboard shows the two seeded dashboards Perso / Pro.
2. Switch dashboard via the dropdown in the left sidebar.
3. Create your first collection: **Collections → + New collection**.
4. Add a link: **+ New link** (choose a URL and the target collection).
5. In dev, links stay in `status=pending` until the archival command runs — see [Cron](#cron--scheduled-jobs).

---

## Browser extension setup

Install the [official Linkwarden extension](https://github.com/linkwarden/browser-extension) in Firefox / Chrome / Edge. Then:

1. Open the extension options.
2. **Instance URL**: `http://<your-instance>/` (e.g. `http://raspberrypi.local:8000`).
3. **API token**: paste the value of `APP_API_TOKEN` from your `.env.local`.
4. Click "Sign in" — the extension calls `GET /api/v1/users/me`; you should see the collection dropdown populate.

New links you add via the extension will land in the app instantly and get archived on the next cron tick.

---

## Cron / scheduled jobs

Two Symfony commands do the background work:

| Command | What it does | Suggested frequency |
|---|---|---|
| `php bin/console app:archive-pending` | Picks links with `status=pending`, fetches HTML, extracts readable text, generates single-file/PNG/PDF if Chromium is available | Every 5 min |
| `php bin/console app:ai-tag-pending` | Picks links with `ai_status=pending` (already archived), calls the tagger agent, attaches tags | Every 10 min |

You can run them manually or install a system cron. Two approaches:

### Option A — Direct cron entries (simplest)

```
*/5  * * * * cd /var/www/bookmarks && php bin/console app:archive-pending --limit=20 >> var/log/archive.log 2>&1
*/10 * * * * cd /var/www/bookmarks && php bin/console app:ai-tag-pending  --limit=20 >> var/log/ai.log      2>&1
```

### Option B — Via `simple-cron-scheduler` (single cron entry)

Create `config/packages/simple_cron_scheduler.yaml`:

```yaml
simple_cron_scheduler:
    schedules:
        archive_pending:
            command: 'app:archive-pending'
            expression: '*/5 * * * *'
        ai_tag_pending:
            command: 'app:ai-tag-pending'
            expression: '*/10 * * * *'
```

Then a single crontab entry ticks the scheduler:

```
* * * * * cd /var/www/bookmarks && php bin/console simple-cron:run >> var/log/cron.log 2>&1
```

---

## Production deployment (nginx + php-fpm)

Assumes the code is at `/var/www/bookmarks` and PHP-FPM listens on `/run/php/php8.2-fpm.sock`.

```bash
# 1. Deploy
git clone https://github.com/aleblanc/Symfony-Bookmarks.git /var/www/bookmarks
cd /var/www/bookmarks
composer install --no-dev --optimize-autoloader

# 2. Configure
cp .env .env.local
$EDITOR .env.local              # set APP_ENV=prod, APP_API_TOKEN, DATABASE_URL to /mnt/ssd/…, etc.

# 3. Migrate
php bin/console doctrine:migrations:migrate --no-interaction --env=prod
php bin/console cache:warmup --env=prod

# 4. Permissions
sudo chown -R www-data:www-data var public/assets/favicons /mnt/ssd/bookmarks

# 5. Cron (see previous section)

# 6. nginx (see below)
sudo systemctl reload nginx
```

**nginx site config**:

```nginx
server {
    listen 443 ssl http2;
    server_name bm.example.tld;
    ssl_certificate     /etc/letsencrypt/live/bm.example.tld/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/bm.example.tld/privkey.pem;

    root  /var/www/bookmarks/public;
    index index.php;

    # Web UI: protected by htpasswd (upstream auth)
    location / {
        auth_basic           "Restricted";
        auth_basic_user_file /etc/nginx/.htpasswd;
        try_files $uri /index.php$is_args$args;
    }

    # API: no htpasswd — the Bearer token secures it, and the browser extension can't send Basic + Bearer together
    location /api/ {
        auth_basic off;
        try_files $uri /index.php$is_args$args;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        internal;
    }

    location ~ \.php$ { return 404; }
}
```

Generate the htpasswd:

```bash
sudo apt install apache2-utils
sudo htpasswd -c /etc/nginx/.htpasswd me
```

---

## AI features (LM Studio)

The app calls a **remote** LM Studio through `symfony/ai-bundle`. Nothing is downloaded locally on the Pi.

1. On your AI machine, install [LM Studio](https://lmstudio.ai).
2. Load a small chat model (Qwen 2.5 7B Instruct works great for tagging).
3. Go to LM Studio → **Developer** tab → click **Start Server**. Make sure "Serve on Local Network" is enabled.
4. Note the URL, e.g. `http://192.168.1.50:1234`.
5. In `.env.local`:
   ```
   LM_STUDIO_HOST_URL=http://192.168.1.50:1234
   APP_AI_TAG_MODEL=qwen2.5-7b-instruct
   APP_AI_ENABLED=true
   ```
6. On the next tick of `app:ai-tag-pending`, freshly archived links get 3–5 tags attached.

The prompts live in `config/packages/ai.yaml` under `ai.agent.tagger.prompt` and `ai.agent.summarizer.prompt` — tweak them freely.

Any OpenAI-compatible endpoint works — swap `LM_STUDIO_HOST_URL` for your Ollama / vLLM / cloud endpoint (adjust the platform block in `ai.yaml` accordingly).

---

## Encrypted vault

A vault lets you attach a password to selected collections. Their links' URL, title, description and readable text are encrypted at rest with `crypto_secretbox` (libsodium XSalsa20-Poly1305). The password derives the key via Argon2id (`crypto_pwhash`).

### Create a vault

```bash
php bin/console app:create-vault mysecrets --password='correct horse battery staple'
```

### Attach a collection to it

Not yet exposed in the UI — do it manually in SQLite for now:

```bash
sqlite3 var/data_prod.db "UPDATE collections SET vault_id = 1 WHERE name = 'Finance';"
```

### Use it

Collections attached to a vault are hidden from the sidebar until unlocked. Visit `/vault/1/unlock`, enter the password. The derived key is kept in the PHP session for **15 minutes**, then the vault re-locks automatically. Locked links appear as `[locked]` placeholders — nothing decryptable server-side without the password.

**If you forget the password, the data is unrecoverable.** No backdoor.

---

## Import existing bookmarks

1. Export your bookmarks from Firefox / Chrome / Linkwarden as an HTML file (Netscape format).
2. Go to **Import bookmarks** in the sidebar.
3. Upload the file. Each `<H3>` folder becomes a Collection; each `<A HREF>` becomes a Link — all scoped to the active dashboard.

Links land as `status=pending` so the archival cron will pick them up.

---

## Development

```bash
composer install                  # install dev deps
php bin/console doctrine:migrations:migrate --no-interaction

# Dev server
php -S 127.0.0.1:8000 -t public
# or with the Symfony CLI (nicer):
symfony server:start
```

You can also run the two workers on-demand while iterating:

```bash
php bin/console app:archive-pending
php bin/console app:ai-tag-pending
```

The Web UI hot-reload is not wired (no npm dev server) — refresh the page manually. Twig cache is cleared automatically in dev.

---

## Testing and quality gates

```bash
# Prepare the test DB (once, and after every schema change)
APP_ENV=test php bin/console doctrine:database:drop --force --if-exists
APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction

# Run tests
vendor/bin/phpunit

# Static analysis (PHPStan level 8, must be 0 errors)
vendor/bin/phpstan analyse --memory-limit=512M

# Code style
composer cs-check     # dry-run
composer cs-fix       # apply
```

The `tests/SmokeTest.php` file uses a data provider to hit every public URL and every API endpoint — a fast regression net.

---

## Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `SQLSTATE[HY000]: General error: 1 no such table: links` when running tests | Test DB not migrated. Run the two `APP_ENV=test` commands above. |
| Web page loads but API returns `401 Invalid or missing token` | `APP_API_TOKEN` is set but the extension sends a different token (or none). Match them in `.env.local` and the extension settings. |
| Extension shows "Cannot connect" | Check the instance URL (must include the scheme, e.g. `http://192.168.1.10:8000`). If nginx `auth_basic` is on `/api/`, disable it there (see nginx config above). |
| `debug:container ai.agent.tagger` fails | Missing `symfony/ai-agent` dep. Run `composer require symfony/ai-agent`. |
| AI cron logs `ai tagging failed: Connection refused` | Wrong `LM_STUDIO_HOST_URL`, LM Studio server not running, or "Serve on Local Network" is off. |
| Chrome archival never produces files | `ChromeDetector` didn't find the binary. Check `php bin/console debug:container App\\Service\\ChromeDetector` and paths in `.env` (`APP_CHROME_PATH`). |
| Everything works in dev, prod page is blank | `APP_ENV=prod` requires a warm cache. Run `php bin/console cache:warmup --env=prod` and check `var/log/prod-*.log`. |

See also [CLAUDE.md](CLAUDE.md) for architectural notes and design decisions.

---

## License

Proprietary — see individual dependency licenses in `composer.lock`. This is a personal project; use as you wish for personal deployments.
