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

    if (empty($input['tipo']) || empty($input['id'])) {
        throw new Exception('Dados incompletos: tipo e id são obrigatórios', 400);
    }

    $conn->beginTransaction();
    $mecanico_id = $_SESSION['user_id'];
    $tipo = $input['tipo'];
    $id = (int)$input['id'];

    // Verificar se o serviço existe e está como 'aceito'
    $sql = "SELECT id, notificacao_id, ficha_id FROM servicos_mecanica 
            WHERE mecanico_id = ? AND status = 'aceito' AND (";
    
    if ($tipo === 'notificacao') {
        $sql .= "notificacao_id = ?)";
    } elseif ($tipo === 'ficha') {
        $sql .= "ficha_id = ?)";
    } else {
        throw new Exception('Tipo de serviço inválido. Use "notificacao" ou "ficha"', 400);
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute([$mecanico_id, $id]);
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
    if ($tipo === 'notificacao') {
        $stmt = $conn->prepare("UPDATE notificacoes SET status = 'em_andamento' WHERE id = ?");
    } else {
        $stmt = $conn->prepare("UPDATE ficha_defeito SET status = 'em_andamento' WHERE id = ?");
    }
    $stmt->execute([$id]);

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Serviço iniciado com sucesso',
        'service_id' => $servico['id']
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    http_response_code($e->getCode() ?: 400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => $e->getCode()
    ]);
    exit();
}
?>