<?php
namespace Helpers;

use Core\Database;

class Auth {
    public static function check() {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        if (strpos($authHeader, 'Bearer ') === 0) {
            $token = substr($authHeader, 7);
            $stmt = Database::getConnection()->prepare(
                "SELECT id_funcionario, tipo_usuario FROM tb_tokens WHERE token = :token AND expira_em > NOW()"
            );
            $stmt->execute([':token' => $token]);
            $tokenData = $stmt->fetch();
            if ($tokenData) {
                return [
                    'id' => $tokenData['id_funcionario'],
                    'tipo' => $tokenData['tipo_usuario']
                ];
            }
        }
        return false;
    }

    public static function requireAuth() {
        $user = self::check();
        if (!$user) {
            \Helpers\JsonResponse::error('Não autenticado', 401);
        }
        return $user;
    }

    public static function requireRole($tipo) {
        $user = self::requireAuth();
        if ($user['tipo'] !== $tipo && $user['tipo'] != 'admin') {
            \Helpers\JsonResponse::error('Acesso negado', 403);
        }
        return $user;
    }
}