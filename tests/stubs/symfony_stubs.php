<?php

declare(strict_types=1);

namespace Symfony\Component\HttpFoundation {

  class ParameterBag {
    public function __construct(private array $params = []) {}

    public function get(string $key, mixed $default = null): mixed {
      return $this->params[$key] ?? $default;
    }
  }

  class Request {
    public ParameterBag $query;

    private function __construct(array $query) {
      $this->query = new ParameterBag($query);
    }

    public static function create(string $uri, string $method = 'GET', array $parameters = []): self {
      return new self($parameters);
    }
  }

  class JsonResponse {
    public function __construct(
      private readonly mixed $data,
      private readonly int   $status = 200,
    ) {}

    public function getStatusCode(): int {
      return $this->status;
    }

    public function getContent(): string {
      return json_encode($this->data, JSON_THROW_ON_ERROR);
    }
  }
}
