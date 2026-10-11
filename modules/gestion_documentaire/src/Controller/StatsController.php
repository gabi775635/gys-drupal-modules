<?php

namespace Drupal\gestion_documentaire\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class StatsController extends ControllerBase
{
  private const ANONYMOUS_TIERS_ID = '088051';
  private const ANONYMOUS_LABEL    = 'Anonyme / Non connecté';

  // =========================================================================
  // HELPER : connexion factorisée
  // =========================================================================

  private function getConnection()
  {
    Database::setActiveConnection('drupal9');
    return Database::getConnection();
  }

  // =========================================================================
  // PAGE PRINCIPALE
  // =========================================================================

  public function stats(Request $request)
  {
    $langcode   = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $connection = $this->getConnection();

    $total_folders = (int) $connection->query("
      SELECT COUNT(DISTINCT tid) FROM taxonomy_term_field_data WHERE vid = 'dossiers'
    ")->fetchField();

    $total_documents = (int) $connection->query("
      SELECT COUNT(DISTINCT mid) FROM media_field_data WHERE status = 1
    ")->fetchField();

    $total_revisions = (int) $connection->query("
      SELECT COUNT(mr.vid)
      FROM media_revision mr
      INNER JOIN media_field_data m ON m.mid = mr.mid
    ")->fetchField();

    $recent_activities = $connection->query("
      SELECT
        m.mid AS entity_id,
        m.name AS entity_name,
        mr.revision_user AS user_id,
        ufdr.name AS user_name,
        FROM_UNIXTIME(mr.revision_created) AS changed_date,
        CASE
          WHEN mr.vid = first_rev.min_vid THEN 'media_created'
          ELSE 'media_revision'
        END AS action
      FROM media_revision mr
      INNER JOIN media_field_data m ON m.mid = mr.mid
      INNER JOIN (
        SELECT mid, MIN(vid) AS min_vid FROM media_revision GROUP BY mid
      ) first_rev ON first_rev.mid = mr.mid
      LEFT JOIN users_field_data ufdr ON ufdr.uid = mr.revision_user
      WHERE mr.revision_created > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 30 DAY))
      ORDER BY mr.revision_created DESC
      LIMIT 20
    ")->fetchAll();

    $available_types = $connection->query("
      SELECT DISTINCT field_media_type_value AS type
      FROM media__field_media_type
      WHERE field_media_type_value IS NOT NULL
      ORDER BY type
    ")->fetchAll();

    $chart_data = $this->buildGlobalChartData($connection);

    return [
      '#theme'             => 'gestion_documentaire_stats',
      '#total_folders'     => $total_folders,
      '#total_documents'   => $total_documents,
      '#total_revisions'   => $total_revisions,
      '#recent_activities' => $recent_activities,
      '#available_types'   => $available_types,
      '#chart_data'        => $chart_data,
      '#langcode'          => $langcode,
      '#attached' => [
        'library' => [
          'gestion_documentaire/chartjs',
          'gestion_documentaire/stats',
        ],
        'drupalSettings' => [
          'gdStatsData' => $chart_data,
        ],
      ],
    ];
  }

  // =========================================================================
  // ENDPOINT : données période
  // =========================================================================

  public function periodData(Request $request): JsonResponse
  {
    $connection  = $this->getConnection();
    $period      = $request->query->get('period', 'current_month');
    $date_from   = $request->query->get('date_from');
    $date_to     = $request->query->get('date_to');
    $type_filter = $request->query->get('type_filter', '');

    [$start_date, $end_date] = $this->getDateRange($period, $date_from, $date_to);
    $start_ts = strtotime($start_date . ' 00:00:00');
    $end_ts   = strtotime($end_date . ' 23:59:59');

    $params             = [':start_ts' => $start_ts, ':end_ts' => $end_ts];
    $type_cond_media    = '';
    $type_cond_revision = '';

    if (!empty($type_filter)) {
      $type_cond_media    = ' AND mrt.field_media_type_value = :type_filter';
      $type_cond_revision = ' AND mrt.field_media_type_value = :type_filter';
      $params[':type_filter'] = $type_filter;
    }

    $inserted_stats = $connection->query("
      SELECT
        COALESCE(mrt.field_media_type_value, 'Non défini') AS type,
        COUNT(DISTINCT m.mid) AS count
      FROM media_field_data m
      LEFT JOIN media__field_media_type mrt ON m.mid = mrt.entity_id AND mrt.deleted = 0
      WHERE m.created BETWEEN :start_ts AND :end_ts
      $type_cond_media
      GROUP BY mrt.field_media_type_value
      ORDER BY count DESC
    ", $params)->fetchAll(\PDO::FETCH_ASSOC);

    $modified_stats = $connection->query("
      SELECT
        COALESCE(mrt.field_media_type_value, 'Non défini') AS type,
        COUNT(DISTINCT mr.mid) AS count
      FROM media_revision mr
      INNER JOIN media_field_data m ON m.mid = mr.mid
      INNER JOIN (
        SELECT mid, MIN(vid) AS min_vid FROM media_revision GROUP BY mid
      ) first_rev ON first_rev.mid = mr.mid
      LEFT JOIN media__field_media_type mrt ON m.mid = mrt.entity_id AND mrt.deleted = 0
      WHERE mr.revision_created BETWEEN :start_ts AND :end_ts
        AND mr.vid != first_rev.min_vid
      $type_cond_revision
      GROUP BY mrt.field_media_type_value
      ORDER BY count DESC
    ", $params)->fetchAll(\PDO::FETCH_ASSOC);

    $modified_total = array_sum(array_column($modified_stats, 'count'));
    $inserted_total = array_sum(array_column($inserted_stats, 'count'));
    $total_period   = $modified_total + $inserted_total;

    foreach ($modified_stats as &$row) {
      $row['percent'] = $total_period > 0 ? round($row['count'] / $total_period * 100, 1) : 0;
    }
    unset($row);
    foreach ($inserted_stats as &$row) {
      $row['percent'] = $total_period > 0 ? round($row['count'] / $total_period * 100, 1) : 0;
    }
    unset($row);

    [$lang_added_total, $lang_removed_total] = $this->computeLangDiffBulk(
      $connection, $start_ts, $end_ts, $type_filter
    );

    // ── Totaux accès + statuts sur la période ─────────────────────────────
    $access_params    = [':start_ts' => $start_ts, ':end_ts' => $end_ts];
    $access_type_join = '';
    $access_type_cond = '';
    if (!empty($type_filter)) {
      $access_type_join = "LEFT JOIN media__field_media_type mrt ON mrt.entity_id = log.media_id AND mrt.deleted = 0";
      $access_type_cond = "AND mrt.field_media_type_value = :type_filter";
      $access_params[':type_filter'] = $type_filter;
    }

    $access_totals = $connection->query("
      SELECT
        SUM(log.action = 'view')          AS total_views,
        SUM(log.action = 'download')      AS total_downloads,
        SUM(log.status = 'success')       AS total_success,
        SUM(log.status = 'error')         AS total_errors
      FROM gestion_documentaire_access_log log
      $access_type_join
      WHERE log.timestamp BETWEEN :start_ts AND :end_ts
      $access_type_cond
    ", $access_params)->fetch(\PDO::FETCH_ASSOC);

    $total_success = (int) ($access_totals['total_success'] ?? 0);
    $total_errors  = (int) ($access_totals['total_errors']  ?? 0);
    $total_all     = $total_success + $total_errors;
    $success_rate  = $total_all > 0 ? round($total_success / $total_all * 100, 1) : 100;

    return new JsonResponse([
      'modified_stats'     => $modified_stats,
      'inserted_stats'     => $inserted_stats,
      'modified_total'     => $modified_total,
      'inserted_total'     => $inserted_total,
      'total_period'       => $total_period,
      'lang_added_total'   => $lang_added_total,
      'lang_removed_total' => $lang_removed_total,
      'total_views'        => (int) ($access_totals['total_views']     ?? 0),
      'total_downloads'    => (int) ($access_totals['total_downloads'] ?? 0),
      'total_success'      => $total_success,
      'total_errors'       => $total_errors,
      'success_rate'       => $success_rate,
      'period'             => $period,
      'date_from'          => $start_date,
      'date_to'            => $end_date,
    ]);
  }

  // =========================================================================
  // ENDPOINT : top documents consultés / téléchargés
  // =========================================================================

  public function accessData(Request $request): JsonResponse
  {
    $connection  = $this->getConnection();
    $period      = $request->query->get('period', 'current_month');
    $date_from   = $request->query->get('date_from');
    $date_to     = $request->query->get('date_to');
    $type_filter = $request->query->get('type_filter', '');
    $page        = max(1, (int) $request->query->get('page', 1));
    $per_page    = max(1, min(100, (int) $request->query->get('per_page', 20)));

    [$start_date, $end_date] = $this->getDateRange($period, $date_from, $date_to);
    $start_ts = strtotime($start_date . ' 00:00:00');
    $end_ts   = strtotime($end_date . ' 23:59:59');

    $params    = [':start_ts' => $start_ts, ':end_ts' => $end_ts];
    $type_join = '';
    $type_cond = '';
    if (!empty($type_filter)) {
      $type_join = "LEFT JOIN media__field_media_type mrt ON mrt.entity_id = log.media_id AND mrt.deleted = 0";
      $type_cond = "AND mrt.field_media_type_value = :type_filter";
      $params[':type_filter'] = $type_filter;
    }

    $offset = ($page - 1) * $per_page;

    // ── Total pour la pagination ──────────────────────────────────────────
    $total_docs = (int) $connection->query("
      SELECT COUNT(DISTINCT log.media_id)
      FROM gestion_documentaire_access_log log
      $type_join
      WHERE log.timestamp BETWEEN :start_ts AND :end_ts
        AND log.status = 'success'
      $type_cond
    ", $params)->fetchField();

    // ── Top documents ─────────────────────────────────────────────────────
    $top_docs = $connection->query("
      SELECT
        log.media_id,
        m.name AS doc_name,
        COALESCE(mrt2.field_media_type_value, 'Non défini') AS doc_type,
        SUM(log.action = 'view')     AS views,
        SUM(log.action = 'download') AS downloads,
        COUNT(*)                     AS total_accesses,
        COUNT(DISTINCT log.tiers_id) AS unique_users,
        DATE_FORMAT(FROM_UNIXTIME(MAX(log.timestamp)), '%d/%m/%Y %H:%i') AS last_access
      FROM gestion_documentaire_access_log log
      LEFT JOIN media_field_data m ON m.mid = log.media_id
      LEFT JOIN media__field_media_type mrt2 ON mrt2.entity_id = log.media_id AND mrt2.deleted = 0
      $type_join
      WHERE log.timestamp BETWEEN :start_ts AND :end_ts
        AND log.status = 'success'
      $type_cond
      GROUP BY log.media_id, m.name, mrt2.field_media_type_value
      ORDER BY total_accesses DESC
      LIMIT $offset, $per_page
    ", $params)->fetchAll(\PDO::FETCH_ASSOC);

    // ── Stats par user ────────────────────────────────────────────────────
    $top_users_total = (int) $connection->query("
      SELECT COUNT(DISTINCT log.tiers_id)
      FROM gestion_documentaire_access_log log
      $type_join
      WHERE log.timestamp BETWEEN :start_ts AND :end_ts
        AND log.status = 'success'
      $type_cond
    ", $params)->fetchField();

    $users_page     = max(1, (int) $request->query->get('users_page', 1));
    $users_per_page = max(1, min(100, (int) $request->query->get('users_per_page', 20)));
    $users_offset   = ($users_page - 1) * $users_per_page;

    $top_users = $connection->query("
      SELECT
        CASE WHEN log.tiers_id = :anon_id THEN :anon_label ELSE log.tiers_id END AS user_label,
        SUM(log.action = 'view')     AS views,
        SUM(log.action = 'download') AS downloads,
        COUNT(*)                     AS total_accesses,
        COUNT(DISTINCT log.media_id) AS unique_docs,
        DATE_FORMAT(FROM_UNIXTIME(MAX(log.timestamp)), '%d/%m/%Y %H:%i') AS last_access
      FROM gestion_documentaire_access_log log
      $type_join
      WHERE log.timestamp BETWEEN :start_ts AND :end_ts
        AND log.status = 'success'
      $type_cond
      GROUP BY log.tiers_id
      ORDER BY total_accesses DESC
      LIMIT $users_offset, $users_per_page
    ", array_merge($params, [
      ':anon_id'    => self::ANONYMOUS_TIERS_ID,
      ':anon_label' => self::ANONYMOUS_LABEL,
    ]))->fetchAll(\PDO::FETCH_ASSOC);

    // ── Évolution jour par jour ───────────────────────────────────────────
    $evolution = $connection->query("
      SELECT
        DATE_FORMAT(FROM_UNIXTIME(log.timestamp), '%Y-%m-%d') AS day,
        SUM(log.action = 'view')     AS views,
        SUM(log.action = 'download') AS downloads
      FROM gestion_documentaire_access_log log
      $type_join
      WHERE log.timestamp BETWEEN :start_ts AND :end_ts
        AND log.status = 'success'
      $type_cond
      GROUP BY DATE_FORMAT(FROM_UNIXTIME(log.timestamp), '%Y-%m-%d')
      ORDER BY day ASC
    ", $params)->fetchAll(\PDO::FETCH_ASSOC);

    return new JsonResponse([
      'top_docs'            => $top_docs,
      'top_users'           => $top_users,
      'evolution'           => $evolution,
      'total_docs'          => $total_docs,
      'total_pages'         => max(1, (int) ceil($total_docs / $per_page)),
      'current_page'        => $page,
      'per_page'            => $per_page,
      'top_users_total'     => $top_users_total,
      'top_users_pages'     => max(1, (int) ceil($top_users_total / $users_per_page)),
      'users_current_page'  => $users_page,
      'users_per_page'      => $users_per_page,
    ]);
  }

  // =========================================================================
  // ENDPOINT : journal des accès avec statuts (nouveau)
  // =========================================================================

  public function accessLog(Request $request): JsonResponse
  {
    $connection  = $this->getConnection();
    $period      = $request->query->get('period', 'current_month');
    $date_from   = $request->query->get('date_from');
    $date_to     = $request->query->get('date_to');
    $type_filter = $request->query->get('type_filter', '');
    $status      = $request->query->get('status', '');
    $action      = $request->query->get('action', '');
    $search      = $request->query->get('search', '');
    $page        = max(1, (int) $request->query->get('page', 1));
    $per_page    = max(1, min(100, (int) $request->query->get('per_page', 20)));

    [$start_date, $end_date] = $this->getDateRange($period, $date_from, $date_to);
    $start_ts = strtotime($start_date . ' 00:00:00');
    $end_ts   = strtotime($end_date . ' 23:59:59');

    $params = [':start_ts' => $start_ts, ':end_ts' => $end_ts];
    $extra  = '';

    if (!empty($type_filter)) {
      $extra .= ' AND mrt.field_media_type_value = :type_filter';
      $params[':type_filter'] = $type_filter;
    }
    if (!empty($status)) {
      $extra .= ' AND log.status = :status';
      $params[':status'] = $status;
    }
    if (!empty($action)) {
      $extra .= ' AND log.action = :action';
      $params[':action'] = $action;
    }
    if (!empty($search)) {
      $extra .= ' AND (log.message LIKE :search
                    OR log.tiers_id LIKE :search
                    OR m.name LIKE :search
                    OR mrr.field_media_reference_value LIKE :search
                    OR mrt.field_media_type_value LIKE :search
                    OR mrl.field_media_lang_value LIKE :search)';
      $params[':search'] = '%' . $search . '%';
    }

    $base_join = "
      FROM gestion_documentaire_access_log log
      LEFT JOIN media_field_data m ON m.mid = log.media_id
      LEFT JOIN media__field_media_type mrt   ON mrt.entity_id   = log.media_id AND mrt.deleted = 0
      LEFT JOIN media__field_media_lang mrl   ON mrl.entity_id   = log.media_id AND mrl.deleted = 0
      LEFT JOIN media__field_media_reference mrr ON mrr.entity_id = log.media_id AND mrr.deleted = 0
      LEFT JOIN file_managed fm ON fm.fid = (
        SELECT mfd.field_media_document_target_id
        FROM media__field_media_document mfd
        WHERE mfd.entity_id = log.media_id
        LIMIT 1
      )
      WHERE log.timestamp BETWEEN :start_ts AND :end_ts
      $extra
    ";

    $total = (int) $connection->query("SELECT COUNT(*) $base_join", $params)->fetchField();

    $offset = ($page - 1) * $per_page;

    $rows = $connection->query("
      SELECT
        log.id,
        DATE_FORMAT(FROM_UNIXTIME(log.timestamp), '%d/%m/%Y %H:%i:%s') AS date_formatted,
        log.timestamp,
        log.status,
        log.action,
        log.media_id,
        m.name AS media_name,
        COALESCE(mrt.field_media_type_value,  'Non défini') AS doc_type,
        COALESCE(mrl.field_media_lang_value,  '—')          AS lang,
        COALESCE(mrr.field_media_reference_value, '—')      AS reference,
        CASE WHEN log.tiers_id = :anon_id THEN :anon_label ELSE log.tiers_id END AS tiers_label,
        COALESCE(fm.uri, '—') AS file_uri,
        log.message
      $base_join
      ORDER BY log.timestamp DESC
      LIMIT $offset, $per_page
    ", array_merge($params, [
      ':anon_id'    => self::ANONYMOUS_TIERS_ID,
      ':anon_label' => self::ANONYMOUS_LABEL,
    ]))->fetchAll(\PDO::FETCH_ASSOC);

    // ── Compteurs agrégés pour les KPI ────────────────────────────────────
    $kpis = $connection->query("
      SELECT
        SUM(log.status = 'success') AS total_success,
        SUM(log.status = 'error')   AS total_errors,
        SUM(log.action = 'view')    AS total_views,
        SUM(log.action = 'download') AS total_downloads
      FROM gestion_documentaire_access_log log
      LEFT JOIN media_field_data m ON m.mid = log.media_id
      LEFT JOIN media__field_media_type mrt ON mrt.entity_id = log.media_id AND mrt.deleted = 0
      WHERE log.timestamp BETWEEN :start_ts AND :end_ts
        " . (!empty($type_filter) ? "AND mrt.field_media_type_value = :type_filter" : "") . "
    ", array_merge([':start_ts' => $start_ts, ':end_ts' => $end_ts], !empty($type_filter) ? [':type_filter' => $type_filter] : []))->fetch(\PDO::FETCH_ASSOC);

    $total_success = (int) ($kpis['total_success'] ?? 0);
    $total_errors  = (int) ($kpis['total_errors']  ?? 0);
    $total_all     = $total_success + $total_errors;

    return new JsonResponse([
      'rows'          => $rows,
      'total'         => $total,
      'total_pages'   => max(1, (int) ceil($total / $per_page)),
      'current_page'  => $page,
      'per_page'      => $per_page,
      'total_success' => $total_success,
      'total_errors'  => $total_errors,
      'success_rate'  => $total_all > 0 ? round($total_success / $total_all * 100, 1) : 100,
      'total_views'   => (int) ($kpis['total_views']    ?? 0),
      'total_downloads' => (int) ($kpis['total_downloads'] ?? 0),
    ]);
  }

  // =========================================================================
  // ENDPOINT : références modifiées
  // =========================================================================

  public function searchModified(Request $request): JsonResponse
  {
    $connection  = $this->getConnection();
    $period      = $request->query->get('period', 'current_month');
    $date_from   = $request->query->get('date_from');
    $date_to     = $request->query->get('date_to');
    $type_filter = $request->query->get('type_filter', '');
    $search      = $request->query->get('search', '');
    $page        = max(1, (int) $request->query->get('page', 1));
    $per_page    = max(1, min(100, (int) $request->query->get('per_page', 20)));

    [$start_date, $end_date] = $this->getDateRange($period, $date_from, $date_to);
    $start_ts = strtotime($start_date . ' 00:00:00');
    $end_ts   = strtotime($end_date . ' 23:59:59');

    $params = [':start_ts' => $start_ts, ':end_ts' => $end_ts];
    $extra  = '';

    if (!empty($type_filter)) {
      $extra .= ' AND mrt.field_media_type_value = :type_filter';
      $params[':type_filter'] = $type_filter;
    }
    if (!empty($search)) {
      $extra .= ' AND (mrr.field_media_reference_value LIKE :search OR m.name LIKE :search)';
      $params[':search'] = '%' . $search . '%';
    }

    $base_join = "
      FROM media_revision mr
      INNER JOIN media_field_data m ON m.mid = mr.mid
      INNER JOIN (
        SELECT mid, MIN(vid) AS min_vid FROM media_revision GROUP BY mid
      ) first_rev ON first_rev.mid = mr.mid
      LEFT JOIN media__field_media_type mrt ON m.mid = mrt.entity_id AND mrt.deleted = 0
      LEFT JOIN media__field_media_reference mrr ON m.mid = mrr.entity_id AND mrr.deleted = 0
      LEFT JOIN users_field_data u_author ON u_author.uid = m.uid
      WHERE mr.revision_created BETWEEN :start_ts AND :end_ts
        AND mr.vid != first_rev.min_vid
        AND mrr.field_media_reference_value IS NOT NULL
        AND mrr.field_media_reference_value != ''
      $extra
    ";

    $total = (int) $connection->query(
      "SELECT COUNT(DISTINCT CONCAT(m.mid, '-', mrr.field_media_reference_value)) $base_join",
      $params
    )->fetchField();

    $total_pages = max(1, (int) ceil($total / $per_page));
    $offset      = ($page - 1) * $per_page;

    $references_raw = $connection->query("
      SELECT
        mrr.field_media_reference_value AS reference,
        COALESCE(mrt.field_media_type_value, 'Non défini') AS type,
        m.name AS document_name,
        m.mid AS mid,
        COUNT(mr.vid) AS revision_count,
        DATE_FORMAT(FROM_UNIXTIME(MAX(mr.revision_created)), '%d/%m/%Y %H:%i') AS last_modified,
        GROUP_CONCAT(
          DISTINCT DATE_FORMAT(FROM_UNIXTIME(mr.revision_created), '%d/%m/%Y')
          ORDER BY mr.revision_created DESC
          SEPARATOR ', '
        ) AS modification_dates,
        COALESCE(u_author.name, 'Anonyme') AS author_name,
        COALESCE(
          (SELECT u2.name FROM media_revision mr2
           LEFT JOIN users_field_data u2 ON u2.uid = mr2.revision_user
           WHERE mr2.mid = m.mid
             AND mr2.revision_created BETWEEN :start_ts AND :end_ts
           ORDER BY mr2.revision_created DESC LIMIT 1),
          'Anonyme'
        ) AS last_editor_name
      $base_join
      GROUP BY m.mid, mrr.field_media_reference_value, mrt.field_media_type_value, m.name, m.uid, u_author.name
      ORDER BY revision_count DESC
      LIMIT $offset, $per_page
    ", $params)->fetchAll(\PDO::FETCH_ASSOC);

    if (empty($references_raw)) {
      return new JsonResponse([
        'references'   => [],
        'total'        => 0,
        'total_pages'  => 1,
        'current_page' => $page,
        'per_page'     => $per_page,
      ]);
    }

    $mids       = array_unique(array_column($references_raw, 'mid'));
    $lang_diffs = $this->computeLangDiffForMids($connection, $mids, $start_ts, $end_ts);

    $references = [];
    foreach ($references_raw as $row) {
      $diff         = $lang_diffs[$row['mid']] ?? ['added' => [], 'removed' => [], 'unchanged' => []];
      $references[] = [
        'reference'          => $row['reference'],
        'type'               => $row['type'],
        'document_name'      => $row['document_name'],
        'revision_count'     => $row['revision_count'],
        'last_modified'      => $row['last_modified'],
        'modification_dates' => $row['modification_dates'],
        'author_name'        => $row['author_name'],
        'last_editor_name'   => $row['last_editor_name'],
        'langs_added'        => $diff['added'],
        'langs_removed'      => $diff['removed'],
        'langs_unchanged'    => $diff['unchanged'],
      ];
    }

    return new JsonResponse([
      'references'   => $references,
      'total'        => $total,
      'total_pages'  => $total_pages,
      'current_page' => $page,
      'per_page'     => $per_page,
    ]);
  }

  // =========================================================================
  // ENDPOINT : nouvelles références
  // =========================================================================

  public function searchInserted(Request $request): JsonResponse
  {
    $connection  = $this->getConnection();
    $period      = $request->query->get('period', 'current_month');
    $date_from   = $request->query->get('date_from');
    $date_to     = $request->query->get('date_to');
    $type_filter = $request->query->get('type_filter', '');
    $search      = $request->query->get('search', '');
    $page        = max(1, (int) $request->query->get('page', 1));
    $per_page    = max(1, min(100, (int) $request->query->get('per_page', 20)));

    [$start_date, $end_date] = $this->getDateRange($period, $date_from, $date_to);
    $start_ts = strtotime($start_date . ' 00:00:00');
    $end_ts   = strtotime($end_date . ' 23:59:59');

    $params = [':start_ts' => $start_ts, ':end_ts' => $end_ts];
    $extra  = '';

    if (!empty($type_filter)) {
      $extra .= ' AND mrt.field_media_type_value = :type_filter';
      $params[':type_filter'] = $type_filter;
    }
    if (!empty($search)) {
      $extra .= ' AND (mrr.field_media_reference_value LIKE :search OR m.name LIKE :search)';
      $params[':search'] = '%' . $search . '%';
    }

    $base_where = "
      FROM media_field_data m
      LEFT JOIN media__field_media_type mrt ON m.mid = mrt.entity_id AND mrt.deleted = 0
      LEFT JOIN media__field_media_reference mrr ON m.mid = mrr.entity_id AND mrr.deleted = 0
      WHERE m.created BETWEEN :start_ts AND :end_ts
        AND mrr.field_media_reference_value IS NOT NULL
        AND mrr.field_media_reference_value != ''
      $extra
    ";

    $total       = (int) $connection->query("SELECT COUNT(*) $base_where", $params)->fetchField();
    $total_pages = max(1, (int) ceil($total / $per_page));
    $offset      = ($page - 1) * $per_page;

    $references = $connection->query("
      SELECT
        mrr.field_media_reference_value AS reference,
        COALESCE(mrt.field_media_type_value, 'Non défini') AS type,
        m.name AS document_name,
        DATE_FORMAT(FROM_UNIXTIME(m.created), '%d/%m/%Y %H:%i') AS created_date,
        COALESCE(u_author.name, 'Anonyme') AS author_name,
        COALESCE(mfl.field_media_lang_value, '-') AS lang
      FROM media_field_data m
      LEFT JOIN media__field_media_type mrt ON m.mid = mrt.entity_id AND mrt.deleted = 0
      LEFT JOIN media__field_media_reference mrr ON m.mid = mrr.entity_id AND mrr.deleted = 0
      LEFT JOIN media__field_media_lang mfl ON m.mid = mfl.entity_id AND mfl.deleted = 0
      LEFT JOIN users_field_data u_author ON u_author.uid = m.uid
      WHERE m.created BETWEEN :start_ts AND :end_ts
        AND mrr.field_media_reference_value IS NOT NULL
        AND mrr.field_media_reference_value != ''
      $extra
      ORDER BY m.created DESC
      LIMIT $offset, $per_page
    ", $params)->fetchAll(\PDO::FETCH_ASSOC);

    return new JsonResponse([
      'references'   => $references,
      'total'        => $total,
      'total_pages'  => $total_pages,
      'current_page' => $page,
      'per_page'     => $per_page,
    ]);
  }

  // =========================================================================
  // ENDPOINT : export CSV
  // =========================================================================

  public function exportReferences(Request $request): Response
  {
    $connection  = $this->getConnection();
    $period      = $request->query->get('period', 'current_month');
    $date_from   = $request->query->get('date_from');
    $date_to     = $request->query->get('date_to');
    $type_filter = $request->query->get('type_filter', '');
    $action      = $request->query->get('action', 'modified');

    [$start_date, $end_date] = $this->getDateRange($period, $date_from, $date_to);
    $start_ts = strtotime($start_date . ' 00:00:00');
    $end_ts   = strtotime($end_date . ' 23:59:59');

    $params = [':start_ts' => $start_ts, ':end_ts' => $end_ts];
    $extra  = '';

    if (!empty($type_filter)) {
      $extra .= ' AND mrt.field_media_type_value = :type_filter';
      $params[':type_filter'] = $type_filter;
    }

    if ($action === 'modified') {

      $results = $connection->query("
        SELECT
          mrr.field_media_reference_value AS reference,
          COALESCE(mrt.field_media_type_value, 'Non défini') AS type,
          m.name AS document_name,
          COUNT(mr.vid) AS revision_count,
          DATE_FORMAT(FROM_UNIXTIME(MAX(mr.revision_created)), '%d/%m/%Y %H:%i') AS last_modified,
          COALESCE(u_author.name, 'Anonyme') AS author_name,
          COALESCE(
            (SELECT u2.name FROM media_revision mr2
             LEFT JOIN users_field_data u2 ON u2.uid = mr2.revision_user
             WHERE mr2.mid = m.mid
               AND mr2.revision_created BETWEEN :start_ts AND :end_ts
             ORDER BY mr2.revision_created DESC LIMIT 1),
            'Anonyme'
          ) AS last_editor_name,
          COALESCE(mfl.field_media_lang_value, '-') AS lang
        FROM media_revision mr
        INNER JOIN media_field_data m ON m.mid = mr.mid
        INNER JOIN (
          SELECT mid, MIN(vid) AS min_vid FROM media_revision GROUP BY mid
        ) first_rev ON first_rev.mid = mr.mid
        LEFT JOIN media__field_media_type mrt ON m.mid = mrt.entity_id AND mrt.deleted = 0
        LEFT JOIN media__field_media_reference mrr ON m.mid = mrr.entity_id AND mrr.deleted = 0
        LEFT JOIN media__field_media_lang mfl ON m.mid = mfl.entity_id AND mfl.deleted = 0
        LEFT JOIN users_field_data u_author ON u_author.uid = m.uid
        WHERE mr.revision_created BETWEEN :start_ts AND :end_ts
          AND mr.vid != first_rev.min_vid
          AND mrr.field_media_reference_value IS NOT NULL
          AND mrr.field_media_reference_value != ''
        $extra
        GROUP BY m.mid, mrr.field_media_reference_value, mrt.field_media_type_value, m.name, m.uid, u_author.name, mfl.field_media_lang_value
        ORDER BY revision_count DESC
      ", $params)->fetchAll();

    } elseif ($action === 'access') {

      // Export accès : inclut status, message, chemin fichier, type, langue, référence
      $results = $connection->query("
        SELECT
          DATE_FORMAT(FROM_UNIXTIME(log.timestamp), '%d/%m/%Y %H:%i:%s') AS date_acces,
          log.status,
          log.action,
          log.media_id,
          m.name AS document_name,
          COALESCE(mrt.field_media_type_value, 'Non défini') AS type,
          COALESCE(mrl.field_media_lang_value, '—') AS lang,
          COALESCE(mrr.field_media_reference_value, '—') AS reference,
          CASE WHEN log.tiers_id = :anon_id THEN :anon_label ELSE log.tiers_id END AS user_label,
          COALESCE(fm.uri, '—') AS file_uri,
          log.message
        FROM gestion_documentaire_access_log log
        LEFT JOIN media_field_data m ON m.mid = log.media_id
        LEFT JOIN media__field_media_type mrt ON mrt.entity_id = log.media_id AND mrt.deleted = 0
        LEFT JOIN media__field_media_lang mrl ON mrl.entity_id = log.media_id AND mrl.deleted = 0
        LEFT JOIN media__field_media_reference mrr ON mrr.entity_id = log.media_id AND mrr.deleted = 0
        LEFT JOIN file_managed fm ON fm.fid = (
          SELECT mfd.field_media_document_target_id
          FROM media__field_media_document mfd
          WHERE mfd.entity_id = log.media_id
          LIMIT 1
        )
        WHERE log.timestamp BETWEEN :start_ts AND :end_ts
        $extra
        ORDER BY log.timestamp DESC
      ", array_merge($params, [
        ':anon_id'    => self::ANONYMOUS_TIERS_ID,
        ':anon_label' => self::ANONYMOUS_LABEL,
      ]))->fetchAll();

    } elseif ($action === 'errors') {

      // Export erreurs uniquement
      $results = $connection->query("
        SELECT
          DATE_FORMAT(FROM_UNIXTIME(log.timestamp), '%d/%m/%Y %H:%i:%s') AS date_acces,
          log.status,
          log.action,
          log.media_id,
          COALESCE(m.name, '—') AS document_name,
          COALESCE(mrt.field_media_type_value, '—') AS type,
          COALESCE(mrl.field_media_lang_value, '—') AS lang,
          COALESCE(mrr.field_media_reference_value, '—') AS reference,
          CASE WHEN log.tiers_id = :anon_id THEN :anon_label ELSE log.tiers_id END AS user_label,
          COALESCE(fm.uri, '—') AS file_uri,
          log.message
        FROM gestion_documentaire_access_log log
        LEFT JOIN media_field_data m ON m.mid = log.media_id
        LEFT JOIN media__field_media_type mrt ON mrt.entity_id = log.media_id AND mrt.deleted = 0
        LEFT JOIN media__field_media_lang mrl ON mrl.entity_id = log.media_id AND mrl.deleted = 0
        LEFT JOIN media__field_media_reference mrr ON mrr.entity_id = log.media_id AND mrr.deleted = 0
        LEFT JOIN file_managed fm ON fm.fid = (
          SELECT mfd.field_media_document_target_id
          FROM media__field_media_document mfd
          WHERE mfd.entity_id = log.media_id
          LIMIT 1
        )
        WHERE log.timestamp BETWEEN :start_ts AND :end_ts
          AND log.status = 'error'
        $extra
        ORDER BY log.timestamp DESC
      ", array_merge($params, [
        ':anon_id'    => self::ANONYMOUS_TIERS_ID,
        ':anon_label' => self::ANONYMOUS_LABEL,
      ]))->fetchAll();

    } else {

      $results = $connection->query("
        SELECT
          mrr.field_media_reference_value AS reference,
          COALESCE(mrt.field_media_type_value, 'Non défini') AS type,
          m.name AS document_name,
          DATE_FORMAT(FROM_UNIXTIME(m.created), '%d/%m/%Y %H:%i') AS created_date,
          COALESCE(u_author.name, 'Anonyme') AS author_name,
          COALESCE(mfl.field_media_lang_value, '-') AS lang
        FROM media_field_data m
        LEFT JOIN media__field_media_type mrt ON m.mid = mrt.entity_id AND mrt.deleted = 0
        LEFT JOIN media__field_media_reference mrr ON m.mid = mrr.entity_id AND mrr.deleted = 0
        LEFT JOIN media__field_media_lang mfl ON m.mid = mfl.entity_id AND mfl.deleted = 0
        LEFT JOIN users_field_data u_author ON u_author.uid = m.uid
        WHERE m.created BETWEEN :start_ts AND :end_ts
          AND mrr.field_media_reference_value IS NOT NULL
          AND mrr.field_media_reference_value != ''
        $extra
        ORDER BY m.created DESC
      ", $params)->fetchAll();
    }

    $filename = $action . '_references_' . date('Y-m-d') . '.csv';
    $handle   = fopen('php://memory', 'r+');
    fputs($handle, "\xEF\xBB\xBF");

    if ($action === 'modified') {
      fputcsv($handle, ['Référence', 'Type', 'Langue', 'Nom du document', 'Auteur', 'Dernier modificateur', 'Nb modifications', 'Dernière modification'], ';');
      foreach ($results as $row) {
        fputcsv($handle, [
          $this->csvEncode($row->reference),
          $this->csvEncode($row->type),
          $this->csvEncode($row->lang ?? '-'),
          $this->csvEncode($row->document_name),
          $this->csvEncode($row->author_name ?? 'Anonyme'),
          $this->csvEncode($row->last_editor_name ?? 'Anonyme'),
          $row->revision_count,
          $this->csvEncode($row->last_modified),
        ], ';');
      }
    } elseif ($action === 'access' || $action === 'errors') {
      fputcsv($handle, ['Date', 'Statut', 'Action', 'Media ID', 'Document', 'Type', 'Langue', 'Référence', 'Utilisateur', 'Chemin fichier', 'Message'], ';');
      foreach ($results as $row) {
        fputcsv($handle, [
          $this->csvEncode($row->date_acces),
          $this->csvEncode($row->status),
          $this->csvEncode($row->action),
          $row->media_id,
          $this->csvEncode($row->document_name),
          $this->csvEncode($row->type),
          $this->csvEncode($row->lang),
          $this->csvEncode($row->reference),
          $this->csvEncode($row->user_label),
          $this->csvEncode($row->file_uri),
          $this->csvEncode($row->message),
        ], ';');
      }
    } else {
      fputcsv($handle, ['Référence', 'Type', 'Langue', 'Nom du document', 'Auteur', 'Date de création'], ';');
      foreach ($results as $row) {
        fputcsv($handle, [
          $this->csvEncode($row->reference),
          $this->csvEncode($row->type),
          $this->csvEncode($row->lang ?? '-'),
          $this->csvEncode($row->document_name),
          $this->csvEncode($row->author_name ?? 'Anonyme'),
          $this->csvEncode($row->created_date),
        ], ';');
      }
    }

    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    return new Response($csv, 200, [
      'Content-Type'        => 'text/csv; charset=UTF-8',
      'Content-Disposition' => 'attachment; filename="' . $filename . '"',
    ]);
  }

  // =========================================================================
  // HELPERS PRIVÉS
  // =========================================================================

  private function computeLangDiffForMids($connection, array $mids, int $start_ts, int $end_ts): array
  {
    if (empty($mids)) return [];

    $placeholders = implode(',', array_fill(0, count($mids), '?'));

    $before_rows = $connection->query("
      SELECT mr.mid, mfl.field_media_lang_value AS lang
      FROM media_revision mr
      INNER JOIN (
        SELECT mid, MAX(vid) AS last_vid_before
        FROM media_revision
        WHERE mid IN ($placeholders)
          AND revision_created < ?
        GROUP BY mid
      ) last_before ON last_before.mid = mr.mid AND last_before.last_vid_before = mr.vid
      LEFT JOIN media__field_media_lang mfl ON mfl.entity_id = mr.mid AND mfl.deleted = 0
    ", array_merge($mids, [$start_ts]))->fetchAll(\PDO::FETCH_ASSOC);

    $after_rows = $connection->query("
      SELECT mr.mid, mfl.field_media_lang_value AS lang
      FROM media_revision mr
      INNER JOIN (
        SELECT mid, MAX(vid) AS last_vid_after
        FROM media_revision
        WHERE mid IN ($placeholders)
          AND revision_created BETWEEN ? AND ?
        GROUP BY mid
      ) last_after ON last_after.mid = mr.mid AND last_after.last_vid_after = mr.vid
      LEFT JOIN media__field_media_lang mfl ON mfl.entity_id = mr.mid AND mfl.deleted = 0
    ", array_merge($mids, [$start_ts, $end_ts]))->fetchAll(\PDO::FETCH_ASSOC);

    $before_by_mid = [];
    foreach ($before_rows as $row) {
      if (!empty($row['lang'])) {
        foreach (preg_split('/[,\s]+/', $row['lang']) as $l) {
          $l = trim(strtolower($l));
          if ($l) $before_by_mid[$row['mid']][] = $l;
        }
      }
    }

    $after_by_mid = [];
    foreach ($after_rows as $row) {
      if (!empty($row['lang'])) {
        foreach (preg_split('/[,\s]+/', $row['lang']) as $l) {
          $l = trim(strtolower($l));
          if ($l) $after_by_mid[$row['mid']][] = $l;
        }
      }
    }

    $result = [];
    foreach ($mids as $mid) {
      $before = $before_by_mid[$mid] ?? [];
      $after  = $after_by_mid[$mid]  ?? [];
      $result[$mid] = [
        'added'     => array_values(array_diff($after,  $before)),
        'removed'   => array_values(array_diff($before, $after)),
        'unchanged' => array_values(array_intersect($before, $after)),
      ];
    }

    return $result;
  }

  private function computeLangDiffBulk($connection, int $start_ts, int $end_ts, string $type_filter = ''): array
  {
    $params = [':start_ts' => $start_ts, ':end_ts' => $end_ts];
    $extra  = '';

    if (!empty($type_filter)) {
      $extra = ' AND mrt.field_media_type_value = :type_filter';
      $params[':type_filter'] = $type_filter;
    }

    $mids = $connection->query("
      SELECT DISTINCT mr.mid
      FROM media_revision mr
      INNER JOIN media_field_data m ON m.mid = mr.mid
      INNER JOIN (
        SELECT mid, MIN(vid) AS min_vid FROM media_revision GROUP BY mid
      ) first_rev ON first_rev.mid = mr.mid
      LEFT JOIN media__field_media_type mrt ON m.mid = mrt.entity_id AND mrt.deleted = 0
      WHERE mr.revision_created BETWEEN :start_ts AND :end_ts
        AND mr.vid != first_rev.min_vid
      $extra
    ", $params)->fetchCol();

    if (empty($mids)) return [0, 0];

    $diffs = $this->computeLangDiffForMids($connection, $mids, $start_ts, $end_ts);

    $added_total   = 0;
    $removed_total = 0;
    foreach ($diffs as $diff) {
      $added_total   += count($diff['added']);
      $removed_total += count($diff['removed']);
    }

    return [$added_total, $removed_total];
  }

  private function buildGlobalChartData($connection): array
  {
    $media_by_type = $connection->query("
      SELECT COALESCE(mrt.field_media_type_value, 'Non défini') AS type, COUNT(DISTINCT m.mid) AS count
      FROM media_field_data m
      LEFT JOIN media__field_media_type mrt ON m.mid = mrt.entity_id AND mrt.deleted = 0
      WHERE m.status = 1
      GROUP BY mrt.field_media_type_value ORDER BY count DESC LIMIT 20
    ")->fetchAll();

    $media_by_lang = $connection->query("
      SELECT COALESCE(ml.field_media_lang_value, 'Non défini') AS lang, COUNT(DISTINCT m.mid) AS count
      FROM media_field_data m
      LEFT JOIN media__field_media_lang ml ON m.mid = ml.entity_id AND ml.deleted = 0
      WHERE m.status = 1
      GROUP BY ml.field_media_lang_value ORDER BY count DESC LIMIT 15
    ")->fetchAll();

    $evolution = $connection->query("
      SELECT DATE_FORMAT(FROM_UNIXTIME(m.created), '%Y-%m') AS month, COUNT(*) AS count
      FROM media_field_data m
      WHERE m.created > UNIX_TIMESTAMP(DATE_SUB(NOW(), INTERVAL 12 MONTH))
      GROUP BY DATE_FORMAT(FROM_UNIXTIME(m.created), '%Y-%m')
      ORDER BY month ASC
    ")->fetchAll();

    $top_references = $connection->query("
      SELECT mrr.field_media_reference_value AS reference, COUNT(DISTINCT m.mid) AS count
      FROM media_field_data m
      LEFT JOIN media__field_media_reference mrr ON m.mid = mrr.entity_id AND mrr.deleted = 0
      WHERE m.status = 1
        AND mrr.field_media_reference_value IS NOT NULL
        AND mrr.field_media_reference_value != ''
      GROUP BY mrr.field_media_reference_value ORDER BY count DESC LIMIT 10
    ")->fetchAll();

    $documents_by_folder = $connection->query("
      SELECT ttfd.name AS folder_name, COUNT(DISTINCT m.mid) AS count
      FROM media_field_data m
      INNER JOIN media__field_taxonomie_associee link ON m.mid = link.entity_id AND link.deleted = 0
      INNER JOIN taxonomy_term_field_data ttfd ON ttfd.tid = link.field_taxonomie_associee_target_id
      WHERE m.status = 1
      GROUP BY ttfd.tid, ttfd.name ORDER BY count DESC LIMIT 10
    ")->fetchAll();

    $top_contributors = $connection->query("
      SELECT COALESCE(ufdr.name, 'Anonyme') AS user_name, COUNT(*) AS revision_count
      FROM media_revision mr
      LEFT JOIN users_field_data ufdr ON ufdr.uid = mr.revision_user
      WHERE mr.revision_user IS NOT NULL AND mr.revision_user > 0
      GROUP BY mr.revision_user, ufdr.name ORDER BY revision_count DESC LIMIT 10
    ")->fetchAll();

    $versions_stats = $connection->query("
      SELECT COALESCE(mrv.field_media_version_value, '1') AS version, COUNT(DISTINCT m.mid) AS count
      FROM media_field_data m
      LEFT JOIN media__field_media_version mrv ON m.mid = mrv.entity_id AND mrv.deleted = 0
      WHERE m.status = 1
      GROUP BY mrv.field_media_version_value ORDER BY version ASC
    ")->fetchAll();

    // Top 10 docs les plus consultés tous temps — succès uniquement
    $top_accessed = $connection->query("
      SELECT
        m.name AS doc_name,
        SUM(log.action = 'view')     AS views,
        SUM(log.action = 'download') AS downloads,
        COUNT(*) AS total
      FROM gestion_documentaire_access_log log
      LEFT JOIN media_field_data m ON m.mid = log.media_id
      WHERE log.status = 'success'
      GROUP BY log.media_id, m.name
      ORDER BY total DESC
      LIMIT 10
    ")->fetchAll();

    // Répartition succès / erreurs globale
    $access_status = $connection->query("
      SELECT
        log.status,
        COUNT(*) AS count
      FROM gestion_documentaire_access_log log
      GROUP BY log.status
    ")->fetchAll();

    return [
      'media_by_type'       => ['labels' => array_column($media_by_type, 'type'),             'data' => array_column($media_by_type, 'count')],
      'media_by_lang'       => ['labels' => array_column($media_by_lang, 'lang'),              'data' => array_column($media_by_lang, 'count')],
      'evolution'           => ['labels' => array_column($evolution, 'month'),                 'data' => array_column($evolution, 'count')],
      'top_references'      => ['labels' => array_column($top_references, 'reference'),        'data' => array_column($top_references, 'count')],
      'documents_by_folder' => ['labels' => array_column($documents_by_folder, 'folder_name'), 'data' => array_column($documents_by_folder, 'count')],
      'top_contributors'    => ['labels' => array_column($top_contributors, 'user_name'),      'data' => array_column($top_contributors, 'revision_count')],
      'versions_stats'      => ['labels' => array_column($versions_stats, 'version'),          'data' => array_column($versions_stats, 'count')],
      'top_accessed'        => [
        'labels'    => array_column($top_accessed, 'doc_name'),
        'views'     => array_column($top_accessed, 'views'),
        'downloads' => array_column($top_accessed, 'downloads'),
      ],
      'access_status'       => [
        'labels' => array_column($access_status, 'status'),
        'data'   => array_column($access_status, 'count'),
      ],
    ];
  }

  private function csvEncode(?string $value): string
  {
    if ($value === null) return '';
    if (!mb_check_encoding($value, 'UTF-8')) {
      $value = mb_convert_encoding($value, 'UTF-8', 'auto');
    }
    return $value;
  }

  private function getDateRange(string $period, ?string $date_from, ?string $date_to): array
  {
    if ($period === 'custom' && $date_from && $date_to && strtotime($date_from) && strtotime($date_to)) {
      return [$date_from, $date_to];
    }

    $now = new \DateTime();

    switch ($period) {
      case 'today':
        return [$now->format('Y-m-d'), $now->format('Y-m-d')];
      case 'yesterday':
        $y = (clone $now)->modify('-1 day');
        return [$y->format('Y-m-d'), $y->format('Y-m-d')];
      case 'last_7_days':
        return [(clone $now)->modify('-6 days')->format('Y-m-d'), $now->format('Y-m-d')];
      case 'current_week':
        return [(clone $now)->modify('monday this week')->format('Y-m-d'), (clone $now)->modify('sunday this week')->format('Y-m-d')];
      case 'last_week':
        return [(clone $now)->modify('monday last week')->format('Y-m-d'), (clone $now)->modify('sunday last week')->format('Y-m-d')];
      case 'last_month':
        return [(clone $now)->modify('first day of last month')->format('Y-m-d'), (clone $now)->modify('last day of last month')->format('Y-m-d')];
      case 'current_quarter':
        $q     = (int) ceil(($now->format('n')) / 3);
        $start = new \DateTime($now->format('Y') . '-' . str_pad(($q - 1) * 3 + 1, 2, '0', STR_PAD_LEFT) . '-01');
        return [$start->format('Y-m-d'), $now->format('Y-m-d')];
      case 'current_year':
        return [$now->format('Y-01-01'), $now->format('Y-12-31')];
      case 'current_month':
      default:
        return [$now->format('Y-m-01'), $now->format('Y-m-t')];
    }
  }
}