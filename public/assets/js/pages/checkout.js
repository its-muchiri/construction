/**
 * Milestone-funding / escrow-release page logic.
 *
 * ENFORCED RULE (per artcollect-design-system.md §7 and this platform's
 * README): this file, and any equipment-damage-dispute page, must NEVER
 * import components/scrap.js or any other decorative module.
 */
import { createStatusTimeline, CONSTRUCTION_PROJECT_STEPS } from "../components/status-timeline.js";

// import { createTornEdge } from "../components/scrap.js"; // <- NEVER do this here.

export function renderProjectStatus(container, currentIndex) {
  container.classList.add("critical-flow");
  container.appendChild(createStatusTimeline({ steps: CONSTRUCTION_PROJECT_STEPS, currentIndex }));
}
