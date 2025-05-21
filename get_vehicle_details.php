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

    // Obter o prefixo do veículo da requisição
    $prefixo = $_GET['prefixo'] ?? '';

    if (empty($prefixo)) {
        echo json_encode(['error' => 'Prefixo do veículo não fornecido']);
        exit;
    }

    // Buscar informações do veículo
    $stmt = $conn->prepare("SELECT v.veiculo, v.tipo, v.status, v.secretaria, v.prefixo, v.descricao 
                          FROM veiculos v 
                          WHERE v.prefixo = ?");
    $stmt->execute([$prefixo]);
    $veiculo = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$veiculo) {
        echo json_encode(['error' => 'Veículo não encontrado']);
        exit;
    }

    // Mapear status
    $veiculo['status'] = ($veiculo['status'] === "ativo") ? "livre" : $veiculo['status'];
    
    // Se o veículo estiver em uso, buscar os detalhes do uso atual
    if ($veiculo['status'] === 'em uso') {
        $stmt = $conn->prepare("SELECT r.nome as motorista, r.cpf, r.destino, r.data, r.hora as hora_saida 
                              FROM registros r
                              WHERE r.veiculo_id = ? AND r.hora_final IS NULL
                              ORDER BY r.data DESC, r.hora DESC LIMIT 1");
        $stmt->execute([$veiculo['veiculo']]);
        $registroAtual = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($registroAtual) {
            foreach ($registroAtual as $key => $value) {
                $veiculo[$key] = $value;
            }
        }
    } else {
        // Se o veículo estiver livre, buscar o último uso finalizado
        $stmt = $conn->prepare("SELECT r.nome as motorista, r.cpf, r.destino, r.data, r.hora as hora_saida, 
                               r.data_final, r.hora_final as hora_retorno, r.km_final - r.km_inicial as km_percorridos
                              FROM registros r
                              WHERE r.veiculo_id = ? AND r.hora_final IS NOT NULL
                              ORDER BY r.data_final DESC, r.hora_final DESC LIMIT 1");
        $stmt->execute([$veiculo['veiculo']]);
        $ultimoRegistro = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Adicionar informações do último uso como um objeto separado
        $veiculo['ultimo_uso'] = $ultimoRegistro ? [
            'motorista' => $ultimoRegistro['motorista'],
            'cpf' => $ultimoRegistro['cpf'],
            'destino' => $ultimoRegistro['destino'],
            'data_saida' => $ultimoRegistro['data'],
            'hora_saida' => $ultimoRegistro['hora_saida'],
            'data_retorno' => $ultimoRegistro['data_final'],
            'hora_retorno' => $ultimoRegistro['hora_retorno'],
            'km_percorridos' => $ultimoRegistro['km_percorridos']
        ] : null;
    }

    // Buscar média de consumo do veículo
    $stmt = $conn->prepare("SELECT AVG((a.km_atual - a.km_anterior) / a.litros) as media_consumo
                          FROM abastecimentos a
                          WHERE a.prefixo_veiculo = ?
                          GROUP BY a.prefixo_veiculo");
    $stmt->execute([$prefixo]);
    $consumoResult = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($consumoResult && $consumoResult['media_consumo']) {
        $veiculo['media_consumo'] = round($consumoResult['media_consumo'], 2);
    } else {
        $veiculo['media_consumo'] = 0;
    }

    echo json_encode($veiculo);

} catch (PDOException $e) {
    echo json_encode(['error' => 'Erro no banco de dados: ' . $e->getMessage()]);
}