<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_admin();
$pdo = get_db();
process_all_due_ticks($pdo);
$errors = [];
$editing = null;
$exposureOptions = ['0.5' => '0.5x (half the market move)', '1' => '1x (matches the market)', '1.5' => '1.5x', '2' => '2x', '3' => '3x', '5' => '5x'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Session expired, please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $riskLevel = $_POST['risk_level'] ?? 'medium';
            if (!in_array($riskLevel, ['low', 'medium', 'high'], true)) $riskLevel = 'medium';
            $tickMinutes = max(5, (int)($_POST['tick_interval_minutes'] ?? 15));
            $minInvestment = max(0, (float)($_POST['min_investment'] ?? 100));
            $exposureIn = (string)($_POST['exposure'] ?? '1');
            $exposure = isset($exposureOptions[$exposureIn]) ? (float)$exposureIn : 1.0;

            $picked = array_map('strval', (array)($_POST['markets'] ?? []));
            $custom = array_filter(array_map(function ($s) {
                $s = strtoupper(trim($s));
                return $s === '' ? '' : 'Y:' . preg_replace('/^Y:/', '', $s);
            }, explode(',', (string)($_POST['custom_markets'] ?? ''))));
            $markets = parse_market_keys(implode(',', array_merge($picked, $custom)));

            if ($name === '') $errors[] = 'Name is required.';
            if (!$markets) $errors[] = 'Pick at least one market for the plan to track.';
            if (count($markets) > 12) $errors[] = 'A plan can track at most 12 markets.';

            $photoUrl = null;
            $upErr = $_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE;
            if (!$errors && $upErr === UPLOAD_ERR_OK && !empty($_FILES['photo']['name'])) {
                $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
                $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                $tmp = $_FILES['photo']['tmp_name'];
                $mime = '';
                if (function_exists('finfo_open')) {
                    $finfo = finfo_open(FILEINFO_MIME_TYPE);
                    $mime = (string)finfo_file($finfo, $tmp);
                    finfo_close($finfo);
                } elseif (function_exists('getimagesize')) {
                    $info = @getimagesize($tmp);
                    $mime = $info['mime'] ?? '';
                }
                if (!isset($allowed[$ext]) || ($mime !== '' && $allowed[$ext] !== $mime)) {
                    $errors[] = 'Photo must be a JPG, PNG, or WEBP image.';
                } elseif ($_FILES['photo']['size'] > 3 * 1024 * 1024) {
                    $errors[] = 'Photo must be under 3MB.';
                } else {
                    $newName = 'plan_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    $destDir = __DIR__ . '/../assets/uploads/experts/';
                    if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
                    if (@move_uploaded_file($tmp, $destDir . $newName)) {
                        $photoUrl = '/assets/uploads/experts/' . $newName;
                    } else {
                        $errors[] = 'Photo upload failed: check that assets/uploads/experts is writable.';
                    }
                }
            } elseif ($upErr === UPLOAD_ERR_INI_SIZE || $upErr === UPLOAD_ERR_FORM_SIZE) {
                $errors[] = 'Photo is too large for the server (max ' . ini_get('upload_max_filesize') . ').';
            }

            if (!$errors) {
                $marketCsv = implode(',', $markets);
                try {
                    if ($id > 0) {
                        $sql = 'UPDATE investment_plans SET name=?, description=?, risk_level=?, tick_interval_minutes=?, min_investment=?, tracked_markets=?, exposure=?'
                             . ($photoUrl ? ', photo_url=?' : '') . ' WHERE id=?';
                        $params = [$name, $description, $riskLevel, $tickMinutes, $minInvestment, $marketCsv, $exposure];
                        if ($photoUrl) $params[] = $photoUrl;
                        $params[] = $id;
                        $pdo->prepare($sql)->execute($params);
                        flash_set('success', 'Plan updated. Active subscriptions re-lock their reference prices on the next update if the markets changed.');
                    } else {
                        $pdo->prepare(
                            "INSERT INTO investment_plans (name, description, risk_level, min_return_pct, max_return_pct, tick_interval_minutes, min_investment, tracked_markets, exposure, photo_url, status)
                             VALUES (?, ?, ?, 0, 0, ?, ?, ?, ?, ?, 'active')"
                        )->execute([$name, $description, $riskLevel, $tickMinutes, $minInvestment, $marketCsv, $exposure, $photoUrl]);
                        flash_set('success', 'Plan created and linked to live markets.');
                    }
                    redirect('/admin/plans.php');
                } catch (Throwable $e) {
                    error_log('Plan save failed: ' . $e->getMessage());
                    $errors[] = 'Could not save plan: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'toggle_status') {
            $id = (int)($_POST['id'] ?? 0);
            $current = $pdo->prepare('SELECT status FROM investment_plans WHERE id = ?');
            $current->execute([$id]);
            $new = $current->fetchColumn() === 'active' ? 'paused' : 'active';
            $pdo->prepare('UPDATE investment_plans SET status = ? WHERE id = ?')->execute([$new, $id]);
            flash_set('success', 'Plan status updated.');
            redirect('/admin/plans.php');
        }
    }
}

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM investment_plans WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch();
}

$plans = $pdo->query(
    "SELECT p.*,
            (SELECT COUNT(*) FROM plan_subscriptions s WHERE s.plan_id = p.id AND s.status = 'active') AS active_subs,
            (SELECT COALESCE(SUM(current_value),0) FROM plan_subscriptions s WHERE s.plan_id = p.id AND s.status = 'active') AS active_value
     FROM investment_plans p ORDER BY p.created_at DESC"
)->fetchAll();

$allKeys = [];
foreach ($plans as $p) foreach (parse_market_keys($p['tracked_markets'] ?? '') as $k) $allKeys[$k] = true;
$quotes = get_live_quotes($pdo, array_keys($allKeys));
$unlinked = array_filter($plans, fn($p) => !parse_market_keys($p['tracked_markets'] ?? ''));

// Form state
$selKeys = $editing ? parse_market_keys($editing['tracked_markets'] ?? '') : [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errors) {
    $selKeys = parse_market_keys(implode(',', (array)($_POST['markets'] ?? [])));
}
$customKeys = array_values(array_filter($selKeys, fn($k) => strpos($k, 'Y:') === 0));
$grouped = [];
foreach (live_market_catalog() as $k => $m) $grouped[$m['group']][$k] = $m;
$curExposure = $editing ? rtrim(rtrim(number_format((float)$editing['exposure'], 2, '.', ''), '0'), '.') : '1';

$pageTitle = 'Manage Investment Plans';
$navContext = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<link rel="stylesheet" href="/assets/css/plans.css?v=3">
<div class="container" style="max-width:1240px;">
  <?php if ($msg = flash_get('success')): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
  <h1>Investment plans</h1>
  <p class="subtitle">Each plan tracks a basket of real markets. Plan values move by the live price change of that basket multiplied by the plan's exposure. There are no random numbers and no manual results.</p>

  <?php if ($unlinked): ?>
    <div class="mk-warn" style="margin-bottom:18px;">
      <strong><?= count($unlinked) ?> plan<?= count($unlinked) === 1 ? ' is' : 's are' ?> not linked to any market:</strong>
      <?= e(implode(', ', array_column($unlinked, 'name'))) ?>.
      These plans are hidden from clients and their values are frozen until you click <em>Edit</em> and pick the markets to track.
    </div>
  <?php endif; ?>

  <div class="grid cols-2" style="grid-template-columns:minmax(0,1.05fr) minmax(0,1fr);align-items:start;">
    <div class="card">
      <h3><?= $editing ? 'Edit ' . e($editing['name']) : 'Create a new plan' ?></h3>
      <?php foreach ($errors as $err): ?><div class="alert error"><?= e($err) ?></div><?php endforeach; ?>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">

        <div class="grid cols-2">
          <div class="form-group">
            <label>Name</label>
            <input type="text" name="name" value="<?= e($_POST['name'] ?? $editing['name'] ?? '') ?>" required>
          </div>
          <div class="form-group">
            <label>Risk level</label>
            <select name="risk_level">
              <?php foreach (['low','medium','high'] as $opt): ?>
                <option value="<?= $opt ?>" <?= ($editing['risk_level'] ?? 'medium') === $opt ? 'selected' : '' ?>><?= ucfirst($opt) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="form-group">
          <label>Description</label>
          <textarea name="description" rows="2"><?= e($_POST['description'] ?? $editing['description'] ?? '') ?></textarea>
        </div>

        <div class="form-group">
          <label style="margin-bottom:10px;display:block;">Markets this plan tracks <span class="subtitle">(equal weight)</span></label>
          <div class="mk-groups">
            <?php foreach ($grouped as $g => $items): ?>
              <div>
                <div class="mk-group-title"><?= e($g) ?></div>
                <div class="mk-opts">
                  <?php foreach ($items as $k => $m): ?>
                    <label class="mk-opt">
                      <input type="checkbox" name="markets[]" value="<?= e($k) ?>" <?= in_array($k, $selKeys, true) ? 'checked' : '' ?>>
                      <span><?= asset_icon_market($k, 18) ?><?= e($m['name']) ?> <small><?= e($m['short']) ?></small></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="form-group">
          <label>Other Yahoo Finance symbols <span class="subtitle">(optional, comma-separated, e.g. NFLX, ^N225, DANGCEM.LG)</span></label>
          <input type="text" name="custom_markets" value="<?= e(implode(', ', array_map(fn($k) => substr($k, 2), $customKeys))) ?>">
        </div>

        <div class="grid cols-2">
          <div class="form-group">
            <label>Exposure</label>
            <select name="exposure">
              <?php foreach ($exposureOptions as $v => $label): ?>
                <option value="<?= e($v) ?>" <?= $curExposure === $v ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Update interval (minutes)</label>
            <input type="number" name="tick_interval_minutes" min="5" value="<?= e((string)($editing['tick_interval_minutes'] ?? 15)) ?>" required>
          </div>
        </div>
        <div class="grid cols-2">
          <div class="form-group">
            <label>Minimum investment</label>
            <input type="number" step="0.01" name="min_investment" value="<?= e((string)($editing['min_investment'] ?? 100)) ?>" required>
          </div>
          <div class="form-group">
            <label>Photo <?= $editing ? '(upload to replace)' : '(optional)' ?></label>
            <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp">
          </div>
        </div>

        <button class="btn gold block" type="submit"><?= $editing ? 'Update plan' : 'Create plan' ?></button>
        <?php if ($editing): ?><p style="margin-top:10px;"><a href="/admin/plans.php">Cancel edit</a></p><?php endif; ?>
      </form>
    </div>

    <div style="display:flex;flex-direction:column;gap:16px;">
      <?php if (!$plans): ?><div class="card"><p class="subtitle">No plans yet.</p></div><?php endif; ?>
      <?php foreach ($plans as $p): $b = plan_basket_summary($p, $quotes); $risk = in_array($p['risk_level'], ['low','medium','high'], true) ? $p['risk_level'] : 'medium'; ?>
        <div class="lp-card risk-<?= e($risk) ?>" style="padding:18px 18px 16px;">
          <div style="display:flex;align-items:center;gap:12px;">
            <?php if (!empty($p['photo_url'])): ?><img class="lp-avatar" style="width:42px;height:42px;" src="<?= e($p['photo_url']) ?>" alt="">
            <?php else: ?><div class="lp-avatar fallback" style="width:42px;height:42px;"><?= e(strtoupper(substr($p['name'], 0, 1))) ?></div><?php endif; ?>
            <div style="flex:1;min-width:0;">
              <div style="font:700 16px var(--font-display);"><?= e($p['name']) ?></div>
              <div class="lp-pills" style="margin-top:4px;">
                <span class="badge <?= $p['status'] === 'active' ? 'green' : 'gray' ?>"><?= e(ucfirst($p['status'])) ?></span>
                <?php if ($b['linked']): ?><span class="lp-pill"><?= rtrim(rtrim(number_format($b['exposure'], 2), '0'), '.') ?>x</span><span class="lp-pill"><?= (int)$p['tick_interval_minutes'] ?>m</span>
                <?php else: ?><span class="badge red">Not linked</span><?php endif; ?>
              </div>
            </div>
            <div style="text-align:right;">
              <div class="lp-chg <?= $b['change_24h'] === null ? 'flat' : ($b['change_24h'] >= 0 ? 'up' : 'down') ?>"><?= $b['change_24h'] === null ? '—' : fmt_pct($b['change_24h']) ?></div>
              <div style="font-size:10.5px;color:var(--muted);margin-top:4px;">24h basket</div>
            </div>
          </div>
          <?php if ($b['keys']): ?>
            <div class="lp-chiprow" style="margin:12px 0 0;">
              <?php foreach ($b['items'] as $it): $q = $it['quote']; ?>
                <span class="lp-chip" title="<?= e($it['meta']['name']) ?>"><?= asset_icon_market($it['key'], 16) ?><?= e($it['meta']['short']) ?>
                  <?php if ($q): ?><?= fmt_market_price($q['price'], $q['decimals']) ?><?php else: ?><span class="neg">no price</span><?php endif; ?>
                </span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-top:14px;flex-wrap:wrap;">
            <div style="font:500 12px var(--font-mono);color:var(--muted);">
              <?= (int)$p['active_subs'] ?> active &middot; <?= fmt_money((float)$p['active_value']) ?> &middot; min <?= fmt_money($p['min_investment']) ?>
            </div>
            <div style="white-space:nowrap;">
              <a class="btn small secondary" href="/admin/plans.php?edit=<?= (int)$p['id'] ?>">Edit</a>
              <form method="post" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="btn small secondary" type="submit"><?= $p['status'] === 'active' ? 'Pause' : 'Activate' ?></button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="card" style="padding:18px;">
        <h3 style="font-size:15px;margin-bottom:8px;">Price feed status</h3>
        <?php $ok = count(array_filter($quotes)); $tot = count($quotes); ?>
        <p class="subtitle" style="margin:0;">
          <?php if (!$tot): ?>No markets in use yet.
          <?php else: ?><span class="lp-live <?= $ok === $tot ? '' : 'off' ?>"><i></i></span>
            <?= $ok ?> of <?= $tot ?> markets have a live price. Sources: Binance, Yahoo Finance and Stooq, refreshed at most once a minute.
            <?php if ($ok < $tot): ?><br>Markets without a price can't update their plans. Check that your server allows outgoing HTTPS connections, as some hosts block them.<?php endif; ?>
          <?php endif; ?>
        </p>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
