# Perysee

**See what matters.** Statistiques web auto-hébergées : PHP 8.3+ / Laravel 13 / Vue 3, MariaDB 10.11, 11.4 ou 11.8.

Ce dossier est la racine du dépôt public [Perysee/perysee](https://github.com/Perysee/perysee). La landing et la documentation du produit sont dans `../perysee-web/`.

## Installation

```sh
curl -fsSL https://get.perysee.com | bash
```

L'installateur demande le mode (`--mode cpanel`, `docker` ou `manual` pour l'imposer), vérifie chaque fichier contre `SHA256SUMS` et ne remplace jamais une installation existante. Une version précise s'installe par son adresse : `https://get.perysee.com/releases/vX.Y.Z/install.sh`.

| Mode | Ce que fait l'installateur | Prérequis |
| --- | --- | --- |
| `cpanel` | Avec la commande locale `uapi`, crée le sous-domaine (racine `public/`), la base et son utilisateur, choisit le PHP 8.3+ le plus récent et programme le cron. N'ajoute que du nouveau et annule ce qu'il a créé en cas d'échec. | SSH sur le compte cPanel |
| `docker` | Construit l'image depuis la release vérifiée, puis lance l'application, son scheduler et MariaDB. Mise à jour : `--mode docker --upgrade --target <dossier>`. | Docker avec `compose`, `openssl`, un reverse proxy HTTPS devant `127.0.0.1:8080` |
| `manual` | Pose les fichiers ; le domaine, PHP web, la base et le cron restent à configurer. | PHP CLI 8.3+, une base MariaDB vide |

L'administrateur est créé avec un mot de passe saisi (invite masquée) ou généré et affiché une fois. Il n'est jamais passé en argument. `--finish` choisit la suite :

- `browser` (défaut en mode manuel) : un lien privé `https://domaine/install#secret=…` ouvre l'assistant déjà déverrouillé ;
- `terminal` : `php artisan perysee:install` termine tout (`PERYSEE_DB_PASSWORD` et `PERYSEE_ADMIN_PASSWORD` peuvent venir de l'environnement) ;
- `none` : seuls les fichiers sont posés.

**Sans terminal** : télécharger `https://get.perysee.com/perysee.zip`, extraire `perysee/` hors de la racine web, faire pointer le domaine sur `perysee/public/` et ouvrir `/install`. Le secret est dans `storage/app/private/installation/secret`.

**Hébergement** : HTTPS, racine du domaine sur `public/`, dossier de l'application, `storage/` et `bootstrap/cache/` inscriptibles (jamais `0777`), et le cron `* * * * * cd DOSSIER && php artisan schedule:run`. Ni Node, ni Composer, ni Redis, ni worker permanent.

## Fonctionnalités

- Sites avec domaines autorisés, exclusions et fuseau ; membres `owner` (écriture) ou `viewer` (lecture).
- Équipe : invitation par e-mail avec un rôle, valable 7 jours. Sans courrier configuré, le lien s'affiche une fois pour le propriétaire ; changement de rôle, retrait et départ, avec toujours un propriétaire.
- Liens de partage publics en lecture seule (`/s/{token}`) : sections au choix, mot de passe et expiration facultatifs, révocation. Le jeton n'est stocké que sous forme d'empreinte.
- Objectifs : jusqu'à cinq par site (événement nommé ou page, seuil par session), conversions attribuées au premier contact, versions successives. Un objectif défini après coup n'est compté que sur les événements bruts conservés (90 jours par défaut).
- Comparaison avec la période précédente : le pourcentage n'apparaît que lorsqu'il est fiable, sinon la raison est affichée.
- Lueur, le collecteur : pages vues sans identifiant ou sessions, consentement configurable, événements nommés.
- Rapports : pages, sessions, sources (dont IA), campagnes, appareils, pays, filtres croisés, funnels et parcours Sankey, exports CSV.
- Page de santé, rétention réglable et mise à jour assistée avec sauvegarde et retour arrière.
- [Modules](#modules) : White label, Annotations, Example.

## Développement local

Prérequis : PHP 8.3+, Composer 2, Node 24.21.0 / npm 11 et Apple container pour MariaDB.

```sh
composer install
bash scripts/mariadb.sh up
php scripts/configure-local.php
npm ci
npm run build
php artisan serve
```

Ouvrir [localhost:8000/install](http://localhost:8000/install). Le détail (MariaDB locale, données de démonstration, mise à jour, installateur) est dans [DEVELOPMENT.md](DEVELOPMENT.md).

## Vérifications

```sh
composer test
npm run test:distribution
npm run build
```

La suite rapide utilise SQLite. Avec MariaDB (`perysee_test`, base dédiée, la CI couvre 10.11, 11.4 et 11.8) :

```sh
bash scripts/mariadb.sh test-db
php scripts/configure-testing.php
composer test:mariadb
```

## Récupération

Sauvegarder `.env`, la base et les fichiers privés de l'installation. Ne jamais supprimer le verrou pour réinstaller ni régénérer `APP_KEY`.

Réinitialiser le mot de passe d'un administrateur **existant**, depuis un terminal de confiance :

```sh
php artisan perysee:recover-admin admin@example.com
```

La commande ne crée aucun compte et ne donne aucun droit supplémentaire ; les sessions précédentes doivent se reconnecter.

## Modules

Un module est un dossier (`module.json`, `src/`, `routes.php`, `database/migrations/`, `dist/module.js`) placé dans `storage/app/modules`. Activer l'installe ; désactiver conserve ses données.

```sh
php artisan perysee:modules list
php artisan perysee:modules enable annotations
php artisan perysee:modules disable annotations
php artisan perysee:make-module pdf-export --name="PDF export"
```

Si un module empêche l'instance de démarrer, `PERYSEE_MODULES=false` dans `.env` la lance sans modules. Le guide d'écriture et le catalogue sont dans la documentation (*Modules*).

## Documentation

Les guides sont dans `perysee-web/apps/docs/` (`npm run dev:docs` depuis `perysee-web/`, [localhost:3001](http://localhost:3001)) : [installation](http://localhost:3001/getting-started/shared-hosting), [Lueur et consentement](http://localhost:3001/tracking/installation), [confidentialité](http://localhost:3001/tracking/privacy).

## Licence

**GNU AGPL v3.0 uniquement**, avec l'exception de modules de Perysee ([LICENSE](LICENSE), [MODULE-EXCEPTION.md](MODULE-EXCEPTION.md), [LICENSING.md](LICENSING.md)). Le nom et le logo ne sont pas concédés ([TRADEMARKS.md](TRADEMARKS.md)). Contributions : [CONTRIBUTING.md](CONTRIBUTING.md) et [CLA.md](CLA.md).
