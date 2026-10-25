# Distribution et exploitation — documentation interne

Ce fichier est réservé aux mainteneurs. Il reste dans le dépôt privé et n’est ni importé par le site documentaire public, ni inclus dans le ZIP distribué.

## Parcours public

La seule commande montrée aux utilisateurs est :

```sh
curl -fsSL https://get.perysee.eu | bash
```

Le script contrôle PHP CLI, télécharge les assets, vérifie le SHA-256 de l’installateur PHP avant de l’exécuter, puis lui transmet le ZIP et ses sommes de contrôle. L’installateur PHP vérifie à son tour le ZIP, demande le dossier et l’URL HTTPS, extrait dans un dossier temporaire, refuse toute destination non vide et affiche le document root et le lien `/install`.

Les questions passent par `/dev/tty` lorsque le script est lancé via un pipe : le code téléchargé et les réponses de l’utilisateur ne partagent pas la même entrée. Sans terminal interactif, utiliser `bash install.sh --target /chemin/perysee --url https://stats.example.com --yes`.

## Construire une distribution

Le dépôt GitHub `mattrbdr/perysee` contient directement le contenu de `application/`. Les sites Nuxt dans `presentation/` restent séparés.

Dans un checkout neuf, avec PHP 8.3+, PHP Zip, Composer 2 et la version Node de `.nvmrc` :

```sh
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
npm ci
npm run build
php scripts/build-release.php --version v0.1.1 --repository mattrbdr/perysee
```

`dist/` reçoit quatre fichiers :

- `perysee.zip` : application de production, dépendances, assets, polices, tracker et guides de l’application.
- `install.php` : installateur autonome lié à cette version.
- `install.sh` : point d’entrée shell, lié à cette version.
- `SHA256SUMS` : empreintes des trois fichiers précédents.

Le packager utilise une liste explicite de chemins. Il ne distribue ni `.env`, ni secrets/verrous d’installation locaux, ni sessions, logs, caches PHP générés, tests, infrastructure, `node_modules/` ou ce fichier `DEVELOPMENT.md`. Les guides Markdown nécessaires à l’interface de l’application restent inclus.

## Tests et workflows

Avec les dépendances de développement installées :

```sh
npm run test:distribution
```

Les tests de distribution couvrent les ZIP propres, les collisions de destination, les checksums, les chemins dangereux et liens symboliques, le script shell, les téléchargements interrompus et le parcours interactif réellement lancé via un pipe dans un pseudo-terminal. Les tests du relais couvrent les fichiers autorisés, les redirections, le cache, HEAD et la protection du jeton.

Le workflow `Application checks` couvre PHP 8.3/8.5, SQLite, une MariaDB 11.4.8 dédiée, le tracker et la distribution. `Build release` appelle ces contrôles, fabrique les assets en production et teste le vrai ZIP. Il publie ensuite les quatre fichiers ensemble pour un tag poussé. Un lancement manuel ne publie pas de release.

```sh
git tag v0.1.1
git push origin v0.1.1
```

Utiliser un nouveau tag pour chaque release. Les versions avec suffixe, par exemple `v0.2.0-beta.1`, sont marquées comme préversions et ne deviennent pas la dernière version stable. Ne pas remplacer les assets d’un tag déjà publié : le relais met les URLs versionnées en cache.

## Relais Cloudflare : get.perysee.eu

Le code est dans `infra/downloads/worker.mjs` et le domaine dans `infra/downloads/wrangler.toml`.

**État de mise en service :** le code et la configuration sont préparés. La commande publique nécessite le déploiement de ce Worker, son secret et une release contenant `install.sh`. Ne pas annoncer l’adresse comme active tant que ces étapes et le test réel ne sont pas terminés.

Une simple redirection Cloudflare vers une release privée ne suffit pas : le client devrait s’authentifier sur GitHub. Le Worker lit les releases avec son propre jeton et sert uniquement les quatre assets autorisés. Il ne donne aucun accès aux fichiers du dépôt, à ses métadonnées ou à une API GitHub arbitraire. Les fichiers de l’application contenus dans le ZIP deviennent bien téléchargeables publiquement ; le dépôt Git et son historique restent privés.

Créer un jeton GitHub limité à `mattrbdr/perysee` avec **Contents: read**, puis le stocker uniquement comme secret du Worker, jamais dans `wrangler.toml`, le frontend, le script public ou les logs. Depuis un terminal mainteneur authentifié sur le compte Cloudflare qui gère `perysee.eu` :

```sh
cd infra/downloads
npx wrangler@4 login
npx wrangler@4 secret put GITHUB_TOKEN
npx wrangler@4 deploy
```

La route utilise un domaine personnalisé Cloudflare. Vérifier les prérequis et conflits DNS dans la zone avant son activation. Les secrets et le domaine sont gérés par Cloudflare ; les sauvegardes de configuration ne doivent jamais inclure la valeur du jeton.

Sources : [secrets Workers](https://developers.cloudflare.com/workers/configuration/secrets/), [domaines personnalisés](https://developers.cloudflare.com/workers/configuration/routing/custom-domains/).

### Routes et stabilité des téléchargements

- `GET /` : résout la dernière release stable, sert le script shell et lui fournit l’URL publique de **cette version**. Le téléchargement reste cohérent même si une nouvelle version sort pendant l’installation.
- `GET /perysee.zip` et les autres noms d’assets : redirigent vers une URL publique versionnée du même relais.
- `GET /releases/vX.Y.Z/NOM` : sert uniquement `install.sh`, `install.php`, `perysee.zip` ou `SHA256SUMS` de cette version.
- Les autres chemins renvoient 404, les autres méthodes 405. HEAD est supporté.

Le jeton est envoyé uniquement à `api.github.com`. Lorsqu’un asset est fourni via une URL signée GitHub, le Worker la suit **sans** en-tête d’authentification. Les réponses publiques ne recopient pas les en-têtes privés de l’amont. En cas de panne, un message générique est renvoyé, sans exposer le contenu de l’erreur GitHub.

La racine et les alias sont mis en cache 60 secondes ; les assets versionnés sont immuables. Aucune republication du Worker n’est nécessaire pour proposer une nouvelle release stable.

### Vérifier la mise en service

Après déploiement et publication d’une version contenant le script shell :

```sh
curl -fsSL https://get.perysee.eu -o /tmp/perysee-install.sh
bash /tmp/perysee-install.sh
```

Utiliser une destination de test neuve. Vérifier les questions interactives, le document root affiché et le lien `/install`. Tester aussi le téléchargement du ZIP sans session GitHub. Le test doit réussir sans jeton sur la machine cliente.

## Tests privés avant activation du domaine

Le test hors réseau d’un ZIP reste disponible :

```sh
php dist/install.php --archive dist/perysee.zip --checksums dist/SHA256SUMS \
  --target /chemin/parent-existant/perysee-test \
  --url https://stats.example.com --yes
```

Pour tester un autre miroir HTTPS sans modifier le script, exporter `PERYSEE_DOWNLOAD_URL` avant de lancer `bash install.sh`. Cette option est interne : le relais public fixe automatiquement sa propre URL versionnée.

L’installateur PHP conserve la prise en charge interne de `GH_TOKEN` ou `GITHUB_TOKEN` pour télécharger une release privée directement. Pour tester ce chemin, télécharger `scripts/install.php` via un compte autorisé, exporter le jeton en mémoire puis lancer le fichier avec PHP. Ne pas mettre ces instructions dans Nuxt Content. Ne jamais passer le jeton dans une URL ou un argument de l’installateur.

## Développement local — environnement et démonstrations

Les commandes de cette page s’exécutent dans `application/` dans l’espace de travail local, ou à la racine du dépôt Laravel lorsqu’il est cloné seul. La landing et ce site documentaire sont deux applications séparées dans `presentation/`.

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
