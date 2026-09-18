<h1>Sign up</h1>

<form id="signup-form" style="max-width: 24rem; display:flex; flex-direction:column; gap: var(--ac-space-4);">
  <label>
    I am a...
    <select name="account_type" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
      <option value="customer">Customer (posting a project)</option>
      <option value="provider">Equipment owner / crew (provider)</option>
    </select>
  </label>

  <label>
    Full name
    <input type="text" name="full_name" required style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>

  <label>
    Phone number
    <input type="tel" name="phone_number" placeholder="e.g. 0712345678" required style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>

  <label>
    Email (optional)
    <input type="email" name="email" style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>

  <label>
    Password
    <input type="password" name="password" minlength="6" required style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>

  <button type="submit" class="btn btn--primary">Create account</button>
</form>

<p id="signup-result" class="card__meta" style="margin-top: var(--ac-space-4);"></p>
<p class="card__meta">Already have an account? <a href="/login">Log in</a>.</p>

<script type="module">
  import { setSession } from "/assets/js/lib/auth-session.js";

  const params = new URLSearchParams(window.location.search);
  const next = params.get("next") || "/";

  document.getElementById("signup-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const formData = new FormData(event.target);
    const resultEl = document.getElementById("signup-result");
    const accountType = formData.get("account_type");

    try {
      const res = await fetch("/api/v1/auth/signup", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          account_type: accountType,
          full_name: formData.get("full_name"),
          phone_number: formData.get("phone_number"),
          email: formData.get("email") || null,
          password: formData.get("password"),
        }),
      });
      const data = await res.json();

      if (!res.ok) {
        resultEl.textContent = data.error || "Sign up failed.";
        return;
      }

      setSession(data.token, data.user);

      if (accountType === "provider") {
        resultEl.innerHTML = `Account created. Providers need Tier 3 KYC approval before listing — <a href="/onboarding">complete verification</a>.`;
        window.location.href = "/onboarding";
      } else {
        window.location.href = next;
      }
    } catch (e) {
      resultEl.textContent = "Network error: " + e.message;
    }
  });
</script>
