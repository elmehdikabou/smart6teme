# smart6teme.com — Correctif « bouton Acheter bloqué / panier non mis à jour »

## Le symptôme

Au clic sur **Acheter**, sur `/product/mi-smart-air-fryer-3-5l/` :

1. le bandeau vert « Mi Smart Air Fryer 3.5 L a été ajouté à votre panier » s'affiche ;
2. le bouton reste bloqué avec son indicateur de chargement ;
3. le compteur du panier ne bouge **qu'après rechargement** de la page.

## La cause, établie par la capture réseau

> **Correction d'une hypothèse antérieure.** J'avais d'abord désigné la mise en
> cache LiteSpeed des URL `?wc-ajax=` comme cause n°1. La capture de l'onglet
> Réseau l'infirme : **aucune requête `?wc-ajax=` n'est émise du tout**. Les
> réglages LiteSpeed restent une bonne hygiène, mais ils ne sont pas le
> problème ici. La cause réelle est ci-dessous.

Ce que montre la capture pour un clic sur « Acheter » :

| Requête | Statut | Type | Déclencheur | Taille |
|---------|--------|------|-------------|--------|
| `mi-smart-air-fryer-3-5l/` | **302** | xhr / Redirect | `jquery.min.js:2` | 0,2 ko |
| `checkout/` | 200 | xhr | `mi-smart-air-fryer-3-5l/` | **83,5 ko** |

Et deux absences décisives :

- **aucun `?wc-ajax=add_to_cart`** → le bouton n'utilise pas l'ajout au panier
  AJAX natif de WooCommerce ;
- **aucun `?wc-ajax=get_refreshed_fragments`** → `wc-cart-fragments.js` n'est
  pas chargé, donc le mini-panier ne peut structurellement pas se mettre à jour.

### Le mécanisme

Le bouton déclenche l'ajout **classique** — `GET /product/…/?add-to-cart=123` —
mais **en XHR** au lieu de laisser le navigateur naviguer. Côté serveur,
WooCommerce fait son travail : il ajoute le produit, enregistre le message de
succès, puis répond par une **redirection 302 vers `/checkout/`**.

Comme la requête est un XHR, cette redirection est suivie *à l'intérieur de la
requête* : les 84 ko de la page de commande sont téléchargés… puis jetés. La
navigation attendue n'a jamais lieu.

D'où les trois symptômes, tous issus de ce seul décalage :

| Symptôme | Origine |
|----------|---------|
| Message de succès | L'ajout **a réussi** côté serveur. |
| Bouton bloqué | Le code attendait une navigation qui n'arrive jamais : rien ne retire l'état « loading ». |
| Panier figé | Voir ci-dessous. |

### Pourquoi le mini-panier ne se met jamais à jour

Ce n'est pas un bug, c'est une conséquence logique. Dans
`WC_Frontend_Scripts::load_scripts()`, WooCommerce n'enfile
`wc-cart-fragments.js` **que si** l'option « rediriger vers le panier après un
ajout » est désactivée :

```php
if ( 'yes' !== get_option( 'woocommerce_cart_redirect_after_add' ) ) {
    self::enqueue_script( 'wc-cart-fragments' );
}
```

Le raisonnement de WooCommerce est correct : inutile de rafraîchir en AJAX un
mini-panier que l'on s'apprête à quitter. Sauf que **votre bouton ne quitte
jamais la page**. Le site cumule donc les deux défauts : pas de navigation, et
pas de fragments.

Les deux réglages sont incompatibles entre eux, et c'est là tout le bug.

## La correction : rendre les deux réglages cohérents

Il faut choisir le comportement voulu pour « Acheter ». Les deux sont
légitimes — c'est une décision commerciale, pas technique.

### Option A — « Acheter » ajoute au panier et on reste sur la page *(recommandé au vu de votre demande)*

C'est ce que vous décrivez vouloir : que le panier se mette à jour sans
rechargement.

**WooCommerce → Réglages → Produits → Général :**

- décocher **« Rediriger vers la page panier après un ajout réussi »** ;
- cocher **« Activer les boutons Ajouter au panier via AJAX »**.

WooCommerce recharge alors `wc-cart-fragments.js` de lui-même, la redirection
302 disparaît, et le mini-panier se met à jour en direct.

Équivalent par le code, dans `wp-config.php` :

```php
define( 'S6T_CART_FIX_NO_REDIRECT_AFTER_ADD', true );
```

### Option B — « Acheter » est un achat immédiat qui mène à la commande

Si le bouton doit vraiment emmener le client au paiement, alors il ne doit pas
être en AJAX : il doit **naviguer**. Dans `wp-config.php` :

```php
define( 'S6T_CART_FIX_BUY_MODE', 'checkout' );
```

Le plugin redirige alors le navigateur vers la page de commande dès que l'ajout
est terminé, au lieu de laisser le bouton tourner dans le vide.

Dans ce cas, ajoutez un second bouton « Ajouter au panier » classique pour les
clients qui veulent continuer leurs achats.

## Le plugin correctif

Le dossier [`smart6teme-cart-fix/`](smart6teme-cart-fix/) applique la
correction côté code, pour qu'un réglage remis à zéro ou une mise à jour du
thème ne fasse pas revenir le bug.

1. **Réinjecte `wc-cart-fragments.js`** quand WooCommerce ou une extension de
   performance l'a écarté.
2. **Publie le nombre d'articles comme fragment WooCommerce** et le recopie dans
   la pastille du thème — y compris quand celle-ci est rendue hors de
   `div.widget_shopping_cart_content` et n'est donc jamais mise à jour en AJAX.
3. **Repli sans fragments** : un point d'entrée léger `?wc-ajax=s6t_cart_state`
   permet au compteur de suivre même si `wc-cart-fragments.js` reste absent.
4. **Surveille les deux schémas d'ajout** (`?wc-ajax=add_to_cart` et
   `?add-to-cart=`) : débloque le bouton et rafraîchit le panier dès que la
   requête est terminée.
5. **Signale le double-clic logiciel** : si deux requêtes d'ajout identiques
   partent à moins de 1,5 s d'intervalle, le gestionnaire est lié deux fois et
   le produit est ajouté en double — le journal le dit explicitement.
6. **Empêche la mise en cache** des réponses `?wc-ajax=` et des pages
   panier / commande / compte (hygiène, pas la cause ici).
7. **Exclut les scripts critiques** des optimisations JS de WP Rocket et
   Autoptimize.
8. **Page de diagnostic** dans WooCommerce → *Diagnostic panier*.

### Installation

```bash
./build-zip.sh          # produit smart6teme-cart-fix.zip
```

**Extensions → Ajouter → Téléverser une extension**, choisir le `.zip`,
installer, activer. L'activation purge le cache automatiquement.

### Vérification

1. **WooCommerce → Diagnostic panier** : la ligne « Redirection après ajout au
   panier » doit passer au vert.
2. En **navigation privée**, ajoutez un produit : le compteur doit s'incrémenter
   sans rechargement.
3. En cas de doute : `https://smart6teme.com/product/mi-smart-air-fryer-3-5l/?s6t_cart_debug=1`,
   puis onglet Console. Le plugin y journalise le schéma détecté, la taille
   téléchargée inutilement et le mode retenu.

### Réglages disponibles

```php
// Comportement du bouton « Acheter » : 'stay' (défaut), 'checkout' ou 'off'.
define( 'S6T_CART_FIX_BUY_MODE', 'stay' );

// Supprime la redirection après ajout : WooCommerce recharge alors les fragments.
define( 'S6T_CART_FIX_NO_REDIRECT_AFTER_ADD', true );

// Désactivations ciblées.
define( 'S6T_CART_FIX_DISABLE_NOCACHE',   true );
define( 'S6T_CART_FIX_DISABLE_FRAGMENTS', true );
```

Filtres : `s6t_cart_fix_count_selectors`, `s6t_cart_fix_watchdog_ms`.

## Ce qui reste à identifier

Le plugin répare les conséquences. Pour supprimer la **source** — le script qui
transforme le clic en XHR — il faut le nommer :

> Onglet **Réseau** → clic sur la ligne `mi-smart-air-fryer-3-5l/` en **302** →
> onglet **Initiator** / **Déclencheur** → dérouler la pile d'appels.

`jquery.min.js:2` n'est que jQuery lui-même ; la pile révèle le fichier du thème
ou de l'extension à l'origine de l'appel. Avec ce nom, la correction devient
définitive plutôt que compensatoire.

## Accès

Cette session n'a **aucun accès réseau à votre infrastructure**, ce qui a été
vérifié et non supposé :

- `smart6teme.com:443` est refusé par la politique d'egress (HTTP 403) ;
- SSH vers `147.79.99.68:65002` : le tunnel s'ouvre (`200 Connection
  Established`) mais aucun octet SSH ne transite — le proxy re-termine le TLS et
  ne relaie pas le TCP brut. Aucun identifiant n'y changerait quoi que ce soit,
  **ne transmettez pas de mot de passe**.

Pour qu'une prochaine session intervienne directement, autorisez
`smart6teme.com` dans la politique réseau de l'environnement :
<https://code.claude.com/docs/en/claude-code-on-the-web>
