<?php

namespace Drupal\gestion_documentaire\Form;

use Drupal\Core\Database\Database;
use Drupal\Core\File\FileSystemInterface;
use Drupal\file\Entity\File;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\media\Entity\Media;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\Url;
use Drupal\gestion_documentaire\Form\RefLangForm;
use Drupal\gestion_documentaire\Controller\GetDataController;
use Drupal\gestion_documentaire\Form\PublishMediaForm;
use Drupal\Component\Utility\Xss;


class AddMediaForm extends FormBase
{

  public function getFormId()
  {
    return 'gestion_documentaire_add_media_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $id_char_version = NULL, $id_char_version_duplicate = NULL)
  {

    $form['titre'] = [
      '#type' => 'markup',
      '#markup' => Xss::filterAdmin('<h2> 📄 ' . $this->t('Add media') . '</h2>'),
    ];

    $parent_taxonomy = $form_state->getValue('parent_taxonomy') ?? $id_char_version_duplicate;
    $filename = $id_char_version;

    Database::setActiveConnection('drupal9');
    $connection = Database::getConnection();

    $ressource = [];
    $taxonomy_id = NULL;

    if ($id_char_version_duplicate) {
      preg_match('/media_(\d+)/', $id_char_version_duplicate, $matches);
      $mid = isset($matches[1]) ? (int) $matches[1] : 0;
      if ($mid > 0) {
        $query = "
        SELECT
        m.mid AS entity_id,
        m.name,
        mrr.field_media_reference_value AS reference,
        mrl.field_media_lang_value AS lang,
        mrt.field_media_type_value AS type,
        link.field_taxonomie_associee_target_id AS parent_taxonomy_id,
        ttfd.name as parent_taxonomy,
        mrg.field_media_group_id_value AS media_group_id
        FROM media_field_data m
        LEFT JOIN media__field_media_reference mrr ON m.mid = mrr.entity_id AND mrr.deleted = 0
        LEFT JOIN media__field_media_lang mrl      ON m.mid = mrl.entity_id AND mrl.deleted = 0
        LEFT JOIN media__field_media_type mrt      ON m.mid = mrt.entity_id AND mrt.deleted = 0
        LEFT JOIN media__field_taxonomie_associee link ON m.mid = link.entity_id AND link.deleted = 0
        LEFT JOIN taxonomy_term_field_data ttfd ON ttfd.tid = link.field_taxonomie_associee_target_id
        LEFT JOIN media__field_media_group_id mrg ON m.mid = mrg.entity_id AND mrg.deleted = 0
        WHERE m.mid = :mid
        LIMIT 1
        ";

        $ressource = $connection->query($query, [':mid' => $mid])->fetchAssoc() ?: [];
      }
    } else if ($id_char_version && strpos($id_char_version, 'taxonomy_') === 0) {
      preg_match('/taxonomy_(\d+)/', $id_char_version, $matches);
      $taxonomy_id = isset($matches[1]) ? (int) $matches[1] : 0;

      if ($taxonomy_id > 0) {
        $ressource['parent_taxonomy_id'] = $taxonomy_id;
        $taxonomy_name = $connection->query("
        SELECT name
        FROM taxonomy_term_field_data
        WHERE tid = :tid
        ORDER BY CASE WHEN langcode = 'fr' THEN 1 ELSE 2 END
        LIMIT 1
        ", [':tid' => $taxonomy_id])->fetchField();

        $ressource['type'] = $taxonomy_name ?: '';
      }
    }

    $form['home_page'] = [
      '#type' => 'markup',
      '#markup' => Xss::filterAdmin('<div class="form-item">' .
        \Drupal\Core\Link::fromTextAndUrl(
          $this->t('Leave form'),
          Url::fromUserInput('/gestion_documentaire/dossier/' . $ressource['type'])
        )->toString() .
        '</div>'),
    ];

    $ref  = $form_state->getValue('ref_js_data') ?? ($ressource['reference'] ?? '');
    $lang = $form_state->getValue('lang_js_data') ?? ($ressource['lang'] ?? '');
    $type = $form_state->hasValue('type')
      ? $form_state->getValue('type')
      : ($ressource['type'] ?? '');

    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Media name')
    ];

    $current_langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $account = \Drupal::currentUser();
    $user_roles = $account->getRoles();
    $is_admin = in_array('admin_gestiondocumentaire', $user_roles);

    $connection = Database::getConnection();

    $terms = \Drupal::entityTypeManager()
      ->getStorage('taxonomy_term')
      ->loadTree('dossiers');

    $options = [];

    if ($is_admin) {
      foreach ($terms as $term) {
        if ($term->name === 'racine' || $term->tid == 1) {
          continue;
        }

        $term_entity = \Drupal\taxonomy\Entity\Term::load($term->tid);
        if ($term_entity && $term_entity->hasTranslation($current_langcode)) {
          $name = $term_entity->getTranslation($current_langcode)->getName();
        } else {
          $name = $term->name;
        }

        $options[$term->tid] = $name;
      }
    } else {
      $accessible_tids = [];
      $placeholders = [];
      $bindings = [];

      foreach ($user_roles as $index => $role) {
        $ph = ':role' . $index;
        $placeholders[] = $ph;
        $bindings[$ph] = $role;
      }

      if (!empty($placeholders)) {
        $query = "
          SELECT DISTINCT
          CAST(SUBSTRING_INDEX(gdr.id_char, '_', -1) AS UNSIGNED) AS tid
          FROM gestion_documentaire_role gdr
          WHERE gdr.id_char LIKE 'taxonomy_%'
          AND gdr.role_libelle IN (" . implode(',', $placeholders) . ")
          AND (gdr.can_edit = 1 OR gdr.can_edit_role = 1)
          ";

        $results = $connection->query($query, $bindings)->fetchAll();

        $direct_tids = [];
        foreach ($results as $row) {
          $direct_tids[] = $row->tid;
          $accessible_tids[] = $row->tid;
        }

        if (!empty($direct_tids)) {
          $placeholders_desc = implode(',', array_fill(0, count($direct_tids), '?'));

          $descendants_query = "
            WITH RECURSIVE taxonomy_descendants AS (
              SELECT t.tid, t.name, 0 AS level
              FROM taxonomy_term_field_data t
              WHERE t.tid IN ($placeholders_desc)
              UNION ALL
              SELECT child.tid, child.name, td.level + 1
              FROM taxonomy_descendants td
              INNER JOIN taxonomy_term__parent p ON p.parent_target_id = td.tid
              INNER JOIN taxonomy_term_field_data child ON child.tid = p.entity_id
              WHERE td.level < 20
              )
              SELECT DISTINCT tid FROM taxonomy_descendants
              ";

          $descendant_results = $connection->query($descendants_query, $direct_tids)->fetchAll();

          foreach ($descendant_results as $row) {
            if (!in_array($row->tid, $accessible_tids)) {
              $accessible_tids[] = $row->tid;
            }
          }
        }
      }

      foreach ($terms as $term) {
        if ($term->name === 'racine' || $term->tid == 1) {
          continue;
        }

        if (in_array($term->tid, $accessible_tids)) {
          $term_entity = \Drupal\taxonomy\Entity\Term::load($term->tid);
          if ($term_entity && $term_entity->hasTranslation($current_langcode)) {
            $name = $term_entity->getTranslation($current_langcode)->getName();
          } else {
            $name = $term->name;
          }

          $options[$term->tid] = $name;
        }
      }
    }

    asort($options, SORT_NATURAL | SORT_FLAG_CASE);

    $default_parent = $ressource['parent_taxonomy_id'] ?? null;

    if ($taxonomy_id == 1) {
      $default_parent = NULL;
    } else if (empty($default_parent) && !empty($options)) {
      $default_parent = array_key_first($options);
    }

    $form['parent_taxonomy'] = [
      '#type' => 'select',
      '#title' => $this->t('Dossier parent'),
      '#options' => $options,
      '#required' => TRUE,
      '#default_value' => $default_parent,
      '#empty_option' => ($taxonomy_id == 1) ? $this->t('- Sélectionner une valeur -') : NULL,
      '#ajax' => [
        'callback' => '::updateTypeOptions',
        'wrapper' => 'type-wrapper',
        'event' => 'change',
      ],
    ];

    $form['type_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'type-wrapper'],
      '#access' => false,
    ];

    $selected_parent = $form_state->getValue('parent_taxonomy') ?? $ressource['parent_taxonomy_id'];

    $types_query = "
        SELECT DISTINCT mrt.field_media_type_value AS type
        FROM media_revision mr
        LEFT JOIN media__field_media_type mrt ON mr.vid = mrt.revision_id
        LEFT JOIN media__field_taxonomie_associee link ON link.revision_id = mr.vid
        WHERE mrt.field_media_type_value IS NOT NULL
        AND link.field_taxonomie_associee_target_id = :parent_id
        ORDER BY mrt.field_media_type_value ASC
        ";

    $result = $connection->query($types_query, [':parent_id' => $selected_parent])->fetchAll();

    $type_options = [];
    foreach ($result as $row) {
      $type_options[$row->type] = $row->type;
    }

    if (empty($type_options)) {
      $parent_name = '';

      $parent_query = "
          SELECT name
          FROM taxonomy_term_field_data
          WHERE tid = :parent_id
          ORDER BY
          CASE
          WHEN langcode = 'fr' THEN 1
          ELSE 2
          END
          LIMIT 1
          ";

      $parent_result = $connection->query($parent_query, [
        ':parent_id' => $selected_parent,
      ])->fetchObject();

      if ($parent_result) {
        $parent_name = $parent_result->name;
        $type_options[$parent_name] = $parent_name;
      }

      if (empty($type_options)) {
        $all_types = $connection->query("
              SELECT DISTINCT mrt.field_media_type_value AS type
              FROM media_revision mr
              LEFT JOIN media__field_media_type mrt ON mr.vid = mrt.revision_id
              WHERE mrt.field_media_type_value IS NOT NULL
              ORDER BY mrt.field_media_type_value ASC
              ")->fetchAll();

        foreach ($all_types as $row) {
          $type_options[$row->type] = $row->type;
        }
      }
    }

    $form['type_wrapper']['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Document type'),
      '#options' => $type_options,
      '#default_value' => $ressource['type'] ?? key($type_options),
    ];

    $form['file'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Upload document'),
      '#upload_location' => 'public://gestion_documentaire/',
      '#upload_validators' => [
        'file_validate_extensions' => ['pdf doc docx xls xlsx ppt pptx'],
        'file_validate_size' => [40 * 1024 * 1024],
      ],
      '#required' => TRUE,
      '#attributes' => [
        'data-check-hash' => 'true',
      ],
    ];

    $form['thumbnail_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Thumbnail'),
    ];

    $form['thumbnail_section']['thumbnail_regenerate'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Generate thumbnail'),
      '#description' => $this->t('If check, thumbnail will be regenerated.'),
      '#default_value' => $has_thumbnail ? 1 : 0,
      '#weight' => 1,
    ];

    $all_roles_raw = \Drupal::database()->select('config', 'c')
      ->fields('c', ['name'])
      ->condition('name', '%user.role.%', 'LIKE')
      ->execute()
      ->fetchAll();

    $all_roles = [];
    foreach ($all_roles_raw as $role_obj) {
      $role_name = str_replace('user.role.', '', $role_obj->name);
      $all_roles[$role_name] = $role_name;
    }

    // Vérifier si l'utilisateur peut gérer les rôles
    $can_edit_role = $is_admin;

    if ($can_edit_role) {
      $form['add_role_fieldset'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Add a role to this resource'),
      ];

      $form['add_role_fieldset']['new_role'] = [
        '#type' => 'select',
        '#title' => $this->t('Select role'),
        '#options' => $all_roles,
        '#empty_option' => '- ' . $this->t('Choose a role') . ' -',
        '#default_value' => '',
      ];

      $form['add_role_fieldset']['can_view_new'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Can see'),
      ];

      $form['add_role_fieldset']['can_edit_new'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Can modify'),
      ];

      $form['add_role_fieldset']['can_delete_new'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Can delete'),
      ];

      $form['add_role_fieldset']['can_edit_role_new'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Can edit role'),
      ];
    }

    $refLangForm = new RefLangForm();
    $form = $refLangForm->buildRefLangFields($form, 0, $ressource);

    $publishForm = new PublishMediaForm();
    $form = $publishForm->buildPublishField($form, true);

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create'),
      '#weight' => 2,
    ];

    $version = $form_state->getValue('version') ?? 1;

    $form['version'] = [
      '#type' => 'hidden',
      '#default_value' => $version,
    ];

    // Passer le media_group_id source pour le versionning
    $form['media_group_id'] = [
      '#type' => 'hidden',
      '#default_value' => $ressource['media_group_id'] ?? $form_state->getValue('media_group_id') ?? '',
    ];

    $form['#attached']['drupalSettings']['gestion_documentaire']['ressource'] = [
      'ref' =>  $ref,
      'type' => $type,
      'lang' => $lang,
    ];

    $form['#attached']['drupalSettings']['gestion_documentaire']['getDataUrl'] =
      Url::fromRoute('gestion_documentaire.getData')->toString();

    $form['#cache'] = ['max-age' => 0];
    $form['#attached']['library'][] = 'gestion_documentaire/tableData';

    return $form;
  }

  public function preSubmitValidate(array &$form, FormStateInterface $form_state)
  {
    $name = $form_state->getValue('name');
    $type = $form_state->getValue('type');
    $id_char_version = "";

    Database::setActiveConnection('drupal9');
    $connection = Database::getConnection();

    $query = "
          SELECT 1
          FROM media_field_data m
          INNER JOIN media_revision mr ON m.mid = mr.mid AND m.vid = mr.vid
          INNER JOIN media__field_media_type mrt ON mr.vid = mrt.revision_id
          WHERE m.name = :name
          AND mrt.field_media_type_value = :type
          AND CONCAT('media_', m.mid) != :id_char_version
          LIMIT 1
          ";
    $exist = $connection->query($query, [
      ':name' => $name,
      ':type' => $type,
      ':id_char_version' => $id_char_version
    ])->fetchField();

    if ($exist) {
      $form_state->setErrorByName('name', $this->t('Ce nom existe déjà.'));
    }
  }

  public function updateTypeOptions(array &$form, FormStateInterface $form_state)
  {
    return $form['type_wrapper'];
  }

  /**
   * Génère une vignette pour le média.
   */
  private function generateThumbnail(Media $media)
  {
    $logger = \Drupal::logger('gestion_documentaire');
    $file_system = \Drupal::service('file_system');

    $file = $media->get('field_media_document')->entity ?? null;
    if (!$file) {
      $logger->warning('Aucun fichier source pour générer la vignette du média @mid', ['@mid' => $media->id()]);
      return;
    }

    $file_uri = $file->getFileUri();
    $ext = strtolower(pathinfo($file_uri, PATHINFO_EXTENSION));

    // Résoudre le chemin source
    if (str_starts_with($file_uri, 'public://')) {
      $source_path = $file_system->realpath('public://') . '/' . substr($file_uri, strlen('public://'));
    } elseif (str_starts_with($file_uri, 'private://')) {
      $source_path = $file_system->realpath($file_uri);
    } else {
      $source_path = $file_system->realpath($file_uri);
    }

    if (!file_exists($source_path)) {
      $logger->error('Fichier source introuvable: @path', ['@path' => $source_path]);
      return;
    }

    // Vérifier le format supporté
    $supported = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $supported)) {
      return;
    }

    // Préparer le répertoire de destination
    $thumb_dir = 'public://gestion_documentaire/_thumbnails';
    $file_system->prepareDirectory(
      $thumb_dir,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
    );

    $thumb_dir_real = $file_system->realpath($thumb_dir);
    if (!$thumb_dir_real) {
      return;
    }

    $thumb_path = $thumb_dir_real . '/' . $media->id() . '_thumb.jpg';

    // Copie temporaire avec nom ASCII
    $safe_tmp = sys_get_temp_dir() . '/thumb_src_' . $media->id() . '_' . uniqid() . '.' . $ext;
    if (!copy($source_path, $safe_tmp)) {
      return;
    }

    // Générer la vignette avec ImageMagick
    $cmd = sprintf(
      'timeout 30 convert -density 72 %s -thumbnail 300x -background white -flatten -strip -quality 70 %s 2>&1',
      escapeshellarg($safe_tmp . '[0]'),
      escapeshellarg($thumb_path)
    );

    $output = [];
    $code = -1;
    exec($cmd, $output, $code);

    @unlink($safe_tmp);

    if ($code !== 0 || !file_exists($thumb_path)) {
      $logger->error('Échec génération vignette: code=@code', ['@code' => $code]);
      return;
    }

    // Créer l'entité File pour la vignette
    $thumb_uri = 'public://gestion_documentaire/_thumbnails/' . $media->id() . '_thumb.jpg';
    $thumb_file = File::create([
      'uri' => $thumb_uri,
      'uid' => \Drupal::currentUser()->id(),
      'status' => 1,
    ]);
    $thumb_file->save();

    // Insérer directement en base
    $database = \Drupal::database();
    $mid = $media->id();
    $langcode = $media->language()->getId();

    // Supprimer les anciennes entrées
    $database->delete('media__field_media_thumbnail')
      ->condition('entity_id', $mid)
      ->execute();
    $database->delete('media_revision__field_media_thumbnail')
      ->condition('entity_id', $mid)
      ->execute();

    // Insérer dans la table courante
    $database->insert('media__field_media_thumbnail')
      ->fields([
        'bundle' => 'document',
        'deleted' => 0,
        'entity_id' => $mid,
        'revision_id' => $mid,
        'langcode' => $langcode,
        'delta' => 0,
        'field_media_thumbnail_target_id' => $thumb_file->id(),
      ])
      ->execute();

    // Récupérer le dernier vid
    $last_vid = $database->select('media_revision', 'mr')
      ->fields('mr', ['vid'])
      ->condition('mr.mid', $mid)
      ->orderBy('mr.vid', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();

    if ($last_vid) {
      $database->insert('media_revision__field_media_thumbnail')
        ->fields([
          'bundle' => 'document',
          'deleted' => 0,
          'entity_id' => $mid,
          'revision_id' => $last_vid,
          'langcode' => $langcode,
          'delta' => 0,
          'field_media_thumbnail_target_id' => $thumb_file->id(),
        ])
        ->execute();
    }

    \Drupal::messenger()->addStatus($this->t('Vignette générée avec succès.'));
  }

  /**
   * Garantit que le nom de fichier se termine par _{group_id}_{version} avant l'extension.
   */
  private function ensureMediaGroupIdAndVersionInFilename(string $name, int $group_id, int $version): string
  {
    $extension = pathinfo($name, PATHINFO_EXTENSION);
    $basename  = pathinfo($name, PATHINFO_FILENAME);

    $parts = explode('_', $basename);
    $count = count($parts);

    $last       = $count >= 1 ? $parts[$count - 1] : '';
    $beforeLast = $count >= 2 ? $parts[$count - 2] : '';

    $lastIsInt       = ctype_digit($last);
    $beforeLastIsInt = ctype_digit($beforeLast);

    if ($lastIsInt && $beforeLastIsInt && (int) $beforeLast === $group_id && (int) $last === $version) {
      $newBasename = $basename;
    } elseif ($lastIsInt && $beforeLastIsInt) {
      $base = implode('_', array_slice($parts, 0, $count - 2));
      $newBasename = $base . '_' . $group_id . '_' . $version;
    } elseif ($lastIsInt) {
      $base = implode('_', array_slice($parts, 0, $count - 1));
      $newBasename = $base . '_' . $group_id . '_' . $version;
    } else {
      $newBasename = $basename . '_' . $group_id . '_' . $version;
    }

    return $extension ? $newBasename . '.' . $extension : $newBasename;
  }

  public function submitForm(array &$form, FormStateInterface $form_state)
  {

    // \Drupal::logger('gestion_documentaire')->notice('ADD VALUES dest: <pre>@v</pre>', [
    // ]);
    Database::setActiveConnection('drupal9');
    $connection = Database::getConnection();

    $name = $form_state->getValue('name');
    $tid = $form_state->getValue('parent_taxonomy');
    $term = Term::load($tid);
    // Nom FR pour le type (identifiant interne stable)
    if ($term && $term->hasTranslation('fr')) {
      $type_name_fr = $term->getTranslation('fr')->getName();
    } else {
      $type_name_fr = $term ? $term->getName() : '';
    }
    $term_name = $type_name_fr;
    $parent_taxonomy = $term_name;
    $fid = $form_state->getValue('file')[0];

    $version = $form_state->getValue('version') ?? "vide";

    // media_group_id transmis depuis UpdateMediaForm lors d'un versionning
    $incoming_group_id = $form_state->getValue('media_group_id') ?? '';
    $is_versioning     = (bool) ($form_state->getValue('is_versioning') ?? FALSE);

    if (!$is_versioning) {
      $incoming_group_id = '';
    }

    $file = File::load($fid);
    if (!$file) {
      $this->messenger()->addError($this->t('Impossible de charger le fichier.'));
      return;
    }

    // Type = nom FR de la taxonomy parente (identifiant interne stable)
    $type = $term_name;

    $fid = $form_state->getValue('file')[0] ?? NULL;

    $filename_to_check = $form_state->getValue('name');
    if (empty($filename_to_check) && $fid) {
      $file_uploaded = File::load($fid);
      if ($file_uploaded) {
        $filename_to_check = $file_uploaded->getFilename();
      }
    }

    if (empty($filename_to_check)) {
      \Drupal::messenger()->addWarning('Aucun nom de fichier pour vérification.');
      return;
    }

    $main_lang = $form_state->getValue('lang_js_data') ?? 'fr';
    $main_lang_array = array_filter(array_map('trim', explode(',', $main_lang)));
    $main_lang_code = $main_lang_array[0] ?? 'fr';

    if (empty($name)) {
      if ($is_versioning && !empty($incoming_group_id)) {
        $source_name = $connection->query("
                SELECT m.name
                FROM media_field_data m
                INNER JOIN media__field_media_group_id mrg ON m.mid = mrg.entity_id
                WHERE mrg.field_media_group_id_value = :group_id
                ORDER BY m.mid ASC
                LIMIT 1
                ", [':group_id' => $incoming_group_id])->fetchField();

        if ($source_name) {
          $basename = pathinfo($source_name, PATHINFO_FILENAME);
          $parts    = explode('_', $basename);
          $count    = count($parts);

          if ($count >= 2 && ctype_digit($parts[$count - 1]) && ctype_digit($parts[$count - 2])) {
            $parts = array_slice($parts, 0, $count - 2);
          } elseif ($count >= 1 && ctype_digit($parts[$count - 1])) {
            $parts = array_slice($parts, 0, $count - 1);
          }

          $name = implode('_', $parts);
        } else {
          $last_media_id = (int) $connection->query("SELECT MAX(mid) FROM media_field_data")->fetchField();
          $name = (!empty($type) ? $type . "_" : 'media_') . ($last_media_id + 1) . "_1";
        }
      } else {
        $last_media_id = (int) $connection->query("SELECT MAX(mid) FROM media_field_data")->fetchField();
        $name = (!empty($type) ? $type . "_" : 'media_') . ($last_media_id + 1) . "_1";
      }
    }

    // Chemin complet du dossier
    $query_path = "
            WITH RECURSIVE taxonomy_path AS (
              SELECT t.tid, t.name, p.parent_target_id, t.name AS full_path, 0 AS level
              FROM taxonomy_term_field_data t
              LEFT JOIN taxonomy_term__parent p ON p.entity_id = t.tid
              WHERE t.tid = :tid
              UNION ALL
              SELECT parent.tid, parent.name, pp.parent_target_id,
              CONCAT(parent.name, '/', tp.full_path) AS full_path,
              tp.level + 1
              FROM taxonomy_term_field_data parent
              JOIN taxonomy_term__parent pp ON pp.entity_id = parent.tid
              JOIN taxonomy_path tp ON tp.parent_target_id = parent.tid
              )
              SELECT full_path
              FROM taxonomy_path
              ORDER BY level DESC
              LIMIT 1;
              ";
    $full_path = $connection->query($query_path, [':tid' => $tid])->fetchField() ?: '';

    $file_system = \Drupal::service('file_system');

    $prefixe = "private://";
    if (strpos($full_path, 'document_vitrine') == true) {
      $prefixe = "public://";
    }

    $directory = $prefixe . 'gestion_documentaire/' . $full_path;
    $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $old_uri = $file->getFileUri();
    $extension = pathinfo($file->getFilename(), PATHINFO_EXTENSION);

    $next_media_id = (int) $connection->query("SELECT MAX(mid) FROM media_field_data")->fetchField() + 1;

    $effective_group_id = !empty($incoming_group_id) ? (int) $incoming_group_id : $next_media_id;
    $effective_version  = (int) ($version ?: 1);

    $name = $this->ensureMediaGroupIdAndVersionInFilename($name, $effective_group_id, $effective_version);

    $new_filename = $name . '.' . $extension;
    $new_uri = $directory . '/' . $new_filename;

    // Déplacer le fichier
    try {
      $file_system->move($old_uri, $new_uri, FileSystemInterface::EXISTS_REPLACE);
    } catch (\Exception $e) {
      \Drupal::messenger()->addError($this->t('Impossible de déplacer le fichier : @error', ['@error' => $e->getMessage()]));
      return;
    }

    $file->setFilename($new_filename);
    $file->setFileUri($new_uri);
    $file->save();

    // Vérification finale du hash après déplacement
    $final_filepath = $file_system->realpath($file->getFileUri());
    if (file_exists($final_filepath)) {
      $exclude_entity_id = !empty($incoming_group_id) ? (int) $incoming_group_id : 0;
      $check_final = GetDataController::checkHashFromFile($final_filepath, $exclude_entity_id);
      if ($check_final['exists']) {
        $m = $check_final['media'];
        \Drupal::messenger()->addError($this->t(
          'Le fichier "@name" existe déjà — Version : @version | Type : @type | Langue(s) : @lang | Réf(s) : @ref',
          [
            '@name'    => $m['name'],
            '@version' => $m['id_char_version'],
            '@type'    => $m['type']      ?? '—',
            '@lang'    => $m['lang']      ?? '—',
            '@ref'     => $m['reference'] ?? '—',
          ]
        ));
        if (file_exists($final_filepath)) {
          unlink($final_filepath);
        }
        return;
      }
    }
    $published = (bool) $form_state->getValue('published');

    // Créer le média
    $media = Media::create([
      'bundle' => 'document',
      'name' => $new_filename,
      'field_media_document' => ['target_id' => $fid, 'display' => 1],
      'status' => $published ? 1 : 0,
      'field_taxonomie_associee' => ['target_id' => $tid],
      'field_media_type' => $type,
      'field_media_group_id' => $incoming_group_id ?: NULL,
    ]);
    $media->save();

    // Assigner le group_id définitif
    if (empty($incoming_group_id)) {
      $media->set('field_media_group_id', $media->id());

      if ($effective_group_id !== (int) $media->id()) {
        $corrected_name = $this->ensureMediaGroupIdAndVersionInFilename(
          pathinfo($new_filename, PATHINFO_FILENAME),
          (int) $media->id(),
          $effective_version
        ) . '.' . $extension;

        $corrected_uri = $directory . '/' . $corrected_name;
        if (rename($file_system->realpath($new_uri), $file_system->realpath($directory) . '/' . $corrected_name)) {
          $file->setFilename($corrected_name);
          $file->setFileUri($corrected_uri);
          $file->save();
          $media->setName($corrected_name);
          $new_filename = $corrected_name;
        }
      }

      $media->save();
    }

    // Mettre à jour le hash
    $filepath = \Drupal::service('file_system')->realpath($file->getFileUri());
    if (file_exists($filepath) && $media->hasField('field_media_hash')) {
      $hash = hash_file('sha256', $filepath);
      $media->set('field_media_hash', $hash);
      $media->save();
    }

    // ✅ GÉNÉRATION DE LA VIGNETTE
    $regenerate_thumbnail = (bool) $form_state->getValue('thumbnail_regenerate');
    if ($regenerate_thumbnail) {
      $this->generateThumbnail($media);
    }

    // Ajouter les rôles
    $nouveau_role = $form_state->getValue('new_role');
    if (!empty($nouveau_role)) {
      $can_view = $form_state->getValue('can_view_new') ? 1 : 0;
      $can_edit = $form_state->getValue('can_edit_new') ? 1 : 0;
      $can_delete = $form_state->getValue('can_delete_new') ? 1 : 0;
      $can_edit_role = $form_state->getValue('can_edit_role_new') ? 1 : 0;
      if ($can_edit_role) $can_edit = 1;

      \Drupal::database()->insert('gestion_documentaire_role')
        ->fields([
          'id_char_version' => "media_" . $media->id(),
          'role_libelle' => $nouveau_role,
          'can_view' => $can_view,
          'can_edit' => $can_edit,
          'can_delete' => $can_delete,
          'can_edit_role' => $can_edit_role,
        ])->execute();
    }

    // Gestion des langues et références
    $refLangForm = new RefLangForm();
    $ref_js_data = $form_state->getValue('ref_js_data') ?? '';
    $lang_js_data = $form_state->getValue('lang_js_data') ?? '';
    $refLangForm->saveRefLangData($media, $ref_js_data, $lang_js_data);

    $media->set('field_media_version', (string) ($version ?? '1'));

    $embedded_group_id = !empty($incoming_group_id) ? (int) $incoming_group_id : (int) $media->id();

    $media->set('field_media_filename', $this->stripMediaIdAndVersionFromFilename(
      pathinfo($new_filename, PATHINFO_FILENAME),
      $embedded_group_id,
      $effective_version
    ));

    $media->setRevisionUserId(\Drupal::currentUser()->id());
    $media->setRevisionLogMessage(json_encode([
      'media' => [
        'nom'    => ['ancien' => '', 'nouveau' => $new_filename],
        'parent' => ['ancien' => '', 'nouveau' => $parent_taxonomy],
        'type'   => $type,
        'hash'   => hash_file('sha256', $filepath),
      ],
      'roles_update' => [],
      'roles_insert' => [],
      'roles_delete' => [],
      'modif' => 0,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $media->setNewRevision(FALSE);
    $media->save();

    $publishForm   = new PublishMediaForm();
    $published     = (bool) $form_state->getValue('published');
    $media_group   = $media->get('field_media_group_id')->value ?? '';
    $publish_result = $publishForm->applyPublishStatus($media, $published, $media_group, $media->id());

    foreach ($publish_result['deleted'] as $name) {
      \Drupal::messenger()->addStatus($this->t('Ancienne version supprimée : @name', ['@name' => $name]));
    }
    foreach ($publish_result['errors'] as $err) {
      \Drupal::messenger()->addError($err);
    }

    $this->messenger()->addStatus($this->t('Le média "@name" a été créé.', ['@name' => $new_filename]));
    $term_reloaded = Term::load($tid);
    $parent_name   = $term_reloaded ? $term_reloaded->getName() : '';
    $form_state->setRedirect('gestion_documentaire.dossier.view', ['nom' => $parent_name]);
  }


  private function stripMediaIdAndVersionFromFilename(string $name, int $mid, int $version): string
  {
    $basename = pathinfo($name, PATHINFO_FILENAME);

    $parts = explode('_', $basename);
    $count = count($parts);

    $last       = $count >= 1 ? $parts[$count - 1] : '';
    $beforeLast = $count >= 2 ? $parts[$count - 2] : '';

    $lastIsInt       = ctype_digit($last);
    $beforeLastIsInt = ctype_digit($beforeLast);

    if ($lastIsInt && $beforeLastIsInt && (int) $beforeLast === $mid && (int) $last === $version) {
      $basename = implode('_', array_slice($parts, 0, $count - 2));
    } elseif ($lastIsInt && (int) $last === $mid) {
      $basename = implode('_', array_slice($parts, 0, $count - 1));
    }

    return $basename;
  }
}
