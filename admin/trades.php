<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_admin();
$pdo = get_db();
$errors = [];
$success = null;

$experts = $pdo->query("SELECT * FROM experts ORDER BY name")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Session expired, please try again.';
    } else {
        $expertId = (int)($_POST['expert_id'] ?? 0);
        $symbol = strtoupper(trim($_POST['asset_symbol'] ?? ''));
        $assetType = $_POST['asset_type'] ?? 'stock';
        $direction = $_POST['direction'] ?? 'buy';
        $entryPrice = (float)($_POST['entry_price'] ?? 0);
        $exitPrice = (float)($_POST['exit_price'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');

        $stmt = $pdo->prepare('SELECT * FROM experts WHERE id = ?');
        $stmt->execute([$expertId]);
        $expert = $stmt->fetch();

        if (!$expert) {
            $errors[] = 'Select a valid expert.';
        } elseif ($symbol === '' || $entryPrice <= 0 || $exitPrice <= 0) {
            $errors[] = 'Fill in symbol, entry price, and exit price.';
        } else {
            $rawChange = (($exitPrice - $entryPrice) / $entryPrice) * 100;
            $profitPercent = $direction === 'sell' ? -$rawChange : $rawChange;
            $profitPercent = round($profitPercent, 4);

            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'INSERT INTO trades (expert_id, asset_symbol, asset_type, direction, entry_price, exit_price, profit_percent, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                )->execute([$expertId, $symbol, $assetType, $direction, $entryPrice, $exitPrice, $profitPercent, $notes]);
                $tradeId = (int)$pdo->lastInsertId();

                $affected = distribute_trade_result($pdo, $tradeId, $expertId, $profitPercent);
                $pdo->commit();

                $success = "Trade posted: {$symbol} ({$direction}) result " . fmt_pct($profitPercent)
                    . ". Distributed to {$affected} active copier(s).";
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Failed to post trade: ' . $e->getMessage();
            }
        }
    }
}

$recentTrades = $pdo->query(
    "SELECT t.*, e.name as expert_name FROM trades t JOIN experts e ON e.id = t.expert_id ORDER BY t.trade_date DESC LIMIT 20"
)->fetchAll();

$pageTitle = 'Post Trade Result';
$navContext = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <h1>Post a trade result</h1>
  <p class="subtitle">Posting a result here instantly recalculates every active copier's balance for that expert — this is the copy-trading engine.</p>

  <?php if ($success): ?><div class="alert success"><?= e($success) ?></div><?php endif; ?>
  <?php foreach ($errors as $err): ?><div class="alert error"><?= e($err) ?></div><?php endforeach; ?>

  <div class="grid cols-2">
    <div class="card">
      <h3>New trade</h3>
      <form method="post">
        <?= csrf_field() ?>
        <div class="form-group">
          <label>Expert</label>
          <select name="expert_id" required>
            <option value="">-- select --</option>
            <?php foreach ($experts as $ex): ?>
              <option value="<?= (int)$ex['id'] ?>"><?= e($ex['name']) ?> (<?= e(ucfirst($ex['asset_focus'])) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Asset symbol (e.g. AAPL, XAUUSD, WTI)</label>
          <input type="text" name="asset_symbol" list="symbolList" placeholder="e.g. AAPL, XAUUSD, BTC" required>
          <datalist id="symbolList">
            <option value="AAPL">Apple Inc.</option>
            <option value="MSFT">Microsoft Corp.</option>
            <option value="GOOGL">Alphabet Inc.</option>
            <option value="AMZN">Amazon.com Inc.</option>
            <option value="NVDA">NVIDIA Corp.</option>
            <option value="META">Meta Platforms Inc.</option>
            <option value="TSLA">Tesla Inc.</option>
            <option value="JPM">JPMorgan Chase & Co.</option>
            <option value="XAUUSD">Gold Spot / USD</option>
            <option value="XAGUSD">Silver Spot / USD</option>
            <option value="WTI">Crude Oil (WTI)</option>
            <option value="BRENT">Crude Oil (Brent)</option>
            <option value="NATGAS">Natural Gas</option>
            <option value="BTC">Bitcoin</option>
            <option value="ETH">Ethereum</option>
            <option value="USDT">Tether</option>
          </datalist>
        </div>
        <div class="form-group">
          <label>Asset type</label>
          <select name="asset_type">
            <option value="stock">Stock</option>
            <option value="gold">Gold</option>
            <option value="commodity">Commodity</option>
          </select>
        </div>
        <div class="form-group">
          <label>Direction</label>
          <select name="direction">
            <option value="buy">Buy (long)</option>
            <option value="sell">Sell (short)</option>
          </select>
        </div>
        <div class="form-group">
          <label>Entry price</label>
          <input type="number" step="0.0001" name="entry_price" required>
        </div>
        <div class="form-group">
          <label>Exit price</label>
          <input type="number" step="0.0001" name="exit_price" required>
        </div>
        <div class="form-group">
          <label>Notes (optional)</label>
          <textarea name="notes" rows="2"></textarea>
        </div>
        <button class="btn block" type="submit">Post trade &amp; distribute P&amp;L</button>
      </form>
    </div>

    <div class="card">
      <h3>Recent trades</h3>
      <table>
        <thead><tr><th>Expert</th><th>Symbol</th><th>Dir</th><th>Result</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach ($recentTrades as $t): ?>
          <tr>
            <td><?= e($t['expert_name']) ?></td>
            <td><?= e($t['asset_symbol']) ?></td>
            <td><?= e(ucfirst($t['direction'])) ?></td>
            <td class="<?= $t['profit_percent'] >= 0 ? 'pos' : 'neg' ?>"><?= fmt_pct($t['profit_percent']) ?></td>
            <td><?= e(date('M j, g:ia', strtotime($t['trade_date']))) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
