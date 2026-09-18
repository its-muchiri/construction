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
    <p class="card__meta">No milestones yet — created automatically once a quote is accepted (30% mobilization / 40% progress / 30% final + retention split).</p>
  <?php else: ?>
    <div id="milestones-container" style="display:flex; flex-direction:column; gap: var(--ac-space-3);">
      <?php foreach ($milestones as $milestone): ?>
        <div class="card" data-milestone-id="<?= (int) $milestone['id'] ?>" data-milestone-status="<?= View::e($milestone['status']) ?>" data-milestone-funded="<?= $milestone['escrow_transaction_id'] ? '1' : '0' ?>">
          <div class="card__meta">
            #<?= (int) $milestone['sequence_number'] ?> <?= View::e($milestone['description']) ?> — KES <?= number_format((float) $milestone['amount']) ?>
            (<?= View::e($milestone['percentage_of_total']) ?>%) · <?= View::e(ucfirst(str_replace('_', ' ', $milestone['status']))) ?>
            <?= $milestone['escrow_transaction_id'] ? ' · funded' : ' · not funded' ?>
          </div>
          <div class="milestone-actions" style="margin-top: var(--ac-space-2); display:flex; gap: var(--ac-space-2); flex-wrap:wrap;"></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <h2 style="margin-top: var(--ac-space-8);">Equipment condition reports</h2>
  <p class="card__meta">Handover/return photo &amp; video evidence — feeds any equipment-damage dispute.</p>
  <div id="condition-reports-container" style="display:flex; flex-direction:column; gap: var(--ac-space-2); margin-top: var(--ac-space-2);"></div>
  <details style="margin-top: var(--ac-space-3);">
    <summary>Submit a condition report (provider / Site Inspector)</summary>
    <form id="condition-report-form" style="max-width: 28rem; display:flex; flex-direction:column; gap: var(--ac-space-3); margin-top: var(--ac-space-3);">
      <label>
        Equipment listing ID
        <input type="number" name="equipment_listing_id" required style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
      </label>
      <label>
        Report type
        <select name="report_type" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
          <option value="handover">Handover (before rental)</option>
          <option value="return">Return (after rental)</option>
        </select>
      </label>
      <label>
        Condition notes (pre-existing damage/wear)
        <textarea name="condition_notes" required style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);"></textarea>
      </label>
      <label>
        Photo URLs (comma-separated)
        <input type="text" name="photo_urls" placeholder="https://..." style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
      </label>
      <label>
        Meter reading (hours, optional)
        <input type="number" step="0.1" name="meter_reading_hours" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
      </label>
      <button type="submit" class="btn btn--secondary">Submit report</button>
    </form>
    <p id="condition-report-result" class="card__meta" style="margin-top: var(--ac-space-2);"></p>
  </details>

  <script type="module">
    import { createStatusTimeline, CONSTRUCTION_PROJECT_STEPS } from "/assets/js/components/status-timeline.js";
    import { createStatusBadge } from "/assets/js/components/status-badge.js";
    import { authHeaders, getUser } from "/assets/js/lib/auth-session.js";

    const bookingId = <?= (int) $bookingId ?>;
    const status = <?= json_encode($booking['status']) ?>;
    const customerId = <?= json_encode((int) $booking['customer_id']) ?>;
    const providerId = <?= json_encode($booking['provider_id'] !== null ? (int) $booking['provider_id'] : null) ?>;
    const user = getUser();

    const STEP_INDEX = {
      open_for_quotes: 0, quote_accepted: 1, mobilized: 2, in_progress: 3,
      awaiting_final_signoff: 4, inspection_window: 5, completed: 6,
    };
    const BADGE_TONE = {
      open_for_quotes: "neutral", quote_accepted: "accent", in_progress: "warning",
      inspection_window: "warning", completed: "success", cancelled: "danger", disputed: "danger",
    };

    document.getElementById("status-badge-container").appendChild(
      createStatusBadge({ label: status.replace(/_/g, " "), tone: BADGE_TONE[status] ?? "accent" })
    );

    if (STEP_INDEX[status] !== undefined) {
      document.getElementById("status-timeline-container").appendChild(
        createStatusTimeline({ steps: CONSTRUCTION_PROJECT_STEPS, currentIndex: STEP_INDEX[status] })
      );
    }

    const isCustomer = user && user.id === customerId;
    const isProvider = user && providerId !== null && user.id === providerId;

    document.querySelectorAll("[data-milestone-id]").forEach((el) => {
      const milestoneId = el.dataset.milestoneId;
      const milestoneStatus = el.dataset.milestoneStatus;
      const funded = el.dataset.milestoneFunded === "1";
      const actions = el.querySelector(".milestone-actions");

      if (isCustomer && !funded && milestoneStatus === "pending") {
        const phoneInput = document.createElement("input");
        phoneInput.type = "tel";
        phoneInput.placeholder = "07XXXXXXXX";
        phoneInput.style.padding = "var(--ac-space-2)";
        const fundBtn = document.createElement("button");
        fundBtn.className = "btn btn--primary";
        fundBtn.textContent = "Fund with M-Pesa";
        fundBtn.addEventListener("click", async () => {
          fundBtn.disabled = true;
          fundBtn.textContent = "Sending prompt…";
          try {
            const res = await fetch("/api/v1/payments/mpesa/stk-push", {
              method: "POST",
              headers: { "Content-Type": "application/json", ...authHeaders() },
              body: JSON.stringify({ milestone_id: Number(milestoneId), phone: phoneInput.value }),
            });
            const data = await res.json();
            fundBtn.textContent = res.ok
              ? "STK Push sent — enter M-Pesa PIN on your phone"
              : "Failed: " + (data.error || "unknown error");
          } catch (e) {
            fundBtn.textContent = "Network error: " + e.message;
          }
          fundBtn.disabled = false;
        });
        actions.append(phoneInput, fundBtn);
      }

      if (isProvider && funded && milestoneStatus === "pending") {
        const btn = document.createElement("button");
        btn.className = "btn btn--secondary";
        btn.textContent = "Request customer sign-off";
        btn.addEventListener("click", async () => {
          btn.disabled = true;
          const res = await fetch(`/api/v1/projects/${bookingId}/milestones/${milestoneId}/request-signoff`, {
            method: "POST",
            headers: authHeaders(),
          });
          btn.textContent = res.ok ? "Sign-off requested" : "Failed";
        });
        actions.appendChild(btn);
      }

      if (isCustomer && milestoneStatus === "provider_requested_signoff") {
        const btn = document.createElement("button");
        btn.className = "btn btn--primary";
        btn.textContent = "Confirm & release funds";
        btn.addEventListener("click", async () => {
          btn.disabled = true;
          const res = await fetch(`/api/v1/projects/${bookingId}/milestones/${milestoneId}/confirm`, {
            method: "POST",
            headers: authHeaders(),
          });
          const data = await res.json();
          btn.textContent = res.ok ? `Released (escrow: ${data.escrow})` : "Failed: " + (data.error || "");
        });
        actions.appendChild(btn);
      }
    });

    // Condition reports
    const reportsContainer = document.getElementById("condition-reports-container");
    fetch(`/api/v1/projects/${bookingId}/condition-reports`)
      .then((r) => r.json())
      .then((reports) => {
        if (!Array.isArray(reports) || reports.length === 0) {
          reportsContainer.innerHTML = '<p class="card__meta">No condition reports submitted yet.</p>';
          return;
        }
        reportsContainer.innerHTML = "";
        for (const report of reports) {
          const div = document.createElement("div");
          div.className = "card";
          const photos = (() => { try { return JSON.parse(report.photo_urls || "[]"); } catch { return []; } })();
          div.innerHTML = `<div class="card__meta">${report.report_type} · equipment #${report.equipment_listing_id} · ${report.independently_verified ? "independently verified" : "provider-submitted"}</div>
            <p>${report.condition_notes ?? ""}</p>
            ${photos.map((url) => `<img src="${url}" alt="Condition report photo" style="max-width:8rem; border-radius: var(--ac-radius-md); margin-right: var(--ac-space-2);">`).join("")}`;
          reportsContainer.appendChild(div);
        }
      })
      .catch(() => { reportsContainer.innerHTML = '<p class="card__meta">Could not load condition reports.</p>'; });

    const reportForm = document.getElementById("condition-report-form");
    const reportResult = document.getElementById("condition-report-result");
    if (!getUser()) {
      reportResult.innerHTML = `Sign in as the provider to submit a report — <a href="/login">log in</a>.`;
      reportForm.hidden = true;
    }
    reportForm?.addEventListener("submit", async (event) => {
      event.preventDefault();
      const formData = new FormData(event.target);
      const photoUrls = String(formData.get("photo_urls") || "")
        .split(",").map((s) => s.trim()).filter(Boolean);

      try {
        const res = await fetch(`/api/v1/projects/${bookingId}/condition-reports`, {
          method: "POST",
          headers: { "Content-Type": "application/json", ...authHeaders() },
          body: JSON.stringify({
            equipment_listing_id: Number(formData.get("equipment_listing_id")),
            report_type: formData.get("report_type"),
            condition_notes: formData.get("condition_notes"),
            photo_urls: photoUrls,
            meter_reading_hours: formData.get("meter_reading_hours") || null,
          }),
        });
        const data = await res.json();
        reportResult.textContent = res.ok ? `Report #${data.id} submitted.` : "Failed: " + (data.error || "unknown error");
        if (res.ok) reportForm.reset();
      } catch (e) {
        reportResult.textContent = "Network error: " + e.message;
      }
    });
  </script>
<?php endif; ?>
