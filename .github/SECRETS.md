# GitHub Secrets à configurer

Aller dans : **Settings > Secrets and variables > Actions > New repository secret**

## SonarCloud
| Secret | Valeur | Où trouver |
|--------|--------|------------|
| `SONAR_TOKEN` | Token d'auth SonarCloud | https://sonarcloud.io > My Account > Security > Generate Token |

## Déploiement SSH (Staging)
| Secret | Description |
|--------|-------------|
| `SSH_HOST` | IP ou hostname du serveur staging |
| `SSH_USER` | Utilisateur SSH (ex: `www-data`, `deploy`) |
| `SSH_PRIVATE_KEY` | Clé SSH privée (copier le contenu de `~/.ssh/id_rsa`) |
| `SSH_PORT` | Port SSH (défaut: 22) |
| `DRUPAL_STAGING_URL` | URL du site staging (ex: `https://staging.gys.fr`) |
| `DRUPAL_MODULES_PATH` | Chemin vers le dossier modules sur le serveur |
| `DRUPAL_ROOT` | Chemin racine Drupal sur le serveur |

## Déploiement SSH (Production)
| Secret | Description |
|--------|-------------|
| `SSH_HOST_PROD` | IP ou hostname du serveur production |
| `SSH_USER_PROD` | Utilisateur SSH production |
| `SSH_PRIVATE_KEY_PROD` | Clé SSH privée production |
| `SSH_PORT_PROD` | Port SSH production |
| `DRUPAL_PROD_URL` | URL de la production (ex: `https://www.gys.fr`) |
| `DRUPAL_MODULES_PATH_PROD` | Chemin modules sur serveur prod |
| `DRUPAL_ROOT_PROD` | Chemin racine Drupal prod |

## Tests E2E (optionnel)
| Secret | Description |
|--------|-------------|
| `DRUPAL_TEST_USER` | Login utilisateur de test |
| `DRUPAL_TEST_PASSWORD` | Mot de passe utilisateur de test |

## Note sur GITHUB_TOKEN
Le secret `GITHUB_TOKEN` est **automatiquement fourni** par GitHub Actions - pas besoin de le créer.
