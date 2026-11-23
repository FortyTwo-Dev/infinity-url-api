# Infinity URL — API

L'API du raccourcisseur d'URL privacy-first d'Infinity Project.
Domaine de liens : **`iurl.fr`**.

Ce dépôt contient le service : la route de redirection, l'API REST, le pipeline
analytique et l'anti-abus. Blade n'est utilisé que pour les quatre pages
d'erreur de `iurl.fr` ; il n'y a ni Vite, ni interface. Le dashboard et le site
vivent dans le dépôt `infinity-url`.

## Ce que fait ce service

```
GET iurl.fr/abc123  →  302 Location: https://example.com  →  destination
```

Pas de page intermédiaire, pas de corps de réponse, pas de cookie sur le domaine
de liens. Les statistiques sont agrégées : aucune adresse IP n'est écrite en
base, aucune ligne par clic n'existe.

## Démarrage rapide

Prérequis : PHP 8.5 (le projet exige `^8.3` ; 8.5 est la version de
développement), Composer, Podman (ou Docker). PostgreSQL 17, Valkey 8 et
Mailpit sont lancés par `compose.yml`.

```sh
# 1. Services (Podman rootless)
systemctl --user enable --now podman.socket
export DOCKER_HOST=unix://$XDG_RUNTIME_DIR/podman/podman.sock
podman compose up -d

# 2. Application
composer install
cp .env.example .env
php artisan key:generate

# 3. Secrets locaux
openssl rand -base64 32        # → BLIND_INDEX_KEY dans .env
mkdir -p /tmp/iurl-secrets
openssl rand -hex 32 > /tmp/iurl-secrets/analytics.salt
chmod 600 /tmp/iurl-secrets/analytics.salt
# → ANALYTICS_SALT_PATH=/tmp/iurl-secrets/analytics.salt dans .env

# 4. Base
php artisan migrate

# 5. Base de test (une seule fois)
php artisan tinker --execute="DB::connection()->getPdo()->exec('CREATE DATABASE infinity_url_test');"

# 6. Serveur
php artisan serve --port=8000
```

Dans deux autres terminaux, pour les files et les tâches planifiées :

```sh
php artisan horizon
php artisan schedule:work
```

Vérification : <http://localhost:8000/up> · Mailpit : <http://localhost:8025>

`php artisan serve` ne transmet pas les variables d'environnement au serveur
enfant : mets les réglages dans `.env`, ou lance le serveur depuis `public/`
avec `php -S 0.0.0.0:8000 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php`.

### Le domaine de liens en local

Avec `LINK_HOST=iurl.fr` et `LINK_URL=` (valeurs de `.env.example`), le chemin
de redirection se teste sans toucher au DNS :

```sh
curl -sI -H 'Host: iurl.fr' http://localhost:8000/abc123
```

Pour une URL courte cliquable dans le navigateur, utilise un hôte local dédié :

```sh
# .env
LINK_HOST=iurl.test
LINK_URL=http://iurl.test:8002

# /etc/hosts
127.0.0.1 iurl.test

# depuis public/
php -S 0.0.0.0:8002 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
```

Ne réutilise pas le port 8001 : il peut appartenir à une autre instance.

## Configuration

Les variables qui changent le comportement en v0.1 :

| Variable | Rôle | Valeur locale |
| --- | --- | --- |
| `LINK_HOST` | Hôte servi par la redirection | `iurl.fr` |
| `LINK_URL` | Base publique de l'URL courte, avec schéma et port | vide (canonique `https://iurl.fr`) |
| `DASHBOARD_URL` | Origine du dashboard, liens des emails et CORS | `http://localhost:3000` ou `3003` |
| `SIGNUPS_OPEN` | Kill switch des inscriptions (D-66) | `true` en local, `false` en production |
| `ABUSE_CHECK_ENABLED` | Vérification de destination à la création | `true` ; `false` seulement pour l'E2E |
| `PSL_PATH` | Liste des suffixes publics | vide : le fichier vendu dans `resources/psl` est utilisé |
| `URLHAUS_URL`, `URLHAUS_AUTH_KEY` | Dump quotidien d'abuse.ch | URL publique, clé gratuite |
| `PHISHING_ARMY_URL`, `OPENPHISH_URL` | Dumps de phishing | URLs publiques |
| `RDAP_URL` | Âge du domaine, domaine enregistrable seul | `https://rdap.org/domain/` |
| `NTFY_URL` | Sujet d'alerte auto-hébergé | vide : aucune alerte, jamais un échec |
| `ANALYTICS_SALT_PATH` | Fichier du sel analytique, hors base et hors sauvegarde | `/tmp/iurl-secrets/analytics.salt` |
| `STATS_RETENTION_DAYS` | Rétention des agrégats | `30` |

`PSL_PATH` est lu avec `env('PSL_PATH') ?: défaut` : une variable vide ne
désactive pas la liste, elle retombe sur la copie vendue dans le dépôt. Une
variable vide ne se comporte pas comme une variable absente.

`BLIND_INDEX_KEY` est générée une fois et ne tourne jamais : elle doit rester
distincte d'`APP_KEY`, sinon la rotation de celle-ci casserait l'unicité des
emails.

## Première configuration de l'anti-abus

```sh
php artisan abuse:import-brands       # liste de départ, une fois par environnement
php artisan abuse:rebuild-lookalikes  # variantes précalculées
php artisan abuse:refresh-blocklists  # dumps complets, une fois avant l'ouverture
php artisan abuse:review-queue        # file de revue, lecture seule
```

Les marques ne sont jamais devinées : la liste est courte, sourcée et revue à la
main. Une proposition de `abuse:propose-brands` n'est appliquée que par un
humain.

## Exploitation

| Commande | Fréquence | Effet |
| --- | --- | --- |
| `abuse:refresh-blocklists` | Quotidien, 04:10 UTC | Dumps complets, `upsert`, purge > 30 jours |
| `abuse:propose-brands` | Hebdomadaire, lundi 04:40 UTC | Propositions issues des dumps, jamais appliquées seules |
| `abuse:monitor-reputation` | Quotidien, 05:00 UTC | `iurl.fr` et domaines clients contre la blocklist locale, alerte ntfy |
| `abuse:purge-reports` | Quotidien, 03:30 UTC | 12 mois, 90 jours pour un signalement rejeté |
| `analytics:rotate-salt` | Hebdomadaire, lundi 00:00 UTC | Remplace le sel et détruit l'ancien |
| `analytics:purge-stats` | Quotidien, 03:15 UTC | Purge des agrégats au-delà de `STATS_RETENTION_DAYS` |
| `users:purge-deleted` | Quotidien | Suppression physique après 30 jours de grâce |

Actions manuelles de l'exploitant, sans écran d'administration :

```sh
php artisan abuse:block-link <slug|public_id> --reason="phishing" --report=<public_id>
php artisan abuse:suspend-account <email|public_id> --reason="repeat abuse"
php artisan abuse:resolve-report <public_id> actioned|rejected --reason="..."
```

En production, le scheduler tourne par cron (`php artisan schedule:run` chaque
minute) et Horizon sous supervisor ou systemd. `php artisan schedule:work` est
la version de développement.

## Pages légales et DPA

Les pages légales sont publiées par le dépôt `infinity-url`, en français, sur
`/legal/notice`, `/legal/terms`, `/legal/aup`, `/legal/privacy`,
`/legal/subcontractors` et `/legal/client-clause`. Les routes `/en/legal/*`
servent le même texte avec un avertissement traduit.

Le modèle d'accord de sous-traitance est versionné dans
[`docs/legal/dpa.md`](docs/legal/dpa.md). Il est fourni sur demande et n'est pas
une page publique. La clause client est publiée et copiable sur
`/legal/client-clause`.

## Documentation

Le dossier de conception est la référence, pas un complément :

| Document | Contenu |
| --- | --- |
| [`docs/00-README.md`](docs/00-README.md) | Index et conventions |
| [`docs/01-decisions.md`](docs/01-decisions.md) | Toutes les décisions, `D-xx` |
| [`docs/03-conformite.md`](docs/03-conformite.md) | Cartographie, bases légales, rétention, sous-traitants |
| [`docs/04-abus-et-domaines.md`](docs/04-abus-et-domaines.md) | Anti-abus, signalement, takedown, DSA |
| [`docs/06-lia.md`](docs/06-lia.md) | Analyses d'intérêt légitime, preuves techniques |
| [`docs/07-schema.md`](docs/07-schema.md) | Modèle de données, en SQL |
| [`docs/08-setup.md`](docs/08-setup.md) | Déploiement et exploitation |
| [`docs/09-tests.md`](docs/09-tests.md) | Ce que les tests prouvent |
| [`docs/10-api.md`](docs/10-api.md) | Contrat HTTP |
| [`docs/11-pipeline.md`](docs/11-pipeline.md) | Du clic au compteur |
| [`docs/15-registre-traitements.md`](docs/15-registre-traitements.md) | Registre art. 30 |
| [`docs/16-decision-dpia.md`](docs/16-decision-dpia.md) | Décision DPIA |
| [`docs/legal/dpa.md`](docs/legal/dpa.md) | Modèle de DPA |

Pour les agents : **[`AGENTS.md`](AGENTS.md)**. Les règles de chemin sont servies
par `ibrain` (`ibrain resolve <fichier> --list`), depuis le pack
`FortyTwo-Dev/iproject-ibrain-packs`.

## Qualité

```sh
php -d memory_limit=1G vendor/bin/pest
./vendor/bin/pint --test
./vendor/bin/phpstan analyse --no-progress --memory-limit=1G
php artisan scramble:export --fail-on-unknown
```

La limite de mémoire est explicite : la suite dépasse les 128 Mo par défaut de
certaines installations PHP.

La CI exécute ces commandes, plus `composer audit` et un contrôle de licences
(aucune dépendance `GPL-2.0-only`, incompatible avec l'AGPL).

Le parcours E2E du dépôt web utilise une base dédiée (`infinity_url_e2e`), les
ports 8000 et 3002, Mailpit et `ABUSE_CHECK_ENABLED=false`. Il ne touche pas à
la base de développement.

## Licence

[AGPL-3.0-only](LICENSE). Le code est ouvert et le restera : toute instance
publique doit offrir le code source correspondant.
