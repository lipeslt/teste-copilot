<?php
require 'db_connection.php';
header('Content-Type: application/json');
session_start();

try {
    if (empty($_SESSION['user_id'])) {
        throw new Exception('Usuário não autenticado', 401);
    }

    $mecanico_id = $_SESSION['user_id'];

    $query = "
        SELECT 
            sm.*,
            CASE 
                WHEN sm.ficha_id IS NOT NULL THEN 'ficha'
                WHEN sm.notificacao_id IS NOT NULL THEN 'notificacao'
                ELSE 'pedido'
            END AS tipo_origem,
            -- Dados da Notificação
            n.mensagem as notificacao_mensagem,
            n.data as notificacao_data,
            n.status as notificacao_status,
            n.prefixo as notificacao_prefixo,
            n.secretaria as notificacao_secretaria,
            -- Dados da Ficha
            f.data as ficha_data,
            f.hora as ficha_hora,
            f.nome as ficha_nome,
            f.nome_veiculo as ficha_nome_veiculo,
            f.secretaria as ficha_secretaria,
            f.suspensao, f.obs_suspensao,
            f.motor, f.obs_motor,
            f.freios, f.obs_freios,
            f.direcao, f.obs_direcao,
            f.sistema_eletrico, f.obs_sistema_eletrico,
            f.carroceria, f.obs_carroceria,
            f.embreagem, f.obs_embreagem,
            f.rodas, f.obs_rodas,
            f.transmissao_9500, f.obs_transmissao_9500,
            f.caixa_mudancas, f.obs_caixa_mudancas,
            f.alimentacao, f.obs_alimentacao,
            f.arrefecimento, f.obs_arrefecimento
        FROM servicos_mecanica sm
        LEFT JOIN notificacoes n ON sm.notificacao_id = n.id
        LEFT JOIN ficha_defeito f ON sm.ficha_id = f.id
        WHERE sm.mecanico_id = :mecanico_id 
        AND sm.status IN ('aceito', 'em_andamento')";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':mecanico_id', $mecanico_id);
    $stmt->execute();
    $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'pedidos' => $pedidos
    ]);

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
    exit();
}
?>