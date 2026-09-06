# CLAUDE.md - Backend (Symfony/PHP)

Ce fichier complète `../CLAUDE.md` (règles générales du projet Cobage : sources de vérité, stack, sécurité, Git, formatage, dépendances, workflow). **Il ne les recopie pas.** Tout ce qui n'est pas spécifique au backend s'applique ici sans modification — se référer à `../CLAUDE.md`.

Le backend Symfony est **l'autorité métier, API et sécurité** du produit. Le frontend n'est jamais une source de vérité pour une décision d'autorisation ou de validation de donnée.

## 1. Sources de vérité spécifiques au backend

- `../bec-docs/docs/audit/audit-cobage.md`, section 3 (Backend — Sécurité) et section 4 (Backend — Qualité)
- `../bec-docs/docs/plan-correction/plan-correction-cobage.md`, phases 1, 2, 4, 5, 6
- `../bec-docs/docs/deploiement/deploiement-cobage.md`, section 4 (adaptations backend)

## 2. Architecture réelle

`src/` : `Controller/`, `Service/`, `Repository/`, `Entity/`, `DTO/`, `EventListener/`, `Scheduler/`, `Message/` + `MessageHandler/` (Messenger), `Security/` (Voters), `Constant/`.

Flux attendu pour un controller (rester mince) :

```text
Request → Validation (DTO + Assert) → Autorisation (Voter) → Service → Repository/Doctrine → Response
```

Ne jamais mettre de logique métier (calcul de matching, filtrage de visibilité, décision d'état) dans un contrôleur — elle appartient au service concerné (`MatchingService`, `VisibilityService`), testable indépendamment de la couche HTTP.

Avant d'ajouter un endpoint qui ressemble à un existant (ex. un second callback OAuth, une seconde méthode de filtrage de repository) : vérifier qu'il n'y a pas déjà une méthode à factoriser plutôt qu'à dupliquer (cf. audit, duplication `googleCallback`/`facebookCallback`, Phase 6 du plan de correction).

## 3. Autorisation

Un Voter dédié existe par ressource (`VoyageVoter`, `DemandeVoter`, `MessageVoter`, `AvisVoter`, `SignalementVoter`, `AdminVoter`) — c'est le mécanisme d'autorisation, ne jamais le contourner par une vérification ad hoc dans un contrôleur ou un service. Toute nouvelle ressource nécessitant une vérification d'appartenance doit avoir son propre Voter, suivant ce patron.

Vérifier systématiquement, sur chaque endpoint qui expose une ressource appartenant à un utilisateur : authentification, et appartenance de la ressource à l'appelant (via le Voter) — même pour un identifiant UUID difficile à deviner (pas d'autorisation implicite par obscurité).

## 4. Base de données (Doctrine)

Toute modification de schéma passe par une migration Doctrine.

**Ne jamais utiliser `cascade: ['remove']`** sur une relation d'`User` dont la suppression casserait une donnée appartenant à un tiers — en particulier `Message` et `Avis` (cf. audit Backend-Qualité #2, Phase 5 du plan de correction : la remédiation prévue est un soft-delete/anonymisation de `User` avec conservation de ces entités liées). Si une phase de correction sur ce sujet est en cours, vérifier son état dans `plan-correction-cobage.md` avant toute modification de `User.php`.

Ajouter systématiquement `leftJoin`/`addSelect` explicites dès qu'une requête retournant une collection va déclencher, pour chaque élément, un accès à une relation (`voyageur`, `settings`) — éviter les N+1, en particulier dans `VoyageRepository`/`DemandeRepository` consommés par `VisibilityService`.

Éviter les recherches `LIKE '%...%'` avec wildcard en tête sur des colonnes appelées à grossir — envisager un index full-text (`pg_trgm`) plutôt que d'empiler des filtres non indexables.

## 5. Authentification

JWT via LexikJWTAuthenticationBundle : access token en cookie, refresh token avec rotation à chaque usage, stocké hashé. Ces conventions sont déjà en place et constituent une bonne pratique constatée par l'audit — ne pas les régresser (ex. : ne jamais faire dépendre l'authentification d'un stockage en base non hashé, ne jamais désactiver la rotation).

Génération des clés JWT : ne jamais générer les clés au build de l'image Docker (cf. audit Backend-Qualité #9, Phase 6) — les générer au démarrage du conteneur sur un volume nommé dédié, monté uniquement sur le service qui en a l'usage (jamais sur un worker en parallèle du service principal, pour éviter une corruption de clé par écriture concurrente).

Toute route qui doit rester accessible sans authentification valide (ex. rafraîchissement de token expiré) doit avoir une règle `PUBLIC_ACCESS` explicite dans `access_control`, jamais une confiance implicite dans l'ordre des règles.

## 6. API

Ne jamais inventer silencieusement un endpoint ou modifier un contrat existant sans vérifier les consommateurs frontend. Toujours plafonner un paramètre de pagination fourni par le client (`limit`) — ne jamais faire confiance à une valeur non bornée pour dimensionner une requête (cf. audit Backend-Sécurité #2, `/api/users*`).

Restreindre les groupes de sérialisation à ce qui est réellement nécessaire pour le contexte d'appel : un endpoint public/liste ne doit pas exposer les mêmes champs qu'un endpoint d'accès à son propre profil (email, téléphone, adresse notamment).

## 7. Sécurité backend

Au-delà des principes de `../CLAUDE.md` section 10 :

- **Requêtes** : paramétrées via Doctrine systématiquement, jamais de concaténation de chaîne à partir d'une entrée utilisateur.
- **Erreurs** : la réponse HTTP en environnement de production ne doit jamais exposer de trace technique — vérifier `%kernel.debug%` (booléen natif), jamais une lecture brute de `$_ENV['APP_DEBUG']`.
- **Uploads** : validation du MIME réel (`finfo`), pas seulement de l'extension ; nom de fichier régénéré, jamais dérivé d'une entrée utilisateur non filtrée ; nginx ne doit jamais exécuter de PHP sous le chemin d'upload.
- **CORS** : origine explicite, jamais de wildcard combiné à `allow_credentials: true` ; `SameSite` du cookie JWT réévalué à chaque changement de topologie front/back (voir Phase 1 du plan de correction).
- **Secrets** : jamais en dur dans un fichier de configuration versionné, jamais loggés en clair — injection par variable d'environnement ou gestionnaire de secrets (voir `deploiement-cobage.md`, section 7, pour la cible Infisical).
- **Rate limiting** : tout nouvel endpoint sensible (authentification, recherche, action pouvant être répétée à faible coût) doit être évalué pour un rate-limiter dédié, sur le modèle déjà en place pour login/register/forgot-password.

## 8. Traitement asynchrone

Messenger (transport `async`) et Scheduler pour l'expiration des voyages/demandes et tout traitement dépendant d'une ressource à latence non maîtrisée. Un handler doit rester idempotent (rejouable sans dupliquer son effet métier) et porter explicitement l'identifiant du propriétaire concerné dans le message, jamais le déduire d'un contexte de requête HTTP qui n'existe pas dans un worker.

## 9. Tests

Le dossier `tests/` part d'une couverture quasi nulle (cf. audit Backend-Qualité #1, Phase 4 du plan de correction) — priorité aux tests fonctionnels sur `AuthController`, les Voters (un cas grant et un cas deny par ressource), `MatchingService`, `VisibilityService`. Toute nouvelle fonctionnalité touchant à ces zones doit être accompagnée d'un test ; ne pas augmenter la dette existante.

## 10. Workflow backend

En complément du workflow général de `../CLAUDE.md` (section 20) :

1. Inspecter le code existant sous `src/` (pas seulement la documentation).
2. Inspecter les tests existants sous `tests/` avant d'en ajouter de nouveaux (éviter la duplication de setup).
3. Vérifier sur Internet les API Symfony/Doctrine/Messenger concernées, pour la version réellement installée (`composer.json`).
4. Vérifier les implications de sécurité et d'autorisation (sections 3, 7).
5. Implémenter le minimum nécessaire, dans le module concerné, en respectant les frontières Controller/Service/Repository.
6. Ajouter les tests (section 9).
7. Exécuter `php bin/phpunit` et vérifier le diff, y compris les migrations générées.
8. Mettre à jour `../bec-docs/docs/` si la tâche fait évoluer une décision qui y était documentée, ou cocher les étapes concernées du plan de correction.

Règle finale, reprise de `../CLAUDE.md` : **« Do not guess when the answer can be verified. »**
