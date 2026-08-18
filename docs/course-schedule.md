# Course schedule system

**Read this when working on course data, the two schedule widgets, or the Schedule admin tab.**
Ported from the course-schedule-manager WordPress plugin.

Course data lives in `COURSES_FILE` (defined in `config.php`, parallel to `DATA_FILE`). Each course
has: `id` (auto-increment), `course_type`, `delivery` (Live-Virtual / On-Demand), `dates`,
`time_est` (a range like `"8:30am-5:00pm"`, or `"Self-paced"`), `price`, `old_price`,
`register_url`, `availability_note`, `guaranteed`, `sort_order`.

## Shortcodes

Resolved inside `custom_html` blocks by `apply_course_shortcodes()` in `includes/shortcodes.php`:

- `[course_schedule type="PMP Certification"]` — Widget 1, filterable table (schedule.js / schedule.css)
- `[course_card type="PMP Certification" start_tab="1"]` — Widget 2, compact card widget (card.js / card.css)

Both accept `type="All"`.

## Inline data pattern

`course_shortcode_inline_script()` outputs `<script>var csmAllData={...}; var csm2AllData={...};</script>`
before `</body>`, keyed by instance id (e.g. `csm1_inst_1`). This replaces WordPress's
`wp_localize_script`. Scripts and CSS are only injected when a shortcode was actually used on the
page (checked via `$GLOBALS['_csm_w1_data']` / `$GLOBALS['_csm_w2_data']`).

## ⚠ DOMContentLoaded ordering

`<script src="schedule.js">` runs while `readyState === 'loading'` and defers `initAll()` to
DOMContentLoaded. Any filter trigger script on a test page **must be registered as a
DOMContentLoaded listener placed *after* the `<script src="schedule.js">` tag** — that puts it second
in the listener queue, so widgets initialize before filters fire. **An inline IIFE immediately after
the script tag does not work.**

## Admin

`admin/tabs/schedule.php` provides list/add/edit. `admin/schedule_save.php` handles CRUD POSTs
(actions: `save`, `delete`, `duplicate`) with the same CSRF pattern as `save.php`.

## Assets

`assets/css/schedule.css`, `assets/css/card.css`, `assets/js/schedule.js`, `assets/js/card.js`.
