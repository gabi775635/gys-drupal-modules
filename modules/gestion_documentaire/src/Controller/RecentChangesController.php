<?php

namespace Drupal\gestion_documentaire\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Drupal\Core\Render\Markup;

class RecentChangesController extends ControllerBase {

  public function getRecentChangesData($id_char_version = NULL, $type = NULL) {
    $rows = [];

    if (!$id_char_version || !$type) {
      return $rows;
    }

    try {
      Database::setActiveConnection('drupal9');
      $connection = Database::getConnection();
    } catch (\Exception $e) {
      \Drupal::logger('gestion_documentaire')->error('Erreur connexion BDD : @msg', ['@msg' => $e->getMessage()]);
      return $rows;
    }

    if ($type === 'taxonomy') {
      $tid = (int) str_replace('taxonomy_', '', $id_char_version);

      $query = "
        SELECT 
          ttr.*,
          ufd.name AS email
        FROM taxonomy_term_revision ttr
        INNER JOIN users_field_data ufd ON ufd.uid = ttr.revision_user
        WHERE ttr.tid = :entity_id
        ORDER BY ttr.revision_created DESC 
        LIMIT 10
      ";

      $binds = [':entity_id' => $tid];
      $mid = 0;
    }
    else {
      preg_match('/media_(\d+)(?:_(\d+))?/', $id_char_version, $matches);
      $mid = isset($matches[1]) ? (int) $matches[1] : 0;

      if (!$mid) {
        \Drupal::logger('gestion_documentaire')->warning('getRecentChangesData : MID invalide pour @id', ['@id' => $id_char_version]);
        return $rows;
      }

      $query = "
  SELECT 
    mr.*,
    ufd.name AS email,
    mfr.status AS media_status
  FROM media_revision mr
  INNER JOIN users_field_data ufd ON ufd.uid = mr.revision_user
  INNER JOIN media_field_revision mfr ON mfr.vid = mr.vid
  WHERE mr.mid = :entity_id
  ORDER BY mr.revision_created DESC 
";

      $binds = [':entity_id' => $mid];
    }

    $results = $connection->query($query, $binds)->fetchAll();

    foreach ($results as $record) {
      $formatted_date = \Drupal::service('date.formatter')->format(
        $record->revision_created,
        'custom',
        'd/m/Y H:i'
      );

      $log_data = !empty($record->revision_log_message)
        ? json_decode($record->revision_log_message, TRUE)
        : [];

      if (!is_array($log_data)) {
        $log_data = [];
      }

      $entity_log = $log_data[$type] ?? [];

      $nom_ancien     = $entity_log['nom']['ancien']     ?? '';
      $nom_nouveau    = $entity_log['nom']['nouveau']    ?? '';
      $parent_ancien  = $entity_log['parent']['ancien']  ?? '';
      $parent_nouveau = $entity_log['parent']['nouveau'] ?? '';

      $lang_nouveau = $entity_log['lang']['nouveau'] ?? $entity_log['lang'] ?? '';
      $ref_nouveau  = $entity_log['references']['nouveau'] ?? $entity_log['references'] ?? '';

      // ===========================================================================
      // COMPARAISON HASH : révision N vs révision N-1
      // Si la révision précédente n'a pas de hash → fichier considéré modifié
      // ===========================================================================
      $hash_modifie = '❌';

      if ($type !== 'taxonomy' && $mid > 0) {
        $hash_courant = $entity_log['hash'] ?? null;

        // Récupérer le log de la révision précédente (vid strictement inférieur)
        $prev_log_raw = $connection->query("
          SELECT revision_log_message
          FROM media_revision
          WHERE mid = :mid
            AND vid < :vid
          ORDER BY vid DESC
          LIMIT 1
        ", [
          ':mid' => $mid,
          ':vid' => $record->vid,
        ])->fetchField();

        if ($prev_log_raw) {
          $prev_log  = json_decode($prev_log_raw, TRUE);
          $prev_hash = is_array($prev_log) ? ($prev_log['media']['hash'] ?? null) : null;

          if (!$prev_hash) {
            // Révision précédente sans hash → on considère le fichier comme modifié
            $hash_modifie = '✅';
          } elseif ($prev_hash !== $hash_courant) {
            $hash_modifie = '✅';
          } else {
            $hash_modifie = '❌';
          }
        } else {
          // Pas de révision précédente (première révision) → création, on affiche modifié
          $hash_modifie = '✅';
        }
      }

      $modif = !empty($log_data['modif']) ? 'Modifié' : 'Crée';

      $roles_insert = $log_data['roles_insert'] ?? [];
      $roles_update = $log_data['roles_update'] ?? [];
      $roles_delete = $log_data['roles_delete'] ?? [];

      $roles_insert_str = [];
      foreach ($roles_insert as $role) {
        $roles_insert_str[] = ($role[0] ?? '?') . ' ('
          . (($role[1] ?? 0) ? '✅' : '❌') . ','
          . (($role[2] ?? 0) ? '✅' : '❌') . ','
          . (($role[3] ?? 0) ? '✅' : '❌') . ','
          . (($role[4] ?? 0) ? '✅' : '❌') . ')';
      }
      $roles_insert_str = implode(' / ', $roles_insert_str);

      $roles_update_str = [];
      foreach ($roles_update as $role) {
        $roles_update_str[] = ($role[0] ?? '?') . ' ('
          . (($role[1] ?? 0) ? '✅' : '❌') . ','
          . (($role[2] ?? 0) ? '✅' : '❌') . ','
          . (($role[3] ?? 0) ? '✅' : '❌') . ','
          . (($role[4] ?? 0) ? '✅' : '❌') . ')';
      }
      $roles_update_str = implode(' / ', $roles_update_str);

      $roles_delete_str = [];
      foreach ($roles_delete as $role) {
        $roles_delete_str[] = $role[0] ?? '?';
      }
      $roles_delete_str = implode(' / ', $roles_delete_str);

      // Nom : afficher ancien → nouveau si différent
      if ($nom_ancien && $nom_nouveau && $nom_ancien !== $nom_nouveau) {
        $nom_display = $nom_ancien . ' → ' . $nom_nouveau;
      } else {
        $nom_display = $nom_nouveau ?: $nom_ancien;
      }

      // Parent : afficher ancien → nouveau si différent
      if ($parent_ancien && $parent_nouveau && $parent_ancien !== $parent_nouveau) {
        $parent_display = $parent_ancien . ' → ' . $parent_nouveau;
      } else {
        $parent_display = $parent_nouveau ?: $parent_ancien;
      }

      // Langue (tronquée avec tooltip)
      $lang_full    = $lang_nouveau ?? '';
      $lang_display = mb_strlen($lang_full) > 12 ? mb_substr($lang_full, 0, 12) . '…' : $lang_full;
      $lang_cell    = empty($lang_full) ? '' : Markup::create(
        '<span title="' . htmlspecialchars($lang_full) . '">' . htmlspecialchars($lang_display) . '</span>'
      );

      // Référence (tronquée avec tooltip)
      $ref_full    = $ref_nouveau ?? '';
      $ref_display = mb_strlen($ref_full) > 16 ? mb_substr($ref_full, 0, 17) . '…' : $ref_full;
      $ref_cell    = empty($ref_full) ? '' : Markup::create(
        '<span title="' . htmlspecialchars($ref_full) . '">' . htmlspecialchars($ref_display) . '</span>'
      );

      if ($type === 'taxonomy') {
        $rows[] = [
            $formatted_date,
            $record->email,
            $nom_display,
            $parent_display,
            $roles_insert_str,
            $roles_update_str,
            $roles_delete_str,
        ];
    } else {
      $statut = $record->media_status ? '✅' : '❌';

      $rows[] = [
          $formatted_date,
          $record->email,
          $nom_display,
          $parent_display,
          $roles_insert_str,
          $roles_update_str,
          $roles_delete_str,
          $lang_cell,
          $ref_cell,
          $hash_modifie,
          $statut,
      ];
    }
    }

    return $rows;
  }

}