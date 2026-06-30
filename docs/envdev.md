# Environment & Dev — Getting Back In

> The everyday environment, access, and recovery reference for the Site Factory.

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

### Admin password backup

- **If you know it** — just save it to your password manager. Nothing to change.
- **If it's lost** — reset it to a value you choose, then store that:

```bash
php -r "echo password_hash('your-new-password', PASSWORD_DEFAULT);"
# paste the resulting hash into config.php as ADMIN_PASSWORD_HASH
```

### Checklist for "I can always get back in"

1. SSH private key in a password manager
2. A tested backup recovery key in the password manager
3. The admin password in the password manager
4. The reference note above saved next to them
