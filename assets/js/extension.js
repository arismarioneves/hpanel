// Ponte com a extensão HPanel Conector, que roda como content script nesta página e conversa por postMessage.
// O token não passa por aqui: a extensão o envia direto à API e devolve só o resultado da chamada.

const PAGE = 'hpanel-page';
const EXT = 'hpanel-extension';
const DETECT_MS = 1500;
const CONNECT_MS = 11 * 60 * 1000; // inclui o tempo de login na janela da Hostinger
const RENEW_MS = 90 * 1000;

export class ExtensionError extends Error {
  constructor(code, message) {
    super(message);
    this.code = code;
  }
}

const missing = () => new ExtensionError('extension_missing', 'A extensão HPanel Conector não está ativa nesta página.');

const pending = new Map();
const readyWaiters = new Set();
let version = document.documentElement.dataset.hpanelExtension || null;
let seq = 0;

window.addEventListener('message', (event) => {
  if (event.source !== window || event.origin !== location.origin) return;
  const msg = event.data;
  if (!msg || typeof msg !== 'object' || msg.source !== EXT) return;
  if (msg.type === 'ready') {
    version = String(msg.version || '?');
    readyWaiters.forEach((fn) => fn(version));
    window.dispatchEvent(new CustomEvent('hp:extension-ready'));
  } else if (msg.type === 'result' && pending.has(msg.id)) {
    const { resolve, reject, timer } = pending.get(msg.id);
    pending.delete(msg.id);
    clearTimeout(timer);
    if (msg.ok) resolve(msg.data);
    else reject(new ExtensionError(String(msg.code || 'extension_error'), String(msg.message || 'A extensão não conseguiu concluir.')));
  } else if (msg.type === 'synced') {
    window.dispatchEvent(new CustomEvent('hp:session-renewed'));
  }
});

let detection = null;

/** Versão da extensão ativa nesta página, ou null se ela não respondeu. */
export function detectExtension() {
  if (version) return Promise.resolve(version);
  detection ??= new Promise((resolve) => {
    const done = (v) => {
      clearTimeout(timer);
      readyWaiters.delete(done);
      resolve(v);
    };
    const timer = setTimeout(() => done(null), DETECT_MS);
    readyWaiters.add(done);
    window.postMessage({ source: PAGE, type: 'ping' }, location.origin);
  });
  return detection.then((v) => v ?? version);
}

function call(type, timeoutMs) {
  return new Promise((resolve, reject) => {
    const id = `${Date.now().toString(36)}-${++seq}`;
    const timer = setTimeout(() => {
      pending.delete(id);
      reject(new ExtensionError('extension_timeout', 'A extensão não respondeu a tempo. Tente de novo.'));
    }, timeoutMs);
    pending.set(id, { resolve, reject, timer });
    window.postMessage({ source: PAGE, type, id }, location.origin);
  });
}

/** Conecta com a sessão do hPanel deste navegador (abre o login da Hostinger se preciso). Resolve com `{servers}`. */
export async function connectViaExtension() {
  if (!(await detectExtension())) throw missing();
  return call('connect', CONNECT_MS);
}

let renewing = null;

/** Pede à extensão o token atual (renovado no navegador). Resolve com `{expiresAt, minutesLeft}`. */
export function renewViaExtension() {
  renewing ??= (async () => {
    if (!(await detectExtension())) throw missing();
    return call('renew', RENEW_MS);
  })().finally(() => { renewing = null; });
  return renewing;
}
