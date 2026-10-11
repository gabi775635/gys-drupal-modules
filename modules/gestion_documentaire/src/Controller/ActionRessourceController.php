<?php

namespace Drupal\gestion_documentaire\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Drupal\media\Entity\Media;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller pour la gestion documentaire.
 */
class ActionRessourceController extends ControllerBase {

  public function updateTaxonomy($id_char_version) {
    return \Drupal::formBuilder()->getForm('\Drupal\gestion_documentaire\Form\UpdateTaxonomyForm', $id_char_version);
  }

  public function updateMedia($id_char_version) {
    return \Drupal::formBuilder()->getForm('\Drupal\gestion_documentaire\Form\UpdateMediaForm', $id_char_version);
  }

  /**
   * Suppression taxonomy + descendants
   */
  public function deleteTaxonomy($id_char_version) {

    Database::setActiveConnection('drupal9');
    $connection = Database::getConnection();

    $account = \Drupal::currentUser();
    $user_roles = $account->getRoles();

    if (
        !in_array('administrator', $user_roles, TRUE) &&
        !in_array('admin_gestiondocumentaire', $user_roles, TRUE)
    ) {
        throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException();
    }

    // ===========================================================================
    // REMPLACEMENT DE LA REQUÊTE SUR LA VUE PAR UNE REQUÊTE DIRECTE SUR LES TABLES
    // ===========================================================================
    
    // Extraction de l'entity_id depuis id_char_version (format: taxonomy_123)
    if (strpos($id_char_version, 'taxonomy_') !== 0) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }
    
    $entity_id = (int) str_replace('taxonomy_', '', $id_char_version);
    
    // Récupération des données de la taxonomie directement depuis les tables
    $taxonomy_query = "
      SELECT 
        ttfd.tid AS entity_id,
        'taxonomy' AS entity_type,
        ttfd.name,
        pt.tid AS parent_taxonomy_id
      FROM taxonomy_term_field_data ttfd
      LEFT JOIN taxonomy_term__parent ttp ON ttfd.tid = ttp.entity_id
      LEFT JOIN taxonomy_term_field_data pt ON ttp.parent_target_id = pt.tid
      WHERE ttfd.tid = :tid
      LIMIT 1
    ";
    
    $record = $connection->query($taxonomy_query, [':tid' => $entity_id])->fetchAssoc();

    if (!$record) {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    // ============================
    // Récupération descendants
    // ============================
    $sql = "
      WITH RECURSIVE taxonomy_children AS (
        SELECT 
          t.tid,
          t.name,
          p.parent_target_id,
          t.name AS full_path,
          0 AS level
        FROM taxonomy_term_field_data t
        LEFT JOIN taxonomy_term__parent p ON p.entity_id = t.tid
        WHERE t.tid = :tid

        UNION ALL

        SELECT 
          child.tid,
          child.name,
          cp.parent_target_id,
          CONCAT(tc.full_path, '/', child.name) AS full_path,
          tc.level + 1
        FROM taxonomy_term__parent cp
        JOIN taxonomy_term_field_data child ON cp.entity_id = child.tid
        JOIN taxonomy_children tc ON cp.parent_target_id = tc.tid
      )

      SELECT 
        'taxonomy' AS entity_type,
        tc.tid AS entity_id,
        tc.name,
        tc.full_path,
        tc.level
      FROM taxonomy_children tc

      UNION ALL

      SELECT 
        'media' AS entity_type,
        m.mid AS entity_id,
        m.name,
        CONCAT(tc.full_path, '/', m.name) AS full_path,
        tc.level + 1
      FROM taxonomy_children tc
      JOIN media__field_taxonomie_associee ma ON ma.field_taxonomie_associee_target_id = tc.tid
      JOIN media_field_data m ON m.mid = ma.entity_id

      ORDER BY level DESC
    ";

    $results = $connection->query($sql, [':tid' => $entity_id])->fetchAll();

    $fs = \Drupal::service('file_system');

    foreach ($results as $row) {
      $row = (array) $row;
      $full_path = ltrim($row['full_path'], '/');

      if ($row['entity_type'] === 'media') {
        $this->deleteMedia('media_' . $row['entity_id']);
      }
      elseif ($row['entity_type'] === 'taxonomy' && $row['entity_id'] != $entity_id) {

        $uri = 'public://gestion_documentaire/' . $full_path;
        $realpath = $fs->realpath($uri);

        // \Drupal::logger('gestion_documentaire')->debug('Suppression dossier : @uri', ['@uri' => $uri]);

        if ($realpath && is_dir($realpath)) {
          $fs->deleteRecursive($uri);
        }

        if ($term = \Drupal\taxonomy\Entity\Term::load($row['entity_id'])) {
          $term->delete();
        }
      }
    }

    // Suppression term parent
    if ($term = \Drupal\taxonomy\Entity\Term::load($entity_id)) {
      $term->delete();
    }

    $referer = \Drupal::request()->server->get('HTTP_REFERER') ?: Url::fromRoute('<front>')->toString();
    return new RedirectResponse($referer);
  }

  /**
   * Suppression média + fichier
   */
  public function deleteMedia($id_char_version) {

    Database::setActiveConnection('drupal9');
    $connection = Database::getConnection();

    if (strpos($id_char_version, 'media_') !== 0) {
        throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $parts     = explode('_', $id_char_version);
    $entity_id = (int) $parts[1];

    $media_query = "
        SELECT
            m.mid AS entity_id,
            link.field_taxonomie_associee_target_id AS parent_taxonomy_id
        FROM media_field_data m
        LEFT JOIN media__field_taxonomie_associee link ON m.mid = link.entity_id
        WHERE m.mid = :mid
        LIMIT 1
    ";

    $record = $connection->query($media_query, [':mid' => $entity_id])->fetchAssoc();

    if (!$record) {
        throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $media = Media::load($record['entity_id']);
    if (!$media) {
        throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    // =====================================
    // RÉCUPÉRER LES FIDS ET URIs AVANT SUPPRESSION
    // =====================================
    $fids = $connection->query(
        "SELECT fid FROM file_usage WHERE id = :mid AND type = 'media'",
        [':mid' => $entity_id]
    )->fetchCol();

    $uris = [];
    if (!empty($fids)) {
        $uris = $connection->query(
            "SELECT uri FROM file_managed WHERE fid IN (:fids[])",
            [':fids[]' => $fids]
        )->fetchCol();

        // Supprimer les entrées en base
        $connection->delete('file_managed')
            ->condition('fid', $fids, 'IN')
            ->execute();

        $connection->delete('file_usage')
            ->condition('fid', $fids, 'IN')
            ->execute();
    }

    // =====================================
    // SUPPRESSION FICHIERS PHYSIQUES
    // =====================================
    $file_system = \Drupal::service('file_system');

    foreach ($uris as $uri) {
        // Les vignettes sont gérées séparément
        if (strpos($uri, '_thumbnails') !== false) continue;

        $real_path = $file_system->realpath($uri);
        if ($real_path && file_exists($real_path)) {
            @unlink($real_path);
        }
    }

    // Suppression vignette disque
    $thumb_dir  = 'public://gestion_documentaire/_thumbnails';
    $thumb_path = $file_system->realpath($thumb_dir) . '/' . $entity_id . '_thumb.jpg';
    if (file_exists($thumb_path)) {
        @unlink($thumb_path);
    }

    // =====================================
    // SUPPRESSION MÉDIA (déclenche hook_media_delete pour le log)
    // =====================================
    \Drupal::entityTypeManager()->getStorage('media')->delete([$media]);

    // =====================================
    // REDIRECTION
    // =====================================
    $url = Url::fromRoute(
        'gestion_documentaire.accueil',
        ['id_char_version' => 'taxonomy_' . $record['parent_taxonomy_id']]
    )->toString();

    return new RedirectResponse($url);
}
  

}