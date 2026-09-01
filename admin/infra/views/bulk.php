<?php
/* ============================= BULK PROVISION ============================= */
    infra_header('bulk');
    // The "Run bulk provision" form/textarea that used to live below this grid
    // is retired (2026-09-01) — provisioning now goes entirely through the Batch
    // page's own pipeline (Upload target list -> Create host -> Generate sites ->
    // Upload sites -> Go Live), so a second, parallel way to provision a domain
    // was exactly the kind of thing that could drift out of sync with it. The
    // underlying engine (infra_provision_locked()/infra_provision_one() in
    // lib/provision.php) is unchanged and still what Batch's own Create host
    // step calls — only this page's UI on top of it is gone. actions/bulk_run.php
    // is untouched on disk, same as D.Own/+New Site/Deploy when they left the
    // nav: nothing deleted, just no longer surfaced here.
    //
    // What's left is a read-only window onto the same per-domain pipeline state
    // Batch's own Go Live card drives — "all in flight" fleet-wide, or filtered to
    // one batch's own tag.
    require __DIR__ . '/_pipeline_grid.php';
    infra_footer(); exit;
