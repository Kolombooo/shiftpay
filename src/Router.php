<?php
declare(strict_types=1);

final class Router
{
    /** @var array<int, array{0:string,1:string,2:callable}> */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        $this->routes[] = [$method, '#^' . $pattern . '$#', $handler];
    }

    public function dispatch(string $method, string $path): void
    {
        $pathMatched = false;
        foreach ($this->routes as [$m, $re, $handler]) {
            if (preg_match($re, $path, $mt)) {
                $pathMatched = true;
                if ($m === $method) {
                    $handler(...array_slice($mt, 1));
                    return;
                }
            }
        }
        json_error($pathMatched ? 405 : 404, $pathMatched ? 'Method not allowed.' : 'Not found.');
    }
}
