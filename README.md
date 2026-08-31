# smart6teme.com — Correctif « bouton Acheter bloqué / panier non mis à jour »

## Le symptôme

Au clic sur **Acheter** :

1. le message « ajouté au panier » s'affiche ;
2. le bouton reste bloqué (spinner qui tourne, état « loading ») ;
3. le mini-panier n'affiche le nouvel article **qu'après rechargement** de la page.

## La cause

Ces trois symptômes n'en font qu'un seul. L'ajout au panier fonctionne **côté
serveur** (d'où le message de succès), mais la réponse de la requête
`/?wc-ajax=add_to_cart` n'est pas le JSON attendu par WooCommerce.

Le script `add-to-cart.js` fait, en simplifiant :

```js
$.post( url, data, function ( response ) {
    $.each( response.fragments, function ( key, value ) {  // ← exception ici
        $( key ).replaceWith( value );
    } );
    $button.removeClass( 'loading' );                       // ← jamais atteint
} );
```

Si `response` n'est pas un objet JSON contenant `fragments`, la boucle lève une
exception : `removeClass('loading')` n'est jamais exécuté (**bouton bloqué**) et
aucun fragment n'est remplacé (**mini-panier figé**). Le produit est pourtant
bien dans la session — il apparaît donc au rechargement suivant.

Deux causes produisent cette réponse invalide, par ordre de fréquence sur un
hébergement Hostinger :

| # | Cause | Pourquoi |
|---|-------|----------|
| 1 | **LiteSpeed Cache met en cache les URL `?wc-ajax=`** | Une page HTML mise en cache est renvoyée à la place du JSON. LiteSpeed est préinstallé sur Hostinger et son *Guest Mode* est particulièrement agressif. |
| 2 | **Optimisation JS (defer / delay / combine)** | `wc-cart-fragments.js` ou `jquery` sont différés ou fusionnés : le gestionnaire d'ajout au panier s'exécute avant que jQuery ne soit prêt. |

Causes secondaires : `wc-cart-fragments.js` désactivé par une extension
« performance », compteur du thème rendu hors des fragments WooCommerce,
pare-feu applicatif renvoyant 403 sur `?wc-ajax=`.

## Étape 1 — Confirmer en 2 minutes

Voir [`DIAGNOSTIC.md`](DIAGNOSTIC.md) : la procédure exacte dans l'onglet
**Réseau** du navigateur. Elle donne la réponse en un coup d'œil et évite de
modifier des réglages au hasard.

## Étape 2 — Corriger les réglages LiteSpeed (Hostinger)

Dans **hPanel → WordPress → Sécurité/Performance**, ou directement dans
l'administration WordPress → **LiteSpeed Cache** :

**Cache → Exclusions → « Ne pas mettre en cache les URI »** — ajouter :

```
/?wc-ajax=
/panier
/commander
/mon-compte
```

*(remplacez `/panier`, `/commander`, `/mon-compte` par les slugs réels de vos
pages Panier, Commande et Mon compte)*

**Cache → Général → Guest Mode** et **Guest Optimization** : **désactiver**.
C'est la cause la plus fréquente d'un panier qui « ne suit pas » sur Hostinger.

**Page Optimization → JS Settings** — passer à **OFF** :

- `JS Combine`
- `Load JS Deferred`
- `JS Delay`

Si vous tenez à les garder actifs, ajoutez plutôt dans
**Page Optimization → Tuning → JS Excludes** *et* **JS Deferred / Delayed Excludes** :

```
jquery.min.js
jquery-migrate
cart-fragments
add-to-cart
jquery.blockUI
woocommerce
cart-fix.js
```

**Puis : LiteSpeed Cache → Toolbox → Purge All.** Sans purge, les pages déjà
stockées conservent l'ancien comportement.

Testez ensuite en **navigation privée** (une session normale peut être exemptée
de cache et masquer le problème).

## Étape 3 — Installer le plugin correctif

Le dossier [`smart6teme-cart-fix/`](smart6teme-cart-fix/) est un plugin
WordPress qui verrouille ces correctifs côté code, pour qu'un réglage remis à
zéro ou une mise à jour ne fasse pas revenir le bug.

Ce qu'il fait :

1. **Interdit la mise en cache** de toutes les réponses `?wc-ajax=` et des pages
   panier / commande / compte (`DONOTCACHEPAGE`, `nocache_headers()`, signal
   `litespeed_control_set_nocache`).
2. **Force le chargement de `wc-cart-fragments.js`** s'il a été retiré.
3. **Publie le nombre d'articles comme fragment WooCommerce** et le recopie dans
   la pastille du thème — y compris quand ce compteur est rendu en dehors de
   `div.widget_shopping_cart_content` et n'est donc jamais mis à jour en AJAX.
4. **Exclut les scripts critiques** des optimisations JS (WP Rocket,
   Autoptimize ; pour LiteSpeed, voir l'étape 2 — ses exclusions se règlent dans
   l'interface).
5. **Filet de sécurité côté navigateur** : si la réponse AJAX reste
   inexploitable, le bouton est débloqué et les fragments redemandés, au lieu de
   laisser un spinner tourner indéfiniment.
6. **Page de diagnostic** dans WooCommerce → *Diagnostic panier*, qui teste
   depuis le serveur si `?wc-ajax=get_refreshed_fragments` renvoie bien du JSON
   non mis en cache.

### Installation

```bash
./build-zip.sh          # produit smart6teme-cart-fix.zip
```

Puis **Extensions → Ajouter → Téléverser une extension**, choisir le `.zip`,
installer, activer. (L'activation purge automatiquement le cache.)

Sans passer par le zip : téléversez le dossier `smart6teme-cart-fix/` dans
`wp-content/plugins/` par FTP ou par le gestionnaire de fichiers hPanel, puis
activez-le depuis **Extensions**.

### Vérification

1. **WooCommerce → Diagnostic panier** : toutes les lignes doivent être vertes.
2. Sur la boutique en navigation privée : ajoutez un produit. Le compteur doit
   s'incrémenter **sans rechargement** et le bouton reprendre son état normal.
3. En cas de doute, ouvrez `https://smart6teme.com/?s6t_cart_debug=1` et lisez
   la console : le plugin y journalise précisément ce qui se passe.

### Réglages

À placer dans `wp-config.php` si besoin de désactiver un correctif :

```php
define( 'S6T_CART_FIX_DISABLE_NOCACHE',    true ); // laisse le cache gérer wc-ajax
define( 'S6T_CART_FIX_DISABLE_FRAGMENTS',  true ); // ne réinjecte pas wc-cart-fragments.js
```

Filtres disponibles : `s6t_cart_fix_count_selectors` (sélecteurs CSS des
compteurs à synchroniser) et `s6t_cart_fix_watchdog_ms` (délai de déblocage du
bouton, 6000 ms par défaut).

## Ce que je n'ai pas pu faire

Cette session Claude Code n'a **aucun accès réseau à votre infrastructure** :

- `smart6teme.com:443` est refusé par la politique d'egress de l'environnement
  (HTTP 403 du proxy) — impossible d'inspecter le site en ligne ;
- SSH vers `147.79.99.68:65002` est impossible : le proxy re-termine le TLS et
  ne relaie pas le TCP brut. Le tunnel s'ouvre mais aucun octet SSH ne passe.
  Aucun identifiant n'y changerait quoi que ce soit.

Le correctif ci-dessus est donc conçu pour être appliqué depuis votre côté. Pour
qu'une prochaine session puisse intervenir directement, il faut autoriser
`smart6teme.com` dans la politique réseau de l'environnement :
<https://code.claude.com/docs/en/claude-code-on-the-web>
