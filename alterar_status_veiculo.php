<?php
session_start();
header('Content-Type: application/json');
require 'conexao.php';

// Verifica se a sessão está ativa e se o usuário está logado
if (!isset($_SESSION['role']) || !isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Acesso não autorizado. Faça login para continuar.']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
$id = $data['id'] ?? null;
$status = $data['status'] ?? null;

// Tenta obter o nome do usuário de várias possíveis chaves de sessão
$usuario = $_SESSION['name'] ?? $_SESSION['nome'] ?? $_SESSION['username'] ?? $_SESSION['user_name'] ?? 'Usuário Desconhecido';

// Remove possíveis tags HTML e limita a 100 caracteres
$usuario = substr(htmlspecialchars(strip_tags($usuario)), 0, 100);

if (!$id || !in_array($status, ['ativo', 'bloqueado'])) {
    echo json_encode(['success' => false, 'message' => 'Dados inválidos']);
    exit;
}

try {
    $conn->beginTransaction();

    // Obtém dados do veículo
    $stmt = $conn->prepare("SELECT veiculo, placa FROM veiculos WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $veiculo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$veiculo) {
        throw new Exception("Veículo não encontrado");
    }

    // Atualiza status
    $stmt = $conn->prepare("UPDATE veiculos SET status = :status WHERE id = :id");
    $stmt->execute([':status' => $status, ':id' => $id]);

    // Registra no histórico
    $acao = ($status === 'bloqueado') ? 'bloqueio' : 'desbloqueio';
    $stmt = $conn->prepare("INSERT INTO historico_bloqueio 
                          (veiculo_id, acao, realizado_por, data_hora) 
                          VALUES (:veiculo_id, :acao, :realizado_por, NOW())");
    $stmt->execute([
        ':veiculo_id' => $id,
        ':acao' => $acao,
        ':realizado_por' => $usuario
    ]);

    $conn->commit();
    echo json_encode([
        'success' => true,
        'debug' => [ // Adicionado para debug - remova em produção
            'session' => $_SESSION,
            'usuario_capturado' => $usuario
        ]
    ]);

} catch (PDOException $e) {
    $conn->rollBack();
    echo json_encode([
        'success' => false, 
        'message' => 'Erro ao atualizar status: ' . $e->getMessage(),
        'error_debug' => $e->getTraceAsString() // Adicionado para debug
    ]);
} catch (Exception $e) {
    $conn->rollBack();
    echo json_encode([
        'success' => false, 
        'message' => $e->getMessage()
    ]);
}