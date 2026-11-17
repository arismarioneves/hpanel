// Sessão do hPanel neste navegador: leitura do cookie jwt (HttpOnly, só a extensão alcança) e padrões de origem.

export const HPANEL = 'https://hpanel.hostinger.com/';
export const HPANEL_PATTERN = `${HPANEL}*`;
// O cookie jwt pode valer para todo o domínio (.hostinger.com); o Chrome só avisa mudanças de cookie (cookies.onChanged)
// nos domínios com permissão, por isso o domínio raiz também está no manifest.
export const HOSTINGER_PATTERNS = [HPANEL_PATTERN, '*://hostinger.com/*'];

const SHAPE = /^eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/;

/** `exp` do payload (sem validar assinatura: quem valida é a Hostinger) ou null. */
export function expiry(token) {
  try {
    const payload = JSON.parse(atob(token.split('.')[1].replace(/-/g, '+').replace(/_/g, '/')));
    return typeof payload.exp === 'number' ? payload.exp : null;
  } catch {
    return null;
  }
}

/** Segundos até o token expirar; Infinity quando o token não informa `exp`. */
export function secondsLeft(token) {
  const exp = expiry(token);
  return exp === null ? Infinity : exp - Math.floor(Date.now() / 1000);
}

export const isUsable = (token) => typeof token === 'string' && SHAPE.test(token) && secondsLeft(token) > 0;

export const isJwtCookie = (cookie) => cookie.name === 'jwt' && cookie.domain.replace(/^\./, '').endsWith('hostinger.com');

// Todos os cookies jwt da Hostinger que a extensão alcança, de qualquer subdomínio e caminho.
const jwtCookies = () => chrome.cookies.getAll({ domain: 'hostinger.com', name: 'jwt' });

/**
 * Token da sessão do hPanel neste navegador, se houver um ainda válido. Pode haver mais de um cookie jwt
 * (domínio inteiro e só hpanel, caminhos diferentes, ou um antigo com o JWT já vencido): vale o utilizável
 * de maior validade.
 */
export async function readJwt() {
  const usable = (await jwtCookies()).map((c) => c.value).filter(isUsable);
  usable.sort((a, b) => secondsLeft(b) - secondsLeft(a));
  return usable[0] ?? null;
}

/** Resumo dos cookies jwt, sem o valor: domínio, caminho, se é utilizável e minutos restantes. */
export async function describeJwtCookies() {
  return (await jwtCookies()).map((c) => ({
    domain: c.domain,
    path: c.path,
    usable: isUsable(c.value),
    minutesLeft: secondsLeft(c.value) === Infinity ? null : Math.floor(secondsLeft(c.value) / 60),
  }));
}

/** Padrão de permissão de uma origem; sem porta, porque o Chrome ignora a porta ao casar o padrão. */
export function originPattern(url) {
  const u = new URL(url);
  return `${u.protocol}//${u.hostname}/*`;
}

/** Origens de painéis autorizadas pelo usuário (as da Hostinger são fixas no manifest e ficam de fora). */
export async function panelPatterns() {
  const { origins = [] } = await chrome.permissions.getAll();
  return origins.filter((o) => !HOSTINGER_PATTERNS.includes(o));
}
