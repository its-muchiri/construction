/**
 * Sticky CTA — used by construction.co.ke, solar.co.ke, and event.co.ke in
 * place of a FAB (see design-system.md's assumption that these platforms'
 * longer, more considered booking journeys favor a persistent in-page CTA
 * over a floating action button, which suits high-frequency quick actions).
 * @param {{ label: string, onClick: () => void }} props
 * @returns {HTMLElement}
 */
export function createStickyCta({ label, onClick }) {
  const wrapper = document.createElement("div");
  wrapper.className = "sticky-cta";

  const button = document.createElement("button");
  button.type = "button";
  button.className = "btn btn--primary";
  button.textContent = label;
  button.addEventListener("click", onClick);

  wrapper.appendChild(button);
  return wrapper;
}
