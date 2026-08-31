# Diagnostic en 2 minutes

À faire dans **Chrome ou Firefox, en navigation privée** (une session normale
peut être exemptée du cache et masquer le problème).

## 1. Ouvrir l'inspecteur

`F12` → onglet **Réseau** → cocher **Conserver le journal** (*Preserve log*).

## 2. Cliquer sur « Acheter »

Repérer dans la liste la ligne dont l'URL contient `wc-ajax=add_to_cart`.

## 3. Lire la réponse

Cliquer dessus, onglet **Réponse**.

### Cas A — la réponse commence par `<!DOCTYPE html>` ou `<html>`

**C'est du HTML mis en cache au lieu du JSON.** Cause confirmée : le cache
(LiteSpeed) sert une page stockée sur l'URL `?wc-ajax=`.

→ README, **étape 2** : exclure `/?wc-ajax=` du cache, désactiver le *Guest
Mode*, puis **Purge All**.

### Cas B — la réponse est bien du JSON avec une clé `fragments`

Le serveur fait son travail : le problème est **côté JavaScript**.

→ Onglet **Console**. Une erreur rouge de type `Cannot read properties of
undefined`, `$ is not a function` ou `jQuery is not defined` au moment du clic
confirme qu'une optimisation JS casse le script.

→ README, **étape 2** : désactiver `JS Combine`, `Load JS Deferred` et
`JS Delay`, ou y ajouter les exclusions listées.

### Cas C — code HTTP 403, 404 ou 503

Un pare-feu applicatif (ModSecurity chez Hostinger) ou une règle de réécriture
bloque les URL `?wc-ajax=`.

→ Support Hostinger : demander la levée de la règle ModSecurity sur
`?wc-ajax=`. Vérifier aussi les règles d'un éventuel plugin de sécurité
(Wordfence, All In One WP Security).

### Cas D — aucune requête `wc-ajax=add_to_cart` n'apparaît

Le clic ne déclenche pas d'AJAX du tout. Deux possibilités :

- l'option **WooCommerce → Réglages → Produits → « Activer les boutons Ajouter
  au panier via AJAX »** est décochée ;
- le bouton du thème n'a pas la classe `ajax_add_to_cart`.

## 4. Vérifier le mini-panier séparément

Toujours dans l'onglet **Réseau**, chercher `wc-ajax=get_refreshed_fragments`.

- **Absent** → `wc-cart-fragments.js` n'est pas chargé. Le mini-panier ne peut
  structurellement pas se mettre à jour sans rechargement. Le plugin correctif
  le réinjecte.
- **Présent, réponse JSON correcte, mais le compteur ne bouge pas** → le
  compteur du thème n'est pas déclaré comme fragment WooCommerce. C'est le
  correctif n°3 du plugin.

## 5. Vérifier les en-têtes de cache

Sur la requête `get_refreshed_fragments`, onglet **En-têtes** → *Réponse* :

| En-tête | Signification |
|---------|---------------|
| `x-litespeed-cache: hit` | **Problème** — la réponse vient du cache. |
| `x-litespeed-cache: miss` | Correct, la réponse est générée à chaque fois. |
| `age:` supérieur à 0 | **Problème** — réponse stockée par un cache intermédiaire. |
| `cf-cache-status: HIT` | **Problème** — Cloudflare met la réponse en cache. |

## Raccourci

Une fois le plugin installé : **WooCommerce → Diagnostic panier** exécute
automatiquement les vérifications 3 à 5 depuis le serveur et affiche le
résultat dans un tableau.
