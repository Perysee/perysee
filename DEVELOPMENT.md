# Distribution et exploitation — documentation interne

Ce fichier est réservé aux mainteneurs. Il reste dans le dépôt privé et n’est ni importé par le site documentaire public, ni inclus dans le ZIP distribué.

## Parcours public

La seule commande montrée aux utilisateurs est :

```sh
curl -fsSL https://get.perysee.com | bash
```

Le script contrôle PHP CLI, télécharge les assets, vérifie le SHA-256 de l’installateur PHP avant de l’exécuter, puis lui transmet le ZIP et ses sommes de contrôle. L’installateur PHP vérifie à son tour le ZIP, demande le dossier et l’URL HTTPS, extrait dans un dossier temporaire, refuse toute destination non vide et affiche le document root et le lien `/install`.

Les questions passent par `/dev/tty` lorsque le script est lancé via un pipe : le code téléchargé et les réponses de l’utilisateur ne partagent pas la même entrée. Sans terminal interactif, utiliser `bash install.sh --target /chemin/perysee --url https://stats.example.com --yes`.

## Construire une distribution

Le dépôt GitHub `Perysee/app` contient directement le contenu de `perysee/`. Les sites Nuxt dans `perysee-web/` restent séparés.

Dans un checkout neuf, avec PHP 8.3+, PHP Zip, Composer 2 et la version Node de `.nvmrc` :

```sh
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
npm ci
npm run build
php scripts/build-release.php --version v0.1.1 --repository Perysee/app
```

`dist/` reçoit quatre fichiers :

- `perysee.zip` : application de production, dépendances, assets, polices, tracker.
- `install.php` : installateur autonome lié à cette version.
- `install.sh` : point d’entrée shell, lié à cette version.
- `SHA256SUMS` : empreintes des trois fichiers précédents.

Le script shell publié utilise par défaut `https://get.perysee.com/releases/VERSION` : son exécution directe ne nécessite aucun identifiant GitHub côté client. La redirection de la racine de `get.perysee.com` sert le script de la release, qui fixe lui-même cette adresse pour chaque installation.

Le packager utilise une liste explicite de chemins. Il ne distribue ni `.env`, ni secrets/verrous d’installation locaux, ni sessions, logs, caches PHP générés, tests, infrastructure, `node_modules/` ou ce fichier `DEVELOPMENT.md`. Les procédures Markdown internes ne sont pas incluses ; les guides publics sont servis par le site documentaire Nuxt.

## Tests et workflows

Avec les dépendances de développement installées :

```sh
npm run test:distribution
```

Les tests de distribution couvrent les ZIP propres, les collisions de destination, les checksums, les chemins dangereux et liens symboliques, le script shell, les téléchargements interrompus et le parcours interactif réellement lancé via un pipe dans un pseudo-terminal. Les tests PHP de l’ancien relais et ses tests HTTP couvrent les fichiers autorisés, les redirections, le cache, HEAD et la protection du jeton ; `tests/Distribution/get.test.mjs` couvre les redirections actuelles de `get.perysee.com`.

Le workflow `Application checks` couvre PHP 8.3/8.5, SQLite, une MariaDB 11.4.8 dédiée, le tracker et la distribution.

## Publier une release

La version de référence est `'version'` dans `config/perysee.php` (sans préfixe `v`). Le tag Git est `v` + cette valeur. `scripts/build-release.php` refuse de construire si le tag demandé et la configuration divergent : l'application compare cette valeur aux releases publiées pour proposer ses mises à jour.

1. Lancer le workflow **Prepare release** (Actions, `workflow_dispatch`) avec la version, par exemple `0.2.0` ou `0.2.0-beta.1`. Il vérifie que la version est plus récente et que le tag n'existe pas, change `config/perysee.php`, pousse `release/v0.2.0` et ouvre la pull request avec la liste des changements. Réglage requis une fois : Settings > Actions > General > « Allow GitHub Actions to create and approve pull requests ».
2. **Relire et fusionner cette pull request est l'approbation de la release.** Les contrôles de la PR ne se lancent pas pour une PR ouverte par le jeton du workflow ; `Release` les rejoue de toute façon avant de publier.
3. À la fusion sur `main`, le workflow **Release** lit la version dans `config/perysee.php`. Si le tag n'existe pas, il exécute `Application checks`, fabrique les assets, vérifie `SHA256SUMS`, installe le vrai ZIP, puis (job `Publish`, environnement `release`) crée la release GitHub avec `perysee.zip`, `install.php`, `install.sh` et `SHA256SUMS` en brouillon, la publie, et c'est cette publication qui crée le tag `vX.Y.Z` sur le commit fusionné. Pour une validation manuelle supplémentaire, ajouter des relecteurs obligatoires à l'environnement `release`.
4. Les instances installées lisent ces mêmes releases GitHub (`/releases/latest`, qui exclut brouillons et préversions) et proposent la mise à jour. Une release n'existe pour elles que lorsque l'archive **et** `SHA256SUMS` sont attachés.

Un push sur `main` qui touche `config/perysee.php` sans changer la version est sans effet : le tag existe déjà. Un lancement manuel de `Release` construit et teste sans rien publier. Les versions avec suffixe, par exemple `v0.2.0-beta.1`, sont des préversions et ne deviennent pas la dernière version stable. Ne pas remplacer les assets d'un tag déjà publié : les instances ont pu les télécharger et vérifier leur somme de contrôle.

Les instances lisent l'API publique de GitHub sans jeton. Tant que le dépôt est privé, le contrôle de mise à jour échoue avec un message explicite (HTTP 404/403) : l'ouverture du code (LIC-01) ou un autre point de publication public est un préalable pour que les clients voient les mises à jour. `PERYSEE_UPDATE_REPOSITORY` change le dépôt interrogé, `PERYSEE_UPDATE_CHECK=false` désactive le contrôle.

## get.perysee.com : redirections vers les releases GitHub

`get.perysee.com` ne stocke ni ne sert aucun fichier : le seul contenu de sa racine web est `infra/get/.htaccess`, qui redirige (302) vers les assets des releases publiques de `Perysee/app`. Aucun PHP, jeton, cache ni espace disque n’est nécessaire.

**Prérequis : le dépôt `Perysee/app` doit être public.** Tant qu’il est privé, GitHub répond 404 aux téléchargements sans jeton, et la commande publique comme la détection de mise à jour des instances échouent. L’ancien relais PHP (`infra/downloads/`, jeton fin et cache privé) reste dans le dépôt pour un éventuel retour en arrière ; il n’est plus déployé.

Routes (tout le reste renvoie 404, les méthodes autres que GET/HEAD renvoient 405, HTTP est redirigé vers HTTPS) :

- `/` redirige vers `releases/latest/download/install.sh` : c’est la commande `curl -fsSL https://get.perysee.com | bash`. Ce script est celui de la release : `scripts/build-release.php` y fixe `https://get.perysee.com/releases/vX.Y.Z`, donc une installation ne mélange jamais deux versions même si la stable change en cours de route.
- `/perysee.zip`, `/install.php`, `/install.sh` et `/SHA256SUMS` redirigent vers la dernière stable (`releases/latest/download/`, qui exclut brouillons et préversions).
- `/releases/vX.Y.Z/NOM` redirige vers `releases/download/vX.Y.Z/NOM` pour ces quatre assets uniquement ; une préversion reste accessible par son tag explicite.
- Ne jamais remplacer les assets d’un tag publié : des instances ont pu les télécharger et vérifier leur somme de contrôle.

Le workflow `deploy-get.yml` (environnement `production-get`, secrets cPanel du dépôt) publie le `.htaccess` dans `/home/CPANEL_USER/perysee/get/public/`, racine web de `get.perysee.com`, puis vérifie les redirections. Dans cPanel, le sous-domaine doit utiliser cette racine, avec HTTPS (AutoSSL) et des réécritures autorisées (`AllowOverride FileInfo Options`). Si le DNS de `perysee.com` est géré par Cloudflare, des règles de redirection Cloudflare équivalentes évitent d’héberger ce fichier ; elles ne sont pas testées ici.

Vérifier la mise en service depuis une machine sans identifiants GitHub :

```sh
curl -sI https://get.perysee.com | grep -i '^location'
curl -fsSL https://get.perysee.com -o /tmp/perysee-install.sh
bash /tmp/perysee-install.sh
```

Utiliser une destination neuve et vérifier les questions interactives, le document root affiché et le lien `/install`. Les règles sont testées par `tests/Distribution/get.test.mjs` (évaluation des `RewriteRule`) ; seule la qualification sur Apache/LiteSpeed réel couvre la sémantique du serveur.

La landing, les docs, l’API et la plateforme sont déployées depuis le dépôt `Perysee/web` (voir `docs/DEPLOIEMENT.md` de ce dépôt).

## Interface : navigation Inertia et Nuxt UI

- **Liens internes = chemins.** Nuxt UI rend en simple `<a>` (donc recharge tout le document) tout lien qui porte un protocole. Les adresses envoyées à l'interface sont donc des chemins : `Ui::relativize` s'en charge pour les données de page et les propriétés partagées, et `queryUrl` garde un chemin comme chemin. `InternalLinksTest` échoue si un écran envoie un lien interne absolu. Un lien vers un autre site (`docsUrl`) reste absolu et doit porter `external: true`.
- **Propriétés partagées réservées.** `Ui::render` refuse un écran qui envoie `links`, `auth`, `branding`, `modules`, `assets`, `navigation`, `flash`, `currentUrl`, `docsUrl` ou `errors` : la propriété de page remplacerait la propriété partagée pour toute l'interface.
- **Événements Inertia v3.** Seuls `httpException` et `networkError` existent pour les erreurs (`invalid` et `exception` n'existent plus). Le layout les écoute et affiche un toast ; en développement, la modale d'Inertia reste affichée pour voir la page d'exception.
- **Coque.** `WorkspaceLayout.vue` (layout persistant d'Inertia) assemble `UDashboardGroup`, `UDashboardSidebar` (ses slots servent aussi au menu mobile, fermé à chaque navigation) et `UDashboardPanel`. Le slot par défaut du panneau **remplace** ses slots `header`, `body` et `footer` : la barre du haut est placée dans ce slot. Le `main` qui défile porte `scroll-region`, sinon Inertia ne remet pas le défilement à zéro ni ne le restaure. Ne pas ajouter `resizable` (racine multiple).
- **Rechargements pendant le développement.** Chaque `npm run build` change la version des assets : la visite suivante est un rechargement complet (réponse 409). C'est normal. `npm run dev` l'évite.

## Modules

Les fonctionnalités optionnelles sont des modules (architecture : `docs/planning/02-ARCHITECTURE-TECHNIQUE.md`, section 11 ; guide public : documentation, `Modules > Build a module`).

- **Sources** : les modules maison sont dans `modules/<id>/` (White label, Annotations, Example). Ils sont versionnés avec le Core mais **absents du ZIP** : `scripts/build-release.php` n'embarque que sa liste explicite de chemins, et `storage/` n'en fait pas partie. `resources/module-template/` (gabarit de `perysee:make-module`) est en revanche distribué.
- **En local** : relier un module au dossier chargé par l'application, par exemple `ln -s ../../../modules/annotations storage/app/modules/annotations`, puis `php artisan perysee:modules enable annotations`. L'activation écrit en base ; la première installe aussi les migrations du module.
- **Nouveau module** : `php artisan perysee:make-module <id>` crée le squelette dans le dossier des modules ; le déplacer dans `modules/` pour le versionner. Préfixer les tables et les noms de migration par l'identifiant (les noms de migration sont communs à tous les modules).
- **Tests** : `php artisan test --compact --filter=Module` (registre, chargeur, installation, périmètre, extensions, pages d'administration, robustesse), `AnnotationsModuleTest`, `WhiteLabelModuleTest`, `ExampleModuleTest`, `MakeModuleTest`, et `npm run test:charts` (hôte JavaScript, rendu des modules). Un module qui s'appuie sur un autre sujet de test charge `base_path('modules')` via `perysee.modules_path`.
- **Avant de livrer un module** : activation sans erreur de chargement, refus des rôles insuffisants, absence hors du périmètre choisi, activation répétée sans seconde migration, désinstallation limitée à ses tables. Le guide public donne la même liste.
- **Limites actuelles** : pas de téléchargement, de signature ni de droits par module. La suite MariaDB (`composer test:mariadb`) n'a pas encore été lancée sur ces migrations.

## Tests privés avant activation du domaine

Le test hors réseau d’un ZIP reste disponible :

```sh
php dist/install.php --archive dist/perysee.zip --checksums dist/SHA256SUMS \
  --target /chemin/parent-existant/perysee-test \
  --url https://stats.example.com --yes
```

Pour tester un autre miroir HTTPS sans modifier le script, exporter `PERYSEE_DOWNLOAD_URL` avant de lancer `bash install.sh`. Cette option est interne : le script de la release fixe automatiquement l’URL versionnée de `get.perysee.com`.

L’installateur PHP conserve la prise en charge interne de `GH_TOKEN` ou `GITHUB_TOKEN` pour télécharger une release privée directement. Pour tester ce chemin, télécharger `scripts/install.php` via un compte autorisé, exporter le jeton en mémoire puis lancer le fichier avec PHP. Ne pas mettre ces instructions dans Nuxt Content. Ne jamais passer le jeton dans une URL ou un argument de l’installateur.

## Développement local — environnement et démonstrations

Les commandes de cette page s’exécutent dans `perysee/` dans l’espace de travail local, ou à la racine du dépôt Laravel lorsqu’il est cloné seul. La landing et ce site documentaire sont deux applications séparées dans `perysee-web/`.

Pour un hébergement en production, suivre [Install on shared hosting](/getting-started/shared-hosting). Pour le suivi et le consentement, consulter [Installer Lueur](/tracking/installation) et [Confidentialité](/tracking/privacy).

## Préparer l’environnement


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

## Sites de démonstration

- [Installer Lueur, intégrer le consentement et tester en local](/tracking/installation).
- [Choisir entre pages vues, sessions et politique de consentement](/tracking/privacy), avec les sources CNIL et CEPD.

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

## Démonstrations locales de Lueur

## Démonstrations locales

Après installation et migrations :

```sh
php artisan db:seed --class=LocalDemoSeeder
```

Deux sites sont attachés au premier administrateur : **Demo · No identifier** et **Demo · Sessions**. Relancer la seed ne réinitialise ni compte, ni mot de passe, ni réglages existants. Elle est refusée en production et ne crée pas d’administrateur.

Ouvrir un site dans Perysee, puis **Tracking setup → Open local test site**. Les pages Home, About et Pricing permettent de générer de vrais événements. Cliquer sur **Allow measurement**, naviguer, puis revenir à la fiche et rafraîchir. **Withdraw / object** teste l’arrêt et la suppression du cookie. La démonstration redemande l’activation après chaque navigation complète, car elle ne remplace pas une CMP. Les cookies de connexion au backoffice sont distincts du cookie de mesure.

Les domaines `localhost` et `127.0.0.1` sont autorisés uniquement sur ces sites de démonstration. Ces pages sont réservées à l’environnement local et aux membres du site. La seed ne fabrique aucun événement.


## MVP analytique — commandes et garanties

Les migrations additives créent `stats_daily`, `stats_hourly`, `stats_dimension_daily`, `measured_sessions`, `analytics_state` et `site_measurement_periods`. Elles conservent les comptes, sites, événements bruts et APP_KEY. Les événements existants restent en attente et sont repris en lots ; les sources/appareils historiques ne sont pas inventés. Le fuseau se verrouille dès la première collecte, sous verrou de la ligne du site partagé avec le contrôleur de réglages.

```sh
php artisan migrate --force
php artisan perysee:aggregate --batch=500 --budget-seconds=15
php artisan perysee:maintenance --limit=1000
```

En développement, lancer `php artisan schedule:work` dans un terminal distinct pour assurer la consolidation pendant les essais locaux. En hébergement, le cron appelle `schedule:run` chaque minute ou toutes les cinq minutes. Agrégation et maintenance se déclenchent toutes les cinq minutes, avec exclusion mutuelle via un verrou nommé MariaDB, indépendant du cache. Chaque transaction prend les événements non traités avec `FOR UPDATE SKIP LOCKED`, met à jour synthèses et déduplication, puis marque exactement les IDs du lot. Aucun curseur dernier ID ne sert de garantie. Un ID antérieur peut être repris après un commit tardif. Une session se compte à la première page vue reçue ; le registre conserve son premier et dernier instant et corrige le créneau si une réception antérieure devient visible.

Le budget est vérifié entre les lots : le temps d'une requête SQL bloquée dépend aussi des timeouts SQL/hébergeur. Les clés de déduplication restent jusqu'à suppression du site pour préserver cette garantie ; elles participent à la croissance du stockage. La purge statistique prend une marge d'un jour UTC pour préserver les calendriers locaux et attend les événements historiques encore en attente. Les données brutes non traitées ne sont jamais purgées. Les suppressions de site retirent au plus un lot par passage puis reprennent à l'appel suivant. Les erreurs affichées ne contiennent ni payload, ni SQL avec valeurs.

Les rapports privés utilisent la session web, le service commun `Reports` et les permissions de site, sans accès implicite de l'admin. Le CSV journalier expose la disponibilité des sessions ; les tables de dimensions et leur export sont limités aux 100 premières valeurs et acceptent un filtre exact. Les rapports de dimensions calculent leurs totaux et séries quotidiennes sur la sélection complète (avant la limite de 100 valeurs affichées), avec le même filtre exact que le CSV. La navigation conserve la période et les sélecteurs de dates conservent le filtre. Le brut est réservé au contrôle de réception, borné à 100 lignes et quinze minutes ; les compteurs de réception couvrent toute la fenêtre et ne sont pas plafonnés au nombre de lignes affichées. Une configuration connue depuis la migration ne reconstitue pas le mode de mesure des années antérieures.

## Fiabilité et laboratoire de performances

```sh
composer test
composer test:mariadb
npm run test:tracker
npm run test:distribution
node scripts/benchmarks/run.mjs 60 /tmp/perysee-benchmark.json
```

Ne jamais exécuter les tests destructifs ni le laboratoire sur la base d'une instance. Leur bootstrap refuse une base autre que `perysee_test`, un utilisateur SQL autre que `perysee_test`, une URL SQL alternative, un cache de configuration ou une base de développement portant le même nom. Ne pas lancer les tests MariaDB en même temps que le benchmark : ils partagent cette base dédiée et les tests la réinitialisent.

`MariaDbReliabilityTest` tue un vrai processus PHP après la sélection, le registre des sessions, les compteurs journaliers et horaires, les dimensions, le marquage et le commit. Un autre test garde une insertion d'ID antérieur non commitée pendant le traitement d'un ID supérieur, puis vérifie sa reprise. Les tests complémentaires couvrent minuit, 25 heures au changement d'heure, rollback, rétention des événements en attente, droits viewer/owner/admin, filtre/CSV et migration depuis le schéma antérieur.

Le banc HTTP local démarre quatre workers du serveur PHP sur 127.0.0.1:18743 et compare trois chemins : le collecteur réel Laravel, Laravel minimal avec SELECT/INSERT, PDO isolé avec SELECT/INSERT. Les deux prototypes omettent les protections du produit et ne sont pas distribués. La charge est cadencée, non un simple envoi séquentiel ; les résultats indiquent latences P50/P95/P99, retard d'émission, statuts, erreurs et pic mémoire PHP. Le cache array neutralise dans ce laboratoire le quota d'une unique IP synthétique : la performance des quotas distribués n'est donc pas démontrée. Le test enregistre aussi une consolidation puis 100 lectures de rapport sur 30 jours. Le stockage est une estimation InnoDB globale, pas un coût physique garanti par événement.

Le workflow manuel `Internal performance laboratory` reproduit ces profils et conserve le JSON en artifact privé. Il ne publie aucun engagement commercial. Les résultats de référence sont dans `scripts/benchmarks/results-2026-10-25.json` ; leurs limites et l'interruption du premier client sont détaillées dans le fichier associé. Un test court permet uniquement de vérifier le banc, pas de conclure sur un percentile.

## Mise à jour, sauvegarde et retour arrière

### Ce que fait la mise à jour assistée

`php artisan perysee:update` (ou le bouton de la page Santé, qui ne fait que demander la mise à jour au planificateur : un cron d'une minute la lance en ligne de commande, sous les limites du CLI) enchaîne :

1. récupération de la release GitHub (`perysee.zip` et `SHA256SUMS`, HTTPS uniquement) ou d'une archive fournie avec `--archive` et `--sha256` ;
2. contrôle du SHA-256, puis extraction dans un dossier de travail (chemins, liens, doublons, état local, fichiers indispensables, `release.json`) ;
3. contrôles préalables : version plus récente, PHP minimal de la release (`platform_check.php`), espace disque, `.env` présent, base joignable, dossiers inscriptibles et même système de fichiers ;
4. sauvegarde dans `storage/app/private/updates/backups/AAAAMMJJ-HHMMSS-vX.Y.Z/` : dump SQL (`database.sql.gz`), `env` (avec `APP_KEY`), `private-files/` ; les trois dernières sauvegardes sont conservées ;
5. mode maintenance (la collecte répond 503 pendant la fenêtre : les événements envoyés pendant ce temps ne sont pas enregistrés), puis remplacement unité par unité des fichiers (`app`, `bootstrap` hors `cache`, `config`, `database/migrations`, `public/*` livrés, `resources`, `routes`, `vendor`, `artisan`, `composer.*`, `release.json`) ; `.env`, `storage/` et `modules/` ne sont jamais touchés ; les anciens fichiers vont dans `…/previous/` ;
6. `php artisan perysee:update:finalize` dans un **nouveau processus** (code de la nouvelle version) : `migrate --force`, `optimize:clear`, sortie de maintenance.

Les migrations des releases doivent rester additives (expand/contract) : le retour arrière automatique ne concerne que les fichiers.

### Échec et retour arrière

- Avant le remplacement des fichiers : rien n'a changé, le site est rouvert.
- Après : les fichiers précédents sont remis. Si aucune migration n'avait été appliquée, le site est rouvert. Sinon il **reste en maintenance** (état `needs_attention` sur la page Santé) tant que la base n'est pas restaurée avec sa sauvegarde.
- Manuel : `php artisan perysee:update:rollback` remet les fichiers de la dernière mise à jour (`--up` rouvre ensuite le site). Restaurer le dump **avant** si des migrations ont été appliquées. Ne pas utiliser `migrate:rollback` : les `down()` servent aux tests et retirent les synthèses. Ne jamais générer une nouvelle `APP_KEY`.

### Sauvegarder la base : méthodes

`--dump=auto` (défaut) prend la première méthode disponible. La page Santé liste celles du serveur.

| Option | Méthode | Quand |
| --- | --- | --- |
| `native` | `mariadb-dump` (ou `mysqldump`), mot de passe via un fichier d'options 0600, `--single-transaction` | Outil présent : le plus rapide et le plus fidèle (routines, déclencheurs) |
| `php` | Dump par la connexion de l'application (tables et lignes, `DROP`/`CREATE`/`INSERT`) | Mutualisé sans client SQL ; sans vues ni routines, que l'application n'utilise pas |
| `external` | Dump fait à la main, indiqué avec `--dump-file=CHEMIN` ; son SHA-256 est enregistré | Terminal ou phpMyAdmin/cPanel « Sauvegarde » |

Faire le dump soi-même en ligne de commande, avant `perysee:update --dump=external --dump-file=...` :

```sh
# Lire DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME dans .env ; le mot de passe est demandé (-p) et n'apparaît pas dans l'historique.
mariadb-dump -h 127.0.0.1 -P 3306 -u perysee -p --single-transaction --quick --routines --triggers --default-character-set=utf8mb4 perysee | gzip > ~/perysee-avant-maj.sql.gz
# mysqldump accepte les mêmes options si mariadb-dump n'existe pas.
```

Restaurer (base vide ou existante, les tables sont recréées) :

```sh
gunzip -c ~/perysee-avant-maj.sql.gz | mariadb -h 127.0.0.1 -P 3306 -u perysee -p perysee
# Dump produit par la méthode php : même commande ; le fichier est du SQL standard.
php artisan up
```

Sans terminal : cPanel > phpMyAdmin > Exporter (méthode personnalisée, SQL, avec DROP TABLE) ou l'outil « Sauvegarde » de cPanel, puis `--dump=external` ; la restauration passe par phpMyAdmin > Importer (limite d'envoi de l'hébergeur : le gz du dump `php` se décompresse d'abord).

### Sans terminal

Le bouton de la page Santé (mot de passe demandé) n'agit que si le cron de l'instance tourne : il dépose une demande, le planificateur la prend dans la minute. Sans cron, aucune mise à jour assistée n'est possible. La mise à jour manuelle reste décrite par `perysee:update --archive perysee.zip --sha256 …` exécutée par le support ou un cron temporaire surveillé. Aucun endpoint public ne lance de migration.

La mise à jour assistée est validée localement (tests PHP sur un arbre temporaire, sans toucher au dépôt) ; elle n'est pas qualifiée sur cPanel/Apache/LiteSpeed réels.

## Batch attribution, carte et démonstrations

Le suivi courant est [docs/SUIVI-FONCTIONNALITES.md](docs/SUIVI-FONCTIONNALITES.md). Le mettre à jour à chaque batch ; ne pas transformer une implémentation locale en qualification d'hébergement.

Après migration additive, les nouveaux événements stockent leur source UTM-first et l'état de leur référent. Les alias IA historiques connus sont regroupés à la lecture, avant top 100 ; aucun ancien Direct n'est réattribué. `source_group=ai` et `provider=chatgpt|claude|perplexity|gemini|copilot|grok` concernent seulement les rapports/CSV Sources. `dimension=country` étend les endpoints existants.

La base `resources/geoip/country.mmdb` est redistribuée avec son manifeste SHA-256 et sa licence DB-IP CC BY 4.0. La page Pays porte l'attribution. Lors d'un renouvellement, télécharger l'édition officielle Country Lite, vérifier son type/lectures IPv4/IPv6, mettre à jour le manifeste et lancer tests/build. La release échoue si le SHA-256 diffère. L'import administrateur depuis Santé accepte une MMDB jusqu'à 20 Mio, en stockage privé, avec validation avant remplacement atomique et conservation de `previous.mmdb`. Configurer aussi les limites PHP upload/post de l'hébergement. Le fichier importé prime sur le fichier livré, y compris après mise à jour.

`PERYSEE_GEOIP_PATH` permet un chemin opérateur explicite. `PERYSEE_TRUSTED_PROXIES` accepte une liste séparée par virgules d'IP/CIDR réellement maîtrisés ; valeur vide par défaut, aucun joker. Les en-têtes Forwarded-For ne sont utilisés que pour ces proxies ; Forwarded-Host n'est pas utilisé. Ne pas stocker d'IP brute dans l'application. Une MMDB absente ou invalide ne bloque pas la collecte et remonte dans Santé.

Démonstrations locales uniquement : `php artisan db:seed --class=LocalDemoSeeder`, puis `php artisan perysee:demo-reset`. La seconde commande remplace les événements/synthèses/historiques des deux UUID connus et marqués `is_demo`, sous verrou MariaDB et transaction ; aucun site réel n'est traité. Elle conserve comptes, clés, droits et réglages de site. Le manifeste attendu est en `analytics_state`, clé `demo_manifest:<id>`. Une interruption annule le reset ; aucun seed n'est lancé à l'installation ou en production.

ECharts est une dépendance du backoffice, chargée par chunk différé ; SVG, palette/typo Perysee, tables accessibles et absence de CDN. Les six logos IA de Lobe Icons sont servis localement, avec leur licence MIT ; le logo ChatGPT utilise le symbole OpenAI. La carte Natural Earth 110m est dans le domaine public et sa provenance est conservée. Les territoires absents de ce fond restent consultables dans le tableau.

Mesures du surcoût géo : `scripts/benchmarks/geo-2026-10-25.md`. Le tracker reste indépendant du chunk graphique. `npm run test:charts` rejoint les vérifications CI existantes. Les fichiers Markdown internes et les données privées de démonstration ne sont jamais embarqués dans la release.


## Interface Nuxt UI et thème partagé

Depuis la refonte du 25/10/2026, les écrans applicatifs utilisent Laravel + Inertia + Vue 3 + Nuxt UI 4.12, sans SSR (`config/inertia.php`). Laravel conserve les sessions, la validation, les permissions, le reporting et les mutations ; les pages Vue consomment les props filtrées du middleware Inertia. Le tracker reste un bundle vanilla séparé. Le site de test local Lueur conserve sa page Blade isolée et sa feuille legacy ; il ne charge pas le routeur de l’administration.

La source du thème reste `../perysee-web/layers/brand/theme.json`. Depuis le workspace complet, `npm run brand:sync` exporte les palettes, les variantes et la version avec SHA-256 dans `resources/`. `npm run brand:check` contrôle les trois exports. Le dépôt autonome utilise ces exports versionnés ; sa compilation et la CI ne dépendent pas du dossier `perysee-web/`. Les variantes Nuxt UI, Geist, l’accent violet et les neutres du thème, les angles droits et Tabler suivent cette source.

Les composants sont dans `resources/js/Components/`, la coque persistante dans `Components/WorkspaceLayout.vue` et le contenu dans `Pages/Workspace.vue`. Les écrans sont chargés séparément ; ECharts reste différé, en SVG, avec destruction et observation du redimensionnement au cycle de vie Vue. Les tableaux exacts restent accessibles et les sessions indisponibles restent des interruptions dans la série. Le calendrier utilise UPopover/UCalendar/UInputDate : deux mois sur desktop, un sur mobile, boutons appliquer/annuler, dates calendaires du fuseau du site, conservation des filtres et remise à zéro de la pagination. Le serveur refuse les dates futures, inversées et les plages dépassant 1 095 jours d’écart.

`npm run build` compile l’ensemble dans `public/build/` ; le ZIP embarque ces fichiers et le paquet PHP Inertia dans vendor. L’hébergement exécute PHP et le cron habituel, sans npm, Node ou processus SSR. JavaScript est requis pour l’administration ; la collecte indépendante garde son contrat existant. Les anciennes vues Blade non routées restent provisoirement en place, sans constituer un deuxième rendu applicatif.

Validation : tests Inertia de navigation, droits, absence de secrets, erreurs de formulaires et dates selon le site ; suites PHP/MariaDB et Tracker ; contrôle navigateur du calendrier au clavier, des filtres IA, de la carte, des formulaires et de la navigation mobile. Le calendrier et les tableaux n’utilisent aucun service externe. Les poids et résultats finaux sont consignés dans `docs/SUIVI-FONCTIONNALITES.md`. La compatibilité sur un mutualisé réel reste à qualifier.

Pour contrôler un installateur ZIP dans le même navigateur que l’instance de développement, utiliser un autre nom d’hôte (par exemple `localhost` si l’application utilise `127.0.0.1`) ou définir un `SESSION_COOKIE` de test distinct avant son premier démarrage. Les cookies ne sont pas isolés par port : deux instances Perysee sur le même hôte peuvent se remplacer leur cookie de session. Le contrôle de production doit conserver les exigences HTTPS et document root ; ne pas les contourner pour terminer un smoke HTTP local.


## Carte et dimensions client (25/10/2026)

La carte ECharts propose déplacement, zoom 1–8×, boutons, clavier (flèches, +/−, Home), agrandissement et activation volontaire du zoom à la molette. Le cadrage reste conservé après sélection/effacement d’un pays, y compris depuis le tableau. La carte garde le contexte de tous les pays de la période ; les totaux, la série et le CSV suivent le filtre exact. Les noms lisibles sont affichés dans les tableaux ; le CSV conserve les codes pays.

La migration additive `add_client_dimensions_to_events` ajoute `device_brand` et `browser_name`, nullables. `Device::details()` dérive uniquement des libellés grossiers issus d’une liste fermée à partir du header reçu, limité à 4 096 caractères. Le header, les versions et les modèles ne sont pas persistés. Les robots reconnus restent inconnus. Apple exige une indication iPhone/iPad/iPod/Macintosh ; un PC Windows ne devient pas Microsoft et un Android générique reste de marque inconnue. SamsungBrowser, Edge et Opera sont testés avant Chrome, Chrome iOS avant Safari. « Chrome family » exprime l’ambiguïté des navigateurs compatibles. Cette attribution déclarative n’est pas une preuve de l’appareil ; voir les limites des [User-Agent](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/Browser_detection_using_the_user_agent) et leur [réduction](https://developer.mozilla.org/en-US/docs/Web/HTTP/Guides/User-agent_reduction). Aucun hint à forte entropie ou modèle matériel n’est demandé.

Les anciennes lignes ont NULL : elles ne sont pas reclassées à partir d’un User-Agent disparu. Les nouvelles valeurs `unknown` signifient mesuré mais non identifié. L’agrégateur traite `browser` et `device_brand` indépendamment de la présence d’UTM/référent, y compris avec un ancien tracker. Les endpoints de dimensions et exports acceptent ces deux types. La page Devices propose trois vues (catégorie, marque, navigateur), avec leur couverture réelle, conservation de l’onglet dans les plages de dates et CSV filtré. Les dimensions ne sont pas des filtres croisés : les agrégats ne prouvent pas la répartition d’une marque dans un type précis.

Le manifeste des démos est en version 3 et inclut marques et navigateurs, issus des mêmes classifieurs et de l’agrégateur. Seuls les deux sites locaux marqués et connus sont réinitialisés. Les pages Campagnes, Événements, Pages et Pays ont des dispositions et des intitulés adaptés à leur usage. La sidebar offre un accès au dashboard, aux sites autorisés et à leur contexte ; les titres de groupes ne sont plus en majuscules.


## Lisibilité et navigation persistante (26/10/2026)

`Workspace` déclare le layout persistant Inertia : sidebar, en-tête, menu de compte et fournisseur Nuxt UI gardent leur instance entre les visites internes. Le contenu est remplacé et les liens actifs suivent les nouvelles props ; les formulaires conservent leur cycle de validation habituel. Le logo utilise également un lien Inertia. Une actualisation volontaire, une nouvelle version des assets ou une redirection externe peut toujours charger un nouveau document.

Les répartitions conservent les grandes barres derrière les libellés, avec un fond violet renforcé et un bord net. Leur longueur est relative à la valeur dominante du tableau ; les pourcentages restent calculés sur le total réel de la sélection. Les logos IA disposent d’un support clair local pour rester lisibles en mode sombre. Les séries conservent valeurs exactes, zéros et interruptions de disponibilité ; leurs axes et traits sont plus lisibles. Aucun compteur ne change pour améliorer le rendu.

Le zoom programmatique ECharts doit fournir `originX` et `originY` en pixels, au centre du graphique. Sans cette origine, le modèle pouvait annoncer un zoom tout en calculant des coordonnées invalides. `map-navigation.test.mjs` utilise le vrai moteur SVG ECharts et le fond mondial : coordonnées finies après zoom, position d’un pays déplacée, retour après zoom inverse et bornes 1–8×. Le pourcentage affiché suit désormais l’événement du moteur.

## Crossed filters and session flows

Joint statistics live in `stats_segment_daily` and `stats_segment_hourly`. The `segment_events` ledger makes ingestion and retained-history reconstruction idempotent inside their transaction. `segment_sessions` records the first measured page context and moves counters if an earlier event is encountered. Reconstruction and pruning share the aggregation advisory lock on MariaDB. Run `perysee:rebuild-segments --batch=500` repeatedly or leave it to the scheduler. Missing historical context remains explicit.

Funnels store immutable versions. Session-flow requests snapshot the highest event ID, scan retained raw session events in bounded batches, then reduce session sequences. `perysee:journey-reports --batch=500 --budget=10` runs each minute. Intermediate session keys remain private and are deleted when the report completes; expired requests and interrupted work are removed by bounded maintenance after one day. A session above 10,000 events is excluded with a coverage warning. Journey trees above 25,000 distinct paths fail explicitly and ask for a narrower period. The displayed graph groups branches after five choices per branch, eight action levels, or its readability budget; volumes remain conserved.

The conversion denominator excludes pending outcomes. Missing history suppresses conversion percentages. Filters match entry context, never the intermediate event stream. Reports use server reception order (`received_at`, then `id`), not client-side timestamps or inferred visitor identity. No change to Lueur's consent or identity contract is needed.

Validation uses `SegmentReportingTest`, `FunnelReportingTest`, `FunnelEngineTest`, the chart/URL tests and the existing ingestion/concurrency suites. Real shared-hosting qualification remains outstanding.

Demo traffic v5 covers 90 days, ends with a few visits in the last fifteen minutes for the reception view, and adds UTM campaigns (newsletter, LinkedIn, X, Product Hunt, Google Ads) and eight named events. It uses a local PHP Randomizer, never the global random state. Resets use a fresh seed by default; `--seed=N` reproduces a dataset at the same frozen time. Cohort context stays consistent within each session. Weekday/hour weights, growth, campaign spikes, varied path lengths and nonuniform conversion probabilities make the sample less regular. Three tracked sample definitions are created once and preserved on future resets; real sites and other report jobs are not processed by the demo reset. Sample funnel and journey reports are precomputed for today, 7 and 30 days; other selections use the normal scheduler. Live visits age out after fifteen minutes: rerun the reset just before a demonstration.

Demo report cache keys remain stable while snapshot data, definition, selection and dates are unchanged, so precomputed examples remain immediately available. The update timestamp identifies this synthetic snapshot. Real sites use five-minute cache windows.
