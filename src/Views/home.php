<?php
/** @var array $equipment */
/** @var array $crew */
/** @var string|null $dbError */
use Construction\Core\View;
?>
<section style="padding-block: var(--ac-space-8) var(--ac-space-12);">
  <h1 style="font-size: 2.5rem; max-width: 32rem;">Equipment rental and labor contracts, quoted and escrowed.</h1>
  <p style="max-width: var(--ac-measure); margin-block: var(--ac-space-4);">
    Post a project, compare quotes from verified providers, and fund milestones through escrow — with an inspection window before final release.
  </p>
  <a href="/projects/new" class="btn btn--primary">Post a project</a>
</section>

<section style="padding-block: var(--ac-space-8); border-block: 1px solid var(--ac-paper-deep);">
  <h2>How it works</h2>
  <div style="display:flex; flex-wrap:wrap; gap: var(--ac-space-6); margin-top: var(--ac-space-4);">
    <div style="flex: 1 1 12rem;">
      <strong>1. Post</strong>
      <p class="card__meta">Describe the equipment or crew you need and your budget range.</p>
    </div>
    <div style="flex: 1 1 12rem;">
      <strong>2. Compare quotes</strong>
      <p class="card__meta">Verified providers submit pricing, availability, and conditions.</p>
    </div>
    <div style="flex: 1 1 12rem;">
      <strong>3. Track to completion</strong>
      <p class="card__meta">Fund milestones through escrow with an inspection window before final release.</p>
    </div>
  </div>
</section>

<section style="padding-block: var(--ac-space-8);">
  <h2 id="spotlight-heading">Featured equipment</h2>
  <?php if ($dbError): ?>
    <p class="card__meta"><?= View::e($dbError) ?></p>
  <?php elseif (empty($equipment)): ?>
    <p class="card__meta">No equipment is listed yet in this environment — see src/Controllers/ListingController.php to add one.</p>
  <?php else: ?>
    <div style="display:flex; flex-wrap:wrap; gap: var(--ac-space-4); margin-top: var(--ac-space-4);">
      <?php foreach ($equipment as $i => $item): ?>
        <div class="card" style="width: 16rem; position: relative;" <?= $i === 0 ? 'id="spotlight-card"' : '' ?>>
          <h3><?= View::e(ucfirst(str_replace('_', ' ', $item['equipment_category']))) ?></h3>
          <div class="card__meta">
            <?= View::e($item['make_model'] ?? 'Unspecified model') ?> · KES <?= number_format((float) $item['daily_rate']) ?>/day
          </div>
          <a href="/providers/<?= (int) $item['provider_id'] ?>" class="btn btn--secondary" style="margin-top: var(--ac-space-3);">View provider</a>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section style="padding-block: var(--ac-space-8);">
  <h2>Featured crews</h2>
  <?php if (empty($crew) && !$dbError): ?>
    <p class="card__meta">No crews are listed yet in this environment.</p>
  <?php elseif (!$dbError): ?>
    <div style="display:flex; flex-wrap:wrap; gap: var(--ac-space-4); margin-top: var(--ac-space-4);">
      <?php foreach ($crew as $item): ?>
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
</section>

<?php if (!empty($equipment)): ?>
<script type="module">
  // Handwritten-annotation garnish on the top listing — non-critical
  // marketing content only; per this platform's README, this component
  // must never appear on the milestone-funding/project-status page.
  import { createAnnotation } from "/assets/js/components/scrap.js";

  const spotlight = document.getElementById("spotlight-card");
  if (spotlight) {
    const note = createAnnotation({ text: "Popular pick", tone: "accentOnPaper" });
    note.style.position = "absolute";
    note.style.top = "-0.75rem";
    note.style.right = "-0.5rem";
    spotlight.appendChild(note);
  }
</script>
<?php endif; ?>
