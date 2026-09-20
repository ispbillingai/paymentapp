# Localised homepage examples

`localization.js` maps countries/territories to their first currently active tender currency in Unicode CLDR 48's `supplemental/currencyData.json`, selected as of 2026-09-20. It contains 255 entries, including all 54 African UN member states. Historical and non-tender currencies are excluded. Lesotho explicitly uses its local loti (LSL). Other multi-currency countries use CLDR's first active choice; actual merchants choose their own settlement currency separately.

Source: https://github.com/unicode-org/cldr-json/blob/main/cldr-json/cldr-core/supplemental/currencyData.json

The derived country/currency data is covered by the accompanying `UNICODE-LICENSE.txt`. Refresh this snapshot when currencies change. Zimbabwe uses ZWG, Sierra Leone SLE and Bulgaria EUR in this snapshot.

The homepage loads the country-only GeoJS JSON endpoint in the visitor's browser, with no credentials or referrer. It reads `country` (not `country_code`), validates against the bundled mapping, and never stores the returned IP. Detection is limited to 2.5 seconds and cached for one hour in session storage. A manually selected country takes precedence and is kept in local storage; returning to automatic detection clears both that preference and the cached detection.

The API is a display dependency only. A blocked request, timeout, malformed result or unavailable storage leaves the static USD examples and manual selector usable. No payment API, merchant setting, database, geolocation permission, provider access key or exchange-rate service is involved. Other website pages do not load the detector. The homepage CSP permits only the GeoJS origin in addition to same-origin connections; it does not allow external scripts.

Sample amounts are deliberately illustrative round numbers, not FX conversions or a pricing catalogue. Totals, receipt values, package illustration and homepage API amount update together. Provider names are retained only for built-in Uganda/Ghana examples; other markets use generic mobile-money labels. Selecting Kenya does not enable Direct Number there.

References: https://www.geojs.io/docs/v1/endpoints/country/ , https://www.geojs.io/docs/general/ , https://www.geojs.io/privacy/
