<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
$pdo = get_db();
$errors = [];

$stmt = $pdo->prepare('SELECT * FROM kyc_submissions WHERE user_id = ? ORDER BY created_at DESC LIMIT 1');
$stmt->execute([$user['id']]);
$latest = $stmt->fetch();

$canSubmit = $user['kyc_status'] === 'none' || $user['kyc_status'] === 'rejected';

$capitalRanges = [
    'under_20k'   => 'Less than $20,000',
    '20k_30k'     => '$20,000 - $30,000',
    '30k_50k'     => '$30,000 - $50,000',
    '50k_100k'    => '$50,000 - $100,000',
    '100k_500k'   => '$100,000 - $500,000',
    '500k_1m'     => '$500,000 - $1,000,000',
    'over_1m'     => '$1,000,000+',
];
$netWorthRanges = [
    'under_100k'  => 'Less than $100,000',
    '100k_250k'   => '$100,000 - $250,000',
    '250k_500k'   => '$250,000 - $500,000',
    '500k_1m'     => '$500,000 - $1,000,000',
    '1m_5m'       => '$1,000,000 - $5,000,000',
    'over_5m'     => '$5,000,000+',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canSubmit) {
    if (!csrf_verify()) {
        $errors[] = 'Session expired, please try again.';
    } else {
        // Step 1: Verification
        $fullLegalName = trim($_POST['full_legal_name'] ?? '');
        $idType = $_POST['id_type'] ?? '';
        $idNumber = trim($_POST['id_number'] ?? '');

        if ($fullLegalName === '' || $idNumber === '' || !in_array($idType, ['passport', 'national_id', 'drivers_license'], true)) {
            $errors[] = 'Please complete all verification fields.';
        }

        $documentPath = null;
        if (empty($_FILES['document']['name']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Please upload a photo or scan of your ID document.';
        } else {
            $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'];
            $ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $_FILES['document']['tmp_name']);
            finfo_close($finfo);

            if (!isset($allowed[$ext]) || $allowed[$ext] !== $mime) {
                $errors[] = 'Document must be a JPG, PNG, or PDF file.';
            } elseif ($_FILES['document']['size'] > 5 * 1024 * 1024) {
                $errors[] = 'Document must be under 5MB.';
            } else {
                $newName = 'kyc_' . $user['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                $destDir = __DIR__ . '/../assets/uploads/kyc/';
                if (move_uploaded_file($_FILES['document']['tmp_name'], $destDir . $newName)) {
                    $documentPath = $newName;
                } else {
                    $errors[] = 'Upload failed. Please try again.';
                }
            }
        }

        // Step 2: Next of kin
        $kinName = trim($_POST['kin_name'] ?? '');
        $kinPhone = trim($_POST['kin_phone'] ?? '');
        $kinAddress = trim($_POST['kin_address'] ?? '');
        if ($kinName === '' || $kinPhone === '' || $kinAddress === '') {
            $errors[] = 'Please complete all next of kin fields.';
        }

        // Step 3: Trading profile
        $capitalRange = $_POST['capital_range'] ?? '';
        $netWorthRange = $_POST['net_worth_range'] ?? '';
        if (!isset($capitalRanges[$capitalRange])) {
            $errors[] = 'Please select your available trading capital.';
        }
        if (!isset($netWorthRanges[$netWorthRange])) {
            $errors[] = 'Please select your total asset base / net worth.';
        }

        if (!$errors) {
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    'INSERT INTO kyc_submissions
                        (user_id, full_legal_name, id_type, id_number, document_path,
                         next_of_kin_name, next_of_kin_phone, next_of_kin_address,
                         capital_range, net_worth_range, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")'
                )->execute([
                    $user['id'], $fullLegalName, $idType, $idNumber, $documentPath,
                    $kinName, $kinPhone, $kinAddress, $capitalRange, $netWorthRange,
                ]);
                $pdo->prepare("UPDATE users SET kyc_status = 'pending' WHERE id = ?")->execute([$user['id']]);
                $pdo->commit();
                flash_set('success', 'Application submitted. This is reviewed manually - you\'ll see a status update here once it\'s checked.');
                redirect('/user/kyc.php');
            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Something went wrong. Please try again.';
            }
        }
    }
}

$pageTitle = 'Account Setup';
$navContext = 'user';
require __DIR__ . '/../includes/header.php';
?>
<style>
/* Self-contained styling for the onboarding wizard - does not depend on
   the shared stylesheet being redeployed, so this page always renders
   correctly on its own. Premium glass / gradient / glow treatment. */

.kw-wrap { max-width: 640px; margin: 0 auto; position: relative; }

.kw-glow {
  position: absolute;
  border-radius: 50%;
  filter: blur(70px);
  pointer-events: none;
  z-index: 0;
  opacity: 0.55;
}
.kw-glow-1 { width: 280px; height: 280px; background: radial-gradient(circle, rgba(76,124,240,0.55), transparent 70%); top: -90px; left: -60px; animation: kwFloat1 9s ease-in-out infinite; }
.kw-glow-2 { width: 260px; height: 260px; background: radial-gradient(circle, rgba(212,175,106,0.4), transparent 70%); bottom: -80px; right: -50px; animation: kwFloat2 11s ease-in-out infinite; }
.kw-glow-3 { width: 200px; height: 200px; background: radial-gradient(circle, rgba(126,90,224,0.4), transparent 70%); top: 40%; right: -70px; animation: kwFloat1 13s ease-in-out infinite reverse; }
@keyframes kwFloat1 { 0%,100% { transform: translate(0,0); } 50% { transform: translate(18px,-14px); } }
@keyframes kwFloat2 { 0%,100% { transform: translate(0,0); } 50% { transform: translate(-16px,16px); } }

.kw-card {
  position: relative;
  z-index: 1;
  background: linear-gradient(180deg, rgba(24,36,60,0.62) 0%, rgba(13,20,36,0.72) 100%);
  backdrop-filter: blur(22px) saturate(160%);
  -webkit-backdrop-filter: blur(22px) saturate(160%);
  border: 1px solid rgba(255,255,255,0.09);
  border-radius: 22px;
  padding: 34px 32px 30px;
  box-shadow: 0 25px 60px rgba(0,0,0,0.45), 0 0 0 1px rgba(76,124,240,0.06) inset, 0 1px 0 rgba(255,255,255,0.07) inset;
  overflow: hidden;
}
.kw-card::before {
  content: '';
  position: absolute; top: 0; left: 0; right: 0; height: 1px;
  background: linear-gradient(90deg, transparent, rgba(140,170,255,0.7), rgba(212,175,106,0.6), transparent);
}
@media (max-width: 600px) { .kw-card { padding: 24px 18px 24px; border-radius: 16px; } }

.kw-pill {
  display: inline-block;
  background: linear-gradient(90deg, rgba(76,124,240,0.22), rgba(126,90,224,0.22));
  border: 1px solid rgba(120,150,255,0.3);
  color: #a9c0ff;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.09em;
  padding: 5px 13px;
  border-radius: 999px;
}
.kw-title {
  font-family: 'Chakra Petch','Segoe UI',sans-serif; font-size: 27px; font-weight: 700; margin: 15px 0 8px;
  background: linear-gradient(90deg, #eef2ff 20%, #cddcff 55%, #e4cd9a 100%);
  -webkit-background-clip: text; background-clip: text; color: transparent;
}
.kw-subtitle { color: #93a0bd; font-size: 14.5px; line-height: 1.6; margin-bottom: 28px; position: relative; z-index: 1; }

/* progress bar */
.kw-progress-track { height: 6px; background: rgba(255,255,255,0.06); border-radius: 999px; overflow: hidden; margin-bottom: 24px; position: relative; z-index: 1; }
.kw-progress-fill {
  height: 100%; width: 33.33%; border-radius: 999px;
  background: linear-gradient(90deg,#4c7cf0,#7e5ae0 55%,#d4af6a);
  background-size: 200% 100%;
  box-shadow: 0 0 14px rgba(76,124,240,0.65);
  transition: width 0.5s cubic-bezier(.65,.05,.36,1);
  animation: kwShimmer 3s linear infinite;
}
@keyframes kwShimmer { 0% { background-position: 0% 0; } 100% { background-position: -200% 0; } }

/* step indicator row */
.kw-steps { display: flex; align-items: flex-start; gap: 4px; margin-bottom: 30px; position: relative; z-index: 1; }
.kw-step { flex: 1; display: flex; flex-direction: column; align-items: center; gap: 8px; position: relative; }
.kw-step-circle {
  width: 36px; height: 36px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: rgba(255,255,255,0.04); border: 1.5px solid rgba(255,255,255,0.14); color: #8592ad;
  font-size: 13px; font-weight: 700; font-family: 'JetBrains Mono',monospace;
  transition: all 0.35s ease;
  z-index: 1;
}
.kw-step-label { font-size: 11.5px; font-weight: 600; color: #8592ad; letter-spacing: 0.02em; text-align: center; transition: color 0.3s ease; }
.kw-step.active .kw-step-circle {
  background: linear-gradient(135deg,#4c7cf0,#7e5ae0); border-color: rgba(160,180,255,0.6); color: #fff;
  box-shadow: 0 0 0 6px rgba(76,124,240,0.16), 0 0 22px rgba(76,124,240,0.55);
  transform: scale(1.1);
  animation: kwPulse 2.1s ease-in-out infinite;
}
@keyframes kwPulse {
  0%, 100% { box-shadow: 0 0 0 6px rgba(76,124,240,0.16), 0 0 18px rgba(76,124,240,0.5); }
  50% { box-shadow: 0 0 0 9px rgba(76,124,240,0.1), 0 0 28px rgba(76,124,240,0.75); }
}
.kw-step.active .kw-step-label { color: #e8edf7; }
.kw-step.done .kw-step-circle { background: linear-gradient(135deg,#1c4433,#155039); border-color: #34d399; color: #34d399; box-shadow: 0 0 14px rgba(52,211,153,0.35); }
.kw-step.done .kw-step-label { color: #34d399; }
.kw-step-connector {
  position: absolute; top: 18px; left: 50%; width: 100%; height: 2px;
  background: rgba(255,255,255,0.1); z-index: 0;
}
.kw-step:last-child .kw-step-connector { display: none; }
.kw-step.done .kw-step-connector { background: linear-gradient(90deg,#34d399,#34d399); box-shadow: 0 0 8px rgba(52,211,153,0.5); }

/* panels */
.kw-panel { display: none; position: relative; z-index: 1; }
.kw-panel.active { display: block; animation: kwSlideIn 0.4s ease; }
@keyframes kwSlideIn { from { opacity: 0; transform: translateX(16px); } to { opacity: 1; transform: translateX(0); } }
.kw-panel h3 { font-family: 'Chakra Petch','Segoe UI',sans-serif; font-size: 19px; color: #eef2ff; margin: 0 0 4px; display: flex; align-items: center; gap: 9px; }
.kw-panel .kw-panel-sub { color: #93a0bd; font-size: 13.5px; margin-bottom: 22px; }

.kw-field { margin-bottom: 18px; }
.kw-field label { display: block; font-size: 12.5px; font-weight: 600; color: #c2cbe2; margin-bottom: 7px; }
.kw-field input[type=text], .kw-field select, .kw-field textarea {
  width: 100%; background: rgba(8,13,24,0.65); border: 1.5px solid rgba(255,255,255,0.1); border-radius: 11px;
  color: #eef2ff; padding: 13px 15px; font-size: 14.5px; font-family: inherit;
  transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.kw-field input:focus, .kw-field select:focus, .kw-field textarea:focus {
  outline: none; border-color: #7e9dfa; box-shadow: 0 0 0 4px rgba(76,124,240,0.22), 0 0 16px rgba(76,124,240,0.25);
}
.kw-field input::placeholder, .kw-field textarea::placeholder { color: #56628a99; }

.kw-file {
  border: 1.5px dashed rgba(126,157,250,0.4); border-radius: 14px; padding: 24px 16px;
  text-align: center; cursor: pointer; transition: border-color 0.2s ease, background 0.2s ease, box-shadow 0.2s ease;
  position: relative; background: rgba(76,124,240,0.05);
}
.kw-file:hover { border-color: #7e9dfa; background: rgba(76,124,240,0.1); box-shadow: 0 0 20px rgba(76,124,240,0.18); }
.kw-file input[type=file] { position: absolute; inset: 0; opacity: 0; cursor: pointer; }
.kw-file-icon { font-size: 23px; margin-bottom: 6px; filter: drop-shadow(0 0 8px rgba(76,124,240,0.5)); }
.kw-file-text { font-size: 13.5px; color: #c2cbe2; font-weight: 600; }
.kw-file-hint { font-size: 11.5px; color: #8592ad; margin-top: 3px; }
.kw-file-name { font-size: 12.5px; color: #34d399; font-weight: 600; margin-top: 8px; display: none; }

.kw-radio-grid { display: flex; flex-direction: column; gap: 9px; }
.kw-radio {
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  border: 1.5px solid rgba(255,255,255,0.1); border-radius: 12px; padding: 14px 16px;
  cursor: pointer; transition: all 0.18s ease; background: rgba(255,255,255,0.02);
}
.kw-radio:hover { border-color: rgba(126,157,250,0.4); background: rgba(76,124,240,0.06); }
.kw-radio input { display: none; }
.kw-radio-text { font-size: 14px; color: #c2cbe2; font-weight: 600; }
.kw-radio-check {
  width: 19px; height: 19px; border-radius: 50%; border: 2px solid rgba(255,255,255,0.2);
  flex-shrink: 0; position: relative; transition: all 0.18s ease;
}
.kw-radio.selected { border-color: #7e9dfa; background: rgba(76,124,240,0.14); box-shadow: 0 0 0 3px rgba(76,124,240,0.16), 0 0 18px rgba(76,124,240,0.25); }
.kw-radio.selected .kw-radio-text { color: #eef2ff; }
.kw-radio.selected .kw-radio-check { border-color: transparent; background: linear-gradient(135deg,#4c7cf0,#7e5ae0); box-shadow: 0 0 10px rgba(76,124,240,0.6); }
.kw-radio.selected .kw-radio-check::after {
  content: ''; position: absolute; left: 6px; top: 2px; width: 5px; height: 9px;
  border: solid #fff; border-width: 0 2px 2px 0; transform: rotate(45deg);
}

.kw-actions { display: flex; justify-content: space-between; gap: 12px; margin-top: 26px; padding-top: 22px; border-top: 1px solid rgba(255,255,255,0.08); position: relative; z-index: 1; }
.kw-btn {
  border: none; border-radius: 11px; padding: 13px 22px; font-size: 14.5px; font-weight: 700;
  cursor: pointer; transition: transform 0.15s ease, box-shadow 0.15s ease, opacity 0.15s ease;
  font-family: inherit; min-width: 130px;
}
.kw-btn:active { transform: scale(0.97); }
.kw-btn-primary { background: linear-gradient(90deg,#4c7cf0,#7e5ae0 65%,#9a6bea); color: #fff; box-shadow: 0 10px 26px rgba(76,124,240,0.4), 0 0 20px rgba(126,90,224,0.25); }
.kw-btn-primary:hover { box-shadow: 0 12px 34px rgba(76,124,240,0.55), 0 0 28px rgba(126,90,224,0.4); transform: translateY(-1px); }
.kw-btn-primary:disabled { opacity: 0.45; cursor: not-allowed; box-shadow: none; }
.kw-btn-ghost { background: rgba(255,255,255,0.03); border: 1.5px solid rgba(255,255,255,0.14); color: #c2cbe2; }
.kw-btn-ghost:hover { background: rgba(255,255,255,0.07); color: #eef2ff; border-color: rgba(255,255,255,0.25); }
</style>

<div class="container narrow kw-wrap">
  <div class="kw-glow kw-glow-1"></div>
  <div class="kw-glow kw-glow-2"></div>
  <div class="kw-glow kw-glow-3"></div>

  <?php if ($msg = flash_get('success')): ?><div class="alert success"><?= e($msg) ?></div><?php endif; ?>
  <?php if ($msg = flash_get('error')): ?><div class="alert error"><?= e($msg) ?></div><?php endif; ?>

  <div class="kw-card">
    <span class="kw-pill">ACCOUNT SETUP</span>
    <h2 class="kw-title">Complete your onboarding</h2>
    <p class="kw-subtitle">A few details so we can verify your Nexora account and unlock full trading access. Reviewed manually by our team, not on-chain or automated.</p>

    <?php if ($user['kyc_status'] === 'approved'): ?>
      <div class="alert success">Your identity is verified. Full trading access is unlocked.</div>
    <?php elseif ($user['kyc_status'] === 'pending'): ?>
      <div class="alert info">Your application is pending review. This can take a little time — check back here for updates.</div>
    <?php elseif ($user['kyc_status'] === 'rejected'): ?>
      <div class="alert error">
        Your last application was rejected<?= $latest && $latest['admin_note'] ? ': ' . e($latest['admin_note']) : '.' ?>
        Please correct the issue and resubmit below.
      </div>
    <?php endif; ?>

    <?php foreach ($errors as $err): ?><div class="alert error"><?= e($err) ?></div><?php endforeach; ?>

    <?php if ($canSubmit): ?>
      <div class="kw-progress-track"><div class="kw-progress-fill" id="kwProgressFill"></div></div>

      <div class="kw-steps" id="kwSteps">
        <div class="kw-step active" data-step-pill="1">
          <div class="kw-step-connector"></div>
          <div class="kw-step-circle">1</div>
          <div class="kw-step-label">Verification</div>
        </div>
        <div class="kw-step" data-step-pill="2">
          <div class="kw-step-connector"></div>
          <div class="kw-step-circle">2</div>
          <div class="kw-step-label">Next of kin</div>
        </div>
        <div class="kw-step" data-step-pill="3">
          <div class="kw-step-circle">3</div>
          <div class="kw-step-label">Trading info</div>
        </div>
      </div>

      <form method="post" enctype="multipart/form-data" id="kycForm" novalidate>
        <?= csrf_field() ?>

        <div class="kw-panel active" data-step="1">
          <h3>🪪 Verification</h3>
          <p class="kw-panel-sub">Your legal identity, matched against a valid ID document.</p>

          <div class="kw-field">
            <label>Full legal name (as on your ID)</label>
            <input type="text" name="full_legal_name" placeholder="e.g. Jane A. Doe" value="<?= e($_POST['full_legal_name'] ?? '') ?>" required>
          </div>
          <div class="kw-field">
            <label>ID type</label>
            <select name="id_type">
              <option value="passport">Passport</option>
              <option value="national_id">National ID card</option>
              <option value="drivers_license">Driver's license</option>
            </select>
          </div>
          <div class="kw-field">
            <label>ID number</label>
            <input type="text" name="id_number" placeholder="Document number" value="<?= e($_POST['id_number'] ?? '') ?>" required>
          </div>
          <div class="kw-field">
            <label>Upload ID document</label>
            <label class="kw-file" id="kwFileDrop">
              <input type="file" name="document" id="kwFileInput" accept=".jpg,.jpeg,.png,.pdf" required>
              <div class="kw-file-icon">📎</div>
              <div class="kw-file-text">Click to upload, or drag a file here</div>
              <div class="kw-file-hint">JPG, PNG, or PDF — max 5MB</div>
              <div class="kw-file-name" id="kwFileName"></div>
            </label>
          </div>

          <div class="kw-actions">
            <span></span>
            <button type="button" class="kw-btn kw-btn-primary" data-next>Continue →</button>
          </div>
        </div>

        <div class="kw-panel" data-step="2">
          <h3>👤 Next of kin</h3>
          <p class="kw-panel-sub">Emergency contact we can reach if needed.</p>

          <div class="kw-field">
            <label>Full name</label>
            <input type="text" name="kin_name" placeholder="Full name" value="<?= e($_POST['kin_name'] ?? '') ?>">
          </div>
          <div class="kw-field">
            <label>Phone number</label>
            <input type="text" name="kin_phone" placeholder="Phone number" value="<?= e($_POST['kin_phone'] ?? '') ?>">
          </div>
          <div class="kw-field">
            <label>Address</label>
            <textarea name="kin_address" rows="3" placeholder="Residential address"><?= e($_POST['kin_address'] ?? '') ?></textarea>
          </div>

          <div class="kw-actions">
            <button type="button" class="kw-btn kw-btn-ghost" data-back>← Back</button>
            <button type="button" class="kw-btn kw-btn-primary" data-next>Continue →</button>
          </div>
        </div>

        <div class="kw-panel" data-step="3">
          <h3>📊 Trading profile</h3>
          <p class="kw-panel-sub">Help us tailor your experience. Answers stay confidential.</p>

          <div class="kw-field">
            <label>Capital available for trading</label>
            <div class="kw-radio-grid" data-radio-group="capital_range">
              <?php foreach ($capitalRanges as $val => $label): $checked = (($_POST['capital_range'] ?? '') === $val); ?>
                <label class="kw-radio<?= $checked ? ' selected' : '' ?>">
                  <span class="kw-radio-text"><?= e($label) ?></span>
                  <input type="radio" name="capital_range" value="<?= e($val) ?>" <?= $checked ? 'checked' : '' ?>>
                  <span class="kw-radio-check"></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="kw-field">
            <label>Total asset base / net worth</label>
            <div class="kw-radio-grid" data-radio-group="net_worth_range">
              <?php foreach ($netWorthRanges as $val => $label): $checked = (($_POST['net_worth_range'] ?? '') === $val); ?>
                <label class="kw-radio<?= $checked ? ' selected' : '' ?>">
                  <span class="kw-radio-text"><?= e($label) ?></span>
                  <input type="radio" name="net_worth_range" value="<?= e($val) ?>" <?= $checked ? 'checked' : '' ?>>
                  <span class="kw-radio-check"></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="kw-actions">
            <button type="button" class="kw-btn kw-btn-ghost" data-back>← Back</button>
            <button class="kw-btn kw-btn-primary" type="submit">Submit application</button>
          </div>
        </div>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if ($canSubmit): ?>
<script>
(function () {
  var form = document.getElementById('kycForm');
  var panels = Array.prototype.slice.call(form.querySelectorAll('[data-step]'));
  var stepPills = Array.prototype.slice.call(document.querySelectorAll('#kwSteps [data-step-pill]'));
  var progressFill = document.getElementById('kwProgressFill');
  var total = panels.length;

  function goTo(n) {
    panels.forEach(function (p) {
      p.classList.toggle('active', p.getAttribute('data-step') === String(n));
    });
    stepPills.forEach(function (pill) {
      var num = parseInt(pill.getAttribute('data-step-pill'), 10);
      pill.classList.toggle('active', num === n);
      pill.classList.toggle('done', num < n);
    });
    progressFill.style.width = (n / total * 100) + '%';
    window.scrollTo({ top: form.offsetTop - 100, behavior: 'smooth' });
  }

  function currentPanelValid(panel) {
    var required = panel.querySelectorAll('input[required], select[required], textarea[required]');
    for (var i = 0; i < required.length; i++) {
      if (!required[i].checkValidity()) { required[i].reportValidity(); return false; }
    }
    var step = panel.getAttribute('data-step');
    if (step === '2') {
      var kin = panel.querySelectorAll('input[name="kin_name"], input[name="kin_phone"], textarea[name="kin_address"]');
      for (var j = 0; j < kin.length; j++) {
        if (!kin[j].value.trim()) { kin[j].focus(); return false; }
      }
    }
    if (step === '3') {
      if (!panel.querySelector('input[name="capital_range"]:checked')) return false;
      if (!panel.querySelector('input[name="net_worth_range"]:checked')) return false;
    }
    return true;
  }

  form.addEventListener('click', function (e) {
    var next = e.target.closest('[data-next]');
    var back = e.target.closest('[data-back]');
    if (next) {
      var panel = next.closest('[data-step]');
      if (!currentPanelValid(panel)) return;
      goTo(parseInt(panel.getAttribute('data-step'), 10) + 1);
    } else if (back) {
      var cur = back.closest('[data-step]');
      goTo(parseInt(cur.getAttribute('data-step'), 10) - 1);
    }
  });

  // Radio card visual selection
  Array.prototype.slice.call(form.querySelectorAll('[data-radio-group]')).forEach(function (group) {
    group.addEventListener('click', function (e) {
      var label = e.target.closest('.kw-radio');
      if (!label) return;
      Array.prototype.slice.call(group.querySelectorAll('.kw-radio')).forEach(function (l) {
        l.classList.remove('selected');
      });
      label.classList.add('selected');
    });
  });

  // File name preview + drag styling
  var fileInput = document.getElementById('kwFileInput');
  var fileDrop = document.getElementById('kwFileDrop');
  var fileName = document.getElementById('kwFileName');
  if (fileInput) {
    fileInput.addEventListener('change', function () {
      if (fileInput.files && fileInput.files[0]) {
        fileName.textContent = '✓ ' + fileInput.files[0].name;
        fileName.style.display = 'block';
      }
    });
    ['dragover', 'dragleave', 'drop'].forEach(function (evt) {
      fileDrop.addEventListener(evt, function (e) {
        e.preventDefault();
        fileDrop.style.borderColor = evt === 'dragover' ? '#4c7cf0' : '#2d4270';
      });
    });
  }
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
