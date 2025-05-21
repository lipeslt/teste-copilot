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
        $stmt = $conn->prepare("SELECT veiculo, tipo, status, secretaria FROM veiculos");
        $stmt->execute();
    } else {
        // Query para veículos de uma secretaria específica
        $stmt = $conn->prepare("SELECT veiculo, tipo, status, secretaria FROM veiculos WHERE secretaria = ?");
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
        
        // Buscar o registro mais recente (em uso)
        $stmt = $conn->prepare("SELECT r.nome, r.cpf, r.destino, r.data, r.hora, r.hora_final 
                              FROM registros r
                              WHERE r.veiculo_id = ? AND r.hora_final IS NULL
                              ORDER BY r.data DESC, r.hora DESC LIMIT 1");
        $stmt->execute([$veiculo['veiculo']]);
        $registroEmUso = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Buscar o último registro completo (finalizado)
        $stmt = $conn->prepare("SELECT r.nome, r.cpf, r.destino, r.data, r.hora, r.data_final, r.hora_final 
                              FROM registros r
                              WHERE r.veiculo_id = ? AND r.hora_final IS NOT NULL
                              ORDER BY r.data_final DESC, r.hora_final DESC LIMIT 1");
        $stmt->execute([$veiculo['veiculo']]);
        $ultimoRegistro = $stmt->fetch(PDO::FETCH_ASSOC);

        // Preencher dados
        if ($veiculo['status'] === 'em uso' && $registroEmUso) {
            $veiculo['motorista'] = $registroEmUso['nome'] ?? '-';
            $veiculo['cpf'] = $registroEmUso['cpf'] ?? '-';
            $veiculo['destino'] = $registroEmUso['destino'] ?? '-';
            $veiculo['data'] = $registroEmUso['data'] ?? '-';
            $veiculo['hora_saida'] = $registroEmUso['hora'] ?? '-';
            $veiculo['ponto_parada'] = '-';
        } else {
            $veiculo['motorista'] = '-';
            $veiculo['cpf'] = '-';
            $veiculo['destino'] = '-';
            $veiculo['data'] = '-';
            $veiculo['hora_saida'] = '-';
            $veiculo['ponto_parada'] = $ultimoRegistro ? $ultimoRegistro['destino'] : '-';
            $veiculo['ultimo_motorista'] = $ultimoRegistro ? $ultimoRegistro['nome'] : '-';
            $veiculo['ultimo_cpf'] = $ultimoRegistro ? $ultimoRegistro['cpf'] : '-';
            $veiculo['ultima_data'] = $ultimoRegistro ? $ultimoRegistro['data_final'] : '-';
            $veiculo['ultima_hora'] = $ultimoRegistro ? $ultimoRegistro['hora_final'] : '-';
        }
    }

    echo json_encode($veiculos);

} catch (PDOException $e) {
    echo json_encode(['error' => 'Erro no banco de dados: ' . $e->getMessage()]);
}