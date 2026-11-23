# 16 — Décision relative à la DPIA

> **Avertissement** : cette décision est une analyse interne, pas un avis
> juridique. Elle se conserve, se date et se révise. Elle ne dispense pas d'une
> relecture par un professionnel avant l'ouverture publique.

**Entité** : InfinityApp, entrepreneur individuel (micro-entreprise)
**Version** : 1.0 — brouillon
**Date** : 2026-11-23
**Rédigé par** : [À FOURNIR]
**Validé par** : [À FOURNIR]
**Prochaine revue** : 2027-11-23, ou au premier déclencheur de la partie 5

---

## 1. Question

Une analyse d'impact relative à la protection des données (DPIA, art. 35 du
RGPD) est-elle requise pour Infinity URL en v0.1 ?

**Réponse : non, en l'état.** Aucun des critères de l'article 35 et des lignes
directrices du CEPD n'est réuni à un niveau qui l'impose. La décision est
motivée ci-dessous et réexaminée dès qu'un des déclencheurs de la partie 5
apparaît.

---

## 2. Critères examinés

| Critère | Présent ? | Pourquoi |
| --- | --- | --- |
| Surveillance systématique à grande échelle | Non | La redirection ne lit aucun comportement, ne suit aucun parcours et ne persiste rien. La mesure d'audience compte des clics et des uniques par lien, sans profil. Aucun referer, aucune page vue, aucun croisement entre liens |
| Traitement de données sensibles (art. 9) | Non | Aucune donnée de santé, d'opinion, de religion, d'orientation sexuelle, de biométrie ou d'origine. Les données de compte se limitent à l'email et aux secrets d'authentification |
| Profilage ou décision automatisée | Non | Aucun profil visiteur, aucun score de personne, aucune décision automatisée sur un utilisateur. Le score de destination porte sur une URL, pas sur une personne, et une revue humaine décide |
| Évaluation ou notation de personnes | Non | Les compteurs sont agrégés par lien, jamais par personne |
| Observation systématique d'une zone accessible au public | Non | Le service observe des requêtes vers ses propres liens, pas une zone publique |
| Données de personnes vulnérables | Non | Aucune catégorie de public n'est ciblée ou identifiée |
| Usage innovant ou nouvelle technologie | Non | HMAC, sel à rotation et compteurs agrégés sont des techniques établies. Aucun modèle de langage en v0.1 (D-129) |
| Croisement de données | Non | La clé contient le `link_id` : deux liens produisent deux clés, la corrélation entre liens est mathématiquement impossible (D-02, D-03) |
| Empêchement d'exercer un droit ou d'accéder à un service | Non | Le service est un raccourcisseur ; aucun droit ni accès à un service essentiel n'en dépend |
| Rétention longue | Non | Empreintes ≤ 7 jours, agrégats 30 jours, logs 72 heures, signalements 12 mois (90 jours si rejetés), comptes + 30 jours de grâce, sauvegardes 30 jours |
| Grande échelle | Non en v0.1 | Instance hébergée naissante, inscriptions plafonnées, pas de clients payants. Le critère sera réexaminé à la croissance |

---

## 3. Analyse par traitement

| Traitement | Risque pour les personnes | Pourquoi il reste faible |
| --- | --- | --- |
| Redirection | Très faible | IP et user-agent en mémoire, jamais persistés, aucun cookie, aucun corps de réponse |
| Mesure d'audience | Faible | Deux empreintes HMAC avec sel détruit à la rotation, clé liée au lien, aucun pays, aucun referer, aucun profil, agrégats seulement |
| Comptes | Faible | Email chiffré, index aveugle, Argon2id, 2FA, jetons révocables, export et suppression |
| Emails transactionnels | Faible | Trois messages, sans image ni pixel, contenu limité à l'action demandée |
| Signalements | Faible | Aucune IP, aucun user-agent, aucun referer, aucune empreinte persistée ; email facultatif et chiffré ; réponse constante `202` |
| Vérification de destination | Faible | Une requête à la création, sur une URL fournie par l'utilisateur ; adresses privées refusées ; RDAP limité au domaine enregistrable ; échec ouvert |
| Logs | Faible | 72 heures, query strings retirées, application sans IP, pas d'export tiers |
| Sauvegardes | Faible | Dump chiffré, rotation 30 jours, modèle pull, serveur sans identifiant de sauvegarde |

Le point le plus sensible est la mesure d'audience : elle traite l'IP d'un
visiteur. Il est borné par le LIA-2, la destruction du sel, l'absence de
corrélation entre liens et l'absence de données comportementales. C'est
précisément ce qui maintient le risque à un niveau qui ne justifie pas une
DPIA.

---

## 4. Conclusion

Aucune DPIA n'est réalisée pour la v0.1. Les critères de l'article 35 ne sont
pas réunis : pas de surveillance systématique à grande échelle, pas de données
sensibles, pas de profilage, pas de décision automatisée, pas de croisement
entre liens, pas de rétention longue.

Cette conclusion ne vaut que pour le périmètre décrit dans `06-lia.md` et
`15-registre-traitements.md`. Elle ne couvre ni les domaines custom, ni la
facturation, ni l'assistant de tri par modèle de langage, qui sont hors v0.1.

---

## 5. Déclencheurs de réexamen

La décision devient à réexaminer, avant la mise en production du changement, dès
que l'un de ces points survient :

- ajout du referer, même limité au domaine ;
- ajout d'un profil visiteur, d'un comptage inter-liens ou d'une corrélation entre clients ;
- ajout du pays ou d'une granularité géographique plus fine ;
- allongement de la rétention des empreintes, des agrégats, des logs ou des signalements ;
- suppression de la destruction du sel, ou conservation de l'ancien sel ;
- ajout d'un cookie, d'un `localStorage` de suivi, d'un fingerprint ou d'un pixel ;
- ajout d'un script tiers, d'une police externe ou d'un outil de mesure sur le site ou sur le domaine de liens ;
- ajout d'un sous-traitant hors UE sur le chemin des clics, ou d'une API de réputation interrogée par URL ;
- ajout d'un modèle de langage dans le tri des signalements (D-129) ;
- ajout de la facturation, d'un prestataire de paiement ou de données de facturation ;
- ajout d'un domaine client, d'un certificat on-demand ou d'une vérification DNS ;
- croissance de l'instance hébergée au point de rendre la surveillance « à grande échelle » ;
- ajout d'une décision automatisée qui bloque un lien ou suspend un compte sans revue humaine.

---

## 6. Signature

| | |
| --- | --- |
| Rédigé par | [À FOURNIR] |
| Date | [À FOURNIR] |
| Validé par | [À FOURNIR] |
| Date | [À FOURNIR] |
| Version | 1.0 |
| Prochaine revue | 2027-11-23 |

**Ce que la signature atteste** : que les critères ont été examinés, que les
faits du registre et du LIA ont été vérifiés à la date indiquée, et que
l'absence de DPIA est assumée. Elle n'atteste pas d'une validation juridique
externe.
