# 03 — Conformité et traitement des données

> **Avertissement** : ce document est une analyse technique et un modèle de travail, pas un avis juridique. La formulation finale des mentions publiées doit être relue par un professionnel avant l'ouverture publique. Ce qui suit est conçu pour rendre cette relecture rapide et peu coûteuse, pas pour la remplacer.

---

## 1. La question à laquelle ce document répond

Le produit revendique « pas de bannière de consentement ». Cette revendication est-elle tenable ?

**Oui, mais pas pour la raison intuitive, et sous trois conditions.** Le raisonnement correct est le suivant, et il doit être écrit tel quel dans la documentation publique.

### Condition 1 — La bannière ne vient pas du RGPD

La bannière de consentement découle de l'article 5(3) de la directive ePrivacy : elle est déclenchée par **l'écriture ou la lecture d'une information sur le terminal du visiteur** — cookie, `localStorage`, fingerprinting, pixel tiers.

Sur le chemin de redirection, Infinity URL ne dépose **rien** sur le terminal. Aucun déclencheur ePrivacy. C'est indépendant du fait qu'on traite ou non une IP côté serveur.

**Invariant à tester en CI** : sur une requête de redirection, aucune réponse ne contient d'en-tête `Set-Cookie`, et aucune page servie sur le domaine de redirection ne charge de script tiers.

### Condition 2 — Recevoir une IP n'est pas une base légale, mais ce n'est pas interdit

C'est l'erreur à ne pas commettre dans un document public. « L'IP est déjà dans les logs donc c'est bon » est faux : la nécessité technique du transport n'autorise pas la réutilisation de la donnée pour une autre finalité.

La chaîne correcte :

1. Recevoir une IP dans une requête HTTP est techniquement nécessaire à toute connexion. Ce n'est pas un stockage ni un accès au terminal, donc l'article 5(3) n'est pas déclenché.
2. L'IP est une donnée personnelle (CJUE, *Breyer*, C-582/14). Son traitement doit donc reposer sur une base de l'article 6 du RGPD.
3. Le consentement n'est pas la base par défaut. Pour de la mesure d'audience agrégée, la base naturelle est **l'intérêt légitime** (art. 6.1.f), documenté par le test de mise en balance ci-dessous.
4. La CNIL admet explicitement le hachage de l'IP avec sel pour produire des statistiques d'audience, sous conditions (finalité limitée, pas de recoupement cross-site, pas de partage pour d'autres usages, durée limitée, statistiques anonymisées).

### Condition 3 — Le périmètre doit être verrouillé

L'intérêt légitime ne couvre pas n'importe quel usage. Les bornes que le produit s'impose :

- **Uniques par lien uniquement.** Jamais de corrélation entre deux liens, encore moins entre deux clients. La clé intègre le `link_id` précisément pour rendre cette corrélation impossible.
- **Une semaine maximum.** Le sel est détruit à la rotation.
- **Aucune donnée comportementale.** Pas de pages vues, pas de parcours, pas de referer.
- **Aucun export de l'identifiant de dédup.** Il ne quitte jamais le job d'agrégation.

---

## 2. Cartographie des traitements

| Traitement | Données | Finalité | Base légale | Rétention | Destinataire |
| --- | --- | --- | --- | --- | --- |
| Redirection | IP (mémoire), slug, user-agent (mémoire) | Fournir la redirection | Exécution du contrat (art. 6.1.b) avec le client | Aucune persistance | Client (via ses stats) |
| Mesure d'audience | HMAC(sel, IP, link_id) journalier et hebdomadaire ; classe d'appareil ; date. **Aucun pays en v0.1 (D-120)** | Comptage de clics et d'uniques | Intérêt légitime (art. 6.1.f) + LIA-2 | Empreinte : jusqu'à la rotation du sel (≤ 7 jours). Agrégats : 30 jours par défaut (`STATS_RETENTION_DAYS`) | Client, sous forme agrégée |
| Comptes | Email **chiffré au repos** + index aveugle, mot de passe haché (argon2id), secret TOTP chiffré, codes de récupération hachés | Authentification, export et suppression | Exécution du contrat | Durée du compte + 30 jours de grâce | Aucun |
| Emails transactionnels | Email, contenu du message | Vérification, réinitialisation, notification de blocage | Exécution du contrat ; obligation légale de motivation pour le blocage (DSA) | Durée d'envoi + 30 jours chez le fournisseur | OVH (MX Plan) |
| Signalements d'abus | Slug signalé, catégorie, texte libre, email facultatif chiffré. **Aucune IP, aucun user-agent, aucun referer, aucune empreinte de rate limiting persistée** | Qualifier et retirer un lien illicite | Intérêt légitime (anti-abus, couvert par le LIA-1) | 12 mois, 90 jours si rejeté | Exploitant ; ntfy auto-hébergé (référence, slug, catégorie) |
| Vérification de destination | URL fournie par l'utilisateur, domaine enregistrable, DNS, IP résolue, en-têtes et statut HTTP, dates TLS, âge RDAP, résultat des blocklists, marqueurs de revue | Prévenir l'hébergement de liens malveillants | Intérêt légitime (LIA-1) | Marqueurs de revue : durée de vie du lien. Résultat non conservé hors marqueurs. Logs : 72 h | Site cible et service RDAP (destinataires du contrôle, **pas** des sous-traitants) ; exploitant |
| Logs techniques | IP (couche d'accès), méthode, URI sans query string, statut | Sécurité, diagnostic | Intérêt légitime (sécurité) | **72 h** | Aucun |
| Sauvegardes | Dump PostgreSQL chiffré | Restauration du service | Intérêt légitime (continuité) / contrat | 30 jours glissants | Aucun (machine de l'exploitant) |
| Facturation (v0.2+) | Coordonnées, historique de paiement | Facturation | Obligation légale + contrat | Durée légale comptable | Prestataire de paiement (MoR) |

**Ce qui n'existe pas, et ne doit jamais apparaître dans ce tableau :** ligne de clic individuelle, adresse IP persistée, referer, cookie analytique, profil visiteur.

---

## 3. LIA — déplacé dans un document autonome

Les analyses d'intérêt légitime sont dans **[`06-lia.md`](06-lia.md)**, et non ici. Raison : un LIA est une pièce juridique datée, versionnée et signée, qui a son propre cycle de vie. Dupliquée dans un document de conception, elle finirait par diverger de la version signée.

`06-lia.md` contient deux analyses distinctes, parce que le produit a deux responsables de traitement :

| | Responsable | Objet |
| --- | --- | --- |
| **LIA-1** | InfinityApp | Logs de sécurité et anti-abus, pour son propre compte |
| **LIA-2** | Le client d'Infinity URL, InfinityApp étant sous-traitant | Mesure d'audience des liens |

Les données de compte et les emails transactionnels ne relèvent **pas** de l'intérêt légitime : leur base est l'exécution du contrat (art. 6.1.b). Les inclure dans un LIA serait une erreur.

Résumé de la conclusion, qui doit rester vraie : l'intérêt légitime prévaut **parce que** le périmètre est étroit. La clé contient l'identifiant du lien, le sel est détruit à la rotation, l'IP n'est jamais persistée, et aucune donnée comportementale n'est dérivée. Toute extension fait basculer l'analyse et impose de refaire le document.

---

## 4. Ce que le client doit publier

Texte à fournir, prêt à adapter. C'est la contrepartie de la condition 3 : sans cette mention chez le client, la base légale ne tient pas.

> **Mesure d'audience des liens**
>
> Les liens courts créés avec ce service enregistrent, lors de chaque ouverture, des statistiques agrégées : nombre de clics et type d'appareil. Ces statistiques servent uniquement à mesurer l'audience de nos liens.
>
> Aucun cookie n'est déposé sur votre appareil. Aucune adresse IP n'est conservée. L'adresse IP est utilisée de façon transitoire pour produire une empreinte technique associée à un seul lien. Cette empreinte est supprimée au plus tard au bout d'une semaine et ne peut plus être recalculée ensuite.
>
> Ces statistiques ne permettent pas de vous identifier et ne sont jamais recoupées entre plusieurs liens. Vous pouvez vous opposer à ce traitement en nous contactant à l'adresse indiquée ci-dessous : [À FOURNIR : adresse de contact du client].

Le client reste responsable de l'insertion de ce texte et de la gestion du droit d'opposition. Infinity URL doit documenter cette responsabilité dans son DPA.

---

## 5. Rétention

| Donnée | Durée | Mécanisme |
| --- | --- | --- |
| Clé de dédup | Jusqu'à la prochaine rotation du sel (≤ 7 jours) | TTL Valkey + destruction du sel |
| Agrégats (compteurs) | 30 jours par défaut sur l'instance hébergée v0.1 ; valeur de `STATS_RETENTION_DAYS` en self-host | Job de purge quotidien |
| Empreinte de rate limiting des signalements | 1 heure | TTL Valkey, jamais persistée |
| Logs techniques | 72 h | Logrotate / journald avec plafond dur |
| Emails transactionnels | Durée d'envoi + 30 jours chez le provider | Configuration du provider |
| Comptes | Durée du compte + 30 jours de grâce | Suppression logique puis physique |
| Signalements | 12 mois, 90 jours si rejeté | Job de purge quotidien |
| Marqueurs de revue de destination | Durée de vie du lien | Suppression du lien |
| Sauvegardes | 30 jours glissants | Rotation locale, dump chiffré tiré depuis la machine de l'exploitant |

Le pays n'est pas traité en v0.1 (D-120) : la colonne existe, la dimension reste
vide. Le résultat du contrôle de destination n'est pas conservé en dehors des
marqueurs de revue.

**Effacement.** Supprimer un lien supprime ses agrégats. C'est la raison pour laquelle il n'y a pas de lignes de clic : avec des compteurs, la promesse d'effacement est tenue en une requête. Les sauvegardes peuvent contenir le lien jusqu'à 30 jours ; la politique publiée doit le dire honnêtement plutôt que promettre un effacement instantané impossible.

---

## 6. Sous-traitants (page publique à tenir à jour)

| Sous-traitant | Rôle | Localisation | Données concernées |
| --- | --- | --- | --- |
| OVH | Hébergement VPS puis serveur dédié | France | Toutes, en transit et au repos |
| OVH (MX Plan) | Emails de compte, de vérification et de blocage | France | Email, contenu des messages |
| URLhaus / abuse.ch | Flux de blocklist téléchargé en masse | Suisse (adéquation UE) | Aucune donnée client : le dump est public et identique pour tous |
| Prometheus, Grafana, ntfy | Supervision auto-hébergée | **Sur le même serveur, UE** | Métriques agrégées ; alertes internes avec référence, slug et catégorie d'un signalement, jamais le texte, l'email ni l'adresse du rapporteur |

**Aucun stockage objet en v0.1** : la sauvegarde est un dump local chiffré, tiré
depuis la machine de l'exploitant (D-102). L'IA de tri et le prestataire de
paiement sont v0.2 et ne sont pas actifs (D-129, D-127) ; ils ne figurent pas
dans la page publique comme sous-traitants actuels.

**Destinataires, pas sous-traitants** : lors du contrôle de destination, le site
cible reçoit notre IP et l'URL testée, et le service RDAP reçoit le domaine
enregistrable seul (`04-abus-et-domaines.md` §3.3). Ils ne traitent pas de
données pour notre compte.

**Règle** : aucun sous-traitant hors UE dans la chaîne de traitement des clics. Les prestataires hors UE (paiement) ne sont jamais dans cette chaîne, et la formulation publique doit être « aucune donnée de navigation ne quitte l'Union européenne », pas un « 100 % UE » global qui serait faux.

---

## 7. Documents à produire avant l'ouverture publique

Attention à l'identité : les documents contractuels et les mentions légales nomment **InfinityApp, entrepreneur individuel**, pas « Infinity Project » ni « Infinity URL » (D-81). « Infinity Project » est une marque : elle ne peut pas être partie à un contrat.

| Document | Statut |
| --- | --- |
| Mentions légales (LCEN art. 6-III) | **À publier en S6.2**, prose prévue dans `app/lib/legal/fr.ts`. Doit contenir : identité `InfinityApp`, statut d'entrepreneur individuel, SIREN, adresse, numéro RNE, hébergeur (OVH) et son adresse, directeur de la publication, contact. **En attente des données d'identité et de la relecture** |
| Politique de confidentialité (site + application) | **À publier en S6.2**, routes `/legal/privacy` et `/en/legal/privacy` |
| CGU, avec mention explicite de l'absence de SLA et clause de limitation de responsabilité | **À publier en S6.2**, route `/legal/terms` |
| AUP (politique d'usage acceptable) | **À publier en S6.2**, route `/legal/aup`, adossée à `04-abus-et-domaines.md` |
| DPA (accord de sous-traitance) | **Modèle dans [`legal/dpa.md`](legal/dpa.md)**, fourni sur demande, non publié comme page publique |
| Clause client pour la politique de confidentialité du client | **Rédigée ci-dessus, §4**, à publier et copier sur `/legal/client-clause` |
| Page sous-traitants | **§6 ci-dessus**, à publier sur `/legal/subcontractors` |
| LIA (deux analyses) | **Rédigé dans [`06-lia.md`](06-lia.md)** — v1.1, brouillon daté, à signer |
| Registre des activités de traitement | **Rédigé dans [`15-registre-traitements.md`](15-registre-traitements.md)** — v1.0, brouillon |
| Décision DPIA | **Tranchée et motivée dans [`16-decision-dpia.md`](16-decision-dpia.md)** — pas de DPIA en v0.1 |
| Politique de rétention | **Rédigée ci-dessus, §5** |
| RC professionnelle | À souscrire avant la première facture (D-83). En entrepreneur individuel, la responsabilité porte sur le patrimoine professionnel, séparé du personnel depuis 2022 mais avec exceptions |

**Point ouvert, à confirmer avant la validation de la v0.1** : D-125 plafonne
les emails transactionnels à quatre, le code en envoie trois (`VerifyEmail`,
`ResetPassword`, `LinkBlocked`). Aucun texte public ne compte les messages tant
que le quatrième n'est pas identifié. Voir l'ADR
[`decisions/0004-s6-legal.md`](decisions/0004-s6-legal.md), Q-02.

---

## 8. Ce qui ferait basculer le dossier

À relire si l'une de ces choses arrive :

- ajout du referer, même au niveau du domaine ;
- ajout d'un profil visiteur ou d'un comptage inter-liens ;
- ajout d'un script côté client sur le domaine de redirection ;
- allongement de la rétention des agrégats au-delà de la valeur documentée ;
- passage à un CDN ou à un sous-traitant hors UE sur le chemin des clics ;
- ajout d'un pixel ou d'un outil de mesure sur le site marketing (ironie à éviter : le site d'un produit privacy-first ne doit pas charger Google Analytics) ;
- ajout du pays dans les statistiques, c'est-à-dire sortie de D-120 ;
- ajout d'une API de réputation interrogée par URL, ou de tout autre destinataire hors UE sur le contrôle de destination ;
- ajout d'un cinquième email, notamment un email de suspension (D-125) ;
- ajout d'un modèle de langage dans le tri des signalements (D-129) ;
- ajout d'un stockage objet pour les sauvegardes, ou sortie du modèle pull de D-102 ;
- ajout d'un cookie ou d'un stockage local de suivi (la préférence de thème reste un stockage local d'affichage, jamais transmis).
