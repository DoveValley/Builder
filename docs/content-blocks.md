# Content block patterns

**Read this when a block needs to break out of the container, or when a full-width block leaves a
white band above the footer.** The block *registration* rules (the four files every new type
touches) stay in `CLAUDE.md` — this file is the layout detail.

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
