import { h, icon } from './h.js';

/**
 * Diálogo acessível (<dialog> nativo: foco preso, Esc fecha).
 * actions: [{ label, value, variant? }] → resolve com `value`; fechar resolve com null.
 * onOpen(close): recebe a função que fecha resolvendo com o valor dado (formulários que salvam e fecham).
 */
export function openModal({ title, content, actions = [], wide = false, onOpen = null }) {
  return new Promise((resolve) => {
    const previous = document.activeElement;
    const dialog = h('dialog', { class: wide ? 'modal modal-wide' : 'modal', 'aria-label': title });
    const close = (value) => {
      dialog.close();
      dialog.remove();
      previous?.focus?.();
      resolve(value);
    };
    dialog.append(
      h('header', { class: 'modal-head' },
        h('h2', {}, title),
        h('button', { type: 'button', class: 'btn btn-ghost btn-icon btn-sm', 'aria-label': 'Fechar', on: { click: () => close(null) } }, icon('x'))),
      h('div', { class: 'modal-body' }, content),
    );
    if (actions.length) {
      dialog.append(h('footer', { class: 'modal-foot' }, actions.map((a) =>
        h('button', { type: 'button', class: `btn btn-${a.variant || 'secondary'}`, on: { click: () => close(a.value) } }, a.label))));
    }
    dialog.addEventListener('cancel', (e) => { e.preventDefault(); close(null); });
    dialog.addEventListener('click', (e) => { if (e.target === dialog) close(null); });
    document.body.append(dialog);
    dialog.showModal();
    const hasDanger = actions.some((a) => a.variant === 'danger');
    dialog.querySelector(hasDanger ? '.modal-foot .btn:first-child' : '.modal-foot .btn:last-child')?.focus();
    onOpen?.(close);
  });
}

export async function confirmDialog(title, message, okLabel = 'Confirmar', variant = 'primary') {
  const result = await openModal({
    title,
    content: h('p', {}, message),
    actions: [{ label: 'Cancelar', value: false }, { label: okLabel, value: true, variant }],
  });
  return result === true;
}
