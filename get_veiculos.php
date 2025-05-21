<?php
session_start();
header('Content-Type: application/json');

$host = "localhost";
$dbname = "workflow_system";
$username = "root";
$password = "";

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Verifica se é para retornar todos os veículos
    $todos = isset($_GET['todos']) && $_GET['todos'] == 1;
    $secretaria = $_GET['secretaria'] ?? '';

    if ($todos) {
        // Query para todos os veículos
        $stmt = $conn->prepare("SELECT veiculo, tipo, status FROM veiculos");
        $stmt->execute();
    } else {
        // Query para veículos de uma secretaria específica
        $stmt = $conn->prepare("SELECT veiculo, tipo, status FROM veiculos WHERE secretaria = ?");
        $stmt->execute([$secretaria]);
    }

    $veiculos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($veiculos)) {
        echo json_encode(['error' => 'Nenhum veículo encontrado']);
        exit;
    }

    foreach ($veiculos as &$veiculo) {
        // Mapear status
        $veiculo['status'] = ($veiculo['status'] === "ativo") ? "livre" : $veiculo['status'];
        
        // Buscar o registro mais recente (em uso) - EXATAMENTE COMO NO ORIGINAL
        $stmt = $conn->prepare("SELECT nome, destino, hora FROM registros 
                              WHERE veiculo_id = ? AND hora_final IS NULL
                              ORDER BY data DESC, hora DESC LIMIT 1");
        $stmt->execute([$veiculo['veiculo']]);
        $registroEmUso = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Buscar o último registro completo (finalizado) - EXATAMENTE COMO NO ORIGINAL
        $stmt = $conn->prepare("SELECT destino FROM registros 
                              WHERE veiculo_id = ? 
                              ORDER BY data DESC, hora DESC LIMIT 1");
        $stmt->execute([$veiculo['veiculo']]);
        $ultimoRegistro = $stmt->fetch(PDO::FETCH_ASSOC);

        // Preencher dados - MANTENDO A MESMA LÓGICA DO ORIGINAL
        if ($veiculo['status'] === 'em uso') {
            $veiculo['motorista'] = $registroEmUso['nome'] ?? '-';
            $veiculo['destino'] = $registroEmUso['destino'] ?? '-';
            $veiculo['hora_saida'] = $registroEmUso['hora'] ?? '-';
            $veiculo['ponto_parada'] = '-';
        } else {
            $veiculo['motorista'] = '-';
            $veiculo['destino'] = '-';
            $veiculo['hora_saida'] = '-';
            $veiculo['ponto_parada'] = $ultimoRegistro['destino'] ?? '-';
        }
    }

    echo json_encode($veiculos);

} catch (PDOException $e) {
    echo json_encode(['error' => 'Erro no banco de dados: ' . $e->getMessage()]);
}