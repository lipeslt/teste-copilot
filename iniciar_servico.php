<?php
require 'db_connection.php';

header('Content-Type: application/json');
session_start();

// Verificação de autenticação
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Não autenticado']));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die(json_encode(['success' => false, 'message' => 'Método não permitido']));
}

$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'JSON inválido']));
}

if (empty($input['tipo']) || empty($input['id'])) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => 'Dados incompletos']));
}

try {
    $conn->beginTransaction();
    $mecanico_id = $_SESSION['user_id'];
    $tipo = $input['tipo'];
    $id = $input['id'];

    // Verificar se o serviço existe e está como 'aceito'
    $sql = "SELECT id FROM servicos_mecanica 
            WHERE mecanico_id = ? AND status = 'aceito' AND ";
    
    if ($tipo === 'notificacao') {
        $sql .= "notificacao_id = ?";
    } elseif ($tipo === 'ficha') {
        $sql .= "ficha_id = ?";
    } else {
        throw new Exception('Tipo de serviço inválido');
    }

    $stmt = $conn->prepare($sql);
    $stmt->execute([$mecanico_id, $id]);

    if ($stmt->rowCount() === 0) {
        throw new Exception('Serviço não encontrado ou não está no estado correto');
    }

    // Atualizar o serviço para 'em_andamento'
    $stmt = $conn->prepare("
        UPDATE servicos_mecanica 
        SET status = 'em_andamento', inicio_servico = NOW() 
        WHERE mecanico_id = ? AND " . ($tipo === 'notificacao' ? "notificacao_id" : "ficha_id") . " = ?
    ");
    $stmt->execute([$mecanico_id, $id]);

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
        'message' => 'Serviço iniciado com sucesso'
    ]);

} catch (Exception $e) {
    $conn->rollBack();
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>