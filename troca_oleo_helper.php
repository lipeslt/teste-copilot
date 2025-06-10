<?php
/**
 * Funções auxiliares para o sistema de troca de óleo
 */

/**
 * Registra uma troca de óleo e atualiza o veículo
 *
 * @param mysqli $conn Conexão com o banco de dados
 * @param int $veiculo_id ID do veículo
 * @param float $km_atual Quilometragem atual
 * @param float $intervalo_km Intervalo em km para próxima troca
 * @param int $intervalo_dias Intervalo em dias para próxima troca
 * @param string $observacao Observações opcionais
 * @param string $usuario Nome do usuário que registrou
 * @return array Status da operação e mensagem
 */
function registrarTrocaOleo($conn, $veiculo_id, $km_atual, $intervalo_km, $intervalo_dias, $observacao = '', $usuario = '') {
    $conn->begin_transaction();
    
    try {
        // Calcula próxima troca
        $km_proxima = $km_atual + $intervalo_km;
        $data_proxima = date('Y-m-d', strtotime("+$intervalo_dias days"));
        
        // Atualiza o veículo
        $sql = "UPDATE veiculos SET 
                km_ultima_troca = ?,
                data_ultima_troca = CURRENT_DATE(),
                km_proxima_troca = ?,
                data_proxima_troca = ?,
                intervalo_km_troca = ?
                WHERE id = ?";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ddsdi', $km_atual, $km_proxima, $data_proxima, $intervalo_km, $veiculo_id);
        $stmt->execute();
        
        // Registra no histórico
        $sql_historico = "INSERT INTO historico_trocas_oleo 
                         (veiculo_id, km_troca, data_troca, km_proxima, data_proxima, observacao, usuario_registro) 
                         VALUES (?, ?, CURRENT_DATE(), ?, ?, ?, ?)";
        
        $stmt_historico = $conn->prepare($sql_historico);
        $stmt_historico->bind_param('iddsss', $veiculo_id, $km_atual, $km_proxima, $data_proxima, $observacao, $usuario);
        $stmt_historico->execute();
        
        $conn->commit();
        return [
            'status' => true,
            'message' => 'Troca de óleo registrada com sucesso!'
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        return [
            'status' => false,
            'message' => 'Erro ao registrar troca de óleo: ' . $e->getMessage()
        ];
    }
}

/**
 * Obtém o último KM registrado para um veículo
 *
 * @param mysqli $conn Conexão com o banco de dados
 * @param int $veiculo_id ID do veículo
 * @return float Último KM registrado ou 0
 */
function getUltimoKm($conn, $veiculo_id) {
    $sql = "SELECT MAX(km_final) as ultimo_km 
            FROM usuarios 
            WHERE veiculo_id = ? AND km_final IS NOT NULL AND km_final > 0";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('s', $veiculo_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if($row = $result->fetch_assoc()) {
        return $row['ultimo_km'] ?: 0;
    }
    
    return 0;
}

/**
 * Obtém o histórico de trocas de óleo de um veículo
 *
 * @param mysqli $conn Conexão com o banco de dados
 * @param int $veiculo_id ID do veículo
 * @return array Lista de trocas de óleo ou array vazio
 */
function getHistoricoTrocas($conn, $veiculo_id) {
    $sql = "SELECT h.*, v.veiculo, v.placa 
            FROM historico_trocas_oleo h
            JOIN veiculos v ON h.veiculo_id = v.id
            WHERE h.veiculo_id = ?
            ORDER BY h.data_troca DESC";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $veiculo_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $historico = [];
    while($row = $result->fetch_assoc()) {
        $historico[] = $row;
    }
    
    return $historico;
}