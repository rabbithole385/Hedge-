<?php
$navContext = $navContext ?? 'guest';
$headerUser = $headerUser ?? null;
$headerAdmin = $headerAdmin ?? null;
$usedSidebarShell = ($navContext === 'user' && $headerUser) || ($navContext === 'admin' && $headerAdmin);
?>
<?php if ($usedSidebarShell): ?>
      <footer class="footer">
        &copy; <?= date('Y') ?> HedgeInv &mdash; Investment values can rise and fall. Markets, plans and bots follow live market prices; past results don't guarantee future returns.
      </footer>
    </div><!-- /.app-content -->
  </div><!-- /.app-main -->
</div><!-- /.app-shell -->
<?php else: ?>
<footer class="footer">
  &copy; <?= date('Y') ?> HedgeInv &mdash; Investment values can rise and fall. Markets, plans and bots follow live market prices; past results don't guarantee future returns.
</footer>
<?php endif; ?>

<?php if ($currentPath !== '/admin/livechat.php'): ?>
<!-- HedgeInv Real-Time Live Chat Widget -->
<link rel="stylesheet" href="/assets/css/livechat.css">
<script src="/assets/js/livechat.js" defer></script>
<?php endif; ?>

</body>
</html>