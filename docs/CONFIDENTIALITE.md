# Mesure d’audience, cookies et consentement

État de la recherche et des choix techniques : **25 octobre 2026**. Ce document décrit les modes proposés et les points que l’éditeur doit évaluer pour son contexte ; Perysee ne se présente pas comme certifié par la CNIL ni comme exempté par défaut.

## JavaScript ou cookie : deux fonctions différentes

| Élément | Fonction dans Perysee | Limite |
|---|---|---|
| Script Lueur | Envoie une page vue après activation | Sans identifiant, il ne relie pas les visites |
| Cookie de session | Conserve un jeton aléatoire sur le site suivi | Une session n’est pas une personne ; plusieurs navigateurs donnent plusieurs sessions |
| Cookie de choix de la CMP | Mémorise acceptation, refus ou opposition | Géré par la CMP, indépendamment du cookie de mesure |
| Cookies du backoffice | Maintiennent la connexion administrateur | Distincts du tracker, qui n’envoie pas les credentials avec ses événements |

**Recommandation produit :** conserver les deux modes de mesure, et faire du consentement un réglage séparé. Garder le défaut « pages vues sans identifiant + consentement requis ». Les sessions répondent à un autre besoin : les activer quand leur utilité justifie le suivi et que le cadre choisi l’autorise. Ne pas fabriquer de visiteurs uniques en combinant IP et navigateur.

## UE, hors UE et exemption

Le périmètre territorial du RGPD ne se réduit pas au pays du serveur ou à la nationalité du visiteur : il peut notamment concerner un organisme hors UE qui cible des personnes dans l’Union ou suit leur comportement. Les autres règles nationales doivent aussi être examinées. Une géolocalisation IP ne suffit donc pas à décider du consentement. [Commission européenne — application du RGPD](https://commission.europa.eu/law/law-topic/data-protection/information-business-and-organisations/application-gdpr_en).

Les règles sur l’accès au terminal couvrent d’autres techniques que les cookies. Remplacer un cookie par du stockage local ou une empreinte ne constitue pas une dispense. Un script sans cookie doit lui aussi être analysé selon ses opérations. [CEPD — lignes directrices 2/2023, version finale du 16 octobre 2024](https://www.edpb.europa.eu/documents/guideline/guidelines-22023-on-technical-scope-of-art-53-of-eprivacy-directive_en).

En France, certaines mesures d’audience peuvent bénéficier d’une exemption sous conditions. La CNIL propose depuis 2025 une auto-évaluation : finalité limitée, minimisation, absence de recoupement et de suivi transversal, statistiques anonymes, opposition et information. Ce cadre ne vaut pas validation générale de conformité. Son exemple de configuration écarte notamment les identifiants CRM et les UTM ; une future attribution marketing devra être examinée séparément. **Lueur n’a pas encore fait l’objet d’une auto-évaluation complète**, notamment pour les règles de restitution et les procédures de droits. [CNIL — outil d’auto-évaluation de juillet 2025](https://www.cnil.fr/sites/default/files/2025-07/outil_d_auto-evaluation_mesure_d_audience.pdf).

| Contexte retenu par l’éditeur | Réglage recommandé |
|---|---|
| Mesure avec consentement | Mode voulu + attente de la CMP ; script chargé après accord si nécessaire |
| Exemption documentée ou contexte où la demande préalable n’est pas requise | Mode compatible avec l’analyse + « consent not required » ; information et opposition maintenues |
| Audience internationale ou analyse encore incertaine | Consentement requis pour tous ; revue spécifique avant une politique régionale |

Le réglage est actuellement **global au site**. Perysee ne fournit ni géolocalisation automatique ni moteur juridique par pays. Pour des parcours régionaux, faire porter la décision par une CMP correctement configurée et conserver la politique du collecteur cohérente ; ne pas faire passer une dispense pour un consentement donné.

## Ce qui est effectivement collecté

Lueur envoie : version du protocole, identifiant public du site, mode, domaine, chemin nettoyé et état d’activation par consentement. Le serveur ajoute sa date UTC. En mode sessions uniquement, un jeton est transmis puis remplacé en base par un HMAC lié au site et à la clé privée de l’instance.

Le cookie `perysee_lueur_<identifiant-du-site>` est first-party et limité à l’hôte, sans attribut Domain. Il contient un identifiant aléatoire et des dates, expire après **30 minutes sans page vue**, et change au plus tard après **24 heures**, même en activité. Il utilise `SameSite=Lax` et `Secure` sous HTTPS ; il est accessible au JavaScript et ne doit jamais servir d’authentification. Il n’est créé qu’une fois la mesure autorisée par la politique/CMP. Le mode sans identifiant ne lit ni n’écrit ce cookie.

Perysee ne conserve dans la table d’événements ni IP brute, ni user-agent, ni email, ni query string, ni URL de référent, ni UTM. L’adresse IP reste techniquement reçue par l’infrastructure ; les journaux du serveur, du CDN, du proxy et leurs durées doivent être traités séparément. Le quota utilise une empreinte HMAC d’IP dans un cache de courte durée. Les chemins peuvent encore contenir des données personnelles non détectées : exclure les pages sensibles ou utiliser `data-path`.

Le HMAC de session est une pseudonymisation, **pas une preuve d’anonymisation**. Les compteurs de contrôle représentent des pages reçues et des identifiants de session observés dans une fenêtre, jamais des personnes uniques ni un décompte exact de sessions commencées.

## Consentement et droits : responsabilités restantes

La CMP doit présenter un choix compréhensible, restaurer le refus sur les pages suivantes et permettre de revenir dessus. Le retrait doit rester aussi accessible que l’acceptation. Les appels `lueur.consent(true/false)` sont une interface technique ; le booléen reçu ne prouve pas à lui seul un consentement valable. [CNIL — retrait du consentement](https://www.cnil.fr/fr/cnil-direct/question/cookies-est-il-possible-de-finalement-refuser-les-cookies-dun-site-web-apres).

Avant un déploiement public, l’éditeur doit déterminer la finalité et la base juridique, informer les visiteurs, définir les procédures de droits et encadrer les prestataires et transferts éventuels. Une base RGPD choisie ne dispense pas, à elle seule, des règles d’accès au terminal. Les fonctionnalités CMP, preuves, traitement des demandes individuelles et audit d’exemption ne sont pas intégralement implémentées par Perysee. [CNIL — cookies et autres traceurs](https://www.cnil.fr/fr/cookies-et-autres-traceurs/regles/cookies/comment-mettre-mon-site-web-en-conformite).

La rétention des événements est paramétrable et vaut 90 jours par défaut, sans prétendre qu’il s’agisse d’un maximum légal universel. Le [guide d’installation](INSTALLATION-SUIVI.md) précise le cron de purge ; sauvegardes et logs ont leur propre cycle. Une désactivation de mesure arrête les envois futurs et supprime le cookie de session, mais n’efface pas rétroactivement les événements déjà reçus.
