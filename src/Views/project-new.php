<h1>Post a project</h1>

<form id="project-form" style="max-width: 32rem; display:flex; flex-direction:column; gap: var(--ac-space-4);">
  <label>
    What do you need?
    <select name="booking_type" id="booking-type" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
      <option value="equipment_rental">Equipment rental</option>
      <option value="labor_contract">Labor contract</option>
    </select>
  </label>

  <label>
    Category
    <input type="text" name="category" placeholder="e.g. excavator, mason crew" required style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>

  <label>
    Site address
    <input type="text" name="location_address" required style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>

  <div id="date-range-container"></div>

  <button type="submit" class="btn btn--primary">Post project</button>
</form>

<p id="project-result" class="card__meta" style="margin-top: var(--ac-space-4);"></p>

<script type="module">
  import { createDateRangePicker } from "/assets/js/components/booking-calendar.js";

  let dateRange = { start: null, end: null };
  document.getElementById("date-range-container").appendChild(
    createDateRangePicker({ onChange: (range) => { dateRange = range; } })
  );

  document.getElementById("project-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const formData = new FormData(event.target);
    const resultEl = document.getElementById("project-result");
    const bookingType = formData.get("booking_type");

    const payload = {
      booking_type: bookingType,
      category: formData.get("category"),
      location_address: formData.get("location_address"),
      location_lat: 0,
      location_lng: 0,
    };
    if (bookingType === "equipment_rental") {
      payload.rental_start_date = dateRange.start;
      payload.rental_end_date = dateRange.end;
    } else {
      payload.project_start_date = dateRange.start;
      payload.project_end_date = dateRange.end;
    }

    try {
      const res = await fetch("/api/v1/projects", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload),
      });
      const data = await res.json();

      if (!res.ok) {
        // Expected right now: no auth middleware exists yet, so customer_id
        // resolves to null and the database rejects the insert. See
        // src/Controllers/ProjectController.php.
        resultEl.textContent = "Post failed: " + (data.error || "unknown error") + " — expected until auth middleware and a live database are wired up.";
        return;
      }

      resultEl.innerHTML = `Project #${data.id} posted (status: ${data.status}). <a href="/projects/${data.id}">Track it</a>`;
    } catch (e) {
      resultEl.textContent = "Network error: " + e.message;
    }
  });
</script>
