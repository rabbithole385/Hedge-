<?php
/**
 * ASSET ICONS
 * Real coin symbols (cryptocurrency-icons, CC0), country flags (flag-icons, MIT)
 * and hand-drawn glyphs for metals, energy and crops. Stocks get a clean
 * colour-coded monogram tile.
 *
 * asset_icon(string $symbol, string $kind, int $size = 34): string (HTML)
 *   $kind: crypto | forex | index | metal | commodity | bond | stock
 */

function asset_icon_kind_for_instrument(string $assetType, string $symbol): string {
    switch ($assetType) {
        case 'crypto': return 'crypto';
        case 'forex': return 'forex';
        case 'metals': return 'metal';
        case 'commodities': return 'commodity';
        case 'bonds': return 'bond';
        default: return 'stock';
    }
}

/** Icon kind + symbol for a live-catalog market key (plans & bots). */
function asset_icon_for_market_key(string $key): array {
    $key = strtoupper($key);
    $map = [
        'BTC' => ['crypto', 'BTC'], 'ETH' => ['crypto', 'ETH'], 'SOL' => ['crypto', 'SOL'], 'BNB' => ['crypto', 'BNB'], 'XRP' => ['crypto', 'XRP'],
        'GOLD' => ['metal', 'XAUUSD'], 'SILVER' => ['metal', 'XAGUSD'],
        'OIL' => ['commodity', 'WTI'], 'BRENT' => ['commodity', 'BRENT'], 'NATGAS' => ['commodity', 'NATGAS'],
        'SPX' => ['index', 'SPX'], 'NDX' => ['index', 'NDX'], 'DJI' => ['index', 'DJI'], 'FTSE' => ['index', 'FTSE'], 'DAX' => ['index', 'DAX'],
        'EURUSD' => ['forex', 'EURUSD'], 'GBPUSD' => ['forex', 'GBPUSD'], 'USDJPY' => ['forex', 'USDJPY'],
    ];
    if (isset($map[$key])) return $map[$key];
    return ['stock', strpos($key, 'Y:') === 0 ? substr($key, 2) : $key];
}

function asset_icon_market(string $key, int $size = 34): string {
    [$kind, $sym] = asset_icon_for_market_key($key);
    return asset_icon($sym, $kind, $size);
}

function asset_icon_instrument(array $instrument, int $size = 34): string {
    return asset_icon($instrument['symbol'], asset_icon_kind_for_instrument($instrument['asset_type'], $instrument['symbol']), $size);
}

function asset_icon(string $symbol, string $kind, int $size = 34): string {
    $symbol = strtoupper($symbol);
    $s = (int)$size;
    $style = "width:{$s}px;height:{$s}px";
    $base = __DIR__ . '/../assets/icons/';

    if ($kind === 'crypto') {
        $file = strtolower($symbol) . '.svg';
        if (is_file($base . 'crypto/' . $file)) {
            return '<span class="ai ai-img" style="' . $style . '"><img src="/assets/icons/crypto/' . $file . '" alt="' . htmlspecialchars($symbol) . '" loading="lazy"></span>';
        }
        return asset_icon_monogram($symbol, $s);
    }

    if ($kind === 'forex' && strlen($symbol) === 6) {
        $cc = ['EUR' => 'eu', 'USD' => 'us', 'GBP' => 'gb', 'JPY' => 'jp', 'AUD' => 'au', 'CAD' => 'ca', 'CHF' => 'ch', 'CNY' => 'cn', 'NZD' => 'nz', 'NGN' => 'ng'];
        $a = $cc[substr($symbol, 0, 3)] ?? null; $b = $cc[substr($symbol, 3, 3)] ?? null;
        if ($a && $b) {
            $f = (int)round($s * 0.74);
            return '<span class="ai ai-pair" style="' . $style . '">'
                . '<img class="p1" src="/assets/icons/flags/' . $a . '.svg" alt="" style="width:' . $f . 'px;height:' . $f . 'px">'
                . '<img class="p2" src="/assets/icons/flags/' . $b . '.svg" alt="" style="width:' . $f . 'px;height:' . $f . 'px">'
                . '</span>';
        }
    }

    if ($kind === 'index') {
        $cc = ['SPX' => 'us', 'NDX' => 'us', 'DJI' => 'us', 'FTSE' => 'gb', 'DAX' => 'de'][$symbol] ?? 'us';
        return '<span class="ai ai-flag" style="' . $style . '"><img src="/assets/icons/flags/' . $cc . '.svg" alt=""><b>' . htmlspecialchars(['SPX' => '500', 'NDX' => '100', 'DJI' => '30', 'FTSE' => '100', 'DAX' => '40'][$symbol] ?? '') . '</b></span>';
    }

    if ($kind === 'bond') {
        return '<span class="ai ai-flag" style="' . $style . '"><img src="/assets/icons/flags/us.svg" alt=""><b>' . htmlspecialchars(preg_replace('/\D/', '', $symbol) . 'Y') . '</b></span>';
    }

    if ($kind === 'metal' || $kind === 'commodity') {
        $g = asset_glyph($symbol);
        if ($g) return '<span class="ai ai-glyph" style="' . $style . '">' . $g . '</span>';
    }

    return asset_icon_monogram($symbol, $s);
}

function asset_icon_monogram(string $symbol, int $s): string {
    $palette = [
        ['#6d94f5', '#3d5fd1'], ['#a78bfa', '#6d4fd8'], ['#34d399', '#0f9a6c'], ['#f59e8b', '#d4574a'],
        ['#38bdf8', '#0b7fb8'], ['#f7b955', '#c9821a'], ['#f472b6', '#be3b83'], ['#94a3b8', '#526178'],
    ];
    [$c1, $c2] = $palette[crc32($symbol) % count($palette)];
    $txt = substr(preg_replace('/[^A-Z0-9]/', '', $symbol), 0, 4);
    $fs = strlen($txt) >= 4 ? 0.27 : (strlen($txt) === 3 ? 0.31 : 0.38);
    return '<span class="ai ai-mono" style="width:' . $s . 'px;height:' . $s . 'px;font-size:' . round($s * $fs, 1) . 'px;background:linear-gradient(140deg,' . $c1 . ',' . $c2 . ')">' . htmlspecialchars($txt) . '</span>';
}

/** Hand-drawn 40x40 glyphs for metals, energy and crops. */
function asset_glyph(string $symbol): ?string {
    $bar = function (string $a, string $b, string $c, string $label) {
        $id = 'g' . substr(md5($a . $label), 0, 6);
        return '<svg viewBox="0 0 40 40"><defs><linearGradient id="' . $id . '" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="' . $a . '"/><stop offset=".55" stop-color="' . $b . '"/><stop offset="1" stop-color="' . $c . '"/></linearGradient></defs>'
            . '<circle cx="20" cy="20" r="20" fill="#16233f"/>'
            . '<path d="M5.5 29 10.6 12.5h18.8L34.5 29z" fill="url(#' . $id . ')"/><path d="M10.6 12.5h18.8l-1.7 3.4H12.3z" fill="#fff" fill-opacity=".38"/><path d="M5.5 29h29" stroke="#000" stroke-opacity=".18"/>'
            . '<text x="20" y="25.4" text-anchor="middle" font-family="Arial,sans-serif" font-weight="800" font-size="8" fill="#00000080">' . $label . '</text></svg>';
    };
    $round = function (string $inner, string $bg = '#16233f') {
        return '<svg viewBox="0 0 40 40"><circle cx="20" cy="20" r="20" fill="' . $bg . '"/>' . $inner . '</svg>';
    };
    switch ($symbol) {
        case 'XAUUSD': case 'GOLD': return $bar('#fde68a', '#e5b64a', '#a87716', 'Au');
        case 'XAGUSD': case 'SILVER': return $bar('#f8fafc', '#c3cad6', '#7d8796', 'Ag');
        case 'XPTUSD': return $bar('#eef2f7', '#aab4c3', '#677386', 'Pt');
        case 'XPDUSD': return $bar('#f1ece4', '#b9ad9a', '#776a55', 'Pd');
        case 'COPPER': return $bar('#f8c4a0', '#d77b45', '#8f4416', 'Cu');
        case 'XRHUSD': return $bar('#f4f6fa', '#b8c0cc', '#6f7a8a', 'Rh');
        case 'ALUMINUM': return $bar('#f1f5f9', '#cbd5e1', '#8a97a8', 'Al');
        case 'NICKEL': return $bar('#e9ece4', '#a9b09a', '#6b725c', 'Ni');
        case 'ZINC': return $bar('#e6edf3', '#9fb0bf', '#5f7284', 'Zn');
        case 'GASOLINE': case 'HEATOIL':
            $c = $symbol === 'GASOLINE' ? '#f87171' : '#fb923c';
            return $round('<path d="M20 9.5c4.2 5.6 7.2 9.4 7.2 13.2a7.2 7.2 0 0 1-14.4 0c0-3.8 3-7.6 7.2-13.2z" fill="' . $c . '"/><path d="M16.6 23.4a3.4 3.4 0 0 0 3.2 3.4" stroke="#fff" stroke-opacity=".7" stroke-width="1.4" fill="none" stroke-linecap="round"/>');
        case 'COCOA':
            return $round('<ellipse cx="20" cy="20" rx="7" ry="11" transform="rotate(-25 20 20)" fill="#7b4a2a"/><path d="M15.4 11.6c2.4 2.6 3.6 5.4 3.6 8.4s1.4 6 4.8 8.6" stroke="#4a2914" stroke-width="1.3" fill="none"/><path d="M17.6 10.8 23 29" stroke="#9c6640" stroke-width=".9"/>');
        case 'ORANGEJUICE':
            return $round('<circle cx="20" cy="21.5" r="8.5" fill="#fb923c"/><circle cx="20" cy="21.5" r="5.6" fill="#fdba74"/><path d="M20 15.9v11.2M14.4 21.5h11.2M16 17.5l8 8M24 17.5l-8 8" stroke="#fb923c" stroke-width=".9"/><path d="M20 13c1.6-2.4 3.6-3 5.4-2.6-1 1.8-2.8 2.8-5.4 2.6z" fill="#4ade80"/>');
        case 'LUMBER':
            return $round('<rect x="9" y="15" width="20" height="10" rx="5" fill="#b07a46"/><ellipse cx="28" cy="20" rx="3.4" ry="5" fill="#e8c18f"/><ellipse cx="28" cy="20" rx="1.6" ry="2.4" fill="none" stroke="#b07a46" stroke-width=".9"/><path d="M12 18.5h10M13 21.5h8" stroke="#8a5a2c" stroke-width=".9" stroke-linecap="round"/>');
        case 'WTI': case 'OIL': case 'BRENT':
            return $round('<path d="M20 9.5c4.2 5.6 7.2 9.4 7.2 13.2a7.2 7.2 0 0 1-14.4 0c0-3.8 3-7.6 7.2-13.2z" fill="#0b0f19" stroke="#e8c988" stroke-width="1.4"/><path d="M16.6 23.4a3.4 3.4 0 0 0 3.2 3.4" stroke="#e8c988" stroke-width="1.4" fill="none" stroke-linecap="round"/>'
                . ($symbol === 'BRENT' ? '<text x="20" y="35.2" text-anchor="middle" font-family="Arial" font-weight="700" font-size="4.6" fill="#e8c988">BRENT</text>' : ''));
        case 'NATGAS':
            return $round('<path d="M20.4 8.5c1 4.4 5.8 6.6 5.8 12.6a6.2 6.2 0 0 1-12.4 0c0-2.8 1.4-4.5 2.8-6 .2 2 1 3.2 2.3 3.8-.6-3.8.2-7 1.5-10.4z" fill="#5aa9ff"/><path d="M20 30.2a3 3 0 0 1-3-3c0-1.8 1.4-2.8 3-5 1.6 2.2 3 3.2 3 5a3 3 0 0 1-3 3z" fill="#c9e6ff"/>');
        case 'WHEAT': case 'CORN': case 'SOYBEAN':
            $col = ['WHEAT' => '#e8c988', 'CORN' => '#f7c948', 'SOYBEAN' => '#b7d36b'][$symbol];
            $g = '<path d="M20 31V12" stroke="' . $col . '" stroke-width="1.6" stroke-linecap="round"/>';
            foreach ([13.5, 17.5, 21.5, 25.5] as $y) {
                $g .= '<ellipse cx="16.8" cy="' . $y . '" rx="3.2" ry="1.7" transform="rotate(-35 16.8 ' . $y . ')" fill="' . $col . '"/>';
                $g .= '<ellipse cx="23.2" cy="' . $y . '" rx="3.2" ry="1.7" transform="rotate(35 23.2 ' . $y . ')" fill="' . $col . '"/>';
            }
            return $round($g . '<ellipse cx="20" cy="10.6" rx="1.6" ry="2.6" fill="' . $col . '"/>');
        case 'COFFEE':
            return $round('<ellipse cx="20" cy="20" rx="7.5" ry="10" transform="rotate(30 20 20)" fill="#8a5a3b"/><path d="M15.6 12.4c4 3.4 4.6 10.6 8.8 15.2" stroke="#3b2414" stroke-width="1.6" fill="none" stroke-linecap="round"/>');
        case 'SUGAR':
            return $round('<path d="M20 10.5 28 15v9.4l-8 4.6-8-4.6V15z" fill="#f8fafc"/><path d="M20 19.6 28 15M20 19.6 12 15M20 19.6V29" stroke="#c3cad6" stroke-width="1"/>');
        case 'COTTON':
            return $round('<circle cx="16.5" cy="17.5" r="4.6" fill="#f8fafc"/><circle cx="23.5" cy="17.5" r="4.6" fill="#f8fafc"/><circle cx="20" cy="22.6" r="4.8" fill="#fff"/><path d="M14 27.5c2-1.2 4-1.6 6-1.6s4 .4 6 1.6" stroke="#7aa35a" stroke-width="1.8" fill="none" stroke-linecap="round"/>');
    }
    return null;
}
