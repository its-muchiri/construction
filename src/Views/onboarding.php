<h1>Provider verification (Tier 3 KYC)</h1>
<p class="card__meta" style="max-width: var(--ac-measure);">
  Given transaction values up to KES 5,000,000+, equipment owners and crews must submit national ID, KRA PIN, and
  business registration before listing. Equipment listings additionally require proof of ownership (and insurance,
  for high-value equipment) — attached separately when you create that listing.
</p>

<form id="kyc-form" style="max-width: 32rem; display:flex; flex-direction:column; gap: var(--ac-space-4); margin-top: var(--ac-space-6);">
  <label>
    National ID document reference (photo/file URL)
    <input type="text" name="national_id" required placeholder="https://…" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>
  <label>
    KRA PIN document reference
    <input type="text" name="kra_pin" required placeholder="https://…" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>
  <label>
    Business registration document reference
    <input type="text" name="business_registration" required placeholder="https://…" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>

  <button type="submit" class="btn btn--primary">Submit for review</button>
</form>

<p id="kyc-result" class="card__meta" style="margin-top: var(--ac-space-4);"></p>

<script type="module">
  import { authHeaders, getUser } from "/assets/js/lib/auth-session.js";

  const resultEl = document.getElementById("kyc-result");

  if (!getUser()) {
    resultEl.innerHTML = `Sign in first — <a href="/login?next=/onboarding">log in</a> or <a href="/signup">sign up</a> as a provider.`;
    document.getElementById("kyc-form").hidden = true;
  }

  document.getElementById("kyc-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const formData = new FormData(event.target);

    const documents = [
      { document_type: "national_id", file_reference: formData.get("national_id") },
      { document_type: "kra_pin", file_reference: formData.get("kra_pin") },
      { document_type: "business_registration", file_reference: formData.get("business_registration") },
    ];

    try {
      const res = await fetch("/api/v1/providers/onboard", {
        method: "POST",
        headers: { "Content-Type": "application/json", ...authHeaders() },
        body: JSON.stringify({ documents }),
      });
      const data = await res.json();

      if (!res.ok) {
        resultEl.textContent = data.error || "Submission failed.";
        return;
      }

      resultEl.innerHTML = `Documents submitted — status: <strong>${data.status}</strong>. An admin will review before you can list equipment or crew. You can still create listings now; they'll show as unverified until approved.`;
      event.target.hidden = true;
    } catch (e) {
      resultEl.textContent = "Network error: " + e.message;
    }
  });
</script>
