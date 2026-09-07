# Encrypted vault

A vault lets you attach a password to selected collections. Their links' URL, title, description and readable text are encrypted at rest with `crypto_secretbox` (libsodium XSalsa20-Poly1305). The password derives the key via Argon2id (`crypto_pwhash`).

## Create a vault

```bash
php bin/console app:create-vault mysecrets --password='correct horse battery staple'
```

## Attach a collection to it

Not yet exposed in the UI — do it manually in SQLite for now:

```bash
sqlite3 var/data_prod.db "UPDATE collections SET vault_id = 1 WHERE name = 'Finance';"
```

## Use it

Visit `/vault/1/unlock` and enter the password. The derived key is kept in the PHP session for **15 minutes**, then the vault re-locks automatically.

While a vault is locked, its collections still appear in the sidebar, but their links are shown as `[locked]` placeholders — the URL and title are ciphered and nothing is decryptable server-side without the password. Once you unlock the vault, the links become readable again for the duration of the session.

**If you forget the password, the data is unrecoverable.** No backdoor.
