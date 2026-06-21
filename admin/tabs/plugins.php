<?php
// Plugins tab — directory + per-plugin panel.
// $tab, $activePlugin, $csrfToken available from index.php.

$registeredPlugins = get_plugins();
$activePluginData  = $activePlugin !== '' ? get_plugin($activePlugin) : null;
?>
<div class="tab-content" style="<?= $tab === 'plugins' ? '' : 'display:none;' ?>">

<?php if ($activePluginData === null): ?>

    <div class="card">
        <h2>Plugins</h2>
        <p class="hint" style="margin-bottom:18px;">Optional features that extend your site. Click a plugin to configure it.</p>
        <?php if (empty($registeredPlugins)): ?>
            <p class="hint">No plugins installed yet.</p>
        <?php else: ?>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px;">
            <?php foreach ($registeredPlugins as $pid => $plugin): ?>
            <a href="?tab=plugins&plugin=<?= h($pid) ?>" style="text-decoration:none;color:inherit;">
                <div style="border:1px solid #e5e7eb;border-radius:8px;padding:18px 20px;background:#fff;transition:box-shadow .15s,border-color .15s;"
                     onmouseover="this.style.boxShadow='0 2px 10px rgba(0,0,0,0.08)';this.style.borderColor='#d1d5db';"
                     onmouseout="this.style.boxShadow='';this.style.borderColor='#e5e7eb';">
                    <div style="font-size:1.6rem;margin-bottom:8px;"><?= h($plugin['icon']) ?></div>
                    <div style="font-weight:600;font-size:1rem;color:#111827;margin-bottom:4px;"><?= h($plugin['name']) ?></div>
                    <div style="font-size:.82rem;color:#6b7280;line-height:1.45;"><?= h($plugin['description']) ?></div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

<?php else: ?>

    <div style="margin-bottom:14px;display:flex;align-items:center;gap:8px;font-size:.875rem;color:#6b7280;">
        <a href="?tab=plugins" style="color:#6b7280;text-decoration:none;">&larr; Plugins</a>
        <span>/</span>
        <span style="color:#111827;font-weight:500;"><?= h($activePluginData['name']) ?></span>
    </div>

    <?php
    $panelFile = $activePluginData['dir'] . '/panel.php';
    if (file_exists($panelFile)) {
        require $panelFile;
    } else {
        echo '<div class="card"><p class="hint">This plugin has no admin panel.</p></div>';
    }
    ?>

<?php endif; ?>

</div>
