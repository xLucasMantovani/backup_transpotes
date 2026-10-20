<?php
namespace Controllers;

use Core\Database;
use Helpers\JsonResponse;
use Helpers\Auth;
use Core\Request;

class ChecklistController {

    public function index(Request $request) {
        $user = Auth::requireAuth();

        $sql = "SELECT ck.data_hora, ck.placa, ck.id_motorista, ck.dados_check,
                       JSON_UNQUOTE(JSON_EXTRACT(f.dados_funcionario, '$.nome')) AS nome_motorista
                FROM tb_checklists ck
                JOIN tb_motoristas m ON ck.id_motorista = m.id_motorista
                JOIN tb_funcionarios f ON m.id_motorista = f.id_funcionario";

        $where = [];
        $params = [];

        if ($user['tipo'] === 'motorista') {
            $where[] = "ck.id_motorista = :id_motorista";
            $params[':id_motorista'] = $user['id'];
        }

        if ($placa = $request->query('placa')) {
            $where[] = "ck.placa = :placa";
            $params[':placa'] = $placa;
        }
        if ($dataInicio = $request->query('data_inicio')) {
            $where[] = "ck.data_hora >= :data_inicio";
            $params[':data_inicio'] = $dataInicio;
        }
        if ($dataFim = $request->query('data_fim')) {
            $where[] = "ck.data_hora <= :data_fim";
            $params[':data_fim'] = $dataFim;
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $sql .= " ORDER BY ck.data_hora DESC";

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);
        $checklists = $stmt->fetchAll();

        foreach ($checklists as &$ck) {
            $ck['dados_check'] = json_decode($ck['dados_check'], true);
        }

        JsonResponse::success($checklists);
    }

    public function show(Request $request) {
        $user = Auth::requireAuth();
        $dataHora = $request->param('dataHora');
        $placa = $request->param('placa');
        $idMotorista = $request->param('idMotorista');
        
        // Construir consulta considerando chave composta
        $sql = "SELECT ck.*, 
                       JSON_UNQUOTE(JSON_EXTRACT(f.dados_funcionario, '$.nome')) AS nome_motorista
                FROM tb_checklists ck
                JOIN tb_motoristas m ON ck.id_motorista = m.id_motorista
                JOIN tb_funcionarios f ON m.id_motorista = f.id_funcionario
                WHERE ck.data_hora = :data_hora AND ck.placa = :placa AND ck.id_motorista = :id_motorista";
        $params = [':data_hora' => $dataHora, ':placa' => $placa, ':id_motorista' => $idMotorista];

        if ($user['tipo'] === 'motorista' && $idMotorista != $user['id']) {
            JsonResponse::error('Acesso negado', 403);
        }

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);
        $checklist = $stmt->fetch();

        if (!$checklist) {
            JsonResponse::error('Checklist não encontrado', 404);
        }

        $checklist['dados_check'] = json_decode($checklist['dados_check'], true);
        JsonResponse::success($checklist);
    }

    public function store(Request $request) {
        $user = Auth::requireRole('motorista');

        $placa = $request->input('placa');
        $dadosCheck = $request->input('dados_check'); // espera array

        if (!$placa || !$dadosCheck) {
            JsonResponse::error('Placa e dados_check são obrigatórios', 422);
        }

        $db = Database::getConnection();

        // Verifica veículo
        $stmt = $db->prepare("SELECT 1 FROM tb_veiculos WHERE placa = :placa");
        $stmt->execute([':placa' => $placa]);
        if (!$stmt->fetch()) {
            JsonResponse::error('Veículo não encontrado', 404);
        }

        $dataHora = date('Y-m-d H:i:s');

        $stmt = $db->prepare(
            "INSERT INTO tb_checklists (data_hora, placa, id_motorista, dados_check)
             VALUES (:data_hora, :placa, :id_motorista, :dados_check)"
        );
        $stmt->execute([
            ':data_hora' => $dataHora,
            ':placa' => $placa,
            ':id_motorista' => $user['id'],
            ':dados_check' => json_encode($dadosCheck)
        ]);

        JsonResponse::success(['data_hora' => $dataHora, 'placa' => $placa, 'id_motorista' => $user['id']], 201);
    }

    public function update(Request $request) {
        $user = Auth::requireAuth();

        // Verifica existência
        $stmt = Database::getConnection()->prepare(
            "SELECT * FROM tb_checklists WHERE data_hora = :data_hora AND placa = :placa AND id_motorista = :id_motorista"
        );
        $stmt->execute([':data_hora' => $dataHora, ':placa' => $placa, ':id_motorista' => $idMotorista]);
        $checklist = $stmt->fetch();
        if (!$checklist) {
            JsonResponse::error('Checklist não encontrado', 404);
        }

        if ($user['tipo'] === 'motorista' && $checklist['id_motorista'] != $user['id']) {
            JsonResponse::error('Acesso negado', 403);
        }

        $dadosCheck = $request->input('dados_check');
        if (!$dadosCheck) {
            JsonResponse::error('dados_check é obrigatório', 422);
        }

        $stmt = Database::getConnection()->prepare(
            "UPDATE tb_checklists SET dados_check = :dados_check WHERE data_hora = :data_hora AND placa = :placa AND id_motorista = :id_motorista"
        );
        $stmt->execute([
            ':dados_check' => json_encode($dadosCheck),
            ':data_hora' => $dataHora,
            ':placa' => $placa,
            ':id_motorista' => $idMotorista
        ]);

        JsonResponse::success(null, 200, 'Checklist atualizado');
    }

    public function destroy(Request $request) {
        $user = Auth::requireAuth();
        $dataHora = $request->param('dataHora');
        $placa = $request->param('placa');
        $idMotorista = $request->param('idMotorista');
        
        $stmt = Database::getConnection()->prepare(
            "SELECT * FROM tb_checklists WHERE data_hora = :data_hora AND placa = :placa AND id_motorista = :id_motorista"
        );
        $stmt->execute([':data_hora' => $dataHora, ':placa' => $placa, ':id_motorista' => $idMotorista]);
        $checklist = $stmt->fetch();
        if (!$checklist) {
            JsonResponse::error('Checklist não encontrado', 404);
        }

        if ($user['tipo'] === 'motorista' && $checklist['id_motorista'] != $user['id']) {
            JsonResponse::error('Acesso negado', 403);
        }

        $stmt = Database::getConnection()->prepare(
            "DELETE FROM tb_checklists WHERE data_hora = :data_hora AND placa = :placa AND id_motorista = :id_motorista"
        );
        $stmt->execute([':data_hora' => $dataHora, ':placa' => $placa, ':id_motorista' => $idMotorista]);

        JsonResponse::success(null, 200, 'Checklist excluído');
    }
}