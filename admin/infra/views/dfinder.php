<?php
/**
 * infra/views/dfinder.php — D.Finder, the domain workbench.
 *
 * Bulk .com name generation across service niches: a personal name joined to a
 * service keyword (carverappliancerepair.com), with a registry that remembers
 * every name already spent so the same one is never proposed twice.
 *
 * The app itself is React, ported from a Claude.ai artifact — the source is
 * assets/js/src/domain-workbench.jsx and the built file beside it is what loads.
 * Nothing transpiles at request time; see the source header for the build command.
 *
 * This file is only the frame: console chrome, the mount point, and the two
 * endpoints plus a CSRF token handed over in one config object. All the app's own
 * styling is scoped under .dw inside the component, so it cannot leak into the
 * rest of the console and the console's CSS cannot reach into it.
 */

// React first, then the app: the component reads the React global at parse time.
// defer on all three keeps that order while letting the page paint first.
$dwBase = '../../assets/';   // views/ -> admin/infra/ -> admin/ -> webroot

/**
 * Cache-bust by the file's own modification time.
 *
 * Apache serves these with an ETag and Last-Modified but NO Cache-Control, so a
 * browser applies its own heuristic and can keep serving a stale copy from disk
 * without ever asking whether it changed. A rebuilt workbench therefore looked
 * like a change that had not happened — the file on the server was new, the
 * screen was old, and nothing about that is visible from either end.
 *
 * A new build changes the mtime, which changes the URL, which is a different
 * resource as far as the cache is concerned. No headers to configure and nothing
 * to remember at build time.
 */
$dwV = function (string $rel) use ($dwBase): string {
    $abs = __DIR__ . '/../../../assets/' . $rel;
    $t   = is_file($abs) ? filemtime($abs) : 0;
    return $dwBase . $rel . ($t ? '?v=' . $t : '');
};

infra_header('dfinder');
?>
<script>
  /* Everything the app needs from the server, in one place. The URLs are
     relative to admin/infra/, which is where index.php runs — not to this file. */
  window.DW_CONFIG = {
    stateUrl: 'actions/dfinder_state.php',
    aiUrl:    'actions/dfinder_ai.php',
    checkUrl: 'actions/dfinder_check.php',
    dbuyUrl:  'actions/dfinder_send_to_dbuy.php',
    csrf:     <?= json_encode(infra_csrf()) ?>
  };
</script>
<script src="<?= ih($dwV('vendor/react.production.min.js')) ?>" defer></script>
<script src="<?= ih($dwV('vendor/react-dom.production.min.js')) ?>" defer></script>
<script src="<?= ih($dwV('js/domain-workbench.js')) ?>" defer></script>

<!-- Full width: the workbench is a three-column layout of its own and the
     console's 1200px main column would squeeze it. Negative margins undo
     .ic-main's padding rather than changing it for every other view. -->
<div id="dw-root" style="margin:-24px -20px;">
  <div style="padding:40px;font-family:ui-monospace,monospace;font-size:13px;color:#6b7280">
    Opening workbench…
  </div>
</div>
<?php infra_footer(); exit;
