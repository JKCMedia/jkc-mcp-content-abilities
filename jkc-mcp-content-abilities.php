<?php
/**
 * Plugin Name:       JKC MCP Content Abilities
 * Description:       Stelt lees- en schrijf-abilities (pagina's, berichten, Yoast SEO, volledige SEO-audit) beschikbaar aan de WordPress MCP Adapter, zodat AI-assistenten zoals Claude content op deze site kunnen lezen, auditen en bewerken. Maakt bij activatie automatisch een Claude-gebruiker met applicatie-wachtwoord aan. Werkt op elke WordPress-site.
 * Version:           1.11.0
 * Requires PHP:      7.4
 * Author:            JKC Media
 * License:           GPL-2.0-or-later
 * Text Domain:       jkc-mcp
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/* =========================================================================
 * AUTO-UPDATE via GitHub (publieke repo, geen token nodig)
 * WordPress toont een update zodra de Version-header op de main-branch hoger is.
 * ====================================================================== */
$jkc_mcp_puc = __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
if ( file_exists( $jkc_mcp_puc ) ) {
    require_once $jkc_mcp_puc;
    if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
        $jkc_mcp_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/JKCMedia/jkc-mcp-content-abilities/',
            __FILE__,
            'jkc-mcp-content-abilities'
        );
        $jkc_mcp_update_checker->setBranch( 'main' );
    }
}

/* =========================================================================
 * SETUP: bij activatie de Claude-gebruiker + applicatie-wachtwoord aanmaken
 * ====================================================================== */

register_activation_hook( __FILE__, 'jkc_mcp_activate' );

function jkc_mcp_activate() {
    if ( ! class_exists( 'WP_Application_Passwords' ) ) {
        return; // WordPress te oud voor applicatie-wachtwoorden.
    }

    $username = 'claude-editor';
    $user     = get_user_by( 'login', $username );

    if ( ! $user ) {
        $host    = wp_parse_url( home_url(), PHP_URL_HOST );
        $user_id = wp_insert_user(
            array(
                'user_login'   => $username,
                'user_pass'    => wp_generate_password( 24, true, true ),
                'user_email'   => 'claude+' . wp_rand( 1000, 9999 ) . '@' . ( $host ? $host : 'example.com' ),
                'role'         => 'editor',
                'display_name' => 'Claude Editor',
            )
        );
        if ( is_wp_error( $user_id ) ) {
            return;
        }
        $user = get_user_by( 'id', $user_id );
    }

    // Als WooCommerce actief is: geef de gebruiker ook shop_manager (productbeheer).
    if ( $user && class_exists( 'WooCommerce' ) && ! in_array( 'shop_manager', (array) $user->roles, true ) ) {
        $user->add_role( 'shop_manager' );
    }

    // Niet opnieuw aanmaken als er al een 'Claude MCP' applicatie-wachtwoord is.
    $existing = WP_Application_Passwords::get_user_application_passwords( $user->ID );
    foreach ( (array) $existing as $item ) {
        if ( isset( $item['name'] ) && 'Claude MCP' === $item['name'] ) {
            return;
        }
    }

    $created = WP_Application_Passwords::create_new_application_password( $user->ID, array( 'name' => 'Claude MCP' ) );
    if ( is_wp_error( $created ) || empty( $created[0] ) ) {
        return;
    }

    // $created[0] is het leesbare wachtwoord (wordt maar 1x getoond).
    update_option(
        'jkc_mcp_setup_credentials',
        array(
            'user'         => $username,
            'app_password' => $created[0],
        ),
        false
    );
}

/**
 * Toon de zojuist aangemaakte inloggegevens eenmalig aan een beheerder.
 */
add_action( 'admin_notices', 'jkc_mcp_credentials_notice' );

function jkc_mcp_credentials_notice() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    $creds = get_option( 'jkc_mcp_setup_credentials' );
    if ( empty( $creds['app_password'] ) ) {
        return;
    }

    $pw      = trim( chunk_split( $creds['app_password'], 4, ' ' ) );
    $dismiss = wp_nonce_url( add_query_arg( 'jkc_mcp_dismiss', '1' ), 'jkc_mcp_dismiss' );

    echo '<div class="notice notice-success">';
    echo '<p><strong>' . esc_html__( 'JKC MCP: Claude-koppeling klaargezet', 'jkc-mcp' ) . '</strong></p>';
    echo '<p>' . esc_html__( 'Gebruik deze gegevens eenmalig in het koppel-script. Kopieer ze NU, het wachtwoord wordt hierna niet meer getoond.', 'jkc-mcp' ) . '</p>';
    echo '<p style="font-size:14px;line-height:2">';
    echo '<code>' . esc_html__( 'Gebruikersnaam:', 'jkc-mcp' ) . ' ' . esc_html( $creds['user'] ) . '</code><br>';
    echo '<code>' . esc_html__( 'Applicatie-wachtwoord:', 'jkc-mcp' ) . ' ' . esc_html( $pw ) . '</code>';
    echo '</p>';
    echo '<p><a class="button button-primary" href="' . esc_url( $dismiss ) . '">' . esc_html__( 'Ik heb het gekopieerd, verbergen', 'jkc-mcp' ) . '</a></p>';
    echo '</div>';
}

add_action( 'admin_init', 'jkc_mcp_maybe_dismiss_credentials' );

function jkc_mcp_maybe_dismiss_credentials() {
    if ( ! isset( $_GET['jkc_mcp_dismiss'], $_GET['_wpnonce'] ) ) {
        return;
    }
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'jkc_mcp_dismiss' ) ) {
        return;
    }
    delete_option( 'jkc_mcp_setup_credentials' );
    wp_safe_redirect( remove_query_arg( array( 'jkc_mcp_dismiss', '_wpnonce' ) ) );
    exit;
}

/**
 * Voer geconfigureerde redirects uit (aangemaakt via jkc/create-redirect).
 */
add_action( 'template_redirect', 'jkc_mcp_do_redirects' );

function jkc_mcp_do_redirects() {
    if ( is_admin() ) {
        return;
    }
    $list = get_option( 'jkc_mcp_redirects', array() );
    if ( empty( $list ) || ! is_array( $list ) ) {
        return;
    }
    $path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
    $path = untrailingslashit( (string) $path );
    if ( '' !== $path && isset( $list[ $path ] ) ) {
        $r    = $list[ $path ];
        $type = ( isset( $r['type'] ) && 302 === (int) $r['type'] ) ? 302 : 301;
        wp_redirect( $r['to'], $type ); // phpcs:ignore WordPress.Security.SafeRedirect
        exit;
    }
}

/**
 * Geef de door dit plugin beheerde JSON-LD structured data uit in de <head>
 * van singular pagina's (los van de schema die Yoast zelf genereert).
 */
add_action( 'wp_head', 'jkc_mcp_output_schema' );

function jkc_mcp_output_schema() {
    if ( ! is_singular() ) {
        return;
    }
    $raw = (string) get_post_meta( get_queried_object_id(), '_jkc_mcp_schema', true );
    if ( '' === $raw ) {
        return;
    }
    $data = json_decode( $raw, true );
    if ( ! is_array( $data ) ) {
        return;
    }
    $json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    if ( false === $json ) {
        return;
    }
    // Veilig in <script>-context: neutraliseer eventuele sluit-tags.
    $json = str_replace( '</', '<\/', $json );
    echo "\n<script type=\"application/ld+json\">" . $json . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}

/**
 * Beheerdersmelding als de Abilities API niet beschikbaar is.
 */
add_action(
    'admin_notices',
    function () {
        if ( function_exists( 'wp_register_ability' ) ) {
            return;
        }
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        echo '<div class="notice notice-error"><p>';
        echo esc_html__( 'JKC MCP Content Abilities: de WordPress Abilities API / MCP Adapter is niet actief. Installeer en activeer die eerst.', 'jkc-mcp' );
        echo '</p></div>';
    }
);

/* =========================================================================
 * HELPERS
 * ====================================================================== */

function jkc_mcp_allowed_types() {
    return array( 'page', 'post', 'oplossingen', 'faq', 'pijler', 'cases', 'team' );
}

function jkc_mcp_allowed_statuses() {
    return array( 'draft', 'pending', 'private', 'publish' );
}

/**
 * Resolve een post (pagina of bericht) op basis van slug of ID.
 *
 * @param array $input Verwacht 'id' (int) en/of 'slug' (string), optioneel 'type'.
 * @return WP_Post|WP_Error
 */
function jkc_mcp_resolve_post( array $input ) {
    $type = isset( $input['type'] ) && in_array( $input['type'], jkc_mcp_allowed_types(), true )
        ? $input['type']
        : 'page';

    if ( ! empty( $input['id'] ) ) {
        $post = get_post( (int) $input['id'] );
        if ( $post && in_array( $post->post_type, jkc_mcp_allowed_types(), true ) ) {
            return $post;
        }
    }

    if ( ! empty( $input['slug'] ) ) {
        $slug = sanitize_title( $input['slug'] );

        if ( 'page' === $type ) {
            $post = get_page_by_path( $slug, OBJECT, 'page' );
            if ( $post ) {
                return $post;
            }
        }

        $found = get_posts(
            array(
                'name'             => $slug,
                'post_type'        => $type,
                'post_status'      => 'any',
                'numberposts'      => 1,
                'suppress_filters' => false,
            )
        );
        if ( ! empty( $found ) ) {
            return $found[0];
        }
    }

    return new WP_Error( 'not_found', __( 'Content not found.', 'jkc-mcp' ), array( 'status' => 404 ) );
}

function jkc_mcp_publish_cap( $type ) {
    $post_type = get_post_type_object( $type );

    if ( $post_type && ! empty( $post_type->cap->publish_posts ) ) {
        return $post_type->cap->publish_posts;
    }

    return ( 'page' === $type ) ? 'publish_pages' : 'publish_posts';
}

/**
 * Bereken post_date-velden voor een geplande of gedateerde publicatie.
 *
 * @param string $publish_date Datum/tijd in sitetijd, bijv. "2026-06-10 09:00".
 * @return array|WP_Error array met post_date, post_date_gmt en is_future, of WP_Error.
 */
function jkc_mcp_schedule_fields( $publish_date ) {
    try {
        $dt = new DateTime( (string) $publish_date, wp_timezone() );
    } catch ( Exception $e ) {
        return new WP_Error( 'invalid_date', __( 'Ongeldige datum/tijd. Gebruik bijvoorbeeld "2026-06-10 09:00".', 'jkc-mcp' ), array( 'status' => 400 ) );
    }
    $utc = clone $dt;
    $utc->setTimezone( new DateTimeZone( 'UTC' ) );
    return array(
        'post_date'     => $dt->format( 'Y-m-d H:i:s' ),
        'post_date_gmt' => $utc->format( 'Y-m-d H:i:s' ),
        'is_future'     => $dt->getTimestamp() > time(),
    );
}

/**
 * Bouw een volledige SEO-audit van een post.
 *
 * @param WP_Post $obj
 * @return array
 */
function jkc_mcp_build_seo_audit( $obj ) {
    $keyphrase = (string) get_post_meta( $obj->ID, '_yoast_wpseo_focuskw', true );
    $metadesc  = (string) get_post_meta( $obj->ID, '_yoast_wpseo_metadesc', true );
    $seo_title = (string) get_post_meta( $obj->ID, '_yoast_wpseo_title', true );
    $canonical = (string) get_post_meta( $obj->ID, '_yoast_wpseo_canonical', true );

    // Render content (shortcodes / blocks naar echte HTML).
    $rendered = jkc_mcp_render_content( $obj );

    $text       = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $rendered ) ) );
    $word_count = ( '' === $text ) ? 0 : count( preg_split( '/\s+/', $text ) );

    $kw_lower   = strtolower( trim( $keyphrase ) );
    $text_lower = strtolower( $text );
    $kw_words   = ( '' === $kw_lower ) ? 0 : count( preg_split( '/\s+/', $kw_lower ) );
    $kw_count   = ( '' !== $kw_lower ) ? substr_count( $text_lower, $kw_lower ) : 0;
    $density    = ( $word_count > 0 && '' !== $kw_lower )
        ? round( ( $kw_count * max( 1, $kw_words ) ) / $word_count * 100, 2 )
        : 0;

    // Subkoppen (H2/H3), inclusief koppen die in page-builder shortcodes zitten.
    $subheads = array();
    foreach ( jkc_mcp_extract_headings( $obj->post_content, $rendered ) as $hd ) {
        if ( in_array( $hd['tag'], array( 'h2', 'h3' ), true ) ) {
            $subheads[] = $hd['text'];
        }
    }
    $subheads_with_kw = 0;
    foreach ( $subheads as $h ) {
        if ( '' !== $kw_lower && false !== strpos( strtolower( $h ), $kw_lower ) ) {
            $subheads_with_kw++;
        }
    }

    $h1_count = (int) preg_match_all( '/<h1[^>]*>/i', $rendered );

    // Afbeeldingen.
    $img_total  = (int) preg_match_all( '/<img\b[^>]*>/i', $rendered, $imgs );
    $img_no_alt = 0;
    if ( $img_total ) {
        foreach ( $imgs[0] as $img ) {
            if ( ! preg_match( '/\salt\s*=\s*("[^"]+"|\'[^\']+\')/i', $img ) ) {
                $img_no_alt++;
            }
        }
    }

    // Links (via gedeelde helper, herkent ook relatieve interne links).
    $internal = 0;
    $outbound = 0;
    foreach ( jkc_mcp_extract_links( $rendered ) as $lnk ) {
        if ( $lnk['internal'] ) {
            $internal++;
        } else {
            $outbound++;
        }
    }

    // Keyphrase-locaties.
    $words_arr   = ( '' === $text ) ? array() : preg_split( '/\s+/', $text );
    $intro       = strtolower( implode( ' ', array_slice( $words_arr, 0, 150 ) ) );
    $kw_in_intro = ( '' !== $kw_lower && false !== strpos( $intro, $kw_lower ) );
    $kw_in_title = ( '' !== $kw_lower && false !== strpos( strtolower( get_the_title( $obj ) ), $kw_lower ) );
    $kw_in_slug  = ( '' !== $kw_lower && false !== strpos( strtolower( str_replace( '-', ' ', $obj->post_name ) ), $kw_lower ) );
    $kw_in_meta  = ( '' !== $kw_lower && false !== strpos( strtolower( $metadesc ), $kw_lower ) );
    $kw_in_seot  = ( '' !== $kw_lower && false !== strpos( strtolower( $seo_title ), $kw_lower ) );

    // Uitgelichte afbeelding.
    $thumb_id = get_post_thumbnail_id( $obj->ID );
    $featured = $thumb_id
        ? array(
            'id'  => (int) $thumb_id,
            'url' => (string) wp_get_attachment_image_url( $thumb_id, 'full' ),
            'alt' => (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true ),
        )
        : null;

    // Indexeerbaarheid.
    $site_indexable = ( '1' === (string) get_option( 'blog_public' ) );
    $page_noindex   = ( '1' === (string) get_post_meta( $obj->ID, '_yoast_wpseo_meta-robots-noindex', true ) );

    // Opgeslagen Yoast-scores.
    $seo_score  = get_post_meta( $obj->ID, '_yoast_wpseo_linkdex', true );
    $read_score = get_post_meta( $obj->ID, '_yoast_wpseo_content_score', true );

    // Gedetecteerde problemen.
    $issues = array();
    if ( ! $site_indexable ) {
        $issues[] = 'De hele site staat op no-index (Instellingen > Lezen). Zoekmachines indexeren niets. LET OP bij livegang.';
    }
    if ( $page_noindex ) {
        $issues[] = 'Deze pagina staat individueel op no-index.';
    }
    if ( ! $featured ) {
        $issues[] = 'Geen uitgelichte afbeelding (featured image) ingesteld.';
    }
    if ( '' === $metadesc ) {
        $issues[] = 'Geen meta description ingesteld.';
    } elseif ( strlen( $metadesc ) < 120 || strlen( $metadesc ) > 156 ) {
        $issues[] = sprintf( 'Meta description is %d tekens (ideaal 120-156).', strlen( $metadesc ) );
    }
    if ( '' === $keyphrase ) {
        $issues[] = 'Geen focus keyphrase ingesteld.';
    } else {
        if ( ! $kw_in_title ) {
            $issues[] = 'Keyphrase staat niet in de paginatitel.';
        }
        if ( ! $kw_in_slug ) {
            $issues[] = 'Keyphrase staat niet in de slug.';
        }
        if ( ! $kw_in_meta ) {
            $issues[] = 'Keyphrase staat niet in de meta description.';
        }
        if ( ! $kw_in_intro ) {
            $issues[] = 'Keyphrase staat niet in de introductie (eerste 150 woorden).';
        }
        if ( count( $subheads ) > 0 && $subheads_with_kw < (int) ceil( count( $subheads ) * 0.3 ) ) {
            $issues[] = sprintf( 'Keyphrase staat in %d van %d subkoppen (H2/H3); te weinig.', $subheads_with_kw, count( $subheads ) );
        }
        if ( $density > 0 && $density < 0.5 ) {
            $issues[] = sprintf( 'Keyphrase-dichtheid laag (%.2f%%).', $density );
        } elseif ( $density > 3 ) {
            $issues[] = sprintf( 'Keyphrase-dichtheid hoog (%.2f%%), let op keyword stuffing.', $density );
        }
    }
    if ( $h1_count > 1 ) {
        $issues[] = sprintf( 'Meerdere H1-koppen gevonden (%d).', $h1_count );
    }
    if ( $word_count < 300 ) {
        $issues[] = sprintf( 'Korte tekst (%d woorden); overweeg 300+.', $word_count );
    }
    if ( $img_no_alt > 0 ) {
        $issues[] = sprintf( '%d afbeelding(en) zonder alt-tekst.', $img_no_alt );
    }
    if ( 0 === $internal ) {
        $issues[] = 'Geen interne links gevonden.';
    }
    $permalink = (string) get_permalink( $obj );
    if ( '' !== $canonical && untrailingslashit( $canonical ) !== untrailingslashit( $permalink ) ) {
        $issues[] = sprintf( 'canonical_differs_from_permalink: canonical (%s) wijkt af van de permalink (%s).', $canonical, $permalink );
    }

    return array(
        'id'     => (int) $obj->ID,
        'type'   => $obj->post_type,
        'title'  => get_the_title( $obj ),
        'slug'   => $obj->post_name,
        'status' => $obj->post_status,
        'link'   => $permalink,
        'seo'    => array(
            'focus_keyphrase'         => $keyphrase,
            'meta_description'        => $metadesc,
            'meta_description_length' => strlen( $metadesc ),
            'seo_title'               => $seo_title,
            'canonical_url'           => $canonical,
            'seo_score'               => ( '' === $seo_score ) ? null : (int) $seo_score,
            'readability_score'       => ( '' === $read_score ) ? null : (int) $read_score,
        ),
        'indexable' => array(
            'site_indexable' => $site_indexable,
            'page_noindex'   => $page_noindex,
        ),
        'featured_image'         => $featured,
        'featured_image_missing' => ( null === $featured ),
        'checks'                 => array(
            'word_count'                 => $word_count,
            'keyphrase_count'            => $kw_count,
            'keyphrase_density_pct'      => $density,
            'keyphrase_in_title'         => $kw_in_title,
            'keyphrase_in_slug'          => $kw_in_slug,
            'keyphrase_in_meta'          => $kw_in_meta,
            'keyphrase_in_intro'         => $kw_in_intro,
            'keyphrase_in_seo_title'     => $kw_in_seot,
            'subheadings_total'          => count( $subheads ),
            'subheadings_with_keyphrase' => $subheads_with_kw,
            'subheadings'                => $subheads,
            'h1_count'                   => $h1_count,
            'images_total'               => $img_total,
            'images_without_alt'         => $img_no_alt,
            'links_internal'             => $internal,
            'links_outbound'             => $outbound,
        ),
        'issues' => $issues,
    );
}

/**
 * Render de content van een post naar echte HTML (shortcodes/blocks uitgevoerd).
 *
 * @param WP_Post $obj
 * @return string
 */
function jkc_mcp_render_content( $obj ) {
    global $post;
    $orig = $post;
    $post = $obj; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
    setup_postdata( $post );
    $rendered = apply_filters( 'the_content', $obj->post_content );
    wp_reset_postdata();
    $post = $orig; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
    return (string) $rendered;
}

/**
 * Shortcodes die als kop (heading) fungeren bij page builders.
 * Filterbaar zodat andere builders later eenvoudig toegevoegd kunnen worden.
 *
 * @return string[]
 */
function jkc_mcp_heading_shortcodes() {
    return (array) apply_filters(
        'jkc_mcp_heading_shortcodes',
        array( 'nectar_highlighted_text', 'vc_custom_heading', 'ultimate_heading', 'fancy-ul', 'heading', 'trx_sc_title' )
    );
}

/**
 * Haal alle koppen (H1-H6) uit content, inclusief koppen die verstopt zitten
 * in page-builder shortcodes (bijv. Visual Composer / Nectar).
 *
 * @param string      $raw      Ruwe post_content (met shortcodes).
 * @param string|null $rendered Optioneel: al gerenderde HTML; anders wordt $raw gebruikt.
 * @return array Lijst van array( 'tag' => 'h2', 'text' => '...', 'source' => 'html|shortcode:naam' ).
 */
function jkc_mcp_extract_headings( $raw, $rendered = null ) {
    if ( null === $rendered ) {
        $rendered = $raw;
    }
    $headings = array();
    $seen     = array();

    $add = function ( $tag, $text, $source ) use ( &$headings, &$seen ) {
        $text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
        if ( '' === $text ) {
            return;
        }
        $key = strtolower( $tag . '|' . $text );
        if ( isset( $seen[ $key ] ) ) {
            return;
        }
        $seen[ $key ]  = true;
        $headings[]    = array( 'tag' => $tag, 'text' => $text, 'source' => $source );
    };

    // 1. Echte HTML-koppen in de gerenderde output.
    if ( preg_match_all( '/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', (string) $rendered, $m, PREG_SET_ORDER ) ) {
        foreach ( $m as $h ) {
            $add( 'h' . $h[1], wp_strip_all_tags( $h[2] ), 'html' );
        }
    }

    // 2. Koppen verstopt in builder-shortcodes (uit de RUWE content).
    $shortcodes = jkc_mcp_heading_shortcodes();
    if ( ! empty( $shortcodes ) && '' !== (string) $raw ) {
        $alt = implode( '|', array_map( 'preg_quote', $shortcodes ) );

        // 2a. Omsluitende shortcodes: [naam ...]tekst[/naam].
        if ( preg_match_all( '/\[(' . $alt . ')\b([^\]]*)\](.*?)\[\/\1\]/is', (string) $raw, $sm, PREG_SET_ORDER ) ) {
            foreach ( $sm as $s ) {
                $tag  = ( preg_match( '/\btag\s*=\s*["\']?(h[1-6])/i', $s[2], $tm ) ) ? strtolower( $tm[1] ) : 'h2';
                $body = wp_strip_all_tags( strip_shortcodes( $s[3] ) );
                if ( '' === trim( $body ) && preg_match( '/\b(?:text|title|heading)\s*=\s*"([^"]*)"/i', $s[2], $am ) ) {
                    $body = wp_strip_all_tags( $am[1] );
                }
                $add( $tag, $body, 'shortcode:' . strtolower( $s[1] ) );
            }
        }

        // 2b. Zelfsluitende shortcodes met text/title/heading-attribuut.
        if ( preg_match_all( '/\[(' . $alt . ')\b([^\]]*?)\/?\]/i', (string) $raw, $sm2, PREG_SET_ORDER ) ) {
            foreach ( $sm2 as $s ) {
                if ( preg_match( '/\b(?:text|title|heading)\s*=\s*"([^"]*)"/i', $s[2], $am ) ) {
                    $tag = ( preg_match( '/\btag\s*=\s*["\']?(h[1-6])/i', $s[2], $tm ) ) ? strtolower( $tm[1] ) : 'h2';
                    $add( $tag, wp_strip_all_tags( $am[1] ), 'shortcode:' . strtolower( $s[1] ) );
                }
            }
        }
    }

    return $headings;
}

/**
 * Haal alle hyperlinks uit een stuk HTML, met ankertekst en doel-URL.
 *
 * @param string $html
 * @return array Lijst van array( anchor, href, url, relative, internal ).
 */
function jkc_mcp_extract_links( $html ) {
    $links     = array();
    $home_host = wp_parse_url( home_url(), PHP_URL_HOST );

    if ( ! preg_match_all( '/<a\b[^>]*?href\s*=\s*("[^"]*"|\'[^\']*\')[^>]*>(.*?)<\/a>/is', (string) $html, $m, PREG_SET_ORDER ) ) {
        return $links;
    }

    foreach ( $m as $a ) {
        $href = trim( $a[1], '"\'' );
        if ( '' === $href ) {
            continue;
        }
        $lower = strtolower( $href );
        if ( '#' === $href[0]
            || 0 === strpos( $lower, 'mailto:' )
            || 0 === strpos( $lower, 'tel:' )
            || 0 === strpos( $lower, 'javascript:' ) ) {
            continue;
        }

        $is_relative = ! preg_match( '#^https?://#i', $href ) && 0 !== strpos( $href, '//' );
        $resolved    = $href;
        if ( 0 === strpos( $href, '//' ) ) {
            $resolved = ( is_ssl() ? 'https:' : 'http:' ) . $href;
        } elseif ( 0 === strpos( $href, '/' ) ) {
            $resolved = home_url( $href );
        }

        $host        = wp_parse_url( $resolved, PHP_URL_HOST );
        $is_internal = ( ! $host || $host === $home_host );

        $links[] = array(
            'anchor'   => trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $a[2] ) ) ),
            'href'     => $href,
            'url'      => $resolved,
            'relative' => (bool) $is_relative,
            'internal' => (bool) $is_internal,
        );
    }

    return $links;
}

/**
 * Controleer of een URL bereikbaar is. Volgt redirects niet automatisch, zodat
 * een 3xx als redirect gerapporteerd kan worden mét de uiteindelijke URL.
 *
 * @param string $url
 * @return array array( http_code, status, final_url, message ).
 */
function jkc_mcp_check_url( $url ) {
    $args = array(
        'timeout'     => 8,
        'redirection' => 0,
        'sslverify'   => true,
        'user-agent'  => 'JKC-MCP-LinkCheck',
    );

    $resp = wp_remote_head( $url, $args );
    $code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );

    // Sommige servers staan geen HEAD toe (405) of antwoorden niet: probeer GET.
    if ( is_wp_error( $resp ) || 0 === $code || 405 === $code ) {
        $resp = wp_remote_get( $url, array_merge( $args, array( 'timeout' => 10 ) ) );
        $code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
    }

    if ( is_wp_error( $resp ) ) {
        $msg    = $resp->get_error_message();
        $status = ( false !== stripos( $msg, 'timed out' ) || false !== stripos( $msg, 'timeout' ) ) ? 'timeout' : 'error';
        return array( 'http_code' => 0, 'status' => $status, 'final_url' => null, 'message' => $msg );
    }

    $final  = null;
    $status = 'ok';
    if ( $code >= 300 && $code < 400 ) {
        $loc    = wp_remote_retrieve_header( $resp, 'location' );
        $final  = $loc ? $loc : null;
        $status = 'redirect';
    } elseif ( 404 === $code ) {
        $status = 'not_found';
    } elseif ( 0 === $code || $code >= 400 ) {
        $status = 'error';
    }

    return array( 'http_code' => $code, 'status' => $status, 'final_url' => $final, 'message' => '' );
}

/**
 * Stel de alt-tekst van een media-bijlage in (zonder andere metadata te raken).
 *
 * @param int    $id  Attachment-ID.
 * @param string $alt Nieuwe alt-tekst.
 * @return array|WP_Error
 */
function jkc_mcp_set_attachment_alt( $id, $alt ) {
    $att = get_post( $id );
    if ( ! $att || 'attachment' !== $att->post_type ) {
        return new WP_Error( 'not_found', __( 'Attachment niet gevonden.', 'jkc-mcp' ), array( 'status' => 404 ) );
    }
    if ( ! current_user_can( 'edit_post', $id ) ) {
        return new WP_Error( 'forbidden', __( 'Geen rechten.', 'jkc-mcp' ), array( 'status' => 403 ) );
    }
    update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
    return array( 'id' => (int) $id, 'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ), 'status' => 'bijgewerkt' );
}

/**
 * Lees Yoast SEO-velden van een taxonomy-term (opgeslagen in option wpseo_taxonomy_meta).
 *
 * @param string $taxonomy
 * @param int    $term_id
 * @return array array( seo_title, meta_description, focus_keyphrase, canonical, noindex ).
 */
function jkc_mcp_get_term_yoast( $taxonomy, $term_id ) {
    $opt  = get_option( 'wpseo_taxonomy_meta', array() );
    $meta = ( is_array( $opt ) && isset( $opt[ $taxonomy ][ $term_id ] ) ) ? $opt[ $taxonomy ][ $term_id ] : array();
    return array(
        'seo_title'        => isset( $meta['wpseo_title'] ) ? (string) $meta['wpseo_title'] : '',
        'meta_description' => isset( $meta['wpseo_desc'] ) ? (string) $meta['wpseo_desc'] : '',
        'focus_keyphrase'  => isset( $meta['wpseo_focuskw'] ) ? (string) $meta['wpseo_focuskw'] : '',
        'canonical'        => isset( $meta['wpseo_canonical'] ) ? (string) $meta['wpseo_canonical'] : '',
        'noindex'          => isset( $meta['wpseo_noindex'] ) && 'noindex' === $meta['wpseo_noindex'],
    );
}

/**
 * Schrijf Yoast SEO-velden van een taxonomy-term weg.
 *
 * @param string $taxonomy
 * @param int    $term_id
 * @param array  $fields seo_title, meta_description, focus_keyphrase (alle optioneel).
 * @return array De bijgewerkte velden (zie jkc_mcp_get_term_yoast()).
 */
function jkc_mcp_set_term_yoast( $taxonomy, $term_id, $fields ) {
    $opt = get_option( 'wpseo_taxonomy_meta', array() );
    if ( ! is_array( $opt ) ) {
        $opt = array();
    }
    if ( ! isset( $opt[ $taxonomy ] ) || ! is_array( $opt[ $taxonomy ] ) ) {
        $opt[ $taxonomy ] = array();
    }
    if ( ! isset( $opt[ $taxonomy ][ $term_id ] ) || ! is_array( $opt[ $taxonomy ][ $term_id ] ) ) {
        $opt[ $taxonomy ][ $term_id ] = array();
    }

    $map = array(
        'seo_title'        => 'wpseo_title',
        'meta_description' => 'wpseo_desc',
        'focus_keyphrase'  => 'wpseo_focuskw',
    );
    foreach ( $map as $in => $yk ) {
        if ( isset( $fields[ $in ] ) ) {
            $opt[ $taxonomy ][ $term_id ][ $yk ] = sanitize_text_field( $fields[ $in ] );
        }
    }

    update_option( 'wpseo_taxonomy_meta', $opt );
    return jkc_mcp_get_term_yoast( $taxonomy, $term_id );
}

/**
 * Resolve een taxonomy-term op ID of slug.
 *
 * @param array  $input    Verwacht 'id' (int) en/of 'slug' (string).
 * @param string $taxonomy Taxonomy-slug, bijv. 'product_cat'.
 * @return WP_Term|WP_Error
 */
function jkc_mcp_resolve_term( $input, $taxonomy ) {
    if ( ! empty( $input['id'] ) ) {
        $t = get_term( (int) $input['id'], $taxonomy );
        if ( $t && ! is_wp_error( $t ) ) {
            return $t;
        }
    }
    if ( ! empty( $input['slug'] ) ) {
        $t = get_term_by( 'slug', sanitize_title( $input['slug'] ), $taxonomy );
        if ( $t ) {
            return $t;
        }
    }
    return new WP_Error( 'not_found', __( 'Term niet gevonden.', 'jkc-mcp' ), array( 'status' => 404 ) );
}

/* =========================================================================
 * ABILITIES
 * ====================================================================== */

add_action( 'wp_abilities_api_categories_init', 'jkc_mcp_register_category' );

function jkc_mcp_register_category() {
    if ( ! function_exists( 'wp_register_ability_category' ) ) {
        return;
    }

    wp_register_ability_category(
        'jkc-content',
        array(
            'label'       => __( 'JKC Content', 'jkc-mcp' ),
            'description' => __( 'Read, audit and manage site content.', 'jkc-mcp' ),
        )
    );
}

add_action( 'wp_abilities_api_init', 'jkc_mcp_register_abilities' );

function jkc_mcp_register_abilities() {
    if ( ! function_exists( 'wp_register_ability' ) ) {
        return;
    }

    $type_prop = array(
        'type'        => 'string',
        'enum'        => jkc_mcp_allowed_types(),
        'description' => 'Content type: "page" (default), "post", "oplossingen", "faq", "pijler", "cases" or "team".',
    );

    /* ---- READ: get content ------------------------------------------- */
    wp_register_ability(
        'jkc/get-content',
        array(
            'label'         => __( 'Get Content', 'jkc-mcp' ),
            'description'   => __( 'Returns the title, content, status and Yoast SEO meta of an allowed content item by slug or ID.', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array(
                'type'       => 'object',
                'properties' => array(
                    'slug' => array( 'type' => 'string', 'description' => 'The slug, for example "over-ons".' ),
                    'id'   => array( 'type' => 'integer', 'description' => 'The ID (alternative to slug).' ),
                    'type' => $type_prop,
                ),
            ),
            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'               => array( 'type' => 'integer' ),
                    'type'             => array( 'type' => 'string' ),
                    'title'            => array( 'type' => 'string' ),
                    'content'          => array( 'type' => 'string' ),
                    'headings'         => array( 'type' => 'array', 'description' => 'Gedetecteerde koppen (H1-H6), inclusief koppen in page-builder shortcodes. Elk item: tag, text, source.' ),
                    'status'           => array( 'type' => 'string' ),
                    'link'             => array( 'type' => 'string', 'format' => 'uri' ),
                    'meta_description' => array( 'type' => 'string' ),
                    'focus_keyphrase'  => array( 'type' => 'string' ),
                    'seo_title'        => array( 'type' => 'string' ),
                ),
            ),
            'execute_callback'    => function ( array $input ) {
                $post = jkc_mcp_resolve_post( $input );
                if ( is_wp_error( $post ) ) {
                    return $post;
                }
                return array(
                    'id'               => (int) $post->ID,
                    'type'             => $post->post_type,
                    'title'            => get_the_title( $post ),
                    'content'          => $post->post_content,
                    'headings'         => jkc_mcp_extract_headings( $post->post_content, jkc_mcp_render_content( $post ) ),
                    'status'           => $post->post_status,
                    'link'             => (string) get_permalink( $post ),
                    'meta_description' => (string) get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true ),
                    'focus_keyphrase'  => (string) get_post_meta( $post->ID, '_yoast_wpseo_focuskw', true ),
                    'seo_title'        => (string) get_post_meta( $post->ID, '_yoast_wpseo_title', true ),
                );
            },
            'permission_callback' => function () {
                return current_user_can( 'edit_pages' ) || current_user_can( 'edit_posts' );
            },
            'meta'                => array(
                'annotations' => array( 'readonly' => true, 'destructive' => false ),
                'mcp'         => array( 'public' => true ),
            ),
        )
    );

    /* ---- READ: find content (zoek pagina/bericht op naam) ------------ */
    wp_register_ability(
        'jkc/find-content',
        array(
            'label'         => __( 'Find Content', 'jkc-mcp' ),
            'description'   => __( 'Search content by a partial title or slug, or list everything when no query is given. Without a "type" it searches across ALL allowed types (pages, posts and custom post types); pass "type" to limit to one. Use this FIRST when the user refers to content loosely or in another language to find the correct slug/id before calling get-content, seo-audit or update tools. Each result includes its type (page/post/...), id, title, slug, status and link.', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array(
                'type'       => 'object',
                'properties' => array(
                    'query' => array(
                        'type'        => 'string',
                        'description' => 'Partial title or slug to match. Leave empty to list everything.',
                    ),
                    'type'  => $type_prop,
                    'limit' => array( 'type' => 'integer', 'description' => 'Max results (default 50).' ),
                ),
            ),
            'output_schema' => array( 'type' => 'object' ),
            'execute_callback'    => function ( array $input ) {
                // Geen type opgegeven: zoek over alle toegestane types (pagina's + berichten + CPT's).
                // Wel een type opgegeven: alleen dat type.
                $types = ( isset( $input['type'] ) && in_array( $input['type'], jkc_mcp_allowed_types(), true ) )
                    ? array( $input['type'] )
                    : jkc_mcp_allowed_types();
                $limit = isset( $input['limit'] ) ? max( 1, min( (int) $input['limit'], 200 ) ) : 50;
                $q     = isset( $input['query'] ) ? strtolower( trim( $input['query'] ) ) : '';

                $all = get_posts(
                    array(
                        'post_type'        => $types,
                        'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
                        'numberposts'      => 300,
                        'orderby'          => 'title',
                        'order'            => 'ASC',
                        'suppress_filters' => false,
                    )
                );

                $out = array();
                foreach ( $all as $p ) {
                    $title = get_the_title( $p );
                    if ( '' === $q
                        || false !== strpos( strtolower( $title ), $q )
                        || false !== strpos( strtolower( $p->post_name ), $q ) ) {
                        $out[] = array(
                            'id'     => (int) $p->ID,
                            'type'   => $p->post_type,
                            'title'  => $title,
                            'slug'   => $p->post_name,
                            'status' => $p->post_status,
                            'link'   => (string) get_permalink( $p ),
                        );
                    }
                    if ( count( $out ) >= $limit ) {
                        break;
                    }
                }
                return array( 'type' => ( count( $types ) === 1 ? $types[0] : 'all' ), 'query' => $q, 'count' => count( $out ), 'results' => $out );
            },
            'permission_callback' => function () {
                return current_user_can( 'edit_pages' ) || current_user_can( 'edit_posts' );
            },
            'meta'                => array(
                'annotations' => array( 'readonly' => true, 'destructive' => false ),
                'mcp'         => array( 'public' => true ),
            ),
        )
    );

    /* ---- READ: full SEO audit ---------------------------------------- */
    wp_register_ability(
        'jkc/seo-audit',
        array(
            'label'         => __( 'SEO Audit', 'jkc-mcp' ),
            'description'   => __( 'Returns a complete SEO snapshot of an allowed content item: Yoast meta (including canonical_url), stored scores, featured_image and featured_image_missing flag, indexability (page_noindex), and computed checks plus a list of detected issues (including canonical_differs_from_permalink).', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array(
                'type'       => 'object',
                'properties' => array(
                    'slug' => array( 'type' => 'string', 'description' => 'The slug (or use id).' ),
                    'id'   => array( 'type' => 'integer', 'description' => 'The ID (or use slug).' ),
                    'type' => $type_prop,
                ),
            ),
            'output_schema' => array( 'type' => 'object' ),
            'execute_callback'    => function ( array $input ) {
                $post = jkc_mcp_resolve_post( $input );
                if ( is_wp_error( $post ) ) {
                    return $post;
                }
                return jkc_mcp_build_seo_audit( $post );
            },
            'permission_callback' => function () {
                return current_user_can( 'edit_pages' ) || current_user_can( 'edit_posts' );
            },
            'meta'                => array(
                'annotations' => array( 'readonly' => true, 'destructive' => false ),
                'mcp'         => array( 'public' => true ),
            ),
        )
    );

    /* ---- READ: bulk SEO audit (hele site snel scannen) --------------- */
    wp_register_ability(
        'jkc/bulk-seo-audit',
        array(
            'label'         => __( 'Bulk SEO Audit', 'jkc-mcp' ),
            'description'   => __( 'Scans all pages, posts or WooCommerce products and returns a prioritised list of SEO issues per item (missing meta description, missing focus keyphrase, missing featured image, no-index, meta length off). Lightweight site-wide check; use jkc/seo-audit for a deep single-page analysis.', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array(
                'type'       => 'object',
                'properties' => array(
                    'type'  => array(
                        'type'        => 'string',
                        'enum'        => array_merge( jkc_mcp_allowed_types(), array( 'product' ) ),
                        'description' => 'Content type to scan. Defaults to "page". Includes allowed custom post types and "product" when WooCommerce is active.',
                    ),
                    'filter' => array(
                        'type'        => 'string',
                        'enum'        => array( 'all', 'featured_image_missing', 'no_meta_description', 'no_focus_keyphrase', 'meta_length_off', 'noindex' ),
                        'description' => 'Toon alleen items met dit probleem. Default "all". Gebruik "featured_image_missing" om alleen items zonder uitgelichte afbeelding te tonen.',
                    ),
                    'limit' => array( 'type' => 'integer', 'description' => 'Max items (default 200).' ),
                ),
            ),
            'output_schema' => array( 'type' => 'object' ),
            'execute_callback'    => function ( array $input ) {
                $bulk_types = jkc_mcp_allowed_types();
                if ( function_exists( 'wc_get_product' ) ) {
                    $bulk_types[] = 'product';
                }
                $type   = isset( $input['type'] ) && in_array( $input['type'], $bulk_types, true )
                    ? $input['type']
                    : 'page';
                $valid_filters = array( 'all', 'featured_image_missing', 'no_meta_description', 'no_focus_keyphrase', 'meta_length_off', 'noindex' );
                $filter = isset( $input['filter'] ) && in_array( $input['filter'], $valid_filters, true ) ? $input['filter'] : 'all';
                $limit  = isset( $input['limit'] ) ? max( 1, min( (int) $input['limit'], 500 ) ) : 200;

                $all = get_posts(
                    array(
                        'post_type'        => $type,
                        'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
                        'numberposts'      => $limit,
                        'orderby'          => 'title',
                        'order'            => 'ASC',
                        'suppress_filters' => false,
                    )
                );

                $site_indexable = ( '1' === (string) get_option( 'blog_public' ) );
                $items   = array();
                $summary = array(
                    'no_meta_description' => 0,
                    'meta_length_off'     => 0,
                    'no_focus_keyphrase'  => 0,
                    'no_featured_image'   => 0,
                    'page_noindex'        => 0,
                );

                foreach ( $all as $p ) {
                    $md      = (string) get_post_meta( $p->ID, '_yoast_wpseo_metadesc', true );
                    $kw      = (string) get_post_meta( $p->ID, '_yoast_wpseo_focuskw', true );
                    $thumb   = get_post_thumbnail_id( $p->ID );
                    $noindex = ( '1' === (string) get_post_meta( $p->ID, '_yoast_wpseo_meta-robots-noindex', true ) );

                    $issues = array();
                    if ( '' === $md ) {
                        $issues[] = 'geen meta description';
                        $summary['no_meta_description']++;
                    } elseif ( strlen( $md ) < 120 || strlen( $md ) > 156 ) {
                        $issues[] = sprintf( 'meta description %d tekens (ideaal 120-156)', strlen( $md ) );
                        $summary['meta_length_off']++;
                    }
                    if ( '' === $kw ) {
                        $issues[] = 'geen focus keyphrase';
                        $summary['no_focus_keyphrase']++;
                    }
                    if ( ! $thumb ) {
                        $issues[] = 'geen featured image';
                        $summary['no_featured_image']++;
                    }
                    if ( $noindex ) {
                        $issues[] = 'pagina op no-index';
                        $summary['page_noindex']++;
                    }

                    // Per-item vlaggen voor de filteroptie.
                    $flags = array(
                        'no_meta_description'    => ( '' === $md ),
                        'meta_length_off'        => ( '' !== $md && ( strlen( $md ) < 120 || strlen( $md ) > 156 ) ),
                        'no_focus_keyphrase'     => ( '' === $kw ),
                        'featured_image_missing' => ( ! $thumb ),
                        'noindex'                => $noindex,
                    );

                    if ( 'all' !== $filter && empty( $flags[ $filter ] ) ) {
                        continue; // Voldoet niet aan de gevraagde filter (telt nog wel mee in summary).
                    }

                    if ( ! empty( $issues ) ) {
                        $items[] = array(
                            'id'     => (int) $p->ID,
                            'title'  => get_the_title( $p ),
                            'slug'   => $p->post_name,
                            'status' => $p->post_status,
                            'issues' => $issues,
                        );
                    }
                }

                return array(
                    'type'           => $type,
                    'filter'         => $filter,
                    'scanned'        => count( $all ),
                    'with_issues'    => count( $items ),
                    'site_indexable' => $site_indexable,
                    'summary'        => $summary,
                    'items'          => $items,
                );
            },
            'permission_callback' => function () {
                return current_user_can( 'edit_pages' ) || current_user_can( 'edit_posts' );
            },
            'meta'                => array(
                'annotations' => array( 'readonly' => true, 'destructive' => false ),
                'mcp'         => array( 'public' => true ),
            ),
        )
    );

    /* ---- WRITE: update content --------------------------------------- */
    wp_register_ability(
        'jkc/update-content',
        array(
            'label'         => __( 'Update Content', 'jkc-mcp' ),
            'description'   => __( 'Updates the title, content, status and/or scheduled publish date of an existing allowed content item. Use publish_date to schedule. Publishing requires publish rights.', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array(
                'type'       => 'object',
                'properties' => array(
                    'slug'    => array( 'type' => 'string', 'description' => 'The slug to update (or use id).' ),
                    'id'      => array( 'type' => 'integer', 'description' => 'The ID to update (or use slug).' ),
                    'type'    => $type_prop,
                    'title'   => array( 'type' => 'string', 'description' => 'New title (optional).' ),
                    'content' => array( 'type' => 'string', 'description' => 'New content as HTML / block markup (optional).' ),
                    'status'  => array(
                        'type'        => 'string',
                        'enum'        => array( 'draft', 'pending', 'private', 'publish' ),
                        'description' => 'New status (optional).',
                    ),
                    'publish_date' => array(
                        'type'        => 'string',
                        'description' => 'Optioneel: plan publicatie op deze datum/tijd in sitetijd (bijv. "2026-06-10 09:00"). Toekomstige tijd = ingepland (status future).',
                    ),
                ),
            ),
            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'     => array( 'type' => 'integer' ),
                    'status' => array( 'type' => 'string' ),
                    'link'   => array( 'type' => 'string', 'format' => 'uri' ),
                ),
            ),
            'execute_callback'    => function ( array $input ) {
                $post = jkc_mcp_resolve_post( $input );
                if ( is_wp_error( $post ) ) {
                    return $post;
                }
                if ( ! current_user_can( 'edit_post', $post->ID ) ) {
                    return new WP_Error( 'forbidden', __( 'You cannot edit this content.', 'jkc-mcp' ), array( 'status' => 403 ) );
                }

                $update = array( 'ID' => $post->ID );
                if ( isset( $input['title'] ) ) {
                    $update['post_title'] = sanitize_text_field( $input['title'] );
                }
                if ( isset( $input['content'] ) ) {
                    $update['post_content'] = $input['content'];
                }
                if ( isset( $input['status'] ) ) {
                    $status = sanitize_key( $input['status'] );
                    if ( ! in_array( $status, jkc_mcp_allowed_statuses(), true ) ) {
                        return new WP_Error( 'invalid_status', __( 'Invalid status.', 'jkc-mcp' ), array( 'status' => 400 ) );
                    }
                    if ( 'publish' === $status && ! current_user_can( jkc_mcp_publish_cap( $post->post_type ) ) ) {
                        return new WP_Error( 'forbidden', __( 'You cannot publish this content.', 'jkc-mcp' ), array( 'status' => 403 ) );
                    }
                    $update['post_status'] = $status;
                }

                if ( ! empty( $input['publish_date'] ) ) {
                    $sched = jkc_mcp_schedule_fields( $input['publish_date'] );
                    if ( is_wp_error( $sched ) ) {
                        return $sched;
                    }
                    if ( ! current_user_can( jkc_mcp_publish_cap( $post->post_type ) ) ) {
                        return new WP_Error( 'forbidden', __( 'You cannot schedule/publish this content.', 'jkc-mcp' ), array( 'status' => 403 ) );
                    }
                    $update['post_date']     = $sched['post_date'];
                    $update['post_date_gmt'] = $sched['post_date_gmt'];
                    $update['post_status']   = $sched['is_future'] ? 'future' : 'publish';
                }

                $result = wp_update_post( $update, true );
                if ( is_wp_error( $result ) ) {
                    return $result;
                }
                $updated = get_post( $result );
                return array(
                    'id'     => (int) $updated->ID,
                    'status' => $updated->post_status,
                    'link'   => (string) get_permalink( $updated ),
                );
            },
            'permission_callback' => function () {
                return current_user_can( 'edit_pages' ) || current_user_can( 'edit_posts' );
            },
            'meta'                => array(
                'annotations' => array( 'readonly' => false, 'destructive' => true ),
                'mcp'         => array( 'public' => true ),
            ),
        )
    );

    /* ---- WRITE: update Yoast SEO meta -------------------------------- */
    wp_register_ability(
        'jkc/update-seo-meta',
        array(
            'label'         => __( 'Update SEO Meta', 'jkc-mcp' ),
            'description'   => __( 'Updates the Yoast meta description, focus keyphrase, SEO title, canonical URL and/or noindex flag of an allowed content item. Set noindex=true to keep a page out of search results WITHOUT changing its publication status; noindex=false restores default indexing. Set canonical to an URL (empty string clears it). Each field is optional; only provided fields change.', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array(
                'type'       => 'object',
                'properties' => array(
                    'slug'             => array( 'type' => 'string', 'description' => 'The slug (or use id).' ),
                    'id'               => array( 'type' => 'integer', 'description' => 'The ID (or use slug).' ),
                    'type'             => $type_prop,
                    'meta_description' => array( 'type' => 'string', 'description' => 'New Yoast meta description (optional).' ),
                    'focus_keyphrase'  => array( 'type' => 'string', 'description' => 'New Yoast focus keyphrase (optional).' ),
                    'seo_title'        => array( 'type' => 'string', 'description' => 'New Yoast SEO title (optional).' ),
                    'canonical'        => array( 'type' => 'string', 'description' => 'Canonical URL (rel=canonical). Empty string clears it. Optional.' ),
                    'noindex'          => array( 'type' => 'boolean', 'description' => 'true = noindex (uit zoekresultaten houden zonder de status te wijzigen), false = standaard indexeren. Optioneel.' ),
                ),
            ),
            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'               => array( 'type' => 'integer' ),
                    'meta_description' => array( 'type' => 'string' ),
                    'focus_keyphrase'  => array( 'type' => 'string' ),
                    'seo_title'        => array( 'type' => 'string' ),
                    'canonical'        => array( 'type' => 'string' ),
                    'noindex'          => array( 'type' => 'boolean' ),
                    'status'           => array( 'type' => 'string', 'description' => 'Publicatiestatus (blijft ongewijzigd door deze tool).' ),
                ),
            ),
            'execute_callback'    => function ( array $input ) {
                $post = jkc_mcp_resolve_post( $input );
                if ( is_wp_error( $post ) ) {
                    return $post;
                }
                if ( ! current_user_can( 'edit_post', $post->ID ) ) {
                    return new WP_Error( 'forbidden', __( 'You cannot edit this content.', 'jkc-mcp' ), array( 'status' => 403 ) );
                }

                if ( isset( $input['meta_description'] ) ) {
                    update_post_meta( $post->ID, '_yoast_wpseo_metadesc', sanitize_text_field( $input['meta_description'] ) );
                }
                if ( isset( $input['focus_keyphrase'] ) ) {
                    update_post_meta( $post->ID, '_yoast_wpseo_focuskw', sanitize_text_field( $input['focus_keyphrase'] ) );
                }
                if ( isset( $input['seo_title'] ) ) {
                    update_post_meta( $post->ID, '_yoast_wpseo_title', sanitize_text_field( $input['seo_title'] ) );
                }
                if ( isset( $input['canonical'] ) ) {
                    $canonical = trim( (string) $input['canonical'] );
                    if ( '' === $canonical ) {
                        delete_post_meta( $post->ID, '_yoast_wpseo_canonical' );
                    } else {
                        update_post_meta( $post->ID, '_yoast_wpseo_canonical', esc_url_raw( $canonical ) );
                    }
                }
                if ( isset( $input['noindex'] ) ) {
                    // Yoast: '1' = noindex, '2' = expliciet index. false -> meta verwijderen = standaardgedrag (index).
                    if ( filter_var( $input['noindex'], FILTER_VALIDATE_BOOLEAN ) ) {
                        update_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', '1' );
                    } else {
                        delete_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex' );
                    }
                }

                return array(
                    'id'               => (int) $post->ID,
                    'meta_description' => (string) get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true ),
                    'focus_keyphrase'  => (string) get_post_meta( $post->ID, '_yoast_wpseo_focuskw', true ),
                    'seo_title'        => (string) get_post_meta( $post->ID, '_yoast_wpseo_title', true ),
                    'canonical'        => (string) get_post_meta( $post->ID, '_yoast_wpseo_canonical', true ),
                    'noindex'          => ( '1' === (string) get_post_meta( $post->ID, '_yoast_wpseo_meta-robots-noindex', true ) ),
                    'status'           => $post->post_status,
                );
            },
            'permission_callback' => function () {
                return current_user_can( 'edit_pages' ) || current_user_can( 'edit_posts' );
            },
            'meta'                => array(
                'annotations' => array( 'readonly' => false, 'destructive' => true ),
                'mcp'         => array( 'public' => true ),
            ),
        )
    );

    /* ---- WRITE: bulk update SEO meta --------------------------------- */
    wp_register_ability(
        'jkc/bulk-update-seo-meta',
        array(
            'label'         => __( 'Bulk Update SEO Meta', 'jkc-mcp' ),
            'description'   => __( 'Apply Yoast meta (meta_description, focus_keyphrase, seo_title) to many pages/posts at once. Provide an "items" array where each item has slug or id plus the fields to set. Generate the texts first, show the user the batch and get approval before calling.', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array(
                'type'       => 'object',
                'properties' => array(
                    'items' => array(
                        'type'        => 'array',
                        'description' => 'Lijst van objecten: {slug of id, meta_description?, focus_keyphrase?, seo_title?, type?}.',
                    ),
                ),
                'required'   => array( 'items' ),
            ),
            'output_schema' => array( 'type' => 'object' ),
            'execute_callback'    => function ( array $input ) {
                $items = ( isset( $input['items'] ) && is_array( $input['items'] ) ) ? $input['items'] : array();
                if ( empty( $items ) ) {
                    return array( 'error' => true, 'message' => 'Geef een niet-lege items-lijst.' );
                }
                $results = array();
                $ok      = 0;
                foreach ( $items as $it ) {
                    if ( ! is_array( $it ) ) {
                        continue;
                    }
                    $post = jkc_mcp_resolve_post( $it );
                    if ( is_wp_error( $post ) ) {
                        $results[] = array( 'input' => $it, 'status' => 'niet gevonden' );
                        continue;
                    }
                    if ( ! current_user_can( 'edit_post', $post->ID ) ) {
                        $results[] = array( 'id' => (int) $post->ID, 'status' => 'geen rechten' );
                        continue;
                    }
                    if ( isset( $it['meta_description'] ) ) {
                        update_post_meta( $post->ID, '_yoast_wpseo_metadesc', sanitize_text_field( $it['meta_description'] ) );
                    }
                    if ( isset( $it['focus_keyphrase'] ) ) {
                        update_post_meta( $post->ID, '_yoast_wpseo_focuskw', sanitize_text_field( $it['focus_keyphrase'] ) );
                    }
                    if ( isset( $it['seo_title'] ) ) {
                        update_post_meta( $post->ID, '_yoast_wpseo_title', sanitize_text_field( $it['seo_title'] ) );
                    }
                    $ok++;
                    $results[] = array( 'id' => (int) $post->ID, 'title' => get_the_title( $post ), 'status' => 'bijgewerkt' );
                }
                return array( 'updated' => $ok, 'total' => count( $items ), 'results' => $results );
            },
            'permission_callback' => function () {
                return current_user_can( 'edit_pages' ) || current_user_can( 'edit_posts' );
            },
            'meta'                => array(
                'annotations' => array( 'readonly' => false, 'destructive' => true ),
                'mcp'         => array( 'public' => true ),
            ),
        )
    );

    /* ---- WRITE: create content --------------------------------------- */
    wp_register_ability(
        'jkc/create-content',
        array(
            'label'         => __( 'Create Content', 'jkc-mcp' ),
            'description'   => __( 'Creates a new allowed content item. Use publish_date to schedule it for a future date/time (status becomes future). Publishing requires publish rights.', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array(
                'type'       => 'object',
                'properties' => array(
                    'title'   => array( 'type' => 'string', 'description' => 'The new title.' ),
                    'type'    => $type_prop,
                    'content' => array( 'type' => 'string', 'description' => 'The new content as HTML / block markup (optional).' ),
                    'status'  => array(
                        'type'        => 'string',
                        'enum'        => array( 'draft', 'pending', 'private', 'publish' ),
                        'description' => 'Status, defaults to draft.',
                    ),
                    'publish_date' => array(
                        'type'        => 'string',
                        'description' => 'Optioneel: plan publicatie op deze datum/tijd in sitetijd (bijv. "2026-06-10 09:00"). Toekomstige tijd = ingepland (status future).',
                    ),
                ),
                'required'   => array( 'title' ),
            ),
            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'     => array( 'type' => 'integer' ),
                    'status' => array( 'type' => 'string' ),
                    'link'   => array( 'type' => 'string', 'format' => 'uri' ),
                ),
            ),
            'execute_callback'    => function ( array $input ) {
                $type = isset( $input['type'] ) && in_array( $input['type'], jkc_mcp_allowed_types(), true )
                    ? $input['type']
                    : 'page';

                $status = isset( $input['status'] ) ? sanitize_key( $input['status'] ) : 'draft';
                if ( ! in_array( $status, jkc_mcp_allowed_statuses(), true ) ) {
                    return new WP_Error( 'invalid_status', __( 'Invalid status.', 'jkc-mcp' ), array( 'status' => 400 ) );
                }
                if ( 'publish' === $status && ! current_user_can( jkc_mcp_publish_cap( $type ) ) ) {
                    return new WP_Error( 'forbidden', __( 'You cannot publish this content.', 'jkc-mcp' ), array( 'status' => 403 ) );
                }

                $postarr = array(
                    'post_type'    => $type,
                    'post_title'   => sanitize_text_field( $input['title'] ),
                    'post_content' => isset( $input['content'] ) ? $input['content'] : '',
                    'post_status'  => $status,
                );

                if ( ! empty( $input['publish_date'] ) ) {
                    $sched = jkc_mcp_schedule_fields( $input['publish_date'] );
                    if ( is_wp_error( $sched ) ) {
                        return $sched;
                    }
                    if ( ! current_user_can( jkc_mcp_publish_cap( $type ) ) ) {
                        return new WP_Error( 'forbidden', __( 'You cannot schedule/publish this content.', 'jkc-mcp' ), array( 'status' => 403 ) );
                    }
                    $postarr['post_date']     = $sched['post_date'];
                    $postarr['post_date_gmt'] = $sched['post_date_gmt'];
                    $postarr['post_status']   = $sched['is_future'] ? 'future' : 'publish';
                }

                $result = wp_insert_post( $postarr, true );
                if ( is_wp_error( $result ) ) {
                    return $result;
                }
                $created = get_post( $result );
                return array(
                    'id'     => (int) $created->ID,
                    'status' => $created->post_status,
                    'link'   => (string) get_permalink( $created ),
                );
            },
            'permission_callback' => function () {
                return current_user_can( 'publish_pages' ) || current_user_can( 'publish_posts' );
            },
            'meta'                => array(
                'annotations' => array( 'readonly' => false, 'destructive' => false ),
                'mcp'         => array( 'public' => true ),
            ),
        )
    );

    /* ---- FIX-DE-AUDIT: afbeeldingen zonder alt ----------------------- */
    wp_register_ability(
        'jkc/find-images-without-alt',
        array(
            'label'         => __( 'Find Images Without Alt', 'jkc-mcp' ),
            'description'   => __( 'List media library images that have no alt text. Returns attachment id, title and URL; set alt text with jkc/set-image-alt.', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array( 'type' => 'object', 'properties' => array( 'limit' => array( 'type' => 'integer', 'description' => 'Max resultaten (default 50).' ) ) ),
            'output_schema' => array( 'type' => 'object' ),
            'execute_callback'    => function ( array $input ) {
                $limit = isset( $input['limit'] ) ? max( 1, min( (int) $input['limit'], 200 ) ) : 50;
                $atts  = get_posts(
                    array(
                        'post_type'        => 'attachment',
                        'post_mime_type'   => 'image',
                        'post_status'      => 'inherit',
                        'numberposts'      => $limit * 4,
                        'suppress_filters' => false,
                    )
                );
                $out = array();
                foreach ( $atts as $a ) {
                    $alt = get_post_meta( $a->ID, '_wp_attachment_image_alt', true );
                    if ( '' === $alt || false === $alt ) {
                        $out[] = array( 'id' => (int) $a->ID, 'title' => get_the_title( $a ), 'url' => (string) wp_get_attachment_url( $a->ID ) );
                        if ( count( $out ) >= $limit ) {
                            break;
                        }
                    }
                }
                return array( 'count' => count( $out ), 'images' => $out );
            },
            'permission_callback' => function () {
                return current_user_can( 'upload_files' ) || current_user_can( 'edit_pages' );
            },
            'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'mcp' => array( 'public' => true ) ),
        )
    );

    wp_register_ability(
        'jkc/set-image-alt',
        array(
            'label'         => __( 'Set Image Alt', 'jkc-mcp' ),
            'description'   => __( 'Set the alt text of a media image by attachment id.', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array( 'type' => 'object', 'properties' => array( 'attachment_id' => array( 'type' => 'integer' ), 'alt' => array( 'type' => 'string' ) ), 'required' => array( 'attachment_id', 'alt' ) ),
            'output_schema' => array( 'type' => 'object' ),
            'execute_callback'    => function ( array $input ) {
                $id  = (int) ( $input['attachment_id'] ?? 0 );
                $att = get_post( $id );
                if ( ! $att || 'attachment' !== $att->post_type ) {
                    return array( 'error' => true, 'message' => 'Attachment niet gevonden.' );
                }
                if ( ! current_user_can( 'edit_post', $id ) ) {
                    return array( 'error' => true, 'message' => 'Geen rechten.' );
                }
                update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt'] ) );
                return array( 'id' => $id, 'alt' => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ), 'status' => 'bijgewerkt' );
            },
            'permission_callback' => function () {
                return current_user_can( 'upload_files' ) || current_user_can( 'edit_pages' );
            },
            'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true ), 'mcp' => array( 'public' => true ) ),
        )
    );

    /* ---- FIX-DE-AUDIT: gebroken links -------------------------------- */
    wp_register_ability(
        'jkc/find-broken-links',
        array(
            'label'         => __( 'Find Broken Links', 'jkc-mcp' ),
            'description'   => __( 'Scan published allowed content types for hyperlinks and report links returning an error (4xx/5xx) or that fail. Checks up to a capped number of unique links.', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array( 'type' => 'object', 'properties' => array( 'max_links' => array( 'type' => 'integer', 'description' => 'Max unieke links om te checken (default 80).' ) ) ),
            'output_schema' => array( 'type' => 'object' ),
            'execute_callback'    => function ( array $input ) {
                $max   = isset( $input['max_links'] ) ? max( 1, min( (int) $input['max_links'], 200 ) ) : 80;
                $posts = get_posts(
                    array(
                        'post_type'        => jkc_mcp_allowed_types(),
                        'post_status'      => 'publish',
                        'numberposts'      => 500,
                        'suppress_filters' => false,
                    )
                );
                $links = array();
                foreach ( $posts as $p ) {
                    $html = apply_filters( 'the_content', $p->post_content );
                    if ( preg_match_all( '/<a\b[^>]*href\s*=\s*("[^"]*"|\'[^\']*\')/i', $html, $m ) ) {
                        foreach ( $m[1] as $href ) {
                            $href = trim( $href, '"\'' );
                            if ( '' === $href || '#' === $href[0]
                                || 0 === stripos( $href, 'mailto:' ) || 0 === stripos( $href, 'tel:' )
                                || 0 === stripos( $href, 'javascript:' ) ) {
                                continue;
                            }
                            if ( 0 === strpos( $href, '//' ) ) {
                                $href = 'https:' . $href;
                            }
                            if ( ! preg_match( '#^https?://#i', $href ) ) {
                                continue;
                            }
                            if ( ! isset( $links[ $href ] ) ) {
                                $links[ $href ] = array();
                            }
                            if ( count( $links[ $href ] ) < 5 ) {
                                $links[ $href ][] = get_the_title( $p );
                            }
                        }
                    }
                    if ( count( $links ) >= $max ) {
                        break;
                    }
                }
                $checked = 0;
                $broken  = array();
                foreach ( $links as $url => $sources ) {
                    if ( $checked >= $max ) {
                        break;
                    }
                    $checked++;
                    $resp = wp_remote_head( $url, array( 'timeout' => 8, 'redirection' => 5, 'user-agent' => 'JKC-MCP-LinkCheck' ) );
                    $code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
                    if ( 0 === $code || 405 === $code ) {
                        $resp = wp_remote_get( $url, array( 'timeout' => 10, 'redirection' => 5, 'user-agent' => 'JKC-MCP-LinkCheck' ) );
                        $code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
                    }
                    if ( 0 === $code || $code >= 400 ) {
                        $broken[] = array( 'url' => $url, 'http_code' => $code, 'on_pages' => $sources );
                    }
                }
                return array( 'checked' => $checked, 'broken_count' => count( $broken ), 'broken' => $broken );
            },
            'permission_callback' => function () {
                return current_user_can( 'edit_pages' ) || current_user_can( 'edit_posts' );
            },
            'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'mcp' => array( 'public' => true ) ),
        )
    );

    /* ---- FIX-DE-AUDIT: redirects ------------------------------------- */
    wp_register_ability(
        'jkc/create-redirect',
        array(
            'label'         => __( 'Create Redirect', 'jkc-mcp' ),
            'description'   => __( 'Create a redirect from a local path (e.g. /oude-pagina) to a target URL, useful after changing a slug. type 301 (permanent, default) or 302.', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array( 'type' => 'object', 'properties' => array( 'from_path' => array( 'type' => 'string' ), 'to_url' => array( 'type' => 'string' ), 'type' => array( 'type' => 'integer', 'enum' => array( 301, 302 ) ) ), 'required' => array( 'from_path', 'to_url' ) ),
            'output_schema' => array( 'type' => 'object' ),
            'execute_callback'    => function ( array $input ) {
                $from = '/' . ltrim( trim( (string) ( $input['from_path'] ?? '' ) ), '/' );
                $from = untrailingslashit( $from );
                $to   = esc_url_raw( (string) ( $input['to_url'] ?? '' ) );
                $type = ( isset( $input['type'] ) && 302 === (int) $input['type'] ) ? 302 : 301;
                if ( '' === $from || '/' === $from || '' === $to ) {
                    return array( 'error' => true, 'message' => 'from_path en to_url zijn verplicht.' );
                }
                $list = get_option( 'jkc_mcp_redirects', array() );
                if ( ! is_array( $list ) ) {
                    $list = array();
                }
                $list[ $from ] = array( 'to' => $to, 'type' => $type );
                update_option( 'jkc_mcp_redirects', $list, false );
                return array( 'from' => $from, 'to' => $to, 'type' => $type, 'status' => 'aangemaakt', 'total_redirects' => count( $list ) );
            },
            'permission_callback' => function () {
                return current_user_can( 'manage_options' ) || current_user_can( 'edit_pages' );
            },
            'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true ), 'mcp' => array( 'public' => true ) ),
        )
    );

    wp_register_ability(
        'jkc/list-redirects',
        array(
            'label'         => __( 'List Redirects', 'jkc-mcp' ),
            'description'   => __( 'List all configured redirects.', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array( 'type' => 'object' ),
            'output_schema' => array( 'type' => 'object' ),
            'execute_callback'    => function () {
                $list = get_option( 'jkc_mcp_redirects', array() );
                $out  = array();
                if ( is_array( $list ) ) {
                    foreach ( $list as $from => $r ) {
                        $out[] = array( 'from' => $from, 'to' => $r['to'], 'type' => (int) ( $r['type'] ?? 301 ) );
                    }
                }
                return array( 'count' => count( $out ), 'redirects' => $out );
            },
            'permission_callback' => function () {
                return current_user_can( 'manage_options' ) || current_user_can( 'edit_pages' );
            },
            'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'mcp' => array( 'public' => true ) ),
        )
    );

    wp_register_ability(
        'jkc/delete-redirect',
        array(
            'label'         => __( 'Delete Redirect', 'jkc-mcp' ),
            'description'   => __( 'Remove a redirect by its from_path.', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array( 'type' => 'object', 'properties' => array( 'from_path' => array( 'type' => 'string' ) ), 'required' => array( 'from_path' ) ),
            'output_schema' => array( 'type' => 'object' ),
            'execute_callback'    => function ( array $input ) {
                $from = untrailingslashit( '/' . ltrim( trim( (string) ( $input['from_path'] ?? '' ) ), '/' ) );
                $list = get_option( 'jkc_mcp_redirects', array() );
                if ( is_array( $list ) && isset( $list[ $from ] ) ) {
                    unset( $list[ $from ] );
                    update_option( 'jkc_mcp_redirects', $list, false );
                    return array( 'from' => $from, 'status' => 'verwijderd', 'total_redirects' => count( $list ) );
                }
                return array( 'error' => true, 'message' => 'Redirect niet gevonden.' );
            },
            'permission_callback' => function () {
                return current_user_can( 'manage_options' ) || current_user_can( 'edit_pages' );
            },
            'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true ), 'mcp' => array( 'public' => true ) ),
        )
    );

    /* ---- SEO: canonical tag (read) ----------------------------------- */
    wp_register_ability(
        'jkc/get-canonical',
        array(
            'label'         => __( 'Get Canonical URL', 'jkc-mcp' ),
            'description'   => __( 'Reads the Yoast canonical URL (rel=canonical) of an allowed content item. Returns the explicitly set canonical (empty if none) plus the effective URL Google would otherwise use (the permalink).', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array(
                'type'       => 'object',
                'properties' => array(
                    'slug' => array( 'type' => 'string', 'description' => 'The slug (or use id).' ),
                    'id'   => array( 'type' => 'integer', 'description' => 'The ID (or use slug).' ),
                    'type' => $type_prop,
                ),
            ),
            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'            => array( 'type' => 'integer' ),
                    'canonical'     => array( 'type' => 'string' ),
                    'effective_url' => array( 'type' => 'string' ),
                ),
            ),
            'execute_callback'    => function ( array $input ) {
                $post = jkc_mcp_resolve_post( $input );
                if ( is_wp_error( $post ) ) {
                    return $post;
                }
                return array(
                    'id'            => (int) $post->ID,
                    'canonical'     => (string) get_post_meta( $post->ID, '_yoast_wpseo_canonical', true ),
                    'effective_url' => (string) get_permalink( $post->ID ),
                );
            },
            'permission_callback' => function () {
                return current_user_can( 'edit_pages' ) || current_user_can( 'edit_posts' );
            },
            'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'mcp' => array( 'public' => true ) ),
        )
    );

    /* ---- SEO: canonical tag (write) ---------------------------------- */
    wp_register_ability(
        'jkc/set-canonical',
        array(
            'label'         => __( 'Set Canonical URL', 'jkc-mcp' ),
            'description'   => __( 'Sets or overwrites the Yoast canonical URL of an allowed content item. Pass an empty canonical to clear it (Yoast then falls back to the permalink). Use for duplicate content, keyword cannibalisation, or pages reachable via multiple URLs.', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array(
                'type'       => 'object',
                'properties' => array(
                    'slug'      => array( 'type' => 'string', 'description' => 'The slug (or use id).' ),
                    'id'        => array( 'type' => 'integer', 'description' => 'The ID (or use slug).' ),
                    'type'      => $type_prop,
                    'canonical' => array( 'type' => 'string', 'description' => 'Absolute canonical URL to set. Empty string clears it.' ),
                ),
                'required'   => array( 'canonical' ),
            ),
            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'        => array( 'type' => 'integer' ),
                    'canonical' => array( 'type' => 'string' ),
                ),
            ),
            'execute_callback'    => function ( array $input ) {
                $post = jkc_mcp_resolve_post( $input );
                if ( is_wp_error( $post ) ) {
                    return $post;
                }
                if ( ! current_user_can( 'edit_post', $post->ID ) ) {
                    return new WP_Error( 'forbidden', __( 'You cannot edit this content.', 'jkc-mcp' ), array( 'status' => 403 ) );
                }
                $canonical = isset( $input['canonical'] ) ? trim( (string) $input['canonical'] ) : '';
                if ( '' === $canonical ) {
                    delete_post_meta( $post->ID, '_yoast_wpseo_canonical' );
                } else {
                    update_post_meta( $post->ID, '_yoast_wpseo_canonical', esc_url_raw( $canonical ) );
                }
                return array(
                    'id'        => (int) $post->ID,
                    'canonical' => (string) get_post_meta( $post->ID, '_yoast_wpseo_canonical', true ),
                );
            },
            'permission_callback' => function () {
                return current_user_can( 'edit_pages' ) || current_user_can( 'edit_posts' );
            },
            'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true ), 'mcp' => array( 'public' => true ) ),
        )
    );

    /* ---- SEO: structured data / JSON-LD schema (read) ---------------- */
    wp_register_ability(
        'jkc/get-schema',
        array(
            'label'         => __( 'Get Schema (structured data)', 'jkc-mcp' ),
            'description'   => __( 'Reads the custom JSON-LD structured data that this plugin manages for an allowed content item and outputs in wp_head. Note: this is the JKC-managed schema block, separate from the schema Yoast generates automatically. Returns the stored JSON-LD object, or empty if none is set.', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array(
                'type'       => 'object',
                'properties' => array(
                    'slug' => array( 'type' => 'string', 'description' => 'The slug (or use id).' ),
                    'id'   => array( 'type' => 'integer', 'description' => 'The ID (or use slug).' ),
                    'type' => $type_prop,
                ),
            ),
            'output_schema' => array( 'type' => 'object' ),
            'execute_callback'    => function ( array $input ) {
                $post = jkc_mcp_resolve_post( $input );
                if ( is_wp_error( $post ) ) {
                    return $post;
                }
                $raw    = (string) get_post_meta( $post->ID, '_jkc_mcp_schema', true );
                $schema = ( '' !== $raw ) ? json_decode( $raw, true ) : null;
                return array(
                    'id'         => (int) $post->ID,
                    'has_schema' => ! empty( $schema ),
                    'schema'     => $schema,
                );
            },
            'permission_callback' => function () {
                return current_user_can( 'edit_pages' ) || current_user_can( 'edit_posts' );
            },
            'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'mcp' => array( 'public' => true ) ),
        )
    );

    /* ---- SEO: structured data / JSON-LD schema (write) --------------- */
    wp_register_ability(
        'jkc/set-schema',
        array(
            'label'         => __( 'Set Schema (structured data)', 'jkc-mcp' ),
            'description'   => __( 'Sets or replaces the JSON-LD structured data of an allowed content item. The plugin outputs it in wp_head as <script type="application/ld+json">. Pass "schema" as a JSON-LD object with @context and @type (e.g. FAQPage, Article, Product, BreadcrumbList). Pass an empty object to clear it. Generate valid schema.org JSON-LD and confirm with the user before applying.', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array(
                'type'       => 'object',
                'properties' => array(
                    'slug'   => array( 'type' => 'string', 'description' => 'The slug (or use id).' ),
                    'id'     => array( 'type' => 'integer', 'description' => 'The ID (or use slug).' ),
                    'type'   => $type_prop,
                    'schema' => array( 'type' => 'object', 'description' => 'JSON-LD object with @context and @type. Empty object clears the schema.' ),
                ),
                'required'   => array( 'schema' ),
            ),
            'output_schema' => array( 'type' => 'object' ),
            'execute_callback'    => function ( array $input ) {
                $post = jkc_mcp_resolve_post( $input );
                if ( is_wp_error( $post ) ) {
                    return $post;
                }
                if ( ! current_user_can( 'edit_post', $post->ID ) ) {
                    return new WP_Error( 'forbidden', __( 'You cannot edit this content.', 'jkc-mcp' ), array( 'status' => 403 ) );
                }
                $schema = isset( $input['schema'] ) ? $input['schema'] : null;
                if ( empty( $schema ) || ! is_array( $schema ) ) {
                    delete_post_meta( $post->ID, '_jkc_mcp_schema' );
                    return array( 'id' => (int) $post->ID, 'status' => 'cleared', 'schema' => null );
                }
                if ( ! isset( $schema['@context'] ) || ! isset( $schema['@type'] ) ) {
                    return new WP_Error( 'invalid_schema', __( 'Schema must include @context and @type.', 'jkc-mcp' ), array( 'status' => 400 ) );
                }
                $json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                if ( false === $json ) {
                    return new WP_Error( 'invalid_schema', __( 'Could not encode the schema as JSON.', 'jkc-mcp' ), array( 'status' => 400 ) );
                }
                update_post_meta( $post->ID, '_jkc_mcp_schema', wp_slash( $json ) );
                return array(
                    'id'     => (int) $post->ID,
                    'status' => 'saved',
                    'type'   => (string) $schema['@type'],
                    'schema' => $schema,
                );
            },
            'permission_callback' => function () {
                return current_user_can( 'edit_pages' ) || current_user_can( 'edit_posts' );
            },
            'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true ), 'mcp' => array( 'public' => true ) ),
        )
    );

    /* ---- SEO: interne links van een pagina opvragen ------------------ */
    wp_register_ability(
        'jkc/get-internal-links',
        array(
            'label'         => __( 'Get Internal Links', 'jkc-mcp' ),
            'description'   => __( 'Returns the links found in a page or post (rendered, so shortcode/builder content is included). Per link: anchor text, target URL, whether it is relative or absolute, and the source content id. By default only internal links; set include_external=true to also return outbound links.', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array(
                'type'       => 'object',
                'properties' => array(
                    'slug'             => array( 'type' => 'string', 'description' => 'The slug (or use id).' ),
                    'id'               => array( 'type' => 'integer', 'description' => 'The ID (or use slug).' ),
                    'type'             => $type_prop,
                    'include_external' => array( 'type' => 'boolean', 'description' => 'Ook externe links meenemen (default false).' ),
                ),
            ),
            'output_schema' => array( 'type' => 'object' ),
            'execute_callback'    => function ( array $input ) {
                $post = jkc_mcp_resolve_post( $input );
                if ( is_wp_error( $post ) ) {
                    return $post;
                }
                $include_external = isset( $input['include_external'] ) && filter_var( $input['include_external'], FILTER_VALIDATE_BOOLEAN );
                $out = array();
                foreach ( jkc_mcp_extract_links( jkc_mcp_render_content( $post ) ) as $l ) {
                    if ( ! $include_external && ! $l['internal'] ) {
                        continue;
                    }
                    $out[] = array(
                        'anchor'    => $l['anchor'],
                        'url'       => $l['url'],
                        'href'      => $l['href'],
                        'relative'  => $l['relative'],
                        'internal'  => $l['internal'],
                        'source_id' => (int) $post->ID,
                    );
                }
                return array( 'id' => (int) $post->ID, 'type' => $post->post_type, 'count' => count( $out ), 'links' => $out );
            },
            'permission_callback' => function () {
                return current_user_can( 'edit_pages' ) || current_user_can( 'edit_posts' );
            },
            'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'mcp' => array( 'public' => true ) ),
        )
    );

    /* ---- SEO: links van een pagina controleren (bereikbaarheid) ------ */
    wp_register_ability(
        'jkc/check-broken-links',
        array(
            'label'         => __( 'Check Broken Links', 'jkc-mcp' ),
            'description'   => __( 'Checks whether links are reachable. Provide a page (slug/id/type) to check its links, or an explicit "urls" array. Returns per link the HTTP code and a status (ok/redirect/not_found/timeout/error); for redirects it returns the final target URL. Internal links only by default.', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array(
                'type'       => 'object',
                'properties' => array(
                    'slug'             => array( 'type' => 'string', 'description' => 'Page slug to check (or use id, or pass urls).' ),
                    'id'               => array( 'type' => 'integer', 'description' => 'Page ID (or use slug, or pass urls).' ),
                    'type'             => $type_prop,
                    'urls'             => array( 'type' => 'array', 'description' => 'Optioneel: expliciete lijst URLs om te checken in plaats van een pagina.' ),
                    'include_external' => array( 'type' => 'boolean', 'description' => 'Ook externe links checken (default false).' ),
                    'max_links'        => array( 'type' => 'integer', 'description' => 'Max te checken links (default 50).' ),
                ),
            ),
            'output_schema' => array( 'type' => 'object' ),
            'execute_callback'    => function ( array $input ) {
                $max              = isset( $input['max_links'] ) ? max( 1, min( (int) $input['max_links'], 200 ) ) : 50;
                $include_external = isset( $input['include_external'] ) && filter_var( $input['include_external'], FILTER_VALIDATE_BOOLEAN );
                $to_check         = array(); // url => array( anchor, source_id ).

                if ( ! empty( $input['urls'] ) && is_array( $input['urls'] ) ) {
                    foreach ( $input['urls'] as $u ) {
                        $u = esc_url_raw( trim( (string) $u ) );
                        if ( '' !== $u && ! isset( $to_check[ $u ] ) ) {
                            $to_check[ $u ] = array( 'anchor' => '', 'source_id' => 0 );
                        }
                    }
                } else {
                    $post = jkc_mcp_resolve_post( $input );
                    if ( is_wp_error( $post ) ) {
                        return $post;
                    }
                    foreach ( jkc_mcp_extract_links( jkc_mcp_render_content( $post ) ) as $l ) {
                        if ( ! $include_external && ! $l['internal'] ) {
                            continue;
                        }
                        if ( ! isset( $to_check[ $l['url'] ] ) ) {
                            $to_check[ $l['url'] ] = array( 'anchor' => $l['anchor'], 'source_id' => (int) $post->ID );
                        }
                    }
                }

                $results = array();
                $broken  = 0;
                $checked = 0;
                foreach ( $to_check as $url => $meta ) {
                    if ( $checked >= $max ) {
                        break;
                    }
                    $checked++;
                    $res       = jkc_mcp_check_url( $url );
                    $is_broken = in_array( $res['status'], array( 'not_found', 'error', 'timeout' ), true );
                    if ( $is_broken ) {
                        $broken++;
                    }
                    $results[] = array(
                        'url'       => $url,
                        'anchor'    => $meta['anchor'],
                        'source_id' => $meta['source_id'],
                        'http_code' => $res['http_code'],
                        'status'    => $res['status'],
                        'final_url' => $res['final_url'],
                        'broken'    => $is_broken,
                    );
                }
                return array( 'checked' => $checked, 'broken_count' => $broken, 'results' => $results );
            },
            'permission_callback' => function () {
                return current_user_can( 'edit_pages' ) || current_user_can( 'edit_posts' );
            },
            'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'mcp' => array( 'public' => true ) ),
        )
    );

    /* ---- WRITE: find/replace binnen content (incl. shortcodes) ------- */
    wp_register_ability(
        'jkc/replace-in-content',
        array(
            'label'         => __( 'Replace In Content', 'jkc-mcp' ),
            'description'   => __( 'Find-and-replace within the RAW content of a page/post, including text inside shortcode attributes and shortcode bodies (e.g. Visual Composer / Nectar headings). Operates on the stored markup so the builder structure stays intact. Provide "find" and "replace"; set regex=true for a regular-expression search. Preview with get-content first.', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array(
                'type'       => 'object',
                'properties' => array(
                    'slug'    => array( 'type' => 'string', 'description' => 'The slug (or use id).' ),
                    'id'      => array( 'type' => 'integer', 'description' => 'The ID (or use slug).' ),
                    'type'    => $type_prop,
                    'find'    => array( 'type' => 'string', 'description' => 'Tekst (of regex-patroon) om te zoeken. Mag tekst binnen shortcodes zijn.' ),
                    'replace' => array( 'type' => 'string', 'description' => 'Vervangende tekst.' ),
                    'regex'   => array( 'type' => 'boolean', 'description' => 'true = behandel find als regex (zonder delimiters, ~-delimiter, u-flag). Default false (letterlijke tekst).' ),
                ),
                'required'   => array( 'find', 'replace' ),
            ),
            'output_schema' => array( 'type' => 'object' ),
            'execute_callback'    => function ( array $input ) {
                $post = jkc_mcp_resolve_post( $input );
                if ( is_wp_error( $post ) ) {
                    return $post;
                }
                if ( ! current_user_can( 'edit_post', $post->ID ) ) {
                    return new WP_Error( 'forbidden', __( 'You cannot edit this content.', 'jkc-mcp' ), array( 'status' => 403 ) );
                }
                $find = isset( $input['find'] ) ? (string) $input['find'] : '';
                if ( '' === $find ) {
                    return new WP_Error( 'invalid_input', __( '"find" mag niet leeg zijn.', 'jkc-mcp' ), array( 'status' => 400 ) );
                }
                $replace   = isset( $input['replace'] ) ? (string) $input['replace'] : '';
                $original  = (string) $post->post_content;
                $count     = 0;
                $use_regex = isset( $input['regex'] ) && filter_var( $input['regex'], FILTER_VALIDATE_BOOLEAN );

                if ( $use_regex ) {
                    $pattern = '~' . str_replace( '~', '\~', $find ) . '~u';
                    $new     = preg_replace( $pattern, $replace, $original, -1, $count );
                    if ( null === $new ) {
                        return new WP_Error( 'invalid_regex', __( 'Ongeldig regex-patroon.', 'jkc-mcp' ), array( 'status' => 400 ) );
                    }
                } else {
                    $new = str_replace( $find, $replace, $original, $count );
                }

                if ( 0 === $count ) {
                    return array( 'id' => (int) $post->ID, 'replacements' => 0, 'status' => 'geen overeenkomsten gevonden' );
                }

                $result = wp_update_post( array( 'ID' => $post->ID, 'post_content' => $new ), true );
                if ( is_wp_error( $result ) ) {
                    return $result;
                }
                return array(
                    'id'           => (int) $post->ID,
                    'replacements' => (int) $count,
                    'status'       => 'bijgewerkt',
                    'link'         => (string) get_permalink( $post->ID ),
                );
            },
            'permission_callback' => function () {
                return current_user_can( 'edit_pages' ) || current_user_can( 'edit_posts' );
            },
            'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true ), 'mcp' => array( 'public' => true ) ),
        )
    );

    /* ---- MEDIA: afbeelding uploaden ---------------------------------- */
    wp_register_ability(
        'jkc/upload-media',
        array(
            'label'         => __( 'Upload Media', 'jkc-mcp' ),
            'description'   => __( 'Uploads an image to the WordPress media library, either from a remote URL ("url") or from base64 data ("base64" + "filename"). Optionally sets title and alt text. Returns media id, URL, mime type and title. Allowed formats follow the WordPress upload settings (typically jpg, png, webp, gif).', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array(
                'type'       => 'object',
                'properties' => array(
                    'url'      => array( 'type' => 'string', 'description' => 'Bron-URL van de afbeelding om te downloaden.' ),
                    'base64'   => array( 'type' => 'string', 'description' => 'Base64-gecodeerde afbeeldingsdata (alternatief voor url). Mag een data: URI zijn.' ),
                    'filename' => array( 'type' => 'string', 'description' => 'Bestandsnaam incl. extensie (verplicht bij base64, optioneel bij url).' ),
                    'title'    => array( 'type' => 'string', 'description' => 'Titel van de media (optioneel).' ),
                    'alt'      => array( 'type' => 'string', 'description' => 'Alt-tekst (optioneel).' ),
                ),
            ),
            'output_schema' => array( 'type' => 'object' ),
            'execute_callback'    => function ( array $input ) {
                if ( ! current_user_can( 'upload_files' ) ) {
                    return new WP_Error( 'forbidden', __( 'Geen rechten om media te uploaden.', 'jkc-mcp' ), array( 'status' => 403 ) );
                }
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';

                $attachment_id = 0;
                $title         = isset( $input['title'] ) ? sanitize_text_field( $input['title'] ) : null;

                if ( ! empty( $input['url'] ) ) {
                    $url = esc_url_raw( trim( (string) $input['url'] ) );
                    if ( '' === $url ) {
                        return new WP_Error( 'invalid_input', __( 'Ongeldige url.', 'jkc-mcp' ), array( 'status' => 400 ) );
                    }
                    $tmp = download_url( $url, 30 );
                    if ( is_wp_error( $tmp ) ) {
                        return $tmp;
                    }
                    $name = ! empty( $input['filename'] ) ? sanitize_file_name( $input['filename'] ) : sanitize_file_name( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
                    if ( '' === $name ) {
                        $name = 'upload.jpg';
                    }
                    $file_array    = array( 'name' => $name, 'tmp_name' => $tmp );
                    $attachment_id = media_handle_sideload( $file_array, 0, $title );
                    if ( is_wp_error( $attachment_id ) ) {
                        if ( file_exists( $tmp ) ) {
                            wp_delete_file( $tmp );
                        }
                        return $attachment_id;
                    }
                } elseif ( ! empty( $input['base64'] ) ) {
                    if ( empty( $input['filename'] ) ) {
                        return new WP_Error( 'invalid_input', __( 'filename is verplicht bij base64.', 'jkc-mcp' ), array( 'status' => 400 ) );
                    }
                    $data = base64_decode( preg_replace( '#^data:[^;]+;base64,#', '', (string) $input['base64'] ), true );
                    if ( false === $data ) {
                        return new WP_Error( 'invalid_input', __( 'Ongeldige base64-data.', 'jkc-mcp' ), array( 'status' => 400 ) );
                    }
                    $name   = sanitize_file_name( $input['filename'] );
                    $upload = wp_upload_bits( $name, null, $data );
                    if ( ! empty( $upload['error'] ) ) {
                        return new WP_Error( 'upload_failed', $upload['error'], array( 'status' => 400 ) );
                    }
                    $filetype = wp_check_filetype( $upload['file'] );
                    if ( empty( $filetype['type'] ) ) {
                        wp_delete_file( $upload['file'] );
                        return new WP_Error( 'invalid_type', __( 'Niet-ondersteund bestandstype.', 'jkc-mcp' ), array( 'status' => 400 ) );
                    }
                    $attachment_id = wp_insert_attachment(
                        array(
                            'post_mime_type' => $filetype['type'],
                            'post_title'     => $title ? $title : preg_replace( '/\.[^.]+$/', '', $name ),
                            'post_content'   => '',
                            'post_status'    => 'inherit',
                        ),
                        $upload['file']
                    );
                    if ( is_wp_error( $attachment_id ) ) {
                        return $attachment_id;
                    }
                    wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
                } else {
                    return new WP_Error( 'invalid_input', __( 'Geef een url of base64 + filename op.', 'jkc-mcp' ), array( 'status' => 400 ) );
                }

                if ( isset( $input['alt'] ) ) {
                    update_post_meta( $attachment_id, '_wp_attachment_image_alt', sanitize_text_field( $input['alt'] ) );
                }

                return array(
                    'id'        => (int) $attachment_id,
                    'url'       => (string) wp_get_attachment_url( $attachment_id ),
                    'mime_type' => (string) get_post_mime_type( $attachment_id ),
                    'title'     => get_the_title( $attachment_id ),
                    'alt'       => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
                );
            },
            'permission_callback' => function () {
                return current_user_can( 'upload_files' );
            },
            'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => false ), 'mcp' => array( 'public' => true ) ),
        )
    );

    /* ---- MEDIA: uitgelichte afbeelding instellen --------------------- */
    wp_register_ability(
        'jkc/set-featured-image',
        array(
            'label'         => __( 'Set Featured Image', 'jkc-mcp' ),
            'description'   => __( 'Sets the featured image of a page, post (or WooCommerce product) by media attachment id. Pass attachment_id 0 to remove the featured image.', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array(
                'type'       => 'object',
                'properties' => array(
                    'slug'          => array( 'type' => 'string', 'description' => 'The slug (or use id).' ),
                    'id'            => array( 'type' => 'integer', 'description' => 'The content ID (or use slug). For products use the product id.' ),
                    'type'          => $type_prop,
                    'attachment_id' => array( 'type' => 'integer', 'description' => 'Media-ID van de afbeelding (0 = uitgelichte afbeelding verwijderen).' ),
                ),
                'required'   => array( 'attachment_id' ),
            ),
            'output_schema' => array( 'type' => 'object' ),
            'execute_callback'    => function ( array $input ) {
                // Resolve doel: sta naast de standaard types ook 'product' toe (op id).
                $post  = null;
                $types = jkc_mcp_allowed_types();
                if ( function_exists( 'wc_get_product' ) ) {
                    $types[] = 'product';
                }
                if ( ! empty( $input['id'] ) ) {
                    $p = get_post( (int) $input['id'] );
                    if ( $p && in_array( $p->post_type, $types, true ) ) {
                        $post = $p;
                    }
                }
                if ( ! $post ) {
                    $post = jkc_mcp_resolve_post( $input );
                    if ( is_wp_error( $post ) ) {
                        return $post;
                    }
                }
                if ( ! current_user_can( 'edit_post', $post->ID ) ) {
                    return new WP_Error( 'forbidden', __( 'You cannot edit this content.', 'jkc-mcp' ), array( 'status' => 403 ) );
                }

                $attachment_id = (int) ( $input['attachment_id'] ?? 0 );
                if ( 0 === $attachment_id ) {
                    delete_post_thumbnail( $post->ID );
                    return array( 'id' => (int) $post->ID, 'featured_image_id' => 0, 'status' => 'verwijderd' );
                }

                $att = get_post( $attachment_id );
                if ( ! $att || 'attachment' !== $att->post_type ) {
                    return new WP_Error( 'invalid_media', __( 'Ongeldige media-ID.', 'jkc-mcp' ), array( 'status' => 400 ) );
                }
                $ok = set_post_thumbnail( $post->ID, $attachment_id );
                if ( ! $ok ) {
                    return new WP_Error( 'failed', __( 'Kon de uitgelichte afbeelding niet instellen.', 'jkc-mcp' ), array( 'status' => 500 ) );
                }
                return array(
                    'id'                => (int) $post->ID,
                    'featured_image_id' => $attachment_id,
                    'featured_image'    => (string) wp_get_attachment_image_url( $attachment_id, 'full' ),
                    'status'            => 'ingesteld',
                );
            },
            'permission_callback' => function () {
                return current_user_can( 'edit_pages' ) || current_user_can( 'edit_posts' ) || current_user_can( 'edit_products' );
            },
            'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true ), 'mcp' => array( 'public' => true ) ),
        )
    );

    /* ---- MEDIA: alt-tekst bijwerken (alias van set-image-alt) -------- */
    wp_register_ability(
        'jkc/update-image-alt',
        array(
            'label'         => __( 'Update Image Alt', 'jkc-mcp' ),
            'description'   => __( 'Update the alt text of a media library image by attachment id, without touching other media metadata. Functionally identical to set-image-alt.', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array( 'type' => 'object', 'properties' => array( 'attachment_id' => array( 'type' => 'integer' ), 'alt' => array( 'type' => 'string' ) ), 'required' => array( 'attachment_id', 'alt' ) ),
            'output_schema' => array( 'type' => 'object' ),
            'execute_callback'    => function ( array $input ) {
                return jkc_mcp_set_attachment_alt( (int) ( $input['attachment_id'] ?? 0 ), (string) ( $input['alt'] ?? '' ) );
            },
            'permission_callback' => function () {
                return current_user_can( 'upload_files' ) || current_user_can( 'edit_pages' );
            },
            'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true ), 'mcp' => array( 'public' => true ) ),
        )
    );

    /* ---- WOOCOMMERCE (alleen als WooCommerce actief is) -------------- */
    if ( function_exists( 'wc_get_product' ) ) {

        wp_register_ability(
            'jkc/wc-find-products',
            array(
                'label'         => __( 'WooCommerce: Find Products', 'jkc-mcp' ),
                'description'   => __( 'Search WooCommerce products by name or SKU, or list them when no query is given. Returns id, name, sku, price and status.', 'jkc-mcp' ),
                'category'      => 'jkc-content',
                'input_schema'  => array( 'type' => 'object', 'properties' => array( 'query' => array( 'type' => 'string' ), 'limit' => array( 'type' => 'integer' ) ) ),
                'output_schema' => array( 'type' => 'object' ),
                'execute_callback'    => function ( array $input ) {
                    $limit = isset( $input['limit'] ) ? max( 1, min( (int) $input['limit'], 200 ) ) : 50;
                    $query = isset( $input['query'] ) ? trim( (string) $input['query'] ) : '';
                    $args  = array( 'post_type' => 'product', 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => $limit, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC' );
                    if ( '' !== $query ) {
                        $args['s'] = $query;
                    }
                    $q   = new WP_Query( $args );
                    $ids = $q->posts;
                    if ( empty( $ids ) && '' !== $query && function_exists( 'wc_get_product_id_by_sku' ) ) {
                        $sid = wc_get_product_id_by_sku( $query );
                        if ( $sid ) {
                            $ids = array( $sid );
                        }
                    }
                    $out = array();
                    foreach ( $ids as $pid ) {
                        $p = wc_get_product( $pid );
                        if ( $p ) {
                            $out[] = array( 'id' => (int) $pid, 'name' => $p->get_name(), 'sku' => $p->get_sku(), 'price' => $p->get_price(), 'status' => $p->get_status() );
                        }
                    }
                    return array( 'count' => count( $out ), 'products' => $out );
                },
                'permission_callback' => function () {
                    return current_user_can( 'edit_products' ) || current_user_can( 'manage_woocommerce' );
                },
                'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'mcp' => array( 'public' => true ) ),
            )
        );

        wp_register_ability(
            'jkc/wc-get-product',
            array(
                'label'         => __( 'WooCommerce: Get Product', 'jkc-mcp' ),
                'description'   => __( 'Get full details of a WooCommerce product by id: name, sku, prices, stock, descriptions, categories, status and link.', 'jkc-mcp' ),
                'category'      => 'jkc-content',
                'input_schema'  => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'integer' ) ), 'required' => array( 'id' ) ),
                'output_schema' => array( 'type' => 'object' ),
                'execute_callback'    => function ( array $input ) {
                    $p = wc_get_product( (int) ( $input['id'] ?? 0 ) );
                    if ( ! $p ) {
                        return array( 'error' => true, 'message' => 'Product niet gevonden.' );
                    }
                    $cats = wp_get_post_terms( $p->get_id(), 'product_cat', array( 'fields' => 'names' ) );
                    return array(
                        'id'                => (int) $p->get_id(),
                        'name'              => $p->get_name(),
                        'sku'               => $p->get_sku(),
                        'status'            => $p->get_status(),
                        'regular_price'     => $p->get_regular_price(),
                        'sale_price'        => $p->get_sale_price(),
                        'price'             => $p->get_price(),
                        'stock_status'      => $p->get_stock_status(),
                        'stock_quantity'    => $p->get_stock_quantity(),
                        'manage_stock'      => (bool) $p->get_manage_stock(),
                        'short_description' => $p->get_short_description(),
                        'description'       => $p->get_description(),
                        'categories'        => is_array( $cats ) ? $cats : array(),
                        'meta_description'  => (string) get_post_meta( $p->get_id(), '_yoast_wpseo_metadesc', true ),
                        'focus_keyphrase'   => (string) get_post_meta( $p->get_id(), '_yoast_wpseo_focuskw', true ),
                        'seo_title'         => (string) get_post_meta( $p->get_id(), '_yoast_wpseo_title', true ),
                        'link'              => (string) get_permalink( $p->get_id() ),
                    );
                },
                'permission_callback' => function () {
                    return current_user_can( 'edit_products' ) || current_user_can( 'manage_woocommerce' );
                },
                'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'mcp' => array( 'public' => true ) ),
            )
        );

        wp_register_ability(
            'jkc/wc-update-product',
            array(
                'label'         => __( 'WooCommerce: Update Product', 'jkc-mcp' ),
                'description'   => __( 'Update a WooCommerce product: name, prices, stock, descriptions, status and Yoast SEO meta (meta_description, focus_keyphrase, seo_title). Prices affect a live shop, so show the change and get explicit approval before calling.', 'jkc-mcp' ),
                'category'      => 'jkc-content',
                'input_schema'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'id'                => array( 'type' => 'integer' ),
                        'name'              => array( 'type' => 'string' ),
                        'regular_price'     => array( 'type' => 'string', 'description' => 'Bedrag, bijv. "19.95".' ),
                        'sale_price'        => array( 'type' => 'string', 'description' => 'Actieprijs, of "" om te verwijderen.' ),
                        'stock_quantity'    => array( 'type' => 'integer' ),
                        'stock_status'      => array( 'type' => 'string', 'enum' => array( 'instock', 'outofstock', 'onbackorder' ) ),
                        'manage_stock'      => array( 'type' => 'boolean' ),
                        'short_description' => array( 'type' => 'string' ),
                        'description'       => array( 'type' => 'string' ),
                        'status'            => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'private', 'pending' ) ),
                        'meta_description'  => array( 'type' => 'string', 'description' => 'Yoast meta description (optioneel).' ),
                        'focus_keyphrase'   => array( 'type' => 'string', 'description' => 'Yoast focus keyphrase (optioneel).' ),
                        'seo_title'         => array( 'type' => 'string', 'description' => 'Yoast SEO-titel (optioneel).' ),
                    ),
                    'required'   => array( 'id' ),
                ),
                'output_schema' => array( 'type' => 'object' ),
                'execute_callback'    => function ( array $input ) {
                    $p = wc_get_product( (int) ( $input['id'] ?? 0 ) );
                    if ( ! $p ) {
                        return array( 'error' => true, 'message' => 'Product niet gevonden.' );
                    }
                    if ( ! current_user_can( 'edit_post', $p->get_id() ) ) {
                        return array( 'error' => true, 'message' => 'Geen rechten om dit product te bewerken.' );
                    }
                    if ( isset( $input['name'] ) ) {
                        $p->set_name( sanitize_text_field( $input['name'] ) );
                    }
                    if ( isset( $input['regular_price'] ) ) {
                        $p->set_regular_price( wc_clean( $input['regular_price'] ) );
                    }
                    if ( isset( $input['sale_price'] ) ) {
                        $p->set_sale_price( '' === $input['sale_price'] ? '' : wc_clean( $input['sale_price'] ) );
                    }
                    if ( isset( $input['manage_stock'] ) ) {
                        $p->set_manage_stock( (bool) $input['manage_stock'] );
                    }
                    if ( isset( $input['stock_quantity'] ) ) {
                        $p->set_stock_quantity( (int) $input['stock_quantity'] );
                    }
                    if ( isset( $input['stock_status'] ) ) {
                        $p->set_stock_status( sanitize_key( $input['stock_status'] ) );
                    }
                    if ( isset( $input['short_description'] ) ) {
                        $p->set_short_description( $input['short_description'] );
                    }
                    if ( isset( $input['description'] ) ) {
                        $p->set_description( $input['description'] );
                    }
                    if ( isset( $input['status'] ) ) {
                        $p->set_status( sanitize_key( $input['status'] ) );
                    }
                    $p->save();

                    // Yoast SEO-meta (zelfde keys als pagina's/berichten).
                    if ( isset( $input['meta_description'] ) ) {
                        update_post_meta( $p->get_id(), '_yoast_wpseo_metadesc', sanitize_text_field( $input['meta_description'] ) );
                    }
                    if ( isset( $input['focus_keyphrase'] ) ) {
                        update_post_meta( $p->get_id(), '_yoast_wpseo_focuskw', sanitize_text_field( $input['focus_keyphrase'] ) );
                    }
                    if ( isset( $input['seo_title'] ) ) {
                        update_post_meta( $p->get_id(), '_yoast_wpseo_title', sanitize_text_field( $input['seo_title'] ) );
                    }

                    return array(
                        'id'               => (int) $p->get_id(),
                        'name'             => $p->get_name(),
                        'regular_price'    => $p->get_regular_price(),
                        'sale_price'       => $p->get_sale_price(),
                        'stock_status'     => $p->get_stock_status(),
                        'status'           => $p->get_status(),
                        'meta_description' => (string) get_post_meta( $p->get_id(), '_yoast_wpseo_metadesc', true ),
                        'focus_keyphrase'  => (string) get_post_meta( $p->get_id(), '_yoast_wpseo_focuskw', true ),
                        'seo_title'        => (string) get_post_meta( $p->get_id(), '_yoast_wpseo_title', true ),
                        'link'             => (string) get_permalink( $p->get_id() ),
                        'result'           => 'bijgewerkt',
                    );
                },
                'permission_callback' => function () {
                    return current_user_can( 'edit_products' ) || current_user_can( 'manage_woocommerce' );
                },
                'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true ), 'mcp' => array( 'public' => true ) ),
            )
        );

        /* ---- WooCommerce: orders ------------------------------------- */
        wp_register_ability(
            'jkc/wc-list-orders',
            array(
                'label'         => __( 'WooCommerce: List Orders', 'jkc-mcp' ),
                'description'   => __( 'List recent WooCommerce orders (id, number, status, total, customer, date). Optional status filter.', 'jkc-mcp' ),
                'category'      => 'jkc-content',
                'input_schema'  => array( 'type' => 'object', 'properties' => array( 'status' => array( 'type' => 'string', 'description' => 'bijv. processing, completed, on-hold' ), 'limit' => array( 'type' => 'integer' ) ) ),
                'output_schema' => array( 'type' => 'object' ),
                'execute_callback'    => function ( array $input ) {
                    $args = array( 'limit' => isset( $input['limit'] ) ? max( 1, min( (int) $input['limit'], 100 ) ) : 25, 'orderby' => 'date', 'order' => 'DESC' );
                    if ( ! empty( $input['status'] ) ) {
                        $args['status'] = sanitize_key( $input['status'] );
                    }
                    $orders = wc_get_orders( $args );
                    $out = array();
                    foreach ( $orders as $o ) {
                        $out[] = array(
                            'id'       => $o->get_id(),
                            'number'   => $o->get_order_number(),
                            'status'   => $o->get_status(),
                            'total'    => $o->get_total(),
                            'currency' => $o->get_currency(),
                            'customer' => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
                            'email'    => $o->get_billing_email(),
                            'date'     => $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : null,
                        );
                    }
                    return array( 'count' => count( $out ), 'orders' => $out );
                },
                'permission_callback' => function () {
                    return current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_shop_orders' );
                },
                'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'mcp' => array( 'public' => true ) ),
            )
        );

        wp_register_ability(
            'jkc/wc-get-order',
            array(
                'label'         => __( 'WooCommerce: Get Order', 'jkc-mcp' ),
                'description'   => __( 'Get full details of one order by id: status, totals, items, billing.', 'jkc-mcp' ),
                'category'      => 'jkc-content',
                'input_schema'  => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'integer' ) ), 'required' => array( 'id' ) ),
                'output_schema' => array( 'type' => 'object' ),
                'execute_callback'    => function ( array $input ) {
                    $o = wc_get_order( (int) ( $input['id'] ?? 0 ) );
                    if ( ! $o ) {
                        return array( 'error' => true, 'message' => 'Order niet gevonden.' );
                    }
                    $items = array();
                    foreach ( $o->get_items() as $item ) {
                        $items[] = array( 'name' => $item->get_name(), 'qty' => $item->get_quantity(), 'total' => $item->get_total() );
                    }
                    return array(
                        'id'       => $o->get_id(),
                        'number'   => $o->get_order_number(),
                        'status'   => $o->get_status(),
                        'total'    => $o->get_total(),
                        'currency' => $o->get_currency(),
                        'customer' => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
                        'email'    => $o->get_billing_email(),
                        'phone'    => $o->get_billing_phone(),
                        'date'     => $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i' ) : null,
                        'items'    => $items,
                        'note'     => $o->get_customer_note(),
                    );
                },
                'permission_callback' => function () {
                    return current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_shop_orders' );
                },
                'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'mcp' => array( 'public' => true ) ),
            )
        );

        wp_register_ability(
            'jkc/wc-update-order-status',
            array(
                'label'         => __( 'WooCommerce: Update Order Status', 'jkc-mcp' ),
                'description'   => __( 'Change an order status (pending, processing, on-hold, completed, cancelled, refunded, failed). Kan een e-mail naar de klant triggeren; toon de wijziging en vraag akkoord vooraf.', 'jkc-mcp' ),
                'category'      => 'jkc-content',
                'input_schema'  => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'integer' ), 'status' => array( 'type' => 'string', 'enum' => array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' ) ), 'note' => array( 'type' => 'string' ) ), 'required' => array( 'id', 'status' ) ),
                'output_schema' => array( 'type' => 'object' ),
                'execute_callback'    => function ( array $input ) {
                    $o = wc_get_order( (int) ( $input['id'] ?? 0 ) );
                    if ( ! $o ) {
                        return array( 'error' => true, 'message' => 'Order niet gevonden.' );
                    }
                    if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
                        return array( 'error' => true, 'message' => 'Geen rechten.' );
                    }
                    $valid  = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed' );
                    $status = sanitize_key( $input['status'] ?? '' );
                    if ( ! in_array( $status, $valid, true ) ) {
                        return array( 'error' => true, 'message' => 'Ongeldige status.' );
                    }
                    $o->update_status( $status, isset( $input['note'] ) ? sanitize_text_field( $input['note'] ) : '' );
                    return array( 'id' => $o->get_id(), 'status' => $o->get_status(), 'result' => 'status bijgewerkt' );
                },
                'permission_callback' => function () {
                    return current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_shop_orders' );
                },
                'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true ), 'mcp' => array( 'public' => true ) ),
            )
        );

        /* ---- WooCommerce: customers ---------------------------------- */
        wp_register_ability(
            'jkc/wc-list-customers',
            array(
                'label'         => __( 'WooCommerce: List Customers', 'jkc-mcp' ),
                'description'   => __( 'List customers (id, name, email). Optional search term.', 'jkc-mcp' ),
                'category'      => 'jkc-content',
                'input_schema'  => array( 'type' => 'object', 'properties' => array( 'search' => array( 'type' => 'string' ), 'limit' => array( 'type' => 'integer' ) ) ),
                'output_schema' => array( 'type' => 'object' ),
                'execute_callback'    => function ( array $input ) {
                    $args = array( 'role' => 'customer', 'number' => isset( $input['limit'] ) ? max( 1, min( (int) $input['limit'], 100 ) ) : 25 );
                    if ( ! empty( $input['search'] ) ) {
                        $args['search']         = '*' . sanitize_text_field( $input['search'] ) . '*';
                        $args['search_columns'] = array( 'user_email', 'display_name', 'user_login' );
                    }
                    $users = get_users( $args );
                    $out   = array();
                    foreach ( $users as $u ) {
                        $out[] = array( 'id' => (int) $u->ID, 'name' => $u->display_name, 'email' => $u->user_email );
                    }
                    return array( 'count' => count( $out ), 'customers' => $out );
                },
                'permission_callback' => function () {
                    return current_user_can( 'manage_woocommerce' ) || current_user_can( 'list_users' );
                },
                'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'mcp' => array( 'public' => true ) ),
            )
        );

        wp_register_ability(
            'jkc/wc-get-customer',
            array(
                'label'         => __( 'WooCommerce: Get Customer', 'jkc-mcp' ),
                'description'   => __( 'Get a customer by id: name, email, order count and total spent.', 'jkc-mcp' ),
                'category'      => 'jkc-content',
                'input_schema'  => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'integer' ) ), 'required' => array( 'id' ) ),
                'output_schema' => array( 'type' => 'object' ),
                'execute_callback'    => function ( array $input ) {
                    $id = (int) ( $input['id'] ?? 0 );
                    $c  = new WC_Customer( $id );
                    if ( ! $c->get_id() ) {
                        return array( 'error' => true, 'message' => 'Klant niet gevonden.' );
                    }
                    return array(
                        'id'          => $c->get_id(),
                        'name'        => trim( $c->get_first_name() . ' ' . $c->get_last_name() ),
                        'email'       => $c->get_email(),
                        'order_count' => $c->get_order_count(),
                        'total_spent' => $c->get_total_spent(),
                    );
                },
                'permission_callback' => function () {
                    return current_user_can( 'manage_woocommerce' ) || current_user_can( 'list_users' );
                },
                'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'mcp' => array( 'public' => true ) ),
            )
        );

        /* ---- WooCommerce: coupons ------------------------------------ */
        wp_register_ability(
            'jkc/wc-list-coupons',
            array(
                'label'         => __( 'WooCommerce: List Coupons', 'jkc-mcp' ),
                'description'   => __( 'List discount coupons (code, type, amount, expiry).', 'jkc-mcp' ),
                'category'      => 'jkc-content',
                'input_schema'  => array( 'type' => 'object', 'properties' => array( 'limit' => array( 'type' => 'integer' ) ) ),
                'output_schema' => array( 'type' => 'object' ),
                'execute_callback'    => function ( array $input ) {
                    $ids = get_posts( array( 'post_type' => 'shop_coupon', 'post_status' => 'publish', 'numberposts' => isset( $input['limit'] ) ? max( 1, min( (int) $input['limit'], 100 ) ) : 50, 'fields' => 'ids' ) );
                    $out = array();
                    foreach ( $ids as $cid ) {
                        $c   = new WC_Coupon( $cid );
                        $exp = $c->get_date_expires();
                        $out[] = array( 'code' => $c->get_code(), 'type' => $c->get_discount_type(), 'amount' => $c->get_amount(), 'expires' => $exp ? $exp->date( 'Y-m-d' ) : null );
                    }
                    return array( 'count' => count( $out ), 'coupons' => $out );
                },
                'permission_callback' => function () {
                    return current_user_can( 'manage_woocommerce' );
                },
                'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'mcp' => array( 'public' => true ) ),
            )
        );

        wp_register_ability(
            'jkc/wc-create-coupon',
            array(
                'label'         => __( 'WooCommerce: Create Coupon', 'jkc-mcp' ),
                'description'   => __( 'Create a discount coupon. discount_type: percent, fixed_cart of fixed_product. Toon de coupon en vraag akkoord vooraf.', 'jkc-mcp' ),
                'category'      => 'jkc-content',
                'input_schema'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'code'          => array( 'type' => 'string' ),
                        'discount_type' => array( 'type' => 'string', 'enum' => array( 'percent', 'fixed_cart', 'fixed_product' ) ),
                        'amount'        => array( 'type' => 'string', 'description' => 'Bedrag of percentage, bijv. "10".' ),
                        'expires'       => array( 'type' => 'string', 'description' => 'Vervaldatum YYYY-MM-DD (optioneel).' ),
                        'usage_limit'   => array( 'type' => 'integer', 'description' => 'Max aantal keer te gebruiken (optioneel).' ),
                    ),
                    'required'   => array( 'code', 'discount_type', 'amount' ),
                ),
                'output_schema' => array( 'type' => 'object' ),
                'execute_callback'    => function ( array $input ) {
                    if ( ! current_user_can( 'manage_woocommerce' ) ) {
                        return array( 'error' => true, 'message' => 'Geen rechten.' );
                    }
                    $type = sanitize_key( $input['discount_type'] ?? '' );
                    if ( ! in_array( $type, array( 'percent', 'fixed_cart', 'fixed_product' ), true ) ) {
                        return array( 'error' => true, 'message' => 'Ongeldig discount_type.' );
                    }
                    $c = new WC_Coupon();
                    $c->set_code( sanitize_text_field( $input['code'] ) );
                    $c->set_discount_type( $type );
                    $c->set_amount( (float) $input['amount'] );
                    if ( ! empty( $input['expires'] ) ) {
                        $c->set_date_expires( sanitize_text_field( $input['expires'] ) );
                    }
                    if ( ! empty( $input['usage_limit'] ) ) {
                        $c->set_usage_limit( (int) $input['usage_limit'] );
                    }
                    $c->save();
                    return array( 'id' => $c->get_id(), 'code' => $c->get_code(), 'type' => $c->get_discount_type(), 'amount' => $c->get_amount(), 'result' => 'kortingscode aangemaakt' );
                },
                'permission_callback' => function () {
                    return current_user_can( 'manage_woocommerce' );
                },
                'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true ), 'mcp' => array( 'public' => true ) ),
            )
        );

        /* ---- WooCommerce: productcategorieën ------------------------- */
        $jkc_wc_cat_read_cap  = function () {
            return current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_products' ) || current_user_can( 'manage_product_terms' );
        };
        $jkc_wc_cat_write_cap = function () {
            return current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_products' ) || current_user_can( 'manage_product_terms' ) || current_user_can( 'manage_categories' );
        };

        wp_register_ability(
            'jkc/wc-find-categories',
            array(
                'label'         => __( 'WooCommerce: Find Categories', 'jkc-mcp' ),
                'description'   => __( 'List WooCommerce product categories with id, name, slug, parent and product count. Category pages typically rank for the most valuable generic keywords. Optional search term.', 'jkc-mcp' ),
                'category'      => 'jkc-content',
                'input_schema'  => array( 'type' => 'object', 'properties' => array( 'search' => array( 'type' => 'string' ), 'limit' => array( 'type' => 'integer' ) ) ),
                'output_schema' => array( 'type' => 'object' ),
                'execute_callback'    => function ( array $input ) {
                    $args = array(
                        'taxonomy'   => 'product_cat',
                        'hide_empty' => false,
                        'number'     => isset( $input['limit'] ) ? max( 1, min( (int) $input['limit'], 300 ) ) : 100,
                        'orderby'    => 'name',
                        'order'      => 'ASC',
                    );
                    if ( ! empty( $input['search'] ) ) {
                        $args['search'] = sanitize_text_field( $input['search'] );
                    }
                    $terms = get_terms( $args );
                    if ( is_wp_error( $terms ) ) {
                        return $terms;
                    }
                    $out = array();
                    foreach ( $terms as $t ) {
                        $link  = get_term_link( $t );
                        $out[] = array(
                            'id'            => (int) $t->term_id,
                            'name'          => $t->name,
                            'slug'          => $t->slug,
                            'parent'        => (int) $t->parent,
                            'product_count' => (int) $t->count,
                            'link'          => is_wp_error( $link ) ? '' : (string) $link,
                        );
                    }
                    return array( 'count' => count( $out ), 'categories' => $out );
                },
                'permission_callback' => $jkc_wc_cat_read_cap,
                'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'mcp' => array( 'public' => true ) ),
            )
        );

        wp_register_ability(
            'jkc/wc-get-category',
            array(
                'label'         => __( 'WooCommerce: Get Category', 'jkc-mcp' ),
                'description'   => __( 'Get a product category by id or slug: name, slug, description, parent, product count and Yoast SEO fields (seo_title, meta_description, focus_keyphrase).', 'jkc-mcp' ),
                'category'      => 'jkc-content',
                'input_schema'  => array( 'type' => 'object', 'properties' => array( 'id' => array( 'type' => 'integer' ), 'slug' => array( 'type' => 'string' ) ) ),
                'output_schema' => array( 'type' => 'object' ),
                'execute_callback'    => function ( array $input ) {
                    $term = jkc_mcp_resolve_term( $input, 'product_cat' );
                    if ( is_wp_error( $term ) ) {
                        return array( 'error' => true, 'message' => 'Categorie niet gevonden.' );
                    }
                    $yoast = jkc_mcp_get_term_yoast( 'product_cat', $term->term_id );
                    $link  = get_term_link( $term );
                    return array(
                        'id'               => (int) $term->term_id,
                        'name'             => $term->name,
                        'slug'             => $term->slug,
                        'parent'           => (int) $term->parent,
                        'description'      => $term->description,
                        'product_count'    => (int) $term->count,
                        'link'             => is_wp_error( $link ) ? '' : (string) $link,
                        'seo_title'        => $yoast['seo_title'],
                        'meta_description' => $yoast['meta_description'],
                        'focus_keyphrase'  => $yoast['focus_keyphrase'],
                    );
                },
                'permission_callback' => $jkc_wc_cat_read_cap,
                'meta'                => array( 'annotations' => array( 'readonly' => true, 'destructive' => false ), 'mcp' => array( 'public' => true ) ),
            )
        );

        wp_register_ability(
            'jkc/wc-update-category',
            array(
                'label'         => __( 'WooCommerce: Update Category', 'jkc-mcp' ),
                'description'   => __( 'Update a product category description (and optionally its name) by id or slug. Show the new text and get approval before calling.', 'jkc-mcp' ),
                'category'      => 'jkc-content',
                'input_schema'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'id'          => array( 'type' => 'integer' ),
                        'slug'        => array( 'type' => 'string' ),
                        'name'        => array( 'type' => 'string', 'description' => 'Nieuwe naam (optioneel).' ),
                        'description' => array( 'type' => 'string', 'description' => 'Nieuwe beschrijving (HTML toegestaan).' ),
                    ),
                ),
                'output_schema' => array( 'type' => 'object' ),
                'execute_callback'    => function ( array $input ) {
                    if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_products' ) && ! current_user_can( 'manage_product_terms' ) && ! current_user_can( 'manage_categories' ) ) {
                        return array( 'error' => true, 'message' => 'Geen rechten.' );
                    }
                    $term = jkc_mcp_resolve_term( $input, 'product_cat' );
                    if ( is_wp_error( $term ) ) {
                        return array( 'error' => true, 'message' => 'Categorie niet gevonden.' );
                    }
                    $args = array();
                    if ( isset( $input['name'] ) ) {
                        $args['name'] = sanitize_text_field( $input['name'] );
                    }
                    if ( isset( $input['description'] ) ) {
                        $args['description'] = wp_kses_post( $input['description'] );
                    }
                    if ( empty( $args ) ) {
                        return array( 'error' => true, 'message' => 'Niets om bij te werken (geef name en/of description).' );
                    }
                    $res = wp_update_term( $term->term_id, 'product_cat', $args );
                    if ( is_wp_error( $res ) ) {
                        return $res;
                    }
                    $term = get_term( $term->term_id, 'product_cat' );
                    return array(
                        'id'          => (int) $term->term_id,
                        'name'        => $term->name,
                        'slug'        => $term->slug,
                        'description' => $term->description,
                        'result'      => 'bijgewerkt',
                    );
                },
                'permission_callback' => $jkc_wc_cat_write_cap,
                'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true ), 'mcp' => array( 'public' => true ) ),
            )
        );

        wp_register_ability(
            'jkc/wc-update-category-seo',
            array(
                'label'         => __( 'WooCommerce: Update Category SEO', 'jkc-mcp' ),
                'description'   => __( 'Set the Yoast SEO title, meta description and/or focus keyphrase on a WooCommerce product category (taxonomy term), by id or slug. Uses the same Yoast conventions as page/post SEO.', 'jkc-mcp' ),
                'category'      => 'jkc-content',
                'input_schema'  => array(
                    'type'       => 'object',
                    'properties' => array(
                        'id'               => array( 'type' => 'integer' ),
                        'slug'             => array( 'type' => 'string' ),
                        'seo_title'        => array( 'type' => 'string', 'description' => 'Yoast SEO-titel (optioneel).' ),
                        'meta_description' => array( 'type' => 'string', 'description' => 'Yoast meta description (optioneel).' ),
                        'focus_keyphrase'  => array( 'type' => 'string', 'description' => 'Yoast focus keyphrase (optioneel).' ),
                    ),
                ),
                'output_schema' => array( 'type' => 'object' ),
                'execute_callback'    => function ( array $input ) {
                    if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_products' ) && ! current_user_can( 'manage_product_terms' ) && ! current_user_can( 'manage_categories' ) ) {
                        return array( 'error' => true, 'message' => 'Geen rechten.' );
                    }
                    $term = jkc_mcp_resolve_term( $input, 'product_cat' );
                    if ( is_wp_error( $term ) ) {
                        return array( 'error' => true, 'message' => 'Categorie niet gevonden.' );
                    }
                    if ( ! isset( $input['seo_title'] ) && ! isset( $input['meta_description'] ) && ! isset( $input['focus_keyphrase'] ) ) {
                        return array( 'error' => true, 'message' => 'Geef minstens één SEO-veld op.' );
                    }
                    $updated = jkc_mcp_set_term_yoast( 'product_cat', $term->term_id, $input );
                    return array(
                        'id'               => (int) $term->term_id,
                        'name'             => $term->name,
                        'slug'             => $term->slug,
                        'seo_title'        => $updated['seo_title'],
                        'meta_description' => $updated['meta_description'],
                        'focus_keyphrase'  => $updated['focus_keyphrase'],
                        'result'           => 'bijgewerkt',
                    );
                },
                'permission_callback' => $jkc_wc_cat_write_cap,
                'meta'                => array( 'annotations' => array( 'readonly' => false, 'destructive' => true ), 'mcp' => array( 'public' => true ) ),
            )
        );
    }
}
