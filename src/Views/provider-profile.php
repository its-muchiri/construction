<?php
/** @var array|null $provider */
/** @var int $providerId */
/** @var string|null $dbError */
use Construction\Core\View;
?>
<?php if ($dbError): ?>
  <p class="card__meta"><?= View::e($dbError) ?></p>
<?php elseif (!$provider): ?>
  <h1>Provider #<?= $providerId ?></h1>
  <p class="card__meta">No provider found with this ID.</p>
<?php else: ?>
  <h1><?= View::e($provider['full_name']) ?></h1>

  <div style="display:flex; gap: var(--ac-space-2); margin: var(--ac-space-4) 0;">
    <button type="button" class="btn btn--primary" id="tab-equipment-btn">Equipment (<?= count($provider['equipment_listings']) ?>)</button>
    <button type="button" class="btn btn--secondary" id="tab-crew-btn">Crew (<?= count($provider['crew_listings']) ?>)</button>
  </div>

  <div id="tab-equipment">
    <?php if (empty($provider['equipment_listings'])): ?>
      <p class="card__meta">No active equipment listings.</p>
    <?php else: ?>
      <div style="display:flex; flex-wrap:wrap; gap: var(--ac-space-4);">
        <?php foreach ($provider['equipment_listings'] as $item): ?>
          <div class="card" style="width: 16rem;">
            <h3><?= View::e(ucfirst(str_replace('_', ' ', $item['equipment_category']))) ?></h3>
            <div class="card__meta"><?= View::e($item['make_model'] ?? 'Unspecified model') ?> · KES <?= number_format((float) $item['daily_rate']) ?>/day</div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div id="tab-crew" hidden>
    <?php if (empty($provider['crew_listings'])): ?>
      <p class="card__meta">No active crew listings.</p>
    <?php else: ?>
      <div style="display:flex; flex-wrap:wrap; gap: var(--ac-space-4);">
        <?php foreach ($provider['crew_listings'] as $item): ?>
          <div class="card" style="width: 16rem;">
            <h3><?= View::e(ucfirst($item['trade'])) ?></h3>
            <div class="card__meta">Team of <?= (int) $item['team_size'] ?> · KES <?= number_format((float) $item['day_rate']) ?>/day</div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <script>
    const equipmentBtn = document.getElementById("tab-equipment-btn");
    const crewBtn = document.getElementById("tab-crew-btn");
    const equipmentPanel = document.getElementById("tab-equipment");
    const crewPanel = document.getElementById("tab-crew");

    equipmentBtn.addEventListener("click", () => {
      equipmentPanel.hidden = false;
      crewPanel.hidden = true;
      equipmentBtn.className = "btn btn--primary";
      crewBtn.className = "btn btn--secondary";
    });
    crewBtn.addEventListener("click", () => {
      equipmentPanel.hidden = true;
      crewPanel.hidden = false;
      crewBtn.className = "btn btn--primary";
      equipmentBtn.className = "btn btn--secondary";
    });
  </script>
<?php endif; ?>
