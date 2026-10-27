import { clearCache, get, post } from './api.js';
import { h, icon, clear } from './h.js';
import { errorBlock, skeletonLines } from './states.js';
import { toast } from './toast.js';
import { openModal } from './modal.js';

/** Versão do PHP do domínio: a Hostinger guarda uma versão por vhost. */
export function openPhpModal(ctx) {
  const body = h('div', { class: 'stack' });
  openModal({ title: `Versão do PHP · ${ctx.domain}`, content: body, actions: [{ label: 'Fechar', value: null }] });
  load(body, ctx);
}

async function load(body, ctx) {
  clear(body).append(...skeletonLines(3));
  try {
    render(body, ctx, await get('php-version', { orderId: ctx.orderId, domain: ctx.domain }));
  } catch (err) {
    clear(body).append(errorBlock(err, () => load(body, ctx)));
  }
}

function render(body, ctx, data) {
  const versions = h('select', { class: 'input', 'aria-label': 'Versão do PHP' },
    data.versions.map((v) => h('option', { value: v.version, selected: v.version === data.current }, v.version === data.current ? `PHP ${v.version} (atual)` : v.label)));
  const status = h('p', { class: 'muted small' }, data.current ? `Em uso: PHP ${data.currentFull || data.current}` : 'A Hostinger não informou a versão atual.');
  const save = h('button', { type: 'button', class: 'btn btn-primary' }, icon('check'), 'Aplicar');

  save.addEventListener('click', async () => {
    const version = versions.value;
    save.disabled = true;
    save.setAttribute('aria-busy', 'true');
    versions.disabled = true;
    clear(status).append('Aplicando… a Hostinger pode levar até 30 s para confirmar.');
    try {
      await post('set-php-version', { orderId: ctx.orderId, domain: ctx.domain, version });
      clearCache();
      toast(`PHP ${version} ativado em ${ctx.domain}.`, 'ok');
      load(body, ctx);
    } catch (err) {
      toast(err.message, 'danger');
      clear(status).append(err.message);
      save.disabled = false;
      versions.disabled = false;
    } finally {
      save.removeAttribute('aria-busy');
    }
  });

  clear(body).append(
    h('div', {}, h('label', { class: 'label' }, 'Versão'), versions),
    h('p', { class: 'hint' }, 'A troca vale para este domínio e vira efetiva no próximo acesso ao site.'),
    h('div', { class: 'row' }, save, status),
  );
}
