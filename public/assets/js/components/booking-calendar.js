/**
 * Booking Calendar — date-range mode, for equipment rental periods and
 * project timelines (see planning/00-portfolio/design-system.md's note
 * that construction.co.ke/event.co.ke/solar.co.ke's site-survey use the
 * date-range configuration of this shared component, vs. laundry.co.ke's
 * single-slot mode).
 *
 * @param {{ onChange: (range: { start: string, end: string }) => void }} props
 * @returns {HTMLElement}
 */
export function createDateRangePicker({ onChange }) {
  const wrapper = document.createElement("div");
  wrapper.className = "date-range-picker";

  const startField = document.createElement("div");
  startField.className = "date-range-picker__field";
  const startLabel = document.createElement("label");
  startLabel.textContent = "Start date";
  startLabel.htmlFor = "date-range-start";
  const startInput = document.createElement("input");
  startInput.type = "date";
  startInput.id = "date-range-start";
  startField.append(startLabel, startInput);

  const endField = document.createElement("div");
  endField.className = "date-range-picker__field";
  const endLabel = document.createElement("label");
  endLabel.textContent = "End date";
  endLabel.htmlFor = "date-range-end";
  const endInput = document.createElement("input");
  endInput.type = "date";
  endInput.id = "date-range-end";
  endField.append(endLabel, endInput);

  function emitChange() {
    if (startInput.value && endInput.value) {
      onChange({ start: startInput.value, end: endInput.value });
    }
  }

  startInput.addEventListener("change", emitChange);
  endInput.addEventListener("change", emitChange);

  wrapper.append(startField, endField);
  return wrapper;
}
