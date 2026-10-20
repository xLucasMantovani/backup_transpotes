<?php
namespace Controllers;

use Core\Database;
use Helpers\JsonResponse;
use Helpers\Auth;
use Core\Request;

class VeiculoController {

    public function index(Request $request) {
        Auth::requireAuth();

        // Todos os usuários autenticados podem listar veículos
        $stmt = Database::getConnection()->query(
            "SELECT placa, dados_veiculo FROM tb_veiculos"
        );
        $veiculos = $stmt->fetchAll();

        foreach ($veiculos as &$v) {
            $v['dados_veiculo'] = json_decode($v['dados_veiculo'], true);
        }

        JsonResponse::success($veiculos);
    }

    public function show(Request $request) {
        Auth::requireAuth();
        $placa = $request->param('placa');
        $stmt = Database::getConnection()->prepare(
            "SELECT placa, dados_veiculo FROM tb_veiculos WHERE placa = :placa"
        );
        $stmt->execute([':placa' => $placa]);
        $veiculo = $stmt->fetch();

        if (!$veiculo) {
            JsonResponse::error('Veículo não encontrado', 404);
        }

        $veiculo['dados_veiculo'] = json_decode($veiculo['dados_veiculo'], true);
        JsonResponse::success($veiculo);
    }

    public function store(Request $request) {
        Auth::requireRole('admin');
        
        $placa = $request->input('placa');
        $dadosVeiculo = $request->input('dados_veiculo'); // espera array

        if (!$placa || !$dadosVeiculo) {
            JsonResponse::error('Placa e dados do veículo são obrigatórios', 422);
        }

        $db = Database::getConnection();

        // Verifica se placa já existe
        $stmt = $db->prepare("SELECT 1 FROM tb_veiculos WHERE placa = :placa");
        $stmt->execute([':placa' => $placa]);
        if ($stmt->fetch()) {
            JsonResponse::error('Placa já cadastrada', 422);
        }

        $stmt = $db->prepare("INSERT INTO tb_veiculos (placa, dados_veiculo) VALUES (:placa, :dados)");
        $stmt->execute([
            ':placa' => $placa,
            ':dados' => json_encode($dadosVeiculo)
        ]);

        JsonResponse::success(['placa' => $placa], 201);
    }

    public function update(Request $request) {
        Auth::requireRole('admin');
        $placa = $request->param('placa');

        $stmt = Database::getConnection()->prepare("SELECT 1 FROM tb_veiculos WHERE placa = :placa");
        $stmt->execute([':placa' => $placa]);
        if (!$stmt->fetch()) {
            JsonResponse::error('Veículo não encontrado', 404);
        }

        $dadosAtuais = $this->getDadosVeiculo($placa);
        $novosDados = $dadosAtuais;
        if ($request->input('dados_veiculo')) {
            $novosDados = array_merge($novosDados, $request->input('dados_veiculo'));
        }

        $stmt = Database::getConnection()->prepare("UPDATE tb_veiculos SET dados_veiculo = :dados WHERE placa = :placa");
        $stmt->execute([
            ':dados' => json_encode($novosDados),
            ':placa' => $placa
        ]);

        JsonResponse::success(null, 200, 'Veículo atualizado');
    }

    public function destroy(Request $request) {
        Auth::requireRole('admin');
        $placa = $request->param('placa');

        $stmt = Database::getConnection()->prepare("DELETE FROM tb_veiculos WHERE placa = :placa");
        $stmt->execute([':placa' => $placa]);

        JsonResponse::success(null, 200, 'Veículo excluído');
    }

    private function getDadosVeiculo($placa) {
        $stmt = Database::getConnection()->prepare("SELECT dados_veiculo FROM tb_veiculos WHERE placa = :placa");
        $stmt->execute([':placa' => $placa]);
        $row = $stmt->fetch();
        return $row ? json_decode($row['dados_veiculo'], true) : [];
    }
}