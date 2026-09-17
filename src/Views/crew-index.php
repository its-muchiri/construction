<?php
/** @var array $listings */
/** @var string|null $trade */
/** @var string|null $dbError */
use Construction\Core\View;

$trades = ['mason', 'electrician', 'plumber', 'carpenter', 'painter', 'general_labor'];
?>
<h1>Browse crews</h1>

<div style="display:flex; flex-wrap:wrap; gap: var(--ac-space-2); margin: var(--ac-space-4) 0;">
  <a href="/crew" class="btn <?= !$trade ? 'btn--primary' : 'btn--secondary' ?>">All</a>
  <?php foreach ($trades as $t): ?>
    <a href="/crew?trade=<?= urlencode($t) ?>" class="btn <?= $trade === $t ? 'btn--primary' : 'btn--secondary' ?>"><?= View::e(ucfirst(str_replace('_', ' ', $t))) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($dbError): ?>
  <p class="card__meta"><?= View::e($dbError) ?></p>
<?php elseif (empty($listings)): ?>
  <p class="card__meta">No crews match this filter yet in this environment.</p>
<?php else: ?>
  <div style="display:flex; flex-wrap:wrap; gap: var(--ac-space-4);">
    <?php foreach ($listings as $item): ?>
      <div class="card" style="width: 16rem;">
        <h3><?= View::e(ucfirst($item['trade'])) ?></h3>
        <div class="card__meta">
          Team of <?= (int) $item['team_size'] ?> · KES <?= number_format((float) $item['day_rate']) ?>/day
        </div>
        <a href="/providers/<?= (int) $item['provider_id'] ?>" class="btn btn--secondary" style="margin-top: var(--ac-space-3);">View provider</a>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
