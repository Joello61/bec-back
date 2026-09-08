# bec-front

## Sécurité - scan de secrets pre-commit

Ce dépôt utilise un hook Git (`.githooks/pre-commit`) qui scanne les changements stagés avec [gitleaks](https://github.com/gitleaks/gitleaks) avant chaque commit, pour éviter de committer un secret réel (clé API, mot de passe, token...).

- Le hook est activé automatiquement par `composer install`/`composer update` (`git config core.hooksPath .githooks`).
- Installer le binaire `gitleaks` : voir [instructions officielles](https://github.com/gitleaks/gitleaks#installing). Sans lui, le hook affiche un avertissement mais laisse passer le commit - un scan gitleaks reste exécuté en CI et bloquera le merge en cas de fuite détectée.
