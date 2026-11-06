<?php
/**
 * INSTRUMENT PRICES - LIVE
 * ------------------------------------------------------------------
 * All instruments on Live Markets / Trade now use real prices:
 *   - Crypto: Binance (one batch request), Yahoo Finance as fallback
 *   - Stocks, forex, metals, commodities: Yahoo Finance (batches of 20)
 *   - Bonds: tracked via Treasury ETFs (SHY / IEF / TLT). The bond's price
 *     moves by the ETF's real % move, since bond prices have no free feed.
 *
 * Sync runs at most once a minute (cron or page load), never longer than
 * a few seconds, and never in two requests at the same time.
 * Instruments without a fresh live price cannot be traded.
 */
require_once __DIR__ . '/live_prices.php';

define('INSTRUMENT_TICK_MINUTES', 1);               // how often prices refresh
define('INSTRUMENT_SYNC_MIN_GAP', 60);              // seconds between syncs
define('INSTRUMENT_SYNC_BUDGET', 8.0);              // max seconds spent on HTTP per sync
define('INSTRUMENT_STALE_AFTER', 1800);             // a price older than this can't be traded
define('INSTRUMENT_HISTORY_EVERY', 900);            // store a chart point every 15 min

/** Where each instrument's live price comes from. */
function instrument_live_source(string $symbol, string $assetType, ?string $override = null): array {
    $symbol = strtoupper($symbol);
    if ($override) return ['yahoo' => $override, 'binance' => null, 'proxy' => false];

    if ($assetType === 'crypto') {
        // USDT has no USDT pair; Polygon's MATIC was renamed POL.
        // Not on Binance (delisted / renamed): USDT, XMR (delisted), FTM (now Sonic), EOS (now Vaulta).
        $notOnBinance = ['USDT' => 1, 'XMR' => 1, 'FTM' => 1, 'EOS' => 1];
        $binance = isset($notOnBinance[$symbol]) ? null : ($symbol === 'MATIC' ? 'POLUSDT' : $symbol . 'USDT');
        // Yahoo uses numbered tickers for some coins whose short names clash.
        $yahooMap = ['MATIC' => 'POL-USD', 'APT' => 'APT21794-USD', 'ARB' => 'ARB11841-USD', 'OP' => 'OP-USD',
                     'GRT' => 'GRT6719-USD', 'UNI' => 'UNI7083-USD', 'SAND' => 'SAND-USD', 'NEAR' => 'NEAR-USD'];
        $yahoo = $yahooMap[$symbol] ?? ($symbol . '-USD');
        return ['yahoo' => $yahoo, 'binance' => $binance, 'proxy' => false];
    }
    if ($assetType === 'forex') {
        $y = strpos($symbol, 'USD') === 0 ? substr($symbol, 3) . '=X' : $symbol . '=X';
        return ['yahoo' => $y, 'binance' => null, 'proxy' => false];
    }
    $map = [
        'XAUUSD' => 'GC=F', 'XAGUSD' => 'SI=F', 'XPTUSD' => 'PL=F', 'XPDUSD' => 'PA=F',
        'SOYBEAN' => 'ZS=F', 'WHEAT' => 'ZW=F', 'COTTON' => 'CT=F', 'SUGAR' => 'SB=F', 'COFFEE' => 'KC=F',
        'COPPER' => 'HG=F', 'WTI' => 'CL=F', 'CORN' => 'ZC=F', 'BRENT' => 'BZ=F', 'NATGAS' => 'NG=F',
        'ALUMINUM' => 'ALI=F', 'COCOA' => 'CC=F', 'GASOLINE' => 'RB=F', 'HEATOIL' => 'HO=F', 'LEANHOGS' => 'HE=F',
        'LIVECATTLE' => 'LE=F', 'LUMBER' => 'LBR=F', 'ORANGEJUICE' => 'OJ=F', 'OATS' => 'ZO=F', 'RICE' => 'ZR=F',
        'GOLD' => 'B', 'SQ' => 'XYZ', // renamed tickers (Barrick, Block)
    ];
    if ($assetType === 'bonds') {
        $etf = ['US02Y' => 'SHY', 'US10Y' => 'IEF', 'US30Y' => 'TLT'][$symbol] ?? 'IEF';
        return ['yahoo' => $etf, 'binance' => null, 'proxy' => true];
    }
    // No free live feed exists for these (LME base metals, rhodium). Admins can set a symbol override.
    $noFeed = ['NICKEL' => 1, 'ZINC' => 1, 'XRHUSD' => 1, 'LEAD' => 1, 'TIN' => 1];
    if (isset($noFeed[$symbol])) return ['yahoo' => null, 'binance' => null, 'proxy' => false];
    return ['yahoo' => $map[$symbol] ?? $symbol, 'binance' => null, 'proxy' => false];
}

function ensure_instrument_live_schema(PDO $pdo): void {
    $cols = [
        'live_since'  => "ALTER TABLE instrument_prices ADD COLUMN live_since DATETIME NULL",
        'live_symbol' => "ALTER TABLE instrument_prices ADD COLUMN live_symbol VARCHAR(30) NULL",
        'live_source' => "ALTER TABLE instrument_prices ADD COLUMN live_source VARCHAR(20) NULL",
        'live_ref'    => "ALTER TABLE instrument_prices ADD COLUMN live_ref DECIMAL(24,8) NULL",
    ];
    foreach ($cols as $c => $sql) {
        if (!$pdo->query("SHOW COLUMNS FROM instrument_prices LIKE '{$c}'")->fetch()) $pdo->exec($sql);
    }
    // Prices need 8 decimals so sub-cent coins (e.g. SHIB) are stored correctly.
    $widen = [
        ['instrument_prices', 'current_price', 'NOT NULL'],
        ['instrument_prices', 'price_24h_ago', 'NULL'],
        ['instrument_price_history', 'price', 'NOT NULL'],
        ['holdings', 'avg_cost', 'NOT NULL'],
    ];
    foreach ($widen as [$t, $c, $null]) {
        $col = $pdo->query("SHOW COLUMNS FROM {$t} LIKE '{$c}'")->fetch();
        if ($col && preg_match('/decimal\((\d+),(\d+)\)/i', $col['Type'], $m) && (int)$m[2] < 8) {
            $pdo->exec("ALTER TABLE {$t} MODIFY {$c} DECIMAL(24,8) {$null}");
        }
    }
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS live_migration_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kind VARCHAR(40) NOT NULL,
            ref VARCHAR(60) NULL,
            details TEXT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
}

/* ---------------- Batch providers ---------------- */

/** Binance 24h tickers for many symbols in one call. Returns sym => [price, open]. */
function live_batch_binance(array $symbols, int $depth = 0): array {
    $symbols = array_values($symbols);
    if (!$symbols) return [];
    if (isset($GLOBALS['LIVE_BATCH_MOCK'])) return ($GLOBALS['LIVE_BATCH_MOCK'])('binance', $symbols);
    $q = rawurlencode(json_encode($symbols));
    $badRequest = false;
    foreach (['https://data-api.binance.vision', 'https://api.binance.com'] as $host) {
        $body = live_http_get($host . '/api/v3/ticker/24hr?symbols=' . $q, $code);
        if ($body === null && $code === 400) { $badRequest = true; break; } // an unknown symbol in the list
        $rows = $body ? json_decode($body, true) : null;
        if (!is_array($rows) || isset($rows['code'])) continue;
        $out = [];
        foreach ($rows as $r) {
            if (!empty($r['symbol']) && (float)($r['lastPrice'] ?? 0) > 0) {
                $out[$r['symbol']] = ['price' => (float)$r['lastPrice'], 'ref' => (float)$r['openPrice'] ?: null, 'series' => []];
            }
        }
        return $out;
    }
    // Binance rejects the whole request if one symbol is unknown (e.g. a delisted coin).
    // Split the list and retry so one bad symbol can't take down the rest.
    if ($badRequest && count($symbols) > 1 && $depth < 6) {
        $half = (int)ceil(count($symbols) / 2);
        return live_batch_binance(array_slice($symbols, 0, $half), $depth + 1)
             + live_batch_binance(array_slice($symbols, $half), $depth + 1);
    }
    return [];
}

/**
 * Yahoo Finance spark endpoint: up to 20 symbols per call.
 * Returns sym => ['price','ref','series'=>[[ts,close],...]].
 */
function live_batch_yahoo(array $symbols): array {
    if (!$symbols) return [];
    if (isset($GLOBALS['LIVE_BATCH_MOCK'])) return ($GLOBALS['LIVE_BATCH_MOCK'])('yahoo', $symbols);
    $list = implode(',', array_map('rawurlencode', $symbols));
    $out = [];
    foreach (['https://query1.finance.yahoo.com/v7/finance/spark', 'https://query2.finance.yahoo.com/v8/finance/spark'] as $url) {
        $body = live_http_get($url . '?symbols=' . $list . '&range=1d&interval=15m');
        $j = $body ? json_decode($body, true) : null;
        if (!is_array($j)) continue;

        // v7 shape: {spark:{result:[{symbol, response:[{meta, timestamp, indicators}]}]}}
        if (isset($j['spark']['result']) && is_array($j['spark']['result'])) {
            foreach ($j['spark']['result'] as $r) {
                $resp = $r['response'][0] ?? null;
                if (!$resp || empty($r['symbol'])) continue;
                $closes = $resp['indicators']['quote'][0]['close'] ?? [];
                $ts = $resp['timestamp'] ?? [];
                $out[$r['symbol']] = live_yahoo_pack($resp['meta'] ?? [], $ts, $closes);
            }
        } else {
            // v8 shape: {SYM: {timestamp:[], close:[], chartPreviousClose, ...}}
            foreach ($j as $sym => $r) {
                if (!is_array($r) || !isset($r['close'])) continue;
                $out[$sym] = live_yahoo_pack($r, $r['timestamp'] ?? [], $r['close']);
            }
        }
        $out = array_filter($out);
        if ($out) return $out;
    }
    return [];
}

function live_yahoo_pack(array $meta, array $ts, array $closes): ?array {
    $series = [];
    foreach ($ts as $i => $t) {
        if (isset($closes[$i]) && $closes[$i] !== null && $closes[$i] > 0) $series[] = [(int)$t, (float)$closes[$i]];
    }
    $price = (float)($meta['regularMarketPrice'] ?? 0);
    if ($price <= 0 && $series) $price = end($series)[1];
    if ($price <= 0) return null;
    $ref = (float)($meta['chartPreviousClose'] ?? $meta['previousClose'] ?? 0);
    return ['price' => $price, 'ref' => $ref > 0 ? $ref : null, 'series' => $series];
}

/* ---------------- Sync ---------------- */

/**
 * Refresh instrument prices from the live feeds. Safe to call on every
 * page load: it returns immediately unless a minute has passed since the
 * last sync, and only one request at a time does the work.
 */
function process_due_instrument_ticks(PDO $pdo, bool $force = false): int {
    ensure_instrument_live_schema($pdo);

    if (!$force) {
        $since = $pdo->query(
            "SELECT TIMESTAMPDIFF(SECOND, STR_TO_DATE(value, '%Y-%m-%d %H:%i:%s'), NOW()) FROM settings WHERE setting_key = 'instruments_synced_at'"
        )->fetchColumn();
        if ($since !== false && $since !== null && (int)$since < INSTRUMENT_SYNC_MIN_GAP) return 0;
    }
    if ((int)$pdo->query("SELECT GET_LOCK('hedgeinv_instrument_sync', 0)")->fetchColumn() !== 1) return 0;

    try {
        $pdo->exec(
            "INSERT INTO settings (setting_key, value) VALUES ('instruments_synced_at', DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s'))
             ON DUPLICATE KEY UPDATE value = DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')"
        );

        $rows = $pdo->query(
            'SELECT *, TIMESTAMPDIFF(SECOND, (SELECT MAX(recorded_at) FROM instrument_price_history h WHERE h.symbol = i.symbol), NOW()) AS hist_age
             FROM instrument_prices i'
        )->fetchAll();

        $binanceWant = []; $yahooWant = []; $srcBySymbol = [];
        foreach ($rows as $r) {
            $src = instrument_live_source($r['symbol'], $r['asset_type'], $r['live_symbol'] ?: null);
            $srcBySymbol[$r['symbol']] = $src;
            if ($src['binance']) $binanceWant[$src['binance']] = true;
            if ($src['yahoo']) $yahooWant[$src['yahoo']] = true;
        }

        $started = microtime(true);
        $binance = live_batch_binance(array_keys($binanceWant));
        // Only ask Yahoo for what Binance didn't cover.
        $yahooNeed = [];
        foreach ($rows as $r) {
            $src = $srcBySymbol[$r['symbol']];
            // Binance-covered crypto still asks Yahoo once, on go-live, to backfill the 24h chart.
            if ($src['binance'] && isset($binance[$src['binance']]) && $r['live_since'] !== null) continue;
            if ($src['yahoo']) $yahooNeed[$src['yahoo']] = true;
        }
        $yahoo = [];
        foreach (array_chunk(array_keys($yahooNeed), 20) as $chunk) {
            if (microtime(true) - $started > INSTRUMENT_SYNC_BUDGET) break;
            $yahoo += live_batch_yahoo($chunk);
        }

        $updated = 0;
        foreach ($rows as $r) {
            $src = $srcBySymbol[$r['symbol']];
            $q = null; $source = null;
            if ($src['binance'] && isset($binance[$src['binance']])) {
                $q = $binance[$src['binance']]; $source = 'binance';
                if (!$q['series'] && $src['yahoo'] && !empty($yahoo[$src['yahoo']]['series'])) {
                    // Scale Yahoo's chart onto Binance's price so the two line up exactly.
                    $y = $yahoo[$src['yahoo']]; $k = $y['price'] > 0 ? $q['price'] / $y['price'] : 1;
                    $q['series'] = array_map(fn($pt) => [$pt[0], round($pt[1] * $k, 6)], $y['series']);
                }
            }
            elseif ($src['yahoo'] && isset($yahoo[$src['yahoo']])) { $q = $yahoo[$src['yahoo']]; $source = 'yahoo'; }
            if (!$q) continue;
            instrument_apply_live_quote($pdo, $r, $q, $source, $src);
            $updated++;
        }
        return $updated;
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('hedgeinv_instrument_sync')");
    }
}

function instrument_apply_live_quote(PDO $pdo, array $r, array $q, string $source, array $src): void {
    $old = (float)$r['current_price'];
    $firstLive = $r['live_since'] === null;

    if ($src['proxy']) {
        // Bond: move our price by the ETF's real % move.
        $etfNow = $q['price'];
        $etfRef = $r['live_ref'] !== null ? (float)$r['live_ref'] : $etfNow;
        $price = $etfRef > 0 ? round($old * ($etfNow / $etfRef), 8) : $old;
        $ref24 = ($q['ref'] && $etfNow > 0) ? round($price * ($q['ref'] / $etfNow), 8) : null;
        $liveRef = $etfNow;
        $series = [];
        foreach ($q['series'] as [$t, $c]) $series[] = [$t, round($price * ($c / $etfNow), 8)];
    } else {
        $price = round($q['price'], 8);
        $ref24 = $q['ref'] ? round($q['ref'], 8) : null;
        $liveRef = null;
        $series = $q['series'];
    }
    if ($price <= 0) return;

    $pdo->beginTransaction();
    try {
        if ($firstLive) {
            // Switching this instrument from the old internal price to the real one.
            // Holdings are rescaled so every client keeps exactly the same position
            // value and P&L % they had a moment before the switch.
            if (!$src['proxy'] && $old > 0 && abs($price / $old - 1) > 0.000001) {
                $ratio = $old / $price;
                $h = $pdo->prepare('SELECT id, user_id, quantity, avg_cost FROM holdings WHERE symbol = ?');
                $h->execute([$r['symbol']]);
                foreach ($h->fetchAll() as $hold) {
                    $newQty = round((float)$hold['quantity'] * $ratio, 8);
                    $newAvg = round((float)$hold['avg_cost'] / $ratio, 8);
                    $pdo->prepare('UPDATE holdings SET quantity = ?, avg_cost = ? WHERE id = ?')->execute([$newQty, $newAvg, $hold['id']]);
                    $pdo->prepare("INSERT INTO live_migration_log (kind, ref, details) VALUES ('holding_rescale', ?, ?)")
                        ->execute([$r['symbol'], json_encode([
                            'holding_id' => (int)$hold['id'], 'user_id' => (int)$hold['user_id'],
                            'old_price' => $old, 'live_price' => $price,
                            'old_qty' => (float)$hold['quantity'], 'new_qty' => $newQty,
                            'old_avg_cost' => (float)$hold['avg_cost'], 'new_avg_cost' => $newAvg,
                        ])]);
                }
            }
            // The old chart history came from the random engine - replace it with real points.
            $pdo->prepare('DELETE FROM instrument_price_history WHERE symbol = ?')->execute([$r['symbol']]);
            $ins = $pdo->prepare('INSERT INTO instrument_price_history (symbol, price, recorded_at) VALUES (?, ?, FROM_UNIXTIME(?))');
            foreach ($series as [$t, $c]) $ins->execute([$r['symbol'], $c, $t]);
        }

        $pdo->prepare(
            'UPDATE instrument_prices SET current_price = ?, price_24h_ago = ?, price_24h_updated_at = NOW(), last_tick_at = NOW(),
                live_source = ?, live_ref = ?, live_since = COALESCE(live_since, NOW()) WHERE id = ?'
        )->execute([$price, $ref24, $source, $liveRef, $r['id']]);

        if ($firstLive || $r['hist_age'] === null || (int)$r['hist_age'] >= INSTRUMENT_HISTORY_EVERY) {
            $pdo->prepare('INSERT INTO instrument_price_history (symbol, price) VALUES (?, ?)')->execute([$r['symbol'], $price]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('Instrument live update failed for ' . $r['symbol'] . ': ' . $e->getMessage());
    }
}

/* ---------------- Reads ---------------- */

const INSTRUMENT_SELECT = "SELECT i.*, TIMESTAMPDIFF(SECOND, i.last_tick_at, NOW()) AS age_s FROM instrument_prices i";

function get_all_instruments(PDO $pdo): array {
    return $pdo->query(INSTRUMENT_SELECT . ' ORDER BY asset_type, symbol')->fetchAll();
}

function get_instrument_by_symbol(PDO $pdo, string $symbol): ?array {
    $stmt = $pdo->prepare(INSTRUMENT_SELECT . ' WHERE symbol = ?');
    $stmt->execute([$symbol]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** True when the instrument has a real, recent price that can be traded on. */
function instrument_is_live(array $i): bool {
    return !empty($i['live_since']) && isset($i['age_s']) && $i['age_s'] !== null && (int)$i['age_s'] <= INSTRUMENT_STALE_AFTER;
}

function get_instruments_by_category(PDO $pdo): array {
    $all = get_all_instruments($pdo);
    $byCategory = ['stocks' => [], 'metals' => [], 'commodities' => [], 'crypto' => [], 'forex' => [], 'bonds' => []];
    foreach ($all as $row) {
        $byCategory[$row['asset_type']][] = $row;
    }
    return $byCategory;
}

/** Change since the 24h-ago reference point - returns [amount, percent] or [null, null] if not yet available. */
function get_24h_change(array $instrument): array {
    if ($instrument['price_24h_ago'] === null || (float)$instrument['price_24h_ago'] == 0) {
        return [null, null];
    }
    $before = (float)$instrument['price_24h_ago'];
    $now = (float)$instrument['current_price'];
    $amount = $now - $before;
    $pct = ($amount / $before) * 100;
    return [$amount, $pct];
}

function get_price_history(PDO $pdo, string $symbol, int $limit = 100): array {
    $stmt = $pdo->prepare(
        'SELECT price, recorded_at FROM instrument_price_history WHERE symbol = ? ORDER BY recorded_at DESC LIMIT ?'
    );
    $stmt->bindValue(1, $symbol);
    $stmt->bindValue(2, $limit, PDO::PARAM_INT);
    $stmt->execute();
    return array_reverse($stmt->fetchAll());
}

/** Last N history prices for many symbols at once (for sparklines). */
function get_sparklines(PDO $pdo, array $symbols, int $points = 32): array {
    if (!$symbols) return [];
    $in = implode(',', array_fill(0, count($symbols), '?'));
    $stmt = $pdo->prepare(
        "SELECT symbol, price FROM instrument_price_history
         WHERE symbol IN ($in) AND recorded_at >= NOW() - INTERVAL 2 DAY ORDER BY recorded_at ASC"
    );
    $stmt->execute(array_values($symbols));
    $out = [];
    foreach ($stmt->fetchAll() as $r) $out[$r['symbol']][] = (float)$r['price'];
    foreach ($out as $k => $v) $out[$k] = array_slice($v, -$points);
    return $out;
}

/** Display order: the markets people look for first, then alphabetical. */
function instrument_priority(string $symbol): int {
    static $top = null;
    if ($top === null) {
        $top = array_flip(['BTC','ETH','USDT','BNB','SOL','XRP','DOGE','ADA','TRX','AVAX','SHIB','LINK','BCH','XLM','DOT','LTC','NEAR','ICP','APT','UNI','ETC','XMR','MATIC','AAVE','ARB','OP','FIL','ATOM',
            'SPY','QQQ','PLTR','NOW','INTU','AMAT','MU','PANW','CRWD','BKNG',
            'AAPL','NVDA','MSFT','AMZN','GOOGL','META','TSLA','AVGO','JPM','V','MA','NFLX','AMD','LLY','COST','WMT','XOM','KO',
            'XAUUSD','XAGUSD','XPTUSD','XPDUSD','WTI','BRENT','NATGAS','COPPER',
            'EURUSD','GBPUSD','USDJPY','AUDUSD','USDCAD','USDCHF','US10Y','US02Y','US30Y']);
    }
    return $top[$symbol] ?? 1000;
}

/** Price formatting with sensible decimals for any asset. */
function fmt_instrument_price(float $p): string {
    if ($p > 0 && $p < 0.01) return '$' . rtrim(rtrim(number_format($p, 8), '0'), '.');
    if ($p >= 1000) return '$' . number_format($p, 2);
    if ($p >= 1) return '$' . number_format($p, $p >= 100 ? 2 : 3);
    return '$' . number_format($p, 5);
}
