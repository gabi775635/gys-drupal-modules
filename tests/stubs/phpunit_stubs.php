<?php

declare(strict_types=1);

namespace PHPUnit\Framework {

  class AssertionFailedError extends \RuntimeException {}

  abstract class TestCase {

    public function setUp(): void {}
    public function tearDown(): void {}

    protected function assertSame(mixed $expected, mixed $actual, string $msg = ''): void {
      if ($expected !== $actual) {
        throw new AssertionFailedError(
          ($msg ? "$msg\n" : '') .
          "assertSame FAILED\n  Expected: " . var_export($expected, true) .
          "\n  Actual  : " . var_export($actual, true)
        );
      }
    }

    protected function assertEmpty(mixed $val, string $msg = ''): void {
      if (!empty($val)) {
        throw new AssertionFailedError(($msg ?: 'assertEmpty FAILED') . ': ' . var_export($val, true));
      }
    }

    protected function assertTrue(mixed $val, string $msg = ''): void {
      if ($val !== true) {
        throw new AssertionFailedError(($msg ?: 'assertTrue FAILED') . ': ' . var_export($val, true));
      }
    }

    protected function assertFalse(mixed $val, string $msg = ''): void {
      if ($val !== false) {
        throw new AssertionFailedError(($msg ?: 'assertFalse FAILED') . ': ' . var_export($val, true));
      }
    }

    protected function assertNull(mixed $val, string $msg = ''): void {
      if ($val !== null) {
        throw new AssertionFailedError(($msg ?: 'assertNull FAILED') . ': ' . var_export($val, true));
      }
    }

    protected function assertLessThan(mixed $expected, mixed $actual, string $msg = ''): void {
      if (!($actual < $expected)) {
        throw new AssertionFailedError(
          ($msg ?: 'assertLessThan FAILED') . ": expected $actual < $expected"
        );
      }
    }

    protected function assertGreaterThan(mixed $expected, mixed $actual, string $msg = ''): void {
      if (!($actual > $expected)) {
        throw new AssertionFailedError(
          ($msg ?: 'assertGreaterThan FAILED') . ": expected $actual > $expected"
        );
      }
    }

    protected function assertContains(mixed $needle, array $haystack, string $msg = ''): void {
      if (!in_array($needle, $haystack, true)) {
        throw new AssertionFailedError(
          ($msg ?: 'assertContains FAILED') . ": " . var_export($needle, true) . " not in array"
        );
      }
    }

    protected function assertNotContains(mixed $needle, array $haystack, string $msg = ''): void {
      if (in_array($needle, $haystack, true)) {
        throw new AssertionFailedError(
          ($msg ?: 'assertNotContains FAILED') . ": " . var_export($needle, true) . " found in array"
        );
      }
    }

    protected function assertArrayHasKey(mixed $key, array $arr, string $msg = ''): void {
      if (!array_key_exists($key, $arr)) {
        throw new AssertionFailedError(($msg ?: 'assertArrayHasKey FAILED') . ": key '$key' missing");
      }
    }

    protected function assertArrayNotHasKey(mixed $key, array $arr, string $msg = ''): void {
      if (array_key_exists($key, $arr)) {
        throw new AssertionFailedError(($msg ?: 'assertArrayNotHasKey FAILED') . ": key '$key' present");
      }
    }

    protected function assertCount(int $expected, array|\Countable $actual, string $msg = ''): void {
      $c = count($actual);
      if ($c !== $expected) {
        throw new AssertionFailedError(
          ($msg ?: 'assertCount FAILED') . ": expected $expected, got $c"
        );
      }
    }

    protected function assertInstanceOf(string $class, mixed $actual, string $msg = ''): void {
      if (!($actual instanceof $class)) {
        $got = is_object($actual) ? get_class($actual) : gettype($actual);
        throw new AssertionFailedError(
          ($msg ?: 'assertInstanceOf FAILED') . ": expected $class, got $got"
        );
      }
    }

    protected function assertStringContainsString(string $needle, string $haystack, string $msg = ''): void {
      if (!str_contains($haystack, $needle)) {
        throw new AssertionFailedError(
          ($msg ?: 'assertStringContainsString FAILED') . ": '$needle' not found"
        );
      }
    }

    protected function assertStringNotContainsString(string $needle, string $haystack, string $msg = ''): void {
      if (str_contains($haystack, $needle)) {
        throw new AssertionFailedError(
          ($msg ?: 'assertStringNotContainsString FAILED') . ": '$needle' found"
        );
      }
    }

    // ── Mock factory ──────────────────────────────────────────────────────────

    protected function createMock(string $class): object {
      return new SimpleMock([]);
    }

    protected function getMockBuilder(string $class): MockBuilder {
      return new MockBuilder($class);
    }
  }

  // ── Objets mock minimalistes ─────────────────────────────────────────────

  class SimpleMock {
    private array $returns;

    public function __construct(array $returns) {
      $this->returns = $returns;
    }

    public function method(string $name): MethodExpectation {
      return new MethodExpectation($this, $name);
    }

    public function _setReturn(string $name, mixed $value): void {
      $this->returns[$name] = $value;
    }

    public function __call(string $name, array $args): mixed {
      return $this->returns[$name] ?? null;
    }
  }

  class MethodExpectation {
    public function __construct(
      private SimpleMock $mock,
      private string     $method,
    ) {}

    public function willReturn(mixed $value): void {
      $this->mock->_setReturn($this->method, $value);
    }
  }

  class MockBuilder {
    private array $extraMethods = [];

    public function __construct(private string $class) {}

    public function addMethods(array $methods): static {
      $this->extraMethods = $methods;
      return $this;
    }

    public function getMock(): SimpleMock {
      return new SimpleMock([]);
    }
  }
}
