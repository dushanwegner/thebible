<?php
/**
 * DW Bible — the Bible search inside the site menu.
 *
 * WHAT    Fills dwtheme's `dwtheme_navrow_action` slot for ONE row: the drawer's
 *         Bible row grows a flush-right search button, and the panel it opens
 *         under that row is a Bible search.
 * WHY     Reaching a verse used to mean: open the menu, tap Bible, wait for the
 *         index, open its filter, type. The search belongs where the reader
 *         already is — one tap from anywhere on the site.
 * HOW     The panel is a plain GET form whose action is the ROW'S OWN URL and
 *         whose field is `q` — so it needs no JavaScript, works in every
 *         language (the row's URL carries the locale), and is answered by the
 *         resolver the index already runs (see class-dwbible-router →
 *         maybe_redirect_query). One search, one grammar, one resolver.
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
	 * The panel disclosed under the row: the search field itself.
	 *
	 * `.dw-nav-search` is the theme's field-in-a-nav-panel object: every
	 * navigation surface knows how to draw it (the rail and its drawer — the
	 * shell is the only one), so the plugin says only WHAT the panel holds.
	 * The placeholder is the index filter's own string, so both surfaces teach
	 * the reader the same three forms in the same words.
	 *
	 * @param string $action_url The row's own URL — the index answers `?q=`.
	 * @return string
	 */
	public static function panel( $action_url ) {
		$icon = function_exists( 'dwtheme_menu_icon' ) ? dwtheme_menu_icon( 'search' ) : '';

		// data-dwbible-vocab is what assets/dwbible-search.js wires itself to: the
		// one cached file that lets the field say, as the reader types, whether
		// what they wrote names a book. Without JS the form is unchanged.
		$html  = '<form class="dw-nav-search" role="search" method="get" action="' . esc_url( $action_url ) . '"';
		$html .= ' data-dwbible-vocab="' . esc_url( self::vocabulary_url() ) . '">';
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
		$html .= ' autocomplete="off" autocapitalize="off" autocorrect="off" spellcheck="false" />';
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
