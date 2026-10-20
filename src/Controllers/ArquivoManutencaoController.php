<?php
namespace Controllers;

use Core\Database;
use Helpers\JsonResponse;
use Helpers\Auth;
use Core\Request;

class ArquivoManutencaoController {

    public function index(Request $request) {
        Auth::requireAuth();

        $manutencaoId = $request->query('id_manutencao');
        if (!$manutencaoId) {
            JsonResponse::error('É necessário informar id_manutencao', 422);
        }

        // Verifica permissão: técnico só vê arquivos das suas manutenções
        $user = Auth::check();
        if ($user['tipo'] === 'tecnico' || $user['tipo'] === 'admin') {
            $stmt = Database::getConnection()->prepare(
                "SELECT mv.id_tecnico FROM tb_manutencao_veiculo mv WHERE mv.id_manutencao = :id"
            );
            $stmt->execute([':id' => $manutencaoId]);
            $manut = $stmt->fetch();
            if (!$manut || $manut['id_tecnico'] != $user['id'] && $user['tipo'] != 'admin') {
                JsonResponse::error('Acesso negado', 403);
            }
        }

        $stmt = Database::getConnection()->prepare(
            "SELECT id_arq_manu, id_manutencao_veiculo, descricao, arq_link, arq_blob
             FROM tb_arquivos_manutencao
             WHERE id_manutencao_veiculo = :id"
        );
        $stmt->execute([':id' => $manutencaoId]);
        $arquivos = $stmt->fetchAll();

        // Converte blob para base64 se existir (para exibição)
        foreach ($arquivos as &$arquivo) {
            if ($arquivo['arq_blob'] !== null) {
                $arquivo['arq_blob'] = base64_encode($arquivo['arq_blob']);
            }
        }

        JsonResponse::success($arquivos);
    }

    public function store(Request $request) {
        Auth::requireAuth();

        $manutencaoId = $request->input('id_manutencao_veiculo');
        $descricao = $request->input('descricao');
        $link = $request->input('arq_link');
        $blob = $request->input('arq_blob'); // pode ser base64

        if (!$manutencaoId) {
            JsonResponse::error('ID da manutenção é obrigatório', 422);
        }

        $db = Database::getConnection();

        // Verifica se manutenção existe e permissão
        $stmt = $db->prepare("SELECT * FROM tb_manutencao_veiculo WHERE id_manutencao = :id");
        $stmt->execute([':id' => $manutencaoId]);
        $manut = $stmt->fetch();
        if (!$manut) {
            JsonResponse::error('Manutenção não encontrada', 404);
        }

        $user = Auth::check();
        if ($user['tipo'] === 'tecnico' && $manut['id_tecnico'] != $user['id']) {
            JsonResponse::error('Acesso negado', 403);
        }

        // Processa blob (se enviado em base64)
        $blobData = null;
        if ($blob) {
            $blobData = base64_decode($blob);
        }

        $stmt = $db->prepare(
            "INSERT INTO tb_arquivos_manutencao (id_manutencao_veiculo, descricao, arq_link, arq_blob)
             VALUES (:id_manutencao, :descricao, :link, :blob)"
        );
        $stmt->execute([
            ':id_manutencao' => $manutencaoId,
            ':descricao' => $descricao,
            ':link' => $link,
            ':blob' => $blobData
        ]);

        JsonResponse::success(['id_arquivo' => $db->lastInsertId()], 201);
    }

    public function show(Request $request) {
        Auth::requireAuth();
        $id = $request->param('id');

        $stmt = Database::getConnection()->prepare("SELECT * FROM tb_arquivos_manutencao WHERE id_arq_manu = :id");
        $stmt->execute([':id' => $id]);
        $arquivo = $stmt->fetch();

        if (!$arquivo) {
            JsonResponse::error('Arquivo não encontrado', 404);
        }

        // Converte blob para base64
        if ($arquivo['arq_blob'] !== null) {
            $arquivo['arq_blob'] = base64_encode($arquivo['arq_blob']);
        }

        JsonResponse::success($arquivo);
    }

    public function destroy(Request $request) {
        Auth::requireAuth();
        $id = $request->param('id');
        
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT am.*, mv.id_tecnico FROM tb_arquivos_manutencao am
                              JOIN tb_manutencao_veiculo mv ON am.id_manutencao_veiculo = mv.id_manutencao
                              WHERE am.id_arq_manu = :id");
        $stmt->execute([':id' => $id]);
        $arquivo = $stmt->fetch();
        if (!$arquivo) {
            JsonResponse::error('Arquivo não encontrado', 404);
        }

        $user = Auth::check();
        if ($user['tipo'] === 'tecnico' && $arquivo['id_tecnico'] != $user['id']) {
            JsonResponse::error('Acesso negado', 403);
        }

        $stmt = $db->prepare("DELETE FROM tb_arquivos_manutencao WHERE id_arq_manu = :id");
        $stmt->execute([':id' => $id]);

        JsonResponse::success(null, 200, 'Arquivo excluído');
    }
}