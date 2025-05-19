<?php
header('Content-Type: application/json');
require 'conexao.php';

session_start();

// Verifica permissões
if (!isset($_SESSION['role'])) {
    echo json_encode(['success' => false, 'message' => 'Acesso não autorizado']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id = $data['id'] ?? null;
$status = $data['status'] ?? null;

// Validações
if (!$id || !in_array($status, ['ativo', 'bloqueado'])) {
    echo json_encode(['success' => false, 'message' => 'Dados inválidos']);
    exit;
}

try {
    // Atualiza status e registra quem alterou
    $stmt = $conn->prepare("UPDATE veiculos SET status = :status, atualizado_por = :usuario, atualizado_em = NOW() WHERE id = :id");
    $stmt->bindParam(':status', $status);
    $stmt->bindParam(':usuario', $_SESSION['username']);
    $stmt->bindParam(':id', $id);
    $stmt->execute();

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Erro ao atualizar status: ' . $e->getMessage()]);
}
?>