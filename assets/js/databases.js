import { get, post, clearCache } from './api.js';
import { h, icon, clear } from './h.js';
import { errorBlock, emptyBlock, skeletonLines } from './states.js';
import { toast } from './toast.js';
import { openModal, confirmDialog } from './modal.js';
import { badge, iconButton, openExternal, copyText, section } from './components.js';
import { formatDate, formatMb } from './format.js';
import { suffixError, passwordChecks, passwordOk, generatePassword, isRemoteIp, PRIVILEGES, SUFFIX_MAX } from './db-rules.js';

const SEARCH_FROM = 8;

/**
 * Lista de bancos com criação e ações por banco.
 * ctx: { orderId, sites: [{domain, vhostType}], domain? } — com `domain`, só os bancos vinculados
 * ao site e o "Criar banco" já vem com ele travado; sem, todos da conta com o site escolhido no modal.
 * onChange(databases) é chamado a cada carga (a aba usa para o formulário de MySQL remoto).
 */
export function mountDatabases(box, ctx, onChange = null) {
  const siteMode = Boolean(ctx.domain);
  let username = '';

  async function load() {
    clear(box).append(...skeletonLines(siteMode ? 2 : 4));
    try {
      const data = await get('databases', siteMode ? { orderId: ctx.orderId, domain: ctx.domain } : { orderId: ctx.orderId });
      username = data.username;
      render(data.databases);
      onChange?.(data.databases);
    } catch (err) {
      clear(box).append(errorBlock(err, load));
    }
  }

  const createButton = () => h('button', {
    type: 'button', class: siteMode ? 'btn btn-secondary btn-sm' : 'btn btn-primary',
    on: {
      click: async () => {
        const created = await openCreateModal({ orderId: ctx.orderId, username, sites: ctx.sites, domain: ctx.domain, lockDomain: siteMode });
        if (created) load();
      },
    },
  }, icon('plus'), 'Criar banco');

  function render(databases) {
    const list = h('ul', { class: 'db-list' });
    const rows = databases.map((db) => ({ db, row: dbRow(db) }));
    const draw = (query) => {
      const shown = rows.filter(({ db }) => !query || [db.name, db.user, db.domain || 'sem site'].some((v) => v.toLowerCase().includes(query)));
      clear(list).append(...(shown.length ? shown.map(({ row }) => row) : [emptyBlock('Nenhum banco encontrado.', 'Ajuste a busca.')]));
    };
    const empty = siteMode
      ? emptyBlock('Nenhum banco vinculado a este site.', 'Crie um aqui ou vincule um banco existente pela aba Bancos.')
      : emptyBlock('Nenhum banco de dados nesta conta.', 'Use "Criar banco" para criar o primeiro.');

    if (siteMode) {
      clear(box).append(databases.length ? list : empty, h('div', { class: 'db-foot' }, createButton()));
    } else {
      const search = h('input', { type: 'search', placeholder: 'Buscar banco, usuário ou site', 'aria-label': 'Buscar banco', autocomplete: 'off' });
      search.addEventListener('input', () => draw(search.value.trim().toLowerCase()));
      clear(box).append(
        h('div', { class: 'toolbar' },
          databases.length >= SEARCH_FROM ? h('label', { class: 'search-box' }, icon('search'), search) : null,
          createButton()),
        databases.length ? list : empty);
    }
    if (databases.length) draw('');
  }

  async function act(task, okMessage) {
    try {
      await task();
      toast(okMessage, 'ok');
      clearCache();
      load();
    } catch (err) {
      toast(err.message, 'danger');
    }
  }

  function dbRow(db) {
    const params = { orderId: ctx.orderId, db: db.name };
    const limited = db.permissions.length < PRIVILEGES.length;
    return h('li', { class: 'db' },
      icon('database'),
      h('div', { class: 'db-id' },
        h('div', { class: 'row' },
          h('strong', {}, db.name),
          siteMode ? null : badge(db.domain || 'Sem site', db.domain ? 'info' : 'warn'),
          limited ? badge(db.permissions.length ? `${db.permissions.length}/${PRIVILEGES.length} permissões` : 'Sem permissões', 'warn') : null),
        h('span', { class: 'muted small' }, [
          `Usuário ${db.user || '—'}`,
          `${formatMb(db.diskUsageMb || 0)}${db.maxSizeMb ? ` de ${formatMb(db.maxSizeMb)}` : ''}`,
          db.createdAt ? `criado ${formatDate(db.createdAt)}` : null,
        ].filter(Boolean).join(' · '))),
      h('div', { class: 'db-actions' },
        h('button', {
          type: 'button', class: 'btn btn-secondary btn-sm',
          on: { click: () => openExternal('phpmyadmin', ctx.domain ? { ...params, domain: ctx.domain } : params) },
        }, 'phpMyAdmin', icon('external')),
        iconButton('key', 'Trocar senha', async () => {
          if (await openPasswordModal(ctx.orderId, db)) toast(`Senha de ${db.user} alterada.`, 'ok');
        }),
        iconButton('settings', 'Permissões', async () => {
          if (await openPermissionsModal(ctx.orderId, db)) { clearCache(); load(); }
        }),
        iconButton('globe', 'Site vinculado', async () => {
          if (await openSiteModal(ctx.orderId, db, ctx.sites)) { clearCache(); load(); }
        }),
        iconButton('refresh', 'Reparar', async () => {
          const ok = await confirmDialog(`Reparar ${db.name}?`, 'A Hostinger verifica e corrige tabelas corrompidas. Em bancos grandes pode levar alguns minutos.', 'Reparar');
          if (ok) act(() => post('databases', { ...params, action: 'repair' }), `Reparo de ${db.name} solicitado.`);
        }),
        iconButton('trash', 'Excluir', async () => {
          const ok = await confirmDialog(
            `Excluir ${db.name}?`,
            `O banco, todas as tabelas e o usuário ${db.user} são apagados de vez. Faça backup antes: não há como desfazer.`,
            'Excluir',
            'danger',
          );
          if (ok) act(() => post('databases', { ...params, action: 'delete' }), `Banco ${db.name} excluído.`);
        })));
  }

  load();
  return { reload: load };
}

/* ===== Modais ===== */

/** Formulário dentro do modal: botões próprios (Cancelar/Salvar), erro e envio com `busy`. */
function modalForm(fields, submitLabel, onSubmit, close) {
  const status = h('p', { class: 'hint hint-error', role: 'alert' });
  const submit = h('button', { type: 'submit', class: 'btn btn-primary' }, icon('check'), submitLabel);
  const form = h('form', { class: 'stack', novalidate: true },
    fields,
    status,
    h('div', { class: 'modal-actions' },
      h('button', { type: 'button', class: 'btn btn-secondary', on: { click: () => close(null) } }, 'Cancelar'),
      submit));
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    status.textContent = '';
    submit.disabled = true;
    submit.setAttribute('aria-busy', 'true');
    try {
      const result = await onSubmit();
      if (result !== undefined) close(result);
    } catch (err) {
      status.textContent = err.message;
    } finally {
      submit.disabled = false;
      submit.removeAttribute('aria-busy');
    }
  });
  form.fail = (message) => { status.textContent = message; };
  return form;
}

const field = (label, control, hint = null) => h('div', {}, h('label', {}, h('span', { class: 'label' }, label), control), hint);

function siteSelect(sites, selected, disabled = false) {
  const order = { main: 0, addon: 1 };
  const sorted = [...sites].sort((a, b) => (order[a.vhostType] ?? 2) - (order[b.vhostType] ?? 2) || a.domain.localeCompare(b.domain));
  return h('select', { class: 'input', disabled },
    sorted.map((s) => h('option', { value: s.domain, selected: s.domain === selected }, s.domain)));
}

function prefixedInput(prefix, placeholder) {
  const input = h('input', {
    class: 'input', type: 'text', maxlength: String(SUFFIX_MAX), autocomplete: 'off', spellcheck: 'false', placeholder,
  });
  return { input, el: h('div', { class: 'input-prefix' }, h('span', { class: 'input-prefix-text mono' }, prefix), input) };
}

/** Senha com mostrar/ocultar, gerar e copiar, e os requisitos marcados ao digitar. */
function passwordField() {
  const input = h('input', { class: 'input', type: 'password', autocomplete: 'new-password', spellcheck: 'false', placeholder: 'Senha do usuário MySQL' });
  const checks = h('ul', { class: 'pw-checks' });
  const update = () => {
    clear(checks).append(...passwordChecks(input.value).map((c) =>
      h('li', { class: c.ok ? 'pw-ok' : null }, icon(c.ok ? 'check' : 'x'), c.text)));
  };
  const toggle = iconButton('eye', 'Mostrar senha', () => {
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    toggle.setAttribute('aria-pressed', String(show));
  });
  toggle.setAttribute('aria-pressed', 'false');
  input.addEventListener('input', update);
  update();
  const el = h('div', {},
    h('div', { class: 'input-group' },
      input,
      toggle,
      iconButton('refresh', 'Gerar senha forte', () => {
        input.value = generatePassword();
        input.type = 'text';
        toggle.setAttribute('aria-pressed', 'true');
        update();
      }),
      iconButton('copy', 'Copiar senha', () => { if (input.value) copyText(input.value, 'Senha copiada.'); })),
    checks);
  return { input, el };
}

/** Cria banco + usuário. Resolve com `{name, user, domain}` ou null se cancelado. */
export function openCreateModal({ orderId, username, sites, domain = null, lockDomain = false }) {
  const prefix = `${username}_`;
  const main = sites.find((s) => s.vhostType === 'main')?.domain ?? sites[0]?.domain ?? '';
  const site = siteSelect(sites, domain || main, lockDomain);
  const name = prefixedInput(prefix, 'nome');
  const user = prefixedInput(prefix, 'usuário');
  const password = passwordField();
  // O usuário acompanha o nome do banco até ser editado à mão.
  let userTouched = false;
  user.input.addEventListener('input', () => { userTouched = true; });
  name.input.addEventListener('input', () => { if (!userTouched) user.input.value = name.input.value; });

  const content = h('div', { class: 'stack' });
  return openModal({
    title: 'Criar banco de dados',
    content,
    onOpen: (close) => {
      const form = modalForm([
        field('Site vinculado', site, lockDomain ? null : h('p', { class: 'hint' }, 'O banco aparece na lista de bancos deste site.')),
        field('Nome do banco', name.el),
        field('Usuário MySQL', user.el, h('p', { class: 'hint' }, `Até ${SUFFIX_MAX} letras, números ou _. O usuário recebe todas as permissões no banco.`)),
        field('Senha', password.el),
      ], 'Criar banco', async () => {
        const problem = (label, v) => { const e = suffixError(v); return e ? `${label}: ${e}` : null; };
        const error = problem('Nome do banco', name.input.value) || problem('Usuário', user.input.value)
          || (passwordOk(password.input.value) ? null : 'A senha não atende aos requisitos.');
        if (error) { form.fail(error); return undefined; }
        const { database } = await post('databases', {
          orderId, action: 'create', domain: site.value, name: name.input.value, user: user.input.value, password: password.input.value,
        });
        clearCache();
        toast(`Banco ${database.name} criado e vinculado a ${database.domain}.`, 'ok');
        return database;
      }, close);
      content.append(form);
      name.input.focus();
    },
  });
}

function openPasswordModal(orderId, db) {
  const password = passwordField();
  const content = h('div', { class: 'stack' });
  return openModal({
    title: 'Trocar senha',
    content,
    onOpen: (close) => {
      const form = modalForm([
        h('p', {}, 'Nova senha do usuário ', h('strong', { class: 'mono' }, db.user), '. Atualize a configuração dos sites que usam este banco.'),
        field('Nova senha', password.el),
      ], 'Trocar senha', async () => {
        if (!passwordOk(password.input.value)) { form.fail('A senha não atende aos requisitos.'); return undefined; }
        await post('databases', { orderId, db: db.name, action: 'password', password: password.input.value });
        return true;
      }, close);
      content.append(form);
      password.input.focus();
    },
  });
}

function openPermissionsModal(orderId, db) {
  const boxes = PRIVILEGES.map((p) => h('input', { type: 'checkbox', value: p, checked: db.permissions.includes(p) }));
  const setAll = (on) => boxes.forEach((b) => { b.checked = on; });
  const content = h('div', { class: 'stack' });
  return openModal({
    title: `Permissões · ${db.user}`,
    content,
    wide: true,
    onOpen: (close) => {
      const form = modalForm([
        h('div', { class: 'row' },
          h('button', { type: 'button', class: 'btn btn-ghost btn-sm', on: { click: () => setAll(true) } }, 'Marcar todas'),
          h('button', { type: 'button', class: 'btn btn-ghost btn-sm', on: { click: () => setAll(false) } }, 'Desmarcar todas'),
          h('button', {
            type: 'button', class: 'btn btn-ghost btn-sm',
            on: { click: () => boxes.forEach((b) => { b.checked = ['Select', 'Show view', 'Lock tables'].includes(b.value); }) },
          }, 'Só leitura')),
        h('div', { class: 'check-grid' }, boxes.map((b) => h('label', { class: 'check mono' }, b, b.value))),
        h('p', { class: 'hint' }, 'Desmarcadas são revogadas. Sem nenhuma, o usuário não acessa o banco.'),
      ], 'Salvar permissões', async () => {
        await post('databases', { orderId, db: db.name, action: 'permissions', permissions: boxes.filter((b) => b.checked).map((b) => b.value) });
        toast(`Permissões de ${db.user} atualizadas.`, 'ok');
        return true;
      }, close);
      content.append(form);
    },
  });
}

function openSiteModal(orderId, db, sites) {
  const site = siteSelect(sites, db.domain);
  const content = h('div', { class: 'stack' });
  return openModal({
    title: `Site vinculado · ${db.name}`,
    content,
    onOpen: (close) => {
      const form = modalForm([
        db.domain ? null : h('p', {}, 'Este banco não está vinculado a nenhum site.'),
        field('Site', site, h('p', { class: 'hint' }, 'Só organiza a lista: o banco e as credenciais continuam os mesmos.')),
      ], 'Vincular', async () => {
        if (site.value === db.domain) return true;
        await post('databases', { orderId, db: db.name, action: 'site', site: site.value });
        toast(`${db.name} vinculado a ${site.value}.`, 'ok');
        return true;
      }, close);
      content.append(form);
      site.focus();
    },
  });
}

/* ===== MySQL remoto ===== */

/**
 * IPs liberados para conectar de fora da Hostinger. `setDatabases(lista)` informa os bancos
 * da conta (opções do formulário) e redesenha quando a lista muda.
 */
export function remoteMysqlSection({ orderId, dbHost }) {
  const body = h('div', { class: 'stack' }, ...skeletonLines(2));
  let databases = [];
  let connections = null;

  async function load() {
    clear(body).append(...skeletonLines(2));
    try {
      ({ connections } = await get('remote-mysql', { orderId }));
      render();
    } catch (err) {
      clear(body).append(errorBlock(err, load));
    }
  }

  function render() {
    if (connections === null) return;
    const list = connections.length
      ? h('ul', { class: 'db-list' }, connections.map((c) => h('li', { class: 'db' },
        icon('globe'),
        h('div', { class: 'db-id' },
          h('strong', { class: 'mono' }, c.ip === '%' ? 'Qualquer host (%)' : c.ip),
          h('span', { class: 'muted small' }, `${c.db} · usuário ${c.user || '—'}`)),
        h('div', { class: 'db-actions' }, iconButton('trash', 'Remover acesso', async () => {
          const ok = await confirmDialog('Remover acesso remoto?', `${c.ip === '%' ? 'Qualquer host' : c.ip} deixa de conectar em ${c.db}.`, 'Remover', 'danger');
          if (!ok) return;
          try {
            ({ connections } = await post('remote-mysql', { orderId, action: 'delete', db: c.db, ip: c.ip }));
            toast('Acesso remoto removido.', 'ok');
            render();
          } catch (err) {
            toast(err.message, 'danger');
          }
        })))))
      : emptyBlock('Nenhum IP liberado.', 'Sem liberação, o MySQL só aceita conexões dos próprios sites da Hostinger.');
    clear(body).append(list, h('div', { class: 'repo-sep' }), addForm());
  }

  function addForm() {
    const db = h('select', { class: 'input', disabled: !databases.length },
      databases.map((d) => h('option', { value: d.name }, d.name)));
    const ip = h('input', { class: 'input', type: 'text', autocomplete: 'off', spellcheck: 'false', placeholder: '203.0.113.10' });
    const any = h('input', { type: 'checkbox' });
    any.addEventListener('change', () => { ip.disabled = any.checked; });
    const status = h('p', { class: 'hint hint-error', role: 'alert' });
    const submit = h('button', { type: 'submit', class: 'btn btn-primary', disabled: !databases.length }, icon('plus'), 'Liberar acesso');
    const form = h('form', { class: 'stack', novalidate: true },
      h('h3', {}, 'Liberar IP'),
      h('div', { class: 'grid' }, field('Banco', db), field('IP (IPv4 ou IPv6)', ip)),
      h('label', { class: 'check' }, any, 'Qualquer host (%)'),
      h('p', { class: 'hint' }, `Conecte em ${dbHost || 'host do banco (Visão geral)'}, porta 3306. Prefira um IP fixo: "Qualquer host" deixa o banco aberto para a internet, protegido só pela senha.`),
      h('div', { class: 'row' }, submit, status));
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const value = any.checked ? '%' : ip.value.trim();
      status.textContent = '';
      if (!isRemoteIp(value)) { status.textContent = 'Informe um IPv4 ou IPv6 válido, ou marque "Qualquer host".'; return; }
      submit.disabled = true;
      try {
        ({ connections } = await post('remote-mysql', { orderId, action: 'add', db: db.value, ip: value }));
        toast('Acesso remoto liberado.', 'ok');
        render();
      } catch (err) {
        status.textContent = err.message;
        submit.disabled = false;
      }
    });
    return form;
  }

  load();
  return {
    el: section('MySQL remoto', body),
    setDatabases(list) { databases = list; render(); },
  };
}
