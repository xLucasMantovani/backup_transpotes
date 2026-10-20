<?php
namespace Controllers;

use Core\Database;
use Helpers\JsonResponse;
use Helpers\Auth;
use Core\Request;

class AbastecimentoController {

    public function index(Request $request) {
        $user = Auth::requireAuth();

        $sql = "SELECT ab.id_abastecimento, ab.placa, ab.id_motorista, ab.km, ab.litros,
                       JSON_UNQUOTE(JSON_EXTRACT(f.dados_funcionario, '$.nome')) AS nome_motorista
                FROM tb_abastecimentos ab
                JOIN tb_motoristas m ON ab.id_motorista = m.id_motorista
                JOIN tb_funcionarios f ON m.id_motorista = f.id_funcionario";

        $where = [];
        $params = [];

        if ($user['tipo'] === 'motorista') {
            $where[] = "ab.id_motorista = :id_motorista";
            $params[':id_motorista'] = $user['id'];
        }

        if ($placa = $request->query('placa')) {
            $where[] = "ab.placa = :placa";
            $params[':placa'] = $placa;
        }
        if ($motoristaId = $request->query('id_motorista') && $user['tipo'] === 'admin') {
            $where[] = "ab.id_motorista = :id_mot";
            $params[':id_mot'] = $motoristaId;
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $sql .= " ORDER BY ab.id_abastecimento DESC";

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);
        $abastecimentos = $stmt->fetchAll();

        JsonResponse::success($abastecimentos);
    }

    public function show(Request $request) {
        $user = Auth::requireAuth();
        $id = $request->param('id');
        
        $sql = "SELECT ab.*, 
                       JSON_UNQUOTE(JSON_EXTRACT(f.dados_funcionario, '$.nome')) AS nome_motorista
                FROM tb_abastecimentos ab
                JOIN tb_motoristas m ON ab.id_motorista = m.id_motorista
                JOIN tb_funcionarios f ON m.id_motorista = f.id_funcionario
                WHERE ab.id_abastecimento = :id";
        $params = [':id' => $id];

        if ($user['tipo'] === 'motorista') {
            $sql .= " AND ab.id_motorista = :id_motorista";
            $params[':id_motorista'] = $user['id'];
        }

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);
        $abastecimento = $stmt->fetch();

        if (!$abastecimento) {
            JsonResponse::error('Abastecimento não encontrado', 404);
        }

        JsonResponse::success($abastecimento);
    }

    public function store(Request $request) {
        $user = Auth::requireRole('motorista');
        $idMotorista = $request->input('id_motorista') ?? $user['id'];

        $placa = $request->input('placa');
        $km = $request->input('km');
        $litros = $request->input('litros');

        if (!$placa || !$km || !$litros) {
            JsonResponse::error('Placa, km e litros são obrigatórios', 422);
        }

        $db = Database::getConnection();

        // Verifica veículo
        $stmt = $db->prepare("SELECT 1 FROM tb_veiculos WHERE placa = :placa");
        $stmt->execute([':placa' => $placa]);
        if (!$stmt->fetch()) {
            JsonResponse::error('Veículo não encontrado', 404);
        }

        $stmt = $db->prepare(
            "INSERT INTO tb_abastecimentos (placa, id_motorista, km, litros)
             VALUES (:placa, :id_motorista, :km, :litros)"
        );
        $stmt->execute([
            ':placa' => $placa,
            ':id_motorista' => $idMotorista,
            ':km' => $km,
            ':litros' => $litros
        ]);

        JsonResponse::success(['id_abastecimento' => $db->lastInsertId()], 201);
    }

    public function update(Request $request) {
        $user = Auth::requireAuth();
        $id = $request->param('id');

        $stmt = Database::getConnection()->prepare("SELECT * FROM tb_abastecimentos WHERE id_abastecimento = :id");
        $stmt->execute([':id' => $id]);
        $abastecimento = $stmt->fetch();
        if (!$abastecimento) {
            JsonResponse::error('Abastecimento não encontrado', 404);
        }

        // Motorista só pode alterar os próprios registros
        if ($user['tipo'] === 'motorista' && $abastecimento['id_motorista'] != $user['id']) {
            JsonResponse::error('Acesso negado', 403);
        }

        $updates = [];
        $params = [':id' => $id];
        foreach (['km', 'litros', 'placa'] as $campo) {
            if ($request->input($campo) !== null) {
                $updates[] = "$campo = :$campo";
                $params[":$campo"] = $request->input($campo);
            }
        }

        if (empty($updates)) {
            JsonResponse::error('Nada para atualizar', 422);
        }

        $sql = "UPDATE tb_abastecimentos SET " . implode(', ', $updates) . " WHERE id_abastecimento = :id";
        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);

        JsonResponse::success(null, 200, 'Abastecimento atualizado');
    }

    public function destroy(Request $request) {
        $user = Auth::requireAuth();
        $id = $request->param('id');
        
        $stmt = Database::getConnection()->prepare("SELECT * FROM tb_abastecimentos WHERE id_abastecimento = :id");
        $stmt->execute([':id' => $id]);
        $abastecimento = $stmt->fetch();
        if (!$abastecimento) {
            JsonResponse::error('Abastecimento não encontrado', 404);
        }

        if ($user['tipo'] === 'motorista' && $abastecimento['id_motorista'] != $user['id']) {
            JsonResponse::error('Acesso negado', 403);
        }

        $stmt = Database::getConnection()->prepare("DELETE FROM tb_abastecimentos WHERE id_abastecimento = :id");
        $stmt->execute([':id' => $id]);

        JsonResponse::success(null, 200, 'Abastecimento excluído');
    }
}