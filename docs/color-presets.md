# Color Presets (Multisite)

**Read this before adding a field to a Color Preset, or touching `ms_apply_theme_preset()`.** A
Color Preset is a named palette a multisite master rotates domains through — separate from a
single site's own Theme tab colors (`theme_css_vars()`/`resolve_color()`, see CLAUDE.md's
"Theme / colors" section), which only apply to a solo, non-multisite site.

## Where presets live

- `sites/{master}/multisite/theme_presets.json` — `{presets: [...], fonts: [...]}`. Edited on the
  **Gen-Visual** tab's Color Preset cards (`admin/tabs/multisite_visual.php`), saved by
  `admin/visual_presets_save.php`.
- `ms_load_theme_presets($masterId)` loads them; `ms_pick_theme_preset($masterId, $params)` chooses
  one per domain — an explicit `theme_preset` param (matching a preset's `id`, its `name`
  case-insensitively, or a 1-based index) wins, otherwise a deterministic hash rotate off the
  domain so a rebuild always lands on the same preset.
- `ms_apply_theme_preset(array &$data, array $preset)` (`includes/multisite/visual.php`) is the
  **single place** a chosen preset's colors actually get written into a domain's working `$data`.

## The rule this system exists to enforce: never hand-store a derived field

`ms_apply_theme_preset()` doesn't just copy `$preset['theme']` onto `$data['theme']` — it also
**derives** three fields at apply-time, every single time, from the two the preset actually stores
(`header_bg`, `accent_color`):

```php
$dark   = $data['theme']['header_bg']    ?? '#0d1f3c';
$accent = $data['theme']['accent_color'] ?? '#fd783b';
$data['theme']['accent2_color']   = $accent;
$data['theme']['skins']['dark']   = ['bg' => $dark, 'heading' => '#ffffff', 'text' => '#e2e8f0'];
$data['theme']['skins']['accent'] = ['heading' => $dark, 'text' => '#ffffff'];
```

**This was NOT always the design, and the earlier approach failed in a way worth remembering.**
`accent2_color` and `skins.dark`/`skins.accent` were once hand-added directly to
`theme_presets.json`, once, to fix two real fleet-wide bugs (Dark-skin block sections and
accent-skin buttons/headings staying frozen off-preset). They didn't survive:
`admin/visual_presets_save.php` rebuilds each preset's `theme` object from only the ~10 fields the
Color Preset card's own editor actually knows about, on **every autosave** — which fires on every
single edit in that panel. The very next color tweak silently wiped both hand-added fields back
out. Found live: all 10 of water-site's presets had already lost them by the time this was caught.

**Deriving at apply-time instead of storing anywhere removes the second copy that can go stale.**
Any future field that a preset "should carry" but the editor doesn't have a control for is the same
trap — derive it in `ms_apply_theme_preset()` from a field the editor genuinely does own, don't
hand-edit the JSON once and assume it'll stick.

## Preview without saving

`admin/theme_preview.php` renders the ACTIVE site's real homepage with a chosen preset's colors
substituted into `$data['theme']` **entirely in memory** — it calls the real
`ms_apply_theme_preset()` (never hand-reconstructs the theme shape from raw GET params — an earlier
version did, and every field the real function derives silently didn't show up in the preview). It
reads nothing from and writes nothing to any preset file; same "no save, just show" pattern as the
existing logo/favicon preview (`admin/visual_preview.php`). This is what the Color Preset card's
"Preview site" button opens.

## Palette jitter (a separate, weaker axis)

`ms_jitter_keys()`/`ms_jitter_contrast_pairs()` (`includes/multisite/visual.php`) nudge a few chrome
colors a couple of points per domain — deterministic (crc32 of the domain), so two sites on the
same preset don't ship byte-identical hex values, and a WCAG contrast gate reverts any nudge that
would break a text/background pair. Text colors are deliberately excluded (pure white; nudging is
exactly where contrast breaks). Honest limit: this defeats exact string matching, not perceptual
matching — `#F7762C` and `#F75B2C` are different strings but near-identical colors, so it's the
weakest of the fleet's variance axes and worth having only because it's free.
