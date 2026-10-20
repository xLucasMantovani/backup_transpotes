<?php
namespace Controllers;

use Core\Database;
use Helpers\JsonResponse;
use Helpers\Auth;
use Core\Request;

class FreteController {

    public function index(Request $request) {
        $user = Auth::requireAuth();

        $sql = "SELECT fr.id_frete, fr.placa, fr.id_motorista, fr.data_frete, fr.origem, fr.destino,
                       fr.distancia, fr.peso, fr.valor, fr.status_frete,
                       JSON_UNQUOTE(JSON_EXTRACT(f.dados_funcionario, '$.nome')) AS nome_motorista
                FROM tb_fretes fr
                JOIN tb_motoristas m ON fr.id_motorista = m.id_motorista
                JOIN tb_funcionarios f ON m.id_motorista = f.id_funcionario";

        $where = [];
        $params = [];

        // Motorista vê apenas seus fretes
        if ($user['tipo'] === 'motorista') {
            $where[] = "fr.id_motorista = :id_motorista";
            $params[':id_motorista'] = $user['id'];
        }

        // Filtros opcionais
        if ($placa = $request->query('placa')) {
            $where[] = "fr.placa = :placa";
            $params[':placa'] = $placa;
        }
        if ($status = $request->query('status')) {
            $where[] = "fr.status_frete = :status";
            $params[':status'] = $status;
        }
        if ($motoristaId = $request->query('id_motorista')) {
            if ($user['tipo'] === 'admin') {
                $where[] = "fr.id_motorista = :id_mot";
                $params[':id_mot'] = $motoristaId;
            }
        }
        if ($dataInicio = $request->query('data_inicio')) {
            $where[] = "fr.data_frete >= :data_inicio";
            $params[':data_inicio'] = $dataInicio;
        }
        if ($dataFim = $request->query('data_fim')) {
            $where[] = "fr.data_frete <= :data_fim";
            $params[':data_fim'] = $dataFim;
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $sql .= " ORDER BY fr.data_frete DESC";

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);
        $fretes = $stmt->fetchAll();

        JsonResponse::success($fretes);
    }

    public function show(Request $request) {
        $user = Auth::requireAuth();
        $id = $request->param('id');

        $sql = "SELECT fr.*, 
                       JSON_UNQUOTE(JSON_EXTRACT(f.dados_funcionario, '$.nome')) AS nome_motorista
                FROM tb_fretes fr
                JOIN tb_motoristas m ON fr.id_motorista = m.id_motorista
                JOIN tb_funcionarios f ON m.id_motorista = f.id_funcionario
                WHERE fr.id_frete = :id";
        $params = [':id' => $id];

        if ($user['tipo'] === 'motorista') {
            $sql .= " AND fr.id_motorista = :id_motorista";
            $params[':id_motorista'] = $user['id'];
        }

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);
        $frete = $stmt->fetch();

        if (!$frete) {
            JsonResponse::error('Frete não encontrado', 404);
        }

        JsonResponse::success($frete);
    }

    public function store(Request $request) {
        $user = Auth::requireRole('motorista');
        $idMotorista = $request->input('id_motorista') ?? $user['id'];
        $placa = $request->input('placa');
        $origem = $request->input('origem');
        $destino = $request->input('destino');
        $distancia = $request->input('distancia', 0);
        $peso = $request->input('peso', 0);
        $valor = $request->input('valor', 0);
        $status_frete = $request->input('status_frete', 'Em andamento');

        if (!$placa || !$origem || !$destino) {
            JsonResponse::error('Placa, origem e destino são obrigatórios', 422);
        }

        $db = Database::getConnection();

        // Verifica se veículo existe
        $stmt = $db->prepare("SELECT 1 FROM tb_veiculos WHERE placa = :placa");
        $stmt->execute([':placa' => $placa]);
        if (!$stmt->fetch()) {
            JsonResponse::error('Veículo não encontrado', 404);
        }

        $stmt = $db->prepare(
            "INSERT INTO tb_fretes (placa, id_motorista, data_frete, origem, destino, distancia, peso, valor, status_frete)
             VALUES (:placa, :id_motorista, NOW(), :origem, :destino, :distancia, :peso, :valor, :status_frete)"
        );
       
        $ok = $stmt->execute([
            ':placa' => $placa,
            ':id_motorista' => $idMotorista,
            ':origem' => $origem,
            ':destino' => $destino,
            ':distancia' => $distancia,
            ':peso' => $peso,
            ':valor' => $valor,
            ':status_frete' => $status_frete
        ]);

        if(!$ok) {
            JsonResponse::error('Falha ao inserir frete:', 500);
        }

        if($stmt->rowCount() === 0) {
            JsonResponse::error('Nenhuma linha inserida', 500);
        }

        JsonResponse::success(['id_frete' => (int) $db->lastInsertId()], 201);
        
    }

    public function update(Request $request) {
        $user = Auth::requireAuth();
        $id = $request->param('id');
        // Localiza frete
        $stmt = Database::getConnection()->prepare("SELECT * FROM tb_fretes WHERE id_frete = :id");
        $stmt->execute([':id' => $id]);
        $frete = $stmt->fetch();
        if (!$frete) {
            JsonResponse::error('Frete não encontrado', 404);
        }

        // Permissões: motorista só atualiza seus fretes e apenas se ainda não concluído? Regra: pode atualizar status, origem/destino, etc.
        if ($user['tipo'] === 'motorista' && $frete['id_motorista'] != $user['id']) {
            JsonResponse::error('Acesso negado', 403);
        }
        if ($user['tipo'] === 'motorista' && $frete['status_frete'] === 'Concluído') {
            JsonResponse::error('Frete concluído não pode ser alterado', 422);
        }

        $updates = [];
        $params = [':id' => $id];

        $allowed = ['placa', 'origem', 'destino', 'distancia', 'peso', 'valor', 'status_frete'];
        foreach ($allowed as $campo) {
            if ($request->input($campo) !== null) {
                $updates[] = "$campo = :$campo";
                $params[":$campo"] = $request->input($campo);
            }
        }

        if (empty($updates)) {
            JsonResponse::error('Nada para atualizar', 422);
        }

        $sql = "UPDATE tb_fretes SET " . implode(', ', $updates) . " WHERE id_frete = :id";
        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);

        JsonResponse::success(null, 200, 'Frete atualizado');
    }

    public function destroy(Request $request) {
        $user = Auth::requireAuth();
        $id = $request->param('id');

        $stmt = Database::getConnection()->prepare("SELECT * FROM tb_fretes WHERE id_frete = :id");
        $stmt->execute([':id' => $id]);
        $frete = $stmt->fetch();
        if (!$frete) {
            JsonResponse::error('Frete não encontrado', 404);
        }

        // Motorista só pode excluir seus fretes e se não concluído; admin pode todos
        if ($user['tipo'] === 'motorista') {
            if ($frete['id_motorista'] != $user['id']) {
                JsonResponse::error('Acesso negado', 403);
            }
            if ($frete['status_frete'] === 'Concluído') {
                JsonResponse::error('Frete concluído não pode ser excluído', 422);
            }
        }

        $stmt = Database::getConnection()->prepare("DELETE FROM tb_fretes WHERE id_frete = :id");
        $stmt->execute([':id' => $id]);

        JsonResponse::success(null, 200, 'Frete excluído');
    }
}