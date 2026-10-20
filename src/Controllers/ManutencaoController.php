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

    /**
     * Cria uma manutenção.
     *
     * - Admin: usa o id_tecnico informado (ou o próprio id).
     * - Técnico: usa o próprio id (ou id_tecnico informado se admin).
     * - Motorista: REGISTRA UMA OCORRÊNCIA.
     *   → O técnico é escolhido automaticamente (o com menos OS em aberto).
     *   → A descrição é prefixada com "[OCORRÊNCIA - <tipo>]" para o admin
     *     distinguir visualmente de uma manutenção agendada.
     */
    public function store(Request $request) {
        $user = Auth::requireAuth();
    
        // Defensivo: garante estrutura do $user
        if (!is_array($user) || !isset($user['tipo'], $user['id'])) {
            JsonResponse::error('Sessão inválida. Faça login novamente.', 401);
        }
    
        $placa     = trim((string) $request->input('placa'));
        $descricao = trim((string) $request->input('descricao'));
    
        if ($placa === '' || $descricao === '') {
            JsonResponse::error('Placa e descrição são obrigatórias', 422);
        }
    
        $db = Database::getConnection();
    
        // 1) Veículo existe?
        $stmt = $db->prepare("SELECT 1 FROM tb_veiculos WHERE placa = :placa");
        $stmt->execute([':placa' => $placa]);
        if (!$stmt->fetch()) {
            JsonResponse::error('Veículo não encontrado', 404);
        }
    
        // 2) Define técnico
        if ($user['tipo'] === 'motorista') {
            $id_tecnico = self::escolherTecnicoComMenosOs($db);
            if ($id_tecnico === null) {
                JsonResponse::error(
                    'Nenhum técnico ativo disponível para receber a ocorrência. Tente novamente mais tarde.',
                    422
                );
            }
            $tipo = trim((string) ($request->input('tipo_ocorrencia') ?: 'Geral'));
            $descricao = "[OCORRÊNCIA - {$tipo}] {$descricao}";
        } else {
            $id_tecnico = (int) ($request->input('id_tecnico') ?? $user['id']);
        }
    
        // 3) Confirma que o técnico existe (evita FK silenciosa)
        $stmt = $db->prepare("SELECT 1 FROM tb_tecnicos WHERE id_tecnico = :id");
        $stmt->execute([':id' => $id_tecnico]);
        if (!$stmt->fetch()) {
            JsonResponse::error("Técnico #{$id_tecnico} não encontrado. Contate o administrador.", 422);
        }
    
        // 4) INSERT com captura real de erro
        try {
            $stmt = $db->prepare(
                "INSERT INTO tb_manutencao_veiculo (id_tecnico, placa, data_ini_manutencao, descricao)
                 VALUES (:id_tecnico, :placa, NOW(), :descricao)"
            );
            $ok = $stmt->execute([
                ':id_tecnico' => $id_tecnico,
                ':placa'      => $placa,
                ':descricao'  => $descricao,
            ]);
        } catch (\PDOException $e) {
            error_log('[ManutencaoController::store] PDO: ' . $e->getMessage());
            JsonResponse::error('Erro PDO ao inserir: ' . $e->getMessage(), 500);
        }
    
        if (!$ok) {
            $errInfo = $stmt->errorInfo();
            $msg = $errInfo[2] ?? 'erro desconhecido (execute retornou false)';
            error_log('[ManutencaoController::store] execute=false: ' . $msg);
            JsonResponse::error('Falha ao inserir manutenção: ' . $msg, 500);
        }
    
        // 5) Confirma que o ID realmente veio de um novo insert
        $novoId = (int) $db->lastInsertId();
        if ($novoId <= 0) {
            error_log('[ManutencaoController::store] lastInsertId inválido após insert: ' . $novoId);
            JsonResponse::error('Insert não retornou ID válido. Verifique os logs do servidor.', 500);
        }
    
        // 6) Confirma a linha de fato existindo (bypass de cache/erro silencioso)
        $stmt = $db->prepare("SELECT COUNT(*) AS c FROM tb_manutencao_veiculo WHERE id_manutencao = :id");
        $stmt->execute([':id' => $novoId]);
        if ((int) $stmt->fetch()['c'] !== 1) {
            error_log('[ManutencaoController::store] Linha não encontrada após insert. id=' . $novoId);
            JsonResponse::error('Registro não confirmado no banco após insert. Verifique os logs.', 500);
        }
    
        JsonResponse::success([
            'id_manutencao' => $novoId,
            'id_tecnico'    => (int) $id_tecnico,
        ], 201);
    }

    private static function escolherTecnicoComMenosOs($db) {
        // Subquery evita dependência de ONLY_FULL_GROUP_BY e do LEFT JOIN com filtro
        $sql = "SELECT t.id_tecnico,
                    (SELECT COUNT(*) FROM tb_manutencao_veiculo mv
                        WHERE mv.id_tecnico = t.id_tecnico
                        AND mv.data_fim_manutencao IS NULL) AS os_abertas
                FROM tb_tecnicos t
                WHERE t.status_tecnico = 1
                ORDER BY os_abertas ASC, t.id_tecnico ASC
                LIMIT 1";

        try {
            $row = $db->query($sql)->fetch();
            if (!$row || empty($row['id_tecnico'])) {
                error_log('[escolherTecnicoComMenosOs] Nenhum técnico ativo encontrado');
                return null;
            }
            return (int) $row['id_tecnico'];
        } catch (\PDOException $e) {
            error_log('[escolherTecnicoComMenosOs] PDO: ' . $e->getMessage());
            return null;
        }
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