/**
 * Smart6teme – Correctif panier AJAX
 *
 * Trois rôles :
 *  1. synchroniser la pastille « nombre d'articles » du thème avec l'état réel
 *     du panier renvoyé par les fragments WooCommerce ;
 *  2. débloquer le bouton « Acheter » resté sur .loading quand la réponse
 *     ?wc-ajax=add_to_cart n'est pas le JSON attendu (page mise en cache,
 *     avertissement PHP en tête de réponse, erreur 403 d'un pare-feu…) ;
 *  3. signaler dans la console la cause exacte, via ?s6t_cart_debug=1.
 *
 * @package Smart6teme_Cart_Fix
 */

/* global jQuery */
( function ( $ ) {
	'use strict';

	if ( typeof $ === 'undefined' ) {
		return;
	}

	var cfg       = window.S6TCartFix || {};
	var DEBUG     = !! cfg.debug || /[?&]s6t_cart_debug=1/.test( window.location.search );
	var WATCHDOG  = parseInt( cfg.watchdog, 10 ) || 6000;
	var SELECTORS = ( cfg.selectors && cfg.selectors.length ) ? cfg.selectors.join( ',' ) : '';
	var STUCK     = '.add_to_cart_button.loading, .single_add_to_cart_button.loading, .ajax_add_to_cart.loading';
	var NUMERIC   = /^\(?\s*\d+\s*\)?$/;

	function log() {
		if ( DEBUG && window.console && window.console.log ) {
			window.console.log.apply( window.console, [ '%c[s6t-cart]', 'color:#7f54b3;font-weight:bold' ].concat( [].slice.call( arguments ) ) );
		}
	}

	/**
	 * Nombre d'articles publié par le serveur dans le fragment span.s6t-cart-data.
	 *
	 * @return {?number} Le compte, ou null si le porteur est absent.
	 */
	function cartCount() {
		var $data = $( '.s6t-cart-data' ).last();

		if ( ! $data.length ) {
			return null;
		}

		var n = parseInt( $data.attr( 'data-count' ), 10 );

		return isNaN( n ) ? null : n;
	}

	/**
	 * Recopie ce compte dans les pastilles du thème.
	 *
	 * Prudence volontaire : on ne réécrit que les éléments sans enfant dont le
	 * texte actuel est vide ou purement numérique, pour ne jamais détruire un
	 * balisage riche (icône, libellé, montant).
	 *
	 * @return {void}
	 */
	function paintCount() {
		var n = cartCount();

		if ( null === n ) {
			return;
		}

		if ( SELECTORS ) {
			$( SELECTORS ).each( function () {
				var $el = $( this );

				if ( $el.children().length ) {
					return;
				}

				var txt = $.trim( $el.text() );

				if ( '' !== txt && ! NUMERIC.test( txt ) ) {
					return;
				}

				var next = ( 0 === txt.indexOf( '(' ) ) ? '(' + n + ')' : String( n );

				if ( txt !== next ) {
					$el.text( next );
				}
			} );
		}

		// Storefront et plusieurs thèmes affichent le compteur via CSS :
		// content: attr(data-cart-items-count).
		$( '[data-cart-items-count]' ).attr( 'data-cart-items-count', n );

		$( document.body ).trigger( 's6t_cart_count_updated', [ n ] );
		log( 'compteur synchronisé :', n );
	}

	/**
	 * Retire l'état « loading » resté collé et redemande les fragments.
	 *
	 * @param {string} reason Motif, pour le journal.
	 * @return {boolean} Vrai si au moins un bouton était bloqué.
	 */
	function unstick( reason ) {
		var $stuck = $( STUCK );

		if ( ! $stuck.length ) {
			return false;
		}

		log( 'bouton débloqué —', reason, $stuck );
		$stuck.removeClass( 'loading' );
		$( document.body ).trigger( 'wc_fragment_refresh' );

		return true;
	}

	/* --- Événements WooCommerce ------------------------------------------ */

	$( document.body ).on(
		'added_to_cart removed_from_cart wc_fragments_refreshed wc_fragments_loaded updated_cart_totals',
		function ( event, fragments, cartHash, $button ) {
			if ( $button && $button.length ) {
				$button.removeClass( 'loading' );
			}

			paintCount();
		}
	);

	/* --- Filet de sécurité : réponse AJAX inexploitable ------------------ */

	// Le gestionnaire WooCommerce itère sur response.fragments. Si la réponse
	// n'est pas du JSON (page HTML servie par le cache, 403 du pare-feu…),
	// il lève une exception avant de retirer .loading : le bouton reste figé
	// et le mini-panier n'est jamais mis à jour, alors même que le produit a
	// bien été ajouté côté serveur.
	$( document ).ajaxComplete( function ( event, xhr, settings ) {
		var url = ( settings && settings.url ) || '';

		if ( -1 === url.indexOf( 'wc-ajax=add_to_cart' ) ) {
			return;
		}

		var body    = xhr.responseText || '';
		var payload = null;

		try {
			payload = xhr.responseJSON || JSON.parse( body );
		} catch ( err ) {
			payload = null;
		}

		if ( payload && ( payload.fragments || payload.error ) ) {
			return; // Réponse normale, rien à faire.
		}

		log(
			'réponse ?wc-ajax=add_to_cart inattendue —',
			'statut', xhr.status,
			'type', ( xhr.getResponseHeader ? xhr.getResponseHeader( 'content-type' ) : '?' ),
			'début', body.slice( 0, 300 )
		);
		unstick( 'réponse invalide' );
	} );

	// Une exception levée dans le callback de succès peut empêcher jQuery
	// d'émettre ajaxComplete : on double la protection par un délai de garde.
	$( document ).on( 'click', '.ajax_add_to_cart, .add_to_cart_button', function () {
		window.setTimeout( function () {
			unstick( 'délai de ' + WATCHDOG + ' ms dépassé' );
		}, WATCHDOG );
	} );

	/* --- Retour arrière navigateur (bfcache) ----------------------------- */

	window.addEventListener( 'pageshow', function ( event ) {
		if ( event.persisted ) {
			log( 'page restaurée depuis le bfcache — rafraîchissement des fragments' );
			$( document.body ).trigger( 'wc_fragment_refresh' );
		}
	} );

	/* --- Amorçage et auto-diagnostic ------------------------------------- */

	$( function () {
		paintCount();

		if ( 'undefined' === typeof window.wc_cart_fragments_params ) {
			log( 'ALERTE : wc-cart-fragments.js n\'est pas chargé. Sans lui, le mini-panier ne peut pas se mettre à jour sans rechargement.' );
		}

		if ( null === cartCount() ) {
			log( 'ALERTE : span.s6t-cart-data absent du DOM. Le thème n\'appelle probablement pas wp_footer().' );
		}
	} );
} )( window.jQuery );
