# 15 — Registre des activités de traitement

> **Avertissement** : ce registre est un document interne, tenu à partir des
> faits du dépôt. Il n'est pas un avis juridique. Il se produit sur demande
> d'une autorité de contrôle, d'un client ou d'un DPO, et ne se publie pas.

**Entité** : InfinityApp, entrepreneur individuel (micro-entreprise)
**SIREN** : 934 964 255
**Adresse** : 8 B rue Abel, 75012 Paris
**Source de l'identité** : `infinity-qrcode/src/lib/legal.ts`
**Version** : 1.0 — brouillon
**Date** : 2026-11-23
**Rédigé par** : [À FOURNIR]
**Validé par** : [À FOURNIR]
**Prochaine revue** : 2027-11-23, ou au premier déclencheur de `06-lia.md` §D.2

---

## 1. Rôles

Le produit a deux responsables de traitement, et les confondre rendrait le
registre faux.

| Traitement | Responsable | Rôle d'InfinityApp | Source |
| --- | --- | --- | --- |
| Redirection d'un lien | Le client, pour son lien | Sous-traitant | `03-conformite.md` §2 et §3, `06-lia.md` B.1 |
| Mesure d'audience d'un lien | Le client | Sous-traitant | `06-lia.md` B.1, D-02, D-03 |
| Comptes et authentification | InfinityApp | Responsable | `03-conformite.md` §2 |
| Emails transactionnels | InfinityApp | Responsable | `03-conformite.md` §2, D-125 |
| Signalements d'abus | InfinityApp | Responsable | `03-conformite.md` §2, `06-lia.md` A.1 |
| Vérification de destination | InfinityApp | Responsable | `06-lia.md` A.1, `04-abus-et-domaines.md` §3 |
| Logs techniques et sécurité | InfinityApp | Responsable | `06-lia.md` A.1, D-36 |
| Sauvegardes | InfinityApp et le client, selon la donnée sauvegardée | Responsable et sous-traitant | D-102, `08-setup.md` §10 |

---

## 2. Redirection d'un lien

| Champ | Contenu |
| --- | --- |
| Responsable | Le client qui a créé le lien |
| Sous-traitant | InfinityApp |
| Finalité | Fournir la redirection vers la destination choisie par le client |
| Base légale | Exécution du contrat entre le client et InfinityApp (art. 6.1.b) |
| Données | Adresse IP en mémoire, slug, user-agent en mémoire. Aucune persistance |
| Personnes concernées | Visiteurs des liens |
| Destinataires | Le client, sous forme de statistiques agrégées ; OVH pour l'hébergement |
| Transferts hors UE | Aucun sur le chemin de la redirection |
| Rétention | Aucune : l'IP et l'user-agent sont jetés avant la réponse ; aucun cookie |
| Mesures de sécurité | `302` sans corps, `Cache-Control: no-store`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex`, aucun `Set-Cookie` ; cache Valkey invalidé à l'édition et à la suppression |
| Source | `03-conformite.md` §2, `10-api.md` §8, D-106, D-134, `tests/Feature/RedirectPrivacyTest.php` |

---

## 3. Mesure d'audience des liens

| Champ | Contenu |
| --- | --- |
| Responsable | Le client |
| Sous-traitant | InfinityApp |
| Finalité | Mesurer l'audience d'un lien : clics, classe d'appareil, visiteurs uniques par lien |
| Base légale | Intérêt légitime du client (art. 6.1.f), documenté par le LIA-2 |
| Données | IP transitoire, jamais persistée ; deux empreintes HMAC-SHA256 (journalière et hebdomadaire) avec le sel courant et l'identifiant interne du lien ; classe d'appareil ; date. **Aucun pays en v0.1** (D-120) |
| Personnes concernées | Visiteurs des liens |
| Destinataires | Le client, sous forme agrégée uniquement ; OVH pour l'hébergement |
| Transferts hors UE | Aucun sur le chemin des clics |
| Rétention | Empreintes : jusqu'à la rotation du sel, au plus 7 jours. Agrégats : 30 jours par défaut, `STATS_RETENTION_DAYS` |
| Mesures de sécurité | Sel dans un fichier, détruit à la rotation ; clé liée au `link_id`, donc non corrélable entre liens ; user-agent réduit en mémoire puis jeté ; bots filtrés avant comptage ; aucun cookie, aucun `localStorage`, aucun fingerprint |
| Source | `03-conformite.md` §2 et §5, `06-lia.md` B.2 à B.4, D-02, D-30, D-39, D-120, `app/Services/Analytics/AnalyticsSalt.php`, `app/Actions/Analytics/CaptureClick.php` |

---

## 4. Comptes et authentification

| Champ | Contenu |
| --- | --- |
| Responsable | InfinityApp |
| Finalité | Créer et authentifier un compte, protéger l'accès, permettre l'export et la suppression |
| Base légale | Exécution du contrat (art. 6.1.b) |
| Données | Email chiffré au repos (AES-256-GCM) et index aveugle HMAC ; mot de passe haché (Argon2id) ; secret TOTP chiffré ; codes de récupération hachés ; jetons API et refresh hachés ; workspace et liens |
| Personnes concernées | Utilisateurs du service |
| Destinataires | OVH pour l'hébergement ; aucun autre |
| Transferts hors UE | Aucun |
| Rétention | Durée du compte, puis 30 jours de grâce avant purge physique ; jetons révoqués à la suppression |
| Mesures de sécurité | Chiffrement de l'email, index aveugle avec `BLIND_INDEX_KEY` distincte d'`APP_KEY`, Argon2id, 2FA TOTP, rotation des refresh avec détection de rejeu, révocation de toutes les sessions, export en allowlist |
| Source | `03-conformite.md` §2 et §5, D-110, D-111, D-114, D-119, `app/Models/User.php`, `app/Services/PersonalDataInventory.php` |

---

## 5. Emails transactionnels

| Champ | Contenu |
| --- | --- |
| Responsable | InfinityApp |
| Finalité | Vérifier une adresse, réinitialiser un mot de passe, notifier le blocage d'un lien |
| Base légale | Exécution du contrat (art. 6.1.b) ; obligation légale de motivation pour la notification de blocage (DSA, `04-abus-et-domaines.md` §5) |
| Données | Adresse email, contenu du message, URL de vérification ou de réinitialisation, motif du blocage |
| Personnes concernées | Utilisateurs du service |
| Destinataires | OVH MX Plan pour l'envoi |
| Transferts hors UE | Aucun |
| Rétention | Durée d'envoi, puis 30 jours chez le fournisseur (`03-conformite.md` §5) |
| Mesures de sécurité | Aucune image, aucun pixel ; expéditeur `noreply@infinityproject.fr` ; `Reply-To` `contact@infinityproject.fr` ; SPF, DKIM et DMARC |
| Source | `03-conformite.md` §2 et §5, D-98, D-125, `app/Mail/VerifyEmail.php`, `app/Mail/ResetPassword.php`, `app/Mail/LinkBlocked.php` |

**Point ouvert** : D-125 plafonne à quatre messages, le code en envoie trois.
Le quatrième doit être confirmé avant la validation de la v0.1 ; aucun texte
public ne compte les messages.

---

## 6. Signalements d'abus

| Champ | Contenu |
| --- | --- |
| Responsable | InfinityApp |
| Finalité | Qualifier un signalement et retirer un lien illicite |
| Base légale | Intérêt légitime (art. 6.1.f), documenté par le LIA-1 |
| Données | Slug signalé, catégorie, texte libre, email facultatif chiffré. Aucune IP, aucun user-agent, aucun referer, aucune empreinte de rate limiting dans la ligne |
| Personnes concernées | Auteurs de signalements, propriétaires de liens signalés |
| Destinataires | L'exploitant, via un ntfy auto-hébergé qui reçoit la référence, le slug et la catégorie, jamais le texte, l'email ni l'adresse du rapporteur |
| Transferts hors UE | Aucun |
| Rétention | 12 mois ; 90 jours si le signalement est rejeté |
| Mesures de sécurité | Réponse `202` dans tous les cas, y compris pour un slug inexistant ; aucun blocage automatique ; email chiffré sans index ; rate limiting par HMAC du sel analytique, contexte `report`, 5 par heure, TTL 1 heure, jamais persisté |
| Source | `04-abus-et-domaines.md` §4.1 bis et §4.2, `06-lia.md` A.1 et A.3, `app/Actions/Abuse/CreateReport.php`, `app/Models/Report.php`, `app/Actions/Abuse/PurgeReports.php`, `app/Providers/AppServiceProvider.php` |

---

## 7. Vérification de destination

| Champ | Contenu |
| --- | --- |
| Responsable | InfinityApp |
| Finalité | Prévenir l'hébergement de liens malveillants, à la création du lien |
| Base légale | Intérêt légitime (art. 6.1.f), documenté par le LIA-1 |
| Données | URL de destination fournie par l'utilisateur ; domaine enregistrable ; enregistrements DNS ; adresse IP résolue ; en-têtes et code HTTP ; dates du certificat TLS ; âge du domaine via RDAP ; résultat des blocklists ; marqueurs de revue internes |
| Personnes concernées | Utilisateurs qui créent un lien ; sites de destination |
| Destinataires | Le site cible, y compris les redirections suivies, qui voit notre IP et l'URL testée ; le service RDAP et le registre faisant autorité, qui reçoivent le domaine enregistrable seul ; l'exploitant pour la file de revue. Ce ne sont pas des sous-traitants |
| Transferts hors UE | Le site cible et le registre RDAP peuvent être hors UE ; l'URL testée est fournie par l'utilisateur et aucun visiteur du lien n'est concerné. Aucune URL client n'est envoyée à un service de réputation |
| Rétention | Marqueurs de revue : durée de vie du lien. Résultat du contrôle : non conservé hors marqueurs. Logs techniques : 72 heures |
| Mesures de sécurité | Vérification une seule fois, à la création ; résolution épinglée ; refus des adresses privées ; timeout de 3 s ; échec ouvert sur absence de preuve ; blocklists locales téléchargées une fois par jour ; RDAP avec le domaine enregistrable seul |
| Source | `04-abus-et-domaines.md` §3.1 à §3.3, `06-lia.md` A.1 et A.5, D-96, D-126, D-128, `app/Services/Abuse/DestinationInspector.php`, `app/Services/Abuse/HttpDestinationProbe.php`, `app/Services/Abuse/RdapClient.php` |

---

## 8. Logs techniques et sécurité

| Champ | Contenu |
| --- | --- |
| Responsable | InfinityApp |
| Finalité | Sécurité, diagnostic, preuve en cas de contentieux |
| Base légale | Intérêt légitime (art. 6.1.f), documenté par le LIA-1 |
| Données | Couche d'accès : IP, date et heure, méthode, URI sans query string, code de statut. Application : aucun `REMOTE_ADDR`. En cas d'échec d'un contrôle de destination, l'exception journalisée peut contenir l'URL testée (point à vérifier, Q-05 de l'ADR 0004) |
| Personnes concernées | Visiteurs, utilisateurs, auteurs de tentatives d'atteinte |
| Destinataires | L'exploitant ; ntfy auto-hébergé pour les erreurs `ERROR` et `CRITICAL` |
| Transferts hors UE | Aucun |
| Rétention | 72 heures maximum |
| Mesures de sécurité | `journald` avec `MaxRetentionSec=72h` ; query strings retirées ; l'application ne journalise jamais l'IP ; aucun export vers un outil d'analyse tiers |
| Source | `03-conformite.md` §2, `06-lia.md` A.1 et C.1, D-36, `08-setup.md` §9 |

---

## 9. Sauvegardes

| Champ | Contenu |
| --- | --- |
| Responsable | InfinityApp pour ses données ; le client pour les données de ses liens, InfinityApp étant sous-traitant |
| Finalité | Restaurer le service après un incident |
| Base légale | Intérêt légitime (art. 6.1.f) pour la continuité ; exécution du contrat pour les données client |
| Données | Dump PostgreSQL complet : comptes (emails chiffrés), liens, statistiques agrégées, signalements, domaines |
| Personnes concernées | Utilisateurs, visiteurs dont les clics sont agrégés, auteurs de signalements |
| Destinataires | Aucun : le dump est tiré sur la machine de l'exploitant, chiffré, et le serveur ne détient aucun identifiant de sauvegarde |
| Transferts hors UE | Aucun |
| Rétention | 30 jours glissants ; RPO 24 heures |
| Mesures de sécurité | `pg_dump -Fc` puis `age` ; modèle pull ; rotation locale ; restauration testée au moins une fois avant l'ouverture publique ; aucun stockage objet en v0.1 |
| Source | `03-conformite.md` §5, `08-setup.md` §10, D-102 |

---

## 10. Sous-traitants

| Sous-traitant | Rôle | Localisation | Données | Source |
| --- | --- | --- | --- | --- |
| OVH | Hébergement du service | France | Toutes les données hébergées, en transit et au repos | D-60, `03-conformite.md` §6 |
| OVH MX Plan | Emails transactionnels | France | Email, contenu du message | D-98, `03-conformite.md` §6 |
| URLhaus / abuse.ch | Dump public de blocklist, téléchargé une fois par jour | Suisse (adéquation UE) | Aucune donnée client : le dump est public et identique pour tous | D-96, `03-conformite.md` §6 |
| Prometheus, Grafana, ntfy | Supervision auto-hébergée | Même serveur, UE | Métriques agrégées ; alertes internes avec référence, slug et catégorie d'un signalement | D-99, `03-conformite.md` §6, `app/Services/Abuse/ReportNotifier.php` |

**Ne sont pas des sous-traitants** : le site cible et le service RDAP reçoivent
une requête de contrôle (voir §7). L'IA de tri et le prestataire de paiement
sont v0.2 et ne sont pas actifs en v0.1 (D-129, D-127). Il n'y a pas de
stockage objet en v0.1 (D-102).

---

## 11. Transferts hors UE

| Destinataire | Pays | Nature | Garantie |
| --- | --- | --- | --- |
| URLhaus / abuse.ch | Suisse | Dump public de blocklist | Décision d'adéquation de la Commission |
| Site cible du contrôle | Variable | Requête de contrôle à la création du lien | URL fournie par l'utilisateur ; aucun visiteur concerné ; décrit dans l'AUP et la politique |
| Registre RDAP | Variable | Domaine enregistrable seul | Requête de contrôle ; aucun visiteur concerné |

Aucune donnée de navigation ne quitte l'Union européenne sur le chemin des
clics. La formulation « 100 % UE » serait fausse et n'est pas utilisée.

---

## 12. Tenue du registre

- Une entrée par traitement, mise à jour à chaque changement de finalité, de
  base légale, de donnée, de destinataire ou de durée.
- Les sources de chaque ligne sont des documents ou des chemins de code : une
  ligne sans source est une ligne à corriger.
- Le registre est relu avec `06-lia.md` à chaque revue annuelle et à chaque
  déclencheur de `06-lia.md` §D.2.
- Il ne se publie pas. Le DPA et la politique de confidentialité en reprennent
  les faits, jamais l'inverse.
