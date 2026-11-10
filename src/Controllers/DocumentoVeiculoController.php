<?php
namespace Controllers;

use Core\Database;
use Helpers\JsonResponse;
use Helpers\Auth;
use Core\Request;

class DocumentoVeiculoController {

    public function index(Request $request) {
        Auth::requireAuth();
    
        $placa     = $request->query('placa');
        $descricao = $request->query('descricao');
    
        // =========================================================
        // MODO "ARQUIVO ÚNICO"
        // Quando placa E descricao vêm na query, retorna UM documento
        // com o blob em base64 (usado por visualizar/baixar no dashboard).
        // =========================================================
        if ($placa && $descricao) {
            $stmt = Database::getConnection()->prepare(
                "SELECT placa, descricao, validade, status_documento, arq_link, arq_blob
                 FROM tb_documentos_veiculo
                 WHERE placa = :placa AND descricao = :descricao"
            );
            $stmt->execute([':placa' => $placa, ':descricao' => $descricao]);
            $doc = $stmt->fetch();
    
            if (!$doc) {
                JsonResponse::error('Documento não encontrado', 404);
            }
    
            if ($doc['arq_blob'] !== null) {
                $doc['arq_blob'] = base64_encode($doc['arq_blob']);
            }
    
            JsonResponse::success($doc);
            return;
        }
    
        // =========================================================
        // MODO "LISTAGEM" (comportamento atual)
        // =========================================================
        $sql = "SELECT dv.placa, dv.descricao, dv.validade, dv.status_documento,
                       dv.arq_link,
                       (dv.arq_blob IS NOT NULL) AS tem_blob,
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

    public function show(Request $request, $placa = null, $descricao = null) {
        Auth::requireAuth();

        // Suporta chamadas com route params OU com query (?placa=...&descricao=...)
        $placa     = $placa     ?? $request->query('placa');
        $descricao = $descricao ?? $request->query('descricao');

        if (!$placa || !$descricao) {
            JsonResponse::error('Informe placa e descrição.', 422);
        }

        $stmt = Database::getConnection()->prepare(
            "SELECT placa, descricao, validade, status_documento, arq_link, arq_blob
             FROM tb_documentos_veiculo
             WHERE placa = :placa AND descricao = :descricao"
        );
        $stmt->execute([':placa' => $placa, ':descricao' => $descricao]);
        $doc = $stmt->fetch();

        if (!$doc) {
            JsonResponse::error('Documento não encontrado', 404);
        }

        // Codifica blob para base64 (padrão do ArquivoManutencaoController)
        if ($doc['arq_blob'] !== null) {
            $doc['arq_blob'] = base64_encode($doc['arq_blob']);
        }

        JsonResponse::success($doc);
    }

    public function store(Request $request) {
        Auth::requireRole('admin');

        $placa     = $request->input('placa');
        $descricao = $request->input('descricao');
        $validade  = $request->input('validade');
        $status    = $request->input('status_documento');
        $link      = $request->input('arq_link');
        $blob      = $request->input('arq_blob'); // base64

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

        // Verifica se documento já existe (PK composta)
        $stmt = $db->prepare("SELECT 1 FROM tb_documentos_veiculo WHERE placa = :placa AND descricao = :descricao");
        $stmt->execute([':placa' => $placa, ':descricao' => $descricao]);
        if ($stmt->fetch()) {
            JsonResponse::error('Documento já cadastrado para este veículo', 422);
        }

        // Decodifica blob (se enviado em base64)
        $blobData = null;
        if ($blob) {
            $blobData = base64_decode($blob, true);
            if ($blobData === false) {
                JsonResponse::error('Conteúdo do arquivo inválido (base64).', 422);
            }
        }

        $stmt = $db->prepare(
            "INSERT INTO tb_documentos_veiculo
                (placa, descricao, validade, status_documento, arq_link, arq_blob)
             VALUES (:placa, :descricao, :validade, :status, :link, :blob)"
        );
        $stmt->execute([
            ':placa'     => $placa,
            ':descricao' => $descricao,
            ':validade'  => $validade,
            ':status'    => $status,
            ':link'      => $link ?: null,
            ':blob'      => $blobData
        ]);

        JsonResponse::success(null, 201);
    }

    public function update(Request $request, $placa = null, $descricao = null) {
        Auth::requireRole('admin');

        $placa     = $placa     ?? $request->query('placa');
        $descricao = $descricao ?? $request->query('descricao');

        if (!$placa || !$descricao) {
            JsonResponse::error('Informe placa e descrição.', 422);
        }

        // Verifica existência
        $stmt = Database::getConnection()->prepare(
            "SELECT 1 FROM tb_documentos_veiculo WHERE placa = :placa AND descricao = :descricao"
        );
        $stmt->execute([':placa' => $placa, ':descricao' => $descricao]);
        if (!$stmt->fetch()) {
            JsonResponse::error('Documento não encontrado', 404);
        }

        $updates = [];
        $params = [':placa' => $placa, ':descricao' => $descricao];

        if ($request->input('validade') !== null) {
            $updates[] = "validade = :validade";
            $params[':validade'] = $request->input('validade');
        }
        if ($request->input('status_documento') !== null) {
            $updates[] = "status_documento = :status";
            $params[':status'] = $request->input('status_documento');
        }
        if ($request->input('arq_link') !== null) {
            $updates[] = "arq_link = :link";
            $params[':link'] = $request->input('arq_link') ?: null;
        }
        if ($request->input('arq_blob') !== null) {
            $raw = $request->input('arq_blob');
            if ($raw === '' || $raw === null) {
                $updates[] = "arq_blob = NULL";
            } else {
                $decoded = base64_decode($raw, true);
                if ($decoded === false) {
                    JsonResponse::error('Conteúdo do arquivo inválido (base64).', 422);
                }
                $updates[] = "arq_blob = :blob";
                $params[':blob'] = $decoded;
            }
        }

        if (empty($updates)) {
            JsonResponse::error('Nada para atualizar', 422);
        }

        $sql = "UPDATE tb_documentos_veiculo SET " . implode(', ', $updates)
             . " WHERE placa = :placa AND descricao = :descricao";
        $stmt = Database::getConnection()->prepare($sql);
        $stmt->execute($params);

        JsonResponse::success(null, 200, 'Documento atualizado');
    }

    public function destroy(Request $request, $placa = null, $descricao = null) {
        Auth::requireRole('admin');

        $placa     = $placa     ?? $request->query('placa');
        $descricao = $descricao ?? $request->query('descricao');

        if (!$placa || !$descricao) {
            JsonResponse::error('Informe placa e descrição.', 422);
        }

        $stmt = Database::getConnection()->prepare(
            "DELETE FROM tb_documentos_veiculo WHERE placa = :placa AND descricao = :descricao"
        );
        $stmt->execute([':placa' => $placa, ':descricao' => $descricao]);

        JsonResponse::success(null, 200, 'Documento excluído');
    }
}