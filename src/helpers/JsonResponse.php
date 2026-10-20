<?php
namespace Helpers;

class JsonResponse {
    public static function success($data, $status = 200, $message = null) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'errors' => null
        ]);
        exit;
    }

    public static function error($message, $status = 400, $errors = null) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'data' => null,
            'message' => $message,
            'errors' => $errors
        ]);
        exit;
    }
}