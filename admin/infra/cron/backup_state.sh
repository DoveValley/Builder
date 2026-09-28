#!/usr/bin/env bash
# Nightly off-box backup of the factory's irreplaceable state → the dirnet vault.
#
# WHAT IS AT RISK. Everything the Infrastructure console knows, plus every batch's
# real target list and every uploaded image, lives in places that are deliberately
# gitignored and were, until this script (and its 2026-09-28 extension), single-copy
# on one machine with no backup anywhere:
#
#   admin/infra/state/fleet.db     431 domains, 156 of them owned and paid for,
#                                  10,000 cities, 7,692 city_niche rows, and
#                                  D.Finder's 627 candidates.
#   admin/infra/config/*.json      the 0600 files: Hestia access/secret key pairs
#                                  for all 20 servers, registrar API keys,
#                                  Cloudflare, Hetzner, keyword providers.
#   sites/{niche}/batches/         every batch's target list (business/city/state/
#                                  FTP data per domain) for the 4 real niche
#                                  masters — gitignored because params.csv carries
#                                  ftp_pass in plaintext, so this was the one copy.
#   uploads/                       every uploaded photo/logo across every site —
#                                  small (~10MB) but much of it not regenerable
#                                  exactly, or only at real AI-image-generation cost.
#   ~/.claude/projects/.../memory/ Claude Code's own persistent memory for this
#                                  project - months of accumulated project state,
#                                  feedback, and lessons. Outside the repo entirely
#                                  (a different tool's state, not this app's), so
#                                  nothing else was ever going to catch it.
#   .ai_key / .openai_key /        Anthropic, OpenAI, and CTM credentials - real
#   .ctm_key / .ctm_secret         secrets, sitting at the repo root, never
#                                  touched by the admin/infra/config/ sweep above.
#   sites/{site}/deploy.json       FTP deploy creds per SITE, not just per batch -
#                                  this is the only path standalone sites outside
#                                  the batch system (recovery-site, granitepmacademy)
#                                  have for their deploy credentials at all.
#   multisite/batch_seq.json       the fleet-wide batch sequence counter (what
#                                  actually produced "Batch 28/29/30") - tiny, but
#                                  losing it risks duplicate sequence numbers later.
#
# Gitignoring them is correct — live infrastructure state and credentials do not
# belong in a repo that gets pushed to GitHub. But the consequence was that losing
# this one VPS meant losing the domain registry AND the ability to reach the fleet
# AND every batch's real target list AND every uploaded image. The data and the
# keys are backed up together on purpose: a restore that returns your records but
# not your access to the servers is half a recovery.
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
CLAUDE_MEMORY=/root/.claude/projects/-var-www-homepage-builder-new/memory
KEY=/root/.ssh/hostinger_vps
VPS=deploy@2.24.99.167
KEEP=14

# The 4 real niche masters — named explicitly rather than globbing sites/*/batches/,
# so a stray backup-copy directory (e.g. appliance-site-backup-*, appliance-site-
# fixed-version — untracked leftovers found sitting in the repo, not part of the
# real fleet) can never get swept into the backup by accident.
NICHE_MASTERS=(mold-site pest-template water-site appliance-site)

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
# Root-level key files + the (likely stale, cheap to keep anyway) Plesk config -
# same "config" folder, they're all credentials of one kind or another.
for f in "$FACTORY"/.ai_key "$FACTORY"/.openai_key "$FACTORY"/.ctm_key "$FACTORY"/.ctm_secret "$FACTORY"/config/plesk.json; do
    [ -f "$f" ] && cp -p "$f" "$WORK/config/"
done

# Per-SITE deploy.json (FTP creds) - the only place standalone sites outside the
# batch system (recovery-site, granitepmacademy) keep theirs at all. Every real
# site directory, minus the known stray backup-copy dirs from an earlier session
# (appliance-site-backup-*, appliance-site-fixed-version) that are not part of
# the real fleet. Same filename in every site dir, so staged under the site id
# to avoid one overwriting the next.
mkdir -p "$WORK/deploy_json"
for f in "$FACTORY"/sites/*/deploy.json; do
    site=$(basename "$(dirname "$f")")
    case "$site" in appliance-site-backup-*|appliance-site-fixed-version) continue;; esac
    cp -p "$f" "$WORK/deploy_json/$site.json"
done

# The fleet-wide batch sequence counter - what actually produced "Batch 28/29/30".
[ -f "$FACTORY/multisite/batch_seq.json" ] && cp -p "$FACTORY/multisite/batch_seq.json" "$WORK/"

# Claude Code's own memory for this project - a different tool, a different
# directory tree entirely (outside $FACTORY), so it's staged into $WORK under a
# clear name rather than archived in place under its generic "memory" basename.
if [ -d "$CLAUDE_MEMORY" ]; then
    cp -rp "$CLAUDE_MEMORY" "$WORK/claude_memory"
fi

# 3 — batches (real niche masters only) + uploads, straight from $FACTORY —
# no need to stage a copy in $WORK first, tar reads them in place.
TAR_ARGS=(-C "$WORK" fleet.db config deploy_json)
if [ -f "$WORK/batch_seq.json" ]; then
    TAR_ARGS+=(batch_seq.json)
fi
if [ -d "$WORK/claude_memory" ]; then
    TAR_ARGS+=(claude_memory)
fi
TAR_ARGS+=(-C "$FACTORY" uploads)
for m in "${NICHE_MASTERS[@]}"; do
    if [ -d "$FACTORY/sites/$m/batches" ]; then
        TAR_ARGS+=("sites/$m/batches")
    fi
done

# 4 — one archive, root-only
tar -czf "$ARCHIVE" "${TAR_ARGS[@]}"
chmod 600 "$ARCHIVE"

# 5 — off the box
ssh -i "$KEY" -o BatchMode=yes -o StrictHostKeyChecking=accept-new "$VPS" 'mkdir -p ~/backups/factory && chmod 700 ~/backups/factory'
scp -i "$KEY" -o BatchMode=yes -o StrictHostKeyChecking=accept-new "$ARCHIVE" "$VPS:~/backups/factory/"
ssh -i "$KEY" -o BatchMode=yes "$VPS" "chmod 600 ~/backups/factory/$(basename "$ARCHIVE")"

# 6 — rotate, newest $KEEP kept
ssh -i "$KEY" -o BatchMode=yes "$VPS" "ls -1t ~/backups/factory/factory_state_*.tar.gz 2>/dev/null | tail -n +$((KEEP+1)) | xargs -r rm -f"

SIZE=$(du -h "$ARCHIVE" | cut -f1)
rm -f "$ARCHIVE"
echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) backup ok: factory_state_${TS}.tar.gz ($SIZE)"
