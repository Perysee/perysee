# Application Perysee

Application autonome **PHP 8.3+ / Laravel 13 / Blade / MariaDB 11.4.x**. Ce dossier contient l’application du produit ; `../presentation/` contient sa landing et son site documentaire et n’est pas une dépendance de l’application.

Ce dossier est la racine du dépôt privé [mattrbdr/perysee](https://github.com/mattrbdr/perysee) : `app/`, `artisan` et `.github/` sont directement à sa racine sur GitHub. Les sites de présentation ne font pas partie de ce dépôt.

## Installer une release

Les [releases GitHub](https://github.com/mattrbdr/perysee/releases) fournissent **`perysee.zip`**, prêt à déployer : dépendances PHP de production, assets compilés, polices et tracker inclus. **Ni npm, ni Node, ni Composer ne sont nécessaires sur l’hébergement.** Ne pas utiliser le ZIP « Source code » généré automatiquement par GitHub.

Le dépôt étant privé, utiliser un compte autorisé et un jeton GitHub avec **Contents: read** sur ce dépôt. Depuis Bash, dans un dossier hors de la racine web :

```sh
read -r -s -p "GitHub token: " GH_TOKEN
echo
export GH_TOKEN
curl --fail --location --proto '=https' --proto-redir '=https' \
  --header "Authorization: Bearer $GH_TOKEN" \
  --header 'Accept: application/vnd.github.raw+json' \
  'https://api.github.com/repos/mattrbdr/perysee/contents/scripts/install.php?ref=main' \
  --output perysee-install.php && php perysee-install.php
unset GH_TOKEN
```

L’installateur nécessite PHP 8.3+, les extensions de l’application et PHP Zip. Il demande le dossier de destination et l’URL HTTPS, télécharge la dernière release stable, vérifie son SHA-256 et extrait les fichiers sans écraser un dossier existant non vide. Il affiche ensuite le document root exact (`DOSSIER/public`) et le lien `/install`. Le parent du dossier choisi doit exister et être accessible en écriture. Le jeton n’est pas enregistré dans l’application. Supprimer le script téléchargé après utilisation.

Configurer le domaine, HTTPS et une base **MariaDB 11.4.x**, puis terminer l’assistant web décrit ci-dessous. Sans terminal, télécharger `perysee.zip` depuis les assets d’une release avec son compte GitHub, vérifier `SHA256SUMS` sur son ordinateur et extraire le ZIP avec le gestionnaire de fichiers. Il contient un dossier `perysee/` : le document root doit viser son sous-dossier `public/`.

Aucune release n’est disponible avant la première publication d’un tag de version. Voir la section « Fabrication et publication » pour préparer les trois fichiers automatiquement.

## Fonctionnalités disponibles

- Installation depuis le navigateur : diagnostic, configuration MariaDB, migrations, premier administrateur et verrouillage définitif.
- Connexion, déconnexion, changement de mot de passe et récupération administrateur par commande.
- Création, liste, consultation et modification de sites, domaines autorisés et fuseau de reporting.
- Isolation des sites par membre : `owner` en écriture, `viewer` en lecture. Aucun accès implicite aux sites pour l’administrateur d’instance.
- Interface Blade en anglais, responsive, avec assets et police Geist locaux.

**Lueur et le collecteur de pages vues sont disponibles**, avec modes sans identifiant ou sessions et consentement configurable. La fiche affiche un contrôle de réception sur quinze minutes ; les rapports agrégés et exports restent à développer. Aucune statistique fictive n’est affichée. La collecte ne confirme un événement qu’après son insertion SQL. Les invitations, inscriptions publiques et suppressions de sites ne sont pas disponibles.

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

La release `perysee.zip` contient `vendor/` de production, les assets compilés dans `public/build/`, `public/fonts/`, `public/tracker/` et les guides intégrés à l’interface. La qualification sur de vrais hébergements cPanel reste à réaliser.

1. Déployer les fichiers sur un hébergement PHP compatible et créer une base MariaDB **11.4.x** dédiée avec les droits nécessaires aux migrations.
2. Activer HTTPS et diriger la racine du domaine **vers `public/`**. Ne jamais exposer la racine de l’application. Vérifier également les alias et accès alternatifs du serveur.
3. Autoriser l’écriture dans le dossier de l’application pour créer/remplacer `.env`, ainsi que dans `storage/` et `bootstrap/cache/`. Ne pas utiliser de permissions universelles `0777`.
4. Déployer sans `.env` préexistant, ou utiliser une configuration de production avec `APP_DEBUG=false`, `APP_ENV=production`, sessions et cache fichiers. Ne pas copier les réglages locaux tels quels. Aucun cache `bootstrap/cache/config.php` ne doit être livré avant installation.
5. Ouvrir `/install`. La clé applicative et un secret aléatoire d’instance sont initialisés sans connexion SQL. Dans le gestionnaire de fichiers cPanel, ouvrir `storage/app/private/installation/secret` et reporter sa valeur dans l’assistant. Ne pas placer ce secret dans une URL ou un fichier public.
6. Corriger les diagnostics, saisir les paramètres SQL puis créer le compte administrateur. Les mots de passe exigent au moins 12 caractères, avec une limite de 72 octets pour bcrypt. Un champ de mot de passe SQL vide conserve une valeur déjà enregistrée pour la même connexion.
7. Après confirmation, se connecter et ajouter le premier site. Le secret est supprimé et `storage/app/private/installation/installed` verrouille l’installation.

L’assistant refuse une base contenant déjà des comptes. Une panne de connexion ne remplace pas la configuration existante. Après un échec de migration, corriger les droits et reprendre avec les mêmes paramètres : les migrations terminées sont conservées. Si la session expire, utiliser de nouveau le secret puis reprendre l’étape SQL.

Une instance installée fonctionne sans Node, Redis, Apple container, SMTP ni worker permanent. Les sessions et le cache utilisent des fichiers, et les dates SQL sont en UTC. La queue configurée en base est réservée aux futurs traitements ponctuels ; aucune tâche d’agrégation n’est planifiée. Une purge bornée des événements expirés est planifiée toutes les cinq minutes et nécessite le scheduler.

## Fabrication et publication

Réservé aux mainteneurs : utiliser un checkout neuf pour ne pas remplacer les dépendances de développement d’une instance locale.

```sh
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
composer check-platform-reqs --no-dev
npm ci
npm run build
php scripts/build-release.php --version v0.1.0 --repository mattrbdr/perysee
```

`dist/` reçoit `perysee.zip`, `install.php` et `SHA256SUMS`. Le packager sélectionne explicitement les fichiers nécessaires et exclut `.env`, les secrets/verrous d’installation locaux, les sessions, les logs, les caches générés, les tests et `node_modules/`. Les guides Markdown nécessaires à l’interface restent inclus.

Les workflows dans `.github/workflows/` fonctionnent depuis la racine de ce dépôt :

- **Application checks** : tests PHP 8.3 et 8.5, SQLite et MariaDB 11.4.8 dédiée, build des assets, tests du tracker et de la distribution.
- **Build release** : lors du push d’un tag `vX.Y.Z`, exécute les contrôles, fabrique les fichiers, installe le vrai ZIP dans un dossier temporaire et démarre Artisan, puis publie les trois assets ensemble dans une release GitHub. Un tag avec suffixe (`v0.1.0-beta.1`) devient une préversion. L’exécution manuelle fabrique uniquement un artifact, sans publication.

Après validation du commit à publier :

```sh
git tag v0.1.0
git push origin v0.1.0
```

Pour tester un ZIP sans GitHub :

```sh
php dist/install.php --archive dist/perysee.zip --checksums dist/SHA256SUMS \
  --target /CHEMIN/VIDE/perysee --url https://stats.example.com --yes
```

Cette commande installe uniquement les fichiers. Elle ne configure pas le domaine ni la base et ne remplace pas une instance existante. Les mises à jour nécessitent une sauvegarde, le remplacement contrôlé des fichiers en conservant `.env` et `storage/`, puis `php artisan optimize:clear` et `php artisan migrate --force` via un terminal de confiance. Ne pas utiliser l’assistant de première installation comme outil de mise à jour.

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

## Suivi et données de démonstration

- [Installer Lueur, intégrer le consentement et tester en local](docs/INSTALLATION-SUIVI.md).
- [Choisir entre pages vues, sessions et politique de consentement](docs/CONFIDENTIALITE.md), avec les sources CNIL et CEPD.

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
