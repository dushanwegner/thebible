<?php
/**
 * DW Bible — the Bible search inside the site menu.
 *
 * WHAT    Fills dwtheme's `dwtheme_navrow_action` slot for ONE row: the drawer's
 *         Bible row grows a flush-right search button, and the panel it opens
 *         under that row is a Bible search — a field, and the books it finds
 *         named under it as the reader types.
 * WHY     Reaching a verse used to mean: open the menu, tap Bible, wait for the
 *         index, open its filter, type. The search belongs where the reader
 *         already is — one tap from anywhere on the site. And a search that can
 *         only answer yes/no leaves the reader to guess WHICH book it understood:
 *         "2 thessa" is easy to type and hard to be sure of until a row says
 *         "2 Thessalonicenses · 2 Thessalonicher".
 * HOW     The panel is a plain GET form whose action is the ROW'S OWN URL and
 *         whose field is `q` — so it needs no JavaScript, works in every
 *         language (the row's URL carries the locale), and is answered by the
 *         resolver the index already runs (see class-dwbible-router →
 *         maybe_redirect_query). One search, one grammar, one resolver: the
 *         suggestions are a shortcut PAST that resolver, never a second one —
 *         pressing Enter still submits the form and lets the server answer.
 * INPUT   The nav item (dwtheme walker). OUTPUT: the action descriptor, or null
 *         for every row that is not the Bible index.
 * USED BY dwtheme inc/nav-action.php — it filters `walker_nav_menu_start_el`,
 *         so the answer here reaches every nav the theme renders.
 *
 * @package dwbible
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class DwBible_Menu_Search {

	/**
	 * Is this menu item the Bible INDEX (as opposed to a book, or anything else)?
	 *
	 * Matched on the URL's last path segment so it holds for every shape the
	 * link takes: the canonical localized `/en/biblia/`, a bare `/bible/`, or
	 * any dataset/combo slug the site has registered. A link that goes deeper
	 * (`/en/biblia/matthaeus/`) is a destination, not the index, and gets no
	 * search of its own.
	 *
	 * @param string $url
	 * @return bool
	 */
	public static function is_index_url( $url ) {
		$path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
		$path = trim( $path, '/' );
		if ( $path === '' ) { return false; }

		$parts = explode( '/', $path );
		$last  = strtolower( (string) end( $parts ) );

		if ( $last === DwBible_Plugin::CANONICAL_SECTION ) { return true; }

		$slugs = (array) apply_filters( 'dwbible_menu_search_slugs', DwBible_Plugin::get_registered_slugs() );
		return in_array( $last, array_map( 'strtolower', $slugs ), true );
	}

	/**
	 * The vocabulary's URL — STAMPED, so a reader is never left judging a
	 * citation against last week's data.
	 *
	 * /bible-books.json answers with `Cache-Control: public, max-age=86400`,
	 * which is right for a file that changes about never — but only if the URL
	 * changes when the content does. It did not, once: the day verse counts
	 * were added to the payload, every browser that had already fetched the old
	 * one kept it for a day and went on judging book names alone, because a
	 * book with no lengths is trusted. The stamp is the plugin's version (the
	 * shape of the payload) plus the mtime of the dataset index (the data in
	 * it), so either kind of change is a new URL and the old copy is simply
	 * never asked for again.
	 *
	 * @return string
	 */
	public static function vocabulary_url() {
		$stamp = defined( 'DWBIBLE_VERSION' ) ? (string) DWBIBLE_VERSION : '0';

		if ( function_exists( 'dwbible_data_dir' ) ) {
			$idx = dwbible_data_dir() . 'latin/json/index.json';
			if ( file_exists( $idx ) ) {
				clearstatcache( true, $idx );
				$stamp .= '.' . (int) filemtime( $idx );
			}
		}

		return add_query_arg( 'v', $stamp, home_url( '/bible-books.json' ) );
	}

	/**
	 * Which language's book names sit beside the Latin ones in the suggestions.
	 *
	 * The rows the field offers are the index page's rows: the Latin spine as
	 * the name ("3 Regum") and the reader's own language as the gloss
	 * ("1 Könige"). Which gloss that is comes from the language the reader is
	 * browsing in — the same answer the index reaches by reading the vernacular
	 * half of its combo slug. Latin-only browsing has no second language and
	 * gets no gloss.
	 *
	 * @return string A dataset key present in /bible-books.json's `gloss`, or ''.
	 */
	public static function gloss_dataset() {
		$lang = function_exists( 'dwi18n_current' ) ? (string) dwi18n_current() : '';
		if ( $lang === '' || $lang === 'la' ) { return ''; }
		if ( ! function_exists( 'dwbible_i18n_dataset_for_lang' ) ) { return ''; }

		$ds = dwbible_i18n_dataset_for_lang( $lang );
		return ( $ds === 'latin' ) ? '' : (string) $ds;
	}

	/**
	 * The panel disclosed under the row: the search field, and the books it finds.
	 *
	 * `.dw-nav-search` is the theme's field-in-a-nav-panel object — the field in
	 * `__field`, the answers it offers in `__answers` — so every navigation
	 * surface knows how to draw it (the rail and its drawer are one shell) and
	 * the plugin says only WHAT the panel holds. The answers are the theme's
	 * `.dw-shell-subnav`, the rule that already governs a list of links
	 * disclosed under a rail row; a suggestion is not a new kind of thing.
	 *
	 * The placeholder is the index filter's own string, so both surfaces teach
	 * the reader the same three forms in the same words.
	 *
	 * @param string $action_url The row's own URL — the index answers `?q=`.
	 * @return string
	 */
	public static function panel( $action_url ) {
		$icon = function_exists( 'dwtheme_menu_icon' ) ? dwtheme_menu_icon( 'search' ) : '';

		// The answers list needs an id for aria-controls. One nav is rendered per
		// page today (the shell is both rail and drawer), but a second one must
		// not silently produce a duplicate id and steal the first field's aria.
		static $seq = 0;
		$list_id = 'dwbible-search-answers-' . ( ++$seq );

		// data-dwbible-vocab is what assets/dwbible-search.js wires itself to: the
		// one cached file that lets the field say, as the reader types, whether
		// what they wrote names a book — and which books those are.
		// data-dwbible-base is what a suggestion's href is built from: the index's
		// own URL, so the rows lead where that index's rows lead, in this language.
		// Without JS none of it exists and the form is a plain GET the server answers.
		$html  = '<form class="dw-nav-search" role="search" method="get" action="' . esc_url( $action_url ) . '"';
		$html .= ' data-dwbible-vocab="' . esc_url( self::vocabulary_url() ) . '"';
		$html .= ' data-dwbible-base="' . esc_url( trailingslashit( $action_url ) ) . '"';
		$html .= ' data-dwbible-gloss="' . esc_attr( self::gloss_dataset() ) . '">';
		$html .= '<div class="dw-nav-search__field">';
		// The field's mark: ONE slot, two states. It is the magnifier until what
		// the reader wrote names a book, and then it is the check — so the
		// answer costs no second glyph beside the browser's own clear button,
		// and nothing moves under the caret. Both are library markup.
		$check = function_exists( 'dwtheme_menu_icon' ) ? dwtheme_menu_icon( 'check' ) : '';
		$html .= '<span class="dw-nav-search__mark" aria-hidden="true">';
		$html .= '<span class="dw-nav-search__mark-search">' . $icon . '</span>';
		$html .= '<span class="dw-nav-search__mark-ok">' . $check . '</span>';
		$html .= '</span>';
		$html .= '<input type="search" name="q"';
		// A shorter placeholder than the index filter's: the field is a rail
		// row wide (~179px at 14px), and a clipped example teaches nothing.
		// Both forms still shown — a book, or a book with a citation.
		$html .= ' placeholder="' . esc_attr__( 'Book or Matthew 5:41…', 'dwbible' ) . '"';
		$html .= ' aria-label="' . esc_attr__( 'Search the Bible by book or reference', 'dwbible' ) . '"';
		// The combobox contract, stated in the markup rather than bolted on by
		// the script: a screen reader is told the field offers a list before a
		// single suggestion exists, and `aria-expanded` is the one thing that
		// changes as they appear.
		$html .= ' role="combobox" aria-autocomplete="list" aria-expanded="false"';
		$html .= ' aria-controls="' . esc_attr( $list_id ) . '"';
		$html .= ' autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" />';
		$html .= '</div>';
		// Empty and hidden until the reader types: the panel opens at the height
		// of the field alone, and the rail does not reserve room for nothing.
		$html .= '<ul class="dw-shell-subnav dw-nav-search__answers" id="' . esc_attr( $list_id ) . '" role="listbox" hidden></ul>';
		$html .= '</form>';

		return $html;
	}

	/**
	 * The `dwtheme_navrow_action` answer for the Bible row.
	 *
	 * @param array|null $action  What an earlier filter decided (respected).
	 * @param WP_Post    $item    The nav item.
	 * @return array|null
	 */
	public static function navrow_action( $action, $item = null ) {
		if ( ! empty( $action ) ) { return $action; }
		if ( ! is_object( $item ) || empty( $item->url ) ) { return $action; }
		if ( ! self::is_index_url( (string) $item->url ) ) { return $action; }

		return [
			'icon'  => 'search',
			'label' => __( 'Search the Bible', 'dwbible' ),
			'panel' => self::panel( (string) $item->url ),
		];
	}
}

add_filter( 'dwtheme_navrow_action', [ 'DwBible_Menu_Search', 'navrow_action' ], 10, 2 );

/**
 * The search module (grammar + the field's behaviour).
 *
 * In the HEAD, not the footer: the Bible index's own filter is inline in the
 * page body and calls into the same grammar, so the module has to exist by the
 * time that script runs. It is ~2 KB and fetches nothing until a reader opens
 * the field.
 */
add_action( 'wp_enqueue_scripts', function () {
	if ( is_admin() ) { return; }

	// __DIR__ is the plugin's includes/ — the asset lives one level up, beside
	// the plugin file, and plugins_url() needs a path INSIDE the plugin root to
	// resolve against (pointing it at this directory yields includes/assets/…).
	$root = dirname( __DIR__ );
	$rel  = 'assets/dwbible-search.js';
	$path = $root . '/' . $rel;
	if ( ! file_exists( $path ) ) { return; }

	wp_enqueue_script(
		'dwbible-search',
		plugins_url( $rel, $root . '/dwbible.php' ),
		[],
		(string) filemtime( $path ),
		false // head
	);
	wp_localize_script( 'dwbible-search', 'dwbibleSearchCfg', [
		// ONE grammar: the server parses `?q=` with this same string.
		'pattern' => DwBible_Reference::CITATION_PATTERN,
	] );
}, 20 );
