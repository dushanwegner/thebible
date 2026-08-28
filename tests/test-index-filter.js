#!/usr/bin/env node
/**
 * test-index-filter.js — browser test for the /bible index book filter.
 *
 * WHAT:   Drives the real filter in a real browser: the book search (name,
 *         abbreviation, any language) and the CITATION path — a query ending in
 *         a chapter/verse ("Matthew 5:41", "Mt 5,41", "Luke 24:13-35",
 *         "1 Cor 13") points the matching row at the verse instead of the
 *         book's table of contents, and Enter goes there.
 *
 * INPUT:  BASE_URL argv[2] or $DWBIBLE_BASE_URL — default the local site's
 *         English Bible index. OUTPUT: one ok/FAIL line per case; exit 1 on any
 *         failure, exit 0 with a "skipped" note when playwright is absent.
 *
 * USAGE:  node tests/test-index-filter.js [BASE_URL]
 *
 * DEPENDS ON: playwright — NOT a dependency of this plugin (production ships no
 *         node). It is resolved from $PLAYWRIGHT_PATH or a sibling repo that
 *         already carries it (LatinPrayerApp); missing = skip, never a red run.
 *
 * TESTED BY: itself — run it against a site serving the index page.
 */

const path = require('path');
const os = require('os');

const BASE = (process.argv[2] || process.env.DWBIBLE_BASE_URL || 'https://latinprayer.local/en/biblia/').replace(/\/?$/, '/');

function loadPlaywright() {
  const candidates = [
    process.env.PLAYWRIGHT_PATH,
    path.join(__dirname, '..', 'node_modules'),
    path.join(os.homedir(), 'dev', 'projects', 'LatinPrayerApp', 'node_modules'),
  ].filter(Boolean);
  for (const dir of candidates) {
    try {
      return require(require.resolve('playwright', { paths: [dir] }));
    } catch (e) { /* try the next candidate */ }
  }
  return null;
}

const playwright = loadPlaywright();
if (!playwright) {
  console.log('skipped — playwright not found (set PLAYWRIGHT_PATH to a node_modules that has it)');
  process.exit(0);
}

let failures = 0;
function check(name, cond, detail) {
  console.log((cond ? 'ok   ' : 'FAIL ') + name + (cond ? '' : '   ' + detail));
  if (!cond) failures++;
}

(async () => {
  const browser = await playwright.chromium.launch();
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await ctx.newPage();

  await page.goto(BASE, { waitUntil: 'domcontentloaded' });
  // The cookie banner sits over the page and is not what we are testing.
  await page.evaluate(() => {
    const o = document.getElementById('dwcookies-overlay');
    if (o) o.remove();
    document.querySelectorAll('.dwcookies-banner, #dwcookies-banner').forEach((e) => e.remove());
  });
  await page.click('.dwbible-index-search-toggle');

  // Type a query, read back what the filter did to the surviving rows.
  async function type(q) {
    await page.fill('.dwbible-filter', q);
    return page.evaluate(() => {
      const vis = [...document.querySelectorAll('.dwbible-tile')].filter((t) => !t.hidden);
      return {
        count: vis.length,
        all: vis.map((t) => t.getAttribute('href')),
        first: vis[0] ? {
          href: vis[0].getAttribute('href'),
          name: vis[0].querySelector('.dwbible-tile-name').textContent,
          gloss: (vis[0].querySelector('.dwbible-tile-alt') || {}).textContent || '',
          label: vis[0].getAttribute('aria-label'),
        } : null,
        empty: document.querySelector('.dwbible-filter-empty').hidden === false,
      };
    });
  }

  let r = await type('matthew');
  check('plain book filter still works',
        r.count === 1 && /\/matthew\/$/.test(r.first.href) && r.first.name === 'Matthaeus', JSON.stringify(r));
  // The undecorated row, to compare against once a citation is typed. The gloss
  // is the reader's own language, so it differs per index — read it, don't pin it.
  const book = r.first;

  r = await type('Matthew 5:41');
  check('citation → verse href', r.first && r.first.href.endsWith('/matthew/5:41/'), JSON.stringify(r));
  check('citation → row names the verse', r.first && r.first.name === book.name + ' 5:41', JSON.stringify(r.first));
  check('citation → gloss untouched', r.first && r.first.gloss === book.gloss, JSON.stringify(r.first));
  check('citation → aria-label carries the reference', r.first && r.first.label === book.label + ' 5:41', JSON.stringify(r.first));
  check('citation → one book', r.count === 1, JSON.stringify(r));

  r = await type('Mt 5,41');
  check('abbreviation + comma separator', r.count === 1 && r.first.href.endsWith('/matthew/5:41/'), JSON.stringify(r));

  r = await type('Luke 24:13-35');
  check('verse range', r.count === 1 && r.first.href.endsWith('/luke/24:13-35/'), JSON.stringify(r));

  r = await type('1 Cor 13');
  check('numbered book + chapter only', r.count === 1 && r.first.href.endsWith('/1-corinthians/13/'), JSON.stringify(r));

  r = await type('Io 3:16');
  check('latin name', r.count >= 1 && r.first.href.endsWith('3:16/'), JSON.stringify(r));

  // A NUMBERED book is findable by its own name, not only by its number: the
  // English index writes "1. John", and the strip that makes "john" a token for
  // it wants the dot as well as a space (dwbible#5). The Gospel still leads,
  // because the rows are in canonical order and Enter takes the first.
  r = await type('john');
  check('"john" finds the Gospel AND the three epistles',
        r.count === 4 && /\/john\/$/.test(r.first.href) && r.first.name === 'Ioannes', JSON.stringify(r.all));

  r = await type('john 3:16');
  check('…and each of them offers what it can hold',
        r.count === 4 && r.first.name === 'Ioannes 3:16'
          && r.all.filter((h) => h.endsWith('3:16/')).length === 2, JSON.stringify(r.all));

  // The roman-numeral half of that strip still requires whitespace: a dot must
  // not turn "Iudith" or "Iob" into a numbered book.
  r = await type('iudith');
  check('a name that merely begins with roman letters is untouched',
        r.count === 1 && r.first.name === 'Iudith', JSON.stringify(r.first));

  // An ambiguous name matches several books, and each takes as much of the
  // citation as IT can hold: 1 John has a chapter 3, the other two epistles are
  // one chapter long, so they offer the book rather than a chapter they lack.
  r = await type('ioannis 3');
  check('ambiguous name → each match carries what it can hold',
        r.count === 3 && r.all.filter((h) => h.endsWith('/3/')).length === 1
          && r.all.filter((h) => /-john\/$/.test(h)).length === 2, JSON.stringify(r.all));

  // A row promises only what its book can hold: a verse that does not exist
  // falls back to its chapter, a chapter that does not exist to the book. A row
  // reading "Ioannes 3:16666" would be a link to nowhere.
  const FIT = [
    ['john 3:16',     'Ioannes 3:16',   '/john/3:16/'],
    ['john 3:16666',  'Ioannes 3',      '/john/3/'],
    ['john 99:1',     'Ioannes',        '/john/'],
    ['john 99',       'Ioannes',        '/john/'],
    ['luke 24:13-35', 'Lucas 24:13-35', '/luke/24:13-35/'],
    ['luke 24:13-99', 'Lucas 24:13',    '/luke/24:13/'],
    ['luke 24:13-3',  'Lucas 24:13',    '/luke/24:13/'],
  ];
  for (const [q, name, href] of FIT) {
    r = await type(q);
    check(`"${q}" → the row reads "${name}"`,
          r.first && r.first.name === name && r.first.href.endsWith(href),
          JSON.stringify(r.first));
  }

  r = await type('zzz 5:41');
  check('unknown book → empty state', r.count === 0 && r.empty, JSON.stringify(r));

  r = await type('matthew');
  check('citation cleared → the row is a book row again',
        r.first.name === 'Matthaeus' && /\/matthew\/$/.test(r.first.href), JSON.stringify(r.first));

  // Enter goes to the first surviving row — the point of typing a citation.
  await page.fill('.dwbible-filter', 'Matthew 5:41');
  await Promise.all([
    page.waitForURL(/matthaeus\/5:41\/?$/, { timeout: 15000 }),
    page.press('.dwbible-filter', 'Enter'),
  ]);
  check('Enter lands on the verse', /matthaeus\/5:41/.test(page.url()), page.url());
  const hl = await page.getAttribute('[data-highlight-ids]', 'data-highlight-ids');
  check('the verse is the page highlight target', hl === '["matthew-5-41"]', String(hl));

  await browser.close();
  console.log(failures ? failures + ' FAILURES' : 'all pass');
  process.exit(failures ? 1 : 0);
})().catch((err) => {
  console.error(err);
  process.exit(1);
});
