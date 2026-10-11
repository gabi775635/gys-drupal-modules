<?php

namespace Drupal\gestion_documentaire\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Database\Database;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Psr\Log\LoggerInterface;
use Drupal\Core\Cache\CacheBackendInterface;

/**
 * Provides a 'Gestion documentaire home block'.
 *
 * @Block(
 *   id = "gestion_documentaire_home_block",
 *   admin_label = @Translation("Gestion Documentaire Home"),
 * )
 */
class HomeBlock extends BlockBase implements ContainerFactoryPluginInterface
{
  protected $languageManager;
  protected $logger;
  protected $cache;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    LanguageManagerInterface $language_manager,
    LoggerInterface $logger,
    CacheBackendInterface $cache
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->languageManager = $language_manager;
    $this->logger          = $logger;
    $this->cache           = $cache;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition)
  {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('language_manager'),
      $container->get('logger.factory')->get('gestion_documentaire'),
      $container->get('cache.default')
    );
  }

  public function build()
  {
    $start_time = microtime(true);

    $id_char_version = $this->configuration['id_char_version'] ?? \Drupal::request()->attributes->get('id_char_version');
    $search          = \Drupal::request()->query->get('search');

    if (!$id_char_version) {
      return ['#markup' => $this->t('Aucun ID fourni')];
    }

    $tid = $this->extractTid($id_char_version);
    if (!$tid) {
      return ['#markup' => $this->t('ID invalide : @id', ['@id' => $id_char_version])];
    }

    $connection = $this->getDatabaseConnection();
    if (!$connection) {
      return ['#markup' => $this->t('Erreur de connexion à la base de données')];
    }

    $account    = \Drupal::currentUser();
    $user_roles = $account->getRoles();
    $is_admin   = in_array('admin_gestiondocumentaire', $user_roles);

    $role_data          = $this->prepareRolePlaceholders($user_roles);
    $parent_permissions = $this->getParentPermissions($connection, $tid, $role_data, $is_admin);

    $role_data = $this->prepareRolePlaceholders($user_roles);
    $results   = $this->getMainData($connection, $tid, $role_data, $is_admin, $search, $parent_permissions);

    $langcode  = $this->languageManager->getCurrentLanguage()->getId();
    $type      = $this->getEntityType($connection, $tid, $langcode);
    $fil_ariane = $this->getBreadcrumb($connection, $tid, $langcode);

    $execution_time = microtime(true) - $start_time;
    if ($execution_time > 0.5) {
      $this->logger->warning('HomeBlock SLOW execution: @time seconds for TID @tid', [
        '@time' => round($execution_time, 3),
        '@tid'  => $tid,
      ]);
    }


    $is_admin   = in_array('admin_gestiondocumentaire', $user_roles) || in_array('administrator', $user_roles);

    
    return [
      '#theme'           => 'home_template',
      '#fil_ariane'      => $fil_ariane,
      '#id_char_version' => $id_char_version,
      '#type'            => $type,
      '#langcode'        => $langcode,
      '#search_url'      => Url::fromRoute('gestion_documentaire.search')->setAbsolute(FALSE)->toString(),
      '#results'         => '',
      '#parent_perms'    => $parent_permissions,
      '#is_admin'        => $is_admin,
      '#attached' => [
        'library' => ['gestion_documentaire/home_block'],
        'drupalSettings' => [
          'gestion_documentaire' => [
            'home' => [
              'id_char_version' => $id_char_version,
              'search_url' => Url::fromRoute('gestion_documentaire.search')
                ->setAbsolute(FALSE)
                ->toString(),
            ],
          ],
        ],
      ],
      '#cache'           => [
        'max-age'  => 0,
        'contexts' => ['url.query_args:search', 'user.roles'],
      ],
    ];
  }

  protected function extractTid($id_char_version)
  {
    if (preg_match('/^(?:taxonomy_)?(\d+)$/', $id_char_version, $matches)) {
      return (int) $matches[1];
    }
    return NULL;
  }

  protected function getDatabaseConnection()
  {
    try {
      Database::setActiveConnection('drupal9');
      return Database::getConnection();
    } catch (\Exception $e) {
      $this->logger->error('Database connection failed: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  protected function prepareRolePlaceholders(array $user_roles)
  {
    $placeholders = [];
    $bindings     = [];
    foreach ($user_roles as $index => $role) {
      $ph               = ':role' . $index;
      $placeholders[]   = $ph;
      $bindings[$ph]    = $role;
    }
    return ['placeholders' => $placeholders, 'bindings' => $bindings];
  }

  protected function getParentPermissions($connection, $tid, array $role_data, $is_admin)
  {
    $query = "
        WITH RECURSIVE taxonomy_path AS (
            SELECT t.tid, t.name, p.parent_target_id, 0 AS level
            FROM taxonomy_term_field_data t
            LEFT JOIN taxonomy_term__parent p ON p.entity_id = t.tid
            WHERE t.tid = :tid AND t.default_langcode = 1
            UNION ALL
            SELECT parent.tid, parent.name, pp.parent_target_id, tp.level + 1
            FROM taxonomy_path tp
            INNER JOIN taxonomy_term__parent pp ON pp.entity_id = tp.parent_target_id
            INNER JOIN taxonomy_term_field_data parent ON parent.tid = pp.entity_id
            WHERE parent.default_langcode = 1 AND tp.level < 20
        )
        SELECT
            tp.tid, tp.name, tp.parent_target_id, tp.level,
            COALESCE(MAX(gdr.can_view), 0)      AS can_view,
            COALESCE(MAX(gdr.can_edit), 0)      AS can_edit,
            COALESCE(MAX(gdr.can_delete), 0)    AS can_delete,
            COALESCE(MAX(gdr.can_edit_role), 0) AS can_edit_role
        FROM taxonomy_path tp
        LEFT JOIN gestion_documentaire_role gdr
            ON gdr.id_char = CONCAT('taxonomy_', tp.tid)
            AND gdr.role_libelle IN (" . implode(',', $role_data['placeholders']) . ")
        GROUP BY tp.tid, tp.name, tp.parent_target_id, tp.level
        ORDER BY tp.level ASC
    ";

    $bindings = array_merge([':tid' => $tid], $role_data['bindings']);

    try {
      $results = $connection->query($query, $bindings)->fetchAll();
      if (empty($results)) return null;

      if ($is_admin) {
        $first = reset($results);
        $first->can_view = $first->can_edit = $first->can_delete = $first->can_edit_role = 1;
        return $first;
      }

      foreach ($results as $result) {
        if ($result->can_view || $result->can_edit || $result->can_delete || $result->can_edit_role) {
          return $result;
        }
      }

      return end($results);

    } catch (\Exception $e) {
      $this->logger->error('Parent permissions query failed: @message', ['@message' => $e->getMessage()]);
      return null;
    }
  }

  protected function getMainData($connection, $tid, array $role_data, $is_admin, $search, $parent_permissions)
  {
    $query = "
      SELECT
        CONCAT('taxonomy_', ttfd.tid) AS id,
        'taxonomy' AS entity_type,
        ttfd.name AS type,
        ttfd.tid AS entity_id,
        ttfd.name,
        CONCAT('taxonomy_', ttfd.tid) AS id_char,
        GROUP_CONCAT(DISTINCT gdr.role_libelle) AS roles,
        CASE WHEN COUNT(gdr.role_libelle) > 0 THEN 1 ELSE 0 END AS role_boolean,
        MAX(COALESCE(gdr.can_view, 1))   AS can_view,
        MAX(COALESCE(gdr.can_edit, 1))   AS can_edit,
        MAX(COALESCE(gdr.can_delete, 1)) AS can_delete,
        ufda.name AS author
      FROM taxonomy_term_field_data ttfd
      LEFT JOIN taxonomy_term__field_user_reference ttfur ON ttfur.entity_id = ttfd.tid
      LEFT JOIN users_field_data ufda ON ufda.uid = ttfur.field_user_reference_target_id
      LEFT JOIN gestion_documentaire_role gdr ON CONCAT('taxonomy_', ttfd.tid) = gdr.id_char
    ";

    $bindings = [':tid' => $tid];

    if (!$is_admin && !empty($role_data['placeholders'])) {
      $query   .= " AND gdr.role_libelle IN (" . implode(',', $role_data['placeholders']) . ")";
      $bindings = array_merge($bindings, $role_data['bindings']);
    }

    $query .= " WHERE ttfd.tid = :tid";

    if (!empty($search)) {
      $query              .= " AND ttfd.name LIKE :search";
      $bindings[':search'] = '%' . $search . '%';
    }

    $query .= " GROUP BY ttfd.tid, ttfd.name, ufda.name";

    try {
      $results_raw = $connection->query($query, $bindings)->fetchAll();
      $results     = [];

      foreach ($results_raw as $row) {
        $row = (array) $row;

        if ($row['role_boolean'] == 0 && $parent_permissions) {
          $row['can_view']   = $parent_permissions->can_view;
          $row['can_edit']   = $parent_permissions->can_edit;
          $row['can_delete'] = $parent_permissions->can_delete;
        }

        if ($is_admin) {
          $row['can_view'] = $row['can_edit'] = $row['can_delete'] = 1;
        }

        $results[] = $row;
      }

      return $results;
    } catch (\Exception $e) {
      $this->logger->error('Main query failed: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }

  
  protected function getEntityType($connection, $tid, $langcode)
{
    try {
      // Essayer d'abord dans la langue courante
      $name = $connection->select('taxonomy_term_field_data', 't')
        ->fields('t', ['name'])
        ->condition('t.tid', $tid)
        ->condition('t.langcode', $langcode)
        ->execute()
        ->fetchField();

      // Si pas de traduction dans la langue courante, fallback sur le français
      if (empty($name)) {
        $name = $connection->select('taxonomy_term_field_data', 't')
          ->fields('t', ['name'])
          ->condition('t.tid', $tid)
          ->condition('t.langcode', 'fr')
          ->execute()
          ->fetchField();
        
        $this->logger->notice(
          '[getEntityType] Fallback FR pour TID=@tid (langue demandée=@lang, pas de trad native)',
          ['@tid' => $tid, '@lang' => $langcode]
        );
      }

      // Fallback ultime : n'importe quelle langue
      if (empty($name)) {
        $name = $connection->select('taxonomy_term_field_data', 't')
          ->fields('t', ['name'])
          ->condition('t.tid', $tid)
          ->condition('t.default_langcode', 1)
          ->execute()
          ->fetchField();
        
        $this->logger->warning(
          '[getEntityType] Fallback langue par défaut pour TID=@tid',
          ['@tid' => $tid]
        );
      }

      return $name ?: '';
      
    } catch (\Exception $e) {
      $this->logger->error('Type query failed: @message', ['@message' => $e->getMessage()]);
      return '';
    }
}

  /**
   * Construit le fil d'ariane en utilisant Url::fromRoute pour chaque entrée.
   * Le path est calculé côté PHP, plus de SQL avec base URL en dur.
   */
  protected function getBreadcrumb($connection, $tid, $langcode)
  {
    $language = $this->languageManager->getCurrentLanguage();

    // Requête récursive pour remonter la hiérarchie (noms seulement, sans URL en dur)
    $query = "
      WITH RECURSIVE taxonomy_path AS (
          SELECT t.tid, t.name, p.parent_target_id, t.langcode, 0 AS level
          FROM taxonomy_term_field_data t
          LEFT JOIN taxonomy_term__parent p ON p.entity_id = t.tid
          WHERE t.tid = :tid AND t.langcode = :langcode

          UNION ALL

          SELECT t_fr.tid, t_fr.name, p.parent_target_id, t_fr.langcode, 0 AS level
          FROM taxonomy_term_field_data t_fr
          LEFT JOIN taxonomy_term__parent p ON p.entity_id = t_fr.tid
          WHERE t_fr.tid = :tid AND t_fr.langcode = 'fr'
            AND NOT EXISTS (
                SELECT 1 FROM taxonomy_term_field_data t
                WHERE t.tid = :tid AND t.langcode = :langcode
            )

          UNION ALL

          SELECT parent.tid, parent.name, pp.parent_target_id, parent.langcode, tp.level + 1
          FROM taxonomy_term_field_data parent
          JOIN taxonomy_term__parent pp ON pp.entity_id = parent.tid
          JOIN taxonomy_path tp ON tp.parent_target_id = parent.tid
          WHERE (
              (parent.langcode = :langcode AND EXISTS (
                  SELECT 1 FROM taxonomy_term_field_data t WHERE t.tid = parent.tid AND t.langcode = :langcode
              ))
              OR
              (parent.langcode = 'fr' AND NOT EXISTS (
                  SELECT 1 FROM taxonomy_term_field_data t WHERE t.tid = parent.tid AND t.langcode = :langcode
              ))
          )
          AND tp.level < 10
      )
      SELECT * FROM taxonomy_path ORDER BY level DESC;
    ";

    try {
      $rows = $connection->query($query, [
        ':tid'      => $tid,
        ':langcode' => $langcode,
      ])->fetchAll();

      // Construction du path côté PHP via routing
      foreach ($rows as &$row) {
        $row->path = Url::fromRoute(
          'gestion_documentaire.dossier.view',
          ['nom' => $row->name],
          ['language' => $language]
        )->setAbsolute(FALSE)->toString();
      }

      return $rows;

    } catch (\Exception $e) {
      $this->logger->error('Breadcrumb query failed: @message', ['@message' => $e->getMessage()]);
      return [];
    }
  }
}