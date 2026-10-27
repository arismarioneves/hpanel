import { get, post } from './api.js';
import { h, icon, clear } from './h.js';
import { errorBlock, emptyBlock, skeletonLines } from './states.js';
import { toast } from './toast.js';
import { openModal, confirmDialog } from './modal.js';
import { badge, copyText } from './components.js';

/**
 * Zona DNS do domínio: a Hostinger agrupa os registros por nome + tipo, e é esse conjunto
 * que o painel edita e exclui por inteiro — um conteúdo por linha no formulário.
 */
export function openDnsModal(ctx) {
  const body = h('div', { class: 'stack' });
  openModal({ title: `DNS · ${ctx.domain}`, content: body, wide: true, actions: [{ label: 'Fechar', value: null }] });
  load(body, ctx);
}

const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS', 'SRV', 'CAA'];
const HINTS = {
  A: 'Endereço IPv4, como 203.0.113.10.',
  AAAA: 'Endereço IPv6, como 2001:db8::1.',
  CNAME: 'Outro domínio, como destino.exemplo.com.',
  MX: 'Prioridade e servidor, como 10 mx1.hostinger.com.',
  TXT: 'Texto livre, como v=spf1 include:_spf.mail.hostinger.com ~all (sem aspas).',
  NS: 'Servidor de nomes, como ns1.dns-parking.com.',
  SRV: 'Prioridade, peso, porta e destino, como 10 5 5060 sip.exemplo.com.',
  CAA: 'Flag, tag e valor, como 0 issue "letsencrypt.org".',
};
const DEFAULT_TTL = 14400;
const SEARCH_FROM = 6;

async function load(body, ctx) {
  clear(body).append(...skeletonLines(5));
  try {
    render(body, ctx, await get('dns-records', { orderId: ctx.orderId, domain: ctx.domain }));
  } catch (err) {
    clear(body).append(errorBlock(err, () => load(body, ctx)));
  }
}

function render(body, ctx, zone) {
  const { records, nameservers } = zone;
  const list = h('div', { class: 'dns-list' });
  const rows = records.map((set) => ({ set, row: recordRow(body, ctx, set) }));

  const draw = (query) => {
    const shown = rows.filter(({ set }) => !query || matches(set, query));
    clear(list);
    list.append(...(shown.length
      ? shown.map(({ row }) => row)
      : [emptyBlock('Nenhum registro encontrado.', 'Ajuste a busca.')]));
  };

  const search = h('input', { type: 'search', placeholder: 'Buscar registro', 'aria-label': 'Buscar registro', autocomplete: 'off' });
  search.addEventListener('input', () => draw(search.value.trim().toLowerCase()));

  clear(body).append(...[
    h('div', { class: 'dns-ns' },
      h('span', { class: 'muted small' }, 'Nameservers'),
      nameservers.length
        ? h('div', { class: 'row badges' }, nameservers.map((ns) => badge(ns)))
        : h('span', { class: 'muted small' }, 'não informados pela Hostinger')),
    records.length >= SEARCH_FROM ? h('label', { class: 'search-box' }, icon('search'), search) : null,
    records.length
      ? list
      : emptyBlock('Esta zona não tem registros.', 'Use o formulário abaixo para criar o primeiro.'),
    h('div', { class: 'repo-sep' }),
    recordForm(body, ctx),
  ].filter(Boolean));
  if (records.length) draw('');
}

const matches = (set, query) =>
  set.name.toLowerCase().includes(query)
  || set.type.toLowerCase().includes(query)
  || set.contents.some((c) => c.toLowerCase().includes(query));

function recordRow(body, ctx, set) {
  const editBox = h('div', { class: 'dns-edit', hidden: true });
  const editButton = h('button', {
    type: 'button', class: 'btn btn-ghost btn-icon btn-sm', 'aria-label': 'Editar registro', 'data-tip': 'Editar', 'aria-expanded': 'false',
    on: {
      click: () => {
        const opening = editBox.hidden;
        editBox.hidden = !opening;
        editButton.setAttribute('aria-expanded', String(opening));
        if (opening) clear(editBox).append(recordForm(body, ctx, set));
      },
    },
  }, icon('settings'));

  const deleteButton = h('button', {
    type: 'button', class: 'btn btn-ghost btn-icon btn-sm', 'aria-label': 'Excluir registro', 'data-tip': 'Excluir',
    on: {
      click: async () => {
        const ok = await confirmDialog(
          'Excluir este registro?',
          `${set.contents.length === 1 ? 'O conteúdo é removido' : `Os ${set.contents.length} conteúdos são removidos`} de ${set.name} (${set.type}) na zona de ${ctx.domain}. A propagação pode levar até o TTL de ${set.ttl} s.`,
          'Excluir',
          'danger',
        );
        if (!ok) return;
        deleteButton.disabled = true;
        try {
          const zone = await post('dns-records', { orderId: ctx.orderId, domain: ctx.domain, action: 'delete', name: set.name, type: set.type });
          toast('Registro excluído.', 'ok');
          render(body, ctx, zone);
        } catch (err) {
          toast(err.message, 'danger');
          deleteButton.disabled = false;
        }
      },
    },
  }, icon('trash'));

  return h('article', { class: 'dns-record' },
    h('div', { class: 'dns-head' },
      badge(set.type, 'accent'),
      h('span', { class: 'dns-name mono' }, set.name),
      h('span', { class: 'muted small' }, `TTL ${set.ttl} s`),
      h('div', { class: 'row' }, editButton, deleteButton)),
    h('ul', { class: 'dns-values' }, set.contents.map((content) => h('li', {},
      h('code', { class: 'dns-value' }, content),
      h('button', {
        type: 'button', class: 'btn btn-ghost btn-icon btn-sm', 'aria-label': 'Copiar conteúdo', 'data-tip': 'Copiar',
        on: { click: () => copyText(content, 'Conteúdo copiado.') },
      }, icon('copy'))))),
    editBox);
}

/**
 * Sem `set`, cria um conjunto novo. Com `set`, substitui o conjunto nome + tipo existente:
 * nome e tipo ficam travados porque mudá-los criaria outro conjunto sem apagar o atual.
 */
function recordForm(body, ctx, set = null) {
  const editing = set !== null;
  const type = h('select', { class: 'input', 'aria-label': 'Tipo', disabled: editing },
    TYPES.map((t) => h('option', { value: t, selected: editing && set.type === t }, t)));
  const name = h('input', { class: 'input', type: 'text', autocomplete: 'off', spellcheck: 'false', placeholder: '@ ou subdomínio', value: editing ? set.name : '', disabled: editing });
  const ttl = h('input', { class: 'input', type: 'number', min: '60', max: '86400', required: true, value: String(editing ? set.ttl : DEFAULT_TTL) });
  const contents = h('textarea', { class: 'input', rows: '3', spellcheck: 'false', placeholder: 'Um conteúdo por linha' }, editing ? set.contents.join('\n') : '');
  const hint = h('p', { class: 'hint' }, HINTS[type.value]);
  type.addEventListener('change', () => { clear(hint).append(HINTS[type.value]); });
  const status = h('p', { class: 'hint hint-error', role: 'alert' });
  const submit = h('button', { type: 'submit', class: 'btn btn-primary' }, icon(editing ? 'check' : 'plus'), editing ? 'Salvar registro' : 'Criar registro');

  const form = h('form', { class: 'stack' },
    editing ? null : h('h3', {}, 'Novo registro'),
    h('div', { class: 'grid' },
      h('div', {}, h('label', { class: 'label' }, 'Tipo'), type),
      h('div', {}, h('label', { class: 'label' }, 'Nome'), name),
      h('div', {}, h('label', { class: 'label' }, 'TTL (s)'), ttl)),
    h('div', {}, h('label', { class: 'label' }, 'Conteúdo'), contents, hint),
    h('div', { class: 'row' }, submit, status));

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    submit.disabled = true;
    submit.setAttribute('aria-busy', 'true');
    status.textContent = '';
    try {
      const zone = await post('dns-records', {
        orderId: ctx.orderId,
        domain: ctx.domain,
        action: 'save',
        name: name.value.trim() || '@',
        type: type.value,
        ttl: Number(ttl.value),
        contents: contents.value.split('\n').map((c) => c.trim()).filter(Boolean),
        overwrite: editing,
      });
      toast(editing ? 'Registro atualizado. A propagação pode levar alguns minutos.' : 'Registro criado. A propagação pode levar alguns minutos.', 'ok');
      render(body, ctx, zone);
    } catch (err) {
      status.textContent = err.message;
      submit.disabled = false;
    } finally {
      submit.removeAttribute('aria-busy');
    }
  });

  return form;
}
