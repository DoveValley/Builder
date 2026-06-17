<?php
// Popups plugin — info popup triggered by the ℹ️ button in nav/sticky bar.

register_plugin(
    'popups',
    'Info Popup',
    'Show an info popup when visitors click the ℹ️ button in the nav or sticky bottom bar. Use it for call handling disclosures or quick service-area info.',
    '&#8505;',
    __DIR__
);

// Render popup HTML + JS before </body> when the popup is enabled and has content.
add_hook('body_end', function(array $data, string $pfx): void {
    $infoPopup = $data['popups']['info'] ?? [];
    if (empty($infoPopup['enabled'])) return;
    if (empty($infoPopup['heading']) && empty($infoPopup['body'])) return;
    require __DIR__ . '/render.php';
}, 5);
