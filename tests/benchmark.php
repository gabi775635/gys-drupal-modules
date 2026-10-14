<?php

declare(strict_types=1);

/**
 * Benchmark SearchController — mesure de performance avant/après.
 *
 * Usage : php tests/benchmark.php [--baseline] [--compare]
 *
 *   --baseline   Enregistre les résultats comme référence (avant)
 *   --compare    Compare les résultats courants avec la référence enregistrée
 *   (aucun flag) Affiche les résultats et vérifie les seuils
 *
 * Sortie : tableau lisible + fichier JSON optionnel dans tests/perf/
 */

// ─── Stubs ───────────────────────────────────────────────────────────────────
require_once __DIR__ . '/stubs/phpunit_stubs.php';
require_once __DIR__ . '/stubs/symfony_stubs.php';
require_once __DIR__ . '/stubs/drupal_stubs.php';

// ─── Autoloader PSR-4 ────────────────────────────────────────────────────────
spl_autoload_register(function (string $class): void {
  $map = [
    'Drupal\\gestion_documentaire\\'       => __DIR__ . '/../modules/gestion_documentaire/src/',
    'Drupal\\Tests\\gestion_documentaire\\' => __DIR__ . '/../modules/gestion_documentaire/tests/src/',
  ];
  foreach ($map as $prefix => $base) {
    if (str_starts_with($class, $prefix)) {
      $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
      if (file_exists($file)) { require_once $file; return; }
    }
  }
});

use Drupal\Tests\gestion_documentaire\Unit\Controller\SearchControllerTest;
use Drupal\Tests\gestion_documentaire\Unit\Controller\TestableSearchController;

// ─── Options CLI ─────────────────────────────────────────────────────────────
$args     = array_slice($argv, 1);
$baseline = in_array('--baseline', $args, true);
$compare  = in_array('--compare',  $args, true);
$baseFile = __DIR__ . '/perf/baseline.json';

// ─── Contrôleur testable ─────────────────────────────────────────────────────
// TestableSearchController expose les méthodes protégées en public via des call*()
require_once __DIR__ . '/../modules/gestion_documentaire/tests/src/Unit/Controller/SearchControllerTest.php';
$ctrl = new TestableSearchController();

// ─── Jeux de données réalistes ───────────────────────────────────────────────

// 50 nœuds de taxonomie imbriqués (tous les champs requis par buildNodesFromTree)
$treeRows = [];
for ($i = 1; $i <= 50; $i++) {
  $treeRows[] = (object)[
    'tid'        => $i,
    'name'       => "Dossier $i",
    'langcode'   => ($i % 3 === 0) ? 'en' : 'fr',
    'parent'     => ($i <= 5) ? 0 : (($i - 1) % 10 + 1),
    'parent_tid' => ($i <= 5) ? 0 : (($i - 1) % 10 + 1),
    'depth'      => ($i <= 5) ? 0 : -1,
    'weight'     => $i % 5,
    'created'    => 1700000000 + $i,
    'changed'    => 1710000000 + $i,
    'author_uid' => $i % 10 + 1,
  ];
}
// Doublons pour tester la priorité langcode
for ($i = 1; $i <= 10; $i++) {
  $treeRows[] = (object)[
    'tid'        => $i,
    'name'       => "Dossier $i (fr)",
    'langcode'   => 'fr',
    'parent'     => 0,
    'parent_tid' => 0,
    'depth'      => 0,
    'weight'     => 0,
    'created'    => 1700000000,
    'changed'    => 1710000000,
    'author_uid' => 1,
  ];
}

// Résultats mixtes pour mergeAndSort (100 taxo + 100 media)
$taxoResults  = [];
$mediaResults = [];
for ($i = 1; $i <= 100; $i++) {
  $taxoResults[]  = [
    'type'    => 'taxonomy',
    'name'    => "Dossier $i",
    'changed' => strtotime("2024-0$((($i % 9) + 1))-01"),
  ];
  $mediaResults[] = [
    'type'     => 'media',
    'name'     => "Fichier $i.pdf",
    'filesize' => $i * 1024,
    'changed'  => strtotime("2024-0$((($i % 9) + 1))-15"),
    'langcode' => ($i % 2 === 0) ? 'fr' : 'en',
  ];
}

// Graphe de nœuds pour propagation (50 nœuds chaînés)
$propagNodes = [];
for ($i = 1; $i <= 50; $i++) {
  $propagNodes[$i] = [
    'tid'               => $i,
    'parent_tid'        => ($i === 1) ? 0 : $i - 1,
    'can_view'          => 0,
    'role_rights'       => [],
    'visible'           => false,
    'visible_via_child' => false,
    'children'          => [],
  ];
}
// Un nœud visible en milieu de chaîne
$propagNodes[25]['visible'] = true;

// Paramètres de requête réalistes
$requestParams = [
  'id_char_version' => 'dossier-42',
  'page'            => '2',
  'per_page'        => '20',
  'search'          => 'rapport annuel',
  'ext'             => 'pdf,docx',
  'lang'            => 'fr',
  'sort'            => 'name',
  'sort_dir'        => 'asc',
];

$langOrder = ['fr', 'uk', 'de', 'es', 'pt', 'it', 'nl', 'pl', 'cz', 'ro'];

// ─── Définition des benchmarks ───────────────────────────────────────────────

$benchmarks = [

  // ── Parsing / validation ──────────────────────────────────────────────────
  [
    'name'      => 'parseSearchParams',
    'n'         => 5000,
    'threshold' => 0.050, // ms par appel
    'fn'        => function () use ($ctrl, $requestParams) {
      $req = \Symfony\Component\HttpFoundation\Request::create('/', 'GET', $requestParams);
      $ctrl->callParseSearchParams($req);
    },
  ],

  // ── Lang helpers ─────────────────────────────────────────────────────────
  [
    'name'      => 'langTokens',
    'n'         => 10000,
    'threshold' => 0.020,
    'fn'        => function () use ($ctrl) {
      $ctrl->callLangTokens('fr, en, de');
    },
  ],
  [
    'name'      => 'langScore',
    'n'         => 10000,
    'threshold' => 0.010,
    'fn'        => function () use ($ctrl) {
      $ctrl->callLangScore('fr', 'fr');
    },
  ],
  [
    'name'      => 'langRank (multi-value)',
    'n'         => 5000,
    'threshold' => 0.020,
    'fn'        => function () use ($ctrl, $langOrder) {
      $ctrl->callLangRank('fr, en', $langOrder);
    },
  ],
  [
    'name'      => 'compareLangValues',
    'n'         => 5000,
    'threshold' => 0.050,
    'fn'        => function () use ($ctrl, $langOrder) {
      $a = ['langcode' => 'fr', 'name' => 'A'];
      $b = ['langcode' => 'en', 'name' => 'B'];
      $ctrl->callCompareLangValues('fr', 'en', $langOrder, false);
    },
  ],

  // ── Construction SQL ─────────────────────────────────────────────────────
  [
    'name'      => 'buildMediaWhereClause',
    'n'         => 5000,
    'threshold' => 0.050,
    'fn'        => function () use ($ctrl) {
      $params = [
        'search'   => 'rapport annuel',
        'ext'      => 'pdf,docx',
        'lang'     => 'fr,en',
        'date'     => '2024-01-01',
        'has_filters' => true,
      ];
      $ctrl->callBuildMediaWhereClause($params, [1, 2, 3, 4, 5]);
    },
  ],
  [
    'name'      => 'buildMediaOrderBy',
    'n'         => 5000,
    'threshold' => 0.050,
    'fn'        => function () use ($ctrl, $langOrder) {
      $ctrl->callBuildMediaOrderBy('lang', 'asc', $langOrder);
    },
  ],

  // ── Traitement données ────────────────────────────────────────────────────
  [
    'name'      => 'buildNodesFromTree (50 rows)',
    'n'         => 500,
    'threshold' => 2.000,
    'fn'        => function () use ($ctrl, $treeRows) {
      $ctrl->callBuildNodesFromTree($treeRows, 'fr');
    },
  ],
  [
    'name'      => 'mergeAndSort (100+100)',
    'n'         => 500,
    'threshold' => 5.000,
    'fn'        => function () use ($ctrl, $taxoResults, $mediaResults, $langOrder) {
      $ctrl->callMergeAndSort($taxoResults, $mediaResults, 'name', 'asc', $langOrder);
    },
  ],
  [
    'name'      => 'propagateDownward (50 nodes)',
    'n'         => 1000,
    'threshold' => 1.000,
    'fn'        => function () use ($ctrl, $propagNodes) {
      $nodes = $propagNodes;
      $descendantMap = [];
      for ($i = 1; $i <= 50; $i++) {
        $descendantMap[$i] = range($i + 1, min($i + 5, 50));
      }
      $ctrl->callPropagateDownward($nodes, 1);
    },
  ],
  [
    'name'      => 'propagateVisibleViaChild (50 nodes)',
    'n'         => 1000,
    'threshold' => 1.000,
    'fn'        => function () use ($ctrl, $propagNodes) {
      $nodes = $propagNodes;
      $ctrl->callPropagateVisibleViaChild($nodes, 25);
    },
  ],
];

// ─── Exécution ───────────────────────────────────────────────────────────────

function runBenchmark(array $bench): array
{
  $fn = $bench['fn'];
  $n  = $bench['n'];

  // Warmup
  for ($i = 0; $i < min(50, (int)($n * 0.1)); $i++) { $fn(); }

  $memBefore = memory_get_usage(true);
  $start     = hrtime(true);

  for ($i = 0; $i < $n; $i++) { $fn(); }

  $elapsed   = (hrtime(true) - $start) / 1e6; // ms totales
  $memAfter  = memory_get_usage(true);
  $avgMs     = $elapsed / $n;
  $memDeltaKb = max(0, ($memAfter - $memBefore)) / 1024;

  return [
    'name'       => $bench['name'],
    'n'          => $n,
    'total_ms'   => round($elapsed, 3),
    'avg_ms'     => round($avgMs, 6),
    'threshold'  => $bench['threshold'],
    'mem_kb'     => round($memDeltaKb, 1),
    'ok'         => $avgMs <= $bench['threshold'],
  ];
}

echo "\n\033[1mSearchController — Benchmarks de performance\033[0m\n";
echo str_repeat('─', 76) . "\n";
printf(
  "%-34s %8s %8s %8s %7s  %s\n",
  'Méthode', 'Avg/call', 'N', 'Total', 'Mém', 'Seuil'
);
echo str_repeat('─', 76) . "\n";

$results = [];
$failed  = 0;

foreach ($benchmarks as $bench) {
  $r         = runBenchmark($bench);
  $results[] = $r;

  $avgDisp  = $r['avg_ms'] < 1
    ? sprintf('%.1f µs', $r['avg_ms'] * 1000)
    : sprintf('%.2f ms', $r['avg_ms']);
  $totDisp  = sprintf('%.1f ms', $r['total_ms']);
  $thDisp   = $r['threshold'] < 1
    ? sprintf('%.0f µs', $r['threshold'] * 1000)
    : sprintf('%.1f ms', $r['threshold']);
  $memDisp  = $r['mem_kb'] > 0
    ? sprintf('%+.0f KB', $r['mem_kb'])
    : '  — ';
  $status   = $r['ok'] ? "\033[32m✓\033[0m" : "\033[31m✗\033[0m";

  if (!$r['ok']) $failed++;

  printf(
    "%s %-33s %8s %8s %8s %7s  ≤ %s\n",
    $status,
    $r['name'],
    $avgDisp,
    number_format($r['n']),
    $totDisp,
    $memDisp,
    $thDisp
  );
}

echo str_repeat('─', 76) . "\n";

// ─── Comparaison avant/après ─────────────────────────────────────────────────

if ($compare && file_exists($baseFile)) {
  $base = json_decode(file_get_contents($baseFile), true);
  echo "\n\033[1mComparaison avant → après\033[0m\n";
  echo str_repeat('─', 60) . "\n";
  printf("%-34s %10s %10s %8s\n", 'Méthode', 'Avant', 'Après', 'Δ');
  echo str_repeat('─', 60) . "\n";

  foreach ($results as $r) {
    $prev = null;
    foreach ($base['results'] as $b) {
      if ($b['name'] === $r['name']) { $prev = $b; break; }
    }
    if ($prev === null) { continue; }

    $delta  = $r['avg_ms'] - $prev['avg_ms'];
    $pct    = $prev['avg_ms'] > 0 ? ($delta / $prev['avg_ms']) * 100 : 0;
    $color  = $delta > 0 ? "\033[31m" : ($delta < 0 ? "\033[32m" : "");
    $reset  = "\033[0m";

    $fmt = fn($ms) => $ms < 1 ? sprintf('%.1f µs', $ms * 1000) : sprintf('%.3f ms', $ms);

    printf(
      "  %-33s %10s %10s  %s%+.1f%%$reset\n",
      $r['name'],
      $fmt($prev['avg_ms']),
      $fmt($r['avg_ms']),
      $color,
      $pct
    );
  }
  echo str_repeat('─', 60) . "\n";
  $prevDate = $base['recorded_at'] ?? '(inconnu)';
  echo "  Baseline enregistrée le : $prevDate\n";
}

// ─── Sauvegarde baseline ──────────────────────────────────────────────────────

if ($baseline) {
  if (!is_dir(dirname($baseFile))) {
    mkdir(dirname($baseFile), 0755, true);
  }
  file_put_contents($baseFile, json_encode([
    'recorded_at' => date('Y-m-d H:i:s'),
    'results'     => $results,
  ], JSON_PRETTY_PRINT));
  echo "\n\033[33mBaseline enregistrée → tests/perf/baseline.json\033[0m\n";
}

// ─── Résumé ───────────────────────────────────────────────────────────────────

$pass = count($results) - $failed;
$color = $failed === 0 ? "\033[32m" : "\033[31m";
echo "\n{$color}\033[1mRésultat : $pass passé(s), $failed dépassement(s) de seuil\033[0m\n\n";

exit($failed > 0 ? 1 : 0);
