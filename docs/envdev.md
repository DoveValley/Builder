# Environment & Dev — Getting Back In

> The everyday environment, access, and recovery reference for the Site Factory.

## General Login

### a) VPS login → Claude Code (the command line)

```bash
ssh root@187.127.254.206          # log into the server (uses your Mac's SSH key, no password typed)
cd /var/www/homepage-builder-new  # go to the project
claude                            # start Claude Code  (or: claude --continue / claude --resume)
```

- **Auth:** SSH key (`~/.ssh/id_ed25519`), or backup `~/.ssh/sitefactory_recovery`. No password.
- **This is for:** development — running Claude Code, editing files, server config.

### b) Site Factory admin (the web control panel)

- **URL:** <https://187.127.254.206/admin/login.php>  (self-signed cert → click "proceed")
- **User:** `admin`
- **Password:** your admin password
- **This is for:** building/editing sites, schedule, deploy — in the browser, no SSH needed.

---

**The difference:** (a) gets you *into the machine*; (b) gets you *into the website's control panel*. Different locks, different credentials. Full details are below.

## Reboot recovery

The key thing: **almost nothing lives on your Mac.** The Site Factory, Apache, PHP, the data, and Claude Code itself all run on the VPS (`187.127.254.206`). Your Mac is just the terminal you connect through — so rebooting it stops nothing.

### 1. The live site never went down

Apache is enabled to start on boot, so the admin and all sites keep serving regardless of your Mac. Just reopen:

- <https://187.127.254.206/admin/login.php> — self-signed cert, click "proceed"
- <http://187.127.254.206/admin/login.php> — plain HTTP fallback

Log in with `admin` + your password. Nothing to restart.

### 2. Get back to dev with Claude Code

Claude Code runs on the VPS. From a fresh Terminal on your Mac, reconnect over SSH and relaunch it:

```bash
ssh root@187.127.254.206           # your SSH login to the VPS
cd /var/www/homepage-builder-new   # the project / live webroot
claude                             # start Claude Code
```

To resume a previous session instead of starting fresh:

```bash
claude --resume      # pick a past session from a list
claude --continue    # jump back into the most recent one
```

**The whole recovery is: SSH in → `cd` to the project → `claude`.**

**If the VPS itself reboots (not just your Mac):** Apache auto-starts because it's enabled, so the live site comes back on its own. The only thing lost is the disposable `php -S` preview server (port 8099, used for screenshots) — it's not needed for production and is restarted on demand.

> Your Mac-side specifics may differ. The SSH command above assumes `root@187.127.254.206`. If you log in as a different user, with an SSH key, or on a non-standard port, swap those in. If you connect via VS Code Remote-SSH or a saved terminal profile, reopen that instead.

## Quick reference

The everyday entry points and health checks in one place.

| What | Where / command |
| --- | --- |
| Admin login (HTTPS) | <https://187.127.254.206/admin/login.php> |
| Admin login (HTTP) | <http://187.127.254.206/admin/login.php> |
| Your Sites list | `/admin/sites.php` |
| SSH into the VPS | `ssh root@187.127.254.206` |
| Project root | `/var/www/homepage-builder-new` |
| Start Claude Code | `claude` (or `claude --continue`) |
| Is the site up? | `systemctl is-active apache2` |
| Restart the web server | `sudo systemctl restart apache2` |
| Local preview server | `php -S localhost:8080 router.php` |

**Reset a forgotten admin password:** `php -r "echo password_hash('new-pass', PASSWORD_DEFAULT);"` and paste the hash into `config.php` as `ADMIN_PASSWORD_HASH`.

## Credentials & access backup

The two things that grant access to this environment — the **SSH key** and the **admin password** — must be backed up **off this server**. Their proper home is a password manager on your own devices (1Password, Bitwarden, Apple Passwords, KeePass — any of them).

### What lives where (and what can't be recovered)

- **SSH private key** — lives on your Mac under `~/.ssh/`. The server only holds the public half in `/root/.ssh/authorized_keys` (comment `claude-code-scottparr`). The private key cannot be read off the VPS.
- **Admin password** — stored only as a one-way bcrypt hash in `config.php`. The plaintext is not recoverable by anyone; it can only be reset.

Never store these on the server itself. A copy on the same VPS dies with the box (or leaks if it's compromised). Writing a private key or plaintext password into the webroot or committing it to Git is a security hole — don't. "Somewhere safe" means a password manager on your own devices.

### Reference note (non-secret) — save this in your vault

Connection details that are safe to store alongside the secrets:

```
SITE FACTORY — VPS ACCESS
  Host (IPv4):     187.127.254.206
  SSH user:        root
  SSH command:     ssh root@187.127.254.206
  Authorized key:  comment "claude-code-scottparr" (private key on the Mac, ~/.ssh)
  Project root:    /var/www/homepage-builder-new
  Admin (HTTPS):   https://187.127.254.206/admin/login.php   (self-signed → "proceed")
  Admin user:      admin
  Restart server:  sudo systemctl restart apache2
```

### Backup recovery SSH key

If your Mac holds the only authorized key, losing it locks you out of the VPS. The fix is a second "recovery" key kept in your password manager. Generate it on your Mac so the private half never touches the server or any transcript — then only the public half is added here:

```bash
# On your Mac:
ssh-keygen -t ed25519 -f ~/.ssh/sitefactory_recovery -C "recovery-key"
cat ~/.ssh/sitefactory_recovery.pub        # the public line to add on the server
```

Add that public line to the server's authorized keys (append — don't overwrite):

```bash
# On the VPS:
echo "ssh-ed25519 AAAA... recovery-key" >> /root/.ssh/authorized_keys
```

Store the private file `~/.ssh/sitefactory_recovery` in your password manager. Test it once (`ssh -i ~/.ssh/sitefactory_recovery root@187.127.254.206`) so you know it works before you need it.

> **Status (2026-06-30): done.** The recovery key was generated, its public half appended to `/root/.ssh/authorized_keys` (comment `recovery-key`), and a login was tested successfully. The server now has **two** authorized keys (`claude-code-scottparr` + `recovery-key`). The private file is at `~/.ssh/sitefactory_recovery` on the Mac — the remaining step is saving it into a password manager.

### Admin password backup

- **If you know it** — just save it to your password manager. Nothing to change.
- **If it's lost** — reset it to a value you choose, then store that:

```bash
php -r "echo password_hash('your-new-password', PASSWORD_DEFAULT);"
# paste the resulting hash into config.php as ADMIN_PASSWORD_HASH
```

## The three secrets — what each unlocks

There are exactly **three** secrets in this environment, and they open **two different locks**. Don't conflate them.

### 1. Your Mac's SSH private key — `~/.ssh/id_ed25519`

- **Unlocks:** SSH login to the **VPS command line** (`ssh root@187.127.254.206`) — where Claude Code, Apache, and all files live.
- **How:** A keypair, not a password. The private half stays on your Mac; the public half sits in `/root/.ssh/authorized_keys` (comment `claude-code-scottparr`). The handshake is automatic — you never type a password.
- **Lives:** On your Mac only. The server holds just the (useless-on-its-own) public half.
- **If lost:** ❌ Not recoverable — wipe the Mac without a backup and it's gone. That's why the recovery key (#2) exists.

### 2. The recovery SSH key — `~/.ssh/sitefactory_recovery`

- **Unlocks:** The **same lock as #1** — a second, independent key to the VPS command line.
- **Why:** Insurance. Before it existed, key #1 was the *only* way in. Now one key can fail and you still get in.
- **Lives:** Should live in your **password manager** (not just on the same Mac as #1 — a dead Mac would take both down).
- **If lost:** ❌ Not recoverable, but it's the backup — you'd just generate a new one.

### 3. The admin password — the web login

- **Unlocks:** The **web admin panel** in a browser (`https://187.127.254.206/admin/login.php`, user `admin`). A completely separate system from SSH — this is where you build/edit sites.
- **How:** Typed into a web form, checked against a one-way **bcrypt hash** in `config.php` (`ADMIN_PASSWORD_HASH`). The server never stores the plaintext.
- **Lives:** In your head / password manager only. The plaintext is nowhere on the server.
- **If lost:** ❌ Not recoverable, ✅ but resettable — overwrite the hash (see "Admin password backup" above).

| | #1 Mac SSH key | #2 Recovery SSH key | #3 Admin password |
| --- | --- | --- | --- |
| **Unlocks** | VPS command line | VPS command line (backup door) | Web admin panel |
| **Type** | Keypair (no typing) | Keypair (no typing) | Typed password → bcrypt hash |
| **Lives on** | Mac only | Password manager | Head / password manager (hash on server) |
| **If lost** | Gone forever | Gone forever (it's the backup) | Can't recover, but can reset |

**One-liner:** SSH keys get you *into the machine*; the admin password gets you *into the website's control panel*. There is **no database password** (the system uses JSON files, no DB) and **no separate VPS root password in normal use** (SSH is key-based) — though the host can set one, see below.

## Provider-level recovery (Hostinger)

The "gone forever" warnings above apply to the SSH keys themselves. But the **hosting provider sits above the keys** and can always get you back in — even with zero working SSH keys. The VPS is a Hostinger box (`srv1793661`), managed from **hPanel**.

- **Browser console (the big one):** hPanel → VPS → **Browser terminal / Console** connects through Hostinger's infrastructure, *not* SSH — like plugging a keyboard into the machine. Log in with the **root password** (set in hPanel) and you get a root shell with no working SSH key at all. Then just re-add a key and you're back to normal:
  ```bash
  echo "ssh-ed25519 AAAA...new-key" >> /root/.ssh/authorized_keys
  ```
- **Reset the root password:** hPanel can set/reset the VPS root password anytime, which is what you'd use to log into the browser console.
- **Rebuild / reinstall OS (nuclear):** hPanel can wipe and reinstall the server. ⚠️ This **erases all sites and data** — true last resort only.

### What the *real* lockout risk is

| If you lose… | Recover via |
| --- | --- |
| Mac SSH key | Recovery key (#2), **or** Hostinger console |
| **Both** SSH keys | **Hostinger console** — log in, add a fresh key |
| Hostinger account login | Hostinger account recovery (their email / 2FA) |
| Hostinger account **and** all keys **and** backups | Genuinely stuck |

The thing that truly can't be bypassed is **not** your SSH key — it's your **Hostinger account itself**. As long as you can log into hPanel, you can always regain server access. So the single most important credential to protect is your **Hostinger login + 2FA recovery codes**.

## Checklist for "I can always get back in"

In priority order (most important first):

1. **Hostinger account** — strong password + 2FA, and the **2FA recovery codes saved in your password manager**. This is the ultimate fallback that bypasses lost SSH keys.
2. **Recovery SSH key** (`~/.ssh/sitefactory_recovery`) in your password manager. *(Key generated & tested 2026-06-30 — just needs storing.)*
3. **Admin web password** in your password manager.
4. The **reference note** above (host, paths, URLs) saved next to them.
5. *(Optional)* Main SSH key (`~/.ssh/id_ed25519`) backed up too — the recovery key already covers this door.
