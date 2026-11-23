# Accord de sous-traitance (DPA) — modèle

> **Modèle non signé.** Ce document est un brouillon de travail, versionné dans
> le dépôt, fourni sur demande à un client. Il n'est pas publié comme page
> publique et ne vaut pas contrat tant qu'il n'est pas complété, daté et signé
> par les deux parties. Les champs `[À FOURNIR]` et `[Client : …]` doivent être
> renseignés avant signature. Une relecture juridique est recommandée.

**Version** : 1.0 — brouillon
**Date** : 2026-11-23

---

## 1. Parties

**Sous-traitant** : InfinityApp, entrepreneur individuel (micro-entreprise)
SIREN : [À FOURNIR]
RNE : [À FOURNIR]
Adresse : [À FOURNIR]
Contact : [À FOURNIR]

**Responsable de traitement** : [Client : raison sociale]
SIREN / numéro d'identification : [Client : …]
Adresse : [Client : …]
Contact : [Client : …]
Représentant : [Client : …]

Le client est responsable du traitement des données de mesure d'audience de ses
liens. InfinityApp agit comme sous-traitant pour ce traitement, au sens de
l'article 28 du RGPD.

---

## 2. Objet

Le présent accord encadre le traitement des données nécessaires à la fourniture
du service Infinity URL : redirection des liens, mesure d'audience agrégée,
comptage des clics et des visiteurs uniques par lien, classe d'appareil, et
stockage des liens et de leurs statistiques.

Le service ne traite pas, pour le compte du client, de données de visiteurs
autres que celles décrites à l'annexe C. Il ne dépose aucun cookie sur le
domaine de liens et ne conserve aucune adresse IP (voir annexe B).

---

## 3. Durée

Le présent accord prend effet à la date de signature et reste en vigueur pendant
la durée du compte du client. Il prend fin à la suppression du compte, sous
réserve des obligations de conservation décrites à l'annexe C et des obligations
légales qui s'imposent à InfinityApp.

---

## 4. Obligations d'InfinityApp

InfinityApp s'engage à :

1. ne traiter les données que sur instruction documentée du client, y compris
   pour les transferts hors UE, sauf obligation légale contraire ;
2. garantir que les personnes autorisées à traiter les données sont soumises à
   une obligation de confidentialité ;
3. mettre en œuvre les mesures techniques et organisationnelles de l'annexe B ;
4. ne recourir à un sous-traitant ultérieur que dans les conditions de
   l'article 5 et l'informer préalablement de tout changement ;
5. aider le client à répondre aux demandes d'exercice des droits, dans la mesure
   de ses moyens techniques ;
6. aider le client à respecter les obligations des articles 32 à 36 du RGPD ;
7. notifier au client toute violation de données personnelles dans les meilleurs
   délais après en avoir pris connaissance, avec les informations disponibles ;
8. supprimer ou restituer les données au terme de l'accord, selon l'annexe C ;
9. mettre à disposition les informations nécessaires pour démontrer le respect
   de l'article 28 et permettre les audits raisonnables.

Le client reste responsable de l'information des personnes concernées, de la
base légale de la mesure d'audience et du traitement des demandes d'opposition.
La clause à publier lui est fournie dans `docs/03-conformite.md` §4.

---

## 5. Sous-traitants ultérieurs

Le client autorise le recours aux sous-traitants listés à l'annexe A.
InfinityApp l'informe de tout ajout ou remplacement, avec un délai raisonnable
permettant au client de s'y opposer. Le sous-traitant ultérieur est soumis aux
mêmes obligations que celles du présent accord.

Le site cible et le service RDAP ne sont pas des sous-traitants : ils reçoivent
une requête de contrôle de destination, décrite dans l'AUP et la politique de
confidentialité. Les blocklists sont des dumps publics téléchargés une fois par
jour, sans donnée client.

---

## 6. Confidentialité et sécurité

InfinityApp applique les mesures de l'annexe B. Elles couvrent notamment :
le chiffrement des emails au repos, l'index aveugle, le hachage des mots de
passe et des jetons, l'absence d'IP persistée, l'absence de cookie sur le
domaine de liens, le sel analytique détruit à la rotation, la journalisation
bornée à 72 heures et la sauvegarde chiffrée.

---

## 7. Violation de données

InfinityApp notifie le client sans délai injustifié après avoir pris
connaissance d'une violation susceptible d'engendrer un risque pour les droits
et libertés. La notification décrit, dans la mesure du possible, la nature de la
violation, les catégories et le volume de données concernées, les conséquences
probables et les mesures prises ou proposées. Le client reste responsable de la
notification à l'autorité de contrôle et aux personnes concernées.

---

## 8. Sort des données

Au terme de l'accord, InfinityApp supprime les données du client dans les
délais de l'annexe C, sauf obligation légale de conservation. Les sauvegardes
chiffrées peuvent contenir les données jusqu'à 30 jours ; elles sont ensuite
écrasées par la rotation. Les liens supprimés ne sont pas réattribués : le slug
reste en tombstone et répond `410`.

---

## 9. Audit

Le client peut demander, au plus une fois par an et sauf obligation légale
contraire, les informations raisonnablement nécessaires pour vérifier le respect
du présent accord. Un audit sur site n'est pas prévu en v0.1 ; les preuves
techniques de `docs/06-lia.md` partie C et le registre
`docs/15-registre-traitements.md` tiennent lieu de documentation.

---

## 10. Droit applicable

Le présent accord est soumis au droit français. [À VALIDER : juridiction
compétente.] Les parties recherchent d'abord une solution amiable.

---

## Annexe A — Sous-traitants ultérieurs

| Sous-traitant | Rôle | Localisation | Données | Source |
| --- | --- | --- | --- | --- |
| OVH | Hébergement | France | Données hébergées, en transit et au repos | D-60 |
| OVH MX Plan | Emails transactionnels | France | Email, contenu du message | D-98 |
| URLhaus / abuse.ch | Dump public de blocklist | Suisse (adéquation UE) | Aucune donnée client | D-96 |
| Prometheus, Grafana, ntfy | Supervision auto-hébergée | Même serveur, UE | Métriques agrégées ; alertes internes sans donnée de rapporteur | D-99 |

Aucun stockage objet en v0.1 (D-102). L'IA de tri et le prestataire de paiement
sont v0.2 et ne sont pas actifs (D-129, D-127).

---

## Annexe B — Mesures de sécurité

| Mesure | Détail | Source |
| --- | --- | --- |
| Email chiffré au repos | AES-256-GCM sur `users.email` | D-110 |
| Index aveugle | HMAC-SHA256 avec `BLIND_INDEX_KEY`, distincte d'`APP_KEY` | D-110 |
| Mots de passe | Argon2id | `config/hashing.php` |
| Jetons | API et refresh hachés en SHA-256, rotation avec détection de rejeu | D-114 |
| 2FA | TOTP, secret chiffré, codes de récupération hachés | D-110, `app/Models/User.php` |
| Aucune IP persistée | Aucune colonne, aucun log applicatif | D-32, `tests/Architecture/NoIpColumnTest.php` |
| Aucun cookie sur le domaine de liens | Aucun `Set-Cookie` sur `GET /{slug}` | D-35, `tests/Feature/RedirectPrivacyTest.php` |
| Sel analytique | Fichier hors base et hors sauvegarde, détruit à la rotation | D-30, D-102 |
| Empreintes | HMAC avec le `link_id`, non corrélables entre liens | D-02, D-03 |
| Logs | 72 heures, query strings retirées, IP absente des logs applicatifs | D-36 |
| Signalements | Aucune IP, aucun user-agent, aucun referer, aucune empreinte persistée | `docs/04` §4.1 bis |
| Sauvegardes | Dump chiffré, rotation 30 jours, modèle pull | D-102 |

---

## Annexe C — Rétention

| Donnée | Durée | Mécanisme |
| --- | --- | --- |
| Empreinte de déduplication | Jusqu'à la rotation du sel (≤ 7 jours) | TTL Valkey et destruction du sel |
| Agrégats | 30 jours par défaut (`STATS_RETENTION_DAYS`) | Purge quotidienne |
| Logs techniques | 72 heures | `journald` avec plafond dur |
| Emails transactionnels | Durée d'envoi + 30 jours chez le fournisseur | Configuration du fournisseur |
| Comptes | Durée du compte + 30 jours de grâce | Suppression logique puis physique |
| Signalements | 12 mois ; 90 jours si rejetés | Purge quotidienne |
| Sauvegardes | 30 jours glissants | Rotation locale |
| Empreinte de rate limiting des signalements | 1 heure | TTL Valkey, jamais persistée |

---

## Annexe D — Champs à compléter avant signature

| Champ | Partie | Valeur |
| --- | --- | --- |
| SIREN | InfinityApp | [À FOURNIR] |
| RNE | InfinityApp | [À FOURNIR] |
| Adresse | InfinityApp | [À FOURNIR] |
| Contact | InfinityApp | [À FOURNIR] |
| Raison sociale | Client | [Client : …] |
| SIREN / identification | Client | [Client : …] |
| Adresse | Client | [Client : …] |
| Contact | Client | [Client : …] |
| Représentant | Client | [Client : …] |
| Date de signature | Les deux | [À FOURNIR] |
| Juridiction | Les deux | [À VALIDER] |
