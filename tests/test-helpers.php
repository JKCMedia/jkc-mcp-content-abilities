<?php
/**
 * Standalone unit tests voor de pure parser-helpers van JKC MCP Content Abilities.
 *
 * Vereist GEEN WordPress: de gebruikte WP-functies worden hieronder gestubt.
 * Dekt:
 *   - jkc_mcp_extract_headings()  (H2/H3 incl. page-builder shortcodes — feature 7)
 *   - jkc_mcp_extract_links()     (interne/externe + relatieve links — feature 3)
 *
 * Draaien:  php tests/test-helpers.php
 *
 * Dit bestand wordt nooit door WordPress geladen (alleen het hoofd-pluginbestand wordt geladen).
 */

error_reporting( E_ALL & ~E_DEPRECATED );

/* ---- Minimale WordPress-stubs ---------------------------------------- */
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/' );
}
function add_action() {}
function add_filter() {}
function register_activation_hook() {}
function apply_filters( $tag, $value ) { return $value; }
function __( $text, $domain = null ) { return $text; }
function wp_strip_all_tags( $string ) { return trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $string ) ) ); }
function strip_shortcodes( $content ) { return preg_replace( '/\[[^\]]*\]/', '', (string) $content ); }
function home_url( $path = '' ) { return 'https://example.com' . $path; }
function is_ssl() { return true; }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function esc_url_raw( $url ) { return $url; }

/* ---- Laad alleen de helperfuncties uit het hoofdbestand -------------- */
// Kopieer naar tests/ zodat __DIR__/plugin-update-checker NIET bestaat en de
// GitHub-update-checker (die WP-constants vereist) wordt overgeslagen.
$src = __DIR__ . '/../jkc-mcp-content-abilities.php';
$tmp = __DIR__ . '/.tmp-plugin.php';
copy( $src, $tmp );
require $tmp;
@unlink( $tmp );

/* ---- Mini-testframework --------------------------------------------- */
$tests  = 0;
$failed = 0;
function check( $label, $cond ) {
    global $tests, $failed;
    $tests++;
    if ( $cond ) {
        echo "  PASS  $label\n";
    } else {
        $failed++;
        echo "  FAIL  $label\n";
    }
}

/* ===== headings: gewone HTML ========================================== */
$h = jkc_mcp_extract_headings( '<h2>Eerste kop</h2><p>tekst</p><h3>Tweede</h3>' );
$texts = array_column( $h, 'text' );
check( 'HTML h2 herkend', in_array( 'Eerste kop', $texts, true ) );
check( 'HTML h3 herkend', in_array( 'Tweede', $texts, true ) );

/* ===== headings: Nectar/Visual Composer shortcode (feature 7) ======== */
$raw = 'Intro [nectar_highlighted_text]Belangrijke subkop[/nectar_highlighted_text] meer tekst';
$h2  = jkc_mcp_extract_headings( $raw, $raw );
check( 'Shortcode-kop (nectar) herkend', in_array( 'Belangrijke subkop', array_column( $h2, 'text' ), true ) );

/* ===== headings: vc_custom_heading met text-attribuut ================ */
$raw3 = '[vc_custom_heading text="SEO subkop" tag="h2"]';
$h3   = jkc_mcp_extract_headings( $raw3, '' );
check( 'Shortcode-kop (vc text-attr) herkend', in_array( 'SEO subkop', array_column( $h3, 'text' ), true ) );

/* ===== headings: dedupe ============================================== */
$dupRaw = '<h2>Dubbel</h2>[heading]Dubbel[/heading]';
$hd     = jkc_mcp_extract_headings( $dupRaw, '<h2>Dubbel</h2>' );
$dubbel = array_filter( $hd, function ( $x ) { return 'Dubbel' === $x['text'] && 'h2' === $x['tag']; } );
check( 'Identieke h2-koppen worden ontdubbeld', count( $dubbel ) === 1 );

/* ===== headings: lege content crasht niet =========================== */
$he = jkc_mcp_extract_headings( '', null );
check( 'Lege content geeft lege array', is_array( $he ) && count( $he ) === 0 );

/* ===== links: intern vs extern ====================================== */
$html  = '<a href="/over-ons">Over ons</a> '
       . '<a href="https://example.com/diensten">Diensten</a> '
       . '<a href="https://google.com">Google</a> '
       . '<a href="mailto:info@example.com">mail</a> '
       . '<a href="#anker">spring</a>';
$links = jkc_mcp_extract_links( $html );
$by    = array();
foreach ( $links as $l ) { $by[ $l['href'] ] = $l; }

check( 'mailto en anker uitgesloten', ! isset( $by['mailto:info@example.com'] ) && ! isset( $by['#anker'] ) );
check( 'relatieve interne link herkend', isset( $by['/over-ons'] ) && $by['/over-ons']['internal'] === true && $by['/over-ons']['relative'] === true );
check( 'absolute interne link herkend', isset( $by['https://example.com/diensten'] ) && $by['https://example.com/diensten']['internal'] === true );
check( 'externe link herkend', isset( $by['https://google.com'] ) && $by['https://google.com']['internal'] === false );
check( 'ankertekst geextraheerd', isset( $by['/over-ons'] ) && $by['/over-ons']['anchor'] === 'Over ons' );
check( 'relatieve link wordt geresolved naar absoluut', isset( $by['/over-ons'] ) && $by['/over-ons']['url'] === 'https://example.com/over-ons' );

/* ===== links: malformed HTML crasht niet ============================ */
$bad = jkc_mcp_extract_links( '<a href=foo>geen quotes</a><a>geen href</a><a href="' );
check( 'malformed HTML geeft array zonder fatal', is_array( $bad ) );

/* ---- Resultaat ------------------------------------------------------ */
echo "\n" . ( $tests - $failed ) . "/$tests geslaagd\n";
exit( $failed > 0 ? 1 : 0 );
