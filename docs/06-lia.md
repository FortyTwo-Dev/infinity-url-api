# 06 — Analyses d'intérêt légitime (LIA)

**Entité** : InfinityApp, entrepreneur individuel (micro-entreprise) — SIREN à compléter
**Adresse** : à compléter
**Version** : 1.0 — brouillon
**Date de rédaction** : 2026-11-21
**Rédigé par** : à compléter
**Validé par** : à compléter
**Prochaine revue** : 2027-11-21, ou au premier déclencheur de la partie D
**Statut** : brouillon de travail, non signé, non relu juridiquement

> Ce document est une pièce interne. Il ne se dépose nulle part. Il se conserve, se date, se versionne, et se produit sur demande d'un client, d'un DPO ou d'une autorité de contrôle. Il ne remplace pas une relecture juridique avant publication des mentions destinées au public.

---

## Pourquoi deux LIA

Le produit traite deux catégories de données pour deux responsables de traitement différents. Les mélanger dans un seul document produirait une analyse fausse.

| | Responsable de traitement | Traitements concernés | Base légale |
| --- | --- | --- | --- |
| **LIA-1** | InfinityApp | Logs de sécurité, lutte contre l'abus | Intérêt légitime (art. 6.1.f) |
| **LIA-2** | Le **client** d'Infinity URL. InfinityApp est sous-traitant | Mesure d'audience des liens | Intérêt légitime (art. 6.1.f), documenté par InfinityApp pour le compte du client |

**Ce qui n'est pas dans un LIA, et c'est volontaire :** les données de compte et les emails transactionnels reposent sur l'**exécution du contrat** (art. 6.1.b), pas sur l'intérêt légitime. Il serait faux de les traiter ici.

---

# Partie A — LIA-1 : InfinityApp responsable de traitement

## A.1 Description du traitement

| Élément | Contenu |
| --- | --- |
| Finalité | Détecter et qualifier les tentatives d'atteinte au service, diagnostiquer les incidents, constituer la preuve en cas de contentieux, prévenir l'hébergement de liens malveillants |
| Données | Adresse IP, date et heure, méthode HTTP, URI **sans query string**, code de statut, user-agent. Pour l'anti-abus : URL de destination fournie par l'utilisateur, résultat du contrôle. Signalements reçus : slug signalé, catégorie, texte libre, email facultatif chiffré — **jamais l'IP du rapporteur** |
| Personnes concernées | Visiteurs des liens, utilisateurs du service, auteurs de tentatives d'atteinte |
| Destinataires | Aucun. Accès restreint à l'exploitant |
| Durée | Logs : 72 heures. Résultat du contrôle de destination : durée de vie du lien |
| Sous-traitants | OVH (hébergement) |

## A.2 Test de la finalité

L'intérêt poursuivi est la sécurité du service et la prévention de son détournement à des fins malveillantes. Cet intérêt est légitime et reconnu : la sécurité des réseaux et de l'information figure parmi les intérêts légitimes cités en considérant 49 du RGPD, et le Digital Services Act impose aux services d'hébergement de réagir promptement aux contenus illicites notifiés — ce qui suppose de pouvoir qualifier et tracer.

Les personnes concernées ne subissent pas ce traitement comme une finalité étrangère à leur attente : tout service en ligne traite des journaux techniques, et l'usage en est ici strictement limité à la sécurité.

## A.3 Test de la nécessité

**Pourquoi une IP ?** Elle est indispensable pour qualifier une attaque (origine, volume, récurrence) et pour répondre à une demande d'un hébergeur ou d'une autorité.

**Pourquoi 72 heures ?** C'est la fenêtre nécessaire pour détecter une campagne, corréler des événements et réagir. Au-delà, la valeur probante chute et le risque augmente.

**Minimisation appliquée :**

- les query strings sont supprimées de l'access log, car elles peuvent contenir des données personnelles ;
- l'application n'écrit jamais l'IP dans ses propres logs ; seule la couche d'accès la voit ;
- aucune IP n'est écrite en base de données ;
- aucune donnée n'est croisée avec un autre service ni avec un autre client ;
- un signalement n'est jamais relié à l'IP de son auteur : l'empreinte de rate limiting vit une heure et n'est pas enregistrée ;
- les logs ne sont ni exportés, ni envoyés à un outil d'analyse tiers.

**Alternative moins intrusive examinée :** journaliser sans IP. Écartée, car elle rend impossible la qualification d'une attaque distribuée et la réponse à une réquisition.

## A.4 Test de la mise en balance

| Attente des personnes | Impact |
| --- | --- |
| Ne pas être surveillé dans son navigation | Nul : seules les requêtes vers le service sont concernées |
| Ne pas être identifié | Faible : IP conservée 72 h, sans croisement, sans profilage |
| Pouvoir exercer ses droits | Oui : accès, effacement et opposition possibles, avec une fenêtre de 72 h qui rend la demande rarement utile mais jamais impossible |

L'impact est faible et borné dans le temps. Les garanties (72 h, pas de query string, pas d'export, pas de croisement) sont proportionnées. L'intérêt légitime prévaut.

## A.5 Conclusion LIA-1

L'intérêt légitime prévaut. Le traitement des logs de sécurité et des contrôles anti-abus peut reposer sur l'article 6.1.f du RGPD.

---

# Partie B — LIA-2 : mesure d'audience des liens, pour le compte des clients

## B.1 Cadre

Le client crée un lien, le diffuse, et souhaite savoir combien de fois il a été ouvert, depuis quel pays et sur quel type d'appareil. **Le client est responsable de traitement** : c'est lui qui poursuit l'intérêt, qui informe ses visiteurs et qui traite les demandes d'exercice de droits. InfinityApp agit comme sous-traitant, encadré par le DPA.

Ce document est donc une **analyse fournie au client**, que celui-ci adopte. Il ne le dispense ni de l'information des personnes (clause fournie en partie C.3) ni du traitement du droit d'opposition.

Le résultat conservé est un **compteur**, jamais une ligne par clic, jamais une personne identifiable.

## B.2 Description du traitement

| Élément | Contenu |
| --- | --- |
| Finalité | Mesurer l'audience d'un lien : nombre de clics, pays d'origine, classe d'appareil, visiteurs uniques par lien |
| Données | Adresse IP (transitoire, jamais persistée) ; **deux empreintes** HMAC-SHA256 dérivées du même sel (une journalière, une hebdomadaire), la plus longue conservée au plus une semaine ; pays résolu ; classe d'appareil ; horodatage à la journée. Le user-agent est lu en mémoire, réduit à une classe, puis jeté. Les bots ne produisent aucun compteur |
| Personnes concernées | Visiteurs des liens |
| Destinataires | Le client, sous forme agrégée uniquement |
| Durée | Empreinte : jusqu'à la rotation du sel (≤ 7 jours). Agrégats : 30 jours sur l'instance hébergée, valeur configurable en self-host |
| Sous-traitants | OVH (hébergement) |

## B.3 Test de la finalité

L'intérêt poursuivi est de mesurer l'efficacité d'une campagne ou d'une publication. C'est un besoin professionnel courant, reconnu par la CNIL comme pouvant relever de l'intérêt légitime lorsqu'il est strictement limité à cette finalité et ne donne pas lieu à un profilage.

Le bénéficiaire est le client. L'intérêt est légitime parce qu'il est limité à la mesure d'audience et qu'il ne produit aucune décision automatisée, aucune publicité ciblée, aucun enrichissement.

## B.4 Test de la nécessité

**Pourquoi une IP ?** Le pays ne peut pas être déterminé sans elle. Aucune alternative moins intrusive ne produit une géolocalisation à maille pays.

**Pourquoi une empreinte persistante une semaine ?** Le comptage des visiteurs uniques exige de reconnaître un même visiteur entre deux visites. Sans identifiant, seuls les clics bruts sont mesurables. Une semaine est la durée minimale qui permet une lecture hebdomadaire, qui est l'usage réel.

**Minimisation appliquée :**

- l'IP n'est jamais écrite sur disque ni dans un log applicatif ;
- elle est transformée par HMAC avec un secret **détruit** à la rotation ;
- la clé intègre l'identifiant interne du lien, ce qui interdit toute corrélation entre deux liens et, a fortiori, entre deux clients ;
- aucune donnée comportementale n'est dérivée : pas de pages vues, pas de parcours, pas de referer ;
- le résultat conservé est un compteur, pas une personne ;
- aucun cookie, aucun `localStorage`, aucun fingerprint, aucun pixel tiers sur le chemin de redirection.

### Composition exacte de la clé

Ce point est la clé de voûte du dispositif, il est donc écrit sans ambiguïté.

```php
$key = hash_hmac('sha256', $ip . '|' . $link->id, $salt);
```

| Élément | Dans le calcul | Remarque |
| --- | --- | --- |
| Sel hebdomadaire | Oui | Détruit à la rotation |
| IP du visiteur | Oui | Jamais persistée en clair |
| Identifiant interne du lien (`id`) | Oui | Immuable. On n'utilise **pas** le slug, qui peut changer si le client renomme son lien et casserait la dédup en cours de semaine |
| URL de destination | Non | Sans rapport avec l'identification du visiteur |
| User-agent | Non | Lu en mémoire, réduit à `mobile \| tablet \| desktop`, puis jeté. Jamais persisté |
| Slug, email, nom du client | Non | Jamais |

**Effet** : la même IP produisant un clic sur deux liens différents génère deux clés différentes. La corrélation entre deux liens — donc entre deux clients — est mathématiquement impossible, et non simplement non pratiquée.

**Ce qui n'est pas nécessaire et n'est donc pas fait :** identifier un visiteur au-delà de sa visite du lien, connaître son parcours, connaître la page d'origine, le suivre d'un lien à l'autre.

## B.5 Test de la mise en balance

| Droit ou attente du visiteur | Impact |
| --- | --- |
| Ne pas être suivi à travers les sites | Nul : la clé est liée à un seul lien et jamais réutilisée ailleurs |
| Ne pas être profilé | Nul : aucun profil, aucune donnée comportementale |
| Ne pas être réidentifié | Très faible : sel détruit à la rotation, aucune IP conservée, aucun moyen de recalculer une empreinte passée |
| Être informé | Couvert par la mention dans la politique de confidentialité du client |
| S'opposer | Possible, à traiter par le client |

**Point de vigilance, à assumer explicitement :** la balance est favorable *parce que* le périmètre est étroit. Toute extension — referer, comptage inter-liens, rétention longue, profil visiteur — fait basculer l'analyse et impose de refaire ce document.

## B.6 Conclusion LIA-2

L'intérêt légitime prévaut, sous réserve que le client informe les personnes concernées avec la clause fournie et traite les demandes d'opposition. Si le client ne publie pas cette mention, il ne peut pas se prévaloir de cette analyse.

---

# Partie C — Preuves techniques

Un LIA qui affirme n'a pas de valeur. Un LIA qui cite des artefacts vérifiables en a. Ces preuves sont annexées et doivent rester à jour.

## C.1 Tableau des preuves

| Garantie | Artefact | Vérification |
| --- | --- | --- |
| Aucun cookie sur la redirection | Aucun en-tête `Set-Cookie` sur `GET /{slug}` | Test CI + `curl -sI https://iurl.fr/abc123` |
| Aucun état sur le terminal | La route de redirection renvoie un `302` sans corps ; aucune page HTML n'est servie sur ce chemin | Revue de la route + test |
| Aucun script tiers sur le domaine de liens | `iurl.fr` ne sert que des redirections | Revue de configuration Traefik + test d'en-têtes |
| IP jamais persistée en base | Aucune colonne d'adresse IP dans le schéma | Revue de schéma (test CI sur les migrations) |
| IP jamais loggée par l'application | Le logger applicatif n'inclut pas `REMOTE_ADDR` | Revue de configuration + test |
| Query strings absentes des logs | Format de log personnalisé | Revue de configuration |
| Sel détruit à la rotation | Job planifié de remplacement, sans archivage de l'ancienne valeur | Test : l'ancien sel n'est plus accessible après rotation |
| Clé non corrélable entre liens | Test unitaire : même IP + deux `link_id` différents → deux clés différentes | Test unitaire nommé explicitement |
| Rétention des agrégats | Job de purge quotidien | Test d'intégration |
| Aucune donnée personnelle dans la file | Le payload sérialisé du job analytique ne contient ni IP, ni UA, ni destination | Test CI + revue du payload |
| Énumération des slugs limitée | `404` plafonnés à 60/min par IP empreintée au sel, TTL 60 s, sur le chemin froid | Test d'intégration + revue du middleware |
| Logs à 72 h | Plafond dur de journald ou logrotate | Revue de configuration + test |
| Absence de corrélation inter-clients | La clé contient le `link_id`, donc deux clients ne peuvent pas produire la même clé | Test unitaire + revue de la fonction de hachage |
| Email chiffré au repos | `Crypt` (AES-256-GCM) sur `users.email` ; index aveugle HMAC dans `email_hash` avec `BLIND_INDEX_KEY` | Test : aucune valeur lisible dans `users.email` ; `email_hash` absent de toute réponse |
| Clé d'index distincte d'`APP_KEY` | La rotation d'`APP_KEY` n'invalide pas l'unicité, contrairement au défaut SEC-8 identifié sur le projet Hytale | Test : changer `app.key` ne modifie pas `EmailHasher::hash()` |
| Secrets non réversibles | Codes de récupération en argon2id, jetons API et refresh en SHA-256 | Revue de schéma + test d'introspection |

## C.2 Le test qui compte le plus

```php
public function test_same_ip_on_two_links_produces_different_keys(): void
{
    $ip = '203.0.113.42';

    $a = $this->analytics->dedupKey($ip, Link::find(1));
    $b = $this->analytics->dedupKey($ip, Link::find(2));

    $this->assertNotSame($a, $b);
}
```

C'est la démonstration, en quatre lignes, que la corrélation entre deux liens est impossible. C'est ce test que l'on montre à un DPO.

## C.3 Clause à fournir au client

Reprise de `03-conformite.md` §4. Le client l'insère dans sa politique de confidentialité ; sans elle, la partie B ne lui est pas opposable.

> **Mesure d'audience des liens**
>
> Les liens courts créés avec ce service enregistrent, lors de chaque ouverture, des statistiques agrégées : nombre de clics, pays d'origine et type d'appareil. Ces statistiques servent uniquement à mesurer l'audience de nos liens.
>
> Aucun cookie n'est déposé sur votre appareil. Aucune adresse IP n'est conservée. L'adresse IP est utilisée de façon transitoire pour déterminer le pays, puis transformée en une empreinte technique associée à un seul lien. Cette empreinte est supprimée au plus tard au bout d'une semaine et ne peut plus être recalculée ensuite.
>
> Ces statistiques ne permettent pas de vous identifier et ne sont jamais recoupées entre plusieurs liens. Vous pouvez vous opposer à ce traitement en nous contactant à l'adresse indiquée ci-dessous.

---

# Partie D — Réexamen

## D.1 Revue périodique

Revue annuelle, à la date indiquée en en-tête. La revue vérifie que les preuves de la partie C sont toujours exactes, et que le périmètre décrit en B.2 n'a pas changé.

## D.2 Déclencheurs de réexamen immédiat

Le document devient faux dès que l'un de ces changements survient. Il doit alors être refait avant la mise en production du changement, pas après.

- Ajout du referer, même limité au domaine.
- Ajout d'un comptage inter-liens ou d'un profil visiteur.
- Allongement de la rétention des agrégats ou de l'empreinte.
- Suppression de la destruction du sel.
- Ajout d'un script ou d'un outil de mesure sur le domaine de liens.
- Ajout d'un sous-traitant hors UE sur le chemin des clics.
- Ajout d'une fonctionnalité qui écrit sur le terminal du visiteur (cookie, `localStorage`, fingerprint).
- Changement de la granularité des agrégats vers une ligne par clic.

## D.3 Registre

Ce document alimente le registre des activités de traitement (art. 30 RGPD), qui reste à rédiger. Une entrée par traitement : logs de sécurité, anti-abus, mesure d'audience, comptes, emails transactionnels.

---

# Partie E — Signature

| | |
| --- | --- |
| Rédigé par | |
| Date | |
| Validé par | |
| Date | |
| Version | 1.0 |
| Prochaine revue | 2027-11-21 |

**Ce que la signature atteste** : que l'analyse a été faite, que les preuves de la partie C ont été vérifiées à la date indiquée, et que le responsable assume la conclusion. Elle n'atteste pas d'une validation juridique externe.
