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
    const gloss = d.gloss || {};
    return {
      ok: true,
      books: tokens.length,
      withVerses: verses.filter((v) => String(v).length).length,
      johnChapters: (verses[tokens.findIndex((t) => t.split(' ').indexOf('john') === 1)] || '').split(',').length,
      // The arrays are only usable if they are the same length — a short one
      // does not fail loudly, it files a name under the wrong book.
      names: (d.names || []).length,
      slugs: (d.slugs || []).length,
      named: (d.names || []).filter((n) => n).length,
      slugged: (d.slugs || []).filter((s) => s).length,
      langs: Object.keys(gloss).sort(),
      glossLens: Object.keys(gloss).map((k) => gloss[k].length),
      // Canonical Bible order, not alphabetical by an internal key.
      first: (d.names || [])[0],
      last: (d.names || [])[tokens.length - 1],
    };
  }, vocabUrl);
  check('every book in the payload carries its verse counts',
        payload.ok && payload.books === 73 && payload.withVerses === payload.books, JSON.stringify(payload));
  check('…and a name and a slug it can be offered under',
        payload.named === 73 && payload.slugged === 73, JSON.stringify(payload));
  check('the parallel arrays are all one length',
        payload.names === 73 && payload.slugs === 73
        && payload.glossLens.every((n) => n === 73), JSON.stringify(payload));
  check('all five vernaculars carry a gloss',
        JSON.stringify(payload.langs) === JSON.stringify(['bibel', 'bible', 'french', 'italian', 'spanish']),
        JSON.stringify(payload.langs));
  check('the books are in canonical Bible order',
        payload.first === 'Genesis' && payload.last === 'Apocalypsis', JSON.stringify(payload));

  // — The answers: WHICH books, in which order, leading where —
  // The check at the end of the field says a book was understood; the rows say
  // which one. "2 thessa" is the case that made this necessary.
  async function answers(text) {
    await page.fill('.dw-nav-panel input[name="q"]', text);
    await page.waitForTimeout(120);
    return page.evaluate(() => {
      const list = document.querySelector('.dw-nav-search__answers');
      const input = document.querySelector('.dw-nav-panel input[name="q"]');
      return {
        hidden: list.hasAttribute('hidden'),
        expanded: input.getAttribute('aria-expanded'),
        rows: [...list.querySelectorAll('a')].map((a) => ({
          name: a.querySelector('.dw-shell-subnav__name').textContent,
          gloss: a.querySelector('.dw-shell-subnav__gloss')
            ? a.querySelector('.dw-shell-subnav__gloss').textContent : '',
          href: a.getAttribute('href'),
        })),
      };
    });
  }

  let a = await answers('');
  check('an empty field offers nothing', a.hidden === true && a.rows.length === 0
        && a.expanded === 'false', JSON.stringify(a));

  a = await answers('xyzzy');
  check('gibberish offers nothing', a.hidden === true && a.rows.length === 0, JSON.stringify(a));

  a = await answers('2 thessa');
  check('a half-typed epistle names exactly one book',
        a.rows.length === 1 && a.rows[0].name === '2 Thessalonicenses', JSON.stringify(a));
  check('…glossed in the language being read',
        a.rows[0] && a.rows[0].gloss === '2. Thessalonians', JSON.stringify(a));
  check('…and the row IS the link, canonical, no redirect hop',
        a.rows[0] && /\/en\/biblia\/2-thessalonicenses\/$/.test(a.rows[0].href), JSON.stringify(a));
  check('the field announces the list', a.expanded === 'true' && a.hidden === false, JSON.stringify(a));

  a = await answers('jo');
  check('an ambiguous prefix names every book it could be, in Bible order',
        JSON.stringify(a.rows.map((r) => r.name))
          === JSON.stringify(['Iosue', 'Iob', 'Ioel', 'Ionas', 'Ioannes', '1 Ioannis', '2 Ioannis', '3 Ioannis']),
        JSON.stringify(a.rows.map((r) => r.name)));

  a = await answers('Matthew 5:41');
  check('a citation is on the row and in its href',
        a.rows.length === 1 && a.rows[0].name === 'Matthaeus 5:41'
        && /\/en\/biblia\/matthaeus\/5:41\/$/.test(a.rows[0].href), JSON.stringify(a));

  // Three books answer to "John"; only the two that HAVE a 3:16 are offered
  // (2 and 3 John are one chapter long), which is how the list narrows to the
  // answer as a reference is finished.
  a = await answers('John 3:16');
  check('a citation rules out the books that cannot hold it',
        JSON.stringify(a.rows.map((r) => r.name)) === JSON.stringify(['Ioannes 3:16', '1 Ioannis 3:16']),
        JSON.stringify(a.rows.map((r) => r.name)));

  // — The keyboard —
  await page.fill('.dw-nav-panel input[name="q"]', 'jo');
  await page.waitForTimeout(120);
  await page.press('.dw-nav-panel input[name="q"]', 'ArrowDown');
  await page.press('.dw-nav-panel input[name="q"]', 'ArrowDown');
  const kb = await page.evaluate(() => {
    const input = document.querySelector('.dw-nav-panel input[name="q"]');
    const rows = [...document.querySelectorAll('.dw-nav-search__answers a')];
    const on = rows.filter((r) => r.classList.contains('is-active'));
    return {
      count: on.length,
      at: rows.indexOf(on[0]),
      name: on[0] ? on[0].querySelector('.dw-shell-subnav__name').textContent : null,
      described: input.getAttribute('aria-activedescendant') === (on[0] ? on[0].id : ''),
      selected: on[0] ? on[0].getAttribute('aria-selected') : null,
      // The field keeps what was typed — arrowing is not editing.
      value: input.value,
    };
  });
  check('the arrows walk the list, one row at a time',
        kb.count === 1 && kb.at === 1 && kb.name === 'Iob' && kb.value === 'jo', JSON.stringify(kb));
  check('…and the field says which row it is on',
        kb.described === true && kb.selected === 'true', JSON.stringify(kb));

  // Escape closes the innermost open thing and nothing else: the answers go,
  // the panel and the drawer around them stay. (The theme already makes the
  // same promise one level up — the panel's Escape does not close the nav.)
  await page.press('.dw-nav-panel input[name="q"]', 'Escape');
  const esc = await page.evaluate(() => ({
    hidden: document.querySelector('.dw-nav-search__answers').hasAttribute('hidden'),
    expanded: document.querySelector('.dw-nav-panel input[name="q"]').getAttribute('aria-expanded'),
    panelOpen: !document.querySelector('.dw-nav-panel').hasAttribute('hidden'),
    drawerOpen: document.body.classList.contains('dw-shell-drawer-open'),
  }));
  check('Escape puts the list away', esc.hidden === true && esc.expanded === 'false', JSON.stringify(esc));
  check('…and takes nothing else with it',
        esc.panelOpen === true && esc.drawerOpen === true, JSON.stringify(esc));
  // A second Escape is the panel's own, and belongs to the theme (nav-action.js).
  // Not asserted here: what it does to the drawer is dwtheme's business, not
  // this field's.

  // Enter on a CHOSEN row follows it; Enter on the field itself still submits
  // to the server (proven by the four navigation cases below, which never touch
  // the arrows and still land on the right page).
  await page.fill('.dw-nav-panel input[name="q"]', 'jo');
  await page.waitForTimeout(120);
  await page.press('.dw-nav-panel input[name="q"]', 'ArrowDown');
  await page.press('.dw-nav-panel input[name="q"]', 'ArrowDown');
  await Promise.all([
    page.waitForURL(/\/biblia\/iob\/?$/, { timeout: 15000 }),
    page.press('.dw-nav-panel input[name="q"]', 'Enter'),
  ]);
  check('Enter follows the chosen row', /\/biblia\/iob\/?$/.test(page.url()), page.url());

  // — Clicking a row is the same act —
  await openDrawer(BASE);
  await page.click('.dw-nav-action');
  await page.waitForTimeout(400);
  await page.fill('.dw-nav-panel input[name="q"]', 'ruth');
  await page.waitForTimeout(200);
  await Promise.all([
    page.waitForURL(/\/biblia\/ruth\/?$/, { timeout: 15000 }),
    page.click('.dw-nav-search__answers a'),
  ]);
  check('tapping a row opens the book', /\/biblia\/ruth\/?$/.test(page.url()), page.url());

  // — The whole chain: a citation reaches the verse —
  // Enter WITHOUT choosing a row: the form submits and the server's resolver
  // answers, exactly as it does with JS off. The suggestions are a shortcut
  // past that resolver, never a second one.
  await openDrawer(BASE);
  await page.click('.dw-nav-action');
  await page.fill('.dw-nav-panel input[name="q"]', 'Matthew 5:41');
  await Promise.all([
    page.waitForURL(/matthaeus\/5:41\/?$/, { timeout: 15000 }),
    page.press('.dw-nav-panel input[name="q"]', 'Enter'),
  ]);
  check('a citation lands on the verse', /matthaeus\/5:41/.test(page.url()), page.url());
  const hl = await page.getAttribute('[data-highlight-ids]', 'data-highlight-ids');
  check('the verse is the page highlight target', hl === '["matthew-5-41"]', String(hl));

  // — A citation that cannot exist lands where it CAN: submitted, not just shown —
  await openDrawer(BASE);
  await page.click('.dw-nav-action');
  await page.fill('.dw-nav-panel input[name="q"]', 'John 3:16666');
  await Promise.all([
    page.waitForURL(/ioannes\/3\/?$/, { timeout: 15000 }),
    page.press('.dw-nav-panel input[name="q"]', 'Enter'),
  ]);
  check('a verse that cannot exist lands on its chapter', /ioannes\/3\/?$/.test(page.url()), page.url());

  await openDrawer(BASE);
  await page.click('.dw-nav-action');
  await page.fill('.dw-nav-panel input[name="q"]', 'John 99:1');
  await Promise.all([
    page.waitForURL(/ioannes\/?$/, { timeout: 15000 }),
    page.press('.dw-nav-panel input[name="q"]', 'Enter'),
  ]);
  check('a chapter that cannot exist lands on the book', /ioannes\/?$/.test(page.url()), page.url());

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

  // — The gloss follows the reader, not the site —
  // The row's name is the Latin spine on every localized index; the second name
  // is the language being read. A German reader typing "2 thessa" must see
  // "2 Thessalonicher", not the English gloss the /en/ leg above checked.
  const de = BASE.replace(/\/(en|de|es|fr|it)\/?$/, '/de/');
  await openDrawer(de);
  await page.click('.dw-nav-action');
  await page.waitForTimeout(600); // the vocabulary lands on this page too
  await page.fill('.dw-nav-panel input[name="q"]', '2 thessa');
  await page.waitForTimeout(200);
  const german = await page.evaluate(() => {
    const a = document.querySelector('.dw-nav-search__answers a');
    return {
      ds: document.querySelector('form.dw-nav-search').getAttribute('data-dwbible-gloss'),
      name: a ? a.querySelector('.dw-shell-subnav__name').textContent : null,
      gloss: a ? a.querySelector('.dw-shell-subnav__gloss').textContent : null,
      href: a ? a.getAttribute('href') : null,
    };
  });
  check('the German drawer glosses in German',
        german.ds === 'bibel' && german.name === '2 Thessalonicenses'
        && german.gloss === '2 Thessalonicher', JSON.stringify(german));
  check('…and its rows stay on the German index',
        /\/de\/biblia\/2-thessalonicenses\/$/.test(german.href || ''), JSON.stringify(german));

  await browser.close();
  console.log(failures ? failures + ' FAILURES' : 'all pass');
  process.exit(failures ? 1 : 0);
})().catch((err) => {
  console.error(err);
  process.exit(1);
});
