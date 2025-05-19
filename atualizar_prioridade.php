<?php
require 'db_connection.php';

header('Content-Type: application/json');
session_start();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método não permitido');
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON inválido');
    }

    if (!isset($data['pedido_id']) || !isset($data['prioridade'])) {
        throw new Exception('Dados incompletos');
    }

    $pedido_id = $data['pedido_id'];
    $prioridade = $data['prioridade'];
    
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Usuário não autenticado');
    }
    $mecanico_id = $_SESSION['user_id'];

    // Verificar se o pedido pertence ao mecânico
    $stmtCheck = $conn->prepare("SELECT id FROM servicos_mecanica WHERE id = :pedido_id AND mecanico_id = :mecanico_id");
    $stmtCheck->bindParam(':pedido_id', $pedido_id);
    $stmtCheck->bindParam(':mecanico_id', $mecanico_id);
    $stmtCheck->execute();
    
    if ($stmtCheck->rowCount() === 0) {
        throw new Exception('Pedido não encontrado ou não pertence ao usuário');
    }

    $query = "UPDATE servicos_mecanica SET prioridade = :prioridade WHERE id = :pedido_id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':prioridade', $prioridade);
    $stmt->bindParam(':pedido_id', $pedido_id);
    $stmt->execute();

    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit();
}
?>