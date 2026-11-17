// Service worker: entrega o token da sessão do hPanel deste navegador aos painéis HPanel autorizados.
// O login acontece só no site da Hostinger, numa janela própria; a renovação também roda no navegador, para que
// o painel e o hPanel usem sempre o mesmo token (o refresh troca o token e invalida o anterior).

import { HOSTINGER_PATTERNS, HPANEL, HPANEL_PATTERN, describeJwtCookies, isJwtCookie, isUsable, originPattern, panelPatterns, readJwt, secondsLeft } from './hostinger.js';

const REFRESH_URL = `${HPANEL}api/auth/api/external/v1/auth/refresh`;
const REFRESH_HEADERS = { accept: 'application/json;charset=utf-8', 'content-type': 'application/json' };
const FRESH_SECONDS = 15 * 60; // acima disso o token é entregue como está, sem renovar
const TAB_TIMEOUT_MS = 45000;
const SCRIPT_ID = 'hpanel-panel';

const failure = (code, message) => Object.assign(new Error(message), { code });
// Diagnóstico: console do service worker e as últimas linhas em storage.session, exibidas no popup. Nunca o token.
const DIAG_LINES = 40;
function log(...args) {
  console.info('[HPanel]', ...args);
  const line = `${new Date().toLocaleTimeString('pt-BR')} ${args.map((a) => (typeof a === 'string' ? a : JSON.stringify(a))).join(' ')}`;
  chrome.storage.session.get('diagnostico')
    .then(({ diagnostico = [] }) => chrome.storage.session.set({ diagnostico: [...diagnostico, line].slice(-DIAG_LINES) }))
    .catch(() => {});
}

// ---- Content script nos painéis autorizados ----

async function syncContentScript() {
  const matches = await panelPatterns();
  const registered = await chrome.scripting.getRegisteredContentScripts({ ids: [SCRIPT_ID] });
  if (!matches.length) {
    if (registered.length) await chrome.scripting.unregisterContentScripts({ ids: [SCRIPT_ID] });
    return;
  }
  const script = { id: SCRIPT_ID, matches, js: ['content.js'], runAt: 'document_end', persistAcrossSessions: true };
  if (registered.length) await chrome.scripting.updateContentScripts([script]);
  else await chrome.scripting.registerContentScripts([script]);
}

chrome.runtime.onInstalled.addListener(syncContentScript);
chrome.runtime.onStartup.addListener(syncContentScript);
chrome.permissions.onRemoved.addListener(syncContentScript);
chrome.permissions.onAdded.addListener(async ({ origins = [] }) => {
  await syncContentScript();
  const added = origins.filter((o) => !HOSTINGER_PATTERNS.includes(o));
  if (!added.length) return;
  // Recarrega as abas do painel recém-autorizado para o content script entrar.
  for (const tab of await chrome.tabs.query({ url: added })) chrome.tabs.reload(tab.id).catch(() => {});
});

// ---- Renovação no navegador ----

async function refreshHere() {
  const res = await fetch(REFRESH_URL, { method: 'POST', credentials: 'include', headers: REFRESH_HEADERS, body: '' });
  return res.ok;
}

function waitComplete(tabId) {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(() => { chrome.tabs.onUpdated.removeListener(listener); reject(new Error('timeout')); }, TAB_TIMEOUT_MS);
    const listener = (id, info) => {
      if (id !== tabId || info.status !== 'complete') return;
      clearTimeout(timer);
      chrome.tabs.onUpdated.removeListener(listener);
      resolve();
    };
    chrome.tabs.onUpdated.addListener(listener);
  });
}

/** Refresh rodado dentro de uma aba do hPanel: a já aberta, ou uma inativa aberta e fechada só para isso. */
async function refreshInTab() {
  const [open] = await chrome.tabs.query({ url: HPANEL_PATTERN, status: 'complete' });
  const tab = open ?? await chrome.tabs.create({ url: HPANEL, active: false });
  try {
    if (!open) await waitComplete(tab.id);
    const [res] = await chrome.scripting.executeScript({
      target: { tabId: tab.id },
      args: [REFRESH_URL, REFRESH_HEADERS],
      func: async (url, headers) => (await fetch(url, { method: 'POST', credentials: 'include', headers, body: '' })).ok,
    });
    return res?.result === true;
  } finally {
    if (!open) chrome.tabs.remove(tab.id).catch(() => {});
  }
}

/** Token válido da sessão do hPanel, renovado quando está perto de expirar; null se só um login novo resolve. */
async function currentToken() {
  const before = await readJwt();
  if (!before) return null;
  if (secondsLeft(before) > FRESH_SECONDS) return before;
  for (const attempt of [refreshHere, refreshInTab]) {
    try {
      if (await attempt()) {
        const after = await readJwt();
        if (after && after !== before) return after;
      }
    } catch {
      // tenta o próximo caminho
    }
  }
  return isUsable(before) ? before : null;
}

let renewing = null;
const tokenOnce = () => (renewing ??= currentToken().finally(() => { renewing = null; }));

// ---- Login na janela da Hostinger ----
// O estado fica em storage.session: o service worker pode ser encerrado enquanto o usuário faz login.

async function openLogin(tabId) {
  const { login } = await chrome.storage.session.get('login');
  if (login) {
    try {
      await chrome.windows.update(login.windowId, { focused: true });
      await chrome.storage.session.set({ login: { ...login, tabId } });
      return;
    } catch {
      // a janela anterior já foi fechada
    }
  }
  const win = await chrome.windows.create({ url: HPANEL, type: 'popup', width: 520, height: 760, focused: true });
  await chrome.storage.session.set({ login: { windowId: win.id, tabId } });
  log('login: janela aberta', { windowId: win.id, tabId, cookies: await describeJwtCookies() });
}

/** Encerra o login pendente: fecha a janela e entrega o token à aba do painel que pediu. */
async function finishLogin(token) {
  const { login } = await chrome.storage.session.get('login');
  if (!login) return false;
  log('login: concluído, entregando à aba', login.tabId);
  await chrome.storage.session.remove('login');
  chrome.windows.remove(login.windowId).catch(() => {});
  chrome.tabs.sendMessage(login.tabId, { cmd: 'login-done', token }).catch(() => {});
  return true;
}

// Cada página que termina de carregar na janela de login confere o cookie: depois do login a Hostinger
// redireciona para o hPanel, e é aí que o jwt válido aparece.
chrome.tabs.onUpdated.addListener(async (tabId, info, tab) => {
  if (info.status !== 'complete') return;
  const { login } = await chrome.storage.session.get('login');
  if (!login || tab.windowId !== login.windowId) return;
  const token = await readJwt();
  log('login: página carregada na janela', { host: tab.url ? new URL(tab.url).host : '(sem acesso à URL)', jwtValido: Boolean(token), cookies: await describeJwtCookies() });
  if (token) await finishLogin(token);
});

chrome.cookies.onChanged.addListener(async ({ cookie, removed, cause }) => {
  if (!isJwtCookie(cookie)) return;
  log('cookie jwt mudou', { domain: cookie.domain, removed, cause, usable: isUsable(cookie.value) });
  if (removed || !isUsable(cookie.value)) return;
  if (await finishLogin(cookie.value)) return;
  // Token trocado no navegador (o próprio hPanel renovou ou houve login novo): repassa aos painéis abertos.
  const patterns = await panelPatterns();
  if (!patterns.length) return;
  for (const tab of await chrome.tabs.query({ url: patterns })) {
    chrome.tabs.sendMessage(tab.id, { cmd: 'sync', token: cookie.value }).catch(() => {});
  }
});

chrome.windows.onRemoved.addListener(async (windowId) => {
  const { login } = await chrome.storage.session.get('login');
  if (login?.windowId !== windowId) return;
  log('login: janela fechada antes de concluir');
  await chrome.storage.session.remove('login');
  chrome.tabs.sendMessage(login.tabId, { cmd: 'login-cancelled' }).catch(() => {});
});

// ---- Pedidos do content script ----

const handlers = {
  async connect(sender) {
    const token = await tokenOnce();
    if (token) return { token };
    await openLogin(sender.tab.id);
    return { pending: true };
  },
  async renew() {
    return { token: await tokenOnce() };
  },
  // Consultado pela página a cada poucos segundos enquanto espera o login: não depende de eventos do navegador.
  async loginStatus() {
    const token = await readJwt();
    const { login } = await chrome.storage.session.get('login');
    if (token) {
      if (login) {
        log('login: jwt válido encontrado na consulta da página');
        await chrome.storage.session.remove('login');
        chrome.windows.remove(login.windowId).catch(() => {});
      }
      return { token };
    }
    if (!login) return { closed: true };
    try {
      await chrome.windows.get(login.windowId);
    } catch {
      log('login: a janela não existe mais');
      await chrome.storage.session.remove('login');
      return { closed: true };
    }
    return { waiting: true };
  },
};

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  if (sender.id !== chrome.runtime.id || !sender.tab || !sender.origin || !Object.hasOwn(handlers, msg?.cmd)) return undefined;
  chrome.permissions.contains({ origins: [originPattern(sender.origin)] })
    .then((allowed) => {
      if (!allowed) throw failure('not_authorized', 'Este painel não está autorizado na extensão.');
      return handlers[msg.cmd](sender);
    })
    .then(sendResponse, (e) => {
      log('erro em', msg.cmd, e.code || '', e.message || String(e));
      sendResponse({ error: { code: e.code || 'extension_error', message: e.message || 'A extensão não conseguiu concluir.' } });
    });
  return true;
});
