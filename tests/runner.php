<?php

declare(strict_types=1);

/**
 * Runner de tests minimal — sans dépendance externe.
 *
 * Usage : php tests/runner.php
 *
 * En CI avec `composer install` : vendor/bin/phpunit
 */

// ─── Stubs ───────────────────────────────────────────────────────────────────
require_once __DIR__ . '/stubs/phpunit_stubs.php';
require_once __DIR__ . '/stubs/symfony_stubs.php';
require_once __DIR__ . '/stubs/drupal_stubs.php';

// ─── Autoloader PSR-4 minimal ────────────────────────────────────────────────
spl_autoload_register(function (string $class): void {
  $map = [
    'Drupal\\gestion_documentaire\\'       => __DIR__ . '/../modules/gestion_documentaire/src/',
    'Drupal\\Tests\\gestion_documentaire\\' => __DIR__ . '/../modules/gestion_documentaire/tests/src/',
  ];

  foreach ($map as $prefix => $base) {
    if (str_starts_with($class, $prefix)) {
      $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
      if (file_exists($file)) {
        require_once $file;
        return;
      }
    }
  }
});

// ─── Découverte + exécution des tests ────────────────────────────────────────
use Drupal\Tests\gestion_documentaire\Unit\Controller\SearchControllerTest;
use PHPUnit\Framework\AssertionFailedError;

// Méthodes à exclure (helpers et assertions héritées)
$excluded = [
  'setUp', 'tearDown', 'createMock', 'getMockBuilder',
  'assertTrue', 'assertFalse', 'assertSame', 'assertNull',
  'assertEmpty', 'assertNotEmpty', 'assertLessThan', 'assertGreaterThan',
  'assertContains', 'assertNotContains', 'assertArrayHasKey', 'assertArrayNotHasKey',
  'assertCount', 'assertInstanceOf', 'assertStringContainsString',
  'assertStringNotContainsString',
];

$ref     = new ReflectionClass(SearchControllerTest::class);
$methods = array_filter(
  $ref->getMethods(ReflectionMethod::IS_PUBLIC),
  fn($m) => $m->getDeclaringClass()->getName() === SearchControllerTest::class
         && !str_starts_with($m->getName(), '__')
         && !str_starts_with($m->getName(), 'call')
         && !in_array($m->getName(), $excluded)
);

$pass = 0;
$fail = 0;

echo "\n\033[1mSearchController — Tests unitaires\033[0m\n";
echo str_repeat('─', 64) . "\n";

foreach ($methods as $method) {
  $name     = $method->getName();
  $instance = new SearchControllerTest();
  $instance->setUp();

  try {
    $instance->{$name}();
    echo "\033[32m ✓\033[0m  $name\n";
    $pass++;
  } catch (AssertionFailedError $e) {
    echo "\033[31m ✗\033[0m  $name\n";
    foreach (explode("\n", $e->getMessage()) as $line) {
      echo "      $line\n";
    }
    $fail++;
  } catch (\Throwable $e) {
    echo "\033[31m ✗\033[0m  $name\n";
    echo "      [" . get_class($e) . "] " . $e->getMessage() . "\n";
    echo "      " . $e->getFile() . ':' . $e->getLine() . "\n";
    $fail++;
  }
}

echo str_repeat('─', 64) . "\n";
$color = $fail === 0 ? "\033[32m" : "\033[31m";
echo "{$color}\033[1mRésultat : $pass passé(s), $fail échoué(s)\033[0m\n\n";

exit($fail > 0 ? 1 : 0);
