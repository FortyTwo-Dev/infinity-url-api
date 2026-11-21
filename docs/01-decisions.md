# 01 — Journal des décisions

Chaque décision est datée et identifiée. Les décisions marquées **GELÉ** ne se rouvrent pas sans un fait nouveau. Les questions sans réponse sont en fin de document.

---

## 1. Décisions invalidées dans les documents antérieurs

Ces points figuraient dans `architecture-shortener-privacy-first.md` ou `open-core-une-app-deux-éditions.md` et sont corrigés ici. La liste existe pour éviter de réintroduire les anciennes versions par inadvertance.

| # | Ancienne décision | Nouvelle décision | Raison |
| --- | --- | --- | --- |
| D-01 | Dédup IP : hash + sel à rotation **quotidienne**, TTL 24 h | Hash + sel à rotation **hebdomadaire**, TTL aligné sur la rotation | Permet les uniques hebdomadaires, qui sont la granularité naturelle d'un sel hebdomadaire. **Correction** : une seule clé ne peut produire qu'une granularité. Les uniques journaliers exigent une seconde empreinte, datée et à TTL plus court. Voir D-107 et `11-pipeline.md`. |
| D-02 | Clé de dédup = `hash(sel, IP)` | Clé de dédup = `HMAC(sel, IP, link_id)`, où `link_id` est l'identifiant interne du lien (jamais le slug, jamais l'URL de destination) | Sans le `link_id`, un visiteur qui clique chez deux clients différents produit la même clé : identifiant inter-clients, indéfendable juridiquement et inacceptable commercialement. Avec lui, la corrélation est mathématiquement impossible. Détail complet dans `06-lia.md` §B.4. |
| D-03 | « Visiteurs uniques » disponibles tous liens confondus | **Uniques par lien uniquement**, jamais inter-liens | Conséquence assumée de D-02. Devient un argument de conformité : pas de profil inter-liens. |
| D-04 | Licence mentionnée « AGPL-2.0 » | **AGPL-3.0-only** | L'AGPL v2 n'a jamais existé (passage direct de l'Affero GPL v1 à l'AGPL v3 en 2007). Un identifiant de licence inexistant casse la détection GitHub et crée une ambiguïté juridique. |
| D-05 | « Package privé lié par composer : l'AGPL ne s'applique pas à lui » | Bridge **hors processus (HTTP)** ou sous AGPL. Pas de package propriétaire chargé dans le même processus | Un module intimement lié chargé dans le même processus est très probablement une œuvre combinée au sens AGPL. L'affirmation inverse du doc antérieur est trop confiante. |
| D-06 | CLA (contributor license agreement) dès les premières PR | **DCO** (Developer Certificate of Origin), pas de CLA | Le CLA permet de relicencier mais décourage les contributions. L'objectif déclaré est de rester open source pour toujours : le DCO est cohérent et retire une décision irréversible. Conséquence assumée : plus de relicenciement possible, plus de dual licensing. |
| D-07 | Machinerie open core dès le départ (package bridge, deux pipelines de build, CI de parité) | **DIFFÉRÉ**. Un seul dépôt AGPL, un seul build. La règle de discipline est conservée, la plomberie est reportée | Coût d'une semaine pour zéro client. La règle utile : tout code qui ne doit pas tourner chez un self-hoster vit dans un namespace séparé, gaté par configuration, prêt à être extrait. |
| D-08 | Parité quadlet / compose maintenue en CI | **DIFFÉRÉ**. Compose unique, quadlet en page de documentation optionnelle | Même raison. La parité de deux runtimes est un coût de maintenance permanent pour un bénéfice non mesuré. |
| D-09 | Wildcard TLS via DNS-01 pour les domaines clients | **HTTP-01 on-demand** par hostname, via l'endpoint `ask` de Traefik | Pas besoin de wildcard si l'on émet par hostname. HTTP-01 fonctionne dès que le domaine pointe vers nous, et supprime toute la problématique de délégation DNS-01 chez le client. |
| D-10 | Route `/p/**` dans la config Nuxt (bio page) | **Supprimée** | Jamais spécifiée. Ce serait un produit dans le produit, avec son propre lot de questions d'abus et de conformité. |
| D-11 | CDN et protection DDoS non tranchés ; Cloudflare implicitement possible | **Pas de CDN en v0.1**. Protection DDoS de l'hébergeur + rate limits Traefik | Un CDN hors UE contredit frontalement le positionnement. À rouvrir seulement si un incident volumétrique réel se produit. |
| D-12 | Cible commerciale : « entreprises EU qui veulent passer leurs audits RGPD » | **Acheteurs self-serve** : développeurs indés, agences, fondateurs de SaaS EU, marketers privacy-conscious. L'entreprise passe par le self-host | Un solo à temps partiel ne peut pas porter un cycle de vente DPO + procurement. La cible précédente n'avait pas de canal compatible avec l'équipe. |
| D-13 | « Migration depuis Bitly » comme pilier de positionnement | **Retiré du positionnement**. Reste une feature d'import CSV, différée | Un client Bitly est un client entreprise, donc hors du motion self-serve. |
| D-14 | Builds reproductibles bit-à-bit comme différenciateur | **DIFFÉRÉ**. On publie un digest d'image + attestation de provenance SLSA | Le bit-à-bit coûte des semaines avec composer/npm et la valeur auprès des DPO n'est pas mesurée (le doc lui-même le notait). |
| D-15 | Analytics niveau 2 avec lignes de clic enrichies | **Compteurs agrégés uniquement**, aucune ligne de clic | Permet de tenir la promesse d'effacement par lien (on supprime les compteurs) et supprime le débat sur la nature des lignes de clic. |
| D-16 | Referer collecté | **Aucun referer en v0.1**. Domaine seul envisageable en v0.2, jamais l'URL complète ni les query strings | Une URL de provenance peut contenir une recherche ou un identifiant de personne. |

---

## 2. Décisions de la v0.1

### Produit et périmètre

| # | Décision | Statut |
| --- | --- | --- |
| D-20 | v0.1 **sans facturation**. Instance hébergée ouverte, inscriptions actives avec plafond | GELÉ |
| D-21 | **Pas de tier gratuit permanent** : inscriptions ouvertes mais plafonnées (5 liens actifs, pas de domaine custom, stats 30 jours) | GELÉ |
| D-22 | **Pas de création anonyme de lien.** Compte + email vérifié obligatoires | GELÉ |
| D-23 | **Pas de page bio**, pas de liens protégés par mot de passe, pas de ciblage device/géo, pas de webhooks, pas de SDK en v0.1 | GELÉ |
| D-24 | **QR inclus en v0.1**, en tant que vue d'un lien : génération côté client via la lib Rust/WASM existante, export PNG et SVG | GELÉ |
| D-25 | Liens protégés par mot de passe : **DIFFÉRÉ** en v0.2 | DIFFÉRÉ |
| D-26 | Le domaine custom est **la** feature payante future | **RÉVISÉE par D-127** : hors v0.1, en v0.2 |
| D-27 | **Pas de SLA** en v0.1, mentionné explicitement dans les CGU | GELÉ |

### Analytics et conformité

| # | Décision | Statut |
| --- | --- | --- |
| D-30 | Sel hebdomadaire, rotation **lundi 00:00 UTC**, **sel détruit** à la rotation | GELÉ |
| D-31 | Clé `HMAC-SHA256(salt, ip \| link_id)`, TTL = seconde suivante de la prochaine rotation | GELÉ |
| D-32 | IP jamais persistée, jamais loggée côté applicatif. Résolution pays à la volée, puis jetée | GELÉ |
| D-33 | Compteurs agrégés : `(link, jour, pays, classe d'appareil)` pour les **clics**, et `(link, jour)` pour les **uniques** | GELÉ |
| D-34 | Les uniques ne sont **pas** ventilés par pays ni par appareil (non-additivité) | GELÉ |
| D-35 | Pas de cookie, pas de localStorage, pas de fingerprint, pas de pixel tiers sur le chemin de redirection. Invariant testé en CI | GELÉ |
| D-36 | Logs 72 h maximum, query strings supprimées de l'access log | GELÉ |
| D-37 | Filtrage des bots **avant** agrégation | GELÉ |
| D-38 | Base légale : intérêt légitime, documenté par un LIA (voir `03-conformite.md`) | GELÉ |
| D-39 | Rétention des agrégats : 30 jours sur l'instance hébergée en v0.1 ; valeur configurable par variable d'environnement, 12 mois par défaut en self-host | GELÉ |

### Architecture

| # | Décision | Statut |
| --- | --- | --- |
| D-40 | Laravel 12 en API + FrankenPHP en worker mode (Octane) | GELÉ |
| D-41 | Nuxt 4, hybrid rendering, dashboard en `ssr: false` + `noindex` | GELÉ |
| D-42 | PostgreSQL source de vérité, Valkey pour le cache et la dédup | GELÉ |
| D-43 | Traefik en frontal, déjà en production sur le serveur | GELÉ |
| D-44 | **Multi-tenancy par `workspace_id`** en base unique. Pas de schéma par tenant, pas de base par tenant | GELÉ |
| D-45 | Dashboard : access token Bearer **en mémoire**, refresh token en cookie `__Host-iurl_refresh` (`HttpOnly`, `Secure`, `SameSite=Lax`, **host-only sur l'hôte API**). CORS avec `Access-Control-Allow-Credentials` et origine exacte `https://url.infinityproject.fr`. API publique : tokens Bearer Sanctum, hachés au repos, avec scopes (voir D-114) | GELÉ |
| D-46 | Chemins de cache Valkey : `link:{id}` avec TTL aligné sur la durée de vie du lien, invalidation à l'édition et à la suppression | **RÉVISÉE par D-134** |
| D-47 | **Negative caching** des slugs inexistants (TTL court, 60 s) pour absorber le scan | GELÉ |
| D-48 | **Singleflight** sur le cache miss pour éviter l'effondrement sur un lien viral | GELÉ |
| D-49 | Slug généré : 7 caractères, alphabet base62 **sans** `0`, `O`, `1`, `l`, `I` (58 caractères, ≈2,2 × 10¹² combinaisons). Jamais d'identifiant séquentiel | GELÉ |
| D-50 | Slug custom : insensible à la casse, stocké en minuscules, 2 à 64 caractères, liste de réservation bloquante | GELÉ |
| D-51 | **Un slug supprimé n'est jamais réattribué.** Il renvoie `410 Gone` indéfiniment | GELÉ |
| D-52 | Analytique traitée après réponse, via la file (Horizon), jamais dans la boucle de redirection | GELÉ |
| D-53 | Échappatoire de scale : extraction d'un microservice de redirection lisant le même Valkey, si et seulement si la mesure l'exige | DIFFÉRÉ |

### Exploitation

| # | Décision | Statut |
| --- | --- | --- |
| D-60 | Hébergement **OVH** : VPS au démarrage, serveur dédié si le besoin se présente | GELÉ |
| D-61 | Le shortener partage d'abord le serveur des autres projets open source, mais en **projet compose autonome** (volumes, réseau, noms propres) | GELÉ |
| D-62 | Limites cgroups par conteneur et plafond explicite de threads FrankenPHP | GELÉ |
| D-63 | **Email transactionnel via provider, jamais le SMTP du serveur** (IP sortante séparée) | GELÉ |
| D-64 | Sauvegarde quotidienne PostgreSQL, RPO 24 h, RTO 4 h au mieux. Exercice de restauration trimestriel | **RÉVISÉ par D-102** |
| D-65 | Pas de HA, pas de multi-région en v0.1 | GELÉ |
| D-66 | Kill switch `SIGNUPS_OPEN` pour fermer les inscriptions sans déploiement | GELÉ |
| D-67 | Déclencheur de split écrit : si le shortener dépasse le seuil de charge défini ou provoque un incident affectant un autre projet, il part sur sa propre machine | GELÉ |

### Open source

| # | Décision | Statut |
| --- | --- | --- |
| D-70 | Licence **AGPL-3.0-only** : fichier `LICENSE`, champ `license` dans `composer.json` et `package.json`, en-tête SPDX dans les sources | GELÉ |
| D-71 | Lien « Source » dans le footer de l'application et du site : obligation de la section 13 et actif marketing | GELÉ |
| D-72 | Audit des dépendances en CI : aucune dépendance `GPL-2.0-only` (incompatible). MIT, BSD et Apache-2.0 passent | GELÉ |
| D-73 | **DCO** sur les contributions, pas de CLA (voir D-06) | GELÉ |
| D-74 | Politique de marque séparée. L'AGPL couvre le code, jamais la marque : un fork ne peut utiliser ni « Infinity Project », ni « Infinity QRCode », ni « Infinity URL », ni les logos | GELÉ |

### Écosystème de produits

| # | Décision | Statut |
| --- | --- | --- |
| D-75 | **Infinity Project** est la maison. **Infinity QRCode** et **Infinity URL** sont des produits, **InfinityApp** un projet à part. Le nom de la maison n'entre pas dans le nom des produits, et inversement | GELÉ |
| D-76 | Le thème (tokens `it-theme`, sombre, accent jaune) et la lib QR **Rust/WASM** d'Infinity QRCode sont **partagés** avec Infinity URL via `infinitylib`. Aucun second design system n'est créé | GELÉ |
| D-77 | `iurl.fr` est un **domaine de liens pur** : aucune page marketing indexée, aucun script tiers, aucun analytics. Le marketing vit sur `infinityproject.fr` | GELÉ |
| D-78 | Le cross-sell entre produits est un **lien public** entre les sites. Pas d'IdP privé, pas de registre de comptes inter-produits en v0.1 — correction de la plomberie décrite dans le doc open core | GELÉ |
| D-79 | Le dépôt du shortener reste `infinity-url` ; chaque produit garde son dépôt et son domaine. Pas de monorepo de la maison avant qu'un troisième produit n'existe | GELÉ |
| D-80 | **Aucun cookie sur `iurl.fr`, jamais.** Dashboard sur `url.infinityproject.fr`, API sur `api.url.infinityproject.fr`. Sinon le cookie de session du dashboard serait envoyé sur chaque redirection | GELÉ |
| D-103 | **Convention d'hôtes** : `infinityproject.fr` = maison, `<produit>.infinityproject.fr` = site du produit, **`api.<produit>.infinityproject.fr` = API du produit**. Permet un `api.quiz.…`, `api.qrcode.…` sans collision. Le cookie de rafraîchissement est **host-only sur l'hôte API** avec le préfixe `__Host-`, jamais un cookie `Domain` | GELÉ |
| D-81 | **Entité légale : `InfinityApp`**, entrepreneur individuel (micro-entreprise), nom commercial enregistré au RNE. C'est elle qui signe le DPA, figure sur les factures et dans les mentions légales. « Infinity Project » est la marque publique de la maison, pas l'entité | GELÉ |
| D-82 | **Pas de dépôt de marque en v0.1.** On s'appuie sur le nom commercial enregistré et les domaines. « Infinity » est trop saturé pour un dépôt serein et l'INPI coûte ~190 € par classe | GELÉ |
| D-83 | **RC professionnelle souscrite avant la première facture**, et clause de limitation de responsabilité dans les CGU (elle ne couvre pas les sanctions réglementaires) | GELÉ |
| D-84 | **`infinitylib` est le foyer des bibliothèques partagées** (thème, QR WASM, utilitaires). C'est là que vivent les paquets consommés par Infinity QRCode et Infinity URL, et c'est ce qui évite un second design system | GELÉ |
| D-85 | **Règle de nommage validée** : `InfinityApp` n'apparaît que dans les mentions légales, les factures et le DPA. « Infinity Project » est le seul nom que voit un client | GELÉ |

### Décisions d'implémentation (2026-11-21)

| # | Décision | Statut |
| --- | --- | --- |
| D-86 | Géolocalisation par **base locale gratuite** (DB-IP Lite), lookup en mémoire, aucun appel réseau au clic. Si l'intégration dépasse une soirée, **le pays est repoussé en v0.2** et la v0.1 n'affiche que clics et appareil | GELÉ |
| D-87 | Détection appareil et bots par **`matomo/device-detector`**, en local. Le user-agent est lu en mémoire, réduit à `mobile \| tablet \| desktop`, puis jeté. Aucun stockage de l'UA. Les bots sont écartés **avant** tout compteur | GELÉ |
| D-88 | **Deux dépôts** : `infinity-url-api` et `infinity-url` (le produit : dashboard, marketing, écrans). Le contrat est un **OpenAPI 3.1 généré depuis le code** (`dedoc/scramble`), publié sur `/openapi.json` et `/docs`, consommé par `openapi-typescript` côté web en CI | GELÉ |
| D-89 | **Pas de HATEOAS.** L'auto-découverte se limite à `GET /api/v1/me` → `capabilities: []`, et au contrat OpenAPI | GELÉ |
| D-90 | **`infinitylib` est déjà une organisation GitHub et npm active.** Le thème est publié et consommé par semver : `@infinitylib/infinity-theme@^0.2` (CSS, MIT) et `@infinitylib/infinity-theme-vue@^0.1` (wrappers Vue, MIT). **Aucune extraction de thème à faire en v0.1.** On n'extrait rien qui n'ait qu'un seul consommateur | GELÉ |
| D-93 | **Compatibilité de licences vérifiée** : les paquets `infinitylib` sont sous MIT (thème) et MIT/Apache-2.0 (`infinity-qrcode`). Les deux sont compatibles avec l'**AGPL-3.0-only** du shortener, aucun conflit | GELÉ |
| D-94 | **`@infinitylib/iqrcode-wasm@0.2.0` est déjà publié** (MIT/Apache-2.0, 81 Ko, `wasm-pack --target web`), ainsi que `@infinitylib/iqrcode-learn-wasm` pour le mode pédagogique. Aucune publication à faire (D-94 initialement prévue, annulée) | GELÉ |
| D-95 | **Spike de 30 min en S0** pour valider le chargement du binaire `.wasm` sous Vite/Nuxt. C'est le seul inconnu technique de la fondation | GELÉ |
| D-96 | **Blocklists par dump local, aucune API par URL.** URLhaus + Phishing Army téléchargés une fois par jour, stockés dans une table Postgres, vérification du domaine enregistrable à la création du lien. Aucune URL de client n'est envoyée à un tiers, et le Fair Use d'abuse.ch ne s'applique pas (1 requête par jour, pas 1 par lien) | GELÉ |
| D-97 | **Google Safe Browsing retiré du produit.** Le motif n'est pas technique : l'API envoie chaque URL créée à un sous-traitant américain, ce qui contredit le positionnement | GELÉ |
| D-98 | **Email transactionnel via le MX Plan OVH existant**, depuis `noreply@infinityproject.fr`, avec SPF, DKIM et DMARC configurés. Migration vers un provider dédié seulement si le volume dépasse quelques centaines d'emails par jour | GELÉ |
| D-99 | **Observabilité : Prometheus + Grafana + ntfy**, auto-hébergés, Grafana non exposé publiquement (accès par tunnel). Pas de service payant de remontée d'erreurs en v0.1 | GELÉ |
| D-100 | **Aucun nouveau service payant en v0.1.** Le seul coût ajouté est le bucket de sauvegarde (~1 €/mois) | GELÉ |
| D-101 | **Stratégie de test des deux côtés** (API et Nuxt), avec les tests d'invariants de conformité en CI. Détail dans `09-tests.md` | GELÉ |
| D-102 | **Sauvegarde locale en v0.1, pas de bucket.** Dump quotidien chiffré (`pg_dump -Fc` + `age`), **tiré depuis la machine personnelle** (modèle pull, le serveur ne détient aucun identifiant de sauvegarde), rotation 30 jours, RPO 24 h. Exercice de restauration obligatoire avant l'ouverture publique. Passage à un stockage objet hors site dès le premier client payant | GELÉ |
| D-104 | **i18n** : `@nuxtjs/i18n`, stratégie `prefix_except_default` (`/` = français, `/en/…` = anglais). La langue vit **uniquement dans l'URL** : aucun cookie, aucune redirection automatique. Le serveur ne traduit jamais ses messages d'erreur, il renvoie des codes | GELÉ |
| D-105 | **Contrat d'API** : RFC 9457 pour les erreurs avec un `code` stable, pagination par curseur, en-têtes IETF `RateLimit-*` (remplace `X-RateLimit-*`), scopes explicites sans `*`, versioning `/v1` additif avec 12 mois de chevauchement | GELÉ |
| D-106 | **Sémantique de redirection** : `302`, `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex`, `HEAD` non compté, pages d'erreur statiques sans JS. `404` slug inconnu, `410` supprimé ou expiré, `403` bloqué | GELÉ |
| D-107 | **Pipeline analytique** : empreintes calculées dans la requête (l'IP ne franchit jamais la frontière), **deux empreintes** (journalière et hebdomadaire), idempotence par UUID de job, bots filtrés avant enqueue. Échec **ouvert** sur la redirection, **fermé** sur les compteurs. Détail dans `11-pipeline.md` | GELÉ |
| D-108 | **Les huit cas d'usage manquants sont en v0.1**, identifiés par la matrice de `12-uml.md` §4 : renvoi d'email de vérification, changement de mot de passe, suppression de compte, désactivation 2FA, régénération des codes de récupération, révocation de toutes les sessions, export CSV, page `/abuse` | GELÉ |
| D-109 | **UML ciblé uniquement** : cas d'usage, machines à états, séquences. **Pas de diagramme de classes** (le schéma SQL est la vérité), pas de diagramme d'activité. Tout en Mermaid dans le dépôt | GELÉ |
| D-110 | **`users.email` chiffré au repos** (AES-256-GCM via `Crypt`) avec un index aveugle `email_hash char(64) unique` en HMAC-SHA256 utilisant **`BLIND_INDEX_KEY` dédiée**, distincte d'`APP_KEY` afin que la rotation de celle-ci ne casse pas l'unicité. Cast `EncryptedWithHash` repris du dépôt Hytale, **y compris `compare()`** pour éviter les écritures inutiles. `totp_secret` chiffré, codes de récupération hachés (Argon2id). Destination des liens et noms de domaine **en clair** : publics par nature | GELÉ |
| D-111 | **`GET /v1/me/export` en v0.1** (portabilité, art. 20) : export JSON construit par un `PersonalDataInventory` en **allowlist** (compte, workspace, liens, stats agrégées). Jamais les hash, jamais les secrets. Modèle repris du dépôt Hytale | GELÉ |
| D-112 | **Aucune liste de sessions exposée à l'utilisateur.** « Révoquer toutes les sessions » couvre le besoin de sécurité sans stocker ni afficher d'IP ni de user-agent, ce qui contredirait la promesse « aucune IP conservée » | GELÉ |
| D-113 | **Auto-surveillance de la réputation du domaine.** Un job quotidien interroge les blocklists (Safe Browsing, Spamhaus, URLhaus) pour `iurl.fr` et les domaines clients, et alerte via ntfy. Compense l'absence de second domaine au lancement : on apprend qu'on est blacklisté en 24 h, pas à la première plainte d'un client. Aucune donnée client n'est envoyée, seulement nos propres noms de domaine | GELÉ |
| D-114 | **Sanctum en mode token, jamais en mode session.** Accès et refresh vivent dans `personal_access_tokens` (+ `family_id`, `type`). Écart assumé à la recommandation Laravel (« ne pas utiliser de jetons API pour son propre SPA ») : le jeton d'accès ne vit qu'en mémoire JS, le refresh est `HttpOnly` + `__Host-`, et on gagne la rotation avec détection de rejeu ainsi que l'absence totale de CSRF (`SameSite=Lax` suffit) | GELÉ |
| D-115 | **Versions pinnées** : PHP 8.4, Laravel 13.x (exige PHP ^8.3), Sanctum 4.5, PostgreSQL 17, Valkey 8, Nuxt 4.x, Node LTS courant, FrankenPHP épinglé par digest. Les paquets hors Laravel sont limités aux besoins réels, listés dans `08-setup.md` §15 | GELÉ |
| D-116 | **Aucune route authentifiée sans ability.** Une route sans ability est un bug ; un test d'architecture le vérifie. Les scopes sont des abilities Sanctum | GELÉ |
| D-117 | **Renommer un slug** : l'ancien est inséré dans `slug_tombstones` et renvoie `410`. Jamais libéré | GELÉ |
| D-118 | **Supprimer un domaine qui porte des liens** : `409`, code `domain_has_links`. Pas de cascade | GELÉ |
| D-119 | **Suppression de compte** : logique immédiate, jetons révoqués, email occupé pendant 30 jours, connexion = réactivation, puis purge physique avec tombstones | GELÉ |
| D-120 | **Le pays est hors v0.1.** La v0.1 affiche clics et appareil. La dimension `country` existe en base et reste vide | GELÉ |
| D-121 | **`/internal/tls/ask` exige `X-Internal-Token`**, comparé en `hash_equals`, et échoue en refus par défaut | **DIFFÉRÉ (v0.2)** |
| D-122 | **Quota de liens** appliqué dans un service, en transaction avec `lockForUpdate` sur le workspace. Jamais dans le contrôleur | GELÉ |
| D-123 | **Réactivation** : un lien `disabled` se réactive en repoussant `expires_at` ; un lien `blocked` jamais par l'utilisateur ; un lien `deleted` jamais | GELÉ |
| D-124 | **`?q=`** cherche `slug` et `destination` en `ILIKE`, 2 caractères minimum, dans le workspace courant. Jamais l'email ni un hash | GELÉ |
| D-125 | **Emails** : expéditeur `noreply@infinityproject.fr`, `Reply-To` `contact@infinityproject.fr`, quatre messages seulement, sans image ni pixel | GELÉ |
| D-126 | **Vérification de destination** : refus sur preuve de danger (blocklist, adresse privée, schéma), acceptation sur absence de preuve de sûreté (timeout, DNS) | GELÉ |
| D-132 | **Liste de marques courte, conservatrice et sourcée** : ~120 entrées, jamais devinées. Trois sources — le dump de phishing hebdomadaire (découverte des marques réellement usurpées), le Majestic Million utilisé **une seule fois** comme point de départ, et les signalements reçus. Un job propose les ajouts, un humain les applique. Revue trimestrielle. **Oublier est bénin, se tromper est coûteux** : le typosquat seul ne bloque pas | GELÉ |
| D-134 | **Cache de redirection** : clé `link:{host}:{slug}`, valeur `{status, location}`, **une seule lecture Valkey**. Le chemin chaud ne connaît que l'hôte et le slug ; une clé par identifiant imposerait deux lectures ou un second aller-retour. Invalidations explicites à l'édition, au renommage (ancien **et** nouveau slug), à la suppression et au blocage. TTL : `302` → min(24 h, `expires_at`) ; `404` → 60 s ; `403` et `410` → 60 s pour qu'un déblocage remonte vite ; tombstone → 24 h | GELÉ |
| D-133 | **Aucun signal de popularité.** Le rang mesure les liens entrants, pas la fiabilité ; il double l'âge du domaine et introduit un biais mesuré (`hellobank.fr` 161 894, `b-and-you.fr` 963 189). Le signal généralisable est « domaine jeune + formulaire de connexion », pas « ressemble à une marque ». Le Majestic Million a servi **une seule fois**, à la main, pour bâtir la liste de départ : ni versionné, ni téléchargé par un job, ni redistribué | GELÉ |
| D-131 | **Détection de typosquat déterministe, sans modèle.** Une liste d'environ 200 marques protégées, versionnée dans le dépôt ; un job génère toutes leurs variantes (substitution, suppression, insertion, transposition, homoglyphes, changement de TLD, combosquat, concaténation) et les stocke indexées. À la création : une seule requête. S'y ajoutent la détection de marque **en sous-domaine** d'un domaine tiers et la détection de **scripts mélangés**. Motif citable obligatoire | GELÉ |
| D-128 | **Vérification de destination par score déterministe à trois issues** : refus (`422` + motif citable), revue manuelle, ou acceptation. Signaux : blocklist, adresse privée, âge du domaine (RDAP), punycode et scripts mélangés, marque dans un domaine tiers, TLD à fort taux d'abus, chaîne de redirection, TLS récent, signalements récents. **Aucun modèle dans la décision** | GELÉ |
| D-129 | **Un modèle de langage n'intervient qu'en assistant de tri des signalements**, en v0.2 : asynchrone, **outils en lecture seule**, décision humaine, et le modèle reçoit **des signaux structurés, jamais le contenu de la page** — ce qui supprime le vecteur d'injection de prompt. Jamais sur la création, jamais avec un outil d'écriture | GELÉ |
| D-130 | **Exposer un serveur MCP** en v0.2, pour qu'un agent (Claude, Cursor, autre) puisse créer un lien court avec un jeton à scope `links:write`. L'API est déjà le produit ; MCP en est une façade, pas un nouveau chemin | GELÉ |
| D-127 | **Les domaines custom sont hors v0.1.** Tous les liens vivent sur `iurl.fr`. Conséquences : pas d'endpoint `ask`, pas de TLS on-demand, pas de vérification DNS, pas d'écrans `/domains`, pas d'abilities `domains:*`. La table `domains` reste, semée d'une seule ligne `iurl.fr`, pour que la v0.2 n'ait aucune migration à faire. **Conséquence commerciale assumée : aucune offre payante en v0.1**, le revenu arrive avec la v0.2 | GELÉ |

> Le détail opératoire de D-117 à D-126 vit dans le pack ibrain `infinity-url`, règle `link-behaviour`, pour être servi à l'agent au moment où il touche le code concerné. Ce journal garde le *pourquoi*, le pack porte le *quoi*.
| D-91 | **`iquiz` consomme `iurl` par l'API publique**, avec un token à scope dédié. Jamais d'accès croisé aux bases. C'est le premier consommateur interne et la preuve de dogfooding | GELÉ |
| D-92 | **i18n FR + EN dès la v0.1** | GELÉ |

---

## 3. Questions ouvertes

| # | Question | État | Condition de réveil |
| --- | --- | --- | --- |
| Q-01 | Nom du **second domaine sacrifiable** | **DIFFÉRÉ** | Décidé : lancement avec `iurl.fr` seul. À acheter au premier incident de réputation, à la première alerte de suspension AFNIC, ou au premier client payant. D-113 compense en surveillant la réputation du domaine principal. |
| Q-02 | Referer : domaine seul en v0.2 ? | DIFFÉRÉ | Demande client réelle. |
| Q-03 | Liens protégés par mot de passe : implémentation sans état client (POST de formulaire + token signé courte durée) | DIFFÉRÉ | v0.2. |
| Q-04 | Bio page / link-in-bio | ABANDONNÉ | Ne pas rouvrir : ce n'est pas le produit. |
| Q-05 | i18n : FR primaire + EN secondaire dès la v0.1 ? | **RÉSOLU** | D-92. FR + EN dès le départ. |
| Q-06 | Dépôt de marque : « Infinity Project » (maison), « Infinity QRCode », « Infinity URL », « iurl » | DIFFÉRÉ | D-82 : pas de dépôt en v0.1, le nom commercial `InfinityApp` assure une protection de base. Rouvrir quand le revenu le justifie, après recherche d'antériorité INPI + EUIPO. |
| Q-07 | Import CSV depuis Bitly / Shlink | DIFFÉRÉ | Après la v0.1. |
| Q-08 | Attestation de provenance SLSA sur l'image publique | DIFFÉRÉ | Quand il y aura des utilisateurs self-host. |
| Q-09 | Passerelle de paiement : MoR (Paddle / Lemon Squeezy) plutôt que Stripe | DIFFÉRÉ | Quand la facturation arrive. Le MoR évite la TVA OSS et la facturation. |
| Q-10 | Seuil exact de déclenchement du split serveur (D-67) | OUVERT | À chiffrer dès que le trafic réel est connu. |
| Q-11 | Unifier la marque publique : `InfinityApp` (nom commercial) et « Infinity Project » (marque de la maison) coexistent | **RÉSOLU** | D-85. `InfinityApp` reste administratif, « Infinity Project » est le nom public. |
| Q-12 | Le nom commercial `InfinityApp` et le projet `InfinityApp` portent le même nom. Faut-il renommer le nom commercial en « Infinity Project » ? | **DIFFÉRÉ** | D-85 couvre déjà la communication : le nom n'apparaît que dans les mentions légales, factures et DPA. Le renommage est une formalité gratuite au guichet unique, à faire seulement si le projet InfinityApp est présenté publiquement. Ne bloque rien. |
