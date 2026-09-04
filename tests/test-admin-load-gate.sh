#!/usr/bin/env bash
# test-admin-load-gate.sh — which admin classes load where, and the export
# button that never worked.
#
# WHAT:  Three things:
#          1. the three admin SCREENS are absent outside wp-admin (49 KB)
#          2. admin-utils and admin-meta are still loaded everywhere — they
#             back filters that fire outside wp-admin and must not be gated
#          3. admin_post_dwbible_export_bible is REGISTERED
# WHY:   (3) was a defect, not an optimisation. The settings page has always
#        rendered an "Export Bible as .txt" form posting
#        action=dwbible_export_bible to admin-post.php, and nothing ever
#        hooked it — the button answered 400 and downloaded nothing, while
#        the UI went on advertising the feature. The handler beside it was
#        complete: capability check, nonce, slug validation, streaming.
#
#        (2) is the part a later tidy-up is most likely to get wrong.
#        class-dwbible-admin-utils.php is named "admin" and backs the
#        `upload_mimes` and `wp_check_filetype_and_ext` filters, which fire
#        wherever a file is uploaded — REST media uploads from the block
#        editor included. class-dwbible-admin-meta.php is hooked to
#        `save_post` from the MAIN plugin file, a hook its own file never
#        mentions. Gating either on is_admin() would break uploads or lose
#        meta on a block-editor save, silently.
# HOW:   admin-post.php separates registered from unregistered by itself:
#          registered   → 500 (the handler ran and refused the missing nonce)
#          unregistered → 400
#        An ANONYMOUS caller cannot tell them apart, so a cookie is minted and
#        the control asserted first.
# INPUT:  none. OUTPUT: exit 0 when all three hold. Writes nothing.
# RUN:    tests/test-admin-load-gate.sh [BASE_URL]   (default: local)
# TESTED BY: itself — it is the guard.
set -euo pipefail

BASE="${1:-http://latinprayer.local}"
case "$BASE" in
  *.local|*localhost*|*127.0.0.1*) SITE=latinprayer ;;
  *) echo "REFUSING: this test mints an auth cookie; BASE must be local, got '$BASE'" >&2; exit 2 ;;
esac

pass=0; fail=0
ok() { if [ "$1" = "1" ]; then pass=$((pass+1)); echo "  ok   $2"; else fail=$((fail+1)); echo "  FAIL $2"; fi; }

echo "1. outside wp-admin, the screens are absent and the filter-backing classes are not:"
OUT="$(wp-local $SITE eval '
  $r = [];
  foreach (["DwBible_Admin_Settings","DwBible_Admin_AI","DwBible_Admin_Export",
            "DwBible_Admin_Utils","DwBible_Admin_Meta","DwBible_Plugin"] as $c) {
      $r[] = class_exists($c, false) ? "1" : "0";
  }
  echo implode("", $r);
' 2>/dev/null | tr -d '\r')"
# Positions: 0 Settings, 1 AI, 2 Export, 3 Utils, 4 Meta, 5 Plugin
ok "$([ "${OUT:0:3}" = "000" ] && echo 1 || echo 0)" \
   "the three admin screens are NOT loaded (Settings/AI/Export = ${OUT:0:3})"
ok "$([ "${OUT:3:1}" = "1" ] && echo 1 || echo 0)" \
   "DwBible_Admin_Utils IS loaded — it backs upload_mimes, which fires on REST uploads"
ok "$([ "${OUT:4:1}" = "1" ] && echo 1 || echo 0)" \
   "DwBible_Admin_Meta IS loaded — the main file hooks it to save_post"
ok "$([ "${OUT:5:1}" = "1" ] && echo 1 || echo 0)" \
   "CONTROL — DwBible_Plugin is loaded, so the probe can see classes at all"

echo "2. the export action is registered (it was not, for the life of the feature):"
COOKIE="$(wp-local $SITE eval '
  $u = get_users(["role"=>"administrator","number"=>1]);
  if (!$u) { echo ""; return; }
  echo "wordpress_logged_in_" . COOKIEHASH . "=" .
       rawurlencode( wp_generate_auth_cookie($u[0]->ID, time()+3600, "logged_in") );
' 2>/dev/null | tr -d '\r\n')"

if [ -z "$COOKIE" ]; then
  echo "  FAIL could not mint an admin cookie"; fail=$((fail+1))
else
  code() { curl -s -b "$COOKIE" -o /dev/null -w '%{http_code}' -d "action=$1" \
             "${BASE%/}/wp-admin/admin-post.php"; }
  FAKE="$(code dwbible_nope_xyz9)"; REAL="$(code dwbible_export_bible)"
  ok "$([ "$FAKE" = "400" ] && echo 1 || echo 0)" \
     "CONTROL — an unregistered admin_post action answers 400 (got ${FAKE})"
  ok "$([ "$REAL" != "$FAKE" ] && echo 1 || echo 0)" \
     "dwbible_export_bible answers differently (${REAL}) — it is hooked"
fi

echo "3. the export writes a header that matches its rows:"
# The header declared a leading "schema" column no row ever emitted, so a
# parser trusting it read every field off by one. Both counts must agree.
# The handler streams to php://output and then exit()s, so nothing after the
# call can report anything — the field counts are taken from its actual output.
# `head -3` also stops the export early instead of dumping the whole Bible.
EXPORT="$(wp-local $SITE eval '
  $u = get_users(["role"=>"administrator","number"=>1]); wp_set_current_user($u[0]->ID);
  $_POST["dwbible_export_bible_nonce"] = wp_create_nonce("dwbible_export_bible");
  $_POST["dwbible_export_bible_slug"]  = "bible";
  require_once WP_PLUGIN_DIR . "/dwbible/includes/class-dwbible-admin-export.php";
  DwBible_Admin_Export::handle_export_bible_txt();
' 2>/dev/null | head -3 | tr -d '\r' || true)"
# `|| true` is required, not sloppiness: head closes the pipe after three
# lines, wp dies of SIGPIPE, and `set -o pipefail` would abort the run here.
count_fields() { printf '%s' "$1" | awk -F'|' '{print NF; exit}'; }
HDR="$(count_fields "$(printf '%s\n' "$EXPORT" | sed -n 2p)")"
ROW="$(count_fields "$(printf '%s\n' "$EXPORT" | sed -n 3p)")"
ok "$([ "$HDR" = "$ROW" ] && echo 1 || echo 0)" "header and data agree on the field count (${HDR} vs ${ROW})"
ok "$([ "$HDR" = "5" ] && echo 1 || echo 0)" "…and it is 5: slug|book|chapter|verse|text (got ${HDR})"

echo
echo "${pass} passed / ${fail} failed"
[ "$fail" -eq 0 ]
