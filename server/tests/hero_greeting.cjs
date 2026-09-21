'use strict';

/**
 * The front page greets the visitor by the country they are reading from.
 *
 *   node hero_greeting.cjs
 *
 * Localization.js has two halves: one the tests already cover, and one that only
 * runs in a browser and writes the greeting into the page. The greeting had
 * quietly disappeared for anyone in a country the product is not offered in,
 * which is a whole country's worth of visitors seeing a page that knows nothing
 * about them. So the browser half is run here against a small stand-in page.
 *
 * No network: the country is handed in the way the address lookup would.
 */
const assert = require('node:assert/strict');
const path = require('node:path');
const SCRIPT = path.join(__dirname, '..', 'site', 'localization.js');

let passed = 0;
const test = (name, run) => {
  try { run(); passed++; }
  catch (error) { error.message = name + ': ' + error.message; throw error; }
};

/** Just enough of a page for the script to write into. */
function page() {
  const make = (id) => ({
    id, textContent: '', hidden: true, dataset: {}, value: '', options: [],
    children: [], append(...kids) { this.children.push(...kids); },
    addEventListener() {}, focus() {},
  });
  const nodes = {};
  ['hero-country', 'hero-lead', 'coverage-lead', 'example-country', 'example-location-status'].forEach(id => {
    nodes[id] = make(id);
  });
  nodes['hero-lead'].textContent = 'Turn mobile money arriving on your own number into payments your billing system understands.';
  nodes['coverage-lead'].textContent = 'Direct Number reads the payment message on your own number.';
  // The "Automatic" option, whose label the script rewrites.
  nodes['example-country'].options = [make('auto')];
  nodes['example-country'].value = 'auto';

  const extra = {'.example-locality': make('locality'), '[data-example-caption]': make('caption')};
  return {
    nodes,
    document: {
      getElementById: id => nodes[id] || null,
      querySelector: sel => extra[sel] || make('any'),
      querySelectorAll: () => [],
      createElement: () => make('created'),
      addEventListener() {},
      readyState: 'complete',
    },
  };
}

/** Loads the script fresh with a country already detected. */
function visitFrom(country) {
  const {nodes, document} = page();
  const store = new Map();
  const storage = {getItem: k => store.get(k) || null, setItem: (k, v) => store.set(k, v), removeItem: k => store.delete(k)};
  // The address lookup is cached per session; seeding the cache is how the
  // script is told where the visitor is without a network call.
  storage.setItem('isp-pay:detected-country:v1', JSON.stringify({country, expires: Date.now() + 3_600_000}));

  global.document = document;
  global.window = {fetch: async () => { throw new Error('the page must not call out with a cached country'); }};
  global.sessionStorage = storage;
  global.localStorage = {getItem: () => null, setItem() {}, removeItem() {}};
  global.navigator = {language: 'en'};
  delete require.cache[require.resolve(SCRIPT)];
  require(SCRIPT);
  return nodes;
}

const settle = () => new Promise(resolve => setImmediate(() => setImmediate(resolve)));

(async () => {
  // A country the product is offered in is named in the heading, as before.
  {
    const nodes = visitFrom('GH');
    await settle();
    test('Ghana is named in the heading', () => {
      assert.equal(nodes['hero-country'].textContent, ' IN GHANA');
    });
    test('Ghana gets its own opening line', () => {
      assert.match(nodes['hero-lead'].textContent, /in Ghana/);
    });
  }

  // A country it is not offered in is named the same way as any other. What is
  // not offered stays out of the examples and the lists, not out of the heading.
  {
    const nodes = visitFrom('KE');
    await settle();
    test('Kenya is named like everywhere else', () => {
      assert.equal(nodes['hero-country'].textContent, ' IN KENYA');
    });
    test('but Kenya is never offered', () => {
      assert.equal(nodes['hero-lead'].textContent.includes('Kenya'), false);
      assert.equal(nodes['coverage-lead'].textContent.includes('Kenya'), false);
    });
    test('and the examples stay international', () => {
      assert.equal(nodes['example-country'].options[0].textContent.includes('Kenya'), false);
    });
  }

  // No country at all: the page says nothing about one, rather than guessing.
  {
    const nodes = visitFrom('');
    await settle();
    test('an unknown location names no country', () => {
      assert.equal(nodes['hero-country'].textContent, '');
    });
  }

  console.log('PASS: ' + passed + ' checks. The front page greets the country it is read from.');
})().catch(error => {
  console.error('FAIL ' + error.message);
  process.exit(1);
});
