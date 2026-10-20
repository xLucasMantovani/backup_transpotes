<?php
namespace Controllers;

use Core\Database;
use Helpers\JsonResponse;
use Helpers\Auth;
use Core\Request;

class DocumentoVeiculoController {

    public function index(Request $request) {
        Auth::requireAuth();

        // Lista documentos, com filtro opcional por placa
        $placa = $request->query('placa');
        $sql = "SELECT dv.placa, dv.descricao, dv.validade, dv.status_documento,
                       JSON_UNQUOTE(JSON_EXTRACT(v.dados_veiculo, '$.modelo')) AS modelo
                FROM tb_documentos_veiculo dv
                JOIN tb_veiculos v ON dv.placa = v.placa";
        $params = [];
        if ($placa) {
            $sql .= " WHERE dv.placa = :placa";
            $params[':placa'] = $placa;
        }

        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);
        $documentos = $stmt->fetchAll();

        JsonResponse::success($documentos);
    }

    public function show(Request $request) {
        Auth::requireAuth();
        
        $placa = $request->param('placa');
        $descricao = $request->param('descricao');
        $stmt = Database::getConnection()->prepare(
            "SELECT * FROM tb_documentos_veiculo WHERE placa = :placa AND descricao = :descricao"
        );
        $stmt->execute([':placa' => $placa, ':descricao' => $descricao]);
        $doc = $stmt->fetch();

        if (!$doc) {
            JsonResponse::error('Documento não encontrado', 404);
        }

        JsonResponse::success($doc);
    }

    public function store(Request $request) {
        Auth::requireRole('admin');

        $placa = $request->input('placa');
        $descricao = $request->input('descricao');
        $validade = $request->input('validade');
        $status = $request->input('status_documento');

        if (!$placa || !$descricao || !$validade || !$status) {
            JsonResponse::error('Todos os campos são obrigatórios', 422);
        }

        $db = Database::getConnection();

        // Verifica se veículo existe
        $stmt = $db->prepare("SELECT 1 FROM tb_veiculos WHERE placa = :placa");
        $stmt->execute([':placa' => $placa]);
        if (!$stmt->fetch()) {
            JsonResponse::error('Veículo não encontrado', 404);
        }

        // Verifica se documento já existe
        $stmt = $db->prepare("SELECT 1 FROM tb_documentos_veiculo WHERE placa = :placa AND descricao = :descricao");
        $stmt->execute([':placa' => $placa, ':descricao' => $descricao]);
        if ($stmt->fetch()) {
            JsonResponse::error('Documento já cadastrado para este veículo', 422);
        }

        $stmt = $db->prepare(
            "INSERT INTO tb_documentos_veiculo (placa, descricao, validade, status_documento)
             VALUES (:placa, :descricao, :validade, :status)"
        );
        $stmt->execute([
            ':placa' => $placa,
            ':descricao' => $descricao,
            ':validade' => $validade,
            ':status' => $status
        ]);

        JsonResponse::success(null, 201);
    }

    public function update(Request $request) {
        Auth::requireRole('admin');
        $placa = $request->param('placa');
        $descricao = $request->param('descricao');
        // Verifica existência
        $stmt = Database::getConnection()->prepare("SELECT 1 FROM tb_documentos_veiculo WHERE placa = :placa AND descricao = :descricao");
        $stmt->execute([':placa' => $placa, ':descricao' => $descricao]);
        if (!$stmt->fetch()) {
            JsonResponse::error('Documento não encontrado', 404);
        }

        $validade = $request->input('validade');
        $status = $request->input('status_documento');

        if (!$validade && !$status) {
            JsonResponse::error('Nada para atualizar', 422);
        }

        $updates = [];
        $params = [':placa' => $placa, ':descricao' => $descricao];
        if ($validade) {
            $updates[] = "validade = :validade";
            $params[':validade'] = $validade;
        }
        if ($status) {
            $updates[] = "status_documento = :status";
            $params[':status'] = $status;
        }

        $sql = "UPDATE tb_documentos_veiculo SET " . implode(', ', $updates) . " WHERE placa = :placa AND descricao = :descricao";
        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);

        JsonResponse::success(null, 200, 'Documento atualizado');
    }

    public function destroy(Request $request) {
        Auth::requireRole('admin');
        $placa = $request->param('placa');
        $descricao = $request->param('descricao');

        $stmt = Database::getConnection()->prepare("DELETE FROM tb_documentos_veiculo WHERE placa = :placa AND descricao = :descricao");
        $stmt->execute([':placa' => $placa, ':descricao' => $descricao]);

        JsonResponse::success(null, 200, 'Documento excluído');
    }
}