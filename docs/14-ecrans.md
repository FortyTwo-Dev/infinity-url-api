# 14 — Inventaire des écrans

Décision D-108 et D-111. Destiné au dépôt `infinity-url`. Une page par écran,
huit champs fixes : rôle, action principale, données, état vide, état erreur, état
de chargement, capability, namespace i18n.

Ce document existe parce que l'information manquait partout ailleurs. Sans lui,
un agent produit un tableau générique ; avec lui, il produit *ce* produit.

---

## 1. Règles globales

| Règle | Conséquence |
| --- | --- |
| **Quatre états obligatoires** | Vide, chargement, erreur, nominal. Un écran sans état vide est un écran non terminé |
| **Capability** | Si la capability est absente de `GET /me`, l'entrée de navigation *et* la route sont inaccessibles. Jamais un simple bouton grisé |
| **Aucune chaîne en dur** | Tout libellé est une clé i18n, présente en `fr` et `en` |
| **Aucun jeton persistant** | L'access token vit en mémoire. Aucun `localStorage`, aucun cookie lisible par le JS |
| **Aucun tiers** | Pas de police externe, pas d'analytics, pas de widget. Le QR est généré côté client |
| **Mobile** | Les tableaux deviennent des listes de cartes. Aucune action uniquement accessible au survol |

### Navigation

```
url.infinityproject.fr
├── /                     présentation du produit            (prerender)
├── /login  /signup  /forgot  /reset  /verify               (SSR off, noindex)
├── /links                liste des liens                    (SSR off, noindex)
│   ├── /links/new        création
│   └── /links/[id]       détail + statistiques + QR
├── /settings             profil
│   ├── /settings/security   mot de passe, 2FA, sessions
│   ├── /settings/tokens     jetons API
│   ├── /settings/privacy    export de données
│   └── /settings/danger     suppression du compte
├── /report               signalement d'un lien (public, SSR, indexable)
├── /abuse                procédure d'abus, contact, DSA (public, SSR, prerender)
└── /legal/notice  /legal/terms  /legal/aup  /legal/privacy
    /legal/subcontractors  /legal/client-clause
```

`iurl.fr` ne sert **aucune** de ces pages. Il ne fait que rediriger, sauf
`GET /report/{slug}` qui répond `302` vers `/report?slug=…` : une redirection de
service, sans corps ni cookie, jamais comptée comme un clic.

`/`, `/en`, `/report`, `/abuse` et les pages `/legal/*` sont les pages publiques
rendues côté serveur (`routeRules`), avec un layout public séparé : pas de shell
authentifié, pas de capability, et un `robots: index, follow` qui surcharge le
`noindex` global. `/`, `/en`, `/abuse`, `/legal/*` et `/en/legal/*` sont
prérendus ; `/report` est rendu à la demande. Le dashboard et l'authentification
restent en SPA.

---

## 2. Écrans détaillés

### `/links`

| Champ | Valeur |
| --- | --- |
| Rôle | Lister les liens du workspace |
| Action principale | « Créer un lien », bouton primaire en haut à droite |
| Données | slug, destination tronquée à 40 caractères, clics sur 7 jours, statut, date de création |
| État vide | « Aucun lien pour l'instant. » + le bouton de création, jamais un tableau vide |
| État erreur | Bandeau avec le code traduit + « Réessayer » qui relance réellement la requête |
| Chargement | Trois lignes squelettes, pas de spinner plein écran |
| Capability | `links:read` |
| i18n | `links.list.*` |

Recherche `?q=` (2 caractères minimum), filtre par statut, tri par date. Le tri
par clics est différé (ADR-0003) : l'API ne trie que par date et `clicks_7d`
est calculé par page.

### `/links/new`

| Champ | Valeur |
| --- | --- |
| Rôle | Créer un lien |
| Action principale | « Créer » |
| Données | destination (URL), slug custom (optionnel), domaine (`iurl.fr` uniquement en v0.1, D-127), expiration (optionnelle) |
| État vide | Sans objet |
| État erreur | Erreurs champ par champ depuis `errors`, plus le code global |
| Chargement | Bouton désactivé avec libellé « Création… » |
| Capability | `links:write` |
| i18n | `links.create.*` |

Erreurs spécifiques à afficher telles quelles : `slug_taken`, `slug_reserved`,
`destination_blocked`, `destination_private_address`. La vérification en ligne
peut prendre quelques secondes : afficher un état d'attente explicite, jamais un
bouton qui semble figé.

### `/links/[id]`

| Champ | Valeur |
| --- | --- |
| Rôle | Détail d'un lien, statistiques, QR |
| Action principale | « Copier le lien » |
| Données | URL courte, destination modifiable, graphique des clics par jour, répartition pays (v0.2) et appareil, uniques du jour et de la semaine, QR |
| État vide | Statistiques : « Aucun clic pour l'instant. » Le graphique n'est pas rendu vide, il est remplacé par ce texte |
| État erreur | Idem liste ; `404` → page « lien introuvable », pas un bandeau |
| Chargement | Squelette de graphique + valeurs en `—`, jamais des `0` avant l'arrivée des données |
| Capability | `links:read` ; l'édition exige `links:write` |
| i18n | `links.detail.*`, `stats.*` |

Le QR est généré dans le navigateur via `@infinitylib/iqrcode-wasm`, avec export
PNG et SVG. Aucun appel réseau pour le produire.

### `/settings/security`

| Champ | Valeur |
| --- | --- |
| Rôle | Mot de passe, 2FA, sessions |
| Action principale | « Activer la 2FA » si désactivée, sinon « Modifier le mot de passe » |
| Données | état 2FA, codes de récupération restants, bouton « Révoquer toutes les sessions » |
| État vide | Sans objet |
| État erreur | Mot de passe incorrect : message sur le champ, pas une alerte globale |
| Chargement | Boutons désactivés pendant l'action |
| Capability | aucune |
| i18n | `settings.security.*` |

**Aucune liste de sessions** (D-112) : pas d'IP, pas de user-agent, pas de
« appareil ». Un seul bouton, qui révoque tout. Les codes de récupération sont
affichés **une seule fois**, avec une confirmation explicite avant de fermer.

### `/report`

| Champ | Valeur |
| --- | --- |
| Rôle | Signaler un lien reçu et jugé suspect, sans compte |
| Action principale | « Signaler ce lien » |
| Données | URL courte (préremplie si `?slug=`), catégorie (hameçonnage / logiciel malveillant / autre), description facultative, email facultatif |
| État vide | Sans objet : le formulaire est le contenu |
| État erreur | Erreurs champ par champ. Un échec réseau ne doit jamais faire perdre le texte saisi |
| Chargement | Bouton désactivé, libellé « Envoi… » |
| Capability | **Aucune** : page publique, sans compte, sans cookie |
| i18n | `abuse.report.*` |

Trois règles d'interface, qui sont des règles de confidentialité :

1. **Aucune case à cocher, aucun consentement, aucun captcha tiers.** Le champ piège est invisible et ne doit pas être perçu comme une vérification.
2. **La confirmation ne dit pas si le lien existe.** Message unique : « Signalement reçu. Nous le traitons sous 48 heures. » avec une référence, et rien d'autre.
3. **L'email est présenté comme facultatif** et la mention « sans email, votre signalement reste anonyme » est visible, pas dans une infobulle.

Le pied de page de `403`, `404` et `410` pointe ici, avec le slug déjà rempli. C'est le seul chemin où la victime est déjà sur notre domaine.

### `/abuse`

| Champ | Valeur |
| --- | --- |
| Rôle | Expliquer la procédure d'abus et le canal de contact, sans compte |
| Action principale | « Signaler un lien » vers `/report` |
| Données | procédure (accusé sous 24 h ouvrées, qualification sous 48 h, retrait immédiat si malveillant), contact `abuse@iurl.fr`, obligations DSA, lien vers `/report` |
| État vide | Sans objet : page de contenu |
| État erreur | Aucun appel réseau : page statique, rien à échouer |
| Chargement | Aucun : page prérendue |
| Capability | **Aucune** : page publique, sans compte, sans cookie |
| i18n | `abuse.page.*` |

La page est opérationnelle, pas contractuelle. L'AUP est publiée sur
`/legal/aup` et la politique de confidentialité sur `/legal/privacy` ; `/abuse`
y renvoie explicitement.

### `/legal/*`

| Champ | Valeur |
| --- | --- |
| Rôle | Publier les mentions légales, les CGU, l'AUP, la politique de confidentialité, la liste des sous-traitants et la clause client |
| Action principale | Aucune : lecture. Les liens du pied de page permettent de passer d'un document à l'autre |
| Données | Contenu statique du module `app/lib/legal/fr.ts` : titre, date de mise à jour, sections et paragraphes |
| État vide | Sans objet |
| État erreur | Slug inconnu → `404`. Aucune requête réseau, aucun formulaire |
| Chargement | Aucun : pages prérendues |
| Capability | **Aucune** : pages publiques, sans compte, sans cookie |
| i18n | `legal.nav.*`, `legal.updated`, `legal.back`, `legal.englishNotice`. La prose légale vit dans `app/lib/legal/fr.ts`, pas dans les catalogues |

Les six documents sont `/legal/notice`, `/legal/terms`, `/legal/aup`,
`/legal/privacy`, `/legal/subcontractors` et `/legal/client-clause`. Les routes
`/en/legal/*` servent le **même texte français**, avec l'avertissement traduit
`legal.englishNotice` (« This legal document is published in French only; the
French version prevails. ») et `lang="fr"` sur le document. Le français est la
seule version normative en S6.

Le pied de page du layout public et celui du dashboard portent les six liens,
plus `/abuse`, `/report` et le lien « Code source ». Aucune page légale ne
charge de police externe, de script tiers, de cookie ou de requête tierce.

---

## 3. Écrans secondaires

| Écran | Rôle | Action principale | Capability | i18n |
| --- | --- | --- | --- | --- |
| `/signup` | Créer un compte | « Créer mon compte » | — | `auth.signup.*` |
| `/verify` | Confirmer l'email | « Renvoyer l'email » | — | `auth.verify.*` |
| `/login` | Se connecter, puis code TOTP | « Se connecter » | — | `auth.login.*` |
| `/forgot` | Demander un lien | « Envoyer » | — | `auth.forgot.*` |
| `/reset` | Nouveau mot de passe | « Enregistrer » | — | `auth.reset.*` |
| `/settings` | Profil et email | aucun (lecture) | — | `settings.profile.*` |
| `/settings/tokens` | Jetons API | « Créer un jeton » | `tokens:read` | `settings.tokens.*` |
| `/settings/privacy` | Export de portabilité | « Télécharger mes données » | — | `settings.privacy.*` |
| `/settings/danger` | Supprimer le compte | « Supprimer définitivement » | — | `settings.danger.*` |
| `/report` | Signaler un lien | « Signaler » | — | `abuse.report.*` |
| `/abuse` | Procédure d'abus | « Signaler un lien » | — | `abuse.page.*` |
| `/legal/*` | Mentions, CGU, AUP, confidentialité, sous-traitants, clause client | aucun | — | `legal.nav.*`, `legal.updated`, `legal.back`, `legal.englishNotice` |

Le secret d'un jeton API n'est affiché **qu'une fois**, à la création, avec une
case à cocher « J'ai copié ce jeton » avant de pouvoir fermer. Même logique que
les codes de récupération.

La suppression de compte demande le mot de passe, affiche le délai de 30 jours, et
rappelle que la connexion pendant ce délai réactive le compte (D-119).

---

## 4. Ce qui n'existe pas

| Écran absent | Raison |
| --- | --- |
| Liste des sessions avec IP et appareil | D-112 |
| Page bio / link-in-bio | Ce n'est pas le produit |
| Facturation, plans, quotas payants | Hors v0.1 |
| Équipe, invitations, rôles | Hors v0.1 |
| Éditeur de QR (logo, couleurs, gabarits) | Hors v0.1 |
| Domaines custom (`/domains/*`) | D-127 : hors v0.1, revient en v0.2 |
| Import CSV | Q-07 |
| Tableau de bord administrateur | L'exploitant travaille en base et sur Grafana |
| Centre de notifications | Le produit n'en envoie pas assez pour justifier un écran |

---

## 5. Critère de sortie

Un écran est terminé quand :

1. les quatre états existent et sont testés ;
2. chaque chaîne est une clé présente en `fr` et `en` ;
3. la capability est vérifiée depuis `/me` ;
4. il n'y a aucun jeton en stockage persistant ;
5. il n'y a aucune requête tierce vérifiable dans l'onglet réseau ;
6. il fonctionne à 360 px de large sans action inaccessible.

La réorganisation et la validation finales (S7) reprennent ces critères sur
l'ensemble des écrans v0.1 ; aucun écran n'est déclaré terminé avant cette
passe, qui ferme la v0.1.
