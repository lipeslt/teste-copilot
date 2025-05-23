<?php
include 'conexao.php';

header('Content-Type: application/json');

if (isset($_GET['veiculo_id'])) {
    $veiculo_id = trim($_GET['veiculo_id']);
    
    // Buscar o último registro deste veículo ordenado por data e hora
    $query = "SELECT km_final FROM registros 
              WHERE veiculo_id = :veiculo_id 
              ORDER BY data DESC, hora DESC 
              LIMIT 1";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':veiculo_id', $veiculo_id);
    $stmt->execute();
    
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode($resultado);
} else {
    echo json_encode(['error' => 'Veículo não informado']);
}
?>