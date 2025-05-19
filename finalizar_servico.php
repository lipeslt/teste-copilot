<?php
require 'db_connection.php';

header('Content-Type: application/json');
session_start();

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['pedido_id']) || !isset($data['finish_time']) || !isset($data['total_time'])) {
        throw new Exception('Dados incompletos');
    }

    $pedido_id = $data['pedido_id'];
    $finish_time = $data['finish_time'];
    $total_time = $data['total_time'];
    
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Usuário não autenticado');
    }
    $mecanico_id = $_SESSION['user_id'];
    
    // Verifica se o pedido pertence ao mecânico
    $stmtCheck = $conn->prepare("SELECT id FROM servicos_mecanica 
                               WHERE id = :pedido_id AND mecanico_id = :mecanico_id AND status = 'em_andamento'");
    $stmtCheck->bindParam(':pedido_id', $pedido_id);
    $stmtCheck->bindParam(':mecanico_id', $mecanico_id);
    $stmtCheck->execute();
    
    if ($stmtCheck->rowCount() === 0) {
        throw new Exception('Pedido não encontrado ou não está em andamento');
    }

    // Atualiza o status para 'finalizado'
    $stmt = $conn->prepare("UPDATE servicos_mecanica 
                           SET status = 'finalizado', 
                               fim_servico = :finish_time, 
                               tempo_total = :total_time,
                               updated_at = NOW()
                           WHERE id = :pedido_id");
    
    $stmt->bindParam(':pedido_id', $pedido_id);
    $stmt->bindParam(':finish_time', $finish_time);
    $stmt->bindParam(':total_time', $total_time);
    $stmt->execute();

    echo json_encode(['success' => true, 'message' => 'Serviço finalizado com sucesso']);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit();
}
?>