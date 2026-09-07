# Development

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
