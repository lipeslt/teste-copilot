<?php
require 'db_connection.php';

header('Content-Type: application/json');
session_start();

try {
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Usuário não autenticado');
    }
    $mecanico_id = $_SESSION['user_id'];

    $query = "SELECT s.*, 
              CASE 
                  WHEN s.notificacao_id IS NOT NULL THEN 'notificacao'
                  WHEN s.ficha_id IS NOT NULL THEN 'ficha'
                  ELSE 'pedido'
              END AS tipo_origem,
              n.prefixo, n.secretaria, n.mensagem,
              f.veiculo AS ficha_veiculo, f.secretaria AS ficha_secretaria
              FROM servicos_mecanica s
              LEFT JOIN notificacoes n ON s.notificacao_id = n.id
              LEFT JOIN ficha_defeito f ON s.ficha_id = f.id
              WHERE s.mecanico_id = :mecanico_id AND s.status IN ('aceito', 'em_andamento')
              ORDER BY 
                CASE WHEN s.status = 'em_andamento' THEN 0 ELSE 1 END,
                s.inicio_servico DESC";

    $stmt = $conn->prepare($query);
    $stmt->bindParam(':mecanico_id', $mecanico_id);
    $stmt->execute();
    $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'pedidos' => $pedidos]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    exit();
}
?>