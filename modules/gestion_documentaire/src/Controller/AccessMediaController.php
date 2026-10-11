<?php

namespace Drupal\gestion_documentaire\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Database\Database;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Drupal\Core\Controller\ControllerBase;

/**
 * Contrôleur d'accès pour les ressources du module Gestion Documentaire.
 */
class AccessMediaController extends ControllerBase
{
  
  /**
  * Endpoint de test de performance.
  */
  public function testAccess($id_char_version)
  {
    $account = \Drupal::currentUser();
    
    $start = microtime(true);
    $access = self::access($account, $id_char_version);
    $end = microtime(true);
    
    return new JsonResponse([
      'allowed' => $access->isAllowed(),
      'duration_ms' => round(($end - $start) * 1000, 2),
    ]);
  }
  
  /* ========================================================================
  * METHODES PUBLIQUES (Drupal routing)
  * ====================================================================== */
  
  public static function update(AccountInterface $account, $id_char_version = NULL, $nom = NULL)
  {
    $identifier = $id_char_version ?? $nom;
    // \Drupal::logger('gestion_documentaire')->debug('AccessMediaController::update - identifier: @identifier', ['@identifier' => $identifier]);
    return self::checkAccess($account, $identifier, 'edit');
  }
  
  public static function delete(AccountInterface $account, $id_char_version = NULL, $nom = NULL)
  {
    $identifier = $id_char_version ?? $nom;
    // \Drupal::logger('gestion_documentaire')->debug('AccessMediaController::delete - identifier: @identifier', ['@identifier' => $identifier]);
    return self::checkAccess($account, $identifier, 'delete');
  }
  
  public static function access(AccountInterface $account, $id_char_version = NULL, $nom = NULL)
  {
    $identifier = $id_char_version ?? $nom;
    // \Drupal::logger('gestion_documentaire')->debug('AccessMediaController::access - identifier: @identifier', ['@identifier' => $identifier]);
    return self::checkAccess($account, $identifier, 'view');
  }
  
  /* ========================================================================
  * RESOLUTION id_char_version depuis hash, reference, ou type__ref__lang
  * ====================================================================== */
  
  public static function resolveIdCharVersion(?string $id_char_version): ?string
  {
    // \Drupal::logger('gestion_documentaire')->debug('resolveIdCharVersion - input: @input', ['@input' => $id_char_version ?? 'NULL']);
    if (!$id_char_version) {
      return NULL;
    }
    
    $connection = \Drupal::database();
    // ----------------------------
    // Format: media_123_1
    // ----------------------------
    if (preg_match('/^media_(\d+)(?:_(\d+))?$/', $id_char_version, $matches)) {
      $mid     = $matches[1];
      $version = $matches[2] ?? 1;
      // \Drupal::logger('gestion_documentaire')->debug('resolveIdCharVersion - format media_X_Y - mid: @mid, version: @version', ['@mid' => $mid, '@version' => $version]);
      
      $result = $connection->select('media_field_data', 'm')
      ->fields('m', ['mid'])
      ->condition('m.mid', $mid)
      ->range(0, 1)
      ->execute()
      ->fetchObject();
      
      if ($result) {
        $resolved = 'media_' . $mid . '_' . $version;
        // \Drupal::logger('gestion_documentaire')->debug('resolveIdCharVersion - resolved media_X_Y: @resolved', ['@resolved' => $resolved]);
        return $resolved;
      }
      
      // \Drupal::logger('gestion_documentaire')->debug('resolveIdCharVersion - media_X_Y not found in DB'); 
      return NULL;
    }
    
    // ----------------------------
    // Format: type__ref_or_name__lang
    // ----------------------------
    if (preg_match('/^([a-z_]+)__([^_]+)__([a-z]{2}(?:,[a-z]{2})*)$/i', $id_char_version, $matches)) {
      $type_value  = $matches[1];
      $ref_or_name = $matches[2];
      $lang_value  = $matches[3];
      // \Drupal::logger('gestion_documentaire')->debug('resolveIdCharVersion - format type__ref__lang - type: @type, ref: @ref, lang: @lang', ['@type' => $type_value, '@ref' => $ref_or_name, '@lang' => $lang_value]);
      
      $is_numeric_ref = preg_match('/^[0-9]{6}$/', $ref_or_name);
      
      if ($is_numeric_ref) {
        // \Drupal::logger('gestion_documentaire')->debug('resolveIdCharVersion - numeric ref detected'); 
        $sql = "
        SELECT m.mid, COALESCE(mrv.field_media_version_value, 1) AS version
        FROM media_field_data m
        INNER JOIN (
          SELECT mid, MAX(vid) AS max_vid
          FROM media_revision
          GROUP BY mid
          ) latest ON m.mid = latest.mid
          INNER JOIN media_revision mr ON m.mid = mr.mid AND mr.vid = latest.max_vid
          LEFT JOIN media__field_media_type mrt ON mr.vid = mrt.revision_id
          LEFT JOIN media__field_media_reference mrr ON mr.vid = mrr.revision_id
          LEFT JOIN media__field_media_lang mrl ON mr.vid = mrl.revision_id
          LEFT JOIN media__field_media_version mrv ON mr.vid = mrv.revision_id
          WHERE mrt.field_media_type_value LIKE :type_pattern
          AND mrr.field_media_reference_value LIKE :ref_pattern
          AND mrl.field_media_lang_value LIKE :lang_pattern
          LIMIT 1
          ";
          
          $params = [
            ':type_pattern' => '%' . $type_value . '%',
            ':ref_pattern'  => '%' . $ref_or_name . '%',
            ':lang_pattern' => '%' . $lang_value . '%',
          ];
          
          $result = $connection->query($sql, $params)->fetchObject();
        } else {
          // \Drupal::logger('gestion_documentaire')->debug('resolveIdCharVersion - name-based ref detected'); 
          $sql = "
          SELECT m.mid, COALESCE(mrv.field_media_version_value, 1) AS version
          FROM media_field_data m
          INNER JOIN (
            SELECT mid, MAX(vid) AS max_vid
            FROM media_revision
            GROUP BY mid
            ) latest ON m.mid = latest.mid
            INNER JOIN media_revision mr ON m.mid = mr.mid AND mr.vid = latest.max_vid
            LEFT JOIN media__field_media_type mrt ON mr.vid = mrt.revision_id
            LEFT JOIN media__field_media_lang mrl ON mr.vid = mrl.revision_id
            LEFT JOIN media__field_media_version mrv ON mr.vid = mrv.revision_id
            WHERE mrt.field_media_type_value LIKE :type_pattern
            AND m.name LIKE :name_pattern
            AND mrl.field_media_lang_value LIKE :lang_pattern
            LIMIT 1
            ";
            
            $params = [
              ':type_pattern' => '%' . $type_value . '%',
              ':name_pattern' => '%' . $ref_or_name . '%',
              ':lang_pattern' => '%' . $lang_value . '%',
            ];
            
            $result = $connection->query($sql, $params)->fetchObject();
          }
          
          if ($result) {
            $resolved = 'media_' . $result->mid . '_' . $result->version;
            // \Drupal::logger('gestion_documentaire')->debug('resolveIdCharVersion - resolved type__ref__lang: @resolved', ['@resolved' => $resolved]);
            return $resolved;
          }
          
          // \Drupal::logger('gestion_documentaire')->debug('resolveIdCharVersion - type__ref__lang not found in DB'); 
          return NULL;
        }
        
        // ----------------------------
        // Format: taxonomy_123
        // ----------------------------
        if (strpos($id_char_version, 'taxonomy_') === 0) {
          $parts = explode('_', $id_char_version);
          $tid   = end($parts);
          // \Drupal::logger('gestion_documentaire')->debug('resolveIdCharVersion - format taxonomy_X - tid: @tid', ['@tid' => $tid]);
          
          $result = $connection->select('taxonomy_term_field_data', 't')
          ->fields('t', ['tid'])
          ->condition('t.tid', $tid)
          ->condition('t.default_langcode', 1)
          ->condition('t.vid', 'dossiers')
          ->range(0, 1)
          ->execute()
          ->fetchObject();
          
          if ($result) {
            $resolved = 'taxonomy_' . $tid;
            // \Drupal::logger('gestion_documentaire')->debug('resolveIdCharVersion - resolved taxonomy_X: @resolved', ['@resolved' => $resolved]);
            return $resolved;
          }
          
          // \Drupal::logger('gestion_documentaire')->debug('resolveIdCharVersion - taxonomy_X not found in DB'); 
          return NULL;
        }
        
        // ----------------------------
        // Fallback: recherche generique par mid
        // ----------------------------
        // \Drupal::logger('gestion_documentaire')->debug('resolveIdCharVersion - fallback: generic mid search'); 
        $query = $connection->select('media_field_data', 'm')
        ->fields('m', ['mid'])
        ->condition('m.mid', $id_char_version, 'LIKE')
        ->range(0, 1);
        
        $query->leftJoin('media__field_media_hash', 'mh', 'm.mid = mh.entity_id');
        $query->leftJoin('media__field_media_reference', 'mr', 'm.mid = mr.entity_id');
        
        $result = $query->execute()->fetchObject();
        
        if ($result) {
          $resolved = 'media_' . $result->mid . '_1';
          // \Drupal::logger('gestion_documentaire')->debug('resolveIdCharVersion - resolved generic mid: @resolved', ['@resolved' => $resolved]);
          return $resolved;
        }
        
        // ----------------------------
        // Fallback: taxonomy par nom exact
        // ----------------------------
        // \Drupal::logger('gestion_documentaire')->debug('resolveIdCharVersion - fallback: taxonomy by name'); 
        $taxonomy_result = $connection->select('taxonomy_term_field_data', 'ttfd')
        ->fields('ttfd', ['tid'])
        ->condition('ttfd.name', '%' . $id_char_version . '%', 'LIKE')
        ->condition('ttfd.vid', 'dossiers')
        ->condition('ttfd.default_langcode', 1)
        ->range(0, 1)
        ->execute()
        ->fetchObject();
        
        if ($taxonomy_result) {
          $resolved = 'taxonomy_' . $taxonomy_result->tid;
          // \Drupal::logger('gestion_documentaire')->debug('resolveIdCharVersion - resolved taxonomy by name: @resolved', ['@resolved' => $resolved]);
          return $resolved;
        }
        
        // ----------------------------
        // Fallback: recherche par nom de fichier (pdf/doc/docx/xlsx)
        // ----------------------------
        // \Drupal::logger('gestion_documentaire')->debug('resolveIdCharVersion - fallback: filename search for @input', ['@input' => $id_char_version]); 
        if (preg_match('/^(.+)\.(pdf|doc|docx|xlsx)$/i', $id_char_version, $matches)) {
          $filename = $matches[1];
          // \Drupal::logger('gestion_documentaire')->debug('resolveIdCharVersion - filename detected: @filename', ['@filename' => $filename]);
          
          $sql = "
          SELECT m.mid, COALESCE(mrv.field_media_version_value, 1) AS version
          FROM media_field_data m
          INNER JOIN (
            SELECT mid, MAX(vid) AS max_vid
            FROM media_revision
            GROUP BY mid
            ) latest ON m.mid = latest.mid
            INNER JOIN media_revision mr ON m.mid = mr.mid AND mr.vid = latest.max_vid
            LEFT JOIN media__field_media_version mrv ON mr.vid = mrv.revision_id
            WHERE m.name = :filename
            OR m.name = :filename_with_ext
            LIMIT 1
            ";
            
            $result = $connection->query($sql, [
              ':filename'          => $filename,
              ':filename_with_ext' => $id_char_version,
              ])->fetchObject();
              
              if ($result) {
                $resolved = 'media_' . $result->mid . '_' . $result->version;
                // \Drupal::logger('gestion_documentaire')->debug('resolveIdCharVersion - resolved filename: @resolved', ['@resolved' => $resolved]);
                return $resolved;
              }
            }
            
            // ----------------------------
            // Fallback: taxonomy par nom exact puis LIKE
            // ----------------------------
            // \Drupal::logger('gestion_documentaire')->debug('resolveIdCharVersion - fallback: taxonomy exact match then LIKE'); 
            $taxonomy_result = $connection->select('taxonomy_term_field_data', 't')
            ->fields('t', ['tid'])
            ->condition('t.name', $id_char_version)
            ->range(0, 1)
            ->execute()
            ->fetchObject();
            
            if ($taxonomy_result) {
              $resolved = 'taxonomy_' . $taxonomy_result->tid;
              // \Drupal::logger('gestion_documentaire')->debug('resolveIdCharVersion - resolved taxonomy exact: @resolved', ['@resolved' => $resolved]);
              return $resolved;
            }
            
            $taxonomy_like_result = $connection->select('taxonomy_term_field_data', 't')
            ->fields('t', ['tid'])
            ->condition('t.name', '%' . $id_char_version . '%', 'LIKE')
            ->condition('t.default_langcode', 1)
            ->condition('t.vid', 'dossiers')
            ->range(0, 1)
            ->execute()
            ->fetchObject();
            
            if ($taxonomy_like_result) {
              $resolved = 'taxonomy_' . $taxonomy_like_result->tid;
              // \Drupal::logger('gestion_documentaire')->debug('resolveIdCharVersion - resolved taxonomy LIKE: @resolved', ['@resolved' => $resolved]);
              return $resolved;
            }
            
            // \Drupal::logger('gestion_documentaire')->debug('resolveIdCharVersion - all fallbacks exhausted, returning NULL'); 
            return NULL;
          }
          
          /* ========================================================================
          * RESOLUTION DU PARENT TAXONOMIQUE
          * ====================================================================== */
          
          private static function resolveParentTaxonomy(string $id_char_version, $connection): ?int
          {
            // \Drupal::logger('gestion_documentaire')->debug('resolveParentTaxonomy - input: @input', ['@input' => $id_char_version]);
            if (strpos($id_char_version, 'media_') === 0) {
              $parts = explode('_', $id_char_version);
              $mid   = $parts[1];
              // \Drupal::logger('gestion_documentaire')->debug('resolveParentTaxonomy - media mode - mid: @mid', ['@mid' => $mid]);
              
              $link = $connection->select('media__field_taxonomie_associee', 'link')
              ->fields('link', ['field_taxonomie_associee_target_id'])
              ->condition('entity_id', $mid)
              ->range(0, 1)
              ->execute()
              ->fetchObject();
              
              if (!$link) {
                // \Drupal::logger('gestion_documentaire')->debug('resolveParentTaxonomy - no taxonomy link for media @mid', ['@mid' => $mid]); 
                return NULL;
              }
              
              $taxonomy = $connection->select('taxonomy_term_field_data', 't')
              ->fields('t', ['tid'])
              ->condition('t.tid', $link->field_taxonomie_associee_target_id)
              ->condition('t.default_langcode', 1)
              ->condition('t.vid', 'dossiers')
              ->execute()
              ->fetchObject();
              
              $result = $taxonomy ? (int) $taxonomy->tid : NULL;
              // \Drupal::logger('gestion_documentaire')->debug('resolveParentTaxonomy - media result: @result', ['@result' => $result ?? 'NULL']);
              return $result;
            }
            
            if (strpos($id_char_version, 'taxonomy_') === 0) {
              $parts = explode('_', $id_char_version);
              $tid   = end($parts);
              // \Drupal::logger('gestion_documentaire')->debug('resolveParentTaxonomy - taxonomy mode - tid: @tid', ['@tid' => $tid]);
              
              $parent_id = $connection->select('taxonomy_term__parent', 'p')
              ->fields('p', ['parent_target_id'])
              ->condition('p.entity_id', $tid)
              ->execute()
              ->fetchField();
              
              $result = $parent_id ? (int) $parent_id : NULL;
              // \Drupal::logger('gestion_documentaire')->debug('resolveParentTaxonomy - taxonomy result: @result', ['@result' => $result ?? 'NULL']);
              return $result;
            }
            
            // \Drupal::logger('gestion_documentaire')->debug('resolveParentTaxonomy - no match, returning NULL'); 
            return NULL;
          }
          
          /* ========================================================================
          * MOTEUR CENTRAL D'AUTORISATION
          * ====================================================================== */
          
          private static function checkAccess(
            AccountInterface $account,
            ?string $id_char_version,
            string $action
            ): AccessResult {
              
              // \Drupal::logger('gestion_documentaire')->debug('checkAccess - action: @action, id_char: @id_char, user: @user', ['@action' => $action, '@id_char' => $id_char_version ?? 'NULL', '@user' => $account->getAccountName()]);
              
              if (
                in_array('administrator', $account->getRoles(), TRUE) ||
                in_array('admin_gestiondocumentaire', $account->getRoles(), TRUE)
                ) {
                  // \Drupal::logger('gestion_documentaire')->debug('checkAccess - admin role, ALLOWED'); 
                  return AccessResult::allowed();
                }
                
                if (!$id_char_version) {
                  // \Drupal::logger('gestion_documentaire')->debug('checkAccess - no id_char_version, FORBIDDEN'); 
                  return AccessResult::forbidden();
                }
                
                $resolved_id = self::resolveIdCharVersion($id_char_version);
                // \Drupal::logger('gestion_documentaire')->debug('checkAccess - resolved_id: @resolved', ['@resolved' => $resolved_id ?? 'NULL']);
                
                if (!$resolved_id) {
                  // \Drupal::logger('gestion_documentaire')->debug('checkAccess - resolution failed, FORBIDDEN'); 
                  return AccessResult::forbidden();
                }
                
                $id_char_version = $resolved_id;
                $connection = \Drupal::database();
                $user_roles      = $account->getRoles();
                // \Drupal::logger('gestion_documentaire')->debug('checkAccess - user_roles: @roles', ['@roles' => implode(', ', $user_roles)]);
                
                $permission_map = [
                  'view'   => ['can_view'],
                  'edit'   => ['can_edit', 'can_edit_role'],
                  'delete' => ['can_delete'],
                ];
                
                if (!isset($permission_map[$action])) {
                  // \Drupal::logger('gestion_documentaire')->debug('checkAccess - unknown action, FORBIDDEN'); 
                  return AccessResult::forbidden();
                }
                
                // ----------------------------
                // 1. Permissions directes sur la ressource
                // ----------------------------
                // \Drupal::logger('gestion_documentaire')->debug('checkAccess - checking direct permissions for @id_char', ['@id_char' => $id_char_version]);
                $roles_bdd = $connection->select('gestion_documentaire_role', 'gdr')
                ->fields('gdr', array_merge(['role_libelle', 'inherit'], $permission_map[$action]))
                ->condition('id_char', $id_char_version)
                ->condition('role_libelle', $user_roles, 'IN')
                ->execute()
                ->fetchAllAssoc('role_libelle');
                
                // \Drupal::logger('gestion_documentaire')->debug('checkAccess - direct roles found: @count', ['@count' => count($roles_bdd)]);
                
                if (!empty($roles_bdd)) {
                  foreach ($user_roles as $role) {
                    if (!isset($roles_bdd[$role])) {
                      continue;
                    }
                    // Perm directe accordée → accès immédiat, pas de remontée
                    foreach ($permission_map[$action] as $field) {
                      if (!empty($roles_bdd[$role]->$field)) {
                        // \Drupal::logger('gestion_documentaire')->debug('checkAccess - direct permission @field for role @role, ALLOWED', ['@field' => $field, '@role' => $role]); 
                        return AccessResult::allowed();
                      }
                    }
                  }
                  
                  // Des lignes existent mais aucune n'accorde l'accès.
                  // On remonte au parent uniquement si inherit=1 sur au moins une ligne du rôle.
                  $can_inherit = FALSE;
                  foreach ($user_roles as $role) {
                    if (isset($roles_bdd[$role]) && !empty($roles_bdd[$role]->inherit)) {
                      $can_inherit = TRUE;
                      // \Drupal::logger('gestion_documentaire')->debug('checkAccess - inherit=1 for role @role', ['@role' => $role]); break;
                    }
                  }
                  
                  if (!$can_inherit) {
                    // inherit=0 sur toutes les lignes directes → bloqué sans remontée
                    // \Drupal::logger('gestion_documentaire')->debug('checkAccess - no inherit, FORBIDDEN'); 
                    return AccessResult::forbidden();
                  }
                  
                  // inherit=1 → on continue et on remonte
                  // \Drupal::logger('gestion_documentaire')->debug('checkAccess - inherit=1, climbing up...');
                }
                
                // ----------------------------
                // 2. Résolution du parent et héritage
                // ----------------------------
                $parent_taxonomy_id = self::resolveParentTaxonomy($id_char_version, $connection);
                // \Drupal::logger('gestion_documentaire')->debug('checkAccess - parent_taxonomy_id: @parent', ['@parent' => $parent_taxonomy_id ?? 'NULL']);
                
                if (!$parent_taxonomy_id) {
                  // \Drupal::logger('gestion_documentaire')->debug('checkAccess - no parent taxonomy, FORBIDDEN'); 
                  return AccessResult::forbidden();
                }
                
                if ($action === 'view') {
                  // \Drupal::logger('gestion_documentaire')->debug('checkAccess - view action, checking descendants with view'); 
                  if (self::hasDescendantWithView($parent_taxonomy_id, $user_roles, $connection)) {
                    // \Drupal::logger('gestion_documentaire')->debug('checkAccess - descendant has view, ALLOWED'); 
                    return AccessResult::allowed();
                  }
                }
                
                $parent_permissions = self::get_permission(
                  'taxonomy_' . $parent_taxonomy_id,
                  $user_roles,
                  $connection
                );
                // \Drupal::logger('gestion_documentaire')->debug('checkAccess - parent permissions count: @count', ['@count' => count($parent_permissions)]);
                
                foreach ($parent_permissions as $perm) {
                  foreach ($permission_map[$action] as $field) {
                    if (!empty($perm->$field)) {
                      // \Drupal::logger('gestion_documentaire')->debug('checkAccess - parent permission @field, ALLOWED', ['@field' => $field]); 
                      return AccessResult::allowed();
                    }
                  }
                }
                
                // \Drupal::logger('gestion_documentaire')->debug('checkAccess - all checks failed, FORBIDDEN'); 
                return AccessResult::forbidden();
              }
              
              /* ========================================================================
              * HERITAGE ASCENDANT : verifie si un descendant a can_view = 1
              * ====================================================================== */
              
              private static function hasDescendantWithView(int $taxonomy_id, array $user_roles, $connection): bool
              {
                // \Drupal::logger('gestion_documentaire')->debug('hasDescendantWithView - taxonomy_id: @tid, roles: @roles', ['@tid' => $taxonomy_id, '@roles' => implode(', ', $user_roles)]);
                $role_placeholders = [];
                $role_bindings     = [];
                
                foreach ($user_roles as $i => $role) {
                  $role_placeholders[]       = ":role$i";
                  $role_bindings[":role$i"]  = $role;
                }
                
                $role_placeholders_sql            = implode(',', $role_placeholders);
                $role_bindings[':parent_id_char'] = $taxonomy_id;
                
                $query = "
                WITH RECURSIVE taxonomy_descendants AS (
                  SELECT entity_id AS child_id, parent_target_id, 0 AS level
                  FROM taxonomy_term__parent
                  WHERE parent_target_id = :parent_id_char
                  
                  UNION ALL
                  
                  SELECT child.entity_id, child.parent_target_id, td.level + 1
                  FROM taxonomy_descendants td
                  JOIN taxonomy_term__parent child ON td.child_id = child.parent_target_id
                  WHERE td.level < 3
                  )
                  SELECT COUNT(*) as has_view
                  FROM taxonomy_descendants td
                  WHERE EXISTS (
                    SELECT 1
                    FROM gestion_documentaire_role gdr
                    WHERE gdr.id_char = CONCAT('taxonomy_', td.child_id)
                    AND gdr.role_libelle IN ($role_placeholders_sql)
                    AND gdr.can_view = 1
                    )
                    ";
                    
                    $result = $connection->query($query, $role_bindings)->fetchField();
                    // \Drupal::logger('gestion_documentaire')->debug('hasDescendantWithView - result: @result', ['@result' => $result]);
                    
                    return (int) $result > 0;
                  }
                  
                  /* ========================================================================
                  * LOGIQUE PARENTS — recursion taxonomy
                  * ====================================================================== */
                  
                  public static function get_permission($id_char_version, $user_roles, $connection)
                  {
                    // \Drupal::logger('gestion_documentaire')->debug('get_permission - id_char: @id_char, roles: @roles', ['@id_char' => $id_char_version, '@roles' => implode(', ', $user_roles)]);
                    $taxonomy_id_char = $id_char_version;
                    
                    if (strpos($id_char_version, 'media_') === 0) {
                      $parts = explode('_', $id_char_version);
                      $mid   = (int) $parts[1];
                      // \Drupal::logger('gestion_documentaire')->debug('get_permission - media mode - mid: @mid', ['@mid' => $mid]);
                      
                      $taxonomy_tid = $connection->select('media__field_taxonomie_associee', 'link')
                      ->fields('link', ['field_taxonomie_associee_target_id'])
                      ->condition('entity_id', $mid)
                      ->range(0, 1)
                      ->execute()
                      ->fetchField();
                      
                      if ($taxonomy_tid) {
                        $taxonomy_id_char = 'taxonomy_' . $taxonomy_tid;
                        // \Drupal::logger('gestion_documentaire')->debug('get_permission - found taxonomy tid: @tid', ['@tid' => $taxonomy_tid]);
                      } else {
                        // \Drupal::logger('gestion_documentaire')->debug('get_permission - no taxonomy found for media, returning empty'); 
                        return [];
                      }
                    }
                    
                    if (strpos($taxonomy_id_char, 'taxonomy_') !== 0) {
                      // \Drupal::logger('gestion_documentaire')->debug('get_permission - not a taxonomy, returning empty'); 
                      return [];
                    }
                    
                    $tid_parts = explode('_', $taxonomy_id_char);
                    $tid       = end($tid_parts);
                    // \Drupal::logger('gestion_documentaire')->debug('get_permission - tid: @tid', ['@tid' => $tid]);
                    
                    $placeholders = [];
                    $bindings     = [':tid' => $tid];
                    
                    foreach ($user_roles as $index => $role) {
                      $ph             = ':role' . $index;
                      $placeholders[] = $ph;
                      $bindings[$ph]  = $role;
                    }
                    
                    if (empty($placeholders)) {
                      // \Drupal::logger('gestion_documentaire')->debug('get_permission - no roles, returning empty'); 
                      return [];
                    }
                    
                    $query = "
                    WITH RECURSIVE taxonomy_path AS (
                      SELECT
                      t.tid,
                      p.parent_target_id,
                      t.name,
                      0 AS level
                      FROM taxonomy_term_field_data t
                      LEFT JOIN taxonomy_term__parent p ON p.entity_id = t.tid
                      WHERE t.tid = :tid
                      AND t.default_langcode = 1
                      AND t.vid = 'dossiers'
                      UNION ALL
                      
                      SELECT
                      parent.tid,
                      pp.parent_target_id,
                      parent.name,
                      tp.level + 1
                      FROM taxonomy_term_field_data parent
                      JOIN taxonomy_term__parent pp ON pp.entity_id = parent.tid
                      JOIN taxonomy_path tp ON tp.parent_target_id = parent.tid
                      WHERE parent.default_langcode = 1
                      AND parent.vid = 'dossiers'
                      )
                      SELECT
                      tp.tid,
                      tp.level,
                      COALESCE(MAX(gdr.can_view), 0)      AS can_view,
                      COALESCE(MAX(gdr.can_edit), 0)      AS can_edit,
                      COALESCE(MAX(gdr.can_delete), 0)    AS can_delete,
                      COALESCE(MAX(gdr.can_edit_role), 0) AS can_edit_role
                      FROM taxonomy_path tp
                      LEFT JOIN gestion_documentaire_role gdr
                      ON gdr.id_char = CONCAT('taxonomy_', tp.tid)
                      AND gdr.role_libelle IN (" . implode(',', $placeholders) . ")
                      GROUP BY tp.tid, tp.level
                      ORDER BY tp.level ASC
                      ";
                      
                      $results = $connection->query($query, $bindings)->fetchAll();
                    //  // \Drupal::logger('gestion_documentaire')->debug('get_permission - results count: @count', ['@count' => count($results)]);
                      return $results;
                    }
                    
                    /* ========================================================================
                    * RECUPERATION DES PERMISSIONS COMPLETES D'UNE RESSOURCE
                    * ====================================================================== */
                    
                    public static function getResourcePermissions(AccountInterface $account, $id_char_version)
                    {
                      // \Drupal::logger('gestion_documentaire')->debug('getResourcePermissions - id_char: @id_char, user: @user', ['@id_char' => $id_char_version, '@user' => $account->getAccountName()]);
                      $permissions = [
                        'can_view'      => FALSE,
                        'can_edit'      => FALSE,
                        'can_edit_role' => FALSE,
                        'can_delete'    => FALSE,
                      ];
                      
                      if (
                        in_array('administrator', $account->getRoles(), TRUE) ||
                        in_array('admin_gestiondocumentaire', $account->getRoles(), TRUE)
                        ) {
                          // \Drupal::logger('gestion_documentaire')->debug('getResourcePermissions - admin role, ALL PERMISSIONS'); 
                          return [
                            'can_view'      => TRUE,
                            'can_edit'      => TRUE,
                            'can_edit_role' => TRUE,
                            'can_delete'    => TRUE,
                          ];
                        }
                        
                        $id_char_version = self::resolveIdCharVersion($id_char_version);
                        // \Drupal::logger('gestion_documentaire')->debug('getResourcePermissions - resolved: @resolved', ['@resolved' => $id_char_version ?? 'NULL']);
                        
                        if (!$id_char_version) {
                          // \Drupal::logger('gestion_documentaire')->debug('getResourcePermissions - resolution failed, returning all false'); 
                          return $permissions;
                        }
                        
                        $connection = \Drupal::database();
                        $user_roles = $account->getRoles();
                        
                        // ----------------------------
                        // Permissions directes
                        // ----------------------------
                        // \Drupal::logger('gestion_documentaire')->debug('getResourcePermissions - checking direct permissions'); 
                        $roles_bdd = $connection->select('gestion_documentaire_role', 'gdr')
                        ->fields('gdr', [
                          'role_libelle',
                          'inherit',
                          'can_view',
                          'can_edit',
                          'can_edit_role',
                          'can_delete',
                          ])
                          ->condition('id_char', $id_char_version)
                          ->condition('role_libelle', $user_roles, 'IN')
                          ->execute()
                          ->fetchAllAssoc('role_libelle');
                          
                          $has_any_direct_permission = FALSE;
                          $can_inherit               = FALSE;
                          
                          if (!empty($roles_bdd)) {
                            // \Drupal::logger('gestion_documentaire')->debug('getResourcePermissions - direct roles found: @count', ['@count' => count($roles_bdd)]);
                            foreach ($user_roles as $role) {
                              if (!isset($roles_bdd[$role])) {
                                continue;
                              }
                              
                              $permissions['can_view']      |= (bool) $roles_bdd[$role]->can_view;
                              $permissions['can_edit']      |= (bool) $roles_bdd[$role]->can_edit;
                              $permissions['can_edit_role'] |= (bool) $roles_bdd[$role]->can_edit_role;
                              $permissions['can_delete']    |= (bool) $roles_bdd[$role]->can_delete;
                              
                              if (!empty($roles_bdd[$role]->inherit)) {
                                $can_inherit = TRUE;
                              }
                            }
                            
                            $has_any_direct_permission = in_array(TRUE, $permissions, TRUE);
                            
                            // Si au moins une perm directe est accordée → retour immédiat
                            if ($has_any_direct_permission) {
                              // \Drupal::logger('gestion_documentaire')->debug('getResourcePermissions - direct permission granted, returning: @perms', ['@perms' => json_encode($permissions)]); 
                              return $permissions;
                            }
                            
                            // Lignes présentes mais tout à FALSE et inherit=0 → bloqué
                            if (!$can_inherit) {
                              // \Drupal::logger('gestion_documentaire')->debug('getResourcePermissions - no inherit, returning all false'); 
                              return $permissions;
                            }
                            
                            // inherit=1 → on continue vers les parents
                            // \Drupal::logger('gestion_documentaire')->debug('getResourcePermissions - inherit=1, climbing up...');
                          }
                          
                          // ----------------------------
                          // Permissions parents
                          // ----------------------------
                          // \Drupal::logger('gestion_documentaire')->debug('getResourcePermissions - checking parent permissions'); 
                          $parent_permissions = self::get_permission($id_char_version, $user_roles, $connection);
                          
                          foreach ($parent_permissions as $perm) {
                            $permissions['can_view']      |= (bool) $perm->can_view;
                            $permissions['can_edit']      |= (bool) $perm->can_edit;
                            $permissions['can_edit_role'] |= (bool) $perm->can_edit_role;
                            $permissions['can_delete']    |= (bool) $perm->can_delete;
                          }
                          
                          // \Drupal::logger('gestion_documentaire')->debug('getResourcePermissions - final permissions: @perms', ['@perms' => json_encode($permissions)]); 
                          return $permissions;
                        }
                      }