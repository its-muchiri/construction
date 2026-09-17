# Tests

No test runner is wired up yet. Recommended: PHPUnit for `src/`, a small assertion runner (or Vitest) for `public/assets/js/`.

Priority areas once real business logic lands:

- Milestone-percentage calculation and escrow-release sequencing (see `project_milestones`)
- Equipment-damage liability calculation once open-questions.md #1-#2 are resolved (insurance vs. deposit-only model) — this is currently unimplemented by design, not by oversight
- M-Pesa/card callback idempotency
- Condition-report comparison logic (handover vs. return) once implemented
