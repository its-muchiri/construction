<?php
/** @var array $listings */
/** @var string|null $category */
/** @var string|null $dbError */
use Construction\Core\View;

$categories = ['excavator', 'crane', 'bulldozer', 'concrete_mixer', 'generator', 'scaffolding', 'other'];
?>
<h1>Browse equipment</h1>

<div style="display:flex; flex-wrap:wrap; gap: var(--ac-space-2); margin: var(--ac-space-4) 0;">
  <a href="/equipment" class="btn <?= !$category ? 'btn--primary' : 'btn--secondary' ?>">All</a>
  <?php foreach ($categories as $c): ?>
    <a href="/equipment?category=<?= urlencode($c) ?>" class="btn <?= $category === $c ? 'btn--primary' : 'btn--secondary' ?>"><?= View::e(ucfirst(str_replace('_', ' ', $c))) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($dbError): ?>
  <p class="card__meta"><?= View::e($dbError) ?></p>
<?php elseif (empty($listings)): ?>
  <p class="card__meta">No equipment matches this filter yet in this environment.</p>
<?php else: ?>
  <div style="display:flex; flex-wrap:wrap; gap: var(--ac-space-4);">
    <?php foreach ($listings as $item): ?>
      <div class="card" style="width: 16rem;">
        <h3><?= View::e(ucfirst(str_replace('_', ' ', $item['equipment_category']))) ?></h3>
        <div class="card__meta">
          <?= View::e($item['make_model'] ?? 'Unspecified model') ?> · KES <?= number_format((float) $item['daily_rate']) ?>/day
        </div>
        <a href="/providers/<?= (int) $item['provider_id'] ?>" class="btn btn--secondary" style="margin-top: var(--ac-space-3);">View provider</a>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
