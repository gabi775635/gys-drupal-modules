<?php

namespace Drupal\gestion_documentaire\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

class SearchController extends ControllerBase
{

  /**
   * Point d'entrée principal : orchestre la recherche et retourne le JSON.
   */
  public function search(Request $request)
  {
    $params = $this->parseSearchParams($request);

    if (empty($params['id_char_version'])) {
      return new JsonResponse(['error' => 'id_char_version manquant'], 400);
    }

    $langcode   = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $lang_order = $this->langPriorityOrder($langcode);

    Database::setActiveConnection('drupal9');
    $connection = Database::getConnection();

    $account    = \Drupal::currentUser();
    $user_roles = $account->getRoles();
    $is_admin   = in_array('admin_gestiondocumentaire', $user_roles);

    // Résoudre le TID parent.
    $parent_taxonomy_id = $this->resolveTaxonomyId(
      $params['id_char_version'],
      $langcode,
      $connection
    );

    if ($parent_taxonomy_id instanceof JsonResponse) {
      return $parent_taxonomy_id;
    }

    // Construire l'arbre de taxonomies.
    $tree_rows = $this->fetchTaxonomyTree($parent_taxonomy_id, $connection);
    [$nodes, $ancestor_map, $descendant_map] = $this->buildNodesFromTree(
      $tree_rows,
      $langcode
    );

    $this->applyRightsToNodes(
      $nodes,
      $descendant_map,
      $user_roles,
      $is_admin,
      $connection
    );

    $direct_children_tids = $nodes[$parent_taxonomy_id]['children'] ?? [];
    $has_filters          = $params['has_filters'];
    $is_recursive         = $has_filters && !empty($direct_children_tids);

    $allowed = $this->computeAllowedTids(
      $parent_taxonomy_id,
      $descendant_map,
      $nodes,
      $has_filters
    );

    // Résultats dossiers (taxonomies enfants directs).
    [$taxonomy_results, $taxonomy_total] = $this->fetchTaxonomyResults(
      $has_filters,
      $direct_children_tids,
      $nodes,
      $params,
      $connection
    );

    // Liste des auteurs/éditeurs disponibles pour les filtres.
    [$all_authors_list, $all_revision_users_list] = $this->fetchAllUsers(
      $allowed['for_users'],
      $connection
    );

    // Résultats médias + méta (ext/lang).
    [$media_results, $total_media, $all_ext_list, $all_lang_list] = $this->fetchMediaResults(
      $allowed,
      $nodes,
      $is_admin,
      $params,
      $taxonomy_total,
      $lang_order,
      $connection
    );

    $total  = $taxonomy_total + $total_media;
    $merged = $this->mergeAndSort(
      $taxonomy_results,
      $media_results,
      $params['sort'],
      $params['sort_dir'],
      $lang_order
    );

    $paginated = array_slice($merged, 0, $params['per_page']);

    return new JsonResponse([
      $paginated,
      $total,
      $params['offset'],
      $params['per_page'],
      $is_recursive,
      $is_admin,
      $all_authors_list,
      $all_revision_users_list,
      $all_ext_list,
      $all_lang_list,
    ]);
  }

  // =========================================================================
  // Parsing des paramètres HTTP
  // =========================================================================

  /**
   * Extrait et valide tous les paramètres de la requête HTTP.
   *
   * @return array<string,mixed>
   */
  protected function parseSearchParams(Request $request): array
  {
    $page     = max(0, (int) $request->query->get('page', 0));
    $per_page = max(1, min(120, (int) $request->query->get('per_page', 10)));
    $offset   = $page * $per_page;

    $id_char_version    = $request->query->get('id_char_version');
    $search             = $request->query->get('search');
    $author_name        = $request->query->get('author_name');
    $revision_user_name = $request->query->get('revision_user_name');
    $created            = $request->query->get('created');
    $changed            = $request->query->get('changed');
    $ext_filter         = $request->query->get('ext');
    $lang_filter        = $request->query->get('lang');

    $sort = trim((string) $request->query->get('sort', ''));

    $sort_dir = strtoupper(trim((string) $request->query->get('sort_dir', '')));
    if (!in_array($sort_dir, ['ASC', 'DESC'], TRUE)) {
      $sort_dir = 'ASC';
    }

    // Champ inconnu → on ignore le tri.
    if ($sort !== '' && $sort !== 'lang' && !isset($this->sortableMediaColumns()[$sort])) {
      $sort = '';
    }

    $has_filters = !empty($search) || !empty($author_name) || !empty($revision_user_name)
      || !empty($created) || !empty($changed)
      || !empty($ext_filter) || !empty($lang_filter);

    return compact(
      'page', 'per_page', 'offset',
      'id_char_version', 'search', 'author_name', 'revision_user_name',
      'created', 'changed', 'ext_filter', 'lang_filter',
      'sort', 'sort_dir', 'has_filters'
    );
  }

  // =========================================================================
  // Résolution du TID parent
  // =========================================================================

  /**
   * Retourne l'ID numérique de la taxonomie parent, ou une JsonResponse d'erreur.
   *
   * @return int|JsonResponse
   */
  protected function resolveTaxonomyId(string $id_char_version, string $langcode, $connection)
  {
    $stripped = str_replace('taxonomy_', '', $id_char_version);

    if (ctype_digit($stripped)) {
      return (int) $stripped;
    }

    $resolved = $connection->query("
      SELECT tid
      FROM taxonomy_term_field_data
      WHERE name = :name AND vid = 'dossiers'
      ORDER BY (langcode = :langcode) DESC, tid ASC
      LIMIT 1
    ", [
      ':name'     => $stripped,
      ':langcode' => $langcode,
    ])->fetchField();

    if (!$resolved) {
      return new JsonResponse(
        ['error' => 'Taxonomie introuvable pour : ' . $stripped],
        404
      );
    }

    return (int) $resolved;
  }

  // =========================================================================
  // Construction de l'arbre de taxonomies
  // =========================================================================

  /**
   * Récupère l'arbre récursif (descendants + ancêtres) depuis la DB.
   *
   * @return array<object>
   */
  protected function fetchTaxonomyTree(int $parent_taxonomy_id, $connection): array
  {
    return $connection->query("
      WITH RECURSIVE
      descendants AS (
          SELECT ttfd.tid, ttfd.name, ttfd.name AS display_name, ttfd.langcode, ttfd.created, ttfd.changed,
                 COALESCE(ttp.parent_target_id, 0) AS parent_tid,
                 ttfur.field_user_reference_target_id AS author_uid,
                 0 AS depth
          FROM taxonomy_term_field_data ttfd
          LEFT JOIN taxonomy_term__parent ttp ON ttp.entity_id = ttfd.tid
          LEFT JOIN taxonomy_term__field_user_reference ttfur ON ttfur.entity_id = ttfd.tid
          WHERE ttfd.tid = :tid AND ttfd.vid = 'dossiers'
          UNION ALL
          SELECT ttfd.tid, ttfd.name, ttfd.name AS display_name, ttfd.langcode, ttfd.created, ttfd.changed,
                 COALESCE(ttp.parent_target_id, 0) AS parent_tid,
                 ttfur.field_user_reference_target_id AS author_uid,
                 d.depth + 1
          FROM taxonomy_term_field_data ttfd
          INNER JOIN taxonomy_term__parent ttp ON ttp.entity_id = ttfd.tid
          INNER JOIN descendants d ON d.tid = ttp.parent_target_id
          LEFT JOIN taxonomy_term__field_user_reference ttfur ON ttfur.entity_id = ttfd.tid
          WHERE ttfd.vid = 'dossiers'
      ),
      ancestors AS (
          SELECT ttfd.tid, ttfd.name, ttfd.name AS display_name, ttfd.langcode, ttfd.created, ttfd.changed,
                 COALESCE(ttp.parent_target_id, 0) AS parent_tid,
                 ttfur.field_user_reference_target_id AS author_uid,
                 -1 AS depth
          FROM taxonomy_term_field_data ttfd
          LEFT JOIN taxonomy_term__parent ttp ON ttp.entity_id = ttfd.tid
          LEFT JOIN taxonomy_term__field_user_reference ttfur ON ttfur.entity_id = ttfd.tid
          INNER JOIN taxonomy_term__parent child_link ON child_link.parent_target_id = ttfd.tid
          WHERE child_link.entity_id = :tid AND ttfd.vid = 'dossiers'
          UNION ALL
          SELECT ttfd.tid, ttfd.name, ttfd.name AS display_name, ttfd.langcode, ttfd.created, ttfd.changed,
                 COALESCE(ttp.parent_target_id, 0) AS parent_tid,
                 ttfur.field_user_reference_target_id AS author_uid,
                 a.depth - 1
          FROM taxonomy_term_field_data ttfd
          LEFT JOIN taxonomy_term__parent ttp ON ttp.entity_id = ttfd.tid
          LEFT JOIN taxonomy_term__field_user_reference ttfur ON ttfur.entity_id = ttfd.tid
          INNER JOIN taxonomy_term__parent child_link ON child_link.parent_target_id = ttfd.tid
          INNER JOIN ancestors a ON a.tid = child_link.entity_id
          WHERE ttfd.vid = 'dossiers'
      )
      SELECT tid, name, display_name, langcode, created, changed, parent_tid, author_uid, depth
      FROM descendants
      UNION
      SELECT tid, name, display_name, langcode, created, changed, parent_tid, author_uid, depth
      FROM ancestors
      ORDER BY tid ASC
    ", [':tid' => $parent_taxonomy_id])->fetchAll();
  }

  /**
   * Transforme les lignes DB en tableau structuré de nodes.
   *
   * @return array{array, array, array} [$nodes, $ancestor_map, $descendant_map]
   */
  protected function buildNodesFromTree(array $tree_rows, string $langcode): array
  {
    $nodes          = [];
    $tids_map       = [];
    $ancestor_map   = [];
    $descendant_map = [];

    foreach ($tree_rows as $row) {
      $tid   = (int) $row->tid;
      $depth = (int) $row->depth;

      $tids_map[$tid] = true;

      if ($depth < 0) {
        $ancestor_map[$tid] = true;
      } else {
        $descendant_map[$tid] = true;
      }

      if (!isset($nodes[$tid])) {
        $nodes[$tid] = [
          'tid'               => $tid,
          'name'              => $row->name,
          'display_name'      => $row->name,
          'langcode'          => $row->langcode,
          'created'           => (int) $row->created,
          'changed'           => (int) $row->changed,
          'parent_tid'        => (int) $row->parent_tid,
          'author_uid'        => $row->author_uid,
          'children'          => [],
          'can_view'          => 0,
          'can_edit'          => 0,
          'can_delete'        => 0,
          'can_create'        => 0,
          'can_edit_role'     => 0,
          'has_role'          => false,
          'visible_via_child' => false,
          'is_ancestor'       => $depth < 0,
        ];
      } else {
        if ($this->langScore($row->langcode, $langcode) < $this->langScore($nodes[$tid]['langcode'], $langcode)) {
          $nodes[$tid]['name']         = $row->name;
          $nodes[$tid]['display_name'] = $row->name;
          $nodes[$tid]['langcode']     = $row->langcode;
        }
        if ($depth >= 0) {
          $nodes[$tid]['is_ancestor'] = false;
        }
      }
    }

    foreach ($nodes as $tid => $node) {
      $parent = $node['parent_tid'];
      if ($parent && isset($nodes[$parent])) {
        $nodes[$parent]['children'][] = $tid;
      }
    }

    return [$nodes, $ancestor_map, $descendant_map];
  }

  // =========================================================================
  // Droits d'accès
  // =========================================================================

  /**
   * Applique les droits admin ou utilisateur à chaque node.
   */
  protected function applyRightsToNodes(
    array &$nodes,
    array $descendant_map,
    array $user_roles,
    bool $is_admin,
    $connection
  ): void {
    if ($is_admin) {
      $admin_rights = [
        'can_view' => 1, 'can_edit' => 1, 'can_delete' => 1,
        'can_create' => 1, 'can_edit_role' => 1, 'has_role' => true,
      ];
      foreach ($nodes as $tid => &$node) {
        $node = array_merge($node, $admin_rights);
      }
      unset($node);
      return;
    }

    $tids_list = array_keys($descendant_map);
    if (empty($tids_list)) {
      return;
    }

    $this->fetchAndApplyRoleRights($nodes, $tids_list, $user_roles, $connection);

    foreach ($nodes as $tid => $node) {
      if (!isset($nodes[$node['parent_tid']])) {
        $this->propagateDownward($nodes, $tid);
      }
    }

    foreach ($nodes as $node) {
      if ($node['can_view'] === 1) {
        $this->propagateVisibleViaChild($nodes, $node['parent_tid']);
      }
    }
  }

  /**
   * Charge les droits depuis la DB et les applique aux nodes.
   */
  protected function fetchAndApplyRoleRights(
    array &$nodes,
    array $tids_list,
    array $user_roles,
    $connection
  ): void {
    $role_placeholders = [];
    $role_bindings     = [];

    foreach ($user_roles as $i => $role) {
      $role_placeholders[]      = ":role$i";
      $role_bindings[":role$i"] = $role;
    }

    $tid_placeholders = [];
    foreach ($tids_list as $i => $tid) {
      $tid_key                 = ":tid$i";
      $tid_placeholders[]      = $tid_key;
      $role_bindings[$tid_key] = "taxonomy_$tid";
    }

    $rights_rows = $connection->query("
      SELECT gdr.id_char,
             MAX(gdr.can_view)      AS can_view,
             MAX(gdr.can_edit)      AS can_edit,
             MAX(gdr.can_delete)    AS can_delete,
             MAX(gdr.can_create)    AS can_create,
             MAX(gdr.can_edit_role) AS can_edit_role
      FROM gestion_documentaire_role gdr
      WHERE gdr.id_char IN (" . implode(',', $tid_placeholders) . ")
        AND gdr.role_libelle IN (" . implode(',', $role_placeholders) . ")
      GROUP BY gdr.id_char
    ", $role_bindings)->fetchAll();

    foreach ($rights_rows as $right) {
      $tid = (int) str_replace('taxonomy_', '', $right->id_char);
      if (isset($nodes[$tid])) {
        $nodes[$tid]['can_view']      = (int) $right->can_view;
        $nodes[$tid]['can_edit']      = (int) $right->can_edit;
        $nodes[$tid]['can_delete']    = (int) $right->can_delete;
        $nodes[$tid]['can_create']    = (int) $right->can_create;
        $nodes[$tid]['can_edit_role'] = (int) $right->can_edit_role;
        $nodes[$tid]['has_role']      = true;
      }
    }
  }

  // =========================================================================
  // Calcul des TIDs autorisés
  // =========================================================================

  /**
   * Calcule les ensembles de TIDs utilisés pour les différentes requêtes.
   *
   * @return array{for_media: int[], for_meta: int[], for_users: int[]}
   */
  protected function computeAllowedTids(
    int $parent_taxonomy_id,
    array $descendant_map,
    array $nodes,
    bool $has_filters
  ): array {
    $visible_descendants = [];
    foreach ($descendant_map as $tid => $_) {
      if (isset($nodes[$tid]) && ($nodes[$tid]['can_view'] === 1 || $nodes[$tid]['visible_via_child'])) {
        $visible_descendants[] = $tid;
      }
    }

    $for_media = $has_filters ? $visible_descendants : [$parent_taxonomy_id];

    $for_meta = $visible_descendants;
    if (!in_array($parent_taxonomy_id, $for_meta)) {
      $for_meta[] = $parent_taxonomy_id;
    }

    return [
      'for_media' => $for_media,
      'for_meta'  => $for_meta,
      'for_users' => $visible_descendants,
    ];
  }

  // =========================================================================
  // Résultats taxonomies (dossiers)
  // =========================================================================

  /**
   * Construit la liste paginée des dossiers enfants directs visibles.
   *
   * @return array{array, int} [$taxonomy_results, $taxonomy_total]
   */
  protected function fetchTaxonomyResults(
    bool $has_filters,
    array $direct_children_tids,
    array $nodes,
    array $params,
    $connection
  ): array {
    if ($has_filters || empty($direct_children_tids)) {
      return [[], 0];
    }

    $sort     = $params['sort'];
    $sort_dir = $params['sort_dir'];
    $offset   = $params['offset'];
    $per_page = $params['per_page'];

    $tax_sort = in_array($sort, ['name', 'display_name', 'created', 'changed'], TRUE) ? $sort : '';

    $sorted_children_all = $direct_children_tids;
    usort($sorted_children_all, function ($a, $b) use ($nodes, $sort, $tax_sort, $sort_dir) {
      if ($tax_sort === 'created' || $tax_sort === 'changed') {
        $cmp = ($nodes[$a][$tax_sort] ?? 0) <=> ($nodes[$b][$tax_sort] ?? 0);
      } else {
        $cmp = strcasecmp($nodes[$a]['name'] ?? '', $nodes[$b]['name'] ?? '');
      }
      if ($sort === '') {
        return $cmp;
      }
      return $sort_dir === 'DESC' ? -$cmp : $cmp;
    });

    $visible_children_all = [];
    foreach ($sorted_children_all as $child_tid) {
      if (!isset($nodes[$child_tid])) {
        continue;
      }
      $node = $nodes[$child_tid];
      if (!$node['can_view'] && !$node['visible_via_child']) {
        continue;
      }
      $visible_children_all[] = $child_tid;
    }

    $taxonomy_total  = count($visible_children_all);
    $sorted_children = array_slice($visible_children_all, $offset, $per_page);

    if (empty($sorted_children)) {
      return [[], $taxonomy_total];
    }

    [$author_names, $revision_names] = $this->fetchTaxonomyUserNames($sorted_children, $connection);
    [$max_changed_map, $media_revision_user_map] = $this->fetchTaxonomyMediaChangedMap(
      $sorted_children,
      $nodes,
      $connection
    );

    $taxonomy_results = [];
    foreach ($sorted_children as $child_tid) {
      if (!isset($nodes[$child_tid])) {
        continue;
      }
      $node = $nodes[$child_tid];
      if (!$node['can_view'] && !$node['visible_via_child']) {
        continue;
      }

      $is_traversal_only = $node['visible_via_child'] && !$node['can_view'];
      $media_changed     = $max_changed_map[$child_tid] ?? 0;
      $use_media_user    = $media_changed > $node['changed'];

      $taxonomy_results[] = (object) [
        'entity_id'          => $child_tid,
        'entity_type'        => 'taxonomy',
        'id_char_version'    => 'taxonomy_' . $child_tid,
        'name'               => $node['name'],
        'display_name'       => $node['name'],
        'created'            => date('Y-m-d H:i:s', $node['created']),
        'changed'            => date('Y-m-d H:i:s', max($node['changed'], $media_changed)),
        'type'               => $node['name'],
        'reference'          => '',
        'lang'               => '',
        'author'             => null,
        'revision_user'      => null,
        'author_name'        => $author_names[$child_tid] ?? '',
        'revision_user_name' => $use_media_user
          ? ($media_revision_user_map[$child_tid] ?? $revision_names[$child_tid] ?? '')
          : ($revision_names[$child_tid] ?? ''),
        'filesize'           => null,
        'can_view'           => 1,
        'can_edit'           => $is_traversal_only ? 0 : $node['can_edit'],
        'can_delete'         => $is_traversal_only ? 0 : $node['can_delete'],
        'can_create'         => $is_traversal_only ? 0 : $node['can_create'],
        'can_edit_role'      => $is_traversal_only ? 0 : $node['can_edit_role'],
        'url_view'     => Url::fromRoute('gestion_documentaire.dossier.view',   ['nom' => $node['name']])->toString(),
        'url_edit'     => Url::fromRoute('gestion_documentaire.dossier.update', ['nom' => $node['name']])->toString(),
        'url_delete'   => Url::fromRoute('gestion_documentaire.dossier.delete', ['nom' => $node['name']])->toString(),
        'url_category' => Url::fromRoute('gestion_documentaire.dossier.view',   ['nom' => $node['name']])->toString(),
      ];
    }

    return [$taxonomy_results, $taxonomy_total];
  }

  /**
   * Charge les noms d'auteur et de dernier éditeur pour un ensemble de TIDs.
   *
   * @return array{array, array} [$author_names, $revision_names]
   */
  protected function fetchTaxonomyUserNames(array $tids, $connection): array
  {
    $child_placeholders = implode(',', array_fill(0, count($tids), '?'));

    $user_rows = $connection->query("
      SELECT ttr.tid, ufda.name AS author_name, 'author' AS utype
      FROM taxonomy_term_revision ttr
      INNER JOIN (
          SELECT tid, MIN(revision_id) AS rev_id
          FROM taxonomy_term_revision
          WHERE tid IN ($child_placeholders)
          GROUP BY tid
      ) first_rev ON first_rev.tid = ttr.tid AND first_rev.rev_id = ttr.revision_id
      LEFT JOIN users_field_data ufda ON ufda.uid = ttr.revision_user

      UNION ALL

      SELECT ttr.tid, ufdr.name AS author_name, 'revision' AS utype
      FROM taxonomy_term_revision ttr
      INNER JOIN (
          SELECT tid, MAX(revision_id) AS rev_id
          FROM taxonomy_term_revision
          WHERE tid IN ($child_placeholders)
          GROUP BY tid
      ) last_rev ON last_rev.tid = ttr.tid AND last_rev.rev_id = ttr.revision_id
      LEFT JOIN users_field_data ufdr ON ufdr.uid = ttr.revision_user
    ", array_merge($tids, $tids))->fetchAll();

    $author_names   = [];
    $revision_names = [];
    foreach ($user_rows as $u) {
      if ($u->utype === 'author') {
        $author_names[$u->tid] = $u->author_name;
      } else {
        $revision_names[$u->tid] = $u->author_name;
      }
    }

    return [$author_names, $revision_names];
  }

  /**
   * Calcule la date de dernier changement d'un média pour chaque dossier enfant.
   *
   * @return array{array, array} [$max_changed_map, $media_revision_user_map]
   */
  protected function fetchTaxonomyMediaChangedMap(
    array $sorted_children,
    array $nodes,
    $connection
  ): array {
    // Construire la map enfant_direct → tous ses descendants (récursif).
    $child_descendant_map = [];
    foreach ($sorted_children as $child_tid) {
      $child_descendant_map[$child_tid] = [];
      $stack = [$child_tid];
      while (!empty($stack)) {
        $current = array_pop($stack);
        $child_descendant_map[$child_tid][] = $current;
        foreach ($nodes[$current]['children'] ?? [] as $sub) {
          $stack[] = $sub;
        }
      }
    }

    $all_descendant_tids = array_values(
      array_unique(array_merge(...array_values($child_descendant_map)))
    );

    $max_changed_map         = [];
    $media_revision_user_map = [];

    if (empty($all_descendant_tids)) {
      return [$max_changed_map, $media_revision_user_map];
    }

    $ph_desc = implode(',', array_fill(0, count($all_descendant_tids), '?'));

    $media_changed_rows = $connection->query("
      SELECT
        link.field_taxonomie_associee_target_id AS tid,
        m.changed AS media_changed,
        (
          SELECT ufd.name
          FROM media_revision mr2
          INNER JOIN (
              SELECT mid, MAX(vid) AS max_vid FROM media_revision GROUP BY mid
          ) lat ON lat.mid = mr2.mid AND lat.max_vid = mr2.vid
          LEFT JOIN users_field_data ufd ON ufd.uid = mr2.revision_user
          WHERE mr2.mid = m.mid
          LIMIT 1
        ) AS revision_user_name
      FROM media_field_data m
      INNER JOIN media__field_taxonomie_associee link ON m.mid = link.entity_id
      WHERE link.field_taxonomie_associee_target_id IN ($ph_desc)
      ORDER BY m.changed DESC
    ", $all_descendant_tids)->fetchAll();

    // Indexer par tid : le premier = le plus récent (ORDER BY DESC).
    $media_by_tid = [];
    foreach ($media_changed_rows as $row) {
      $tid = (int) $row->tid;
      if (!isset($media_by_tid[$tid])) {
        $media_by_tid[$tid] = $row;
      }
    }

    // Agréger par enfant direct.
    foreach ($child_descendant_map as $child_tid => $desc_tids) {
      $best_changed = 0;
      $best_user    = null;
      foreach ($desc_tids as $dtid) {
        if (isset($media_by_tid[$dtid]) && (int) $media_by_tid[$dtid]->media_changed > $best_changed) {
          $best_changed = (int) $media_by_tid[$dtid]->media_changed;
          $best_user    = $media_by_tid[$dtid]->revision_user_name;
        }
      }
      if ($best_changed > 0) {
        $max_changed_map[$child_tid]         = $best_changed;
        $media_revision_user_map[$child_tid] = $best_user;
      }
    }

    return [$max_changed_map, $media_revision_user_map];
  }

  // =========================================================================
  // Auteurs disponibles pour les filtres
  // =========================================================================

  /**
   * Retourne la liste triée des auteurs et éditeurs disponibles.
   *
   * @return array{string[], string[]} [$all_authors_list, $all_revision_users_list]
   */
  protected function fetchAllUsers(array $allowed_tids_for_users, $connection): array
  {
    if (empty($allowed_tids_for_users)) {
      return [[], []];
    }

    $placeholders_u = implode(',', array_fill(0, count($allowed_tids_for_users), '?'));

    $all_users = $connection->query("
      SELECT DISTINCT ufda.name AS user_name, 'author' AS user_type
      FROM taxonomy_term_revision ttr
      INNER JOIN (
          SELECT tid, MIN(revision_id) AS first_revision_id
          FROM taxonomy_term_revision WHERE tid IN ($placeholders_u) GROUP BY tid
      ) fr ON fr.tid = ttr.tid AND fr.first_revision_id = ttr.revision_id
      LEFT JOIN users_field_data ufda ON ufda.uid = ttr.revision_user
      WHERE ufda.name IS NOT NULL AND ufda.name != '' AND ufda.uid != 0

      UNION

      SELECT DISTINCT ufdr.name, 'revision'
      FROM taxonomy_term_revision ttr
      INNER JOIN (
          SELECT tid, MAX(revision_id) AS last_revision_id
          FROM taxonomy_term_revision WHERE tid IN ($placeholders_u) GROUP BY tid
      ) lr ON lr.tid = ttr.tid AND lr.last_revision_id = ttr.revision_id
      LEFT JOIN users_field_data ufdr ON ufdr.uid = ttr.revision_user
      WHERE ufdr.name IS NOT NULL AND ufdr.name != '' AND ufdr.uid != 0

      UNION

      SELECT DISTINCT ufda.name, 'author'
      FROM media_field_data m
      INNER JOIN media__field_taxonomie_associee link ON m.mid = link.entity_id
      LEFT JOIN users_field_data ufda ON ufda.uid = m.uid
      WHERE link.field_taxonomie_associee_target_id IN ($placeholders_u)
        AND ufda.name IS NOT NULL AND ufda.name != '' AND ufda.uid != 0

      UNION

      SELECT DISTINCT ufdr.name, 'revision'
      FROM media_field_data m
      INNER JOIN media_revision mr ON m.mid = mr.mid
      INNER JOIN media__field_taxonomie_associee link ON m.mid = link.entity_id
      LEFT JOIN users_field_data ufdr ON ufdr.uid = mr.revision_user
      WHERE link.field_taxonomie_associee_target_id IN ($placeholders_u)
        AND ufdr.name IS NOT NULL AND ufdr.name != '' AND ufdr.uid != 0
    ", array_merge(
      $allowed_tids_for_users, $allowed_tids_for_users,
      $allowed_tids_for_users, $allowed_tids_for_users
    ))->fetchAll();

    $all_authors        = [];
    $all_revision_users = [];
    foreach ($all_users as $user) {
      if ($user->user_type === 'author') {
        $all_authors[] = $user->user_name;
      } else {
        $all_revision_users[] = $user->user_name;
      }
    }

    $all_authors_list        = array_values(array_unique($all_authors));
    $all_revision_users_list = array_values(array_unique($all_revision_users));
    sort($all_authors_list);
    sort($all_revision_users_list);

    return [$all_authors_list, $all_revision_users_list];
  }

  // =========================================================================
  // Résultats médias
  // =========================================================================

  /**
   * Construit la clause WHERE et les bindings pour la requête médias.
   *
   * @return array{string[], array} [$where_conditions, $bindings]
   */
  protected function buildMediaWhereClause(array $params, array $base_bindings): array
  {
    $where    = [];
    $bindings = $base_bindings;

    if (!empty($params['search'])) {
      $where[]    = "(m.name LIKE ? OR mfn.field_media_filename_value LIKE ? OR mrr.field_media_reference_value LIKE ?)";
      $bindings[] = "%" . $params['search'] . "%";
      $bindings[] = "%" . $params['search'] . "%";
      $bindings[] = "%" . $params['search'] . "%";
    }

    if (!empty($params['author_name'])) {
      $where[]    = "ufda.name LIKE ?";
      $bindings[] = "%" . $params['author_name'] . "%";
    }

    if (!empty($params['created'])) {
      $where[]    = "DATE(FROM_UNIXTIME(m.created)) = ?";
      $bindings[] = $params['created'];
    }

    if (!empty($params['changed'])) {
      $where[]    = "DATE(FROM_UNIXTIME(m.changed)) = ?";
      $bindings[] = $params['changed'];
    }

    if (!empty($params['ext_filter'])) {
      $exts = array_filter(array_map('trim', explode(',', $params['ext_filter'])));
      if (!empty($exts)) {
        $ext_conds = [];
        foreach ($exts as $ext) {
          $ext_conds[] = "LOWER(m.name) LIKE ?";
          $bindings[]  = "%." . strtolower($ext);
        }
        $where[] = '(' . implode(' OR ', $ext_conds) . ')';
      }
    }

    if (!empty($params['lang_filter'])) {
      $langs = array_filter(array_map('trim', explode(',', $params['lang_filter'])));
      if (!empty($langs)) {
        $lang_conds = [];
        foreach ($langs as $lang) {
          $lang_conds[] = "ml.field_media_lang_value LIKE ?";
          $bindings[]   = "%" . strtolower($lang) . "%";
        }
        $where[] = '(' . implode(' OR ', $lang_conds) . ')';
      }
    }

    return [$where, $bindings];
  }

  /**
   * Exécute les requêtes médias (count, méta, résultats paginés).
   *
   * @return array{array, int, string[], string[]}
   *   [$media_results, $total_media, $all_ext_list, $all_lang_list]
   */
  protected function fetchMediaResults(
    array $allowed,
    array $nodes,
    bool $is_admin,
    array $params,
    int $taxonomy_total,
    array $lang_order,
    $connection
  ): array {
    $media_results = [];
    $total_media   = 0;
    $all_ext_list  = [];
    $all_lang_list = [];

    if (empty($allowed['for_media'])) {
      return [$media_results, $total_media, $all_ext_list, $all_lang_list];
    }

    $placeholders   = implode(',', array_fill(0, count($allowed['for_media']), '?'));
    $media_bindings = $allowed['for_media'];

    [$where, $media_bindings] = $this->buildMediaWhereClause($params, $media_bindings);

    $where_sql = !empty($where) ? 'AND ' . implode(' AND ', $where) : '';

    $revision_having = '';
    if (!empty($params['revision_user_name'])) {
      $revision_having  = "AND m.mid IN (
        SELECT mr_last.mid
        FROM media_revision mr_last
        INNER JOIN (
          SELECT mid, MAX(vid) AS max_vid FROM media_revision GROUP BY mid
        ) latest_rev ON latest_rev.mid = mr_last.mid AND latest_rev.max_vid = mr_last.vid
        INNER JOIN users_field_data ufd2 ON ufd2.uid = mr_last.revision_user
        WHERE ufd2.name LIKE ?
      )";
      $media_bindings[] = "%" . $params['revision_user_name'] . "%";
    }

    $total_media = (int) $connection->query("
      SELECT COUNT(DISTINCT m.mid)
      FROM media_field_data m
      INNER JOIN (
          SELECT mid, MAX(vid) AS max_vid FROM media_revision GROUP BY mid
      ) latest ON m.mid = latest.mid
      INNER JOIN media_revision mr ON m.mid = mr.mid AND mr.vid = latest.max_vid
      LEFT JOIN media__field_media_reference mrr ON mr.vid = mrr.revision_id
      LEFT JOIN media__field_media_lang ml ON mr.vid = ml.revision_id
      LEFT JOIN media__field_taxonomie_associee link ON m.mid = link.entity_id
      LEFT JOIN media__field_media_filename mfn ON m.mid = mfn.entity_id
      LEFT JOIN users_field_data ufda ON ufda.uid = m.uid
      WHERE link.field_taxonomie_associee_target_id IN ($placeholders)
      $where_sql
      $revision_having
    ", $media_bindings)->fetchField();

    // Méta (ext + lang) — scanné sur tous les descendants visibles.
    [$all_ext_list, $all_lang_list] = $this->fetchMediaMeta(
      $allowed['for_meta'],
      $connection
    );

    // Trier la liste des langues selon la priorité de la langue courante.
    usort($all_lang_list, function ($a, $b) use ($lang_order) {
      return $this->compareLangValues($a, $b, $lang_order, FALSE);
    });

    $media_offset    = max(0, $params['offset'] - $taxonomy_total);
    $sql_limit       = (int) $params['per_page'];
    $sql_offset      = (int) $media_offset;
    $media_order_sql = $this->buildMediaOrderBy($params['sort'], $params['sort_dir'], $lang_order);

    $media_rows = $connection->query("
      SELECT
        m.mid AS entity_id,
        'media' AS entity_type,
        CONCAT('media_', m.mid, '_', COALESCE(mrv.field_media_version_value, 1)) AS id_char_version,
        m.name,
        COALESCE(mfn.field_media_filename_value, m.name) AS display_name,
        FROM_UNIXTIME(m.created) AS created,
        FROM_UNIXTIME(m.changed) AS changed,
        mrt.field_media_type_value AS type,
        mrr.field_media_reference_value AS reference,
        ml.field_media_lang_value AS lang,
        ufda.name AS author_name,
        fm.filesize AS filesize,
        LOWER(SUBSTRING_INDEX(fm.filename, '.', -1)) AS file_ext,
        (
          SELECT ufd3.name FROM media_revision mr3
          INNER JOIN users_field_data ufd3 ON ufd3.uid = mr3.revision_user
          WHERE mr3.mid = m.mid ORDER BY mr3.vid DESC LIMIT 1
        ) AS revision_user_name,
        link.field_taxonomie_associee_target_id AS taxonomy_tid,
        coalesce(mrv.field_media_version_value,1) as version,
        m.status as publish
      FROM media_field_data m
      INNER JOIN (
          SELECT mid, MAX(vid) AS max_vid FROM media_revision GROUP BY mid
      ) latest ON m.mid = latest.mid
      INNER JOIN media_revision mr ON m.mid = mr.mid AND mr.vid = latest.max_vid
      LEFT JOIN media__field_media_reference mrr ON mr.vid = mrr.revision_id
      LEFT JOIN media__field_media_type mrt ON mr.vid = mrt.revision_id
      LEFT JOIN media__field_media_lang ml ON mr.vid = ml.revision_id
      LEFT JOIN media__field_media_version mrv ON mr.vid = mrv.revision_id
      LEFT JOIN media__field_taxonomie_associee link ON m.mid = link.entity_id
      LEFT JOIN media__field_media_filename mfn ON m.mid = mfn.entity_id
      LEFT JOIN users_field_data ufda ON ufda.uid = m.uid
      LEFT JOIN media__field_media_document mfd ON m.mid = mfd.entity_id
      LEFT JOIN file_managed fm ON fm.fid = mfd.field_media_document_target_id
      WHERE link.field_taxonomie_associee_target_id IN ($placeholders)
      $where_sql
      $revision_having
      $media_order_sql
      LIMIT $sql_limit OFFSET $sql_offset
    ", $media_bindings)->fetchAll();

    $media_results = $this->buildMediaResultRows($media_rows, $nodes, $is_admin);

    return [$media_results, $total_media, $all_ext_list, $all_lang_list];
  }

  /**
   * Récupère les extensions et langues disponibles pour les filtres.
   *
   * @return array{string[], string[]} [$all_ext_list, $all_lang_list]
   */
  protected function fetchMediaMeta(array $allowed_tids_for_meta, $connection): array
  {
    if (empty($allowed_tids_for_meta)) {
      return [[], []];
    }

    $placeholders_meta = implode(',', array_fill(0, count($allowed_tids_for_meta), '?'));

    $meta_rows = $connection->query("
      SELECT DISTINCT
        LOWER(SUBSTRING_INDEX(m.name, '.', -1)) AS ext,
        ml.field_media_lang_value AS lang
      FROM media_field_data m
      INNER JOIN media__field_taxonomie_associee link ON m.mid = link.entity_id
      LEFT JOIN media__field_media_lang ml ON m.mid = ml.entity_id
      WHERE link.field_taxonomie_associee_target_id IN ($placeholders_meta)
        AND m.name LIKE '%.%'
    ", $allowed_tids_for_meta)->fetchAll();

    $all_ext_list  = [];
    $all_lang_list = [];
    $ext_seen      = [];
    $lang_seen     = [];

    foreach ($meta_rows as $meta) {
      $ext = $meta->ext ?? '';
      if ($ext && strlen($ext) <= 5 && !isset($ext_seen[$ext])) {
        $ext_seen[$ext]  = true;
        $all_ext_list[]  = $ext;
      }
      if (!empty($meta->lang)) {
        foreach (preg_split('/[,\s]+/', $meta->lang) as $l) {
          $l = trim(strtolower($l));
          if ($l && !isset($lang_seen[$l])) {
            $lang_seen[$l]   = true;
            $all_lang_list[] = $l;
          }
        }
      }
    }

    sort($all_ext_list);

    return [$all_ext_list, $all_lang_list];
  }

  /**
   * Construit les objets résultat médias depuis les lignes DB.
   *
   * @return array<object>
   */
  protected function buildMediaResultRows(array $media_rows, array $nodes, bool $is_admin): array
  {
    $results = [];

    foreach ($media_rows as $row) {
      $media_tid  = (int) $row->taxonomy_tid;
      $media_node = $nodes[$media_tid] ?? null;

      // Remplacer le type brut (FR figé) par le nom traduit de la taxonomy.
      if ($media_node) {
        $row->type = $media_node['name'];
      }

      $row->can_view      = $is_admin ? 1 : ($media_node ? $media_node['can_view']      : 0);
      $row->can_edit      = $is_admin ? 1 : ($media_node ? $media_node['can_edit']      : 0);
      $row->can_delete    = $is_admin ? 1 : ($media_node ? $media_node['can_delete']    : 0);
      $row->can_create    = $is_admin ? 1 : ($media_node ? $media_node['can_create']    : 0);
      $row->can_edit_role = $is_admin ? 1 : ($media_node ? $media_node['can_edit_role'] : 0);

      if ($row->can_view == 0) {
        continue;
      }

      $row->url_view = Url::fromRoute('gestion_documentaire.redirectRenderRessourcev2', [
        'type' => $row->type ?? '', 'name' => "media_" . $row->entity_id,
        'lang' => $row->lang ?? '', 'action' => '',
      ])->toString();
      $row->url_download = Url::fromRoute('gestion_documentaire.redirectRenderRessourcev2', [
        'type' => $row->type ?? '', 'name' => "media_" . $row->entity_id,
        'lang' => $row->lang ?? '', 'action' => 'download',
      ])->toString();
      $row->url_edit      = Url::fromRoute('gestion_documentaire.document.update',    ['nom' => $row->name])->toString();
      $row->url_delete    = Url::fromRoute('gestion_documentaire.document.delete',    ['nom' => $row->name])->toString();
      $row->url_duplicate = Url::fromRoute('gestion_documentaire.document.duplicate', ['nom' => $row->name])->toString();
      $row->url_category  = Url::fromRoute('gestion_documentaire.dossier.view',       ['nom' => $row->type])->toString();

      unset($row->rn, $row->taxonomy_tid);
      $row->file_ext = $row->file_ext ?? '';
      $results[]     = $row;
    }

    return $results;
  }

  // =========================================================================
  // Tri final
  // =========================================================================

  /**
   * Fusionne les résultats taxonomy + médias et les trie.
   *
   * @return array<object>
   */
  protected function mergeAndSort(
    array $taxonomy_results,
    array $media_results,
    string $sort,
    string $sort_dir,
    array $lang_order
  ): array {
    $merged = array_merge($taxonomy_results, $media_results);

    usort($merged, function ($a, $b) use ($sort, $sort_dir, $lang_order) {
      // Les dossiers restent toujours avant les documents.
      if ($a->entity_type !== $b->entity_type) {
        return $a->entity_type === 'taxonomy' ? -1 : 1;
      }

      // Aucun tri demandé → comportement historique.
      if ($sort === '') {
        if ($a->entity_type === 'taxonomy') {
          return strcasecmp($a->name, $b->name);
        }
        return strtotime($b->changed) - strtotime($a->changed);
      }

      return $this->compareRowsBySort($a, $b, $sort, $sort_dir, $lang_order);
    });

    return $merged;
  }

  // =========================================================================
  // Helpers de score de langue
  // =========================================================================

  protected function langScore($lang, $current_langcode)
  {
    if ($lang === $current_langcode) return 1;
    if ($lang === 'fr')              return 2;
    return 3;
  }

  /**
   * Colonnes triables sur la requête médias.
   *
   * La clé est ce que reçoit le paramètre `sort`, la valeur est
   * l'expression SQL. Cette whitelist est la SEULE source des fragments
   * interpolés dans l'ORDER BY : aucune valeur venant de l'URL n'y arrive.
   *
   * `lang` n'y figure pas : il est traité à part (ordre métier).
   */
  protected function sortableMediaColumns(): array
  {
    return [
      'name'               => 'm.name',
      'display_name'       => 'display_name',
      'created'            => 'm.created',
      'changed'            => 'm.changed',
      'type'               => 'mrt.field_media_type_value',
      'reference'          => 'mrr.field_media_reference_value',
      'author_name'        => 'ufda.name',
      'revision_user_name' => 'revision_user_name',
      'filesize'           => 'fm.filesize',
      'file_ext'           => 'file_ext',
      'version'            => 'version',
      'publish'            => 'm.status',
    ];
  }

  /**
   * Ordre métier des langues.
   *
   * Base : fr, uk, de, it, es, ru, zh — puis tout le reste par ordre
   * alphabétique.
   *
   * Si le site est en anglais, uk passe devant fr, le reste ne bouge pas.
   */
  protected function langPriorityOrder(string $langcode): array
  {
    if (in_array(strtolower($langcode), ['en', 'uk', 'us'], TRUE)) {
      return ['uk', 'fr', 'de', 'it', 'es', 'ru', 'zh'];
    }

    return ['fr', 'uk', 'de', 'it', 'es', 'ru', 'zh'];
  }

  /**
   * Éclate une valeur de langue en tokens normalisés.
   *
   * "de, UK,fr" → ['de', 'uk', 'fr']
   */
  protected function langTokens(?string $value): array
  {
    $value = strtolower(trim((string) $value));
    if ($value === '') {
      return [];
    }

    $tokens = preg_split('/[,\s]+/', $value, -1, PREG_SPLIT_NO_EMPTY);

    return array_values(array_unique(array_map('trim', $tokens)));
  }

  /**
   * Groupe global d'une valeur de langue.
   *
   *   0 → contient au moins une langue de l'ordre métier
   *   1 → langue(s) renseignée(s) mais toutes hors liste
   *   2 → aucune langue
   *
   * Toujours croissant, quel que soit le sens du tri : inverser l'ordre
   * ne doit pas faire remonter les documents sans langue.
   */
  protected function langGroup(?string $value, array $order): int
  {
    $tokens = $this->langTokens($value);

    if (empty($tokens)) {
      return 2;
    }

    foreach ($tokens as $token) {
      if (in_array($token, $order, TRUE)) {
        return 0;
      }
    }

    return 1;
  }

  /**
   * Rang d'une valeur de langue dans l'ordre métier.
   *
   * Sur une valeur multilingue, on classe sur la MEILLEURE langue présente :
   * "uk,es,de,it" se range avec les uk, pas avec les es.
   *
   * Retourne 0..n-1 pour une langue connue, n sinon.
   */
  protected function langRank(?string $value, array $order): int
  {
    $tokens = $this->langTokens($value);
    if (empty($tokens)) {
      return count($order);
    }

    $best = count($order);

    foreach ($tokens as $token) {
      $pos = array_search($token, $order, TRUE);
      if ($pos !== FALSE && $pos < $best) {
        $best = $pos;
      }
    }

    return $best;
  }

  /**
   * Mono-langue (0) ou multi-langue (1).
   *
   * Sert de départage À L'INTÉRIEUR d'une même langue : une FDS
   * uniquement française passe devant un document "fr,de,uk", mais les
   * deux restent devant tout ce qui est uk.
   */
  protected function langIsMulti(?string $value): int
  {
    return count($this->langTokens($value)) > 1 ? 1 : 0;
  }

  /**
   * Compare deux valeurs de langue.
   *
   * Ordre appliqué :
   *   1. groupe   — connu, puis hors liste, puis vide (toujours croissant)
   *   2. langue   — ordre métier, sur la meilleure langue de la chaîne
   *   3. mono/multi — mono avant multi (toujours croissant)
   *   4. alphabétique sur la valeur brute
   *
   * Donne : fr, fr+autres, uk, uk+autres, de, de+autres, … puis hors
   * liste, puis sans langue.
   *
   * $desc n'inverse que les étapes 2 et 4 : les regroupements restent
   * stables dans les deux sens.
   */
  protected function compareLangValues(?string $a, ?string $b, array $order, bool $desc = FALSE): int
  {
    $ga = $this->langGroup($a, $order);
    $gb = $this->langGroup($b, $order);
    if ($ga !== $gb) {
      return $ga <=> $gb;
    }

    $ra = $this->langRank($a, $order);
    $rb = $this->langRank($b, $order);
    if ($ra !== $rb) {
      $cmp = $ra <=> $rb;
      return $desc ? -$cmp : $cmp;
    }

    $ma = $this->langIsMulti($a);
    $mb = $this->langIsMulti($b);
    if ($ma !== $mb) {
      return $ma <=> $mb;
    }

    $cmp = strcasecmp((string) $a, (string) $b);
    return $desc ? -$cmp : $cmp;
  }

  /**
   * Construit l'ORDER BY de la requête médias.
   *
   * Branche `lang` — pendant SQL de compareLangValues() :
   *   1. groupe     : langue connue / hors liste / vide  (toujours ASC)
   *   2. rang       : ordre métier sur la meilleure langue de la chaîne
   *   3. mono/multi : mono avant multi                    (toujours ASC)
   *   4. alphabétique
   *   5. départage  : changed DESC puis mid, pour un ordre stable entre
   *                   deux requêtes — sans quoi LIMIT/OFFSET peut
   *                   dupliquer ou sauter des lignes d'une page à l'autre.
   */
  protected function buildMediaOrderBy(string $sort, string $sort_dir, array $lang_order): string
  {
    // Sens verrouillé : seule source du fragment interpolé.
    $dir = ($sort_dir === 'DESC') ? 'DESC' : 'ASC';

    if ($sort === '') {
      return 'ORDER BY m.changed DESC, m.mid ASC';
    }

    if ($sort === 'lang') {
      $col = 'ml.field_media_lang_value';

      // Chaîne normalisée : minuscules, sans espaces, sans virgules en bord.
      $norm    = "TRIM(BOTH ',' FROM REPLACE(LOWER(COALESCE($col, '')), ' ', ''))";
      $wrapped = "CONCAT(',', $norm, ',')";

      // Nombre de langues = nombre de virgules + 1.
      $nb = "(CHAR_LENGTH($norm) - CHAR_LENGTH(REPLACE($norm, ',', '')) + 1)";

      $n     = count($lang_order);
      $cases = [];
      $known = [];
      foreach ($lang_order as $i => $lang) {
        $test    = "LOCATE('," . $lang . ",', $wrapped) > 0";
        $cases[] = "WHEN $test THEN $i";
        $known[] = $test;
      }

      $rank = "CASE\n"
            . '    ' . implode("\n    ", $cases) . "\n"
            . "    ELSE $n\n"
            . '  END';

      $group = "CASE\n"
             . "    WHEN $norm = '' THEN 2\n"
             . '    WHEN (' . implode("\n         OR ", $known) . ") THEN 0\n"
             . "    ELSE 1\n"
             . '  END';

      $multi = "CASE WHEN $nb > 1 THEN 1 ELSE 0 END";

      return "ORDER BY\n"
           . "  ($group) ASC,\n"
           . "  ($rank) $dir,\n"
           . "  ($multi) ASC,\n"
           . "  $norm $dir,\n"
           . "  m.changed DESC,\n"
           . "  m.mid ASC";
    }

    $columns = $this->sortableMediaColumns();
    if (!isset($columns[$sort])) {
      return 'ORDER BY m.changed DESC, m.mid ASC';
    }

    return 'ORDER BY ' . $columns[$sort] . ' ' . $dir . ', m.mid ASC';
  }

  /**
   * Compare deux lignes du résultat fusionné selon le champ de tri.
   *
   * Le sens est appliqué ICI et non par l'appelant : sur `lang`, seuls
   * l'ordre métier et l'alphabétique s'inversent, pas les regroupements.
   */
  protected function compareRowsBySort($a, $b, string $sort, string $sort_dir, array $lang_order): int
  {
    $desc = ($sort_dir === 'DESC');

    if ($sort === 'lang') {
      return $this->compareLangValues($a->lang ?? '', $b->lang ?? '', $lang_order, $desc);
    }

    $va = $a->{$sort} ?? null;
    $vb = $b->{$sort} ?? null;

    if (in_array($sort, ['created', 'changed'], TRUE)) {
      $cmp = strtotime((string) $va) <=> strtotime((string) $vb);
    } elseif (in_array($sort, ['filesize', 'version', 'publish'], TRUE)) {
      $cmp = (int) $va <=> (int) $vb;
    } else {
      $cmp = strcasecmp((string) $va, (string) $vb);
    }

    return $desc ? -$cmp : $cmp;
  }

  // =========================================================================
  // Propagation des droits dans l'arbre
  // =========================================================================

  protected function propagateDownward(&$nodes, $root_tid)
  {
    $stack = [[$root_tid, []]];

    while (!empty($stack)) {
      [$tid, $inherited] = array_pop($stack);

      if (!isset($nodes[$tid])) {
        continue;
      }

      $node = &$nodes[$tid];

      if (!$node['has_role'] && !empty($inherited)) {
        $node['can_view']      = $inherited['can_view'];
        $node['can_edit']      = $inherited['can_edit'];
        $node['can_delete']    = $inherited['can_delete'];
        $node['can_create']    = $inherited['can_create'];
        $node['can_edit_role'] = $inherited['can_edit_role'];
      }

      $to_transmit = [
        'can_view'      => $node['can_view'],
        'can_edit'      => $node['can_edit'],
        'can_delete'    => $node['can_delete'],
        'can_create'    => $node['can_create'],
        'can_edit_role' => $node['can_edit_role'],
      ];

      foreach ($node['children'] as $child_tid) {
        $stack[] = [$child_tid, $to_transmit];
      }
    }
  }

  protected function propagateVisibleViaChild(&$nodes, $parent_tid)
  {
    while ($parent_tid && isset($nodes[$parent_tid])) {
      if ($nodes[$parent_tid]['visible_via_child']) {
        break;
      }
      if ($nodes[$parent_tid]['can_view'] === 1) {
        break;
      }

      $nodes[$parent_tid]['visible_via_child'] = true;
      $parent_tid = $nodes[$parent_tid]['parent_tid'];
    }
  }
}
