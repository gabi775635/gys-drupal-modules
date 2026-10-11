<?php

namespace Drupal\gestion_documentaire\Controller;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Database\Database;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Contrôleur d'accès pour les ressources du module Gestion Documentaire.
 */
class AccessMediaControllerv2
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
       return self::checkAccess($account, $identifier, 'edit');
   }
   
   public static function delete(AccountInterface $account, $id_char_version = NULL, $nom = NULL)
   {
       $identifier = $id_char_version ?? $nom;
       return self::checkAccess($account, $identifier, 'delete');
   }
   
   public static function access(AccountInterface $account, $id_char_version = NULL, $nom = NULL)
   {
       $identifier = $id_char_version ?? $nom;
       return self::checkAccess($account, $identifier, 'view');
   }
  /* ========================================================================
   * Resolution id_char_version depuis hash, reference, ou type__ref__lang
   * ====================================================================== */

  public static function resolveIdCharVersion(?string $id_char_version): ?string
  {
    // $logger = \Drupal::logger('gestion_documentaire');

    // $logger->debug('[resolveIdCharVersion] Entree: @input', ['@input' => $id_char_version]);

    if (!$id_char_version) {
      // $logger->warning('[resolveIdCharVersion] id_char_version vide ou null, retour NULL');
      return NULL;
    }

    $connection = Database::getConnection('default', 'drupal');
    // ----------------------------
    // Format: media_123_1
    // ----------------------------
    if (preg_match('/^media_(\d+)(?:_(\d+))?$/', $id_char_version, $matches)) {
      $mid     = $matches[1];
      $version = $matches[2] ?? 1;

      // $logger->debug('[resolveIdCharVersion] Format media_N_V detecte: mid=@mid, version=@version', [ '@mid'     => $mid, '@version' => $version, ]);

      $result = $connection->select('media_field_data', 'm')
        ->fields('m', ['mid'])
        ->condition('m.mid', $mid)
        ->range(0, 1)
        ->execute()
        ->fetchObject();

      if ($result) {
        $resolved = 'media_' . $mid . '_' . $version;
        // $logger->debug('[resolveIdCharVersion] Media trouve en BDD, retour: @resolved', ['@resolved' => $resolved]);
        return $resolved;
      }

      // $logger->warning('[resolveIdCharVersion] Aucun media en BDD pour mid=@mid', ['@mid' => $mid]);
      return NULL;
    }

    // ----------------------------
    // Format: type__ref_or_name__lang
    // ----------------------------
    if (preg_match('/^([a-z_]+)__([^_]+)__([a-z]{2}(?:,[a-z]{2})*)$/i', $id_char_version, $matches)) {
      $type_value  = $matches[1];
      $ref_or_name = $matches[2];
      $lang_value  = $matches[3];

      $is_numeric_ref = preg_match('/^[0-9]{6}$/', $ref_or_name);

      // $logger->debug('[resolveIdCharVersion] Format type__ref__lang detecte: type=@type, ref=@ref, is_numeric=@num, lang=@lang', ['@type' => $type_value,'@ref'  => $ref_or_name,'@num'  => $is_numeric_ref ? 'YES' : 'NO','@lang' => $lang_value,]);

      if ($is_numeric_ref) {
        // $logger->debug('[resolveIdCharVersion] Recherche par reference numerique');

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

        // $logger->debug('[resolveIdCharVersion] Params requete numerique: @params', [   '@params' => json_encode($params),]);

        $result = $connection->query($sql, $params)->fetchObject();
      } else {
        // $logger->debug('[resolveIdCharVersion] Recherche par nom de document');

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

        // $logger->debug('[resolveIdCharVersion] Params requete nom: @params', ['@params' => json_encode($params),]);

        $result = $connection->query($sql, $params)->fetchObject();
      }

      if ($result) {
        $resolved = 'media_' . $result->mid . '_' . $result->version;
        // $logger->debug('[resolveIdCharVersion] Media trouve via type__ref__lang: mid=@mid, version=@version, retour=@resolved', ['@mid'      => $result->mid,'@version'  => $result->version,'@resolved' => $resolved,]);
        return $resolved;
      }

      // $logger->warning('[resolveIdCharVersion] Aucun media trouve pour type__ref__lang: @input | type=@type, ref=@ref, lang=@lang', ['@input' => $id_char_version,'@type'  => $type_value,'@ref'   => $ref_or_name,'@lang'  => $lang_value,]);

      return NULL;
    }

    // ----------------------------
    // Format: taxonomy_123
    // ----------------------------
    if (strpos($id_char_version, 'taxonomy_') === 0) {
      $parts = explode('_', $id_char_version);
      $tid   = end($parts);

      // $logger->debug('[resolveIdCharVersion] Format taxonomy_N detecte: tid=@tid', ['@tid' => $tid]);

      $result = $connection->select('taxonomy_term_field_data', 't')
        ->fields('t', ['tid'])
        ->condition('t.tid', $tid)
        ->condition('t.default_langcode', 1)
        ->range(0, 1)
        ->execute()
        ->fetchObject();

      if ($result) {
        $resolved = 'taxonomy_' . $tid;
        // $logger->debug('[resolveIdCharVersion] Taxonomy trouvee en BDD, retour: @resolved', ['@resolved' => $resolved]);
        return $resolved;
      }

      // $logger->warning('[resolveIdCharVersion] Aucune taxonomy en BDD pour tid=@tid', ['@tid' => $tid]);
      return NULL;
    }

    
    
    // ----------------------------
// Fallback: recherche par nom de fichier (pdf/doc/docx)
// ----------------------------
if (preg_match('/^(.+)\.(pdf|doc|docx)$/i', $id_char_version, $matches)) {
  $filename = $matches[1];

  // $logger->debug('[resolveIdCharVersion] Fallback nom de fichier: @filename', ['@filename' => $filename]);

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
      // $logger->debug('[resolveIdCharVersion] Fallback nom de fichier trouve, retour: @resolved', ['@resolved' => $resolved]);
      return $resolved;
  }

  // $logger->warning('[resolveIdCharVersion] Fallback nom de fichier: aucun media trouve pour @filename', ['@filename' => $filename]);
}


// ----------------------------
    // Fallback: recherche par nom de taxonomie (ex: catalogue_avec_tarif)
    // ----------------------------
    // $logger->debug('[resolveIdCharVersion] Tentative recherche par nom de taxonomie: @input', ['@input' => $id_char_version]);

    $result = $connection->select('taxonomy_term_field_data', 't')
      ->fields('t', ['tid'])
      ->condition('t.name', $id_char_version)
      ->condition('t.default_langcode', 1)
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    if (!$result) {
      // Essai avec remplacement des underscores par des espaces
      $name_with_spaces = str_replace('_', ' ', $id_char_version);
      $result = $connection->select('taxonomy_term_field_data', 't')
        ->fields('t', ['tid'])
        ->condition('t.name', $name_with_spaces)
        ->condition('t.default_langcode', 1)
        ->range(0, 1)
        ->execute()
        ->fetchObject();
    }

    if ($result) {
      $resolved = 'taxonomy_' . $result->tid;
      // $logger->debug('[resolveIdCharVersion] Taxonomy trouvee par nom, retour: @resolved', ['@resolved' => $resolved]);
      return $resolved;
    }


// $logger->warning('[resolveIdCharVersion] Fallback: aucune correspondance trouvee pour: @input', ['@input' => $id_char_version]);

return NULL;
  }

  /* ========================================================================
   * MOTEUR CENTRAL D'AUTORISATION
   * ====================================================================== */

 /* ========================================================================
 * MOTEUR CENTRAL D'AUTORISATION
 * ====================================================================== */

private static function checkAccess(
  AccountInterface $account,
  ?string $id_char_version,
  string $action
): AccessResult {

  // $logger = \Drupal::logger('gestion_documentaire');
  $uid    = $account->id();
  $roles  = implode(', ', $account->getRoles());

  if (in_array('administrator', $account->getRoles(), TRUE) || in_array('admin_gestiondocumentaire', $account->getRoles(), TRUE)) {
    return AccessResult::allowed();
  }

  if (!$id_char_version) {
    return AccessResult::forbidden();
  }

  $resolved_id = self::resolveIdCharVersion($id_char_version);

  if (!$resolved_id) {
    return AccessResult::forbidden();
  }

  $id_char_version = $resolved_id;

  $connection = Database::getConnection();
  $user_roles = $account->getRoles();

  $permission_map = [
    'view'   => ['can_view'],
    'edit'   => ['can_edit', 'can_edit_role'],
    'delete' => ['can_delete'],
  ];

  if (!isset($permission_map[$action])) {
    return AccessResult::forbidden();
  }

  // ----------------------------
  // 1. Permissions directes sur la ressource
  // ----------------------------
  $roles_bdd = $connection->select('gestion_documentaire_role', 'gdr')
    ->fields('gdr', array_merge(['role_libelle'], $permission_map[$action]))
    ->condition('id_char', $id_char_version)
    ->condition('role_libelle', $user_roles, 'IN')
    ->execute()
    ->fetchAllAssoc('role_libelle');

  if (!empty($roles_bdd)) {
    foreach ($user_roles as $role) {
      if (!isset($roles_bdd[$role])) {
        continue;
      }

      foreach ($permission_map[$action] as $field) {
        $val = $roles_bdd[$role]->$field ?? NULL;
        if (!empty($val)) {
          return AccessResult::allowed();
        }
      }
    }
  }

  // ----------------------------
  // 2. Permissions héritées depuis les parents (top-down)
  // ----------------------------
  $parent_taxonomy_id = NULL;

  if (strpos($id_char_version, 'media_') === 0) {
    $parts = explode('_', $id_char_version);
    $mid   = $parts[1];

    $link_result = $connection->select('media__field_taxonomie_associee', 'link')
      ->fields('link', ['field_taxonomie_associee_target_id'])
      ->condition('entity_id', $mid)
      ->range(0, 1)
      ->execute()
      ->fetchObject();

    if ($link_result) {
      $taxonomy_result = $connection->select('taxonomy_term_field_data', 't')
        ->fields('t', ['tid'])
        ->condition('t.tid', $link_result->field_taxonomie_associee_target_id)
        ->condition('t.default_langcode', 1)
        ->execute()
        ->fetchObject();

      if ($taxonomy_result) {
        $parent_taxonomy_id = $taxonomy_result->tid;
      }
    }
  } else {
    $tid = NULL;

    if (strpos($id_char_version, 'taxonomy_') === 0) {
      $parts = explode('_', $id_char_version);
      $tid   = end($parts);
    }

    if ($tid) {
      $parent_taxonomy_id = $connection->select('taxonomy_term__parent', 'p')
        ->fields('p', ['parent_target_id'])
        ->condition('p.entity_id', $tid)
        ->execute()
        ->fetchField();
    }


    
  }

  if ($parent_taxonomy_id) {
    // Pour l'action 'view', on utilise une logique différente : héritage ascendant + descendant
    if ($action === 'view') {
      // Récupérer tous les descendants avec leurs permissions
      $has_descendant_view = self::hasDescendantWithView(
        $parent_taxonomy_id,
        $user_roles,
        $connection
      );
      
      if ($has_descendant_view) {
        return AccessResult::allowed();
      }
    }
    
    // Pour toutes les actions (y compris view si pas de descendant), on fait l'héritage descendant classique
    $parent_permissions = self::get_permission(
      'taxonomy_' . $parent_taxonomy_id,
      $user_roles,
      $connection
    );

    foreach ($parent_permissions as $perm) {
      foreach ($permission_map[$action] as $field) {
        $val = $perm->$field ?? NULL;
        if (!empty($val)) {
          return AccessResult::allowed();
        }
      }
    }
  }

  return AccessResult::forbidden();
}

/**
 * Vérifie si un nœud (ou un de ses descendants) a can_view = 1 pour les rôles de l'utilisateur
 * Héritage ascendant : un parent est visible si un de ses enfants a view=1
 */
private static function hasDescendantWithView(int $taxonomy_id, array $user_roles, $connection): bool
{
  $role_placeholders = [];
  $role_bindings = [];
  foreach ($user_roles as $i => $role) {
    $role_placeholders[] = ":role$i";
    $role_bindings[":role$i"] = $role;
  }
  $role_placeholders_sql = implode(',', $role_placeholders);
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
  
  return (int) $result > 0;
}

  /* ========================================================================
   * LOGIQUE PARENTS — recursion taxonomy
   * ====================================================================== */

  public static function get_permission($id_char_version, $user_roles, $connection)
  {
    // $logger = \Drupal::logger('gestion_documentaire');

    // $logger->debug('[get_permission] Debut: id_char_version=@id, roles=[@roles]', ['@id'    => $id_char_version,'@roles' => implode(', ', $user_roles),]);

    $placeholders = [];
    $bindings     = [':id_char_version' => $id_char_version];

    foreach ($user_roles as $index => $role) {
      $ph             = ':role' . $index;
      $placeholders[] = $ph;
      $bindings[$ph]  = $role;
    }

    // $logger->debug('[get_permission] Bindings CTE: @bindings', ['@bindings' => json_encode($bindings),]);

    $query = "
      WITH RECURSIVE taxonomy_path AS (
          SELECT
              t.tid,
              p.parent_target_id,
              t.name,
              0 AS level
          FROM taxonomy_term_field_data t
          LEFT JOIN taxonomy_term__parent p ON p.entity_id = t.tid
          WHERE CONCAT('taxonomy_', t.tid) = :id_char_version
            AND t.default_langcode = 1

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
      )
      SELECT
          tp.tid,
          COALESCE(MAX(gdr.can_view), 0)      AS can_view,
          COALESCE(MAX(gdr.can_edit), 0)      AS can_edit,
          COALESCE(MAX(gdr.can_delete), 0)    AS can_delete,
          COALESCE(MAX(gdr.can_edit_role), 0) AS can_edit_role
      FROM taxonomy_path tp
      LEFT JOIN gestion_documentaire_role gdr
          ON gdr.id_char = CONCAT('taxonomy_', tp.tid)
          AND gdr.role_libelle IN (" . implode(',', $placeholders) . ")
      GROUP BY tp.tid
      ORDER BY tp.tid ASC;
    ";

    $results = $connection->query($query, $bindings)->fetchAll();

    // $logger->debug('[get_permission] Resultats CTE: @count lignes | detail: @data', ['@count' => count($results),'@data'  => json_encode($results),]);

    if (empty($results)) {
      // $logger->warning('[get_permission] CTE sans resultats pour id_char_version=@id — taxonomy introuvable ou pas de permissions configurees', ['@id' => $id_char_version,]);
    }

    return $results;
  }

  /* ========================================================================
   * RECUPERATION DES PERMISSIONS COMPLETES D'UNE RESSOURCE
   * ====================================================================== */

  public static function getResourcePermissions(AccountInterface $account, $id_char_version)
  {
    // $logger = \Drupal::logger('gestion_documentaire');

    // $logger->debug('[getResourcePermissions] Debut: uid=@uid, id_char_version=@id', ['@uid' => $account->id(),'@id'  => $id_char_version ?? 'NULL',]);

    $permissions = [
      'can_view'      => FALSE,
      'can_edit'      => FALSE,
      'can_edit_role' => FALSE,
      'can_delete'    => FALSE,
    ];

    if (in_array('administrator', $account->getRoles(), TRUE) || in_array('admin_gestiondocumentaire', $account->getRoles(), TRUE)) {
      // $logger->debug('[getResourcePermissions] Utilisateur administrator, toutes permissions accordees');
      return [
        'can_view'      => TRUE,
        'can_edit'      => TRUE,
        'can_edit_role' => TRUE,
        'can_delete'    => TRUE,
      ];
    }

    $id_char_version = self::resolveIdCharVersion($id_char_version);

    if (!$id_char_version) {
      // $logger->warning('[getResourcePermissions] resolveIdCharVersion retourne NULL, retour permissions vides');
      return $permissions;
    }

    // $logger->debug('[getResourcePermissions] id_char_version resolu: @id', ['@id' => $id_char_version]);

    $connection = Database::getConnection();
    $user_roles = $account->getRoles();

    // ----------------------------
    // Permissions directes
    // ----------------------------
    // $logger->debug('[getResourcePermissions] Recherche permissions directes pour id_char=@id, roles=[@roles]', ['@id'    => $id_char_version,'@roles' => implode(', ', $user_roles),]);

    $roles_bdd = $connection->select('gestion_documentaire_role', 'gdr')
      ->fields('gdr', [
        'role_libelle',
        'can_view',
        'can_edit',
        'can_edit_role',
        'can_delete',
      ])
      ->condition('id_char', $id_char_version)
      ->condition('role_libelle', $user_roles, 'IN')
      ->execute()
      ->fetchAllAssoc('role_libelle');

    // $logger->debug('[getResourcePermissions] Lignes directes trouvees: @count | detail: @data',['@count' => count($roles_bdd),'@data' => json_encode($roles_bdd)]);

    if (!empty($roles_bdd)) {
      foreach ($user_roles as $role) {
        if (!isset($roles_bdd[$role])) {
          // $logger->debug('[getResourcePermissions] Role @role absent des resultats directs', ['@role' => $role]);
          continue;
        }

        $permissions['can_view']      |= (bool) $roles_bdd[$role]->can_view;
        $permissions['can_edit']      |= (bool) $roles_bdd[$role]->can_edit;
        $permissions['can_edit_role'] |= (bool) $roles_bdd[$role]->can_edit_role;
        $permissions['can_delete']    |= (bool) $roles_bdd[$role]->can_delete;

        // $logger->debug('[getResourcePermissions] Apres role=@role: permissions=@perms', ['@role'  => $role,'@perms' => json_encode($permissions),]);
      }

      if (in_array(TRUE, $permissions, TRUE)) {
        // $logger->debug('[getResourcePermissions] Au moins une permission directe active, retour immediat: @perms', ['@perms' => json_encode($permissions),]);
        return $permissions;
      }

      // $logger->debug('[getResourcePermissions] Lignes directes presentes mais toutes a FALSE, passage aux parents');
    }

    // ----------------------------
    // Permissions parents
    // ----------------------------
    // $logger->debug('[getResourcePermissions] Recherche permissions heritees via get_permission');

    $parent_permissions = self::get_permission($id_char_version, $user_roles, $connection);

    // $logger->debug('[getResourcePermissions] Resultats parents: @count lignes', ['@count' => count($parent_permissions),]);

    foreach ($parent_permissions as $perm) {
      $permissions['can_view']      |= (bool) $perm->can_view;
      $permissions['can_edit']      |= (bool) $perm->can_edit;
      $permissions['can_edit_role'] |= (bool) $perm->can_edit_role;
      $permissions['can_delete']    |= (bool) $perm->can_delete;

      // $logger->debug('[getResourcePermissions] Apres heritage tid=@tid: permissions=@perms', ['@tid'   => $perm->tid,'@perms' => json_encode($permissions),]);
    }

    // $logger->debug('[getResourcePermissions] Permissions finales pour uid=@uid, id=@id: @perms', ['@uid'   => $account->id(),'@id'    => $id_char_version,'@perms' => json_encode($permissions),]);

    return $permissions;
  }
}
