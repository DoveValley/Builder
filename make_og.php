<?php
// Generates og-image.png (1200×630) for Granite PM Academy homepage.
// Run from project root: php make_og.php
$W = 1200; $H = 630;
$out = __DIR__ . '/sites/granitepmacademy/uploads/og-image.png';

$img = imagecreatetruecolor($W, $H);

// ── Full-bleed background photo, pixel-darkened ───────────────────────────────
$navyR = 13; $navyG = 31; $navyB = 60;

$heroSrc = imagecreatefromwebp(__DIR__ . '/sites/granitepmacademy/uploads/media/hero_professional.webp');
// Center-crop vertically: source 1200×800 → dest 1200×630, take middle 630 rows
$srcY = (int)((800 - 630) / 2); // 85
imagecopyresampled($img, $heroSrc, 0, 0, 0, $srcY, $W, $H, 1200, 630);
imagedestroy($heroSrc);

// Pixel pass: darken photo + heavy navy blend on left, lighter on right
for ($x = 0; $x < $W; $x++) {
    // leftBlend: 1.0 = pure navy (x=0), 0.0 = no extra blend (x≥800)
    $leftBlend = max(0.0, 1.0 - $x / 800.0);
    for ($y = 0; $y < $H; $y++) {
        $px = imagecolorat($img, $x, $y);
        $r  = ($px >> 16) & 0xFF;
        $g  = ($px >> 8)  & 0xFF;
        $b  =  $px        & 0xFF;
        // Base darken (right side shows subject at ~55% brightness)
        $r = (int)($r * 0.55);
        $g = (int)($g * 0.55);
        $b = (int)($b * 0.55);
        // Blend toward navy on left (text area needs clean dark bg)
        $r = (int)($r + ($navyR - $r) * $leftBlend);
        $g = (int)($g + ($navyG - $g) * $leftBlend);
        $b = (int)($b + ($navyB - $b) * $leftBlend);
        imagesetpixel($img, $x, $y, imagecolorallocate($img, $r, $g, $b));
    }
}

// ── Accent strips ─────────────────────────────────────────────────────────────
$gold = imagecolorallocate($img, 255, 192, 0);
imagefilledrectangle($img, 0, 0, $W, 5, $gold);
imagefilledrectangle($img, 0, $H - 5, $W, $H, $gold);

// ── Logo (20% smaller than 420 → 336px wide) ─────────────────────────────────
$logoFile = __DIR__ . '/sites/granitepmacademy/uploads/logo_granite.png';
$logo = imagecreatefrompng($logoFile);
$lW = 370; $lH = (int)(132 * $lW / 549); // 10% bigger than 336
$logoResized = imagecreatetruecolor($lW, $lH);
imagealphablending($logoResized, false);
imagesavealpha($logoResized, true);
imagefilledrectangle($logoResized, 0, 0, $lW, $lH,
    imagecolorallocatealpha($logoResized, 0, 0, 0, 127));
imagecopyresampled($logoResized, $logo, 0, 0, 0, 0, $lW, $lH, 549, 132);
// Lower-right corner, just above the bottom gold bar
$logoX = $W - $lW - 35;
$logoY = $H - $lH - 12;
imagecopy($img, $logoResized, $logoX, $logoY, 0, 0, $lW, $lH);

// ── Fonts ─────────────────────────────────────────────────────────────────────
$fBold = '/System/Library/Fonts/Supplemental/Arial Bold.ttf';
$fReg  = '/Library/Fonts/Arial Unicode.ttf';
$white = imagecolorallocate($img, 255, 255, 255);
$slate = imagecolorallocate($img, 180, 195, 215);
$dim   = imagecolorallocate($img, 120, 145, 180);
$dimW  = imagecolorallocate($img, 90, 115, 155);

// ── Eyebrow label ─────────────────────────────────────────────────────────────
imagettftext($img, 12, 0, 58, 210, $gold, $fBold, 'PMI PREMIER AUTHORIZED TRAINING PARTNER');

// ── Main headline ─────────────────────────────────────────────────────────────
$sz = 56;
imagettftext($img, $sz, 0, 58, 300, $white, $fBold, 'Pass the PMP on');
imagettftext($img, $sz, 0, 58, 370, $white, $fBold, 'Your First Try.');
imagettftext($img, $sz, 0, 58, 440, $gold,  $fBold, 'Guaranteed.');

// ── Supporting line ───────────────────────────────────────────────────────────
imagettftext($img, 17, 0, 58, 492, $slate, $fReg, 'Live online  ·  6 PMI certifications  ·  PMP Pass Guarantee');

// ── Cert names ────────────────────────────────────────────────────────────────
imagettftext($img, 13, 0, 58, 522, $dim, $fReg, 'PMP   CAPM   PMI-ACP   PMI-RMP   PgMP   PMI-PBA');

// ── Domain ────────────────────────────────────────────────────────────────────
imagettftext($img, 13, 0, 58, 608, $dimW, $fReg, 'granitepmacademy.com');

// ── Save ──────────────────────────────────────────────────────────────────────
imagepng($img, $out, 6);
echo "Saved: $out\n";
