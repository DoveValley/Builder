<?php
/**
 * infra/bootstrap.php — the ONLY factory touchpoint: shares the admin login
 * session via config.php (session_start + auth). No factory domain logic is used.
 * Everything else in admin/infra/ is self-contained.
 */
require_once __DIR__ . '/../../config.php';   // session_start() + ADMIN_* constants

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: ../login.php');
    exit;
}

if (empty($_SESSION['infra_csrf'])) {
    $_SESSION['infra_csrf'] = bin2hex(random_bytes(32));
}

require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/http.php';
require_once __DIR__ . '/lib/plesk.php';
require_once __DIR__ . '/lib/cloudflare.php';
require_once __DIR__ . '/lib/fleet.php';

function ih($s): string { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function infra_csrf(): string { return $_SESSION['infra_csrf']; }
function infra_check_csrf(): bool { return isset($_POST['csrf']) && hash_equals($_SESSION['infra_csrf'] ?? '', $_POST['csrf']); }

function infra_set_flash(string $type, string $msg): void { $_SESSION['infra_flash'] = ['type' => $type, 'msg' => $msg]; }
function infra_render_flash(): void
{
    if (empty($_SESSION['infra_flash'])) return;
    $f = $_SESSION['infra_flash']; unset($_SESSION['infra_flash']);
    $bg = $f['type'] === 'ok' ? '#dcfce7;border-color:#86efac;color:#166534'
        : ($f['type'] === 'err' ? '#fee2e2;border-color:#fca5a5;color:#991b1b'
        : '#fef9c3;border-color:#fde047;color:#854d0e');
    echo '<div style="white-space:pre-wrap;border:1px solid;border-radius:8px;padding:12px 14px;margin-bottom:16px;font-size:13px;background:' . $bg . '">' . ih($f['msg']) . '</div>';
}

function infra_header(string $active = 'dashboard'): void
{
    $nav = [
        'dashboard'  => ['label' => 'Dashboard',  'href' => 'index.php'],
        'domains'    => ['label' => 'Domains',     'href' => 'index.php?view=domains'],
        'new'        => ['label' => '+ New Site',  'href' => 'index.php?view=new'],
        'plesk'      => ['label' => 'Plesk',       'href' => 'index.php?view=plesk'],
        'cloudflare' => ['label' => 'Cloudflare',  'href' => 'index.php?view=cloudflare'],
        'golive'     => ['label' => 'Go-Live',     'href' => 'index.php?view=golive'],
    ];
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>Infrastructure — Console</title>';
    echo '<style>' . infra_css() . '</style></head><body>';
    echo '<header class="ic-top"><div class="ic-brand">🛠 Infrastructure</div><nav class="ic-nav">';
    foreach ($nav as $k => $n) {
        $cls = $k === $active ? 'active' : '';
        echo '<a class="' . $cls . '" href="' . ih($n['href']) . '">' . ih($n['label']) . '</a>';
    }
    echo '</nav><a class="ic-back" href="../index.php">&larr; Back to Factory</a></header>';
    echo '<main class="ic-main">';
    infra_render_flash();
}

function infra_footer(): void { echo '</main></body></html>'; }

/** Shared client-side table filter for any <input class="ic-search" data-target="tableId">. */
function infra_search_js(): void
{
    echo '<script>document.querySelectorAll(".ic-search").forEach(function(b){'
       . 'b.addEventListener("input",function(){var q=this.value.toLowerCase();'
       . 'var t=document.getElementById(this.dataset.target);if(!t)return;'
       . 't.querySelectorAll("tbody tr").forEach(function(tr){'
       . 'tr.style.display=tr.textContent.toLowerCase().indexOf(q)>-1?"":"none";});});});</script>';
}

function infra_css(): string
{
    return <<<CSS
*{box-sizing:border-box}body{margin:0;font:14px/1.5 -apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1f2937;background:#f3f4f6}
.ic-top{display:flex;align-items:center;gap:24px;background:#111827;color:#fff;padding:0 20px;height:52px}
.ic-brand{font-weight:700;font-size:15px}
.ic-nav{display:flex;gap:4px;flex:1}
.ic-nav a{color:#cbd5e1;text-decoration:none;padding:6px 12px;border-radius:6px;font-weight:600}
.ic-nav a:hover{background:#1f2937;color:#fff}.ic-nav a.active{background:#2563eb;color:#fff}
.ic-back{color:#9ca3af;text-decoration:none;font-size:13px}.ic-back:hover{color:#fff}
.ic-main{max-width:1200px;margin:0 auto;padding:24px 20px}
.ic-tiles{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px;margin-bottom:24px}
.ic-tile{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:16px}
.ic-tile .n{font-size:28px;font-weight:700;line-height:1}.ic-tile .l{color:#6b7280;font-size:12px;text-transform:uppercase;letter-spacing:.04em;margin-top:6px}
.ic-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;margin-bottom:16px;overflow:hidden}
.ic-card>h2{margin:0;padding:14px 16px;font-size:15px;border-bottom:1px solid #f0f0f0;display:flex;align-items:center;gap:10px}
.ic-card .body{padding:14px 16px}
.badge{display:inline-block;padding:2px 9px;border-radius:999px;font-size:12px;font-weight:600}
.b-ok{background:#dcfce7;color:#166534}.b-warn{background:#fef9c3;color:#854d0e}.b-err{background:#fee2e2;color:#991b1b}.b-mut{background:#e5e7eb;color:#374151}
table{width:100%;border-collapse:collapse;font-size:13px}th,td{text-align:left;padding:8px 10px;border-bottom:1px solid #f0f0f0}
th{color:#6b7280;font-size:11px;text-transform:uppercase;letter-spacing:.04em}
tr:hover td{background:#f9fafb}code{background:#f3f4f6;padding:1px 5px;border-radius:4px;font-size:12px}
.ic-search{width:100%;max-width:320px;padding:8px 10px;border:1px solid #d1d5db;border-radius:8px;margin-bottom:10px}
.ic-empty{color:#6b7280;padding:16px;text-align:center}
.ic-note{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af;padding:10px 14px;border-radius:8px;font-size:13px;margin-bottom:16px}
.btn{display:inline-block;background:#2563eb;color:#fff;text-decoration:none;padding:7px 14px;border-radius:8px;font-weight:600;font-size:13px;border:0;cursor:pointer}
.btn.sec{background:#e5e7eb;color:#111827}
CSS;
}
