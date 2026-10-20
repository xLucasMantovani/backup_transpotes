<?php
namespace Controllers;

use Core\Database;
use Helpers\JsonResponse;
use Helpers\Auth;
use Core\Request;

class TecnicoController {

    public function index(Request $request) {
        Auth::requireRole('admin');

        $sql = "SELECT t.id_tecnico, t.status_tecnico, t.observacoes, t.tipo_tecnico,
                       JSON_UNQUOTE(JSON_EXTRACT(f.dados_funcionario, '$.nome')) AS nome,
                       JSON_UNQUOTE(JSON_EXTRACT(f.dados_funcionario, '$.cpf')) AS cpf,
                       JSON_UNQUOTE(JSON_EXTRACT(f.dados_funcionario, '$.telefone')) AS telefone
                FROM tb_tecnicos t
                JOIN tb_funcionarios f ON t.id_tecnico = f.id_funcionario";

        $stmt = Database::getConnection()->query($sql);
        $tecnicos = $stmt->fetchAll();

        JsonResponse::success($tecnicos);
    }

    public function show(Request $request) {
        Auth::requireRole('admin');
        $id = $request->param('id');

        $stmt = Database::getConnection()->prepare(
            "SELECT t.id_tecnico, t.status_tecnico, t.observacoes, t.tipo_tecnico,
                    f.dados_funcionario
             FROM tb_tecnicos t
             JOIN tb_funcionarios f ON t.id_tecnico = f.id_funcionario
             WHERE t.id_tecnico = :id"
        );
        $stmt->execute([':id' => $id]);
        $tecnico = $stmt->fetch();

        if (!$tecnico) {
            JsonResponse::error('Técnico não encontrado', 404);
        }

        $tecnico['dados_funcionario'] = json_decode($tecnico['dados_funcionario'], true);
        JsonResponse::success($tecnico);
    }

    public function store(Request $request) {
        Auth::requireRole('admin');
        
        $nome = $request->input('nome');
        $cpf = $request->input('cpf');
        $telefone = $request->input('telefone', null);
        $endereco = $request->input('endereco', null);
        $status = $request->input('status_tecnico', 1);
        $observacoes = $request->input('observacoes', null);
        $tipo = $request->input('tipo_tecnico', null);

        if (!$nome || !$cpf) {
            JsonResponse::error('Nome e CPF são obrigatórios', 422);
        }

        $db = Database::getConnection();

        $stmt = $db->prepare("SELECT 1 FROM tb_funcionarios WHERE JSON_UNQUOTE(JSON_EXTRACT(dados_funcionario, '$.cpf')) = :cpf");
        $stmt->execute([':cpf' => $cpf]);
        if ($stmt->fetch()) {
            JsonResponse::error('CPF já cadastrado', 422);
        }

        $dadosFuncionario = json_encode([
            'nome' => $nome,
            'cpf' => $cpf,
            'telefone' => $telefone,
            'endereco' => $endereco
        ]);

        $stmt = $db->prepare("INSERT INTO tb_funcionarios (dados_funcionario) VALUES (:dados)");
        $stmt->execute([':dados' => $dadosFuncionario]);
        $idFuncionario = $db->lastInsertId();

        $stmt = $db->prepare(
            "INSERT INTO tb_tecnicos (id_tecnico, status_tecnico, observacoes, tipo_tecnico)
             VALUES (:id, :status, :obs, :tipo)"
        );
        $stmt->execute([
            ':id' => $idFuncionario,
            ':status' => $status,
            ':obs' => $observacoes,
            ':tipo' => $tipo
        ]);

        JsonResponse::success(['id_tecnico' => $idFuncionario], 201);
    }

    public function update(Request $request) {
        Auth::requireRole('admin');
        $id = $request->param('id');
        
        $stmt = Database::getConnection()->prepare("SELECT 1 FROM tb_tecnicos WHERE id_tecnico = :id");
        $stmt->execute([':id' => $id]);
        if (!$stmt->fetch()) {
            JsonResponse::error('Técnico não encontrado', 404);
        }

        $dadosAtuais = $this->getDadosFuncionario($id);
        $novosDados = $dadosAtuais;
        if ($request->input('nome')) $novosDados['nome'] = $request->input('nome');
        if ($request->input('telefone')) $novosDados['telefone'] = $request->input('telefone');
        if ($request->input('endereco')) $novosDados['endereco'] = $request->input('endereco');
        if ($request->input('cpf')) {
            $stmt = Database::getConnection()->prepare(
                "SELECT 1 FROM tb_funcionarios 
                 WHERE JSON_UNQUOTE(JSON_EXTRACT(dados_funcionario, '$.cpf')) = :cpf 
                 AND id_funcionario <> :id"
            );
            $stmt->execute([':cpf' => $request->input('cpf'), ':id' => $id]);
            if ($stmt->fetch()) {
                JsonResponse::error('CPF já cadastrado', 422);
            }
            $novosDados['cpf'] = $request->input('cpf');
        }

        $stmt = Database::getConnection()->prepare("UPDATE tb_funcionarios SET dados_funcionario = :dados WHERE id_funcionario = :id");
        $stmt->execute([':dados' => json_encode($novosDados), ':id' => $id]);

        $updates = [];
        $params = [':id' => $id];
        if ($request->input('status_tecnico') !== null) {
            $updates[] = "status_tecnico = :status";
            $params[':status'] = $request->input('status_tecnico');
        }
        if ($request->input('observacoes') !== null) {
            $updates[] = "observacoes = :obs";
            $params[':obs'] = $request->input('observacoes');
        }
        if ($request->input('tipo_tecnico') !== null) {
            $updates[] = "tipo_tecnico = :tipo";
            $params[':tipo'] = $request->input('tipo_tecnico');
        }
        if (!empty($updates)) {
            $sql = "UPDATE tb_tecnicos SET " . implode(', ', $updates) . " WHERE id_tecnico = :id";
            $stmt = Database::getConnection()->prepare($sql);
            $stmt->execute($params);
        }

        JsonResponse::success(null, 200, 'Técnico atualizado');
    }

    public function destroy(Request $request) {
        Auth::requireRole('admin');
        $id = $request->param('id');
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM tb_tecnicos WHERE id_tecnico = :id");
        $stmt->execute([':id' => $id]);
        $stmt = $db->prepare("DELETE FROM tb_funcionarios WHERE id_funcionario = :id");
        $stmt->execute([':id' => $id]);

        JsonResponse::success(null, 200, 'Técnico excluído');
    }

    private function getDadosFuncionario($id) {
        $stmt = Database::getConnection()->prepare(
            "SELECT dados_funcionario FROM tb_funcionarios WHERE id_funcionario = :id"
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ? json_decode($row['dados_funcionario'], true) : [];
    }
}