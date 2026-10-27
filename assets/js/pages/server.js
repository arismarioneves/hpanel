import '../app.js';
import { cached, clearCache, get, post } from '../api.js';
import { sameData } from '../swr.js';
import { h, icon, $, clear, boot } from '../h.js';
import { errorBlock, emptyBlock, skeletonLines } from '../states.js';
import { toast } from '../toast.js';
import { confirmDialog } from '../modal.js';
import { meter, badge, section, fact, iconButton, openExternal, copyText } from '../components.js';
import { formatDate, formatMb, formatNumber, level, percent, relTime } from '../format.js';
import { areaChart, donut } from '../chart.js';
import { groupSites } from '../sites.js';
import { openGitModal } from '../git.js';
import { openDnsModal } from '../dns.js';
import { renderCron } from '../cron.js';
import * as favs from '../favorites.js';

const { orderId } = boot();
const TABS = ['visao', 'sites', 'bancos', 'cron', 'ferramentas'];
const rendered = new Set();
let detail = null;
let loadError = null;
let phpDomain = null;
let phpControls = null;

const VHOST = { main: 'Principal', addon: 'Addon', subdomain: 'Subdomínio' };
const FILTERS = [
  ['all', 'Todos', () => true],
  ['wordpress', 'WordPress', (s) => s.type === 'wordpress'],
  ['other', 'Outros', (s) => s.type !== 'wordpress'],
  ['main', 'Principal', (s) => s.vhostType === 'main'],
  ['addon', 'Addon', (s) => s.vhostType === 'addon'],
  ['subdomain', 'Subdomínio', (s) => s.vhostType === 'subdomain'],
];

const currentTab = () => (TABS.includes(location.hash.slice(1)) ? location.hash.slice(1) : 'visao');
const panel = (tab) => $(`#panel-${tab}`);

function highlightTab() {
  const tab = currentTab();
  for (const t of TABS) {
    $(`[data-tab="${t}"]`).setAttribute('aria-selected', String(t === tab));
    panel(t).hidden = t !== tab;
  }
  $(`[data-tab="${tab}"]`).scrollIntoView({ block: 'nearest', inline: 'nearest' });
  return tab;
}

function showTab() {
  const tab = highlightTab();
  if (detail && !rendered.has(tab)) {
    rendered.add(tab);
    RENDER[tab](clear(panel(tab)));
  } else if (!detail && loadError) {
    clear(panel(tab)).append(errorBlock(loadError, () => init()));
  }
}

async function init(refresh = false) {
  const tab = highlightTab();
  const p = panel(tab);
  loadError = null;
  refreshExtras = refresh;
  clear(p).append(...skeletonLines(6));
  let shown = null;
  try {
    await cached('account', { orderId }, (data) => {
      const same = shown && sameData(shown, data);
      shown = data;
      detail = data;
      if (same) { renderHead(); return; } // só o carimbo mudou: atualiza "Dados há X", sem redesenhar a aba
      rendered.clear();
      TABS.forEach((t) => clear(panel(t)));
      renderHead();
      showTab();
    }, { refresh });
  } catch (err) {
    if (shown) return;
    loadError = err;
    clear(p).append(errorBlock(err, () => init(refresh)));
    if (detail) rendered.delete(tab);
    else $('#serverTitle').textContent = 'Servidor';
  }
}

function renderHead() {
  const { server, account } = detail;
  document.title = `${server.title || 'Servidor'} · HPanel`;
  $('#serverTitle').textContent = server.title || 'Servidor';
  const meta = $('#serverMeta');
  clear(meta).append([server.planDisplayableName || server.planName, server.datacenter?.title].filter(Boolean).join(' · '));
  if (account?.ip) {
    meta.append(' · IP ', h('button', {
      type: 'button', class: 'btn btn-ghost btn-sm mono', 'data-tip': 'Copiar IP',
      on: { click: () => copyText(account.ip, 'IP copiado.') },
    }, account.ip, icon('copy')));
  }
  clear($('#serverActions')).append(
    detail.cachedAt ? h('span', { class: 'muted small' }, `Dados ${relTime(detail.cachedAt)}`) : null,
    h('button', {
      type: 'button', class: 'btn btn-secondary btn-sm', 'data-tip': 'Raiz da conta, com todos os sites',
      on: { click: () => openExternal('file-browser', { orderId }) },
    }, icon('folder'), 'Gerenciador de Arquivos'),
    h('button', { type: 'button', class: 'btn btn-secondary btn-sm', on: { click: () => init(true) } }, icon('refresh'), 'Atualizar'));
}

/* ===== Visão geral ===== */
const RANGES = [['1h', '1 h'], ['6h', '6 h'], ['24h', '24 h'], ['7d', '7 dias'], ['30d', '30 dias']];
const RANGE_KEY = 'hp_metrics_range';
const savedRange = () => {
  try { const r = localStorage.getItem(RANGE_KEY); return RANGES.some(([k]) => k === r) ? r : '24h'; } catch { return '24h'; }
};
let metricsRange = savedRange();
let refreshExtras = false;

const num = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 });
const METRICS = [
  ['cpu', 'CPU', (v) => `${num.format(v)} %`],
  ['memory', 'Memória', (v) => formatMb(v)],
  ['ep', 'PHP workers', (v) => num.format(v)],
  ['nproc', 'Processos', (v) => num.format(v)],
  ['io', 'Disco I/O', (v) => (v >= 1024 ? `${num.format(v / 1024)} MB/s` : `${num.format(v)} KB/s`)],
  ['iops', 'IOPS', (v) => num.format(v)],
];
const METRIC_HINT = {
  cpu: 'Uso de CPU da conta (100 % = todos os núcleos do plano).',
  memory: 'RAM usada pelos processos da conta.',
  ep: 'Requisições PHP simultâneas (entry processes). No limite, visitantes recebem erro 503/508.',
  nproc: 'Processos ativos da conta (PHP, cron, SSH).',
  io: 'Velocidade de leitura/escrita em disco.',
  iops: 'Operações de disco por segundo.',
};

function renderVisao(p) {
  const a = detail.account;
  const malware = statusChip('Antimalware', null, 'muted', 'Carregando…');
  const cron = cronChip(null);
  const status = h('div', { class: 'status-strip' }, malware, cron);
  const resources = h('div', { class: 'chart-grid' }, Array.from({ length: 6 }, () => h('div', { class: 'skeleton skeleton-chart' })));

  p.append(
    status,
    a ? storageSection(a) : emptyBlock('Detalhes da conta indisponíveis agora.', 'A Hostinger não retornou os dados de uso. Use "Atualizar" para tentar de novo — as outras abas funcionam normalmente.'),
    section('Recursos', resources, rangePicker(resources)),
    a ? infoSection(a) : null);

  if (a) {
    const backup = Number(a.backup_interval || 0);
    status.append(
      statusChip('Backup', null, backup > 0 ? 'ok' : 'warn', backup === 1 ? 'Diário' : backup > 0 ? `A cada ${backup} dias` : 'Indisponível'),
      statusChip('SSH', null, a.shell_enabled ? 'ok' : 'muted', a.shell_enabled ? 'Habilitado' : 'Desabilitado'),
      a.web_server ? statusChip('Web server', null, 'muted', a.web_server) : null,
      a.database_version ? statusChip('Banco', null, 'muted', a.database_version) : null);
  }
  loadMetrics(resources);
  loadMalware(malware);
  loadCronCount();
  refreshExtras = false;
}

function statusChip(label, tip, tone, value) {
  return h('span', { class: `status-chip status-${tone}`, 'data-tip': tip || null },
    h('span', { class: `dot dot-${tone === 'muted' ? 'off' : tone}` }), h('span', { class: 'muted' }, label), h('strong', {}, value));
}

function storageSection(a) {
  const u = a.usage || {};
  const big = [['storage', 'Disco', formatMb], ['inodes', 'Inodes (arquivos)', formatNumber]]
    .filter(([k]) => u[k]?.limit > 0)
    .map(([k, label, fmt]) => {
      const pct = percent(u[k].value, u[k].limit);
      const tone = level(pct);
      return h('div', { class: `storage-card meter-${tone}` },
        donut(pct, tone),
        h('div', { class: 'storage-text' },
          h('span', { class: 'muted small' }, label),
          h('strong', { class: 'storage-pct' }, `${num.format(pct)}%`),
          h('span', { class: 'small' }, `${fmt(u[k].value)} de ${fmt(u[k].limit)}`,
            h('span', { class: 'muted' }, ` · ${fmt(Math.max(0, u[k].limit - u[k].value))} livres`))));
    });
  const small = [['databases', 'Bancos de dados'], ['subdomains', 'Subdomínios']]
    .filter(([k]) => u[k]?.limit > 0)
    .map(([k, label]) => meter(`${label} · ${formatNumber(u[k].value)} de ${formatNumber(u[k].limit)}`, u[k].value, u[k].limit, 'n', true));
  if (!big.length && !small.length) return section('Armazenamento', emptyBlock('Sem dados de uso.'));
  return section('Armazenamento', h('div', { class: 'storage' }, big, small.length ? h('div', { class: 'storage-small' }, small) : null));
}

function infoSection(a) {
  const lim = a.plan_limits || {};
  return section('Informações', h('dl', { class: 'facts facts-compact' },
    fact('Hostname', a.server?.hostname),
    fact('Usuário', a.username),
    fact('Host do banco', a.server?.database?.hostname),
    fact('IP do banco', a.server?.database?.ip),
    fact('CPU', lim.cpu_cores != null ? `${lim.cpu_cores} núcleos` : null),
    fact('RAM', lim.ram ? formatMb(lim.ram / 1024) : null),
    fact('Criado em', formatDate(a.created_at))));
}

function rangePicker(target) {
  const buttons = RANGES.map(([key, label]) => h('button', {
    type: 'button', class: 'chip-btn chip-sm', 'aria-pressed': String(key === metricsRange),
    on: {
      click: () => {
        if (key === metricsRange) return;
        metricsRange = key;
        try { localStorage.setItem(RANGE_KEY, key); } catch { /* sem armazenamento */ }
        buttons.forEach((b, i) => b.setAttribute('aria-pressed', String(RANGES[i][0] === key)));
        loadMetrics(target);
      },
    },
  }, label));
  return h('div', { class: 'chips', role: 'group', 'aria-label': 'Período' }, buttons);
}

async function loadMetrics(target) {
  const range = metricsRange;
  target.setAttribute('aria-busy', 'true');
  try {
    await cached('metrics', { orderId, range }, (data) => {
      if (range !== metricsRange || !target.isConnected) return;
      clear(target).append(...metricCards(data.series || {}, range));
    }, { maxAgeMs: 60000, refresh: refreshExtras });
  } catch (err) {
    if (range === metricsRange && target.isConnected) clear(target).append(errorBlock(err, () => loadMetrics(target)));
  } finally {
    target.removeAttribute('aria-busy');
  }
}

function metricCards(series, range) {
  const long = range === '7d' || range === '30d';
  const formatTime = (t) => new Date(t * 1000).toLocaleString('pt-BR', long
    ? { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }
    : { hour: '2-digit', minute: '2-digit' });
  const cards = METRICS.filter(([k]) => series[k]).map(([k, label, format]) => {
    const { limit, points } = series[k];
    const values = points.map((pt) => pt[1]);
    const now = values.at(-1) ?? 0;
    const avg = values.length ? values.reduce((s, v) => s + v, 0) / values.length : 0;
    const peak = Math.max(0, ...values);
    const faults = points.reduce((s, pt) => s + pt[2], 0);
    const tone = level(percent(peak, limit));
    return h('article', { class: `chart-card chart-${faults > 0 ? 'danger' : tone}` },
      h('header', { class: 'chart-head' },
        h('span', { 'data-tip': METRIC_HINT[k] }, label),
        faults > 0 ? badge(`${faults}× no limite`, 'danger') : null),
      h('div', { class: 'chart-now' }, h('strong', {}, format(now)), h('span', { class: 'muted small' }, limit > 0 ? `de ${format(limit)}` : '')),
      areaChart(points, { limit, format, formatTime, label: `${label}: ${format(now)} agora, pico ${format(peak)}` }),
      h('footer', { class: 'chart-foot' },
        h('span', {}, 'Média ', h('strong', {}, format(avg))),
        h('span', {}, 'Pico ', h('strong', {}, format(peak)))));
  });
  return cards.length ? cards : [emptyBlock('Sem métricas para este período.')];
}

async function loadMalware(chip) {
  let current = chip;
  const swap = (next) => { current.replaceWith(next); current = next; };
  try {
    await cached('malware', { orderId }, (data) => {
      if (current.isConnected) swap(malwareChip(data.malware));
    }, { maxAgeMs: 600000, refresh: refreshExtras });
  } catch {
    if (current.isConnected) swap(statusChip('Antimalware', null, 'muted', 'Indisponível'));
  }
}

function malwareChip(m) {
  const infected = m.compromised + m.malicious;
  const last = m.lastScanEnd ? `Último scan: ${new Date(m.lastScanEnd).toLocaleString('pt-BR')}` : null;
  const scanning = m.scanStatus === 'running' ? ' · verificando' : '';
  if (infected > 0) return statusChip('Antimalware', last, 'danger', `${infected} arquivo${infected > 1 ? 's' : ''} suspeito${infected > 1 ? 's' : ''}`);
  if (!m.protection) return statusChip('Antimalware', last, 'warn', 'Proteção desativada');
  return statusChip('Antimalware', last, 'ok', `Protegido${scanning}`);
}

/* Chip "Cron jobs" da visão geral: mostra a contagem e leva à aba de gestão. */
function cronChip(count) {
  return h('button', {
    type: 'button', class: 'status-chip status-link', 'data-tip': 'Gerenciar tarefas cron', 'data-cron-chip': '',
    on: { click: () => { location.hash = 'cron'; } },
  }, icon('clock'), h('span', { class: 'muted' }, 'Cron jobs'), h('strong', {}, count == null ? '…' : String(count)));
}

function updateCronChip(jobs) {
  document.querySelector('[data-cron-chip]')?.replaceWith(cronChip(jobs.length));
}

async function loadCronCount() {
  try {
    await cached('cron-jobs', { orderId }, (data) => updateCronChip(data.jobs), { maxAgeMs: 60000, refresh: refreshExtras });
  } catch {
    document.querySelector('[data-cron-chip] strong')?.replaceChildren('—');
  }
}

/* ===== Sites ===== */
function renderSites(p) {
  const all = detail.server.websites || [];
  let filter = 'all';
  let query = '';
  const list = h('div', { class: 'site-list' });

  const chips = h('div', { class: 'chips', role: 'group', 'aria-label': 'Filtrar sites' },
    FILTERS.map(([key, label, fn]) => h('button', {
      type: 'button', class: 'chip-btn', 'aria-pressed': String(key === filter), dataset: { filter: key },
    }, label, h('span', { class: 'count' }, String(all.filter(fn).length)))));
  chips.addEventListener('click', (e) => {
    const b = e.target.closest('[data-filter]');
    if (!b) return;
    filter = b.dataset.filter;
    chips.querySelectorAll('[data-filter]').forEach((x) => x.setAttribute('aria-pressed', String(x === b)));
    draw();
  });

  const input = h('input', { type: 'search', placeholder: 'Buscar domínio', 'aria-label': 'Buscar domínio', autocomplete: 'off' });
  input.addEventListener('input', () => { query = input.value.trim().toLowerCase(); draw(); });

  function draw() {
    const fn = FILTERS.find((f) => f[0] === filter)[2];
    const shown = all.filter((s) => fn(s) && (!query || s.domain.toLowerCase().includes(query)));
    clear(list);
    if (!shown.length) {
      list.append(emptyBlock('Nenhum site encontrado.', 'Ajuste a busca ou o filtro.'));
      return;
    }
    for (const g of groupSites(shown)) list.append(siteRow(g.site, g.children));
  }

  p.append(h('div', { class: 'toolbar' }, h('label', { class: 'search-box' }, icon('search'), input), chips), list);
  draw();
}

function siteRow(site, children = [], isChild = false) {
  const dbs = h('div', { class: 'site-dbs', hidden: true });
  const dbButton = iconButton('database', 'Bancos de dados', () => toggleDbs(dbButton, dbs, site.domain));
  dbButton.setAttribute('aria-expanded', 'false');

  return h('article', { class: 'site' },
    h('div', { class: 'site-main' },
      h('div', {},
        h('a', { class: 'site-domain', href: `https://${site.domain}`, target: '_blank', rel: 'noopener noreferrer' }, site.domain),
        h('div', { class: 'site-meta' }, `Criado ${formatDate(site.createdAt)} · Atualizado ${formatDate(site.updatedAt)}`)),
      h('div', { class: 'row badges' },
        badge(site.type === 'wordpress' ? 'WordPress' : 'Outro', site.type === 'wordpress' ? 'info' : ''),
        badge(VHOST[site.vhostType] || site.vhostType || '—'),
        badge(site.status === 'enabled' ? 'Ativo' : 'Inativo', site.status === 'enabled' ? 'ok' : 'danger')),
      h('div', { class: 'site-actions' },
        starButton(site.domain),
        iconButton('folder', 'Gerenciador de arquivos', () => openExternal('file-browser', { orderId, domain: site.domain })),
        dbButton,
        isChild ? null : iconButton('git', 'Git e auto deploy', () => openGitModal({ orderId, domain: site.domain })),
        isChild ? null : iconButton('globe', 'Registros DNS', () => openDnsModal({ orderId, domain: site.domain })),
        iconButton('code', 'Versão PHP', () => { phpDomain = site.domain; location.hash = 'ferramentas'; phpControls?.select(site.domain); }),
        h('a', {
          class: 'btn btn-ghost btn-icon btn-sm', href: `https://hpanel.hostinger.com/websites/${encodeURIComponent(site.domain)}`,
          target: '_blank', rel: 'noopener noreferrer', 'aria-label': 'Abrir no hPanel', 'data-tip': 'Abrir no hPanel',
        }, icon('external')))),
    dbs,
    children.length ? h('div', { class: 'site-children' }, children.map((c) => siteRow(c, [], true))) : null);
}

const favTip = (on) => (on ? 'Remover dos favoritos' : 'Adicionar aos favoritos');

function starButton(domain) {
  const on = favs.has(domain);
  return h('button', {
    type: 'button', class: 'btn btn-ghost btn-icon btn-sm fav-btn', 'aria-label': `Favorito: ${domain}`, 'aria-pressed': String(on),
    'data-tip': favTip(on), dataset: { fav: domain },
    on: {
      click: () => {
        const was = favs.has(domain);
        const now = favs.toggle({ domain, orderId, serverTitle: detail.server.title || 'Servidor' });
        if (!was && !now) toast(`Limite de ${favs.MAX_FAVS} favoritos atingido. Remova algum para adicionar outro.`, 'warn');
      },
    },
  }, icon('star'));
}

/** Mantém as estrelas em dia quando os favoritos mudam (nesta aba, no dashboard de outra aba etc.). */
favs.subscribe(() => {
  for (const b of document.querySelectorAll('[data-fav]')) {
    const on = favs.has(b.dataset.fav);
    b.setAttribute('aria-pressed', String(on));
    b.dataset.tip = favTip(on);
  }
});

async function toggleDbs(button, box, domain) {
  const opening = box.hidden;
  box.hidden = !opening;
  button.setAttribute('aria-expanded', String(opening));
  if (!opening || box.dataset.loaded) return;
  clear(box).append(...skeletonLines(2));
  try {
    const { databases } = await get('databases', { orderId, domain });
    box.dataset.loaded = '1';
    clear(box).append(dbList(databases, domain));
  } catch (err) {
    clear(box).append(errorBlock(err, () => { box.hidden = true; toggleDbs(button, box, domain); }));
  }
}

function dbList(databases, domain) {
  if (!databases.length) return emptyBlock('Nenhum banco de dados.', 'Crie bancos pelo hPanel; eles aparecem aqui.');
  return h('ul', { class: 'db-list' }, databases.map((db) => h('li', { class: 'db' },
    icon('database'),
    h('div', { class: 'db-id' }, h('strong', {}, db.name), h('span', { class: 'muted small' }, `${db.user || '—'} · ${formatMb(db.diskUsageMb || 0)}`)),
    h('button', {
      type: 'button', class: 'btn btn-secondary btn-sm',
      on: { click: () => openExternal('phpmyadmin', domain ? { orderId, domain, db: db.name } : { orderId, db: db.name }) },
    }, 'phpMyAdmin', icon('external')))));
}

/* ===== Bancos ===== */
async function renderBancos(p) {
  clear(p).append(...skeletonLines(4));
  try {
    const { databases } = await get('databases', { orderId });
    clear(p).append(section('Bancos de dados da conta', dbList(databases, null), detail.username));
  } catch (err) {
    clear(p).append(errorBlock(err, () => renderBancos(p)));
  }
}

/* ===== Ferramentas ===== */
function renderFerramentas(p) {
  p.append(phpCard(), h('div', { class: 'section' }), sshCard());
}

function phpCard() {
  const domains = (detail.server.websites || []).map((s) => s.domain).sort();
  const domainSel = h('select', { class: 'input', 'aria-label': 'Domínio' }, domains.map((d) => h('option', { value: d }, d)));
  const versionSel = h('select', { class: 'input', 'aria-label': 'Versão do PHP', disabled: true });
  const status = h('p', { class: 'muted small' });
  const save = h('button', { type: 'button', class: 'btn btn-primary', disabled: true }, 'Aplicar');
  if (phpDomain && domains.includes(phpDomain)) domainSel.value = phpDomain;

  async function load() {
    const domain = domainSel.value;
    domainSel.disabled = true;
    versionSel.disabled = true;
    save.disabled = true;
    clear(versionSel).append(h('option', {}, 'Carregando…'));
    status.textContent = '';
    try {
      const v = await get('php-version', { orderId, domain });
      if (domainSel.value !== domain) return;
      clear(versionSel).append(...v.versions.map((x) => h('option', { value: x.version }, x.version === v.current ? `PHP ${x.version} (atual)` : x.label)));
      if (v.current) versionSel.value = v.current;
      status.textContent = v.current ? `Em uso: PHP ${v.currentFull || v.current}` : '';
      versionSel.disabled = false;
      save.disabled = false;
    } catch (err) {
      if (domainSel.value !== domain) return;
      clear(versionSel).append(h('option', {}, 'Indisponível'));
      status.textContent = err.message;
    } finally {
      if (domainSel.value === domain) domainSel.disabled = false;
    }
  }

  save.addEventListener('click', async () => {
    const domain = domainSel.value;
    const version = versionSel.value;
    save.disabled = true;
    save.setAttribute('aria-busy', 'true');
    status.textContent = 'Aplicando… a Hostinger pode levar até 30 s para confirmar.';
    try {
      await post('set-php-version', { orderId, domain, version });
      clearCache();
      toast(`PHP ${version} ativado em ${domain}.`, 'ok');
      await load();
    } catch (err) {
      status.textContent = '';
      toast(err.message, 'danger');
      save.disabled = false;
    } finally {
      save.removeAttribute('aria-busy');
    }
  });
  domainSel.addEventListener('change', load);
  phpControls = { select: (d) => { if (domains.includes(d)) { domainSel.value = d; load(); } } };
  load();

  return section('Versão do PHP', h('div', { class: 'card stack' },
    h('div', { class: 'grid' }, h('div', {}, h('label', { class: 'label' }, 'Domínio'), domainSel), h('div', {}, h('label', { class: 'label' }, 'Versão'), versionSel)),
    h('div', { class: 'row' }, save, status)));
}

/** Cria/recria a chave SSH e invalida o cache da aba (os dados da conta podem ter mudado). */
async function mutateSshKey(action) {
  const res = await post('ssh-key', { orderId, action });
  clearCache();
  return res;
}

function sshCard() {
  const body = h('div', { class: 'card stack' }, ...skeletonLines(2));

  async function run(task) {
    clear(body).append(...skeletonLines(2));
    try {
      const { publicKey } = await task();
      show(publicKey);
    } catch (err) {
      clear(body).append(errorBlock(err, () => run(() => get('ssh-key', { orderId }))));
    }
  }

  function show(key) {
    if (!key) {
      clear(body).append(
        h('p', {}, 'Esta conta ainda não tem chave SSH para deploy via Git.'),
        h('p', { class: 'hint' }, 'Ao criar, o hPanel volta a exibir o deploy via SSH (em vez do GitHub App) para os sites desta conta.'),
        h('div', { class: 'row' }, h('button', { type: 'button', class: 'btn btn-primary', on: { click: () => run(() => mutateSshKey('create')) } }, icon('key'), 'Criar chave SSH')));
      return;
    }
    clear(body).append(
      h('pre', { class: 'code' }, key),
      h('div', { class: 'row' },
        h('button', { type: 'button', class: 'btn btn-secondary', on: { click: () => copyText(key, 'Chave copiada.') } }, icon('copy'), 'Copiar'),
        h('button', {
          type: 'button', class: 'btn btn-danger-ghost',
          on: {
            click: async () => {
              const ok = await confirmDialog('Recriar a chave SSH?', 'A chave atual deixa de funcionar. Depois, atualize-a no GitHub/GitLab onde ela estiver cadastrada.', 'Recriar', 'danger');
              if (ok) run(() => mutateSshKey('recreate'));
            },
          },
        }, icon('refresh'), 'Recriar')));
  }

  run(() => get('ssh-key', { orderId }));
  return section('Chave SSH (Git)', body, detail.username ? `Conta ${detail.username}` : null);
}

const RENDER = {
  visao: renderVisao,
  sites: renderSites,
  bancos: renderBancos,
  cron: (p) => renderCron(p, { orderId, refresh: refreshExtras, onJobs: updateCronChip }),
  ferramentas: renderFerramentas,
};

// <base href> faria "#aba" navegar para a raiz; troca o hash da URL atual.
document.querySelector('.tabs').addEventListener('click', (event) => {
  const link = event.target.closest('[data-tab]');
  if (!link) return;
  event.preventDefault();
  location.hash = link.dataset.tab;
});
window.addEventListener('hashchange', showTab);
init();
