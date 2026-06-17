<?php
// Popup render — included by the body_end hook in plugin.php.
// $infoPopup and $pfx are in scope from the enclosing closure.

if (!function_exists('renderPopupBody')) {
    function renderPopupBody(string $text): string {
        $text = trim($text);
        if ($text === '') return '';
        if (preg_match('/<[a-z][\s\S]*>/i', $text)) {
            return sanitize_rich_html($text);
        }
        $safe = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $safe = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $safe);
        $safe = preg_replace(
            '/\bhttps?:\/\/[^\s<>"]+/i',
            '<a href="$0" target="_blank" rel="noopener">$0</a>',
            $safe
        );
        $safe = preg_replace(
            '/\b(?<!href=["\'])(?<!\/\/)([a-z0-9][a-z0-9\-]*\.[a-z]{2,}(?:\/[^\s<"]*)?)\b/i',
            '<a href="https://$1" target="_blank" rel="noopener">$1</a>',
            $safe
        );
        $safe = preg_replace(
            '/\((\d{3})\)\s*(\d{3})-(\d{4})/',
            '<a href="tel:+1$1$2$3">($1) $2-$3</a>',
            $safe
        );
        $paras = preg_split('/\n\s*\n/', trim($safe));
        $html = '';
        foreach ($paras as $p) { $p = trim($p); if ($p !== '') $html .= '<p>' . nl2br($p) . '</p>'; }
        return $html;
    }
}
?>
<div class="info-popup-overlay" id="infoPopupOverlay" onclick="if(event.target===this)closeInfoPopup()">
    <div class="info-popup-box" role="dialog" aria-modal="true">
        <button class="info-popup-close" onclick="closeInfoPopup()" aria-label="Close">&times;</button>
        <?php if (!empty($infoPopup['image'])): ?>
            <img class="info-popup-image" src="<?= h($pfx . $infoPopup['image']) ?>" alt="<?= h(resolve_shortcodes($infoPopup['heading'] ?? '')) ?>">
        <?php endif; ?>
        <div class="info-popup-content">
            <h2 class="info-popup-heading"><?= h(resolve_shortcodes($infoPopup['heading'] ?? '')) ?></h2>
            <div class="info-popup-body"><?= renderPopupBody(resolve_shortcodes($infoPopup['body'] ?? '')) ?></div>
        </div>
    </div>
</div>
<script>
(function() {
    window.openInfoPopup = function() {
        var overlay = document.getElementById('infoPopupOverlay');
        if (overlay) {
            overlay.classList.add('is-open');
            document.body.style.overflow = 'hidden';
        }
    };
    window.closeInfoPopup = function() {
        var overlay = document.getElementById('infoPopupOverlay');
        if (overlay) {
            overlay.classList.remove('is-open');
            document.body.style.overflow = '';
        }
    };
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeInfoPopup();
    });
})();
</script>
