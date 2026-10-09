'use strict';
document.querySelectorAll('[data-conditional]').forEach(group => {
  const name = group.dataset.conditional;
  const value = group.dataset.value;
  const controls = [...document.querySelectorAll(`[name="${name}"]`)];
  const update = () => {
    const selected = controls.some(control => (control.type === 'radio' || control.type === 'checkbox') ? control.checked && control.value === value : control.value === value);
    group.hidden = !selected;
    group.querySelectorAll('[data-required]').forEach(input => { input.required = selected; });
  };
  controls.forEach(control => control.addEventListener('change', update));
  update();
});
document.querySelectorAll('[name="safety_improvements[]"]').forEach(box => {
  box.addEventListener('change', () => {
    if (!box.checked) return;
    document.querySelectorAll('[name="safety_improvements[]"]').forEach(other => {
      if (other !== box && (box.value === 'None' || other.value === 'None')) other.checked = false;
    });
    const other = document.querySelector('[data-conditional="safety_improvements[]"]');
    if (other) {
      const selected = document.querySelector('[name="safety_improvements[]"][value="Other"]').checked;
      other.hidden = !selected;
      other.querySelector('[data-required]').required = selected;
    }
  });
});
document.querySelectorAll('[data-copy]').forEach(button => {
  button.addEventListener('click', async () => {
    const target = document.getElementById(button.dataset.copy);
    const status = document.getElementById('copy-status');
    try {
      await navigator.clipboard.writeText(target.textContent);
      if (status) status.textContent = 'Folder path copied.';
      button.textContent = 'Copied ✓';
      setTimeout(() => { button.textContent = 'Copy path'; }, 2500);
    } catch {
      const range = document.createRange();
      range.selectNodeContents(target);
      const selection = window.getSelection();
      selection.removeAllRanges(); selection.addRange(range);
      if (status) status.textContent = 'Path selected. Press Ctrl+C to copy it.';
    }
  });
});
document.querySelectorAll('form[data-confirm]').forEach(form => {
  form.addEventListener('submit', event => { if (!window.confirm(form.dataset.confirm)) event.preventDefault(); });
});
const errors = document.getElementById('form-errors');
if (errors) errors.focus();
