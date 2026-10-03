---
kind: external_dependency
name: ufw — Host firewall managed by the installer
slug: ufw
category: external_dependency
category_hints:
    - client_constraint
scope:
    - '**'
source_files:
    - deploy/scripts/install-sbc.sh
    - DEPLOYMENT.md
---

### Identity
Uncomplicated Firewall (ufw) is the host firewall used on Debian/Armbian/Ubuntu SBCs.

### Role
The installer adds rules allowing `80/tcp` and `443/tcp` and reloads ufw **only if already active** — it deliberately never auto-enables ufw to avoid locking out SSH during initial setup.

### Client constraint
On Armbian images that ship `nftables`/`firewalld` instead of ufw, the installer's rules may not take effect; operators must open ports manually via their native firewall tool. The guide documents the equivalent `nft add rule inet filter input tcp dport {80,443} accept` command.