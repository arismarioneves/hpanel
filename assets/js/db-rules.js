/** Regras dos formulários de banco MySQL (mesmas do servidor em lib/Validate.php). */

export const SUFFIX_MAX = 14;
const SUFFIX_RE = /^(?!_)[A-Za-z0-9_]{1,14}$/;

/** Mensagem de erro do trecho digitado após o prefixo `<username>_`, ou null se válido. */
export function suffixError(value) {
  if (!value) return 'Obrigatório.';
  if (value.length > SUFFIX_MAX) return `Até ${SUFFIX_MAX} caracteres.`;
  if (!SUFFIX_RE.test(value)) return 'Use letras, números ou _ (sem começar com _).';
  return null;
}

/** Requisitos da senha, na ordem exibida. */
export function passwordChecks(value) {
  return [
    { text: 'Mínimo de 8 caracteres', ok: value.length >= 8 && value.length <= 128 },
    { text: 'Letra minúscula', ok: /[a-z]/.test(value) },
    { text: 'Letra maiúscula', ok: /[A-Z]/.test(value) },
    { text: 'Número', ok: /\d/.test(value) },
  ];
}

export const passwordOk = (value) => !/[\x00-\x1F\x7F]/.test(value) && passwordChecks(value).every((c) => c.ok);

const LOWER = 'abcdefghijkmnpqrstuvwxyz';
const UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
const DIGITS = '23456789';
const SYMBOLS = '!#%*+-=?@^_';
const ALL = LOWER + UPPER + DIGITS + SYMBOLS;

/**
 * Senha aleatória com ao menos uma minúscula, maiúscula, número e símbolo (sem caracteres ambíguos).
 * `random(n)` devolve n inteiros sem sinal de 32 bits (padrão: crypto.getRandomValues).
 */
export function generatePassword(length = 20, random = (n) => crypto.getRandomValues(new Uint32Array(n))) {
  const r = random(length * 2);
  const pick = (set, i) => set[r[i] % set.length];
  const chars = [pick(LOWER, 0), pick(UPPER, 1), pick(DIGITS, 2), pick(SYMBOLS, 3)];
  for (let i = 4; i < length; i++) chars.push(pick(ALL, i));
  // Embaralha (Fisher–Yates) para os obrigatórios não ficarem sempre no início.
  for (let i = chars.length - 1; i > 0; i--) {
    const j = r[length + i] % (i + 1);
    [chars[i], chars[j]] = [chars[j], chars[i]];
  }
  return chars.join('');
}

/** Privilégios MySQL controlados pelo hPanel (mesma lista de Validate::DB_PRIVILEGES). */
export const PRIVILEGES = [
  'Alter', 'Alter routine', 'Create', 'Create routine', 'Create temporary tables', 'Create view',
  'Delete', 'Drop', 'Event', 'Execute', 'Index', 'Insert', 'Lock tables', 'References',
  'Select', 'Show view', 'Trigger', 'Update',
];

/** IPv4, IPv6 ou `%` (qualquer host). Validação rasa: o servidor confere de verdade. */
export function isRemoteIp(value) {
  const v = value.trim();
  if (v === '%') return true;
  const v4 = v.split('.');
  if (v4.length === 4 && v4.every((p) => /^\d{1,3}$/.test(p) && Number(p) <= 255)) return true;
  return v.includes(':') && /^[0-9a-fA-F:.]+$/.test(v);
}
