#!/usr/bin/env bash
# Nightly off-box backup of the factory's irreplaceable state → the dirnet vault.
#
# WHAT IS AT RISK. Everything the Infrastructure console knows lives in two places
# that are deliberately gitignored and were, until this script, single-copy on one
# machine with no backup anywhere:
#
#   admin/infra/state/fleet.db     431 domains, 156 of them owned and paid for,
#                                  10,000 cities, 7,692 city_niche rows, and
#                                  D.Finder's 627 candidates.
#   admin/infra/config/*.json      the 0600 files: Hestia access/secret key pairs
#                                  for all 20 servers, registrar API keys,
#                                  Cloudflare, Hetzner, keyword providers.
#
# Gitignoring them is correct — live infrastructure state and credentials do not
# belong in a repo that gets pushed to GitHub. But the consequence was that losing
# this one VPS meant losing the domain registry AND the ability to reach the fleet.
# The data and the keys are backed up together on purpose: a restore that returns
# your records but not your access to the servers is half a recovery.
#
# HOW THE DATABASE IS COPIED. VACUUM INTO, not cp. fleet.db runs in WAL mode, so a
# plain copy can catch it mid-transaction with its changes still in the -wal file —
# a backup that restores to a torn state, and you would not find out until you
# needed it. VACUUM INTO takes a read lock and writes a consistent, compacted
# database. Needs SQLite >= 3.27; this box has 3.45.1 through PHP, so nothing has
# to be installed.
#
# Matches scripts/backup-db.sh: same vault, same key, same ~/backups, same rotation.
set -euo pipefail

FACTORY=/var/www/homepage-builder-new
KEY=/root/.ssh/hostinger_vps
VPS=deploy@2.24.99.167
KEEP=14

TS=$(date +%Y%m%d_%H%M%S)
WORK=$(mktemp -d)
# The archive carries live credentials. Anything on the way to the tarball is
# readable only by root, and the trap fires on failure too — an aborted run must
# not leave Hestia key pairs lying in /tmp.
chmod 700 "$WORK"
trap 'rm -rf "$WORK"' EXIT

ARCHIVE="/tmp/factory_state_${TS}.tar.gz"

# 1 — consistent database snapshot
php -r '
$src = $argv[1]; $dst = $argv[2];
$db = new PDO("sqlite:" . $src);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("VACUUM INTO " . $db->quote($dst));
' "$FACTORY/admin/infra/state/fleet.db" "$WORK/fleet.db"

# Prove the copy opens and has the rows, rather than shipping whatever landed.
php -r '
$db = new PDO("sqlite:" . $argv[1]);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$n = $db->query("SELECT COUNT(*) FROM domains")->fetchColumn();
if ($n < 1) { fwrite(STDERR, "backup has no domains — refusing to ship it\n"); exit(1); }
' "$WORK/fleet.db"

# 2 — credentials (the real ones only; *.example.json are in the repo already)
mkdir -p "$WORK/config"
shopt -s nullglob
for f in "$FACTORY"/admin/infra/config/*.json; do
    case "$f" in *.example.json) continue;; esac
    cp -p "$f" "$WORK/config/"
done

# 3 — one archive, root-only
tar -czf "$ARCHIVE" -C "$WORK" fleet.db config
chmod 600 "$ARCHIVE"

# 4 — off the box
ssh -i "$KEY" -o BatchMode=yes -o StrictHostKeyChecking=accept-new "$VPS" 'mkdir -p ~/backups/factory && chmod 700 ~/backups/factory'
scp -i "$KEY" -o BatchMode=yes -o StrictHostKeyChecking=accept-new "$ARCHIVE" "$VPS:~/backups/factory/"
ssh -i "$KEY" -o BatchMode=yes "$VPS" "chmod 600 ~/backups/factory/$(basename "$ARCHIVE")"

# 5 — rotate, newest $KEEP kept
ssh -i "$KEY" -o BatchMode=yes "$VPS" "ls -1t ~/backups/factory/factory_state_*.tar.gz 2>/dev/null | tail -n +$((KEEP+1)) | xargs -r rm -f"

SIZE=$(du -h "$ARCHIVE" | cut -f1)
rm -f "$ARCHIVE"
echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) backup ok: factory_state_${TS}.tar.gz ($SIZE)"
