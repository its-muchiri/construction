# construction.co.ke

Scaffold for the construction.co.ke marketplace platform. See `/planning/03-construction-co-ke/` for the full PRD, user flows, database schema, API spec, and open questions this scaffold implements a starting skeleton of.

## Stack
PHP (no framework, PSR-4 autoloaded) + vanilla JS/CSS + relational SQL (MySQL/MariaDB), per `/planning/00-portfolio/shared-architecture.md`.

## Design system
Tokens in `public/assets/css/tokens.css` are ported from the real artcollect.co.ke system documented in `/planning/00-portfolio/artcollect-design-system.md`, with the **platform accent set to marker-red** (artcollect's "handwritten" lane accent) — chosen for an industrial/bold/safety-adjacent association distinct from the other four platforms' lanes. Only the token architecture, the collage/pixel decorative primitives, and the motion/accessibility governance rules are adopted; the heavier graffiti and 3D/diorama treatments are intentionally **not** ported here.

This platform does **not** use a FAB (see `planning/00-portfolio/design-system.md`'s assumption that construction/solar/event use a sticky in-page CTA instead, given their longer, more considered booking journey vs. laundry/rider's high-frequency actions) — see `public/assets/js/components/sticky-cta.js`.

**Critical-flow rule (enforced, not just documented):** `public/assets/js/pages/checkout.js` (milestone funding) and any escrow-release or equipment-damage-dispute page must never import `components/scrap.js` or any decorative module — this platform's highest-stakes screens (large sums, damage claims) deserve the calmest possible UI, per `artcollect-design-system.md` §7.

## Structure

```
public/                 Web root — front controller, static assets
  index.php             Front controller: bootstraps Router, dispatches request
  assets/css/           tokens.css, reset.css, main.css
  assets/js/            main.js, components/, pages/
src/
  Config/               Database connection (PDO)
  Core/                 Router, Request, Response
  Controllers/          ProjectController, PaymentController, ReviewController, DisputeController
  Models/               Data-access classes
  Modules/              Placeholder for shared-module integration points (Escrow v2, KYC v2, ...)
database/
  schema.sql            Shared core tables + this platform's extension tables (construction_bookings, project_milestones, equipment_condition_reports, ...)
routes/
  api.php               Route table — mirrors planning/03-construction-co-ke/api-endpoints.md
```

## Getting started

1. Copy `.env.example` to `.env` and fill in database + M-Pesa Daraja + card-gateway credentials (card is common here given high transaction values).
2. Create the database and run `database/schema.sql` against it.
3. Point your web server's document root at `public/`, with all requests rewritten to `public/index.php`.
4. `composer install` if/when shared-module packages are added as dependencies.

## What this scaffold is (and isn't)

This is a **starting skeleton**: the controllers wire up the request/response shape of the quote → milestone → escrow-release lifecycle, not the milestone-percentage negotiation logic, condition-report evidence comparison, or damage-liability calculation (blocked on `planning/03-construction-co-ke/open-questions.md` #1–#2 — the insurance/deposit model is unresolved). Every stub references the planning doc section it should eventually implement.

**Implemented in this scaffold:** projects/quotes/milestones/condition-reports, payments (stub), reviews, disputes, Tier 3 provider KYC onboarding, equipment/crew listing search (`ListingController`), and the e-commerce store. **Not yet wired:** the equipment-damage liability calculation itself remains intentionally unimplemented pending the unresolved open questions above.
