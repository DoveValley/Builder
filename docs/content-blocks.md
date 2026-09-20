# Content block patterns

**Read this when a block needs to break out of the container, when a full-width block leaves a
white band above the footer, when a photo renders the wrong size on mobile, or when working on
`related_links`.** The block *registration* rules (the four files every new type touches) stay in
`CLAUDE.md` — this file is the layout detail.

## `custom_html` is in `$isFullWidth`

Unlike most non-hero blocks, `custom_html` blocks are NOT wrapped in `.container` by
`site-template.php`. The block renderer still adds a `.block-custom-html` wrapper (with
`padding: 24px`) **unless** the HTML starts with `<div[^>]*class="[^"]*\bcontent-block\b` — in that
case it echoes the HTML raw, with no wrapper at all.

## Full-width `custom_html` pattern

To create an edge-to-edge colored section inside a `custom_html` block, start the HTML with
`<div class="content-block" style="padding:0;margin:0;">` (which bypasses the padded wrapper), then
use the viewport breakout technique on the inner div:

```html
<div class="content-block" style="padding:0;margin:0;">
  <div style="width:100vw;flex-shrink:0;margin-left:calc(-50vw + 50%);background:#2563eb;padding:72px 0;">
    <div style="max-width:860px;margin:0 auto;padding:0 24px;">
      ...content...
    </div>
  </div>
</div>
```

`flex-shrink:0` is **required** — `.content-block` is `display:flex` and would otherwise shrink the
`100vw` div to the container width. The `calc(-50vw + 50%)` math works at any nesting level; it
accounts for padding at every ancestor automatically.

## Last-block gap elimination

`site-template.php` checks `$lastBlockType` (the type of the final block in `$contentBlocks`). When
it is `custom_html`, it adds `style="padding-bottom:0"` to `<main>` and `style="margin-top:0"` to
`<footer>`. This prevents the 48px `site-main` padding and 48px `site-footer` margin-top from
creating a white band between a full-width closing block and the footer.

## `related_links` — real pages only, never a guess

Its `rl_items` field holds MORE candidate `{text, url}` pairs than will actually render — editorial
slack, because Page Pool means every domain builds a different subset of the master's pages, so a
hand-typed candidate can 404 on any domain that didn't happen to build that target. The render case
(`includes/blocks.php`, `case 'related_links':`) calls `related_links_resolve()`
(`plugins/related_links/plugin.php`) which resolves each candidate's shortcodes, checks it against
`ms_page_slug_exists()` (`includes/helpers.php` — the same per-domain page-existence check
`plugins/services_links` already uses), and returns only the ones that are real, in the given
order, capped at `rl_max` (default 3). Fewer than 2 real matches → the whole block renders nothing,
same "disappear rather than show thin/broken" rule used for fake ratings and safe badges elsewhere.
Soft-guarded like every other plugin-backed block (`function_exists()`) — a site missing the plugin
renders nothing here, never a broken block.

## Mobile image sizing — the `aspect-ratio` + HTML attribute trap

`img_intrinsic_attrs()` (`includes/helpers.php`) stamps every `<img>` with the photo's raw native
pixel `width`/`height` HTML attributes, for CLS prevention. That's fine when a block's CSS sets
`width` **and** `height` together (both explicit HTML attrs fully overridden) — but a rule that sets
`width:100%; aspect-ratio:4/3` with **no explicit `height`** does NOT fully override the HTML
attribute: per spec, the browser still uses the HTML attribute's height as the "used value," and
`aspect-ratio` only fills in a dimension that's otherwise fully unspecified. Result: the box renders
at the *source photo's* raw native height, ignoring its own (often much narrower, especially on
mobile) rendered width — measured up to 600px tall on a 366px-wide mobile column before this was
fixed on `.hs-image`/`.fs-arch-img`/`.if-photo`. **Any element that sets `aspect-ratio` and also
gets `img_intrinsic_attrs()` needs an explicit `height: auto;` alongside it**, or check for this
exact pattern before assuming a box's on-screen size matches its CSS. The same class of bug can hit
a flex wrapper too: a desktop `flex: 1 1 380px` (a WIDTH basis) silently becomes a HEIGHT basis once
a mobile media query flips the container to `flex-direction:column` — reset with `flex-basis:auto`/
`flex:none` in the mobile override.

**Mobile focal point + responsive srcset**, built alongside the fix above:
- A photo can carry a separate `focal_x_mobile`/`focal_y_mobile` (Media Library's Focal Point tool)
  from its desktop focal point. `img_focal_vars()`/`bg_style_vars()` (`includes/blocks.php`) always
  emit `--op` (desktop) and, only when the mobile fields are both set, `--opm` — consumed via
  `object-position:var(--op)` on the element plus one global
  `@media(max-width:768px){ object-position:var(--opm,var(--op)) }` rule, so a photo with no mobile
  override just keeps using its desktop focal point on mobile (`--opm` falls back to `--op`).
- `img_write_mobile_variant()` (`includes/media_lib.php`) writes a `-mobile.webp` sibling next to an
  uploaded photo. `img_srcset()` (`includes/blocks.php`) emits a `srcset`/`sizes` pair pointing the
  browser at it below a breakpoint, when the variant file exists — a photo uploaded before this
  feature existed just has no `-mobile.webp` sibling and serves the one file at every width, not an
  error.
