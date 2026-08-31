<?php
/**
 * Page de diagnostic : WooCommerce → Diagnostic panier.
 *
 * Exécute depuis le serveur les vérifications qu'on ferait à la main dans
 * l'onglet Réseau du navigateur, et pointe la cause du mini-panier qui ne se
 * met à jour qu'après rechargement.
 *
 * @package Smart6teme_Cart_Fix
 */

defined( 'ABSPATH' ) || exit;

/**
 * Diagnostic du panier AJAX.
 */
class S6T_Cart_Diagnostic {

	const CAPABILITY = 'manage_woocommerce';
	const SLUG       = 's6t-cart-diagnostic';

	/**
	 * Accroche les hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 99 );
	}

	/**
	 * Ajoute la page sous le menu WooCommerce.
	 *
	 * @return void
	 */
	public static function register_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Diagnostic panier', 's6t-cart-fix' ),
			__( 'Diagnostic panier', 's6t-cart-fix' ),
			self::CAPABILITY,
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Affiche le rapport.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Accès refusé.', 's6t-cart-fix' ) );
		}

		$rows = self::run_checks();

		echo '<div class="wrap"><h1>' . esc_html__( 'Diagnostic du panier AJAX', 's6t-cart-fix' ) . '</h1>';
		echo '<p>' . esc_html__( 'Chaque ligne teste une cause connue du bouton « Acheter » bloqué et du mini-panier qui ne se met à jour qu\'après rechargement.', 's6t-cart-fix' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr>';
		echo '<th style="width:60px">' . esc_html__( 'État', 's6t-cart-fix' ) . '</th>';
		echo '<th style="width:280px">' . esc_html__( 'Vérification', 's6t-cart-fix' ) . '</th>';
		echo '<th>' . esc_html__( 'Résultat', 's6t-cart-fix' ) . '</th>';
		echo '</tr></thead><tbody>';

		$icons = array(
			'ok'   => '<span style="color:#008a20;font-size:18px">&#10003;</span>',
			'warn' => '<span style="color:#bd8600;font-size:18px">&#9888;</span>',
			'fail' => '<span style="color:#d63638;font-size:18px">&#10007;</span>',
		);

		foreach ( $rows as $row ) {
			$status = isset( $icons[ $row['status'] ] ) ? $row['status'] : 'warn';

			echo '<tr>';
			echo '<td>' . $icons[ $status ] . '</td>'; // phpcs:ignore WordPress.Security.EscapingOutput.OutputNotEscaped -- balisage constant.
			echo '<td><strong>' . esc_html( $row['label'] ) . '</strong></td>';
			echo '<td>' . wp_kses_post( $row['detail'] ) . '</td>';
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	/**
	 * Exécute toutes les vérifications.
	 *
	 * @return array[] Lignes { status, label, detail }.
	 */
	public static function run_checks() {
		$rows = array();

		$rows[] = self::check_woocommerce();
		$rows[] = self::check_ajax_option();
		$rows[] = self::check_redirect_after_add();
		$rows[] = self::check_fragments_endpoint();
		$rows[] = self::check_front_page_scripts();
		$rows[] = self::check_optimization_plugins();
		$rows[] = self::check_persistent_cache();

		return $rows;
	}

	/**
	 * WooCommerce est-il actif ?
	 *
	 * @return array
	 */
	private static function check_woocommerce() {
		if ( ! function_exists( 'WC' ) ) {
			return self::row( 'fail', 'WooCommerce', 'WooCommerce n\'est pas actif : le reste du diagnostic n\'a pas de sens.' );
		}

		return self::row( 'ok', 'WooCommerce', 'Actif, version <code>' . esc_html( WC()->version ) . '</code>.' );
	}

	/**
	 * L'ajout au panier en AJAX est-il activé dans les réglages ?
	 *
	 * @return array
	 */
	private static function check_ajax_option() {
		$enabled = 'yes' === get_option( 'woocommerce_enable_ajax_add_to_cart' );

		if ( $enabled ) {
			return self::row( 'ok', 'Ajout au panier en AJAX', 'Activé (WooCommerce → Réglages → Produits).' );
		}

		return self::row(
			'warn',
			'Ajout au panier en AJAX',
			'Désactivé. Chaque clic sur « Acheter » recharge la page. Si vous attendez une mise à jour sans rechargement, activez l\'option dans WooCommerce → Réglages → Produits.'
		);
	}

	/**
	 * La redirection après ajout au panier prive-t-elle le site des fragments ?
	 *
	 * WC_Frontend_Scripts n'enfile wc-cart-fragments.js que si cette option est
	 * désactivée : WooCommerce juge inutile de rafraîchir un mini-panier qu'on
	 * s'apprête à quitter. Un thème dont le bouton « Acheter » déclenche un XHR
	 * au lieu de naviguer cumule alors les deux défauts : pas de navigation et
	 * pas de fragments.
	 *
	 * @return array
	 */
	private static function check_redirect_after_add() {
		$redirect = 'yes' === get_option( 'woocommerce_cart_redirect_after_add' );

		if ( ! $redirect ) {
			return self::row( 'ok', 'Redirection après ajout au panier', 'Désactivée : WooCommerce charge wc-cart-fragments.js et le mini-panier peut se mettre à jour sans rechargement.' );
		}

		return self::row(
			'fail',
			'Redirection après ajout au panier',
			'<strong>Activée.</strong> WooCommerce n\'enfile alors volontairement pas <code>wc-cart-fragments.js</code> : sans ce script, le mini-panier ne peut pas se mettre à jour sans rechargement. Si votre bouton « Acheter » déclenche une requête AJAX au lieu de naviguer, la redirection est en plus avalée et le bouton reste bloqué.<br>Correction : décochez <em>WooCommerce → Réglages → Produits → Général → « Rediriger vers le panier après un ajout »</em>, ou ajoutez <code>define( \'S6T_CART_FIX_NO_REDIRECT_AFTER_ADD\', true );</code> dans <code>wp-config.php</code>.'
		);
	}

	/**
	 * Le point d'entrée des fragments répond-il du JSON non mis en cache ?
	 *
	 * Deux appels successifs : si le second est servi par le cache
	 * (x-litespeed-cache: hit), la cause du bug est identifiée.
	 *
	 * @return array
	 */
	private static function check_fragments_endpoint() {
		$url = add_query_arg( 'wc-ajax', 'get_refreshed_fragments', home_url( '/' ) );

		$first  = self::fetch( $url );
		$second = self::fetch( $url );

		if ( is_wp_error( $first ) ) {
			return self::row(
				'warn',
				'Point d\'entrée ?wc-ajax=get_refreshed_fragments',
				'Requête en boucle locale impossible depuis le serveur (' . esc_html( $first->get_error_message() ) . '). Testez l\'URL à la main : <code>' . esc_html( $url ) . '</code>'
			);
		}

		$code    = wp_remote_retrieve_response_code( $first );
		$type    = wp_remote_retrieve_header( $first, 'content-type' );
		$body    = wp_remote_retrieve_body( $first );
		$decoded = json_decode( $body, true );

		$detail  = 'URL : <code>' . esc_html( $url ) . '</code><br>';
		$detail .= 'Code HTTP : <code>' . esc_html( $code ) . '</code> — Content-Type : <code>' . esc_html( $type ? $type : 'absent' ) . '</code><br>';
		$detail .= 'En-têtes de cache : ' . self::cache_headers_summary( $first ) . ' puis ' . self::cache_headers_summary( $second ) . '<br>';

		if ( 200 !== (int) $code ) {
			return self::row( 'fail', 'Point d\'entrée ?wc-ajax=get_refreshed_fragments', $detail . '<strong>Le point d\'entrée ne répond pas 200.</strong> Un pare-feu applicatif ou une règle de réécriture bloque les URL <code>?wc-ajax=</code>.' );
		}

		if ( ! is_array( $decoded ) || ! isset( $decoded['fragments'] ) ) {
			return self::row(
				'fail',
				'Point d\'entrée ?wc-ajax=get_refreshed_fragments',
				$detail . '<strong>La réponse n\'est pas le JSON attendu</strong> (aucune clé <code>fragments</code>). C\'est exactement ce qui bloque le bouton « Acheter » : le script WooCommerce échoue en tentant de lire les fragments. Début de la réponse reçue :<br><code>' . esc_html( substr( trim( $body ), 0, 300 ) ) . '</code>'
			);
		}

		if ( self::looks_cached( $second ) ) {
			return self::row(
				'fail',
				'Point d\'entrée ?wc-ajax=get_refreshed_fragments',
				$detail . '<strong>La réponse est servie depuis le cache au second appel.</strong> Les fragments sont donc figés pour tous les visiteurs. Excluez <code>wc-ajax</code> du cache (voir README, étape 2).'
			);
		}

		return self::row( 'ok', 'Point d\'entrée ?wc-ajax=get_refreshed_fragments', $detail . 'JSON valide et non mis en cache.' );
	}

	/**
	 * La page d'accueil charge-t-elle bien wc-cart-fragments.js, sans defer ?
	 *
	 * @return array
	 */
	private static function check_front_page_scripts() {
		$response = self::fetch( home_url( '/' ) );

		if ( is_wp_error( $response ) ) {
			return self::row( 'warn', 'Scripts de la page d\'accueil', 'Page inaccessible depuis le serveur (' . esc_html( $response->get_error_message() ) . ').' );
		}

		$html = wp_remote_retrieve_body( $response );

		if ( false === strpos( $html, 'cart-fragments' ) ) {
			return self::row(
				'fail',
				'Scripts de la page d\'accueil',
				'<strong><code>wc-cart-fragments.js</code> n\'est pas chargé.</strong> Sans ce script, le mini-panier ne peut pas se mettre à jour sans rechargement. Une extension de performance ou le thème le retire : ce plugin le réinjecte, purgez le cache puis revérifiez.'
			);
		}

		$deferred = (bool) preg_match( '#<script[^>]*cart-fragments[^>]*(defer|data-(cfasync|rocketlazyload|no-optimize|deferred))#i', $html );

		if ( $deferred ) {
			return self::row(
				'warn',
				'Scripts de la page d\'accueil',
				'<code>wc-cart-fragments.js</code> est chargé mais <strong>différé ou retardé</strong> par une extension d\'optimisation. C\'est une cause fréquente de bouton bloqué : ajoutez-le aux exclusions JS (voir README, étape 2).'
			);
		}

		return self::row( 'ok', 'Scripts de la page d\'accueil', '<code>wc-cart-fragments.js</code> est chargé normalement. En-têtes de cache de la page : ' . self::cache_headers_summary( $response ) );
	}

	/**
	 * Quels plugins de cache / optimisation sont actifs ?
	 *
	 * @return array
	 */
	private static function check_optimization_plugins() {
		$known = array(
			'litespeed-cache/litespeed-cache.php'         => 'LiteSpeed Cache',
			'wp-rocket/wp-rocket.php'                     => 'WP Rocket',
			'autoptimize/autoptimize.php'                 => 'Autoptimize',
			'w3-total-cache/w3-total-cache.php'           => 'W3 Total Cache',
			'wp-super-cache/wp-cache.php'                 => 'WP Super Cache',
			'wp-optimize/wp-optimize.php'                 => 'WP-Optimize',
			'sg-cachepress/sg-cachepress.php'             => 'SG Optimizer',
			'hostinger/hostinger.php'                     => 'Hostinger Tools',
			'hostinger-easy-onboarding/hostinger-easy-onboarding.php' => 'Hostinger Easy Onboarding',
			'nitropack/main.php'                          => 'NitroPack',
			'flying-press/flying-press.php'               => 'FlyingPress',
			'perfmatters/perfmatters.php'                 => 'Perfmatters',
		);

		$active = (array) get_option( 'active_plugins', array() );
		$found  = array();

		foreach ( $known as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				$found[] = $name;
			}
		}

		if ( ! $found ) {
			return self::row( 'ok', 'Extensions de cache / optimisation', 'Aucune extension connue de ce type n\'est active.' );
		}

		return self::row(
			'warn',
			'Extensions de cache / optimisation',
			'Actives : <strong>' . esc_html( implode( ', ', $found ) ) . '</strong>. Ce sont les candidates les plus probables : appliquez-leur les exclusions du README, étape 2.'
		);
	}

	/**
	 * Cache de pages / d'objets persistant au niveau du fichier.
	 *
	 * @return array
	 */
	private static function check_persistent_cache() {
		$bits = array();

		$bits[] = 'WP_CACHE : <code>' . ( defined( 'WP_CACHE' ) && WP_CACHE ? 'true' : 'false' ) . '</code>';
		$bits[] = 'advanced-cache.php : <code>' . ( file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ? 'présent' : 'absent' ) . '</code>';
		$bits[] = 'object-cache.php : <code>' . ( file_exists( WP_CONTENT_DIR . '/object-cache.php' ) ? 'présent' : 'absent' ) . '</code>';

		$page_cache = ( defined( 'WP_CACHE' ) && WP_CACHE ) && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' );

		return self::row(
			$page_cache ? 'warn' : 'ok',
			'Cache de pages au niveau fichier',
			implode( ' — ', $bits ) . ( $page_cache ? '<br>Un cache de pages est en place : purgez-le après chaque modification, sinon les anciennes pages continuent d\'être servies.' : '' )
		);
	}

	/* --------------------------------------------------------------------
	 * Utilitaires
	 * ----------------------------------------------------------------- */

	/**
	 * Construit une ligne du tableau de résultats.
	 *
	 * @param string $status « ok », « warn » ou « fail ».
	 * @param string $label  Intitulé de la vérification.
	 * @param string $detail Détail, HTML autorisé (filtré par wp_kses_post).
	 * @return array
	 */
	private static function row( $status, $label, $detail ) {
		return array(
			'status' => $status,
			'label'  => $label,
			'detail' => $detail,
		);
	}

	/**
	 * Requête HTTP en boucle locale.
	 *
	 * @param string $url URL à appeler.
	 * @return array|WP_Error
	 */
	private static function fetch( $url ) {
		return wp_remote_get(
			$url,
			array(
				'timeout'     => 20,
				'redirection' => 3,
				'user-agent'  => 'Mozilla/5.0 (compatible; Smart6teme-Cart-Diagnostic/' . S6T_CART_FIX_VERSION . ')',
				'cookies'     => array(),
			)
		);
	}

	/**
	 * Résumé lisible des en-têtes de cache d'une réponse.
	 *
	 * @param array $response Réponse wp_remote_get().
	 * @return string
	 */
	private static function cache_headers_summary( $response ) {
		if ( is_wp_error( $response ) ) {
			return '<em>indisponible</em>';
		}

		$interesting = array( 'x-litespeed-cache', 'x-litespeed-cache-control', 'cf-cache-status', 'x-cache', 'x-cache-status', 'age', 'cache-control' );
		$parts       = array();

		foreach ( $interesting as $header ) {
			$value = wp_remote_retrieve_header( $response, $header );

			if ( $value ) {
				$parts[] = '<code>' . esc_html( $header . ': ' . ( is_array( $value ) ? implode( ', ', $value ) : $value ) ) . '</code>';
			}
		}

		return $parts ? implode( ' ', $parts ) : '<em>aucun en-tête de cache</em>';
	}

	/**
	 * La réponse a-t-elle été servie par un cache ?
	 *
	 * @param array|WP_Error $response Réponse wp_remote_get().
	 * @return bool
	 */
	private static function looks_cached( $response ) {
		if ( is_wp_error( $response ) ) {
			return false;
		}

		foreach ( array( 'x-litespeed-cache', 'x-cache', 'x-cache-status', 'cf-cache-status' ) as $header ) {
			$value = wp_remote_retrieve_header( $response, $header );

			if ( $value && false !== stripos( is_array( $value ) ? implode( ',', $value ) : $value, 'hit' ) ) {
				return true;
			}
		}

		$age = wp_remote_retrieve_header( $response, 'age' );

		return $age && (int) $age > 0;
	}
}

S6T_Cart_Diagnostic::init();
