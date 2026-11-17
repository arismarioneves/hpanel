import { HPANEL, describeJwtCookies, originPattern, panelPatterns, readJwt, secondsLeft } from './hostinger.js';

const $ = (id) => document.getElementById(id);

function el(tag, props = {}, ...children) {
  const node = Object.assign(document.createElement(tag), props);
  node.append(...children);
  return node;
}

async function showHostinger() {
  const target = $('hostinger');
  const token = await readJwt();
  if (!token) {
    target.replaceChildren('Você não está logado no hPanel neste navegador. ',
      el('button', { className: 'link', type: 'button', textContent: 'Abrir o hPanel', onclick: () => chrome.tabs.create({ url: HPANEL }) }));
    return;
  }
  const left = secondsLeft(token);
  target.className = 'ok';
  target.textContent = left === Infinity ? 'Logado' : `Logado · token válido por ${Math.max(0, Math.floor(left / 60))} min`;
}

async function isHPanel(tabId) {
  try {
    const [res] = await chrome.scripting.executeScript({
      target: { tabId },
      func: () => document.querySelector('meta[name="application-name"]')?.content === 'HPanel',
    });
    return res?.result === true;
  } catch {
    return false;
  }
}

async function showHere(tab) {
  if (!tab?.url || !/^https?:/.test(tab.url) || tab.url.startsWith(HPANEL)) return;
  const pattern = originPattern(tab.url);
  const host = new URL(tab.url).host;
  const granted = await chrome.permissions.contains({ origins: [pattern] });
  $('here').hidden = false;
  if (granted) {
    $('hereText').className = 'ok';
    $('hereText').textContent = `${host} está autorizado.`;
    return;
  }
  const looksLikePanel = await isHPanel(tab.id);
  $('hereText').textContent = looksLikePanel
    ? `${host} é um HPanel. Autorize para conectar sem copiar o token.`
    : `${host} não parece ser um HPanel. Autorize só se for o endereço do seu painel.`;
  const button = $('authorize');
  button.hidden = false;
  button.addEventListener('click', async () => {
    // O service worker recarrega a aba do painel ao receber a permissão.
    if (await chrome.permissions.request({ origins: [pattern] })) window.close();
  });
}

async function showPanels() {
  const list = $('panels');
  const patterns = await panelPatterns();
  $('noPanels').hidden = patterns.length > 0;
  list.replaceChildren(...patterns.map((pattern) => {
    const host = pattern.replace(/^[a-z]+:\/\//, '').replace(/\/\*$/, '');
    const remove = el('button', { type: 'button', textContent: 'Remover' });
    remove.addEventListener('click', async () => {
      await chrome.permissions.remove({ origins: [pattern] });
      showPanels();
    });
    return el('li', {}, el('span', { textContent: host }), remove);
  }));
}

async function showDiagnostics() {
  const { diagnostico = [], login } = await chrome.storage.session.get(['diagnostico', 'login']);
  const text = [
    `versão ${chrome.runtime.getManifest().version}`,
    `cookies jwt: ${JSON.stringify(await describeJwtCookies())}`,
    `login pendente: ${login ? 'sim' : 'não'}`,
    `permissões: ${JSON.stringify((await chrome.permissions.getAll()).origins)}`,
    '',
    ...diagnostico,
  ].join('\n');
  $('diag').textContent = text;
  $('copyDiag').addEventListener('click', () => navigator.clipboard.writeText(text));
}

const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
await Promise.all([showHostinger(), showHere(tab), showPanels(), showDiagnostics()]);
