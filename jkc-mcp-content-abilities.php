<?php
/**
 * Plugin Name:       JKC MCP Content Abilities
 * Description:       Stelt lees- en schrijf-abilities (pagina's, berichten, Yoast SEO, volledige SEO-audit) beschikbaar aan de WordPress MCP Adapter, zodat AI-assistenten zoals Claude content op deze site kunnen lezen, auditen en bewerken. Maakt bij activatie automatisch een Claude-gebruiker met applicatie-wachtwoord aan. Werkt op elke WordPress-site.
 * Version:           1.5.0
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
}
