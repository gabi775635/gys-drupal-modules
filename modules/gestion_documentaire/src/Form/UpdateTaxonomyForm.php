<?php

namespace Drupal\gestion_documentaire\Form;

use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Database;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\taxonomy\Entity\Term;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\Core\Url;
use Drupal\gestion_documentaire\Controller\RecentChangesController;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Component\Utility\Xss;

class UpdateTaxonomyForm extends FormBase {

  public function getFormId() {
    return 'gestion_documentaire_update_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, $id_char_version = NULL) {
    $id_char = $id_char_version;

    if (!$id_char) {
      $form['#markup'] = $this->t('Aucun ID de ressource fourni.');
      return $form;
    }

    Database::setActiveConnection('drupal9');
    $connection = Database::getConnection();
    $current_langcode = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $query_taxonomy = "
      SELECT
        ttfd.tid AS entity_id,
        'taxonomy' AS entity_type,
        CONCAT('taxonomy_', ttfd.tid) AS id_char,
        CONCAT('taxonomy_', ttfd.tid) AS id_char_version,
        ttfd.name AS name,
        pt.name AS parent_taxonomy,
        pt.tid AS parent_taxonomy_id,
        FROM_UNIXTIME(ttfd.created) AS created,
        FROM_UNIXTIME(ttfd.changed) AS changed,
        CONCAT('/extranet/fr/gestion_documentaire/', CONCAT('taxonomy_', ttfd.tid)) AS path,
        ttfur.field_user_reference_target_id AS uid
      FROM taxonomy_term_field_data ttfd
      LEFT JOIN taxonomy_term__parent ttp ON ttfd.tid = ttp.entity_id
      LEFT JOIN taxonomy_term_field_data pt ON ttp.parent_target_id = pt.tid
      LEFT JOIN taxonomy_term__field_user_reference ttfur ON ttfur.entity_id = ttfd.tid
      WHERE CONCAT('taxonomy_', ttfd.tid) = :id_char and ttfd.langcode= :langcode
      LIMIT 1
    ";

    $ressource = $connection->query($query_taxonomy, [':id_char' => $id_char, ':langcode' => $current_langcode])->fetchAssoc();

    if (!$ressource) {
      $form['#markup'] = $this->t('Ressource introuvable.');
      return $form;
    }

    $form['titre'] = [
      '#type' => 'markup',
      '#markup' => Xss::filterAdmin('<h2> 📂 ' . $this->t('Modify folder') . ' : ' . Xss::filter($ressource['name']) . '</h2>'),
    ];

    // Permissions
    $account = \Drupal::currentUser();
    $user_roles = $account->getRoles();

    $query = $connection->select('gestion_documentaire_role', 'gdr');
    $query->addExpression('MAX(COALESCE(gdr.can_edit, 0))', 'can_edit');
    $query->addExpression('MAX(COALESCE(gdr.can_edit_role, 0))', 'can_edit_role');
    $query->addField('gdr', 'role_libelle');
    $query->condition('id_char', $id_char);
    $query->groupBy('gdr.role_libelle');

    if (!in_array('administrator', $user_roles)) {
      $query->condition('gdr.role_libelle', $user_roles, 'IN');
    }

    $permissions = $query->execute()->fetchAssoc();
    $can_edit = !empty($permissions['can_edit']);
    $can_edit_role = !empty($permissions['can_edit_role']);

    if (in_array('administrator', $user_roles)) {
      $can_edit = 1;
      $can_edit_role = 1;
    }

    $is_admin = in_array('administrator', $user_roles);

    $path = !empty($ressource['parent_taxonomy'])
      ? '/gestion_documentaire/dossier/' . $ressource['parent_taxonomy']
      : '/gestion_documentaire/dossier/racine';

    $form['home_page'] = [
      '#type' => 'markup',
      '#markup' => Xss::filterAdmin(\Drupal\Core\Link::fromTextAndUrl(
        $this->t('Leave form'),
        Url::fromUserInput($path)
      )->toString()),
    ];

    // --- Section Traductions ---
    $form['translations_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Folder name'),
      '#collapsible' => FALSE,
    ];

    $term = Term::load($ressource['entity_id']);

    $form['translations_section']['translation_fr'] = [
      '#type' => 'textfield',
      '#title' => $this->t('French') . ' (fr)',
      '#description' => $this->t('Example: Welding formation'),
      '#required' => TRUE,
      '#default_value' => $term->hasTranslation('fr') ? $term->getTranslation('fr')->getName() : '',
    ];

    $form['translations_section']['translation_en'] = [
      '#type' => 'textfield',
      '#title' => $this->t('English') . ' (en)',
      '#default_value' => $term->hasTranslation('en') ? $term->getTranslation('en')->getName() : '',
    ];

    // Hidden ID
    $form['id'] = [
      '#type' => 'hidden',
      '#value' => $ressource['id_char'] ?? $id_char,
    ];

    // Gestion des rôles
    if ($can_edit_role) {
      $roles_raw = $connection->select('gestion_documentaire_role', 'gdr')
        ->fields('gdr')
        ->condition('id_char', $id_char)
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
        '#title' => $this->t('Add role to this resource'),
      ];

      $form['add_role_fieldset']['new_role'] = [
        '#type' => 'select',
        '#title' => $this->t('Select role'),
        '#options' => $all_roles,
        '#empty_option' => '- '.$this->t('Choose a role').' -',
        '#default_value' => NULL,
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

      // ── Héritage des permissions ─────────────────────────────────────
      $form['add_role_fieldset']['inherit_new'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Permission inheritance'),
        '#description' => $this->t('If checked, these permissions will be inherited by child folders. Uncheck to apply them only to this folder.'),
        '#default_value' => 1,
      ];

      if (!empty($roles_raw)) {
        $header = [
          'role_libelle'  => $this->t('Role'),
          'can_view'      => $this->t('Can see'),
          'can_edit'      => $this->t('Can modify'),
          'can_delete'    => $this->t('Can delete'),
          'can_edit_role' => $this->t('Can edit role'),
          'inherit'       => $this->t('Permission inheritance'),
          'delete_role'   => $this->t('Delete'),
        ];

        $form['roles_table'] = [
          '#type' => 'table',
          '#header' => $header,
          '#empty' => $this->t('No defined role for this resource'),
        ];

        foreach ($roles_raw as $role_obj) {
          $role = (array) $role_obj;
          $role_id = $role['role_libelle'];

          // Valeur inherit actuelle (défaut 1 si colonne absente)
          $current_inherit = isset($role['inherit']) ? (int) $role['inherit'] : 1;

          $form['roles_table'][$role_id]['role_libelle'] = ['#markup' => Xss::filter($role['role_libelle'])];

          $form['roles_table'][$role_id]['can_view'] = [
            '#type' => 'checkbox',
            '#default_value' => $role['can_view'],
            '#title_display' => 'invisible',
            '#title' => $this->t('Can see'),
          ];
          $form['roles_table'][$role_id]['can_edit'] = [
            '#type' => 'checkbox',
            '#default_value' => $role['can_edit'],
            '#title_display' => 'invisible',
            '#title' => $this->t('Can modify'),
          ];
          $form['roles_table'][$role_id]['can_delete'] = [
            '#type' => 'checkbox',
            '#default_value' => $role['can_delete'],
            '#title_display' => 'invisible',
            '#title' => $this->t('Can delete'),
          ];
          $form['roles_table'][$role_id]['can_edit_role'] = [
            '#type' => 'checkbox',
            '#default_value' => $role['can_edit_role'],
            '#title_display' => 'invisible',
            '#title' => $this->t('Can edit role'),
          ];
          $form['roles_table'][$role_id]['inherit'] = [
            '#type' => 'checkbox',
            '#default_value' => $current_inherit,
            '#title_display' => 'invisible',
            '#title' => $this->t('Permission inheritance'),
          ];
          $form['roles_table'][$role_id]['delete_role'] = [
            '#type' => 'checkbox',
            '#title' => $this->t('Delete'),
            '#default_value' => 0,
            '#title_display' => 'invisible',
          ];
        }
      }
    }

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Update'),
    ];

    $controller = new RecentChangesController();
    $rows = $controller->getRecentChangesData($id_char, $ressource['entity_type']);

    $form['title'] = [
      '#type' => 'markup',
      '#markup' => Xss::filterAdmin('<br><br><h2>' . $this->t('Resource modification history') . '</h2><br>'),
    ];

    $form['recent_changes'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Date'),
        $this->t('User'),
        $this->t('Name'),
        $this->t('Folder'),
        $this->t('Role added'),
        $this->t('Role modified'),
        $this->t('Role deleted'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('No modification in log'),
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $name_en = $form_state->getValue('translation_en');

    if (!empty($name_en)) {
      if (preg_match('/\s/', $name_en)) {
        $form_state->setErrorByName('translation_en', $this->t('The English name must not contain spaces. Use underscores (_) or hyphens (-).'));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $id_char = $form_state->getValue('id');
    Database::setActiveConnection('drupal9');
    $connection = Database::getConnection();

    $account = \Drupal::currentUser();
    $user_roles = $account->getRoles();

    $query = $connection->select('gestion_documentaire_role', 'gdr');
    $query->addExpression('MAX(COALESCE(gdr.can_edit, 0))', 'can_edit');
    $query->addExpression('MAX(COALESCE(gdr.can_edit_role, 0))', 'can_edit_role');
    $query->addField('gdr', 'role_libelle');
    $query->condition('id_char', $id_char);
    $query->groupBy('gdr.role_libelle');

    if (!in_array('administrator', $user_roles)) {
      $query->condition('gdr.role_libelle', $user_roles, 'IN');
    }

    $permissions = $query->execute()->fetchAssoc();
    $can_edit = !empty($permissions['can_edit']);
    $can_edit_role = !empty($permissions['can_edit_role']);

    $is_admin = in_array('administrator', $user_roles);
    if ($is_admin) {
      $can_edit = 1;
      $can_edit_role = 1;
    }

    $forbidden_chars = ['!', '@', '#', '$', '%', '^', '&', '*'];
    $name_en = $form_state->getValue('translation_en');
    foreach ($forbidden_chars as $char) {
      if (strpos($name_en, $char) !== FALSE) {
        \Drupal::messenger()->addWarning($this->t('The name contains forbidden characters: @char', ['@char' => $char]));
        return;
      }
    }

    $query_doublon = "
      SELECT ttfd.tid
      FROM taxonomy_term_field_data ttfd
      WHERE ttfd.name = :nouveau_nom
        AND ttfd.vid = 'dossiers'
        AND CONCAT('taxonomy_', ttfd.tid) != :id_char
      LIMIT 1
    ";

    $doublon = $connection->query($query_doublon, [
      ':nouveau_nom' => $name_en,
      ':id_char' => $id_char,
    ])->fetchField();

    if ($doublon) {
      \Drupal::messenger()->addWarning($this->t('This resource name already exists'));
      return;
    }

    $query_ressource = "
      SELECT
        ttfd.tid AS entity_id,
        'taxonomy' AS entity_type,
        ttfd.name AS name,
        pt.name AS parent_taxonomy,
        pt.tid AS parent_taxonomy_id
      FROM taxonomy_term_field_data ttfd
      LEFT JOIN taxonomy_term__parent ttp ON ttfd.tid = ttp.entity_id
      LEFT JOIN taxonomy_term_field_data pt ON ttp.parent_target_id = pt.tid
      WHERE CONCAT('taxonomy_', ttfd.tid) = :id_char
      LIMIT 1
    ";

    $ressource = $connection->query($query_ressource, [':id_char' => $id_char])->fetchAssoc();

    if (!$ressource) {
      \Drupal::messenger()->addError($this->t('Resource not found'));
      return;
    }

    $id_char = $ressource['entity_type'] . '_' . $ressource['entity_id'];
    $term = Term::load($ressource['entity_id']);

    $modif_name        = 0;
    $modif_parent      = 0;
    $modif_role_update = 0;
    $modif_role_insert = 0;
    $tab_modif_role_update = [];
    $tab_modif_role_insert = [];
    $tab_modif_role_delete = [];

    $old_name_en = $term->getName();
    $old_name_fr = $term->hasTranslation('fr') ? $term->getTranslation('fr')->getName() : '';

    $form_languages = ['fr', 'en'];
    foreach ($form_languages as $langcode) {
      $name = $form_state->getValue('translation_' . $langcode);

      if ($langcode == 'fr' && empty($name)) {
        \Drupal::messenger()->addError($this->t('Le nom français est obligatoire.'));
        return;
      }

      if ($langcode == 'en' && empty($name)) {
        continue;
      }

      if ($term->hasTranslation($langcode)) {
        $translated_term = $term->getTranslation($langcode);
        $old_name = $translated_term->getName();
        if ($old_name !== $name) {
          $translated_term->setName($name);
          $translated_term->save();
          $modif_name = 1;
          \Drupal::messenger()->addStatus($this->t(
            'Traduction @lang mise à jour : "@ancien" → "@nouveau"',
            ['@lang' => $langcode, '@ancien' => $old_name, '@nouveau' => $name]
          ));
        }
      }
      else {
        $translated_term = $term->addTranslation($langcode, ['name' => $name]);
        $translated_term->save();
        $modif_name = 1;
        \Drupal::messenger()->addStatus($this->t(
          'Traduction @lang créée : "@nouveau"',
          ['@lang' => $langcode, '@nouveau' => $name]
        ));
      }
    }

    $term->save();

    // Mise à jour des rôles
    if ($can_edit_role) {
      $roles_values = $form_state->getValue('roles_table');
      if (!empty($roles_values)) {
        foreach ($roles_values as $role_libelle => $permissions_role) {
          if (!empty($permissions_role['delete_role'])) {
            $this->deleteUserRole($id_char, $role_libelle);
            $modif_role_delete = 1;
            $tab_modif_role_delete[] = [$role_libelle];
          }
          else {
            $existing_role = $connection->select('gestion_documentaire_role', 'r')
              ->fields('r', ['can_view', 'can_edit', 'can_delete', 'can_edit_role', 'inherit'])
              ->condition('id_char', $id_char)
              ->condition('role_libelle', $role_libelle)
              ->execute()
              ->fetchAssoc();

            $new_values = [
              'can_view'      => !empty($permissions_role['can_view'])      ? 1 : 0,
              'can_edit'      => !empty($permissions_role['can_edit'])      ? 1 : 0,
              'can_delete'    => !empty($permissions_role['can_delete'])    ? 1 : 0,
              'can_edit_role' => !empty($permissions_role['can_edit_role']) ? 1 : 0,
              'inherit'       => !empty($permissions_role['inherit'])       ? 1 : 0,
            ];

            $role_changed = FALSE;
            if ($existing_role) {
              foreach ($new_values as $key => $value) {
                if ((int) ($existing_role[$key] ?? 1) !== $value) {
                  $role_changed = TRUE;
                  break;
                }
              }
            }
            else {
              $role_changed = TRUE;
            }

            if ($role_changed) {
              if ($existing_role) {
                $connection->update('gestion_documentaire_role')
                  ->fields($new_values)
                  ->condition('id_char', $id_char)
                  ->condition('role_libelle', $role_libelle)
                  ->execute();
              }
              else {
                $connection->insert('gestion_documentaire_role')
                  ->fields(array_merge(['id_char' => $id_char, 'role_libelle' => $role_libelle], $new_values))
                  ->execute();
              }

              $modif_role_update = 1;
              $tab_modif_role_update[] = array_merge([$role_libelle], array_values($new_values));
              \Drupal::messenger()->addStatus($this->t(
                'Permissions du rôle "@role" mises à jour.',
                ['@role' => $role_libelle]
              ));
            }
          }
        }
      }

      // Insertion nouveau rôle
      $nouveau_role = $form_state->getValue('new_role');
      if (!empty($nouveau_role)) {
        $can_view_new      = $form_state->getValue('can_view_new')      ? 1 : 0;
        $can_edit_new      = $form_state->getValue('can_edit_new')      ? 1 : 0;
        $can_delete_new    = $form_state->getValue('can_delete_new')    ? 1 : 0;
        $can_edit_role_new = $form_state->getValue('can_edit_role_new') ? 1 : 0;
        $inherit_new       = $form_state->getValue('inherit_new')       ? 1 : 0;

        if ($can_edit_role_new == 1) {
          $can_edit_new = 1;
        }

        $connection->insert('gestion_documentaire_role')
          ->fields([
            'id_char'       => $id_char,
            'role_libelle'  => $nouveau_role,
            'can_view'      => $can_view_new,
            'can_edit'      => $can_edit_new,
            'can_delete'    => $can_delete_new,
            'can_edit_role' => $can_edit_role_new,
            'inherit'       => $inherit_new,
          ])->execute();

        $modif_role_insert = 1;
        $tab_modif_role_insert[] = [$nouveau_role, $can_view_new, $can_edit_new, $can_delete_new, $can_edit_role_new, $inherit_new];
        \Drupal::messenger()->addStatus($this->t(
          'Nouveau rôle "@role" ajouté.',
          ['@role' => $nouveau_role]
        ));
      }
    }

    // Révision + renommage dossier physique
    if ($modif_name || $modif_parent || $modif_role_update || $modif_role_insert || $modif_role_delete) {
      try {
        $query_path = "
          WITH RECURSIVE taxonomy_path AS (
            SELECT t.tid, t.name, p.parent_target_id, t.name AS full_path, 0 AS level
            FROM taxonomy_term_field_data t
            LEFT JOIN taxonomy_term__parent p ON p.entity_id = t.tid
            WHERE t.name = :parent_taxonomy
            UNION ALL
            SELECT parent.tid, parent.name, pp.parent_target_id,
                   CONCAT(parent.name, '/', tp.full_path) AS full_path, tp.level + 1
            FROM taxonomy_term_field_data parent
            JOIN taxonomy_term__parent pp ON pp.entity_id = parent.tid
            JOIN taxonomy_path tp ON tp.parent_target_id = parent.tid
          )
          SELECT full_path FROM taxonomy_path ORDER BY level DESC LIMIT 1;
        ";

        $parent_name = $ressource['parent_taxonomy'];
        $full_path = $connection->query($query_path, [':parent_taxonomy' => $parent_name])->fetchField();

        if (!$full_path) {
          \Drupal::logger('gestion_documentaire')->warning("Impossible de reconstruire le chemin du parent : $parent_name");
        }
        else {
          $file_system  = \Drupal::service('file_system');
          $root_dir     = 'public://gestion_documentaire/';
          $old_dir      = $root_dir . $full_path . '/' . $ressource['name'];
          $old_realpath = $file_system->realpath($old_dir);
          $new_realpath = $file_system->realpath($root_dir . $full_path) . '/' . $name_en;

          if (is_dir($old_realpath)) {
            if ($modif_name) {
              if (!file_exists(dirname($new_realpath))) {
                $file_system->prepareDirectory(dirname($new_realpath), FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
              }
              rename($old_realpath, $new_realpath);
              \Drupal::logger('gestion_documentaire')->info("Dossier renommé : $old_realpath → $new_realpath");
            }
          }
          else {
            $new_dir = $root_dir . $full_path . '/' . $name_en;
            $file_system->prepareDirectory($new_dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
            \Drupal::logger('gestion_documentaire')->info("Nouveau dossier créé : $new_dir");
          }
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('gestion_documentaire')->error('Erreur lors du renommage du dossier : @message', ['@message' => $e->getMessage()]);
      }

      $revision_log_data = [
        'taxonomy' => [
          'nom_fr' => ['ancien' => $old_name_fr, 'nouveau' => $form_state->getValue('translation_fr')],
          'nom_en' => ['ancien' => $old_name_en, 'nouveau' => $name_en],
          'nom'    => ['ancien' => $old_name_en, 'nouveau' => $name_en],
          'parent' => ['ancien' => $ressource['parent_taxonomy'], 'nouveau' => $ressource['parent_taxonomy']],
        ],
        'roles_update' => $tab_modif_role_update,
        'roles_insert' => $tab_modif_role_insert,
        'roles_delete' => $tab_modif_role_delete,
        'modif'        => 1,
      ];

      $vocab = \Drupal\taxonomy\Entity\Vocabulary::load('dossiers');
      if ($vocab) {
        $vocab->set('revision', TRUE);
        $vocab->save();
      }

      $term->setRevisionUserId(\Drupal::currentUser()->id());
      $term->setRevisionCreationTime(time());
      $term->setRevisionLogMessage(json_encode($revision_log_data, JSON_PRETTY_PRINT));
      $term->setNewRevision(TRUE);
      $term->save();
    }
    else {
      \Drupal::messenger()->addStatus($this->t('Aucune modification détectée.'));
    }

    $new_name_fr = $form_state->getValue('translation_fr');
    if ($old_name_fr !== $new_name_fr) {
      $new_url = '/extranet/gestion_documentaire/dossier/modifier/' . rawurlencode($new_name_fr);
      $form_state->setResponse(new RedirectResponse($new_url));
      return;
    }

    $form_state->setRebuild(TRUE);
  }

  protected function deleteUserRole($id_char, $role_libelle) {
    Database::setActiveConnection('drupal9');
    $connection = Database::getConnection();
    $connection->delete('gestion_documentaire_role')
      ->condition('id_char', $id_char)
      ->condition('role_libelle', $role_libelle)
      ->execute();
    \Drupal::messenger()->addStatus($this->t('Rôle "@role" supprimé avec succès.', ['@role' => $role_libelle]));
  }
}