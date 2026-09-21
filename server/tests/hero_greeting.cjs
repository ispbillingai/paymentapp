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
    className: '', children: [], append(...kids) { this.children.push(...kids); },
    addEventListener() {}, focus() {},
    querySelector() { return null; },
  });
  // The coverage cards, in the order the page is written in. Each one answers
  // for its own country code, which is how the script finds them.
  const card = (code) => {
    const node = make('card-' + code);
    node.className = 'country-card';
    node.countryCode = code;
    node.querySelector = sel => sel === '.country-code' ? {textContent: code} : null;
    node.remove = () => { const at = holder.cards.indexOf(node); if (at !== -1) holder.cards.splice(at, 1); };
    return node;
  };
  const holder = make('coverage-cards');
  holder.cards = ['GH', 'NG', 'ZA'].map(card);
  holder.prepend = node => {
    const at = holder.cards.indexOf(node);
    if (at !== -1) holder.cards.splice(at, 1);
    holder.cards.unshift(node);
  };
  // Everything before the browser is a card, which is what restores the order.
  holder.insertBefore = (node) => {
    const at = holder.cards.indexOf(node);
    if (at !== -1) holder.cards.splice(at, 1);
    holder.cards.push(node);
  };
  holder.querySelectorAll = () => holder.cards.slice();
  holder.querySelector = sel => sel === '[data-country-made]'
    ? holder.cards.find(c => c.dataset.countryMade) || null
    : null;

  const nodes = {};
  ['hero-country', 'hero-lead', 'coverage-lead', 'example-country', 'example-location-status'].forEach(id => {
    nodes[id] = make(id);
  });
  nodes['hero-lead'].textContent = 'Turn mobile money arriving on your own number into payments your billing system understands.';
  nodes['coverage-lead'].textContent = 'Direct Number reads the payment message on your own number.';
  // The "Automatic" option, whose label the script rewrites.
  nodes['example-country'].options = [make('auto')];
  nodes['example-country'].value = 'auto';

  const extra = {
    '.example-locality': make('locality'),
    '[data-example-caption]': make('caption'),
    '.coverage-cards': holder,
  };
  return {
    nodes, holder,
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
  const {nodes, holder, document} = page();
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
  return {nodes, cards: holder};
}

/** The country codes of the coverage cards, in the order they now appear. */
const order = cards => cards.cards.map(card =>
  card.countryCode || card.dataset.countryMade || '?');

const settle = () => new Promise(resolve => setImmediate(() => setImmediate(resolve)));

(async () => {
  // A country the product is offered in is named in the heading, as before.
  {
    const {nodes, cards} = visitFrom('GH');
    await settle();
    test('Ghana is named in the heading', () => {
      assert.equal(nodes['hero-country'].textContent, ' IN GHANA');
    });
    test('Ghana gets its own opening line', () => {
      assert.match(nodes['hero-lead'].textContent, /in Ghana/);
    });
    test('Ghana leads the coverage cards, which it did already', () => {
      assert.deepEqual(order(cards), ['GH', 'NG', 'ZA']);
    });
  }

  // A country further down the written order comes to the front.
  {
    const {cards} = visitFrom('ZA');
    await settle();
    test('South Africa is moved to the front', () => {
      assert.deepEqual(order(cards), ['ZA', 'GH', 'NG']);
    });
  }

  // A country it is not offered in is named the same way as any other. What is
  // not offered stays out of the examples and the lists, not out of the heading.
  {
    const {nodes, cards} = visitFrom('KE');
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
    test('Kenya gets a card of its own, first', () => {
      assert.deepEqual(order(cards), ['KE', 'GH', 'NG', 'ZA']);
    });
    test('and that card claims only what is true of it', () => {
      const made = cards.cards[0];
      const body = made.children[1];
      assert.equal(body.children[0].textContent, 'Kenya ');
      assert.equal(body.children[1].textContent, 'Name the sender your payment alerts arrive from');
      assert.equal(body.children[2].textContent, 'Custom sender');
    });
  }

  // A country with its networks built in says so on its card.
  {
    const {cards} = visitFrom('UG');
    await settle();
    test('Uganda gets a card naming its networks', () => {
      assert.deepEqual(order(cards), ['UG', 'GH', 'NG', 'ZA']);
      const body = cards.cards[0].children[1];
      assert.equal(body.children[1].textContent, 'MTN MoMo · Airtel Money');
      assert.equal(body.children[2].textContent, 'Parser support included');
    });
  }

  // No country at all: the page says nothing about one, rather than guessing.
  {
    const {nodes, cards} = visitFrom('');
    await settle();
    test('an unknown location names no country', () => {
      assert.equal(nodes['hero-country'].textContent, '');
    });
    test('and the cards keep the order the page was written in', () => {
      assert.deepEqual(order(cards), ['GH', 'NG', 'ZA']);
    });
  }

  console.log('PASS: ' + passed + ' checks. The front page greets the country it is read from.');
})().catch(error => {
  console.error('FAIL ' + error.message);
  process.exit(1);
});
