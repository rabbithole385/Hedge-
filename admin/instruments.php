<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/market_data.php';

$admin = require_admin();
$pdo = get_db();
ensure_instrument_live_schema($pdo);
$errors = [];
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Session expired, please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $symbol = strtoupper(trim($_POST['symbol'] ?? ''));
            $name = trim($_POST['name'] ?? '');
            $assetType = $_POST['asset_type'] ?? 'stocks';
            if (!in_array($assetType, ['stocks','crypto','forex','metals','commodities','bonds'], true)) $assetType = 'stocks';
            $price = (float)($_POST['current_price'] ?? 0);
            $liveSymbol = strtoupper(trim($_POST['live_symbol'] ?? ''));
            if ($liveSymbol !== '' && !preg_match('/^[A-Z0-9.\-=^]{1,30}$/', $liveSymbol)) $liveSymbol = '';

            if ($symbol === '' || $name === '') {
                $errors[] = 'Symbol and name are required.';
            } elseif ($price <= 0) {
                $errors[] = 'Enter a valid starting price.';
            } else {
                if ($id > 0) {
                    $old = $pdo->prepare('SELECT live_symbol, symbol FROM instrument_prices WHERE id = ?');
                    $old->execute([$id]);
                    $prev = $old->fetch() ?: [];
                    // A new data source means the next live price is a fresh switch-over (holdings get rescaled).
                    $resetLive = ($prev['live_symbol'] ?? '') !== $liveSymbol || ($prev['symbol'] ?? '') !== $symbol;
                    $pdo->prepare('UPDATE instrument_prices SET symbol=?, name=?, asset_type=?, current_price=?, live_symbol=?'
                        . ($resetLive ? ', live_since=NULL, live_ref=NULL' : '') . ' WHERE id=?')
                        ->execute([$symbol, $name, $assetType, $price, $liveSymbol ?: null, $id]);
                    flash_set('success', 'Instrument updated.');
                } else {
                    $stmt = $pdo->prepare('SELECT id FROM instrument_prices WHERE symbol = ?');
                    $stmt->execute([$symbol]);
                    if ($stmt->fetch()) {
                        $errors[] = 'That symbol already exists.';
                    } else {
                        $pdo->prepare('INSERT INTO instrument_prices (symbol, name, asset_type, current_price, live_symbol) VALUES (?, ?, ?, ?, ?)')
                            ->execute([$symbol, $name, $assetType, $price, $liveSymbol ?: null]);
                        flash_set('success', 'Instrument added.');
                    }
                }
                if (!$errors) redirect('/admin/instruments.php');
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            // Block deletion if any user holds a position, to avoid orphaned holdings.
            $stmt = $pdo->prepare('SELECT symbol FROM instrument_prices WHERE id = ?');
            $stmt->execute([$id]);
            $symbol = $stmt->fetchColumn();
            $held = $pdo->prepare('SELECT COUNT(*) FROM holdings WHERE symbol = ?');
            $held->execute([$symbol]);
            if ((int)$held->fetchColumn() > 0) {
                flash_set('error', 'Cannot delete - users currently hold a position in this instrument.');
            } else {
                $pdo->prepare('DELETE FROM instrument_prices WHERE id = ?')->execute([$id]);
                flash_set('success', 'Instrument removed.');
            }
            redirect('/admin/instruments.php');
        }
    }
}

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM instrument_prices WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch();
}

$instruments = $pdo->query('SELECT * FROM instrument_prices ORDER BY asset_type, symbol')->fetchAll();
$counts = ['stocks' => 0, 'metals' => 0, 'commodities' => 0, 'crypto' => 0, 'forex' => 0, 'bonds' => 0];
foreach ($instruments as $i) $counts[$i['asset_type']] = ($counts[$i['asset_type']] ?? 0) + 1;

$pageTitle = 'Manage Instruments';
$navContext = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <?php if ($msg = flash_get('success')): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($msg = flash_get('error')): ?><div class="alert error"><?= e($msg) ?></div><?php endif; ?>

  <h1>Manage tradable instruments</h1>
  <div class="alert info" style="margin-bottom:18px;">Prices update automatically from live feeds about once a minute: Binance for crypto, Yahoo Finance for everything else, and Treasury ETFs for bonds. The price you type is only used until the first live price arrives. If a market shows no live price, set its Yahoo Finance symbol below (e.g. <code>BRK-B</code>, <code>DANGCEM.LG</code>, <code>^N225</code>).</div>
  <p class="subtitle">
    <?= count($instruments) ?> total &mdash;
    <?= $counts['stocks'] ?> stocks, <?= $counts['metals'] ?> metals, <?= $counts['commodities'] ?> commodities, <?= $counts['crypto'] ?> crypto.
    Adding one here makes it available immediately on Markets and Trade for every user.
  </p>

  <div class="grid cols-2">
    <div class="card">
      <h3><?= $editing ? 'Edit ' . e($editing['symbol']) : 'Add new instrument' ?></h3>
      <?php foreach ($errors as $err): ?><div class="alert error"><?= e($err) ?></div><?php endforeach; ?>
      <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">
        <div class="form-group">
          <label>Symbol (e.g. AAPL, BTC, XAUUSD)</label>
          <input type="text" name="symbol" value="<?= e($editing['symbol'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label>Name</label>
          <input type="text" name="name" value="<?= e($editing['name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label>Category</label>
          <select name="asset_type">
            <?php foreach (['stocks','crypto','forex','metals','commodities','bonds'] as $opt): ?>
              <option value="<?= $opt ?>" <?= ($editing['asset_type'] ?? '') === $opt ? 'selected' : '' ?>><?= ucfirst($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Starting / current price (USD)</label>
          <input type="number" step="0.0001" name="current_price" value="<?= e((string)($editing['current_price'] ?? '')) ?>" required>
        </div>
        <div class="form-group">
          <label>Live data symbol <span class="subtitle">(optional Yahoo Finance symbol; leave blank for automatic)</span></label>
          <input type="text" name="live_symbol" value="<?= e((string)($editing['live_symbol'] ?? '')) ?>" placeholder="auto">
        </div>
        <button class="btn block" type="submit"><?= $editing ? 'Update instrument' : 'Add instrument' ?></button>
        <?php if ($editing): ?><p style="margin-top:10px;"><a href="/admin/instruments.php">Cancel edit</a></p><?php endif; ?>
      </form>
    </div>

    <div class="card">
      <h3>All instruments</h3>
      <input type="text" class="instrument-filter" placeholder="Filter..." oninput="filterAdminInstruments(this.value)">
      <div class="table-scroll">
        <table id="admin-instrument-table">
          <thead><tr><th>Symbol</th><th>Name</th><th>Category</th><th>Price</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($instruments as $i): ?>
            <tr data-search="<?= e(strtolower($i['symbol'] . ' ' . $i['name'])) ?>">
              <td data-label="Symbol" class="mono-num"><?= e($i['symbol']) ?></td>
              <td data-label="Name"><?= e($i['name']) ?></td>
              <td data-label="Category"><span class="badge blue"><?= e(ucfirst($i['asset_type'])) ?></span></td>
              <td data-label="Price" class="mono-num"><?= fmt_money((float)$i['current_price']) ?></td>
              <td style="white-space:nowrap;">
                <a class="btn small secondary" href="/admin/instruments.php?edit=<?= (int)$i['id'] ?>">Edit</a>
                <form method="post" style="display:inline;" onsubmit="return confirm('Remove this instrument?');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$i['id'] ?>">
                  <button class="btn small danger" type="submit">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script>
function filterAdminInstruments(query) {
  query = query.trim().toLowerCase();
  const rows = document.querySelectorAll('#admin-instrument-table tbody tr');
  rows.forEach(function(row) {
    row.style.display = row.getAttribute('data-search').indexOf(query) !== -1 ? '' : 'none';
  });
}
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
