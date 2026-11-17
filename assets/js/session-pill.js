import { get, post } from './api.js';
import { renewViaExtension } from './extension.js';
import { h, icon, clear } from './h.js';
import { pillState, WARN_MINUTES } from './session-status.js';
import { toast } from './toast.js';

const POLL_MS = 5 * 60 * 1000;
const slot = document.querySelector('[data-session-pill]');

let session = null;
let lastFetch = 0;
let pill = null;
let pop = null;

const timeFmt = new Intl.DateTimeFormat('pt-BR', { hour: '2-digit', minute: '2-digit' });

function validity(s, state) {
  if (state.kind === 'demo') return 'Modo demonstração: dados fictícios, sem prazo de validade.';
  if (state.kind === 'expired') return 'O token da Hostinger expirou. Conecte de novo para continuar.';
  if (s.minutesLeft == null) return 'Validade do token desconhecida.';
  const until = s.expiresAt ? ` (até ${timeFmt.format(new Date(s.expiresAt * 1000))})` : '';
  return `Válida por mais ${s.minutesLeft} min${until}.`;
}

function closePop(focusPill = false) {
  if (!pop || pop.hidden) return;
  pop.hidden = true;
  pill.setAttribute('aria-expanded', 'false');
  if (focusPill) pill.focus();
}

function openPop() {
  const state = pillState(session);
  // Element.append(null) vira o texto "null": filtra as linhas opcionais.
  clear(pop).append(...[
    h('p', { class: 'session-pop-title' }, h('span', { class: `dot dot-${state.tone}`, 'aria-hidden': 'true' }), state.label),
    h('p', { class: 'muted' }, validity(session, state)),
    state.kind === 'ok'
      ? h('p', { class: 'muted' }, session.via === 'extension'
        ? 'Renovada pela extensão, junto com o seu login no hPanel.'
        : 'Renova sozinho enquanto você usa o painel.')
      : null,
    h('div', { class: 'session-pop-actions' },
      state.canRenew
        ? h('button', { type: 'button', class: 'btn btn-secondary btn-sm', on: { click: renew } }, icon('refresh'), 'Renovar agora')
        : null,
      h('a', { href: 'settings', class: 'btn btn-ghost btn-sm' }, icon('settings'), 'Configurações'),
      h('button', { type: 'button', class: 'btn btn-ghost btn-sm', 'data-action': 'logout' }, icon('logout'), session.demo ? 'Sair do demo' : 'Sair')),
  ].filter(Boolean));
  pop.hidden = false;
  pill.setAttribute('aria-expanded', 'true');
  pop.querySelector('button, a')?.focus();
}

async function renew(event) {
  const button = event.currentTarget;
  button.disabled = true;
  try {
    const data = session.via === 'extension' ? await renewViaExtension() : await post('renew');
    render({ ...session, expired: false, ...data });
    toast('Sessão renovada.', 'ok');
    closePop(true);
  } catch (err) {
    button.disabled = false;
    // session_expired já abre o modal Reconectar (api.js → hp:session-expired).
    if (err.code !== 'session_expired') toast(err.message, 'danger');
  }
}

function render(next) {
  session = next;
  const state = pillState(session);
  if (!state) {
    slot.hidden = true;
    closePop();
    return;
  }
  const wasOpen = pop && !pop.hidden;
  pill.className = `session-pill session-pill-${state.tone}`;
  pill.dataset.tip = state.hint;
  pill.setAttribute('aria-label', `Sessão: ${state.label}. ${state.hint}.`);
  if (state.kind === 'expired') pill.setAttribute('aria-haspopup', 'dialog');
  else pill.removeAttribute('aria-haspopup');
  clear(pill).append(
    h('span', { class: `dot dot-${state.tone}`, 'aria-hidden': 'true' }),
    h('span', { class: 'session-pill-label', 'aria-hidden': 'true' }, state.label),
    h('span', { class: 'session-pill-short', 'aria-hidden': 'true' }, state.short),
  );
  slot.hidden = false;
  if (state.kind === 'expired') closePop();
  else if (wasOpen) openPop();
}

async function refresh() {
  if (document.hidden) return;
  lastFetch = Date.now();
  try {
    render(await get('session'));
  } catch {
    /* offline ou falha momentânea: mantém o último estado */
    return;
  }
  // Sessão da extensão: o servidor não renova; perto de expirar, a página pede o token novo à extensão.
  if (session?.via === 'extension' && (session.expired || (session.minutesLeft ?? Infinity) <= WARN_MINUTES)) {
    renewViaExtension().then((data) => render({ ...session, expired: false, ...data }), () => {});
  }
}

if (slot) {
  pill = h('button', { type: 'button', class: 'session-pill', 'aria-expanded': 'false', 'aria-controls': 'session-pop' });
  // tabindex -1: clique em texto do pop move o foco para ele (relatedTarget continua dentro do slot).
  pop = h('div', { id: 'session-pop', class: 'session-pop', role: 'group', 'aria-label': 'Sessão', tabindex: '-1', hidden: true });
  slot.append(pill, pop);

  pill.addEventListener('click', () => {
    if (pillState(session)?.kind === 'expired') {
      window.dispatchEvent(new CustomEvent('hp:session-expired'));
      return;
    }
    if (pop.hidden) openPop();
    else closePop();
  });
  document.addEventListener('click', (event) => {
    if (!slot.contains(event.target)) closePop();
  });
  // Foco indo para fora (Tab) fecha; relatedTarget nulo é clique fora, já tratado acima.
  slot.addEventListener('focusout', (event) => {
    if (event.relatedTarget && !slot.contains(event.relatedTarget)) closePop();
  });
  slot.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !pop.hidden) {
      event.stopPropagation();
      closePop(true);
    }
  });

  window.addEventListener('hp:session-expired', () => {
    if (session?.connected && !session.demo) render({ ...session, expired: true, minutesLeft: 0 });
  });

  window.addEventListener('hp:session-renewed', refresh);

  setInterval(refresh, POLL_MS);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && Date.now() - lastFetch >= POLL_MS) refresh();
  });
  refresh();
}
