# Troubleshooting

| Symptom | Cause / fix |
|---|---|
| `SQLSTATE[HY000]: General error: 1 no such table: links` when running tests | Test DB not migrated. Run `APP_ENV=test php bin/console doctrine:migrations:migrate --no-interaction`. |
| Web page loads but API returns `401 Invalid or missing token` | `APP_API_TOKEN` is set but the extension sends a different token (or none). Match them in `.env.local` and the extension settings. |
| Extension shows "Cannot connect" | Check the instance URL (must include the scheme, e.g. `http://192.168.1.10:8000`). If nginx `auth_basic` is on `/api/`, disable it there. |
| `debug:container ai.agent.tagger` fails | Missing `symfony/ai-agent` dep. Run `composer require symfony/ai-agent`. |
| AI cron logs `ai tagging failed: Connection refused` | Wrong `LM_STUDIO_HOST_URL`, LM Studio server not running, or "Serve on Local Network" is off. |
| Chrome archival never produces files | `ChromeDetector` didn't find the binary. Check `php bin/console debug:container App\\Service\\ChromeDetector` and paths in `.env` (`APP_CHROME_PATH`). |
| Everything works in dev, prod page is blank | `APP_ENV=prod` requires a warm cache. Run `php bin/console cache:warmup --env=prod` and check `var/log/prod-*.log`. |

See also [CLAUDE.md](../CLAUDE.md) for architectural notes and design decisions.
