<?php
namespace Core;

class Router {
    private $routes = [];

    public function get($path, $action)    { $this->addRoute('GET', $path, $action); }
    public function post($path, $action)   { $this->addRoute('POST', $path, $action); }
    public function put($path, $action)    { $this->addRoute('PUT', $path, $action); }
    public function delete($path, $action) { $this->addRoute('DELETE', $path, $action); }

    private function addRoute($method, $path, $action) {
        // Converte {id} -> (?P<id>[^/]+)
        $pattern = preg_replace('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', '(?P<$1>[^/]+)', $path);
        $pattern = '#^' . $pattern . '$#';

        $this->routes[$method][] = [
            'pattern' => $pattern,
            'action'  => $action,
        ];
    }

    public function dispatch(Request $request) {
        $method = $request->method;
        $path   = $request->path;

        if (!isset($this->routes[$method])) {
            \Helpers\JsonResponse::error('Rota não encontrada', 404);
            return;
        }

        foreach ($this->routes[$method] as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                // Extrai só os parâmetros nomeados
                $params = [];
                foreach ($matches as $key => $value) {
                    if (!is_int($key)) {
                        $params[$key] = $value;
                    }
                }

                // Injeta os params no request (se seu Request permitir)
                if (method_exists($request, 'setParams')) {
                    $request->setParams($params);
                } else {
                    $request->params = $params;
                }

                list($controller, $methodName) = explode('@', $route['action']);
                $controllerClass = "Controllers\\$controller";
                $controllerInstance = new $controllerClass();
                $controllerInstance->$methodName($request);
                return;
            }
        }

        \Helpers\JsonResponse::error('Rota não encontrada', 404);
    }
}