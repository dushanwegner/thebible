<?php
/**
 * Plugin Name: The Bible
 * Description: Provides /bible/ with links to books; renders selected book HTML using the site's template.
 * Version: 0.1.0
 * Author: DW
 * License: GPL2+
 */

if (!defined('ABSPATH')) { exit; }

class TheBible_Plugin {
    const QV_FLAG = 'thebible';
    const QV_BOOK = 'thebible_book';
    const QV_CHAPTER = 'thebible_ch';
    const QV_VFROM = 'thebible_vfrom';
    const QV_VTO = 'thebible_vto';

    private static $books = null; // array of [order, short_name, filename]
    private static $slug_map = null; // slug => array entry

    public static function init() {
        add_action('init', [__CLASS__, 'add_rewrite_rules']);
        add_filter('query_vars', [__CLASS__, 'add_query_vars']);
        add_action('template_redirect', [__CLASS__, 'handle_template_redirect']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('wp_head', [__CLASS__, 'print_custom_css']);
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);
    }

    private static function inject_nav_helpers($html, $highlight_ids = [], $chapter_scroll_id = null, $book_label = '') {
        if (!is_string($html) || $html === '') return $html;

        // Ensure a stable anchor at the very top of the book content
        if (strpos($html, 'id="thebible-book-top"') === false && strpos($html, 'id=\"thebible-book-top\"') === false) {
            $html = '<a id="thebible-book-top"></a>' . $html;
        }

        // Prepend an up-arrow to the first chapters block linking back to /bible/
        $bible_index = esc_url(trailingslashit(home_url('/bible/')));
        $chap_up = '<a class="thebible-up thebible-up-index" href="' . $bible_index . '" aria-label="Back to Bible">&#8593;</a> ';
        $html = preg_replace(
            '~<p\s+class=(["\"])chapters\1>~',
            '<p class="chapters">' . $chap_up,
            $html,
            1
        );

        // Prepend an up-arrow to verses blocks linking back to top of book, but skip the first (Chapter 1)
        $book_top = '#thebible-book-top';
        $vers_up = '<a class="thebible-up thebible-up-book" href="' . $book_top . '" aria-label="Back to book">&#8593;</a> ';
        $count = 0;
        $html = preg_replace_callback(
            '~<p\s+class=(["\"])verses\1>~',
            function($m) use (&$count, $vers_up) {
                $count++;
                if ($count === 1) {
                    // First verses list (chapter 1): no up-arrow
                    return $m[0];
                }
                return '<p class="verses">' . $vers_up;
            },
            $html
        );

        // Ensure each verse paragraph has a class for styling (IDs use slug-ch-verse)
        $html = preg_replace(
            '~<p\s+id=(["\"])([a-z0-9\-]+-\d+-\d+)\1>~i',
            '<p id="$2" class="verse">',
            $html
        );

        // Add sticky status bar at top (book + current chapter)
        $book_label = is_string($book_label) ? $book_label : '';
        $book_slug_js = esc_js( self::slugify( $book_label ) );
        $book_label_html = esc_html( $book_label );
        $sticky = '<div class="thebible-sticky" data-slug="' . $book_slug_js . '">'
                . '<div class="thebible-sticky__left">'
                . '<span class="thebible-sticky__label" data-label>' . $book_label_html . '</span> '
                . '<span class="thebible-sticky__sep">—</span> '
                . '<span class="thebible-sticky__chapter" data-ch>1</span>'
                . '</div>'
                . '<div class="thebible-sticky__controls">'
                . '<a href="#" class="thebible-ctl thebible-ctl-prev" data-prev aria-label="Previous chapter">&#8592;</a>'
                . '<a href="#thebible-book-top" class="thebible-ctl thebible-ctl-top" data-top aria-label="Top of book">&#8593;</a>'
                . '<a href="#" class="thebible-ctl thebible-ctl-next" data-next aria-label="Next chapter">&#8594;</a>'
                . '</div>'
                . '</div>';
        $html = $sticky . $html;

        // Add highlight styles and scrolling script
        $append = '';
        // Basic styles for sticky and highlight
        $append .= '<style>.thebible .thebible-sticky{position:sticky;top:0;z-index:10;background:#f8f9fa;border-bottom:1px solid rgba(0,0,0,.1);font-size:.9rem;padding:.25rem .5rem;display:flex;align-items:center;justify-content:space-between;gap:.5rem}.thebible .thebible-sticky__controls{display:flex;gap:.5rem;align-items:center}.thebible .thebible-ctl{color:inherit;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;width:1.6rem;height:1.6rem;border-radius:.25rem;border:1px solid rgba(0,0,0,.15)}.thebible .thebible-ctl:is(.is-disabled){opacity:.35;pointer-events:none}.thebible .thebible-btn{font-size:.85rem;border:1px solid rgba(0,0,0,.15);padding:.15rem .4rem;border-radius:.25rem;text-decoration:none;color:inherit;display:inline-block}.thebible .thebible-btn:hover{background:#f1f3f5}.thebible .verse-highlight{background:#fff3cd;padding:0 .2em;border-radius:.15rem;box-shadow:inset 0 0 0 2px #ffe08a}</style>';
        if (is_array($highlight_ids) && !empty($highlight_ids)) {
            $ids_json = wp_json_encode(array_values(array_unique($highlight_ids)));
            // Scroll with 15% viewport offset so verse isn't glued to very top
            $script = '<script>(function(){var ids=' . $ids_json . ';var first=null;ids.forEach(function(id){var el=document.getElementById(id);if(el){el.classList.add("verse-highlight");if(!first) first=el;}});if(first){var r=first.getBoundingClientRect();var y=window.pageYOffset + r.top - (window.innerHeight*0.15);window.scrollTo({top:Math.max(0,y),behavior:"smooth"});}})();</script>';
            $append .= $script;
        } elseif (is_string($chapter_scroll_id) && $chapter_scroll_id !== '') {
            // Chapter-only: scroll to chapter heading accounting for admin bar and sticky bar heights
            $cid = esc_js($chapter_scroll_id);
            $script = '<script>(function(){var id="' . $cid . '";var el=document.getElementById(id);if(!el)return;var bar=document.querySelector(".thebible-sticky");var ab=document.getElementById("wpadminbar");var off=(document.body.classList.contains("admin-bar")&&ab?ab.offsetHeight:0)+(bar?bar.offsetHeight:0);var r=el.getBoundingClientRect();var y=window.pageYOffset + r.top - off;window.scrollTo({top:Math.max(0,y),behavior:"smooth"});})();</script>';
            $append .= $script;
        }
        // Sticky updater script: detect current chapter and update bar on scroll; offset for admin bar
        $append .= '<script>(function(){var bar=document.querySelector(".thebible-sticky");if(!bar)return;var container=document.querySelector(".thebible.thebible-book")||document.querySelector(".thebible .thebible-book");function headsList(){var list=[];if(container){list=Array.prototype.slice.call(container.querySelectorAll("h2[id]"));}else{list=Array.prototype.slice.call(document.querySelectorAll(".thebible .thebible-book h2[id]"));}return list.filter(function(h){return /-ch-\d+$/.test(h.id);});}var heads=headsList();var controls=bar.querySelector(".thebible-sticky__controls");var origControlsHtml=controls?controls.innerHTML:"";var linkPrev=bar.querySelector("[data-prev]");var linkNext=bar.querySelector("[data-next]");var linkTop=bar.querySelector("[data-top]");function setTopOffset(){var ab=document.getElementById("wpadminbar");var off=(document.body.classList.contains("admin-bar")&&ab)?ab.offsetHeight:0;bar.style.top=off+"px";}function disable(el,yes){if(!el)return;if(yes){el.classList.add("is-disabled");el.setAttribute("aria-disabled","true");el.setAttribute("tabindex","-1");}else{el.classList.remove("is-disabled");el.removeAttribute("aria-disabled");el.removeAttribute("tabindex");}}function smoothToEl(el,offsetPx){if(!el)return;var r=el.getBoundingClientRect();var y=window.pageYOffset + r.top - (offsetPx||0);window.scrollTo({top:Math.max(0,y),behavior:"smooth"});}
        function versesList(){var list=[]; if(!container) return list; list=Array.prototype.slice.call(container.querySelectorAll("p[id]")); return list.filter(function(p){return /-\d+-\d+$/.test(p.id);});}
        function getVerseFromNode(node){
            if(!node) return null; var el = (node.nodeType===1? node : node.parentElement);
            while(el && el!==container){ if(el.matches && el.matches("p[id]") && /-\d+-\d+$/.test(el.id)) return el; el = el.parentElement; }
            return null;
        }
        var verses=versesList();
        function selectionInfo(){var sel=window.getSelection && window.getSelection(); if(!sel || sel.rangeCount===0 || sel.isCollapsed) return null; var range=sel.getRangeAt(0);
            // Primary: use closest verse elements from anchor/focus
            var aVerse=getVerseFromNode(sel.anchorNode); var fVerse=getVerseFromNode(sel.focusNode);
            var startIdx=-1, endIdx=-1;
            if(aVerse && fVerse){
                for(var i=0;i<verses.length;i++){ if(verses[i]===aVerse) startIdx=i; if(verses[i]===fVerse) endIdx=i; }
                if(startIdx>-1 && endIdx>-1){ if(startIdx>endIdx){ var t=startIdx; startIdx=endIdx; endIdx=t; }
                }
            }
            // Fallback: intersect ranges
            if(startIdx===-1 || endIdx===-1){
                startIdx=-1; endIdx=-1;
                for(var j=0;j<verses.length;j++){var v=verses[j]; var r=document.createRange(); r.selectNode(v); var intersects= !(range.compareBoundaryPoints(Range.END_TO_START, r) <= 0 || range.compareBoundaryPoints(Range.START_TO_END, r) >= 0); if(intersects){ if(startIdx===-1) startIdx=j; endIdx=j; }}
            }
            if(startIdx===-1) return null; var sid=verses[startIdx].id; var eid=verses[endIdx].id; var sm=sid.match(/-(\d+)-(\d+)$/); var em=eid.match(/-(\d+)-(\d+)$/); if(!sm||!em) return null; return { sCh: parseInt(sm[1],10), sV: parseInt(sm[2],10), eCh: parseInt(em[1],10), eV: parseInt(em[2],10) };
        }
        var selTimer=null; function scheduleUpdate(){ if(selTimer) clearTimeout(selTimer); selTimer=setTimeout(update, 50); }
        function buildRef(info){ if(!info) return ""; var book=bar.querySelector("[data-label]")?bar.querySelector("[data-label]").textContent.trim():""; if(info.sCh===info.eCh){ return book+" "+info.sCh+":"+(info.sV===info.eV? info.sV : info.sV+"-"+info.eV); } return book+" "+info.sCh+":"+info.sV+"-"+info.eCh+":"+info.eV; }
        function buildLink(info){ var base=location.origin + location.pathname.replace(/\/$/,"/"); if(info.sCh===info.eCh){ return base + info.sCh+":"+(info.sV===info.eV? info.sV : info.sV+"-"+info.eV); } return base + info.sCh+":"+info.sV+"-"+info.eCh+":"+info.eV; }
        function copyToClipboard(txt){ if(navigator.clipboard && navigator.clipboard.writeText){ return navigator.clipboard.writeText(txt); } var ta=document.createElement("textarea"); ta.value=txt; document.body.appendChild(ta); ta.select(); try{ document.execCommand("copy"); }finally{ document.body.removeChild(ta);} return Promise.resolve(); }
        function verseText(info){ var out=[]; if(!info) return ""; for(var i=0;i<verses.length;i++){ var pid=verses[i].id; var m=pid.match(/-(\d+)-(\d+)$/); if(!m) continue; var ch=parseInt(m[1],10), v=parseInt(m[2],10); var within=(ch>info.sCh || (ch===info.sCh && v>=info.sV)) && (ch<info.eCh || (ch===info.eCh && v<=info.eV)); if(within){ var body=verses[i].querySelector(".verse-body"); out.push(body? body.textContent.trim() : verses[i].textContent.trim()); } } return out.join(" "); }
        function renderSelectionControls(info){ if(!controls) return; var ref=buildRef(info); var link=buildLink(info); var md="["+ref+"](\n"+link+")"; controls.innerHTML = "copy: <a href=\"#\" data-copy-url>URL</a> <a href=\"#\" data-copy-link>link</a> <a href=\"#\" data-copy-text>verse</a> <a href=\"#\" data-copy-bquote>bquote</a>"; var aUrl=controls.querySelector("[data-copy-url]"); var aLink=controls.querySelector("[data-copy-link]"); var aText=controls.querySelector("[data-copy-text]"); var aBq=controls.querySelector("[data-copy-bquote]"); if(aUrl){ aUrl.addEventListener("click", function(e){ e.preventDefault(); copyToClipboard(link).then(function(){ aUrl.textContent="copied"; setTimeout(function(){aUrl.textContent="URL";},1000); }); }); } if(aLink){ aLink.addEventListener("click", function(e){ e.preventDefault(); copyToClipboard(md).then(function(){ aLink.textContent="copied"; setTimeout(function(){aLink.textContent="link";},1000); }); }); } if(aText){ aText.addEventListener("click", function(e){ e.preventDefault(); var txt=verseText(info); copyToClipboard(txt).then(function(){ aText.textContent="copied"; setTimeout(function(){aText.textContent="verse";},1000); }); }); } if(aBq){ aBq.addEventListener("click", function(e){ e.preventDefault(); var txt=verseText(info); var bq="> "+txt+"\n>\n> – ["+ref+"]("+link+")"; copyToClipboard(bq).then(function(){ aBq.textContent="copied"; setTimeout(function(){aBq.textContent="bquote";},1000); }); }); }
        }
        function ensureStandardControls(){ if(!controls) return; if(controls.innerHTML!==origControlsHtml){ controls.innerHTML=origControlsHtml; bar._bound=false; linkPrev=bar.querySelector("[data-prev]"); linkNext=bar.querySelector("[data-next]"); linkTop=bar.querySelector("[data-top]"); } }
        function update(){if(!heads.length){heads=headsList();} if(!verses.length){verses=versesList();}
            var info=selectionInfo(); var elCh=bar.querySelector("[data-ch]");
            if(info && elCh){ elCh.textContent = buildRef(info).replace(/^.*?\s(.*)$/,"$1"); if(controls) renderSelectionControls(info); }
            else { ensureStandardControls(); }
            var topCut=window.innerHeight*0.2;var current=null;var currentIdx=0;for(var i=0;i<heads.length;i++){var h=heads[i];var r=h.getBoundingClientRect();if(r.top<=topCut){current=h;currentIdx=i;}else{break;}}if(!current){current=heads[0]||null;currentIdx=0;} if(!info){ var ch=1;if(current){var m=current.id.match(/-ch-(\d+)$/);if(m){ch=parseInt(m[1],10)||1;}} if(elCh){ elCh.textContent=String(ch);} }
            // controls
            var ab=document.getElementById("wpadminbar");var off=((document.body.classList.contains("admin-bar")&&ab)?ab.offsetHeight:0) + (bar?bar.offsetHeight:0);
            // prev
            if(currentIdx<=0){
                disable(linkPrev,true); disable(linkTop,true);
                if(linkPrev) linkPrev.href="#";
            } else {
                disable(linkPrev,false); disable(linkTop,false);
                if(linkPrev) linkPrev.href="#"+heads[currentIdx-1].id;
            }
            // next
            if(currentIdx>=heads.length-1){
                disable(linkNext,true);
                if(linkNext) linkNext.href="#";
            } else {
                disable(linkNext,false);
                if(linkNext) linkNext.href="#"+heads[currentIdx+1].id;
            }
            // click handlers (once) — use href target to avoid stale indices
            if(!bar._bound){
                bar._bound=true;
                if(linkPrev) linkPrev.addEventListener("click",function(e){
                    if(this.classList.contains("is-disabled")) return;
                    var hash=this.getAttribute("href")||""; if(!hash || hash==="#") return; e.preventDefault();
                    var id=hash.replace(/^#/,""); var el=document.getElementById(id); smoothToEl(el, off);
                });
                if(linkNext) linkNext.addEventListener("click",function(e){
                    if(this.classList.contains("is-disabled")) return;
                    var hash=this.getAttribute("href")||""; if(!hash || hash==="#") return; e.preventDefault();
                    var id=hash.replace(/^#/,""); var el=document.getElementById(id); smoothToEl(el, off);
                });
                if(linkTop) linkTop.addEventListener("click",function(e){
                    if(this.classList.contains("is-disabled")) return; e.preventDefault();
                    var topEl=document.getElementById("thebible-book-top"); smoothToEl(topEl, off);
                });
            }
        }
        window.addEventListener("scroll",update,{passive:true});
        window.addEventListener("resize",function(){heads=headsList();setTopOffset();update();},{passive:true});
        document.addEventListener("DOMContentLoaded",function(){setTopOffset();update();});
        document.addEventListener("selectionchange", scheduleUpdate, {passive:true});
        document.addEventListener("mouseup", scheduleUpdate, {passive:true});
        document.addEventListener("keyup", scheduleUpdate, {passive:true});
        window.addEventListener("load",function(){setTopOffset();update();});
        setTopOffset();update();})();</script>';
        if ($append !== '') { $html .= $append; }

        return $html;
    }

    public static function activate() {
        self::add_rewrite_rules();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public static function add_rewrite_rules() {
        add_rewrite_rule('^bible/?$', 'index.php?' . self::QV_FLAG . '=1', 'top');
        // /bible/{book}
        add_rewrite_rule('^bible/([^/]+)/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_FLAG . '=1', 'top');
        // /bible/{book}/{chapter}:{verse} or {chapter}:{from}-{to}
        add_rewrite_rule('^bible/([^/]+)/([0-9]+):([0-9]+)(?:-([0-9]+))?/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_CHAPTER . '=$matches[2]&' . self::QV_VFROM . '=$matches[3]&' . self::QV_VTO . '=$matches[4]&' . self::QV_FLAG . '=1', 'top');
        // /bible/{book}/{chapter}
        add_rewrite_rule('^bible/([^/]+)/([0-9]+)/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_CHAPTER . '=$matches[2]&' . self::QV_FLAG . '=1', 'top');
    }

    public static function add_query_vars($vars) {
        $vars[] = self::QV_FLAG;
        $vars[] = self::QV_BOOK;
        $vars[] = self::QV_CHAPTER;
        $vars[] = self::QV_VFROM;
        $vars[] = self::QV_VTO;
        return $vars;
    }

    private static function data_dir() {
        return plugin_dir_path(__FILE__) . 'data/bible_books_html/';
    }

    private static function index_csv_path() {
        return self::data_dir() . 'index.csv';
    }

    private static function load_index() {
        if (self::$books !== null) return;
        self::$books = [];
        self::$slug_map = [];
        $csv = self::index_csv_path();
        if (!file_exists($csv)) return;
        if (($fh = fopen($csv, 'r')) !== false) {
            // skip header
            $header = fgetcsv($fh);
            while (($row = fgetcsv($fh)) !== false) {
                if (count($row) < 3) continue;
                $order = intval($row[0]);
                $short = $row[1];
                $filename = $row[2];
                $entry = [
                    'order' => $order,
                    'short_name' => $short,
                    'filename' => $filename,
                ];
                self::$books[] = $entry;
                $slug = self::slugify($short);
                self::$slug_map[$slug] = $entry;
            }
            fclose($fh);
        }
    }

    private static function slugify($name) {
        $slug = strtolower($name);
        $slug = str_replace([' ', '__'], ['-', '-'], $slug);
        $slug = str_replace(['_', '\\', '/'], ['-', '-', '-'], $slug);
        $slug = preg_replace('/[^a-z0-9\-]+/', '', $slug);
        $slug = preg_replace('/\-+/', '-', $slug);
        return trim($slug, '-');
    }

    private static function book_groups() {
        self::load_index();
        $ot = [];
        $nt = [];
        foreach (self::$books as $b) {
            if ($b['order'] <= 46) $ot[] = $b; else $nt[] = $b;
        }
        return [$ot, $nt];
    }

    public static function handle_template_redirect() {
        $flag = get_query_var(self::QV_FLAG);
        if (!$flag) return;

        // Prepare title and content
        $book_slug = get_query_var(self::QV_BOOK);
        if ($book_slug) {
            self::render_book($book_slug);
        } else {
            self::render_index();
        }
        exit; // prevent WP from continuing
    }

    private static function render_index() {
        self::load_index();
        status_header(200);
        nocache_headers();
        $title = 'The Bible';
        $content = self::build_index_html();
        self::output_with_theme($title, $content, 'index');
    }

    private static function render_book($slug) {
        self::load_index();
        $entry = self::$slug_map[$slug] ?? null;
        if (!$entry) {
            self::render_404();
            return;
        }
        $file = self::data_dir() . $entry['filename'];
        if (!file_exists($file)) {
            self::render_404();
            return;
        }
        $html = file_get_contents($file);
        // Build optional highlight/scroll targets from URL like /book/20:2-4 or /book/20
        $targets = [];
        $chapter_scroll_id = null;
        $ch = absint( get_query_var( self::QV_CHAPTER ) );
        $vf = absint( get_query_var( self::QV_VFROM ) );
        $vt = absint( get_query_var( self::QV_VTO ) );
        $book_slug = self::slugify( $entry['short_name'] );
        if ( $ch && $vf ) {
            if ( ! $vt || $vt < $vf ) { $vt = $vf; }
            for ( $i = $vf; $i <= $vt; $i++ ) {
                $targets[] = $book_slug . '-' . $ch . '-' . $i;
            }
        } elseif ( $ch && ! $vf ) {
            // Chapter-only: scroll to chapter heading id like slug-ch-{ch}
            $chapter_scroll_id = $book_slug . '-ch-' . $ch;
        }
        // Inject navigation helpers and optional highlight/scroll behavior
        $html = self::inject_nav_helpers($html, $targets, $chapter_scroll_id, $entry['short_name']);
        status_header(200);
        nocache_headers();
        $title = $entry['short_name'];
        $content = '<div class="thebible thebible-book">' . $html . '</div>';
        self::output_with_theme($title, $content, 'book');
    }

    private static function render_404() {
        status_header(404);
        nocache_headers();
        if (function_exists('get_header')) get_header();
        echo '<main id="primary" class="site-main container mt-2">'
           . '<h1>Not Found</h1>'
           . '<p>The requested book could not be found.</p>'
           . '</main>';
        if (function_exists('get_footer')) get_footer();
    }

    private static function build_index_html() {
        list($ot, $nt) = self::book_groups();
        $home = home_url('/bible/');
        $out = '<div class="thebible thebible-index">';
        $out .= '<div class="thebible-groups">';
        $out .= '<section class="thebible-group thebible-ot"><h2>Old Testament</h2><ul>';
        foreach ($ot as $b) {
            $slug = self::slugify($b['short_name']);
            $url = trailingslashit($home) . $slug . '/';
            $out .= '<li><a href="' . esc_url($url) . '">' . esc_html($b['short_name']) . '</a></li>';
        }
        $out .= '</ul></section>';
        $out .= '<section class="thebible-group thebible-nt"><h2>New Testament</h2><ul>';
        foreach ($nt as $b) {
            $slug = self::slugify($b['short_name']);
            $url = trailingslashit($home) . $slug . '/';
            $out .= '<li><a href="' . esc_url($url) . '">' . esc_html($b['short_name']) . '</a></li>';
        }
        $out .= '</ul></section>';
        $out .= '</div>';
        $out .= '</div>';
        return $out;
    }

    private static function output_with_theme($title, $content_html, $context = '') {
        // Allow theme override templates (e.g., dwtheme/thebible/...).
        // If a template is found, it is responsible for calling get_header/get_footer and echoing content.
        $context = is_string($context) ? $context : '';
        if ( function_exists('locate_template') ) {
            $thebible_title   = $title;        // available to template
            $thebible_content = $content_html; // available to template
            $thebible_context = $context;      // 'index' | 'book'
            $templates = [];
            if ($context === 'book') {
                $templates = [ 'thebible/single-book.php', 'thebible/thebible.php' ];
            } elseif ($context === 'index') {
                $templates = [ 'thebible/index.php', 'thebible/thebible.php' ];
            } else {
                $templates = [ 'thebible/thebible.php' ];
            }
            $found = locate_template( $templates, false, false );
            if ( $found ) {
                // Load the found template within current scope so our variables are available
                require $found;
                return;
            }
        }

        // Fallback: use plugin's built-in wrapper
        if (function_exists('get_header')) get_header();
        echo '<main id="primary" class="site-main container mt-2">';
        echo '<article class="thebible-article">';
        echo '<header class="entry-header mb-3"><h1 class="entry-title">' . esc_html($title) . '</h1></header>';
        echo '<div class="entry-content">' . $content_html . '</div>';
        echo '</article>';
        echo '</main>';
        if (function_exists('get_footer')) get_footer();
    }

    public static function register_settings() {
        register_setting(
            'thebible_options',
            'thebible_custom_css',
            [
                'type'              => 'string',
                'sanitize_callback' => function( $css ) { return is_string($css) ? $css : ''; },
                'default'           => '',
            ]
        );
    }

    public static function admin_menu() {
        add_menu_page(
            'The Bible',
            'The Bible',
            'manage_options',
            'thebible',
            [ __CLASS__, 'render_settings_page' ],
            'dashicons-book-alt',
            58
        );
    }

    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $css = get_option( 'thebible_custom_css', '' );
        ?>
        <div class="wrap">
            <h1>The Bible</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'thebible_options' ); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="thebible_custom_css">Custom CSS (applied on Bible pages)</label></th>
                            <td>
                                <textarea name="thebible_custom_css" id="thebible_custom_css" class="large-text code" rows="14" style="font-family:monospace;"><?php echo esc_textarea( $css ); ?></textarea>
                                <p class="description">Rendered on /bible and any /bible/{book} pages.</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2>CSS reference</h2>
            <div class="thebible-css-reference" style="max-width:900px;">
                <p>Selectors you can target:</p>
                <ul style="list-style:disc;margin-left:1.2em;">
                    <li><code>.thebible</code> wrapper on all plugin output</li>
                    <li><code>.thebible-index</code> on /bible</li>
                    <li><code>.thebible-book</code> around a rendered book</li>
                    <li><code>.chapters</code> list of chapter links on top of a book</li>
                    <li><code>.verses</code> blocks of verses</li>
                    <li><code>.verse</code> each verse paragraph (added at render time)</li>
                    <li><code>.verse-num</code> the verse number span within a verse paragraph</li>
                    <li><code>.verse-body</code> the verse text span within a verse paragraph</li>
                    <li><code>.verse-num</code> the verse number span within a verse paragraph</li>
                    <li><code>.verse-body</code> the verse text span within a verse paragraph</li>
                    <li><code>.verse-highlight</code> added when a verse is highlighted from a URL fragment</li>
                    <li><code>.thebible-sticky</code> top status bar with chapter info and controls
                        <ul style="list-style:circle;margin-left:1.2em;">
                            <li><code>.thebible-sticky__left</code>, <code>[data-label]</code>, <code>[data-ch]</code></li>
                            <li><code>.thebible-sticky__controls</code> with <code>.thebible-ctl</code> buttons (<code>[data-prev]</code>, <code>[data-top]</code>, <code>[data-next]</code>)</li>
                        </ul>
                    </li>
                    <li><code>.thebible-up</code> small up-arrow links inserted before chapters/verses</li>
                </ul>
                <p>Anchors and IDs:</p>
                <ul style="list-style:disc;margin-left:1.2em;">
                    <li>At very top of each book: <code>#thebible-book-top</code></li>
                    <li>Chapter headings: <code>h2[id^="{book-slug}-ch-"]</code>, e.g. <code>#sophonias-ch-3</code></li>
                    <li>Verse paragraphs: <code>p[id^="{book-slug}-"]</code> with pattern <code>{slug}-{chapter}-{verse}</code>, e.g. <code>#sophonias-3-5</code></li>
                </ul>
            </div>
        </div>
        <?php
    }

    public static function print_custom_css() {
        $is_bible = get_query_var( self::QV_FLAG );
        if ( ! $is_bible ) return;
        $css = get_option( 'thebible_custom_css', '' );
        if ( ! is_string( $css ) || $css === '' ) return;
        echo '<style id="thebible-custom-css">' . $css . '</style>';
    }
}

TheBible_Plugin::init();
