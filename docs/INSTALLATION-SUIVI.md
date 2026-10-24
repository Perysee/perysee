# Installer Lueur et tester la collecte

Lueur est le petit script de Perysee. Il mesure les pages vues et, si le site l’active, des sessions. Il ne mesure pas des personnes uniques. Les rapports agrégés et les campagnes ne sont pas encore implémentés ; la fiche du site propose un contrôle de réception sur les quinze dernières minutes.

## Préparer l’application

Installer Perysee selon le [README](../README.md), terminer l’assistant et créer son administrateur. Pour une application existante, appliquer les nouvelles migrations puis compiler les assets :

```sh
php artisan migrate
npm ci
npm run build
```

Node est utilisé pour la compilation uniquement. La distribution à déployer doit contenir `vendor/`, `public/build/`, `public/fonts/` et `public/tracker/`. La route `/lueur.js` sert le fichier compilé et négocie gzip avec `Accept-Encoding` ; ne pas intégrer directement le fichier `.gz` dans une balise script. Le build vérifie une limite de 5 Kio compressés.

## Choisir les deux réglages du site

Dans **Edit site**, choisir indépendamment :

- **Measurement** : `Pageviews · no identifier`, ou `Pageviews and sessions · first-party cookie`.
- **Consent policy** : attendre le consentement (défaut), ou démarrer sans demande après analyse de l’éditeur.

Perysee ne détermine pas les obligations selon une adresse IP. Aucun réglage ne certifie une exemption. Lire le [guide de confidentialité](CONFIDENTIALITE.md) avant d’activer la mesure automatique. Les nouvelles installations privilégient les pages vues sans identifiant et l’attente du consentement.

Ajouter le domaine exact et ses variantes dans **Allowed domains**. Les domaines ne sont pas des URLs ; `www.example.com` doit être ajouté séparément. Les sous-domaines ne partagent pas le cookie de session.

## Installer le script

La fiche du site fournit le snippet exact avec son identifiant public, son mode et sa politique. Exemple à adapter en copiant celui de l’interface :

```html
<script defer crossorigin="anonymous" referrerpolicy="no-referrer"
  src="https://stats.example.com/lueur.js"
  data-site="IDENTIFIANT_PUBLIC_DU_SITE"
  data-mode="sessions"
  data-consent-required="true"></script>
```

L’identifiant public n’est pas un secret. Mettre à jour ce snippet après une modification du mode ou de la politique ; les réglages envoyés restent contrôlés côté serveur. Autoriser le domaine Perysee dans les directives CSP `script-src` et `connect-src` de votre site.

### Relier le gestionnaire de consentement

Le gestionnaire de consentement (CMP) reste responsable de l’information, du choix, de sa conservation et des éventuelles preuves. Pour bloquer aussi le chargement du script avant permission, faire insérer la balise ci-dessus par la CMP après accord. Sur son rappel d’acceptation :

```js
window.lueur = window.lueur || [];
window.lueur.push(['consent', true]);
```

La file fonctionne avant ou après le chargement du script. Au retrait ou à l’opposition :

```js
window.lueur = window.lueur || [];
window.lueur.push(['consent', false]);
```

Le retrait stoppe les événements futurs et efface le cookie Lueur. Il ne supprime pas automatiquement les données précédemment acceptées. La CMP doit rejouer le refus sur les autres pages et lors des visites suivantes, ou ne plus charger le script : Lueur ne conserve pas lui-même le choix dans un cookie ou dans `localStorage`. Sans CMP persistante, l’appel vaut pour la page courante uniquement. Ce point vaut aussi pour le mécanisme d’opposition d’un site configuré sans demande préalable.

En mode `data-consent-required="false"`, la collecte démarre au chargement, sauf opposition fournie par la CMP ou signal de confidentialité. Un événement alors collecté porte `consent=false` : aucune preuve de consentement n’est inventée.

### Pages privées, SPA et exclusions

Les fragments et paramètres d’URL ne sont jamais envoyés. Les nombres, emails et certains jetons évidents dans les segments sont remplacés avant émission puis normalisés côté serveur. Cela ne détecte pas tous les noms ou autres données personnelles : exclure les pages sensibles ou fournir un chemin de regroupement :

```html
<!-- Ajouter cet attribut au snippet pour remplacer le chemin réel. -->
<script defer src="https://stats.example.com/lueur.js"
  data-site="IDENTIFIANT_PUBLIC_DU_SITE"
  data-path="/account/settings"
  data-mode="pageviews" data-consent-required="true"
  crossorigin="anonymous" referrerpolicy="no-referrer"></script>
```

Les navigations `pushState`, `replaceState` et `popstate` sont suivies sans compter deux fois un même chemin consécutif. Un changement de query string seul n’ajoute pas une page vue. Pour une navigation personnalisée : `window.lueur.pageview('/catalogue')`. Un `data-path` fixe s’applique aux navigations automatiques ; utiliser l’API pour des regroupements spécifiques.

Ne pas charger le script sur les pages à exclure. L’éditeur peut aussi définir `window.peryseeExclude = true` avant son chargement. Lueur respecte DNT et Global Privacy Control ; ces signaux bloquent la collecte même après activation. En mode sessions, les cookies doivent être autorisés et disponibles : sinon aucun événement n’est émis plutôt que d’inventer des sessions.

## Démonstrations locales

Après installation et migrations :

```sh
php artisan db:seed --class=LocalDemoSeeder
```

Deux sites sont attachés au premier administrateur : **Demo · No identifier** et **Demo · Sessions**. Relancer la seed ne réinitialise ni compte, ni mot de passe, ni réglages existants. Elle est refusée en production et ne crée pas d’administrateur.

Ouvrir un site dans Perysee puis **Open local test site**. Les pages Home, About et Pricing permettent de générer de vrais événements. Cliquer sur **Allow measurement**, naviguer, puis revenir à la fiche et rafraîchir. **Withdraw / object** teste l’arrêt et la suppression du cookie. La démonstration redemande l’activation après chaque navigation complète, car elle ne remplace pas une CMP. Les cookies de connexion au backoffice sont distincts du cookie de mesure.

Les domaines `localhost` et `127.0.0.1` sont autorisés uniquement sur ces sites de démonstration. Ces pages sont réservées à l’environnement local et aux membres du site. La seed ne fabrique aucun événement.

## Exploitation et vérifications

La collecte confirme `204` seulement après l’insertion SQL. Une erreur SQL renvoie `503` ; les événements refusés ou bloqués ne sont pas rejoués. Le script utilise `fetch` avec `credentials: omit` et `referrerPolicy: no-referrer`. Il n’utilise pas `sendBeacon`, qui ne propose pas ce contrôle des credentials. Une panne de mesure ne bloque pas la page suivie.

Le serveur accepte JSON ou `text/plain`, limite le corps à 4 Kio et applique un quota de 120 requêtes par minute et par site/IP pseudonymisée dans le cache. Le site public, l’en-tête Origin et CORS ne constituent pas une authentification contre les fausses visites. Les protections d’abus restent à qualifier en charge.

La rétention brute est de 90 jours par défaut (`PERYSEE_RAW_RETENTION_DAYS`). Programmer le scheduler, par exemple via cPanel :

```cron
*/5 * * * * cd /home/UTILISATEUR/perysee && /CHEMIN/PHP artisan schedule:run >> storage/logs/cron.log 2>&1
```

La purge traite au maximum 1 000 lignes expirées par passage, sans worker permanent. Le cron doit rester actif ; contrôler les événements expirés restants et adapter la fréquence ou la taille si le débit dépasse la purge. Cette version ne possède pas encore d’agrégats : les événements expirés sont supprimés même s’ils n’ont pas été agrégés. La politique des sauvegardes et journaux d’hébergement doit être réglée séparément. Pour un passage manuel : `php artisan perysee:prune-tracking --limit=1000`.

```sh
composer test
composer test:mariadb
npm run test:tracker
npm run build
```

Les tests JavaScript exécutent la source et la version minifiée : consentement, cookies, expiration, opposition, nettoyage des chemins, navigations et erreurs réseau.
