# CLAUDE.md — Symfony Bookmarks

Read this before touching the code. It captures what exists, why, and the non-obvious constraints.

## What this project is

A self-hosted, single-user bookmark manager in PHP/Symfony/SQLite, meant to run on a Raspberry Pi 4 (~200 MB RAM) and stay compatible with the official Linkwarden browser extensions (Firefox / Chrome). It replaces the full Linkwarden stack (Next.js + Postgres + Chromium worker + workers) with a lightweight PHP-only stack.

The Linkwarden project itself lives in the parent directory (`../`) — this project reads its `packages/router/*.tsx` to know what API surface the extension expects.

## Why not just use Linkwarden / Karakeep / Shiori

Explored in previous conversation. Constraints that drove the custom build:
- **RAM ≤ 2 GB** on Pi 4 → excludes Linkwarden, Karakeep (both 1.5–3 GB with Chrome workers).
- **No Docker** — plain `apt install` + `composer install` + systemd cron.
- **Two dashboards** (Perso / Pro) with independent scopes — no OSS bookmark manager does this natively.
- **Encrypted vault** (real at-rest crypto, not just a "hidden" flag) — none of the alternatives do it.
- **AI tagging** via a **remote** LM Studio on a separate machine (user has a dedicated AI box).
- Shiori would work but has no AI, no vault, no workspaces.

## Stack — why each piece

| Package | Version | Why |
|---|---|---|
| `symfony/framework-bundle` | `8.2.x-dev` | User explicitly asked for Symfony 8.2 (dev branch — 8.0 is latest stable). `minimum-stability: dev + prefer-stable: true` in composer.json |
| `doctrine/orm` | `^3.6.7` | User pinned these versions; ORM v3 uses attribute mapping natively |
| `doctrine/dbal` | `^4.4.3` | Doctrine v4 for SQLite driver |
| `symfony/ai-bundle` + `symfony/ai-agent` + `symfony/ai-lm-studio-platform` | `>=0.10` | User wants official Symfony AI abstraction, not a hand-rolled OpenAI client. Config in `config/packages/ai.yaml` declares platform `lmstudio` + two agents (`tagger`, `summarizer`). |
| `aleblanc/simple-cron-scheduler` | `^0.2.0` | User's own package. **No Symfony Messenger** — user explicitly rejected it. Background work is plain periodic commands driven by cron |
| `fivefilters/readability.php` | `^4.1` | Pure-PHP readable extraction, always available (no Chrome needed). **API v4 note**: `Configuration` uses `originalURL:` (capital URL) not `originalUrl:`; `Readability::parse()` returns an `Article` with public readonly properties `->title`, `->textContent`, `->excerpt` — no `getTitle()` etc. |
| `paragonie/halite` | `^5.1` | Available for high-level crypto but the vault uses **raw `sodium_*` calls** directly (Argon2id password hashing + `crypto_secretbox` AEAD) — simpler and one less abstraction |
| `symfony/monolog-bundle` | `^4.1` | 4 named channels: `archive`, `ai`, `cron`, `api` — declared in `config/packages/monolog.yaml`. Injected as `@monolog.logger.<channel>` in services.yaml |
| `phpstan/phpstan` + `phpstan-doctrine` + `phpstan-symfony` | level 8 | Must stay green on every commit — no baseline entries |
| `friendsofphp/php-cs-fixer` | | `@Symfony:risky` + `declare_strict_types` + `strict_param`. `composer cs-check` / `composer cs-fix` |

## No user auth in v1

Explicit user requirement. There is **no** `User` entity, no Security firewall, no login form. Protection is expected to be upstream (nginx `auth_basic`, VPN, LAN-only).

The API has one optional guard: `ApiTokenSubscriber` (in `src/EventSubscriber/`). If `APP_API_TOKEN` env is set, requests to `/api/*` need `Authorization: Bearer <token>`; if empty, all requests pass. This lets the Linkwarden extension always send its Bearer header without breaking dev.

## Architecture at a glance

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

**Key insight**: `Link.status` and `Link.aiStatus` are the queue. The cron commands poll `WHERE status='pending'` — no Messenger transport, no broker, nothing to install beyond cron.

## The Chrome auto-detection contract

`src/Service/ChromeDetector.php` — reproduces the exact candidate list the user pasted. `availableFeatures()` returns a fixed-shape array that:
- Twig reads via `archive_features()` (see `FeaturesExtension`) to hide checkboxes in `templates/links/new.html.twig`.
- Each `AssetArchiverInterface` reads via its own `isEnabled()` to skip itself in `ArchiveRunner`.

**Never** call `Process` directly on Chromium anywhere else — always through an archiver that checks `isEnabled()` first.

## File layout

```
src/
├── Command/                    # 5 CLI commands (archive-pending, ai-tag-pending, create-vault, generate-secrets, retag)
├── Controller/
│   ├── Api/                    # /api/v1/* — envelope {"response": ...}, matches Linkwarden extension
│   └── Web/                    # Twig UI controllers
├── Entity/                     # 6 entities: Dashboard, Vault, Collection, Tag, Link, ArchiveAsset
├── EventListener/              # EncryptLinkListener (Doctrine lifecycle) for vault
├── EventSubscriber/            # ApiTokenSubscriber (optional Bearer guard)
├── Repository/                 # 6 repos with named methods (NEVER put queries in controllers)
├── Service/
│   ├── Ai/                     # AutoTagger wraps @ai.agent.tagger from ai-bundle
│   ├── Archiver/               # ArchiveRunner + 3 archivers + ReadableExtractor
│   ├── Favicon/
│   ├── Import/                 # Netscape HTML bookmarks importer
│   ├── Search/                 # LinkSearch (SQLite FTS5)
│   ├── Vault/                  # VaultCipher (sodium_*) + VaultSession
│   ├── ChromeDetector.php
│   └── CurrentDashboard.php
└── Twig/                       # FeaturesExtension exposes archive_features(), all_dashboards(), current_dashboard()

templates/                      # snake_case names, _prefix for partials
migrations/                     # initial migration seeds Perso + Pro dashboards and builds FTS5 index
tests/SmokeTest.php             # data-provider smoke test on every public URL + API endpoint
```

## Conventions to follow (Symfony best practices, non-negotiable)

- `declare(strict_types=1);` on every PHP file.
- `DateTimeImmutable`, never `DateTime`. Columns use `datetime_immutable` type.
- **All queries live in a repository method**, never in a controller. Controllers are glue.
- Entity getters that expose a collection return `array` (via `toArray()`), never `Doctrine\Common\Collections\Collection`. See `Link::getTags()`.
- Autowiring via constructor injection. Never `$container->get()`, never `$em->getRepository(X::class)`.
- Controllers are `final` and extend `AbstractController`.
- Repositories are `final` and extend `ServiceEntityRepository` with `@extends` PHPDoc for PHPStan.
- Attribute routing (`#[Route]`), never `routes.yaml`.
- Templates: `snake_case`, partials prefixed with `_`.
- `readonly` on injected service properties.
- PHPStan level 8 must stay green. If you add a service that expects a specific interface not yet available, wire it explicitly in `services.yaml` — don't rely on autowiring for it.

## Known gotchas

1. **SQLite PRAGMAs not applied yet.** `foreign_keys` and `journal_mode=WAL` are NOT set at connect time. The naive `options: 1002/1003` in doctrine.yaml did not work with `pdo_sqlite` — those are invalid PDO attribute constants for SQLite. A `PostConnect` listener needs to be added. Non-blocking: the app works, but `ON DELETE CASCADE` constraints in the schema aren't enforced by SQLite without the PRAGMA. TODO.

2. **Symfony version display shows 8.1.6** even though composer.json requires `8.2.x-dev` for several components. This is because many Symfony components don't have an 8.2 branch yet — they resolve to 8.1.x stable. This is expected and fine.

3. **`ai-agent` requires explicit install.** `symfony/ai-bundle` alone doesn't bring in the agent runtime — `composer require symfony/ai-agent` is separate. If `debug:container ai.agent.tagger` fails, that's why.

4. **Recipe files auto-created by Flex** for `ai-generic-platform` and `ai-lm-studio-platform` (in `config/packages/`) were **deleted** because they overrode our `ai.yaml`. If you re-run `composer require` on those packages, delete their yaml files again.

5. **Test DB is separate.** `.env.test` inherits `.env`'s DATABASE_URL but adds suffix `_test%env(default::TEST_TOKEN)%` — so the file is `var/data_test.db`. If a test fails with "no such table", run `APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction`.

6. **Vault encryption is opt-in per Collection.** A `Link` is only encrypted if `link.getCollection().getVault() !== null` AND the vault is unlocked in the current session. Persisting to a locked vault throws. Loading from a locked vault returns `[locked]` placeholders (see `EncryptLinkListener`).

7. **Netscape import** creates or reuses collections named after `<H3>` folders, all scoped to the currently active dashboard.

## Common commands

```bash
# Dev server
symfony server:start -d              # or: php -S 127.0.0.1:8000 -t public

# DB
php bin/console doctrine:migrations:migrate --no-interaction
APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction

# Background workers (would be cron on Pi)
php bin/console app:archive-pending --limit=20
php bin/console app:ai-tag-pending --limit=20

# One-time setup
php bin/console app:generate-secrets           # prints APP_API_TOKEN to paste in .env.local
php bin/console app:create-vault mysecrets -p 'strong-password'

# Quality gates
vendor/bin/phpstan analyse --memory-limit=512M
composer cs-check
vendor/bin/phpunit
```

## Production cron entry (target machine)

```
* * * * * cd /var/www/bookmarks && php bin/console simple-cron:run >> var/log/cron.log 2>&1
```

`config/packages/simple_cron_scheduler.yaml` (TODO — not yet written) declares two schedules:
- `app:archive-pending` every 5 minutes
- `app:ai-tag-pending` every 10 minutes

## Production nginx (target machine)

```nginx
server {
    listen 443 ssl http2;
    server_name bm.example.tld;

    root /var/www/bookmarks/public;
    index index.php;

    location / {
        auth_basic "Restricted";
        auth_basic_user_file /etc/nginx/.htpasswd;
        try_files $uri /index.php$is_args$args;
    }
    location /api/ {
        auth_basic off;                     # Bearer token protects the API
        try_files $uri /index.php$is_args$args;
    }
    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        internal;
    }
}
```

## The plan document

The full 30h implementation plan lives at `../docs/superpowers/plans/2026-09-06-symfony-bookmark-manager.md` (relative to this file — in the parent Linkwarden repo). Progress is 95% done; the remaining items are:
- SQLite PRAGMA listener
- `config/packages/simple_cron_scheduler.yaml` with the two schedules
- Optional Phase 10: semantic search via `sqlite-vec` extension
- README + install docs

## When in doubt

- Extension API contract → grep `packages/router/*.tsx` in the parent repo.
- Symfony AI bundle config keys → `vendor/symfony/ai-bundle/src/AiBundle.php` (search for `processAgentConfig`, `processPlatformConfig`).
- Readability.php v4 API → `vendor/fivefilters/readability.php/src/{Configuration,Readability,Article}.php`.
