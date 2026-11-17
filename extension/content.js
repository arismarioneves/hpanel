// Content script nos painéis HPanel autorizados. Conversa com a página por postMessage e envia o token
// direto à API do painel (mesma origem, com o cabeçalho anti-CSRF): o JavaScript da página nunca vê o token.
(() => {
  if (globalThis.__hpanelConector) return;
  globalThis.__hpanelConector = true;
  if (document.querySelector('meta[name="application-name"]')?.content !== 'HPanel') return;

  const PAGE = 'hpanel-page';
  const EXT = 'hpanel-extension';
  const LOGIN_TIMEOUT_MS = 10 * 60 * 1000;
  const LOGIN_POLL_MS = 2000;
  const version = chrome.runtime.getManifest().version;

  const failure = (code, message) => Object.assign(new Error(message), { code });
  // Diagnóstico no console desta página (contexto da extensão). Nunca o token.
  const log = (...args) => console.info('[HPanel Conector]', ...args);
  const post = (msg) => window.postMessage({ source: EXT, ...msg }, location.origin);

  async function api(name, body) {
    const url = new URL(`api/${name}`, document.baseURI);
    if (url.origin !== location.origin) throw failure('forbidden', 'Endereço do painel inválido.');
    let res;
    try {
      res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-HPanel': '1' },
        body: JSON.stringify(body),
      });
    } catch {
      throw failure('offline', 'Sem conexão com o painel.');
    }
    let json = null;
    try { json = await res.json(); } catch { /* corpo não-JSON */ }
    if (!json || typeof json.ok !== 'boolean') throw failure('upstream_unavailable', 'O painel respondeu de forma inesperada. Tente novamente.');
    if (!json.ok) throw failure(json.code, json.message);
    return json.data;
  }

  async function ask(cmd) {
    let res;
    try {
      res = await chrome.runtime.sendMessage({ cmd });
    } catch {
      throw failure('extension_error', 'A extensão não respondeu. Recarregue a página e tente de novo.');
    }
    if (res?.error) throw failure(res.error.code, res.error.message);
    return res ?? {};
  }

  let login = null;
  function waitLogin() {
    login?.reject(failure('login_cancelled', 'Login substituído por uma nova tentativa.'));
    return new Promise((resolve, reject) => {
      let done = false;
      const settle = (fn, value) => {
        if (done) return;
        done = true;
        clearTimeout(timer);
        clearInterval(poll);
        if (login === current) login = null;
        fn(value);
      };
      const current = { resolve: (token) => settle(resolve, token), reject: (err) => settle(reject, err) };
      const timer = setTimeout(() => current.reject(failure('login_timeout', 'O login não foi concluído a tempo. Tente de novo.')), LOGIN_TIMEOUT_MS);
      // Além do aviso da extensão, pergunta periodicamente: cobre aviso perdido e service worker reiniciado.
      const poll = setInterval(async () => {
        try {
          const res = await ask('loginStatus');
          if (res.token) current.resolve(res.token);
          else if (res.closed) current.reject(failure('login_cancelled', 'A janela de login foi fechada antes de concluir.'));
        } catch {
          // tenta de novo no próximo ciclo
        }
      }, LOGIN_POLL_MS);
      login = current;
    });
  }

  const actions = {
    async connect() {
      const res = await ask('connect');
      log('connect: resposta da extensão', res.token ? 'token pronto' : res.pending ? 'aguardando login na janela' : 'sem token');
      const token = res.token ?? (res.pending ? await waitLogin() : null);
      if (!token) throw failure('extension_error', 'A extensão não encontrou a sessão do hPanel.');
      return api('connect', { token, extension: true });
    },
    async renew() {
      const { token } = await ask('renew');
      if (!token) throw failure('login_required', 'Entre no hPanel neste navegador para continuar.');
      return api('token', { token });
    },
  };

  window.addEventListener('message', async (event) => {
    if (event.source !== window || event.origin !== location.origin) return;
    const msg = event.data;
    if (!msg || typeof msg !== 'object' || msg.source !== PAGE) return;
    if (msg.type === 'ping') {
      post({ type: 'ready', version });
      return;
    }
    if (!Object.hasOwn(actions, msg.type) || typeof msg.id !== 'string') return;
    try {
      post({ type: 'result', id: msg.id, ok: true, data: await actions[msg.type]() });
    } catch (err) {
      post({ type: 'result', id: msg.id, ok: false, code: err.code || 'extension_error', message: err.message || 'A extensão não conseguiu concluir.' });
    }
  });

  chrome.runtime.onMessage.addListener((msg) => {
    if (msg?.cmd === 'login-done' || msg?.cmd === 'login-cancelled') log('mensagem da extensão:', msg.cmd, login ? '(aguardando)' : '(nada pendente)');
    if (msg?.cmd === 'login-done') login?.resolve(msg.token);
    else if (msg?.cmd === 'login-cancelled') login?.reject(failure('login_cancelled', 'A janela de login foi fechada antes de concluir.'));
    else if (msg?.cmd === 'sync' && document.body.dataset.via === 'extension') {
      api('token', { token: msg.token }).then((data) => post({ type: 'synced', data }), () => {});
    }
  });

  document.documentElement.dataset.hpanelExtension = version;
  post({ type: 'ready', version });
})();
