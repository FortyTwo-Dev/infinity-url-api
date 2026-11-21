# 10 — Contrat d'API

Décisions D-88, D-89, D-103, D-105. L'API est le produit : le dashboard, `iQuiz` et les clients tiers consomment exactement le même contrat. Ce document est la référence avant que Scramble ne le publie.

---

## 1. Principes

1. **Le dashboard est le client n°1.** Aucun endpoint privé, aucune exception. Si un écran a besoin d'une donnée, elle passe par l'API publique.
2. **Les codes d'erreur sont stables et ne sont jamais traduits.** Le serveur renvoie un code machine ; la traduction vit dans le front. Une API qui renvoie des messages traduits casse tous ses clients à chaque changement de langue.
3. **On ne casse jamais `v1`.** Uniquement des ajouts. Toute rupture ouvre `v2`.
4. **Le contrat est généré, pas écrit à la main.** Scramble produit l'OpenAPI depuis les routes et les validations ; `openapi-typescript` produit les types du front.

---

## 2. Conventions

| Élément | Règle |
| --- | --- |
| Base | `https://api.url.infinityproject.fr/v1` |
| Format | JSON, `Content-Type: application/json` |
| Erreurs | `application/problem+json` (RFC 9457) |
| Identifiants | ULID dans les champs `id`, jamais l'entier interne |
| Dates | RFC 3339, toujours en UTC, suffixe `Z` |
| Champs | `snake_case` (cohérent avec la base et avec Laravel) |
| Tri | `?sort=-created_at` (`-` pour décroissant) |
| Pagination | Curseur opaque |
| Idempotence | Hors v1 (voir §12) |

---

## 3. Authentification

### 3.1 Session du dashboard

Deux jetons, jamais un seul. Tous deux sont des jetons **Sanctum** stockés dans `personal_access_tokens` (D-114).

| Jeton | Forme | Durée | Transport |
| --- | --- | --- | --- |
| Accès | Jeton Sanctum, `abilities = ['*']` | 15 minutes | En-tête `Authorization: Bearer`, conservé **en mémoire JS** |
| Rafraîchissement | Jeton Sanctum, `abilities = ['refresh']` | 30 jours glissants, rotation à chaque usage | Cookie `__Host-iurl_refresh`, `HttpOnly`, `Secure`, `SameSite=Lax`, host-only sur l'hôte API |

Aucun des deux n'est un JWT : ils sont révocables immédiatement, ce qui compte davantage qu'économiser une lecture en base.

**Pas de CSRF à gérer.** Le cookie est `SameSite=Lax`, donc un `POST` cross-site n'envoie pas le cookie ; et la réponse n'est lisible que par l'origine autorisée en CORS. C'est une simplification réelle par rapport au mode session de Sanctum, qui imposerait un cookie `XSRF-TOKEN` illisible depuis l'autre hôte.

| Endpoint | Corps | Effet |
| --- | --- | --- |
| `POST /auth/register` | `email`, `password` | Crée le compte, envoie l'email de vérification. Répond **toujours `201`**, même si l'adresse existe (anti-énumération) |
| `POST /auth/verify-email` | `token` | Vérifie, lien signé valable 24 h, usage unique |
| `POST /auth/verification/resend` | `email` | Renvoie le lien. 3/heure. Répond toujours `202` |
| `POST /auth/login` | `email`, `password` | Si 2FA active : renvoie `{"status":"totp_required","challenge":"…"}`. Sinon : access token + cookie |
| `POST /auth/2fa/verify` | `challenge`, `code` | Vérifie le TOTP et termine la connexion |
| `POST /auth/2fa/enable` | — | Renvoie le secret et l'URI `otpauth://` |
| `POST /auth/2fa/confirm` | `code` | Active la 2FA, renvoie 10 codes de récupération (affichés une seule fois) |
| `POST /auth/2fa/disable` | `password`, `code` | Désactive. Exige les deux, sinon un cookie volé suffit à retirer la protection |
| `POST /auth/2fa/recovery-codes` | `password`, `code` | Régénère les 10 codes, invalide les anciens |
| `POST /auth/refresh` | — (cookie) | Nouveau access token, **rotatateur** du refresh |
| `POST /auth/logout` | — | Révoque le refresh courant, supprime le cookie |
| `POST /auth/logout-all` | — | Révoque toutes les familles de refresh de l'utilisateur |
| `PATCH /me/password` | `current_password`, `password` | Change le mot de passe, révoque les autres sessions |
| `DELETE /me` | `password` | Suppression du compte, délai de grâce de 30 jours, révoque tous les jetons |
| `POST /auth/password/forgot` | `email` | Envoie un lien valable 1 h, réponse toujours `202` |
| `POST /auth/password/reset` | `token`, `password` | Réinitialise et **révoque tous les refresh tokens** |

Règles :

- Mot de passe haché en **argon2id**.
- TOTP : RFC 6238, fenêtre de 30 s, tolérance ±1 pas, secret chiffré avec `APP_KEY`.
- Codes de récupération : hachés, à usage unique, consommés même en cas d'échec partiel.
- Toute réinitialisation de mot de passe révoque les sessions existantes.
- Le refresh token est **rotaté** à chaque rafraîchissement ; un refresh rejoué révoque toute la famille (détection de rejeu).

### 3.2 Jetons API

Format natif de Sanctum : `{id}|{secret}`.

```
42|9K2m...   (affiché une seule fois, à la création)
```

- Seul le SHA-256 est stocké (`personal_access_tokens.token`).
- L'`id` sert à identifier le jeton dans l'interface (nom, date de création, dernière utilisation) sans jamais réafficher le secret.
- Le jeton n'a **pas d'expiration par défaut**, mais accepte une `expires_at`.
- Les scopes du §9 sont stockés dans `abilities`.
- **Un jeton `refresh` ne peut appeler aucune route** : il ne possède que l'ability `refresh`, et chaque route en exige une autre. Aucun middleware de rejet n'est nécessaire.

---

### 3.3 Données personnelles et chiffrement

| Règle | Conséquence pour l'API |
| --- | --- |
| L'email est **chiffré au repos** (D-110) | Aucun endpoint ne fait de recherche partielle sur l'email. La correspondance est exacte, via l'index aveugle |
| `email_hash` n'est **jamais** exposé | Ni en lecture, ni accepté en entrée, ni sérialisé dans une erreur |
| Aucune **liste de sessions** (D-112) | `POST /auth/logout-all` couvre le besoin sans stocker d'IP ni de user-agent |
| L'export est en **allowlist** (D-111) | `GET /me/export` ne peut pas divulguer une colonne future par omission |

Ces quatre règles ne sont pas des détails d'implémentation : ce sont les contreparties techniques de ce que promet la politique de confidentialité.

## 4. Format d'erreur

RFC 9457 (`application/problem+json`), plus un champ `code` stable pour les clients.

```json
{
  "type": "https://api.url.infinityproject.fr/problems/slug-taken",
  "title": "Slug already taken",
  "status": 409,
  "detail": "The slug 'promo' is already used on this domain.",
  "instance": "/v1/links",
  "code": "slug_taken",
  "errors": {
    "slug": ["This slug is already used on this domain."]
  }
}
```

| Statut | Usage |
| --- | --- |
| `400` | Requête malformée |
| `401` | Jeton absent ou invalide |
| `403` | Jeton valide, scope insuffisant |
| `404` | Ressource inexistante **ou appartenant à un autre workspace** (ne jamais révéler l'existence) |
| `409` | Conflit : slug pris, domaine déjà enregistré |
| `410` | Ressource définitivement supprimée (lien tombstoné) |
| `422` | Validation échouée, avec `errors` |
| `429` | Rate limit, avec `Retry-After` |
| `503` | Dépendance indisponible |

**Codes stables** (liste à maintenir dans un enum côté API et côté web) :

```
validation_failed, unauthorized, forbidden, scope_missing,
link_not_found, link_expired, link_blocked, slug_taken, slug_reserved,
destination_blocked, destination_private_address,
rate_limited, account_locked, totp_required, email_unverified,
quota_exceeded, not_found, method_not_allowed, server_error,
totp_already_enabled, recovery_code_invalid,
totp_invalid, token_revoked
```

---

## 5. Pagination

Curseur, jamais d'offset : un offset se décale quand des liens sont créés pendant la navigation.

```http
GET /v1/links?limit=50&cursor=eyJpZCI6MTIzfQ
```

```json
{
  "data": [ ... ],
  "next_cursor": "eyJpZCI6OTk5fQ",
  "has_more": true
}
```

`limit` par défaut 20, maximum 100. Le curseur est opaque et ne doit jamais être construit par le client.

---

## 6. Rate limits

En-têtes **IETF** `RateLimit-Limit`, `RateLimit-Remaining`, `RateLimit-Reset` (corrige le `X-RateLimit-*` des documents antérieurs), plus `Retry-After` sur `429`.

| Portée | Limite | Fenêtre |
| --- | --- | --- |
| API, par jeton | 120 requêtes | 1 min |
| Création de lien | 30 | 1 min |
| Création de jeton API | 10 | 1 h |
| Connexion | 10 | 15 min par IP + email |
| **Signalement** | 5 | 1 h, par IP empreintée |
| Vérification TOTP | 5 | 15 min |
| Renvoi d'email de vérification | 3 | 1 h |
| Réinitialisation de mot de passe | 3 | 1 h |
| **Redirection**, par IP | 1 200 | 1 min |
| **404**, par IP | 60 | 1 min (anti-énumération) |
| Inscriptions | 2 comptes | 24 h par IP |

Le seuil de redirection est volontairement haut : une entreprise derrière un NAT peut partager une IP. Un seuil bas casserait des liens parfaitement légitimes. L'anti-énumération se fait sur les `404`, pas sur les redirections réussies.

---

## 7. Endpoints de la v1

| Méthode | Chemin | Scope | Rôle |
| --- | --- | --- | --- |
| GET | `/me` | `me:read` | Utilisateur, workspace, **`capabilities`** |
| GET | `/me/export` | `me:read` | **Export de portabilité (art. 20)** : compte, workspace, liens, stats agrégées. Allowlist stricte, jamais de hash ni de secret (D-111) |
| GET | `/workspaces` | `me:read` | Un seul en v1, mais l'endpoint existe |
| GET | `/links` | `links:read` | Liste paginée, filtres `?domain=`, `?status=`, `?q=` |
| POST | `/links` | `links:write` | Création |
| GET | `/links/{id}` | `links:read` | Détail |
| PATCH | `/links/{id}` | `links:write` | Destination, slug, expiration, statut |
| DELETE | `/links/{id}` | `links:write` | Suppression logique (le slug reste `410`) |
| GET | `/links/{id}/stats` | `stats:read` | `?from=&to=&interval=day\|week` |
| GET | `/links/{id}/stats.csv` | `stats:read` | Export CSV, même jeu de données |
| GET | `/tokens` | `tokens:read` | Liste (nom, id, scopes, dernière utilisation) |

Les endpoints `/domains` sont **hors v1** (D-127). La table et le modèle existent, semés d'une seule ligne `iurl.fr` ; aucune route ne les expose tant que les domaines custom ne reviennent pas en v0.2.
| POST | `/tokens` | `tokens:write` | Création, secret affiché **une seule fois** |
| DELETE | `/tokens/{id}` | `tokens:write` | Révocation |

Hors `/v1` :

| Chemin | Rôle |
| --- | --- |
| `GET /health`, `GET /ready` | Sondes, sans authentification, sans base |
| `GET /internal/tls/ask?domain=` | Appelé par Traefik uniquement, sur le réseau interne, **jamais exposé** |
| `GET /openapi.json`, `GET /docs` | Contrat public |
| `POST /v1/report` | Signalement public, **sans authentification** |

### Exemple de `GET /v1/me`

```json
{
  "user": {
    "id": "01JD3K8YQ4X7M2V9R5T1B6N0C3",
    "email": "client@example.com",
    "email_verified": true,
    "totp_enabled": true
  },
  "workspace": {
    "id": "01JD3K8Z2A4B6C8D0E2F4G6H8J",
    "name": "Mon espace",
    "plan": "free",
    "limits": { "active_links": 5, "custom_domains": 0, "stats_retention_days": 30 }
  },
  "capabilities": ["links:write", "stats:read", "qr"]
}
```

`capabilities` n'est pas décoratif : c'est **l'auto-découverte** décidée en D-89. Le dashboard masque l'UI d'une fonctionnalité absente, sans build séparé. Une feature cloud-only future n'aura qu'à apparaître dans cette liste.

---


### 7.1 `POST /v1/report` — signalement anonyme

Le seul endpoint public qui écrit en base sans authentification.

```http
POST /v1/report
Content-Type: application/json

{
  "slug": "abc123",
  "category": "phishing",
  "details": "Reçu par SMS, demande de connexion bancaire.",
  "email": "optionnel@example.com",
  "website": ""
}
```

```http
HTTP/1.1 202 Accepted

{ "reference": "01JD3K8YQ4X7M2V9R5T1B6N0C3", "status": "received" }
```

| Règle | Détail |
| --- | --- |
| Authentification | Aucune |
| Réponse | **Toujours `202`**, que le slug existe ou non. Sinon l'endpoint sert à cartographier les liens |
| `category` | `phishing`, `malware`, ou `other` |
| `details` | Facultatif, 2 000 caractères maximum |
| `email` | Facultatif, chiffré au repos, jamais utilisé pour identifier le rapporteur |
| `website` | **Champ piège**, invisible, doit rester vide. Rempli par un robot → `202` sans enregistrement |
| Rate limit | 5 par heure, par IP empreintée avec le sel courant. **L'empreinte n'est jamais persistée** |
| Traitement | Aucun n'est automatique : un humain qualifie sous 48 h |

Le rapporteur ne fournit aucune preuve, ne s'authentifie pas, et ne reçoit aucun cookie. L'anonymat n'est pas une option du formulaire, c'est la seule façon dont il fonctionne.

## 8. Sémantique de la redirection

C'est 95 % du trafic, et c'est aussi une surface de conformité. Aucune de ces lignes n'est négociable.

```http
HTTP/1.1 302 Found
Location: https://example.com/page
Cache-Control: no-store
Referrer-Policy: no-referrer
X-Robots-Tag: noindex
```

| Choix | Raison |
| --- | --- |
| **302**, jamais 301 | Un 301 est mis en cache de façon permanente par le navigateur : modifier la destination d'un lien n'aurait plus d'effet |
| **`Cache-Control: no-store`** | Sinon le navigateur ne rappelle pas le service et **les clics ne sont plus comptés** |
| **`Referrer-Policy: no-referrer`** | Le site de destination n'apprend pas que le visiteur vient de `iurl.fr/<slug>` |
| **`X-Robots-Tag: noindex`** | Empêche l'indexation des destinations via les liens courts |
| **Aucun corps** sur `200/302` | Rien à analyser, rien à mettre en cache, rien à exécuter |
| **`HEAD` accepté** | Sans compter de clic : un `HEAD` est un scanner ou un préchargeur, pas une visite |
| Aucun `Set-Cookie` | Invariant testé en CI |
| **Aucun interstitiel** | Pas de page « vous allez être redirigé », pas de compte à rebours, pas de `meta refresh`, pas de JavaScript. La réponse de succès n'a **aucun corps** |

Les seules pages servies sur `iurl.fr` sont les états terminaux (`404`, `410`, `403`). Aucune ne redirige. Un interstitiel — même « pour la sécurité » — casserait le comptage, la latence, les aperçus de liens et les QR, et rouvrirait la porte à un script sur le domaine de liens.

### Pages d'erreur sur `iurl.fr`

Rendues par **Blade**, et par Blade uniquement : ces quatre pages sont la seule vue du dépôt. Le HTML produit reste statique — CSS inline, **aucun JavaScript, aucune ressource externe** (ni police, ni image, ni feuille de style distante). Sinon l'invariant « aucun script sur le domaine de liens » tombe.

| Vue | Statut | Texte |
| --- | --- | --- |
| `errors/404` | `404` | « Ce lien n'existe pas. » + lien vers `https://infinityproject.fr` |
| `errors/410-deleted` | `410` | « Ce lien a été supprimé définitivement. » |
| `errors/410-expired` | `410` | « Ce lien a expiré. » |
| `errors/403` | `403` | « Ce lien a été bloqué pour abus. » + `abuse@iurl.fr` |

| Cas | Statut | Contenu |
| --- | --- | --- |
| Slug inexistant | `404` | Page sobre, `noindex`, lien vers la maison |
| Lien supprimé (tombstone) | `410` | « Ce lien a été supprimé définitivement » |
| Lien expiré | `410` | « Ce lien a expiré » |
| Lien bloqué pour abus | `403` | Explication + `abuse@iurl.fr` |
| Domaine non vérifié | `404` | Ne pas révéler l'existence du domaine |

**Un `404` et non un `403`** pour un slug inexistant : un `403` confirmerait l'existence d'un slug. Et un `410` et non un `404` pour un lien supprimé, pour que l'utilisateur ne recrée pas son lien en croyant à une erreur de frappe.

---

## 9. Scopes

Les scopes sont stockés dans `abilities` de Sanctum et vérifiés par le middleware `abilities:` fourni par le paquet.

| Scope | Donne accès à |
| --- | --- |
| `me:read` | `GET /me`, `GET /me/export` et `GET /workspaces` |
| `me:write` | **Dashboard uniquement** : `PATCH /me/password`, `DELETE /me`, `POST /auth/logout-all` et les routes 2FA. Jamais accordable à un jeton API |
| `links:read` | Liste et détail des liens |
| `links:write` | Création, édition, suppression |
| `stats:read` | Statistiques et export CSV |
| `tokens:read` | Liste des jetons |
| `tokens:write` | Création et révocation |

Règles :

- **Chaque route authentifiée exige une ability.** Une route sans ability est un bug, et un test d'architecture le vérifie.
- Aucun jeton **API** ne reçoit `*`. Seul le jeton d'accès du dashboard porte `*`, parce qu'il représente l'utilisateur lui-même.
- `iQuiz` reçoit `me:read`, `links:read`, `links:write` et `stats:read`. Jamais `tokens:*`.
- Les abilities `domains:read` et `domains:write` reviendront avec la v0.2, pas avant.
- `tokens:write` permet de créer un jeton : c'est le scope le plus dangereux, il n'est jamais accordé à un client tiers.
- `POST /tokens` **refuse** `me:write` et `*` dans les scopes demandés : sans cette allowlist, un jeton API pourrait changer le mot de passe ou supprimer le compte.
- Un scope absent produit `403` avec `code: "scope_missing"`.

---

## 10. Versioning

- Préfixe de chemin `/v1`.
- Dans `v1` : uniquement des ajouts (nouveaux champs, nouveaux endpoints, nouveaux codes d'erreur). Un client qui ignore un champ inconnu ne doit pas casser.
- Rupture : `/v2`, avec chevauchement de **12 mois**.
- Dépréciation : en-têtes `Deprecation` et `Sunset` (RFC 8594), plus une page de changelog.
- La spec OpenAPI est versionnée avec l'application ; un changelog public liste les ajouts.

---

## 11. Documentation

- `GET /openapi.json` : spec 3.1 générée par Scramble.
- `GET /docs` : interface de lecture publique.
- La CI valide la spec et échoue si les types générés côté web ne sont pas à jour (D-88).
- Toute route publique doit apparaître dans la spec : un test le vérifie, sinon `/docs` devient un mensonge.

---

## 12. Hors v1, décidé

| Fonctionnalité | Raison |
| --- | --- |
| Idempotency-Key sur la création | Utile pour les clients machine (`iQuiz`), mais ajoute une table et une sémantique. v1.1 |
| Webhooks signés | Aucun consommateur aujourd'hui |
| Création en masse | `iQuiz` crée à l'unité |
| Import CSV | Q-07 |
| SDK typés | Le contrat OpenAPI suffit à générer un client |
| Liens protégés par mot de passe | Q-03, v0.2 |
| Ciblage device/géo | Non |

---

## 13. Décision sur la vérification de destination

À la création d'un lien, l'API vérifie la destination (§`04-abus-et-domaines.md`, §3). Deux cas se distinguent, et il faut trancher explicitement :

| Résultat | Comportement |
| --- | --- |
| Domaine dans la blocklist | **Refus** `422`, code `destination_blocked` |
| Adresse privée, loopback, metadata | **Refus** `422`, code `destination_private_address` |
| Schéma non HTTP(S), identifiants dans l'URL | **Refus** `422` |
| Destination injoignable (timeout, DNS) | **Accepté.** Le site peut être temporairement down ou bloquer les robots. Refuser ici serait hostile et contournable. L'échec est journalisé et alimente une métrique |
| Destination joignable et propre | **Accepté** |

Le principe : **on refuse sur une preuve de danger, jamais sur une absence de preuve de sûreté.**
