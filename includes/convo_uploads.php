<?php
/**
 * includes/convo_uploads.php — what the Test Lab's "Share with Claude" folder takes.
 *
 * ONE list, read by both ends of the same gate:
 *
 *   admin/convo_upload.php   the server check, which decides what is saved
 *   admin/playground.php     the file input's accept="", which decides what the
 *                            operating system's picker will even let you click
 *
 * They were two hand-written copies of the same extensions, which is a rule that
 * only looks like it holds. Adding .jsx to the server list left the picker still
 * refusing to select one — the upload could not fail, because it could not start.
 * A whitelist enforced in two places is one edit away from disagreeing, and the
 * disagreement shows up as a file manager that greys out a file for no stated
 * reason. Same lesson as the API clients: duplicates do not stay duplicates.
 *
 * What is deliberately NOT here: html, htm, xhtml. Those execute their own script
 * in the panel's origin when served from it — stored XSS — and no download header
 * makes that safe. Nor anything the server would run (php, phtml, phar, cgi, pl,
 * py, sh); uploads/convo/.htaccess strips those handlers as well.
 *
 * .svg is handled separately (convo_svg_exts(), below) — accepted, but only after
 * sanitize_svg() (includes/helpers.php) strips <script>, event-handler attributes,
 * and javascript:/data:/vbscript: URIs, same gate the Brand Icons upload already
 * uses. Still served force-download by uploads/convo/.htaccess like every other
 * non-raster type here — sanitizing the content doesn't need inline rendering to
 * be safe, so there's no reason to touch that second layer of defense.
 */

/** Raster types accepted, and validated by real content (getimagesize), not suffix. */
function convo_image_exts(): array
{
    return ['jpg', 'jpeg', 'png', 'webp', 'gif'];
}

/** Vector type accepted after sanitize_svg() — see the docblock above. */
function convo_svg_exts(): array
{
    return ['svg'];
}

/**
 * Everything else, accepted by extension because there is no content check that
 * spans documents, data and source. Each of these is inert: the server will not
 * run it and the browser will not render it as a document.
 */
function convo_doc_exts(): array
{
    return [
        // documents and data
        'pdf', 'txt', 'md', 'markdown', 'csv', 'tsv', 'json', 'xml', 'yaml', 'yml', 'log',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'rtf', 'odt', 'ods', 'odp', 'zip',
        // source, for porting work — handing Claude a component to read off the VPS
        // is what this folder is for
        'js', 'jsx', 'mjs', 'cjs', 'ts', 'tsx', 'css', 'scss', 'sql', 'ini', 'conf',
        'toml', 'diff', 'patch',
        // in-progress browser downloads — a partial/stuck download is itself the
        // diagnostic artifact when troubleshooting a download that failed
        'crdownload', 'part', 'download',
    ];
}

/**
 * The file input's accept="" value.
 *
 * image/* rather than the image extensions, so a picker still offers formats the
 * content check would accept under an unusual suffix.
 *
 * ⚠ accept="" is a HINT the picker applies, not a rule. Most browsers offer an
 * "All files" escape in the dialog, and a drag-and-drop or paste bypasses it
 * entirely — which is why convo_upload.php checks again server-side and always must.
 */
function convo_accept_attr(): string
{
    $dots = array_map(static fn(string $e): string => '.' . $e, [...convo_doc_exts(), ...convo_svg_exts()]);
    return 'image/*,' . implode(',', $dots);
}

/** The human-readable version, for the line under the drop zone. */
function convo_accept_note(): string
{
    return 'images · SVG (sanitized) · PDF · text/markdown/CSV/JSON · source (js/jsx/ts/css) · Office docs · zip · max 20 MB';
}
