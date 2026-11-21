# 02 — Périmètre de la v0.1

Version qui doit sortir en quelques mois à temps partiel. Le critère de découpe n'est pas « qu'est-ce qui est utile » mais **« qu'est-ce qui justifie qu'un inconnu clone le dépôt ou s'inscrive »**.

---

## 1. Dans le périmètre

| Domaine | Contenu exact |
| --- | --- |
| Compte | Inscription email + mot de passe, email vérifié avant le premier lien, 2FA TOTP, réinitialisation de mot de passe |
| Workspace | Un workspace par utilisateur à l'inscription, table et modèles prêts pour le multi-workspace ultérieur |
| Liens | Création, édition de la destination, édition du slug custom, suppression, expiration par date |
| Slugs | Génération 7 caractères (alphabet sans confondables), slug custom, liste de réservation, tombstones `410 Gone` |
| Redirection | `302`, cache Valkey, negative cache, singleflight, invalidation à l'édition et à la suppression |
| Stats | Clics par jour et par classe d'appareil, uniques par jour et par semaine **par lien**. Le pays est repoussé en v0.2 (D-120) |
| QR | Vue d'un lien, génération WASM côté client, export PNG et SVG |
| API | `POST /links`, `GET /links`, `GET /links/{id}`, `PATCH /links/{id}`, `DELETE /links/{id}`, `GET /links/{id}/stats` ; tokens Bearer avec scopes et rotation |
| Abus | Scan de la destination à la création, `abuse@iurl.fr`, page de signalement, takedown sous 48 h, kill switch d'inscription |
| Front | Marketing prérendu, dashboard en SPA, pages légales, documentation d'auto-hébergement |
| Exploitation | compose unique, healthchecks, sauvegarde quotidienne, purge des logs à 72 h, job de rétention des agrégats |

---

## 2. Hors périmètre (et pourquoi)

| Exclu | Raison |
| --- | --- |
| Facturation, plans, quotas payants | Aucun client à facturer. Décision D-20. |
| **Domaines custom** | D-127 : hors v0.1. La table reste semée d'une ligne `iurl.fr`, les endpoints et les écrans arrivent en v0.2 |
| **Pays dans les statistiques** | D-120 : la colonne existe, la dimension reste vide jusqu'en v0.2 |
| SSO / OIDC | Personne ne le demande à ce stade, et c'est la feature cloud-only par excellence. |
| Équipes, rôles, invitations | Le multi-utilisateur multiplie les questions de permissions et de support. Un workspace = un owner en v0.1. |
| Liens protégés par mot de passe | Conflit avec l'invariant « aucun état sur le terminal ». Mérite un design dédié. |
| Ciblage device / géo, deep links | Complexifie le modèle et les stats sans valider le produit. |
| Webhooks, SDK, bulk, idempotency | Confort développeur. À faire quand il y aura des développeurs. |
| Referer, UTM, uniques inter-liens | Décisions D-16, D-03. |
| Page bio | Décision D-10. |
| Import Bitly / Shlink | Décision D-13. |
| Extension qui observe la navigation | Une redirection `302` sans corps ne peut pas porter de content script : détecter nos redirections exigerait `webRequest` sur tous les sites, donc lire la navigation de l'utilisateur. Incompatible avec le produit. Le signalement anonyme existe déjà sans extension, via `POST /report` |
| Package bridge open core, quadlet, builds reproductibles, CDN, HA | Décisions D-07, D-08, D-14, D-11, D-65. |
| Microservice de redirection | D-53. |
| Application mobile | Aucun canal pour la distribuer à ce stade. |
| **Extension navigateur** | Refusée, pas seulement différée. Voir la note ci-dessous. |

---

## 3. Ordre d'attaque

L'ordre est dicté par le risque, pas par la dépendance technique. **Les domaines custom sont sortis du périmètre (D-127)** : plus de spike TLS, plus d'endpoint `ask`, plus de vérification DNS. Le risque principal restant est le chargement du WASM sous Vite, traité en S0.

| Étape | Contenu | Estimation |
| --- | --- | --- |
| S0 — Fondations | Dépôt, `LICENSE` AGPL-3.0-only, CI (lint, tests, audit de licences), compose de dev, healthchecks, squelette Laravel + Nuxt avec `@infinitylib/infinity-theme` et `@infinitylib/infinity-theme-vue` installés dès le départ, **spike de 30 min sur le WASM QR** (D-95). Inclut : correction du certificat de l'apex `infinityproject.fr` | 8–10 h |
| S1 — Chemin chaud | Schéma Postgres, route de redirection isolée (sans session), cache Valkey, negative cache, singleflight | 16–24 h |
| S2 — Liens et slugs | CRUD, génération, réservation, tombstones, API tokens, **OpenAPI généré** (`dedoc/scramble`) et types TS générés côté web. Inclut les compléments de compte issus de la matrice de cas d'usage : renvoi d'email, changement de mot de passe, suppression de compte, désactivation 2FA, codes de récupération, révocation des sessions. Inclut **chiffrement de l'email + index aveugle** et **`GET /me/export`** | 27–38 h |
| S3 — Analytics | File Horizon, dédup HMAC, compteurs, filtrage bots (`device-detector`), job de rétention, rotation et destruction du sel. **Le pays n'est pas traité en v0.1** (D-120) : la colonne existe, la dimension reste vide | 20–30 h |
| S4 — Dashboard | Liste, création, édition, page de stats (un graphique suffit), **export CSV**, QR via `@infinitylib/iqrcode-wasm` | 26–34 h |
| S5 — Abus | **Score de destination à trois issues** (blocklist, RDAP, TLS, redirections) ; **typosquat déterministe** (liste courte sourcée, variantes précalculées, sous-domaine, scripts mélangés) ; **job de découverte de marques** ; motif citable ; `POST /report` anonyme ; `iurl.fr/report/{slug}` ; **page `/abuse`** ; takedown ; kill switch ; AUP | 22–28 h |
| S6 — Légal et docs | CGU, AUP, politique de confidentialité, page sous-traitants, clause client, LIA, README, quickstart self-host | 16–24 h |

**Total : 137–192 h.** À 8–10 h par semaine : **3 à 4 mois**. Avec une marge de sécurité réaliste, compter 4 à 5 mois.

**Seul inconnu technique de S0** : le chargement du binaire `.wasm` sous Vite/Nuxt. Le paquet est construit avec `wasm-pack --target web`, ce qui exporte un module ESM dont la fonction `init()` doit être appelée avant usage, et dont le `.wasm` est résolu à l'exécution. C'est un détail de configuration Vite, pas un obstacle — mais c'est le genre de chose qui coûte une soirée si elle est découverte en S5. D'où le spike de 30 minutes en S0.

Si le calendrier doit être tenu à 4 mois, la seule coupe acceptable est le **QR** (S5 partiel) : il est agréable mais ne conditionne ni l'inscription ni le paiement futur. Les domaines custom, eux, ne se coupent pas.

---

## 4. Définition de « terminé » pour la v0.1

La v0.1 est terminée quand, dans cet ordre :

1. Un inconnu clone le dépôt, lance `podman compose up`, et obtient une instance fonctionnelle en moins de 10 minutes, avec un README qui ne ment pas.
2. Un inconnu s'inscrit sur l'instance hébergée, vérifie son email, crée un lien, le partage et voit un clic apparaître dans les stats.
3. La CI est verte, incluant le test qui vérifie qu'aucun cookie et aucune écriture `localStorage` n'existent sur le chemin de redirection.
4. Les pages légales sont publiées et la clause client est copiable depuis la documentation.
5. Le fichier `LICENSE` est correct, le champ `license` est renseigné dans les deux manifestes, et le lien « Source » est présent dans le footer.
6. Une sauvegarde a été restaurée avec succès au moins une fois, chronométrée.

---

## 5. Ce qu'on ne fait pas pendant la v0.1

Pour éviter la dérive, liste explicite des comportements interdits :

- Ajouter une feature parce qu'un concurrent l'a. Le concurrent a une équipe ; toi tu as un canal qui n'existe pas encore.
- Construire un dashboard plus riche avant d'avoir 10 utilisateurs.
- Optimiser le chemin de redirection avant d'avoir mesuré un problème.
- Écrire du code cloud-only « en attendant » dans le cœur.
- Ajouter un service externe non-EU dans la chaîne de traitement des clics.
- Promettre une disponibilité ou un support dans un document public.
