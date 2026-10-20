<?php
namespace Core;

class Request {
    public $method;
    public $path;
    public $query;
    public $body;

    public static function capture() {
        $request = new self();
        $request->method = $_SERVER['REQUEST_METHOD'];
        $request->path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $request->query = $_GET;
        $request->body = json_decode(file_get_contents('php://input'), true) ?? [];
        return $request;
    }

    public function input($key, $default = null) {
        return $this->body[$key] ?? $default;
    }

    public function query($key, $default = null) {
        return $this->query[$key] ?? $default;
    }
}