<?php
/**
 * Progress logging seam for long-running build/deploy operations.
 *
 * The static builder (generate_static.php) and FTP deployer (deploy_ftp.php) emit
 * a stream of log + progress events while they run. Historically each echoed
 * Server-Sent Events (SSE) inline, hard-wiring those operations to a browser
 * EventSource. The multisite generator needs the same operations driven from a
 * CLI worker, where SSE is meaningless and the parent process wants plain,
 * parseable output instead.
 *
 * This file decouples "what happened" from "how it's reported":
 *   - progress_log()  / progress_tick()  — what the build/deploy logic calls
 *   - progress_set_sink()                 — swaps the destination
 *
 * Default sink = SSE, byte-for-byte identical to the old sse()/ftp_sse() output,
 * so the admin endpoints behave exactly as before with no sink set. The CLI
 * worker calls progress_set_sink(progress_jsonlines_sink()) to get one JSON
 * object per line on stdout instead.
 */

if (!function_exists('progress_set_sink')) {

    /** Set the active event sink. null = default (SSE). */
    function progress_set_sink(?callable $sink): void
    {
        $GLOBALS['_progress_sink'] = $sink;
    }

    /**
     * Emit the SSE response headers and disable every layer of output buffering
     * so progress events stream to the browser immediately instead of being held
     * until the script ends. Call once, after auth, before the long-running work.
     *
     * Both the buffering ini_set()s matter: with output_buffering or zlib
     * compression on (common in shared-host php.ini), the per-event flush() in
     * progress_emit() is silently swallowed and the whole log dumps at the end.
     */
    function progress_sse_begin(): void
    {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        set_time_limit(0);
        @ini_set('output_buffering', 'off');
        @ini_set('zlib.output_compression', '0');

        // Surface uncaught exceptions and fatal errors as a real SSE 'fatal' event.
        // Without this, anything the work throws just cuts the stream and the
        // browser only sees a generic "connection lost" — hiding the real reason.
        $emit = function (string $msg): void {
            echo 'data: ' . json_encode(['type' => 'fatal', 'msg' => $msg]) . "\n\n";
            @ob_flush();
            flush();
        };
        set_exception_handler(function (\Throwable $e) use ($emit) {
            $GLOBALS['_sse_fatal_sent'] = true;
            $emit($e->getMessage());
        });
        register_shutdown_function(function () use ($emit) {
            if (!empty($GLOBALS['_sse_fatal_sent'])) return;
            $err = error_get_last();
            if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                $emit('Server error: ' . $err['message']);
            }
        });
    }

    /** Emit one log / status message. $type: log | warn | error | fatal | done. */
    function progress_log(string $msg, string $type = 'log'): void
    {
        progress_emit(['type' => $type, 'msg' => $msg]);
    }

    /** Emit a progress counter. */
    function progress_tick(int $done, int $total): void
    {
        progress_emit(['type' => 'progress', 'done' => $done, 'total' => $total]);
    }

    /** Route a payload to the active sink, or the default SSE emitter. */
    function progress_emit(array $payload): void
    {
        $sink = $GLOBALS['_progress_sink'] ?? null;
        if ($sink !== null) {
            $sink($payload);
            return;
        }
        // Default: SSE — identical wire format to the legacy sse()/ftp_sse().
        echo 'data: ' . json_encode($payload) . "\n\n";
        @ob_flush();
        flush();
    }

    /** Sink for CLI workers: one JSON object per line on stdout. */
    function progress_jsonlines_sink(): callable
    {
        return function (array $payload): void {
            echo json_encode($payload) . "\n";
            @flush();
        };
    }
}
