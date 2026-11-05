# Application Perysee

Application autonome **PHP 8.3+ / Laravel 13 / Vue 3 + Nuxt UI / MariaDB 11.4.x**. Ce dossier contient l’application du produit ; `../perysee-web/` contient sa landing et son site documentaire et n’est pas une dépendance de l’application.

Ce dossier est la racine du dépôt public [Perysee/perysee](https://github.com/Perysee/perysee) : `app/`, `artisan` et `.github/` sont directement à sa racine sur GitHub. Les sites de présentation ne font pas partie de ce dépôt.

## Installer Perysee

Sur un hébergement compatible, la commande publique d’installation est :

```sh
curl -fsSL https://get.perysee.com | bash
```

L’installateur vérifie PHP, télécharge les fichiers prêts à utiliser, demande le dossier de destination et l’URL HTTPS, puis affiche le document root (`DOSSIER/public`) et le lien `/install`. Ni npm, ni Node, ni Composer ne sont nécessaires sur l’hébergement. PHP CLI 8.3+, les extensions de l’application et PHP Zip sont nécessaires pour cette commande.

Sans terminal, le ZIP est proposé à `https://get.perysee.com/perysee.zip`. Extraire son dossier `perysee/` hors de la racine web, puis faire pointer le domaine sur `perysee/public/`. Terminer avec l’assistant web décrit ci-dessous.

Le développement local, les tests et la mise à jour assistée sont décrits dans [DEVELOPMENT.md](DEVELOPMENT.md), exclu du ZIP. La fabrication des releases et le déploiement de `get.perysee.com` sont gérés par les mainteneurs hors de ce dépôt.

## Fonctionnalités disponibles

- Installation depuis le navigateur : diagnostic, configuration MariaDB, migrations, premier administrateur et verrouillage définitif.
- Connexion, déconnexion, changement de mot de passe et récupération administrateur par commande.
- Création, liste, consultation et modification de sites, domaines autorisés, exclusions et fuseau de reporting (fixé après le premier événement). Suppression permanente différée par la maintenance.
- Isolation des sites par membre : `owner` en écriture, `viewer` en lecture. Aucun accès implicite aux sites pour l’administrateur d’instance.
- Interface Vue 3 / Inertia / Nuxt UI en anglais, responsive, accordée à la landing et à la documentation : Geist, palette commune et icônes Tabler locales. Aucun SSR ni serveur Node sur l’hébergement.

**Lueur et le collecteur sont disponibles**, avec pages vues sans identifiant ou sessions, consentement configurable et événements nommés. Les rapports consolidés couvrent pages, sessions commencées, sources, campagnes et appareils, avec exports CSV. Le contrôle de réception sur quinze minutes reste distinct des rapports. Le scheduler consolide les données et effectue la maintenance toutes les cinq minutes. Les administrateurs disposent d’une page de santé et de réglages de rétention. Hors sites de démonstration explicitement signalés, aucune statistique fictive n’est affichée ; la collecte confirme un événement après son insertion SQL. Les pays sont déterminés approximativement à partir d’une base MMDB locale, avec couverture explicite ; l’attribution distingue les sources IA reconnues. Les filtres croisés sont reconstruits depuis les événements conservés. Les funnels versionnés et parcours automatiques sont mesurés dans une même session, avec Sankey ECharts, calcul par lots et couverture historique explicite. Les pixels, invitations et inscriptions publiques ne sont pas implémentés.
- **Modules** : le Core accueille des fonctionnalités optionnelles sous forme de modules, activables et désactivables depuis la page **Modules** (administrateurs), limitables à certains sites, avec leur propre page de réglages. Trois modules sont maintenus avec les sources : White label, Annotations et Example. Voir [Modules](#modules).

## Développement local

Prérequis : PHP 8.3+ avec les extensions Laravel et PDO MySQL, Composer 2, Node 24.21.0/npm 11 pour compiler les assets et Apple container pour MariaDB locale.

```sh
composer install
bash scripts/mariadb.sh up
bash scripts/mariadb.sh health
php scripts/configure-local.php
npm ci
npm run build
php artisan serve
```

Le premier démarrage SQL peut prendre quelques secondes : relancer `health` avant de continuer. Ouvrir [localhost:8000/install](http://localhost:8000/install), récupérer le secret dans `storage/app/private/installation/secret`, puis suivre les étapes. Le fichier apparaît à la première ouverture de l’assistant. Utiliser les paramètres MariaDB locaux ci-dessous et le mot de passe de `infra/mariadb/.env`. L’assistant applique les migrations : aucune migration préalable n’est nécessaire.

| Élément | Valeur locale |
| --- | --- |
| Application | `http://localhost:8000` |
| Santé de démarrage | `/up`, sans requête SQL |
| Conteneur Apple | `perysee-mariadb` |
| Image | `docker.io/library/mariadb:11.4.8` |
| Adresse SQL | `127.0.0.1:13306` |
| Base / utilisateur | `perysee` / `perysee` |
| Volume | `perysee-mariadb-data` |
| Identifiants | `infra/mariadb/.env`, générés et ignorés par Git |

Le script local conserve les secrets déjà configurés. Ne pas lancer `key:generate` sur une instance existante. Arrêter MariaDB avec `bash scripts/mariadb.sh stop` conserve les données.

## Installation cible sans SSH

La release `perysee.zip` contient `vendor/` de production, les assets compilés dans `public/build/`, `public/fonts/`, `public/tracker/`. La qualification sur de vrais hébergements cPanel reste à réaliser.

1. Déployer les fichiers sur un hébergement PHP compatible et créer une base MariaDB **11.4.x** dédiée avec les droits nécessaires aux migrations.
2. Activer HTTPS et diriger la racine du domaine **vers `public/`**. Ne jamais exposer la racine de l’application. Vérifier également les alias et accès alternatifs du serveur.
3. Autoriser l’écriture dans le dossier de l’application pour créer/remplacer `.env`, ainsi que dans `storage/` et `bootstrap/cache/`. Ne pas utiliser de permissions universelles `0777`.
4. Déployer sans `.env` préexistant, ou utiliser une configuration de production avec `APP_DEBUG=false`, `APP_ENV=production`, sessions et cache fichiers. Ne pas copier les réglages locaux tels quels. Aucun cache `bootstrap/cache/config.php` ne doit être livré avant installation.
5. Ouvrir `/install`. La clé applicative et un secret aléatoire d’instance sont initialisés sans connexion SQL. Dans le gestionnaire de fichiers cPanel, ouvrir `storage/app/private/installation/secret` et reporter sa valeur dans l’assistant. Ne pas placer ce secret dans une URL ou un fichier public.
6. Corriger les diagnostics, saisir les paramètres SQL puis créer le compte administrateur. Les mots de passe exigent au moins 12 caractères, avec une limite de 72 octets pour bcrypt. Un champ de mot de passe SQL vide conserve une valeur déjà enregistrée pour la même connexion.
7. Après confirmation, se connecter et ajouter le premier site. Le secret est supprimé et `storage/app/private/installation/installed` verrouille l’installation.

L’assistant refuse une base contenant déjà des comptes. Une panne de connexion ne remplace pas la configuration existante. Après un échec de migration, corriger les droits et reprendre avec les mêmes paramètres : les migrations terminées sont conservées. Si la session expire, utiliser de nouveau le secret puis reprendre l’étape SQL.

Une instance installée fonctionne sans Node, Redis, Apple container, SMTP ni worker permanent. Les sessions et le cache utilisent des fichiers, et les dates SQL sont en UTC. L’agrégation et la maintenance bornées sont planifiées toutes les cinq minutes et nécessitent le scheduler. Aucun worker permanent n’est requis.

## Récupération

Sauvegarder `.env`, la base et les fichiers privés de l’installation. Ne jamais supprimer le verrou pour réinstaller, ni régénérer `APP_KEY` : restaurer la clé originale depuis une sauvegarde si elle manque.

Pour réinitialiser le mot de passe d’un administrateur **existant**, via un terminal de confiance (local, terminal cPanel ou intervention de l’hébergeur) :

```sh
php artisan perysee:recover-admin admin@example.com
```

Les deux saisies de mot de passe sont masquées. La commande ne crée aucun compte et n’accorde aucun droit supplémentaire. Elle restaure aussi le verrou si la création de l’administrateur avait réussi avant une interruption de finalisation. Les sessions précédentes doivent se reconnecter. Sans terminal disponible, faire intervenir l’exploitant ; aucun endpoint public de récupération n’est exposé.

## Vérifications

```sh
composer test
npm run test:distribution
npm run build
php artisan migrate:status
```

La suite rapide utilise SQLite en mémoire. Pour valider les migrations et le parcours complet avec MariaDB 11.4 :

```sh
bash scripts/mariadb.sh test-db
php scripts/configure-testing.php
composer test:mariadb
```

Cette commande crée une base et un compte **`perysee_test`** distincts. L’utilisateur SQL n’a des droits que sur cette base. `.env.testing` et les identifiants de test sont privés et ignorés par Git. Le bootstrap refuse toute autre base/utilisateur, une URL SQL alternative, un cache de configuration ou une base de développement portant le même nom.

**Les tests MariaDB réinitialisent uniquement cette base dédiée.** Ils couvrent installation à partir d’une base vide, reprise, verrouillage, erreurs SQL, authentification, CSRF, récupération et isolation entre sites. Les tests n’installent pas l’instance locale et ne créent pas son administrateur.

## Modules

Un module est un dossier (`module.json`, `src/`, `routes.php`, `database/migrations/`, `dist/module.js`) placé dans `storage/app/modules` (ou `PERYSEE_MODULES_PATH`). La première activation l'installe et exécute ses migrations ; désactiver conserve ses données. Les modules maison sont versionnés dans `modules/` et ne sont pas inclus dans le ZIP du Core.

```sh
php artisan perysee:modules list
php artisan perysee:modules enable annotations
php artisan perysee:modules update annotations --archive=annotations-1.1.0.zip   # le module doit être désactivé
php artisan perysee:modules enable lab --accept-risk       # requis pour un module « experimental »
php artisan perysee:modules disable annotations
php artisan perysee:modules uninstall annotations --force   # supprime tables et réglages
php artisan perysee:make-module pdf-export --name="PDF export"
```

En local, relier un module maison au dossier des modules : `ln -s ../../../modules/example storage/app/modules/example`. Le guide d'écriture est dans la documentation (`Modules > Build a module`) et l'architecture dans `docs/planning/02-ARCHITECTURE-TECHNIQUE.md` (section 11). Chaque module déclare sa stabilité dans `module.json` (`stable`, `beta` ou `experimental`, `beta` par défaut) : l’interface l’affiche, et un module expérimental ne s’active qu’après confirmation (`--accept-risk` en terminal). Un module qui échoue de façon répétée (chargement, données de page, marque, carte Santé, exceptions levées par ses fichiers) est mis en quarantaine : désactivé avec ses dépendants, données conservées, motif affiché sur la page Modules et la page Santé ; réactivez-le une fois corrigé. Seuils dans `config/perysee.php` (`module_failures`). Un module se met à jour depuis la page Modules ou en terminal avec une archive de version strictement supérieure : l’ancien dossier est conservé dans `storage/app/modules-backup` (trois versions), et les migrations de la nouvelle version s’appliquent à la prochaine activation. `module.json` déclare aussi `api` (version du contrat entre le Core et les modules, 1 aujourd’hui) : un module écrit pour une autre version est refusé. Si un module empêche l’instance de fonctionner, `PERYSEE_MODULES=false` dans `.env` démarre Perysee sans charger aucun module (ni leurs scripts) ; leur état est conservé et la page Modules l’indique. Les droits par module et le téléchargement de bundles signés ne sont pas implémentés.

## Documentation du produit

Les guides sont maintenus dans le site documentaire `perysee-web/apps/docs/`, séparé du dépôt et de la distribution Laravel. En local, lancer `npm run dev:docs` depuis `perysee-web/` pour ouvrir [la documentation](http://localhost:3001). Les liens ci-dessous utilisent cette adresse locale ; en production, définir `PERYSEE_DOCS_URL` avec l’origine réelle du site documentaire pour les liens de l’application.

- [Développement local et tests](DEVELOPMENT.md).
- [Installation sur un hébergement](http://localhost:3001/getting-started/shared-hosting).

## Suivi et données de démonstration

- [Installer Lueur, intégrer le consentement et tester en local](http://localhost:3001/tracking/installation).
- [Choisir entre pages vues, sessions et politique de consentement](http://localhost:3001/tracking/privacy), avec les sources CNIL et CEPD.

```sh
php artisan migrate
php artisan db:seed --class=LocalDemoSeeder
```

La seed locale crée deux sites de démonstration attachés au premier administrateur sans modifier son compte. Elle ne fabrique aucun événement. Ouvrir un site puis **Open local test site** pour générer de vraies pages vues.

```sh
npm run build:tracker
npm run test:tracker
```

Le build complet inclut le script minifié et son gzip. En production, activer le cron décrit dans le guide de suivi pour appliquer la rétention des événements.

Les commandes `perysee:rebuild-segments --batch=500` et `perysee:journey-reports --batch=500 --budget=10` sont intégrées au scheduler. Les rapports de parcours sont des snapshots temporaires privés, purgés après un jour. Les définitions de funnels restent versionnées ; aucune donnée purgée n’est reconstruite artificiellement.

Pour remplir les **deux sites de démonstration connus uniquement**, en local :

```sh
php artisan perysee:demo-reset
# Reproduire exactement le même jeu à date identique :
php artisan perysee:demo-reset --seed=73421
```

Le reset génère 30 jours de trafic synthétique avec horaires, volumes, sources et chemins variés. La démo avec sessions reçoit trois funnels préconfigurés (inscription, téléchargement, contact), leurs résultats sur sept jours et un parcours automatique déjà calculé. Les comptes, sites réels et définitions de funnels existantes sont conservés. Le mode sans identifiant conserve ses limites : aucun parcours ne peut y être reconstruit. La commande est atomique et refusée en production.

## Licence

Perysee Core est publié sous licence **GNU AGPL v3.0 uniquement**, avec l’exception de modules de Perysee ([LICENSE](LICENSE), [MODULE-EXCEPTION.md](MODULE-EXCEPTION.md), [LICENSING.md](LICENSING.md)). Le nom et le logo Perysee ne sont pas concédés ([TRADEMARKS.md](TRADEMARKS.md)). Contributions : [CONTRIBUTING.md](CONTRIBUTING.md) et [CLA.md](CLA.md).
