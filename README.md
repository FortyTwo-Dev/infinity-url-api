# Infinity URL — API

L'API du raccourcisseur d'URL privacy-first d'Infinity Project.
Domaine de liens : **`iurl.fr`**.

Ce dépôt contient uniquement le service : la route de redirection, l'API REST et
le pipeline analytique. Il n'y a **ni interface, ni Blade, ni Vite**. Le
dashboard et le site vivent dans le dépôt `infinity-url`.

## Ce que fait ce service

```
GET iurl.fr/abc123  →  302 Location: https://example.com  →  destination
```

Pas de page intermédiaire, pas de corps de réponse, pas de cookie sur le domaine
de liens. Les statistiques sont agrégées : aucune IP n'est conservée, aucune
ligne par clic n'existe.

## Démarrage rapide

Prérequis : PHP 8.5, Composer, Podman (ou Docker).

```sh
systemctl --user enable --now podman.socket
export DOCKER_HOST=unix://$XDG_RUNTIME_DIR/podman/podman.sock
podman compose up -d

composer install
cp .env.example .env
php artisan key:generate
openssl rand -base64 32     # → BLIND_INDEX_KEY dans .env
php artisan migrate

# base de test (une seule fois)
php artisan tinker --execute="DB::connection()->getPdo()->exec('CREATE DATABASE infinity_url_test');"

php artisan serve
```

Vérification : <http://localhost:8000/up> · Mailpit : <http://localhost:8025>

## Documentation

Le dossier de conception est la référence, pas un complément :

| Document | Contenu |
| --- | --- |
| [`docs/00-README.md`](docs/00-README.md) | Index et conventions |
| [`docs/01-decisions.md`](docs/01-decisions.md) | Toutes les décisions, `D-xx` |
| [`docs/07-schema.md`](docs/07-schema.md) | Modèle de données, en SQL |
| [`docs/10-api.md`](docs/10-api.md) | Contrat HTTP |
| [`docs/11-pipeline.md`](docs/11-pipeline.md) | Du clic au compteur |
| [`docs/09-tests.md`](docs/09-tests.md) | Ce que les tests prouvent |
| [`docs/08-setup.md`](docs/08-setup.md) | Déploiement et exploitation |

Pour les agents : **[`AGENTS.md`](AGENTS.md)**. Les règles de chemin sont servies
par `ibrain` (`ibrain resolve <fichier> --list`), depuis le pack
`FortyTwo-Dev/iproject-ibrain-packs`.

## Qualité

```sh
php artisan test
./vendor/bin/pint --test
./vendor/bin/phpstan analyse
```

La CI exécute ces trois commandes, plus `composer audit` et un contrôle de
licences (aucune dépendance `GPL-2.0-only`, incompatible avec l'AGPL).

## Licence

[AGPL-3.0-only](LICENSE). Le code est ouvert et le restera : toute instance
publique doit offrir le code source correspondant.
