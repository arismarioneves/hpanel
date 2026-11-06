import { test } from 'node:test';
import assert from 'node:assert/strict';
import { suffixError, passwordChecks, passwordOk, generatePassword, isRemoteIp, PRIVILEGES } from '../../assets/js/db-rules.js';

test('sufixo de nome/usuário segue a regra do hPanel', () => {
  assert.equal(suffixError('loja_2'), null);
  assert.equal(suffixError('a'.repeat(14)), null);
  for (const bad of ['', '_loja', 'a'.repeat(15), 'loja-1', 'loja 1', 'ção']) {
    assert.notEqual(suffixError(bad), null, bad);
  }
});

test('senha exige 8+ caracteres, minúscula, maiúscula e número', () => {
  assert.equal(passwordOk('Senha123'), true);
  for (const bad of ['Senha12', 'senha123', 'SENHA123', 'SenhaSem', 'Senha\n123']) {
    assert.equal(passwordOk(bad), false, bad);
  }
  assert.deepEqual(passwordChecks('abc').map((c) => c.ok), [false, true, false, false]);
});

test('senha gerada sempre passa nas regras, mesmo com aleatoriedade degenerada', () => {
  const zeros = (n) => new Uint32Array(n);
  for (const random of [zeros, undefined]) {
    const pw = generatePassword(20, random);
    assert.equal(pw.length, 20);
    assert.equal(passwordOk(pw), true, pw);
  }
});

test('IP remoto: IPv4, IPv6 ou qualquer host', () => {
  for (const ok of ['203.0.113.10', ' 2001:db8::1 ', '%']) assert.equal(isRemoteIp(ok), true, ok);
  for (const bad of ['', '203.0.113', '203.0.113.256', '203.0.113.%', 'exemplo.com']) assert.equal(isRemoteIp(bad), false, bad);
});

test('lista de privilégios tem as 18 chaves do hPanel', () => {
  assert.equal(new Set(PRIVILEGES).size, 18);
});
