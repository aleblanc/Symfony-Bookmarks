# Symfony Bookmarks

A lightweight, self-hosted, single-user bookmark manager built in **Symfony 8.2** + **SQLite**, designed to run on a Raspberry Pi 4 (~200 MB RAM) and stay **compatible with the official Linkwarden browser extensions** for Firefox and Chrome.

Goals:
- Replace the full Linkwarden stack (Next.js + PostgreSQL + Chromium workers, ~1.5–3 GB RAM) with a lean PHP-only stack.
- No Docker required — plain `apt install` + `composer install` + a cron entry.
- No user accounts — protection is handled upstream (nginx `auth_basic`, VPN, or LAN-only).
- Two independent dashboards (**Perso** / **Pro**) with fully isolated collections, tags and search.
- Optional per-collection **encrypted vault** (libsodium Argon2id + `crypto_secretbox`).
- Optional **AI auto-tagging** via a remote [LM Studio](https://lmstudio.ai) instance, through the official `symfony/ai-bundle`.
- Archival (readable text always, screenshot / PDF if Chromium is installed) driven by a periodic command — no message broker.

---

## Table of contents

- [Features](#features)
- [Screenshots](#screenshots)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [First run](#first-run)
- [Cron / scheduled jobs](#cron--scheduled-jobs)
- [Production deployment (nginx + php-fpm)](#production-deployment-nginx--php-fpm)
- [AI features (LM Studio)](#ai-features-lm-studio)
- [Encrypted vault](#encrypted-vault)
- [Import existing bookmarks](#import-existing-bookmarks)
- [More docs](#more-docs)
- [License](#license)

---

## Features

- Linkwarden-compatible REST API at `/api/v1/*` (envelope `{"response": ...}`, `Authorization: Bearer <token>`).
- SQLite storage with **FTS5** full-text search on links.
- Two dashboards (Perso / Pro) — every list / search is scoped to the active dashboard.
- Collections, tags, favicons, "readable" text extraction (pure PHP, no browser).
- Runtime detection of Chromium: features gracefully disable in the UI when tooling is absent.
- Encrypted vault protecting selected collections (URL + title + description + text ciphered at rest).
- Web UI in Twig (no npm build required — CSS is inline).
- Netscape HTML bookmarks import (Firefox / Chrome / Linkwarden exports).
- AI auto-tagging via any OpenAI-compatible endpoint (LM Studio, Ollama, vLLM, cloud API…).

---

## Screenshots

| Dashboard | Links (compact cards) |
|---|---|
| ![Dashboard — recently clicked / added / per collection](docs/screenshots/dashboard.png) | ![Links list](docs/screenshots/links.png) |

| Collection detail | Tags page |
|---|---|
| ![Collection with sub-folders and links](docs/screenshots/collection.png) | ![Tag cloud](docs/screenshots/tags.png) |

| Import — folder selection | New link |
|---|---|
| ![Import: pick folders to import](docs/screenshots/import.png) | ![New link form](docs/screenshots/new-link.png) |

---

## Requirements

**Runtime:**
- PHP **8.5** (8.2 minimum) with extensions: `ctype`, `iconv`, `mbstring`, `xml`, `curl`, `sqlite3` (`pdo_sqlite`), `sodium`
- SQLite **3.35+** (bundled with the `sqlite3` package on Debian/Ubuntu/Raspberry Pi OS Bookworm)
- Composer 2
- A web server (nginx / Apache / `symfony local:server` in dev)

**Optional (unlock features):**
- `chromium` (system package) — enables screenshot + PDF archival
- An LM Studio (or Ollama / vLLM) instance reachable over HTTP — enables AI auto-tagging

**Target machine (my setup):** Raspberry Pi 4 (4 or 8 GB) with Raspberry Pi OS Bookworm (default apt ships PHP 8.2; use the deb.sury.org repo for PHP 8.5). SQLite file should live on an SSD (USB3) — not the SD card — for durability and performance.

---

## Installation

### 1. System packages (Debian / Ubuntu / Raspberry Pi OS Bookworm)

```bash
# Bookworm's default apt ships PHP 8.2; add the deb.sury.org repo to get PHP 8.5:
sudo apt install -y apt-transport-https ca-certificates curl lsb-release
curl -sSL https://packages.sury.org/php/apt.gpg | sudo tee /etc/apt/trusted.gpg.d/sury-php.gpg > /dev/null
echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" | sudo tee /etc/apt/sources.list.d/sury-php.list

sudo apt update
sudo apt install -y \
  php8.5-cli php8.5-fpm php8.5-sqlite3 php8.5-mbstring php8.5-xml \
  php8.5-curl php8.5-intl php8.5-dom \
  sqlite3 composer git

# Optional — unlock archival features:
sudo apt install -y chromium
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
| `APP_ARCHIVE_DIR` | `var/archives` | Where screenshot / PDF files are stored. Point to your SSD in prod |
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

## Cron / scheduled jobs

Schedules are declared **in code** with `simple-cron-scheduler`'s `#[AsCronTask]`
attribute on the command classes in `src/Command/` (auto-discovered — no YAML task
list). Current schedules:

| Command | Schedule | What it does |
|---|---|---|
| `app:index-pending` | `*/5 * * * *` | fetch pending links, extract readable text, screenshot/PDF if Chromium is present |
| `app:ai-tag-pending` | `*/10 * * * *` | tag archived links via the LLM agent (if `APP_AI_ENABLED=true`) |
| `app:ai-summarize-pending` | `*/15 * * * *` | summarize archived links via the LLM agent |

A **single** system crontab entry ticks the scheduler every minute; it runs
whatever is due:

```
* * * * * cd /var/www/bookmarks && php bin/console scheduler:run >> var/log/cron.log 2>&1
```

Inspect the registered tasks with `php bin/console scheduler:list`. To change a
frequency, edit the `#[AsCronTask('<cron expr>', …)]` attribute on the command.

---

## Production deployment (nginx + php-fpm)

Assumes the code is at `/var/www/bookmarks` and PHP-FPM listens on `/run/php/php8.5-fpm.sock`.

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

**nginx site config** (plain HTTP — put TLS termination behind a reverse proxy / VPN as you prefer):

```nginx
server {
    listen 80;
    server_name bookmarks.local;

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

    location /bundles {
        try_files $uri =404;
    }

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.5-fpm.sock;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT   $document_root;
        internal;
    }

    location ~ \.php$ { return 404; }

    error_log  /var/log/nginx/bookmarks.error.log;
    access_log /var/log/nginx/bookmarks.access.log;
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

Attach a password to selected collections; their links' URL, title, description and readable text are encrypted at rest (libsodium Argon2id + `crypto_secretbox`). Locked links show as `[locked]` placeholders until you unlock the vault.

See **[docs/encrypted-vault.md](docs/encrypted-vault.md)** for setup and usage.

---

## Import existing bookmarks

1. Export your bookmarks from Firefox / Chrome / Linkwarden as an HTML file (Netscape format).
2. Go to **Import bookmarks** in the sidebar.
3. Upload the file. Each `<H3>` folder becomes a Collection; each `<A HREF>` becomes a Link — all scoped to the active dashboard.

Links land as `status=pending` so the archival cron will pick them up.

---

## More docs

- [Architecture](docs/architecture.md) — data flow and the no-broker cron design.
- [Browser extension setup](docs/browser-extension.md) — connecting the Linkwarden extension.
- [Encrypted vault](docs/encrypted-vault.md) — per-collection at-rest encryption.
- [Development](docs/development.md) — dev server, workers, tests and quality gates.
- [Troubleshooting](docs/troubleshooting.md) — common symptoms and fixes.
- [Roadmap](docs/ROADMAP.md) — non-committed feature ideas.
- [CLAUDE.md](CLAUDE.md) — full design notes and non-obvious constraints.

---

## License

Proprietary — see individual dependency licenses in `composer.lock`. This is a personal project; use as you wish for personal deployments.
