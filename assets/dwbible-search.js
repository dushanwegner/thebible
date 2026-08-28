/**
 * DW Bible — the book search, in the browser.
 *
 * WHAT   Two things that must agree, so they live in one file:
 *          DwBibleSearch.norm()  — how a typed word is reduced before matching
 *          DwBibleSearch.parse() — splitting "<book> <chapter>[:<verse>]" apart
 *        …and the rail's Bible field, which uses them to say, as the reader
 *        types, whether what they wrote NAMES A BOOK.
 * WHY    "John 1:42" means something; "Zaphod 1:1" does not, and the reader
 *        should not have to press Enter to find that out. The answer is a
 *        quiet check at the end of the field — never an error, because a
 *        half-typed word is not a mistake.
 * HOW    The vocabulary — every token that names a book in every language, and
 *        how many verses each chapter has — is ONE cached fetch of
 *        /bible-books.json, made the first time a reader opens the field and
 *        never again. A query never reaches the origin — the same shape
 *        dwsearch uses on dushanwegner.com.
 * INPUT  window.dwbibleSearchCfg = { pattern } (localized from PHP — it is
 *        DwBible_Reference::CITATION_PATTERN, so the grammar has one definition
 *        for the server, the index filter and this field), and the field's own
 *        data-dwbible-vocab, which carries a STAMPED URL: a payload that grows
 *        a new field must arrive at a new address, or browsers holding the old
 *        one keep answering with it for a day.
 * USED BY the Bible index's inline filter, and the rail's Bible search panel
 *        (dwbible includes/class-dwbible-menu-search.php).
 */
(function (w, d) {
	"use strict";

	var cfg = w.dwbibleSearchCfg || {};
	var CITATION = new RegExp(cfg.pattern || '^(.*?)[\\s.]*(\\d+)\\s*(?:[:,.]\\s*(\\d+)?(?:\\s*[-–—]\\s*(\\d+)?)?)?\\s*$');

	/**
	 * Reduce a word to what matching sees: lowercase, accents folded, the
	 * ligatures Latin actually uses spelled out, everything else dropped.
	 * Mirrors DwBible_Plugin::search_normalize() on the server.
	 */
	function norm(s) {
		return (s || '').toLowerCase()
			.normalize('NFD').replace(/[̀-ͯ]/g, '')
			.replace(/æ/g, 'ae').replace(/œ/g, 'oe').replace(/ß/g, 'ss')
			.replace(/[^a-z0-9]/g, '');
	}

	/**
	 * Split a typed query into its book half and its citation half.
	 * → { q: <normalized book query>, ref: 'ch[:v[-v]]' or '',
	 *     ch, v, vTo: the numbers, or null where nothing was typed }
	 * The separator does not matter — ":" and "," are the same citation, and
	 * "-" opens a range — because that is what the shared grammar says.
	 */
	function parse(raw) {
		var s = (raw || '').trim();
		var m = CITATION.exec(s);
		if (m && norm(m[1])) {
			var ref = m[2];
			if (m[3]) { ref += ':' + m[3] + (m[4] ? '-' + m[4] : ''); }
			return {
				q: norm(m[1]),
				ref: ref,
				ch: m[2] ? parseInt(m[2], 10) : null,
				v: m[3] ? parseInt(m[3], 10) : null,
				vTo: m[4] ? parseInt(m[4], 10) : null
			};
		}
		return { q: norm(s), ref: '', ch: null, v: null, vTo: null };
	}

	/** Does this normalized query PREFIX any of these space-separated tokens? */
	function hits(tokens, q) {
		if (!q) { return false; }
		for (var i = 0; i < tokens.length; i++) {
			if (tokens[i] && tokens[i].lastIndexOf(q, 0) === 0) { return true; }
		}
		return false;
	}

	/**
	 * Could THIS book hold that chapter and verse? `verses` is the book's verse
	 * count per chapter; a book we have no lengths for is trusted.
	 *
	 * A number still being typed is not a wrong number: only what is already
	 * there is judged, and a range's END is never held against its start (on the
	 * way to "5:41-45" the reader passes through "5:41-4", which is not an
	 * error, only unfinished).
	 */
	function canHold(verses, p) {
		if (p.ch === null) { return true; }
		if (!verses || !verses.length) { return true; }
		if (p.ch < 1 || p.ch > verses.length) { return false; }
		var n = verses[p.ch - 1];
		if (p.v === null || !n) { return true; }
		if (p.v < 1 || p.v > n) { return false; }
		if (p.vTo === null) { return true; }
		return p.vTo <= n;
	}

	/**
	 * The largest part of a citation this book can actually be sent to.
	 *
	 * The companion question to canHold(): that one asks whether what was typed
	 * MEANS something, this one asks where it can lead. A verse that does not
	 * exist falls back to its chapter, a chapter that does not exist to the book
	 * — because a link that promises John 3:16666 and lands on a page without it
	 * is worse than one that quietly offers John 3. A half-finished range end
	 * ("24:13-3") is dropped rather than followed, so what a row points at is
	 * valid on every keystroke.
	 *
	 * → 'ch[:v[-v]]', or '' for "just the book".
	 */
	function fit(verses, p) {
		if (p.ch === null) { return ''; }
		if (!verses || !verses.length) { return p.ref; }
		if (p.ch < 1 || p.ch > verses.length) { return ''; }

		var n = verses[p.ch - 1];
		if (p.v === null || !n) { return String(p.ch); }
		if (p.v < 1 || p.v > n) { return String(p.ch); }
		if (p.vTo === null || p.vTo > n || p.vTo < p.v) { return p.ch + ':' + p.v; }
		return p.ch + ':' + p.v + '-' + p.vTo;
	}

	w.DwBibleSearch = { norm: norm, parse: parse, hits: hits, canHold: canHold, fit: fit };

	// ── The rail's Bible field ───────────────────────────────────────────────
	// Everything below is optional chrome: with JS off, or before the fetch
	// lands, the field is still a plain GET form that the server answers.

	var vocab = null;   // [{ tokens: [...], verses: [n per chapter] }] per book
	var loading = null; // the in-flight fetch, so N fields share one request

	function loadVocabulary(url) {
		if (vocab) { return Promise.resolve(vocab); }
		if (loading) { return loading; }
		loading = fetch(url, { credentials: 'omit' })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (data) {
				var tokens = (data && data.tokens) || [];
				var verses = (data && data.verses) || [];
				vocab = tokens.map(function (line, i) {
					var v = verses[i] ? String(verses[i]).split(',') : [];
					return {
						tokens: String(line).split(' '),
						verses: v.map(function (n) { return parseInt(n, 10) || 0; })
					};
				});
				return vocab;
			})
			.catch(function () { vocab = []; return vocab; }); // a failed fetch just means no highlight
		return loading;
	}

	/**
	 * Does this name a book — and, if it carries a citation, one the book could
	 * actually have? "John 4:9999" names a real book and no real verse.
	 * Ambiguity is generous on purpose: "Io 3:16" is plausible if ANY book the
	 * query could mean has a 3:16.
	 */
	function namesABook(value) {
		if (!vocab || !vocab.length) { return false; }
		var p = parse(value);
		if (!p.q) { return false; }
		for (var i = 0; i < vocab.length; i++) {
			if (hits(vocab[i].tokens, p.q) && canHold(vocab[i].verses, p)) { return true; }
		}
		return false;
	}

	function wire(form) {
		var input = form.querySelector('input[name="q"]');
		var url = form.getAttribute('data-dwbible-vocab');
		if (!input || !url) { return; }

		function reflect() {
			form.classList.toggle('is-resolved', namesABook(input.value));
		}

		// The vocabulary is fetched on the first sign of interest, never on page
		// load: a reader who does not search never pays for it.
		function wake() {
			loadVocabulary(url).then(reflect);
		}
		input.addEventListener('focus', wake, { once: true });
		input.addEventListener('input', function () {
			if (!vocab) { wake(); return; }
			reflect();
		});
	}

	function init() {
		var forms = d.querySelectorAll('form.dw-nav-search[data-dwbible-vocab]');
		for (var i = 0; i < forms.length; i++) { wire(forms[i]); }
	}

	if (d.readyState === 'loading') { d.addEventListener('DOMContentLoaded', init); } else { init(); }
})(window, document);