<?php

namespace Drupal\gestion_documentaire\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\media\Entity\Media;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class RenderRessourceController extends ControllerBase {



  public function renderByMid($id_char_version) {
    $logger = \Drupal::logger('gestion_documentaire');
    $id = (string) $id_char_version;
  
    // $logger->debug('[renderByMid] Entrée: @raw (@type) → @id', [
    //   '@raw'  => var_export($id_char_version, TRUE),
    //   '@type' => gettype($id_char_version),
    //   '@id'   => $id,
    // ]);
  
    // Nombre nu (25680) → préfixe + version
    if (ctype_digit($id)) {
      $final = 'media_' . $id . '_1';
      // $logger->debug('[renderByMid] Nombre nu → @final', ['@final' => $final]);
      return $this->content($final);
    }
  
    // media_N sans version → on ajoute _1
    if (preg_match('/^media_\d+$/', $id)) {
      $final = $id . '_1';
      // $logger->debug('[renderByMid] media_N sans version → @final', ['@final' => $final]);
      return $this->content($final);
    }
  
    // media_N_V, taxonomy_N, type__ref__lang, nom.pdf → tel quel
    // $logger->debug('[renderByMid] Format déjà complet, transmis tel quel: @id', ['@id' => $id]);
    return $this->content($id);
  }

  
  /**
   * Logue une erreur dans gestion_documentaire_access_log et lance une NotFoundHttpException.
   */
  private function logAndThrow(string $reason, int $media_id = 0): void {
    $account  = \Drupal::currentUser();
    $tiers_id = \Drupal::database()->select('user__field_id', 'ufi')
      ->fields('ufi', ['field_id_value'])
      ->condition('ufi.entity_id', $account->id())
      ->execute()
      ->fetchField();

    \Drupal::database()->insert('gestion_documentaire_access_log')
      ->fields([
        'media_id'  => $media_id,
        'tiers_id'  => $tiers_id ?? '',
        'timestamp' => \Drupal::time()->getCurrentTime(),
        'action'    => 'error',
        'status'    => 'error',
        'message'   => $reason,
      ])
      ->execute();

    // throw new NotFoundHttpException($reason);
  }

  /**
   * Affiche ou télécharge une ressource.
   *
   * FORMATS SUPPORTÉS :
   * 1. media_123_1 → ID média avec version
   * 2. taxonomy_123 → ID taxonomy
   * 3. type__ref__lang → Recherche par type/référence/langue
   * 4. nom_fichier.pdf → Recherche par nom (+ référence optionnelle via $ref)
   */
  public function content($id_char_version, $download = false, $ref = NULL) {

    $logger = \Drupal::logger('gestion_documentaire');
    $connection = \Drupal::database('drupal9');

    // ============================
    // Langue navigateur
    // ============================
    $user_lang = \Drupal::languageManager()->getCurrentLanguage()->getId();

    if (str_starts_with($user_lang, 'en')) {
      $user_lang = 'uk';
    }

    if (!in_array($user_lang, ['fr', 'uk'])) {
      $user_lang = 'uk';
    }

    // ============================
    // Gestion des taxonomy (redirection)
    // ============================
    if (preg_match('/^taxonomy_(\d+)$/', $id_char_version, $matches)) {
      $tid = $matches[1];

      // Charger le terme de taxonomie
      $taxonomy_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
      $term = $taxonomy_storage->load($tid);

      if (!$term) {
        $this->logAndThrow('Dossier introuvable.');
      }

      // Récupérer le nom dans la langue courante ou fallback FR
      $term_name = null;
      if ($term->hasTranslation($user_lang)) {
        $translated = $term->getTranslation($user_lang);
        $term_name = $translated->getName();
      } elseif ($term->hasTranslation('fr')) {
        $translated = $term->getTranslation('fr');
        $term_name = $translated->getName();
      } else {
        $term_name = $term->getName();
      }

      // Rediriger vers la vue du dossier via RessourceController
      $httpKernel = \Drupal::service('http_kernel');
      $request = \Symfony\Component\HttpFoundation\Request::create(
        "/gestion_documentaire/taxonomy/{$id_char_version}",
        'GET'
      );

      return $httpKernel->handle($request, \Symfony\Component\HttpKernel\HttpKernelInterface::SUB_REQUEST);
    }

    // ============================
    // DETECTION DU FORMAT
    // ============================

    $mid                = null;
    $version            = 1;
    $search_by_criteria = false;
    $search_by_name     = false;
    $type_value         = null;
    $ref_or_name        = null;
    $lang_value         = null;
    $is_numeric_ref     = false;
    $media_name         = null;

    // Format 1 : media_123_1 (ID numérique avec version optionnelle)
    if (preg_match('/^media_(\d+)(?:_(\d+))?$/', $id_char_version, $matches)) {
      $mid     = $matches[1];
      $version = $matches[2] ?? 1;
    }
    // Format 2 : type__ref_or_name__lang (recherche par critères)
    elseif (preg_match('/^([a-z_]+)__([^_]+)__([a-z]{2}(?:,[a-z]{2})*)$/i', $id_char_version, $matches)) {
      $search_by_criteria = true;
      $type_value         = $matches[1];
      $ref_or_name        = $matches[2];
      $lang_value         = $matches[3];
      $is_numeric_ref     = preg_match('/^[0-9]{6}$/', $ref_or_name);
    }
    // Format 3 : nom_du_fichier.pdf (nom direct du média)
    // Si $ref est fourni, on cherche par (nom + référence)
    // Sinon, on cherche juste par nom
    else {
      $search_by_name = true;
      $media_name     = $id_char_version;
    }

    // ============================
    // Construction de la requête SQL
    // ============================

    if ($search_by_name) {
      // ========================================
      // Recherche par nom de média
      // ========================================
      $sql = "
      SELECT
      m.mid AS entity_id,
      m.name AS media_name,
      mrl.field_media_lang_value AS lang,
      mrr.field_media_reference_value AS reference,
      mrt.field_media_type_value AS type,
      mfd.field_media_document_target_id AS fid,
      CONCAT(
        COALESCE(
          (
            WITH RECURSIVE taxonomy_path AS (
              SELECT
              t_def.tid,
              COALESCE(t_en.name, t_fr.name, t_def.name) AS name,
              p2.parent_target_id,
              COALESCE(t_en.name, t_fr.name, t_def.name) AS full_path,
              0 AS level
              FROM taxonomy_term_field_data t_def
              LEFT JOIN taxonomy_term_field_data t_en
              ON t_en.tid = t_def.tid AND t_en.langcode = 'en'
              LEFT JOIN taxonomy_term_field_data t_fr
              ON t_fr.tid = t_def.tid AND t_fr.langcode = 'fr'
              LEFT JOIN taxonomy_term__parent p2
              ON p2.entity_id = t_def.tid
              WHERE t_def.tid = link.field_taxonomie_associee_target_id
              AND t_def.default_langcode = 1

              UNION ALL

              SELECT
              p_def.tid,
              COALESCE(p_en.name, p_fr.name, p_def.name),
              pp.parent_target_id,
              CONCAT(COALESCE(p_en.name, p_fr.name, p_def.name), '/', tp.full_path),
              tp.level + 1
              FROM taxonomy_term_field_data p_def
              LEFT JOIN taxonomy_term_field_data p_en
              ON p_en.tid = p_def.tid AND p_en.langcode = 'en'
              LEFT JOIN taxonomy_term_field_data p_fr
              ON p_fr.tid = p_def.tid AND p_fr.langcode = 'fr'
              JOIN taxonomy_term__parent pp ON pp.entity_id = p_def.tid
              JOIN taxonomy_path tp ON tp.parent_target_id = p_def.tid
              WHERE p_def.default_langcode = 1
              )
              SELECT full_path
              FROM taxonomy_path
              ORDER BY level DESC
              LIMIT 1
            ),
            ''
          ),
          '/',
          m.name
          ) AS full_path,
          CASE
          WHEN mrl.field_media_lang_value LIKE :user_lang_pattern THEN 0
          WHEN mrl.field_media_lang_value LIKE '%uk%' THEN 1
          ELSE 2
          END AS lang_priority
          FROM media_field_data m
          LEFT JOIN media__field_taxonomie_associee link ON m.mid = link.entity_id
          INNER JOIN (
            SELECT mid, MAX(vid) AS max_vid
            FROM media_revision
            GROUP BY mid
            ) latest ON m.mid = latest.mid
            INNER JOIN media_revision mr ON m.mid = mr.mid AND mr.vid = latest.max_vid
            LEFT JOIN media__field_media_lang mrl ON mr.vid = mrl.revision_id
            LEFT JOIN media__field_media_reference mrr ON mr.vid = mrr.revision_id
            LEFT JOIN media__field_media_type mrt ON mr.vid = mrt.revision_id
            LEFT JOIN media__field_media_document mfd   ON m.mid = mfd.entity_id
            WHERE m.name = :media_name
            ";

      // Si $ref est fourni, on filtre aussi par référence
      if ($ref !== NULL) {
        $sql .= " AND mrr.field_media_reference_value LIKE :ref_pattern";
      }

      $sql .= "
      ORDER BY lang_priority ASC
      LIMIT 1
      ";

      $params = [
        ':user_lang_pattern' => '%' . $user_lang . '%',
        ':media_name'        => $media_name,
      ];

      if ($ref !== NULL) {
        $params[':ref_pattern'] = '%' . $ref . '%';
      }

    } elseif ($search_by_criteria) {
      // ========================================
      // Recherche par critères (type__ref__lang)
      // ========================================

      if ($is_numeric_ref) {

        $sql = "
        SELECT
        m.mid AS entity_id,
        m.name AS media_name,
        mrl.field_media_lang_value AS lang,
        mrr.field_media_reference_value AS reference,
        mrt.field_media_type_value AS type,
        CONCAT(
          COALESCE(
            (
              WITH RECURSIVE taxonomy_path AS (
                SELECT
                t2.tid,
                t2.name,
                p2.parent_target_id,
                t2.name AS full_path,
                0 AS level
                FROM taxonomy_term_field_data t2
                LEFT JOIN taxonomy_term__parent p2 ON p2.entity_id = t2.tid
                WHERE t2.tid = link.field_taxonomie_associee_target_id
                UNION ALL
                SELECT
                parent.tid,
                parent.name,
                pp.parent_target_id,
                CONCAT(parent.name, '/', tp.full_path),
                tp.level + 1
                FROM taxonomy_term_field_data parent
                JOIN taxonomy_term__parent pp ON pp.entity_id = parent.tid
                JOIN taxonomy_path tp ON tp.parent_target_id = parent.tid
                )
                SELECT full_path
                FROM taxonomy_path
                ORDER BY level DESC
                LIMIT 1
              ),
              ''
            ),
            '/',
            m.name
            ) AS full_path,
            CASE
            WHEN mrl.field_media_lang_value LIKE :user_lang_pattern THEN 0
            WHEN mrl.field_media_lang_value LIKE '%uk%' THEN 1
            ELSE 2
            END AS lang_priority
            FROM media_field_data m
            LEFT JOIN media__field_taxonomie_associee link ON m.mid = link.entity_id
            INNER JOIN (
              SELECT mid, MAX(vid) AS max_vid
              FROM media_revision
              GROUP BY mid
              ) latest ON m.mid = latest.mid
              INNER JOIN media_revision mr ON m.mid = mr.mid AND mr.vid = latest.max_vid
              LEFT JOIN media__field_media_lang mrl ON mr.vid = mrl.revision_id
              LEFT JOIN media__field_media_reference mrr ON mr.vid = mrr.revision_id
              LEFT JOIN media__field_media_type mrt ON mr.vid = mrt.revision_id
              WHERE mrt.field_media_type_value LIKE :type_pattern
              AND mrr.field_media_reference_value LIKE :ref_pattern
              AND mrl.field_media_lang_value LIKE :doc_lang_pattern
              ORDER BY lang_priority ASC
              LIMIT 1
              ";

        $params = [
          ':user_lang_pattern' => '%' . $user_lang . '%',
          ':type_pattern'      => '%' . $type_value . '%',
          ':ref_pattern'       => '%' . $ref_or_name . '%',
          ':doc_lang_pattern'  => '%' . $lang_value . '%',
        ];

      } else {

        $sql = "
        SELECT
        m.mid AS entity_id,
        m.name AS media_name,
        mrl.field_media_lang_value AS lang,
        mrt.field_media_type_value AS type,
        CONCAT(
          COALESCE(
            (
              WITH RECURSIVE taxonomy_path AS (
                SELECT
                t2.tid,
                t2.name,
                p2.parent_target_id,
                t2.name AS full_path,
                0 AS level
                FROM taxonomy_term_field_data t2
                LEFT JOIN taxonomy_term__parent p2 ON p2.entity_id = t2.tid
                WHERE t2.tid = link.field_taxonomie_associee_target_id
                UNION ALL
                SELECT
                parent.tid,
                parent.name,
                pp.parent_target_id,
                CONCAT(parent.name, '/', tp.full_path),
                tp.level + 1
                FROM taxonomy_term_field_data parent
                JOIN taxonomy_term__parent pp ON pp.entity_id = parent.tid
                JOIN taxonomy_path tp ON tp.parent_target_id = parent.tid
                )
                SELECT full_path
                FROM taxonomy_path
                ORDER BY level DESC
                LIMIT 1
              ),
              ''
            ),
            '/',
            m.name
            ) AS full_path,
            CASE
            WHEN mrl.field_media_lang_value LIKE :user_lang_pattern THEN 0
            WHEN mrl.field_media_lang_value LIKE '%uk%' THEN 1
            ELSE 2
            END AS lang_priority
            FROM media_field_data m
            LEFT JOIN media__field_taxonomie_associee link ON m.mid = link.entity_id
            INNER JOIN (
              SELECT mid, MAX(vid) AS max_vid
              FROM media_revision
              GROUP BY mid
              ) latest ON m.mid = latest.mid
              INNER JOIN media_revision mr ON m.mid = mr.mid AND mr.vid = latest.max_vid
              LEFT JOIN media__field_media_lang mrl ON mr.vid = mrl.revision_id
              LEFT JOIN media__field_media_type mrt ON mr.vid = mrt.revision_id
              WHERE mrt.field_media_type_value LIKE :type_pattern
              AND m.name LIKE :name_pattern
              AND mrl.field_media_lang_value LIKE :doc_lang_pattern
              ORDER BY lang_priority ASC
              LIMIT 1
              ";

        $params = [
          ':user_lang_pattern' => '%' . $user_lang . '%',
          ':type_pattern'      => '%' . $type_value . '%',
          ':name_pattern'      => '%' . $ref_or_name . '%',
          ':doc_lang_pattern'  => '%' . $lang_value . '%',
        ];
      }

    } else {
      // ========================================
      // Recherche par media_id
      // ========================================

      $sql = "
      SELECT
      m.mid AS entity_id,
      m.name AS media_name,
      mrl.field_media_lang_value AS lang,
      mt.field_media_type_value AS type,
      mfd.field_media_document_target_id AS fid,
      CONCAT(
        COALESCE(
          (
            WITH RECURSIVE taxonomy_path AS (
              SELECT
              t2.tid,
              t2.name,
              p2.parent_target_id,
              t2.name AS full_path,
              0 AS level
              FROM taxonomy_term_field_data t2
              LEFT JOIN taxonomy_term__parent p2 ON p2.entity_id = t2.tid
              WHERE t2.tid = link.field_taxonomie_associee_target_id
              UNION ALL
              SELECT
              parent.tid,
              parent.name,
              pp.parent_target_id,
              CONCAT(parent.name, '/', tp.full_path),
              tp.level + 1
              FROM taxonomy_term_field_data parent
              JOIN taxonomy_term__parent pp ON pp.entity_id = parent.tid
              JOIN taxonomy_path tp ON tp.parent_target_id = parent.tid
              )
              SELECT full_path
              FROM taxonomy_path
              ORDER BY level DESC
              LIMIT 1
            ),
            ''
          ),
          '/',
          m.name
          ) AS full_path,
          CASE
          WHEN mrl.field_media_lang_value LIKE :user_lang_pattern THEN 0
          WHEN mrl.field_media_lang_value LIKE '%uk%' THEN 1
          ELSE 2
          END AS lang_priority
          FROM media_field_data m
          LEFT JOIN media__field_taxonomie_associee link ON m.mid = link.entity_id
          INNER JOIN (
            SELECT mid, MAX(vid) AS max_vid
            FROM media_revision
            GROUP BY mid
            ) latest ON m.mid = latest.mid
            INNER JOIN media_revision mr ON m.mid = mr.mid AND mr.vid = latest.max_vid
            LEFT JOIN media__field_media_lang mrl ON mr.vid = mrl.revision_id
            LEFT JOIN media__field_media_version mrv ON mr.vid = mrv.revision_id
            LEFT JOIN media__field_media_type mt ON mr.mid = mt.entity_id
            LEFT JOIN media__field_media_document mfd   ON m.mid = mfd.entity_id

            WHERE m.mid = :mid
            ";

      if ($version) {
        $sql .= " AND COALESCE(mrv.field_media_version_value, 1) = :version";
      }

      $sql .= "
      ORDER BY lang_priority ASC
      LIMIT 1
      ";

      $params = [
        ':user_lang_pattern' => '%' . $user_lang . '%',
        ':mid'               => $mid,
      ];

      if ($version) {
        $params[':version'] = $version;
      }
    }

    // ============================
    // Exécution de la requête
    // ============================

    $row = $connection->query($sql, $params)->fetchAssoc();

    if (!$row) {
      $this->logAndThrow('Media introuvable.');
    }

    if (empty($row['entity_id']) || empty($row['full_path'])) {
      $this->logAndThrow('Media introuvable (données incomplètes).', (int) ($row['entity_id'] ?? 0));
    }

    // ============================
    // Vérification média
    // ============================

    $media = Media::load($row['entity_id']);
    if (!$media) {
      $this->logAndThrow('Media introuvable (chargement entité).', (int) $row['entity_id']);
    }

    

    // ============================
    // Construction chemin disque
    // ============================

    $file_system = \Drupal::service('file_system');

    if (!empty($row['fid'])) {
      // Récupérer l'URI depuis file_managed
      $file_uri = $connection->query(
        "SELECT uri FROM file_managed WHERE fid = :fid",
        [':fid' => $row['fid']]
      )->fetchField();

      if (!$file_uri) {
        $this->logAndThrow('Fichier non trouvé (URI manquante).', (int) $row['entity_id']);
      }

      // Déterminer le scheme et construire le chemin manuellement
      if (str_starts_with($file_uri, 'public://')) {
        $public_root = $file_system->realpath('public://');
        $relative    = substr($file_uri, strlen('public://'));
        $file_path   = $public_root . '/' . $relative;

      } elseif (str_starts_with($file_uri, 'private://')) {
        // realpath via stream wrapper Drupal pour gérer correctement
        // les accents/caractères spéciaux dans le chemin
        $file_path = $file_system->realpath($file_uri);

      } else {
        $file_path = $file_system->realpath($file_uri);
      }

    } else {
      // Fallback : ancien comportement avec full_path + public://
      $public_root   = $file_system->realpath('public://');
      $relative_path = ltrim($row['full_path'], '/');
      $file_path     = $public_root . '/gestion_documentaire/' . $relative_path;
    }

    if (!file_exists($file_path)) {
      $this->logAndThrow('Fichier non trouvé sur disque.', (int) $row['entity_id']);
    }

    // ============================
    // MIME TYPE
    // ============================
    $ext = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

    $mime_map = [
      'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
      'xls'  => 'application/vnd.ms-excel',
      'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'doc'  => 'application/msword',
      'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
      'ppt'  => 'application/vnd.ms-powerpoint',
      'pdf'  => 'application/pdf',
      'png'  => 'image/png',
      'jpg'  => 'image/jpeg',
      'jpeg' => 'image/jpeg',
      'gif'  => 'image/gif',
      'txt'  => 'text/plain',
      'webp' => 'image/webp',
      'svg'  => 'image/svg+xml',
      'zip'  => 'application/zip',
      'rar'  => 'application/x-rar-compressed',
    ];

    $mime_type = $mime_map[$ext] ?? mime_content_type($file_path);

    $inline_types = [
      'application/pdf',
      'image/jpeg',
      'image/png',
      'image/gif',
      'image/webp',
      'text/plain',
    ];

    $forceDownload = $download || !in_array($mime_type, $inline_types);

    // ============================
    // Nom du fichier pour le téléchargement
    // IMPORTANT: Ne PAS nettoyer les accents, les garder tels quels
    // ============================

    if ($search_by_name && $ref !== NULL) {
      // Format 3 avec ref : nom + ref fournie (SANS nettoyage)
      $type_for_name = $row['type'] ?? 'document';
      $lang_for_name = $row['lang'] ?? $user_lang;
      $download_name = "{$type_for_name}_{$ref}_{$lang_for_name}.{$ext}";

    } elseif ($search_by_name) {
      // Format 3 sans ref : juste le nom du fichier
      $download_name = $media_name;

    } elseif ($ref !== NULL) {
      $type_for_name = $row['type'] ?? 'document';
      $lang_for_name = $row['lang'] ?? $user_lang;
      // Nettoyer le préfixe media_ si présent
      $clean_ref = preg_replace('/^media_/', '', $ref);
      $download_name = "{$type_for_name}_{$clean_ref}_{$lang_for_name}.{$ext}";
    } elseif ($search_by_criteria) {
      // Format type__ref__lang dans l'URL (SANS nettoyage)
      $download_name = "{$type_value}_{$ref_or_name}_{$lang_value}.{$ext}";

    } else {
      // Ancien format media_id, pas de ref
      $download_name = "media_{$mid}.{$ext}";
    }


 
  if (($row['type'] ?? '') === 'fds') {
    // Récupérer le filename depuis l'entité
    $filename = $connection->query(
        "SELECT field_media_filename_value
         FROM media__field_media_filename
         WHERE entity_id = :entity_id
         LIMIT 1",
        [':entity_id' => $row['entity_id']]
    )->fetchField();

    // Nettoyer le filename : supprimer l'extension et extraire le nom du produit
        // Nettoyer le filename : supprimer l'extension et extraire le nom du produit
        if ($filename) {
          $filenameWithoutExt = pathinfo($filename, PATHINFO_FILENAME);
          
          // Remplacer les tirets et underscores par des espaces
          $cleaned = preg_replace('/[-_]+/', ' ', $filenameWithoutExt);
          
          // Découper en mots
          $words = explode(' ', $cleaned);
          
          // Supprimer UNIQUEMENT le premier mot s'il fait 1 caractère (ex: "c" dans "c-FDS")
          if (!empty($words) && strlen($words[0]) === 1) {
            array_shift($words);
          }
          
          // Supprimer "FDS" ou "fds" s'il est en première position maintenant
          if (!empty($words) && strtoupper($words[0]) === 'FDS') {
            array_shift($words);
          }
          
          // Supprimer le code usine de 3 lettres (toujours après FDS, donc maintenant en position 0)
          if (!empty($words) && strlen($words[0]) === 3 && ctype_upper($words[0])) {
            array_shift($words);
          }
          
          // Reconstruire le nom nettoyé
          $filename = implode(' ', $words);
          
          // Si tout a été supprimé, fallback sur le nom original
          if (empty(trim($filename))) {
              $filename = $filenameWithoutExt;
          }
  } else {
        $filename = 'document';
    }

    // Déterminer la référence et la formater sur 6 chiffres
    $reference = $row['reference'] ?? '';
    if (empty($reference)) {
        $reference = preg_replace('/^media_/', '', $ref ?? (string) $mid);
    }
    // Ajouter des zéros devant pour avoir 6 caractères (si numérique)
    if (is_numeric($reference)) {
        $reference = str_pad($reference, 6, '0', STR_PAD_LEFT);
    }

    // Langues (peuvent être multiples)
    $langs = $row['lang'] ?? $user_lang;

    $download_name = sprintf(
        'fds_%s_%s.%s',
        // $reference,
        $filename,
        $langs,
        $ext
    );
}
    // ============================
    // Réponse HTTP
    // ============================

    $response = new BinaryFileResponse($file_path);

    // Utiliser le bon header Content-Disposition avec support UTF-8 (RFC 5987)
    $disposition = $forceDownload ? 'attachment' : 'inline';
    $response->headers->set(
      'Content-Disposition',
      $disposition . '; filename="' . addslashes($download_name) . '"; filename*=UTF-8\'\'' . rawurlencode($download_name)
    );

    $response->headers->set('Content-Type', $mime_type);
    $response->headers->set('Access-Control-Expose-Headers', 'Content-Disposition');

    if (!$forceDownload && in_array($mime_type, $inline_types)) {
      $response->headers->set('Cache-Control', 'private, max-age=300');
    } else {
      $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
      $response->headers->set('Pragma', 'no-cache');
      $response->headers->set('Expires', '0');
    }

    while (ob_get_level()) {
      ob_end_clean();
    }

    // ============================
    // Log d'accès
    // ============================
    $account = \Drupal::currentUser();
    $uid = $account->id();

    $tiers_id = \Drupal::database()->select('user__field_id', 'ufi')
      ->fields('ufi', ['field_id_value'])
      ->condition('ufi.entity_id', $uid)
      ->execute()
      ->fetchField();

    \Drupal::database()->insert('gestion_documentaire_access_log')
      ->fields([
        'media_id'  => (int) $row['entity_id'],
        'tiers_id'  => $tiers_id ?? '',
        'timestamp' => \Drupal::time()->getCurrentTime(),
        'action'    => $forceDownload ? 'download' : 'view',
        'status'    => 'success',
        'message'   => 'Fichier servi avec succès : ' . $download_name,
      ])
      ->execute();

    return $response;
  }

  /**
   * Téléchargement forcé.
   */
  public function download($id_char_version, $ref = NULL) {
    return $this->content($id_char_version, true, $ref);
  }

  /**
   * Sert la vignette d'un média, en la générant si nécessaire.
   *
   * Ajout : si ?regenerate=1 est passé en query string, la vignette
   * existante est d'abord supprimée pour forcer une régénération
   * (même logique que UpdateMediaForm).
   */
  public function mediaThumbnail(int $mid) {
    $logger = \Drupal::logger('gestion_documentaire');
    $file_system = \Drupal::service('file_system');

    // Charger le média
    $media = \Drupal\media\Entity\Media::load($mid);
    if (!$media) {
      throw new NotFoundHttpException('Média introuvable.');
    }

    // ── Régénération forcée si demandée via ?regenerate=1 ───────
    $regenerate = (bool) \Drupal::request()->query->get('regenerate');
    if ($regenerate) {
      $this->deleteThumbnail($media);
      // Le champ est maintenant vide → on saute la branche
      // "servir l'existante" et on tombe direct dans la génération.
    }
    // ────────────────────────────────────────────────────────────

    // Vérifier si une vignette est déjà rattachée au média
    $thumb_field = $media->get('field_media_thumbnail');

    if (!$thumb_field->isEmpty()) {
      $thumb_file = $thumb_field->entity;

      if ($thumb_file) {
        $thumb_uri  = $thumb_file->getFileUri();
        $thumb_path = $file_system->realpath($thumb_uri);

        if ($thumb_path && file_exists($thumb_path)) {
          // $logger->notice('[Thumbnail] Vignette existante servie directement');
          $response = new BinaryFileResponse($thumb_path);
          $response->headers->set('Content-Type', 'image/jpeg');
          $response->headers->set('Cache-Control', 'public, max-age=86400');
          while (ob_get_level()) ob_end_clean();
          return $response;
        }
      }
    }

    // Pas de vignette rattachée → générer depuis le fichier source
    $file = $media->get('field_media_document')->entity ?? null;
    if (!$file) {
      throw new NotFoundHttpException('Fichier source introuvable.');
    }

    $file_uri = $file->getFileUri();
    $ext      = strtolower(pathinfo($file_uri, PATHINFO_EXTENSION));

    // Résoudre le chemin réel
    if (str_starts_with($file_uri, 'public://')) {
      $source_path = $file_system->realpath('public://') . '/' . substr($file_uri, strlen('public://'));
    } elseif (str_starts_with($file_uri, 'private://')) {
      $source_path = $file_system->realpath($file_uri);
    } else {
      $source_path = $file_system->realpath($file_uri);
    }

    $source_exists = $source_path && file_exists($source_path);
    if (!$source_exists) {
      throw new NotFoundHttpException('Fichier source introuvable sur disque.');
    }

    // Vérifier le format supporté
    $supported = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $supported)) {
      throw new NotFoundHttpException('Format non supporté pour la vignette.');
    }

    // Préparer le répertoire de destination
    $thumb_dir = 'public://gestion_documentaire/_thumbnails';
    $dir_ok    = $file_system->prepareDirectory(
      $thumb_dir,
      \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS
    );

    $thumb_dir_real = $file_system->realpath($thumb_dir);

    if (!$thumb_dir_real) {
      throw new NotFoundHttpException('Répertoire vignettes inaccessible.');
    }

    $thumb_path = $thumb_dir_real . '/' . $mid . '_thumb.jpg';

    // Copie temporaire avec nom ASCII pour éviter les problèmes d'accents
    // dans le chemin source (ex: écoconception/) qui font échouer convert
    // malgré escapeshellarg
    $safe_tmp = sys_get_temp_dir() . '/thumb_src_' . $mid . '_' . uniqid() . '.' . $ext;
    if (!copy($source_path, $safe_tmp)) {
      throw new NotFoundHttpException('Impossible de copier le fichier source.');
    }

    // Construire et exécuter la commande convert
    $cmd = sprintf(
      'timeout 30 convert -density 72 %s -thumbnail 500x -background white -flatten -strip -quality 70 %s 2>&1',
      escapeshellarg($safe_tmp . '[0]'),
      escapeshellarg($thumb_path)
    );

    $output = [];
    $code   = -1;
    exec($cmd, $output, $code);

    @unlink($safe_tmp); // nettoyage dans tous les cas

    $thumb_exists_after = file_exists($thumb_path);

    if ($code !== 0 || !$thumb_exists_after) {
      $logger->error('[Thumbnail] Échec génération vignette: code=@code, output=@out', [
        '@code' => $code,
        '@out'  => implode(' | ', $output),
      ]);
      throw new NotFoundHttpException('Échec de la génération de la vignette.');
    }

    // Rattacher la vignette au média
    $thumb_uri  = 'public://gestion_documentaire/_thumbnails/' . $mid . '_thumb.jpg';
    $thumb_file = \Drupal\file\Entity\File::create([
      'uri'    => $thumb_uri,
      'uid'    => \Drupal::currentUser()->id(),
      'status' => 1,
    ]);
    $thumb_file->save();

    $media->set('field_media_thumbnail', ['target_id' => $thumb_file->id()]);
    $media->setNewRevision(FALSE);
    $media->save();

    $response = new BinaryFileResponse($thumb_path);
    $response->headers->set('Content-Type', 'image/jpeg');
    $response->headers->set('Cache-Control', 'public, max-age=86400');
    while (ob_get_level()) ob_end_clean();
    return $response;
  }


  
  public function mediaThumbnailByReference(string $reference, string $lang) {
    $mid = $this->findMediaIdForReference($reference, $lang);
  
    if (!$mid) {
      throw new NotFoundHttpException('Aucun média trouvé pour cette référence, ni dans la langue demandée, ni en uk, ni en fr.');
    }
  
    return $this->mediaThumbnail($mid);
  }
  
  /**
   * Cherche l'entity_id (mid) correspondant à une référence, avec fallback de langue.
   * Ordre : langue demandée -> uk -> fr.
   * Ne retient un mid que s'il possède une vignette déjà générée OU un fichier source exploitable.
   */
  private function findMediaIdForReference(string $reference, string $lang): ?int {
    $logger = \Drupal::logger('gestion_documentaire');
  
    $reference = trim($reference);
    $reference = preg_replace('/[\x00-\x1F\x7F\xA0]+/u', '', $reference);
  
    if ($reference === '') {
      $logger->warning('[findMedia] Référence vide après nettoyage');
      return NULL;
    }
  
    $langs_to_try = [$lang];
  
    if ($lang !== 'uk') {
      $langs_to_try[] = 'uk';
    }
    if ($lang !== 'fr' && !in_array('fr', $langs_to_try, TRUE)) {
      $langs_to_try[] = 'fr';
    }
  
    $database = \Drupal::database();
  
    foreach ($langs_to_try as $try_lang) {
      // Table racine : media_field_data (contient TOUS les médias).
      $query = $database->select('media_field_data', 'm')
        ->fields('m', ['mid']);
  
      // LEFT JOIN sur les champs qui peuvent être absents.
      $query->leftJoin('media__field_media_reference', 'r', 'r.entity_id = m.mid');
      $query->leftJoin('media__field_media_filename', 'f', 'f.entity_id = m.mid');
  
      // Match sur la référence OU sur le nom du média OU sur le filename.
      $ref_pattern = '%' . $database->escapeLike($reference) . '%';
      $or = $query->orConditionGroup()
        ->condition('r.field_media_reference_value', $ref_pattern, 'LIKE')
        ->condition('m.name', $ref_pattern, 'LIKE')
        ->condition('f.field_media_filename_value', $ref_pattern, 'LIKE');
      $query->condition($or);
  
      // Type = brochure (INNER JOIN, obligatoire).
      $query->innerJoin('media__field_media_type', 't', 't.entity_id = m.mid');
      $query->condition('t.field_media_type_value', 'brochure');
  
      // Langue métier (INNER JOIN, obligatoire).
      $query->innerJoin('media__field_media_lang', 'l', 'l.entity_id = m.mid');
      $query->condition(
        'l.field_media_lang_value',
        '%' . $database->escapeLike($try_lang) . '%',
        'LIKE'
      );
  
      $query->range(0, 1);
  
      $mid = $query->execute()->fetchField();
  
      // $logger->notice('[findMedia] ref=@ref (len=@len) lang=@lang → mid=@mid', [
      //   '@ref'  => $reference,
      //   '@len'  => strlen($reference),
      //   '@lang' => $try_lang,
      //   '@mid'  => $mid ?: 'NULL',
      // ]);
  
      if ($mid && $this->mediaHasUsableSource((int) $mid)) {
        return (int) $mid;
      }
    }
  
    return NULL;
  }
  /**
   * Vérifie qu'un média a soit une vignette déjà rattachée et présente sur disque,
   * soit un fichier source (field_media_document) présent sur disque.
   */
  private function mediaHasUsableSource(int $mid): bool {
    $media = \Drupal\media\Entity\Media::load($mid);
    if (!$media) {
      return FALSE;
    }
  
    $file_system = \Drupal::service('file_system');
  
    // Cas 1 : vignette déjà générée et présente sur disque.
    $thumb_field = $media->get('field_media_thumbnail');
    if (!$thumb_field->isEmpty()) {
      $thumb_file = $thumb_field->entity;
      if ($thumb_file) {
        $thumb_path = $file_system->realpath($thumb_file->getFileUri());
        if ($thumb_path && file_exists($thumb_path)) {
          return TRUE;
        }
      }
    }
  
    // Cas 2 : pas de vignette, mais un fichier source exploitable existe.
    $file = $media->get('field_media_document')->entity ?? null;
    if (!$file) {
      return FALSE;
    }
  
    $file_uri = $file->getFileUri();
    $ext = strtolower(pathinfo($file_uri, PATHINFO_EXTENSION));
    $supported = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $supported)) {
      return FALSE;
    }
  
    if (str_starts_with($file_uri, 'public://')) {
      $source_path = $file_system->realpath('public://') . '/' . substr($file_uri, strlen('public://'));
    } else {
      $source_path = $file_system->realpath($file_uri);
    }
  
    return $source_path && file_exists($source_path);
  }

  /**
   * Supprime la vignette existante (fichier physique + entité File + lignes en base).
   *
   * Repris de UpdateMediaForm pour permettre la régénération via mediaThumbnail.
   */
  private function deleteThumbnail(Media $media): void {
    $file_system = \Drupal::service('file_system');

    if (!$media->hasField('field_media_thumbnail')) {
      return;
    }

    $thumb_field = $media->get('field_media_thumbnail');
    if (!$thumb_field->isEmpty()) {
      $old_thumb_file = $thumb_field->entity;
      if ($old_thumb_file) {
        $old_path = $file_system->realpath($old_thumb_file->getFileUri());
        if ($old_path && file_exists($old_path)) {
          @unlink($old_path);
        }
        $old_thumb_file->delete();
      }
    }

    // Vider le champ en base directement (table courante + révision)
    \Drupal::database()->delete('media__field_media_thumbnail')
      ->condition('entity_id', $media->id())
      ->execute();
    \Drupal::database()->delete('media_revision__field_media_thumbnail')
      ->condition('entity_id', $media->id())
      ->execute();
  }

  /**
   * Génère un JPEG depuis la première page d'un PDF via convert directement.
   */
  private function generateFromPdf(string $file_path, string $dest, int $width, int $quality): bool {
    return $this->runConvert($file_path, $dest, $width, $quality, true);
  }

  /**
   * Génère un JPEG redimensionné depuis une image source via convert.
   */
  private function generateFromImage(string $file_path, string $dest, int $width, int $quality): bool {
    return $this->runConvert($file_path, $dest, $width, $quality, false);
  }

  /**
   * Exécute ImageMagick convert et écrit le résultat dans $dest.
   * Retourne true en cas de succès, false sinon.
   *
   * @param bool $is_pdf  Si true, ajoute -density 72 pour accélérer le rendu PDF.
   */
  private function runConvert(string $source, string $dest, int $width, int $quality, bool $is_pdf = false): bool {
    $density_opt = $is_pdf ? '-density 72' : '';

    $cmd = sprintf(
      'timeout 30 convert -density 72 %s -thumbnail %dx -background white -flatten -strip -quality %d %s 2>&1',
      escapeshellarg($source . '[0]'),
      $width,
      $quality,
      escapeshellarg($dest)
    );

    exec($cmd, $output, $code);

    return $code === 0 && file_exists($dest);
  }

}