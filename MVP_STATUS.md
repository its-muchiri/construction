# MVP Status — Construction (construction.co.ke)

STATE: IN_PROGRESS

## Planning references
- Product spec: ../planning/03-construction-co-ke/prd.md
- Data model: ../planning/03-construction-co-ke/database-schema.md
- API contract: ../planning/03-construction-co-ke/api-endpoints.md
- User flows: ../planning/03-construction-co-ke/user-flows.md
- Open questions (stakeholder-pending decisions): ../planning/03-construction-co-ke/open-questions.md
- Shared modules (auth, payments/escrow, booking engine, KYC, reviews): ../planning/00-portfolio/shared-architecture.md
- Authoritative MVP feature cut & build order: ../planning/00-portfolio/build-sequencing-roadmap.md

## What "MVP complete" means for this project
Per build-sequencing-roadmap.md, construction.co.ke is **last** in the build order — it has the highest transaction values (up to KES 5,000,000+), the longest escrow holds, and the deepest KYC requirements, so it benefits from every shared module being battle-tested on the other four platforms first. Its MVP is: equipment rental and labor-contract booking, KYC Tier 3 provider onboarding, escrow with a retention percentage, and an e-commerce store for materials/tools — built on shared modules 1–4 plus module 9 (KYC v2, Tier 3) and module 10 (Escrow & Commission Engine v2: long-hold + retention-percentage release). Equipment-condition-report workflow and milestone-based *multi-week* payment releases are technically V2 per the roadmap, but prd.md calls equipment-condition reporting this platform's single highest-leverage risk mitigation — treat it as effectively MVP-critical rather than deferring it.

- Customer can sign up / log in
- Customer can post a project request (equipment type/duration, or labor scope)
- Verified equipment owners/crews can submit quotes; customer accepts one, creating a booking
- Customer funds escrow with a retention percentage held back pending a post-completion inspection window (Tier 3 escrow behavior per shared-architecture.md's trust-tiering table)
- Provider documents equipment condition at handover and return (photo/video), feeding dispute evidence
- Provider completes Tier 3 KYC (ID, business registration, proof of ownership/insurance where applicable) before listing
- Customer can buy materials/tools/safety equipment from the store (heavy equipment stays rental-only, not store-purchasable, per prd.md)
- Customer can submit a review; damage/long-duration disputes route to an investigation path
- Every image slot (homepage hero, equipment/crew directory photos, provider portfolio photos, store product photos for materials/tools) shows a real, topically relevant photo sourced from Unsplash — not a placeholder box or broken image
- App builds and runs with zero errors, works on mobile width
- Core flow (post request → accept quote → fund escrow → condition report → complete → release funds → review) covered by a smoke test

## Checklist
Controllers already exist for most of this (src/Controllers/*) — verify against the spec above and the planning docs rather than assuming they're complete, and rather than rebuilding from scratch.

- [ ] Identity/auth + Tier 3 KYC — **auth is now DONE**: signup/login/bearer-token session + auth middleware implemented from scratch (see 2026-09-18 changelog entry below; previously $request->user was never populated anywhere, so every write endpoint silently failed — project-new.php's own comment documented this gap). KYC document *submission* (OnboardingController) already existed and now has a working UI at /onboarding. Still missing, so left unchecked overall: an admin console to approve/reject a pending provider's KYC (no admin UI exists anywhere in this codebase yet — user-flows.md step 3 requires this before a provider's Tier 3 status can move past `pending_verification`).
- [ ] Post project request + provider quote/bid submission and acceptance (ProjectController.php's create/submitQuote/acceptQuote now work end-to-end against the live DB now that auth is wired up — verified via curl. Still unchecked: acceptQuote's TODO to create default project_milestones rows on acceptance is unimplemented, so a booking has no milestones after its quote is accepted)
- [ ] Escrow with retention percentage + inspection-window release (verify PaymentController.php against shared-architecture.md's Escrow & Commission Engine v2 and trust-tiering table)
- [ ] Equipment-condition reporting at handover/return, feeding dispute evidence (verify this exists somewhere in ProjectController.php/DisputeController.php — if it doesn't, this is prd.md's stated highest-leverage feature and should be prioritized)
- [ ] Verified provider directory (equipment + crew) (verify equipment-index.php, crew-index.php, provider-profile.php)
- [ ] Store for materials/tools/safety equipment, excluding heavy equipment (verify StoreController.php against prd.md's E-Commerce Store Scope)
- [ ] Review submission + damage-dispute investigation path (verify ReviewController.php, DisputeController.php)
- [ ] Real Unsplash photography (verified resolving URLs) for hero imagery on home.php, equipment-index.php/crew-index.php/provider-profile.php, and store product photos — construction site/equipment/materials subject matter, no placeholders or broken images
- [ ] Error handling (no providers available, failed escrow funding, incomplete condition report)
- [ ] Smoke test / manual run-through of the full core flow passes
- [ ] Remove stubs, TODOs, placeholder data
- [ ] Cross-check against open-questions.md — where it conflicts with an assumption made here, note the assumption taken and continue (don't stop to ask)

Not MVP per the roadmap: full multi-week milestone payment schedules (beyond the single retention-percentage hold), insurance-partnership integration, programmatic SEO pages.

## Known issues / open questions
- This platform's shared-module dependencies (KYC Tier 3, escrow v2 long-hold) are supposed to be proven on laundry.co.ke and rider.co.ke first per build-sequencing-roadmap.md. shared-architecture.md leaves open whether the shared modules are one library or five independent per-platform implementations ("Notes on Deployment Strategy") — if this project has its own independent escrow/KYC code rather than a shared one, verify it still matches the Tier 3 / v2 behavior spec rather than assuming parity with the other platforms.
- Equipment-damage dispute resolution speed is prd.md's stated core risk — treat any gap in condition-report evidence collection as high priority.
- **NEXT PASS — highest priority: the escrow/milestone payment engine is a complete no-op stub.** `PaymentController::stkPush`/`card`/`mpesaCallback` return canned success responses without touching the database at all — no `payments` row, no `escrow_transactions` row is ever created. `ProjectController::acceptQuote` still has the original TODO: it never creates `project_milestones` rows (no default 30/40/30 split — open-questions.md #3 treats the split as negotiable but an MVP default is reasonable per user-flows.md). `ProjectController::confirmMilestone` updates milestone status but never calls into any escrow-release logic (its own TODO says so). None of the retention-percentage/inspection-window behavior that's this platform's headline feature is wired up yet. This blocks the full core smoke test (post → accept quote → **fund escrow** → condition report → complete → **release funds** → review) at the funding step.
- `DisputeController::store` still has its TODO unimplemented: an `equipment_damage` dispute does not auto-attach the booking's `equipment_condition_reports` as evidence (user-flows.md's dispute flow step 2). Also: no admin/dispute-investigation console UI exists — DisputeController is API-only.
- No admin console exists anywhere (KYC approval, dispute resolution, listing moderation) — Platform Admin and Site Inspector roles from prd.md's permissions table have no UI. Likely the next major checklist item after escrow.
- Store has API endpoints (StoreController) but **no page/view at all** — no `/store` route, no product listing UI, no cart/checkout UI. `store_products` table is empty (0 rows) — no seed data for materials/tools/safety equipment.
- No images anywhere in the app yet — home.php, equipment-index.php, crew-index.php, provider-profile.php have zero `<img>` tags. Needs real Unsplash sourcing per this platform's image-slot requirements once the store and listing UIs have something to show images for.
- `equipment_listings` has exactly 1 seed row, `crew_listings` has 0 — provider directory pages will render empty states against the live DB until more listings exist (either seed data or real signups).
- No smoke test script/harness exists yet. This pass verified the new auth flow manually via curl against the live Neon DB (signup → login → /me → auth-gated project POST, all passing) and cleaned up the test rows afterward, but there's no repeatable automated test.
- Deployment gap: `APP_KEY` (new — signs the bearer token) is only set in local `.env`/`.env.local`; it must also be added as a real Vercel environment variable (`vercel env add APP_KEY production`, or via the Vercel dashboard) before signup/login will work on the live deployment. The Vercel CLI isn't installed in this environment, so this pass couldn't do it directly.

## Changelog
- 2026-09-17: Initial checklist created (assumed generic scope, not sourced from planning/)
- 2026-09-18: Rewritten against planning/03-construction-co-ke and shared-architecture.md; checklist now reflects the roadmap's authoritative MVP cut and points at existing controllers to verify rather than assuming a blank slate
- 2026-09-18: Auth — found that no authentication existed anywhere in the codebase: `$request->user` was never populated by anything, so every write endpoint (post project, submit quote, KYC onboarding, listings, reviews, disputes, store orders) resolved the acting user to null and would fail against a real database (project-new.php's own inline comment documented this as a known gap). Implemented from scratch: `src/Core/Auth.php` (stateless HMAC-signed bearer token — no server-side session store, since this runs as a Vercel PHP function), `AuthController` (signup/login/me), auth middleware in `public/index.php` that resolves the token into `$request->user`, and added `Response::unauthorized()` guards to every controller method that depends on the acting user (ProjectController::create/submitQuote, OnboardingController::submit, ListingController::createEquipment/createCrew, ReviewController::store, DisputeController::store, StoreController::createOrder, PaymentController::myEarnings). Added `/login`, `/signup`, `/onboarding` pages, a `public/assets/js/lib/auth-session.js` client-side session helper, an auth-aware nav in layout.php, and fixed project-new.php to actually send the bearer token and gate on sign-in instead of silently failing. Verified the full flow (signup → login → /me → unauthenticated 401 → authenticated project POST → duplicate-phone 409 → wrong-password 401) via curl against the live Neon Postgres database, then cleaned up the test rows. Assumption taken (not in open-questions.md, so noted here): auth uses a stateless bearer token rather than server-side sessions/cookies, since Vercel's PHP runtime has no persistent process between invocations; `APP_KEY` added to `.env`/`.env.example`/`.env.local` but still needs to be set as a real Vercel env var before the live deployment's signup/login will work (Vercel CLI not installed in this environment).
