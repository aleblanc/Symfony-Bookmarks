# Architecture

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

See also [CLAUDE.md](../CLAUDE.md) for the full design notes and non-obvious constraints.
