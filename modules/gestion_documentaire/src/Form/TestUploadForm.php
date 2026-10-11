<?php

namespace Drupal\gestion_documentaire\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\media\Entity\Media;
use Drupal\taxonomy\Entity\Term;
use Drupal\Core\File\FileSystemInterface;
use Drupal\gestion_documentaire\Controller\ActionRessourceController;
use Drupal\gestion_documentaire\Controller\AccessMediaController;
use Drupal\Component\Utility\Xss;

class TestUploadForm extends FormBase {

  public $DRUPAL_ROOT;
  public $DEST_SUBDIR;
  public $MEDIA_BUNDLE;
  public $MEDIA_FIELD;
  public $TAXO_FIELD;
  public $REF_FIELD;
  public $TYPE_FIELD;
  public $LANG_FIELD;
  public $HASH_FIELD;
  public $GROUP_ID_FIELD;
  public $FILENAME_FIELD;
  public $TAXO_VOCAB;
  public $AUTHOR_UID;

  const LANG_MAP = [
    'jp' => 'ja', 'cz' => 'cs', 'cn' => 'zh', 'en' => 'uk', 'uk' => 'uk',
  ];

  public function __construct() {
    $this->DRUPAL_ROOT    = \Drupal::root();
    $this->DEST_SUBDIR    = 'gestion_documentaire';
    $this->MEDIA_BUNDLE   = 'document';
    $this->MEDIA_FIELD    = 'field_media_document';
    $this->TAXO_FIELD     = 'field_taxonomie_associee';
    $this->REF_FIELD      = 'field_media_reference';
    $this->TYPE_FIELD     = 'field_media_type';
    $this->LANG_FIELD     = 'field_media_lang';
    $this->HASH_FIELD     = 'field_media_hash';
    $this->GROUP_ID_FIELD = 'field_media_group_id';
    $this->FILENAME_FIELD = 'field_media_filename';
    $this->TAXO_VOCAB     = 'dossiers';
    $this->AUTHOR_UID     = 1433;
  }

  private function normalizeLang(string $lang): string {
    $lang = strtolower(trim($lang));
    return self::LANG_MAP[$lang] ?? $lang;
  }

  private function extractDisplayName(string $filename): string {
    $basename = pathinfo($filename, PATHINFO_FILENAME);
    $parts    = explode('_', $basename);
    $count    = count($parts);

    $last       = $parts[$count - 1] ?? '';
    $beforeLast = $parts[$count - 2] ?? '';

    if (ctype_digit($last) && ctype_digit($beforeLast)) {
      $basename = implode('_', array_slice($parts, 0, $count - 2));
    } elseif (ctype_digit($last)) {
      $basename = implode('_', array_slice($parts, 0, $count - 1));
    }

    return $basename;
  }

  private function getUsedLanguages() {
    $connection = \Drupal::database('drupal9');
    $results = $connection->query("
      SELECT DISTINCT field_media_lang_value FROM media__field_media_lang
      WHERE field_media_lang_value IS NOT NULL AND field_media_lang_value != ''
    ")->fetchAll();
    $languages = [];
    foreach ($results as $row) {
      foreach (array_filter(array_map('trim', explode(',', $row->field_media_lang_value))) as $lang) {
        if (!in_array($lang, $languages)) $languages[] = $lang;
      }
    }
    sort($languages);
    return $languages;
  }

  private function getUsedTypes() {
    $connection = \Drupal::database('drupal9');
    $media_types = $connection->query("
      SELECT DISTINCT field_media_type_value AS value
      FROM media__field_media_type
      WHERE field_media_type_value IS NOT NULL AND field_media_type_value != ''
    ")->fetchCol();
    $taxonomy_terms = $connection->query("
      SELECT DISTINCT name AS value
      FROM taxonomy_term_field_data
      WHERE name IS NOT NULL AND name != ''
    ")->fetchCol();
    $all    = array_merge($media_types, $taxonomy_terms);
    $all    = array_map('trim', $all);
    $all    = array_filter($all);
    $unique = [];
    foreach ($all as $value) { $unique[mb_strtolower($value)] = $value; }
    $all = array_values($unique);
    natcasesort($all);
    return array_values($all);
  }

  public function getFormId() {
    return 'gestion_documentaire_test_upload_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    // \Drupal::logger('gestion_doc_import')->debug('buildForm() called');

    $form['#attributes']['class'][] = 'upload-form-container';

    $used_languages = $this->getUsedLanguages();
    $used_types     = $this->getUsedTypes();

    $lang_chips = implode('', array_map(fn($l) => '<span class="lang-badge">' . htmlspecialchars($l) . '</span>', $used_languages));
    $type_chips = implode('', array_map(fn($t) => '<span class="type-badge">' . htmlspecialchars($t) . '</span>', $used_types));
  
    $form['fieldset'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Add multiple document'),
    ];

    // ── Guide de nommage ──────────────────────────────────────────────────
    $form['fieldset']['guide'] = [
      '#markup' => Xss::filterAdmin(
        '<div class="format-guide">' .
          '<div class="fg-block">' .
            '<div class="fg-block-title">' . $this->t('Filename format') . '</div>' .
            '<div class="fg-formats">' .
              '<p>' . $this->t('Type, language(s), reference(s) and optional custom name are <strong>automatically detected</strong> from the filename — order does not matter.') . '</p>' .
              '<p>' . $this->t('If nothing is specified in the filename, two fallback options are available:') . '</p>' .
              '<ul>' .
                '<li>' . $this->t('Manual input per file in the validation table') . '</li>' .
                '<li>' . $this->t('Global input using the text fields at the bottom of the page') . '</li>' .
              '</ul>' .
              '<div class="fg-format-row"><code>type__ref__lang.pdf</code><span class="fg-fmt-note">— ' . $this->t('full') . '</span></div>' .
              '<div class="fg-format-row"><code>type__ref__lang__name.pdf</code><span class="fg-fmt-note">— ' . $this->t('with custom name') . '</span></div>' .
              '<div class="fg-format-row"><code>type__ref.pdf</code><span class="fg-fmt-note">— ' . $this->t('without language') . '</span></div>' .
              '<div class="fg-format-row"><code>type__lang.pdf</code><span class="fg-fmt-note">— ' . $this->t('without reference') . '</span></div>' .
            '</div>' .
            '<div class="fg-hint">' .
              $this->t('Same behaviour as the legacy <strong>échange tempo</strong>.') . '<br>' .
              // $this->t('Separator: <code>__</code> (recommended) or <code>_</code> — with <code>_</code>, a type containing an underscore (e.g. <code>test_gab</code>) may be parsed incorrectly.') . '<br>' .
              $this->t('Ref = 6 '). $this->t('digits'). ' &nbsp;·&nbsp; ' .
              $this->t('Multi-value') . $this->t(': <code>fr,de</code> or <code>fr+de</code>') . ' &nbsp;·&nbsp; ' .
              $this->t('Alias '). $this->t(': jp→ja · cz→cs · cn→zh · en→uk') .
            '</div>' .
          '</div>' .
        '</div>'
      ),
    ];

    // ── parent_taxonomy_raw : sera rempli par JS avec le tid trouvé dans la BDD ──
    $form['fieldset']['parent_taxonomy_raw'] = [
      '#type' => 'hidden',
      '#attributes' => [
        'id' => 'parent-taxonomy-raw',
      ],
    ];

    // ── Upload ────────────────────────────────────────────────────────────
    $form['fieldset']['documents'] = [
      '#type'              => 'managed_file',
      '#title'             => $this->t('Fichiers'),
      '#upload_location'   => 'public://gestion_documentaire/',
      '#multiple'          => TRUE,
      '#upload_validators' => ['file_validate_extensions' => ['pdf doc docx xlsx txt']],
    ];

    // ── Résultats JS ──────────────────────────────────────────────────────
    $form['fieldset']['validation_results'] = [
      '#markup' => Xss::filterAdmin('<div id="validation-results" class="validation-results"></div>'),
    ];

    // ── Champs de fallback (affichage uniquement, valeurs injectées par JS) ─
    $form['fieldset']['free_name_fields'] = [
      '#type'       => 'container',
      '#attributes' => ['id' => 'free-name-fields', 'class' => ['free-name-fields'], 'style' => 'display:none'],
    ];

    $form['fieldset']['free_name_fields']['fallback_title'] = [
      '#markup' => Xss::filterAdmin('<div class="fallback-title">Valeurs pour les fichiers non reconnus</div>'),
    ];

    $form['fieldset']['free_name_fields']['manual_type'] = [
      '#type'        => 'textfield',
      '#title'       => $this->t('Type'),
      '#placeholder' => 'datasheet',
      '#description' => $this->t(''),
      '#required'    => FALSE,
    ];

    $form['fieldset']['free_name_fields']['free_name_langs'] = [
      '#type'        => 'textfield',
      '#title'       => $this->t('Langue(s)'),
      '#placeholder' => 'fr, en',
      '#description' => $this->t(''),
      '#required'    => FALSE,
    ];

    $form['fieldset']['free_name_fields']['manual_refs'] = [
      '#type'        => 'textfield',
      '#title'       => $this->t('Référence(s)'),
      '#placeholder' => '055123',
      '#description' => $this->t(''),
      '#required'    => FALSE,
    ];

    // ── Champ caché is_structured ─────────────────────────────────────────
    $form['fieldset']['is_structured'] = [
      '#type'          => 'hidden',
      '#default_value' => 1,
    ];

    // ── Progress ──────────────────────────────────────────────────────────
    $form['fieldset']['progress'] = [
      '#markup' => Xss::filterAdmin('
        <div id="progress-wrapper" class="progress-wrapper">
          <div class="progress-bar"><div id="progress-fill" class="progress-fill"></div></div>
          <div id="progress-log" class="progress-log"></div>
        </div>
      '),
    ];

    // ── Submit ────────────────────────────────────────────────────────────
    $form['fieldset']['submit'] = [
      '#type'       => 'submit',
      '#value'      => $this->t('Importer'),
      '#attributes' => ['id' => 'submit-upload-btn', 'class' => ['submit-button']],
    ];

    $form['#attached']['library'][] = 'gestion_documentaire/upload_simple';
    $form['#attached']['drupalSettings']['uploadValidator']['types'] = $used_types;

    // Passer tous les tids et leurs noms pour la recherche JS
    $type_tid_map = [];
    $terms_all = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree('dossiers');
    foreach ($terms_all as $term) {
      $type_tid_map[mb_strtolower(trim($term->name))] = [
        'tid' => $term->tid,
        'name' => $term->name,
        'depth' => $term->depth,
      ];
    }
    $form['#attached']['drupalSettings']['uploadValidator']['typeTidMap'] = $type_tid_map;

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    // \Drupal::logger('gestion_doc_import')->debug('=== submitForm() START ===');

    $file_ids   = $form_state->getValue('documents');
    $parent_tid = $form_state->getValue('parent_taxonomy_raw');

    // Récupérer file_meta directement depuis la requête POST
    $request    = \Drupal::request();
    $file_metas = $request->request->all()['file_meta'] ?? [];

    // \Drupal::logger('gestion_doc_import')->debug('submitForm | parent_tid: @p', ['@p' => $parent_tid]);
    // \Drupal::logger('gestion_doc_import')->debug('submitForm | file_metas RAW: @m', ['@m' => json_encode($file_metas)]);

    if (empty($file_ids)) {
      $this->messenger()->addWarning($this->t('Aucun fichier uploadé.'));
      return;
    }

    if (empty($parent_tid)) {
      $type_tid_map = [];
      $terms_all    = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree('dossiers');
      foreach ($terms_all as $term) {
        $type_tid_map[mb_strtolower(trim($term->name))] = $term->tid;
      }

      if (!empty($file_metas)) {
        foreach ($file_metas as $meta) {
          $type = mb_strtolower(trim($meta['type'] ?? ''));
          if (isset($type_tid_map[$type])) {
            $parent_tid = $type_tid_map[$type];
            break;
          }
        }
      }

      if (empty($parent_tid)) {
        $first_term = reset($type_tid_map);
        if ($first_term) {
          $parent_tid = $first_term;
        } else {
          $this->messenger()->addError($this->t('Impossible de déterminer le dossier parent.'));
          return;
        }
      }
    }

    // Indexer les metas par nom de fichier
    $meta_by_name = [];
    if (!empty($file_metas)) {
      foreach ($file_metas as $meta) {
        $original_name = trim($meta['original_name'] ?? '');
        if ($original_name) {
          $meta_by_name[$original_name] = $meta;
          // \Drupal::logger('gestion_doc_import')->debug('Meta indexé: @name', ['@name' => $original_name]);
        }
      }
    }

    $created          = [];
    $updated          = [];
    $errors           = [];
    $fs               = \Drupal::service('file_system');
    $connection       = \Drupal::database('drupal9');
    $default_langcode = \Drupal::service('language_manager')->getDefaultLanguage()->getId();

    // ── Vérification d'accès sur le dossier cible ─────────────────────────
$account = \Drupal::currentUser();
$id_char_to_check = $parent_tid ? 'taxonomy_' . $parent_tid : null;

if (!$id_char_to_check) {
  $this->messenger()->addError($this->t('❌ Impossible de déterminer le dossier cible.'));
  return;
}

$perms = AccessMediaController::getResourcePermissions($account, $id_char_to_check);

if (empty($perms['can_edit'])) {
  $this->messenger()->addError($this->t(
    "❌ Vous n'avez pas le droit de déposer des documents dans ce dossier (@tid).",
    ['@tid' => $id_char_to_check]
  ));
  return;
}

    foreach ($file_ids as $fid) {
      $file = File::load($fid);
      if (!$file) {
        $errors[] = "Impossible de charger le fichier ID $fid";
        continue;
      }

      $file->setPermanent();
      $file->save();

      $filename  = $file->getFilename();
      $file_path = $fs->realpath($file->getFileUri());

      // \Drupal::logger('gestion_doc_import')->debug('Fichier: @fn | meta_by_name keys: @keys', [ '@fn'   => $filename, '@keys' => implode(', ', array_keys($meta_by_name)),  ]);

      $meta = $meta_by_name[$filename] ?? null;

      if (!$meta) {
        foreach ($meta_by_name as $orig_name => $m) {
          if (strpos($orig_name, $filename) !== false || strpos($filename, $orig_name) !== false) {
            $meta = $m;
            // \Drupal::logger('gestion_doc_import')->debug('Meta trouvé par correspondance: @orig -> @file', ['@orig' => $orig_name,'@file' => $filename, ]);
            break;
          }
        }
      }

      if (!$meta) {
        $errors[] = "$filename : métadonnées introuvables (clés disponibles: " . implode(', ', array_keys($meta_by_name)) . ")";
        continue;
      }

      $type        = trim($meta['type'] ?? '');
      $langs_raw   = $meta['langs'] ?? '';
      $refs_raw    = $meta['refs'] ?? '';
      $custom_name = trim($meta['custom_name'] ?? '');

      $langs = array_values(array_filter(array_map([$this, 'normalizeLang'], array_map('trim', explode(',', $langs_raw)))));
      $refs  = array_values(array_filter(array_map('trim', explode(',', $refs_raw))));

      // \Drupal::logger('gestion_doc_import')->debug('Processing @fn → type:@t langs:@l refs:@r name:@n', ['@fn' => $filename,'@t'  => $type,'@l'  => implode(',', $langs),'@r'  => implode(',', $refs),'@n'  => $custom_name,]);

      try {
        $result = $this->processMediaFile($file_path, $parent_tid, $type, $langs, $refs, $custom_name, $fs, $connection, $default_langcode);
        $result['action'] === 'updated' ? ($updated[] = $result) : ($created[] = $result);
      }
      catch (\Exception $e) {
        $errors[] = "$filename : " . $e->getMessage();
        // \Drupal::logger('gestion_doc_import')->error('ERREUR @file : @error', [ '@file'  => $filename,'@error' => $e->getMessage(),]);
      }
    }

    foreach ($created as $item) {
      $this->messenger()->addStatus($this->t('✅ Créé : @name (ID: @id)', ['@name' => $item['media_name'],'@id'   => $item['media_id'],]));
    }
    foreach ($updated as $item) {
      $this->messenger()->addStatus($this->t('🔄 Mis à jour : @name (ID: @id)', [ '@name' => $item['media_name'], '@id'   => $item['media_id'],]));
    }
    if (!empty($errors)) {
      $this->messenger()->addError($this->t('❌ @count erreur(s) : @errors', ['@count'  => count($errors), '@errors' => implode(' | ', $errors),]));
    }
  }

  // ============================================================
  // LOGIQUE PRINCIPALE (portée depuis transfert_media_one)
  // ============================================================

  /**
   * Point d'entrée : gère create/update avec la logique complète de
   * transfert_media_one (hash → refs → nettoyage → création).
   */
  private function processMediaFile($file_path, $parent_tid, $type, array $langs, array $refs, $custom_name, $fs, $connection, $default_langcode) {
    if (!file_exists($file_path)) {
      throw new \Exception("Fichier introuvable: $file_path");
    }

    $filename  = basename($file_path);
    $file_hash = hash_file('sha256', $file_path);

    // \Drupal::logger('gestion_doc_import')->debug('processMediaFile | file:@f hash:@h type:@t langs:@l refs:@r name:@n', ['@f' => $filename, '@h' => $file_hash, '@t' => $type,'@l' => implode(',', $langs), '@r' => implode(',', $refs), '@n' => $custom_name,]);

    // Détecter le storage scheme depuis les médias existants du même type
    $storage_scheme = $this->detectStorageScheme($type, $connection);

    // ── MODE NOM PERSONNALISÉ (sans référence numérique) ──────────────────
    $is_custom_name_mode = !empty($custom_name) && empty($refs);

    if ($is_custom_name_mode) {
      // \Drupal::logger('gestion_doc_import')->debug('Mode: NOM PERSONNALISÉ (sans référence)');

      $ext             = pathinfo($filename, PATHINFO_EXTENSION);
      $search_filename = $custom_name . '.' . $ext;

      $existing_by_name = $this->findMediaByExactName($search_filename, $type, $connection);

      if ($existing_by_name) {
        // \Drupal::logger('gestion_doc_import')->debug('Fichier trouvé par nom → mise à jour');
        $this->replaceFileOnly($existing_by_name->mid, $file_path, $file_hash, $fs, $connection);
        $media = Media::load($existing_by_name->mid);
        return ['action' => 'updated', 'media_id' => $existing_by_name->mid, 'media_name' => $media ? $media->getName() : $filename];
      }

      // Pas trouvé → tomber dans la création
      // \Drupal::logger('gestion_doc_import')->debug('Aucun fichier trouvé par nom → création');
      $media_id = $this->createNewMedia($file_path, $parent_tid, $type, implode(',', $langs), $file_hash, $fs, $connection, $default_langcode, $custom_name, '', $storage_scheme);
      $media    = Media::load($media_id);
      return ['action' => 'created', 'media_id' => $media_id, 'media_name' => $media ? $media->getName() : $filename];
    }

    // ── ÉTAPE 1 : Vérification hash ───────────────────────────────────────
    // \Drupal::logger('gestion_doc_import')->debug('Étape 1 : vérification hash SHA256');

    $existing_with_hash = $this->findMediaByHash($file_hash, $type, $connection);

    if ($existing_with_hash) {
      // \Drupal::logger('gestion_doc_import')->debug('Hash trouvé → Media ID: @mid', ['@mid' => $existing_with_hash->mid]);

      // Étape 1.1 + 1.2 : Nettoyage des doublons (triplet ref+lang+type)
      $this->cleanDuplicateTriplets($refs, $langs, $type, $existing_with_hash->mid, $connection);

      // Étape 1.3 : Fusion refs + langs dans le média existant
      $existing_refs_array  = array_filter(array_map('trim', explode(',', $existing_with_hash->field_media_reference_value ?? '')));
      $existing_langs_array = array_filter(array_map('trim', explode(',', $existing_with_hash->field_media_lang_value ?? '')));

      $merged_refs  = array_values(array_unique(array_merge($existing_refs_array, $refs)));
      $merged_langs = array_values(array_unique(array_merge($existing_langs_array, $langs)));

      $this->replaceMediaFile(
        $existing_with_hash->mid,
        $file_path, $file_hash,
        $merged_refs, $merged_langs,
        $fs, $connection, $parent_tid, $custom_name, $storage_scheme
      );

      $media = Media::load($existing_with_hash->mid);
      return ['action' => 'updated', 'media_id' => $existing_with_hash->mid, 'media_name' => $media ? $media->getName() : $filename];
    }

    // \Drupal::logger('gestion_doc_import')->debug('Hash non trouvé → vérification des références');

    // ── ÉTAPE 2 : Vérification des références existantes ─────────────────
    $medias_to_clean      = [];
    $found_existing_refs  = false;

    foreach ($refs as $ref) {
      $all_medias = $connection->query("
        SELECT m.mid, mfr.field_media_reference_value, mfl.field_media_lang_value, mft.field_media_type_value
        FROM media_field_data m
        LEFT JOIN media__field_media_reference mfr ON m.mid = mfr.entity_id
        LEFT JOIN media__field_media_lang mfl ON m.mid = mfl.entity_id
        LEFT JOIN media__field_media_type mft ON m.mid = mft.entity_id
        WHERE mft.field_media_type_value = :type
      ", [':type' => $type])->fetchAll();

      foreach ($all_medias as $row) {
        $row_refs  = array_map('trim', explode(',', $row->field_media_reference_value));
        $row_langs = array_map('trim', explode(',', $row->field_media_lang_value));

        foreach ($langs as $lang) {
          if (in_array($ref, $row_refs) && in_array($lang, $row_langs)) {
            $found_existing_refs = true;
            if (!isset($medias_to_clean[$row->mid])) {
              $medias_to_clean[$row->mid] = [
                'refs_to_remove'  => [],
                'langs_to_remove' => [],
                'current_refs'    => $row->field_media_reference_value,
                'current_langs'   => $row->field_media_lang_value,
              ];
            }
            $medias_to_clean[$row->mid]['refs_to_remove'][]  = $ref;
            $medias_to_clean[$row->mid]['langs_to_remove'][] = $lang;
          }
        }
      }
    }

    // ── ÉTAPE 3 : Nettoyage des anciens médias ────────────────────────────
    if (!empty($medias_to_clean)) {
      $this->cleanMedias($medias_to_clean, $connection);
    }

    // ── ÉTAPE 4 : Vérification finale + création ──────────────────────────
    // Double-check : si un triplet existe encore, pas de création
    foreach ($refs as $ref) {
      foreach ($langs as $lang) {
        if ($this->mediaExistsWithRefAndLang($ref, $lang, $type, $connection)) {
          // \Drupal::logger('gestion_doc_import')->debug('Triplet ref=@r lang=@l type=@t déjà existant → pas de création', ['@r' => $ref, '@l' => $lang, '@t' => $type,]);
          // On met quand même à jour les refs/langs du média existant
          $existing = $this->findMediaByRefAndType($ref, $type, $connection);
          if ($existing) {
            $existing_refs_array  = array_filter(array_map('trim', explode(',', $existing->field_media_reference_value ?? '')));
            $existing_langs_array = array_filter(array_map('trim', explode(',', $existing->field_media_lang_value ?? '')));
            $merged_refs          = array_values(array_unique(array_merge($existing_refs_array, $refs)));
            $merged_langs         = array_values(array_unique(array_merge($existing_langs_array, $langs)));
            $this->replaceMediaFile($existing->mid, $file_path, $file_hash, $merged_refs, $merged_langs, $fs, $connection, $parent_tid, $custom_name, $storage_scheme);
            $media = Media::load($existing->mid);
            return ['action' => 'updated', 'media_id' => $existing->mid, 'media_name' => $media ? $media->getName() : $filename];
          }
        }
      }
    }

    // \Drupal::logger('gestion_doc_import')->debug('Aucun média existant → création');
    $media_id = $this->createNewMedia($file_path, $parent_tid, $type, implode(',', $langs), $file_hash, $fs, $connection, $default_langcode, $custom_name, implode(',', $refs), $storage_scheme);
    $media    = Media::load($media_id);
    return ['action' => 'created', 'media_id' => $media_id, 'media_name' => $media ? $media->getName() : $filename];
  }

  // ============================================================
  // MÉTHODES UTILITAIRES - REVISION LOG
  // ============================================================

  /**
   * Récupère l'état actuel d'un média depuis la base (snapshot avant modification).
   */
  private function getMediaCurrentState(int $mid, $connection): array {
    $row = $connection->query("
      SELECT
        m.name,
        t.name         AS parent_taxonomy,
        t.tid          AS parent_taxonomy_id,
        mft.field_media_type_value      AS type,
        mfl.field_media_lang_value      AS lang,
        mfr.field_media_reference_value AS reference,
        mfh.field_media_hash_value      AS hash
      FROM media_field_data m
      LEFT JOIN media__field_taxonomie_associee link ON m.mid = link.entity_id
      LEFT JOIN taxonomy_term_field_data t           ON link.field_taxonomie_associee_target_id = t.tid
      LEFT JOIN media__field_media_type mft          ON m.mid = mft.entity_id
      LEFT JOIN media__field_media_lang mfl          ON m.mid = mfl.entity_id
      LEFT JOIN media__field_media_reference mfr     ON m.mid = mfr.entity_id
      LEFT JOIN media__field_media_hash mfh          ON m.mid = mfh.entity_id
      WHERE m.mid = :mid
      LIMIT 1
    ", [':mid' => $mid])->fetchAssoc();

    return $row ?: [
      'name'               => '',
      'parent_taxonomy'    => '',
      'parent_taxonomy_id' => null,
      'type'               => '',
      'lang'               => '',
      'reference'          => '',
      'hash'               => '',
    ];
  }

  /**
   * Récupère le nom d'un terme taxonomique depuis son tid.
   */
  private function getTaxonomyName(int $tid, $connection): string {
    if (!$tid) return '';
    return (string) $connection->query(
      "SELECT name FROM taxonomy_term_field_data WHERE tid = :tid LIMIT 1",
      [':tid' => $tid]
    )->fetchField();
  }

  /**
   * Récupère le tid taxonomique associé à un média.
   */
  private function getMediaTaxoTid(int $mid, $connection): int {
    return (int) $connection->query(
      "SELECT field_taxonomie_associee_target_id FROM media__field_taxonomie_associee WHERE entity_id = :mid LIMIT 1",
      [':mid' => $mid]
    )->fetchField();
  }

  /**
   * Construit le tableau de log de révision au format identique à UpdateMediaForm.
   */
  private function buildRevisionLog(
    array  $old_state,
    string $new_filename,
    string $new_taxonomy,
    string $new_type,
    array  $new_refs,
    array  $new_langs,
    string $new_hash
  ): array {
    return [
      'media'  => [
        'nom'        => ['ancien' => $old_state['name']            ?? '', 'nouveau' => $new_filename],
        'parent'     => ['ancien' => $old_state['parent_taxonomy'] ?? '', 'nouveau' => $new_taxonomy],
        'references' => implode(',', $new_refs),
        'lang'       => implode(',', $new_langs),
        'type'       => $new_type,
        'hash'       => $new_hash,
      ],
      'roles_update' => [],
      'roles_insert' => [],
      'roles_delete' => [],
    ];
  }

  // ============================================================
  // MÉTHODES UTILITAIRES - STOCKAGE / RECHERCHE
  // ============================================================

  /**
   * Détecte le storage scheme (public:// ou private://) depuis les médias
   * existants du même type, comme dans transfert_media_one.
   */
  private function detectStorageScheme(string $type, $connection): string {
    $uri = $connection->query("
      SELECT fm.uri
      FROM media__field_media_type mft
      INNER JOIN media__field_media_document mfd ON mft.entity_id = mfd.entity_id
      INNER JOIN file_managed fm ON fm.fid = mfd.field_media_document_target_id
      WHERE mft.field_media_type_value = :type
      AND fm.uri IS NOT NULL
      LIMIT 1
    ", [':type' => $type])->fetchField();

    if ($uri && str_starts_with($uri, 'private://')) {
      return 'private://';
    }
    return 'public://';
  }

  /**
   * Étapes 1.1 + 1.2 : nettoie les médias qui contiennent déjà un triplet
   * (ref, lang, type) identique, en excluant le média principal.
   */
  private function cleanDuplicateTriplets(array $refs, array $langs, string $type, int $exclude_mid, $connection): void {
    $medias_to_clean = [];

    foreach ($refs as $ref) {
      foreach ($langs as $lang) {
        $other_medias = $connection->query("
          SELECT m.mid, mfr.field_media_reference_value, mfl.field_media_lang_value
          FROM media_field_data m
          LEFT JOIN media__field_media_reference mfr ON m.mid = mfr.entity_id
          LEFT JOIN media__field_media_lang mfl ON m.mid = mfl.entity_id
          LEFT JOIN media__field_media_type mft ON m.mid = mft.entity_id
          WHERE mft.field_media_type_value = :type
          AND m.mid != :exclude_mid
        ", [':type' => $type, ':exclude_mid' => $exclude_mid])->fetchAll();

        foreach ($other_medias as $row) {
          $row_refs  = array_map('trim', explode(',', $row->field_media_reference_value));
          $row_langs = array_map('trim', explode(',', $row->field_media_lang_value));

          if (in_array($ref, $row_refs) && in_array($lang, $row_langs)) {
            if (!isset($medias_to_clean[$row->mid])) {
              $medias_to_clean[$row->mid] = [
                'refs_to_remove'  => [],
                'langs_to_remove' => [],
                'current_refs'    => $row->field_media_reference_value,
                'current_langs'   => $row->field_media_lang_value,
              ];
            }
            $medias_to_clean[$row->mid]['refs_to_remove'][]  = $ref;
            $medias_to_clean[$row->mid]['langs_to_remove'][] = $lang;
          }
        }
      }
    }

    $this->cleanMedias($medias_to_clean, $connection);
  }

  /**
   * Exécute le nettoyage d'une liste de médias :
   * supprime les langues concernées et supprime le média si plus rien ne reste.
   */
  private function cleanMedias(array $medias_to_clean, $connection): void {
    foreach ($medias_to_clean as $mid => $clean_data) {
      // \Drupal::logger('gestion_doc_import')->debug('Nettoyage Media ID: @mid', ['@mid' => $mid]);

      $old_media = Media::load($mid);
      if (!$old_media) continue;

      // Snapshot avant modification pour le log
      $old_state = $this->getMediaCurrentState($mid, $connection);

      $current_refs_list  = array_map('trim', explode(',', $clean_data['current_refs']));
      $current_langs_list = array_map('trim', explode(',', $clean_data['current_langs']));
      $refs_to_remove     = array_unique($clean_data['refs_to_remove']);
      $langs_to_remove    = array_unique($clean_data['langs_to_remove']);

      // Supprimer les langues du triplet concerné
      foreach ($refs_to_remove as $ref_to_remove) {
        foreach ($langs_to_remove as $lang_to_remove) {
          if (in_array($ref_to_remove, $current_refs_list) && in_array($lang_to_remove, $current_langs_list)) {
            $current_langs_list = array_values(array_diff($current_langs_list, [$lang_to_remove]));
          }
        }
      }

      $new_refs_string  = implode(',', $current_refs_list);
      $new_langs_string = implode(',', $current_langs_list);

      // Si plus aucune langue (ou référence) → supprimer le média
      if (empty($new_refs_string) || empty($new_langs_string)) {
        // \Drupal::logger('gestion_doc_import')->debug('Aucune langue/référence restante → suppression Media ID: @mid', ['@mid' => $mid]);
        try {
          // Tenter via ActionRessourceController si disponible (comme dans transfert_media_one)
          $version_row = $connection->query("
            SELECT id_char_version
            FROM drupal9_2.gestion_documentaire_ressource_view
            WHERE entity_id = :mid
            LIMIT 1
          ", [':mid' => $mid])->fetch();

          if ($version_row) {
            ActionRessourceController::deleteMedia($version_row->id_char_version);
          } else {
            $old_media->delete();
            $this->messenger()->addStatus($this->t('🗑️ Ancien média supprimé : @name (ID: @id)', [
              '@name' => $old_media->getName(),
              '@id'   => $old_media->id(),
            ]));
          }
        }
        catch (\Throwable $e) {
          // \Drupal::logger('gestion_doc_import')->error('Erreur suppression Media @mid : @err', ['@mid' => $mid, '@err' => $e->getMessage()]);
          try {
            $old_media->delete();
            $this->messenger()->addStatus($this->t('🗑️ Ancien média supprimé (fallback) : @name (ID: @id)', [
              '@name' => $old_media->getName(),
              '@id'   => $old_media->id(),
            ]));
          } catch (\Throwable $e2) {}
        }
      } else {
        // Récupérer le tid taxonomique pour le log
        $taxo_tid      = $this->getMediaTaxoTid($mid, $connection);
        $new_taxonomy  = $this->getTaxonomyName($taxo_tid, $connection);
        $new_refs_arr  = array_values(array_filter(array_map('trim', explode(',', $new_refs_string))));
        $new_langs_arr = array_values(array_filter(array_map('trim', explode(',', $new_langs_string))));

        $old_media->set($this->REF_FIELD, $new_refs_string);
        $old_media->set($this->LANG_FIELD, $new_langs_string);

        $log = $this->buildRevisionLog(
          $old_state,
          $old_media->getName(),
          $new_taxonomy,
          $old_media->get($this->TYPE_FIELD)->value ?? '',
          $new_refs_arr,
          $new_langs_arr,
          $old_state['hash']
        );
        $old_media->setNewRevision(TRUE);
        $old_media->setRevisionLogMessage(json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $old_media->setRevisionCreationTime(time());
        $old_media->setRevisionUserId($this->AUTHOR_UID);
        $old_media->save();

        // \Drupal::logger('gestion_doc_import')->debug('Media @mid mis à jour : refs=@r langs=@l', ['@mid' => $mid, '@r' => $new_refs_string, '@l' => $new_langs_string,]);
      }
    }
  }

  /**
   * Cherche un média par hash SHA256 et type (avec LIKE sur le type comme
   * dans transfert_media_one).
   */
  private function findMediaByHash(string $file_hash, string $type, $connection) {
    return $connection->query("
      SELECT m.mid, m.name, mfh.field_media_hash_value,
             mfr.field_media_reference_value, mfl.field_media_lang_value,
             mft.field_media_type_value
      FROM media_field_data m
      LEFT JOIN media__field_media_hash mfh ON m.mid = mfh.entity_id
      LEFT JOIN media__field_media_reference mfr ON m.mid = mfr.entity_id
      LEFT JOIN media__field_media_lang mfl ON m.mid = mfl.entity_id
      LEFT JOIN media__field_media_type mft ON m.mid = mft.entity_id
      WHERE mfh.field_media_hash_value = :hash
      AND mft.field_media_type_value LIKE :type
      LIMIT 1
    ", [':hash' => $file_hash, ':type' => '%' . $type . '%'])->fetch() ?: null;
  }

  /**
   * Cherche un média par nom exact de fichier et type.
   */
  private function findMediaByExactName(string $filename, string $type, $connection) {
    return $connection->query("
      SELECT m.mid, m.name, mfl.field_media_lang_value, mfr.field_media_reference_value
      FROM media_field_data m
      LEFT JOIN media__field_media_lang mfl ON m.mid = mfl.entity_id
      LEFT JOIN media__field_media_type mft ON m.mid = mft.entity_id
      LEFT JOIN media__field_media_reference mfr ON m.mid = mfr.entity_id
      WHERE m.name = :filename
      AND mft.field_media_type_value = :type
      LIMIT 1
    ", [':filename' => $filename, ':type' => $type])->fetch() ?: null;
  }

  /**
   * Cherche un média par référence et type (recherche CSV multi-valeurs).
   */
  private function findMediaByRefAndType(string $reference, string $type, $connection) {
    return $connection->query("
      SELECT m.mid, m.name, mfl.field_media_lang_value, mfr.field_media_reference_value
      FROM media_field_data m
      LEFT JOIN media__field_media_reference mfr ON m.mid = mfr.entity_id
      LEFT JOIN media__field_media_type mft ON m.mid = mft.entity_id
      LEFT JOIN media__field_media_lang mfl ON m.mid = mfl.entity_id
      WHERE (mfr.field_media_reference_value = :ref_exact
             OR mfr.field_media_reference_value LIKE :ref_start
             OR mfr.field_media_reference_value LIKE :ref_middle
             OR mfr.field_media_reference_value LIKE :ref_end)
      AND mft.field_media_type_value = :type
      LIMIT 1
    ", [
      ':ref_exact'  => $reference,
      ':ref_start'  => $reference . ',%',
      ':ref_middle' => '%,' . $reference . ',%',
      ':ref_end'    => '%,' . $reference,
      ':type'       => $type,
    ])->fetch() ?: null;
  }

  /**
   * Vérifie si un triplet (ref, lang, type) existe déjà en base.
   */
  private function mediaExistsWithRefAndLang(string $ref, string $lang, string $type, $connection): bool {
    $result = $connection->query("
      SELECT m.mid
      FROM media_field_data m
      LEFT JOIN media__field_media_reference mfr ON m.mid = mfr.entity_id
      LEFT JOIN media__field_media_lang mfl ON m.mid = mfl.entity_id
      LEFT JOIN media__field_media_type mft ON m.mid = mft.entity_id
      WHERE mft.field_media_type_value = :type
      AND mfr.field_media_reference_value LIKE :ref
      AND mfl.field_media_lang_value LIKE :lang
    ", [':type' => $type, ':ref' => '%' . $ref . '%', ':lang' => '%' . $lang . '%'])->fetch();

    return (bool) $result;
  }

  /**
   * Met à jour uniquement le fichier physique d'un média (mode nom personnalisé),
   * en conservant l'entité File existante (comme dans transfert_media_one).
   */
  private function replaceFileOnly(int $mid, string $file_path, string $file_hash, $fs, $connection): void {
    $media = Media::load($mid);
    if (!$media) throw new \Exception("Impossible de charger le média ID $mid.");

    // Snapshot avant modification pour le log
    $old_state = $this->getMediaCurrentState($mid, $connection);
    $taxo_tid  = $this->getMediaTaxoTid($mid, $connection);
    $taxonomy_name = $this->getTaxonomyName($taxo_tid, $connection);

    $old_file_field = $media->get($this->MEDIA_FIELD)->first();
    if (!$old_file_field) return;

    $file_entity = File::load($old_file_field->target_id);
    if (!$file_entity) return;

    $old_uri = $file_entity->getFileUri();

    // Écraser le fichier physique sur place (ID File conservé)
    $fs->copy($file_path, $old_uri, FileSystemInterface::EXISTS_REPLACE);

    $file_entity->setSize(filesize($file_path));
    $file_entity->setMimeType(\Drupal::service('file.mime_type.guesser')->guessMimeType($old_uri));
    $file_entity->save();

    $media->set($this->HASH_FIELD, $file_hash);
    $media->set($this->FILENAME_FIELD, $this->extractDisplayName($media->getName()));

    // Refs et langs inchangées — on les relit depuis l'état actuel
    $current_refs  = array_values(array_filter(array_map('trim', explode(',', $old_state['reference'] ?? ''))));
    $current_langs = array_values(array_filter(array_map('trim', explode(',', $old_state['lang']      ?? ''))));

    $log = $this->buildRevisionLog(
      $old_state,
      $media->getName(),
      $taxonomy_name,
      $old_state['type'],
      $current_refs,
      $current_langs,
      $file_hash
    );
    $media->setNewRevision(TRUE);
    $media->setRevisionLogMessage(json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $media->setRevisionCreationTime(time());
    $media->setRevisionUserId($this->AUTHOR_UID);
    $media->save();
  }

  /**
   * Remplace le fichier d'un média existant et met à jour refs + langs.
   * Reconstruit l'arborescence taxonomique pour le chemin de destination,
   * comme dans transfert_media_one.
   */
  private function replaceMediaFile(int $mid, string $file_path, string $file_hash, array $final_refs, array $final_langs, $fs, $connection, $parent_tid, string $custom_name = '', string $storage_scheme = 'public://'): void {
    $media = Media::load($mid);
    if (!$media) throw new \Exception("Impossible de charger le média ID $mid.");

    // Snapshot avant modification pour le log
    $old_state = $this->getMediaCurrentState($mid, $connection);

    $ext          = pathinfo(basename($file_path), PATHINFO_EXTENSION);
    $new_filename = !empty($custom_name) ? $custom_name . '.' . $ext : $media->getName();

    // Récupérer le tid taxonomique du média (priorité) ou utiliser parent_tid
    $taxo_field = $media->get($this->TAXO_FIELD)->first();
    $taxo_tid   = $taxo_field ? $taxo_field->target_id : $parent_tid;

    $dest_dir      = $this->buildDestinationDir($taxo_tid, $storage_scheme, $connection, $fs);
    $dest          = $dest_dir . '/' . $new_filename;
    $new_taxonomy  = $this->getTaxonomyName((int) $taxo_tid, $connection);

    $old_file_field = $media->get($this->MEDIA_FIELD)->first();
    $old_file_id    = $old_file_field ? $old_file_field->target_id : null;

    if ($old_file_id) {
      $file_entity = File::load($old_file_id);

      if ($file_entity) {
        // Mettre à jour l'entité File existante (ID conservé, comme transfert_media_one)
        $fs->copy($file_path, $dest, FileSystemInterface::EXISTS_REPLACE);
        $file_entity->setFileUri($dest);
        $file_entity->setFilename($new_filename);
        $file_entity->setSize(filesize($file_path));
        $file_entity->setMimeType(\Drupal::service('file.mime_type.guesser')->guessMimeType($dest));
        $file_entity->save();
      } else {
        // File entity introuvable → en créer une nouvelle
        $fs->copy($file_path, $dest, FileSystemInterface::EXISTS_REPLACE);
        $file_entity = File::create(['uri' => $dest, 'uid' => $this->AUTHOR_UID, 'status' => 1]);
        $file_entity->save();
        $media->set($this->MEDIA_FIELD, ['target_id' => $file_entity->id()]);
      }
    } else {
      // Pas de fichier du tout → création
      $fs->copy($file_path, $dest, FileSystemInterface::EXISTS_REPLACE);
      $file_entity = File::create(['uri' => $dest, 'uid' => $this->AUTHOR_UID, 'status' => 1]);
      $file_entity->save();
      $media->set($this->MEDIA_FIELD, ['target_id' => $file_entity->id()]);
    }

    if ($media->getName() !== $new_filename) {
      $media->set('name', $new_filename);
    }

    $media->set($this->HASH_FIELD, $file_hash);
    $media->set($this->LANG_FIELD, implode(',', $final_langs));
    $media->set($this->FILENAME_FIELD, $this->extractDisplayName($new_filename));
    if (!empty($final_refs)) {
      $media->set($this->REF_FIELD, implode(',', $final_refs));
    }

    $log = $this->buildRevisionLog(
      $old_state,
      $new_filename,
      $new_taxonomy,
      $media->get($this->TYPE_FIELD)->value ?? $old_state['type'],
      $final_refs,
      $final_langs,
      $file_hash
    );
    $media->setNewRevision(TRUE);
    $media->setRevisionLogMessage(json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $media->setRevisionCreationTime(time());
    $media->setRevisionUserId($this->AUTHOR_UID);
    $media->save();
  }

  /**
   * Crée un nouveau média avec son fichier physique.
   */
  private function createNewMedia(string $file_path, $parent_tid, string $media_type_value, string $media_lang_value, string $file_hash, $fs, $connection, string $default_langcode, string $custom_name = '', string $reference = '', string $storage_scheme = 'public://'): int {
    $max_mid      = (int) $connection->query('SELECT MAX(mid) FROM media')->fetchField();
    $next_mid     = $max_mid + 1;
    $ext          = pathinfo(basename($file_path), PATHINFO_EXTENSION);
    $new_filename = (!empty($custom_name) ? $custom_name : $media_type_value) . '_' . $next_mid . '.' . $ext;

    $dest_dir = $this->buildDestinationDir($parent_tid, $storage_scheme, $connection, $fs);
    $dest     = $dest_dir . '/' . $new_filename;

    $fs->copy($file_path, $dest, FileSystemInterface::EXISTS_REPLACE);

    $file_entity = File::create(['uri' => $dest, 'uid' => $this->AUTHOR_UID, 'status' => 1]);
    $file_entity->save();

    $taxonomy_name = $this->getTaxonomyName((int) $parent_tid, $connection);
    $refs_arr      = array_values(array_filter(array_map('trim', explode(',', $reference))));
    $langs_arr     = array_values(array_filter(array_map('trim', explode(',', $media_lang_value))));

    $log = [
      'media'  => [
        'nom'        => ['ancien' => '', 'nouveau' => $new_filename],
        'parent'     => ['ancien' => '', 'nouveau' => $taxonomy_name],
        'references' => implode(',', $refs_arr),
        'lang'       => implode(',', $langs_arr),
        'type'       => $media_type_value,
        'hash'       => $file_hash,
      ],
      'roles_update' => [],
      'roles_insert' => [],
      'roles_delete' => [],
      'modif'        => 1,
    ];

    $media = Media::create([
      'bundle'   => $this->MEDIA_BUNDLE,
      'uid'      => $this->AUTHOR_UID,
      'name'     => $new_filename,
      'status'   => 1,
      'langcode' => $default_langcode,
    ]);

    $media->set($this->TYPE_FIELD,     $media_type_value);
    $media->set($this->LANG_FIELD,     $media_lang_value);
    $media->set($this->HASH_FIELD,     $file_hash);
    $media->set($this->MEDIA_FIELD,    ['target_id' => $file_entity->id()]);
    $media->set($this->GROUP_ID_FIELD, 'media_' . $next_mid . '_1');
    $media->set($this->FILENAME_FIELD, $this->extractDisplayName($new_filename));

    if (!empty($reference)) {
      $media->set($this->REF_FIELD, $reference);
    }
    if ($parent_tid) {
      $media->set($this->TAXO_FIELD, ['target_id' => $parent_tid]);
    }

    // Pas de setNewRevision(TRUE) sur une création — la première révision est auto
    $media->setRevisionLogMessage(json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    $media->setRevisionCreationTime(time());
    $media->setRevisionUserId($this->AUTHOR_UID);
    $media->save();

    return $media->id();
  }

  /**
   * Construit le répertoire de destination en suivant l'arborescence
   * taxonomique (requête récursive), comme dans transfert_media_one.
   */
  private function buildDestinationDir($parent_tid, string $storage_scheme, $connection, $fs): string {
    $full_taxo_path = '';

    if ($parent_tid) {
      $row = $connection->query("
        WITH RECURSIVE taxonomy_path AS (
          SELECT t2.tid, t2.name, p2.parent_target_id, t2.name AS full_path, 0 AS level
          FROM taxonomy_term_field_data t2
          LEFT JOIN taxonomy_term__parent p2 ON p2.entity_id = t2.tid
          WHERE t2.tid = :tid
          UNION ALL
          SELECT parent.tid, parent.name, pp.parent_target_id,
                 CONCAT(parent.name, '/', tp.full_path), tp.level + 1
          FROM taxonomy_term_field_data parent
          JOIN taxonomy_term__parent pp ON pp.entity_id = parent.tid
          JOIN taxonomy_path tp ON tp.parent_target_id = parent.tid
        )
        SELECT full_path FROM taxonomy_path ORDER BY level DESC LIMIT 1
      ", [':tid' => $parent_tid])->fetch();

      if ($row) {
        $full_taxo_path = strtolower($row->full_path);
      }
    }

    $dir = $storage_scheme . $this->DEST_SUBDIR . ($full_taxo_path ? '/' . $full_taxo_path : '');
    $fs->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    return $dir;
  }
}