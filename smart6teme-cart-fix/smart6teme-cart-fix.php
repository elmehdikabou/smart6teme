<?php
/**
 * Plugin Name:          Smart6teme – Correctif panier AJAX
 * Plugin URI:           https://github.com/elmehdikabou/smart6teme
 * Description:          Corrige le bouton « Acheter » qui reste bloqué et le mini-panier qui ne se met à jour qu'après rechargement de la page (fragments AJAX WooCommerce).
 * Version:              1.0.0
 * Author:               Smart6teme
 * License:              GPL-2.0-or-later
 * Text Domain:          s6t-cart-fix
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * WC requires at least: 7.0
 *
 * @package Smart6teme_Cart_Fix
 */

defined( 'ABSPATH' ) || exit;

define( 'S6T_CART_FIX_VERSION', '1.0.0' );
define( 'S6T_CART_FIX_FILE', __FILE__ );
define( 'S6T_CART_FIX_DIR', plugin_dir_path( __FILE__ ) );
define( 'S6T_CART_FIX_URL', plugin_dir_url( __FILE__ ) );

require_once S6T_CART_FIX_DIR . 'includes/class-s6t-cart-diagnostic.php';

/* -------------------------------------------------------------------------
 * Outils
 * ---------------------------------------------------------------------- */

/**
 * Vrai si la requête en cours est une requête AJAX WooCommerce.
 *
 * WooCommerce utilise deux points d'entrée : /?wc-ajax=<action> (rapide, sans
 * charger l'admin) et admin-ajax.php. Les deux doivent échapper au cache.
 *
 * @return bool
 */
function s6t_cart_fix_is_wc_ajax() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple lecture de contexte.
	if ( isset( $_GET['wc-ajax'] ) && '' !== $_GET['wc-ajax'] ) {
		return true;
	}

	return function_exists( 'wp_doing_ajax' ) ? wp_doing_ajax() : ( defined( 'DOING_AJAX' ) && DOING_AJAX );
}

/**
 * Un correctif est-il désactivé via une constante de wp-config.php ?
 *
 * @param string $name Suffixe de la constante, ex. « NOCACHE ».
 * @return bool
 */
function s6t_cart_fix_disabled( $name ) {
	$const = 'S6T_CART_FIX_DISABLE_' . strtoupper( $name );

	return defined( $const ) && constant( $const );
}

/* -------------------------------------------------------------------------
 * Correctif 1 — Ne jamais mettre en cache les réponses AJAX WooCommerce
 *
 * C'est la cause n°1 du symptôme décrit : quand ?wc-ajax=add_to_cart renvoie
 * une page HTML servie par le cache au lieu du JSON attendu, le script
 * WooCommerce lève une exception. Résultat : le bouton reste bloqué sur
 * « loading » et le mini-panier n'est jamais rafraîchi — alors que le produit
 * a bien été ajouté côté serveur (d'où le message de succès au rechargement).
 * ---------------------------------------------------------------------- */

add_action( 'init', 's6t_cart_fix_no_cache_on_ajax', 0 );

/**
 * Interdit la mise en cache de la réponse AJAX en cours.
 *
 * @return void
 */
function s6t_cart_fix_no_cache_on_ajax() {
	if ( s6t_cart_fix_disabled( 'nocache' ) || ! s6t_cart_fix_is_wc_ajax() ) {
		return;
	}

	s6t_cart_fix_declare_no_cache( 'Smart6teme : réponse AJAX WooCommerce' );

	if ( ! headers_sent() ) {
		nocache_headers();
		header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0' );
		header( 'X-S6T-Cart-Fix: no-store' );
	}
}

add_action( 'template_redirect', 's6t_cart_fix_no_cache_on_cart_pages', 0 );

/**
 * Interdit la mise en cache des pages panier / commande / compte.
 *
 * Ces pages contiennent l'état du panier : mises en cache, elles affichent
 * l'état d'un autre visiteur ou un panier vide.
 *
 * @return void
 */
function s6t_cart_fix_no_cache_on_cart_pages() {
	if ( s6t_cart_fix_disabled( 'nocache' ) || ! function_exists( 'is_cart' ) ) {
		return;
	}

	if ( is_cart() || is_checkout() || is_account_page() ) {
		s6t_cart_fix_declare_no_cache( 'Smart6teme : page panier / commande / compte' );
	}
}

/**
 * Pose les constantes et signaux « ne pas mettre en cache » compris par les
 * principaux plugins de cache (LiteSpeed — préinstallé sur Hostinger —,
 * WP Rocket, W3 Total Cache, WP Super Cache…).
 *
 * @param string $reason Motif, journalisé par LiteSpeed.
 * @return void
 */
function s6t_cart_fix_declare_no_cache( $reason ) {
	foreach ( array( 'DONOTCACHEPAGE', 'DONOTCACHEOBJECT', 'DONOTCACHEDB', 'DONOTMINIFY', 'DONOTROCKETOPTIMIZE' ) as $const ) {
		if ( ! defined( $const ) ) {
			define( $const, true );
		}
	}

	do_action( 'litespeed_control_set_nocache', $reason );
}

/* -------------------------------------------------------------------------
 * Correctif 2 — Garantir le chargement de wc-cart-fragments.js
 *
 * Sans ce script, le mini-panier ne peut structurellement pas se mettre à
 * jour sans rechargement. Beaucoup de thèmes et d'extensions « performance »
 * le retirent pour gagner quelques dizaines de millisecondes.
 * ---------------------------------------------------------------------- */

add_action( 'wp_enqueue_scripts', 's6t_cart_fix_enqueue_assets', 999 );

/**
 * Réinjecte les fragments de panier et charge le script correctif.
 *
 * @return void
 */
function s6t_cart_fix_enqueue_assets() {
	if ( is_admin() || ! function_exists( 'WC' ) ) {
		return;
	}

	if ( ! s6t_cart_fix_disabled( 'fragments' )
		&& wp_script_is( 'wc-cart-fragments', 'registered' )
		&& ! wp_script_is( 'wc-cart-fragments', 'enqueued' ) ) {
		wp_enqueue_script( 'wc-cart-fragments' );
	}

	wp_enqueue_script(
		's6t-cart-fix',
		S6T_CART_FIX_URL . 'assets/js/cart-fix.js',
		array( 'jquery' ),
		S6T_CART_FIX_VERSION,
		true
	);

	wp_localize_script(
		's6t-cart-fix',
		'S6TCartFix',
		array(
			/**
			 * Sélecteurs CSS des pastilles « nombre d'articles » à synchroniser.
			 *
			 * @param string[] $selectors Liste de sélecteurs.
			 */
			'selectors' => array_values( (array) apply_filters( 's6t_cart_fix_count_selectors', s6t_cart_fix_default_count_selectors() ) ),
			/**
			 * Délai (ms) au-delà duquel un bouton encore en « loading » est débloqué.
			 *
			 * @param int $ms Délai en millisecondes.
			 */
			'watchdog'  => (int) apply_filters( 's6t_cart_fix_watchdog_ms', 6000 ),
			'debug'     => (bool) ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
		)
	);
}

/**
 * Sélecteurs par défaut des compteurs de panier des thèmes courants.
 *
 * @return string[]
 */
function s6t_cart_fix_default_count_selectors() {
	return array(
		'.cart-contents-count',        // Storefront.
		'.ast-cart-menu-wrap .count',  // Astra.
		'.ast-cart-count',             // Astra.
		'.wd-cart-number',             // WoodMart.
		'.cart-icon strong',           // Flatsome.
		'.ct-cart-count',              // Blocksy.
		'.oceanwp-cart-count',         // OceanWP.
		'.kadence-cart-total',         // Kadence.
		'.wc-block-mini-cart__badge',  // Blocs WooCommerce.
		'.elementor-button-icon-qty',  // Widget « Menu Cart » d'Elementor.
		'.xoo-wsc-items-count',        // Side Cart WooCommerce.
		'.cart-count',
		'.cart_count',
		'.cart-items-count',
		'.mini-cart-count',
		'.header-cart-count',
		'.menu-item-cart .count',
		'.site-header-cart .count',
	);
}

/* -------------------------------------------------------------------------
 * Correctif 3 — Exposer le nombre d'articles comme fragment WooCommerce
 *
 * WooCommerce ne remplace en AJAX que les éléments déclarés comme fragments.
 * Un compteur ajouté par le thème ou un constructeur de page en dehors de
 * div.widget_shopping_cart_content n'est donc jamais mis à jour. On publie
 * ici une source de vérité que le JS recopie dans les pastilles du thème.
 * ---------------------------------------------------------------------- */

add_filter( 'woocommerce_add_to_cart_fragments', 's6t_cart_fix_register_fragment' );

/**
 * Ajoute le porteur de données au tableau des fragments.
 *
 * @param array $fragments Fragments WooCommerce.
 * @return array
 */
function s6t_cart_fix_register_fragment( $fragments ) {
	$fragments['span.s6t-cart-data'] = s6t_cart_fix_data_markup();

	return $fragments;
}

add_action( 'wp_footer', 's6t_cart_fix_print_data_holder', 5 );

/**
 * Imprime le porteur de données : sans lui dans le DOM, le fragment
 * correspondant n'a rien à remplacer.
 *
 * @return void
 */
function s6t_cart_fix_print_data_holder() {
	if ( is_admin() || ! function_exists( 'WC' ) ) {
		return;
	}

	echo s6t_cart_fix_data_markup(); // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- balisage construit avec esc_attr().
}

/**
 * Balisage du porteur de données de panier.
 *
 * @return string
 */
function s6t_cart_fix_data_markup() {
	$count = 0;
	$total = '';

	if ( function_exists( 'WC' ) && WC()->cart ) {
		$count = (int) WC()->cart->get_cart_contents_count();
		$total = wp_strip_all_tags( WC()->cart->get_cart_subtotal() );
	}

	return sprintf(
		'<span class="s6t-cart-data" data-count="%1$s" data-total="%2$s" aria-hidden="true" style="display:none!important"></span>',
		esc_attr( $count ),
		esc_attr( $total )
	);
}

/* -------------------------------------------------------------------------
 * Correctif 4 — Sortir les scripts critiques des optimisations JS
 *
 * Le report (defer), le retard (delay) ou la fusion des scripts casse
 * régulièrement l'ajout au panier, qui suppose jQuery déjà initialisé.
 * Pour LiteSpeed Cache, les exclusions se règlent dans l'interface :
 * voir README.md, étape 2.
 * ---------------------------------------------------------------------- */

add_filter( 'rocket_exclude_js', 's6t_cart_fix_critical_js_paths' );
add_filter( 'rocket_exclude_defer_js', 's6t_cart_fix_critical_js_paths' );
add_filter( 'rocket_delay_js_exclusions', 's6t_cart_fix_critical_js_paths' );

/**
 * Chemins des scripts à ne jamais différer, retarder ni fusionner.
 *
 * @param array $excluded Exclusions existantes.
 * @return array
 */
function s6t_cart_fix_critical_js_paths( $excluded ) {
	return array_merge(
		(array) $excluded,
		array(
			'/wp-includes/js/jquery/jquery.min.js',
			'/woocommerce/assets/js/frontend/add-to-cart',
			'/woocommerce/assets/js/frontend/cart-fragments',
			'/woocommerce/assets/js/frontend/woocommerce',
			'/woocommerce/assets/js/jquery-blockui/',
			'/smart6teme-cart-fix/assets/js/cart-fix.js',
		)
	);
}

add_filter( 'autoptimize_filter_js_exclude', 's6t_cart_fix_autoptimize_excludes' );

/**
 * Mêmes exclusions pour Autoptimize (liste séparée par des virgules).
 *
 * @param string $exclude Exclusions existantes.
 * @return string
 */
function s6t_cart_fix_autoptimize_excludes( $exclude ) {
	return $exclude . ', jquery.min.js, cart-fragments, add-to-cart, jquery.blockUI, cart-fix.js';
}

/* -------------------------------------------------------------------------
 * Activation
 * ---------------------------------------------------------------------- */

register_activation_hook( __FILE__, 's6t_cart_fix_on_activate' );

/**
 * Vide le cache à l'activation : les pages déjà stockées gardent l'ancien
 * comportement tant qu'elles n'ont pas été purgées.
 *
 * @return void
 */
function s6t_cart_fix_on_activate() {
	do_action( 'litespeed_purge_all' );

	if ( function_exists( 'rocket_clean_domain' ) ) {
		rocket_clean_domain();
	}

	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
	}

	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}
}
