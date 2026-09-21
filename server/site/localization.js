/* Country currencies derived from Unicode CLDR 48, current on 2026-09-20.
 * Copyright Unicode, Inc. See UNICODE-LICENSE.txt and CURRENCY-DATA.md.
 * Demonstrations only: this file never changes a payment or merchant currency. */
(function () {
  'use strict';
  const currencies = Object.freeze({"AC":"SHP","AD":"EUR","AE":"AED","AF":"AFN","AG":"XCD","AI":"XCD","AL":"ALL","AM":"AMD","AO":"AOA","AR":"ARS","AS":"USD","AT":"EUR","AU":"AUD","AW":"AWG","AX":"EUR","AZ":"AZN","BA":"BAM","BB":"BBD","BD":"BDT","BE":"EUR","BF":"XOF","BG":"EUR","BH":"BHD","BI":"BIF","BJ":"XOF","BL":"EUR","BM":"BMD","BN":"BND","BO":"BOB","BQ":"USD","BR":"BRL","BS":"BSD","BT":"BTN","BV":"NOK","BW":"BWP","BY":"BYN","BZ":"BZD","CA":"CAD","CC":"AUD","CD":"CDF","CF":"XAF","CG":"XAF","CH":"CHF","CI":"XOF","CK":"NZD","CL":"CLP","CM":"XAF","CN":"CNY","CO":"COP","CR":"CRC","CU":"CUP","CV":"CVE","CW":"XCG","CX":"AUD","CY":"EUR","CZ":"CZK","DE":"EUR","DG":"USD","DJ":"DJF","DK":"DKK","DM":"XCD","DO":"DOP","DZ":"DZD","EA":"EUR","EC":"USD","EE":"EUR","EG":"EGP","EH":"MAD","ER":"ERN","ES":"EUR","ET":"ETB","EU":"EUR","FI":"EUR","FJ":"FJD","FK":"FKP","FM":"USD","FO":"DKK","FR":"EUR","GA":"XAF","GB":"GBP","GD":"XCD","GE":"GEL","GF":"EUR","GG":"GBP","GH":"GHS","GI":"GIP","GL":"DKK","GM":"GMD","GN":"GNF","GP":"EUR","GQ":"XAF","GR":"EUR","GS":"GBP","GT":"GTQ","GU":"USD","GW":"XOF","GY":"GYD","HK":"HKD","HM":"AUD","HN":"HNL","HR":"EUR","HT":"HTG","HU":"HUF","IC":"EUR","ID":"IDR","IE":"EUR","IL":"ILS","IM":"GBP","IN":"INR","IO":"USD","IQ":"IQD","IR":"IRR","IS":"ISK","IT":"EUR","JE":"GBP","JM":"JMD","JO":"JOD","JP":"JPY","KE":"KES","KG":"KGS","KH":"KHR","KI":"AUD","KM":"KMF","KN":"XCD","KP":"KPW","KR":"KRW","KW":"KWD","KY":"KYD","KZ":"KZT","LA":"LAK","LB":"LBP","LC":"XCD","LI":"CHF","LK":"LKR","LR":"LRD","LS":"LSL","LT":"EUR","LU":"EUR","LV":"EUR","LY":"LYD","MA":"MAD","MC":"EUR","MD":"MDL","ME":"EUR","MF":"EUR","MG":"MGA","MH":"USD","MK":"MKD","ML":"XOF","MM":"MMK","MN":"MNT","MO":"MOP","MP":"USD","MQ":"EUR","MR":"MRU","MS":"XCD","MT":"EUR","MU":"MUR","MV":"MVR","MW":"MWK","MX":"MXN","MY":"MYR","MZ":"MZN","NA":"NAD","NC":"XPF","NE":"XOF","NF":"AUD","NG":"NGN","NI":"NIO","NL":"EUR","NO":"NOK","NP":"NPR","NR":"AUD","NU":"NZD","NZ":"NZD","OM":"OMR","PA":"PAB","PE":"PEN","PF":"XPF","PG":"PGK","PH":"PHP","PK":"PKR","PL":"PLN","PM":"EUR","PN":"NZD","PR":"USD","PS":"ILS","PT":"EUR","PW":"USD","PY":"PYG","QA":"QAR","RE":"EUR","RO":"RON","RS":"RSD","RU":"RUB","RW":"RWF","SA":"SAR","SB":"SBD","SC":"SCR","SD":"SDG","SE":"SEK","SG":"SGD","SH":"SHP","SI":"EUR","SJ":"NOK","SK":"EUR","SL":"SLE","SM":"EUR","SN":"XOF","SO":"SOS","SR":"SRD","SS":"SSP","ST":"STN","SV":"USD","SX":"XCG","SY":"SYP","SZ":"SZL","TA":"GBP","TC":"USD","TD":"XAF","TF":"EUR","TG":"XOF","TH":"THB","TJ":"TJS","TK":"NZD","TL":"USD","TM":"TMT","TN":"TND","TO":"TOP","TR":"TRY","TT":"TTD","TV":"AUD","TW":"TWD","TZ":"TZS","UA":"UAH","UG":"UGX","UM":"USD","US":"USD","UY":"UYU","UZ":"UZS","VA":"EUR","VC":"XCD","VE":"VES","VG":"USD","VI":"USD","VN":"VND","VU":"VUV","WF":"XPF","WS":"WST","XK":"EUR","YE":"YER","YT":"EUR","ZA":"ZAR","ZM":"ZMW","ZW":"ZWG"});
  const africanCountries = new Set('DZ AO BJ BW BF BI CV CM CF TD KM CG CD CI DJ EG GQ ER SZ ET GA GM GH GN GW KE LS LR LY MG MW ML MR MU MA MZ NA NE NG RW ST SN SC SL SO ZA SS SD TZ TG TN UG ZM ZW EH RE YT SH'.split(' '));
  const sampleAmounts = {UGX:2000,GHS:10,KES:50,TZS:1000,RWF:500,BIF:1000,XOF:500,XAF:500,
    NGN:500,ZMW:10,ZWG:20,ZAR:10,NAD:10,LSL:10,SZL:10,BWP:5,MWK:500,MZN:20,MGA:2000,
    AOA:500,CDF:1000,GNF:5000,SLE:10,LRD:100,ETB:50,SSP:1000,SDG:500,SOS:500,ERN:10,
    DJF:100,KMF:250,CVE:50,STN:10,MRU:20,MAD:5,DZD:100,TND:2,LYD:2,EGP:20,MUR:20,
    SCR:10,GMD:20,USD:1,EUR:1,GBP:1,CAD:1,AUD:1,NZD:1,CHF:1,TRY:20,INR:50,JPY:100,KRW:1000};
  /* Not offered, so never shown anywhere on this site: no example, no option,
     no entry in the coverage list. The gateway refuses it server side too. */
  const unavailable = new Set(['KE']);
  const shown = code => code && !unavailable.has(code) ? code : '';
  const cacheKey = 'isp-pay:detected-country:v1';
  const preferenceKey = 'isp-pay:example-country:v1';
  function countryCode(value) {
    const code = typeof value === 'string' ? value.toUpperCase() : '';
    return Object.prototype.hasOwnProperty.call(currencies, code) ? code : '';
  }
  function exampleFor(value) {
    const country = countryCode(value);
    const currency = currencies[country] || 'USD';
    const amount = sampleAmounts[currency] || 10;
    return {country, currency, amount, total:amount*64, half:amount/2,
      primary:country === 'UG' || country === 'GH' ? 'MTN MoMo' : 'Mobile money',
      secondary:country === 'UG' ? 'Airtel Money' : country === 'GH' ? 'Telecel Cash' : 'Mobile money',
      phone:country === 'UG' ? '0772000000' : country === 'GH' ? '0244000000' : 'CUSTOMER_PHONE'};
  }
  function formatMoney(amount, currency, locale) {
    try {
      return new Intl.NumberFormat(locale || 'en', {style:'currency',currency,currencyDisplay:'code',minimumFractionDigits:0,maximumFractionDigits:2}).format(amount);
    } catch (_) { return currency + ' ' + String(amount); }
  }
  function storageGet(storage, key) { try { return storage?.getItem(key); } catch (_) { return null; } }
  function storageSet(storage, key, value) { try { storage?.setItem(key, value); } catch (_) {} }
  function storageRemove(storage, key) { try { storage?.removeItem(key); } catch (_) {} }
  async function detectCountry({fetcher,storage,now=Date.now,timeoutMs=2500} = {}) {
    try {
      const cached = JSON.parse(storageGet(storage, cacheKey) || 'null');
      if (cached && countryCode(cached.country) && cached.expires > now() && cached.expires <= now()+3600000) return {country:countryCode(cached.country),source:'ip'};
    } catch (_) {}
    let timer;
    const controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
    try {
      const lookup = (async () => {
        const response = await fetcher('https://get.geojs.io/v1/ip/country.json', {
          method:'GET',credentials:'omit',referrerPolicy:'no-referrer',cache:'no-store',
          ...(controller ? {signal:controller.signal} : {})
        });
        if (!response.ok) return '';
        const result = await response.json();
        return countryCode(result?.country);
      })();
      const timeout = new Promise(resolve => { timer = setTimeout(() => { controller?.abort(); resolve(''); }, timeoutMs); });
      const country = await Promise.race([lookup, timeout]);
      if (country) storageSet(storage, cacheKey, JSON.stringify({country,expires:now()+3600000}));
      return {country,source:country ? 'ip' : 'fallback'};
    } catch (_) { return {country:'',source:'fallback'}; }
    finally { clearTimeout(timer); }
  }
  const api = {currencies,countryCode,exampleFor,formatMoney,detectCountry};
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
  if (typeof window !== 'undefined') window.ISPPayLocalization = api;
  if (typeof document === 'undefined') return;

  /* Coverage: show the visitor's own country, detected from their address, and
     a box that opens the full scrollable list. Uses the same country data as
     the examples above, so the two can never disagree. */
  (function () {
    const browser = document.getElementById('coverage-browser');
    if (!browser) return;
    const BUILT_IN = {UG:'MTN MoMo · Airtel Money', GH:'MTN MoMo · Telecel Cash · AT Money'};
    let regionNames;
    try { regionNames = new Intl.DisplayNames(['en'], {type:'region'}); } catch (_) {}
    const label = code => (regionNames && regionNames.of(code)) || code;

    const codes = Object.keys(currencies).filter(code => /^[A-Z]{2}$/.test(code) && !unavailable.has(code))
      .sort((a, b) => label(a).localeCompare(label(b)));
    const list = document.getElementById('coverage-scroll');
    const rows = codes.map(code => {
      const li = document.createElement('li');
      const built = BUILT_IN[code];
      const cc = document.createElement('span'); cc.className = 'cc'; cc.textContent = code;
      const nm = document.createElement('span'); nm.className = 'nm'; nm.textContent = label(code);
      const cur = document.createElement('span'); cur.className = 'cur'; cur.textContent = currencies[code];
      const tag = document.createElement('span');
      tag.className = 'tag' + (built ? ' built' : '');
      tag.textContent = built ? 'Networks built in' : 'Custom sender';
      li.append(cc, nm, cur, tag);
      li.dataset.search = (label(code) + ' ' + code + ' ' + currencies[code]).toLowerCase();
      list.append(li);
      return li;
    });
    document.getElementById('coverage-count').textContent = codes.length + ' countries';
    browser.hidden = false;

    const box = document.getElementById('coverage-all');
    const panel = document.getElementById('coverage-list');
    const search = document.getElementById('coverage-search');
    const empty = document.getElementById('coverage-empty');
    box.addEventListener('change', () => {
      panel.hidden = !box.checked;
      if (box.checked) search.focus();
    });
    search.addEventListener('input', () => {
      const q = search.value.trim().toLowerCase();
      let shown = 0;
      rows.forEach(li => {
        const hit = !q || li.dataset.search.indexOf(q) !== -1;
        li.hidden = !hit;
        if (hit) shown++;
      });
      empty.hidden = shown > 0;
    });

    const mine = document.getElementById('coverage-yours');
    let store;
    try { store = sessionStorage; } catch (_) {}
    detectCountry({fetcher:window.fetch && window.fetch.bind(window), storage:store})
      .then(result => {
        const code = shown(result && countryCode(result.country));
        if (!code) return;
        const built = BUILT_IN[code];
        const badge = document.createElement('span');
        badge.className = 'country-code';
        badge.textContent = code;
        const body = document.createElement('div');
        const h = document.createElement('h3');
        h.textContent = 'You are in ' + label(code);
        const p = document.createElement('p');
        p.textContent = built ? built + ' · ready to pick'
          : 'Name your network sender · amounts in ' + currencies[code];
        body.append(h, p);
        mine.append(badge, body);
      })
      .catch(() => {});
  })();
  const selector = document.getElementById('example-country');
  if (!selector) return;
  document.querySelector('.example-locality').hidden = false;
  const status = document.getElementById('example-location-status');
  let names;
  try { names = new Intl.DisplayNames(['en'], {type:'region'}); } catch (_) {}
  const nameOf = country => country ? (names?.of(country) || country) : 'International';
  for (const [label, african] of [['Africa',true],['Other regions',false]]) {
    const group = document.createElement('optgroup');
    group.label = label;
    Object.keys(currencies).filter(code => africanCountries.has(code) === african && !unavailable.has(code))
      .sort((a,b) => nameOf(a).localeCompare(nameOf(b)))
      .forEach(code => {
        const option = document.createElement('option');
        option.value = code;
        option.textContent = nameOf(code) + ' · ' + currencies[code];
        group.append(option);
      });
    selector.append(group);
  }
  let sessionStore, preferenceStore;
  try { sessionStore = sessionStorage; } catch (_) {}
  try { preferenceStore = localStorage; } catch (_) {}
  /**
   * The written copy follows the same rule as the figures. A country is named only
   * when it is actually known, from the visitor's address or their own choice.
   * When it is not, the page says nothing about any country at all, rather than
   * guessing one or defaulting to a market the visitor may have nothing to do with.
   * The neutral wording lives in the HTML and is kept here to restore it.
   */
  const copy = {};
  ['hero-lead', 'coverage-lead'].forEach(id => {
    const node = document.getElementById(id);
    if (node) copy[id] = node.textContent;
  });
  function localiseCopy(country, known) {
    const put = (id, text) => { const node = document.getElementById(id); if (node) node.textContent = text; };
    if (!known || !country) {
      put('hero-country', '');
      put('hero-lead', copy['hero-lead']);
      put('coverage-lead', copy['coverage-lead']);
      return;
    }
    const name = nameOf(country);
    const example = exampleFor(country);
    const builtIn = example.primary !== 'Mobile money';
    put('hero-country', ' IN ' + name.toUpperCase());
    put('hero-lead', builtIn
      ? 'Turn ' + example.primary + ' and ' + example.secondary + ' payments arriving on your own number in ' + name + ' into payments your billing system understands. Match receipts, automate activation and keep every transaction in view.'
      : 'Turn mobile money arriving on your own number in ' + name + ' into payments your billing system understands, read in ' + example.currency + '. Match receipts, automate activation and keep every transaction in view.');
    put('coverage-lead', builtIn
      ? name + ' has its networks built in, ' + example.primary + ' and ' + example.secondary + ' among them, so you pick yours and start.'
      : 'In ' + name + ' you connect by naming the sender your payment messages arrive from, and amounts are read in ' + example.currency + '.');
  }
  function render(country, source) {
    country = shown(country);
    localiseCopy(country, source === 'ip' || source === 'manual');
    const example = exampleFor(country);
    document.querySelectorAll('[data-example-money]').forEach(node => {
      const key = node.dataset.exampleMoney;
      const amount = key === 'total' ? example.total : key === 'half' ? example.half : example.amount;
      node.textContent = formatMoney(amount, example.currency, navigator.language);
    });
    document.querySelectorAll('[data-example-provider]').forEach(node => { node.textContent = example[node.dataset.exampleProvider] || 'Mobile money'; });
    document.querySelectorAll('[data-example-amount]').forEach(node => { node.textContent = String(example.amount); });
    document.querySelectorAll('[data-example-phone]').forEach(node => { node.textContent = example.phone; });
    document.querySelectorAll('[data-example-currency]').forEach(node => { node.textContent = example.currency; });
    if (source === 'ip') selector.options[0].textContent = 'Automatic · ' + nameOf(country) + ' (' + example.currency + ')';
    else if (source === 'fallback') selector.options[0].textContent = 'Detect my country';
    status.textContent = source === 'ip' ? 'Approximate IP location · sample amounts, not prices.'
      : source === 'manual' ? 'Your selected country · sample amounts, not prices.'
      : source === 'loading' ? 'Finding your country · sample amounts, not prices.'
      : 'Location unavailable. Choose a country for local examples.';
    document.querySelector('[data-example-caption]').textContent = 'Sample figures in ' + example.currency + '. Use your merchant’s configured currency and real purchase details.';
  }
  let revision = 0;
  async function automatic() {
    const current = ++revision;
    render('', 'loading');
    const result = await detectCountry({fetcher:window.fetch?.bind(window),storage:sessionStore});
    if (current === revision && selector.value === 'auto') render(result.country, result.source);
  }
  selector.addEventListener('change', () => {
    revision++;
    if (selector.value === 'auto') {
      storageRemove(preferenceStore, preferenceKey);
      storageRemove(sessionStore, cacheKey);
      automatic();
    } else {
      const country = countryCode(selector.value);
      storageSet(preferenceStore, preferenceKey, country || 'international');
      render(country, 'manual');
    }
  });
  const saved = storageGet(preferenceStore, preferenceKey);
  if (countryCode(saved) || saved === 'international') {
    selector.value = saved;
    render(countryCode(saved), 'manual');
  } else { automatic(); }
})();
