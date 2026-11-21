# Marques protégées

`brands.seed.csv` est la liste utilisée par la détection de typosquat
(`04-abus-et-domaines.md` §3.5, D-131, D-132).

## Colonnes

| Colonne | Rôle |
| --- | --- |
| `domain` | Le motif protégé : un domaine enregistrable, ou un nom d'hôte complet |
| `category` | `banque`, `telecom`, `livraison`, `gouvernement`, `commerce`, `sante`, `transport`, `media`, `paiement`, `tech` |
| `type` | `registrable` (variantes sur le domaine) ou `hostname` (comparaison du nom d'hôte complet, ex. `impots.gouv.fr`) |
| `source` | `seed`, `phishing-dump` ou `report` |
| `checked_at` | Date de la dernière vérification humaine |

## Il n'y a pas de rang

Aucun rang de popularité n'est stocké, ni dans ce fichier, ni en base. Trois raisons :

1. **Le rang mesure les liens entrants, pas la fiabilité.** Un site très lié n'est pas
   sûr, un site peu lié n'est pas malveillant : c'est un contresens de catégorie.
2. **Il n'apporte presque rien.** L'âge du domaine couvre le cas majoritaire (le
   phishing frais) ; la popularité ne couvre que le domaine ancien et inconnu, que
   les blocklists et les signalements finissent par attraper aussi.
3. **Il introduit un biais mesuré.** Une banque de détail peu liée est classée
   « inconnue » : `hellobank.fr` au rang 161 894, `b-and-you.fr` au 963 189.

Le Majestic Million a servi **une seule fois**, à la main, comme point de départ. Il
n'est ni versionné, ni téléchargé par un job, ni redistribué.

## Vérifier une entrée

Une entrée ne s'ajoute pas sur une intuition :

1. le domaine **résout** (DNS) ;
2. il correspond réellement à la marque, pas à un homonyme ;
3. `type` est correct : un service public sur un sous-domaine exige `hostname` ;
4. `checked_at` est mis à jour.

Une entrée manquante est bénigne : le lien n'est pas signalé, ce qui est le
comportement par défaut. Une entrée erronée bloque un lien légitime.

## Mise à jour

Revue trimestrielle. Entre deux revues, le job de découverte (D-132) propose des
candidats issus du dump de phishing ; un humain les accepte avec `source:
phishing-dump`.
