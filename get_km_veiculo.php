<?php
require_once '../conexao.php';

if(isset($_GET['id'])) {
    $veiculo_id = $_GET['id'];
    
    // Busca último km registrado
    $sql = "SELECT MAX(km_final) as ultimo_km 
            FROM usuarios 
            WHERE veiculo_id = ? AND km_final IS NOT NULL AND km_final > 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $veiculo_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    
    // Busca intervalo de km configurado
    $sql_intervalo = "SELECT intervalo_km_troca FROM veiculos WHERE id = ?";
    $stmt_intervalo = $conn->prepare($sql_intervalo);
    $stmt_intervalo->bind_param('i', $veiculo_id);
    $stmt_intervalo->execute();
    $result_intervalo = $stmt_intervalo->get_result();
    $row_intervalo = $result_intervalo->fetch_assoc();
    
    $response = [
        'ultimo_km' => $row['ultimo_km'] ?? 0,
        'intervalo' => $row_intervalo['intervalo_km_troca'] ?? 10000
    ];
    
    header('Content-Type: application/json');
    echo json_encode($response);
} else {
    header('HTTP/1.1 400 Bad Request');
    echo json_encode(['error' => 'ID do veículo não informado']);
}