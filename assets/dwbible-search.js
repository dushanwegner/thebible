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
 * HOW    The vocabulary (every token that names a book, in every language) is
 *        ONE cached fetch of /bible-books.json, made the first time a reader
 *        opens the field and never again. A query never reaches the origin —
 *        the same shape dwsearch uses on dushanwegner.com.
 * INPUT  window.dwbibleSearchCfg = { pattern, vocabUrl } (localized from PHP —
 *        the pattern is DwBible_Reference::CITATION_PATTERN, so the grammar
 *        has one definition for the server, the index filter and this field).
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
	 * → { q: <normalized book query>, ref: 'ch[:v[-v]]' or '' }
	 */
	function parse(raw) {
		var s = (raw || '').trim();
		var m = CITATION.exec(s);
		if (m && norm(m[1])) {
			var ref = m[2];
			if (m[3]) { ref += ':' + m[3] + (m[4] ? '-' + m[4] : ''); }
			return { q: norm(m[1]), ref: ref };
		}
		return { q: norm(s), ref: '' };
	}

	/** Does this normalized query PREFIX any of these space-separated tokens? */
	function hits(tokens, q) {
		if (!q) { return false; }
		for (var i = 0; i < tokens.length; i++) {
			if (tokens[i] && tokens[i].lastIndexOf(q, 0) === 0) { return true; }
		}
		return false;
	}

	w.DwBibleSearch = { norm: norm, parse: parse, hits: hits };

	// ── The rail's Bible field ───────────────────────────────────────────────
	// Everything below is optional chrome: with JS off, or before the fetch
	// lands, the field is still a plain GET form that the server answers.

	var vocab = null;   // array of token arrays, one per book
	var loading = null; // the in-flight fetch, so N fields share one request

	function loadVocabulary(url) {
		if (vocab) { return Promise.resolve(vocab); }
		if (loading) { return loading; }
		loading = fetch(url, { credentials: 'omit' })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (data) {
				var list = data && data.tokens ? data.tokens : [];
				vocab = list.map(function (line) { return String(line).split(' '); });
				return vocab;
			})
			.catch(function () { vocab = []; return vocab; }); // a failed fetch just means no highlight
		return loading;
	}

	function namesABook(value) {
		if (!vocab || !vocab.length) { return false; }
		var q = parse(value).q;
		if (!q) { return false; }
		for (var i = 0; i < vocab.length; i++) {
			if (hits(vocab[i], q)) { return true; }
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