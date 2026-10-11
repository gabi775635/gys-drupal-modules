<?php

namespace Drupal\gestion_documentaire\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Database\Database;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * @Block(
 *   id = "gestion_documentaire_simple_nav_block",
 *   admin_label = @Translation("Gestion Documentaire - Vue simplifiée"),
 * )
 */
class SimpleNavBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected $languageManager;
  protected $logger;

  public function __construct(array $configuration, $plugin_id, $plugin_definition,
    LanguageManagerInterface $language_manager, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->languageManager = $language_manager;
    $this->logger = $logger;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static($configuration, $plugin_id, $plugin_definition,
      $container->get('language_manager'),
      $container->get('logger.factory')->get('gestion_documentaire')
    );
  }

  public function build() {
    $id_char_version = $this->configuration['id_char_version']
      ?? \Drupal::request()->attributes->get('id_char_version')
      ?? \Drupal::request()->query->get('dossier');

    if (!$id_char_version) {
      return ['#markup' => ''];
    }

    $tid = $this->resolveTid($id_char_version);

    if (!$tid) {
      return ['#markup' => ''];
    }

    $id_char_version = 'taxonomy_' . $tid;

    // ── Libellé racine ───────────────────────────────────────────────
    $root_label = $id_char_version;
    $term = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->load($tid);
    if ($term) {
      $langcode = $this->languageManager->getCurrentLanguage()->getId();
      if ($term->hasTranslation($langcode)) {
        $term = $term->getTranslation($langcode);
      }
      elseif ($term->hasTranslation('fr')) {
        $term = $term->getTranslation('fr');
      }
      $root_label = $term->getName();
    }

    // ── Vérification des permissions ─────────────────────────────────
    $account    = \Drupal::currentUser();
    $user_roles = $account->getRoles();
    $is_admin   = in_array('admin_gestiondocumentaire', $user_roles, TRUE)
               || in_array('administrator', $user_roles, TRUE);

    $can_view = $can_edit = $can_delete = $can_edit_role = 0;

    if ($is_admin) {
      $can_view = $can_edit = $can_delete = $can_edit_role = 1;
    }
    else {
      $connection = $this->getDatabaseConnection();
      if ($connection) {
        $role_data   = $this->prepareRolePlaceholders($user_roles);
        $parent_perm = $this->getParentPermissions($connection, $tid, $role_data);
        if ($parent_perm) {
          $can_view      = (int) $parent_perm->can_view;
          $can_edit      = (int) $parent_perm->can_edit;
          $can_delete    = (int) $parent_perm->can_delete;
          $can_edit_role = (int) $parent_perm->can_edit_role;
        }
      }
    }

    $search_url = Url::fromRoute('gestion_documentaire.search')
      ->setAbsolute(FALSE)->toString();

    return [
      '#theme' => 'simple_nav_template',
      '#is_admin'   => $is_admin,
      '#can_view'   => $can_view,
      '#can_edit'   => $can_edit,
      '#can_delete' => $can_delete,
      '#attached' => [
        'library' => ['gestion_documentaire/simple_nav_block'],
        'drupalSettings' => [
          'gestion_documentaire' => [
            'simple_nav' => [
              'id_char_version' => $id_char_version,
              'root_label'      => $root_label,
              'search_url'      => $search_url,
              'is_admin'        => $is_admin,
              'can_view'        => $can_view,
              'can_edit'        => $can_edit,
              'can_delete'      => $can_delete,
              'can_edit_role'   => $can_edit_role,
            ],
          ],
        ],
      ],
      '#cache' => [
        'max-age'  => 0,
        'contexts' => ['user.roles'],
      ],
    ];
  }

  /**
   * Résout un tid depuis taxonomy_123, 123, ou un nom texte.
   */
  protected function resolveTid(string $id_char_version): ?int {

    if (preg_match('/^(?:taxonomy_)?(\d+)$/', $id_char_version, $matches)) {
      return (int) $matches[1];
    }

    try {
      $terms = \Drupal::entityTypeManager()
        ->getStorage('taxonomy_term')
        ->loadByProperties(['name' => $id_char_version]);

      if (!empty($terms)) {
        return (int) array_key_first($terms);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('resolveTid failed: @message', ['@message' => $e->getMessage()]);
    }

    return NULL;
  }

  /**
   * Connexion à la base Drupal 9 contenant les taxonomies / permissions.
   */
  protected function getDatabaseConnection() {
    try {
      Database::setActiveConnection('drupal9');
      return Database::getConnection();
    }
    catch (\Exception $e) {
      $this->logger->error('Database connection failed: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Prépare les placeholders SQL pour les rôles courants.
   */
  protected function prepareRolePlaceholders(array $user_roles): array {
    $placeholders = [];
    $bindings     = [];
    foreach ($user_roles as $index => $role) {
      $ph             = ':role' . $index;
      $placeholders[] = $ph;
      $bindings[$ph]  = $role;
    }
    return ['placeholders' => $placeholders, 'bindings' => $bindings];
  }

  /**
   * Remonte la hiérarchie taxonomique et récupère le premier niveau
   * porteur d'une permission pour les rôles de l'utilisateur.
   * Reprise de HomeBlock::getParentPermissions().
   */
  protected function getParentPermissions($connection, int $tid, array $role_data) {
    if (empty($role_data['placeholders'])) {
      return NULL;
    }

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
      if (empty($results)) {
        return NULL;
      }

      // On retourne le premier niveau qui a au moins une permission.
      foreach ($results as $result) {
        if ($result->can_view || $result->can_edit || $result->can_delete || $result->can_edit_role) {
          return $result;
        }
      }

      // Sinon le plus profond (aucune perm, mais on garde la structure).
      return end($results);
    }
    catch (\Exception $e) {
      $this->logger->error('Parent permissions query failed: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

}