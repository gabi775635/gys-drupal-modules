<?php

declare(strict_types=1);

namespace Drupal\Tests\gestion_documentaire\Unit\Controller;

use Drupal\gestion_documentaire\Controller\SearchController;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Sous-classe qui expose les méthodes protected pour les tests.
 *
 * On n'expose QUE ce qu'on teste : les méthodes pures qui ne font pas appel
 * à \Drupal::* ou à Url::fromRoute (celles-ci restent couvertes via des tests
 * d'intégration Kernel séparés).
 */
class TestableSearchController extends SearchController
{
  // ── Exposition des protected ──────────────────────────────────────────────

  public function callParseSearchParams(Request $r): array
  {
    return $this->parseSearchParams($r);
  }

  public function callBuildNodesFromTree(array $rows, string $lang): array
  {
    return $this->buildNodesFromTree($rows, $lang);
  }

  public function callComputeAllowedTids(int $pid, array $desc, array $nodes, bool $filters): array
  {
    return $this->computeAllowedTids($pid, $desc, $nodes, $filters);
  }

  public function callBuildMediaWhereClause(array $params, array $base): array
  {
    return $this->buildMediaWhereClause($params, $base);
  }

  public function callBuildMediaOrderBy(string $sort, string $dir, array $order): string
  {
    return $this->buildMediaOrderBy($sort, $dir, $order);
  }

  public function callMergeAndSort(array $tax, array $media, string $sort, string $dir, array $order): array
  {
    return $this->mergeAndSort($tax, $media, $sort, $dir, $order);
  }

  public function callCompareRowsBySort($a, $b, string $sort, string $dir, array $order): int
  {
    return $this->compareRowsBySort($a, $b, $sort, $dir, $order);
  }

  public function callPropagateDownward(array &$nodes, int $root): void
  {
    $this->propagateDownward($nodes, $root);
  }

  public function callPropagateVisibleViaChild(array &$nodes, int $parent): void
  {
    $this->propagateVisibleViaChild($nodes, $parent);
  }

  public function callLangScore(?string $lang, string $current): int
  {
    return $this->langScore($lang, $current);
  }

  public function callLangTokens(?string $v): array
  {
    return $this->langTokens($v);
  }

  public function callLangGroup(?string $v, array $order): int
  {
    return $this->langGroup($v, $order);
  }

  public function callLangRank(?string $v, array $order): int
  {
    return $this->langRank($v, $order);
  }

  public function callLangIsMulti(?string $v): int
  {
    return $this->langIsMulti($v);
  }

  public function callCompareLangValues(?string $a, ?string $b, array $order, bool $desc = false): int
  {
    return $this->compareLangValues($a, $b, $order, $desc);
  }

  public function callLangPriorityOrder(string $lang): array
  {
    return $this->langPriorityOrder($lang);
  }

  public function callResolveTaxonomyId(string $id, string $lang, $conn): mixed
  {
    return $this->resolveTaxonomyId($id, $lang, $conn);
  }

  public function callFetchAndApplyRoleRights(array &$nodes, array $tids, array $roles, $conn): void
  {
    $this->fetchAndApplyRoleRights($nodes, $tids, $roles, $conn);
  }
}

// ─────────────────────────────────────────────────────────────────────────────

class SearchControllerTest extends TestCase
{
  private TestableSearchController $ctrl;

  public function setUp(): void
  {
    $this->ctrl = new TestableSearchController();
  }

  // ===========================================================================
  // Helpers internes
  // ===========================================================================

  /** Crée une Request GET avec les query params donnés. */
  private function req(array $params = []): Request
  {
    return Request::create('/search', 'GET', $params);
  }

  /** Crée un stdObject qui simule une ligne DB de taxonomie. */
  private function taxRow(int $tid, int $depth, string $name = 'Dossier', string $lang = 'fr', int $parent = 0): \stdClass
  {
    return (object) [
      'tid'          => $tid,
      'name'         => $name,
      'display_name' => $name,
      'langcode'     => $lang,
      'created'      => 1_700_000_000,
      'changed'      => 1_700_000_001,
      'parent_tid'   => $parent,
      'author_uid'   => null,
      'depth'        => $depth,
    ];
  }

  /** Crée un stdObject résultat générique (pour mergeAndSort). */
  private function row(string $type, string $name, string $changed = '2024-01-01 00:00:00', string $lang = ''): \stdClass
  {
    return (object) [
      'entity_type' => $type,
      'name'        => $name,
      'changed'     => $changed,
      'lang'        => $lang,
      'created'     => $changed,
      'filesize'    => 0,
      'version'     => 1,
      'publish'     => 1,
    ];
  }

  /** Crée un node canonique pour les tests. */
  private function node(int $tid, int $parent = 0, array $rights = [], array $children = []): array
  {
    return array_merge([
      'tid'               => $tid,
      'name'              => "Node$tid",
      'display_name'      => "Node$tid",
      'langcode'          => 'fr',
      'created'           => 0,
      'changed'           => 0,
      'parent_tid'        => $parent,
      'author_uid'        => null,
      'children'          => $children,
      'can_view'          => 0,
      'can_edit'          => 0,
      'can_delete'        => 0,
      'can_create'        => 0,
      'can_edit_role'     => 0,
      'has_role'          => false,
      'visible_via_child' => false,
      'is_ancestor'       => false,
    ], $rights);
  }

  // ===========================================================================
  // parseSearchParams
  // ===========================================================================

  /** @test */
  public function parseSearchParams_defaults(): void
  {
    $p = $this->ctrl->callParseSearchParams($this->req());

    $this->assertSame(0,   $p['page']);
    $this->assertSame(10,  $p['per_page']);
    $this->assertSame(0,   $p['offset']);
    $this->assertSame('',  $p['sort']);
    $this->assertSame('ASC', $p['sort_dir']);
    $this->assertFalse($p['has_filters']);
    $this->assertNull($p['id_char_version']);
  }

  /** @test */
  public function parseSearchParams_page_offset(): void
  {
    $p = $this->ctrl->callParseSearchParams($this->req(['page' => 3, 'per_page' => 20]));

    $this->assertSame(3,  $p['page']);
    $this->assertSame(20, $p['per_page']);
    $this->assertSame(60, $p['offset']);   // 3 * 20
  }

  /** @test */
  public function parseSearchParams_negative_page_clamped_to_zero(): void
  {
    $p = $this->ctrl->callParseSearchParams($this->req(['page' => -5]));

    $this->assertSame(0, $p['page']);
    $this->assertSame(0, $p['offset']);
  }

  /** @test */
  public function parseSearchParams_per_page_clamped_between_1_and_120(): void
  {
    $too_low  = $this->ctrl->callParseSearchParams($this->req(['per_page' => 0]));
    $too_high = $this->ctrl->callParseSearchParams($this->req(['per_page' => 999]));

    $this->assertSame(1,   $too_low['per_page']);
    $this->assertSame(120, $too_high['per_page']);
  }

  /** @test */
  public function parseSearchParams_invalid_sort_dir_defaults_to_asc(): void
  {
    $p = $this->ctrl->callParseSearchParams($this->req(['sort_dir' => 'INVALID']));

    $this->assertSame('ASC', $p['sort_dir']);
  }

  /** @test */
  public function parseSearchParams_desc_sort_dir_accepted(): void
  {
    $p = $this->ctrl->callParseSearchParams($this->req(['sort_dir' => 'desc']));

    $this->assertSame('DESC', $p['sort_dir']);
  }

  /** @test */
  public function parseSearchParams_unknown_sort_field_is_cleared(): void
  {
    $p = $this->ctrl->callParseSearchParams($this->req(['sort' => 'nonexistent_column']));

    $this->assertSame('', $p['sort']);
  }

  /** @test */
  public function parseSearchParams_known_sort_field_is_kept(): void
  {
    foreach (['name', 'created', 'changed', 'filesize', 'author_name', 'lang'] as $col) {
      $p = $this->ctrl->callParseSearchParams($this->req(['sort' => $col]));
      $this->assertSame($col, $p['sort'], "sort '$col' devrait être conservé");
    }
  }

  /** @test */
  public function parseSearchParams_has_filters_set_by_any_filter_field(): void
  {
    $filter_fields = ['search', 'author_name', 'revision_user_name', 'created', 'changed', 'ext', 'lang'];

    foreach ($filter_fields as $field) {
      $p = $this->ctrl->callParseSearchParams($this->req([$field => 'something']));
      $this->assertTrue($p['has_filters'], "'$field' devrait activer has_filters");
    }
  }

  // ===========================================================================
  // langScore
  // ===========================================================================

  /** @test */
  public function langScore_exact_match_returns_1(): void
  {
    $this->assertSame(1, $this->ctrl->callLangScore('fr', 'fr'));
    $this->assertSame(1, $this->ctrl->callLangScore('de', 'de'));
  }

  /** @test */
  public function langScore_fr_non_current_returns_2(): void
  {
    $this->assertSame(2, $this->ctrl->callLangScore('fr', 'de'));
  }

  /** @test */
  public function langScore_other_returns_3(): void
  {
    $this->assertSame(3, $this->ctrl->callLangScore('es', 'de'));
    $this->assertSame(3, $this->ctrl->callLangScore('zh', 'fr'));
  }

  // ===========================================================================
  // langTokens
  // ===========================================================================

  /** @test */
  public function langTokens_null_returns_empty(): void
  {
    $this->assertSame([], $this->ctrl->callLangTokens(null));
    $this->assertSame([], $this->ctrl->callLangTokens(''));
    $this->assertSame([], $this->ctrl->callLangTokens('   '));
  }

  /** @test */
  public function langTokens_single_normalised_lowercase(): void
  {
    $this->assertSame(['fr'], $this->ctrl->callLangTokens('FR'));
    $this->assertSame(['uk'], $this->ctrl->callLangTokens('  UK  '));
  }

  /** @test */
  public function langTokens_comma_and_space_separated(): void
  {
    $this->assertSame(['de', 'uk', 'fr'], $this->ctrl->callLangTokens('de, UK,fr'));
  }

  /** @test */
  public function langTokens_deduplicates(): void
  {
    $this->assertSame(['fr'], $this->ctrl->callLangTokens('fr, FR, fr'));
  }

  // ===========================================================================
  // langGroup
  // ===========================================================================

  private static array $order = ['fr', 'uk', 'de'];

  /** @test */
  public function langGroup_empty_value_returns_2(): void
  {
    $this->assertSame(2, $this->ctrl->callLangGroup(null, self::$order));
    $this->assertSame(2, $this->ctrl->callLangGroup('', self::$order));
  }

  /** @test */
  public function langGroup_known_lang_returns_0(): void
  {
    $this->assertSame(0, $this->ctrl->callLangGroup('fr', self::$order));
    $this->assertSame(0, $this->ctrl->callLangGroup('uk,es', self::$order));  // uk est connu
  }

  /** @test */
  public function langGroup_unknown_lang_returns_1(): void
  {
    $this->assertSame(1, $this->ctrl->callLangGroup('es', self::$order));
    $this->assertSame(1, $this->ctrl->callLangGroup('zh,it', self::$order));
  }

  // ===========================================================================
  // langRank
  // ===========================================================================

  /** @test */
  public function langRank_empty_returns_count_of_order(): void
  {
    $this->assertSame(3, $this->ctrl->callLangRank(null, self::$order));
    $this->assertSame(3, $this->ctrl->callLangRank('', self::$order));
  }

  /** @test */
  public function langRank_first_element_returns_0(): void
  {
    $this->assertSame(0, $this->ctrl->callLangRank('fr', self::$order));
  }

  /** @test */
  public function langRank_last_element_returns_n_minus_1(): void
  {
    $this->assertSame(2, $this->ctrl->callLangRank('de', self::$order));
  }

  /** @test */
  public function langRank_multivalue_picks_best(): void
  {
    // "es,uk" → uk est rang 1, es est inconnu → meilleur = 1
    $this->assertSame(1, $this->ctrl->callLangRank('es,uk', self::$order));
  }

  // ===========================================================================
  // langIsMulti
  // ===========================================================================

  /** @test */
  public function langIsMulti_single_returns_0(): void
  {
    $this->assertSame(0, $this->ctrl->callLangIsMulti('fr'));
    $this->assertSame(0, $this->ctrl->callLangIsMulti(null));
    $this->assertSame(0, $this->ctrl->callLangIsMulti(''));
  }

  /** @test */
  public function langIsMulti_multi_returns_1(): void
  {
    $this->assertSame(1, $this->ctrl->callLangIsMulti('fr,uk'));
    $this->assertSame(1, $this->ctrl->callLangIsMulti('de,it,es'));
  }

  // ===========================================================================
  // langPriorityOrder
  // ===========================================================================

  /** @test */
  public function langPriorityOrder_fr_site_starts_with_fr(): void
  {
    $order = $this->ctrl->callLangPriorityOrder('fr');

    $this->assertSame('fr', $order[0]);
    $this->assertSame('uk', $order[1]);
  }

  /** @test */
  public function langPriorityOrder_en_site_starts_with_uk(): void
  {
    foreach (['en', 'uk', 'us'] as $lang) {
      $order = $this->ctrl->callLangPriorityOrder($lang);
      $this->assertSame('uk', $order[0], "langue site '$lang' devrait mettre uk en premier");
      $this->assertSame('fr', $order[1]);
    }
  }

  // ===========================================================================
  // compareLangValues
  // ===========================================================================

  /** @test */
  public function compareLangValues_different_groups_group_wins(): void
  {
    // fr (groupe 0) < es (groupe 1) < null (groupe 2)
    $order = ['fr', 'uk'];

    $this->assertLessThan(0, $this->ctrl->callCompareLangValues('fr', 'es', $order));
    $this->assertLessThan(0, $this->ctrl->callCompareLangValues('es', null, $order));
    $this->assertLessThan(0, $this->ctrl->callCompareLangValues('fr', null, $order));
  }

  /** @test */
  public function compareLangValues_same_group_rank_wins(): void
  {
    $order = ['fr', 'uk', 'de'];

    // fr rang 0 < uk rang 1
    $this->assertLessThan(0, $this->ctrl->callCompareLangValues('fr', 'uk', $order));
    $this->assertGreaterThan(0, $this->ctrl->callCompareLangValues('uk', 'fr', $order));
  }

  /** @test */
  public function compareLangValues_desc_inverts_rank_not_group(): void
  {
    $order = ['fr', 'uk'];

    // En DESC, uk (rang 1) passe avant fr (rang 0)
    $asc  = $this->ctrl->callCompareLangValues('fr', 'uk', $order, false);
    $desc = $this->ctrl->callCompareLangValues('fr', 'uk', $order, true);

    $this->assertLessThan(0, $asc);
    $this->assertGreaterThan(0, $desc);
  }

  /** @test */
  public function compareLangValues_same_rank_mono_before_multi(): void
  {
    $order = ['fr', 'uk'];

    // fr (mono) vs fr,uk (multi) → mono passe avant
    $this->assertLessThan(0, $this->ctrl->callCompareLangValues('fr', 'fr,uk', $order));
  }

  /** @test */
  public function compareLangValues_equal_falls_back_to_alphabetic(): void
  {
    $order = [];

    $this->assertLessThan(0, $this->ctrl->callCompareLangValues('abc', 'xyz', $order));
    $this->assertGreaterThan(0, $this->ctrl->callCompareLangValues('xyz', 'abc', $order));
    $this->assertSame(0, $this->ctrl->callCompareLangValues('abc', 'abc', $order));
  }

  // ===========================================================================
  // buildMediaWhereClause
  // ===========================================================================

  /** @test */
  public function buildMediaWhereClause_no_filters_returns_empty_where(): void
  {
    $params = [
      'search' => null, 'author_name' => null, 'revision_user_name' => null,
      'created' => null, 'changed' => null, 'ext_filter' => null, 'lang_filter' => null,
    ];

    [$where, $bindings] = $this->ctrl->callBuildMediaWhereClause($params, [42]);

    $this->assertEmpty($where);
    $this->assertSame([42], $bindings);  // base bindings inchangés
  }

  /** @test */
  public function buildMediaWhereClause_search_adds_like_conditions(): void
  {
    $params = ['search' => 'rapport', 'author_name' => null, 'revision_user_name' => null,
               'created' => null, 'changed' => null, 'ext_filter' => null, 'lang_filter' => null];

    [$where, $bindings] = $this->ctrl->callBuildMediaWhereClause($params, []);

    $this->assertCount(1, $where);
    $this->assertStringContainsString('LIKE', $where[0]);
    // 3 bindings LIKE pour name, filename, reference
    $this->assertCount(3, $bindings);
    $this->assertStringContainsString('rapport', $bindings[0]);
  }

  /** @test */
  public function buildMediaWhereClause_ext_filter_multiple(): void
  {
    $params = ['search' => null, 'author_name' => null, 'revision_user_name' => null,
               'created' => null, 'changed' => null,
               'ext_filter' => 'pdf, xlsx', 'lang_filter' => null];

    [$where, $bindings] = $this->ctrl->callBuildMediaWhereClause($params, []);

    $this->assertCount(1, $where);
    $this->assertStringContainsString('OR', $where[0]);
    $this->assertCount(2, $bindings);
    $this->assertSame('%.pdf', $bindings[0]);
    $this->assertSame('%.xlsx', $bindings[1]);
  }

  /** @test */
  public function buildMediaWhereClause_date_filter_uses_from_unixtime(): void
  {
    $params = ['search' => null, 'author_name' => null, 'revision_user_name' => null,
               'created' => '2024-03-15', 'changed' => null,
               'ext_filter' => null, 'lang_filter' => null];

    [$where, $bindings] = $this->ctrl->callBuildMediaWhereClause($params, []);

    $this->assertCount(1, $where);
    $this->assertStringContainsString('FROM_UNIXTIME', $where[0]);
    $this->assertSame('2024-03-15', $bindings[0]);
  }

  /** @test */
  public function buildMediaWhereClause_lang_filter_multiple(): void
  {
    $params = ['search' => null, 'author_name' => null, 'revision_user_name' => null,
               'created' => null, 'changed' => null,
               'ext_filter' => null, 'lang_filter' => 'fr,uk'];

    [$where, $bindings] = $this->ctrl->callBuildMediaWhereClause($params, []);

    $this->assertCount(1, $where);
    $this->assertCount(2, $bindings);
  }

  // ===========================================================================
  // buildMediaOrderBy
  // ===========================================================================

  /** @test */
  public function buildMediaOrderBy_empty_sort_returns_changed_desc(): void
  {
    $sql = $this->ctrl->callBuildMediaOrderBy('', 'ASC', []);

    $this->assertStringContainsString('m.changed DESC', $sql);
    $this->assertStringContainsString('m.mid ASC', $sql);
  }

  /** @test */
  public function buildMediaOrderBy_known_column(): void
  {
    $sql = $this->ctrl->callBuildMediaOrderBy('name', 'DESC', []);

    $this->assertStringContainsString('m.name', $sql);
    $this->assertStringContainsString('DESC', $sql);
  }

  /** @test */
  public function buildMediaOrderBy_unknown_column_falls_back_to_default(): void
  {
    $sql = $this->ctrl->callBuildMediaOrderBy('hacked_column', 'ASC', []);

    $this->assertStringContainsString('m.changed DESC', $sql);
  }

  /** @test */
  public function buildMediaOrderBy_lang_sort_builds_case_expression(): void
  {
    $order = ['fr', 'uk', 'de'];
    $sql   = $this->ctrl->callBuildMediaOrderBy('lang', 'ASC', $order);

    $this->assertStringContainsString('CASE', $sql);
    $this->assertStringContainsString('fr', $sql);
    $this->assertStringContainsString('uk', $sql);
  }

  /** @test */
  public function buildMediaOrderBy_dir_only_asc_or_desc(): void
  {
    // Une valeur arbitraire doit être normalisée en ASC
    $sql = $this->ctrl->callBuildMediaOrderBy('name', 'INVALID', []);

    $this->assertStringContainsString('ASC', $sql);
    $this->assertStringNotContainsString('INVALID', $sql);
  }

  // ===========================================================================
  // buildNodesFromTree
  // ===========================================================================

  /** @test */
  public function buildNodesFromTree_empty_rows_returns_empty_maps(): void
  {
    [$nodes, $ancestors, $descendants] = $this->ctrl->callBuildNodesFromTree([], 'fr');

    $this->assertEmpty($nodes);
    $this->assertEmpty($ancestors);
    $this->assertEmpty($descendants);
  }

  /** @test */
  public function buildNodesFromTree_depth_0_in_descendant_map(): void
  {
    [$nodes, $ancestors, $descendants] = $this->ctrl->callBuildNodesFromTree(
      [$this->taxRow(10, 0)],
      'fr'
    );

    $this->assertArrayHasKey(10, $descendants);
    $this->assertArrayNotHasKey(10, $ancestors);
    $this->assertFalse($nodes[10]['is_ancestor']);
  }

  /** @test */
  public function buildNodesFromTree_negative_depth_in_ancestor_map(): void
  {
    [$nodes, $ancestors, $descendants] = $this->ctrl->callBuildNodesFromTree(
      [$this->taxRow(5, -1)],
      'fr'
    );

    $this->assertArrayHasKey(5, $ancestors);
    $this->assertArrayNotHasKey(5, $descendants);
    $this->assertTrue($nodes[5]['is_ancestor']);
  }

  /** @test */
  public function buildNodesFromTree_children_array_populated(): void
  {
    $rows = [
      $this->taxRow(1, 0, 'Root',  'fr', 0),
      $this->taxRow(2, 1, 'Child', 'fr', 1),
    ];

    [$nodes] = $this->ctrl->callBuildNodesFromTree($rows, 'fr');

    $this->assertContains(2, $nodes[1]['children']);
    $this->assertEmpty($nodes[2]['children']);
  }

  /** @test */
  public function buildNodesFromTree_best_langcode_wins_on_duplicate_tid(): void
  {
    // Même TID (10), d'abord en 'de', puis en 'fr' (langue du site)
    // → fr doit gagner car langScore(fr, fr) = 1 < langScore(de, fr) = 3
    $rows = [
      $this->taxRow(10, 0, 'Nom DE', 'de', 0),
      $this->taxRow(10, 0, 'Nom FR', 'fr', 0),
    ];

    [$nodes] = $this->ctrl->callBuildNodesFromTree($rows, 'fr');

    $this->assertSame('Nom FR', $nodes[10]['name']);
    $this->assertSame('fr',     $nodes[10]['langcode']);
  }

  // ===========================================================================
  // computeAllowedTids
  // ===========================================================================

  /** @test */
  public function computeAllowedTids_no_filters_for_media_is_parent_only(): void
  {
    $nodes = [
      1 => $this->node(1, 0, ['can_view' => 1]),
      2 => $this->node(2, 1, ['can_view' => 1]),
    ];
    $desc  = [1 => true, 2 => true];

    $allowed = $this->ctrl->callComputeAllowedTids(1, $desc, $nodes, false);

    $this->assertSame([1], $allowed['for_media']);
  }

  /** @test */
  public function computeAllowedTids_with_filters_for_media_is_visible_descendants(): void
  {
    $nodes = [
      1 => $this->node(1, 0, ['can_view' => 1]),
      2 => $this->node(2, 1, ['can_view' => 1]),
      3 => $this->node(3, 1, ['can_view' => 0]),  // non visible
    ];
    $desc = [1 => true, 2 => true, 3 => true];

    $allowed = $this->ctrl->callComputeAllowedTids(1, $desc, $nodes, true);

    $this->assertContains(1, $allowed['for_media']);
    $this->assertContains(2, $allowed['for_media']);
    $this->assertNotContains(3, $allowed['for_media']);
  }

  /** @test */
  public function computeAllowedTids_parent_always_in_for_meta(): void
  {
    $nodes = [10 => $this->node(10, 0, ['can_view' => 0])];
    $desc  = [10 => true];

    $allowed = $this->ctrl->callComputeAllowedTids(10, $desc, $nodes, false);

    $this->assertContains(10, $allowed['for_meta']);
  }

  /** @test */
  public function computeAllowedTids_visible_via_child_counts_as_visible(): void
  {
    $nodes = [
      5 => $this->node(5, 0, ['can_view' => 0, 'visible_via_child' => true]),
    ];
    $desc = [5 => true];

    $allowed = $this->ctrl->callComputeAllowedTids(5, $desc, $nodes, true);

    $this->assertContains(5, $allowed['for_media']);
  }

  // ===========================================================================
  // mergeAndSort
  // ===========================================================================

  /** @test */
  public function mergeAndSort_taxonomy_always_before_media(): void
  {
    $tax   = [$this->row('taxonomy', 'Z_Dossier')];
    $media = [$this->row('media',    'A_Document')];

    $result = $this->ctrl->callMergeAndSort($tax, $media, '', 'ASC', []);

    $this->assertSame('taxonomy', $result[0]->entity_type);
    $this->assertSame('media',    $result[1]->entity_type);
  }

  /** @test */
  public function mergeAndSort_no_sort_taxonomy_alphabetic(): void
  {
    $tax = [
      $this->row('taxonomy', 'Zeta'),
      $this->row('taxonomy', 'Alpha'),
    ];

    $result = $this->ctrl->callMergeAndSort($tax, [], '', 'ASC', []);

    $this->assertSame('Alpha', $result[0]->name);
    $this->assertSame('Zeta',  $result[1]->name);
  }

  /** @test */
  public function mergeAndSort_no_sort_media_by_changed_desc(): void
  {
    $media = [
      $this->row('media', 'Old', '2023-01-01 00:00:00'),
      $this->row('media', 'New', '2024-06-01 00:00:00'),
    ];

    $result = $this->ctrl->callMergeAndSort([], $media, '', 'ASC', []);

    $this->assertSame('New', $result[0]->name);
    $this->assertSame('Old', $result[1]->name);
  }

  /** @test */
  public function mergeAndSort_sort_by_name_asc(): void
  {
    $media = [
      $this->row('media', 'Z'),
      $this->row('media', 'A'),
    ];

    $result = $this->ctrl->callMergeAndSort([], $media, 'name', 'ASC', []);

    $this->assertSame('A', $result[0]->name);
    $this->assertSame('Z', $result[1]->name);
  }

  /** @test */
  public function mergeAndSort_sort_by_name_desc(): void
  {
    $media = [
      $this->row('media', 'A'),
      $this->row('media', 'Z'),
    ];

    $result = $this->ctrl->callMergeAndSort([], $media, 'name', 'DESC', []);

    $this->assertSame('Z', $result[0]->name);
    $this->assertSame('A', $result[1]->name);
  }

  // ===========================================================================
  // compareRowsBySort
  // ===========================================================================

  /** @test */
  public function compareRowsBySort_name_case_insensitive(): void
  {
    $a = (object) ['name' => 'alpha'];
    $b = (object) ['name' => 'BETA'];

    $this->assertLessThan(0, $this->ctrl->callCompareRowsBySort($a, $b, 'name', 'ASC', []));
  }

  /** @test */
  public function compareRowsBySort_filesize_numeric(): void
  {
    $a = (object) ['filesize' => '100'];
    $b = (object) ['filesize' => '50'];

    // ASC → petit avant grand
    $this->assertGreaterThan(0, $this->ctrl->callCompareRowsBySort($a, $b, 'filesize', 'ASC', []));
    // DESC → grand avant petit
    $this->assertLessThan(0, $this->ctrl->callCompareRowsBySort($a, $b, 'filesize', 'DESC', []));
  }

  /** @test */
  public function compareRowsBySort_created_parses_date(): void
  {
    $a = (object) ['created' => '2024-01-01 00:00:00'];
    $b = (object) ['created' => '2023-01-01 00:00:00'];

    $this->assertGreaterThan(0, $this->ctrl->callCompareRowsBySort($a, $b, 'created', 'ASC', []));
    $this->assertLessThan(0,    $this->ctrl->callCompareRowsBySort($a, $b, 'created', 'DESC', []));
  }

  /** @test */
  public function compareRowsBySort_lang_delegates_to_compareLangValues(): void
  {
    $order = ['fr', 'uk'];
    $a     = (object) ['lang' => 'fr'];
    $b     = (object) ['lang' => 'uk'];

    // fr avant uk en ASC
    $this->assertLessThan(0, $this->ctrl->callCompareRowsBySort($a, $b, 'lang', 'ASC', $order));
    // uk avant fr en DESC
    $this->assertGreaterThan(0, $this->ctrl->callCompareRowsBySort($a, $b, 'lang', 'DESC', $order));
  }

  // ===========================================================================
  // propagateDownward
  // ===========================================================================

  /** @test */
  public function propagateDownward_root_with_no_children_unchanged(): void
  {
    $nodes = [1 => $this->node(1, 0, ['can_view' => 0])];

    $this->ctrl->callPropagateDownward($nodes, 1);

    $this->assertSame(0, $nodes[1]['can_view']);
  }

  /** @test */
  public function propagateDownward_child_inherits_parent_rights_when_no_own_role(): void
  {
    $nodes = [
      1 => $this->node(1, 0, ['can_view' => 1, 'can_edit' => 1, 'has_role' => true], [2]),
      2 => $this->node(2, 1, ['can_view' => 0, 'has_role' => false]),
    ];

    $this->ctrl->callPropagateDownward($nodes, 1);

    $this->assertSame(1, $nodes[2]['can_view']);
    $this->assertSame(1, $nodes[2]['can_edit']);
  }

  /** @test */
  public function propagateDownward_child_with_own_role_keeps_its_rights(): void
  {
    $nodes = [
      1 => $this->node(1, 0, ['can_view' => 1, 'has_role' => true], [2]),
      2 => $this->node(2, 1, ['can_view' => 0, 'can_edit' => 0, 'has_role' => true]),
    ];

    $this->ctrl->callPropagateDownward($nodes, 1);

    // L'enfant a has_role=true → il ne doit PAS hériter
    $this->assertSame(0, $nodes[2]['can_view']);
  }

  /** @test */
  public function propagateDownward_propagates_through_multiple_levels(): void
  {
    $nodes = [
      1 => $this->node(1, 0, ['can_view' => 1, 'has_role' => true], [2]),
      2 => $this->node(2, 1, ['can_view' => 0, 'has_role' => false], [3]),
      3 => $this->node(3, 2, ['can_view' => 0, 'has_role' => false]),
    ];

    $this->ctrl->callPropagateDownward($nodes, 1);

    $this->assertSame(1, $nodes[2]['can_view']);
    $this->assertSame(1, $nodes[3]['can_view']);
  }

  // ===========================================================================
  // propagateVisibleViaChild
  // ===========================================================================

  /** @test */
  public function propagateVisibleViaChild_marks_parents_visible(): void
  {
    // nodes[2] parent=1, nodes[1] parent=0 (0 n'est pas dans $nodes → arrêt)
    $nodes = [
      1 => $this->node(1, 0),   // parent_tid=0 (absent du tableau → arrêt)
      2 => $this->node(2, 1),   // parent_tid=1
    ];

    // Appelé avec parent_tid=2 : marque 2, puis remonte à 1 et le marque aussi.
    $this->ctrl->callPropagateVisibleViaChild($nodes, 2);

    // Les deux nodes doivent être marqués visible_via_child.
    $this->assertTrue($nodes[2]['visible_via_child']);
    $this->assertTrue($nodes[1]['visible_via_child']);
  }

  /** @test */
  public function propagateVisibleViaChild_stops_at_already_visible(): void
  {
    $nodes = [
      10 => $this->node(10, 0, ['can_view' => 1]),
      20 => $this->node(20, 10),
    ];

    $this->ctrl->callPropagateVisibleViaChild($nodes, 20);

    // 10 a can_view=1, la propagation doit s'arrêter
    $this->assertFalse($nodes[10]['visible_via_child']);
  }

  /** @test */
  public function propagateVisibleViaChild_stops_if_already_set(): void
  {
    $nodes = [
      1 => $this->node(1, 0, ['visible_via_child' => true]),
      2 => $this->node(2, 1),
    ];

    // On ne veut pas de boucle infinie ni de récursion infinie
    $this->ctrl->callPropagateVisibleViaChild($nodes, 2);

    $this->assertTrue($nodes[2]['visible_via_child']);
    // 1 était déjà marqué, la boucle s'arrête
    $this->assertTrue($nodes[1]['visible_via_child']);
  }

  // ===========================================================================
  // resolveTaxonomyId (avec mock de connexion)
  // ===========================================================================

  /** @test */
  public function resolveTaxonomyId_numeric_suffix_returns_int_without_db(): void
  {
    $conn = $this->createMock(\stdClass::class);  // jamais appelé

    $result = $this->ctrl->callResolveTaxonomyId('taxonomy_42', 'fr', $conn);

    $this->assertSame(42, $result);
  }

  /** @test */
  public function resolveTaxonomyId_bare_number_returns_int(): void
  {
    $conn   = $this->createMock(\stdClass::class);
    $result = $this->ctrl->callResolveTaxonomyId('taxonomy_1000', 'fr', $conn);

    $this->assertSame(1000, $result);
  }

  /** @test */
  public function resolveTaxonomyId_name_found_in_db_returns_int(): void
  {
    $stmt = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['fetchField'])
      ->getMock();
    $stmt->method('fetchField')->willReturn('7');

    $conn = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['query'])
      ->getMock();
    $conn->method('query')->willReturn($stmt);

    $result = $this->ctrl->callResolveTaxonomyId('taxonomy_MonDossier', 'fr', $conn);

    $this->assertSame(7, $result);
  }

  /** @test */
  public function resolveTaxonomyId_name_not_found_returns_404_json_response(): void
  {
    $stmt = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['fetchField'])
      ->getMock();
    $stmt->method('fetchField')->willReturn(null);

    $conn = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['query'])
      ->getMock();
    $conn->method('query')->willReturn($stmt);

    $result = $this->ctrl->callResolveTaxonomyId('taxonomy_Introuvable', 'fr', $conn);

    $this->assertInstanceOf(JsonResponse::class, $result);
    $this->assertSame(404, $result->getStatusCode());
  }

  // ===========================================================================
  // fetchAndApplyRoleRights (avec mock de connexion)
  // ===========================================================================

  /** @test */
  public function fetchAndApplyRoleRights_applies_rights_to_matching_node(): void
  {
    $right = (object) [
      'id_char'      => 'taxonomy_5',
      'can_view'     => '1',
      'can_edit'     => '0',
      'can_delete'   => '1',
      'can_create'   => '0',
      'can_edit_role'=> '0',
    ];

    $stmt = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['fetchAll'])
      ->getMock();
    $stmt->method('fetchAll')->willReturn([$right]);

    $conn = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['query'])
      ->getMock();
    $conn->method('query')->willReturn($stmt);

    $nodes = [5 => $this->node(5)];

    $this->ctrl->callFetchAndApplyRoleRights($nodes, [5], ['some_role'], $conn);

    $this->assertSame(1,    $nodes[5]['can_view']);
    $this->assertSame(0,    $nodes[5]['can_edit']);
    $this->assertSame(1,    $nodes[5]['can_delete']);
    $this->assertTrue($nodes[5]['has_role']);
  }

  /** @test */
  public function fetchAndApplyRoleRights_no_rights_found_node_unchanged(): void
  {
    $stmt = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['fetchAll'])
      ->getMock();
    $stmt->method('fetchAll')->willReturn([]);

    $conn = $this->getMockBuilder(\stdClass::class)
      ->addMethods(['query'])
      ->getMock();
    $conn->method('query')->willReturn($stmt);

    $nodes = [9 => $this->node(9, 0, ['can_view' => 0])];

    $this->ctrl->callFetchAndApplyRoleRights($nodes, [9], ['some_role'], $conn);

    $this->assertSame(0,    $nodes[9]['can_view']);
    $this->assertFalse($nodes[9]['has_role']);
  }
}
