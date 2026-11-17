import { renewViaExtension } from './extension.js';
import { CACHE_PREFIX, readCache, writeCache } from './swr.js';
import { toast } from './toast.js';

export class ApiError extends Error {
  constructor(code, message, status) {
    super(message);
    this.code = code;
    this.status = status;
  }
}

const inflight = new Map();
const RETRY_DELAY = 1500;
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
// Falha de revalidação avisa no máximo uma vez a cada 10 s (o dashboard revalida N endpoints de uma vez).
const STALE_TOAST_MS = 10000;
let staleToastAt = -Infinity;

// Páginas que funcionam sem sessão: não redirecionam em `not_connected`.
const STAY_PAGES = new Set(['landing', 'connect']);

/** Apaga o cache de respostas da aba (chaves `hp_cache:`); usar ao conectar, sair ou entrar no demo. */
export function clearCache() {
  try {
    Object.keys(sessionStorage).filter((k) => k.startsWith(CACHE_PREFIX)).forEach((k) => sessionStorage.removeItem(k));
  } catch { /* armazenamento indisponível */ }
}

function tabStorage() {
  try { return sessionStorage; } catch { return null; }
}

async function request(method, path, params, retried = false) {
  let url = `api/${path}`;
  const init = { method, credentials: 'same-origin', headers: { Accept: 'application/json' } };
  if (method === 'GET' && params) url += `?${new URLSearchParams(params)}`;
  if (method === 'POST') {
    init.headers['Content-Type'] = 'application/json';
    init.headers['X-HPanel'] = '1';
    init.body = JSON.stringify(params || {});
  }

  let res;
  try {
    res = await fetch(url, init);
  } catch {
    throw new ApiError('offline', 'Sem conexão com a internet.', 0);
  }
  let json = null;
  try { json = await res.json(); } catch { /* corpo não-JSON */ }
  if (!json || typeof json.ok !== 'boolean') {
    throw new ApiError('upstream_unavailable', 'O servidor respondeu de forma inesperada. Tente novamente.', res.status);
  }
  if (json.ok) return json.data;

  const err = new ApiError(json.code, json.message, res.status);
  if (err.code === 'session_expired' && !retried) {
    // Com a extensão ativa, o token renovado no navegador substitui o expirado e a chamada é repetida uma vez.
    const renewed = await renewViaExtension().then(() => true, () => false);
    if (renewed) return request(method, path, params, true);
  }
  if (err.code === 'not_connected' && !STAY_PAGES.has(document.body.dataset.page)) location.assign('./');
  if (err.code === 'session_expired') window.dispatchEvent(new CustomEvent('hp:session-expired'));
  throw err;
}

/** GET com deduplicação e 1 nova tentativa se a Hostinger falhar. */
export function get(path, params) {
  const key = `${path}?${new URLSearchParams(params || {})}`;
  if (inflight.has(key)) return inflight.get(key);
  const p = request('GET', path, params)
    .catch(async (err) => {
      if (err.code !== 'upstream_unavailable') throw err;
      await sleep(RETRY_DELAY);
      return request('GET', path, params);
    })
    .finally(() => inflight.delete(key));
  inflight.set(key, p);
  return p;
}

export const post = (path, body) => request('POST', path, body);

/** Encerra a sessão (real ou demo), apaga o cache da aba e volta para a página inicial. */
export async function logout() {
  await post('logout');
  clearCache();
  location.assign('./');
}

/**
 * Stale-while-revalidate: entrega o cache da aba (se válido) com `{stale:true}` e,
 * em seguida, a resposta nova com `{stale:false}`. Com `refresh`, ignora o cache e pede `refresh=1`.
 * Rejeita só se não houve dado em cache; senão avisa discretamente.
 */
export async function cached(path, params, onData, { maxAgeMs = 300000, refresh = false } = {}) {
  const key = `${path}?${new URLSearchParams(params || {})}`;
  const storage = tabStorage();
  const hit = !refresh && storage ? readCache(storage, key, maxAgeMs) : null;
  if (hit) onData(hit.value, { stale: true });
  let fresh;
  try {
    fresh = await get(path, refresh ? { ...(params || {}), refresh: 1 } : params);
  } catch (err) {
    if (!hit) throw err;
    console.warn(`Falha ao revalidar ${key}`, err);
    if (Date.now() - staleToastAt >= STALE_TOAST_MS) {
      staleToastAt = Date.now();
      toast('Não foi possível atualizar agora.', 'warn');
    }
    return;
  }
  if (storage) writeCache(storage, key, fresh);
  onData(fresh, { stale: false });
}
