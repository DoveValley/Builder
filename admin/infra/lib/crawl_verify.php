<?php
/**
 * infra/lib/crawl_verify.php — is this request REALLY from Googlebot?
 *
 * ── WHY THIS EXISTS AS ITS OWN FILE ──────────────────────────────────────────────
 * Because getting it wrong poisons every number built on top of it, and it is very
 * easy to get wrong. Measured on baileyrestoration.com's access log, 2026-10-21:
 *
 *     78 requests whose user-agent said "Googlebot"
 *     48 actually came from Google's published ranges
 *     30 were impostors
 *
 * The impostors were scanners — `/temp/.env`, `/private-key`,
 * `/....//....//..../proc/self/environ` — wearing the name. Matching on the UA string
 * alone reported "36% of Googlebot requests are 404ing" about a site where real
 * Googlebot got 36 × 200, 12 × 304 and NOT ONE 404. That is a clean bill of health
 * reported as a crisis, and it is the kind of confidently-wrong output this estate
 * keeps getting bitten by.
 *
 * ── THE RULE ─────────────────────────────────────────────────────────────────────
 * ⚠ IF THE RANGES CANNOT BE ESTABLISHED, THIS REFUSES TO ANSWER. It never falls back
 * to trusting the user-agent. A caller that cannot verify must report "could not
 * measure" — the same not-measured-is-not-zero discipline used by the index and rank
 * modules. Silently degrading to UA matching would reintroduce the exact 30-impostor
 * error at the one moment nobody is watching.
 *
 * The list is Google's own, published for this purpose, cached on disk and refreshed
 * weekly. A failed refresh uses the cache and says so; it does not disable checking.
 */

require_once __DIR__ . '/http.php';

/**
 * Google's published crawler ranges.
 *
 * ⚠ These URLs MOVE. The long-documented
 * `/static/search/apis/ipranges/googlebot.json` now 301s, and the file itself is gone
 * (404) — Google folded it into `common-crawlers.json` under `/static/crawling/`.
 * Checked 2026-10-21. That is exactly why a failed fetch keeps using the cached list
 * and says so, instead of quietly deciding there are no crawlers.
 */
const CRAWL_RANGE_SOURCES = [
    'common-crawlers'  => 'https://developers.google.com/static/crawling/ipranges/common-crawlers.json',
    'special-crawlers' => 'https://developers.google.com/static/crawling/ipranges/special-crawlers.json',
];
/** Refresh the cached list when it is older than this. */
const CRAWL_RANGE_MAX_AGE = 604800;      // 7 days

function crawl_verify_cache_path(): string
{
    return dirname(__DIR__) . '/state/googlebot_ranges.json';
}

/**
 * The CIDR list, from cache when fresh, from Google when not.
 *
 * @return array{ok:bool, ranges:array<int,string>, at:string, source:string, msg:string}
 */
function crawl_verify_ranges(bool $force = false): array
{
    $path = crawl_verify_cache_path();
    $cached = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
    $age = (is_array($cached) && !empty($cached['at'])) ? time() - strtotime($cached['at']) : PHP_INT_MAX;

    if (!$force && is_array($cached) && !empty($cached['ranges']) && $age < CRAWL_RANGE_MAX_AGE) {
        return ['ok' => true, 'ranges' => $cached['ranges'], 'at' => $cached['at'],
                'source' => 'cache', 'msg' => 'cached ' . (int) round($age / 86400) . 'd ago'];
    }

    $ranges = [];
    $errs = [];
    foreach (CRAWL_RANGE_SOURCES as $name => $url) {
        $r = infra_http('GET', $url, ['timeout' => 20]);
        if (($r['code'] ?? 0) !== 200 || !is_array($r['json'] ?? null)) {
            $errs[] = $name . ': HTTP ' . ($r['code'] ?? 0) . ($r['error'] ? ' ' . $r['error'] : '');
            continue;
        }
        foreach ((array) ($r['json']['prefixes'] ?? []) as $p) {
            $cidr = (string) ($p['ipv4Prefix'] ?? $p['ipv6Prefix'] ?? '');
            if ($cidr !== '') $ranges[] = $cidr;
        }
    }
    $ranges = array_values(array_unique($ranges));

    if (!$ranges) {
        // Stale cache beats no cache: Google's ranges change slowly, and a week-old
        // list is far closer to the truth than trusting the user-agent would be.
        if (is_array($cached) && !empty($cached['ranges'])) {
            return ['ok' => true, 'ranges' => $cached['ranges'], 'at' => (string) $cached['at'],
                    'source' => 'stale cache',
                    'msg' => 'could not refresh (' . implode('; ', $errs) . ') — using the cached list'];
        }
        return ['ok' => false, 'ranges' => [], 'at' => '', 'source' => 'none',
                'msg' => 'no Googlebot ranges available (' . implode('; ', $errs)
                       . ') — refusing to classify rather than trusting the user-agent'];
    }

    $out = ['at' => gmdate('c'), 'ranges' => $ranges];
    $tmp = $path . '.tmp';
    if (file_put_contents($tmp, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") !== false) {
        rename($tmp, $path);
        @chmod($path, 0664);
    }
    return ['ok' => true, 'ranges' => $ranges, 'at' => $out['at'], 'source' => 'live',
            'msg' => count($ranges) . ' prefixes fetched'
                   . ($errs ? ' (' . implode('; ', $errs) . ')' : '')];
}

/**
 * Is $ip inside $cidr? Handles v4 and v6 by comparing packed bytes, which avoids
 * both the 32-bit integer overflow that breaks naive v4 maths and the need for a
 * separate v6 code path.
 */
function crawl_verify_in_cidr(string $ip, string $cidr): bool
{
    $slash = strrpos($cidr, '/');
    if ($slash === false) return false;
    $net  = substr($cidr, 0, $slash);
    $bits = (int) substr($cidr, $slash + 1);

    $a = @inet_pton($ip);
    $b = @inet_pton($net);
    if ($a === false || $b === false || strlen($a) !== strlen($b)) return false;   // v4 vs v6 never match

    $whole = intdiv($bits, 8);
    $rem   = $bits % 8;
    if ($whole > 0 && substr($a, 0, $whole) !== substr($b, 0, $whole)) return false;
    if ($rem === 0) return true;
    $mask = chr((0xFF << (8 - $rem)) & 0xFF);
    return (($a[$whole] ?? "\0") & $mask) === (($b[$whole] ?? "\0") & $mask);
}

/**
 * A reusable checker, with the range list resolved once.
 *
 * Returns null when the ranges could not be established — callers MUST treat that as
 * "cannot measure" and must not fall back to the user-agent. Returning a closure that
 * always says false would be worse: it would read as "no Googlebot anywhere", which is
 * a measurement, and we do not have one.
 *
 * @return array{ok:bool, msg:string, source:string, check:?callable}
 */
function crawl_verify_checker(bool $force = false): array
{
    $r = crawl_verify_ranges($force);
    if (!$r['ok']) return ['ok' => false, 'msg' => $r['msg'], 'source' => $r['source'], 'check' => null];

    $v4 = $v6 = [];
    foreach ($r['ranges'] as $c) {
        if (strpos($c, ':') === false) $v4[] = $c; else $v6[] = $c;
    }

    $check = function (string $ip) use ($v4, $v6): bool {
        $list = strpos($ip, ':') === false ? $v4 : $v6;
        foreach ($list as $c) if (crawl_verify_in_cidr($ip, $c)) return true;
        return false;
    };
    return ['ok' => true, 'msg' => $r['msg'], 'source' => $r['source'], 'check' => $check];
}

/**
 * Classify one access-log line.
 *
 * 'googlebot' — UA says Googlebot AND the IP is Google's
 * 'impostor'  — UA says Googlebot and the IP is not. Worth counting: it is a crude
 *               but real signal of scanner traffic, and it is the number that
 *               explains why a UA-only count looked so much worse than reality.
 * 'other'     — everything else
 *
 * @return array{class:string, ip:string, url:string, code:int, at:string}|null
 */
function crawl_verify_classify_line(string $line, callable $isGoogle): ?array
{
    if (!preg_match('#^(\S+) \S+ \S+ \[([^\]]+)\] "(\S+) (\S+)[^"]*" (\d{3})#', $line, $m)) return null;
    [, $ip, $ts, , $url, $code] = $m;
    $claims = stripos($line, 'googlebot') !== false;
    $class  = !$claims ? 'other' : ($isGoogle($ip) ? 'googlebot' : 'impostor');
    return ['class' => $class, 'ip' => $ip, 'url' => $url, 'code' => (int) $code, 'at' => $ts];
}
