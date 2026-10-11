# GYS - Modules Drupal | CI/CD Pipeline

> Modules Drupal custom pour l'outil de gestion documentaire GYS  
> Développé par Gabriel Domergue — Alternant Développeur Fullstack

## Modules

| Module | Description |
|--------|-------------|
| `gestion_documentaire` | Module principal - gestion des ressources documentaires (17 controllers, 7 forms, 3 blocks) |
| `entity_revision` | Module utilitaire - liaison utilisateur/révision sur les entités Drupal |

## Pipeline CI/CD

```
Push/PR
  │
  ├─ PHPCS (Drupal Code Standards)
  ├─ PHPStan (Static Analysis)
  ├─ PHPUnit (Unit Tests)
  ├─ Puppeteer E2E (Integration Tests)
  ├─ Security Audit (Composer + npm)
  │
  └─ SonarCloud Analysis
       │
       ├─ develop → Deploy to Staging
       └─ main    → Deploy to Production
```

## Configuration SonarCloud

1. Créer un projet sur [sonarcloud.io](https://sonarcloud.io)
2. Lier votre organisation GitHub
3. Récupérer le `SONAR_TOKEN`
4. L'ajouter dans GitHub Secrets (voir `.github/SECRETS.md`)
5. Mettre à jour `sonar-project.properties` avec votre `sonar.organization`

## Lancer le PHPCS en local

```bash
# Installer Drupal Coder
composer global require drupal/coder squizlabs/php_codesniffer

# Configurer les standards
~/.composer/vendor/bin/phpcs --config-set installed_paths ~/.composer/vendor/drupal/coder/coder_sniffer

# Analyser
~/.composer/vendor/bin/phpcs --standard=Drupal modules/gestion_documentaire/src/

# Correction automatique (attention, modifie les fichiers)
~/.composer/vendor/bin/phpcbf --standard=Drupal modules/gestion_documentaire/src/
```

## Convention de commits

Ce projet suit la convention [Conventional Commits](https://www.conventionalcommits.org/fr/v1.0.0/) :

```
feat: ajout de la fonctionnalité de recherche avancée
fix: correction du bug d'affichage du dashboard
refactor: extraction du service de recherche Elasticsearch
test: ajout des tests Puppeteer pour le formulaire d'upload
ci: amélioration du pipeline de déploiement staging
```

## Stack technique

- **PHP** 8.1 / **Drupal** 9.x
- **Node.js** 18 (E2E Puppeteer)
- **MySQL** (base de données)
- **Elasticsearch** (moteur de recherche documentaire)
- **SonarCloud** (qualité de code)
- **GitHub Actions** (CI/CD)
