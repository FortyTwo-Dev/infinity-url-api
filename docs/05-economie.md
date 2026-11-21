# 05 — Économie, coûts et canal

Document court, parce que la conclusion est simple : **le projet n'a pas besoin d'être rentable pour exister, et il ne faut pas construire l'économie avant d'avoir un utilisateur.**

---

## 1. Coûts réels de la v0.1

| Poste | Fournisseur | Coût mensuel estimé |
| --- | --- | --- |
| VPS | OVH | 12–20 € |
| Domaine `iurl.fr` | OVH | ~1 €/mois (10 €/an) |
| Second domaine | différé (Q-01) | 0 € |
| Email transactionnel | OVH MX Plan (déjà en place) | 0 € |
| Sauvegarde | Locale, tirée depuis la machine de l'exploitant (D-102) | 0 € |
| Supervision | Prometheus + Grafana + ntfy, auto-hébergés | 0 € (≈ 400 Mo de RAM) |
| Blocklists | URLhaus + Phishing Army (dump local) | 0 € |
| **Total** | | **≈ 13–21 €/mois** |

Le coût marginal d'un utilisateur supplémentaire en v0.1 est proche de zéro. La ressource rare n'est pas l'infrastructure, c'est **le temps de support et de réponse aux abus**.

**Seuil de rentabilité indicative** : à 13–21 €/mois de coûts, 3 abonnés à 10 € couvrent l'infrastructure. Le vrai seuil n'est donc pas financier.

---

## 2. Ce qu'on ne vend pas

Cette liste est aussi importante que la liste des features. Elle découle directement du cadre du projet (solo, temps partiel, pas d'astreinte).

| Non vendu | Raison |
| --- | --- |
| SLA contractuel | Impossible à tenir seul. Mentionné explicitement dans les CGU. |
| Support avec engagement de délai | Best effort, par email. |
| Négociation de DPA | Le modèle est fourni, à prendre ou à laisser. |
| SSO, audit log, provisioning SCIM | Ce sont les features entreprise par excellence. Le client qui les veut part en self-host. |
| Développement sur mesure | Hors modèle. |
| Migration assistée depuis Bitly ou Shlink | Un import CSV suffit, et le client Bitly n'est pas la cible. |
| Hébergement dédié | Le self-host est la réponse. |
| Onboarding en visio | La documentation est la réponse. |

La cohérence de cette liste est ce qui protège le temps disponible. Chaque exception coûte une soirée et crée un précédent.

---

## 3. Plans futurs (v0.2+, quand la facturation arrive)

**Aucun plan payant en v0.1** (D-127). Le domaine custom, seule raison de payer, arrive en v0.2 avec la facturation. La v0.1 est gratuite, sans Stripe ni tunnel de paiement : c'est un produit open source et une démo hébergée.

Ce tableau sert à vérifier que l'architecture ne se contredit pas.

| Plan | Contenu | Prix indicatif |
| --- | --- | --- |
| Gratuit | 5 liens actifs, domaine `iurl.fr` uniquement, stats 30 jours | 0 € |
| Pro | **Domaine custom (v0.2)**, liens illimités, stats 12 mois, API | 9 €/mois |
| Agence | Plusieurs domaines, plusieurs membres, export | 29 €/mois (v0.3) |

**Principe de prix** : un seul axe, pas de tarification à l'usage. Le prix par clic est incompréhensible pour un indépendant et transforme chaque pic de trafic en conversation de support.

**Paiement** : passer par un **merchant of record** (Paddle, Lemon Squeezy) plutôt que Stripe. Cela retire la TVA OSS, la facturation et les relances fiscales. Le coût en commission est largement inférieur au temps passé sur la conformité fiscale. Cela n'introduit aucun sous-traitant dans la chaîne de traitement des clics, donc ne contredit pas le positionnement.

---


### Benchmark tarifaire (relevé le 2026-11-21)

Le domaine custom est devenu un produit de commodité : il apparaît dès 5 $ chez certains. Le prix seul ne peut donc pas être l'argument ; c'est la conformité et l'hébergement UE qui doivent porter la différence.

| Outil | Gratuit | Premier plan avec domaine custom | Ce qu'il inclut |
| --- | --- | --- | --- |
| Dub | 25 liens/mois, 3 domaines | **Pro 30 $/mois** (27 $ annuel) | 1 000 liens/mois, 1 an d'historique |
| Bitly | 5 liens/mois, avec publicité | **Growth 29 $/mois** (35 $ mensuel) | 500 liens/mois, 120 jours d'historique |
| Short.io | **5 domaines**, 1 000 liens au total | Gratuit, puis Hobby 5 $ | Liens illimités à 18 $ |
| Rebrandly | 10 liens/mois | **Essentials 9 $/mois** | — |
| TinyURL | 30 liens/mois | **Pro 11 $/mois** (9 $ annuel) | 125 liens/mois, 3 domaines |
| SendURL / ReSlug | — | **9 $/mois** | — |

Deux lectures :

1. Le prix d'entrée avec domaine custom est **5 à 9 $** chez les petits acteurs, et **29-30 $** chez ceux qui vendent de la profondeur d'API et d'analytics. Il n'y a presque rien entre les deux.
2. Une offre à 9 € doit donc être **meilleure que TinyURL ou ReSlug au même prix** (liens illimités, API réelle, 12 mois d'historique), et **trois fois moins chère que Bitly ou Dub** pour un indépendant européen.

### Prix retenus pour la v0.2 (indicatif)

| Plan | Contenu | Prix HT |
| --- | --- | --- |
| Gratuit | 5 liens actifs, `iurl.fr` uniquement, 30 jours de stats | 0 € |
| **Pro** | Domaine custom, liens illimités, 12 mois de stats, API, export | **9 €/mois ou 84 €/an** |
| Agence | Plusieurs domaines, plusieurs membres, export | 29 €/mois (v0.3) |

Ce qui est assumé dans ce choix :

- **Ne pas descendre sous 5 €** : en dessous, les frais du merchant of record et le prix fixe par transaction mangent la marge, et le produit est perçu comme un jouet.
- **Ne pas monter à 19 €** : au-delà de 10 €, un indépendant doit *décider* d'acheter ; à 9 €, il s'abonne. La friction d'achat compte plus que 10 € de revenu.
- **Annuel à 84 €** (deux mois offerts) : du cash d'avance, et un churn divisé.
- **Zéro tarification à l'usage.** Le prix par clic est incompréhensible pour un indépendant et transforme chaque pic de trafic en conversation de support.

À 9 €, le net après commission du MoR est d'environ 8 €. Trois abonnés couvrent l'infrastructure ; dix abonnés représentent ~80 € nets par mois. À cette échelle, **le revenu est symbolique et le but est la preuve d'usage**, pas la rentabilité.

## 4. Quand commence la v0.2

La v0.1 est un produit open source et une démo hébergée, gratuits, sans facturation. Le revenu est un objectif de v0.2, pas de v0.1. Il faut donc dire **quand** la v0.2 commence, sinon elle ne commence jamais.

Trois déclencheurs, le premier atteint suffit :

| Déclencheur | Seuil |
| --- | --- |
| Demande répétée d'un domaine custom | Trois demandes distinctes, même vague |
| Usage réel | 20 comptes actifs et au moins une instance auto-hébergée hors de chez toi |
| Coût à financer | Les 13–21 €/mois deviennent gênants, ou le split serveur devient nécessaire |

### Contenu de la v0.2, dans cet ordre

1. **Domaines custom** — TLS on-demand, endpoint `ask`, vérification DNS, écrans `/domains`. C'est la feature qui justifie le paiement, donc elle précède la facturation.
2. **Facturation** — un merchant of record (Paddle ou Lemon Squeezy), un seul plan Pro, pas de tarification à l'usage.
3. **Page de tarifs** — seulement quand les deux premiers points existent.

### Ce qui n'arrive pas avant

Pas de page de prix, pas de tunnel de paiement, pas de plan Enterprise, pas de support contractualisé. Tant que la v0.2 n'est pas déclenchée, le produit est complet sans elles : c'est ce qui rend le report acceptable plutôt qu'inconfortable.

## 5. Canal d'acquisition

Décidé : **le dépôt et le bouche-à-oreille**. Pas de SEO payant, pas d'ads, pas de contenu programmatique.

Conséquences concrètes, à traiter comme des livrables et non comme des intentions :

| Levier | Livrable |
| --- | --- |
| README | La page de vente. Capture d'écran, GIF de la redirection, quickstart en 5 minutes, tableau comparatif honnête avec Shlink et Dub |
| Démo hébergée | Une instance publique fonctionnelle, pas un « contactez-nous » |
| Documentation d'auto-hébergement | Un `compose.yml` qui marche, testé en CI à chaque release |
| Licence et transparence | `LICENSE` correct, page « différences self-host / cloud » tenue à jour, lien Source dans le footer |
| Communautés | Hacker News (Show HN), Reddit r/selfhosted et r/webdev, LinuxFr, annuaires awesome-selfhosted et awesome-privacy |
| Contact direct | Les 50 premiers inscrits reçoivent un email personnel. C'est le seul « onboarding » que tu fais. |

**Ce qu'il faut mesurer** : le nombre d'étoiles n'est pas la métrique. Les métriques utiles sont le nombre d'instances réellement déployées (visible via un ping anonyme optionnel, à ne pas imposer), le nombre d'inscriptions organiques et le nombre de retours reçus.

**Ce qu'il ne faut pas attendre** : que le trafic vienne du site marketing. Un projet de cette taille est découvert par son dépôt, pas par sa landing page.

---

## 6. Positionnement face aux concurrents

| Concurrent | Modèle | Pourquoi on ne l'attaque pas frontalement |
| --- | --- | --- |
| Shlink | Self-host, mature, gratuit | On ne le bats pas sur les features. On se distingue par l'expérience hébergée et la promesse de minimisation. |
| Dub | AGPL + cloud, financé | On ne court pas la course aux dashboards. Ils l'ont gagnée. |
| Bitly, Short.io, Rebrandly | SaaS entreprise | Hors cible, hors moyen. |
| Kutt, YOURLS | Self-host simples | Différenciation par la conformité outillée et l'API, pas par la simplicité. |

**La phrase de positionnement à tenir** : *« Le raccourcisseur qui ne construit pas de profil de vos visiteurs, et qui le prouve — code ouvert, aucune IP conservée, aucun cookie. »*

C'est vérifiable, donc défendable. C'est aussi la seule promesse qu'un concurrent financé ne peut pas copier sans casser son propre modèle de tracking.

---

## 7. Écosystème Infinity Project

Le shortener n'est pas un produit isolé : il arrive après Infinity QRCode, qui est déjà en ligne, gratuit, open source et pédagogique. Cela change trois choses, toutes favorables.

| Levier | Effet |
| --- | --- |
| Crédibilité | Le shortener arrive sur une marque qui a déjà livré un produit open source fonctionnel. Le README d'Infinity URL peut pointer vers Infinity QRCode comme preuve de sérieux. |
| Coût de la v0.1 | Le thème et la lib QR WASM sont déjà écrits et vivent dans `infinitylib`. Le QR du shortener devient une intégration, pas un développement (D-76, D-84). |
| Acquisition | Les visiteurs d'Infinity QRCode sont exactement la cible : des gens qui génèrent des QR, donc des gens qui ont besoin de liens courts. Le cross-sell est un simple lien entre les deux sites (D-78), pas une intégration technique. |

**Ce que ça n'autorise pas** : construire un compte unifié entre les produits en v0.1. Un compte commun suppose un IdP, une gestion de sessions partagée et un registre de comptes inter-produits — c'est-à-dire exactement la plomberie que le doc open core décrivait, et qu'on a explicitement reportée. Un lien dans le footer suffit.

**Répartition des domaines** : `infinityproject.fr` porte la maison et le marketing, `iurl.fr` ne fait que rediriger et servir l'application. Cette séparation est ce qui permet de garantir, et de démontrer, qu'aucun script tiers ne tourne sur le domaine des liens.

---

## 8. Ce qui reste à décider

Rien de bloquant. Les points ouverts sont dans `01-decisions.md` :

- Q-01 : second domaine sacrifiable, différé. À acheter au premier incident de réputation, pas avant ;
- Q-09 : choix du MoR, au déclenchement de la v0.2 ;
- prix exacts, au déclenchement de la v0.2.

Aucun de ces points ne bloque le développement de la v0.1.
