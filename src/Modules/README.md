# Shared Modules — Integration Point

Placeholder directory. construction.co.ke is the **last** platform in the build sequence (see `planning/00-portfolio/build-sequencing-roadmap.md`) specifically because it depends on the Escrow & Commission Engine's v2 capability (long-hold, milestone-based release with a retention percentage and inspection window) and the KYC pipeline's v2 (Tier 3) depth — both of which should be proven on earlier platforms first.

Once those shared modules exist, `src/Controllers/ProjectController.php`'s inline `TODO` milestone-release logic and the KYC-gating for provider onboarding should delegate here instead of reimplementing per platform.
