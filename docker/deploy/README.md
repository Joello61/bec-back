# Déploiement CI/CD - Backend Cobage

Phase D4 (`bec-docs/docs/deploiement/deploiement-cobage.md`). `.github/workflows/deploy.yml`
construit l'image backend une seule fois en CI, la pousse vers GHCR, déploie
automatiquement en staging puis attend une validation manuelle avant la production -
jamais l'inverse, jamais de reconstruction sur le serveur cible.

**Déclenchement** : après le succès complet du workflow `CI` (`.github/workflows/lint.yml`)
sur `main` - jamais sur une Pull Request.

**Périmètre** : ce pipeline ne touche que `backend`/`worker`/`scheduler` (même image). Le
frontend a son propre pipeline indépendant (`bec-frontend/.github/workflows/deploy.yml`),
qui ne touche jamais ces services. Les deux pipelines ciblent le même checkout du dépôt
`bec-infra` sur le serveur (fichiers Compose/nginx), synchronisé sur sa branche `main`
indépendamment du SHA applicatif déployé.

## Prérequis (aucun n'est fait par ce dépôt)

### 1. Serveur provisionné, dépôt `bec-infra` cloné

Un seul VPS pour staging et production (deux chemins de déploiement distincts, ex.
`/opt/apps/cobage` et `/opt/apps/cobage-staging`), routés par la même instance Traefik
partagée - voir `bec-infra/docker-compose.prod.traefik.yml.example`. Le dépôt `bec-infra`
doit être cloné une fois, manuellement, à chacun de ces deux chemins - ce script se
charge ensuite lui-même de le synchroniser (`git fetch` + `git checkout origin/main`) à
chaque déploiement.

### 2. Clé SSH de déploiement (par environnement)

Une paire de clés par environnement (jamais la même pour staging et production), clé
publique ajoutée à `~/.ssh/authorized_keys` de l'utilisateur de déploiement sur le
serveur.

### 3. Authentification GHCR sur le serveur

```bash
echo "<personal-access-token, scope read:packages>" | docker login ghcr.io -u <utilisateur-github> --password-stdin
```

Persisté dans `~/.docker/config.json` de l'utilisateur qui exécute les déploiements -
jamais refait à chaque déploiement.

### 4. Infisical (Phase D3)

Projet "Cobage" créé, deux identités machine Universal Auth (`bec-staging-deploy`,
`bec-production-deploy`), lecture seule, jamais partagées entre environnements. Chacune
doit pouvoir lire toutes les variables listées dans `bec-infra/.env.prod.example` pour
son environnement.

### 5. Environnements GitHub (Settings > Environments, dépôt `bec-back`)

Créer `staging` et `production`, chacun avec ses **propres** secrets (mêmes noms,
valeurs différentes) :

| Secret | Contenu |
|---|---|
| `SSH_PRIVATE_KEY` | Clé privée de déploiement de cet environnement |
| `SSH_HOST` | Adresse du serveur |
| `SSH_USER` | Utilisateur SSH de déploiement |
| `DEPLOY_PATH` | Chemin absolu du checkout `bec-infra` sur le serveur pour cet environnement |
| `INFISICAL_PROJECT_ID` | Identifiant du projet Infisical "Cobage" (non sensible, identique dans les deux environnements - un secret de dépôt suffirait, dupliqué ici par simplicité) |
| `INFISICAL_CLIENT_ID` | Identité machine Universal Auth dédiée à cet environnement |
| `INFISICAL_CLIENT_SECRET` | Client secret associé - jamais partagé entre staging et production |

**Sur `production` uniquement** : ajouter une règle **"Required reviewers"** - c'est le
seul garde-fou manuel avant la mise en prod réelle. Sans cette règle,
`deploy-production` s'exécute automatiquement après `deploy-staging`.

### 6. Visibilité du package GHCR (optionnel)

Privé par défaut (recommandé). Le rendre public dispense le serveur de `docker login`
mais expose l'image construite publiquement - décision à prendre explicitement.

## Rollback

Jamais automatisé. Rollback = redéployer un tag d'image antérieur déjà présent sur GHCR
(onglet "Packages" du dépôt), en relançant manuellement `docker/deploy/ssh-deploy.sh`
avec `BACKEND_IMAGE` pointant vers ce tag. Jamais de rollback automatique de migration -
toute migration destructive doit être scindée en plusieurs déploiements (ajout →
migration de données → bascule du code → suppression dans un déploiement ultérieur).

## Vérification avant le tout premier déploiement réel

- [ ] `infisical run --projectId=<id> --env=staging -- docker compose -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.prod.traefik.yml config` valide sans erreur sur le serveur lui-même.
- [ ] Traefik installé, démarré, réseau `traefik-public` créé ; DNS du domaine de cet environnement déjà propagé.
- [ ] `docker login ghcr.io` déjà fait sur le serveur.
- [ ] Secrets des deux environnements GitHub renseignés, "Required reviewers" actif sur `production`.
- [ ] Migrations existantes revues une dernière fois (additives uniquement).
