<?php
namespace Core;

class Router {
    private $routes = [];

    public function get($path, $action) {
        $this->routes['GET'][$path] = $action;
    }

    public function post($path, $action) {
        $this->routes['POST'][$path] = $action;
    }

    public function put($path, $action) {
        $this->routes['PUT'][$path] = $action;
    }

    public function delete($path, $action) {
        $this->routes['DELETE'][$path] = $action;
    }

    public function dispatch(Request $request) {
        $method = $request->method;
        $path = $request->path;
        //var_dump($request);
        if (isset($this->routes[$method][$path])) {
            $action = $this->routes[$method][$path];
            list($controller, $methodName) = explode('@', $action);
            $controllerClass = "Controllers\\$controller";
            $controllerInstance = new $controllerClass();
            $controllerInstance->$methodName($request);
        } else {
            \Helpers\JsonResponse::error('Rota não encontrada', 404);
        }
    }
}