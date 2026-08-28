#!/usr/bin/env node
/**
 * test-menu-search.js — browser test for the Bible search inside the site menu.
 *
 * WHAT:   Drives the real drawer on a phone-sized viewport: the Bible row's
 *         flush-right search button, the panel it opens under that row, and the
 *         whole chain a reader actually walks — type "Matthew 5:41", press
 *         Enter, land on the verse. Also the unresolvable case, which must land
 *         on the index with the filter open and filled rather than on a 404.
 *
 * INPUT:  BASE_URL argv[2] or $DWBIBLE_BASE_URL — any page of the site (default
 *         the local site's English home). OUTPUT: one ok/FAIL line per case;
 *         exit 1 on any failure, exit 0 with a "skipped" note without playwright.
 *
 * USAGE:  node tests/test-menu-search.js [BASE_URL]
 *
 * DEPENDS ON: playwright — see tests/test-index-filter.js for why it is resolved
 *         from a sibling repo rather than depended on here.
 *
 * TESTED BY: itself — run it against a site whose menu carries a Bible row.
 */

const path = require('path');
const os = require('os');

const BASE = process.argv[2] || process.env.DWBIBLE_BASE_URL || 'https://latinprayer.local/en/';

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
  // The menu is the phone's navigation — it does not exist above the md breakpoint.
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 390, height: 844 } });
  const page = await ctx.newPage();

  async function openDrawer(url) {
    await page.goto(url, { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => {
      const o = document.getElementById('dwcookies-overlay');
      if (o) o.remove();
      document.querySelectorAll('.dwcookies-banner, #dwcookies-banner').forEach((e) => e.remove());
    });
    // The drawer is the tool shell's sidebar; the hamburger is its collapse control.
    await page.click('.dw-shell__collapse');
    await page.waitForSelector('body.dw-shell-drawer-open', { timeout: 5000 });
  }

  await openDrawer(BASE);

  // — The row —
  const rows = await page.evaluate(() => {
    const items = [...document.querySelectorAll('.dw-shell-nav__list > li')];
    const withAction = items.filter((li) => li.querySelector('.dw-nav-action'));
    const bible = withAction[0];
    return {
      total: items.length,
      withAction: withAction.length,
      title: bible ? bible.querySelector('a').textContent.trim() : null,
      href: bible ? bible.querySelector('a').getAttribute('href') : null,
      expanded: bible ? bible.querySelector('.dw-nav-action').getAttribute('aria-expanded') : null,
      panelHidden: bible ? bible.querySelector('.dw-nav-panel').hasAttribute('hidden') : null,
      // The button is a SIBLING of the link, never nested inside it.
      nested: bible ? !!bible.querySelector('a .dw-nav-action') : null,
      // The action is the row's LAST element: flush right.
      last: bible ? bible.lastElementChild.className : null,
    };
  });
  check('exactly one row carries an action', rows.withAction === 1, JSON.stringify(rows));
  check('it is the Bible row', /biblia|bible/.test(rows.href || ''), String(rows.href));
  check('the action button is not inside the link', rows.nested === false, JSON.stringify(rows));
  check('starts closed', rows.expanded === 'false' && rows.panelHidden === true, JSON.stringify(rows));

  // — Opening —
  const before = page.url();
  await page.click('.dw-nav-action');
  const opened = await page.evaluate(() => {
    const btn = document.querySelector('.dw-nav-action');
    const panel = document.querySelector('.dw-nav-panel');
    return {
      expanded: btn.getAttribute('aria-expanded'),
      hidden: panel.hasAttribute('hidden'),
      focused: document.activeElement === panel.querySelector('input'),
      menuOpen: document.body.classList.contains('dw-shell-drawer-open'),
      // The panel sits UNDER the row it belongs to, not somewhere else.
      belowRow: panel.getBoundingClientRect().top >= panel.closest('li').querySelector('a').getBoundingClientRect().bottom - 1,
      action: panel.querySelector('form').getAttribute('action'),
    };
  });
  check('opens the panel', opened.expanded === 'true' && opened.hidden === false, JSON.stringify(opened));
  check('focus lands in the field', opened.focused === true, JSON.stringify(opened));
  check('the menu stays open (the action never navigates)',
        opened.menuOpen === true && page.url() === before, page.url());
  check('the panel sits under the row', opened.belowRow === true, JSON.stringify(opened));
  check('the form posts to the row\'s own URL', opened.action === rows.href, JSON.stringify(opened));

  // — Closing again —
  await page.click('.dw-nav-action');
  const closed = await page.evaluate(() => ({
    expanded: document.querySelector('.dw-nav-action').getAttribute('aria-expanded'),
    hidden: document.querySelector('.dw-nav-panel').hasAttribute('hidden'),
  }));
  check('re-click closes it', closed.expanded === 'false' && closed.hidden === true, JSON.stringify(closed));

  // — Live feedback: does what I typed name a book? —
  // The vocabulary is one cached fetch, made on the reader's first sign of
  // interest and never on page load.
  const vocabHits = [];
  page.on('request', (r) => { if (r.url().includes('bible-books.json')) vocabHits.push(r.url()); });

  async function resolves(text) {
    await page.fill('.dw-nav-panel input[name="q"]', text);
    await page.waitForTimeout(120);
    return page.evaluate(() => document.querySelector('form.dw-nav-search').classList.contains('is-resolved'));
  }

  await page.click('.dw-nav-action'); // reopen (the re-click above closed it)
  await page.waitForTimeout(400);     // the vocabulary lands

  // A citation is plausible when the book EXISTS and could hold those numbers.
  // Both separators, ranges, and the states a reader passes through on the way.
  const CASES = [
    ['John', true, 'a book name'],
    ['Joh', true, 'a partial name'],
    ['Matthäus', true, 'a name in another language'],
    ['mt 5,41', true, 'an abbreviation with a comma'],
    ['John 1:42', true, 'a name with a citation'],
    ['John 4:54', true, 'the last verse of a chapter'],
    ['John 4:55', false, 'one verse past the end'],
    ['John 4:9999', false, 'a verse that cannot exist'],
    ['John 4,9999', false, 'the same, with a comma'],
    ['John 21', true, 'the last chapter'],
    ['John 22', false, 'one chapter past the end'],
    ['John 0', false, 'chapter zero'],
    ['John 0:1', false, 'verse in chapter zero'],
    ['Luke 24:13-35', true, 'a range'],
    ['Luke 24:13-99', false, 'a range past the end'],
    ['Luke 24:13-3', true, 'a range end still being typed'],
    ['John 4:', true, 'a separator with no verse yet'],
    ['John 4:1-', true, 'a dash with no end yet'],
    // The Psalter is VULGATE-numbered here: the 176-verse psalm is 118, not 119.
    ['Psalms 118:176', true, 'the long psalm, Vulgate-numbered'],
    ['Psalms 119:176', false, 'the same verse under Hebrew numbering'],
    ['Psalms 150', true, 'the last psalm'],
    ['Psalms 151', false, 'a psalm that does not exist'],
    // Malachias 4 exists for the reader (the router maps it to 3:19-24), so the
    // field must not call implausible what the site itself answers.
    ['Malachias 4:6', true, 'the Malachias-4 shim the router honours'],
    ['Malachias 4:7', false, 'one verse past that shim'],
    // Names filed under the wrong book by a dataset's order number used to
    // match the wrong book entirely.
    ['Malachia 4:6', true, 'the Italian name of Malachias'],
    ['Aggeo 2:23', true, 'the Italian name of Aggeus'],
    ['Aggeo 4:1', false, 'a chapter Aggeus does not have'],
    ['Zaphod 1:1', false, 'a made-up name'],
    ['xyzzy', false, 'gibberish'],
    ['', false, 'an empty field'],
  ];
  for (const [q, want, what] of CASES) {
    const got = await resolves(q);
    check(`${want ? 'plausible' : 'not plausible'}: ${what}`, got === want, JSON.stringify(q) + ' → ' + got);
  }
  check('the vocabulary was fetched exactly once', vocabHits.length <= 1, JSON.stringify(vocabHits));

  // A payload that grows a field must arrive at a NEW address, or a browser
  // holding the old one keeps answering with it for a day — which is exactly
  // how verse checking looked "not implemented" while it was live: a book with
  // no lengths is trusted, so an old payload silently judges book names alone.
  const vocabUrl = await page.getAttribute('form.dw-nav-search', 'data-dwbible-vocab');
  check('the vocabulary URL is stamped', /[?&]v=\d[\d.]*/.test(vocabUrl || ''), String(vocabUrl));

  const payload = await page.evaluate(async (u) => {
    const r = await fetch(u, { credentials: 'omit' });
    if (!r.ok) { return { ok: false, status: r.status }; }
    const d = await r.json();
    const tokens = d.tokens || [];
    const verses = d.verses || [];
    return {
      ok: true,
      books: tokens.length,
      withVerses: verses.filter((v) => String(v).length).length,
      johnChapters: (verses[tokens.findIndex((t) => t.split(' ').indexOf('john') === 1)] || '').split(',').length,
    };
  }, vocabUrl);
  check('every book in the payload carries its verse counts',
        payload.ok && payload.books === 73 && payload.withVerses === payload.books, JSON.stringify(payload));

  // — The whole chain: a citation reaches the verse —
  // (the panel is still open from the checks above)
  await page.fill('.dw-nav-panel input[name="q"]', 'Matthew 5:41');
  await Promise.all([
    page.waitForURL(/matthaeus\/5:41\/?$/, { timeout: 15000 }),
    page.press('.dw-nav-panel input[name="q"]', 'Enter'),
  ]);
  check('a citation lands on the verse', /matthaeus\/5:41/.test(page.url()), page.url());
  const hl = await page.getAttribute('[data-highlight-ids]', 'data-highlight-ids');
  check('the verse is the page highlight target', hl === '["matthew-5-41"]', String(hl));

  // — A book name alone reaches the book —
  await openDrawer(BASE);
  await page.click('.dw-nav-action');
  await page.fill('.dw-nav-panel input[name="q"]', 'mt');
  await Promise.all([
    page.waitForURL(/matthaeus\/?$/, { timeout: 15000 }),
    page.press('.dw-nav-panel input[name="q"]', 'Enter'),
  ]);
  check('an abbreviation lands on the book', /biblia\/matthaeus\/?$/.test(page.url()), page.url());

  // — Unresolvable is a list of candidates, never a 404 —
  await openDrawer(BASE);
  await page.click('.dw-nav-action');
  await page.fill('.dw-nav-panel input[name="q"]', 'zzzz');
  await Promise.all([
    page.waitForURL(/[?&]q=zzzz/, { timeout: 15000 }),
    page.press('.dw-nav-panel input[name="q"]', 'Enter'),
  ]);
  const fallback = await page.evaluate(() => {
    const wrap = document.querySelector('.dwbible-filter-wrap');
    const input = document.querySelector('.dwbible-filter');
    return {
      onIndex: !!document.querySelector('.dwbible-index'),
      open: wrap ? !wrap.hasAttribute('hidden') : null,
      value: input ? input.value : null,
      empty: document.querySelector('.dwbible-filter-empty')
        ? document.querySelector('.dwbible-filter-empty').hidden === false : null,
      visibleBooks: [...document.querySelectorAll('.dwbible-tile')].filter((t) => !t.hidden).length,
    };
  });
  check('an unresolvable query renders the index, not a 404', fallback.onIndex === true, JSON.stringify(fallback));
  check('with the filter open and holding the query',
        fallback.open === true && fallback.value === 'zzzz', JSON.stringify(fallback));
  check('and the filter has already run over it',
        fallback.visibleBooks === 0 && fallback.empty === true, JSON.stringify(fallback));

  await browser.close();
  console.log(failures ? failures + ' FAILURES' : 'all pass');
  process.exit(failures ? 1 : 0);
})().catch((err) => {
  console.error(err);
  process.exit(1);
});
