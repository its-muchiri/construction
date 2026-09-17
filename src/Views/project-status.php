<?php
/** @var array|null $booking */
/** @var int $bookingId */
/** @var array $quotes */
/** @var array $milestones */
/** @var string|null $dbError */
use Construction\Core\View;
?>
<h1>Project #<?= $bookingId ?></h1>

<?php if ($dbError): ?>
  <p class="card__meta"><?= View::e($dbError) ?></p>
<?php elseif (!$booking): ?>
  <p class="card__meta">No project found with this ID.</p>
<?php else: ?>
  <p class="card__meta">
    <?= View::e(ucfirst(str_replace('_', ' ', $booking['booking_type']))) ?> · <?= View::e(ucfirst($booking['category'])) ?> · <?= View::e($booking['location_address']) ?>
  </p>
  <?php if ((float) $booking['total_contract_value'] > 0): ?>
    <p>Contract value: KES <?= number_format((float) $booking['total_contract_value']) ?> · Retention: <?= View::e($booking['retention_percentage']) ?>%</p>
  <?php endif; ?>

  <div id="status-badge-container" style="margin-top: var(--ac-space-2);"></div>
  <div id="status-timeline-container" style="max-width: 56rem; margin-top: var(--ac-space-4);"></div>

  <h2 style="margin-top: var(--ac-space-8);">Quotes</h2>
  <?php if (empty($quotes)): ?>
    <p class="card__meta">No quotes submitted yet.</p>
  <?php else: ?>
    <div style="display:flex; flex-wrap:wrap; gap: var(--ac-space-4);">
      <?php foreach ($quotes as $quote): ?>
        <div class="card" style="width: 16rem;">
          <div class="card__meta">KES <?= number_format((float) $quote['quoted_amount']) ?> · <?= View::e(ucfirst($quote['status'])) ?></div>
          <p class="card__meta">Proposed start: <?= View::e($quote['proposed_start_date']) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h2 style="margin-top: var(--ac-space-8);">Milestones</h2>
  <?php if (empty($milestones)): ?>
    <p class="card__meta">No milestones defined yet — created once a quote is accepted (see src/Controllers/ProjectController.php's TODO on milestone creation).</p>
  <?php else: ?>
    <ol style="padding-left: var(--ac-space-4);">
      <?php foreach ($milestones as $milestone): ?>
        <li>
          <?= View::e($milestone['description']) ?> — KES <?= number_format((float) $milestone['amount']) ?>
          (<?= View::e($milestone['percentage_of_total']) ?>%) · <?= View::e(ucfirst(str_replace('_', ' ', $milestone['status']))) ?>
        </li>
      <?php endforeach; ?>
    </ol>
  <?php endif; ?>

  <script type="module">
    import { createStatusTimeline, CONSTRUCTION_PROJECT_STEPS } from "/assets/js/components/status-timeline.js";
    import { createStatusBadge } from "/assets/js/components/status-badge.js";

    const status = <?= json_encode($booking['status']) ?>;
    const STEP_INDEX = {
      open_for_quotes: 0, quote_accepted: 1, mobilized: 2, in_progress: 3,
      awaiting_final_signoff: 4, inspection_window: 5, completed: 6,
    };
    const BADGE_TONE = {
      open_for_quotes: "neutral", quote_accepted: "accent", in_progress: "warning",
      completed: "success", cancelled: "danger", disputed: "danger",
    };

    document.getElementById("status-badge-container").appendChild(
      createStatusBadge({ label: status.replace(/_/g, " "), tone: BADGE_TONE[status] ?? "accent" })
    );

    if (STEP_INDEX[status] !== undefined) {
      document.getElementById("status-timeline-container").appendChild(
        createStatusTimeline({ steps: CONSTRUCTION_PROJECT_STEPS, currentIndex: STEP_INDEX[status] })
      );
    }
  </script>
<?php endif; ?>
