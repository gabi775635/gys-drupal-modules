<?php

namespace Drupal\gestion_documentaire\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Database;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\taxonomy\Entity\Term;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\Core\Url;
use Drupal\Core\File\FileSystemInterface;
use Drupal\gestion_documentaire\Controller\RecentChangesController;
use Drupal\gestion_documentaire\Controller\AccessMediaController;
use Drupal\gestion_documentaire\Controller\GetDataController;
use Drupal\gestion_documentaire\Form\RefLangForm;
use Drupal\gestion_documentaire\Form\AddMediaForm;
use Drupal\gestion_documentaire\Form\PublishMediaForm;

use Drupal\Core\Form\FormState;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Component\Utility\Xss;


class UpdateMediaForm extends FormBase
{



  public function getFormId()
  {
    return 'gestion_documentaire_update_media_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $id_char_version = NULL)
  {
    $id_char_version = $id_char_version;


    if (!$id_char_version) {
      $form['#markup'] = $this->t('Aucun ID de ressource fourni.');
      return $form;
    }

    // Connexion à la base
    Database::setActiveConnection();
    $connection = Database::getConnection('drupal9');

    // ===========================================================================
    // REMPLACEMENT REQUÊTE VUE PAR REQUÊTE DIRECTE SUR LES TABLES
    // ===========================================================================


    $current_langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $account = \Drupal::currentUser();
    $user_roles = $account->getRoles();


    // Extraction de l'entity_id depuis id_char_version (format: media_123 ou media_123_1)
    if (strpos($id_char_version, 'media_') !== 0) {
      $form['#markup'] = $this->t('ID de ressource invalide.');
      return $form;
    }

    $parts = explode('_', $id_char_version);
    if (count($parts) < 2) {
      $form['#markup'] = $this->t('ID de ressource invalide.');
      return $form;
    }
    $entity_id = (int) $parts[1];
    $requested_version = isset($parts[2]) ? (int) $parts[2] : null;

    // Requête directe pour récupérer les données du média avec la version spécifique
    $media_query = "
    SELECT
    m.mid AS entity_id,
    m.uid AS uid,
    'media' AS entity_type,
    CONCAT('media_', m.mid) AS id_char,
    CONCAT('media_', m.mid, '_', COALESCE(mrv.field_media_version_value, 1)) AS id_char_version,
    m.name AS name,
    t.name AS parent_taxonomy,
    t.tid AS parent_taxonomy_id,
    FROM_UNIXTIME(m.created) AS created,
    FROM_UNIXTIME(m.changed) AS changed,
    CONCAT(
      '/document/',
      CONCAT('media_', m.mid, '_', COALESCE(mrv.field_media_version_value, 1))
      ) AS path,
      mr.revision_log_message,
      mr.vid AS revision_id,
      mr.revision_user,
      mrt.field_media_type_value AS type,
      mrl.field_media_lang_value AS lang,
      mrr.field_media_reference_value AS reference,
      mrh.field_media_hash_value AS hash,
      COALESCE(mrv.field_media_version_value, 1) AS version,
      mrg.field_media_group_id_value AS media_group_id
      FROM media_field_data m
      LEFT JOIN media__field_taxonomie_associee link ON m.mid = link.entity_id
      LEFT JOIN taxonomy_term_field_data t ON link.field_taxonomie_associee_target_id = t.tid
      LEFT JOIN media_revision mr ON m.mid = mr.mid
      LEFT JOIN media__field_media_type mrt ON m.mid = mrt.entity_id
      LEFT JOIN media__field_media_lang mrl ON m.mid = mrl.entity_id
      LEFT JOIN media__field_media_reference mrr ON m.mid = mrr.entity_id
      LEFT JOIN media__field_media_hash mrh ON m.mid = mrh.entity_id
      LEFT JOIN media__field_media_version mrv ON m.mid = mrv.entity_id
      LEFT JOIN media__field_media_group_id mrg ON m.mid = mrg.entity_id
      WHERE m.mid = :mid
      ";

    if ($requested_version !== null) {
      $media_query .= " AND COALESCE(mrv.field_media_version_value, 1) = :version";
      $params = [':mid' => $entity_id, ':version' => $requested_version];
    } else {
      $params = [':mid' => $entity_id];
    }

    $media_query .= " ORDER BY mr.vid DESC LIMIT 1";

    $ressource = $connection->query($media_query, $params)->fetchAssoc();

    if (!$ressource) {
      $form['#markup'] = $this->t('Ressource introuvable.');
      return $form;
    }

    $form['titre'] = [
      '#type' => 'markup',
      '#markup' => Xss::filterAdmin('<h2> 📄 ' . $this->t('Modify document') . ' : ' . Xss::filter($ressource['name']) . '</h2>'),
    ];

    $form['home_page'] = [
      '#type' => 'markup',
      '#markup' => Xss::filterAdmin('<div class="form-item">' .
        \Drupal\Core\Link::fromTextAndUrl(
          $this->t('Leave form'),
          Url::fromRoute('gestion_documentaire.dossier.view', ['nom' => $ressource['parent_taxonomy']])->setAbsolute(FALSE)
        )->toString() .
        '</div>'),
    ];

    $account = \Drupal::currentUser();
    $permissions = AccessMediaController::getResourcePermissions($account, $id_char_version);
    $can_edit = $permissions['can_edit'];
    $can_edit_role = $permissions['can_edit_role'];
    $can_delete = $permissions['can_delete'];
    $can_view = $permissions['can_view'];

    $form['media_group_id'] = [
      '#type' => 'hidden',
      '#default_value' => $ressource['media_group_id'],
    ];

    // Hidden ID
    $form['id_char_version'] = [
      '#type' => 'hidden',
      '#value' => $ressource['id_char_version'],
    ];

    $media = Media::load($ressource['entity_id']);

    if ($can_edit) {

      $form['name'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Media name'),
        '#default_value' => $media->get('field_media_filename')->value ?? $this->stripMediaIdAndVersionFromFilename(
          $ressource['name'],
          $entity_id,
          (int) ($ressource['version'] ?? 1)
        ),
        '#required' => TRUE,
      ];




      $is_admin = in_array('admin_gestiondocumentaire', $user_roles);

      $connection = Database::getConnection();

      // Récupérer tous les termes de la taxonomie
      $terms = \Drupal::entityTypeManager()
        ->getStorage('taxonomy_term')
        ->loadTree('dossiers');

      $options = [];

      // Si admin, afficher tous les dossiers sauf la racine
      if ($is_admin) {
        foreach ($terms as $term) {
          if ($term->name === 'racine' || $term->tid == 1) {
            continue;
          }

          // Récupérer le nom traduit
          $term_entity = \Drupal\taxonomy\Entity\Term::load($term->tid);
          if ($term_entity && $term_entity->hasTranslation($current_langcode)) {
            $name = $term_entity->getTranslation($current_langcode)->getName();
          } else {
            $name = $term->name;
          }

          $options[$term->tid] = $name;
        }
      } else {
        // Pour les non-admins, récupérer les dossiers avec droits d'édition
        $accessible_tids = [];

        // Récupérer les TID où l'utilisateur a des droits directement (can_edit ou can_edit_role)
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

          // Récupérer TOUS les descendants des dossiers accessibles (récursif)
          if (!empty($direct_tids)) {
            $placeholders_desc = implode(',', array_fill(0, count($direct_tids), '?'));

            $descendants_query = "
                WITH RECURSIVE taxonomy_descendants AS (
                  -- Point de départ : les TID où l'utilisateur a des droits
                  SELECT
                  t.tid,
                  t.name,
                  0 AS level
                  FROM taxonomy_term_field_data t
                  WHERE t.tid IN ($placeholders_desc)

                  UNION ALL

                  -- Récupérer tous les enfants
                  SELECT
                  child.tid,
                  child.name,
                  td.level + 1
                  FROM taxonomy_descendants td
                  INNER JOIN taxonomy_term__parent p ON p.parent_target_id = td.tid
                  INNER JOIN taxonomy_term_field_data child ON child.tid = p.entity_id
                  WHERE td.level < 20  -- Limite de profondeur pour éviter les boucles infinies
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

        // Construire les options avec les TID accessibles
        foreach ($terms as $term) {
          // Exclure la racine
          if ($term->name === 'racine' || $term->tid == 1) {
            continue;
          }

          // Vérifier si ce TID est dans la liste des accessibles
          if (in_array($term->tid, $accessible_tids)) {
            // Récupérer le nom traduit
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

      // Trier par ordre alphabétique
      asort($options, SORT_NATURAL | SORT_FLAG_CASE);

      $default_parent = $ressource['parent_taxonomy_id'] ?? null;

      // Si aucune valeur par défaut n'est définie, prendre la première clé du tableau
      if (empty($default_parent) && !empty($options)) {
        $first_key = array_key_first($options);
        if ($first_key !== null) {
          $default_parent = $first_key;
        }
      }

      // ===========================================================================
      // SELECT DOSSIER PARENT (manquant auparavant : parent_taxonomy n'était jamais
      // ajouté au formulaire, donc $form_state->getValue('parent_taxonomy') = NULL)
      // ===========================================================================
      $form['parent_taxonomy'] = [
        '#type' => 'select',
        '#title' => $this->t('Folder'),
        '#options' => $options,
        '#default_value' => $default_parent,
        '#required' => TRUE,
        '#ajax' => [
          'callback' => '::updateTypeOptions',
          'wrapper' => 'type-wrapper',
          'event' => 'change',
        ],
      ];



      if ($is_admin) {
        $db9 = Database::getConnection('drupal9');

        $current_version = (int) ($ressource['version'] ?? 1);
        $group_id = $ressource['media_group_id'] ?? null;

        // Max des versions du groupe
        $max_version = $current_version;
        if ($group_id) {
          $max = $db9->query("
                SELECT MAX(COALESCE(mrv.field_media_version_value, 1))
                FROM media__field_media_group_id mrg
                LEFT JOIN media__field_media_version mrv ON mrg.entity_id = mrv.entity_id
                WHERE mrg.field_media_group_id_value = :group_id
                ", [':group_id' => $group_id])->fetchField();
          if ($max) {
            $max_version = (int) $max;
          }
        }

        $next_version = $max_version + 1;


        // État de la case (lu au rebuild AJAX)
        $create_new = (bool) $form_state->getValue('create_new_version');
        $displayed_version = $create_new ? $next_version : $current_version;


        $rows = $db9->query("
              SELECT m.mid, m.name, m.status AS status,
              MAX(COALESCE(mrv.field_media_version_value, 1)) AS version
              FROM media__field_media_group_id mrg
              INNER JOIN media_field_data m ON m.mid = mrg.entity_id
              LEFT JOIN media_revision mr ON m.mid = mr.mid
              LEFT JOIN media__field_media_version mrv ON mr.vid = mrv.revision_id
              WHERE mrg.field_media_group_id_value = :group_id
              GROUP BY m.mid, m.name, m.status
              ORDER BY version ASC
              ", [
          ':group_id'    => $ressource['media_group_id']
        ])->fetchAll(\PDO::FETCH_ASSOC);
        // Wrapper rafraîchi par AJAX
        $form['version_wrapper'] = [
          '#type' => 'container',
          '#attributes' => ['id' => 'version-wrapper'],
        ];

        if (count($rows) < 2) {
          $form['version_wrapper']['create_new_version'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Créer une nouvelle version'),
            '#ajax' => [
              'callback' => '::updateVersionDisplay',
              'wrapper' => 'version-wrapper',
              'event' => 'change',
            ],
          ];
        } else {
          $items = '';
          foreach ($rows as $r) {
            $is_current   = ((int) $r['mid'] === (int) $ressource['entity_id']);
            $is_published = !empty($r['status']);
            $label        = $this->t('Version @v', ['@v' => $r['version']]);

            // Construire le suffixe entre parenthèses selon le cas
            $tags = [];
            if ($is_current) {
              $tags[] = $this->t('current version');
            }
            if ($is_published) {
              $tags[] = $this->t('published');
            }
            $suffix = $tags ? ' <em>(' . implode(' ', $tags) . ')</em>' : '';

            if ($is_current) {
              $items .= '<li><strong>' . $label . '</strong> — '
                . htmlspecialchars($r['name']) . $suffix . '</li>';
            } else {
              $url = Url::fromUri('internal:/gestion_documentaire/document/modifier/' . $r['name'])->toString();
              $items .= '<li>' . $label . ' — '
                . '<a href="' . $url . '">' . htmlspecialchars($r['name']) . '</a>' . $suffix . '</li>';
            }
          }

          $help = $this->t("How to keep only one version:")
            . "\n• " . $this->t("You can't have two published versions: publishing a new one deletes the previous.")
            . "\n• " . $this->t("You can delete a version directly from the navigation section.")
            . "\n⚠ " . $this->t("A deleted version cannot be recovered.");

          $form['version_wrapper']['version_notice'] = [
            '#type' => 'markup',
            '#markup' => Xss::filterAdmin(
              '<div class="messages messages--warning">'
                . '<strong>⚠️ ' . $this->t('@nb versions exist for this document.', ['@nb' => count($rows)]) . '</strong>'
                . '<p>' . $this->t("Create new version is not allowed (max 2)")
                . ' <span style="cursor:help;" title="' . htmlspecialchars($help, ENT_QUOTES) . '">ℹ️</span>'
                . '</p>'
                . '<ul>' . $items . '</ul>'
                . '</div>'
            ),
          ];
        }
        if (count($rows) < 2) {
          $form['version_wrapper']['version_display'] = [
            '#type' => 'textfield',
            '#title' => $this->t('Version'),
            '#value' => $displayed_version,
            '#disabled' => TRUE,
          ];
        }
      }

      // Récupérer le parent_taxonomy sélectionné (valeur ajax ou défaut)
      $selected_parent = $form_state->getValue('parent_taxonomy') ?? $ressource['parent_taxonomy_id'];

      // Requête types filtrée selon le parent sélectionné
      $types_query = "
              SELECT DISTINCT mrt.field_media_type_value AS type
              FROM media_field_data m
              LEFT JOIN media__field_media_type mrt ON m.mid = mrt.entity_id
              LEFT JOIN media__field_taxonomie_associee link ON m.mid = link.entity_id
              WHERE mrt.field_media_type_value IS NOT NULL
              AND link.field_taxonomie_associee_target_id = :parent_id
              ORDER BY mrt.field_media_type_value ASC
              ";

      $result = $connection->query($types_query, [':parent_id' => $selected_parent])->fetchAll();

      $type_options = [];
      foreach ($result as $row) {
        $type_options[$row->type] = $row->type;
      }

      // Si aucun résultat pour ce dossier, récupérer le nom FR du parent comme type
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

        // Si toujours rien, fallback sur tous les types
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

      // ===========================================================================
      // WRAPPER TYPE (manquant auparavant : la cible AJAX #type-wrapper n'existait
      // pas comme conteneur, donc updateTypeOptions ne pouvait pas la remplacer)
      // ===========================================================================
      $form['type_wrapper'] = [
        '#type' => 'container',
        '#attributes' => [
          'id' => 'type-wrapper',
          'style' => 'display: none;',
        ],
      ];

      $form['type_wrapper']['type'] = [
        '#type' => 'select',
        '#title' => $this->t('Document type'),
        '#options' => $type_options,
        '#default_value' => $ressource['type'] ?? key($type_options),
      ];

      // Champ pour remplacer le fichier
      $form['file'] = [
        '#type' => 'managed_file',
        '#title' => $this->t('Upload document'),
        '#upload_location' => 'public://gestion_documentaire/',
        '#upload_validators' => [
          'file_validate_extensions' => ['pdf doc docx xls xlsx ppt pptx'],
          'file_validate_size' => [30 * 1024 * 1024],
        ],
        '#required' => false,
        '#attributes' => [
          'data-check-hash' => 'true',
        ],
      ];

      if (!empty($ressource['type']) && !empty($ressource['name'])) {
        $form['media_link'] = [
          '#type' => 'markup',
          '#markup' => Xss::filterAdmin('<div class="form-item">' .
            \Drupal\Core\Link::fromTextAndUrl(
              $this->t('View current document'),
              Url::fromRoute('gestion_documentaire.redirectRenderRessourcev2', [
                'type'   => $ressource['type'],
                'name'   => "media_" . $ressource['entity_id'],
                'lang'   => $ressource['lang'] ?? '',
                'action' => '',
              ])->setAbsolute(FALSE)
            )->toString() .
            '</div>'),
        ];
      }
    }

    // Gestion des rôles
    if ($can_edit_role) {
      $roles_raw = $connection->select('gestion_documentaire_role', 'gdr')
        ->fields('gdr')
        ->condition('id_char_version', $id_char_version)
        ->execute()
        ->fetchAll();

      $all_roles_raw = $connection->select('config', 'c')
        ->fields('c', ['name'])
        ->condition('name', '%user.role.%', 'LIKE')
        ->execute()
        ->fetchAll();

      $all_roles = [];
      foreach ($all_roles_raw as $role_obj) {
        $role_name = str_replace('user.role.', '', $role_obj->name);
        $all_roles[$role_name] = $role_name;
      }

      $form['add_role_fieldset'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Add a new role'),
      ];

      $form['add_role_fieldset']['new_role'] = [
        '#type' => 'select',
        '#title' => $this->t('Select role'),
        '#options' => $all_roles,
        '#empty_option' => $this->t('- Choose role -'),
      ];

      $form['add_role_fieldset']['can_view_new'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Can see'),
      ];

      $form['add_role_fieldset']['can_edit_new'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Can edit'),
      ];

      $form['add_role_fieldset']['can_delete_new'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Can delete'),
      ];

      $form['add_role_fieldset']['can_edit_role_new'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Can edit role'),
      ];

      if (!empty($roles_raw)) {
        $header = [
          'role_libelle' => $this->t('Rôle'),
          'can_view' => $this->t('Peut voir'),
          'can_edit' => $this->t('Peut modifier'),
          'can_delete' => $this->t('Peut supprimer'),
          'can_edit_role' => $this->t('Peut edit permission'),
          'delete_role' => $this->t('Supprimer'),
        ];

        $form['roles_table'] = [
          '#type' => 'table',
          '#header' => $header,
          '#empty' => $this->t('Aucun rôle défini pour cette ressource.'),
        ];

        foreach ($roles_raw as $role_obj) {
          $role = (array) $role_obj;
          $role_id = $role['role_libelle'];
          $form['roles_table'][$role_id]['role_libelle'] = ['#markup' => Xss::filter($role['role_libelle'])];
          $form['roles_table'][$role_id]['can_view'] = [
            '#type' => 'checkbox',
            '#default_value' => $role['can_view'],
            '#title_display' => 'invisible',
          ];
          $form['roles_table'][$role_id]['can_edit'] = [
            '#type' => 'checkbox',
            '#default_value' => $role['can_edit'],
            '#title_display' => 'invisible',
          ];
          $form['roles_table'][$role_id]['can_delete'] = [
            '#type' => 'checkbox',
            '#default_value' => $role['can_delete'],
            '#title_display' => 'invisible',
          ];
          $form['roles_table'][$role_id]['can_edit_role'] = [
            '#type' => 'checkbox',
            '#default_value' => $role['can_edit_role'],
            '#title_display' => 'invisible',
          ];
          $form['roles_table'][$role_id]['delete_role'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Supprimer'),
            '#default_value' => 0,
            '#title_display' => 'invisible',
          ];
        }
      }
    }

    if ($media) {
      $refLangForm = \Drupal::service('class_resolver')
        ->getInstanceFromDefinition(RefLangForm::class);
      $form = $refLangForm->buildRefLangFields($form, $ressource['entity_id'], $ressource);
    }

    $is_published = $media ? (bool) $media->isPublished() : false;
    $publishForm = \Drupal::service('class_resolver')
      ->getInstanceFromDefinition(PublishMediaForm::class);
    $form = $publishForm->buildPublishField($form, $is_published);


    // ===========================================================================
    // SECTION VIGNETTE — wrapper séparé, rafraîchi via AJAX au changement de dossier
    // ===========================================================================
    $form['thumbnail_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'thumbnail-wrapper'],
    ];

    // Taxonomie parente sélectionnée (valeur AJAX si présente, sinon valeur en base)
    $selected_parent_tid = $form_state->getValue('parent_taxonomy') ?: ($ressource['parent_taxonomy_id'] ?? null);
    $selected_parent_name = $ressource['parent_taxonomy'] ?? '';
    if ($selected_parent_tid && ($sel_term = Term::load($selected_parent_tid))) {
      $selected_parent_name = $sel_term->getName();
    }

    if ($this->isThumbnailAllowed($selected_parent_name)) {

      // Vérifier si une vignette existe déjà - directement en base
      $has_thumbnail = FALSE;
      if ($media && $media->hasField('field_media_thumbnail')) {
        $database = \Drupal::database();
        $thumb_fid = $database->select('media__field_media_thumbnail', 'mt')
          ->fields('mt', ['field_media_thumbnail_target_id'])
          ->condition('mt.entity_id', $parts[1])
          ->condition('mt.deleted', 0)
          ->execute()
          ->fetchField();

        if ($thumb_fid) {
          $has_thumbnail = TRUE;
        }
      }

      $form['thumbnail_wrapper']['thumbnail_section'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Thumbnail'),
      ];

      $form['thumbnail_wrapper']['thumbnail_section']['thumbnail_regenerate'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Generate thumbnail'),
        '#description' => $this->t('If checked, thumbnail will be regenerated.'),
        '#default_value' => $has_thumbnail ? 1 : 0,
      ];
    }

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update'),
      '#weight' => 2,
    ];

    $language_manager = \Drupal::service('language_manager');
    $lngcode = $language_manager->getCurrentLanguage()->getId();
    $form['#attached']['drupalSettings']['gestion_documentaire']['ressource'] = [
      'ref' =>  $ressource['reference'],
      'type' => $ressource['type'],
      'lang' => $ressource['lang'],
      'name' => $ressource['name'],
    ];

    $form['#attached']['drupalSettings']['gestion_documentaire']['getDataUrl'] = \Drupal\Core\Url::fromRoute('gestion_documentaire.getData')->toString();
    $form['#attached']['library'][] = 'gestion_documentaire/tableData';

    $controller = new RecentChangesController();
    $rows = $controller->getRecentChangesData($id_char_version, $ressource['entity_type']);

    $form['changesSection'] = [
      '#type' => 'container',
      '#weight' => 3,
    ];

    $form['changesSection']['title'] = [
      '#type' => 'markup',
      '#markup' => Xss::filterAdmin('<br><br><h2>' . $this->t('Resource modification history') . '</h2><br>'),
    ];

    $form['changesSection']['recent_changes'] = [
      '#type' => 'table',
      '#header' => [
        ['data' => $this->t('Date'),           'style' => 'width: 110px'],
        ['data' => $this->t('User'),           'style' => 'width: 120px'],
        ['data' => $this->t('Name'),           'style' => 'width: 150px'],
        ['data' => $this->t('Folder'),         'style' => 'width: 120px'],
        ['data' => $this->t('Role added'),     'style' => 'width: 100px'],
        ['data' => $this->t('Role modified'),  'style' => 'width: 100px'],
        ['data' => $this->t('Role deleted'),   'style' => 'width: 100px'],
        ['data' => $this->t('Language'),       'style' => 'width: 80px'],
        ['data' => $this->t('Reference'),      'style' => 'width: 100px'],
        ['data' => $this->t('Edit'),           'style' => 'width: 40px'],
        ['data' => $this->t('Publish'),        'style' => 'width: 40px'],
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No modification in log'),
    ];

    return $form;
  }

  public function updateTypeOptions(array &$form, FormStateInterface $form_state)
  {
    $response = new AjaxResponse();
    $response->addCommand(new ReplaceCommand('#type-wrapper', $form['type_wrapper']));
    $response->addCommand(new ReplaceCommand('#thumbnail-wrapper', $form['thumbnail_wrapper']));
    return $response;
  }

  public function updateVersionDisplay(array &$form, FormStateInterface $form_state)
  {
    return $form['version_wrapper'];
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

  private function ensureMediaIdAndVersionInFilename(string $name, int $mid, int $version): string
  {
    $extension = pathinfo($name, PATHINFO_EXTENSION);
    $basename  = pathinfo($name, PATHINFO_FILENAME);

    $parts = explode('_', $basename);
    $count = count($parts);

    $last       = $count >= 1 ? $parts[$count - 1] : '';
    $beforeLast = $count >= 2 ? $parts[$count - 2] : '';

    $lastIsInt       = ctype_digit($last);
    $beforeLastIsInt = ctype_digit($beforeLast);

    if ($lastIsInt && $beforeLastIsInt && (int) $beforeLast === $mid && (int) $last === $version) {
      $newBasename = $basename;
    } elseif ($lastIsInt && $beforeLastIsInt) {
      $base = implode('_', array_slice($parts, 0, $count - 2));
      $newBasename = $base . '_' . $mid . '_' . $version;
    } elseif ($lastIsInt) {
      $base = implode('_', array_slice($parts, 0, $count - 1));
      $newBasename = $base . '_' . $mid . '_' . $version;
    } else {
      $newBasename = $basename . '_' . $mid . '_' . $version;
    }

    return $extension ? $newBasename . '.' . $extension : $newBasename;
  }

  /**
   * Supprime la vignette existante.
   */
  private function deleteThumbnail(Media $media)
  {
    $logger = \Drupal::logger('gestion_documentaire');
    $file_system = \Drupal::service('file_system');

    if ($media->hasField('field_media_thumbnail')) {
      $thumb_field = $media->get('field_media_thumbnail');
      if (!$thumb_field->isEmpty()) {
        $old_thumb_file = $thumb_field->entity;
        if ($old_thumb_file) {
          $old_thumb_uri = $old_thumb_file->getFileUri();
          $old_thumb_path = $file_system->realpath($old_thumb_uri);

          // Supprimer le fichier physique
          if ($old_thumb_path && file_exists($old_thumb_path)) {
            @unlink($old_thumb_path);
            $logger->notice('Vignette supprimée: @path', ['@path' => $old_thumb_path]);
          }

          // Supprimer l'entité File
          $old_thumb_file->delete();
          $logger->notice('Entité File vignette supprimée: fid=@fid', ['@fid' => $old_thumb_file->id()]);
        }

        // Vider le champ en base directement pour éviter les problèmes de révision
        \Drupal::database()->delete('media__field_media_thumbnail')
          ->condition('entity_id', $media->id())
          ->execute();
        \Drupal::database()->delete('media_revision__field_media_thumbnail')
          ->condition('entity_id', $media->id())
          ->execute();
      }
    }
  }

  /**
   * Génère une nouvelle vignette pour le média.
   */
  /**
   * Génère une nouvelle vignette pour le média.
   */
  private function generateThumbnail(Media $media)
  {
    $logger = \Drupal::logger('gestion_documentaire');
    $file_system = \Drupal::service('file_system');

    $file = $media->get('field_media_document')->entity ?? null;
    if (!$file) {
      $logger->warning('Aucun fichier source pour générer la vignette du média @mid', ['@mid' => $media->id()]);
      \Drupal::messenger()->addWarning($this->t('Impossible de générer la vignette : aucun fichier source.'));
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

    // Insérer directement en base - UNIQUEMENT les colonnes qui existent
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
      // Insérer dans la table de révision
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

    // $logger->notice('Vignette générée avec succès pour mid=@mid', ['@mid' => $mid]);
    \Drupal::messenger()->addStatus($this->t('Vignette générée avec succès.'));
  }

  private function getThumbnailAllowedTaxonomies(): array
  {
    return [
      'brochure',
      // 'autre_dossier',
      // 'encore_un_autre',
    ];
  }

  private function isThumbnailAllowed(?string $parentTaxonomy): bool
  {
    $current = strtolower(trim((string) $parentTaxonomy));
    $allowed = array_map(
      static fn($v) => strtolower(trim($v)),
      $this->getThumbnailAllowedTaxonomies()
    );
    return in_array($current, $allowed, true);
  }

  /**
   * Gère la vignette selon la checkbox.
   */
  private function handleThumbnail(Media $media, FormStateInterface $form_state)
  {
    // Si la section vignette n'a pas été affichée (taxonomie non autorisée),
    // le champ n'existe pas dans le form → on ne touche pas à la vignette.
    if ($form_state->getValue('thumbnail_regenerate') === NULL) {
      return;
    }

    $regenerate_thumbnail = (bool) $form_state->getValue('thumbnail_regenerate');

    // Vérifier si une vignette existe avant toute action
    $has_thumbnail = FALSE;
    if ($media->hasField('field_media_thumbnail')) {
      $thumb_field = $media->get('field_media_thumbnail');
      $has_thumbnail = !$thumb_field->isEmpty();
    }

    if ($regenerate_thumbnail) {
      $this->deleteThumbnail($media);
      $this->generateThumbnail($media);
      \Drupal::messenger()->addStatus($this->t('Vignette régénérée avec succès.'));
    } else {
      $this->deleteThumbnail($media);
      if ($has_thumbnail) {
        \Drupal::messenger()->addStatus($this->t('Vignette supprimée avec succès.'));
      }
    }
  }
  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    $is_admin = in_array('admin_gestiondocumentaire', \Drupal::currentUser()->getRoles());

    if ($is_admin && $form_state->getValue('create_new_version')) {
      $this->createNewVersion($form_state);
    } else {
      $this->updateMedia($form_state);
    }
  }

  private function createNewVersion(FormStateInterface $form_state)
  {
    $id_char_version = $form_state->getValue('id_char_version');
    $parts = explode('_', $id_char_version);
    $current_entity_id = (int) $parts[1];

    Database::setActiveConnection();
    $connection = Database::getConnection('drupal9');

    // Données de la ressource source (pour group_id + version max)
    $group_id = $form_state->getValue('media_group_id') ?: null;

    $current_version = $connection->query("
                      SELECT COALESCE(mrv.field_media_version_value, 1)
                      FROM media_field_data m
                      LEFT JOIN media_revision mr ON m.mid = mr.mid
                      LEFT JOIN media__field_media_version mrv ON mr.vid = mrv.revision_id
                      WHERE m.mid = :mid
                      ORDER BY mr.vid DESC LIMIT 1
                      ", [':mid' => $current_entity_id])->fetchField();
    $current_version = (int) ($current_version ?: 1);

    // Prochaine version = max du groupe + 1
    $max_version = $current_version;
    if ($group_id) {
      $max = $connection->query("
                        SELECT MAX(COALESCE(mrv.field_media_version_value, 1))
                        FROM media__field_media_group_id mrg
                        LEFT JOIN media__field_media_version mrv ON mrg.entity_id = mrv.entity_id
                        WHERE mrg.field_media_group_id_value = :group_id
                        ", [':group_id' => $group_id])->fetchField();
      if ($max) {
        $max_version = (int) $max;
      }
    }
    $new_version = $max_version + 1;

    // Fichier pour la nouvelle version
    $media_src    = Media::load($current_entity_id);
    $uploaded_fid = $form_state->getValue('file')[0] ?? null;

    if (!$uploaded_fid) {
      // Pas de nouveau fichier → on DUPLIQUE l'existant,
      // sinon AddMediaForm déplacerait le fichier de l'ancienne version.
      $existing_file = $media_src ? ($media_src->get('field_media_document')->entity ?? null) : null;
      if (!$existing_file) {
        \Drupal::messenger()->addError($this->t('Aucun fichier à versionner.'));
        return;
      }
      $file_system = \Drupal::service('file_system');
      $tmp_uri = 'public://gestion_documentaire/' . uniqid('newver_') . '_' . $existing_file->getFilename();
      $copied_uri = $file_system->copy($existing_file->getFileUri(), $tmp_uri, FileSystemInterface::EXISTS_RENAME);

      $new_file = File::create([
        'uri'      => $copied_uri,
        'uid'      => \Drupal::currentUser()->id(),
        'status'   => 1,
        'filename' => $existing_file->getFilename(),
      ]);
      $new_file->save();
      $uploaded_fid = $new_file->id();
    }

    // FormState pour AddMediaForm
    $add_form_state = new FormState();
    $add_form_state->setValues([
      'name'                 => $form_state->getValue('name'),
      'parent_taxonomy'      => $form_state->getValue('parent_taxonomy'),
      'file'                 => [$uploaded_fid],
      'version'              => $new_version,
      'is_versioning'        => 1,
      'media_group_id'       => $group_id ?: $current_entity_id,
      'lang_js_data'         => $form_state->getValue(['lang_section', 'lang_js_data']) ?? $form_state->getValue('lang_js_data'),
      'ref_js_data'          => $form_state->getValue(['ref_section', 'ref_js_data']) ?? $form_state->getValue('ref_js_data'),
      'published'            => $form_state->getValue('published'),
      'thumbnail_regenerate' => $form_state->getValue('thumbnail_regenerate'),
    ]);

    // NOTE : on logue $add_form_state (ce qui est réellement transmis à AddMediaForm),
    // et non $form_state, sinon les valeurs posées en dur (version, is_versioning)
    // apparaissent à tort comme NULL dans le log.
    \Drupal::logger('gestion_documentaire')->notice('ADD CHECK: parent=@p | file=@f | version=@v | group=@g | versioning=@iv | lang=@l | ref=@r | pub=@pub', [
      '@p'   => var_export($add_form_state->getValue('parent_taxonomy'), TRUE),
      '@f'   => var_export($add_form_state->getValue('file'), TRUE),
      '@v'   => var_export($add_form_state->getValue('version'), TRUE),
      '@g'   => var_export($add_form_state->getValue('media_group_id'), TRUE),
      '@iv'  => var_export($add_form_state->getValue('is_versioning'), TRUE),
      '@l'   => var_export($add_form_state->getValue('lang_js_data'), TRUE),
      '@r'   => var_export($add_form_state->getValue('ref_js_data'), TRUE),
      '@pub' => var_export($add_form_state->getValue('published'), TRUE),
    ]);


    // Appel direct du submit d'AddMediaForm
    $addForm = new AddMediaForm();
    $dummy_form = [];
    $addForm->submitForm($dummy_form, $add_form_state);

    // Propager la redirection décidée par AddMediaForm
    $redirect = $add_form_state->getRedirect();
    if ($redirect instanceof Url) {
      $form_state->setRedirectUrl($redirect);
    }
  }

  public function updateMedia(FormStateInterface $form_state)
  {

    // \Drupal::messenger()->addError($this->t('Cc c\'est un test. (jusqu\'a 13h40)'));
    $tab_modif_role_update = [];
    $tab_modif_role_insert = [];
    $tab_modif_role_delete = [];
    $id_char_version = $form_state->getValue('id_char_version');

    $parts = explode('_', $id_char_version);
    $current_entity_id = (int) $parts[1];

    $tid = $form_state->getValue('parent_taxonomy');
    $term = Term::load($tid);
    $parent_taxonomy = $term ? $term->getName() : '';

    Database::setActiveConnection();
    $connection = Database::getConnection('drupal9');

    $requested_version = isset($parts[2]) ? (int) $parts[2] : null;

    $ressource_query = "
                      SELECT
                      m.mid AS entity_id,
                      m.uid AS uid,
                      'media' AS entity_type,
                      CONCAT('media_', m.mid) AS id_char,
                      CONCAT('media_', m.mid, '_', COALESCE(mrv.field_media_version_value, 1)) AS id_char_version,
                      m.name AS name,
                      t.name AS parent_taxonomy,
                      t.tid AS parent_taxonomy_id,
                      FROM_UNIXTIME(m.created) AS created,
                      FROM_UNIXTIME(m.changed) AS changed,
                      mr.revision_log_message,
                      mr.vid AS revision_id,
                      mr.revision_user,
                      mrt.field_media_type_value AS type,
                      mrl.field_media_lang_value AS lang,
                      mrr.field_media_reference_value AS reference,
                      mrh.field_media_hash_value AS hash,
                      COALESCE(mrv.field_media_version_value, 1) AS version,
                      mrg.field_media_group_id_value AS media_group_id
                      FROM media_field_data m
                      LEFT JOIN media__field_taxonomie_associee link ON m.mid = link.entity_id
                      LEFT JOIN taxonomy_term_field_data t ON link.field_taxonomie_associee_target_id = t.tid
                      LEFT JOIN media_revision mr ON m.mid = mr.mid
                      LEFT JOIN media__field_media_type mrt ON mr.vid = mrt.revision_id
                      LEFT JOIN media__field_media_lang mrl ON mr.vid = mrl.revision_id
                      LEFT JOIN media__field_media_reference mrr ON mr.vid = mrr.revision_id
                      LEFT JOIN media__field_media_hash mrh ON mr.vid = mrh.revision_id
                      LEFT JOIN media__field_media_version mrv ON mr.vid = mrv.revision_id
                      LEFT JOIN media__field_media_group_id mrg ON mr.vid = mrg.revision_id
                      WHERE m.mid = :mid
                      ";

    if ($requested_version !== null) {
      $ressource_query .= " AND COALESCE(mrv.field_media_version_value, 1) = :version";
      $params = [':mid' => $current_entity_id, ':version' => $requested_version];
    } else {
      $params = [':mid' => $current_entity_id];
    }
    $ressource_query .= " ORDER BY mr.vid DESC LIMIT 1";

    $ressource = $connection->query($ressource_query, $params)->fetchAssoc();

    if (!$ressource) {
      \Drupal::messenger()->addError($this->t('Ressource introuvable.'));
      return;
    }

    $new_version_value = (int) ($ressource['version'] ?? 1);
    $nouveau_nom_saisi = $form_state->getValue('name');

    $media_for_ext = Media::load($current_entity_id);
    $existing_file_for_ext = $media_for_ext ? ($media_for_ext->get('field_media_document')->entity ?? null) : null;
    $existing_ext = $existing_file_for_ext ? strtolower(pathinfo($existing_file_for_ext->getFilename(), PATHINFO_EXTENSION)) : '';

    if ($existing_ext && strtolower(pathinfo($nouveau_nom_saisi, PATHINFO_EXTENSION)) !== $existing_ext) {
      $nouveau_nom_saisi = $nouveau_nom_saisi . '.' . $existing_ext;
    }

    if ($nouveau_nom_saisi === $ressource['name']) {
      $nouveau_nom = $ressource['name'];
    } else {
      $nouveau_nom = $this->ensureMediaIdAndVersionInFilename(
        $nouveau_nom_saisi,
        $current_entity_id,
        $new_version_value
      );
    }

    // $doublon = $connection->query("
    //   SELECT CONCAT('media_', m.mid, '_', COALESCE(mrv.field_media_version_value, 1)) AS id_char_version
    //   FROM media_field_data m
    //   LEFT JOIN media__field_taxonomie_associee link ON m.mid = link.entity_id
    //   LEFT JOIN taxonomy_term_field_data t ON link.field_taxonomie_associee_target_id = t.tid
    //   LEFT JOIN media_revision mr ON m.mid = mr.mid
    //   LEFT JOIN media__field_media_version mrv ON mr.vid = mrv.revision_id
    //   WHERE m.name = :name
    //     AND t.name = :parent
    //     AND m.mid != :current_mid
    //   LIMIT 1
    // ", [
    //   ':name'        => $nouveau_nom,
    //   ':parent'      => $parent_taxonomy,
    //   ':current_mid' => $current_entity_id,
    // ])->fetchField();

    // if ($doublon) {
    //   \Drupal::messenger()->addWarning($this->t('Ce nom de ressource existe déjà.'));
    //   return;
    // }

    $account     = \Drupal::currentUser();
    $permissions = AccessMediaController::getResourcePermissions($account, $id_char_version);
    $can_edit      = $permissions['can_edit'];
    $can_edit_role = $permissions['can_edit_role'];

    $media = Media::load($ressource['entity_id']);
    if (!$media) {
      \Drupal::messenger()->addError($this->t('Media introuvable.'));
      return;
    }

    $modif_name = $modif_taxonomy = $modif_file = $modif_type = $modif_lang = $modif_ref = 0;
    $selected_type = $ressource['type'];

    if ($can_edit) {
      $selected_type = $form_state->getValue(['type_wrapper', 'type'])
        ?? $form_state->getValue('type')
        ?? $ressource['type'];
      if ($selected_type !== $ressource['type']) {
        $media->set('field_media_type', $selected_type);
        $modif_type = 1;
        \Drupal::messenger()->addStatus($this->t('Le type a été modifié : @old → @new', [
          '@old' => $ressource['type'],
          '@new' => $selected_type,
        ]));
      } else {
        $media->set('field_media_type', $selected_type);
      }

      $base_new_name = pathinfo($nouveau_nom, PATHINFO_FILENAME);
      $base_old_name = pathinfo($ressource['name'], PATHINFO_FILENAME);
      if ($base_new_name !== $base_old_name) {
        $modif_name = 1;
      }

      $term = null;
      if ($parent_taxonomy != $ressource['parent_taxonomy'] || $modif_name) {
        $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['name' => $parent_taxonomy]);
        $term  = reset($terms);
        if ($term) {
          $modif_taxonomy = 1;
          $media->set('field_taxonomie_associee', [['target_id' => $term->id()]]);
        } else {
          \Drupal::messenger()->addWarning($this->t('Le terme de taxonomie est introuvable.'));
        }
      }

      $ref_js_data  = $form_state->getValue(['ref_section', 'ref_js_data'])  ?? $form_state->getValue('ref_js_data');
      $lang_js_data = $form_state->getValue(['lang_section', 'lang_js_data']) ?? $form_state->getValue('lang_js_data');

      $refLangForm = \Drupal::service('class_resolver')
        ->getInstanceFromDefinition(RefLangForm::class);
      $changes     = $refLangForm->saveRefLangData($media, $ref_js_data, $lang_js_data);

      $modif_ref  = $changes['modif_ref'];
      $modif_lang = $changes['modif_lang'];

      foreach ($changes['deleted_langs'] as $lang) {
        \Drupal::messenger()->addStatus($this->t('Langue supprimée : @lang', ['@lang' => $lang]));
      }
      foreach ($changes['added_langs'] as $lang) {
        \Drupal::messenger()->addStatus($this->t('Langue ajoutée : @lang', ['@lang' => $lang]));
      }
      foreach ($changes['deleted_refs'] as $ref) {
        \Drupal::messenger()->addStatus($this->t('Référence supprimée : @ref', ['@ref' => $ref]));
      }
      foreach ($changes['added_refs'] as $ref) {
        \Drupal::messenger()->addStatus($this->t('Référence ajoutée : @ref', ['@ref' => $ref]));
      }

      // ========== GESTION DU FICHIER (CORRIGÉ) ==========
      $file_system    = \Drupal::service('file_system');
      $existing_file  = $media->get('field_media_document')->entity ?? null;
      $new_realpath   = null;
      $nouveau_fichier = $form_state->getValue('file')[0] ?? null;
      $file_uploaded  = $nouveau_fichier ? File::load($nouveau_fichier) : false;

      if ($existing_file instanceof File || $file_uploaded) {
        $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['name' => $parent_taxonomy]);
        $term  = reset($terms);
        if ($term) {
          $new_full_path = $connection->query("
                                WITH RECURSIVE taxonomy_path AS (
                                  SELECT t2.tid, t2.name, p2.parent_target_id, t2.name AS full_path, 0 AS level
                                  FROM taxonomy_term_field_data t2
                                  LEFT JOIN taxonomy_term__parent p2 ON p2.entity_id = t2.tid
                                  WHERE t2.tid = :tid
                                  UNION ALL
                                  SELECT parent.tid, parent.name, pp.parent_target_id,
                                  CONCAT(parent.name, '/', tp.full_path) AS full_path,
                                  tp.level + 1
                                  FROM taxonomy_term_field_data parent
                                  JOIN taxonomy_term__parent pp ON pp.entity_id = parent.tid
                                  JOIN taxonomy_path tp ON tp.parent_target_id = parent.tid
                                  )
                                  SELECT full_path FROM taxonomy_path ORDER BY level DESC LIMIT 1
                                  ", [':tid' => $term->id()])->fetchField();
        }

        if (empty($new_full_path)) {
          \Drupal::messenger()->addError("Impossible de déterminer le chemin de destination.");
          return;
        }

        $prefixe = "private://";
        if (strpos($new_full_path, 'document_vitrine') !== false) {
          $prefixe = "public://";
        }
        $new_directory = $prefixe . 'gestion_documentaire/' . $new_full_path;
        $file_system->prepareDirectory($new_directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

        // ========== CAS 1 : NOUVEAU FICHIER UPLOADÉ (PRIORITÉ) ==========
        if ($file_uploaded) {
          try {
            $new_ext = strtolower(pathinfo($file_uploaded->getFilename(), PATHINFO_EXTENSION));
            $old_ext = strtolower(pathinfo($nouveau_nom, PATHINFO_EXTENSION));

            if ($new_ext !== $old_ext) {
              $nouveau_nom = pathinfo($nouveau_nom, PATHINFO_FILENAME) . '.' . $new_ext;
            }

            $new_uri = $new_directory . '/' . $nouveau_nom;
            $new_realpath = $file_system->realpath($new_directory) . '/' . $nouveau_nom;

            // SUPPRIMER L'ANCIEN FICHIER PHYSIQUE
            if ($existing_file instanceof File) {
              $old_realpath = $file_system->realpath($existing_file->getFileUri());
              if ($old_realpath && file_exists($old_realpath)) {
                @unlink($old_realpath);
              }
              $existing_file->delete();
            }

            // DÉPLACER LE NOUVEAU
            $file_system->move($file_uploaded->getFileUri(), $new_uri, FileSystemInterface::EXISTS_REPLACE);
            $file_uploaded->setFileUri($new_uri);
            $file_uploaded->setFilename($nouveau_nom);
            $file_uploaded->save();

            $media->set('field_media_document', $file_uploaded);
            $modif_file = 1;
            \Drupal::messenger()->addStatus('Fichier remplacé avec succès.');
          } catch (\Exception $e) {
            \Drupal::messenger()->addError('Impossible de remplacer le fichier : ' . $e->getMessage());
            return;
          }
        }
        // ========== CAS 2 : PAS DE NOUVEAU FICHIER, JUSTE RENOMMER/DÉPLACER ==========
        elseif ($existing_file instanceof File && ($modif_name || $modif_taxonomy)) {
          $new_uri = $new_directory . '/' . $nouveau_nom;
          $new_realpath = $file_system->realpath($new_directory) . '/' . $nouveau_nom;
          $old_realpath = $file_system->realpath($existing_file->getFileUri());

          if (!file_exists($old_realpath)) {
            \Drupal::messenger()->addError("Fichier source introuvable : $old_realpath");
          } elseif (rename($old_realpath, $new_realpath)) {
            $existing_file->setFileUri($new_uri);
            $existing_file->setFilename($nouveau_nom);
            $existing_file->save();
            $media->set('field_media_document', $existing_file);
            $modif_file = 1;
            \Drupal::messenger()->addStatus('Fichier déplacé et renommé avec succès.');
          } else {
            \Drupal::messenger()->addError('Impossible de renommer le fichier.');
            return;
          }
        }

        $media->setName($nouveau_nom);

        // Check hash final
        if ($new_realpath && file_exists($new_realpath)) {
          $check_final = GetDataController::checkHashFromFile($new_realpath, $current_entity_id);
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
            return;
          }
        }
      }

      // Gestion des rôles
      if ($can_edit_role) {
        $roles_values = $form_state->getValue('roles_table') ?? [];

        foreach ($roles_values as $role_libelle => $perm) {
          if (!empty($perm['delete_role']) && $perm['delete_role'] == 1) {
            $existing_role = $connection->select('gestion_documentaire_role', 'r')
              ->fields('r', ['role_libelle', 'can_view', 'can_edit', 'can_delete', 'can_edit_role'])
              ->condition('id_char_version', $id_char_version)
              ->condition('role_libelle', $role_libelle)
              ->execute()->fetchAssoc();

            if ($existing_role) {
              $tab_modif_role_delete[] = [
                $existing_role['role_libelle'],
                $existing_role['can_view'],
                $existing_role['can_edit'],
                $existing_role['can_delete'],
                $existing_role['can_edit_role'],
              ];
            }

            $connection->delete('gestion_documentaire_role')
              ->condition('id_char_version', $id_char_version)
              ->condition('role_libelle', $role_libelle)
              ->execute();

            \Drupal::messenger()->addStatus($this->t('Rôle "@role" supprimé.', ['@role' => $role_libelle]));
            continue;
          }

          $existing_role = $connection->select('gestion_documentaire_role', 'r')
            ->fields('r')
            ->condition('id_char_version', $id_char_version)
            ->condition('role_libelle', $role_libelle)
            ->execute()->fetchAssoc();

          if ($existing_role) {
            $new_values = [
              'can_view'      => !empty($perm['can_view'])      ? 1 : 0,
              'can_edit'      => !empty($perm['can_edit'])      ? 1 : 0,
              'can_delete'    => !empty($perm['can_delete'])    ? 1 : 0,
              'can_edit_role' => !empty($perm['can_edit_role']) ? 1 : 0,
            ];

            if (
              $existing_role['can_view']      != $new_values['can_view']
              || $existing_role['can_edit']      != $new_values['can_edit']
              || $existing_role['can_delete']    != $new_values['can_delete']
              || $existing_role['can_edit_role'] != $new_values['can_edit_role']
            ) {
              $connection->update('gestion_documentaire_role')
                ->fields($new_values)
                ->condition('id_char_version', $id_char_version)
                ->condition('role_libelle', $role_libelle)
                ->execute();

              $tab_modif_role_update[] = array_merge([$role_libelle], array_values($new_values));
              \Drupal::messenger()->addStatus($this->t('Permissions du rôle "@role" mises à jour.', ['@role' => $role_libelle]));
            }
          }
        }

        $nouveau_role = $form_state->getValue('new_role');
        if ($nouveau_role) {
          $connection->insert('gestion_documentaire_role')
            ->fields([
              'id_char_version' => $id_char_version,
              'id_char'         => $id_char_version,
              'role_libelle'    => $nouveau_role,
              'can_view'        => $form_state->getValue('can_view_new')      ? 1 : 0,
              'can_edit'        => $form_state->getValue('can_edit_new')      ? 1 : 0,
              'can_delete'      => $form_state->getValue('can_delete_new')    ? 1 : 0,
              'can_edit_role'   => $form_state->getValue('can_edit_role_new') ? 1 : 0,
            ])->execute();

          $tab_modif_role_insert[] = [
            $nouveau_role,
            $form_state->getValue('can_view_new')      ? 1 : 0,
            $form_state->getValue('can_edit_new')      ? 1 : 0,
            $form_state->getValue('can_delete_new')    ? 1 : 0,
            $form_state->getValue('can_edit_role_new') ? 1 : 0,
          ];
          \Drupal::messenger()->addStatus($this->t('Nouveau rôle "@role" ajouté.', ['@role' => $nouveau_role]));
        }
      }
    }

    $media->set('field_media_version', (string) $new_version_value);

    $display_name = pathinfo(
      $this->stripMediaIdAndVersionFromFilename($nouveau_nom, $current_entity_id, $new_version_value),
      PATHINFO_FILENAME
    );
    $media->set('field_media_filename', $display_name);

    $revision_log_data = [
      'media' => [
        'nom'        => ['ancien' => $ressource['name'], 'nouveau' => $nouveau_nom],
        'parent'     => ['ancien' => $ressource['parent_taxonomy'], 'nouveau' => $parent_taxonomy],
        'references' => $modif_ref,
        'lang'       => $modif_lang,
        'type'       => $selected_type,
        'hash'       => $ressource['hash'],
      ],
      'roles_update' => $tab_modif_role_update,
      'roles_insert' => $tab_modif_role_insert,
      'roles_delete' => $tab_modif_role_delete,
      'modif'        => ($modif_name || $modif_taxonomy || $modif_file || $modif_type || $modif_lang || $modif_ref
        || !empty($tab_modif_role_update) || !empty($tab_modif_role_insert) || !empty($tab_modif_role_delete)) ? 1 : 0,
    ];

    $media->setRevisionUserId($account->id());
    $media->setRevisionLogMessage(json_encode($revision_log_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $media->setNewRevision(TRUE);
    $media->save();

    // ✅ GESTION DE LA VIGNETTE
    $this->handleThumbnail($media, $form_state);

    $publishForm = \Drupal::service('class_resolver')
      ->getInstanceFromDefinition(PublishMediaForm::class);
    $published      = (bool) $form_state->getValue('published');
    $media_group    = $ressource['media_group_id'] ?? '';
    $publish_result = $publishForm->applyPublishStatus($media, $published, $media_group, $ressource['entity_id']);

    foreach ($publish_result['deleted'] as $name) {
      \Drupal::messenger()->addStatus($this->t('Ancienne version supprimée : @name', ['@name' => $name]));
    }
    foreach ($publish_result['errors'] as $err) {
      \Drupal::messenger()->addError($err);
    }

    $media_reloaded = Media::load($ressource['entity_id']);
    $nom = $media_reloaded ? $media_reloaded->getName() : '';


    $url = Url::fromUri(
      'internal:/gestion_documentaire/document/modifier/' . $nom

    );

    $form_state->setRedirectUrl($url);
  }
}
