<?php
namespace Controllers;

use Core\Database;
use Helpers\JsonResponse;
use Helpers\Auth;
use Core\Request;

class ManutencaoController {

    public function index(Request $request) {
        Auth::requireAuth();

        $user = Auth::check();
        $sql = "SELECT mv.id_manutencao, mv.placa, mv.id_tecnico, mv.data_ini_manutencao,
                       mv.data_fim_manutencao, mv.descricao,
                       JSON_UNQUOTE(JSON_EXTRACT(f.dados_funcionario, '$.nome')) AS nome_tecnico,
                       JSON_UNQUOTE(JSON_EXTRACT(v.dados_veiculo, '$.modelo')) AS modelo_veiculo
                FROM tb_manutencao_veiculo mv
                JOIN tb_tecnicos t ON mv.id_tecnico = t.id_tecnico
                JOIN tb_funcionarios f ON t.id_tecnico = f.id_funcionario
                JOIN tb_veiculos v ON mv.placa = v.placa";

        $params = [];
        // Se técnico, vê apenas suas manutenções
        if ($user['tipo'] === 'tecnico') {
            $sql .= " WHERE mv.id_tecnico = :id_tecnico";
            $params[':id_tecnico'] = $user['id'];
        }

        // Filtros opcionais para admin
        if ($user['tipo'] === 'admin') {
            $where = [];
            if ($placa = $request->query('placa')) {
                $where[] = "mv.placa = :placa";
                $params[':placa'] = $placa;
            }
            if ($status = $request->query('status')) {
                if ($status === 'aberta') {
                    $where[] = "mv.data_fim_manutencao IS NULL";
                } elseif ($status === 'concluida') {
                    $where[] = "mv.data_fim_manutencao IS NOT NULL";
                }
            }
            if (!empty($where)) {
                $sql .= " WHERE " . implode(' AND ', $where);
            }
        }

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);
        $manutencoes = $stmt->fetchAll();

        JsonResponse::success($manutencoes);
    }

    public function show(Request $request) {
        Auth::requireAuth();
        $id = $request->param('id');

        $user = Auth::check();
        $sql = "SELECT mv.*, 
                       JSON_UNQUOTE(JSON_EXTRACT(f.dados_funcionario, '$.nome')) AS nome_tecnico
                FROM tb_manutencao_veiculo mv
                JOIN tb_tecnicos t ON mv.id_tecnico = t.id_tecnico
                JOIN tb_funcionarios f ON t.id_tecnico = f.id_funcionario
                WHERE mv.id_manutencao = :id";
        $params = [':id' => $id];
        if ($user['tipo'] === 'tecnico') {
            $sql .= " AND mv.id_tecnico = :id_tecnico";
            $params[':id_tecnico'] = $user['id'];
        }

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);
        $manutencao = $stmt->fetch();

        if (!$manutencao) {
            JsonResponse::error('Manutenção não encontrada', 404);
        }

        JsonResponse::success($manutencao);
    }

    public function store(Request $request) {
        Auth::requireRole('tecnico');
        $id_tecnico = $request->input('id_tecnico') ?? $user['id'];
        $placa = $request->input('placa');
        $descricao = $request->input('descricao');

        if (!$placa || !$descricao) {
            JsonResponse::error('Placa e descrição são obrigatórias', 422);
        }

        $db = Database::getConnection();

        // Verifica se veículo existe
        $stmt = $db->prepare("SELECT 1 FROM tb_veiculos WHERE placa = :placa");
        $stmt->execute([':placa' => $placa]);
        if (!$stmt->fetch()) {
            JsonResponse::error('Veículo não encontrado', 404);
        }

        $stmt = $db->prepare(
            "INSERT INTO tb_manutencao_veiculo (id_tecnico, placa, data_ini_manutencao, descricao)
             VALUES (:id_tecnico, :placa, NOW(), :descricao)"
        );
        $stmt->execute([
            ':id_tecnico' => $id_tecnico,
            ':placa' => $placa,
            ':descricao' => $descricao
        ]);

        JsonResponse::success(['id_manutencao' => $db->lastInsertId()], 201);
    }

    public function update(Request $request) {
        $user = Auth::requireAuth();
        $id = $request->param('id');
        // Localiza manutenção
        $stmt = Database::getConnection()->prepare("SELECT * FROM tb_manutencao_veiculo WHERE id_manutencao = :id");
        $stmt->execute([':id' => $id]);
        $manutencao = $stmt->fetch();
        if (!$manutencao) {
            JsonResponse::error('Manutenção não encontrada', 404);
        }

        // Permissão: técnico só pode alterar as próprias manutenções; admin pode todas
        if ($user['tipo'] === 'tecnico' && $manutencao['id_tecnico'] != $user['id']) {
            JsonResponse::error('Acesso negado', 403);
        }

        $updates = [];
        $params = [':id' => $id];

        // Campos atualizáveis: descricao, data_fim_manutencao (para finalizar), placa (só admin)
        if ($request->input('descricao') !== null) {
            $updates[] = "descricao = :descricao";
            $params[':descricao'] = $request->input('descricao');
        }
        if ($request->input('data_fim_manutencao') !== null) {
            $updates[] = "data_fim_manutencao = :data_fim";
            $params[':data_fim'] = $request->input('data_fim_manutencao');
        }
        if ($user['tipo'] === 'admin' && $request->input('placa') !== null) {
            $updates[] = "placa = :placa";
            $params[':placa'] = $request->input('placa');
        }

        if (empty($updates)) {
            JsonResponse::error('Nada para atualizar', 422);
        }

        $sql = "UPDATE tb_manutencao_veiculo SET " . implode(', ', $updates) . " WHERE id_manutencao = :id";
        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);

        JsonResponse::success(null, 200, 'Manutenção atualizada');
    }

    public function destroy(Request $request) {
        Auth::requireRole('admin'); // Somente admin pode excluir
        $id = $request->param('id');
        
        $stmt = Database::getConnection()->prepare("DELETE FROM tb_manutencao_veiculo WHERE id_manutencao = :id");
        $stmt->execute([':id' => $id]);

        JsonResponse::success(null, 200, 'Manutenção excluída');
    }
}