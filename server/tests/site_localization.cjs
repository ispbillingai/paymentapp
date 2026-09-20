'use strict';

// No network, database, browser, or payment access: run with node site_localization.cjs.
const assert = require('node:assert/strict');
const { currencies, countryCode, exampleFor, formatMoney, detectCountry } = require('../site/localization.js');
const CACHE_KEY = 'isp-pay:detected-country:v1';
const NOW = 1_800_000_000_000;
let passed = 0;
const test = async (name, run) => {
  try { await run(); passed++; }
  catch (error) { error.message = name + ': ' + error.message; throw error; }
};
const memoryStorage = initial => {
  const values = new Map(Object.entries(initial || {}));
  return { values, getItem: key => values.get(key) || null, setItem: (key, value) => values.set(key, value) };
};
const response = value => ({ ok: true, json: async () => value });
const detect = overrides => detectCountry({ now: () => NOW, timeoutMs: 100, ...overrides });
const fallback = { country: '', source: 'fallback' };
const normalizeSpace = value => value.replace(/\s/g, ' ');

(async () => {
  // All 54 African UN member countries must resolve, including countries whose
  // payment providers are not yet supported. These are demonstration currencies.
  const africa = 'DZ AO BJ BW BF BI CV CM CF TD KM CG CD CI DJ EG GQ ER SZ ET GA GM GH GN GW KE LS LR LY MG MW ML MR MU MA MZ NA NE NG RW ST SN SC SL SO ZA SS SD TZ TG TN UG ZM ZW'.split(' ');
  assert.equal(new Set(africa).size, 54);
  for (const country of africa) {
    await test('African country ' + country, () => {
      assert.equal(countryCode(country), country);
      assert.match(currencies[country], /^[A-Z]{3}$/);
      const example = exampleFor(country);
      assert.equal(example.currency, currencies[country]);
      assert.equal(example.country, country);
      assert.ok(Number.isFinite(example.amount) && example.amount > 0);
    });
  }
  await test('current currency codes', () => {
    assert.equal(currencies.ZW, 'ZWG');
    assert.equal(currencies.SL, 'SLE');
    assert.equal(currencies.BG, 'EUR');
    assert.equal(currencies.MR, 'MRU');
    assert.equal(currencies.ST, 'STN');
    assert.equal(currencies.LS, 'LSL');
  });
  await test('mapping is immutable', () => {
    assert.ok(Object.isFrozen(currencies));
    assert.throws(() => { currencies.UG = 'USD'; }, TypeError);
  });
  await test('lowercase country normalization', () => assert.equal(countryCode('gh'), 'GH'));
  for (const invalid of ['XX', 'T1', 'ZZ', 'ZZZ', '', ' US ', 'UG\n', '<script>', '__proto__', 'constructor', null, undefined, 254, {}, ['UG']]) {
    await test('reject invalid country ' + String(invalid), () => assert.equal(countryCode(invalid), ''));
  }
  for (const [country, currency, amount, phone] of [['UG', 'UGX', 2000, '0772000000'], ['GH', 'GHS', 10, '0244000000'], ['KE', 'KES', 50, 'CUSTOMER_PHONE']]) {
    await test(country + ' coherent localized example', () => {
      const example = exampleFor(country);
      assert.equal(example.currency, currency);
      assert.equal(example.amount, amount);
      assert.equal(example.half, amount / 2);
      assert.equal(example.total, amount * 64);
      assert.equal(example.phone, phone);
      assert.equal(example.primary, country === 'KE' ? 'Mobile money' : 'MTN MoMo');
      const formatted = normalizeSpace(formatMoney(amount, currency, 'en-US'));
      assert.equal(formatted, currency + ' ' + amount.toLocaleString('en-US'));
    });
  }
  await test('unknown country remains an international illustration', () => {
    const example = exampleFor('XX');
    assert.equal(example.country, '');
    assert.equal(example.currency, 'USD');
    assert.equal(example.phone, 'CUSTOMER_PHONE');
    assert.equal(example.primary, 'Mobile money');
  });
  await test('formatting respects locale and fractional example amounts', () => {
    assert.equal(normalizeSpace(formatMoney(2.5, 'GHS', 'en-US')), 'GHS 2.5');
    assert.equal(normalizeSpace(formatMoney(2000, 'UGX', 'fr-FR')), '2 000 UGX');
    assert.equal(normalizeSpace(formatMoney(10, 'ZWG', 'en-US')), 'ZWG 10');
    assert.equal(normalizeSpace(formatMoney(10, 'SLE', 'en-US')), 'SLE 10');
  });
  await test('unsupported formatter input has a nonthrowing fallback', () => {
    assert.equal(formatMoney(10, 'INVALID', 'en-US'), 'INVALID 10');
    assert.equal(formatMoney(10, 'GHS', 'not_a_locale'), 'GHS 10');
  });
  await test('GeoJS country field is accepted; request and cache contain no payment data', async () => {
    const storage = memoryStorage();
    let calls = 0;
    const result = await detect({ storage, fetcher: async (url, options) => {
      calls++;
      assert.equal(url, 'https://get.geojs.io/v1/ip/country.json');
      assert.equal(options.method, 'GET');
      assert.equal(options.credentials, 'omit');
      assert.equal(options.referrerPolicy, 'no-referrer');
      assert.equal(options.cache, 'no-store');
      assert.equal(options.body, undefined);
      assert.equal(options.headers, undefined);
      assert.ok(options.signal instanceof AbortSignal);
      return response({ country: 'GH', country_3: 'GHA', name: 'Ghana', ip: '198.51.100.9', unexpected: 'do-not-persist' });
    } });
    assert.deepEqual(result, { country: 'GH', source: 'ip' });
    assert.equal(calls, 1);
    assert.equal(storage.values.size, 1);
    assert.deepEqual(JSON.parse(storage.values.get(CACHE_KEY)), { country: 'GH', expires: NOW + 3_600_000 });
    assert.ok(!storage.values.get(CACHE_KEY).includes('198.51.100.9'));
  });
  for (const [name, payload] of [
    ['wrong GeoJS endpoint field', { country_code: 'GH' }],
    ['unknown country', { country: 'XX' }],
    ['Tor pseudo-country', { country: 'T1' }],
    ['malformed country', { country: '<img onerror=alert(1)>' }],
    ['object country', { country: { code: 'GH' } }],
    ['empty JSON object', {}], ['null JSON', null], ['JSON list', [{ country: 'GH' }]],
  ]) {
    await test(name + ' safely falls back without persistence', async () => {
      const storage = memoryStorage();
      assert.deepEqual(await detect({ storage, fetcher: async () => response(payload) }), fallback);
      assert.equal(storage.values.size, 0);
    });
  }
  await test('network rejection falls back', async () => assert.deepEqual(await detect({ fetcher: async () => { throw new Error('offline'); } }), fallback));
  await test('HTTP rejection never parses or caches its body', async () => {
    let parsed = false;
    const result = await detect({ fetcher: async () => ({ ok: false, status: 429, json: async () => { parsed = true; return { country: 'GH' }; } }) });
    assert.deepEqual(result, fallback);
    assert.equal(parsed, false);
  });
  await test('invalid JSON falls back', async () => assert.deepEqual(await detect({ fetcher: async () => ({ ok: true, json: async () => { throw new SyntaxError('invalid JSON'); } }) }), fallback));
  await test('missing fetch implementation falls back', async () => assert.deepEqual(await detect({}), fallback));
  await test('timeout aborts even an unresponsive fetch', async () => {
    let signal;
    const started = Date.now();
    const result = await detect({ timeoutMs: 10, fetcher: (_url, options) => { signal = options.signal; return new Promise(() => {}); } });
    assert.deepEqual(result, fallback);
    assert.equal(signal.aborted, true);
    assert.ok(Date.now() - started < 2000, 'timeout must not stall the page');
  });
  await test('timeout also bounds a response whose JSON body stalls', async () => {
    assert.deepEqual(await detect({ timeoutMs: 10, fetcher: async () => ({ ok: true, json: () => new Promise(() => {}) }) }), fallback);
  });
  await test('late successful response cannot populate cache after timeout', async () => {
    const storage = memoryStorage();
    let release;
    const pending = detect({ timeoutMs: 10, storage, fetcher: () => new Promise(resolve => { release = resolve; }) });
    assert.deepEqual(await pending, fallback);
    release(response({ country: 'GH' }));
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(storage.values.size, 0);
  });
  await test('fresh cache avoids external request', async () => {
    const storage = memoryStorage({ [CACHE_KEY]: JSON.stringify({ country: 'UG', expires: NOW + 3_600_000 }) });
    let fetched = false;
    const result = await detect({ storage, fetcher: async () => { fetched = true; return response({ country: 'GH' }); } });
    assert.deepEqual(result, { country: 'UG', source: 'ip' });
    assert.equal(fetched, false);
  });
  for (const [name, cached] of [
    ['expired', JSON.stringify({ country: 'UG', expires: NOW - 1 })],
    ['exact expiry', JSON.stringify({ country: 'UG', expires: NOW })],
    ['excessively distant expiry', JSON.stringify({ country: 'UG', expires: NOW + 3_600_001 })],
    ['unknown cached country', JSON.stringify({ country: 'XX', expires: NOW + 1000 })],
    ['corrupted', '{broken'],
    ['missing expiry', JSON.stringify({ country: 'UG' })],
  ]) {
    await test(name + ' cache is replaced using a fresh country response', async () => {
      const storage = memoryStorage({ [CACHE_KEY]: cached });
      let calls = 0;
      assert.deepEqual(await detect({ storage, fetcher: async () => { calls++; return response({ country: 'KE' }); } }), { country: 'KE', source: 'ip' });
      assert.equal(calls, 1);
      assert.deepEqual(JSON.parse(storage.values.get(CACHE_KEY)), { country: 'KE', expires: NOW + 3_600_000 });
    });
  }
  await test('blocked browser storage does not block localization', async () => {
    const storage = { getItem() { throw new Error('denied'); }, setItem() { throw new Error('denied'); } };
    assert.deepEqual(await detect({ storage, fetcher: async () => response({ country: 'GH' }) }), { country: 'GH', source: 'ip' });
  });
  await test('unavailable browser storage does not block localization', async () => {
    assert.deepEqual(await detect({ fetcher: async () => response({ country: 'GH' }) }), { country: 'GH', source: 'ip' });
  });
  console.log(`PASS: ${passed} localization regression checks (no network or database).`);
})().catch(error => { console.error(error); process.exitCode = 1; });
