<?php

declare(strict_types=1);

namespace Drupal\Core\Controller {

  if (!class_exists(\Drupal\Core\Controller\ControllerBase::class)) {
    abstract class ControllerBase {}
  }
}

namespace Drupal\Core {

  if (!class_exists(\Drupal\Core\Url::class)) {
    class Url {
      private string $route;
      private array  $params;

      private function __construct(string $route, array $params = []) {
        $this->route  = $route;
        $this->params = $params;
      }

      public static function fromRoute(string $route, array $params = []): self {
        return new self($route, $params);
      }

      public function toString(): string {
        $qs = http_build_query($this->params);
        return '/stub/' . str_replace('.', '/', $this->route) . ($qs ? '?' . $qs : '');
      }
    }
  }
}

namespace Drupal\Core\Database {

  if (!class_exists(\Drupal\Core\Database\Database::class)) {
    class Database {
      public static function setActiveConnection(string $key): void {}

      public static function getConnection(): object {
        return new class {
          public function query(string $sql, array $args = []): object {
            return new class {
              public function fetchAll(): array { return []; }
              public function fetchField(): mixed { return null; }
            };
          }
        };
      }
    }
  }
}

// Namespace global pour les fonctions Drupal
namespace {

  if (!function_exists('\\Drupal')) {
    // placeholder — les méthodes appelant \Drupal::* sont mockées dans les tests
  }
}
