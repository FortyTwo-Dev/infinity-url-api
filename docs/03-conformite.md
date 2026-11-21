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
| Mesure d'audience | HMAC(sel, IP, link_id) pendant la semaine ; pays résolu ; classe d'appareil | Comptage de clics et d'uniques | Intérêt légitime (art. 6.1.f) + LIA | Clé : jusqu'à la rotation du sel. Agrégats : 30 jours (hébergé v0.1) / configurable | Client, sous forme agrégée |
| Comptes | Email **chiffré au repos** + index aveugle, mot de passe haché (argon2id), secret TOTP chiffré, codes de récupération hachés | Authentification | Exécution du contrat | Durée du compte | Aucun |
| Facturation (v0.2+) | Coordonnées, historique de paiement | Facturation | Obligation légale + contrat | Durée légale comptable | Prestataire de paiement (MoR) |
| Logs techniques | IP, méthode, URI sans query string, statut | Sécurité, diagnostic | Intérêt légitime (sécurité) | **72 h** | Aucun |
| Signalements d'abus | Slug signalé, catégorie, texte libre, email facultatif chiffré | Qualifier et retirer un lien illicite | Intérêt légitime (anti-abus, couvert par le LIA-1) | 12 mois, 90 jours si rejeté | Aucun |
| Emails transactionnels | Email, contenu du message | Vérification, réinitialisation | Exécution du contrat | Durée d'envoi | OVH (MX Plan) |

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
> Les liens courts créés avec ce service enregistrent, lors de chaque ouverture, des statistiques agrégées : nombre de clics, pays d'origine et type d'appareil. Ces statistiques servent uniquement à mesurer l'audience de nos liens.
>
> Aucun cookie n'est déposé sur votre appareil. Aucune adresse IP n'est conservée. L'adresse IP est utilisée de façon transitoire pour déterminer le pays, puis transformée en une empreinte technique associée à un seul lien. Cette empreinte est supprimée au plus tard au bout d'une semaine et ne peut plus être recalculée ensuite.
>
> Ces statistiques ne permettent pas de vous identifier et ne sont jamais recoupées entre plusieurs liens. Vous pouvez vous opposer à ce traitement en nous contactant à l'adresse indiquée ci-dessous.

Le client reste responsable de l'insertion de ce texte et de la gestion du droit d'opposition. Infinity URL doit documenter cette responsabilité dans son DPA.

---

## 5. Rétention

| Donnée | Durée | Mécanisme |
| --- | --- | --- |
| Clé de dédup | Jusqu'à la prochaine rotation du sel (≤ 7 jours) | TTL Valkey + destruction du sel |
| Agrégats (compteurs) | 30 jours sur l'instance hébergée v0.1 ; 12 mois par défaut en self-host | Job de purge quotidien, `ANALYTICS_RETENTION_DAYS` |
| Logs techniques | 72 h | Logrotate / journald avec plafond dur |
| Emails transactionnels | Durée d'envoi + 30 jours chez le provider | Configuration du provider |
| Comptes | Durée du compte + 30 jours de grâce | Suppression logique puis physique |
| Signalements | 12 mois, 90 jours si rejeté | Job de purge quotidien |
| Sauvegardes | 30 jours glissants | Rotation locale, dump chiffré tiré depuis la machine de l'exploitant |

**Effacement.** Supprimer un lien supprime ses agrégats. C'est la raison pour laquelle il n'y a pas de lignes de clic : avec des compteurs, la promesse d'effacement est tenue en une requête. Les sauvegardes peuvent contenir le lien jusqu'à 30 jours ; la politique publiée doit le dire honnêtement plutôt que promettre un effacement instantané impossible.

---

## 6. Sous-traitants (page publique à tenir à jour)

| Sous-traitant | Rôle | Localisation | Données concernées |
| --- | --- | --- | --- |
| OVH | Hébergement VPS puis serveur dédié | France | Toutes, en transit et au repos |
| Stockage objet OVH (ou équivalent) | Sauvegardes | France | Dump PostgreSQL chiffré |
| OVH (MX Plan) | Emails de compte et de vérification | France | Email, contenu des messages |
| URLhaus / abuse.ch | Flux de blocklist téléchargé en masse | Suisse (adéquation UE) | Aucune donnée client : le dump est public et identique pour tous |
| Prometheus, Grafana, ntfy | Supervision auto-hébergée | **Sur le même serveur, UE** | Métriques agrégées, aucune donnée personnelle |
| OVHcloud AI Endpoints (v0.2, optionnel) | Assistance de tri des signalements | UE | **Signaux dérivés d'une URL signalée uniquement** : jamais le contenu de la page, jamais le texte du rapporteur, jamais son email |
| MoR de paiement (v0.2+) | Facturation | UE/UK selon prestataire | Coordonnées, paiement |

**Règle** : aucun sous-traitant hors UE dans la chaîne de traitement des clics. Les prestataires hors UE (paiement) ne sont jamais dans cette chaîne, et la formulation publique doit être « aucune donnée de navigation ne quitte l'Union européenne », pas un « 100 % UE » global qui serait faux.

---

## 7. Documents à produire avant l'ouverture publique

Attention à l'identité : les documents contractuels et les mentions légales nomment **InfinityApp, entrepreneur individuel**, pas « Infinity Project » ni « Infinity URL » (D-81). « Infinity Project » est une marque : elle ne peut pas être partie à un contrat.

| Document | Statut |
| --- | --- |
| Mentions légales (LCEN art. 6-III) | À rédiger. Doit contenir : identité `InfinityApp`, statut d'entrepreneur individuel, SIREN, adresse, numéro RNE, hébergeur (OVH) et son adresse, contact |
| Politique de confidentialité (site + application) | À rédiger |
| CGU, avec mention explicite de l'absence de SLA et clause de limitation de responsabilité | À rédiger |
| AUP (politique d'usage acceptable) | À rédiger, voir `04-abus-et-domaines.md` |
| DPA (accord de sous-traitance) | À rédiger, modèle pour les clients |
| Clause client pour la politique de confidentialité du client | **Rédigée ci-dessus, §4** |
| Page sous-traitants | À rédiger, §6 |
| LIA (deux analyses) | **Rédigé dans [`06-lia.md`](06-lia.md)** — brouillon à dater et signer |
| Registre des activités de traitement | À rédiger (obligation art. 30) |
| Décision DPIA | À trancher : a priori **non requise** (pas de surveillance systématique à grande échelle, pas de traitement à risque élevé), mais la décision doit être écrite et motivée |
| Politique de rétention | **Rédigée ci-dessus, §5** |
| RC professionnelle | À souscrire avant la première facture (D-83). En entrepreneur individuel, la responsabilité porte sur le patrimoine professionnel, séparé du personnel depuis 2022 mais avec exceptions |

---

## 8. Ce qui ferait basculer le dossier

À relire si l'une de ces choses arrive :

- ajout du referer, même au niveau du domaine ;
- ajout d'un profil visiteur ou d'un comptage inter-liens ;
- ajout d'un script côté client sur le domaine de redirection ;
- allongement de la rétention des agrégats au-delà de 12 mois ;
- passage à un CDN ou à un sous-traitant hors UE sur le chemin des clics ;
- ajout d'un pixel ou d'un outil de mesure sur le site marketing (ironie à éviter : le site d'un produit privacy-first ne doit pas charger Google Analytics).
