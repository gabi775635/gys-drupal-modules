# Gestion Documentaire

Module Drupal personnalisé (GYS) fournissant une **GED** (gestion électronique de documents) construite sur les entités natives `taxonomy_term` (dossiers), `media` (documents) et `file`, avec une couche de **droits par rôle** propre au module.

- **Machine name** : `gestion_documentaire`
- **Type** : module custom
- **Compatibilité** : Drupal `^9`
- **Package** : Custom

Le module fonctionne en binôme avec **`entity_revision`**, qui historise les
modifications dans le `revision_log_message` des entités (§9).

---

## 1. Fonctionnalités

| Domaine | Description |
|---|---|
| Arborescence documentaire | Navigation hiérarchique dans les dossiers (termes de taxonomie) et leurs documents (médias). |
| Dossiers | Création / modification / suppression de dossiers, avec libellé FR + clé technique EN. |
| Documents | Upload, versionnement, remplacement de fichier, typage, langues et références produit. |
| Droits fins | Table `gestion_documentaire_role` : `can_view`, `can_edit`, `can_delete`, `can_create`, `can_edit_role` par rôle et par ressource, hérités le long de l'arborescence. |
| Traçabilité des accès | Table `gestion_documentaire_access_log` : consultation et téléchargement des médias. |
| Historique | Consultation des révisions, à partir des `revision_log_message` produits par `entity_revision`. |
| Recherche | Recherche plein texte sur les dossiers et documents. |
| Résolution d'URL | URLs « parlantes » résolues vers l'entité réelle (voir §6). |
| API JSON | Endpoints AJAX de validation et d'enrichissement produit (voir §8). |

---

## 2. Environnements et chemins

### 2.1 Emplacement des modules

| Environnement | Chemin |
|---|---|
| Planet Extranet (référence) | `/home/data1user/soudure/www/extranet/modules/gestion_documentaire/` |
| | `/home/data1user/soudure/www/extranet/modules/entity_revision/` |
| Planet myGys (MAJ manuelle) | `/home/data1user/soudure/www/myGys/modules/gestion_documentaire/` |
| | `/home/data1user/soudure/www/myGys/modules/entity_revision/` |
| Réplique Extranet | `/home/soudure/www/extranet/modules/gestion_documentaire/` |
| | `/home/soudure/www/extranet/modules/entity_revision/` |

**Planet Extranet est la référence.** Les deux autres environnements en sont des copies.

| Site | Import | Débug | Affichage |
|---|---|---|---|
| Planet Extranet | Complet | Fonctionnel | Fonctionnel |
| Planet myGys | Partiel (3 types de doc) | À remplacer par le module (cf. procédure) | Fonctionnel |
| Réplique Extranet | Complet | Impossible dès que l'affichage des médias est concerné | À débuguer (problème réplique) |

### 2.2 Portage vers myGys

Copier le module depuis Planet Extranet, puis appliquer deux substitutions sur
l'ensemble des fichiers (`find` + `sed`) depuis
`/home/data1user/soudure/www/myGys/gestion_documentaire/` :

```
drupal9  →  drupal9_2
extranet →  myGys
```

La première remplace le nom de la connexion BDD, la seconde le préfixe d'URL.

### 2.3 Stockage des fichiers

Les fichiers ne restent pas dans le répertoire d'upload par défaut : à
l'enregistrement, ils sont déplacés dans une arborescence qui reproduit celle des
dossiers.

```
# Documents publics
/home/data1user/soudure/www/extranet/sites/default/files/gestion_documentaire/<chemin/du/dossier>/

# Documents privés
/home/data1user/soudure/www/extranet/sites/default/private/gestion_documentaire/<chemin/du/dossier>/
```

Le chemin relatif est calculé par une CTE récursive (`taxonomy_path`) qui concatène les
noms des dossiers parents. Le répertoire est créé automatiquement
(`prepareDirectory`) et le fichier est renommé.

> Le chemin physique est **figé à la création** du document : renommer ou déplacer un
> dossier ne déplace pas les fichiers déjà stockés.

---

## 3. Setup base de données

### 3.1 `gestion_documentaire_role`

Table des droits, **à créer à la main** (elle n'est pas gérée par `hook_install()`) :

```sql
CREATE TABLE `gestion_documentaire_role` (
  `id`            int NOT NULL AUTO_INCREMENT,
  `id_char`       varchar(50)  COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role_libelle`  varchar(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_libelle`  varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `can_view`      tinyint(1) DEFAULT '1',
  `can_edit`      tinyint(1) DEFAULT '1',
  `can_delete`    tinyint(1) DEFAULT '1',
  `created_at`    datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `can_edit_role` tinyint(1) DEFAULT '0',
  `can_create`    tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

Les droits peuvent être attribués à un **rôle** (`role_libelle`) ou à un **utilisateur
nommé** (`user_libelle` => a implémenter dans accessMédia).

Conventions de nommage des identifiants :

| Forme | Où | Signification |
|---|---|---|
| `taxonomy_<tid>` | table + URL | Dossier |
| `media_<mid>` | table | Document, toutes versions confondues |
| `media_<mid>_<version>` | URL uniquement | Document, version précise |
| `taxonomy/taxonomy_<tid>` | colonne `id_char` | Chemin qualifié du dossier |

Le suffixe de version n'apparaît que dans les URL — **les droits portent sur le
document, pas sur une de ses versions**.

Règle métier appliquée par les formulaires : `can_edit_role = 1` force `can_edit = 1`.

Les droits sont **hérités** le long de l'arborescence : le calcul remonte les parents
jusqu'au premier dossier qui porte une règle pour le rôle courant.

### 3.2 `gestion_documentaire_access_log`

Journal des accès aux médias :

```sql
CREATE TABLE `gestion_documentaire_access_log` (
  `id`        int unsigned NOT NULL AUTO_INCREMENT,
  `media_id`  int unsigned NOT NULL,
  `tiers_id`  varchar(10) NOT NULL DEFAULT '',
  `timestamp` int unsigned NOT NULL DEFAULT '0',
  `action`    varchar(16) NOT NULL DEFAULT 'view',
  `status`    varchar(16) NOT NULL DEFAULT 'success',
  `message`   text,
  PRIMARY KEY (`id`),
  KEY `idx_media_id`  (`media_id`),
  KEY `idx_timestamp` (`timestamp`),
  KEY `idx_tiers_id`  (`tiers_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
```

### 3.3 Prérequis serveur

> Le module utilise massivement des **CTE récursives** (`WITH RECURSIVE`) pour
> parcourir l'arborescence : calcul du chemin d'un dossier, suppression en cascade,
> héritage des droits, recherche.
>
> Cela impose **MySQL 8.0+** ou **MariaDB 10.2.2+**.

### 3.4 Bases externes

| Connexion | Contenu | Usage |
|---|---|---|
| `gys` | tables `produits`, `categories` | Enrichissement des références produit (§8) |
| `drupal9` | base Drupal principale | Requêtes SQL directes des contrôleurs (`drupal9_2` sur myGys) |

---

## 4. Configuration Drupal préalable

### 4.1 Vocabulaire

Un seul vocabulaire, nom machine **`dossiers`**, codé en dur dans les formulaires
(le sélecteur « Vocabulary » d'`AddTaxonomyForm` est désactivé et forcé sur cette valeur).

Gestion des champs :
`/extranet/fr/admin/structure/taxonomy/manage/dossiers/overview/fields`

Les révisions doivent être activées, sinon l'historique reste vide.

### 4.2 Type de média

Bundle **`document`**, basé sur la source *File* de Drupal core.

Gestion des champs :
`/extranet/fr/admin/structure/media/manage/document/fields`

### 4.3 Champs du média `document`

| Champ | Type | Rôle |
|---|---|---|
| `field_media_document` | File | Fichier source (fourni par le type de média) |
| `field_media_filename` | Texte | **Nom saisi par l'utilisateur** (≠ nom réel du média) |
| `field_taxonomie_associee` | Référence → `taxonomy_term` (`dossiers`) | Dossier parent |
| `field_media_type` | Texte | Type de document (notice, datasheet…) — sert aussi de segment d'URL |
| `field_media_lang` | Texte | Codes langue, séparés par des virgules |
| `field_media_reference` | Texte | Références produit, séparées par des virgules |
| `field_media_version` | Texte | Numéro de version |
| `field_media_hash` | Texte (64 car.) | Empreinte SHA-256, détection de doublons |
| `field_media_group_id` | Entier | Regroupe les versions successives d'un même document |
| `field_created_by` | Référence → `user` | **Créé par `hook_install()`** |
| `field_updated_by` | Référence → `user` | **Créé par `hook_install()`** |

> **Deux noms coexistent.** `field_media_filename` contient le nom saisi par
> l'utilisateur (`nouveau_type`), alors que le nom réel de l'entité média est ce nom
> concaténé à l'identifiant et à la version (`nouveau_type_12345_1.pdf`). C'est une
> source de confusion récurrente au débug.

### 4.4 Champs de `taxonomy_term`

| Champ | Type | Rôle |
|---|---|---|
| `field_user_reference` | Référence → `user` | Utilisateur rattaché au dossier |
| `field_created_by` | Référence → `user` | **Créé par `hook_install()`** |
| `field_updated_by` | Référence → `user` | **Créé par `hook_install()`** |

### 4.5 Traductions de taxonomies

Toutes les traductions d'un dossier partagent le **même `tid`**. Seuls `name` et
`langcode` changent, dans `taxonomy_term_field_data` et
`taxonomy_term_field_revision`.

```php
$current_langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
$term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($tid);
if ($term->hasTranslation($current_langcode)) {
  $term_name = $term->getTranslation($current_langcode)->getName();
}
```



## 5. Import de documents

L'import se fait toujours en deux temps : **créer le dossier**, puis **importer les
documents**.

### 5.1 Créer le dossier (préalable obligatoire)

Trois possibilités :

| Voie | Adresse / commande |
|---|---|
| Interface Drupal | `/extranet/fr/admin/structure/taxonomy/manage/dossiers/overview` |
| Interface GED | `/extranet/fr/gestion_documentaire/dossier/ajouter/racine` |
| Script | `transfert_taxonomy.php` |

Le script crée les taxonomies listées dans `term.txt` — **n'y mettre que celles à
créer**. Un chemin complet par ligne :

```
racine/document_vitrine/marketing/nouveau_type
```

Depuis `/home/data1user/soudure/www/extranet/` :

```bash
php vendor/bin/drush php:eval "include '/home/data1user/soudure/importExport/gestion_documentaire/transfert_gd/transfert_taxonomy.php';"
```

### 5.2 Les trois voies d'import des documents

#### A. Script de transfert en masse — `transfert_medias.php`

Pour un import portant sur **plusieurs types de documents**.

Script : `/home/data1user/soudure/importExport/gestion_document/transfert_gd/transfert_medias.php`

1. Renseigner les types à importer dans le script :

   ```php
   $taxoParents = [
       'abaque'
   ];
   ```

2. Déposer les fichiers dans :

   ```
   /home/data1user/soudure/importExport/gestion_documentaire/transfert_gd/media
   ```

3. Respecter impérativement l'un des deux formats de nommage :

   ```
   type__nomOuRéférence__lang.pdf
   type__ref__lang__nom.pdf
   ```

   Le séparateur est un **double underscore**.

#### B. Script de transfert mono-type — `transfert_media.php`

Même procédure que ci-dessus, pour un **seul type de document**, avec le type et le
volume passés en arguments :

```bash
transfert_media.php 'type' '1'
```

#### C. Upload en lot depuis l'interface — `/upload`

Pour des fichiers de **moins de 60 Mo**, et à condition que le nommage soit respecté :

```
/extranet/fr/gestion_documentaire/upload
```

C'est la voie à privilégier quand on ne veut pas passer par le serveur.



### 5.3 Correction automatique des langues

Les scripts d'import corrigent les codes langue non normalisés :

| Saisi | Corrigé |
|---|---|
| `JP` | `JA` |
| `CZ` | `CS` |
| `CN` | `ZH` |

**Aucun contrôle n'est fait sur les références.** Les cas particuliers sont trop
nombreux (référence visible uniquement au SAV, produit non mis en ligne, etc.).

### 5.4 Vérifications après un premier import

> Toujours tester avec **un seul fichier** lors d'un premier import, puis vérifier
> dans la GED :
>
> 1. l'accès au document ;
> 2. l'ouverture du formulaire de modification (sans rien modifier) ;
> 3. que le nom affiché est bien le nom utilisateur (`nouveau_type`) et non le nom
>    réel du média (`nouveau_type_12345_1.pdf`).

---

## 6. Résolution des URL

C'est la partie la moins intuitive du module. Les URL publiques manipulent des **noms**
(`/dossier/Notices`) alors que les contrôleurs internes attendent des **identifiants**
(`taxonomy_42`). Deux mécanismes assurent la traduction, et ils ne se comportent pas
pareil.

### 6.1 Sous-requête interne — `RessourceController`

Mécanisme principal. **Ce n'est pas une redirection** : l'URL affichée dans le
navigateur ne change jamais.

```
Navigateur                    Drupal (interne)
──────────                    ────────────────
GET /fr/dossier/Notices
   │
   ├─► RessourceController::view('Notices', 'taxonomy')
   │      │
   │      ├─ résolution du nom → tid = 42
   │      ├─ path interne : /gestion_documentaire/taxonomy/taxonomy_42
   │      │
   │      └─► http_kernel->handle($sub, SUB_REQUEST)
   │             │
   │             └─► RenderRessourceController::content('taxonomy_42')
   │
   └─◄ HTML servi, URL toujours /fr/dossier/Notices
```

Points clés de `createSubRequest()` :

- La sous-requête est construite avec `Request::create()` puis passée au service
  `http_kernel` en mode `SUB_REQUEST`.
- **La session est recopiée explicitement** (`$request->setSession(...)`). Sans ça
  l'utilisateur serait anonyme dans la sous-requête et perdrait tous ses droits.
- La méthode HTTP et le corps POST sont propagés, ce qui permet aux **formulaires** de
  fonctionner à travers la sous-requête.
- Le schéma est forcé en HTTPS et le préfixe de langue réinjecté dans le path.

**En debug** : une erreur levée dans un contrôleur interne remonte avec l'URL publique
dans les logs, pas avec le path réel. Si une page « dossier » plante, tester
directement `/gestion_documentaire/taxonomy/taxonomy_42` pour isoler la cause.

### 6.2 Deux résolveurs nom → ID

`RessourceController` contient **deux** résolveurs qui ne font pas la même chose.

| | `resolveRessource()` | `resolveAndRedirect()` |
|---|---|---|
| Utilisé par | `view()`, `download()` | `update`, `delete`, `add`, `duplicate` |
| Méthode | `loadMultiple()` puis parcours PHP | `entityQuery()` sur `name` |
| Multilingue | langue courante, **puis repli sur `fr`** | aucun repli |
| Performance | charge toutes les entités en mémoire | requête indexée |

### 6.3 Table des actions

`getPathForAction()` mappe l'action de l'URL vers le path interne :

| Action | Path interne |
|---|---|
| `update` | `/gestion_documentaire/update/{taxonomy\|media}/{id_char_version}` |
| `delete` | `/gestion_documentaire/delete/{taxonomy\|media}/{id_char_version}` |
| `add_taxonomy` | `/gestion_documentaire/add/taxonomy/{id_char_version}` |
| `add_media` | `/gestion_documentaire/add/media/{id_char_version}` |
| `duplicate` | `/gestion_documentaire/add/media/{id}/{id}` |

Le type de ressource n'est pas passé en paramètre : il est **déduit du préfixe** de
`id_char_version`.

### 6.4 Redirection HTTP réelle — `RedirectRenderRessourceController`

Second mécanisme : celui-là renvoie une vraie `RedirectResponse` (302), **l'URL change
dans le navigateur**. Il traite les URL de la forme `type/nom/langue[/download]`,
typiquement appelées depuis le site public.

1. Recherche d'un média sur `field_media_type`, `field_media_lang`, et le nom **ou** la
   référence produit.
2. Si aucun média, second essai sur les dossiers.
3. Sinon `NotFoundHttpException`.

> La langue est **forcée à `fr`** dans le path généré, et le préfixe `extranet` est
> recherché en dur parmi les segments de l'URL courante.

### 6.5 Nom du fichier téléchargé

Le nom proposé au téléchargement est reconstruit dynamiquement par
`RenderRessourceController` — il ne correspond ni au nom du média ni à celui du fichier
sur disque.

| Mode de recherche | Nom généré |
|---|---|
| Par nom de média, avec référence | `{type}{ref_clean}{lang}.{ext}` |
| Par nom de média, sans référence | nom du fichier tel quel |
| Par critères (`type__ref__lang`) | `{type_value}{ref_or_name}{lang_value}.{ext}` |
| Par ID média (`media_123_1`) | `media_{mid}.{ext}` |

Les références sont nettoyées avant usage :

```php
$ref_clean = preg_replace('/[^a-zA-Z0-9_-]/', '_', $ref);
```

---

## 7. Gestion des versions

Le versionnement repose sur deux champs : **`field_media_group_id`** et
**`field_media_version`**.

Le `group_id` vaut par défaut l'ID du premier média de la lignée ; s'il est vide, il est
récupéré automatiquement à la création d'une nouvelle version :

```php
$embedded_group_id = !empty($incoming_group_id) ? (int) $incoming_group_id : (int) $media->id();
```

### 7.1 Contraintes

- **Deux versions maximum** par document.
- **Une seule version publiée** à la fois.

La case « Créer une nouvelle version » n'apparaît dans `UpdateMediaForm` que si le
document compte moins de deux versions.

### 7.2 Mécanisme

Toute création de version part du **formulaire de modification** de l'ancienne version.
`UpdateMediaForm` ne crée pas le média lui-même : il construit un `FormState` neuf et
**appelle directement `AddMediaForm::submitForm()`**.

```php
$add_form_state = new FormState();
$add_form_state->setValues([
  'name'            => $form_state->getValue('name'),
  'parent_taxonomy' => $form_state->getValue('parent_taxonomy'),
  'file'            => [$uploaded_fid],
  'version'         => $new_version,
  'is_versioning'   => 1,
  'media_group_id'  => $group_id ?: $current_entity_id,
  // …
]);

$addForm = new AddMediaForm();
$addForm->submitForm($dummy_form, $add_form_state);

// Propager la redirection décidée par AddMediaForm
$redirect = $add_form_state->getRedirect();
if ($redirect instanceof Url) {
  $form_state->setRedirectUrl($redirect);
}
```

La redirection décidée par `AddMediaForm` est ensuite propagée au formulaire appelant.

> Au débug, **loguer `$add_form_state`, pas `$form_state`** : les valeurs posées en dur
> (`version`, `is_versioning`) apparaissent sinon à tort comme `NULL`.

### 7.3 Versions et révisions

Deux notions se superposent :

- **version** = nouveau fichier, nouvelle entité média, liée par `field_media_group_id` ;
- **révision** Drupal (`media_revision`) = changement de métadonnées à l'intérieur d'une
  même version.

---

## 8. Saisie des références et des langues

Toute la logique est centralisée dans **`RefLangForm`**, réutilisé à l'identique par
`AddMediaForm` et `UpdateMediaForm`.

Les deux saisies ne diffèrent que par l'habillage graphique ; sur le fond, **seules les
langues sont obligatoires**, quel que soit le type de document.

Le mécanisme repose sur trois zones :

1. **zone de saisie** — champ texte + bouton `+` (et, pour les références, un bouton
   « Search by category ») ;
2. **zone d'affichage** — tableau des valeurs ajoutées, avec suppression ligne à ligne ;
3. **zone de formatage** — champ caché (`ref_js_data` / `lang_js_data`) contenant la
   liste sérialisée `réf1,réf2,…`.

C'est ce champ caché qui est lu au submit. La sauvegarde se fait dans
`RefLangForm::submitForm()`.

---

## 9. Endpoints AJAX

Tous préfixés par `/extranet/gestion_documentaire/` (le préfixe `extranet` est détecté
dynamiquement dans le chemin) :

| Endpoint | Méthode | Rôle |
|---|---|---|
| `getData?reference=…&type=…` | GET | Désignations produit depuis la base `gys`. |
| `getInformationProduct` | GET | Informations complémentaires produit. |
| `validateReferenceNumbers?ref=…` | GET | Vérifie l'existence des références saisies. |
| `validateLanguages?lang=…` | GET | Vérifie les codes langue saisis. |
| `checkDocumentExistence` | POST | Détecte un document de même nom. |
| `checkFileHashDuplicate` | POST | Détecte un fichier identique via son hash. |

Ces URLs sont exposées au JS via `drupalSettings.gestion_documentaire.getDataUrl`.

---

## 10. Contrat avec `entity_revision`

Drupal ne journalise nativement que l'ID de l'utilisateur ayant créé une révision, sans
historiser le contenu des modifications. `entity_revision` comble ce manque en
remplissant `revision_log_message` avec un **JSON structuré**, que les contrôleurs
d'historique désérialisent.

Champs suivis : nom FR, nom EN, taxonomie parente, type, référence, langue, rôles
ajoutés / modifiés / supprimés, et auteur de la modification.

```json
{
    "media": {
        "nom":        { "ancien": "", "nouveau": "datasheet_86334.pdf" },
        "parent":     { "ancien": "", "nouveau": "datasheet" },
        "references": "087231",
        "lang":       "sk",
        "type":       "datasheet",
        "hash":       "67a6553d7ea62e9b547bd34e418699bbe9e39cf28311f0986a4bc094eecb6769"
    },
    "roles_update": [],
    "roles_insert": [],
    "roles_delete": []
}
```

La clé racine vaut `taxonomy` ou `media` selon l'entité.

> Les deux modules sont couplés par ce format JSON. Le modifier d'un côté casse
> l'affichage de l'historique de l'autre — d'où le versionnement conjoint des deux.

---

## 11. Structure du module

```
gestion_documentaire/
├── css/
│   ├── global.css
│   ├── simple-nav-block.css
│   ├── stats.css
│   └── tableData.css
├── gestion_documentaire.info.yml
├── gestion_documentaire.install        # crée field_created_by / field_updated_by
├── gestion_documentaire.libraries.yml
├── gestion_documentaire.module         # hook_theme(), hook_page_attachments()
├── gestion_documentaire.routing.yml
├── js/
│   ├── home-block.js
│   ├── simple-nav-block.js
│   ├── stats.js
│   ├── tableData.js                    # tableaux dynamiques, validations AJAX
│   └── upload_progress.js              # barre de progression d'upload
├── src/
│   ├── Controller/
│   │   ├── AccessMediaController.php           # contrôle d'accès aux fichiers
│   │   ├── AccessMediaControllerv2.php
│   │   ├── ActionRessourceController.php       # update / delete taxonomy & media
│   │   ├── AllRecentChangesController.php      # historique global
│   │   ├── ApiController.php
│   │   ├── GetDataController.php               # endpoints JSON
│   │   ├── HistoryRenderController.php         # rendu d'une révision
│   │   ├── HomePageController.php              # page d'accueil de la GED
│   │   ├── ProduitController.php               # documents d'une référence produit
│   │   ├── RecentChangesController.php         # historique d'une ressource
│   │   ├── RedirectMenuController.php          # liste hiérarchique par rôle
│   │   ├── RedirectRenderRessourceController.php  # redirection 302 (§6.4)
│   │   ├── RenderRessourceController.php       # rendu / téléchargement
│   │   ├── RessourceController.php             # résolution nom → ID (§6.1)
│   │   ├── SearchController.php                # recherche
│   │   ├── SimpleNavController.php
│   │   └── StatsController.php
│   ├── Form/
│   │   ├── AddMediaForm.php
│   │   ├── AddTaxonomyForm.php
│   │   ├── PublishMediaForm.php
│   │   ├── RefLangForm.php                     # références / langues (§8)
│   │   ├── TestUploadForm.php                  # upload en lot (§5.2 C)
│   │   ├── UpdateMediaForm.php
│   │   └── UpdateTaxonomyForm.php
│   └── Plugin/Block/
│       ├── DashboardBlock.php
│       ├── HomeBlock.php
│       └── SimpleNavBlock.php
└── templates/
    ├── dashboard-template.html.twig
    ├── gestion-documentaire-stats.html.twig
    ├── home-template.html.twig
    └── simple-nav-block.html.twig
```

Fichiers de configuration YAML :

| Fichier | Contenu |
|---|---|
| `.info.yml` | Nom du module, version Drupal requise, dépendances |
| `.libraries.yml` | JS / CSS / CDN |
| `.routing.yml` | Routes |
| `.install` | Manipulations à l'installation |
| `.module` | Hooks propres au module, variables passées aux templates |

---

## 12. Dépendances

- `core/jquery`, `core/drupal`, `core/drupalSettings` (librairie `tableData`)
- Module custom **`entity_revision`**

---

## 13. Documentation

- `O:\Développement\DocumentationTechnique\Site Web\Gestion Documentaire\Doc gestion.docx`