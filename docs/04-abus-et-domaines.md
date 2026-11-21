# 04 — Abus, domaines et réputation

Un raccourcisseur d'URL est, par construction, un outil d'amplification. C'est aussi la seule partie du produit capable de tuer le projet en une nuit : si `iurl.fr` atterrit sur Google Safe Browsing, tous les liens de tous les clients deviennent un écran rouge.

Ce document existe pour que la réponse soit préparée, pas improvisée.

---

## 1. Stratégie de domaines

| Domaine | Usage | Statut |
| --- | --- | --- |
| `infinityproject.fr` | Maison : marketing, présentation des produits, blog. **Aucune redirection de liens** | Acquis (certificat apex à réparer) |
| `url.infinityproject.fr` | Dashboard et marketing d'Infinity URL (D-80) | À configurer |
| `api.url.infinityproject.fr` | API publique et API du dashboard (D-80, D-103) | À configurer |
| `iurl.fr` | **Domaine de liens pur** : redirections uniquement. Aucun marketing indexé, aucun script tiers, aucun cookie | **Acquis** |
| Second domaine sacrifiable | Liens à risque : comptes d'essai, liens non vérifiés, import massif. Permet de couper sans toucher au principal | **DIFFÉRÉ** (Q-01). Lancement avec `iurl.fr` seul |
| Domaines clients | Fournis par le client, sous sa responsabilité, avec vérification d'appartenance | **v0.2** (D-127) |

### Règles non négociables

- **Jamais un sous-domaine comme isolation.** `free.iurl.fr` partage la réputation de `iurl.fr` au niveau du domaine enregistrable. Seul un domaine distinct isole.
- **Jamais de partage avec un autre projet.** Aucun autre service ne doit servir de liens sur `iurl.fr`. Si un autre projet a besoin d'un lien court, il passe par l'API et un slug, pas par un domaine commun.
- **`.fr` a un propriétaire qui peut suspendre.** L'AFNIC peut suspendre un domaine pour abus. C'est la raison principale d'avoir le second domaine : ce n'est pas seulement la réputation, c'est la survie.
- **Email sortant séparé.** Aucun email transactionnel ne part de l'IP du serveur. Un lien de phishing qui fait blacklister l'IP casserait la délivrabilité des autres projets hébergés sur la même machine.

---

## 2. Génération et gestion des slugs

| Sujet | Règle |
| --- | --- |
| Alphabet | `abcdefghijkmnopqrstuvwxyz23456789` — 26 lettres moins `l`, plus les chiffres `2` à `9` : **33 caractères**, tous sans ambiguïté à la lecture. **Minuscules uniquement** : un slug ne doit jamais dépendre de la casse |
| Longueur | 7 caractères → 33⁷ ≈ **4,3 × 10¹⁰** combinaisons. Collision possible à grande échelle : générer, insérer, réessayer sur conflit (contrainte unique) |
| Séquence | Jamais d'identifiant séquentiel, jamais de slug dérivé de l'ID de base |
| Slug custom | Insensible à la casse, stocké en minuscules, 2 à 64 caractères, `[a-z0-9_-]` |
| Suppression | Le slug est **tombstoné** : `410 Gone` pour toujours, jamais réattribué (D-51) |
| Réservation | Liste bloquante, appliquée **avant** toute génération et à la validation des slugs custom |

### Liste de réservation (à maintenir en base, pas en dur)

```
api app admin administrator login logout signup register auth account
dashboard settings billing billing invoices stats analytics links domains
qr p s static assets images img css js fonts health healthz ready
robots.txt sitemap.xml favicon.ico manifest.json .well-known
abuse report legal privacy terms dpa status docs blog help support
about pricing contact security source license
```

Compléter à chaque nouvelle route publique. Un test en CI doit échouer si une route de l'application entre en collision avec la liste.

### Énumération

Le risque n'est pas la collision, c'est le scan. Un attaquant qui parcourt les slugs peut cartographier ce que tu héberges, ce qui est à la fois une atteinte à la confidentialité des clients et un outil de ciblage.

- Negative caching de 60 s sur les slugs inexistants (D-47) ;
- rate limit sur les `404` par IP, avec la même technique de hash que pour l'analytique (jamais l'IP en clair) ;
- pas de page `404` qui suggère des slugs proches.

---

## 3. Vérification de la destination à la création

C'est le seul mécanisme d'anti-abus qui fonctionne sans surveillance des clics. Il s'exécute **une fois, à la création**, sur une URL fournie volontairement par l'utilisateur.

### 3.1 Contrôles

1. **Validité** : schéma `http`/`https` uniquement. Refus de `javascript:`, `data:`, `file:`, `ftp:`, et des URL contenant des identifiants (`user:pass@`).
2. **Pas de raccourcisseur en chaîne** : refus d'une destination qui pointe vers un autre raccourcisseur connu (liste courte), pour casser le rebond.
3. **Résolution DNS puis filtrage des adresses privées** : `127.0.0.0/8`, `10/8`, `172.16/12`, `192.168/16`, `169.254/16` (dont le metadata cloud `169.254.169.254`), `::1`, `fc00::/7`, `fe80::/10`. Toute destination interne est refusée.
4. **Résolution épinglée** : l'IP résolue est utilisée pour la connexion, afin d'éviter un rebinding DNS entre la vérification et la requête.
5. **Requête de contrôle** : `HEAD` puis `GET` partiel si nécessaire, timeout 3 s, taille de réponse plafonnée, maximum 3 redirections suivies, user-agent identifiable (`InfinityURL-AbuseCheck/1.0`).
6. **Blocklists locales** : vérification du domaine enregistrable de la destination contre une table Postgres alimentée par des dumps publics téléchargés une fois par jour, plus les signalements reçus. **Aucune API interrogée par URL** : aucune URL de client ne quitte l'UE.
7. **Heuristiques** : IP littérale en destination, excès de sous-domaines, homoglyphes/punycode suspect, URL très longue avec paramètres encodés.

### 3.2 Ce que la vérification n'est pas

- Ce n'est **pas** de la surveillance : on ne lit pas les clics des visiteurs, on ne connaît pas leur identité, on n'analyse pas le trafic.
- Ce n'est **pas** une garantie : une page propre au moment de la création peut devenir malveillante ensuite. D'où le signalement et le takedown.
- Ce n'est **pas** un scan de contenu profond : on ne récupère pas le corps complet pour l'analyser en v0.1.

### 3.3 Consentement à la vérification

La vérification signifie qu'Infinity URL émet une requête vers la destination, révélant son IP au site cible. Ce n'est pas un problème de conformité (l'URL est fournie par l'utilisateur, pas par un visiteur), mais cela doit être écrit dans l'AUP et dans la documentation, pour éviter toute surprise.

---


### 3.5 Typosquat : variantes précalculées, pas d'inférence

Un domaine comme `leposte.fr` face à la marque `laposte.fr` n'est pas un problème de langage : c'est une distance entre deux chaînes. On le traite de façon exacte, en local, en moins d'une milliseconde.

**Pourquoi pas un modèle** : il serait plus lent, non déterministe, non testable en CI, et incapable de produire le motif citable qu'exige la motivation d'un blocage.

#### Variantes couvertes

| Type | Exemple |
| --- | --- |
| Substitution | `leposte.fr` |
| Suppression | `lapost.fr` |
| Insertion | `lapostee.fr` |
| Transposition | `lapotse.fr` |
| Homoglyphe | `lаposte.fr` (а cyrillique) |
| Changement de TLD | `laposte.com` |
| Combosquat | `laposte-securite.fr` |
| Concaténation | `lapostefr.com` |
| **Marque en sous-domaine** | `laposte.fr.connexion.example.com` |
| **Scripts mélangés** | deux alphabets dans un même domaine |

#### Fonctionnement

1. Une liste d'environ **200 marques protégées**, versionnée dans le dépôt : banques, télécoms, livraison, administrations, marketplaces, services de paiement. Elle doit citer ses sources et se mettre à jour.
2. Un job génère toutes les variantes de chaque marque — quelques centaines de milliers d'entrées — et les stocke dans `lookalike_domains`.
3. À la création d'un lien : une seule requête indexée sur le domaine enregistrable, plus une vérification des labels du sous-domaine.


#### Construire la liste sans se tromper

Oublier une marque est **bénin** : le typosquat passe, donc le produit se comporte comme avant la fonctionnalité. Ajouter une mauvaise entrée est **coûteux** : on bloque un lien légitime. L'asymétrie impose une liste courte et conservatrice, pas exhaustive.

Trois sources, aucune ne dépend de la mémoire :

| Source | Ce qu'elle apporte | Fréquence |
| --- | --- | --- |
| **Dump de phishing** (Phishing Army, OpenPhish) | Les URL imitent la marque dans le sous-domaine ou le chemin : un comptage de fréquence révèle qui est **réellement** usurpé cette semaine | Hebdomadaire, automatique |
| **Majestic Million**, filtré `.fr` — **une seule fois, à la main** | Un point de départ objectif : les domaines les plus liés, donc ceux qui valent la peine d'être imités | Initiale, non récurrente |
| Signalements reçus | La source la plus fiable, la plus lente | Continu |

Le job de découverte **propose**, il n'applique jamais : il extrait les labels de sous-domaine du dump, écarte les génériques (`www`, `login`, `secure`, `account`), compte les fréquences, et soumet les nouvelles venues à une revue humaine.

#### Le détecteur ne dépend pas de la liste

| Signal | Source | Coût |
| --- | --- | --- |
| Domaine de moins de 30 jours | RDAP | gratuit |
| Chemin contenant `login`, `verify`, `secure`, `account` | analyse d'URL | local |
| Marque dans un sous-domaine tiers | locale | local |
| Blocklist, typosquat, signalements | déjà en place | local |

Un domaine jeune servant un formulaire de connexion est suspect **quelle que soit la marque imitée**. C'est ce qui rend une liste incomplète acceptable.

#### Pourquoi il n'y a pas de signal de popularité

Un classement de popularité a été envisagé puis écarté, pour trois raisons :

1. **Le rang mesure les liens entrants, pas la fiabilité.** Un site très lié n'est pas sûr — les sites populaires sont ceux qu'on compromet — et un site peu lié n'est pas malveillant. Confondre les deux est un contresens de catégorie.
2. **Il double l'âge du domaine.** Une campagne utilise un domaine enregistré quelques jours auparavant : l'âge l'attrape déjà, sans jeu de données. La popularité ne couvre que le domaine ancien et inconnu, que les blocklists et les signalements finissent par rattraper.
3. **Il introduit un biais mesuré.** Une banque de détail peu liée est classée « inconnue » : `hellobank.fr` au rang 161 894, `b-and-you.fr` au 963 189.

Coût évité : un téléchargement de 80 Mo, une table de 1 M de lignes, un job hebdomadaire, une licence à respecter et un biais à expliquer. **Le jeu n'en valait pas la chandelle.** Si les données de phishing révèlent plus tard un trou réel — des domaines âgés et inconnus — la décision se rouvrira.

#### Combinaisons et issues

| Signal | Issue |
| --- | --- |
| Typosquat seul | **Revue manuelle** — `leposte.fr` peut être une entreprise légitime |
| Typosquat + domaine de moins de 30 jours | Blocage |
| Typosquat + blocklist | Blocage |
| Typosquat + signalements récents | Blocage |
| Homoglyphe ou scripts mélangés | Blocage |
| Marque en sous-domaine d'un domaine tiers | Blocage |

**La règle qui compte** : le typosquat seul ne bloque jamais. Une campagne de phishing utilise un domaine enregistré quelques jours avant ; un typosquat ancien et calme est probablement légitime.

### 3.4 Blocklists : pourquoi un dump et pas une API

Une API interrogée par URL envoie l'URL du client à un tiers à chaque création de lien. C'est un transfert de données hors UE, et c'est exactement ce que le produit prétend éviter. La solution est de télécharger la liste **en entier**, une fois par jour, et de vérifier localement.

| Source | Éditeur | Couverture | Accès |
| --- | --- | --- | --- |
| **URLhaus** | abuse.ch (Suisse, décision d'adéquation) | URL de distribution de malware | Dump quotidien, Auth-Key gratuite |
| **Phishing Army** | Communauté, agrégation | Hameçonnage, agrège plusieurs sources | Téléchargement libre |
| **OpenPhish, flux gratuit** | OpenPhish | Hameçonnage récent | Gratuit, sans clé, mais très limité (~500 URL toutes les 12 h). Complément, pas source principale |
| Liste locale | InfinityApp | Signalements reçus, faux positifs connus | Table interne |

**Le Fair Use d'abuse.ch ne s'applique pas ici.** Le message que tu as lu concerne les *requêtes* à l'API. Notre design fait **une requête de téléchargement par jour**, pas une par lien. Sur 1 000 liens créés dans la journée, ça reste une requête.

**Stockage** : table Postgres, pas de chargement en mémoire.

```sql
create table blocklist_domains (
    domain     text primary key,
    sources    text[] not null,
    first_seen date not null,
    last_seen  date not null
);
```

Le rafraîchissement quotidien fait un `upsert` puis supprime les entrées absentes du dump depuis plus de N jours. Un `select 1 ... where domain = ?` sur quelques millions de lignes est instantané, et on ne paie pas 50 Mo de RAM par worker PHP. La vérification n'a lieu qu'à la création du lien, jamais au clic.

**Extraction du domaine enregistrable** : utiliser une Public Suffix List (par exemple `jeremykendall/php-domain-parser`, avec la liste mise en cache), sinon `a.b.example.co.uk` et `example.co.uk` seraient traités différemment. Sans ça, un attaquant contourne la liste en ajoutant un sous-domaine.

**Attribution** : les licences de ces flux demandent parfois un crédit. À mettre dans la page « à propos » et dans le README.

## 4. Signalement et retrait

### 4.1 Canaux

| Canal | Détail |
| --- | --- |
| `abuse@iurl.fr` | Adresse publiée dans les CGU, l'AUP, la page de signalement, et la fiche WHOIS |
| Page `/report` | Formulaire : URL courte, catégorie, email facultatif. Aucun compte, aucun cookie |
| `iurl.fr/report/{slug}` | `302` vers le formulaire prérempli. Le domaine de liens ne sert toujours que des redirections |
| Fiche WHOIS | Adresse abuse renseignée chez OVH et visible |
| DNS | Enregistrement TXT de contact abuse (bonne pratique, aide les plateformes) |

Aucun bouton de signalement sur la redirection elle-même : ce serait un interstitiel, donc un coût d'UX et un ralentissement sur 95 % du trafic légitime.

### 4.1 bis Le canal sans interstitiel

Un lien malveillant qui fonctionne **redirige** : le visiteur ne voit jamais notre domaine, donc on ne peut pas l'inviter à signaler. La seule victime qui atterrit chez nous est celle qui tombe sur `404`, `410` ou `403`. Le canal doit donc être *construisible* et non *affiché*.

**`iurl.fr` reste 100 % redirections**, y compris pour le signalement :

```
iurl.fr/report/{slug}  →  302  →  url.infinityproject.fr/report?slug={slug}
```

Le formulaire vit sur le domaine marketing, où une page a le droit d'exister. `report` est déjà dans la liste de slugs réservés, donc aucun lien ne peut s'y opposer. Une route à deux segments (`/report/{slug}`) ne peut pas entrer en collision avec un slug, qui ne contient pas de `/`.

| Surface | Couverture |
| --- | --- |
| Pied de page de `403`, `404`, `410` | La seule où la victime est déjà chez nous |
| `iurl.fr/report/{slug}` | Pour qui connaît le mécanisme |
| `abuse@iurl.fr`, WHOIS et TXT | Plateformes et robots |
| `/report` du site marketing, indexable | Le grand public qui cherche « signaler un lien » |

**Ce que le formulaire ne stocke jamais** : IP, user-agent, referer, cookie, compte. Le rate limiting utilise la même empreinte que l'analytique — HMAC avec le sel courant, contexte `report`, TTL d'une heure — et **l'empreinte n'est pas persistée dans la ligne de signalement**.

**Trois règles** :

1. La réponse est **toujours `202`**, que le slug existe ou non. Sinon le formulaire devient un outil pour cartographier les liens.
2. **Aucun signalement n'est automatique.** Un humain qualifie sous 48 h ; le scan de destination est un signal, pas une décision. Cela empêche de faire bloquer le lien d'un concurrent.
3. **Aucune preuve n'est demandée** au rapporteur. Pas de token de clic, pas de vérification : exiger une preuve tuerait l'anonymat.

L'email de contact est facultatif et **chiffré au repos** : il sert à répondre au rapporteur, jamais à l'identifier. Sans email, le signalement reste entièrement anonyme.


### 4.1 ter Assistant de tri (v0.2, D-129)

Le tri manuel est la référence. Un modèle de langage peut seulement le rendre plus rapide, **sans jamais décider**.

**Ce que le modèle reçoit** : des signaux calculés par nos outils, jamais la page de destination.

```json
{
  "url": "https://paypal-secure.example/login",
  "signals": {
    "registrable_domain": "example",
    "domain_age_days": 4,
    "blocklist_hits": [],
    "redirect_hops": 3,
    "tls_cert_age_days": 2,
    "punycode": false,
    "mixed_scripts": false,
    "brand_in_subdomain": "paypal",
    "reports_last_24h": 2
  }
}
```

Trois conséquences, et elles sont volontaires :

| Conséquence | Pourquoi |
| --- | --- |
| **Injection de prompt quasi éliminée** | Il n'y a pas de texte hostile à interpréter, seulement des faits typés calculés par notre code |
| **Aucune donnée personnelle transmise** | Ni le texte du rapporteur, ni son email, ni une IP (jamais stockée) |
| **Motif citable préservé** | Les raisons sont dans les signaux, pas dans la sortie du modèle |

**Ce que le modèle ne peut pas faire** : bloquer un lien, appeler un outil d'écriture, modifier un signalement. Ses seuls outils sont en lecture (RDAP, blocklist, résolution DNS, récupération d'en-têtes). Il rédige une note ; l'opérateur décide.

**Fragilité assumée** : la fonctionnalité est optionnelle, désactivable (`AI_TRIAGE_ENABLED=false` par défaut), et parle à une API compatible OpenAI. Si le fournisseur change de conditions, on remplace le modèle ou on la désactive — le produit reste identique.

### 4.2 Procédure

| Étape | Délai | Action |
| --- | --- | --- |
| 1 | Sous 24 h ouvrées | Accusé de réception |
| 2 | Sous 48 h | Qualification : malveillant / litigieux / infondé |
| 3 | Immédiat après qualification | Si malveillant : le lien passe en `403` avec une page d'explication, l'utilisateur est suspendu, l'agrégat est conservé pour la traçabilité |
| 4 | Sous 48 h | Notification à l'utilisateur concerné avec le motif (obligation de motivation, voir §5) |
| 5 | Immédiat | Ajout du domaine de destination à la liste locale si récidive |

Le lien bloqué doit renvoyer une page claire qui explique le blocage et propose `abuse@iurl.fr`, **sans rediriger**. `403` et non `404` : un `404` ferait croire à une erreur de l'utilisateur et pousserait à recréer le lien.

### 4.3 Suspension de compte

Un compte qui génère plusieurs liens malveillants est suspendu, pas seulement le lien. La suspension bloque la création mais laisse l'export des données, par respect du RGPD et pour éviter un faux sentiment de confiscation.

---

## 5. DSA — ce qui s'applique réellement

Infinity URL est un **service d'hébergement** au sens du Digital Services Act (il stocke, à la demande d'un utilisateur, des informations). Cela implique des obligations précises, et **une protection précise**.

| Obligation | Application |
| --- | --- |
| Point de contact unique pour les autorités et les utilisateurs | Email publié, réactif |
| Mécanisme de notification et d'action | `/report` + `abuse@`, procédure §4.2 |
| Motivation des restrictions | Notification à l'utilisateur avec le motif du blocage |
| Politique d'usage acceptable claire et publique | AUP |
| Transparence | Pas d'obligation de rapport public à cette taille (réservée aux très grandes plateformes) |

**Protection, et c'est le point important :** l'article 8 du DSA pose l'**absence d'obligation générale de surveillance**. C'est-à-dire que la loi elle-même t'autorise à ne pas surveiller les clics. Ta posture « anti-phishing sans surveillance » n'est pas seulement un choix marketing : elle est alignée avec ce que le droit te demande, à condition de réagir promptement sur notification. À écrire dans la documentation — c'est un argument de crédibilité rare.

Ce document ne remplace pas une lecture juridique du DSA appliquée à ton cas. Mais la structure ci-dessus couvre l'essentiel des obligations d'un service d'hébergement de petite taille.

---

## 6. Playbook de délistage

À exécuter le jour où `iurl.fr` ou le domaine secondaire apparaît sur une blocklist. Ne pas improviser ce jour-là.

| Plateforme | Point d'entrée | Ce qu'il faut préparer |
| --- | --- | --- |
| Google Safe Browsing | Search Console → sûreté → demande de réexamen | Preuve des retraits effectués, procédure d'abus, délai de réponse |
| Microsoft SmartScreen | Formulaire de soumission de site | Idem |
| Spamhaus | Formulaire de retrait, section domaine | Idem, plus la justification de la nature du service |
| VirusTotal | Demande de réanalyse des URL signalées | Liste des URL retirées |
| Quad9 / OpenDNS | Formulaires de catégorisation | Idem |
| OVH | Ticket support, si l'IP est concernée | Journal des actions |
| AFNIC | Procédure de restauration, si le domaine est suspendu | Idem |

**Prérequis qui font la différence :** une page publique `/abuse` qui décrit la procédure, une adresse abuse réactive, et un historique démontrable de retraits rapides. Les plateformes traitent mieux les opérateurs qui ont une procédure écrite que ceux qui répondent « on va faire mieux ».

**Correction d'une idée reçue** : un second domaine ne sauve pas les liens existants. Un lien court contient son domaine ; si `iurl.fr` tombe, tous les liens déjà créés tombent avec lui. Le second domaine n'isole pas rétroactivement, il isole *par segment* — typiquement les liens d'essai sur le domaine sacrifiable, les liens clients sur le principal.

Lancer avec un seul domaine (Q-01) est donc un choix assumé : toute la réputation est partagée. La contrepartie n'est pas l'isolation, c'est **la détection précoce**. D'où D-113 : un job quotidien interroge les blocklists pour `iurl.fr` et les domaines clients, et alerte via ntfy. On apprend qu'on est blacklisté en 24 heures, pas à la première plainte.

---

## 7. Contrôle des inscriptions

Décision D-21 et D-22 : ouvert, mais verrouillé.

| Mesure | Paramètre |
| --- | --- |
| Vérification email avant le premier lien | Obligatoire |
| Plafond de liens | 5 liens **actifs** par workspace (les supprimés libèrent le quota) |
| Domaine custom | Refusé sur ce tier (et indisponible en v0.1, D-127) |
| Rétention des stats | 30 jours |
| Rate limit de création de compte | Hash d'IP, TTL 24 h, 2 comptes par IP et par jour |
| Revue manuelle | Les 50 premiers comptes sont validés à la main. C'est aussi le meilleur moment pour parler à un utilisateur. |
| Kill switch | `SIGNUPS_OPEN=false` ferme les inscriptions sans déploiement, en 10 secondes |

Le plafond n'est pas un levier de prix : c'est un fusible. Quand la facturation arrivera, elle branchera une table de plans sur le même mécanisme.

---

## 8. Réputation croisée avec les autres projets hébergés

Le risque n'apparaît pas « quand le produit aura une audience ». Il apparaît **au premier incident**, quel que soit le nombre d'utilisateurs.

| Risque | Mitigation en v0.1 |
| --- | --- |
| Un pic de trafic sur le shortener rend les autres projets injoignables | Limites cgroups par conteneur, plafond de threads FrankenPHP |
| Un lien malveillant fait blacklister l'IP sortante | Email via provider, jamais le SMTP du serveur |
| Le shortener sature le disque avec ses logs | Purge à 72 h avec plafond dur, testée |
| Une attaque DDoS sur le shortener affecte tout | Protection DDoS de l'hébergeur OVH, rate limits Traefik, negative caching |
| Un incident oblige à redémarrer la machine | Projet compose autonome : `compose up` sur une nouvelle machine + bascule DNS |

**Déclencheur de split (D-67)** — à chiffrer dès que le trafic réel est connu, mais formulé dès maintenant :

> Le shortener quitte la machine partagée si l'une de ces conditions est remplie : dépassement du seuil de clics quotidiens défini, incident de disponibilité ayant affecté un autre projet, ou apparition du domaine sur une blocklist.

---

## 9. Récapitulatif des actions avant ouverture publique

1. Acheter le second domaine (Q-01).
2. Publier `/abuse` et l'adresse `abuse@`.
3. Rédiger l'AUP.
4. Configurer le rafraîchissement quotidien des blocklists (URLhaus + Phishing Army) et vérifier que le job tourne.
4 bis. Configurer l'auto-surveillance de réputation (D-113) : `iurl.fr` et les domaines clients testés chaque jour contre les blocklists, alerte ntfy.
5. Écrire et tester la liste de réservation des slugs.
6. Vérifier que le kill switch fonctionne.
7. Faire un exercice de takedown à blanc, chronométré.
8. Renseigner le contact abuse dans le WHOIS et en TXT.
