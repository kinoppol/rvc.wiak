<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<int,array{method:string,pattern:string,regex:string,params:array,handler:callable|array}> */
    private array $routes = [];

    public function get(string $path, callable|array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    private function add(string $method, string $path, callable|array $handler): void
    {
        $paramNames = [];
        $regex = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', function ($m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return '([^/]+)';
        }, $path);
        $this->routes[] = [
            'method' => $method,
            'regex' => '#^' . $regex . '$#',
            'params' => $paramNames,
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $path): void
    {
        foreach ($this->routes as $route) {
            if ($route['method'] !== $method) {
                continue;
            }
            if (preg_match($route['regex'], $path, $m)) {
                $args = [];
                foreach ($route['params'] as $i => $name) {
                    $args[$name] = $m[$i + 1];
                }
                $handler = $route['handler'];
                if (is_array($handler)) {
                    [$class, $action] = $handler;
                    $controller = new $class();
                    $controller->$action($args);
                } else {
                    $handler($args);
                }
                return;
            }
        }
        http_response_code(404);
        if (Request::isAjax()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'ไม่พบหน้าที่ร้องขอ'], JSON_UNESCAPED_UNICODE);
            return;
        }
        echo '<!doctype html><meta charset="utf-8"><body style="font-family:sans-serif;text-align:center;margin-top:80px"><h1>404</h1><p>ไม่พบหน้าที่ร้องขอ</p><a href="./">กลับหน้าแรก</a></body>';
    }
}
