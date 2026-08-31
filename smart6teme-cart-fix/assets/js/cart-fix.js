/**
 * Smart6teme – Correctif panier AJAX
 *
 * Deux schémas d'ajout au panier coexistent sur les boutiques WooCommerce, et
 * ce script traite les deux :
 *
 *  - le schéma natif  : POST /?wc-ajax=add_to_cart, réponse JSON { fragments } ;
 *  - le schéma classique : GET /produit/?add-to-cart=123, réponse HTML — voire
 *    une redirection 302 vers /checkout/. C'est celui de smart6teme.com : le
 *    thème le déclenche en XHR au lieu de laisser le navigateur naviguer, si
 *    bien que la page de commande est téléchargée puis jetée, que le bouton
 *    reste sur « loading » et que le mini-panier ne bouge jamais.
 *
 * Rôles : débloquer le bouton, rafraîchir le compteur sans rechargement, et
 * journaliser la cause exacte via ?s6t_cart_debug=1.
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
	var MODE      = cfg.buyMode || 'stay';
	var SELECTORS = ( cfg.selectors && cfg.selectors.length ) ? cfg.selectors.join( ',' ) : '';
	var STUCK     = '.add_to_cart_button.loading, .single_add_to_cart_button.loading, .ajax_add_to_cart.loading, button.loading, .button.loading';
	var NUMERIC   = /^\(?\s*\d+\s*\)?$/;

	function log() {
		if ( DEBUG && window.console && window.console.log ) {
			window.console.log.apply( window.console, [ '%c[s6t-cart]', 'color:#7f54b3;font-weight:bold' ].concat( [].slice.call( arguments ) ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Compteur
	 * ------------------------------------------------------------------ */

	/**
	 * Nombre d'articles publié par le serveur dans span.s6t-cart-data.
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
	 * texte est vide ou purement numérique, pour ne jamais détruire un
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

		// Storefront et plusieurs thèmes rendent le compteur en CSS :
		// content: attr(data-cart-items-count).
		$( '[data-cart-items-count]' ).attr( 'data-cart-items-count', n );

		$( document.body ).trigger( 's6t_cart_count_updated', [ n ] );
		log( 'compteur synchronisé :', n );
	}

	/**
	 * Redemande l'état du panier au serveur, puis repeint le compteur.
	 *
	 * Passe par les fragments WooCommerce quand ils sont disponibles ; sinon
	 * interroge le point d'entrée léger du plugin, ce qui permet au compteur
	 * de suivre même sur une boutique où wc-cart-fragments.js reste absent.
	 *
	 * @return {void}
	 */
	function refreshCart() {
		if ( 'undefined' !== typeof window.wc_cart_fragments_params ) {
			$( document.body ).trigger( 'wc_fragment_refresh' );
			return;
		}

		if ( ! cfg.stateUrl ) {
			log( 'ni fragments ni point d\'entrée de repli : compteur non rafraîchi' );
			return;
		}

		$.getJSON( cfg.stateUrl )
			.done( function ( data ) {
				if ( ! data || 'undefined' === typeof data.count ) {
					return;
				}

				$( '.s6t-cart-data' )
					.attr( 'data-count', data.count )
					.attr( 'data-total', data.total || '' );

				log( 'état du panier récupéré en repli :', data );
				paintCount();
			} )
			.fail( function ( xhr ) {
				log( 'repli indisponible —', xhr.status );
			} );
	}

	/**
	 * Retire l'état « loading » resté collé.
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

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Événements WooCommerce natifs
	 * ------------------------------------------------------------------ */

	$( document.body ).on(
		'added_to_cart removed_from_cart wc_fragments_refreshed wc_fragments_loaded updated_cart_totals',
		function ( event, fragments, cartHash, $button ) {
			if ( $button && $button.length ) {
				$button.removeClass( 'loading' );
			}

			paintCount();
		}
	);

	/* ---------------------------------------------------------------------
	 * Surveillance des requêtes d'ajout au panier
	 * ------------------------------------------------------------------ */

	var lastAdd = { url: '', at: 0 };

	/**
	 * Signale deux ajouts identiques rapprochés : le gestionnaire de clic est
	 * alors probablement lié deux fois, et le produit ajouté en double.
	 *
	 * @param {string} url URL de la requête.
	 * @return {void}
	 */
	function warnOnDuplicate( url ) {
		var now = ( new Date() ).getTime();

		if ( url === lastAdd.url && ( now - lastAdd.at ) < 1500 ) {
			log( 'ALERTE : deuxième requête d\'ajout identique en', ( now - lastAdd.at ), 'ms — le gestionnaire de clic est lié deux fois, le produit est probablement ajouté en double.' );
		}

		lastAdd.url = url;
		lastAdd.at  = now;
	}

	$( document ).ajaxComplete( function ( event, xhr, settings ) {
		var url     = ( settings && settings.url ) || '';
		var native  = -1 !== url.indexOf( 'wc-ajax=add_to_cart' );
		var classic = /[?&]add-to-cart=/.test( url );

		if ( ! native && ! classic ) {
			return;
		}

		warnOnDuplicate( url );

		if ( native ) {
			// Schéma natif : la réponse doit être du JSON contenant « fragments ».
			// Sinon (page HTML mise en cache, 403 d'un pare-feu, avertissement PHP
			// en tête de réponse), le gestionnaire WooCommerce lève une exception
			// avant de retirer .loading.
			var payload = null;

			try {
				payload = xhr.responseJSON || JSON.parse( xhr.responseText || '' );
			} catch ( err ) {
				payload = null;
			}

			if ( payload && ( payload.fragments || payload.error ) ) {
				return;
			}

			log(
				'réponse ?wc-ajax=add_to_cart inattendue —',
				'statut', xhr.status,
				'type', ( xhr.getResponseHeader ? xhr.getResponseHeader( 'content-type' ) : '?' ),
				'début', ( xhr.responseText || '' ).slice( 0, 300 )
			);
			unstick( 'réponse native invalide' );
			refreshCart();

			return;
		}

		// Schéma classique déclenché en XHR par le thème. La réponse est du
		// HTML — souvent la page de commande, obtenue après une redirection 302
		// que le XHR a suivie en silence. Le produit est bien au panier, mais
		// rien ne se passe côté interface.
		var bytes = ( xhr.responseText || '' ).length;

		log( 'ajout « classique » en XHR :', url, '→ statut', xhr.status, ',', bytes, 'octets téléchargés puis jetés' );

		if ( 'off' === MODE ) {
			return;
		}

		unstick( 'ajout classique terminé' );

		if ( 'checkout' === MODE && cfg.checkoutUrl ) {
			log( 'mode « checkout » : navigation vers', cfg.checkoutUrl );
			window.location.assign( cfg.checkoutUrl );
			return;
		}

		refreshCart();
	} );

	// Une exception levée dans le callback de succès peut empêcher jQuery
	// d'émettre ajaxComplete : on double la protection par un délai de garde.
	$( document ).on( 'click', '.ajax_add_to_cart, .add_to_cart_button, .single_add_to_cart_button', function () {
		window.setTimeout( function () {
			if ( unstick( 'délai de ' + WATCHDOG + ' ms dépassé' ) ) {
				refreshCart();
			}
		}, WATCHDOG );
	} );

	/* ---------------------------------------------------------------------
	 * Retour arrière navigateur (bfcache)
	 * ------------------------------------------------------------------ */

	window.addEventListener( 'pageshow', function ( event ) {
		if ( event.persisted ) {
			log( 'page restaurée depuis le bfcache — rafraîchissement du panier' );
			refreshCart();
		}
	} );

	/* ---------------------------------------------------------------------
	 * Amorçage et auto-diagnostic
	 * ------------------------------------------------------------------ */

	$( function () {
		paintCount();

		if ( 'undefined' === typeof window.wc_cart_fragments_params ) {
			log( 'ALERTE : wc-cart-fragments.js n\'est pas chargé. WooCommerce ne l\'enfile pas quand l\'option « rediriger vers le panier après un ajout » est active. Repli sur le point d\'entrée du plugin.' );
		}

		if ( null === cartCount() ) {
			log( 'ALERTE : span.s6t-cart-data absent du DOM. Le thème n\'appelle probablement pas wp_footer().' );
		}

		log( 'mode bouton « Acheter » :', MODE );
	} );
} )( window.jQuery );
