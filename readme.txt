=== JKC MCP Content Abilities ===
Contributors: jkcmedia
Requires PHP: 7.4
Stable tag: 1.8.1
License: GPLv2 or later

Stelt lees-, audit- en schrijf-abilities beschikbaar aan de WordPress MCP Adapter,
zodat AI-assistenten zoals Claude pagina's, berichten en Yoast SEO kunnen lezen,
auditen en bewerken. Maakt bij activatie automatisch een Claude-gebruiker met
applicatie-wachtwoord aan. Werkt op elke WordPress-site.

== Vereisten ==
* WordPress 5.6+ (voor applicatie-wachtwoorden)
* WordPress Abilities API + MCP Adapter actief (endpoint /wp-json/mcp/mcp-adapter-default-server)
* Yoast SEO (voor de SEO-velden en audit)

== Automatische setup ==
Bij activatie maakt de plugin de gebruiker "claude-editor" (rol Redacteur) aan,
genereert een applicatie-wachtwoord "Claude MCP", en toont gebruikersnaam +
wachtwoord eenmalig in een groene beheerdersmelding. Kopieer die gegevens, ze
worden maar 1 keer getoond. Bestaat de gebruiker al, dan wordt niets overschreven.

== Abilities ==
* jkc/get-content     - lees titel, content, status en Yoast-meta (alleen-lezen)
* jkc/seo-audit       - volledige SEO-snapshot: meta, scores, featured image,
                        indexeerbaarheid (site + pagina), keyphrase-checks,
                        woordaantal, H1-aantal, afbeeldingen zonder alt,
                        interne/externe links en een lijst gevonden problemen
* jkc/update-content  - wijzig titel, content en/of status
* jkc/update-seo-meta - wijzig Yoast meta description, focus keyphrase en SEO-titel
* jkc/create-content  - maak een nieuwe pagina of bericht aan

Werkt voor pagina's (type "page", standaard) en berichten (type "post").
Alle abilities zijn meta.mcp.public = true en verschijnen op de default server.

== Changelog ==
= 1.8.1 =
* WooCommerce-producten: Yoast SEO-meta (meta description, focus keyphrase, SEO-titel) lezen en bewerken.
= 1.8.0 =
* WooCommerce-beheer (producten zoeken/lezen/bewerken) + fix-de-audit-tools: afbeeldingen zonder alt, gebroken links, en redirects beheren.
= 1.7.0 =
* jkc/bulk-update-seo-meta: Yoast-meta voor meerdere pagina's tegelijk wegschrijven.
= 1.6.0 =
* jkc/bulk-seo-audit toegevoegd: hele site in een keer scannen op SEO-problemen (geprioriteerde lijst).
= 1.5.0 =
* Posts/pagina's inplannen: create-content en update-content accepteren publish_date (toekomstige datum/tijd = ingepland).
= 1.4.0 =
* Automatische updates via GitHub: WordPress meldt voortaan een update zodra er een nieuwe versie op de repo staat.
= 1.3.0 =
* jkc/find-content toegevoegd: pagina's/berichten zoeken op (deel van) titel of slug, of alle oplijsten. Claude vindt nu de juiste pagina bij losse of anderstalige omschrijvingen.
= 1.2.0 =
* jkc/seo-audit toegevoegd (featured image, no-index, SEO-issues, keyphrase-checks).
* Automatische aanmaak van claude-editor + applicatie-wachtwoord bij activatie.
= 1.1.1 =
* Category op de juiste hook (wp_abilities_api_categories_init) geregistreerd.
= 1.1.0 =
* Generiek gemaakt voor elke site; ondersteuning voor pagina's en berichten.
= 1.0.0 =
* Eerste versie.
