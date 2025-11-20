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
    const QV_SLUG = 'thebible_slug';
    const QV_OG   = 'thebible_og';

    private static $books = null; // array of [order, short_name, filename]
    private static $slug_map = null; // slug => array entry
    private static $abbr_maps = [];

    public static function init() {
        add_action('init', [__CLASS__, 'add_rewrite_rules']);
        add_filter('query_vars', [__CLASS__, 'add_query_vars']);
        add_action('template_redirect', [__CLASS__, 'handle_template_redirect']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_enqueue']);
        add_action('add_meta_boxes', [__CLASS__, 'add_bible_meta_box']);
        add_action('save_post', [__CLASS__, 'save_bible_meta'], 10, 2);
        add_filter('manage_posts_columns', [__CLASS__, 'add_bible_column']);
        add_filter('manage_pages_columns', [__CLASS__, 'add_bible_column']);
        add_action('manage_posts_custom_column', [__CLASS__, 'render_bible_column'], 10, 2);
        add_action('manage_pages_custom_column', [__CLASS__, 'render_bible_column'], 10, 2);
        add_filter('bulk_actions-edit-post', [__CLASS__, 'register_strip_bibleserver_bulk']);
        add_filter('bulk_actions-edit-page', [__CLASS__, 'register_strip_bibleserver_bulk']);
        add_filter('handle_bulk_actions-edit-post', [__CLASS__, 'handle_strip_bibleserver_bulk'], 10, 3);
        add_filter('handle_bulk_actions-edit-page', [__CLASS__, 'handle_strip_bibleserver_bulk'], 10, 3);
        add_filter('upload_mimes', [__CLASS__, 'allow_font_uploads']);
        add_filter('wp_check_filetype_and_ext', [__CLASS__, 'allow_font_filetype'], 10, 5);
        add_action('wp_head', [__CLASS__, 'print_custom_css']);
        add_action('wp_head', [__CLASS__, 'print_og_meta']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('customize_register', [__CLASS__, 'customize_register']);
        add_filter('the_content', [__CLASS__, 'filter_content_auto_link_bible_refs'], 20);
        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);
    }

    private static function u_strlen($s) {
        if (function_exists('mb_strlen')) return mb_strlen($s, 'UTF-8');
        $arr = preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY);
        return is_array($arr) ? count($arr) : strlen((string)$s);
    }

    private static function u_substr($s, $start, $len = null) {
        if (function_exists('mb_substr')) return $len === null ? mb_substr($s, $start, null, 'UTF-8') : mb_substr($s, $start, $len, 'UTF-8');
        $arr = preg_split('//u', (string)$s, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($arr)) return '';
        $slice = array_slice($arr, $start, $len === null ? null : $len);
        return implode('', $slice);
    }

    private static function fit_text_to_area($text, $max_w, $max_h, $font_file, $max_font_size, $min_font_size = 12, $use_ttf_hint = false, $prefix = '', $suffix = '', $line_height_factor = 1.35) {
        $font_size = max($min_font_size, (int)$max_font_size);
        // Try decreasing font size until it fits
        while ($font_size >= $min_font_size) {
            $full = $prefix . $text . $suffix;
            $h = self::measure_text_block($full, $max_w, $font_file, $font_size, $line_height_factor);
            if ($h <= $max_h) return [ $font_size, $full ];
            $font_size -= 2;
        }
        // Still too tall: truncate text with ellipsis at min size, preserving suffix (closing quote)
        $ellipsis = ($use_ttf_hint ? '…' : '...');
        $low = 0; $high = self::u_strlen($text);
        $best_body = '';
        while ($low <= $high) {
            $mid = (int) floor(($low + $high)/2);
            $cand_body = self::u_substr($text, 0, $mid) . $ellipsis;
            $cand_full = $prefix . $cand_body . $suffix;
            $h = self::measure_text_block($cand_full, $max_w, $font_file, $min_font_size, $line_height_factor);
            if ($h <= $max_h) { $best_body = $cand_body; $low = $mid + 1; } else { $high = $mid - 1; }
        }
        if ($best_body === '') { $best_body = $ellipsis; }
        return [ $min_font_size, $prefix . $best_body . $suffix ];
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
        $book_label = is_string($book_label) ? self::pretty_label($book_label) : '';
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
        if (is_array($highlight_ids) && !empty($highlight_ids)) {
            $ids_json = wp_json_encode(array_values(array_unique($highlight_ids)));
            // Scroll with 15% viewport offset so verse isn't glued to very top
            $script = '<script>(function(){var ids=' . $ids_json . ';var first=null;ids.forEach(function(id){var el=document.getElementById(id);if(el){el.classList.add("verse-highlight");if(!first) first=el;}});if(first){var bar=document.querySelector(".thebible-sticky");var ab=document.getElementById("wpadminbar");var off=(document.body.classList.contains("admin-bar")&&ab?ab.offsetHeight:0)+(bar?bar.offsetHeight:0)+25;var r=first.getBoundingClientRect();var y=window.pageYOffset + r.top - off;window.scrollTo({top:Math.max(0,y),behavior:"smooth"});}})();</script>';
            $append .= $script;
        } elseif (is_string($chapter_scroll_id) && $chapter_scroll_id !== '') {
            // Chapter-only: scroll to chapter heading accounting for admin bar and sticky bar heights
            $cid = esc_js($chapter_scroll_id);
            $script = '<script>(function(){var id="' . $cid . '";var el=document.getElementById(id);if(!el)return;var bar=document.querySelector(".thebible-sticky");var ab=document.getElementById("wpadminbar");var off=(document.body.classList.contains("admin-bar")&&ab?ab.offsetHeight:0)+(bar?bar.offsetHeight:0)+25;var r=el.getBoundingClientRect();var y=window.pageYOffset + r.top - off;window.scrollTo({top:Math.max(0,y),behavior:"smooth"});})();</script>';
            $append .= $script;
        }
        // Sticky updater script: detect current chapter and update bar on scroll; offset for admin bar
        $append .= '<script>(function(){var bar=document.querySelector(".thebible-sticky");if(!bar)return;var container=document.querySelector(".thebible.thebible-book")||document.querySelector(".thebible .thebible-book");function headsList(){var list=[];if(container){list=Array.prototype.slice.call(container.querySelectorAll("h2[id]"));}else{list=Array.prototype.slice.call(document.querySelectorAll(".thebible .thebible-book h2[id]"));}return list.filter(function(h){return /-ch-\d+$/.test(h.id);});}var heads=headsList();var controls=bar.querySelector(".thebible-sticky__controls");var origControlsHtml=controls?controls.innerHTML:"";var linkPrev=bar.querySelector("[data-prev]");var linkNext=bar.querySelector("[data-next]");var linkTop=bar.querySelector("[data-top]");function setTopOffset(){var ab=document.getElementById("wpadminbar");var off=(document.body.classList.contains("admin-bar")&&ab)?ab.offsetHeight:0;if(off>0){bar.style.top=off+"px";}else{bar.style.top="";}}function disable(el,yes){if(!el)return;if(yes){el.classList.add("is-disabled");el.setAttribute("aria-disabled","true");el.setAttribute("tabindex","-1");}else{el.classList.remove("is-disabled");el.removeAttribute("aria-disabled");el.removeAttribute("tabindex");}}function smoothToEl(el,offsetPx){if(!el)return;var r=el.getBoundingClientRect();var y=window.pageYOffset + r.top - (offsetPx||0);window.scrollTo({top:Math.max(0,y),behavior:"smooth"});}
        // Flash highlight on in-page verse link clicks
        document.addEventListener("click",function(e){var a=e.target && e.target.closest && e.target.closest("a[href*=\"#\"]");if(!a)return;var href=a.getAttribute("href")||"";var hashIndex=href.indexOf("#");if(hashIndex===-1)return;var id=href.slice(hashIndex+1);if(!id)return;var tgt=document.getElementById(id);if(!tgt)return;var verse=null;if(tgt.matches&&tgt.matches("p")){verse=tgt;}else if(tgt.closest){var p=tgt.closest("p");if(p) verse=p;}if(!verse)return;verse.classList.add("verse");setTimeout(function(){verse.classList.remove("verse-flash");void verse.offsetWidth;verse.classList.add("verse-flash");setTimeout(function(){verse.classList.remove("verse-flash");},2000);},0);},true);
        function currentOffset(){var ab=document.getElementById("wpadminbar");var abH=(document.body.classList.contains("admin-bar")&&ab)?ab.offsetHeight:0;var barH=bar?bar.offsetHeight:0;return abH+barH+25;}
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
        function buildLink(info){
            var base = location.origin + location.pathname
                .replace(/\/?(\d+(?::\d+(?:-\d+)?)?)\/?$/, "/") // strip any trailing chapter/verse segment
                .replace(/#.*$/, ""); // strip hash fragment just in case
            if(info.sCh===info.eCh){
                return base + info.sCh+":"+(info.sV===info.eV? info.sV : info.sV+"-"+info.eV);
            }
            return base + info.sCh+":"+info.sV+"-"+info.eCh+":"+info.eV;
        }
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
            var off=currentOffset();
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
        // Intercept in-content anchor clicks to scroll below sticky
        document.addEventListener("click", function(e){
            var a = e.target.closest("a[href^=#]");
            if(!a) return;
            var href = a.getAttribute("href") || "";
            if(!href || href === "#") return;
            var id = href.replace(/^#/, "");
            var el = document.getElementById(id);
            if(!el) return;
            e.preventDefault();
            smoothToEl(el, currentOffset());
            // If this is a verse id like book-CH-V, update the URL to /book/CH:V
            var m = id.match(/-(\d+)-(\d+)$/);
            if (history && history.replaceState && m) {
                var ch = m[1], v = m[2];
                var base = location.origin + location.pathname
                    .replace(/\/?(\d+(?::\d+(?:-\d+)?)?)\/?$/, "/") // strip any trailing chapter/verse
                    .replace(/#.*$/, "");
                history.replaceState(null, "", base + ch + ":" + v);
            } else if (history && history.replaceState) {
                history.replaceState(null, "", "#" + id);
            }
        }, {passive:false});
        // Adjust on hash navigation
        window.addEventListener("hashchange", function(){
            var id = location.hash.replace(/^#/, "");
            var el = document.getElementById(id);
            if(el) {
                smoothToEl(el, currentOffset());
                var m = id.match(/-(\d+)-(\d+)$/);
                if (history && history.replaceState && m) {
                    var ch = m[1], v = m[2];
                    var base = location.origin + location.pathname
                        .replace(/\/?(\d+(?::\d+(?:-\d+)?)?)\/?$/, "/");
                    history.replaceState(null, "", base + ch + ":" + v);
                }
            }
        });
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
        $slugs = self::base_slugs();
        foreach ($slugs as $slug) {
            $slug = trim($slug, "/ ");
            if ($slug === '') continue;
            // index
            add_rewrite_rule('^' . preg_quote($slug, '/') . '/?$', 'index.php?' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug, 'top');
            // /{slug}/{book}
            add_rewrite_rule('^' . preg_quote($slug, '/') . '/([^/]+)/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug, 'top');
            // /{slug}/{book}/{chapter}:{verse} or {chapter}:{from}-{to}
            add_rewrite_rule('^' . preg_quote($slug, '/') . '/([^/]+)/([0-9]+):([0-9]+)(?:-([0-9]+))?/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_CHAPTER . '=$matches[2]&' . self::QV_VFROM . '=$matches[3]&' . self::QV_VTO . '=$matches[4]&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug, 'top');
            // /{slug}/{book}/{chapter}
            add_rewrite_rule('^' . preg_quote($slug, '/') . '/([^/]+)/([0-9]+)/?$', 'index.php?' . self::QV_BOOK . '=$matches[1]&' . self::QV_CHAPTER . '=$matches[2]&' . self::QV_FLAG . '=1&' . self::QV_SLUG . '=' . $slug, 'top');
        }
    }

    public static function enqueue_assets() {
        // Enqueue styles only on plugin routes
        $is_bible = ! empty( get_query_var( self::QV_FLAG ) )
            || ! empty( get_query_var( self::QV_BOOK ) )
            || ! empty( get_query_var( self::QV_SLUG ) );
        if ( $is_bible ) {
            $css_url = plugins_url( 'assets/thebible.css', __FILE__ );
            wp_enqueue_style( 'thebible-styles', $css_url, [], '0.1.0' );
        }
    }

    public static function add_query_vars($vars) {
        $vars[] = self::QV_FLAG;
        $vars[] = self::QV_BOOK;
        $vars[] = self::QV_CHAPTER;
        $vars[] = self::QV_VFROM;
        $vars[] = self::QV_VTO;
        $vars[] = self::QV_SLUG;
        $vars[] = self::QV_OG;
        return $vars;
    }

    private static function data_root_dir() {
        // New structure: data/{slug}/ with html/ and text/ subfolders
        $slug = get_query_var(self::QV_SLUG);
        if (!is_string($slug) || $slug === '') { $slug = 'bible'; }
        $root = plugin_dir_path(__FILE__) . 'data/' . $slug . '/';
        if (is_dir($root)) return $root;
        return null;
    }

    private static function html_dir() {
        $root = self::data_root_dir();
        if ($root) {
            $h = trailingslashit($root) . 'html/';
            if (is_dir($h)) return $h;
        }
        // Back-compat: old layout data/{slug}_books_html/
        $slug = get_query_var(self::QV_SLUG);
        if (!is_string($slug) || $slug === '') { $slug = 'bible'; }
        $old = plugin_dir_path(__FILE__) . 'data/' . $slug . '_books_html/';
        if (is_dir($old)) return $old;
        // Fallback to default English
        $fallback = plugin_dir_path(__FILE__) . 'data/bible_books_html/';
        return $fallback;
    }

    private static function index_csv_path() {
        return self::html_dir() . 'index.csv';
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
                $display = '';
                $filename = '';
                // New format: order, short_name, display_name, filename, ...
                if (count($row) >= 4) {
                    $display = isset($row[2]) ? $row[2] : '';
                    $filename = isset($row[3]) ? $row[3] : (isset($row[2]) ? $row[2] : '');
                } else {
                    // Old format: order, short_name, filename, ...
                    $filename = $row[2];
                }
                $entry = [
                    'order' => $order,
                    'short_name' => $short,
                    'display_name' => $display,
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

    private static function get_abbreviation_map($slug) {
        if (isset(self::$abbr_maps[$slug])) {
            return self::$abbr_maps[$slug];
        }
        $map = [];
        $lang = ($slug === 'bibel') ? 'de' : 'en';
        $file = plugin_dir_path(__FILE__) . 'data/' . $slug . '/abbreviations.' . $lang . '.json';
        if (file_exists($file)) {
            $raw = file_get_contents($file);
            $data = json_decode($raw, true);
            if (is_array($data) && !empty($data['books']) && is_array($data['books'])) {
                foreach ($data['books'] as $short => $variants) {
                    if (!is_array($variants)) continue;
                    foreach ($variants as $v) {
                        $key = trim(mb_strtolower((string)$v, 'UTF-8'));
                        if ($key === '') continue;
                        // First writer wins; avoid clobbering in case of collisions.
                        if (!isset($map[$key])) {
                            $map[$key] = (string)$short;
                        }
                    }
                }
            }
        }
        self::$abbr_maps[$slug] = $map;
        return $map;
    }

    private static function pretty_label($short_name) {
        if (!is_string($short_name)) return '';
        $label = $short_name;
        // Convert underscores to spaces by default
        $label = str_replace('_', ' ', $label);
        // Leading numeral becomes 'N. '
        $label = preg_replace('/^(\d+)\s+/', '$1. ', $label);
        // Specific compounds get a slash separator
        $label = preg_replace('/\bKings\s+Samuel\b/', 'Kings / Samuel', $label);
        $label = preg_replace('/\bEsdras\s+Nehemias\b/', 'Esdras / Nehemias', $label);
        // normalize whitespace
        $label = preg_replace('/\s+/', ' ', $label);
        return trim($label);
    }

    public static function add_bible_meta_box() {
        $screens = get_post_types(['public' => true], 'names');
        foreach ($screens as $post_type) {
            add_meta_box(
                'thebible_meta',
                __('Bible for references', 'thebible'),
                [__CLASS__, 'render_bible_meta_box'],
                $post_type,
                'side',
                'default'
            );
        }
    }

    public static function render_bible_meta_box($post) {
        wp_nonce_field('thebible_meta_save', 'thebible_meta_nonce');
        $current = get_post_meta($post->ID, 'thebible_slug', true);
        if (!is_string($current) || $current === '') {
            $current = 'bible';
        }
        $options = [
            'bible' => __('English (Douay-Rheims)', 'thebible'),
            'bibel' => __('Deutsch (Menge)', 'thebible'),
        ];
        echo '<p><label for="thebible_slug_field">' . esc_html__('Use this Bible when auto-linking references in this content.', 'thebible') . '</label></p>';
        echo '<select name="thebible_slug_field" id="thebible_slug_field" class="widefat">';
        foreach ($options as $slug => $label) {
            echo '<option value="' . esc_attr($slug) . '"' . selected($current, $slug, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
    }

    public static function save_bible_meta($post_id, $post) {
        if (!isset($_POST['thebible_meta_nonce']) || !wp_verify_nonce($_POST['thebible_meta_nonce'], 'thebible_meta_save')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;
        if (!isset($_POST['thebible_slug_field'])) return;
        $val = sanitize_text_field(wp_unslash($_POST['thebible_slug_field']));
        if ($val !== 'bible' && $val !== 'bibel') {
            delete_post_meta($post_id, 'thebible_slug');
            return;
        }
        update_post_meta($post_id, 'thebible_slug', $val);
    }

    public static function add_bible_column($columns) {
        if (!is_array($columns)) return $columns;
        $columns['thebible_slug'] = __('Bible', 'thebible');
        return $columns;
    }

    public static function render_bible_column($column, $post_id) {
        if ($column !== 'thebible_slug') return;
        $slug = get_post_meta($post_id, 'thebible_slug', true);
        if ($slug === 'bibel') {
            echo esc_html__('Deutsch (Menge)', 'thebible');
        } elseif ($slug === 'bible') {
            echo esc_html__('English (Douay-Rheims)', 'thebible');
        } else {
            echo '&#8212;';
        }
    }

    public static function register_strip_bibleserver_bulk($bulk_actions) {
        if (!is_array($bulk_actions)) return $bulk_actions;
        $bulk_actions['thebible_strip_bibleserver'] = __('Strip BibleServer links', 'thebible');
        $bulk_actions['thebible_set_bible'] = __('Set Bible: English (Douay-Rheims)', 'thebible');
        $bulk_actions['thebible_set_bibel'] = __('Set Bible: Deutsch (Menge)', 'thebible');
        return $bulk_actions;
    }

    private static function strip_bibleserver_links_from_content($content) {
        if (!is_string($content) || $content === '') return $content;
        // HTML links generated by the editor
        $pattern_html = '~<a\\s+[^>]*href=["\']https?://(?:www\\.)?bibleserver\\.com/[^"\']*["\'][^>]*>(.*?)</a>~is';
        $content = preg_replace($pattern_html, '$1', $content);

        // Raw Markdown-style links that may still be present in content
        // e.g. *[Matthäus 5:27-28](https://www.bibleserver.com/EU/Matth%C3%A4us5%2C27-28)*
        $pattern_md = '~\[([^\]]+)\]\(\s*https?://(?:www\.)?bibleserver\.com/[^\s\)]+\s*\)~i';
        $content = preg_replace($pattern_md, '$1', $content);

        return $content;
    }

    public static function handle_strip_bibleserver_bulk($redirect_to, $doaction, $post_ids) {
        if (!is_array($post_ids)) {
            return $redirect_to;
        }

        // Action 1: strip BibleServer links from content
        if ($doaction === 'thebible_strip_bibleserver') {
            $count = 0;
            foreach ($post_ids as $post_id) {
                $post = get_post($post_id);
                if (!$post || $post->post_type === 'revision') continue;
                $old = $post->post_content;
                $new = self::strip_bibleserver_links_from_content($old);
                if ($new !== $old) {
                    wp_update_post([
                        'ID' => $post_id,
                        'post_content' => $new,
                    ]);
                    $count++;
                }
            }
            if ($count > 0) {
                $redirect_to = add_query_arg('thebible_stripped_bibleserver', $count, $redirect_to);
            }
            return $redirect_to;
        }

        // Action 2/3: bulk set Bible slug meta
        if ($doaction === 'thebible_set_bible' || $doaction === 'thebible_set_bibel') {
            $target = ($doaction === 'thebible_set_bibel') ? 'bibel' : 'bible';
            $count = 0;
            foreach ($post_ids as $post_id) {
                $post = get_post($post_id);
                if (!$post || $post->post_type === 'revision') continue;
                update_post_meta($post_id, 'thebible_slug', $target);
                $count++;
            }
            if ($count > 0) {
                $key = ($target === 'bibel') ? 'thebible_set_bibel' : 'thebible_set_bible';
                $redirect_to = add_query_arg($key, $count, $redirect_to);
            }
            return $redirect_to;
        }

        return $redirect_to;
    }

    public static function filter_content_auto_link_bible_refs($content) {
        if (!is_string($content) || $content === '') return $content;
        if (is_feed() || is_admin()) return $content;

        $post_id = get_the_ID();
        if (!$post_id) return $content;

        $slug = get_post_meta($post_id, 'thebible_slug', true);
        if (!is_string($slug) || $slug === '') {
            $slug = 'bible';
        }
        if ($slug !== 'bible' && $slug !== 'bibel') {
            $slug = 'bible';
        }

        $abbr = self::get_abbreviation_map($slug);
        if (empty($abbr)) return $content;

        // Book token (group 1): optional leading number ("1.", "2"), then one or more words of letters (incl. umlauts) and dots.
        // Then space(s), chapter, colon, verse, optional dash and verse.
        $pattern = '/\b('
                 . '(?:[0-9]{1,2}\.?)?\s*'                 // optional leading number like "1." or "2"
                 . '[A-Za-zÄÖÜäöüß]+'                         // first word letters
                 . '[A-Za-zÄÖÜäöüß\.] *'                     // allow abbreviation with dot and trailing spaces
                 . '(?:\s+[A-Za-zÄÖÜäöüß\.0-9]+)*'          // optional extra words
                 . ')\s+(\d+):(\d+)(?:-(\d+))?/u';

        $content = preg_replace_callback(
            $pattern,
            function ($m) use ($slug, $abbr) {
                if (!isset($m[1], $m[2], $m[3])) return $m[0];
                $book_raw = $m[1];
                $ch = (int)$m[2];
                $vf = (int)$m[3];
                $vt = isset($m[4]) && $m[4] !== '' ? (int)$m[4] : 0;
                if ($ch <= 0 || $vf <= 0) return $m[0];

                // Normalize book token: strip trailing dot, collapse spaces
                $norm = preg_replace('/\.\s*$/u', '', $book_raw);
                $norm = preg_replace('/\s+/u', ' ', trim((string)$norm));
                $key = mb_strtolower($norm, 'UTF-8');

                // Try exact key
                $short = null;
                if ($key !== '' && isset($abbr[$key])) {
                    $short = $abbr[$key];
                } else {
                    // Fallback: strip a dot directly after a leading number (e.g. "1. Mose" -> "1 Mose")
                    $alt = preg_replace('/^(\d+)\.\s*/u', '$1 ', $norm);
                    $alt = preg_replace('/\s+/u', ' ', trim((string)$alt));
                    $alt_key = mb_strtolower($alt, 'UTF-8');
                    if ($alt_key !== '' && isset($abbr[$alt_key])) {
                        $short = $abbr[$alt_key];
                    }
                }

                if ($short === null) {
                    return $m[0];
                }

                $book_slug = self::slugify($short);
                if ($book_slug === '') return $m[0];

                $base = home_url('/' . trim($slug, '/') . '/' . $book_slug . '/');
                if ($vt && $vt >= $vf) {
                    $url = $base . $ch . ':' . $vf . '-' . $vt;
                } else {
                    $url = $base . $ch . ':' . $vf;
                }

                $ref_text = $m[0];
                return '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($ref_text) . '</a>';
            },
            $content
        );

        return $content;
    }

    private static function book_groups() {
        self::load_index();
        $ot = [];
        $nt = [];
        // Detect NT boundary dynamically by first occurrence of Matthew across locales
        $nt_slug_candidates = ['matthew','matthaeus'];
        $nt_start_order = null;
        foreach (self::$books as $b) {
            $slug = self::slugify($b['short_name']);
            if (in_array($slug, $nt_slug_candidates, true)) {
                $nt_start_order = intval($b['order']);
                break;
            }
        }
        foreach (self::$books as $b) {
            if ($nt_start_order !== null) {
                if (intval($b['order']) < $nt_start_order) $ot[] = $b; else $nt[] = $b;
            } else {
                // Fallback to legacy threshold
                if ($b['order'] <= 46) $ot[] = $b; else $nt[] = $b;
            }
        }
        return [$ot, $nt];
    }

    public static function handle_template_redirect() {
        $flag = get_query_var(self::QV_FLAG);
        if (!$flag) return;

        // Serve Open Graph image when requested
        $og = get_query_var(self::QV_OG);
        if ($og) {
            self::render_og_image();
            exit;
        }

        // Prepare title and content
        $book_slug = get_query_var(self::QV_BOOK);
        if ($book_slug) {
            self::render_book($book_slug);
        } else {
            self::render_index();
        }
        exit; // prevent WP from continuing
    }

    private static function get_book_entry_by_slug($slug) {
        self::load_index();
        return self::$slug_map[$slug] ?? null;
    }

    private static function extract_verse_text($entry, $ch, $vf, $vt) {
        if (!$entry || !is_array($entry)) return '';
        $file = self::html_dir() . $entry['filename'];
        if (!file_exists($file)) return '';
        $html = (string) file_get_contents($file);
        if ($html === '') return '';
        $book_slug = self::slugify($entry['short_name']);
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);
        $parts = [];
        for ($i = $vf; $i <= $vt; $i++) {
            $id = $book_slug . '-' . $ch . '-' . $i;
            $nodes = $xp->query('//*[@id="' . $id . '"]');
            if ($nodes && $nodes->length) {
                $p = $nodes->item(0);
                $body = null;
                foreach ($p->getElementsByTagName('span') as $span) {
                    if ($span->hasAttribute('class') && strpos($span->getAttribute('class'), 'verse-body') !== false) { $body = $span; break; }
                }
                $txt = $body ? trim($body->textContent) : trim($p->textContent);
                $txt = self::normalize_whitespace($txt);
                if ($txt !== '') $parts[] = $txt;
            }
        }
        return trim(implode(' ', $parts));
    }

    private static function normalize_whitespace($s) {
        // Replace various Unicode spaces/invisibles with normal space or remove, collapse, and trim
        $s = (string)$s;
        // Map a set of known invisibles to spaces or empty
        $map = [
            "\xC2\xA0" => ' ', // NBSP U+00A0
            "\xC2\xAD" => '',  // Soft hyphen U+00AD
            "\xE1\x9A\x80" => ' ', // OGHAM space mark U+1680
            "\xE2\x80\x80" => ' ', // En quad U+2000
            "\xE2\x80\x81" => ' ', // Em quad U+2001
            "\xE2\x80\x82" => ' ', // En space U+2002
            "\xE2\x80\x83" => ' ', // Em space U+2003
            "\xE2\x80\x84" => ' ', // Three-per-em space U+2004
            "\xE2\x80\x85" => ' ', // Four-per-em space U+2005
            "\xE2\x80\x86" => ' ', // Six-per-em space U+2006
            "\xE2\x80\x87" => ' ', // Figure space U+2007
            "\xE2\x80\x88" => ' ', // Punctuation space U+2008
            "\xE2\x80\x89" => ' ', // Thin space U+2009
            "\xE2\x80\x8A" => ' ', // Hair space U+200A
            "\xE2\x80\x8B" => '',  // Zero width space U+200B
            "\xE2\x80\x8C" => '',  // Zero width non-joiner U+200C
            "\xE2\x80\x8D" => '',  // Zero width joiner U+200D
            "\xE2\x80\x8E" => '',  // LRM U+200E
            "\xE2\x80\x8F" => '',  // RLM U+200F
            "\xE2\x80\xA8" => ' ', // Line separator U+2028
            "\xE2\x80\xA9" => ' ', // Paragraph separator U+2029
            "\xE2\x80\xAF" => ' ', // Narrow no-break space U+202F
            "\xE2\x81\xA0" => ' ', // Word joiner U+2060
            "\xEF\xBB\xBF" => '',  // BOM U+FEFF
        ];
        $s = strtr($s, $map);
        // Collapse whitespace
        $s = preg_replace('/\s+/u', ' ', $s);
        // Trim and ensure no trailing space remains before closing quotes
        $s = trim($s);
        return $s;
    }

    private static function hex_to_color($im, $hex) {
        $hex = trim((string)$hex);
        if (preg_match('/^#?([0-9a-f]{6})$/i', $hex, $m)) {
            $rgb = $m[1];
            $r = hexdec(substr($rgb,0,2));
            $g = hexdec(substr($rgb,2,2));
            $b = hexdec(substr($rgb,4,2));
            return imagecolorallocate($im, $r, $g, $b);
        }
        return imagecolorallocate($im, 0, 0, 0);
    }

    private static function og_cache_dir() {
        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . 'thebible-og-cache/';
        if (!is_dir($dir)) { wp_mkdir_p($dir); }
        return $dir;
    }

    private static function og_cache_purge() {
        $dir = self::og_cache_dir();
        if (!is_dir($dir)) return 0;
        $count = 0;
        $it = @scandir($dir);
        if (!$it) return 0;
        foreach ($it as $f) {
            if ($f === '.' || $f === '..') continue;
            $p = $dir . $f;
            if (is_file($p)) { @unlink($p); $count++; }
        }
        return $count;
    }

    private static function draw_text_block($im, $text, $x, $y, $max_w, $font_file, $font_size, $color, $max_bottom=null, $align='left', $line_height_factor = 1.35) {
        $use_ttf = (is_string($font_file) && $font_file !== '' && function_exists('imagettfbbox') && function_exists('imagettftext') && file_exists($font_file));
        if (! $use_ttf) {
            $font = 5;
            $cw = imagefontwidth($font);
            $ch = imagefontheight($font);
            $line_h = max($ch, (int) floor($ch * $line_height_factor));
            $max_chars = max(1, (int) floor($max_w / $cw));
            $words = preg_split('/\s+/', $text);
            $line = '';
            $used_h = 0;
            foreach ($words as $wrd) {
                $try = $line === '' ? $wrd : ($line . ' ' . $wrd);
                if (strlen($try) > $max_chars) {
                    $draw_x = $x;
                    if ($align === 'right') {
                        $line_w = strlen($line) * $cw;
                        $draw_x = $x + max(0, $max_w - $line_w);
                    }
                    imagestring($im, $font, $draw_x, $y + $used_h, $line, $color);
                    $used_h += $line_h;
                    if ($max_bottom !== null && ($y + $used_h + $ch) > $max_bottom) return $used_h;
                    $line = $wrd;
                } else {
                    $line = $try;
                }
            }
            if ($line !== '') { 
                $draw_x = $x;
                if ($align === 'right') {
                    $line_w = strlen($line) * $cw;
                    $draw_x = $x + max(0, $max_w - $line_w);
                }
                imagestring($im, $font, $draw_x, $y + $used_h, $line, $color); 
                $used_h += $line_h; 
            }
            return $used_h;
        }
        $line_h = (int) floor($font_size * $line_height_factor);
        $words = preg_split('/\s+/', $text);
        $line = '';
        $used_h = 0;
        foreach ($words as $wrd) {
            $try = $line === '' ? $wrd : ($line . ' ' . $wrd);
            $box = imagettfbbox($font_size, 0, $font_file, $try);
            $width = abs($box[2]-$box[0]);
            if ($width > $max_w) {
                $line_box = imagettfbbox($font_size, 0, $font_file, $line);
                $line_w = abs($line_box[2]-$line_box[0]);
                $draw_x = ($align === 'right') ? ($x + max(0, $max_w - $line_w)) : $x;
                imagettftext($im, $font_size, 0, $draw_x, $y + $used_h + $line_h, $color, $font_file, $line);
                $used_h += $line_h;
                if ($max_bottom !== null && ($y + $used_h + $line_h) > $max_bottom) return $used_h;
                $line = $wrd;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') {
            $line_box = imagettfbbox($font_size, 0, $font_file, $line);
            $line_w = abs($line_box[2]-$line_box[0]);
            $draw_x = ($align === 'right') ? ($x + max(0, $max_w - $line_w)) : $x;
            imagettftext($im, $font_size, 0, $draw_x, $y + $used_h + $line_h, $color, $font_file, $line);
            $used_h += $line_h;
        }
        return $used_h;
    }

    private static function measure_text_block($text, $max_w, $font_file, $font_size, $line_height_factor = 1.35) {
        $use_ttf = (is_string($font_file) && $font_file !== '' && function_exists('imagettfbbox') && function_exists('imagettftext') && file_exists($font_file));
        if (! $use_ttf) {
            $font = 5;
            $cw = imagefontwidth($font);
            $ch = imagefontheight($font);
            $line_h = max($ch, (int) floor($ch * $line_height_factor));
            $max_chars = max(1, (int) floor($max_w / $cw));
            $words = preg_split('/\s+/', $text);
            $line = '';
            $used_h = 0;
            foreach ($words as $wrd) {
                $try = $line === '' ? $wrd : ($line . ' ' . $wrd);
                if (strlen($try) > $max_chars) {
                    $used_h += $line_h;
                    $line = $wrd;
                } else {
                    $line = $try;
                }
            }
            if ($line !== '') { $used_h += $line_h; }
            return $used_h;
        }
        $line_h = (int) floor($font_size * $line_height_factor);
        $words = preg_split('/\s+/', $text);
        $line = '';
        $used_h = 0;
        foreach ($words as $wrd) {
            $try = $line === '' ? $wrd : ($line . ' ' . $wrd);
            $box = imagettfbbox($font_size, 0, $font_file, $try);
            $width = abs($box[2]-$box[0]);
            if ($width > $max_w) {
                $used_h += $line_h;
                $line = $wrd;
            } else {
                $line = $try;
            }
        }
        if ($line !== '') { $used_h += $line_h; }
        return $used_h;
    }

    private static function render_og_image() {
        $enabled = get_option('thebible_og_enabled', '1');
        if ($enabled !== '1' && $enabled !== 1) { status_header(404); exit; }
        if (!function_exists('imagecreatetruecolor')) { status_header(500); exit; }

        $book_slug = get_query_var(self::QV_BOOK);
        $ch = absint( get_query_var( self::QV_CHAPTER ) );
        $vf = absint( get_query_var( self::QV_VFROM ) );
        $vt = absint( get_query_var( self::QV_VTO ) );
        if (!$book_slug || !$ch || !$vf) { status_header(400); exit; }
        if (!$vt || $vt < $vf) { $vt = $vf; }

        $entry = self::get_book_entry_by_slug($book_slug);
        if (!$entry) { status_header(404); exit; }
        $book_label = isset($entry['display_name']) && $entry['display_name'] !== '' ? $entry['display_name'] : self::pretty_label($entry['short_name']);
        $ref = $book_label . ' ' . $ch . ':' . ($vf === $vt ? $vf : ($vf . '-' . $vt));
        $text = self::extract_verse_text($entry, $ch, $vf, $vt);
        if ($text === '') { status_header(404); exit; }
        // Strip any trailing/leading invisible control/mark characters that may render as boxes near quotes
        $text = preg_replace('/^[\p{Cf}\p{Cc}\p{Mn}\p{Me}]+|[\p{Cf}\p{Cc}\p{Mn}\p{Me}]+$/u', '', (string)$text);
        $text = trim($text);

        $w = max(100, intval(get_option('thebible_og_width', 1200)));
        $h = max(100, intval(get_option('thebible_og_height', 630)));
        $bg = (string) get_option('thebible_og_bg_color', '#111111');
        $fg = (string) get_option('thebible_og_text_color', '#ffffff');
        // Resolve font: prefer explicit path; otherwise try to map an uploaded URL to a local path under uploads
        $font_file = (string) get_option('thebible_og_font_ttf', '');
        if ($font_file === '' || !file_exists($font_file)) {
            $font_url = (string) get_option('thebible_og_font_url', '');
            if ($font_url !== '') {
                $uploads = wp_get_upload_dir();
                if (!empty($uploads['baseurl']) && !empty($uploads['basedir'])) {
                    $baseurl = rtrim($uploads['baseurl'], '/');
                    $basedir = rtrim($uploads['basedir'], '/');
                    if (strpos($font_url, $baseurl.'/') === 0) {
                        $candidate = $basedir . substr($font_url, strlen($baseurl));
                        if (file_exists($candidate)) { $font_file = $candidate; }
                    }
                }
            }
        }
        // Font sizes: main (max, auto-fit) and reference (exact). Fallback to legacy if unset
        $font_size_legacy = intval(get_option('thebible_og_font_size', 40));
        $font_main = max(8, intval(get_option('thebible_og_font_size_main', $font_size_legacy?:40)));
        $font_ref  = max(8, intval(get_option('thebible_og_font_size_ref',  $font_size_legacy?:40)));
        $font_min_main = max(8, intval(get_option('thebible_og_min_font_size_main', 18)));
        $bg_url = (string) get_option('thebible_og_background_image_url', '');

        // Read style options needed for hashing and layout
        $pad_x_opt = intval(get_option('thebible_og_padding_x', 50));
        $pad_top_opt = intval(get_option('thebible_og_padding_top', 50));
        $pad_bottom_opt = intval(get_option('thebible_og_padding_bottom', 50));
        $min_gap_opt = (int) get_option('thebible_og_min_gap', 16);
        $bg_url_opt = (string) get_option('thebible_og_background_image_url','');
        $qL_opt_hash = (string) get_option('thebible_og_quote_left','«');
        $qR_opt_hash = (string) get_option('thebible_og_quote_right','»');
        $logo_url_opt = (string) get_option('thebible_og_icon_url','');
        $logo_side_opt = (string) get_option('thebible_og_logo_side','left');
        $logo_max_w_opt = (int) get_option('thebible_og_icon_max_w', 160);
        $logo_dx_opt = (int) get_option('thebible_og_logo_pad_adjust_x', (int)get_option('thebible_og_logo_pad_adjust',0));
        $logo_dy_opt = (int) get_option('thebible_og_logo_pad_adjust_y', 0);
        $lh_main_opt = (string) get_option('thebible_og_line_height_main','1.35');

        // Build a cache key from the request and relevant style options
        $cache_parts = [
            'book' => $book_slug,
            'ch' => $ch,
            'vf' => $vf,
            'vt' => $vt,
            'w' => $w,
            'h' => $h,
            'bg' => $bg,
            'fg' => $fg,
            'font' => $font_file ?: $font_url,
            'font_main' => $font_main,
            'font_ref' => $font_ref,
            'min_main' => $font_min_main,
            'bg_url' => $bg_url_opt,
            'qL' => $qL_opt_hash,
            'qR' => $qR_opt_hash,
            'pad_x' => $pad_x_opt,
            'pad_top' => $pad_top_opt,
            'pad_bottom' => $pad_bottom_opt,
            'gap' => $min_gap_opt,
            'logo' => $logo_url_opt,
            'logo_side' => $logo_side_opt,
            'logo_w' => $logo_max_w_opt,
            'logo_dx' => $logo_dx_opt,
            'logo_dy' => $logo_dy_opt,
            'lh_main' => $lh_main_opt,
            'text' => $text,
            'ref' => $ref,
        ];
        $hash = substr(sha1(wp_json_encode($cache_parts)), 0, 16);
        $cache_dir = self::og_cache_dir();
        $cache_file = $cache_dir . 'og-' . $hash . '.png';
        $nocache = isset($_GET['thebible_og_nocache']) && $_GET['thebible_og_nocache'];
        if (!$nocache && is_file($cache_file)) {
            nocache_headers();
            status_header(200);
            header('Content-Type: image/png');
            readfile($cache_file);
            exit;
        }

        $im = imagecreatetruecolor($w, $h);
        $bgc = self::hex_to_color($im, $bg);
        imagefilledrectangle($im, 0, 0, $w, $h, $bgc);

        if ($bg_url) {
            $resp = wp_remote_get($bg_url, ['timeout' => 5]);
            $blob = is_wp_error($resp) ? '' : wp_remote_retrieve_body($resp);
            if ($blob) {
                $bg_img = imagecreatefromstring($blob);
                if ($bg_img) {
                    $bw = imagesx($bg_img); $bh = imagesy($bg_img);
                    $scale = max($w/$bw, $h/$bh);
                    $nw = (int) floor($bw*$scale); $nh = (int) floor($bh*$scale);
                    $dst = imagecreatetruecolor($w, $h);
                    imagecopyresampled($dst, $bg_img, 0 - (int) floor(($nw-$w)/2), 0 - (int) floor(($nh-$h)/2), 0, 0, $nw, $nh, $bw, $bh);
                    imagedestroy($bg_img);
                    imagedestroy($im);
                    $im = $dst;
                    $overlay = imagecolorallocatealpha($im, 0, 0, 0, 80);
                    imagefilledrectangle($im, 0, 0, $w, $h, $overlay);
                }
            }
        }

        $fgc = self::hex_to_color($im, $fg);
        // Configurable padding (separate) and min gap (defaults used at registration)
        $pad_x = intval(get_option('thebible_og_padding_x', 50));
        $pad_top = intval(get_option('thebible_og_padding_top', 50));
        $pad_bottom = intval(get_option('thebible_og_padding_bottom', 50));
        $min_gap = max(0, intval(get_option('thebible_og_min_gap', 16)));
        $x = $pad_x; $y = $pad_top;

        // Icon configuration (simplified: always bottom). User chooses logo side; source uses opposite.
        $icon_url = (string) get_option('thebible_og_icon_url','');
        $logo_side = (string) get_option('thebible_og_logo_side','left');
        if ($logo_side !== 'right') { $logo_side = 'left'; }
        $logo_pad_adjust = intval(get_option('thebible_og_logo_pad_adjust', 0));
        $logo_pad_adjust_x = intval(get_option('thebible_og_logo_pad_adjust_x', $logo_pad_adjust));
        $logo_pad_adjust_y = intval(get_option('thebible_og_logo_pad_adjust_y', 0));
        $icon_max_w = max(0, intval(get_option('thebible_og_icon_max_w', 160)));
        $line_h_main = floatval(get_option('thebible_og_line_height_main', '1.35'));
        $icon_im = null; $icon_w = 0; $icon_h = 0;
        if ($icon_url) {
            $resp = wp_remote_get($icon_url, ['timeout' => 5]);
            $blob = is_wp_error($resp) ? '' : wp_remote_retrieve_body($resp);
            if ($blob) {
                $tmp = @imagecreatefromstring($blob);
                if ($tmp) {
                    $iw = imagesx($tmp); $ih = imagesy($tmp);
                    if ($iw > 0 && $ih > 0) {
                        $scale = 1.0;
                        $maxw = max(1, min($icon_max_w > 0 ? $icon_max_w : $w, $w - 2*$pad_x));
                        if ($iw > $maxw) { $scale = $maxw / $iw; }
                        $tw = (int) floor($iw * $scale);
                        $th = (int) floor($ih * $scale);
                        $icon_w = $tw; $icon_h = $th;
                        $icon_im = imagecreatetruecolor($tw, $th);
                        imagealphablending($icon_im, false);
                        imagesavealpha($icon_im, true);
                        imagecopyresampled($icon_im, $tmp, 0,0,0,0, $tw,$th, $iw,$ih);
                        imagedestroy($tmp);
                    } else {
                        imagedestroy($tmp);
                    }
                }
            }
        }

        $use_ttf = (is_string($font_file) && $font_file !== '' && function_exists('imagettfbbox') && function_exists('imagettftext') && file_exists($font_file));
        // Use custom quotation marks from settings; fallback to ASCII if TTF is unavailable and marks are non-ASCII
        $qL_opt = (string) get_option('thebible_og_quote_left','«');
        $qR_opt = (string) get_option('thebible_og_quote_right','»');
        $non_ascii = function($s){ return preg_match('/[^\x20-\x7E]/', (string)$s); };
        $qL = (!$use_ttf && $non_ascii($qL_opt)) ? '"' : $qL_opt;
        $qR = (!$use_ttf && $non_ascii($qR_opt)) ? '"' : $qR_opt;

        $ref_size = $font_ref;
        // Force bottom placement; align opposite of logo side
        $refpos = 'bottom';
        $refalign = ($logo_side === 'left') ? 'right' : 'left';
        // Hard trim trailing Unicode spaces/invisibles before composing quotes
        $text_clean = preg_replace('/[\p{Z}\x{00AD}\x{2000}-\x{200F}\x{2028}\x{2029}\x{202F}\x{2060}-\x{2064}\x{FEFF}\x{1680}]+$/u','',$text);
        $text_clean = preg_replace('/[\p{C}\p{Z}\p{M}]+$/u','', $text_clean);
        // Remove any remaining trailing chars that are not letters, numbers, punctuation, or symbols
        $text_clean = preg_replace('/[^\p{L}\p{N}\p{P}\p{S}]+$/u','', $text_clean);
        // Special handling of guillemets inside verse text
        $has_inner_left = (strpos($text_clean, '«') !== false);
        $has_inner_right = (strpos($text_clean, '»') !== false);
        if ($has_inner_left && $has_inner_right) {
            // If both present inside verse, normalize to single guillemets
            $text_clean = str_replace(['«','»'], ['‹','›'], $text_clean);
        } else {
            // If verse ends with the configured closing quote, strip it to avoid doubled closer at the end
            $qr_len = self::u_strlen($qR);
            if ($qr_len > 0 && self::u_substr($text_clean, -$qr_len) === $qR) {
                $text_clean = self::u_substr($text_clean, 0, self::u_strlen($text_clean) - $qr_len);
                $text_clean = rtrim($text_clean);
            }
        }
        // Always-bottom layout
        // 1) Compute reference block height at bottom padding
        $ref_h = self::measure_text_block($ref, $w - 2*$pad_x, $font_file, $ref_size);
        $bottom_for_ref = $h - $pad_bottom - $ref_h;
        // 2) Draw main verse text above the reference with min gap
        $avail_h = ($bottom_for_ref - $min_gap) - $y;
        $use_ttf = (is_string($font_file) && $font_file !== '' && function_exists('imagettfbbox') && function_exists('imagettftext') && file_exists($font_file));
        list($fit_size, $fit_text) = self::fit_text_to_area($text_clean, $w - 2*$pad_x, $avail_h, $font_file, $font_main, $font_min_main, $use_ttf, $qL, $qR, max(1.0, $line_h_main));
        self::draw_text_block($im, $fit_text, $x, $y, $w - 2*$pad_x, $font_file, $fit_size, $fgc, $bottom_for_ref - $min_gap, 'left', max(1.0, $line_h_main));
        // 3) Draw logo (if any) at bottom on chosen side with adjusted padding
        if ($icon_im) {
            $logo_pad_x = max(0, $pad_x + $logo_pad_adjust_x);
            $logo_pad_y = max(0, $pad_bottom + $logo_pad_adjust_y);
            $iy = $h - $logo_pad_y - $icon_h;
            $ix = ($logo_side === 'right') ? ($w - $logo_pad_x - $icon_w) : $logo_pad_x;
            imagecopy($im, $icon_im, $ix, $iy, 0, 0, $icon_w, $icon_h);
        }
        // 4) Draw reference at bottom, aligned opposite of logo side
        self::draw_text_block($im, $ref, $x, $bottom_for_ref, $w - 2*$pad_x, $font_file, $ref_size, $fgc, null, $refalign);

        // Save to cache and stream
        @imagepng($im, $cache_file);
        nocache_headers();
        status_header(200);
        header('Content-Type: image/png');
        if (is_file($cache_file)) { readfile($cache_file); } else { imagepng($im); }
        imagedestroy($im);
        exit;
    }

    private static function render_index() {
        self::load_index();
        status_header(200);
        nocache_headers();
        $slug = get_query_var(self::QV_SLUG);
        if (!is_string($slug) || $slug === '') { $slug = 'bible'; }
        $title = ($slug === 'bibel') ? 'Die Bibel' : 'The Bible';
        $content = self::build_index_html();
        $footer = self::render_footer_html();
        if ($footer !== '') { $content .= $footer; }
        self::output_with_theme($title, $content, 'index');
    }

    private static function render_book($slug) {
        self::load_index();
        $entry = self::$slug_map[$slug] ?? null;
        if (!$entry) {
            self::render_404();
            return;
        }
        $file = self::html_dir() . $entry['filename'];
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
        $human = isset($entry['display_name']) && $entry['display_name'] !== '' ? $entry['display_name'] : $entry['short_name'];
        $html = self::inject_nav_helpers($html, $targets, $chapter_scroll_id, $human);
        status_header(200);
        nocache_headers();
        $title = isset($entry['display_name']) && $entry['display_name'] !== ''
            ? $entry['display_name']
            : self::pretty_label( $entry['short_name'] );
        $content = '<div class="thebible thebible-book">' . $html . '</div>';
        $footer = self::render_footer_html();
        if ($footer !== '') { $content .= $footer; }
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
        $base = get_query_var(self::QV_SLUG);
        if (!is_string($base) || $base === '') { $base = 'bible'; }
        $home = home_url('/' . $base . '/');
        $out = '<div class="thebible thebible-index">';
        $out .= '<div class="thebible-groups">';
        $ot_label = ($base === 'bibel') ? 'Altes Testament' : 'Old Testament';
        $nt_label = ($base === 'bibel') ? 'Neues Testament' : 'New Testament';
        $out .= '<section class="thebible-group thebible-ot"><h2>' . esc_html($ot_label) . '</h2><ul>';
        foreach ($ot as $b) {
            $slug = self::slugify($b['short_name']);
            $url = trailingslashit($home) . $slug . '/';
            $label = !empty($b['display_name']) ? $b['display_name'] : self::pretty_label($b['short_name']);
            $out .= '<li><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></li>';
        }
        $out .= '</ul></section>';
        $out .= '<section class="thebible-group thebible-nt"><h2>' . esc_html($nt_label) . '</h2><ul>';
        foreach ($nt as $b) {
            $slug = self::slugify($b['short_name']);
            $url = trailingslashit($home) . $slug . '/';
            $label = !empty($b['display_name']) ? $b['display_name'] : self::pretty_label($b['short_name']);
            $out .= '<li><a href="' . esc_url($url) . '">' . esc_html($label) . '</a></li>';
        }
        $out .= '</ul></section>';
        $out .= '</div>';
        $out .= '</div>';
        return $out;
    }

    private static function base_slugs() {
        $list = get_option('thebible_slugs', 'bible,bibel');
        if (!is_string($list)) $list = 'bible';
        $parts = array_filter(array_map('trim', explode(',', $list)));
        if (empty($parts)) { $parts = ['bible']; }
        return array_values(array_unique($parts));
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
        register_setting(
            'thebible_options',
            'thebible_slugs',
            [
                'type'              => 'string',
                'sanitize_callback' => function( $val ) {
                    if ( ! is_string( $val ) ) return 'bible';
                    // normalize comma-separated list
                    $parts = array_filter( array_map( 'trim', explode( ',', $val ) ) );
                    // only allow known slugs for now
                    $known = [ 'bible', 'bibel' ];
                    $out = [];
                    foreach ( $parts as $p ) { if ( in_array( $p, $known, true ) ) $out[] = $p; }
                    if ( empty( $out ) ) $out = [ 'bible' ];
                    return implode( ',', array_unique( $out ) );
                },
                'default'           => 'bible,bibel',
            ]
        );

        register_setting('thebible_options', 'thebible_og_enabled', [ 'type' => 'string', 'sanitize_callback' => function($v){ return $v==='0' ? '0' : '1'; }, 'default' => '1' ]);
        register_setting('thebible_options', 'thebible_og_width', [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 1200 ]);
        register_setting('thebible_options', 'thebible_og_height', [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 630 ]);
        register_setting('thebible_options', 'thebible_og_bg_color', [ 'type' => 'string', 'sanitize_callback' => function($v){ return is_string($v)?$v:'#111111'; }, 'default' => '#111111' ]);
        register_setting('thebible_options', 'thebible_og_text_color', [ 'type' => 'string', 'sanitize_callback' => function($v){ return is_string($v)?$v:'#ffffff'; }, 'default' => '#ffffff' ]);
        register_setting('thebible_options', 'thebible_og_font_ttf', [ 'type' => 'string', 'sanitize_callback' => function($v){ return is_string($v)?$v:''; }, 'default' => '' ]);
        register_setting('thebible_options', 'thebible_og_font_url', [ 'type' => 'string', 'sanitize_callback' => function($v){ return is_string($v)?esc_url_raw($v):''; }, 'default' => '' ]);
        // Back-compat size (still read as fallback)
        register_setting('thebible_options', 'thebible_og_font_size', [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 40 ]);
        // New: separate sizes for main text and reference
        register_setting('thebible_options', 'thebible_og_font_size_main', [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 40 ]);
        register_setting('thebible_options', 'thebible_og_font_size_ref', [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 40 ]);
        // Minimum main size before truncation kicks in
        register_setting('thebible_options', 'thebible_og_min_font_size_main', [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 18 ]);
        // Layout & spacing
        // Specific paddings (defaults 50px). General padding deprecated.
        register_setting('thebible_options', 'thebible_og_padding_x', [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 50 ]);
        register_setting('thebible_options', 'thebible_og_padding_top', [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 50 ]);
        register_setting('thebible_options', 'thebible_og_padding_bottom', [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 50 ]);
        register_setting('thebible_options', 'thebible_og_min_gap', [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 16 ]);
        // Main text line-height (as a factor, e.g., 1.35)
        register_setting('thebible_options', 'thebible_og_line_height_main', [ 'type' => 'string', 'sanitize_callback' => function($v){ $v=is_string($v)?trim($v):''; return $v; }, 'default' => '1.35' ]);
        // Icon settings
        register_setting('thebible_options', 'thebible_og_icon_url', [ 'type' => 'string', 'sanitize_callback' => function($v){ return is_string($v)?esc_url_raw($v):''; }, 'default' => '' ]);
        // Simplified placement: always bottom; choose which side holds the logo; source uses the opposite
        register_setting('thebible_options', 'thebible_og_logo_side', [ 'type' => 'string', 'sanitize_callback' => function($v){ $v=is_string($v)?$v:''; return in_array($v,["left","right"],true)?$v:'left'; }, 'default' => 'left' ]);
        // Padding adjust for logo relative to general padding (can be negative)
        register_setting('thebible_options', 'thebible_og_logo_pad_adjust', [ 'type' => 'integer', 'sanitize_callback' => 'intval', 'default' => 0 ]); // legacy single-axis
        register_setting('thebible_options', 'thebible_og_logo_pad_adjust_x', [ 'type' => 'integer', 'sanitize_callback' => 'intval', 'default' => 0 ]);
        register_setting('thebible_options', 'thebible_og_logo_pad_adjust_y', [ 'type' => 'integer', 'sanitize_callback' => 'intval', 'default' => 0 ]);
        register_setting('thebible_options', 'thebible_og_icon_max_w', [ 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 160 ]);
        register_setting('thebible_options', 'thebible_og_background_image_url', [ 'type' => 'string', 'sanitize_callback' => function($v){ return is_string($v)?$v:''; }, 'default' => '' ]);
        // Quotation marks and reference position
        register_setting('thebible_options', 'thebible_og_quote_left', [ 'type' => 'string', 'sanitize_callback' => function($v){ return is_string($v)?$v:''; }, 'default' => '«' ]);
        register_setting('thebible_options', 'thebible_og_quote_right', [ 'type' => 'string', 'sanitize_callback' => function($v){ return is_string($v)?$v:''; }, 'default' => '»' ]);
        register_setting('thebible_options', 'thebible_og_ref_position', [ 'type' => 'string', 'sanitize_callback' => function($v){ $v=is_string($v)?$v:''; return in_array($v,["top","bottom"],true)?$v:'bottom'; }, 'default' => 'bottom' ]);
        register_setting('thebible_options', 'thebible_og_ref_align', [ 'type' => 'string', 'sanitize_callback' => function($v){ $v=is_string($v)?$v:''; return in_array($v,["left","right"],true)?$v:'left'; }, 'default' => 'left' ]);
    }

    public static function customize_register( $wp_customize ) {
        if ( ! class_exists('WP_Customize_Control') ) return;
        // Section for The Bible footer appearance
        $wp_customize->add_section('thebible_footer_section', [
            'title'       => __('Bible Footer CSS','thebible'),
            'priority'    => 160,
            'description' => __('Custom CSS applied to the footer area rendered by The Bible plugin (.thebible-footer, .thebible-footer-title).','thebible'),
        ]);
        // Setting: footer-specific CSS
        $wp_customize->add_setting('thebible_footer_css', [
            'type'              => 'option',
            'capability'        => 'edit_theme_options',
            'sanitize_callback' => function( $css ) { return is_string($css) ? $css : ''; },
            'default'           => '',
            'transport'         => 'refresh',
        ]);
        // Control: textarea for CSS
        $wp_customize->add_control('thebible_footer_css', [
            'section'  => 'thebible_footer_section',
            'label'    => __('Custom CSS for Bible Footer','thebible'),
            'type'     => 'textarea',
            'settings' => 'thebible_footer_css',
        ]);
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

    public static function admin_enqueue($hook) {
        // Only enqueue on our settings page
        if ($hook !== 'toplevel_page_thebible') return;
        if (function_exists('wp_enqueue_media')) {
            wp_enqueue_media();
        }
    }

    public static function allow_font_uploads($mimes) {
        if (!is_array($mimes)) { $mimes = []; }
        // Common font MIME types
        $mimes['ttf'] = 'font/ttf';
        $mimes['otf'] = 'font/otf';
        $mimes['woff'] = 'font/woff';
        $mimes['woff2'] = 'font/woff2';
        // Some hosts map fonts as octet-stream; allow anyway to select in media library
        if (!isset($mimes['ttf'])) { $mimes['ttf'] = 'application/octet-stream'; }
        if (!isset($mimes['otf'])) { $mimes['otf'] = 'application/octet-stream'; }
        return $mimes;
    }

    public static function allow_font_filetype($data, $file, $filename, $mimes, $real_mime) {
        if (!current_user_can('manage_options')) return $data;
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, ['ttf','otf','woff','woff2'], true)) {
            $type = ($ext === 'otf') ? 'font/otf' : (($ext === 'ttf') ? 'font/ttf' : (($ext==='woff2')?'font/woff2':'font/woff'));
            return [ 'ext' => $ext, 'type' => $type, 'proper_filename' => $data['proper_filename'] ];
        }
        return $data;
    }

    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $css = get_option( 'thebible_custom_css', '' );
        $slugs_opt = get_option( 'thebible_slugs', 'bible,bibel' );
        $active = array_filter( array_map( 'trim', explode( ',', is_string($slugs_opt)?$slugs_opt:'' ) ) );
        $known = [ 'bible' => 'English (Douay)', 'bibel' => 'Deutsch (Menge)' ];
        $og_enabled = get_option('thebible_og_enabled','1');
        $og_w = intval(get_option('thebible_og_width',1200));
        $og_h = intval(get_option('thebible_og_height',630));
        $og_bg = (string) get_option('thebible_og_bg_color','#111111');
        $og_fg = (string) get_option('thebible_og_text_color','#ffffff');
        $og_font = (string) get_option('thebible_og_font_ttf','');
        $og_font_url = (string) get_option('thebible_og_font_url','');
        $og_size_legacy = intval(get_option('thebible_og_font_size',40));
        $og_size_main = intval(get_option('thebible_og_font_size_main', $og_size_legacy?:40));
        $og_size_ref  = intval(get_option('thebible_og_font_size_ref',  $og_size_legacy?:40));
        $og_min_main  = intval(get_option('thebible_og_min_font_size_main', 18));
        $og_img = (string) get_option('thebible_og_background_image_url','');
        // Layout & icon options for settings UI
        $og_pad_x = intval(get_option('thebible_og_padding_x', 50));
        $og_pad_top = intval(get_option('thebible_og_padding_top', 50));
        $og_pad_bottom = intval(get_option('thebible_og_padding_bottom', 50));
        $og_min_gap = intval(get_option('thebible_og_min_gap', 16));
        $og_icon_url = (string) get_option('thebible_og_icon_url','');
        $og_logo_side = (string) get_option('thebible_og_logo_side','left');
        $og_logo_pad_adjust = intval(get_option('thebible_og_logo_pad_adjust', 0));
        $og_logo_pad_adjust_x = intval(get_option('thebible_og_logo_pad_adjust_x', $og_logo_pad_adjust));
        $og_logo_pad_adjust_y = intval(get_option('thebible_og_logo_pad_adjust_y', 0));
        $og_icon_max_w = intval(get_option('thebible_og_icon_max_w', 160));
        $og_line_main = (string) get_option('thebible_og_line_height_main','1.35');
        $og_line_main_f = floatval($og_line_main ? $og_line_main : '1.35');
        $og_qL = (string) get_option('thebible_og_quote_left','«');
        $og_qR = (string) get_option('thebible_og_quote_right','»');
        $og_refpos = (string) get_option('thebible_og_ref_position','bottom');
        $og_refalign = (string) get_option('thebible_og_ref_align','left');

        // Handle footer save (all-at-once)
        if ( isset($_POST['thebible_footer_nonce_all']) && wp_verify_nonce( $_POST['thebible_footer_nonce_all'], 'thebible_footer_save_all' ) && current_user_can('manage_options') ) {
            foreach ($known as $fs => $label) {
                $field = 'thebible_footer_text_' . $fs;
                $ft = isset($_POST[$field]) ? (string) wp_unslash( $_POST[$field] ) : '';
                // New preferred location
                $root = plugin_dir_path(__FILE__) . 'data/' . $fs . '/';
                $ok = is_dir($root) || wp_mkdir_p($root);
                if ( $ok ) {
                    @file_put_contents( trailingslashit($root) . 'copyright.md', $ft );
                } else {
                    // Legacy fallback
                    $dir = plugin_dir_path(__FILE__) . 'data/' . $fs . '_books_html/';
                    if ( is_dir($dir) || wp_mkdir_p($dir) ) {
                        @file_put_contents( trailingslashit($dir) . 'copyright.txt', $ft );
                    }
                }
            }
            echo '<div class="updated notice"><p>Footers saved.</p></div>';
        }
        // Handle cache purge
        if ( isset($_POST['thebible_og_purge_cache_nonce']) && wp_verify_nonce($_POST['thebible_og_purge_cache_nonce'],'thebible_og_purge_cache') && current_user_can('manage_options') ) {
            $deleted = self::og_cache_purge();
            echo '<div class="updated notice"><p>OG image cache cleared (' . intval($deleted) . ' files removed).</p></div>';
        }
        ?>
        <div class="wrap">
            <h1>The Bible</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'thebible_options' ); ?>
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label>Active bibles</label></th>
                            <td>
                                <?php foreach ( $known as $slug => $label ): $checked = in_array($slug, $active, true); ?>
                                    <label style="display:block;margin:.2em 0;">
                                        <input type="checkbox" name="thebible_slugs_list[]" value="<?php echo esc_attr($slug); ?>" <?php checked( $checked ); ?>>
                                        <code>/<?php echo esc_html($slug); ?>/</code> — <?php echo esc_html($label); ?>
                                    </label>
                                <?php endforeach; ?>
                                <input type="hidden" name="thebible_slugs" id="thebible_slugs" value="<?php echo esc_attr( implode(',', $active ) ); ?>">
                                <script>(function(){function sync(){var boxes=document.querySelectorAll('input[name="thebible_slugs_list[]"]');var out=[];boxes.forEach(function(b){if(b.checked) out.push(b.value);});document.getElementById('thebible_slugs').value=out.join(',');}document.addEventListener('change',function(e){if(e.target && e.target.name==='thebible_slugs_list[]'){sync();}});document.addEventListener('DOMContentLoaded',sync);})();</script>
                                <p class="description">Select which bibles are publicly accessible. Others remain installed but routed pages are disabled.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label>Quotation marks</label></th>
                            <td>
                                <label>Left <input type="text" name="thebible_og_quote_left" value="<?php echo esc_attr($og_qL); ?>" style="width:4em;text-align:center;"></label>
                                &nbsp;
                                <label>Right <input type="text" name="thebible_og_quote_right" value="<?php echo esc_attr($og_qR); ?>" style="width:4em;text-align:center;"></label>
                                <p class="description">Use any characters (e.g. « » or “ ”). If no TTF font is set, non-ASCII marks may fallback to straight quotes in the image.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="thebible_og_ref_position">Reference position</label></th>
                            <td>
                                <select name="thebible_og_ref_position" id="thebible_og_ref_position">
                                    <option value="bottom" <?php selected($og_refpos==='bottom'); ?>>Bottom</option>
                                    <option value="top" <?php selected($og_refpos==='top'); ?>>Top</option>
                                </select>
                                &nbsp;
                                <label for="thebible_og_ref_align">Alignment</label>
                                <select name="thebible_og_ref_align" id="thebible_og_ref_align">
                                    <option value="left" <?php selected($og_refalign==='left'); ?>>Left</option>
                                    <option value="right" <?php selected($og_refalign==='right'); ?>>Right</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="thebible_custom_css">Custom CSS (applied on Bible pages)</label></th>
                            <td>
                                <textarea name="thebible_custom_css" id="thebible_custom_css" class="large-text code" rows="14" style="font-family:monospace;"><?php echo esc_textarea( $css ); ?></textarea>
                                <p class="description">Rendered on /bible and any /bible/{book} pages.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="thebible_og_enabled">Social image (Open Graph)</label></th>
                            <td>
                                <label><input type="checkbox" name="thebible_og_enabled" id="thebible_og_enabled" value="1" <?php checked($og_enabled==='1'); ?>> Enable dynamic image for verse URLs</label>
                                <p class="description">Generates a PNG for <code>og:image</code> when a URL includes chapter and verse, e.g. <code>/bible/john/3:16</code>.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="thebible_og_width">Image size</label></th>
                            <td>
                                <input type="number" min="100" name="thebible_og_width" id="thebible_og_width" value="<?php echo esc_attr($og_w); ?>" style="width:7em;"> ×
                                <input type="number" min="100" name="thebible_og_height" id="thebible_og_height" value="<?php echo esc_attr($og_h); ?>" style="width:7em;"> px
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="thebible_og_bg_color">Colors</label></th>
                            <td>
                                <input type="text" name="thebible_og_bg_color" id="thebible_og_bg_color" value="<?php echo esc_attr($og_bg); ?>" placeholder="#111111" style="width:8em;"> background
                                <span style="display:inline-block;width:1.2em;height:1.2em;vertical-align:middle;border:1px solid #ccc;background:<?php echo esc_attr($og_bg); ?>"></span>
                                &nbsp; <input type="text" name="thebible_og_text_color" id="thebible_og_text_color" value="<?php echo esc_attr($og_fg); ?>" placeholder="#ffffff" style="width:8em;"> text
                                <span style="display:inline-block;width:1.2em;height:1.2em;vertical-align:middle;border:1px solid #ccc;background:<?php echo esc_attr($og_fg); ?>"></span>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="thebible_og_font_ttf">Font</label></th>
                            <td>
                                <p style="margin:.2em 0 .6em;">
                                    <label>Server path: <input type="text" name="thebible_og_font_ttf" id="thebible_og_font_ttf" value="<?php echo esc_attr($og_font); ?>" class="regular-text" placeholder="/path/to/font.ttf"></label>
                                </p>
                                <p style="margin:.2em 0 .6em;">
                                    <label>Or uploaded URL: <input type="url" name="thebible_og_font_url" id="thebible_og_font_url" value="<?php echo esc_attr($og_font_url); ?>" class="regular-text" placeholder="https://.../yourfont.ttf"></label>
                                    <button type="button" class="button" id="thebible_pick_font">Select/upload font</button>
                                </p>
                                <p class="description">TTF/OTF recommended. If path is invalid, the uploader URL will be mapped to a local file under Uploads. Without a valid font file, non‑ASCII quotes may fall back to straight quotes.</p>
                                <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
                                    <label>Max main size <input type="number" min="8" name="thebible_og_font_size_main" id="thebible_og_font_size_main" value="<?php echo esc_attr($og_size_main); ?>" style="width:6em;"></label>
                                    <label>Min main size <input type="number" min="8" name="thebible_og_min_font_size_main" id="thebible_og_min_font_size_main" value="<?php echo esc_attr($og_min_main); ?>" style="width:6em;"></label>
                                    <label>Max source size <input type="number" min="8" name="thebible_og_font_size_ref" id="thebible_og_font_size_ref" value="<?php echo esc_attr($og_size_ref); ?>" style="width:6em;"></label>
                                    <label>Line height (main) <input type="number" step="0.05" min="1" name="thebible_og_line_height_main" id="thebible_og_line_height_main" value="<?php echo esc_attr($og_line_main); ?>" style="width:6em;"></label>
                                </div>
                                <p class="description">Main text auto-shrinks between Max and Min. If still too long at Min, it is truncated with … Source uses up to its max size and wraps as needed.</p>
                                <script>(function(){
                                    function initPicker(){
                                        if (!window.wp || !wp.media) return;
                                        var frame=null;
                                        var btn=document.getElementById('thebible_pick_font');
                                        if(!btn) return;
                                        btn.addEventListener('click', function(e){
                                            e.preventDefault();
                                            if(frame){ frame.open(); return; }
                                            frame = wp.media({ title: 'Select a font file', library: { type: ['application/octet-stream','font/ttf','font/otf','application/x-font-ttf','application/x-font-otf'] }, button: { text: 'Use this font' }, multiple: false });
                                            frame.on('select', function(){
                                                var att = frame.state().get('selection').first().toJSON();
                                                if(att && att.url){ document.getElementById('thebible_og_font_url').value = att.url; }
                                            });
                                            frame.open();
                                        });
                                    }
                                    if(document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', initPicker); } else { initPicker(); }
                                })();</script>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label>Cache</label></th>
                            <td>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('thebible_og_purge_cache','thebible_og_purge_cache_nonce'); ?>
                                    <button type="submit" class="button">Clear cached images</button>
                                </form>
                                <p class="description">Cached OG images are stored under Uploads/thebible-og-cache and reused for identical requests. Clear the cache after changing design settings.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="thebible_og_background_image_url">Background image</label></th>
                            <td>
                                <p style="margin:.2em 0 .6em;">
                                    <input type="url" name="thebible_og_background_image_url" id="thebible_og_background_image_url" value="<?php echo esc_attr($og_img); ?>" class="regular-text" placeholder="https://.../image.jpg">
                                    <button type="button" class="button" id="thebible_pick_bg">Select/upload image</button>
                                </p>
                                <p class="description">Optional. If set, the image is used as a cover background with a dark overlay for readability.</p>
                                <script>(function(){
                                    function initBgPicker(){
                                        if (!window.wp || !wp.media) return;
                                        var btn=document.getElementById('thebible_pick_bg');
                                        if(!btn) return; var frame=null;
                                        btn.addEventListener('click', function(e){ e.preventDefault(); if(frame){frame.open();return;} frame=wp.media({title:'Select background image', library:{ type:['image'] }, button:{ text:'Use this image' }, multiple:false}); frame.on('select', function(){ var att=frame.state().get('selection').first().toJSON(); if(att && att.url){ document.getElementById('thebible_og_background_image_url').value = att.url; } }); frame.open(); });
                                    }
                                    if(document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', initBgPicker); } else { initBgPicker(); }
                                })();</script>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label>Layout</label></th>
                            <td>
                                <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap;">
                                    <label>Side padding <input type="number" min="0" name="thebible_og_padding_x" id="thebible_og_padding_x" value="<?php echo esc_attr($og_pad_x); ?>" style="width:6em;"> px</label>
                                    <label>Top padding <input type="number" min="0" name="thebible_og_padding_top" id="thebible_og_padding_top" value="<?php echo esc_attr($og_pad_top); ?>" style="width:6em;"> px</label>
                                    <label>Bottom padding <input type="number" min="0" name="thebible_og_padding_bottom" id="thebible_og_padding_bottom" value="<?php echo esc_attr($og_pad_bottom); ?>" style="width:6em;"> px</label>
                                    <label>Min gap text↔source <input type="number" min="0" name="thebible_og_min_gap" id="thebible_og_min_gap" value="<?php echo esc_attr($og_min_gap); ?>" style="width:6em;"> px</label>
                                </div>
                                <p class="description">Set exact paddings for sides, top, and bottom. The min gap enforces spacing between the main text and the bottom row.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="thebible_og_icon_url">Icon</label></th>
                            <td>
                                <p style="margin:.2em 0 .6em;">
                                    <label>Icon URL: <input type="url" name="thebible_og_icon_url" id="thebible_og_icon_url" value="<?php echo esc_attr($og_icon_url); ?>" class="regular-text" placeholder="https://.../icon.png"></label>
                                    <button type="button" class="button" id="thebible_pick_icon">Select/upload image</button>
                                </p>
                                <p style="margin:.2em 0 .6em;">
                                    <label>Logo side 
                                        <select name="thebible_og_logo_side" id="thebible_og_logo_side">
                                            <option value="left" <?php selected($og_logo_side==='left'); ?>>Left</option>
                                            <option value="right" <?php selected($og_logo_side==='right'); ?>>Right</option>
                                        </select>
                                    </label>
                                    &nbsp;
                                    <label>Logo padding X <input type="number" name="thebible_og_logo_pad_adjust_x" id="thebible_og_logo_pad_adjust_x" value="<?php echo esc_attr($og_logo_pad_adjust_x); ?>" style="width:6em;"> px</label>
                                    &nbsp;
                                    <label>Logo padding Y <input type="number" name="thebible_og_logo_pad_adjust_y" id="thebible_og_logo_pad_adjust_y" value="<?php echo esc_attr($og_logo_pad_adjust_y); ?>" style="width:6em;"> px</label>
                                    &nbsp;
                                    <label>Max width <input type="number" min="1" name="thebible_og_icon_max_w" id="thebible_og_icon_max_w" value="<?php echo esc_attr($og_icon_max_w); ?>" style="width:6em;"> px</label>
                                </p>
                                <p class="description">Logo and source are always at the bottom. Choose which side holds the logo; the source uses the other side. Logo padding X/Y shift the logo relative to side/bottom padding (can be negative).</p>
                                <script>(function(){
                                    function initIconPicker(){
                                        if (!window.wp || !wp.media) return;
                                        var btn=document.getElementById('thebible_pick_icon');
                                        if(!btn) return; var frame=null;
                                        btn.addEventListener('click', function(e){ e.preventDefault(); if(frame){frame.open();return;} frame=wp.media({title:'Select icon', library:{ type:['image'] }, button:{ text:'Use this image' }, multiple:false}); frame.on('select', function(){ var att=frame.state().get('selection').first().toJSON(); if(att && att.url){ document.getElementById('thebible_og_icon_url').value = att.url; } }); frame.open(); });
                                    }
                                    if(document.readyState === 'loading'){ document.addEventListener('DOMContentLoaded', initIconPicker); } else { initIconPicker(); }
                                })();</script>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2>Per‑Bible footers</h2>
            <form method="post">
                <?php wp_nonce_field('thebible_footer_save_all', 'thebible_footer_nonce_all'); ?>
                <p class="description">Preferred location: <code>wp-content/plugins/thebible/data/{slug}/copyright.md</code>. Legacy fallback: <code>data/{slug}_books_html/copyright.txt</code>.</p>
                <table class="form-table" role="presentation">
                    <tbody>
                        <?php foreach ($known as $slug => $label): ?>
                        <?php
                            // Load existing footer for display
                            $root = plugin_dir_path(__FILE__) . 'data/' . $slug . '/';
                            $val = '';
                            if ( file_exists( $root . 'copyright.md' ) ) {
                                $val = (string) file_get_contents( $root . 'copyright.md' );
                            } else {
                                $legacy = plugin_dir_path(__FILE__) . 'data/' . $slug . '_books_html/copyright.txt';
                                if ( file_exists( $legacy ) ) { $val = (string) file_get_contents( $legacy ); }
                            }
                        ?>
                        <tr>
                            <th scope="row"><label for="thebible_footer_text_<?php echo esc_attr($slug); ?>"><?php echo esc_html('/' . $slug . '/ — ' . $label); ?></label></th>
                            <td>
                                <textarea name="thebible_footer_text_<?php echo esc_attr($slug); ?>" id="thebible_footer_text_<?php echo esc_attr($slug); ?>" class="large-text code" rows="6" style="font-family:monospace;"><?php echo esc_textarea( $val ); ?></textarea>
                                <p class="description">Markdown supported for links and headings; line breaks are preserved.</p>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button('Save Footers'); ?>
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
        $footer_css = get_option( 'thebible_footer_css', '' );
        $out = '';
        if ( is_string($css) && $css !== '' ) { $out .= $css . "\n"; }
        if ( is_string($footer_css) && $footer_css !== '' ) { $out .= $footer_css . "\n"; }
        if ( $out !== '' ) {
            echo '<style id="thebible-custom-css">' . $out . '</style>';
        }
    }

    public static function print_og_meta() {
        $flag = get_query_var(self::QV_FLAG);
        if (!$flag) return;
        $book = get_query_var(self::QV_BOOK);
        $ch = absint(get_query_var(self::QV_CHAPTER));
        $vf = absint(get_query_var(self::QV_VFROM));
        $vt = absint(get_query_var(self::QV_VTO));
        if (!$book || !$ch || !$vf) return;
        if (!$vt || $vt < $vf) $vt = $vf;

        $entry = self::get_book_entry_by_slug($book);
        if (!$entry) return;
        $label = isset($entry['display_name']) && $entry['display_name'] !== '' ? $entry['display_name'] : self::pretty_label($entry['short_name']);
        $title = $label . ' ' . $ch . ':' . ($vf === $vt ? $vf : ($vf . '-' . $vt));

        $base_slug = get_query_var(self::QV_SLUG);
        if (!is_string($base_slug) || $base_slug==='') $base_slug = 'bible';
        $path = '/' . trim($base_slug,'/') . '/' . trim($book,'/') . '/' . $ch . ':' . $vf . ($vt>$vf?('-'.$vt):'');
        $url = home_url($path);
        $og_url = add_query_arg(self::QV_OG, '1', $url);
        $desc = self::extract_verse_text($entry, $ch, $vf, $vt);
        $desc = wp_strip_all_tags($desc);

        echo "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '" />' . "\n";
        echo '<meta property="og:type" content="article" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url($url) . '" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($desc) . '" />' . "\n";
        echo '<meta property="og:image" content="' . esc_url($og_url) . '" />' . "\n";
        echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr($title) . '" />' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr($desc) . '" />' . "\n";
        echo '<meta name="twitter:image" content="' . esc_url($og_url) . '" />' . "\n";
    }

    private static function render_footer_html() {
        // Prefer new markdown footer at dataset root, fallback to old copyright.txt in html dir
        $raw = '';
        $root = self::data_root_dir();
        if ($root) {
            $md = trailingslashit($root) . 'copyright.md';
            if (file_exists($md)) {
                $raw = (string) file_get_contents($md);
            }
        }
        if ($raw === '') {
            $txt_path = self::html_dir() . 'copyright.txt';
            if (file_exists($txt_path)) { $raw = (string) file_get_contents($txt_path); }
        }
        if (!is_string($raw) || $raw === '') return '';
        // Very light Markdown to HTML: allow links and simple headings; escape everything else
        $safe = esc_html($raw);
        // Convert [text](url) style links
        $safe = preg_replace('/\[(.*?)\]\((https?:\/\/[^\s)]+)\)/', '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>', $safe);
        // Split into lines and build blocks: headings or paragraphs
        $lines = preg_split('/\r?\n/', $safe);
        $blocks = [];
        $para = [];
        $flush_para = function() use (&$para, &$blocks) {
            if (!empty($para)) {
                // Join paragraph lines with spaces
                $text = trim(preg_replace('/\s+/', ' ', implode(' ', array_map('trim', $para))));
                if ($text !== '') { $blocks[] = '<p>' . $text . '</p>'; }
                $para = [];
            }
        };
        foreach ($lines as $ln) {
            if (preg_match('/^###\s+(.*)$/', $ln, $m)) { $flush_para(); $blocks[] = '<h3 class="thebible-footer-title">' . $m[1] . '</h3>'; continue; }
            if (preg_match('/^##\s+(.*)$/', $ln, $m))  { $flush_para(); $blocks[] = '<h2 class="thebible-footer-title">' . $m[1] . '</h2>'; continue; }
            if (preg_match('/^#\s+(.*)$/', $ln, $m))   { $flush_para(); $blocks[] = '<h1 class="thebible-footer-title">' . $m[1] . '</h1>'; continue; }
            if (trim($ln) === '') { $flush_para(); continue; }
            $para[] = $ln;
        }
        $flush_para();
        return '<footer class="thebible-footer">' . implode('', $blocks) . '</footer>';
    }
}

TheBible_Plugin::init();
