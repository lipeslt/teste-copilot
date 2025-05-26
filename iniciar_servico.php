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

    if (empty($input['id'])) {
        throw new Exception('ID do serviço é obrigatório', 400);
    }

    $conn->beginTransaction();
    $mecanico_id = $_SESSION['user_id'];
    $id = (int)$input['id'];

    // Verificar se o serviço existe e está como 'aceito', independentemente da origem
    $sql = "SELECT id, notificacao_id, ficha_id FROM servicos_mecanica 
            WHERE id = ? AND mecanico_id = ? AND status = 'aceito'";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([$id, $mecanico_id]);
    $servico = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$servico) {
        throw new Exception('Serviço não encontrado ou não está no estado "aceito"', 404);
    }

    // Atualizar o serviço para 'em_andamento'
    $stmt = $conn->prepare("
        UPDATE servicos_mecanica 
        SET status = 'em_andamento', 
            inicio_servico = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$servico['id']]);

    // Atualizar a origem (notificação ou ficha)
    if ($servico['notificacao_id']) {
        $stmt = $conn->prepare("UPDATE notificacoes SET status = 'em_andamento' WHERE id = ?");
        $stmt->execute([$servico['notificacao_id']]);
    } elseif ($servico['ficha_id']) {
        $stmt = $conn->prepare("UPDATE ficha_defeito SET status = 'em_andamento' WHERE id = ?");
        $stmt->execute([$servico['ficha_id']]);
    }

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Serviço iniciado com sucesso',
        'service_id' => $servico['id'],
        'start_time' => date('Y-m-d H:i:s')
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