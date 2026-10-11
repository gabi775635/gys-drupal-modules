<?php

namespace Drupal\gestion_documentaire\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class AllRecentChangesController extends ControllerBase {

  public function getAllRecentChangesData(Request $request) {
    $limit  = (int) $request->query->get('per_page', 20);
    $page   = max(0, (int) $request->query->get('page', 0));
    $offset = $page * $limit;

    $name            = $request->query->get('name');
    $user            = $request->query->get('user');
    $email           = $request->query->get('email');
    $dateFilterStart = $request->query->get('dateFilterStart');
    $dateFilterEnd   = $request->query->get('dateFilterEnd');
    $type            = $request->query->get('type');

    if (!empty($dateFilterStart)) {
      $dateFilterStart = date('Y-m-d H:i:s', strtotime($dateFilterStart));
    }
    if (!empty($dateFilterEnd)) {
      $dateFilterEnd = date('Y-m-d H:i:s', strtotime($dateFilterEnd));
    }

    try {
      Database::setActiveConnection('drupal9');
      $connection = Database::getConnection();
    } catch (\Exception $e) {
      return new JsonResponse(['error' => 'Erreur connexion BDD : ' . $e->getMessage()], 500);
    }

    // =====================
    // Sous-requête TAXONOMY
    // Récupère le nom du terme depuis taxonomy_term_field_data
    // =====================
    $query_taxo = "
      SELECT
        ttr.tid                              AS id,
        ttfd.name                            AS name,
        'taxonomy'                           AS type,
        ttr.revision_user                    AS revision_user,
        FROM_UNIXTIME(ttr.revision_created)  AS revision_created,
        ufd.name                             AS email,
        ttr.revision_log_message             AS revision_log_message
      FROM taxonomy_term_revision ttr
      INNER JOIN taxonomy_term_field_data ttfd ON ttfd.tid = ttr.tid
      INNER JOIN users_field_data ufd           ON ufd.uid = ttr.revision_user
      WHERE ttfd.vid = 'dossiers'
    ";

    // =====================
    // Sous-requête MEDIA
    // Récupère le nom du média depuis media_field_data
    // =====================
    $query_media = "
      SELECT
        mr.mid                               AS id,
        mfd.name                             AS name,
        'media'                              AS type,
        mr.revision_user                     AS revision_user,
        FROM_UNIXTIME(mr.revision_created)   AS revision_created,
        ufd.name                             AS email,
        mr.revision_log_message              AS revision_log_message
      FROM media_revision mr
      INNER JOIN media_field_data mfd ON mfd.mid = mr.mid
      INNER JOIN users_field_data ufd ON ufd.uid = mr.revision_user
      WHERE mfd.bundle = 'document'
    ";

    // =====================
    // Requête combinée
    // =====================
    $query = "
      SELECT * FROM (
        $query_taxo
        UNION ALL
        $query_media
      ) AS combined
    ";

    $conditions = [];
    $params     = [];

    if (!empty($name)) {
      $conditions[] = "combined.name LIKE :name";
      $params[':name'] = '%' . $name . '%';
    }

    if (!empty($user)) {
      $conditions[] = "combined.email LIKE :user";
      $params[':user'] = '%' . $user . '%';
    }

    if (!empty($email)) {
      $conditions[] = "combined.email LIKE :email";
      $params[':email'] = '%' . $email . '%';
    }

    if (!empty($type) && in_array($type, ['taxonomy', 'media'])) {
      $conditions[] = "combined.type = :type";
      $params[':type'] = $type;
    }

    if (!empty($dateFilterStart)) {
      $conditions[] = "combined.revision_created >= :dateFilterStart";
      $params[':dateFilterStart'] = $dateFilterStart;
    }

    if (!empty($dateFilterEnd)) {
      $conditions[] = "combined.revision_created <= :dateFilterEnd";
      $params[':dateFilterEnd'] = $dateFilterEnd;
    }

    if (!empty($conditions)) {
      $query .= " WHERE " . implode(' AND ', $conditions);
    }

    $query .= " ORDER BY combined.revision_created DESC LIMIT $limit OFFSET $offset";

    // =====================
    // Exécution principale
    // =====================
    $results = $connection->query($query, $params)->fetchAll(\PDO::FETCH_ASSOC);

    // =====================
    // Comptage total
    // =====================
    $query_count = "
      SELECT COUNT(*) FROM (
        $query_taxo
        UNION ALL
        $query_media
      ) AS combined
    ";

    if (!empty($conditions)) {
      $query_count .= " WHERE " . implode(' AND ', $conditions);
    }

    $total_count = (int) $connection->query($query_count, $params)->fetchField();

    // =====================
    // Nettoyage final
    // =====================
    $clean_results = [];
    foreach ($results as $row) {
      $roles_insert_str = [];
      $roles_update_str = [];
      $roles_delete_str = [];

      $row['revision_log_message'] = $row['revision_log_message'] ?? '{}';
      $json = json_decode($row['revision_log_message'], true);

      if (!empty($json['roles_insert']) && is_array($json['roles_insert'])) {
        foreach ($json['roles_insert'] as $role) {
          $roles_insert_str[] = $role[0] . ' ('
            . ($role[1] ? '✅' : '❌') . ','
            . ($role[2] ? '✅' : '❌') . ','
            . ($role[3] ? '✅' : '❌') . ','
            . ($role[4] ? '✅' : '❌') . ')';
        }
      }

      if (!empty($json['roles_update']) && is_array($json['roles_update'])) {
        foreach ($json['roles_update'] as $role) {
          $roles_update_str[] = $role[0] . ' ('
            . ($role[1] ? '✅' : '❌') . ','
            . ($role[2] ? '✅' : '❌') . ','
            . ($role[3] ? '✅' : '❌') . ','
            . ($role[4] ? '✅' : '❌') . ')';
        }
      }

      if (!empty($json['roles_delete']) && is_array($json['roles_delete'])) {
        foreach ($json['roles_delete'] as $role) {
          $roles_delete_str[] = $role[0];
        }
      }

      $row['roles_insert'] = implode(' / ', $roles_insert_str);
      $row['roles_update'] = implode(' / ', $roles_update_str);
      $row['roles_delete'] = implode(' / ', $roles_delete_str);

      $clean_results[] = $row;
    }

    return new JsonResponse([$clean_results, $total_count, $offset, $limit]);
  }
}