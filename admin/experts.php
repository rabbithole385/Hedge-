<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$admin = require_admin();
$pdo = get_db();
$errors = [];
$editing = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Session expired, please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $bio = trim($_POST['bio'] ?? '');
            $assetFocus = $_POST['asset_focus'] ?? 'stocks';
            $riskLevel = $_POST['risk_level'] ?? 'medium';
            if (!in_array($assetFocus, ['stocks','gold','commodities','mixed'], true)) $assetFocus = 'stocks';
            if (!in_array($riskLevel, ['low','medium','high'], true)) $riskLevel = 'medium';
            $minCopy = (float)($_POST['min_copy_amount'] ?? 50);
            $winRateManual = isset($_POST['win_rate_manual']) ? 1 : 0;
            $manualWinRate = (float)($_POST['win_rate'] ?? 0);
            $returnManual = isset($_POST['monthly_return_manual']) ? 1 : 0;
            $manualReturn = (float)($_POST['monthly_return'] ?? 0);
            $tradeCountManual = isset($_POST['trade_count_manual']) ? 1 : 0;
            $manualTradeCount = (int)($_POST['trade_count_override'] ?? 0);

            if ($name === '') {
                $errors[] = 'Name is required.';
            } else {
                // Handle optional photo upload
                $photoUrl = null;
                $upErr = $_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE;
                if ($upErr === UPLOAD_ERR_INI_SIZE || $upErr === UPLOAD_ERR_FORM_SIZE) {
                    $errors[] = 'Photo is too large for the server (max ' . ini_get('upload_max_filesize') . '). Please use a smaller image.';
                } elseif ($upErr !== UPLOAD_ERR_OK && $upErr !== UPLOAD_ERR_NO_FILE) {
                    $errors[] = 'Photo upload failed (error code ' . (int)$upErr . '). Please try again.';
                } elseif ($upErr === UPLOAD_ERR_OK && !empty($_FILES['photo']['name'])) {
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
                    } elseif (function_exists('mime_content_type')) {
                        $mime = (string)mime_content_type($tmp);
                    }
                    if ($mime === 'image/jpg' || $mime === 'image/pjpeg') $mime = 'image/jpeg';

                    if (!isset($allowed[$ext]) || ($mime !== '' && $allowed[$ext] !== $mime)) {
                        $errors[] = 'Photo must be a JPG, PNG, or WEBP image.';
                    } elseif ($_FILES['photo']['size'] > 3 * 1024 * 1024) {
                        $errors[] = 'Photo must be under 3MB.';
                    } else {
                        $newName = 'expert_' . bin2hex(random_bytes(8)) . '.' . $ext;
                        $destDir = __DIR__ . '/../assets/uploads/experts/';
                        if (!is_dir($destDir)) {
                            @mkdir($destDir, 0755, true);
                        }
                        if (is_dir($destDir) && is_writable($destDir) && @move_uploaded_file($tmp, $destDir . $newName)) {
                            $photoUrl = '/assets/uploads/experts/' . $newName;
                        } else {
                            $errors[] = 'Photo upload failed: the folder assets/uploads/experts is missing or not writable (set it to 755 in cPanel).';
                        }
                    }
                }

                if (!$errors) {
                  try {
                    if ($id > 0) {
                        if ($photoUrl) {
                            $pdo->prepare(
                                'UPDATE experts SET name=?, bio=?, asset_focus=?, risk_level=?, min_copy_amount=?, photo_url=?,
                                 win_rate_manual=?, win_rate=IF(?=1, ?, win_rate),
                                 monthly_return_manual=?, monthly_return=IF(?=1, ?, monthly_return),
                                 trade_count_manual=?, trade_count_override=? WHERE id=?'
                            )->execute([$name, $bio, $assetFocus, $riskLevel, $minCopy, $photoUrl,
                                $winRateManual, $winRateManual, $manualWinRate,
                                $returnManual, $returnManual, $manualReturn,
                                $tradeCountManual, $manualTradeCount, $id]);
                        } else {
                            $pdo->prepare(
                                'UPDATE experts SET name=?, bio=?, asset_focus=?, risk_level=?, min_copy_amount=?,
                                 win_rate_manual=?, win_rate=IF(?=1, ?, win_rate),
                                 monthly_return_manual=?, monthly_return=IF(?=1, ?, monthly_return),
                                 trade_count_manual=?, trade_count_override=? WHERE id=?'
                            )->execute([$name, $bio, $assetFocus, $riskLevel, $minCopy,
                                $winRateManual, $winRateManual, $manualWinRate,
                                $returnManual, $returnManual, $manualReturn,
                                $tradeCountManual, $manualTradeCount, $id]);
                        }
                        flash_set('success', 'Expert updated.');
                    } else {
                        $pdo->prepare(
                            'INSERT INTO experts (name, bio, asset_focus, risk_level, min_copy_amount, photo_url,
                             win_rate_manual, win_rate, monthly_return_manual, monthly_return,
                             trade_count_manual, trade_count_override, status)
                             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\')'
                        )->execute([$name, $bio, $assetFocus, $riskLevel, $minCopy, $photoUrl,
                            $winRateManual, $winRateManual ? $manualWinRate : 0,
                            $returnManual, $returnManual ? $manualReturn : 0,
                            $tradeCountManual, $tradeCountManual ? $manualTradeCount : 0]);
                        flash_set('success', 'Expert created.');
                    }
                  } catch (Throwable $ex) {
                    error_log('Expert save failed: ' . $ex->getMessage());
                    $errors[] = 'Could not save expert: ' . $ex->getMessage();
                  }
                  if (!$errors) {
                    redirect('/admin/experts.php');
                  }
                }
            }
        } elseif ($action === 'toggle_status') {
            $id = (int)($_POST['id'] ?? 0);
            $stmt = $pdo->prepare('SELECT status FROM experts WHERE id = ?');
            $stmt->execute([$id]);
            $current = $stmt->fetchColumn();
            $new = $current === 'active' ? 'paused' : 'active';
            $pdo->prepare('UPDATE experts SET status = ? WHERE id = ?')->execute([$new, $id]);
            flash_set('success', 'Expert status updated.');
            redirect('/admin/experts.php');
        }
    }
}

if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM experts WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $editing = $stmt->fetch();
}

$experts = $pdo->query('SELECT * FROM experts ORDER BY created_at DESC')->fetchAll();

$pageTitle = 'Manage Experts';
$navContext = 'admin';
require __DIR__ . '/../includes/header.php';
?>
<div class="container">
  <?php if ($msg = flash_get('success')): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
  <h1>Manage expert traders</h1>

  <div class="grid cols-2">
    <div class="card">
      <h3><?= $editing ? 'Edit ' . e($editing['name']) : 'Add new expert' ?></h3>
      <?php foreach ($errors as $err): ?><div class="alert error"><?= e($err) ?></div><?php endforeach; ?>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : 0 ?>">
        <?php if (!empty($editing['photo_url'])): ?>
          <div class="form-group">
            <label>Current photo</label>
            <img src="<?= e($editing['photo_url']) ?>" alt="" style="width:72px;height:72px;border-radius:50%;object-fit:cover;display:block;">
          </div>
        <?php endif; ?>
        <div class="form-group">
          <label>Photo <?= $editing ? '(upload to replace)' : '(optional)' ?></label>
          <input type="file" name="photo" accept=".jpg,.jpeg,.png,.webp">
        </div>
        <div class="form-group">
          <label>Name</label>
          <input type="text" name="name" value="<?= e($editing['name'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label>Bio / strategy description</label>
          <textarea name="bio" rows="3"><?= e($editing['bio'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
          <label>Asset focus</label>
          <select name="asset_focus">
            <?php foreach (['stocks','gold','commodities','mixed'] as $opt): ?>
              <option value="<?= $opt ?>" <?= ($editing['asset_focus'] ?? '') === $opt ? 'selected' : '' ?>><?= ucfirst($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Risk level</label>
          <select name="risk_level">
            <?php foreach (['low','medium','high'] as $opt): ?>
              <option value="<?= $opt ?>" <?= ($editing['risk_level'] ?? '') === $opt ? 'selected' : '' ?>><?= ucfirst($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Minimum copy amount</label>
          <input type="number" step="0.01" name="min_copy_amount" value="<?= e((string)($editing['min_copy_amount'] ?? 50)) ?>">
        </div>
        <div class="form-group" style="border-top:1px solid var(--border); padding-top:14px;">
          <label>
            <input type="checkbox" name="win_rate_manual" value="1" <?= !empty($editing['win_rate_manual']) ? 'checked' : '' ?> style="width:auto; margin-right:8px;">
            Manually set win rate (stops auto-calculating from posted trades)
          </label>
        </div>
        <div class="form-group">
          <label>Win rate % <?= !empty($editing['win_rate_manual']) ? '' : '(auto-calculated - only used if checkbox above is ticked)' ?></label>
          <input type="number" step="0.1" name="win_rate" value="<?= e((string)($editing['win_rate'] ?? 0)) ?>">
        </div>
        <div class="form-group" style="border-top:1px solid var(--border); padding-top:14px;">
          <label>
            <input type="checkbox" name="monthly_return_manual" value="1" <?= !empty($editing['monthly_return_manual']) ? 'checked' : '' ?> style="width:auto; margin-right:8px;">
            Manually set average monthly return (stops auto-calculating from posted trades)
          </label>
        </div>
        <div class="form-group">
          <label>Average monthly return % <?= !empty($editing['monthly_return_manual']) ? '' : '(auto-calculated - only used if checkbox above is ticked)' ?></label>
          <input type="number" step="0.1" name="monthly_return" value="<?= e((string)($editing['monthly_return'] ?? 0)) ?>">
        </div>
        <div class="form-group" style="border-top:1px solid var(--border); padding-top:14px;">
          <label>
            <input type="checkbox" name="trade_count_manual" value="1" <?= !empty($editing['trade_count_manual']) ? 'checked' : '' ?> style="width:auto; margin-right:8px;">
            Manually set total trade count (stops counting actual posted trades)
          </label>
        </div>
        <div class="form-group">
          <label>Total trades <?= !empty($editing['trade_count_manual']) ? '' : '(auto-counted from posted trades - only used if checkbox above is ticked)' ?></label>
          <input type="number" step="1" min="0" name="trade_count_override" value="<?= e((string)($editing['trade_count_override'] ?? 0)) ?>">
        </div>
        <button class="btn block" type="submit"><?= $editing ? 'Update expert' : 'Create expert' ?></button>
        <?php if ($editing): ?><p style="margin-top:10px;"><a href="/admin/experts.php">Cancel edit</a></p><?php endif; ?>
      </form>
    </div>

    <div class="card">
      <h3>All experts</h3>
      <table>
        <thead><tr><th>Name</th><th>Focus</th><th>Win rate</th><th>Trades</th><th>Avg return</th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($experts as $ex):
            $tradeCount = get_expert_trade_count($pdo, $ex);
        ?>
          <tr>
            <td style="display:flex; align-items:center; gap:10px;">
              <?php if (!empty($ex['photo_url'])): ?>
                <img src="<?= e($ex['photo_url']) ?>" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover;">
              <?php endif; ?>
              <?= e($ex['name']) ?>
            </td>
            <td><span class="badge blue"><?= e(ucfirst($ex['asset_focus'])) ?></span></td>
            <td><?= e($ex['win_rate']) ?>% <?= !empty($ex['win_rate_manual']) ? '<span class="badge gray">manual</span>' : '<span class="badge gray">auto</span>' ?></td>
            <td><?= $tradeCount ?> <?= !empty($ex['trade_count_manual']) ? '<span class="badge gray">manual</span>' : '<span class="badge gray">auto</span>' ?></td>
            <td class="<?= $ex['monthly_return'] >= 0 ? 'pos' : 'neg' ?>"><?= fmt_pct($ex['monthly_return']) ?> <?= !empty($ex['monthly_return_manual']) ? '<span class="badge gray">manual</span>' : '<span class="badge gray">auto</span>' ?></td>
            <td><span class="badge <?= $ex['status']==='active'?'green':'gray' ?>"><?= e(ucfirst($ex['status'])) ?></span></td>
            <td style="white-space:nowrap;">
              <a class="btn small secondary" href="/admin/experts.php?edit=<?= (int)$ex['id'] ?>">Edit</a>
              <form method="post" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="id" value="<?= (int)$ex['id'] ?>">
                <button class="btn small secondary" type="submit"><?= $ex['status']==='active' ? 'Pause' : 'Activate' ?></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>