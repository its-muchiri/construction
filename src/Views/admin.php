<h1>Admin console</h1>
<p id="admin-gate" class="card__meta"></p>

<div id="admin-panels" hidden>
  <section>
    <h2>Provider KYC review queue</h2>
    <p class="card__meta" style="max-width: var(--ac-measure);">
      Tier 3 depth: national ID, KRA PIN and business registration are required before a provider can list equipment
      or crews or submit quotes. Approving verifies every pending document; rejecting sends the provider back to resubmit.
    </p>
    <div id="kyc-queue" style="display:flex; flex-direction:column; gap: var(--ac-space-4); margin-top: var(--ac-space-4);"></div>
  </section>

  <section style="margin-top: var(--ac-space-12);">
    <h2>Open disputes</h2>
    <p class="card__meta" style="max-width: var(--ac-measure);">
      Equipment-damage disputes arrive with the booking's handover/return condition-report photos already attached as evidence.
    </p>
    <div id="dispute-queue" style="display:flex; flex-direction:column; gap: var(--ac-space-4); margin-top: var(--ac-space-4);"></div>
  </section>
</div>

<script type="module">
  import { authHeaders, getUser } from "/assets/js/lib/auth-session.js";

  const gateEl = document.getElementById("admin-gate");
  const panelsEl = document.getElementById("admin-panels");
  const kycEl = document.getElementById("kyc-queue");
  const disputeEl = document.getElementById("dispute-queue");

  function el(tag, props = {}, ...children) {
    const node = document.createElement(tag);
    Object.assign(node, props);
    for (const child of children) node.append(child);
    return node;
  }

  async function api(method, path, body) {
    const res = await fetch(path, {
      method,
      headers: { "Content-Type": "application/json", ...authHeaders() },
      body: body ? JSON.stringify(body) : undefined,
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || `Request failed (${res.status})`);
    return data;
  }

  function safeLink(url) {
    // Evidence/document references are user-supplied strings — only render http(s) ones as links.
    if (/^https?:\/\//i.test(url)) {
      return el("a", { href: url, target: "_blank", rel: "noopener noreferrer", textContent: url });
    }
    return el("span", { textContent: url });
  }

  async function loadKyc() {
    kycEl.replaceChildren();
    const providers = await api("GET", "/api/v1/admin/kyc");
    if (!providers.length) {
      kycEl.append(el("p", { className: "card__meta", textContent: "No providers awaiting KYC review." }));
      return;
    }

    for (const provider of providers) {
      const status = el("p", { className: "card__meta" });
      const docs = el("ul", { className: "card__meta" });
      for (const doc of provider.documents) {
        docs.append(el("li", {}, `${doc.document_type.replace(/_/g, " ")} (${doc.verification_status}): `, safeLink(doc.file_reference)));
      }

      const notes = el("input", { type: "text", placeholder: "Notes (optional)", style: "padding: var(--ac-space-2); flex: 1 1 12rem;" });
      const decide = (decision) => async () => {
        status.textContent = "Saving…";
        try {
          await api("POST", `/api/v1/admin/kyc/${provider.id}/decision`, { decision, notes: notes.value || null });
          await loadKyc();
        } catch (e) {
          status.textContent = e.message;
        }
      };

      kycEl.append(el("div", { className: "card", style: "max-width: 40rem;" },
        el("strong", { textContent: `${provider.full_name} — ${provider.phone_number}` }),
        el("p", { className: "card__meta", textContent: `Provider #${provider.id}${provider.email ? " · " + provider.email : ""}` }),
        docs,
        el("div", { style: "display:flex; gap: var(--ac-space-2); flex-wrap:wrap; margin-top: var(--ac-space-2);" },
          notes,
          el("button", { type: "button", className: "btn btn--primary", textContent: "Approve", onclick: decide("approve") }),
          el("button", { type: "button", className: "btn btn--secondary", textContent: "Reject", onclick: decide("reject") }),
        ),
        status,
      ));
    }
  }

  async function loadDisputes() {
    disputeEl.replaceChildren();
    const disputes = await api("GET", "/api/v1/disputes");
    if (!disputes.length) {
      disputeEl.append(el("p", { className: "card__meta", textContent: "No open disputes." }));
      return;
    }

    for (const dispute of disputes) {
      const evidence = JSON.parse(dispute.evidence_urls || "[]");
      const evidenceList = el("ul", { className: "card__meta" });
      for (const url of evidence) evidenceList.append(el("li", {}, safeLink(url)));

      const outcome = el("select", { style: "padding: var(--ac-space-2);" },
        el("option", { value: "resolved_no_action", textContent: "Resolved — no action" }),
        el("option", { value: "resolved_partial", textContent: "Resolved — partial (deduct from retention)" }),
        el("option", { value: "resolved_refund", textContent: "Resolved — refund" }),
        el("option", { value: "under_review", textContent: "Mark under review" }),
        el("option", { value: "escalated", textContent: "Escalate" }),
      );
      const notes = el("input", { type: "text", placeholder: "Resolution notes", style: "padding: var(--ac-space-2); flex: 1 1 14rem;" });
      const status = el("p", { className: "card__meta" });

      const save = el("button", { type: "button", className: "btn btn--primary", textContent: "Save", onclick: async () => {
        status.textContent = "Saving…";
        try {
          await api("PATCH", `/api/v1/disputes/${dispute.id}/resolve`, { status: outcome.value, resolution_notes: notes.value || null });
          await loadDisputes();
        } catch (e) {
          status.textContent = e.message;
        }
      } });

      disputeEl.append(el("div", { className: "card", style: "max-width: 40rem;" },
        el("strong", { textContent: `Dispute #${dispute.id} — ${dispute.category.replace(/_/g, " ")}` }),
        el("p", { className: "card__meta" }, `Booking `, el("a", { href: `/projects/${dispute.booking_id}`, textContent: `#${dispute.booking_id}` }), ` · ${dispute.status.replace(/_/g, " ")}`),
        el("p", { textContent: dispute.description }),
        evidence.length ? evidenceList : el("p", { className: "card__meta", textContent: "No evidence attached." }),
        el("div", { style: "display:flex; gap: var(--ac-space-2); flex-wrap:wrap; margin-top: var(--ac-space-2);" }, outcome, notes, save),
        status,
      ));
    }
  }

  const user = getUser();
  if (!user) {
    gateEl.innerHTML = `Sign in as a Platform Admin — <a href="/login?next=/admin">log in</a>.`;
  } else if (user.account_type !== "admin") {
    gateEl.textContent = "This page is for Platform Admins only.";
  } else {
    panelsEl.hidden = false;
    Promise.all([loadKyc(), loadDisputes()]).catch((e) => {
      gateEl.textContent = e.message;
    });
  }
</script>
