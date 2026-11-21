# 09 — Stratégie de test

Décision D-101. Deux dépôts, donc deux suites, plus un contrat partagé. L'objectif n'est pas un pourcentage de couverture : c'est de rendre impossible la régression sur ce qui fait la valeur du produit.

---

## 1. Les trois règles

**1. On teste les promesses, pas les accesseurs.** Un test qui vérifie que `getName()` renvoie le nom n'a aucune valeur. Un test qui vérifie qu'aucun cookie n'est posé sur la redirection vaut plus que cinquante autres.

**2. On teste contre les vrais services.** Postgres et Valkey réels, jamais SQLite, jamais un mock de cache. Le comportement de TTL, de singleflight et de contrainte d'unicité *est* ce qu'on veut tester.

**3. On teste peu de parcours, mais en entier.** Deux parcours E2E, pas trente. Un parcours E2E qui casse est une information ; trente parcours E2E qui cassent est du bruit qu'on finit par ignorer.

---

## 2. API — `infinity-url-api`

### 2.1 Unitaires : la logique pure

| Sujet | Cas à couvrir |
| --- | --- |
| Génération de slug | Longueur 7, alphabet sans `0 O 1 l I`, distribution, jamais de séquence |
| Réservation | Un slug de la liste est refusé, à la génération comme à la saisie custom |
| Slug custom | Casse, longueur 2–64, caractères autorisés, normalisation en minuscules |
| Domaine enregistrable | `a.b.example.co.uk` et `example.co.uk` donnent le même domaine (PSL) |
| TTL de dédup | Le TTL correspond exactement à la prochaine rotation du sel |
| Rétention | La date de purge est calculée correctement à cheval sur un changement de mois |
| Détection d'appareil | Mobile / tablette / desktop, et bot |
| Cast `EncryptedWithHash` | Normalisation en minuscules, synchronisation du hash à l'écriture, `compare()` qui ne marque pas l'attribut comme modifié quand la valeur déchiffrée est identique |

### 2.2 Les tests d'invariants de conformité

C'est la partie la plus importante du document. Ces tests ne testent pas du code, ils testent des **promesses publiques**. S'ils cassent, le produit ment.

| Invariant | Test | Emplacement |
| --- | --- | --- |
| Aucun cookie sur la redirection | `GET /{slug}` ne contient aucun `Set-Cookie` | `tests/Feature/RedirectPrivacyTest.php` |
| Aucune colonne d'IP | Introspection du schéma : aucune colonne nommée `ip*` dans aucune table | `tests/Architecture/NoIpColumnTest.php` |
| Aucune table de clics | Le schéma ne contient aucune table d'événements de clic | idem |
| Aucun script sur `iurl.fr` | La redirection ne sert que des en-têtes, et les pages d'erreur ne contiennent ni `<script>`, ni ressource externe | `RedirectPrivacyTest` + `ErrorPagePrivacyTest` |
| Clé non corrélable entre liens | Même IP + deux `link_id` → deux clés différentes | `tests/Unit/DedupKeyTest.php` |
| Sel irrécupérable | Après rotation, l'ancien sel n'est plus accessible | `tests/Feature/SaltRotationTest.php` |
| User-agent jamais persisté | Aucune colonne UA, aucun log applicatif contenant l'UA | `tests/Architecture/NoUserAgentTest.php` |
| **Email jamais lisible en base** | `users.email` contient du ciphertext, jamais la valeur en clair | `tests/Feature/EmailEncryptionTest.php` |
| **Hash d'index jamais exposé** | `email_hash` absent de toute réponse JSON et de tout payload | `tests/Feature/HashColumnsHiddenTest.php` |
| **Rotation d'`APP_KEY` sans perte d'index** | Changer `app.key` ne modifie pas `EmailHasher::hash()` (clé dédiée) | `tests/Unit/BlindIndexKeyTest.php` |
| **Export en allowlist** | `GET /me/export` ne renvoie que les clés déclarées par `PersonalDataInventory` | `tests/Feature/PersonalDataExportTest.php` |
| Purge des logs ≤ 72 h | La configuration de journalisation a un plafond dur ≤ 72 h | `tests/Feature/LogRetentionConfigTest.php` |
| **Aucune route sans ability** | Toute route authentifiée déclare au moins une ability ; un jeton `refresh` ne peut atteindre aucun endpoint | `tests/Architecture/RouteAbilitiesTest.php` |

Le test `NoIpColumnTest` est volontairement brutal : il échoue si quelqu'un ajoute un champ `ip_address` « juste pour déboguer ». C'est exactement le genre d'ajout qui fait mentir un LIA.

### 2.3 Fonctionnels : HTTP

- **Auth** : inscription, vérification d'email, connexion, 2FA TOTP, réinitialisation, expiration de token.
- **Tokens API** : création, scopes, rotation, révocation, hachage au repos.
- **Liens** : création, validation, édition, suppression, expiration.
- **Redirection** : `302`, cache chaud, cache froid, negative cache sur 404, `410` sur slug supprimé.
- **Rate limiting** : dépassement, en-têtes `X-RateLimit-*`, 404 répétés.
- **OpenAPI** : la spec générée est valide et contient toutes les routes publiques.

### 2.4 Intégration

- `compose.test.yml` avec Postgres et Valkey réels.
- Un test dédié vérifie le **singleflight** : 100 requêtes simultanées sur un cache froid ne déclenchent qu'une seule lecture en base.
- Un test dédié vérifie la **file** : la redirection répond avant que l'analytique ne soit écrite, et le job produit bien les compteurs.
- Horizon en mode synchrone pour les tests fonctionnels, en mode réel pour le test d'intégration de la file.

### 2.5 Charge : un garde-fou, pas un benchmark

Un script k6 sur la route de redirection, avec un cache chaud, sur la machine de développement. L'assertion n'est pas « on tient 100 000 rps », c'est :

> p95 < 50 ms et zéro erreur à 500 requêtes par seconde.

Le but est de détecter une régression — un N+1, un cache débranché, un middleware oublié — pas de prouver la scalabilité. Ce test tourne en nightly, pas à chaque commit.

---

## 3. Web — `infinity-url`

### 3.1 Unitaires (Vitest)

- Composables : formatage de dates et de nombres selon la locale, calcul des plages de dates du graphique.
- Stores Pinia : état des liens, états de chargement et d'erreur.
- **Pas de test du thème.** Il appartient à `infinitylib` et se teste là-bas.

### 3.2 Composants (`@nuxt/test-utils`)

- Formulaire de création : validation, erreurs serveur, slug custom, expiration.
- Tableau des liens : état vide, un lien, pagination, suppression.
- Graphique : ne casse pas avec 0, 1 et 1 000 points.

### 3.3 E2E (Playwright) — deux parcours

1. **Le parcours qui justifie le produit** : inscription → email capturé par Mailpit → création d'un lien → ouverture dans un nouvel onglet → clic visible dans les statistiques.
2. **Le parcours API** : création d'un lien avec un token Bearer, vérification de la réponse, des en-têtes de rate limit et de la forme de l'erreur.

### 3.4 Contrat de types

`openapi-typescript` génère les types du client depuis `/openapi.json`. La CI régénère et **échoue si le diff est non vide**. C'est ce qui empêche les deux dépôts de dériver (D-88).

---

## 4. Outillage

### 4.1 `compose.test.yml`

| Service | Rôle |
| --- | --- |
| PostgreSQL | Base réelle, mêmes extensions qu'en production |
| Valkey | Cache et dédup réels |
| Mailpit | Capture des emails, jamais d'envoi réel |

**Jamais l'ACME de production en test.** Les rate limits de Let's Encrypt se consomment en une après-midi de développement. En v0.1, il n'y a plus d'émission de certificat dans les tests (D-127) : le compose se limite à PostgreSQL, Valkey et Mailpit.

### 4.2 CI (GitHub Actions)

| Job | Contenu |
| --- | --- |
| API — qualité | Pint, PHPStan niveau 6 |
| API — tests | Unitaires, fonctionnels, invariants, intégration |
| API — sécurité | `composer audit` |
| API — licences | Vérification qu'aucune dépendance `GPL-2.0-only` n'entre dans le projet |
| Web — qualité | ESLint, `vue-tsc` |
| Web — tests | Vitest, composants |
| Web — E2E | Playwright sur le compose de test |
| Contrat | Spec OpenAPI valide, types générés à jour |
| Nightly | k6 |

Dépôt public ⇒ minutes GitHub Actions illimitées et gratuites. C'est un avantage concret de l'open source.

### 4.3 Ce qu'on ne fait pas en v0.1

- **Pas de mutation testing.** Coûteux à lire, sans bénéfice à cette échelle.
- **Pas d'objectif de couverture global.** Un chiffre global pousse à tester des accesseurs.
- **Pas de mock de la base.** Un test qui mocke Postgres ne teste pas Postgres.
- **Pas de test unitaire de contrôleur.** Les tests fonctionnels couvrent déjà le comportement.

---

## 5. Règle de couverture

La couverture de 100 % n'est exigée que sur quatre modules, et vérifiée en CI :

| Module | Pourquoi |
| --- | --- |
| Calcul de la clé de dédup | C'est la promesse de non-corrélation |
| Cast de chiffrement et index aveugle | C'est la promesse « email illisible en base » |
| Génération et validation des slugs | C'est la surface d'énumération |
| Calcul de rétention | C'est la promesse de purge |
| Route de redirection | C'est 95 % du trafic et l'invariant « aucun cookie » |

Ailleurs, la couverture est un indicateur, pas un objectif.

---

## 6. Définition de « vert »

Une branche est mergeable quand, en plus des tests :

1. la spec OpenAPI n'a pas changé sans que les types web soient régénérés ;
2. aucun test d'invariant de conformité n'a été modifié sans mise à jour correspondante de `06-lia.md` ;
3. l'audit de licences ne signale aucune dépendance incompatible avec l'AGPL-3.0 ;
4. le test anti-cookie passe sur la route de redirection.

Le point 2 est le plus important et le plus facile à oublier : **si un test de conformité doit changer, ce n'est pas le test qui est en tort, c'est le document juridique ou le code.**
