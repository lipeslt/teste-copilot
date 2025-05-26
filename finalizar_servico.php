<?php
require 'db_connection.php';

header('Content-Type: application/json');
session_start();

try {
    // Verificação de autenticação
    if (empty($_SESSION['user_id'])) {
        throw new Exception('Usuário não autenticado', 401);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método não permitido', 405);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON inválido', 400);
    }

    if (empty($input['pedido_id'])) {
        throw new Exception('ID do serviço é obrigatório', 400);
    }

    $conn->beginTransaction();
    $mecanico_id = $_SESSION['user_id'];
    $pedido_id = (int)$input['pedido_id'];
    $tempo_real = isset($input['total_time']) ? (int)$input['total_time'] : null;

    // Verificar se o serviço existe e está como 'em_andamento'
    $sql = "SELECT id, notificacao_id, ficha_id FROM servicos_mecanica 
            WHERE id = ? AND mecanico_id = ? AND status = 'em_andamento'";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$pedido_id, $mecanico_id]);
    $servico = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$servico) {
        throw new Exception('Serviço não encontrado ou não está em andamento', 404);
    }

    // Atualizar o serviço para 'finalizado'
    $stmt = $conn->prepare("
        UPDATE servicos_mecanica 
        SET status = 'finalizado', 
            data_conclusao = NOW(),
            tempo_real = ?,
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$tempo_real, $servico['id']]);

    // Atualizar a origem (notificação ou ficha)
    if ($servico['notificacao_id']) {
        $stmt = $conn->prepare("UPDATE notificacoes SET status = 'finalizado' WHERE id = ?");
        $stmt->execute([$servico['notificacao_id']]);
    } elseif ($servico['ficha_id']) {
        $stmt = $conn->prepare("UPDATE ficha_defeito SET status = 'finalizado' WHERE id = ?");
        $stmt->execute([$servico['ficha_id']]);
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Serviço finalizado com sucesso',
        'service_id' => $servico['id']
    ]);

} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code($e->getCode() ?: 400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => $e->getCode()
    ]);
    exit();
}
?>