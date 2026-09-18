<h1>Log in</h1>

<form id="login-form" style="max-width: 24rem; display:flex; flex-direction:column; gap: var(--ac-space-4);">
  <label>
    Phone number
    <input type="tel" name="phone_number" placeholder="e.g. 0712345678" required style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>

  <label>
    Password
    <input type="password" name="password" required style="display:block; width:100%; padding: var(--ac-space-2); margin-top: var(--ac-space-1);">
  </label>

  <button type="submit" class="btn btn--primary">Log in</button>
</form>

<p id="login-result" class="card__meta" style="margin-top: var(--ac-space-4);"></p>
<p class="card__meta">No account yet? <a href="/signup">Sign up</a>.</p>

<script type="module">
  import { setSession } from "/assets/js/lib/auth-session.js";

  const params = new URLSearchParams(window.location.search);
  const next = params.get("next") || "/";

  document.getElementById("login-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    const formData = new FormData(event.target);
    const resultEl = document.getElementById("login-result");

    try {
      const res = await fetch("/api/v1/auth/login", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          phone_number: formData.get("phone_number"),
          password: formData.get("password"),
        }),
      });
      const data = await res.json();

      if (!res.ok) {
        resultEl.textContent = data.error || "Log in failed.";
        return;
      }

      setSession(data.token, data.user);
      window.location.href = next;
    } catch (e) {
      resultEl.textContent = "Network error: " + e.message;
    }
  });
</script>
