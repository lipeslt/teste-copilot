<?php
require 'db_connection.php';
session_start();

header('Content-Type: application/json');

try {
    if (empty($_SESSION['user_id'])) {
        throw new Exception('Usuário não autenticado');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON inválido');
    }

    if (!isset($input['tipo']) || !isset($input['id'])) {
        throw new Exception('Parâmetros inválidos');
    }

    $conn->beginTransaction();

    $mecanico_id = $_SESSION['user_id'];
    $tipo = $input['tipo'];
    $id = (int)$input['id'];

    if ($tipo === 'notificacao') {
        // Verificar se a notificação existe e está disponível
        $stmt = $conn->prepare("SELECT * FROM notificacoes WHERE id = ? AND status = 'pendente'");
        $stmt->execute([$id]);
        $notificacao = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$notificacao) {
            throw new Exception('Notificação não encontrada ou já em serviço');
        }

        // Inserir o serviço como ACEITO
        $sql = "INSERT INTO servicos_mecanica (
            notificacao_id,
            mecanico_id,
            secretaria,
            nome_veiculo,
            prefixo,
            observacoes,
            status,
            created_at,
            updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, 'aceito', NOW(), NOW())";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $id,
            $mecanico_id,
            $notificacao['secretaria'],
            $notificacao['nome_veiculo'] ?? '',
            $notificacao['prefixo'] ?? '',
            $notificacao['mensagem'] ?? ''
        ]);

        // Atualizar status da notificação para 'aceito'
        $stmt = $conn->prepare("UPDATE notificacoes SET status = 'aceito' WHERE id = ?");
        $stmt->execute([$id]);

    } elseif ($tipo === 'ficha') {
        // Verificar se a ficha existe e está disponível
        $stmt = $conn->prepare("SELECT * FROM ficha_defeito WHERE id = ? AND status = 'pendente'");
        $stmt->execute([$id]);
        $ficha = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ficha) {
            throw new Exception('Ficha não encontrada ou já em serviço');
        }

        // Inserir o serviço como ACEITO
        $sql = "INSERT INTO servicos_mecanica (
            ficha_id,
            mecanico_id,
            secretaria,
            nome_veiculo,
            observacoes,
            status,
            created_at,
            updated_at
        ) VALUES (?, ?, ?, ?, ?, 'aceito', NOW(), NOW())";

        $stmt = $conn->prepare($sql);
        $stmt->execute([
            $id,
            $mecanico_id,
            $ficha['secretaria'],
            $ficha['nome_veiculo'],
            $ficha['descricao_servico'] ?? ''
        ]);

        // Atualizar status da ficha para 'aceito'
        $stmt = $conn->prepare("UPDATE ficha_defeito SET status = 'aceito' WHERE id = ?");
        $stmt->execute([$id]);
    } else {
        throw new Exception('Tipo de origem inválido');
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Serviço aceito com sucesso'
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit();
}
?>