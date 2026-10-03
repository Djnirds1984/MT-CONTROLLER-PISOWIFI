---
kind: external_dependency
name: libsodium — Encryption primitive for secrets at rest
slug: libsodium
category: external_dependency
category_hints:
    - auth_protocol
scope:
    - '**'
source_files:
    - includes/crypto.php
    - DEPLOYMENT.md
---

### Identity
PHP's built-in `sodium_*` extension provides the crypto primitives used by `includes/crypto.php`.

### Role
- Encrypts stored router passwords with XSalsa20-Poly1305 (`sodium_crypto_secretbox`) using a 32-byte key at `/etc/aircoins/secret.key` (mode `0400`, owned by `www-data`).
- Generates the key on first install if missing.
- Decryption happens only in memory for a single API call; plaintext is never persisted or rendered.

### Auth/storage shape
- Key rotation invalidates all stored router passwords (they become unrecoverable without backup of `secret.key`).
- The sodium key must be backed up alongside the SQLite database.