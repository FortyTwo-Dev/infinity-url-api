# Infinity URL — dossier de conception

**Maison** : Infinity Project — `infinityproject.fr` (marque publique)
**Entité légale** : InfinityApp, entrepreneur individuel (micro-entreprise) — signataire du DPA, des CGU et des factures
**Produit** : Infinity URL, raccourcisseur d'URL privacy-first, open source, hébergé en France
**Domaine de liens** : `iurl.fr`
**Dépôts** : `infinity-url` (produit) et `infinity-url-api` (API)
**Licence** : AGPL-3.0-only
**Statut** : conception, avant v0.1
**Dernière mise à jour** : 2026-11-21

---

## Nomenclature

| Niveau | Nom | Domaine |
| --- | --- | --- |
| Maison | **Infinity Project** | `infinityproject.fr` |
| Socle technique | **Infinity Lib** (`infinitylib`) | [github.com/InfinityLib](https://github.com/InfinityLib) · npm `@infinitylib/*` |
| Produit 1 | **Infinity QRCode** | `qrcode.infinityproject.fr` |
| Produit 2 | **Infinity URL** | `iurl.fr` |
| Produit 3 (en préparation) | **iQuiz** | à définir — consommateur de l'API d'Infinity URL (D-91) |
| Projet en préparation | **InfinityApp** | à définir |
| Entité légale | **InfinityApp**, entrepreneur individuel | — |

> **Point d'attention** : le nom commercial de l'entité et un projet portent le même nom, `InfinityApp`. Juridiquement c'est possible, mais ça crée une confusion au moment des mentions légales et un problème le jour où le projet serait cédé (le nom commercial reste à l'entité). Voir Q-12.

Règles :

- Le nom de la maison n'entre pas dans le nom des produits, et inversement.
- `iurl.fr` est un **domaine de liens pur** : redirections uniquement, aucune page marketing indexée, aucun script tiers, aucune bannière, **aucun cookie**.
- Le marketing et les applications vivent sur le domaine de la maison.
- Le nom de maison reste dans le domaine de la maison ; le domaine de liens reste hors marque parce que la brièveté prime sur la lisibilité de marque sur le chemin de redirection. Même logique que `bit.ly` / `bitly.com`.
- Le thème et la lib QR (Rust/WASM) sont partagés entre les produits. Aucun second design system n'est créé pour Infinity URL.

### Plan de nom d'hôtes

| Hôte | Rôle | Cookies |
| --- | --- | --- |
| `iurl.fr` | Redirection des liens, rien d'autre | **Jamais** |
| `url.infinityproject.fr` | Dashboard + pages marketing d'Infinity URL | Non |
| `api.url.infinityproject.fr` | API publique et API consommée par le dashboard | Un seul : `__Host-iurl_refresh`, host-only |
| `<domaine-client>` | Redirection | **Jamais** |

Convention : `infinityproject.fr` = maison, `<produit>.infinityproject.fr` = site du produit, `api.<produit>.infinityproject.fr` = son API. Un futur `api.quiz.…` ou `api.qrcode.…` ne rentre pas en collision (D-103).

La raison du découpage est technique, pas esthétique : si le dashboard vivait sur `iurl.fr`, son cookie de session serait envoyé sur chaque redirection — c'est-à-dire sur 95 % du trafic, et en contradiction directe avec la promesse « aucun cookie sur les visiteurs ». Avec le dashboard sur le domaine de la maison, `iurl.fr` ne porte jamais de cookie, et l'invariant est vérifiable en une requête.

Le cookie de rafraîchissement vit **uniquement sur l'hôte API**, sans attribut `Domain`. Le dashboard, servi depuis un autre hôte, l'obtient par un `fetch` en `credentials: 'include'` : le navigateur l'envoie à `api.url.infinityproject.fr`, jamais à `url.` ni à `iurl.fr`. Un cookie `Domain=.url.infinityproject.fr` serait plus simple mais exposerait la session à tout futur sous-domaine sous `url.`.

---

## Documents sources (hors de ce dossier)

Deux documents de recherche antérieurs ont servi de base. Ils restent valables sur l'infrastructure, mais plusieurs de leurs décisions sont corrigées ici.

- `~/Downloads/architecture-shortener-privacy-first.md`
- `~/Downloads/open-core-une-app-deux-éditions.md`

Les corrections sont listées dans [`01-decisions.md`](01-decisions.md), section « Décisions invalidées ».

---

## Index

| Fichier | Objet |
| --- | --- |
| [00-Question.md](00-Question.md) | Boîte à questions tampon : en attente / traité |
| [01-decisions.md](01-decisions.md) | Journal des décisions, corrections des docs antérieurs, décisions encore ouvertes |
| [02-mvp-v0.1.md](02-mvp-v0.1.md) | Périmètre de la v0.1, ordre d'attaque, estimation, définition de « terminé » |
| [03-conformite.md](03-conformite.md) | Cartographie des données, bases légales, renvoi vers le LIA, clause client, rétention |
| [04-abus-et-domaines.md](04-abus-et-domaines.md) | Domaines, slugs, anti-phishing, takedown, DSA, playbook de délistage |
| [05-economie.md](05-economie.md) | Coûts réels, plans futurs, canal d'acquisition, ce qui n'est pas vendu |
| [06-lia.md](06-lia.md) | Analyses d'intérêt légitime : LIA-1 (InfinityApp responsable), LIA-2 (pour les clients), preuves techniques |
| [07-schema.md](07-schema.md) | Schéma PostgreSQL, contraintes qui rendent les promesses vraies, cycle de vie des jobs |
| [08-setup.md](08-setup.md) | Environnements, DNS, Traefik, compose, secrets, déploiement, logs, sauvegarde, supervision |
| [09-tests.md](09-tests.md) | Stratégie de test API et Nuxt, tests d'invariants de conformité, CI |
| [10-api.md](10-api.md) | Contrat d'API : auth, erreurs, pagination, rate limits, scopes, sémantique de redirection |
| [11-pipeline.md](11-pipeline.md) | Du clic au compteur : empreintes, idempotence des jobs, bots, modes de défaillance |
| [12-uml.md](12-uml.md) | Cas d'usage, matrice de couverture, machines à états, diagrammes de séquence |
| [14-ecrans.md](14-ecrans.md) | Inventaire des écrans du dashboard : rôle, états, capability, i18n |
| [resources/brands/](resources/brands/) | Liste de départ des marques protégées et son attribution |

Le dossier **13 n'existe pas** : les dix arbitrages d'implémentation (D-117 à D-126) sont dans le pack ibrain, règle `link-behaviour`, pour être servis à l'agent au moment où il touche le code concerné.

---

## Pack ibrain

La connaissance destinée aux agents ne vit pas dans ce dossier : elle vit dans un pack versionné.

```
~/.iapp/ibrain/packs/github.com/FortyTwo-Dev/iproject-ibrain-packs/infinity-url/
  ibrain.yaml
  rules/       product, privacy, api-contract, data-model,
               link-behaviour, analytics, frontend, testing, deployment
  skills/      adding-an-endpoint, adding-a-screen
```

`ibrain.yaml` à la racine de ce dossier le déclare en `layer: project`, ce qui permet de vérifier la résolution :

```sh
ibrain resolve app/Models/User.php --list     # data-model, link-behaviour, privacy, product
ibrain resolve app/pages/links.vue --list     # frontend, product
```

Pour l'utiliser dans les deux dépôts du produit, remplacer la source `path` par la source `git` :

```yaml
  - source: git
    url: "https://github.com/FortyTwo-Dev/iproject-ibrain-packs"
    ref: "main"
    pack: "infinity-url"
    layer: project
```

`AGENTS.md` est généré par `ibrain sync` ; seul l'en-tête manuel (ordre de lecture, commandes, étapes S0–S6) est écrit à la main. `.ai/rules/` est un arbre généré : ne jamais l'éditer.

### Où vit ce dossier

**Une seule copie**, dans `infinity-url-api/docs/`. C'est le dépôt qui décrit le système : schéma, contrat, pipeline.

Le dépôt produit `infinity-url` ne reçoit que `14-ecrans.md`, son `AGENTS.md` et le pack. Les règles `api-contract` et `data-model` du pack sont écrites pour être autosuffisantes : dupliquer ce dossier dans les deux dépôts garantirait qu'il diverge en deux semaines.

---

## Conventions de statut

- **GELÉ** — décidé, on ne revient pas dessus sans raison explicite.
- **OUVERT** — question identifiée, pas encore tranchée.
- **DIFFÉRÉ** — décidé de ne pas traiter avant une version ultérieure, avec la condition de réveil.

Chaque décision du journal porte un identifiant `D-xx`. Chaque question ouverte porte un identifiant `Q-xx`.

---

## Cadre du projet (à garder en tête)

Décidé en amont et structurant tout le reste :

- **Objectif** : revenu complémentaire, projet solo à temps partiel, apprentissage et partage open source.
- **Pas de critère d'échec commercial** : le projet survit en open source quoi qu'il arrive. La métrique de réussite est l'adoption du dépôt et ce qui est appris, pas le MRR.
- **Conséquence directe** : pas de vente entreprise, pas de SLA en v0.1, pas d'astreinte, pas de facturation en v0.1. Les gros clients vont en self-host.
- **Conséquence produit** : le dépôt GitHub, le README et la démo hébergée sont le canal d'acquisition. Le temps investi dans la documentation publique a un meilleur retour que le temps investi dans le dashboard.
