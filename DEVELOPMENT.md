# Développement

Guide de développement local, de test et d’exploitation de Perysee Core. Les procédures de release et de distribution sont maintenues par l’équipe dans un dépôt privé.

## Branches et releases

Le dépôt suit un gitflow : `develop` reçoit le travail (`feat/`, `fix/`, `chore/`, `docs/`), `main` ne porte que les releases publiées. Une release part de `develop` (workflow `Prepare release`, PR `release/vX.Y.Z` vers `main`, fusionnée en merge commit), un hotfix part de `main`, et `main` est ensuite refusionné dans `develop`. Les releases et hotfix se fusionnent en merge commit, jamais en squash.

## Publier un module gratuit dans le catalogue

Le catalogue public est celui de l'API (`GET /api/v1/catalog/modules`, dépôt `Perysee/web`). Les modules officiels vivent dans le dépôt privé `Perysee/perysee-modules` (dossier `perysee-modules/` de l'espace de travail), gratuits comme payants. L'API distribue leurs archives : elle lit la release privée avec un jeton en lecture seule et renvoie au Core une adresse GitHub temporaire, sans rien stocker. Chaque entrée porte une signature Ed25519 faite **hors serveur** ; l'adresse signée est celle de l'API (`/api/v1/modules/<id>/download`), donc stable. Un module payant exige un jeton dont la licence le couvre.

1. **Clés (une seule fois).** `node scripts/sign-module.mjs keygen --out ~/.perysee/module-signing.key` dans `apps/api` crée la clé privée (jamais dans un dépôt, ni sur un serveur) et affiche la clé publique. Renseigner celle-ci dans `MODULE_SIGNING_PUBLIC_KEYS` de l'API (`{"k1":"<clé>"}`) et dans `resources/module-keys.json` du Core (`{"keys":{"k1":"<clé>"}}`). Pour une rotation, ajouter la nouvelle clé aux deux listes avant de signer avec elle ; retirer l'ancienne une fois les instances mises à jour.
2. **Archives.** Dans `perysee-modules`, monter la `version` de `modules/<id>/module.json`, puis pousser le tag `<id>-v<version>` : le workflow `Release module` vérifie le tag, fabrique `<id>-<version>.zip` et `MODULES-SHA256SUMS` (`scripts/build-modules.php`) et crée la release privée.
3. **Signature.** Pour chaque module : `node scripts/sign-module.mjs sign --key <clé> --key-id k1 --slug <id> --version <x.y.z> --archive <zip de la release> --url https://api.perysee.com/api/v1/modules/<id>/download --core <Perysee minimal> --stability <niveau>`. La sortie JSON se publie avec `PATCH /api/v1/modules/:id` (permission `modules.manage`) ; l'API refuse tout ensemble qui ne vérifie pas.
4. **Contrôle.** Sur une instance de test : `PERYSEE_MODULE_CATALOG_URL=<api> php artisan perysee:modules catalog`, puis `install-from-catalog <id>`.

Les assets d'une release publiée ne se remplacent jamais : l'empreinte est dans l'entrée signée. Corriger un module = nouvelle version, nouvelle release, nouvelle signature.

## Interface : navigation Inertia et Nuxt UI

- **Liens internes = chemins.** Nuxt UI rend en simple `<a>` (donc recharge tout le document) tout lien qui porte un protocole. Les adresses envoyées à l'interface sont donc des chemins : `Ui::relativize` s'en charge pour les données de page et les propriétés partagées, et `queryUrl` garde un chemin comme chemin. `InternalLinksTest` échoue si un écran envoie un lien interne absolu. Un lien vers un autre site (`docsUrl`) reste absolu et doit porter `external: true`.
- **Propriétés partagées réservées.** `Ui::render` refuse un écran qui envoie `links`, `auth`, `branding`, `modules`, `assets`, `navigation`, `flash`, `currentUrl`, `docsUrl` ou `errors` : la propriété de page remplacerait la propriété partagée pour toute l'interface.
- **Événements Inertia v3.** Seuls `httpException` et `networkError` existent pour les erreurs (`invalid` et `exception` n'existent plus). Le layout les écoute et affiche un toast ; en développement, la modale d'Inertia reste affichée pour voir la page d'exception.
- **Coque.** `WorkspaceLayout.vue` (layout persistant d'Inertia) assemble `UDashboardGroup`, `UDashboardSidebar` (ses slots servent aussi au menu mobile, fermé à chaque navigation) et `UDashboardPanel`. Le slot par défaut du panneau **remplace** ses slots `header`, `body` et `footer` : la barre du haut est placée dans ce slot. Le `main` qui défile porte `scroll-region`, sinon Inertia ne remet pas le défilement à zéro ni ne le restaure. Ne pas ajouter `resizable` (racine multiple).
- **Rechargements pendant le développement.** Chaque `npm run build` change la version des assets : la visite suivante est un rechargement complet (réponse 409). C'est normal. `npm run dev` l'évite.

## Modules

Les fonctionnalités optionnelles sont des modules (guide : documentation, `Modules > Build a module`).

- **Sources** : les modules officiels ne sont pas dans ce dépôt : ils vivent dans `modules/<id>/` du dépôt `perysee-modules`. Le Core ne les versionne donc pas et son ZIP ne les contient pas : `scripts/build-release.php` n'embarque que sa liste explicite de chemins, et `storage/` n'en fait pas partie. `resources/module-template/` (gabarit de `perysee:make-module`) est en revanche distribué.
- **En local** : relier un module au dossier chargé par l'application, par exemple `ln -s <chemin de perysee-modules>/modules/annotations storage/app/modules/annotations`, puis `php artisan perysee:modules enable annotations`. L'activation écrit en base ; la première installe aussi les migrations du module.
- **Nouveau module** : `php artisan perysee:make-module <id>` crée le squelette dans le dossier des modules ; le déplacer dans `modules/` du dépôt `perysee-modules` pour le versionner. Préfixer les tables et les noms de migration par l'identifiant (les noms de migration sont communs à tous les modules).
- **Tests** : `php artisan test --compact --filter=Module` (registre, chargeur, installation, périmètre, extensions, pages d'administration, robustesse), `AnnotationsModuleTest`, `WhiteLabelModuleTest`, `ExampleModuleTest`, `MakeModuleTest`, et `npm run test:charts` (hôte JavaScript, rendu des modules). Un module qui s'appuie sur un autre sujet de test charge `base_path('modules')` via `perysee.modules_path`.
- **Avant de livrer un module** : activation sans erreur de chargement, refus des rôles insuffisants, absence hors du périmètre choisi, activation répétée sans seconde migration, désinstallation limitée à ses tables. Le guide public donne la même liste.
- **Événements de domaine et registres (ADR 005)** : `App\Modules\Events\DomainEvents` (bus, `listening()` avant tout calcul), le catalogue `App\Modules\Events\*`, les registres `NotificationChannels` (canal Core `core.mail`) et `ReportSources` (source Core `core.traffic`). Les modules s'abonnent avec `ModuleProvider::listen`, déclarent avec `notificationChannel` / `reportSource` et indiquent `core` ≥ `ModuleApi::DOMAIN_EVENTS_SINCE` dans `module.json` ; `ModuleApi::VERSION` reste 1. Publication : après le commit et le déverrouillage pour l'agrégateur, en `terminating` pour les actions d'administration, jamais dans la collecte (`DomainEventCriticalPathTest`). Un événement ne perd ni ne change un champ dans une version de schéma : `DomainEventCatalogTest` le garde, la suppression exige un nouveau schéma. Tests : `php artisan test --filter=DomainEvent` (+ `NotificationChannelsTest`, `ReportSourcesTest`) ; le coût au repos avec écouteur est borné par `DomainEventsCostTest` (le budget d'`IdleSchedulerCostTest` vaut sans écouteur).

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

Le premier démarrage SQL peut prendre quelques secondes : relancer `health` avant de continuer. Ouvrir [localhost:8000/install](http://localhost:8000/install), récupérer le secret dans `storage/app/private/installation/secret` (ou lancer `php artisan perysee:install --browser --url=http://localhost:8000`, qui affiche un lien `/install#secret=…` déjà déverrouillé), puis suivre les étapes. Sans navigateur, `php artisan perysee:install` fait tout dans le terminal (voir [Installateur](#installateur)). Le fichier apparaît à la première ouverture de l’assistant. Utiliser les paramètres MariaDB locaux ci-dessous et le mot de passe de `infra/mariadb/.env`. L’assistant applique les migrations : aucune migration préalable n’est nécessaire.

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

La suite rapide utilise SQLite en mémoire. Pour valider les migrations et le parcours complet avec MariaDB 11.4 (la CI exécute aussi 10.11 et 11.8, voir `App\Installation\DatabaseVersion`) :

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

Le budget est vérifié entre les lots : le temps d'une requête SQL bloquée dépend aussi des timeouts SQL/hébergeur. Les clés de déduplication restent jusqu'à suppression du site pour préserver cette garantie ; elles participent à la croissance du stockage. Les registres de sessions (`measured_sessions`, `segment_sessions`) sont conservés tant que les statistiques le sont, puis purgés par `perysee:prune-sessions` (ADR 010) : une session plus ancienne que la rétention des statistiques plus un jour n'est plus recomptable, et rien n'est supprimé tant qu'un événement non traité plus ancien existe. La purge statistique prend une marge d'un jour UTC pour préserver les calendriers locaux et attend les événements historiques encore en attente. Les données brutes non traitées ne sont jamais purgées. Les suppressions de site retirent au plus un lot par passage puis reprennent à l'appel suivant. Les erreurs affichées ne contiennent ni payload, ni SQL avec valeurs.

Les rapports privés utilisent la session web, le service commun `Reports` et les permissions de site, sans accès implicite de l'admin. Le CSV journalier expose la disponibilité des sessions ; les tables de dimensions et leur export sont limités aux 100 premières valeurs et acceptent un filtre exact. Les rapports de dimensions calculent leurs totaux et séries quotidiennes sur la sélection complète (avant la limite de 100 valeurs affichées), avec le même filtre exact que le CSV. La navigation conserve la période et les sélecteurs de dates conservent le filtre. Le brut est réservé au contrôle de réception, borné à 100 lignes et quinze minutes ; les compteurs de réception couvrent toute la fenêtre et ne sont pas plafonnés au nombre de lignes affichées. Une configuration connue depuis la migration ne reconstitue pas le mode de mesure des années antérieures.

## Équipe, partage public, objectifs et comparaison

Quatre fonctions s'appuient sur des migrations additives (`2026_11_07_*`) : `site_invitations`, `share_links`, `goals`, `goal_versions`, `goal_sessions` et `stats_goal_daily`. Aucune ne modifie `users` ni `site_members`. Les décisions de conception sont dans `docs/adr/` ([001](docs/adr/001-public-share-links-separate-shell.md), [002](docs/adr/002-invitation-tokens-and-the-no-mail-path.md)).

### Planificateur

Le cron appelle `php artisan schedule:run` chaque minute. `routes/console.php` déclare :

| Commande | Fréquence | Rôle |
| --- | --- | --- |
| `perysee:aggregate --batch=500 --budget-seconds=15` | 5 min | Consolidation des événements, segments et objectifs en direct. |
| `perysee:maintenance --limit=1000` | 5 min | Battement du planificateur, suppression des sites, rétention des statistiques, rapports de parcours périmés, invitations périmées, puis `perysee:prune-tracking`. |
| `perysee:rebuild-segments --batch=500` | 5 min | Reconstruction des segments sur l'historique conservé. |
| `perysee:rebuild-goals --batch=500` | 1 min, sans chevauchement | Compte les événements passés pour un objectif défini après coup. Sans travail en attente, le coût est une lecture. |
| `perysee:journey-reports --batch=500 --budget=10` | 1 min, sans chevauchement | Rapports de parcours asynchrones. |
| `perysee:update --requested --yes` | 1 min, sans chevauchement | Prend la demande de mise à jour déposée depuis la page Santé. |
| `perysee:update:check --quiet-unless-new` | 04:17 | Cherche une nouvelle version. |
| `perysee:modules catalog --refresh-only` | 04:43 | Rafraîchit le catalogue de modules. |
| `perysee:prune-api-tokens` | toutes les heures, sans chevauchement | Supprime les jetons d'API expirés ou révoqués depuis plus de 30 jours. |
| `perysee:prune-access-log --limit=1000` | toutes les heures, sans chevauchement | Supprime les entrées du journal d'accès de plus de 90 jours. |
| `perysee:prune-diagnostics` | toutes les heures, sans chevauchement | Supprime les diagnostics de collecte de plus de 7 jours. |
| `perysee:prune-event-values --limit=1000` | toutes les heures, sans chevauchement | Supprime les identifiants de commande hors fenêtre et les propriétés d'événements hors rétention. |
| `perysee:storage-snapshot` | toutes les heures, sans chevauchement | Enregistre une fois par jour la taille de chaque table (`table_size_snapshots`, estimation du moteur, sans `COUNT(*)`) et supprime les instantanés de plus de 400 jours. Une fois l'instantané du jour pris, le coût est une lecture. `perysee:storage-report [--json]` affiche les tailles en lecture seule. Avec un quota saisi sur la page Santé (`storage_quota_bytes`), la droite des moindres carrés sur 30 jours (7 jours minimum) donne la date d'atteinte, et l'alerte `storage` s'affiche sous 30 jours. |
| `perysee:prune-sessions --limit=1000` | toutes les heures, sans chevauchement | Supprime `measured_sessions` et `segment_sessions` au-delà de la rétention des statistiques plus un jour UTC, sauf tant qu'un événement non traité plus ancien existe (ADR 010). Verrou d'agrégation, 20 lots au plus par passage. À vide : deux lectures. |
| `perysee:prune-share-links --limit=1000` | toutes les heures, sans chevauchement | Supprime les liens de partage expirés ou révoqués depuis plus de 30 jours. À vide : une lecture. |
| `perysee:prune-cache --limit=1000` | toutes les heures, sans chevauchement | Supprime les fichiers expirés du magasin de cache `file` (`storage/framework/cache/data`, celui que l'installateur configure) : les compteurs de limitation de débit créent un fichier par clé et un hébergement mutualisé compte les inodes. Lecture du seul préfixe d'expiration, 50 000 fichiers examinés au plus par passage, départ tournant selon l'heure. Aucune requête SQL. |
| `perysee:storage-budget` | toutes les heures, sans chevauchement | Compare la taille de la base au budget de stockage (ADR 011) et fait monter ou descendre le palier de rétention. Sans budget : une lecture. Avec un budget : une mesure (statistiques du moteur) et l'enregistrement du dernier chiffre. Ne supprime rien : les purges lisent la rétention effective. |

### Budget de stockage et rétention adaptative (ADR 011)

Page Santé, carte « Storage budget » : un budget en Mio (`storage_budget_bytes`) et un mode `storage_mode` (`off` par défaut, `ask`, `auto`), tous deux dans `analytics_state`. Les réglages de rétention de l'administrateur ne sont jamais réécrits : un palier calculé (`storage_tier`) les plafonne.

| Palier | Effet (plafond, jamais une augmentation) |
| --- | --- |
| 0 | Normal. |
| 1 | Événements bruts 30 jours au plus. |
| 2 | Événements bruts 7 jours au plus. |
| 3 | Agrégats horaires (`stats_hourly`, `stats_segment_hourly`) 90 jours au plus. |
| 4 | Agrégats horaires 30 jours au plus. |

- Un palier de plus à 100 % du budget, au plus un par 24 h ; tous levés sous 80 % (entre les deux, le palier tient). Les agrégats journaliers et les registres de sessions ne sont jamais touchés.
- `off` : l'alerte Santé seulement. `ask` : l'alerte porte un bouton d'accord (`PUT /health/storage/agreement`, palier validé) pour chaque palier ; l'accord est oublié sous 80 %. `auto` : choisi explicitement, journalisé dans le journal d'accès (`storage.settings`, `storage.agreed`).
- Point d'accès unique aux purges : `InstanceHealth::effectiveRetention()` (une lecture). `perysee:prune-tracking`, `perysee:maintenance`, `perysee:prune-sessions` et `perysee:purge` l'utilisent ; `retentionDays()` reste le réglage de l'administrateur.
- Chaque changement de palier écrit une ligne `storage_tier` dans le journal de continuité (`breaks` à la montée, `note` à la levée).
- Événement de domaine `ingest.storage_budget_exceeded` (`tier`, `ratio_percent`, `days_left`), à partir de la version 0.5.0 : un module qui l'écoute le déclare dans `core` de `module.json` (`ModuleProvider::listen()` le vérifie). Publié par `perysee:storage-budget` à chaque montée de palier, une seule fois par palier et par incident, jamais par la collecte.
- Les purges restent des lots bornés : après une montée de palier, la suppression suit le rythme des passes (1 000 lignes par table et par passe de cinq minutes). Pour aller plus vite : `perysee:purge`.

### Récupérer l'espace (`perysee:storage-optimize`)

Supprimer des lignes ne rend pas l'espace au disque : il reste libre dans la table. `php artisan perysee:storage-optimize <table> [--dry-run] [--force]` reconstruit une table (`OPTIMIZE TABLE`) pour le rendre. Jamais planifiée, une table à la fois.

- **Simulation par défaut** : taille de la table, espace récupérable estimé (`data_free`), `innodb_file_per_table` (une requête `SHOW VARIABLES`), espace libre nécessaire (environ la taille de la table, plus la croissance d'un jour d'après la projection) et espace libre du quota saisi. `--force` lance la reconstruction.
- **Liste blanche** : le nom doit être un identifiant simple ET une table que la base elle-même liste (`StorageMeter`) ; il est quoté, jamais injecté tel quel.
- **Gardes** : refus, même avec `--force`, si le quota saisi laisse moins que l'espace nécessaire, ou si `innodb_file_per_table` est `OFF` (l'espace partagé ne rétrécit jamais). Sans quota saisi, la simulation dit pourquoi l'espace libre est inconnu ; `--force` passe outre, aux risques de l'opérateur.
- Sur hébergement mutualisé, le quota peut être dépassé pendant la reconstruction. Une attente de verrou de plus de 15 s abandonne l'opération (les requêtes s'empilent derrière un `OPTIMIZE` en attente). Une sauvegarde est conseillée.
- SQLite : message « Not applicable », code de sortie 0.

### Purge manuelle (`perysee:purge`)

Outil de l'opérateur pour purger plus tôt que la rétention quand la base est trop grosse. `php artisan perysee:purge <cible> --older-than=<durée>` avec la cible `events`, `stats`, `segments`, `sessions` ou `share-links`, et une durée `90d`, `12w`, `6m` ou `2y` (un mois = 30 jours, une année = 365 ; minimum 1 jour).

- **Simulation par défaut** : décompte exact par table, sans aucun DELETE. `--dry-run` l'impose même avec `--force`.
- **Exécution** : `--force`, puis confirmation interactive ; `--yes` la remplace dans un script, et sans terminal ni `--yes` rien n'est supprimé. `--yes` seul est refusé. `--limit` (1 à 10 000, 1 000 par défaut) borne chaque lot ; au plus 50 lots par table et par appel, la commande dit s'il faut la relancer.
- **Mêmes règles que les purges horaires** (`App\Analytics\Maintenance\RetentionPurge`) : jamais d'événement non traité (`processed_at` nul), statistiques, compteurs de segments et registres de sessions conservés tant qu'un événement non traité plus ancien existe, verrou d'agrégation pris pendant la suppression.
- **Journal de continuité** : une purge plus courte que la rétention en vigueur écrit une ligne `retention_purge` (instance, gravité `breaks`, `data_from` = date de coupe) ; une purge qui ne fait qu'avancer l'échéance normale n'écrit rien.
- La sortie rappelle qu'une sauvegarde est conseillée et ce qui est perdu (rapports, comparaisons, filtres croisés, recalcul d'objectifs et de segments).

### Équipe et invitations

- Écran `/sites/{website}/team` (`SiteMemberController`) : tout membre voit la liste ; seul un `owner` voit les invitations et les commandes (inviter, changer un rôle, retirer, renvoyer, révoquer). Un administrateur d'instance sans rôle sur le site n'a aucun accès implicite. Chacun peut quitter un site (`DELETE /sites/{website}/team/me`).
- Un site garde toujours un propriétaire : changements de rôle, retraits et départs passent par `App\Support\SiteTeam`, sous verrou sur la ligne du site, et refusent de retirer le dernier `owner`. `TeamMariaDbTest` rejoue deux propriétaires qui se rétrogradent en même temps avec de vrais processus ; il est ignoré hors MariaDB.
- Invitation : adresse e-mail (minuscules) et rôle, valable 7 jours, une seule invitation en attente par site et adresse. Le jeton (`random_bytes(32)` en hexadécimal) n'est jamais stocké : seule son empreinte SHA-256 l'est (`token_hash`). « Renvoyer » change le jeton ; l'ancien lien cesse de fonctionner.
- **Le courrier est facultatif.** Tant que ni l'écran **Administration > Mail** ni `MAIL_MAILER` (autre que `log` ou `array`, le défaut) ne fournissent de serveur, ou si l'envoi échoue, l'invitation est conservée et le lien `/invitations/{token}` est affiché une seule fois au propriétaire, avec un bouton de copie. Avec un serveur configuré (voir [Messagerie de l'instance](#messagerie-de-linstance-smtp)), un `Mailable` est envoyé de façon synchrone par `App\Mail\InstanceMailer` (pas de file). Un compte créé par invitation n'a son adresse marquée vérifiée que si un message est réellement parti.
- `GET /invitations/{token}` ne consomme jamais le jeton (les scanners de courrier ouvrent les liens) ; le `POST` le consomme, dans une transaction avec `lockForUpdate`. Jeton inconnu, expiré, révoqué ou utilisé : même 404. Les réponses portent `Referrer-Policy: no-referrer` (`BootstrapInstallation`).
- Compte inexistant : nom et mot de passe (12 caractères au moins), le compte n'est jamais administrateur. Compte existant : connexion obligatoire avec l'adresse invitée, sinon message générique qui ne révèle pas l'adresse. Rien n'est fusionné entre adresses.
- Limites : 20 invitations par heure et par utilisateur, un renvoi par minute et par invitation, 10 requêtes par minute et par adresse IP sur les liens (`ThrottleInvitationLinks`). Cinq tentatives d'acceptation depuis un compte connecté qui n'est pas l'invité ferment l'invitation (une heure).
- Rétention : `perysee:maintenance` supprime les invitations expirées, acceptées ou révoquées depuis plus de 30 jours.

### Liens de partage publics

- Écran `/sites/{website}/sharing` (propriétaire seulement, jamais sur un site de démonstration). Un lien porte les sections choisies (`overview`, `pages`, `sources`, `campaigns`, `devices`, `countries`, `events`), une date d'expiration facultative et un mot de passe facultatif (8 caractères au moins).
- Routes publiques dans `routes/share.php` : `GET /s/{token}`, `POST /s/{token}/unlock`, `GET /s/{token}/api/{overview|timeseries|dimensions}`. Elles passent par `ResolveShareLink`, pas par la liaison de modèle. Lien inconnu, révoqué, expiré, site supprimé ou de démonstration : une même page 404 statique (JSON pour l'API). Les échecs sont limités à 60 par minute et par adresse IP.
- Le jeton (32 octets aléatoires, base64url) n'est jamais stocké : `share_links.token_hash` (SHA-256) et un indice de six caractères pour la liste. Le lien complet n'est affiché qu'une fois, à la création. Le jeton est dans l'URL (historique du navigateur, journaux d'un proxy) : mot de passe, expiration et révocation sont les réponses prévues.
- Mot de passe : 5 essais par lien en 15 minutes, 20 par adresse IP en une heure. Le modifier ou le retirer incrémente `secret_version` et déconnecte les visiteurs qui l'avaient saisi.
- Coque séparée (`Pages/Share/Show.vue`, `Unlock.vue`) : pour les routes `share.*`, `HandleInertiaRequests` ne partage que `currentUrl`, `assets`, `flash` et `errors` ; aucun compte, aucune navigation, aucun module. Réponses en `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`, `X-Robots-Tag: noindex, nofollow`. Aucun filtre, export ou identifiant de site n'est lu depuis la requête.
- L'usage est enregistré au plus une fois par heure et par lien (`last_used_at`, `use_count`). Les liens expirés ou révoqués depuis plus de 30 jours sont supprimés par `perysee:prune-share-links` (un lien révoqué ou expiré répondait déjà par la même page 404) ; les autres lignes disparaissent avec le site (clé étrangère en cascade).

### API de lecture (`/api/v1`)

- Écran `/sites/{website}/api-tokens` (propriétaire seulement, jamais sur un site de démonstration, aucun accès implicite de l'administrateur d'instance). Un jeton appartient à un site, porte des portées (`overview`, `pages`, `sources`, `campaigns`, `devices`, `countries`, `events`, `goals`, `funnels`), une limite de 10 à 300 requêtes par minute (60 par défaut, plafond `PERYSEE_API_MAX_RATE`) et une expiration facultative (plafond facultatif `PERYSEE_API_MAX_EXPIRY_DAYS`). Un jeton survit au départ de son créateur.
- Le secret (`psy_` + 32 octets en base64url) n'est jamais stocké : `api_tokens.token_hash` (SHA-256) et les six derniers caractères comme repère. Il n'est affiché qu'une fois, à la création. Il ne circule que dans l'en-tête `Authorization: Bearer` ; dans l'URL ou le corps, il est ignoré.
- Routes dans `routes/api_v1.php`, hors groupe `web` (ni session, ni cookie, ni CSRF) : `GET /site`, `/overview`, `/timeseries`, `/dimensions/{dimension}`, `/goals`, `/goals/{id}`, `/funnels`, `/funnels/{id}`, et `/openapi.json` (public, `resources/openapi/v1.json`, un test vérifie qu'il décrit exactement les routes). Le site vient du jeton (`ResolveApiToken`), jamais d'une liaison de modèle. Un jeton inconnu, absent, expiré, révoqué ou d'un site supprimé ou de démonstration reçoit un même 401. Erreurs : `{"error": {"code", "message", "details"}}`.
- Les chiffres viennent de `Reports`, `GoalReports` et `JourneyReports` ; la mise en forme passe par `ReportPresenter` (liste blanche partagée avec les liens de partage, dont le JSON est figé par `ShareJsonCharacterizationTest`) et `ReportSections` (portées vers dimensions). Un filtre exige la portée de sa dimension. Tableaux limités à 100 lignes (`meta.truncated`, `distinct_values`). `compare=previous` renvoie le verdict du serveur sur les pourcentages.
- `continuity: [{at, kind, scope, severity}]` s'ajoute aux réponses `overview` et `timeseries` (ajout de champ, ADR 006 ; aucune nouvelle portée) : les lignes du journal de continuité (ADR 009) de la période, jamais le `payload`, une lecture de plus par requête. `at` est le jour local du site. Le CSV d'export ajoute la ligne `# not comparable before <date>` (date de la dernière rupture `breaks` de la période qui touche pages vues, sessions ou événements), après la ligne `# demonstration data` ; les liens de partage n'ont pas le journal.
- Funnels en lecture seule : le dernier résultat déjà calculé pour la version, la période et les filtres demandés, sinon 404 `report_not_computed`. Aucun calcul n'est déclenché.
- Aucun CORS : `ApiV1Boundary` (premier middleware global) refuse un `OPTIONS` et retire les en-têtes `Access-Control-*` sur `/api/v1/*`. `/api/collect` garde le comportement de toujours. Chaque réponse porte `Cache-Control: no-store, private`, `Referrer-Policy: no-referrer`, `X-Robots-Tag`, `X-Perysee-Api-Version: 1`.
- Limite par jeton avec `RateLimiter` (en-têtes `X-RateLimit-*`, `Retry-After` sur 429) ; les échecs d'authentification sont limités à 60 par minute et par adresse IP hachée.
- Usage : `last_used_at` et `use_count` au plus toutes les 10 minutes par jeton (une requête `UPDATE`). Rétention : `perysee:prune-api-tokens` (toutes les heures, sans chevauchement) supprime les jetons expirés ou révoqués depuis plus de 30 jours. Commande dédiée, pas dans `perysee:maintenance`, pour ne pas toucher au budget de requêtes du passage à vide.
- Décision : [ADR 006](docs/adr/006-read-only-api-tokens.md). Tests : `ApiTokenManagementTest`, `ApiV1AuthTest`, `ApiV1ReportsTest`, `ApiV1RateLimitTest`, `ApiV1GoalsFunnelsTest`, `ApiV1OpenApiTest`, `ApiTokenPurgeTest`, `ShareJsonCharacterizationTest`, plus `tests/Charts/api-tokens-nav.test.mjs`.

### Objectifs et conversions

- Écrans `/sites/{website}/goals` et `/goals/{goal}` (lecture pour tout membre, création, modification et archivage pour un `owner`), API JSON et export CSV. Cinq objectifs actifs au plus par site ; type `event` (événement nommé) ou `path` (chemin normalisé) ; seuil de 1 à 1000 occurrences par session.
- Modifier un objectif crée une nouvelle version (`goal_versions`) ; les versions antérieures restent consultables (`?version=`). Archiver ne supprime rien.
- Une session mesurée convertit au plus une fois par version d'objectif, sur l'événement qui atteint le seuil ; elle est attribuée aux dimensions de son premier événement (**premier contact**). Un événement sans session mesurée compte comme occurrence, jamais comme conversion. Les compteurs sont dans `stats_goal_daily` (jour local du site) ; `goal_sessions` sert de registre et de déduplication.
- **Données brutes et rétention.** Un objectif défini après coup est compté sur les événements bruts existants à sa création (`snapshot_id`), par lots de `perysee:rebuild-goals`, donc au mieux sur `raw_retention_days` (90 jours par défaut). Les événements postérieurs sont comptés par l'agrégateur ; les deux ne se chevauchent jamais. Le rapport signale un historique incomplet (`rebuilding` pendant le comptage, `partial` au-delà de la rétention brute ou si des sessions manquent).
- `perysee:prune-tracking` (appelé par `perysee:maintenance`) supprime les lignes de `goal_sessions` dont la dernière occurrence dépasse `raw_retention_days`. Les compteurs de `stats_goal_daily` ne sont pas touchés par la rétention des statistiques. La suppression d'un site retire ses objectifs, versions, sessions et compteurs par lots.

### Comparaison de périodes

`compare=previous` (seule valeur acceptée, 422 sinon) ajoute un bloc `comparison` produit par `Reports::comparison()`. La période précédente a la même durée et finit la veille du début, en calendrier du fuseau du site. Le serveur décide seul si un pourcentage peut s'afficher (`reliability.<métrique>.percent_allowed` et `reason`) : `current_period` (la période inclut aujourd'hui), `previous_zero`, `config_change` (une rupture du journal de continuité, ADR 009, tombe entre le début de la période précédente et la fin de la période courante et concerne la métrique par son `scope` : `all`, `sessions` pour les sessions, `pageviews`, `events` pour les événements personnalisés ; une valeur d'énumération ajoutée à l'API v1, donc un ajout compatible ; une seule lecture du journal par comparaison, au plus quatre requêtes ajoutées, RAP-04), `unavailable`, `partial_coverage`, `rebuilding`, `not_applicable` (filtre d'événement). Le front rend ces raisons sans les recalculer. La série précédente est alignée par rang de jour, ou par heure locale pour une période d'un jour (les jours de 23 et 25 heures laissent le créneau manquant vide). Sans `compare`, aucune requête supplémentaire et les réponses sont identiques ; `GET /api/sites/{id}/overview` ajoute la clé `comparison` seulement à la demande ; l'export CSV l'ignore. Le tableau de bord ne compare que les totaux et saute les sites en reconstruction.

**Journal de continuité dans les rapports (ADR 009).** `Reports::continuity()` (une lecture, `ContinuityLog::between()`) donne à l'écran d'un site la prop Inertia `continuity` : `[{at, kind, scope, severity}]`, `at` étant le jour local du site (`data_from`, ou le jour d'écriture), jamais le payload. Le graphique trace un `markLine` pointillé libellé par rupture (`resources/js/continuity.mjs`, `chartMarkers`) pour les seules métriques concernées par le `scope` et pour les périodes à granularité journalière ; la liste « About these numbers » remplace les périodes de mesure en UTC. Les liens de partage ne reçoivent pas le journal (`ShareJsonCharacterizationTest`).

### Tests ajoutés

`ComparisonReportTest` (18), `TeamManagementTest` (11), `TeamMariaDbTest` (1, MariaDB seulement), `InvitationAcceptanceTest` (6), `ShareLinkTest` (12), `GoalDefinitionTest` (4), `GoalConversionTest` (7), `GoalBackfillTest` (6), `GoalReportingTest` (6), `GoalCostTest` (3), `GoalPurgeTest` (2), plus `tests/Charts/comparison.test.mjs`. Sur SQLite, ces 76 tests PHP passent (un ignoré). La suite MariaDB (voir « Vérifications ») rejoue les mêmes classes avec le conteneur Apple `perysee-mariadb` (`bash scripts/mariadb.sh test-db`, `php scripts/configure-testing.php`, `composer test:mariadb`) ; elle est la seule à exécuter `TeamMariaDbTest`. Les tests d'objectifs ne supposent aucun identifiant fixe : MariaDB ne remet pas l'auto-incrément à zéro entre deux tests.

## Double authentification, appareils connectés et journal d'accès

Décision : [ADR 007](docs/adr/007-two-factor-authentication-open-sessions-and-the-access-log.md). Quatre migrations additives (`2026_11_08_1200*`) : `user_two_factor`, `user_recovery_codes`, `user_sessions`, `access_log`. Aucune ne touche `users`.

- **Dépendances** : `pragmarx/google2fa` (TOTP) et `bacon/bacon-qr-code` (QR en SVG côté serveur). Elles exigent les extensions `iconv` et `xmlwriter`, vérifiées par l'installateur. Le ZIP de release embarque tout `vendor/`.
- **Connexion** : `AuthController::store` n'authentifie jamais un compte doté d'un second facteur ; l'état en attente vit dans la session (`login.2fa`, 5 minutes) et `TwoFactorChallengeController` appelle `Auth::login()` après un code valide. Un compte sans second facteur suit le chemin d'origine (`LoginFlowCharacterizationTest` le fige).
- **Tests** : `TwoFactorEnrollmentTest`, `TwoFactorLoginTest`, `RequiredTwoFactorTest`, `UserSessionsTest`, `AccessLogTest`, `AccessLogPurgeTest`, `TwoFactorRecoveryCommandTest`, `TwoFactorMariaDbTest` (MariaDB seulement : deux tentatives simultanées avec le même code), `npm run test:security`. Le client de test de Laravel ne renvoie pas le cookie de session : `KeepsSessionCookie::browser()` le fait quand un test dépend de l'identifiant de session.
- **Purge** : `php artisan perysee:prune-access-log` (90 jours, lots de 1000, 20 lots au plus par passage), planifiée toutes les heures sans chevauchement. Le passage à vide de `perysee:maintenance` n'est pas modifié.
- **Perte du téléphone** : codes de secours, puis `php artisan perysee:recover-admin <email> --reset-2fa` pour un administrateur. Perdre `APP_KEY` rend les secrets illisibles.

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

## Messagerie de l’instance (SMTP)

Un administrateur règle le serveur SMTP depuis **Administration > Mail** (`/settings/mail`). Sans réglage enregistré, `MAIL_*` dans `.env` reste utilisé : les installations existantes ne changent pas. Les réglages enregistrés (et activés) l’emportent. Décisions : [ADR 003](docs/adr/003-instance-smtp-settings.md).

- **Stockage.** Une clé `mail_settings` de l’état de l’instance (`analytics_state`, via `InstanceHealth`), sans migration. Le mot de passe est chiffré (`Crypt::encryptString`) et n’est jamais renvoyé au navigateur. Si `APP_KEY` change, il doit être ressaisi.
- **Un seul point d’entrée.** Tout nouveau message passe par `App\Mail\InstanceMailer` : `configured()` (réglages enregistrés complets ou mailer d’environnement autre que `log`/`array`), `send($to, $mailable)` (synchrone, sans file, 10 s de délai) et `test($to)`. `MailSettings::apply()` configure le mailer au moment de l’envoi, pas à chaque requête. Une fonctionnalité qui a besoin d’un mail teste `configured()` et garde un chemin sans mail (les invitations affichent un lien à copier).
- **Erreurs.** `MailNotSent` ne porte qu’une catégorie : `connection`, `authentication`, `sender_rejected`, `other` (et `not_configured` pour un test sans serveur). Le texte de l’exception est journalisé par `report()` après suppression des secrets ; l’interface n’affiche jamais la réponse du serveur.
- **Le mot de passe ne change pas de serveur.** Si l’hôte, le port ou l’utilisateur changent (ou si le chiffrement passe à « None ») sans mot de passe saisi ni case « Remove the password », l’enregistrement et le test sont refusés.
- **Sécurité.** Réservé aux administrateurs, 5 envois de test par minute et par administrateur, délai de connexion de 10 s. Le serveur se connecte à l’hôte choisi par un administrateur, au même niveau de confiance que l’hôte de base de données de l’installateur.
- **Tests.** `MailSettingsTest`, `InstanceMailerTest`, `MailSettingsPageTest`, `TeamManagementTest` (invitations) et `tests/Charts/workspace-shell.test.mjs`. Pour une vérification de bout en bout sans vrai serveur, un petit puits SMTP local (Python `asyncio`, 127.0.0.1:2525) suffit : choisir « None » comme chiffrement.

## Vérificateur d’installation du tracker

Une page **Verify the installation** par site (`/sites/{site}/tracking/verify`, liée depuis « Tracking setup ») répond à « pourquoi rien n’apparaît ? ». Décisions : [ADR 004](docs/adr/004-tracker-installation-check.md).

- **Collecteur.** Chaque refus est une `App\Analytics\Ingestion\CollectionRejected` (statut et corps identiques à l’ancien `abort_*`) portant une `RejectionCause`. Elle est enregistrée dans le `catch` de `TrackingController::collect`, après le rollback, par `RejectionLog`. Le chemin accepté ne change pas : un SELECT du site, un INSERT, plus un UPDATE à la première collecte ; `CollectionQueryBudgetTest` fige cette liste. Les refus de forme (413, 415, 422) sont désormais comptés dans « Collection rejections ».
- **Stockage.** Table `collection_diagnostics` : une ligne par site, heure UTC et cause, `hits` incrémenté par upsert, rétention de 7 jours (`perysee:prune-diagnostics`, toutes les heures). Ni IP, ni User-Agent, ni chemin, ni payload ; `sample_host` seulement pour `domain_not_allowed` et `unknown_site`. Écritures limitées à 20 par minute, site et cause : les comptes affichés sont « au moins N ».
- **Test « Test now ».** Réservé aux propriétaires : `POST /sites/{site}/tracking/probe` (6 par minute) délivre un jeton HMAC de 10 minutes lié au site ; le navigateur le présente dans l’en-tête `X-Perysee-Probe` au vrai `/api/collect`, qui répond 204 sans rien écrire dans `events` ni dans `first_collected_at`. `OPTIONS /api/collect` répond au preflight. Le test ne prouve pas la liste de domaines ni le consentement du site suivi.
- **Sites silencieux.** `SiteActivity` ajoute `last_event_at` et `silent` aux listes (une requête groupée par page). Seuil : `perysee.silent_site_days` (`PERYSEE_SILENT_SITE_DAYS`, 3 jours).
- **Budget au repos.** `IdleSchedulerCostTest` est à 30 requêtes sur SQLite (limite 30) : la purge n’est donc pas dans `perysee:maintenance` mais dans sa propre commande.
- **Limite et durées.** Le collecteur accepte 120 événements par minute pour une adresse et un site (`perysee.collect.rate_limit`, `max_attempts` et `decay_seconds`). Les durées du tracker sont des constantes de `resources/js/lueur.js` : une session mesurée se termine après 30 minutes d’inactivité (`idle`, 1 800 000 ms) ou 24 heures de vie (`lifetime`, 86 400 000 ms), et le cookie de mesure est réécrit à chaque activité avec un `Max-Age` d’au plus 1 800 secondes.
- **Tests.** `CollectionQueryBudgetTest`, `CollectionRejectionCharacterizationTest`, `CollectionRejectionTest`, `CollectionDiagnosticsPurgeTest`, `TrackerCheckTest`, `TrackerProbeTest`, `SilentSiteIndicatorTest`, `tests/Charts/tracker-check.test.mjs`.

## Installateur

`curl -fsSL https://get.perysee.com | bash` télécharge `install.sh`, épinglé à sa version lors du build. Sans `--mode`, un terminal permet de choisir `cpanel`, `docker` ou `manual` (le mode que la machine suggère est proposé par défaut ; `--yes` sans `--mode` garde le mode manuel). Une version précise s'installe par son adresse : `https://get.perysee.com/releases/vX.Y.Z/install.sh`.

| Élément | Rôle |
| --- | --- |
| `scripts/install.sh` | Choix du mode, recherche d'un PHP CLI 8.3+ (`PERYSEE_PHP`, `php`, `php8.x`, `/opt/cpanel/ea-php*`), vérification des fichiers contre `SHA256SUMS`. La branche Docker est du shell pur : ni PHP ni registre Perysee. |
| `scripts/install.php` | Télécharge/vérifie/extrait le ZIP (jamais d'écrasement), puis termine selon `--finish` (`browser`, `terminal` ou `none`). Mode `cpanel` : `--domain` obligatoire, termine par `perysee:install --cpanel`. |
| `perysee:install` (`app/Console/Commands/InstallApplication.php`) | Installation complète au terminal ; `--browser` affiche le lien `/install#secret=…` ; `--cpanel --domain=` prépare le compte puis installe. Mots de passe par `PERYSEE_DB_PASSWORD`, `PERYSEE_ADMIN_PASSWORD` ou invite masquée, jamais en argument. |
| `app/Installation/Cpanel/` | `UapiClient` (commande locale `uapi`, mot de passe de base sur l'entrée standard), `CpanelProvisioner` (sous-domaine, base, utilisateur, PHP, cron ; ne crée que du nouveau et ne supprime que ce qu'il a créé). |
| `app/Installation/DatabaseVersion.php` | Séries MariaDB prises en charge (10.11, 11.4, 11.8). Ajouter une série impose de l'ajouter à la matrice de `.github/workflows/ci.yml`. MySQL n'est pas pris en charge : verrous et upserts supposent le pilote `mariadb`. |
| `docker/` | `Dockerfile`, `compose.yaml`, `docker-entrypoint.sh`, publiés avec la release (dans `SHA256SUMS`). L'image est construite chez l'utilisateur depuis le ZIP vérifié. `PERYSEE_INSTALL_MODE=docker` désactive la mise à jour sur place ; mise à jour par `install.sh --mode docker --upgrade --target <dossier>`. |

Tests : `php artisan test` (commande, provisionnement avec `uapi` simulé par `Process::fake`), `npm run test:distribution` (le build de release, `install.php`, `install.sh` avec `curl`, `docker` et un terminal simulés par `PERYSEE_TTY`). La CI de release installe le vrai ZIP contre une base MariaDB éphémère et construit l'image Docker.

`get.perysee.com` redirige vers les fichiers de la release : `install.sh`, `install.php`, `perysee.zip`, `SHA256SUMS`, `Dockerfile`, `compose.yaml` et `docker-entrypoint.sh` (règles dans `Perysee/web`, `infra/get`).

## Mise à jour, sauvegarde et retour arrière

Une instance Docker (`PERYSEE_INSTALL_MODE=docker`) refuse la mise à jour décrite ci-dessous : le code vit dans l'image, il se remplace avec `install.sh --mode docker --upgrade`.

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

Le registre des fonctionnalités est tenu par l'équipe dans un dépôt privé. Le mettre à jour à chaque batch ; ne pas transformer une implémentation locale en qualification d'hébergement.

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

Validation : tests Inertia de navigation, droits, absence de secrets, erreurs de formulaires et dates selon le site ; suites PHP/MariaDB et Tracker ; contrôle navigateur du calendrier au clavier, des filtres IA, de la carte, des formulaires et de la navigation mobile. Le calendrier et les tableaux n’utilisent aucun service externe. Les poids et résultats finaux sont consignés dans `docs/SUIVI-FONCTIONNALITES.md`.
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

## Valeur des événements et propriétés bornées (ADR 008)

- **Collecte.** `value`, `props` et `id` sont retirés du corps par `EventValues::take()` avant la liste blanche du contrôleur, validés par `EventValues::prepare()` (422 pour une erreur de structure) puis ajoutés à l'`INSERT`. Les bornes souples et la liste de clés refusées sont dans `PropertyPolicy` (figées dans le code). Aucune requête de plus sans `id` ; avec `id`, un `INSERT IGNORE` dans `event_order_ids`, dans la transaction de l'événement.
- **Agrégation.** `Goals` ajoute `value_sum` et `valued_hits` aux deltas existants ; `EventPropertyStats` remplit `stats_event_property_daily` (100 noms par site, 10 clés par nom, 50 valeurs par jour, le reste dans `(other)`).
- **Rétention.** `perysee:prune-event-values` (toutes les heures) purge les identifiants de commande (`PERYSEE_ORDER_DEDUP_DAYS`, 30 par défaut) et les compteurs de propriétés (rétention des statistiques). Le passage à vide de `perysee:maintenance` ne change pas.
- **Rapports.** `GoalReports` ajoute le bloc `amounts` (les `totals` gardent leur forme) ; `EventPropertyReports` et `EventPropertyController` servent `/sites/{site}/events/properties` (page, API, CSV). Tout CSV passe par `CsvSafe`.
- **Tests ajoutés.** `EventValueIngestionTest`, `EventPropertiesIngestionTest`, `EventOrderDeduplicationTest`, `GoalValueTest`, `EventPropertyAggregationTest`, `EventValuePurgeTest`, `GoalValueReportingTest`, `EventPropertyReportTest`, `CsvInjectionTest`, `WebsiteCurrencyTest`, et `tests/Tracker/lueur-values.test.mjs`. Aucun identifiant de ligne fixe : utiliser les identifiants des modèles.
