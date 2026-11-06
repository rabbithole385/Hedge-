<?php
/**
 * LIVE MARKET PRICES
 * ------------------------------------------------------------------
 * Real prices pulled from public market-data feeds (no API key needed):
 *   - Binance public market data (crypto)        data-api.binance.vision
 *   - Yahoo Finance chart API (stocks, indices, gold, oil, forex, crypto)
 *   - Stooq CSV quotes (last-resort fallback)
 *
 * Every quote is cached in the `live_market_quotes` table so the site
 * never hammers the providers: at most one request per market per
 * LIVE_QUOTE_TTL seconds, with a back-off after a failed request.
 *
 * Investment plans track a basket of these markets. A plan's value moves
 * by exactly the real % move of its basket (times the plan's exposure),
 * never by a random number.
 */

require_once __DIR__ . '/asset_icons.php';

define('LIVE_QUOTE_TTL', 60);          // seconds a cached quote is considered fresh for display
define('LIVE_QUOTE_TICK_MAX_AGE', 900); // a plan tick only uses quotes newer than this (15 min)
define('LIVE_QUOTE_ERROR_BACKOFF', 120); // after a failed fetch, wait this long before retrying
define('LIVE_HTTP_TIMEOUT', 6);

/** Markets admins can pick for plans. key => meta */
function live_market_catalog(): array {
    static $cat = null;
    if ($cat !== null) return $cat;
    $cat = [
        // Crypto
        'BTC'    => ['name' => 'Bitcoin',          'short' => 'BTC',     'group' => 'Crypto',      'decimals' => 2, 'sources' => [['binance', 'BTCUSDT'], ['yahoo', 'BTC-USD']]],
        'ETH'    => ['name' => 'Ethereum',         'short' => 'ETH',     'group' => 'Crypto',      'decimals' => 2, 'sources' => [['binance', 'ETHUSDT'], ['yahoo', 'ETH-USD']]],
        'SOL'    => ['name' => 'Solana',           'short' => 'SOL',     'group' => 'Crypto',      'decimals' => 2, 'sources' => [['binance', 'SOLUSDT'], ['yahoo', 'SOL-USD']]],
        'BNB'    => ['name' => 'BNB',              'short' => 'BNB',     'group' => 'Crypto',      'decimals' => 2, 'sources' => [['binance', 'BNBUSDT'], ['yahoo', 'BNB-USD']]],
        'XRP'    => ['name' => 'XRP',              'short' => 'XRP',     'group' => 'Crypto',      'decimals' => 4, 'sources' => [['binance', 'XRPUSDT'], ['yahoo', 'XRP-USD']]],
        // Metals & energy
        'GOLD'   => ['name' => 'Gold',             'short' => 'XAU',     'group' => 'Commodities', 'decimals' => 2, 'sources' => [['yahoo', 'GC=F'], ['stooq', 'xauusd'], ['binance', 'PAXGUSDT']]],
        'SILVER' => ['name' => 'Silver',           'short' => 'XAG',     'group' => 'Commodities', 'decimals' => 3, 'sources' => [['yahoo', 'SI=F'], ['stooq', 'xagusd']]],
        'OIL'    => ['name' => 'WTI Crude Oil',    'short' => 'WTI',     'group' => 'Commodities', 'decimals' => 2, 'sources' => [['yahoo', 'CL=F'], ['stooq', 'cl.f']]],
        'BRENT'  => ['name' => 'Brent Crude',      'short' => 'BRENT',   'group' => 'Commodities', 'decimals' => 2, 'sources' => [['yahoo', 'BZ=F'], ['stooq', 'cb.f']]],
        'NATGAS' => ['name' => 'Natural Gas',      'short' => 'NG',      'group' => 'Commodities', 'decimals' => 3, 'sources' => [['yahoo', 'NG=F'], ['stooq', 'ng.f']]],
        // Indices
        'SPX'    => ['name' => 'S&P 500',          'short' => 'SPX',     'group' => 'Indices',     'decimals' => 2, 'sources' => [['yahoo', '^GSPC'], ['stooq', '^spx']]],
        'NDX'    => ['name' => 'Nasdaq 100',       'short' => 'NDX',     'group' => 'Indices',     'decimals' => 2, 'sources' => [['yahoo', '^NDX'], ['stooq', '^ndx']]],
        'DJI'    => ['name' => 'Dow Jones 30',     'short' => 'DJI',     'group' => 'Indices',     'decimals' => 2, 'sources' => [['yahoo', '^DJI'], ['stooq', '^dji']]],
        'FTSE'   => ['name' => 'FTSE 100',         'short' => 'FTSE',    'group' => 'Indices',     'decimals' => 2, 'sources' => [['yahoo', '^FTSE'], ['stooq', '^ftm']]],
        'DAX'    => ['name' => 'DAX 40',           'short' => 'DAX',     'group' => 'Indices',     'decimals' => 2, 'sources' => [['yahoo', '^GDAXI'], ['stooq', '^dax']]],
        // Stocks
        'AAPL'   => ['name' => 'Apple',            'short' => 'AAPL',    'group' => 'Stocks',      'decimals' => 2, 'sources' => [['yahoo', 'AAPL'], ['stooq', 'aapl.us']]],
        'MSFT'   => ['name' => 'Microsoft',        'short' => 'MSFT',    'group' => 'Stocks',      'decimals' => 2, 'sources' => [['yahoo', 'MSFT'], ['stooq', 'msft.us']]],
        'NVDA'   => ['name' => 'NVIDIA',           'short' => 'NVDA',    'group' => 'Stocks',      'decimals' => 2, 'sources' => [['yahoo', 'NVDA'], ['stooq', 'nvda.us']]],
        'TSLA'   => ['name' => 'Tesla',            'short' => 'TSLA',    'group' => 'Stocks',      'decimals' => 2, 'sources' => [['yahoo', 'TSLA'], ['stooq', 'tsla.us']]],
        'AMZN'   => ['name' => 'Amazon',           'short' => 'AMZN',    'group' => 'Stocks',      'decimals' => 2, 'sources' => [['yahoo', 'AMZN'], ['stooq', 'amzn.us']]],
        'GOOGL'  => ['name' => 'Alphabet',         'short' => 'GOOGL',   'group' => 'Stocks',      'decimals' => 2, 'sources' => [['yahoo', 'GOOGL'], ['stooq', 'googl.us']]],
        'META'   => ['name' => 'Meta',             'short' => 'META',    'group' => 'Stocks',      'decimals' => 2, 'sources' => [['yahoo', 'META'], ['stooq', 'meta.us']]],
        // Forex
        'EURUSD' => ['name' => 'EUR / USD',        'short' => 'EURUSD',  'group' => 'Forex',       'decimals' => 5, 'sources' => [['yahoo', 'EURUSD=X'], ['stooq', 'eurusd']]],
        'GBPUSD' => ['name' => 'GBP / USD',        'short' => 'GBPUSD',  'group' => 'Forex',       'decimals' => 5, 'sources' => [['yahoo', 'GBPUSD=X'], ['stooq', 'gbpusd']]],
        'USDJPY' => ['name' => 'USD / JPY',        'short' => 'USDJPY',  'group' => 'Forex',       'decimals' => 3, 'sources' => [['yahoo', 'JPY=X'], ['stooq', 'usdjpy']]],
    ];
    return $cat;
}

/**
 * Resolve a market key to its meta. Besides catalog keys, admins may enter
 * any Yahoo Finance symbol as "Y:SYMBOL" (e.g. Y:NFLX, Y:^N225).
 */
function live_market_meta(string $key): ?array {
    $key = strtoupper(trim($key));
    $cat = live_market_catalog();
    if (isset($cat[$key])) return $cat[$key] + ['key' => $key];
    if (strpos($key, 'Y:') === 0) {
        $sym = substr($key, 2);
        if ($sym === '' || !preg_match('/^[A-Z0-9.\-=^]{1,20}$/', $sym)) return null;
        return ['key' => $key, 'name' => $sym, 'short' => $sym, 'group' => 'Custom', 'decimals' => 2, 'sources' => [['yahoo', $sym]]];
    }
    return null;
}

/** Parse "GOLD,BTC,Y:NFLX" into a clean, de-duplicated list of valid keys. */
function parse_market_keys(?string $csv): array {
    $out = [];
    foreach (explode(',', (string)$csv) as $k) {
        $k = strtoupper(trim($k));
        if ($k === '' || isset($out[$k])) continue;
        if (live_market_meta($k)) $out[$k] = true;
    }
    return array_keys($out);
}

function ensure_live_market_schema(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS live_market_quotes (
            market_key VARCHAR(40) NOT NULL PRIMARY KEY,
            price DECIMAL(24,8) NULL,
            ref_24h DECIMAL(24,8) NULL,
            series TEXT NULL,
            source VARCHAR(20) NULL,
            fetched_at DATETIME NULL,
            last_error VARCHAR(255) NULL,
            last_error_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/* ---------------- HTTP ---------------- */

function live_http_get(string $url, ?int &$status = null): ?string {
    $status = 0;
    if (isset($GLOBALS['LIVE_HTTP_MOCK']) && is_callable($GLOBALS['LIVE_HTTP_MOCK'])) { // tests only
        [$status, $body] = ($GLOBALS['LIVE_HTTP_MOCK'])($url);
        return ($status >= 200 && $status < 300) ? $body : null;
    }
    $ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_TIMEOUT => LIVE_HTTP_TIMEOUT,
            CURLOPT_USERAGENT => $ua,
            CURLOPT_HTTPHEADER => ['Accept: application/json,text/csv,*/*'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $status = $code;
        return ($body !== false && $code >= 200 && $code < 300) ? (string)$body : null;
    }
    $ctx = stream_context_create(['http' => ['timeout' => LIVE_HTTP_TIMEOUT, 'header' => "User-Agent: {$ua}\r\n", 'ignore_errors' => false]]);
    $body = @file_get_contents($url, false, $ctx);
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) $status = (int)$m[1];
    return $body === false ? null : $body;
}

/* ---------------- Providers ----------------
 * Each returns ['price'=>float, 'ref_24h'=>?float, 'series'=>float[]] or null.
 */

function live_fetch_binance(string $symbol): ?array {
    foreach (['https://data-api.binance.vision', 'https://api.binance.com'] as $host) {
        $body = live_http_get($host . '/api/v3/klines?symbol=' . rawurlencode($symbol) . '&interval=30m&limit=49');
        if (!$body) continue;
        $rows = json_decode($body, true);
        if (!is_array($rows) || count($rows) < 2 || !isset($rows[0][4])) continue;
        $series = array_map(fn($r) => (float)$r[4], $rows);
        $price = end($series);
        if ($price <= 0) continue;
        return ['price' => $price, 'ref_24h' => (float)$rows[0][1], 'series' => $series];
    }
    return null;
}

function live_fetch_yahoo(string $symbol): ?array {
    foreach (['query1', 'query2'] as $h) {
        $body = live_http_get("https://{$h}.finance.yahoo.com/v8/finance/chart/" . rawurlencode($symbol) . '?range=5d&interval=30m&includePrePost=false');
        if (!$body) continue;
        $j = json_decode($body, true);
        $res = $j['chart']['result'][0] ?? null;
        if (!$res) continue;
        $price = (float)($res['meta']['regularMarketPrice'] ?? 0);
        $ts = $res['timestamp'] ?? [];
        $closes = $res['indicators']['quote'][0]['close'] ?? [];
        $points = [];
        foreach ($ts as $i => $t) {
            if (isset($closes[$i]) && $closes[$i] !== null && $closes[$i] > 0) $points[] = [(int)$t, (float)$closes[$i]];
        }
        if ($price <= 0 && $points) $price = end($points)[1];
        if ($price <= 0) continue;

        // 24h reference: the first point at or after (last point - 24h).
        $ref = null;
        $series = [];
        if ($points) {
            $lastT = end($points)[0];
            foreach ($points as [$t, $c]) {
                if ($t >= $lastT - 86400) {
                    if ($ref === null) $ref = $c;
                    $series[] = $c;
                }
            }
        }
        if ($ref === null) $ref = (float)($res['meta']['chartPreviousClose'] ?? $res['meta']['previousClose'] ?? 0) ?: null;
        $series[] = $price;
        return ['price' => $price, 'ref_24h' => $ref, 'series' => array_slice($series, -60)];
    }
    return null;
}

function live_fetch_stooq(string $symbol): ?array {
    $body = live_http_get('https://stooq.com/q/l/?s=' . rawurlencode($symbol) . '&f=sd2t2ohlc&h&e=csv');
    if (!$body) return null;
    $lines = preg_split('/\r?\n/', trim($body));
    if (count($lines) < 2) return null;
    $cols = str_getcsv($lines[1]);
    $close = isset($cols[6]) ? (float)$cols[6] : 0;
    $open = isset($cols[3]) ? (float)$cols[3] : 0;
    if ($close <= 0) return null;
    return ['price' => $close, 'ref_24h' => $open > 0 ? $open : null, 'series' => $open > 0 ? [$open, $close] : [$close]];
}

/**
 * Test hook: tests can set $GLOBALS['LIVE_PRICE_MOCK'] = fn(string $key): ?array
 * to bypass the network. Never set in production.
 */
function live_fetch_market(string $key): ?array {
    if (isset($GLOBALS['LIVE_PRICE_MOCK']) && is_callable($GLOBALS['LIVE_PRICE_MOCK'])) {
        $r = ($GLOBALS['LIVE_PRICE_MOCK'])($key);
        return $r ? $r + ['source' => 'mock'] : null;
    }
    $meta = live_market_meta($key);
    if (!$meta) return null;
    foreach ($meta['sources'] as [$provider, $sym]) {
        $fn = 'live_fetch_' . $provider;
        $r = $fn($sym);
        if ($r && $r['price'] > 0) return $r + ['source' => $provider];
    }
    return null;
}

/* ---------------- Cached quotes ---------------- */

function live_row_to_quote(array $row): array {
    $meta = live_market_meta($row['market_key']) ?? ['name' => $row['market_key'], 'short' => $row['market_key'], 'group' => '', 'decimals' => 2];
    $price = $row['price'] !== null ? (float)$row['price'] : null;
    $ref = $row['ref_24h'] !== null ? (float)$row['ref_24h'] : null;
    $series = $row['series'] ? (json_decode($row['series'], true) ?: []) : [];
    $age = isset($row['age_s']) && $row['age_s'] !== null ? max(0, (int)$row['age_s']) : null;
    return [
        'key' => $row['market_key'],
        'name' => $meta['name'],
        'short' => $meta['short'],
        'group' => $meta['group'],
        'decimals' => $meta['decimals'],
        'price' => $price,
        'ref_24h' => $ref,
        'change_pct' => ($price && $ref) ? (($price / $ref) - 1) * 100 : null,
        'series' => array_map('floatval', $series),
        'source' => $row['source'],
        'fetched_at' => $row['fetched_at'],
        'age' => $age,
    ];
}

/**
 * Get quotes for several markets at once, refreshing stale ones from the
 * providers (respecting TTL and error back-off). Returns key => quote|null.
 */
function get_live_quotes(PDO $pdo, array $keys, bool $allowFetch = true): array {
    $keys = array_values(array_unique(array_filter(array_map(fn($k) => strtoupper(trim($k)), $keys))));
    if (!$keys) return [];
    ensure_live_market_schema($pdo);

    $in = implode(',', array_fill(0, count($keys), '?'));
    // Ages are computed by MySQL so PHP/MySQL timezone differences can't skew them.
    $stmt = $pdo->prepare(
        "SELECT q.*, TIMESTAMPDIFF(SECOND, q.fetched_at, NOW()) AS age_s,
                TIMESTAMPDIFF(SECOND, q.last_error_at, NOW()) AS err_age_s
         FROM live_market_quotes q WHERE market_key IN ($in)"
    );
    $stmt->execute($keys);
    $rows = [];
    foreach ($stmt->fetchAll() as $r) $rows[$r['market_key']] = $r;

    $out = [];
    foreach ($keys as $k) {
        if (!live_market_meta($k)) { $out[$k] = null; continue; }
        $row = $rows[$k] ?? null;
        $fresh = $row && $row['age_s'] !== null && (int)$row['age_s'] < LIVE_QUOTE_TTL;
        $backingOff = $row && $row['err_age_s'] !== null && (int)$row['err_age_s'] < LIVE_QUOTE_ERROR_BACKOFF
            && ($row['age_s'] === null || (int)$row['err_age_s'] < (int)$row['age_s']);

        if ($allowFetch && !$fresh && !$backingOff) {
            $q = live_fetch_market($k);
            if ($q) {
                $pdo->prepare(
                    "INSERT INTO live_market_quotes (market_key, price, ref_24h, series, source, fetched_at, last_error, last_error_at)
                     VALUES (?, ?, ?, ?, ?, NOW(), NULL, NULL)
                     ON DUPLICATE KEY UPDATE price=VALUES(price), ref_24h=VALUES(ref_24h), series=VALUES(series),
                        source=VALUES(source), fetched_at=NOW(), last_error=NULL, last_error_at=NULL"
                )->execute([$k, $q['price'], $q['ref_24h'], json_encode(array_values($q['series'])), $q['source']]);
                $row = ['market_key' => $k, 'price' => $q['price'], 'ref_24h' => $q['ref_24h'],
                        'series' => json_encode(array_values($q['series'])), 'source' => $q['source'], 'fetched_at' => date('Y-m-d H:i:s'), 'age_s' => 0];
            } else {
                $pdo->prepare(
                    "INSERT INTO live_market_quotes (market_key, last_error, last_error_at) VALUES (?, 'All price sources failed', NOW())
                     ON DUPLICATE KEY UPDATE last_error='All price sources failed', last_error_at=NOW()"
                )->execute([$k]);
            }
        }
        $out[$k] = ($row && $row['price'] !== null) ? live_row_to_quote($row) : null;
    }
    return $out;
}

/* ---------------- Plan baskets ---------------- */

/** Equal-weighted basket summary for a plan: 24h change, normalised series, per-market quotes. */
function plan_basket_summary(array $plan, array $quotes, ?array $dirs = null): array {
    $keys = parse_market_keys($plan['tracked_markets'] ?? '');
    $exposure = max(0.1, (float)($plan['exposure'] ?? 1));
    $items = [];
    $changes = [];
    $normSeries = [];
    foreach ($keys as $k) {
        $q = $quotes[$k] ?? null;
        $items[] = ['key' => $k, 'meta' => live_market_meta($k), 'quote' => $q];
        $d = $dirs === null ? 1 : (int)($dirs[$k] ?? 0); // bots: +1 long, -1 short, 0 flat
        if ($q && $q['change_pct'] !== null) $changes[] = $d * $q['change_pct'];
        if ($q && count($q['series']) >= 2 && $q['series'][0] > 0) {
            $base = $q['series'][0];
            $normSeries[] = array_map(fn($v) => $d * ($v / $base - 1) * 100, $q['series']);
        }
    }
    // Average the normalised series, aligned from the most recent point backwards.
    $series = [];
    if ($normSeries) {
        $len = min(array_map('count', $normSeries));
        for ($i = 0; $i < $len; $i++) {
            $sum = 0;
            foreach ($normSeries as $s) $sum += $s[count($s) - $len + $i];
            $series[] = 100 * (1 + ($exposure * $sum / count($normSeries)) / 100);
        }
    }
    $change = $changes ? $exposure * array_sum($changes) / count($changes) : null;
    return [
        'keys' => $keys,
        'items' => $items,
        'exposure' => $exposure,
        'change_24h' => $change,
        'series' => $series,
        'linked' => (bool)$keys,
        'complete' => $keys && count($changes) === count($keys),
    ];
}

function fmt_market_price(?float $price, int $decimals = 2): string {
    if ($price === null) return '—';
    if ($price >= 1000) $decimals = min($decimals, 2);
    return number_format($price, $decimals);
}

/** Smooth SVG sparkline (area + line) for a numeric series. */
function render_sparkline_svg(array $series, int $w = 300, int $h = 80, ?bool $up = null, string $idSuffix = ''): string {
    if (count($series) < 2) {
        return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none"><line x1="0" y1="' . ($h / 2) . '" x2="' . $w . '" y2="' . ($h / 2) . '" stroke="currentColor" stroke-opacity=".25" stroke-dasharray="4 4"/></svg>';
    }
    $min = min($series); $max = max($series);
    $range = ($max - $min) ?: 1;
    $n = count($series);
    $pad = 6;
    $pts = [];
    foreach ($series as $i => $v) {
        $x = $i / ($n - 1) * $w;
        $y = $pad + (1 - ($v - $min) / $range) * ($h - 2 * $pad);
        $pts[] = [round($x, 2), round($y, 2)];
    }
    // Catmull-Rom -> cubic bezier for a smooth curve
    $d = 'M' . $pts[0][0] . ',' . $pts[0][1];
    for ($i = 0; $i < $n - 1; $i++) {
        $p0 = $pts[max(0, $i - 1)]; $p1 = $pts[$i]; $p2 = $pts[$i + 1]; $p3 = $pts[min($n - 1, $i + 2)];
        $c1x = $p1[0] + ($p2[0] - $p0[0]) / 6; $c1y = $p1[1] + ($p2[1] - $p0[1]) / 6;
        $c2x = $p2[0] - ($p3[0] - $p1[0]) / 6; $c2y = $p2[1] - ($p3[1] - $p1[1]) / 6;
        $d .= sprintf(' C%.2f,%.2f %.2f,%.2f %.2f,%.2f', $c1x, $c1y, $c2x, $c2y, $p2[0], $p2[1]);
    }
    if ($up === null) $up = end($series) >= $series[0];
    $color = $up ? 'var(--positive)' : 'var(--negative)';
    $gid = 'sg' . substr(md5(implode(',', $series) . $idSuffix), 0, 8);
    return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
        . '<defs><linearGradient id="' . $gid . '" x1="0" y1="0" x2="0" y2="1">'
        . '<stop offset="0%" stop-color="' . $color . '" stop-opacity=".32"/><stop offset="100%" stop-color="' . $color . '" stop-opacity="0"/></linearGradient></defs>'
        . '<path d="' . $d . ' L' . $w . ',' . $h . ' L0,' . $h . ' Z" fill="url(#' . $gid . ')"/>'
        . '<path d="' . $d . '" fill="none" stroke="' . $color . '" stroke-width="2" vector-effect="non-scaling-stroke" stroke-linecap="round"/>'
        . '</svg>';
}
