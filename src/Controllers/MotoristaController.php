<?php
namespace Controllers;

use Core\Database;
use Helpers\JsonResponse;
use Helpers\Auth;
use Core\Request;

class MotoristaController {

    public function index(Request $request) {
        Auth::requireRole('admin');

        $sql = "SELECT m.id_motorista, m.status_motorista, m.observacoes, m.funcoes_motorista,
                       JSON_UNQUOTE(JSON_EXTRACT(f.dados_funcionario, '$.nome')) AS nome,
                       JSON_UNQUOTE(JSON_EXTRACT(f.dados_funcionario, '$.cpf')) AS cpf,
                       JSON_UNQUOTE(JSON_EXTRACT(f.dados_funcionario, '$.telefone')) AS telefone
                FROM tb_motoristas m
                JOIN tb_funcionarios f ON m.id_motorista = f.id_funcionario";

        $stmt = Database::getConnection()->query($sql);
        $motoristas = $stmt->fetchAll();

        JsonResponse::success($motoristas);
    }

    public function show(Request $request) {
        Auth::requireRole('admin');
        $id = $request->param('id');

        $stmt = Database::getConnection()->prepare(
            "SELECT m.id_motorista, m.status_motorista, m.observacoes, m.funcoes_motorista,
                    f.dados_funcionario
             FROM tb_motoristas m
             JOIN tb_funcionarios f ON m.id_motorista = f.id_funcionario
             WHERE m.id_motorista = :id"
        );
        $stmt->execute([':id' => $id]);
        $motorista = $stmt->fetch();

        if (!$motorista) {
            JsonResponse::error('Motorista não encontrado', 404);
        }

        // Decodifica o JSON para retornar como objeto
        $motorista['dados_funcionario'] = json_decode($motorista['dados_funcionario'], true);
        JsonResponse::success($motorista);
    }

    public function store(Request $request) {
        Auth::requireRole('admin');

        $nome = $request->input('nome');
        $cpf = $request->input('cpf');
        $telefone = $request->input('telefone', null);
        $endereco = $request->input('endereco', null);
        $status = $request->input('status_motorista', 1);
        $observacoes = $request->input('observacoes', null);
        $funcoes = $request->input('funcoes_motorista', null);

        if (!$nome || !$cpf) {
            JsonResponse::error('Nome e CPF são obrigatórios', 422);
        }

        $db = Database::getConnection();

        // Verifica se CPF já existe
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
            "INSERT INTO tb_motoristas (id_motorista, status_motorista, observacoes, funcoes_motorista)
             VALUES (:id, :status, :obs, :funcoes)"
        );
        $stmt->execute([
            ':id' => $idFuncionario,
            ':status' => $status,
            ':obs' => $observacoes,
            ':funcoes' => $funcoes
        ]);

        JsonResponse::success(['id_motorista' => $idFuncionario], 201);
    }

    public function update(Request $request) {
        Auth::requireRole('admin');
        $id = $request->param('id');
        // Verifica se motorista existe
        $stmt = Database::getConnection()->prepare("SELECT 1 FROM tb_motoristas WHERE id_motorista = :id");
        $stmt->execute([':id' => $id]);
        if (!$stmt->fetch()) {
            JsonResponse::error('Motorista não encontrado', 404);
        }

        // Atualiza dados do funcionário (JSON)
        $camposFuncionario = [];
        $dadosAtuais = $this->getDadosFuncionario($id);
        $novosDados = $dadosAtuais;

        if ($request->input('nome')) $novosDados['nome'] = $request->input('nome');
        if ($request->input('telefone')) $novosDados['telefone'] = $request->input('telefone');
        if ($request->input('endereco')) $novosDados['endereco'] = $request->input('endereco');
        if ($request->input('cpf')) {
            // Verifica unicidade do CPF
            $stmt = Database::getConnection()->prepare(
                "SELECT 1 FROM tb_funcionarios 
                 WHERE JSON_UNQUOTE(JSON_EXTRACT(dados_funcionario, '$.cpf')) = :cpf 
                 AND id_funcionario <> :id"
            );
            $stmt->execute([':cpf' => $request->input('cpf'), ':id' => $id]);
            if ($stmt->fetch()) {
                JsonResponse::error('CPF já cadastrado para outro funcionário', 422);
            }
            $novosDados['cpf'] = $request->input('cpf');
        }

        $stmt = Database::getConnection()->prepare("UPDATE tb_funcionarios SET dados_funcionario = :dados WHERE id_funcionario = :id");
        $stmt->execute([':dados' => json_encode($novosDados), ':id' => $id]);

        // Atualiza dados específicos do motorista
        $updates = [];
        $params = [':id' => $id];
        if ($request->input('status_motorista') !== null) {
            $updates[] = "status_motorista = :status";
            $params[':status'] = $request->input('status_motorista');
        }
        if ($request->input('observacoes') !== null) {
            $updates[] = "observacoes = :obs";
            $params[':obs'] = $request->input('observacoes');
        }
        if ($request->input('funcoes_motorista') !== null) {
            $updates[] = "funcoes_motorista = :funcoes";
            $params[':funcoes'] = $request->input('funcoes_motorista');
        }
        if (!empty($updates)) {
            $sql = "UPDATE tb_motoristas SET " . implode(', ', $updates) . " WHERE id_motorista = :id";
            $stmt = Database::getConnection()->prepare($sql);
            $stmt->execute($params);
        }

        JsonResponse::success(null, 200, 'Motorista atualizado');
    }

    public function destroy(Request $request) {
        Auth::requireRole('admin');
        $id = $request->param('id');
        // Exclui motorista (e consequentemente funcionário? Depende da regra: aqui excluímos ambos)
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM tb_motoristas WHERE id_motorista = :id");
        $stmt->execute([':id' => $id]);
        $stmt = $db->prepare("DELETE FROM tb_funcionarios WHERE id_funcionario = :id");
        $stmt->execute([':id' => $id]);

        JsonResponse::success(null, 200, 'Motorista excluído');
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