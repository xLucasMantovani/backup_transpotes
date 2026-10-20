<?php
namespace Controllers;

use Core\Database;
use Helpers\JsonResponse;
use Helpers\Auth;
use Core\Request;

class AuthController {

    public function login(Request $request) {
        $cpf = $request->input('cpf');
        $senha = $request->input('senha');

        if (!$cpf || !$senha) {
            JsonResponse::error('CPF e senha são obrigatórios', 422);
        }

        // Busca funcionário pelo CPF no JSON
        $stmt = Database::getConnection()->prepare(
            "SELECT id_funcionario, dados_funcionario 
             FROM tb_funcionarios 
             WHERE JSON_UNQUOTE(JSON_EXTRACT(dados_funcionario, '$.cpf')) = :cpf"
        );
        $stmt->execute([':cpf' => $cpf]);
        $funcionario = $stmt->fetch();

        if (!$funcionario) {
            JsonResponse::error('Credenciais inválidas', 401);
        }

        $dados = json_decode($funcionario['dados_funcionario'], true);
        $senhaHash = $dados['senha_hash'] ?? '';

        if (!password_verify($senha, $senhaHash)) {
            JsonResponse::error('Credenciais inválidas', 401);
        }

        $tipo = self::getTipoUsuario($funcionario['id_funcionario']);
        if (!$tipo) {
            JsonResponse::error('Funcionário sem perfil definido', 403);
        }

        // Gera token
        $token = bin2hex(random_bytes(32));
        $expira = date('Y-m-d H:i:s', strtotime('+7 days'));

        $stmt = Database::getConnection()->prepare(
            "INSERT INTO tb_tokens (token, id_funcionario, tipo_usuario, expira_em) 
             VALUES (:token, :id, :tipo, :expira)"
        );
        $stmt->execute([
            ':token' => $token,
            ':id' => $funcionario['id_funcionario'],
            ':tipo' => $tipo,
            ':expira' => $expira
        ]);

        JsonResponse::success([
            'token' => $token,
            'tipo_usuario' => $tipo,
            'expira_em' => $expira
        ]);
    }

    public function logout(Request $request) {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? '';
        if (strpos($authHeader, 'Bearer ') === 0) {
            $token = substr($authHeader, 7);
            $stmt = Database::getConnection()->prepare("DELETE FROM tb_tokens WHERE token = :token");
            $stmt->execute([':token' => $token]);
        }
        JsonResponse::success(null, 200, 'Logout realizado');
    }

    private static function getTipoUsuario($idFuncionario) {
        $db = Database::getConnection();

        // Verifica se é motorista
        $stmt = $db->prepare("SELECT 1 FROM tb_motoristas WHERE id_motorista = :id");
        $stmt->execute([':id' => $idFuncionario]);
        if ($stmt->fetch()) {
            return 'motorista';
        }

        // Verifica se é técnico
        $stmt = $db->prepare("SELECT 1 FROM tb_tecnicos WHERE id_tecnico = :id");
        $stmt->execute([':id' => $idFuncionario]);
        if ($stmt->fetch()) {
            return 'tecnico';
        }

        // Se houver tabela de administradores, verificar aqui
        // Exemplo: $stmt = $db->prepare("SELECT 1 FROM tb_administradores WHERE id_admin = :id");
        return null;
    }
}