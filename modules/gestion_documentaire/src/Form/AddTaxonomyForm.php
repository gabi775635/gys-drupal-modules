<?php

namespace Drupal\gestion_documentaire\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\Url;
use Drupal\Core\Database\Database;
use Drupal\Core\File\FileSystemInterface;

class AddTaxonomyForm extends FormBase {

  public function getFormId() {
    return 'gestion_documentaire_add_taxonomy_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $id_char_version = $this->getRequest()->attributes->get('id_char_version');

    $form['titre'] = [
      '#type' => 'markup',
      '#markup' => '<h2>' . '📂 '. $this->t('Create folder') . '</h2>',
    ];

    $form['home_page'] = [
      '#type' => 'markup',
      '#markup' => \Drupal\Core\Link::fromTextAndUrl(
        $this->t('Leave form'),
        Url::fromUserInput('/gestion_documentaire/dossier/' . $id_char_version)
      )->toString(),
    ];

    $form['translations_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Folder name'),
      '#collapsible' => FALSE,
    ];

    $form['translations_section']['translation_fr'] = [
      '#type' => 'textfield',
      '#title' => $this->t('French') . ' (fr)',
      '#description' => $this->t('Example: Welding formation'),
      '#required' => TRUE,
    ];

    $form['translations_section']['translation_en'] = [
      '#type' => 'textfield',
      '#title' => $this->t('English') . ' (en)',
      '#description' => $this->t('Optional. If empty, French name will be used as technical key.'),
      '#required' => FALSE,
    ];

    $form['vid'] = [
      '#type' => 'select',
      '#title' => $this->t('Vocabulary'),
      '#options' => ['dossiers' => 'dossiers'],
      '#default_value' => 'dossiers',
      '#disabled' => TRUE,
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

    $form['#cache'] = ['max-age' => 0];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Create'),
    ];

    return $form;
  }

  public function validateForm(array &$form, FormStateInterface $form_state) {
    $name_en = $form_state->getValue('translation_en');

    // Validation du format anglais uniquement si renseigné
    if (!empty($name_en)) {
      if (preg_match('/\s/', $name_en)) {
        $form_state->setErrorByName('translation_en', $this->t('Le nom anglais ne doit pas contenir d\'espaces. Utilisez des underscores (_) ou tirets (-).'));
      }

      if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name_en)) {
        $form_state->setErrorByName('translation_en', $this->t('Le nom anglais ne peut contenir que des lettres, chiffres, underscores (_) et tirets (-).'));
      }
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    Database::setActiveConnection('drupal9');
    $connection = Database::getConnection();

    $name_fr = $form_state->getValue('translation_fr');
    $name_en = $form_state->getValue('translation_en');
    $vid     = $form_state->getValue('vid');
    $id_char_version = $this->getRequest()->attributes->get('id_char_version');

    // Si pas de nom anglais, on utilise le nom français comme clé technique
    $type = !empty($name_en) ? $name_en : $name_fr;

    // Récupération du parent
    $parent_taxonomy = null;
    if ($id_char_version) {
      $tid = (int) str_replace('taxonomy_', '', $id_char_version);
      $parent_term = Term::load($tid);
      if ($parent_term) {
        $parent_taxonomy = [
          'entity_id' => $tid,
          'name'      => $parent_term->getName(),
        ];
      }
    }

    // Vérification doublon sur la clé technique
    $existing = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties([
      'name' => $type,
      'vid'  => $vid,
    ]);
    if (!empty($existing)) {
      $this->messenger()->addWarning($this->t('⚠️ Un dossier avec ce nom existe déjà.'));
      return;
    }

    $parent_tid = isset($parent_taxonomy['entity_id']) ? $parent_taxonomy['entity_id'] : NULL;

    // Création du terme
    $term = Term::create([
      'name'     => $type,
      'vid'      => $vid,
      'langcode' => !empty($name_en) ? 'en' : 'fr',
    ]);
    $term->set('parent', [$parent_tid]);

    if ($term->hasField('field_taxonomy_type')) {
      $term->set('field_taxonomy_type', $type);
    }

    $term->save();
    $term_id = $term->id();

    // Ajouter la traduction française uniquement si nom anglais fourni
    if (!empty($name_en) && !empty($name_fr)) {
      if (!$term->hasTranslation('fr')) {
        $translated_term = $term->addTranslation('fr');
      } else {
        $translated_term = $term->getTranslation('fr');
      }
      $translated_term->setName($name_fr);
      $translated_term->save();
    }

    $this->messenger()->addStatus($this->t('✅ Dossier "@name_fr" (clé technique: @type) créé.', [
      '@name_fr' => $name_fr,
      '@type'    => $type,
    ]));

    // Ajout du rôle si choisi
    $nouveau_role = $form_state->getValue('new_role');
    $tab_modif_role_insert = [];
    if (!empty($nouveau_role)) {
      $can_view      = $form_state->getValue('can_view_new') ? 1 : 0;
      $can_edit      = $form_state->getValue('can_edit_new') ? 1 : 0;
      $can_delete    = $form_state->getValue('can_delete_new') ? 1 : 0;
      $can_edit_role = $form_state->getValue('can_edit_role_new') ? 1 : 0;

      if ($can_edit_role == 1) {
        $can_edit = 1;
      }

      \Drupal::database()->insert('gestion_documentaire_role')
        ->fields([
          'id_char_version' => 'taxonomy_' . $term_id,
          'id_char'         => 'taxonomy_' . $term_id,
          'role_libelle'    => $nouveau_role,
          'can_view'        => $can_view,
          'can_edit'        => $can_edit,
          'can_delete'      => $can_delete,
          'can_edit_role'   => $can_edit_role,
        ])->execute();

      $tab_modif_role_insert[] = [$nouveau_role, $can_view, $can_edit, $can_delete, $can_edit_role];

      $this->messenger()->addStatus($this->t('✅ Nouveau rôle "@role" ajouté à ce dossier.', ['@role' => $nouveau_role]));
    }

    // Révision
    $revision_log_data = [
      'taxonomy' => [
        'nom_fr' => ['ancien' => '', 'nouveau' => $name_fr],
        'nom_en' => ['ancien' => '', 'nouveau' => $name_en],
        'type'   => ['ancien' => '', 'nouveau' => $type],
        'parent' => ['ancien' => '', 'nouveau' => $parent_taxonomy['name'] ?? ''],
      ],
      'roles_insert' => $tab_modif_role_insert,
    ];

    $term->setRevisionUserId(\Drupal::currentUser()->id());
    $term->setRevisionLogMessage(json_encode($revision_log_data, JSON_PRETTY_PRINT));
    $term->setRevisionCreationTime(time());
    $term->setNewRevision(FALSE);
    $term->save();

    // Création du dossier physique
    $query_path = "
      WITH RECURSIVE taxonomy_path AS (
        SELECT
          t.tid,
          t.name,
          p.parent_target_id,
          t.name AS full_path,
          0 AS level
        FROM taxonomy_term_field_data t
        LEFT JOIN taxonomy_term__parent p ON p.entity_id = t.tid
        WHERE t.tid = :parent_tid
          AND t.langcode = 'en'

        UNION ALL

        SELECT
          parent.tid,
          parent.name,
          pp.parent_target_id,
          CONCAT(parent.name, '/', tp.full_path) AS full_path,
          tp.level + 1
        FROM taxonomy_term_field_data parent
        JOIN taxonomy_term__parent pp ON pp.entity_id = parent.tid
        JOIN taxonomy_path tp ON tp.parent_target_id = parent.tid
        WHERE parent.langcode = 'en'
      )
      SELECT full_path
      FROM taxonomy_path
      ORDER BY level DESC
      LIMIT 1
    ";

    $parent_tid_query = $parent_tid ?? 0;
    $full_path = $connection->query($query_path, [':parent_tid' => $parent_tid_query])->fetchField();

    if (!$full_path) {
      $full_path = '';
    }

    $file_system = \Drupal::service('file_system');
    $directory   = 'public://gestion_documentaire/' . $full_path . '/' . $type;

    // $file_system->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);

    $form_state->setRedirect('gestion_documentaire.accueil', [
      'id_char_version' => 'taxonomy_' . $term_id,
    ]);
  }
}