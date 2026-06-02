<?php
/**
 * Plugin Name:       JKC MCP Content Abilities
 * Description:       Stelt lees- en schrijf-abilities (pagina's, berichten, Yoast SEO, volledige SEO-audit) beschikbaar aan de WordPress MCP Adapter, zodat AI-assistenten zoals Claude content op deze site kunnen lezen, auditen en bewerken. Maakt bij activatie automatisch een Claude-gebruiker met applicatie-wachtwoord aan. Werkt op elke WordPress-site.
 * Version:           1.9.0
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
    return array( 'page', 'post' );
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
    return ( 'post' === $type ) ? 'publish_posts' : 'publish_pages';
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

    // Render content (shortcodes / blocks naar echte HTML).
    global $post;
    $orig = $post;
    $post = $obj;
    setup_postdata( $post );
    $rendered = apply_filters( 'the_content', $obj->post_content );
    wp_reset_postdata();
    $post = $orig;

    $text       = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $rendered ) ) );
    $word_count = ( '' === $text ) ? 0 : count( preg_split( '/\s+/', $text ) );

    $kw_lower   = strtolower( trim( $keyphrase ) );
    $text_lower = strtolower( $text );
    $kw_words   = ( '' === $kw_lower ) ? 0 : count( preg_split( '/\s+/', $kw_lower ) );
    $kw_count   = ( '' !== $kw_lower ) ? substr_count( $text_lower, $kw_lower ) : 0;
    $density    = ( $word_count > 0 && '' !== $kw_lower )
        ? round( ( $kw_count * max( 1, $kw_words ) ) / $word_count * 100, 2 )
        : 0;

    // Subkoppen (H2/H3).
    $subheads = array();
    if ( preg_match_all( '/<h([23])[^>]*>(.*?)<\/h\1>/is', $rendered, $m, PREG_SET_ORDER ) ) {
        foreach ( $m as $h ) {
            $clean = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $h[2] ) ) );
            if ( '' !== $clean ) {
                $subheads[] = $clean;
            }
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

    // Links.
    $home_host = wp_parse_url( home_url(), PHP_URL_HOST );
    $internal  = 0;
    $outbound  = 0;
    if ( preg_match_all( '/<a\b[^>]*href\s*=\s*("[^"]*"|\'[^\']*\')[^>]*>/i', $rendered, $lm ) ) {
        foreach ( $lm[1] as $href ) {
            $href = trim( $href, '"\'' );
            if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === stripos( $href, 'mailto:' ) || 0 === stripos( $href, 'tel:' ) ) {
                continue;
            }
            $host = wp_parse_url( $href, PHP_URL_HOST );
            if ( ! $host || $host === $home_host ) {
                $internal++;
            } else {
                $outbound++;
            }
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

    return array(
        'id'     => (int) $obj->ID,
        'type'   => $obj->post_type,
        'title'  => get_the_title( $obj ),
        'slug'   => $obj->post_name,
        'status' => $obj->post_status,
        'link'   => (string) get_permalink( $obj ),
        'seo'    => array(
            'focus_keyphrase'         => $keyphrase,
            'meta_description'        => $metadesc,
            'meta_description_length' => strlen( $metadesc ),
            'seo_title'               => $seo_title,
            'seo_score'               => ( '' === $seo_score ) ? null : (int) $seo_score,
            'readability_score'       => ( '' === $read_score ) ? null : (int) $read_score,
        ),
        'indexable' => array(
            'site_indexable' => $site_indexable,
            'page_noindex'   => $page_noindex,
        ),
        'featured_image' => $featured,
        'checks'         => array(
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
        'description' => 'Content type: "page" (default) or "post".',
    );

    /* ---- READ: get content ------------------------------------------- */
    wp_register_ability(
        'jkc/get-content',
        array(
            'label'         => __( 'Get Content', 'jkc-mcp' ),
            'description'   => __( 'Returns the title, content, status and Yoast SEO meta of a page or post by slug or ID.', 'jkc-mcp' ),
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
            'description'   => __( 'Search pages or posts by a partial title or slug, or list all of them when no query is given. Use this FIRST when the user refers to a page loosely or in another language (e.g. "the about page", "de over-pagina") to find the correct slug/id before calling get-content, seo-audit or update tools. Returns id, title, slug, status and link.', 'jkc-mcp' ),
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
                $type  = isset( $input['type'] ) && in_array( $input['type'], jkc_mcp_allowed_types(), true )
                    ? $input['type']
                    : 'page';
                $limit = isset( $input['limit'] ) ? max( 1, min( (int) $input['limit'], 200 ) ) : 50;
                $q     = isset( $input['query'] ) ? strtolower( trim( $input['query'] ) ) : '';

                $all = get_posts(
                    array(
                        'post_type'        => $type,
                        'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
                        'numberposts'      => 200,
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
                return array( 'type' => $type, 'query' => $q, 'count' => count( $out ), 'results' => $out );
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
            'description'   => __( 'Returns a complete SEO snapshot of a page or post: Yoast meta and stored scores, featured image, indexability (site-wide and per-page), and computed checks (keyphrase in title/slug/intro/subheadings, density, word count, H1 count, images without alt, internal/outbound links) plus a list of detected issues.', 'jkc-mcp' ),
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
                        'enum'        => array( 'page', 'post', 'product' ),
                        'description' => 'Content type: "page" (default), "post" of "product" (WooCommerce).',
                    ),
                    'limit' => array( 'type' => 'integer', 'description' => 'Max items (default 200).' ),
                ),
            ),
            'output_schema' => array( 'type' => 'object' ),
            'execute_callback'    => function ( array $input ) {
                $bulk_types = array( 'page', 'post' );
                if ( function_exists( 'wc_get_product' ) ) {
                    $bulk_types[] = 'product';
                }
                $type  = isset( $input['type'] ) && in_array( $input['type'], $bulk_types, true )
                    ? $input['type']
                    : 'page';
                $limit = isset( $input['limit'] ) ? max( 1, min( (int) $input['limit'], 500 ) ) : 200;

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
            'description'   => __( 'Updates the title, content, status and/or scheduled publish date of an existing page or post. Use publish_date to schedule. Publishing requires publish rights.', 'jkc-mcp' ),
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
            'description'   => __( 'Updates the Yoast meta description, focus keyphrase and SEO title of a page or post.', 'jkc-mcp' ),
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
                ),
            ),
            'output_schema' => array(
                'type'       => 'object',
                'properties' => array(
                    'id'               => array( 'type' => 'integer' ),
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

                return array(
                    'id'               => (int) $post->ID,
                    'meta_description' => (string) get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true ),
                    'focus_keyphrase'  => (string) get_post_meta( $post->ID, '_yoast_wpseo_focuskw', true ),
                    'seo_title'        => (string) get_post_meta( $post->ID, '_yoast_wpseo_title', true ),
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
            'description'   => __( 'Creates a new page or post. Use publish_date to schedule it for a future date/time (status becomes future). Publishing requires publish rights.', 'jkc-mcp' ),
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
            'description'   => __( 'Scan published pages and posts for hyperlinks and report links returning an error (4xx/5xx) or that fail. Checks up to a capped number of unique links.', 'jkc-mcp' ),
            'category'      => 'jkc-content',
            'input_schema'  => array( 'type' => 'object', 'properties' => array( 'max_links' => array( 'type' => 'integer', 'description' => 'Max unieke links om te checken (default 80).' ) ) ),
            'output_schema' => array( 'type' => 'object' ),
            'execute_callback'    => function ( array $input ) {
                $max   = isset( $input['max_links'] ) ? max( 1, min( (int) $input['max_links'], 200 ) ) : 80;
                $posts = get_posts(
                    array(
                        'post_type'        => array( 'page', 'post' ),
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
    }
}
