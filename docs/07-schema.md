# 07 — Schéma de base de données

Le schéma n'est pas une formalité : c'est l'endroit où les promesses publiques deviennent **vraies par construction**. Une colonne d'IP ajoutée « pour déboguer » ferait mentir le LIA ; un index d'unicité mal placé rendrait un slug réutilisable.

---

## 1. Principes

| Principe | Conséquence concrète |
| --- | --- |
| **Aucune donnée personnelle de visiteur** | Aucune colonne d'IP, de user-agent, de referer. Aucune table d'événements de clic. |
| **Agrégats seulement** | Deux tables de statistiques, jamais une ligne par clic. |
| **Unicité des slugs jusque dans la suppression** | Index unique sur `(domain_id, slug)` incluant les lignes supprimées, plus une table de tombstones pour les effacements définitifs. |
| **Tenant partout** | `workspace_id` non nul sur toute table métier. Aucune table « globale » hors référentiel. |
| **Identifiants publics non énumérables** | Clé primaire `bigint` interne, `public_id` ULID pour l'API. |
| **Chiffrer ce qui est secret, pas ce qui est personnel** | Email et secret TOTP chiffrés ; destination de lien et nom de domaine en clair, car publics par nature (D-110). |
| **Tout en UTC** | `timestamptz` et `date` en UTC, y compris la frontière des journées et la rotation du sel. |

---

## 2. Extensions et conventions

Pas d'extension `citext` : l'email est chiffré, sa normalisation (minuscules + `trim`) se fait en PHP dans le cast, et son unicité est portée par l'index aveugle `email_hash`.

- Clés primaires : `bigint generated always as identity`.
- Identifiants exposés : `public_id text not null unique`, ULID 26 caractères.
- Horodatages : `timestamptz`, jamais `timestamp`.
- Suppression logique : `deleted_at timestamptz`.
- Noms de tables au pluriel, colonnes en `snake_case`.

---

## 3. Comptes et tenants

```sql
create table users (
    id                bigint generated always as identity primary key,
    public_id         text not null unique,

    -- Chiffré au repos (AES-256-GCM). Jamais indexable, jamais unique.
    -- L'unicité et la recherche passent par email_hash (D-110).
    email             text not null,
    email_hash        char(64) not null unique,

    password_hash     text not null,          -- argon2id
    email_verified_at timestamptz,

    -- Chiffré au repos : réversible par nature, lu à chaque vérification.
    totp_secret       text,
    totp_confirmed_at timestamptz,

    created_at        timestamptz not null default now(),
    updated_at        timestamptz not null default now(),
    deleted_at        timestamptz
);

create table recovery_codes (
    id         bigint generated always as identity primary key,
    user_id    bigint not null references users(id) on delete cascade,
    code_hash  text not null,                 -- argon2id, jamais réversible
    used_at    timestamptz,
    created_at timestamptz not null default now()
);

create index recovery_codes_user on recovery_codes (user_id) where used_at is null;
```

**`email_hash` = HMAC-SHA256(email normalisé, `BLIND_INDEX_KEY`)**, pas `APP_KEY`. C'est ce qui permet de faire tourner `APP_KEY` sans invalider l'index, et sans rendre les emails testables hors ligne à partir d'un dump seul. La normalisation vit dans le cast, pas dans la base : deux chemins d'écriture ne peuvent pas produire deux index différents.

Le cast `EncryptedWithHash` du dépôt Hytale est repris **avec son `compare()`**, sans quoi le chiffrement non déterministe (IV aléatoire) marquerait l'attribut comme modifié à chaque sauvegarde, réécrirait un ciphertext et un hash, et salirait `updated_at`.

```sql
create table workspaces (
    id         bigint generated always as identity primary key,
    public_id  text not null unique,
    name       text not null,
    slug       text not null unique,
    settings   jsonb not null default '{}'::jsonb,
    created_at timestamptz not null default now(),
    updated_at timestamptz not null default now(),
    deleted_at timestamptz
);

create table workspace_members (
    workspace_id bigint not null references workspaces(id) on delete cascade,
    user_id      bigint not null references users(id) on delete cascade,
    role         text not null default 'owner'
                 check (role in ('owner','admin','member')),
    created_at   timestamptz not null default now(),
    primary key (workspace_id, user_id)
);
```

`workspace_members` existe dès la v0.1 même s'il n'y a qu'un owner : ajouter une table après coup coûte une migration de données, la créer maintenant coûte trois lignes.

`totp_secret` doit être chiffré au repos avec `APP_KEY`, jamais stocké en clair. C'est le seul secret réversible de l'application.

---

## 4. Jetons

On utilise la table `personal_access_tokens` de **Sanctum**, pas une table maison (D-114). Elle porte déjà le hachage SHA-256, la recherche, l'expiration et la purge. Deux colonnes sont ajoutées.

```sql
-- Table fournie par Sanctum. `tokenable_type` / `tokenable_id` pointent vers
-- `users`, jamais vers un workspace : le workspace est résolu via
-- workspace_members (un utilisateur n'en a qu'un en v1).
-- `token` contient le SHA-256 hexadécimal du jeton, unique.
-- `abilities` contient les scopes, au format JSON.
alter table personal_access_tokens
    add column family_id uuid null,
    add column type text not null default 'access'
        check (type in ('access','refresh')),
    add column consumed_at timestamptz null;

create index pat_family on personal_access_tokens (family_id);
create index pat_type on personal_access_tokens (type);
```

| Type | `abilities` | Durée | Transport |
| --- | --- | --- | --- |
| `access`, dashboard | `['*']` | 15 min | `Authorization: Bearer`, en mémoire JS |
| `access`, API | scopes explicites (§9 de `10-api.md`) | pas d'expiration par défaut | `Authorization: Bearer` |
| `refresh` | `['refresh']` | 30 jours, rotation à chaque usage | Cookie `__Host-iurl_refresh` |

Le jeton en clair n'est **jamais** stocké : Sanctum ne conserve que son SHA-256 et ne permet de l'afficher qu'une seule fois, à la création. `sanctum:prune-expired --hours=24` tourne quotidiennement.

**Pourquoi Sanctum plutôt qu'une table maison** : le hachage, la recherche, l'expiration et la purge sont déjà écrits et éprouvés. Ce qu'on ajoute, c'est uniquement la rotation : `family_id` permet de révoquer toute une chaîne de jetons quand un refresh est rejoué.

`consumed_at` est indispensable à la détection de rejeu : un refresh consommé
doit rester lisible jusqu'à son expiration, sinon la famille qu'il faut révoquer
n'est plus identifiable. Il est purgé par `sanctum:prune-expired`, comme les
autres.

Un jeton `refresh` ne peut appeler **aucune** route : il ne possède que l'ability `refresh`, et chaque route en exige une autre. C'est ce qui remplace le middleware de rejet qu'on aurait dû écrire.

**Conséquence assumée** : le jeton est rattaché à l'utilisateur, pas au workspace. Le jour où un utilisateur appartiendra à plusieurs workspaces, il faudra une colonne `workspace_id` sur la table ou un scope de jeton par workspace. Aujourd'hui, un utilisateur = un workspace, donc le problème n'existe pas.

---

## 5. Domaines

```sql
create table domains (
    id                 bigint generated always as identity primary key,
    public_id          text not null unique,
    workspace_id       bigint not null references workspaces(id) on delete cascade,
    hostname           text not null unique,
    verification_token text not null,
    status             text not null default 'pending'
                       check (status in ('pending','verifying','active','failed','disabled')),
    verified_at        timestamptz,
    tls_status         text not null default 'none'
                       check (tls_status in ('none','issuing','valid','expiring','expired','failed')),
    tls_expires_at     timestamptz,
    last_checked_at    timestamptz,
    created_at         timestamptz not null default now(),
    updated_at         timestamptz not null default now(),
    deleted_at         timestamptz,
    constraint hostname_normalized check (hostname = lower(hostname))
);
```

**En v0.1, cette table contient exactement une ligne** : `iurl.fr`, rattachée au workspace système, `status = 'active'`, `verified_at` renseigné. Aucun endpoint ne la modifie (D-127). Elle existe dès maintenant pour que `links.domain_id` reste non nul et que la v0.2 n'ait aucune migration de données à faire.

`tls_expires_at` n'est pas décoratif : en v0.2, c'est la métrique qui évite qu'un client perde son lien en silence (voir `08-setup.md`).

---

## 6. Liens

```sql
create table links (
    id          bigint generated always as identity primary key,
    public_id   text not null unique,
    workspace_id bigint not null references workspaces(id) on delete cascade,
    domain_id   bigint not null references domains(id) on delete restrict,
    slug        text not null,
    destination text not null,
    status      text not null default 'active'
                check (status in ('active','disabled','blocked')),
    expires_at  timestamptz,
    created_by  bigint references users(id) on delete set null,
    created_at  timestamptz not null default now(),
    updated_at  timestamptz not null default now(),
    deleted_at  timestamptz,
    constraint slug_format check (slug ~ '^[a-z0-9_-]{2,64}$'),
    constraint destination_http check (destination ~* '^https?://')
);

-- Inclut les lignes supprimées logiquement : un slug n'est jamais réattribué (D-51).
create unique index links_domain_slug_unique on links (domain_id, slug);

create index links_workspace_created on links (workspace_id, created_at desc);

-- Pour le job d'expiration : ne scanne que les liens concernés.
create index links_expiring on links (expires_at)
    where status = 'active' and deleted_at is null;
```

**Point clé** : l'index unique porte sur *toutes* les lignes, y compris `deleted_at is not null`. C'est ce qui rend le `410 Gone` permanent sans code applicatif supplémentaire.

```sql
create table slug_tombstones (
    domain_id bigint not null references domains(id) on delete cascade,
    slug      text not null,
    erased_at timestamptz not null default now(),
    primary key (domain_id, slug)
);
```

Utilisée lors d'un **effacement définitif** (demande RGPD) **et lors du renommage d'un slug** (D-117) : on supprime la ligne `links` ou on change son slug, et on insère l'ancien slug ici pour qu'il reste réservé. Sans ça, un effacement permettrait à un tiers de réclamer le slug et de récupérer les clics des QR déjà imprimés, et un renommage libérerait l'ancienne URL.

```sql
create table reserved_slugs (
    slug   text primary key check (slug = lower(slug)),
    reason text
);
```

En base et non en configuration : la liste doit pouvoir être complétée sans déploiement, et être consultable par un test.

---

## 7. Statistiques

```sql
-- Clics et uniques d'un lien, par jour. Les uniques ne sont PAS ventilés.
create table link_stats_daily (
    link_id bigint not null references links(id) on delete cascade,
    date    date   not null,
    clicks  integer not null default 0,
    uniques integer not null default 0,
    primary key (link_id, date)
);

create index link_stats_daily_date on link_stats_daily (date);

-- Uniques par semaine ISO. Non additifs avec les uniques journaliers.
create table link_stats_weekly (
    link_id  bigint not null references links(id) on delete cascade,
    iso_year smallint not null,
    iso_week smallint not null,
    uniques  integer not null default 0,
    primary key (link_id, iso_year, iso_week)
);

create index link_stats_weekly_date on link_stats_weekly (iso_year, iso_week);

-- Clics ventilés. Compteurs additifs uniquement.
create table link_stats_dim (
    link_id   bigint not null references links(id) on delete cascade,
    date      date   not null,
    dimension text   not null check (dimension in ('country','device')),
    value     text   not null,
    clicks    integer not null default 0,
    primary key (link_id, date, dimension, value)
);

create index link_stats_dim_date on link_stats_dim (date);
```

### Pourquoi trois tables et pas une

Les **uniques ne sont pas additifs**. Un visiteur qui clique depuis la France et depuis l'Allemagne reste une personne. Si les uniques étaient ventilés par pays, la somme des lignes serait supérieure au total. D'où la règle :

| Métrique | Ventilable | Additif | Table |
| --- | --- | --- | --- |
| Clics | Oui, par pays et par appareil | Oui | `link_stats_dim` + `link_stats_daily` |
| Uniques du jour | Non | Non (sommer 7 jours ≠ 7 jours d'uniques) | `link_stats_daily` |
| Uniques de la semaine | Non | Non (sommer 4 semaines ≠ uniques du mois) | `link_stats_weekly` |

Conséquences produit assumées :

- le dashboard ne peut pas afficher « uniques par pays » ;
- il ne peut pas afficher « uniques sur une période libre » ;
- il affiche des uniques **par jour** et **par semaine**, chacun exact, et jamais une somme.

Ce n'est pas une limite technique, c'est la conséquence directe de la clé scopée par lien, du sel hebdomadaire et de la non-additivité (D-02, D-34, D-107).

Le `date` est en UTC. Un visiteur français à 00h30 locales apparaît la veille. C'est documenté, et c'est cohérent avec la rotation du sel à lundi 00:00 UTC.

---

## 8. Blocklist

```sql
create table blocklist_domains (
    domain     text primary key,
    sources    text[] not null,
    first_seen date not null,
    last_seen  date not null
);

create index blocklist_domains_last_seen on blocklist_domains (last_seen);
```

Rafraîchissement quotidien : `upsert` sur `domain`, puis `delete from blocklist_domains where last_seen < current_date - interval '30 days'`.

La vérification se fait à la **création du lien** uniquement. Jamais au clic : ce serait un accès base ou cache sur 95 % du trafic, pour un risque marginal.

---


## 8 bis. Marques protégées et typosquats

```sql
create table protected_brands (
    brand       text not null,              -- 'La Poste'
    domain      text not null,              -- 'laposte.fr'
    category    text not null,              -- 'bank','telco','delivery','government','marketplace'
    source      text not null,              -- 'seed','phishing-dump','report'
    verified_at timestamptz not null default now(),
    primary key (domain)
);

create table lookalike_domains (
    domain       text primary key,          -- variante générée
    brand_domain text not null,             -- la marque imitée
    variant_type text not null              -- 'substitution','deletion','homoglyph','tld','combosquat','concatenation'
);

-- Pas de table de popularité : le rang mesure les liens entrants, pas la
-- fiabilité, et double l'âge du domaine (D-133). Le Majestic Million a servi
-- une seule fois à la main pour bâtir la liste de départ.

-- Propositions du job de découverte, jamais appliquées automatiquement (D-132).
create table brand_proposals (
    id          bigint generated always as identity primary key,
    token       text not null,              -- 'laposte', extrait du dump
    occurrences integer not null,
    window_from date not null,
    window_to   date not null,
    state       text not null default 'proposed'
                check (state in ('proposed','accepted','rejected')),
    created_at  timestamptz not null default now(),
    unique (token, window_from, window_to)
);
```

Le second est **généré**, jamais saisi à la main : un job le reconstruit à chaque mise à jour de la liste. Quelques centaines de milliers de lignes, une clé primaire, donc une requête par création de lien et rien d'autre.

`variant_type` n'est pas décoratif : c'est lui qui produit le motif affiché au client — « domaine à une lettre de `laposte.fr` », et non « domaine suspect ».

La détection de marque **en sous-domaine** et celle de **scripts mélangés** ne passent pas par ces tables : elles se calculent à la volée sur le domaine enregistrable et les labels, en quelques microsecondes.

---

## 9. Signalements

```sql
create table reports (
    id             bigint generated always as identity primary key,
    public_id      text not null unique,                -- ULID, référence donnée au rapporteur
    link_id        bigint null references links(id) on delete set null,
    submitted_host text not null,                       -- 'iurl.fr' ou un domaine client
    submitted_slug text not null,                       -- ce que le rapporteur a tapé
    category       text not null
                   check (category in ('phishing','malware','other')),
    details        text,                                -- texte libre, 2 000 caractères max
    contact_email  text,                                -- chiffré au repos, facultatif
    state          text not null default 'new'
                   check (state in ('new','reviewing','actioned','rejected')),
    resolution     text,
    created_at     timestamptz not null default now(),
    resolved_at    timestamptz
);

create index reports_open on reports (state, created_at)
    where state in ('new','reviewing');
create index reports_link on reports (link_id) where link_id is not null;
```

**Ce que cette table ne contient pas, et ne doit jamais contenir** : IP, user-agent, referer, identifiant de session, empreinte de rate limiting. Le signalement est anonyme par construction, pas par promesse.

`contact_email` est chiffré au repos avec le cast `encrypted`, sans index aveugle : on n'a jamais besoin de retrouver un signalement par email. Il sert uniquement à répondre au rapporteur.

`link_id` est nullable : un rapporteur peut signaler un slug qui n'existe pas, ou un slug d'un domaine supprimé. `on delete set null` conserve le signalement comme trace après l'effacement du lien — c'est volontaire, un signalement n'est pas une donnée du lien.

**Rétention** : 12 mois, puis purge. Un rapport rejeté est conservé 90 jours, le temps de pouvoir répondre à une contestation.

---

## 10. Tables gérées par Laravel

`migrations`, `jobs`, `job_batches`, `failed_jobs`, `personal_access_tokens` (Sanctum). Ne pas les personnaliser, sauf les deux colonnes ajoutées à `personal_access_tokens` (§4).

Noter que `failed_jobs` peut contenir le payload d'un job — donc une destination de lien. La purge doit donc être bornée : `php artisan queue:prune-failed --hours=72`.

`sanctum:prune-expired --hours=24` tourne quotidiennement pour supprimer les jetons expirés depuis plus d'un jour.

---

## 11. Ce qui n'existe pas, et ne doit exister dans aucune migration

| Interdit | Raison |
| --- | --- |
| Colonne `ip_address` ou équivalent | Fait mentir le LIA |
| Colonne `user_agent` | Idem |
| Colonne `referer` / `referrer` | Idem |
| Table `clicks` / `link_visits` / `events` | Rend l'effacement impossible à tenir et crée un profil |
| Colonne `country` sur `links` | Le pays est une statistique, pas un attribut du lien |
| Table `visitors` ou `fingerprints` | Interdit par principe |
| Email en clair dans `users.email` | Le chiffrement est une mesure de sécurité, pas une convention : un test vérifie qu'aucune valeur lisible ne s'y trouve |
| Hash d'index aveugle exposé par l'API | `email_hash` n'est jamais sérialisé, jamais retourné, jamais accepté en entrée |

Chacune de ces lignes est couverte par un test d'introspection du schéma (`09-tests.md`, §2.2). Le test est le garde-fou ; ce tableau est la raison.

---

## 12. Cycle de vie

| Job | Fréquence | Effet |
| --- | --- | --- |
| Expiration des liens | Toutes les 5 min | Passe `status = 'disabled'` après `expires_at` |
| Agrégation des clics | Continu (Horizon) | Incrémente `link_stats_*` |
| Rotation du sel | Lundi 00:00 UTC | Remplace le sel, détruit l'ancien, vide les clés de dédup |
| Rétention des agrégats | Quotidien | Supprime `date < current_date - retention` |
| Rétention des tombstones | Jamais | Un slug effacé reste réservé indéfiniment |
| Blocklists | Quotidien | Upsert du dump, purge des entrées anciennes |
| Purge des jobs échoués | Quotidien | 72 h |
| Purge des jetons expirés | Quotidien | `sanctum:prune-expired --hours=24` |
| Purge des signalements | Quotidien | 12 mois ; 90 jours pour les rejetés |

---

## 13. Seuils de révision du schéma

Le schéma actuel tient sans difficulté jusqu'à plusieurs millions de liens. Signaux qu'il faut le faire évoluer :

| Signal | Action |
| --- | --- |
| `link_stats_*` dépasse ~50 M de lignes | Partitionner par mois sur `date` |
| Les requêtes de liste de liens dépassent 100 ms | Réévaluer l'index `(workspace_id, created_at desc)` |
| Le job d'expiration devient visible dans les métriques | Passer à un index partiel plus étroit ou à un balayage par plage d'horodatage |
| Plusieurs membres par workspace en usage réel | Ajouter les politiques d'accès, envisager le Row-Level Security |
| Besoin d'un environnement de test de charge réaliste | Générer des données, ne jamais copier la production (elle contient des emails) |

**Ne pas anticiper.** Partitionner 50 M de lignes qui n'existent pas est du temps perdu ; le faire quand le seuil est atteint prend une heure.
