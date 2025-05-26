<?php
// Iniciar sessão para controle de acesso
session_start();

// Configuração do banco de dados
$config = [
    'host' => 'localhost',
    'username' => 'root',
    'password' => '',
    'database' => 'workflow_system'
];

// Modo de Debug
define('DEBUG_MODE', true);

function debug($message) {
    if (DEBUG_MODE) {
        error_log($message);
    }
}

// Conexão com o banco de dados
function conectarBD() {
    global $config;

    try {
        debug("Tentando conectar ao banco de dados {$config['database']} em {$config['host']}");

        $conn = new mysqli($config['host'], $config['username'], $config['password'], $config['database']);

        if ($conn->connect_error) {
            $errorMsg = "Falha na conexão: " . $conn->connect_error;
            debug($errorMsg);
            throw new Exception($errorMsg);
        }

        $conn->set_charset("utf8mb4");
        debug("Conexão com o banco de dados estabelecida com sucesso");

        return $conn;
    } catch (Exception $e) {
        // Registra o erro em log
        $errorMsg = "Erro de conexão: " . $e->getMessage();
        error_log($errorMsg);

        // Retorna false em caso de falha
        return false;
    }
}

// Função para fechar a conexão
function fecharConexao($conn) {
    if ($conn) {
        $conn->close();
    }
}

// Função para executar consultas SQL com segurança
function executarConsulta($sql, $params = [], $tipos = "") {
    $conn = conectarBD();

    if (!$conn) {
        return ['erro' => 'Falha na conexão com o banco de dados'];
    }

    try {
        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new Exception("Erro ao preparar a consulta: " . $conn->error);
        }

        // Bind de parâmetros se houver
        if (!empty($params) && !empty($tipos)) {
            $stmt->bind_param($tipos, ...$params);
        }

        // Executa a consulta
        $stmt->execute();

        // Verifica se a consulta foi bem-sucedida
        if ($stmt->error) {
            throw new Exception("Erro ao executar a consulta: " . $stmt->error);
        }

        // Obtém o resultado se for uma consulta SELECT
        if (stripos($sql, 'SELECT') === 0) {
            $resultado = $stmt->get_result();
            $dados = [];

            while ($row = $resultado->fetch_assoc()) {
                $dados[] = $row;
            }

            $stmt->close();
            fecharConexao($conn);
            return $dados;
        } else {
            // Para INSERT, UPDATE ou DELETE retorna o ID do último insert ou número de linhas afetadas
            $lastId = $conn->insert_id;
            $affectedRows = $stmt->affected_rows;

            $stmt->close();
            fecharConexao($conn);

            return [
                'sucesso' => true,
                'ultimo_id' => $lastId,
                'linhas_afetadas' => $affectedRows
            ];
        }
    } catch (Exception $e) {
        // Registra o erro e retorna mensagem
        error_log($e->getMessage());

        if (isset($stmt)) {
            $stmt->close();
        }

        fecharConexao($conn);

        return ['erro' => $e->getMessage()];
    }
}

// Adicione esta função para verificar a estrutura das tabelas
function verificarEstruturaBanco() {
    $conn = conectarBD();

    if (!$conn) {
        return ['erro' => 'Falha na conexão com o banco de dados'];
    }

    try {
        // Verificar tabela veiculos
        $result = $conn->query("DESCRIBE veiculos");
        if (!$result) {
            throw new Exception("Tabela 'veiculos' não encontrada ou inacessível");
        }

        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'] . ' (' . $row['Type'] . ')';
        }
        debug("Estrutura da tabela 'veiculos': " . implode(", ", $columns));

        // Verificar tabela trocas_oleo
        $result = $conn->query("DESCRIBE trocas_oleo");
        if (!$result) {
            throw new Exception("Tabela 'trocas_oleo' não encontrada ou inacessível");
        }

        $columns = [];
        while ($row = $result->fetch_assoc()) {
            $columns[] = $row['Field'] . ' (' . $row['Type'] . ')';
        }
        debug("Estrutura da tabela 'trocas_oleo': " . implode(", ", $columns));

        fecharConexao($conn);
        return ['sucesso' => true];
    } catch (Exception $e) {
        fecharConexao($conn);
        return ['erro' => $e->getMessage()];
    }
}

// FUNÇÕES PARA VEÍCULOS

// Obter todos os veículos
function obterVeiculos() {
    $sql = "SELECT * FROM veiculos ORDER BY veiculo";
    return executarConsulta($sql);
}

// Obter veículos com filtros
function obterVeiculosComFiltro($busca = '', $status = '', $secretaria = '') {
    $sql = "SELECT * FROM veiculos WHERE 1=1";
    $params = [];
    $tipos = "";

    if (!empty($busca)) {
        $sql .= " AND (veiculo LIKE ? OR placa LIKE ?)";
        $busca = "%$busca%";
        $params[] = $busca;
        $params[] = $busca;
        $tipos .= "ss";
    }

    if (!empty($secretaria)) {
        $sql .= " AND secretaria = ?";
        $params[] = $secretaria;
        $tipos .= "s";
    }

    $sql .= " ORDER BY veiculo";

    $veiculos = executarConsulta($sql, $params, $tipos);

    // Filtrar por status se necessário (em dia, próxima, atrasada)
    if (!empty($status) && !empty($veiculos) && !isset($veiculos['erro'])) {
        $dataAtual = new DateTime();
        $veiculosFiltrados = [];

        foreach ($veiculos as $veiculo) {
            if (empty($veiculo['proxima_troca_oleo'])) {
                continue;
            }

            $proximaTroca = new DateTime($veiculo['proxima_troca_oleo']);
            $diasRestantes = $dataAtual->diff($proximaTroca)->days;
            $passouTroca = $proximaTroca < $dataAtual;

            if ($status === 'em_dia' && !$passouTroca && $diasRestantes > 15) {
                $veiculosFiltrados[] = $veiculo;
            } elseif ($status === 'proxima' && !$passouTroca && $diasRestantes <= 15) {
                $veiculosFiltrados[] = $veiculo;
            } elseif ($status === 'atrasada' && $passouTroca) {
                $veiculosFiltrados[] = $veiculo;
            }
        }

        return $veiculosFiltrados;
    }

    return $veiculos;
}

// Obter um veículo por ID
function obterVeiculo($id) {
    $sql = "SELECT * FROM veiculos WHERE id = ?";
    $params = [$id];
    $tipos = "i";

    $resultados = executarConsulta($sql, $params, $tipos);

    if (!empty($resultados) && !isset($resultados['erro'])) {
        return $resultados[0];
    }

    return ['erro' => 'Veículo não encontrado'];
}

// Adicionar um novo veículo
function adicionarVeiculo($dados) {
    $sql = "INSERT INTO veiculos (veiculo, combustivel, matricula, placa, renavam, chassi, marca,
            ano_modelo, tipo, secretaria, status, tanque, proxima_troca_oleo, proximo_km_troca)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $params = [
        $dados['veiculo'],
        $dados['combustivel'],
        $dados['matricula'] ?? null,
        $dados['placa'],
        $dados['renavam'] ?? null,
        $dados['chassi'] ?? null,
        $dados['marca'],
        $dados['ano_modelo'],
        $dados['tipo'],
        $dados['secretaria'],
        $dados['status'],
        $dados['tanque'],
        $dados['proxima_troca_oleo'] ?? null,
        $dados['proximo_km_troca'] ?? 0
    ];

    $tipos = "sssssssssssis";

    return executarConsulta($sql, $params, $tipos);
}

// Atualizar um veículo existente
function atualizarVeiculo($id, $dados) {
    $sql = "UPDATE veiculos SET
            veiculo = ?,
            combustivel = ?,
            matricula = ?,
            placa = ?,
            renavam = ?,
            chassi = ?,
            marca = ?,
            ano_modelo = ?,
            tipo = ?,
            secretaria = ?,
            status = ?,
            tanque = ?";

    $params = [
        $dados['veiculo'],
        $dados['combustivel'],
        $dados['matricula'] ?? null,
        $dados['placa'],
        $dados['renavam'] ?? null,
        $dados['chassi'] ?? null,
        $dados['marca'],
        $dados['ano_modelo'],
        $dados['tipo'],
        $dados['secretaria'],
        $dados['status'],
        $dados['tanque']
    ];

    $tipos = "ssssssssssi";

    // Adicionar parâmetros opcionais se fornecidos
    if (isset($dados['proxima_troca_oleo'])) {
        $sql .= ", proxima_troca_oleo = ?";
        $params[] = $dados['proxima_troca_oleo'];
        $tipos .= "s";
    }

    if (isset($dados['proximo_km_troca'])) {
        $sql .= ", proximo_km_troca = ?";
        $params[] = $dados['proximo_km_troca'];
        $tipos .= "i";
    }

    $sql .= " WHERE id = ?";
    $params[] = $id;
    $tipos .= "i";

    return executarConsulta($sql, $params, $tipos);
}

// Excluir um veículo
function excluirVeiculo($id) {
    // Primeiro, exclui as trocas de óleo associadas
    $sqlTrocas = "DELETE FROM trocas_oleo WHERE veiculo_id = ?";
    executarConsulta($sqlTrocas, [$id], "i");

    // Depois, exclui o veículo
    $sql = "DELETE FROM veiculos WHERE id = ?";
    return executarConsulta($sql, [$id], "i");
}

// Atualizar km atual e datas de troca
function atualizarKmTrocaOleo($id, $kmAtual, $ultimaTroca = null, $proximaTroca = null, $proximoKmTroca = null) {
    $sql = "UPDATE veiculos SET km_atual = ?";
    $params = [$kmAtual];
    $tipos = "i";

    if ($ultimaTroca !== null) {
        $sql .= ", ultima_troca = ?";
        $params[] = $ultimaTroca;
        $tipos .= "s";
    }

    if ($proximaTroca !== null) {
        $sql .= ", proxima_troca_oleo = ?";
        $params[] = $proximaTroca;
        $tipos .= "s";
    }

    if ($proximoKmTroca !== null) {
        $sql .= ", proximo_km_troca = ?";
        $params[] = $proximoKmTroca;
        $tipos .= "i";
    }

    $sql .= " WHERE id = ?";
    $params[] = $id;
    $tipos .= "i";

    return executarConsulta($sql, $params, $tipos);
}

// FUNÇÕES PARA TROCAS DE ÓLEO

// Registrar uma nova troca de óleo
function registrarTrocaOleo($dados) {
    $conn = conectarBD();

    if (!$conn) {
        return ['erro' => 'Falha na conexão com o banco de dados'];
    }

    try {
        // Iniciar transação
        $conn->begin_transaction();

        // Verificar se a tabela tem a estrutura correta
        $checkTable = $conn->query("SHOW COLUMNS FROM trocas_oleo");
        $columns = [];
        while ($col = $checkTable->fetch_assoc()) {
            $columns[] = $col['Field'];
        }

        // Construir consulta dinâmica com base nas colunas existentes
        $fields = [];
        $placeholders = [];
        $values = [];
        $types = "";

        // Mapeamento de dados para campos da tabela
        $dataMapping = [
            'veiculo_id' => ['value' => $dados['veiculo_id'], 'type' => 'i'],
            'data_agendamento' => ['value' => $dados['data_agendamento'] ?? date('Y-m-d'), 'type' => 's'],
            'data_realizacao' => ['value' => $dados['data_realizacao'], 'type' => 's'],
            'realizado_por' => ['value' => $dados['realizado_por'] ?? null, 'type' => 'i'],
            'km' => ['value' => $dados['km'], 'type' => 'i'],
            'tipo_oleo' => ['value' => $dados['tipo_oleo'], 'type' => 's'],
            'quantidade' => ['value' => $dados['quantidade'], 'type' => 's'],
            'proxima_troca' => ['value' => $dados['proxima_troca'], 'type' => 's'],
            'proximo_km' => ['value' => $dados['proximo_km'], 'type' => 'd'],
            'observacoes' => ['value' => $dados['observacoes'] ?? null, 'type' => 's']
        ];

        // Verificar quais campos existem na tabela e adicioná-los à consulta
        foreach ($dataMapping as $field => $data) {
            if (in_array($field, $columns)) {
                $fields[] = $field;
                $placeholders[] = '?';
                $values[] = $data['value'];
                $types .= $data['type'];
            }
        }

        if (empty($fields)) {
            throw new Exception("Nenhum campo válido para inserção");
        }

        // Inserir registro de troca
        $sql = "INSERT INTO trocas_oleo (" . implode(", ", $fields) . ") VALUES (" . implode(", ", $placeholders) . ")";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            throw new Exception("Erro ao preparar a consulta: " . $conn->error);
        }

        // Bind dinâmico de parâmetros
        if (!empty($values)) {
            $stmt->bind_param($types, ...$values);
        }

        $stmt->execute();

        if ($stmt->error) {
            throw new Exception("Erro ao registrar troca de óleo: " . $stmt->error);
        }

        $insertId = $conn->insert_id;

        // Atualizar informações do veículo
        $sqlUpdateVeiculo = "UPDATE veiculos SET
                            ultima_troca = ?,
                            km_ultima_troca = ?,
                            km_atual = ?,
                            proxima_troca_oleo = ?,
                            proximo_km_troca = ?
                            WHERE id = ?";

        $stmtVeiculo = $conn->prepare($sqlUpdateVeiculo);

        if (!$stmtVeiculo) {
            throw new Exception("Erro ao preparar a atualização do veículo: " . $conn->error);
        }

        $stmtVeiculo->bind_param("siiisi",
            $dados['data_realizacao'],
            $dados['km'],
            $dados['km'],
            $dados['proxima_troca'],
            $dados['proximo_km'],
            $dados['veiculo_id']
        );

        $stmtVeiculo->execute();

        if ($stmtVeiculo->error) {
            throw new Exception("Erro ao atualizar veículo: " . $stmtVeiculo->error);
        }

        // Commit da transação
        $conn->commit();

        $stmt->close();
        $stmtVeiculo->close();
        fecharConexao($conn);

        return [
            'sucesso' => true,
            'id' => $insertId
        ];
    } catch (Exception $e) {
        // Rollback em caso de erro
        $conn->rollback();

        // Log do erro
        error_log($e->getMessage());

        fecharConexao($conn);
        return ['erro' => $e->getMessage()];
    }
}

// Obter histórico de trocas de óleo
function obterHistoricoTrocas($filtros = []) {
    $sql = "SELECT t.*, v.veiculo, v.placa
            FROM trocas_oleo t
            INNER JOIN veiculos v ON t.veiculo_id = v.id
            WHERE 1=1";

    $params = [];
    $tipos = "";

    // Filtro por veículo
    if (!empty($filtros['veiculo_id'])) {
        $sql .= " AND t.veiculo_id = ?";
        $params[] = $filtros['veiculo_id'];
        $tipos .= "i";
    }

    // Filtro por data inicial
    if (!empty($filtros['data_inicial'])) {
        $sql .= " AND t.data_realizacao >= ?";
        $params[] = $filtros['data_inicial'];
        $tipos .= "s";
    }

    // Filtro por data final
    if (!empty($filtros['data_final'])) {
        $sql .= " AND t.data_realizacao <= ?";
        $params[] = $filtros['data_final'];
        $tipos .= "s";
    }

    // Ordenação
    $sql .= " ORDER BY t.data_realizacao DESC";

    return executarConsulta($sql, $params, $tipos);
}

// Obter próximas trocas de óleo (para calendário)
function obterProximasTrocas($dataInicial = null, $dataFinal = null) {
    if ($dataInicial === null) {
        $dataInicial = date('Y-m-d');
    }

    if ($dataFinal === null) {
        $dataFinal = date('Y-m-d', strtotime('+3 months'));
    }

    $sql = "SELECT v.id, v.veiculo, v.placa, v.proxima_troca_oleo, v.secretaria
            FROM veiculos v
            WHERE v.proxima_troca_oleo BETWEEN ? AND ?
            ORDER BY v.proxima_troca_oleo";

    $params = [$dataInicial, $dataFinal];
    $tipos = "ss";

    return executarConsulta($sql, $params, $tipos);
}

// Obter estatísticas para o dashboard
function obterEstatisticas() {
    $conn = conectarBD();

    if (!$conn) {
        return ['erro' => 'Falha na conexão com o banco de dados'];
    }

    try {
        // Total de veículos
        $sqlTotal = "SELECT COUNT(*) as total FROM veiculos";
        $resultTotal = $conn->query($sqlTotal);
        $totalVeiculos = $resultTotal->fetch_assoc()['total'];

        // Data atual
        $dataAtual = date('Y-m-d');

        // Veículos com troca em dia (mais de 15 dias para vencer)
        $sqlEmDia = "SELECT COUNT(*) as total FROM veiculos
                    WHERE proxima_troca_oleo > DATE_ADD('$dataAtual', INTERVAL 15 DAY)";
        $resultEmDia = $conn->query($sqlEmDia);
        $veiculosEmDia = $resultEmDia->fetch_assoc()['total'];

        // Veículos com troca próxima (menos de 15 dias para vencer)
        $sqlProxima = "SELECT COUNT(*) as total FROM veiculos
                      WHERE proxima_troca_oleo BETWEEN '$dataAtual' AND DATE_ADD('$dataAtual', INTERVAL 15 DAY)";
        $resultProxima = $conn->query($sqlProxima);
        $veiculosProxima = $resultProxima->fetch_assoc()['total'];

        // Veículos com troca atrasada
        $sqlAtrasada = "SELECT COUNT(*) as total FROM veiculos
                       WHERE proxima_troca_oleo < '$dataAtual'";
        $resultAtrasada = $conn->query($sqlAtrasada);
        $veiculosAtrasada = $resultAtrasada->fetch_assoc()['total'];

        // Trocas realizadas por mês (últimos 5 meses)
        $sqlTrocasMes = "SELECT YEAR(data_realizacao) as ano, MONTH(data_realizacao) as mes,
                         COUNT(*) as total
                         FROM trocas_oleo
                         WHERE data_realizacao >= DATE_SUB('$dataAtual', INTERVAL 5 MONTH)
                         GROUP BY YEAR(data_realizacao), MONTH(data_realizacao)
                         ORDER BY YEAR(data_realizacao) DESC, MONTH(data_realizacao) DESC
                         LIMIT 5";
        $resultTrocasMes = $conn->query($sqlTrocasMes);

        $trocasPorMes = [];
        while ($row = $resultTrocasMes->fetch_assoc()) {
            $nomeMes = obterNomeMes($row['mes']);
            $trocasPorMes[] = [
                'ano' => $row['ano'],
                'mes' => $row['mes'],
                'nome_mes' => $nomeMes,
                'total' => $row['total']
            ];
        }

        // Veículos por tipo
        $sqlTipos = "SELECT tipo, COUNT(*) as total
                    FROM veiculos
                    GROUP BY tipo
                    ORDER BY total DESC";
        $resultTipos = $conn->query($sqlTipos);

        $veiculosPorTipo = [];
        while ($row = $resultTipos->fetch_assoc()) {
            $veiculosPorTipo[] = [
                'tipo' => $row['tipo'] ?: 'Não especificado',
                'total' => $row['total']
            ];
        }

        fecharConexao($conn);

        return [
            'total_veiculos' => $totalVeiculos,
            'veiculos_em_dia' => $veiculosEmDia,
            'veiculos_proxima_troca' => $veiculosProxima,
            'veiculos_atrasados' => $veiculosAtrasada,
            'trocas_por_mes' => $trocasPorMes,
            'veiculos_por_tipo' => $veiculosPorTipo
        ];
    } catch (Exception $e) {
        error_log($e->getMessage());
        fecharConexao($conn);
        return ['erro' => $e->getMessage()];
    }
}

// Função auxiliar para obter nome do mês
function obterNomeMes($mes) {
    $meses = [
        1 => 'Janeiro',
        2 => 'Fevereiro',
        3 => 'Março',
        4 => 'Abril',
        5 => 'Maio',
        6 => 'Junho',
        7 => 'Julho',
        8 => 'Agosto',
        9 => 'Setembro',
        10 => 'Outubro',
        11 => 'Novembro',
        12 => 'Dezembro'
    ];

    return $meses[$mes] ?? '';
}

// Processar as requisições AJAX
if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    header('Content-Type: application/json');

    // Pegar a ação da requisição
    $acao = $_POST['acao'] ?? $_GET['acao'] ?? '';

    debug("Requisição AJAX recebida. Ação: $acao");

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        debug("Dados POST: " . print_r($_POST, true));
    } else {
        debug("Dados GET: " . print_r($_GET, true));
    }

    // Verificar a estrutura do banco se a ação for verificar_banco
    if ($acao === 'verificar_banco') {
        echo json_encode(verificarEstruturaBanco());
        exit;
    }

    switch ($acao) {
        case 'obter_veiculos':
            $busca = $_GET['busca'] ?? '';
            $status = $_GET['status'] ?? '';
            $secretaria = $_GET['secretaria'] ?? '';

            $veiculos = obterVeiculosComFiltro($busca, $status, $secretaria);
            echo json_encode($veiculos);
            break;

        case 'obter_veiculo':
            $id = $_GET['id'] ?? 0;

            $veiculo = obterVeiculo($id);
            echo json_encode($veiculo);
            break;

        case 'adicionar_veiculo':
            $resultado = adicionarVeiculo($_POST);
            echo json_encode($resultado);
            break;

        case 'atualizar_veiculo':
            $id = $_POST['id'] ?? 0;

            $resultado = atualizarVeiculo($id, $_POST);
            echo json_encode($resultado);
            break;

        case 'excluir_veiculo':
            $id = $_POST['id'] ?? 0;

            $resultado = excluirVeiculo($id);
            echo json_encode($resultado);
            break;

        case 'registrar_troca_oleo':
            $resultado = registrarTrocaOleo($_POST);
            echo json_encode($resultado);
            break;

        case 'obter_historico':
            $filtros = [
                'veiculo_id' => $_GET['veiculo_id'] ?? null,
                'data_inicial' => $_GET['data_inicial'] ?? null,
                'data_final' => $_GET['data_final'] ?? null
            ];

            $historico = obterHistoricoTrocas($filtros);
            echo json_encode($historico);
            break;

        case 'obter_proximas_trocas':
            $dataInicial = $_GET['data_inicial'] ?? date('Y-m-d');
            $dataFinal = $_GET['data_final'] ?? date('Y-m-d', strtotime('+3 months'));

            $trocas = obterProximasTrocas($dataInicial, $dataFinal);
            echo json_encode($trocas);
            break;

        case 'obter_estatisticas':
            $estatisticas = obterEstatisticas();
            echo json_encode($estatisticas);
            break;

        default:
            echo json_encode(['erro' => 'Ação inválida']);
            break;
    }

    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Gestão de Troca de Óleo</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #2c3e50;
            --secondary: #3498db;
            --success: #2ecc71;
            --danger: #e74c3c;
            --warning: #f39c12;
            --info: #1abc9c;
            --light: #ecf0f1;
            --dark: #34495e;
            --shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
            --border-radius: 8px;
            --transition: all 0.3s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: #f5f7fa;
            color: #333;
            line-height: 1.6;
        }

        .container {
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }

        header {
            background: linear-gradient(135deg, var(--primary), var(--dark));
            color: white;
            padding: 1.5rem;
            border-radius: var(--border-radius);
            margin-bottom: 2rem;
            box-shadow: var(--shadow);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        header h1 {
            font-size: 1.8rem;
            margin: 0;
            display: flex;
            align-items: center;
        }

        header h1 i {
            margin-right: 15px;
            font-size: 2rem;
        }

        .header-actions {
            display: flex;
            gap: 15px;
        }

        .notification-badge {
            position: relative;
        }

        .notification-badge .count {
            position: absolute;
            top: -8px;
            right: -8px;
            background-color: var(--danger);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            font-size: 0.7rem;
            font-weight: bold;
        }

        .card {
            background: white;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
            transition: var(--transition);
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.12);
        }

        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid var(--light);
            padding-bottom: 1rem;
            margin-bottom: 1.5rem;
        }

        .card-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            text-align: center;
            padding: 1.5rem;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: bold;
            margin: 10px 0;
            color: var(--secondary);
        }

        .stat-label {
            font-size: 1rem;
            color: #777;
        }

        .stat-icon {
            font-size: 2rem;
            margin-bottom: 1rem;
        }

        .primary-icon {
            color: var(--primary);
        }

        .success-icon {
            color: var(--success);
        }

        .warning-icon {
            color: var(--warning);
        }

        .danger-icon {
            color: var(--danger);
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1.2rem;
        }

        .form-group {
            margin-bottom: 1.2rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 500;
            color: var(--dark);
        }

        input, select, textarea {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            font-size: 0.95rem;
            transition: var(--transition);
        }

        input:focus, select:focus, textarea:focus {
            border-color: var(--secondary);
            outline: none;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--border-radius);
            cursor: pointer;
            font-weight: 500;
            font-size: 0.95rem;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn:hover {
            transform: translateY(-2px);
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: #1a252f;
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #27ae60;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-warning:hover {
            background: #d35400;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-danger:hover {
            background: #c0392b;
        }

        .btn-info {
            background: var(--info);
            color: white;
        }

        .btn-info:hover {
            background: #16a085;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 1.5rem;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: 1.5rem 0;
            border-radius: var(--border-radius);
            overflow: hidden;
        }

        thead {
            background-color: var(--primary);
            color: white;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
        }

        tbody tr {
            border-bottom: 1px solid #ddd;
            transition: var(--transition);
        }

        tbody tr:nth-child(even) {
            background-color: #f9f9f9;
        }

        tbody tr:hover {
            background-color: #f1f1f1;
        }

        .status {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
            text-align: center;
        }

        .status-upcoming {
            background-color: #e3f2fd;
            color: #1976d2;
        }

        .status-due {
            background-color: #fff8e1;
            color: #ff8f00;
        }

        .status-overdue {
            background-color: #ffebee;
            color: #c62828;
        }

        .status-complete {
            background-color: #e8f5e9;
            color: #2e7d32;
        }

        .action-icons {
            display: flex;
            gap: 10px;
        }

        .action-icon {
            cursor: pointer;
            padding: 5px;
            border-radius: 4px;
            transition: var(--transition);
        }

        .action-icon:hover {
            background-color: #f1f1f1;
        }

        .edit-icon {
            color: var(--secondary);
        }

        .delete-icon {
            color: var(--danger);
        }

        .filter-section {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            margin-bottom: 20px;
            align-items: center;
        }

        .filter-section .form-group {
            margin-bottom: 0;
            min-width: 200px;
        }

        .search-box {
            position: relative;
            flex-grow: 1;
        }

        .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #777;
        }

        .search-box input {
            padding-left: 35px;
        }

        .tag {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            margin-right: 5px;
            margin-bottom: 5px;
        }

        .tag-info {
            background-color: #e3f2fd;
            color: #1976d2;
        }

        .tag-warning {
            background-color: #fff8e1;
            color: #ff8f00;
        }

        .pagination {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 1.5rem;
        }

        .pagination-item {
            width: 35px;
            height: 35px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 1px solid #ddd;
            border-radius: var(--border-radius);
            cursor: pointer;
            transition: var(--transition);
        }

        .pagination-item.active {
            background-color: var(--secondary);
            color: white;
            border-color: var(--secondary);
        }

        .pagination-item:hover:not(.active) {
            background-color: #f1f1f1;
        }

        .modal-backdrop {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease, visibility 0.3s ease;
        }

        .modal-backdrop.active {
            opacity: 1;
            visibility: visible;
        }

        .modal {
            background-color: white;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            transform: translateY(-20px);
            transition: transform 0.3s ease;
        }

        .modal-backdrop.active .modal {
            transform: translateY(0);
        }

        .modal-header {
            padding: 1.5rem;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--primary);
        }

        .close-modal {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #777;
            transition: color 0.3s ease;
        }

        .close-modal:hover {
            color: var(--danger);
        }

        .modal-body {
            padding: 1.5rem;
        }

        .modal-footer {
            padding: 1.5rem;
            border-top: 1px solid #eee;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .tabs {
            display: flex;
            border-bottom: 1px solid #ddd;
            margin-bottom: 1.5rem;
        }

        .tab-item {
            padding: 10px 15px;
            cursor: pointer;
            transition: var(--transition);
            border-bottom: 2px solid transparent;
            font-weight: 500;
        }

        .tab-item.active {
            color: var(--secondary);
            border-bottom-color: var(--secondary);
        }

        .tab-item:hover:not(.active) {
            background-color: #f9f9f9;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .progress-container {
            width: 100%;
            background-color: #e0e0e0;
            border-radius: 10px;
            height: 8px;
            overflow: hidden;
            margin-top: 5px;
        }

        .progress-bar {
            height: 100%;
            border-radius: 10px;
            transition: var(--transition);
        }

        .oil-level {
            margin-top: 10px;
        }

        .oil-percentage {
            font-weight: bold;
            margin-bottom: 5px;
        }

        .oil-bar-container {
            position: relative;
            width: 100%;
            height: 30px;
            background-color: #eee;
            border-radius: var(--border-radius);
            overflow: hidden;
        }

        .oil-bar {
            height: 100%;
            background: linear-gradient(to right, #f1c40f, #e67e22);
            transition: width 0.5s ease;
        }

        .oil-marker {
            position: absolute;
            top: 0;
            height: 100%;
            width: 2px;
            background-color: #e74c3c;
        }

        .data-info {
            display: flex;
            gap: 15px;
            margin-top: 10px;
        }

        .data-item {
            flex: 1;
            padding: 10px;
            background-color: #f9f9f9;
            border-radius: var(--border-radius);
            text-align: center;
        }

        .data-label {
            font-size: 0.85rem;
            color: #777;
            margin-bottom: 5px;
        }

        .data-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--dark);
        }

        /* Calendar styles */
        .calendar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .calendar-nav {
            display: flex;
            gap: 10px;
        }

        .calendar-month {
            font-size: 1.2rem;
            font-weight: 600;
        }

        .calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 5px;
        }

        .calendar-day-header {
            text-align: center;
            font-weight: 500;
            padding: 8px 0;
            color: var(--dark);
        }

        .calendar-day {
            padding: 10px;
            border: 1px solid #eee;
            border-radius: 4px;
            min-height: 80px;
            cursor: pointer;
            transition: var(--transition);
        }

        .calendar-day:hover {
            background-color: #f9f9f9;
        }

        .calendar-day.today {
            background-color: #e3f2fd;
            border-color: var(--secondary);
        }

        .calendar-day.has-event {
            position: relative;
        }

        .calendar-day.has-event::after {
            content: '';
            position: absolute;
            top: 5px;
            right: 5px;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: var(--secondary);
        }

        .calendar-day.other-month {
            color: #bbb;
            background-color: #f9f9f9;
        }

        .calendar-day-number {
            font-weight: 500;
            margin-bottom: 5px;
        }

        .calendar-event {
            font-size: 0.75rem;
            padding: 2px 4px;
            background-color: var(--secondary);
            color: white;
            border-radius: 2px;
            margin-bottom: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Responsiveness */
        @media (max-width: 768px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .card-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .action-buttons {
                justify-content: flex-start;
                flex-wrap: wrap;
            }

            table {
                display: block;
                overflow-x: auto;
                white-space: nowrap;
            }

            .calendar-grid {
                grid-template-columns: repeat(7, minmax(40px, 1fr));
            }

            .calendar-day {
                min-height: 60px;
                padding: 5px;
            }
        }

        @media (max-width: 480px) {
            header {
                flex-direction: column;
                gap: 15px;
            }

            .header-actions {
                width: 100%;
                justify-content: center;
            }

            .filter-section {
                flex-direction: column;
                align-items: stretch;
            }

            .filter-section .form-group {
                width: 100%;
            }
        }

        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        @keyframes slideIn {
            from { transform: translateY(-20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .fade-in {
            animation: fadeIn 0.5s ease forwards;
        }

        .slide-in {
            animation: slideIn 0.5s ease forwards;
        }

        /* Toast notification */
        .toast-container {
            position: fixed;
            bottom: 20px;
            right: 20px;
            z-index: 1050;
        }

        .toast {
            background: white;
            border-left: 4px solid var(--primary);
            border-radius: var(--border-radius);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            padding: 15px 20px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
            transform: translateX(120%);
            opacity: 0;
            transition: transform 0.3s ease, opacity 0.3s ease;
        }

        .toast.active {
            transform: translateX(0);
            opacity: 1;
        }

        .toast-icon {
            font-size: 1.5rem;
        }

        .toast-success {
            border-left-color: var(--success);
        }

        .toast-success .toast-icon {
            color: var(--success);
        }

        .toast-warning {
            border-left-color: var(--warning);
        }

        .toast-warning .toast-icon {
            color: var(--warning);
        }

        .toast-danger {
            border-left-color: var(--danger);
        }

        .toast-danger .toast-icon {
            color: var(--danger);
        }

        .toast-close {
            margin-left: auto;
            background: none;
            border: none;
            cursor: pointer;
            color: #777;
            transition: color 0.3s ease;
        }

        .toast-close:hover {
            color: var(--danger);
        }

        .vehicle-selector {
            margin-bottom: 20px;
        }

        .charts-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .chart-card {
            height: 300px;
        }

        .chart-container {
            height: 100%;
            width: 100%;
        }

        /* Print styles */
        @media print {
            .no-print {
                display: none !important;
            }

            .container {
                width: 100%;
                max-width: none;
                padding: 0;
            }

            body {
                background: white;
                font-size: 12pt;
            }

            .card {
                box-shadow: none;
                border: 1px solid #ddd;
            }

            table {
                page-break-inside: avoid;
            }

            thead {
                background-color: #eee !important;
                color: black !important;
            }
        }

        /* Floating action button */
        .fab {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background-color: var(--secondary);
            color: white;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            cursor: pointer;
            transition: all 0.3s ease;
            z-index: 900;
        }

        .fab:hover {
            background-color: #2980b9;
            transform: translateY(-5px);
            box-shadow: 0 6px 16px rgba(0, 0, 0, 0.3);
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <h1><i class="fas fa-oil-can"></i> Sistema de Gestão de Troca de Óleo</h1>
        <div class="header-actions">
            <div class="notification-badge">
                <i class="fas fa-bell fa-lg"></i>
                <span class="count" id="notification-count">0</span>
            </div>
        </div>
    </header>

    <!-- Main Content -->
    <div class="container">
        <!-- Dashboard -->
        <div class="card fade-in">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-tachometer-alt"></i> Dashboard</h2>
                <div>
                    <button class="btn btn-info" onclick="printReport()"><i class="fas fa-print"></i> Imprimir Relatório</button>
                </div>
            </div>
            <div class="dashboard-grid">
                <div class="card stat-card">
                    <div class="stat-icon primary-icon">
                        <i class="fas fa-car"></i>
                    </div>
                    <div class="stat-value" id="total-vehicles">0</div>
                    <div class="stat-label">Total de Veículos</div>
                </div>
                <div class="card stat-card">
                    <div class="stat-icon success-icon">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-value" id="updated-vehicles">0</div>
                    <div class="stat-label">Trocas em Dia</div>
                </div>
                <div class="card stat-card">
                    <div class="stat-icon warning-icon">
                        <i class="fas fa-exclamation-circle"></i>
                    </div>
                    <div class="stat-value" id="upcoming-vehicles">0</div>
                    <div class="stat-label">Próximas Trocas</div>
                </div>
                <div class="card stat-card">
                    <div class="stat-icon danger-icon">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-value" id="overdue-vehicles">0</div>
                    <div class="stat-label">Trocas Atrasadas</div>
                </div>
            </div>
        </div>

        <!-- Charts -->
        <div class="card fade-in">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-chart-line"></i> Análise de Trocas</h2>
            </div>
            <div class="charts-container">
                <div class="chart-card">
                    <canvas id="oilChangeChart" class="chart-container"></canvas>
                </div>
                <div class="chart-card">
                    <canvas id="vehicleTypeChart" class="chart-container"></canvas>
                </div>
            </div>
        </div>

        <!-- Vehicle Selection -->
        <div class="card fade-in">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-car"></i> Selecionar Veículo</h2>
                <button class="btn btn-success" onclick="openAddVehicleModal()"><i class="fas fa-plus"></i> Novo Veículo</button>
            </div>
            <div class="filter-section">
                <div class="search-box">
                    <i class="fas fa-search"></i>
                    <input type="text" id="search-vehicle" placeholder="Buscar veículo...">
                </div>
                <div class="form-group">
                    <select id="filter-status">
                        <option value="">Status</option>
                        <option value="em_dia">Em dia</option>
                        <option value="proxima">Próxima troca</option>
                        <option value="atrasada">Atrasada</option>
                    </select>
                </div>
                <div class="form-group">
                    <select id="filter-secretaria">
                        <option value="">Secretaria</option>
                        <option value="Administração">Administração</option>
                        <option value="Saúde">Saúde</option>
                        <option value="Educação">Educação</option>
                        <option value="Obras">Obras</option>
                        <option value="Agricultura">Agricultura</option>
                    </select>
                </div>
            </div>
            <div class="vehicle-list">
                <table id="vehicles-table">
                    <thead>
                        <tr>
                            <th>Veículo</th>
                            <th>Placa</th>
                            <th>Secretaria</th>
                            <th>Última Troca</th>
                            <th>Próxima Troca</th>
                            <th>KM Atual</th>
                            <th>KM Próxima</th>
                            <th>Status</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- Vehicle data rows will be generated by JavaScript -->
                    </tbody>
                </table>
            </div>
            <div class="pagination" id="pagination">
                <!-- Pagination will be generated by JavaScript -->
            </div>
        </div>

        <!-- Oil Change Management -->
        <div class="card fade-in">
            <div class="card-header">
                <h2 class="card-title"><i class="fas fa-oil-can"></i> Gestão de Troca de Óleo</h2>
            </div>
            <div class="tabs">
                <div class="tab-item active" data-tab="register">Registrar Troca</div>
                <div class="tab-item" data-tab="history">Histórico de Trocas</div>
                <div class="tab-item" data-tab="calendar">Calendário</div>
            </div>
            <div class="tab-content active" id="register-tab">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="vehicle-select">Veículo</label>
                        <select id="vehicle-select">
                            <option value="">Selecione um veículo</option>
                            <!-- Vehicle options will be generated by JavaScript -->
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="current-km">Km Atual</label>
                        <input type="number" id="current-km" placeholder="Informe o km atual">
                    </div>
                    <div class="form-group">
                        <label for="oil-type">Tipo de Óleo</label>
                        <select id="oil-type">
                            <option value="">Selecione o tipo de óleo</option>
                            <option value="15W40">15W40</option>
                            <option value="5W30">5W30</option>
                            <option value="5W40">5W40</option>
                            <option value="10W40">10W40</option>
                            <option value="20W50">20W50</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="oil-amount">Quantidade (L)</label>
                        <input type="number" id="oil-amount" placeholder="Quantidade em litros" step="0.1">
                    </div>
                    <div class="form-group">
                        <label for="change-date">Data da Troca</label>
                        <input type="date" id="change-date" value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group">
                        <label for="next-change-km">Km para Próxima Troca</label>
                        <input type="number" id="next-change-km" placeholder="Km para próxima troca">
                    </div>
                    <div class="form-group">
                        <label for="next-change-date">Data para Próxima Troca</label>
                        <input type="date" id="next-change-date">
                    </div>
                    <div class="form-group">
                        <label for="observations">Observações</label>
                        <textarea id="observations" rows="3" placeholder="Observações adicionais..."></textarea>
                    </div>
                </div>
                <div class="action-buttons">
                    <button class="btn btn-primary" onclick="registerOilChange()"><i class="fas fa-save"></i> Salvar Registro</button>
                    <button class="btn btn-danger" onclick="clearForm()"><i class="fas fa-times"></i> Cancelar</button>
                </div>
            </div>
            <div class="tab-content" id="history-tab">
                <div class="filter-section">
                    <div class="form-group">
                        <select id="history-vehicle-select">
                            <option value="">Todos os Veículos</option>
                            <!-- Vehicle options will be generated by JavaScript -->
                        </select>
                    </div>
                    <div class="form-group">
                        <input type="date" id="history-start-date">
                    </div>
                    <div class="form-group">
                        <input type="date" id="history-end-date">
                    </div>
                    <button class="btn btn-primary" onclick="filterHistory()"><i class="fas fa-filter"></i> Filtrar</button>
                </div>
                <table id="history-table">
                    <thead>
                        <tr>
                            <th>Veículo</th>
                            <th>Placa</th>
                            <th>Data da Troca</th>
                            <th>Km na Troca</th>
                            <th>Tipo de Óleo</th>
                            <th>Quantidade</th>
                            <th>Próxima Troca</th>
                            <th>Km Próxima</th>
                        </tr>
                    </thead>
                    <tbody>
                        <!-- History data rows will be generated by JavaScript -->
                    </tbody>
                </table>
                <div class="pagination" id="history-pagination">
                    <!-- Pagination will be generated by JavaScript -->
                </div>
            </div>
            <div class="tab-content" id="calendar-tab">
                <div class="calendar-header">
                    <div class="calendar-nav">
                        <button class="btn btn-primary" onclick="prevMonth()"><i class="fas fa-chevron-left"></i></button>
                        <div class="calendar-month" id="calendar-month">Maio 2023</div>
                        <button class="btn btn-primary" onclick="nextMonth()"><i class="fas fa-chevron-right"></i></button>
                    </div>
                </div>
                <div class="calendar-grid" id="calendar-days-header">
                    <div class="calendar-day-header">Dom</div>
                    <div class="calendar-day-header">Seg</div>
                    <div class="calendar-day-header">Ter</div>
                    <div class="calendar-day-header">Qua</div>
                    <div class="calendar-day-header">Qui</div>
                    <div class="calendar-day-header">Sex</div>
                    <div class="calendar-day-header">Sáb</div>
                </div>
                <div class="calendar-grid" id="calendar-days">
                    <!-- Calendar days will be generated by JavaScript -->
                </div>
            </div>
        </div>

        <!-- Vehicle Details (empty initially, will be populated when a vehicle is selected) -->
        <div id="vehicle-details-container" style="display: none;">
            <div class="card fade-in">
                <div class="card-header">
                    <h2 class="card-title"><i class="fas fa-info-circle"></i> <span id="vehicle-details-title">Detalhes do Veículo</span></h2>
                    <div>
                        <button class="btn btn-primary" onclick="editVehicle()"><i class="fas fa-edit"></i> Editar</button>
                        <button class="btn btn-danger" onclick="confirmDeleteVehicle()"><i class="fas fa-trash"></i> Excluir</button>
                    </div>
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>Veículo</label>
                        <div id="vehicle-name" class="data-value">-</div>
                    </div>
                    <div class="form-group">
                        <label>Placa</label>
                        <div id="vehicle-plate" class="data-value">-</div>
                    </div>
                    <div class="form-group">
                        <label>Tipo</label>
                        <div id="vehicle-type" class="data-value">-</div>
                    </div>
                    <div class="form-group">
                        <label>Secretaria</label>
                        <div id="vehicle-secretary" class="data-value">-</div>
                    </div>
                    <div class="form-group">
                        <label>Matrícula</label>
                        <div id="vehicle-registration" class="data-value">-</div>
                    </div>
                    <div class="form-group">
                        <label>Renavam</label>
                        <div id="vehicle-renavam" class="data-value">-</div>
                    </div>
                    <div class="form-group">
                        <label>Chassi</label>
                        <div id="vehicle-chassis" class="data-value">-</div>
                    </div>
                    <div class="form-group">
                        <label>Marca/Modelo</label>
                        <div id="vehicle-brand" class="data-value">-</div>
                    </div>
                    <div class="form-group">
                        <label>Ano/Modelo</label>
                        <div id="vehicle-year" class="data-value">-</div>
                    </div>
                    <div class="form-group">
                        <label>Combustível</label>
                        <div id="vehicle-fuel" class="data-value">-</div>
                    </div>
                    <div class="form-group">
                        <label>Capacidade do Tanque</label>
                        <div id="vehicle-tank" class="data-value">-</div>
                    </div>
                    <div class="form-group">
                        <label>Status</label>
                        <div id="vehicle-status" class="data-value">-</div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Informações de Troca de Óleo</h3>
                    </div>
                    <div class="oil-level">
                        <div class="oil-percentage">Status do Óleo: <span id="oil-status">0%</span></div>
                        <div class="oil-bar-container">
                            <div class="oil-bar" id="oil-bar" style="width: 0%;"></div>
                            <div class="oil-marker" id="oil-marker" style="left: 80%;"></div>
                        </div>
                    </div>
                    <div class="data-info">
                        <div class="data-item">
                            <div class="data-label">Última Troca</div>
                            <div class="data-value" id="last-change-date">-</div>
                        </div>
                        <div class="data-item">
                            <div class="data-label">KM na Última Troca</div>
                            <div class="data-value" id="last-change-km">-</div>
                        </div>
                        <div class="data-item">
                            <div class="data-label">Próxima Troca</div>
                            <div class="data-value" id="next-change-date-display">-</div>
                        </div>
                        <div class="data-item">
                            <div class="data-label">KM para Próxima Troca</div>
                            <div class="data-value" id="next-change-km-display">-</div>
                        </div>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Histórico de Trocas</h3>
                    </div>
                    <table id="vehicle-history-table">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>KM</th>
                                <th>Tipo Óleo</th>
                                <th>Quantidade</th>
                                <th>Próxima Troca</th>
                                <th>KM Próxima</th>
                                <th>Observações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <!-- Vehicle history will be generated by JavaScript -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add/Edit Vehicle Modal -->
    <div class="modal-backdrop" id="vehicle-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title" id="vehicle-modal-title">Adicionar Veículo</h2>
                <button class="close-modal" onclick="closeVehicleModal()">&times;</button>
            </div>
            <div class="modal-body">
                <form id="vehicle-form">
                    <input type="hidden" id="vehicle-form-id" value="">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="vehicle-form-name">Veículo*</label>
                            <input type="text" id="vehicle-form-name" required>
                        </div>
                        <div class="form-group">
                            <label for="vehicle-form-plate">Placa*</label>
                            <input type="text" id="vehicle-form-plate" required>
                        </div>
                        <div class="form-group">
                            <label for="vehicle-form-type">Tipo*</label>
                            <select id="vehicle-form-type" required>
                                <option value="">Selecione</option>
                                <option value="Carro">Carro</option>
                                <option value="Caminhão">Caminhão</option>
                                <option value="Ônibus">Ônibus</option>
                                <option value="Van">Van</option>
                                <option value="Motocicleta">Motocicleta</option>
                                <option value="Máquina">Máquina</option>
                                <option value="Trator">Trator</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="vehicle-form-secretary">Secretaria*</label>
                            <select id="vehicle-form-secretary" required>
                                <option value="">Selecione</option>
                                <option value="Administração">Administração</option>
                                <option value="Saúde">Saúde</option>
                                <option value="Educação">Educação</option>
                                <option value="Obras">Obras</option>
                                <option value="Agricultura">Agricultura</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="vehicle-form-registration">Matrícula</label>
                            <input type="text" id="vehicle-form-registration">
                        </div>
                        <div class="form-group">
                            <label for="vehicle-form-renavam">Renavam</label>
                            <input type="text" id="vehicle-form-renavam">
                        </div>
                        <div class="form-group">
                            <label for="vehicle-form-chassis">Chassi</label>
                            <input type="text" id="vehicle-form-chassis">
                        </div>
                        <div class="form-group">
                            <label for="vehicle-form-brand">Marca*</label>
                            <input type="text" id="vehicle-form-brand" required>
                        </div>
                        <div class="form-group">
                            <label for="vehicle-form-year">Ano/Modelo*</label>
                            <input type="text" id="vehicle-form-year" required placeholder="Ex: 2023/2024">
                        </div>
                        <div class="form-group">
                            <label for="vehicle-form-fuel">Combustível*</label>
                            <select id="vehicle-form-fuel" required>
                                <option value="">Selecione</option>
                                <option value="Gasolina">Gasolina</option>
                                <option value="Etanol">Etanol</option>
                                <option value="Diesel-S10">Diesel-S10</option>
                                <option value="Diesel-S500">Diesel-S500</option>
                                <option value="Flex">Flex</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="vehicle-form-tank">Capacidade do Tanque (L)*</label>
                            <input type="number" id="vehicle-form-tank" required>
                        </div>
                        <div class="form-group">
                            <label for="vehicle-form-status">Status*</label>
                            <select id="vehicle-form-status" required>
                                <option value="ativo">Ativo</option>
                                <option value="em uso">Em Uso</option>
                            </select>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <button class="btn btn-danger" onclick="closeVehicleModal()">Cancelar</button>
                <button class="btn btn-primary" onclick="saveVehicle()">Salvar</button>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal-backdrop" id="delete-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title">Confirmar Exclusão</h2>
                <button class="close-modal" onclick="closeDeleteModal()">&times;</button>
            </div>
            <div class="modal-body">
                <p>Você tem certeza que deseja excluir este veículo? Esta ação não pode ser desfeita.</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" onclick="closeDeleteModal()">Cancelar</button>
                <button class="btn btn-danger" onclick="deleteVehicle()">Excluir</button>
            </div>
        </div>
    </div>

    <!-- Calendar Event Modal -->
    <div class="modal-backdrop" id="calendar-event-modal">
        <div class="modal">
            <div class="modal-header">
                <h2 class="modal-title" id="calendar-event-title">Trocas de Óleo - 15/05/2025</h2>
                <button class="close-modal" onclick="closeCalendarEventModal()">&times;</button>
            </div>
            <div class="modal-body">
                <div id="calendar-event-list">
                    <!-- Calendar events will be displayed here -->
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" onclick="closeCalendarEventModal()">Fechar</button>
            </div>
        </div>
    </div>

    <!-- Toast Notifications -->
    <div class="toast-container" id="toast-container">
        <!-- Toast notifications will be created dynamically -->
    </div>

    <!-- Floating Action Button -->
    <div class="fab" onclick="openAddVehicleModal()">
        <i class="fas fa-plus"></i>
    </div>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <!-- JavaScript -->
    <script>
        // Global variables
        let currentVehicleId = null;
        let currentPage = 1;
        let itemsPerPage = 10;
        let currentHistoryPage = 1;
        let historyItemsPerPage = 10;
        let calendarDate = new Date("2025-05-26");
        let oilChangeChart = null;
        let vehicleTypeChart = null;

        // DOM Elements
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize the application
            initApp();

            // Event Listeners for tabs
            document.querySelectorAll('.tab-item').forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');
                    activateTab(tabId);
                });
            });

            // Event listener for search
            document.getElementById('search-vehicle').addEventListener('input', function() {
                loadVehiclesTable(1, this.value);
            });

            // Event listeners for filters
            document.getElementById('filter-status').addEventListener('change', function() {
                loadVehiclesTable(1, document.getElementById('search-vehicle').value);
            });

            document.getElementById('filter-secretaria').addEventListener('change', function() {
                loadVehiclesTable(1, document.getElementById('search-vehicle').value);
            });

            // Set default date for change-date
            document.getElementById('change-date').valueAsDate = new Date("2025-05-26");

            // Calculate and set default next change date (3 months from now)
            const nextChangeDate = new Date("2025-05-26");
            nextChangeDate.setMonth(nextChangeDate.getMonth() + 3);
            document.getElementById('next-change-date').valueAsDate = nextChangeDate;
        });

        function initApp() {
            // Load dashboard stats
            loadDashboardStats();

            // Load vehicles into the table
            loadVehiclesTable();

            // Populate vehicle selection dropdowns
            populateVehicleDropdowns();

            // Initialize charts
            initCharts();

            // Initialize calendar
            renderCalendar();

            // Check for overdue oil changes
            checkOverdueChanges();
        }

        function loadDashboardStats() {
            fetch('painel_troca_oleo.php?acao=obter_estatisticas')
                .then(response => response.json())
                .then(data => {
                    if (data.erro) {
                        showToast('Erro ao carregar estatísticas: ' + data.erro, 'danger');
                        return;
                    }

                    document.getElementById('total-vehicles').textContent = data.total_veiculos;
                    document.getElementById('updated-vehicles').textContent = data.veiculos_em_dia;
                    document.getElementById('upcoming-vehicles').textContent = data.veiculos_proxima_troca;
                    document.getElementById('overdue-vehicles').textContent = data.veiculos_atrasados;

                    // Update charts
                    updateCharts(data);
                })
                .catch(error => {
                    showToast('Erro ao carregar estatísticas: ' + error, 'danger');
                });
        }

        function updateCharts(data) {
            // Update Oil Change Chart
            if (oilChangeChart) {
                oilChangeChart.destroy();
            }

            const oilChangeLabels = [];
            const oilChangeData = [];

            // Process data for chart
            if (data.trocas_por_mes && data.trocas_por_mes.length > 0) {
                data.trocas_por_mes.forEach(item => {
                    oilChangeLabels.unshift(`${item.nome_mes} ${item.ano}`);
                    oilChangeData.unshift(parseInt(item.total));
                });
            }

            const oilChangeConfig = {
                type: 'bar',
                data: {
                    labels: oilChangeLabels,
                    datasets: [{
                        label: 'Trocas de Óleo',
                        data: oilChangeData,
                        backgroundColor: 'rgba(52, 152, 219, 0.6)',
                        borderColor: 'rgba(52, 152, 219, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Trocas de Óleo por Mês'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            };

            oilChangeChart = new Chart(document.getElementById('oilChangeChart'), oilChangeConfig);

            // Update Vehicle Type Chart
            if (vehicleTypeChart) {
                vehicleTypeChart.destroy();
            }

            const vehicleTypeLabels = [];
            const vehicleTypeData = [];
            const backgroundColors = [
                'rgba(52, 152, 219, 0.6)',
                'rgba(155, 89, 182, 0.6)',
                'rgba(46, 204, 113, 0.6)',
                'rgba(241, 196, 15, 0.6)',
                'rgba(231, 76, 60, 0.6)',
                'rgba(52, 73, 94, 0.6)'
            ];
            const borderColors = [
                'rgba(52, 152, 219, 1)',
                'rgba(155, 89, 182, 1)',
                'rgba(46, 204, 113, 1)',
                'rgba(241, 196, 15, 1)',
                'rgba(231, 76, 60, 1)',
                'rgba(52, 73, 94, 1)'
            ];

            if (data.veiculos_por_tipo && data.veiculos_por_tipo.length > 0) {
                data.veiculos_por_tipo.forEach((item, index) => {
                    vehicleTypeLabels.push(item.tipo);
                    vehicleTypeData.push(parseInt(item.total));
                });
            }

            const vehicleTypeConfig = {
                type: 'pie',
                data: {
                    labels: vehicleTypeLabels,
                    datasets: [{
                        label: 'Quantidade de Veículos',
                        data: vehicleTypeData,
                        backgroundColor: backgroundColors,
                        borderColor: borderColors,
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Veículos por Tipo'
                        },
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            };

            vehicleTypeChart = new Chart(document.getElementById('vehicleTypeChart'), vehicleTypeConfig);
        }

        function initCharts() {
            // Initially create empty charts, they'll be populated by loadDashboardStats()
            const oilChangeConfig = {
                type: 'bar',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Trocas de Óleo',
                        data: [],
                        backgroundColor: 'rgba(52, 152, 219, 0.6)',
                        borderColor: 'rgba(52, 152, 219, 1)',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Trocas de Óleo por Mês'
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                stepSize: 1
                            }
                        }
                    }
                }
            };

            oilChangeChart = new Chart(document.getElementById('oilChangeChart'), oilChangeConfig);

            const vehicleTypeConfig = {
                type: 'pie',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Quantidade de Veículos',
                        data: [],
                        backgroundColor: [],
                        borderColor: [],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        title: {
                            display: true,
                            text: 'Veículos por Tipo'
                        },
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            };

            vehicleTypeChart = new Chart(document.getElementById('vehicleTypeChart'), vehicleTypeConfig);
        }

        function checkOverdueChanges() {
            fetch('painel_troca_oleo.php?acao=obter_estatisticas')
                .then(response => response.json())
                .then(data => {
                    if (data.erro) return;

                    const overdueCount = parseInt(data.veiculos_atrasados) || 0;
                    const upcomingCount = parseInt(data.veiculos_proxima_troca) || 0;
                    const totalNotifications = overdueCount + upcomingCount;

                    document.getElementById('notification-count').textContent = totalNotifications;

                    if (totalNotifications > 0) {
                        if (overdueCount > 0) {
                            showToast(`${overdueCount} veículo${overdueCount > 1 ? 's' : ''} com troca de óleo atrasada!`, 'danger');
                        }
                        if (upcomingCount > 0) {
                            showToast(`${upcomingCount} veículo${upcomingCount > 1 ? 's' : ''} próximo${upcomingCount > 1 ? 's' : ''} da troca de óleo`, 'warning');
                        }
                    }
                })
                .catch(error => console.error('Erro ao verificar trocas atrasadas:', error));
        }

        function loadVehiclesTable(page = 1, search = '') {
            const tableBody = document.querySelector('#vehicles-table tbody');
            tableBody.innerHTML = '<tr><td colspan="9" style="text-align:center;">Carregando...</td></tr>';

            const statusFilter = document.getElementById('filter-status').value;
            const secretariaFilter = document.getElementById('filter-secretaria').value;

            let url = `painel_troca_oleo.php?acao=obter_veiculos&page=${page}&limit=${itemsPerPage}`;

            if (search) {
                url += `&busca=${encodeURIComponent(search)}`;
            }

            if (statusFilter) {
                url += `&status=${encodeURIComponent(statusFilter)}`;
            }

            if (secretariaFilter) {
                url += `&secretaria=${encodeURIComponent(secretariaFilter)}`;
            }

            fetch(url)
                .then(response => response.json())
                .then(data => {
                    tableBody.innerHTML = '';

                    if (data.erro) {
                        tableBody.innerHTML = `<tr><td colspan="9" style="text-align:center;">Erro: ${data.erro}</td></tr>`;
                        return;
                    }

                    if (data.length === 0) {
                        tableBody.innerHTML = '<tr><td colspan="9" style="text-align:center;">Nenhum veículo encontrado</td></tr>';
                        return;
                    }

                    currentPage = page;

                    // Add vehicle rows
                    data.forEach(vehicle => {
                        const row = document.createElement('tr');

                        // Determine status class
                        let statusClass;
                        let statusText;

                        const currentDate = new Date("2025-05-26");
                        const nextChangeDate = vehicle.proxima_troca_oleo ? new Date(vehicle.proxima_troca_oleo) : null;

                        if (nextChangeDate) {
                            const daysUntilChange = Math.floor((nextChangeDate - currentDate) / (1000 * 60 * 60 * 24));

                            if (daysUntilChange < 0) {
                                statusClass = 'status-overdue';
                                statusText = 'Atrasada';
                            } else if (daysUntilChange <= 15) {
                                statusClass = 'status-due';
                                statusText = 'Próxima';
                            } else {
                                statusClass = 'status-complete';
                                statusText = 'Em dia';
                            }
                        } else {
                            statusClass = 'status-due';
                            statusText = 'Não definida';
                        }

                        // Format dates for display
                        const lastChangeDate = vehicle.ultima_troca ? new Date(vehicle.ultima_troca).toLocaleDateString('pt-BR') : 'N/D';
                        const nextChangeDateFormatted = nextChangeDate ? nextChangeDate.toLocaleDateString('pt-BR') : 'N/D';

                        row.innerHTML = `
                            <td>${vehicle.veiculo || 'N/D'}</td>
                            <td>${vehicle.placa || 'N/D'}</td>
                            <td>${vehicle.secretaria || 'N/D'}</td>
                            <td>${lastChangeDate}</td>
                            <td>${nextChangeDateFormatted}</td>
                            <td>${vehicle.km_atual ? vehicle.km_atual.toLocaleString('pt-BR') + ' km' : 'N/D'}</td>
                            <td>${vehicle.proximo_km_troca ? vehicle.proximo_km_troca.toLocaleString('pt-BR') + ' km' : 'N/D'}</td>
                            <td><div class="status ${statusClass}">${statusText}</div></td>
                            <td>
                                <div class="action-icons">
                                    <span class="action-icon edit-icon" onclick="openVehicleDetails(${vehicle.id})" title="Detalhes">
                                        <i class="fas fa-info-circle"></i>
                                    </span>
                                    <span class="action-icon edit-icon" onclick="editVehicleModal(${vehicle.id})" title="Editar">
                                        <i class="fas fa-edit"></i>
                                    </span>
                                    <span class="action-icon delete-icon" onclick="confirmDeleteVehicle(${vehicle.id})" title="Excluir">
                                        <i class="fas fa-trash"></i>
                                    </span>
                                </div>
                            </td>
                        `;

                        tableBody.appendChild(row);
                    });

                    // Update pagination
                    const totalCount = data.total_count || data.length;
                    const totalPages = Math.ceil(totalCount / itemsPerPage);
                    updatePagination(totalPages, page);
                })
                .catch(error => {
                    console.error('Erro ao carregar veículos:', error);
                    tableBody.innerHTML = '<tr><td colspan="9" style="text-align:center;">Erro ao carregar os dados. Tente novamente.</td></tr>';
                });
        }

        function updatePagination(totalPages, currentPage) {
            const pagination = document.getElementById('pagination');
            pagination.innerHTML = '';

            if (totalPages <= 1) {
                return;
            }

            // Add previous button
            if (currentPage > 1) {
                const prevItem = document.createElement('div');
                prevItem.className = 'pagination-item';
                prevItem.innerHTML = '<i class="fas fa-chevron-left"></i>';
                prevItem.addEventListener('click', () => loadVehiclesTable(currentPage - 1, document.getElementById('search-vehicle').value));
                pagination.appendChild(prevItem);
            }

            // Add page numbers
            let startPage = Math.max(1, currentPage - 2);
            let endPage = Math.min(totalPages, currentPage + 2);

            for (let i = startPage; i <= endPage; i++) {
                const pageItem = document.createElement('div');
                pageItem.className = 'pagination-item';
                if (i === currentPage) {
                    pageItem.classList.add('active');
                }
                pageItem.textContent = i;
                pageItem.addEventListener('click', () => loadVehiclesTable(i, document.getElementById('search-vehicle').value));
                pagination.appendChild(pageItem);
            }

            // Add next button
            if (currentPage < totalPages) {
                const nextItem = document.createElement('div');
                nextItem.className = 'pagination-item';
                nextItem.innerHTML = '<i class="fas fa-chevron-right"></i>';
                nextItem.addEventListener('click', () => loadVehiclesTable(currentPage + 1, document.getElementById('search-vehicle').value));
                pagination.appendChild(nextItem);
            }
        }

        function populateVehicleDropdowns() {
            fetch('painel_troca_oleo.php?acao=obter_veiculos')
                .then(response => response.json())
                .then(data => {
                    if (data.erro) {
                        showToast('Erro ao carregar veículos: ' + data.erro, 'danger');
                        return;
                    }

                    const vehicleSelect = document.getElementById('vehicle-select');
                    const historyVehicleSelect = document.getElementById('history-vehicle-select');

                    // Clear existing options (except first)
                    while (vehicleSelect.options.length > 1) {
                        vehicleSelect.remove(1);
                    }

                    while (historyVehicleSelect.options.length > 1) {
                        historyVehicleSelect.remove(1);
                    }

                    // Add vehicle options
                    data.forEach(vehicle => {
                        const option = document.createElement('option');
                        option.value = vehicle.id;
                        option.textContent = `${vehicle.veiculo} (${vehicle.placa})`;

                        const historyOption = option.cloneNode(true);

                        vehicleSelect.appendChild(option);
                        historyVehicleSelect.appendChild(historyOption);
                    });
                })
                .catch(error => {
                    showToast('Erro ao carregar veículos: ' + error, 'danger');
                });
        }

        function renderCalendar() {
            const year = calendarDate.getFullYear();
            const month = calendarDate.getMonth();

            // Update calendar month display
            const monthNames = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho',
                                'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
            document.getElementById('calendar-month').textContent = `${monthNames[month]} ${year}`;

            // Get the first day of the month
            const firstDay = new Date(year, month, 1);
            const lastDay = new Date(year, month + 1, 0);

            // Get the day of the week of the first day (0 = Sunday, 6 = Saturday)
            const firstDayOfWeek = firstDay.getDay();

            // Get the number of days in the month
            const daysInMonth = lastDay.getDate();

            // Get the calendar grid container
            const calendarDays = document.getElementById('calendar-days');
            calendarDays.innerHTML = '';

            // Add days from previous month
            const prevMonth = new Date(year, month, 0);
            const daysInPrevMonth = prevMonth.getDate();

            for (let i = firstDayOfWeek - 1; i >= 0; i--) {
                const dayElement = document.createElement('div');
                dayElement.className = 'calendar-day other-month';
                dayElement.innerHTML = `<div class="calendar-day-number">${daysInPrevMonth - i}</div>`;
                calendarDays.appendChild(dayElement);
            }

            // Format dates for API request
            const dataInicial = `${year}-${(month + 1).toString().padStart(2, '0')}-01`;
            const dataFinal = `${year}-${(month + 1).toString().padStart(2, '0')}-${daysInMonth.toString().padStart(2, '0')}`;

            // Get events for this month
            fetch(`painel_troca_oleo.php?acao=obter_proximas_trocas&data_inicial=${dataInicial}&data_final=${dataFinal}`)
                .then(response => response.json())
                .then(events => {
                    // Create event map by day
                    const eventsByDay = {};

                    if (!events.erro && events.length > 0) {
                        events.forEach(event => {
                            const eventDate = new Date(event.proxima_troca_oleo);
                            const eventDay = eventDate.getDate();

                            if (!eventsByDay[eventDay]) {
                                eventsByDay[eventDay] = [];
                            }

                            eventsByDay[eventDay].push(event);
                        });
                    }

                    // Add days of current month with events
                    for (let i = 1; i <= daysInMonth; i++) {
                        const dayDate = new Date(year, month, i);
                        const dayElement = document.createElement('div');
                        dayElement.className = 'calendar-day';

                        // Check if it's today
                        const today = new Date("2025-05-26");
                        if (dayDate.getDate() === today.getDate() &&
                            dayDate.getMonth() === today.getMonth() &&
                            dayDate.getFullYear() === today.getFullYear()) {
                            dayElement.classList.add('today');
                        }

                        // Check if there are events on this date
                        if (eventsByDay[i] && eventsByDay[i].length > 0) {
                            dayElement.classList.add('has-event');

                            const events = eventsByDay[i];

                            if (events.length > 0) {
                                const event = document.createElement('div');
                                event.className = 'calendar-event';
                                event.textContent = `${events.length} troca${events.length > 1 ? 's' : ''}`;

                                // Add click event to show details
                                dayElement.addEventListener('click', () => {
                                    showCalendarEventDetails(dayDate, events);
                                });

                                dayElement.appendChild(event);
                            }
                        }

                        dayElement.innerHTML = `<div class="calendar-day-number">${i}</div>` + dayElement.innerHTML;
                        calendarDays.appendChild(dayElement);
                    }

                    // Add days from next month
                    const totalDaysDisplayed = firstDayOfWeek + daysInMonth;
                    const daysFromNextMonth = 7 - (totalDaysDisplayed % 7);

                    if (daysFromNextMonth < 7) {
                        for (let i = 1; i <= daysFromNextMonth; i++) {
                            const dayElement = document.createElement('div');
                            dayElement.className = 'calendar-day other-month';
                            dayElement.innerHTML = `<div class="calendar-day-number">${i}</div>`;
                            calendarDays.appendChild(dayElement);
                        }
                    }
                })
                .catch(error => {
                    console.error('Erro ao carregar eventos do calendário:', error);

                    // Add days without events if API fails
                    for (let i = 1; i <= daysInMonth; i++) {
                        const dayDate = new Date(year, month, i);
                        const dayElement = document.createElement('div');
                        dayElement.className = 'calendar-day';

                        // Check if it's today
                        const today = new Date("2025-05-26");
                        if (dayDate.getDate() === today.getDate() &&
                            dayDate.getMonth() === today.getMonth() &&
                            dayDate.getFullYear() === today.getFullYear()) {
                            dayElement.classList.add('today');
                        }

                        dayElement.innerHTML = `<div class="calendar-day-number">${i}</div>`;
                        calendarDays.appendChild(dayElement);
                    }
                });
        }

        function prevMonth() {
            calendarDate.setMonth(calendarDate.getMonth() - 1);
            renderCalendar();
        }

        function nextMonth() {
            calendarDate.setMonth(calendarDate.getMonth() + 1);
            renderCalendar();
        }

        function showCalendarEventDetails(date, events) {
            const modal = document.getElementById('calendar-event-modal');
            const title = document.getElementById('calendar-event-title');
            const list = document.getElementById('calendar-event-list');

            // Format date
            const formattedDate = date.toLocaleDateString('pt-BR', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric'
            });

            title.textContent = `Trocas de Óleo - ${formattedDate}`;

            // Clear list
            list.innerHTML = '';

            // Add events
            events.forEach(event => {
                const eventItem = document.createElement('div');
                eventItem.className = 'card';
                eventItem.style.marginBottom = '10px';

                eventItem.innerHTML = `
                    <h3>${event.veiculo} (${event.placa})</h3>
                    <p><strong>Secretaria:</strong> ${event.secretaria || 'N/D'}</p>
                    <button class="btn btn-primary" onclick="registerOilChangeFromCalendar(${event.id})">Registrar Troca</button>
                `;

                list.appendChild(eventItem);
            });

            // Show modal
            modal.classList.add('active');
        }

        function closeCalendarEventModal() {
            document.getElementById('calendar-event-modal').classList.remove('active');
        }

        function registerOilChangeFromCalendar(vehicleId) {
            // Close calendar event modal
            closeCalendarEventModal();

            // Switch to register tab
            activateTab('register');

            // Select the vehicle
            document.getElementById('vehicle-select').value = vehicleId;

            // Populate form with suggested values
            populateOilChangeForm(vehicleId);
        }

        function activateTab(tabId) {
            document.querySelectorAll('.tab-item').forEach(tab => {
                tab.classList.remove('active');
            });

            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });

            document.querySelector(`.tab-item[data-tab="${tabId}"]`).classList.add('active');
            document.getElementById(`${tabId}-tab`).classList.add('active');

            // Additional actions for specific tabs
            if (tabId === 'history') {
                loadHistoryTable();
            } else if (tabId === 'calendar') {
                renderCalendar();
            }
        }

        function loadHistoryTable(page = 1) {
            const tableBody = document.querySelector('#history-table tbody');
            tableBody.innerHTML = '<tr><td colspan="8" style="text-align:center;">Carregando...</td></tr>';

            // Filter by vehicle if selected
            const selectedVehicleId = document.getElementById('history-vehicle-select').value;
            const startDate = document.getElementById('history-start-date').value;
            const endDate = document.getElementById('history-end-date').value;

            let url = `painel_troca_oleo.php?acao=obter_historico&page=${page}&limit=${historyItemsPerPage}`;

            if (selectedVehicleId) {
                url += `&veiculo_id=${selectedVehicleId}`;
            }

            if (startDate) {
                url += `&data_inicial=${startDate}`;
            }

            if (endDate) {
                url += `&data_final=${endDate}`;
            }

            fetch(url)
                .then(response => response.json())
                .then(data => {
                    tableBody.innerHTML = '';

                    if (data.erro) {
                        tableBody.innerHTML = `<tr><td colspan="8" style="text-align:center;">Erro: ${data.erro}</td></tr>`;
                        return;
                    }

                    if (data.length === 0) {
                        tableBody.innerHTML = '<tr><td colspan="8" style="text-align:center;">Nenhum registro encontrado</td></tr>';
                        return;
                    }

                    currentHistoryPage = page;

                    // Add history rows
                    data.forEach(record => {
                        const row = document.createElement('tr');

                        // Format dates for display
                        const changeDate = record.data_realizacao ? new Date(record.data_realizacao).toLocaleDateString('pt-BR') : 'N/D';
                        const nextChangeDate = record.proxima_troca ? new Date(record.proxima_troca).toLocaleDateString('pt-BR') : 'N/D';

                        row.innerHTML = `
                            <td>${record.veiculo || 'N/D'}</td>
                            <td>${record.placa || 'N/D'}</td>
                            <td>${changeDate}</td>
                            <td>${record.km ? record.km.toLocaleString('pt-BR') + ' km' : 'N/D'}</td>
                            <td>${record.tipo_oleo || 'N/D'}</td>
                            <td>${record.quantidade ? record.quantidade + ' L' : 'N/D'}</td>
                            <td>${nextChangeDate}</td>
                            <td>${record.proximo_km ? record.proximo_km.toLocaleString('pt-BR') + ' km' : 'N/D'}</td>
                        `;

                        tableBody.appendChild(row);
                    });

                    // Update pagination
                    const totalCount = data.total_count || data.length;
                    const totalPages = Math.ceil(totalCount / historyItemsPerPage);
                    updateHistoryPagination(totalPages, page);
                })
                .catch(error => {
                    console.error('Erro ao carregar histórico:', error);
                    tableBody.innerHTML = '<tr><td colspan="8" style="text-align:center;">Erro ao carregar os dados. Tente novamente.</td></tr>';
                });
        }

        function updateHistoryPagination(totalPages, currentPage) {
            const pagination = document.getElementById('history-pagination');
            pagination.innerHTML = '';

            if (totalPages <= 1) {
                return;
            }

            // Add previous button
            if (currentPage > 1) {
                const prevItem = document.createElement('div');
                prevItem.className = 'pagination-item';
                prevItem.innerHTML = '<i class="fas fa-chevron-left"></i>';
                prevItem.addEventListener('click', () => loadHistoryTable(currentPage - 1));
                pagination.appendChild(prevItem);
            }

            // Add page numbers
            let startPage = Math.max(1, currentPage - 2);
            let endPage = Math.min(totalPages, currentPage + 2);

            for (let i = startPage; i <= endPage; i++) {
                const pageItem = document.createElement('div');
                pageItem.className = 'pagination-item';
                if (i === currentPage) {
                    pageItem.classList.add('active');
                }
                pageItem.textContent = i;
                pageItem.addEventListener('click', () => loadHistoryTable(i));
                pagination.appendChild(pageItem);
            }

            // Add next button
            if (currentPage < totalPages) {
                const nextItem = document.createElement('div');
                nextItem.className = 'pagination-item';
                nextItem.innerHTML = '<i class="fas fa-chevron-right"></i>';
                nextItem.addEventListener('click', () => loadHistoryTable(currentPage + 1));
                pagination.appendChild(nextItem);
            }
        }

        function filterHistory() {
            loadHistoryTable(1);
        }

        function openVehicleDetails(vehicleId) {
            fetch(`painel_troca_oleo.php?acao=obter_veiculo&id=${vehicleId}`)
                .then(response => response.json())
                .then(vehicle => {
                    if (vehicle.erro) {
                        showToast('Erro ao carregar dados do veículo: ' + vehicle.erro, 'danger');
                        return;
                    }

                    currentVehicleId = vehicleId;

                    // Update vehicle details
                    document.getElementById('vehicle-details-title').textContent = `${vehicle.veiculo || 'N/D'} (${vehicle.placa || 'N/D'})`;
                    document.getElementById('vehicle-name').textContent = vehicle.veiculo || 'N/D';
                    document.getElementById('vehicle-plate').textContent = vehicle.placa || 'N/D';
                    document.getElementById('vehicle-type').textContent = vehicle.tipo || 'N/D';
                    document.getElementById('vehicle-secretary').textContent = vehicle.secretaria || 'N/D';
                    document.getElementById('vehicle-registration').textContent = vehicle.matricula || 'N/D';
                    document.getElementById('vehicle-renavam').textContent = vehicle.renavam || 'N/D';
                    document.getElementById('vehicle-chassis').textContent = vehicle.chassi || 'N/D';
                    document.getElementById('vehicle-brand').textContent = vehicle.marca || 'N/D';
                    document.getElementById('vehicle-year').textContent = vehicle.ano_modelo || 'N/D';
                    document.getElementById('vehicle-fuel').textContent = vehicle.combustivel || 'N/D';
                    document.getElementById('vehicle-tank').textContent = vehicle.tanque ? `${vehicle.tanque} L` : 'N/D';
                    document.getElementById('vehicle-status').textContent = vehicle.status ? vehicle.status.charAt(0).toUpperCase() + vehicle.status.slice(1) : 'N/D';

                    // Oil change info
                    const lastChangeDate = vehicle.ultima_troca ? new Date(vehicle.ultima_troca).toLocaleDateString('pt-BR') : 'N/D';
                    const nextChangeDate = vehicle.proxima_troca_oleo ? new Date(vehicle.proxima_troca_oleo).toLocaleDateString('pt-BR') : 'N/D';

                    document.getElementById('last-change-date').textContent = lastChangeDate;
                    document.getElementById('last-change-km').textContent = vehicle.km_ultima_troca ? `${vehicle.km_ultima_troca.toLocaleString('pt-BR')} km` : 'N/D';
                    document.getElementById('next-change-date-display').textContent = nextChangeDate;
                    document.getElementById('next-change-km-display').textContent = vehicle.proximo_km_troca ? `${vehicle.proximo_km_troca.toLocaleString('pt-BR')} km` : 'N/D';

                    // Calculate oil status
                    if (vehicle.ultima_troca && vehicle.proxima_troca_oleo) {
                        const currentDate = new Date("2025-05-26");
                        const lastChange = new Date(vehicle.ultima_troca);
                        const nextChange = new Date(vehicle.proxima_troca_oleo);

                        const totalDays = Math.floor((nextChange - lastChange) / (1000 * 60 * 60 * 24));
                        const daysUsed = Math.floor((currentDate - lastChange) / (1000 * 60 * 60 * 24));
                        const daysPercentage = Math.round((daysUsed / totalDays) * 100);

                        let kmPercentage = 0;
                        if (vehicle.km_ultima_troca !== null && vehicle.proximo_km_troca !== null && vehicle.km_atual !== null) {
                            const kmTotal = vehicle.proximo_km_troca - vehicle.km_ultima_troca;
                            const kmUsed = vehicle.km_atual - vehicle.km_ultima_troca;
                            if (kmTotal > 0) {
                                kmPercentage = Math.round((kmUsed / kmTotal) * 100);
                            }
                        }

                        // Use the higher percentage
                        const oilUsagePercentage = Math.max(daysPercentage, kmPercentage);

                        document.getElementById('oil-status').textContent = `${Math.min(100, oilUsagePercentage)}%`;
                        document.getElementById('oil-bar').style.width = `${Math.min(100, oilUsagePercentage)}%`;

                        // Oil bar color
                        if (oilUsagePercentage < 50) {
                            document.getElementById('oil-bar').style.background = 'linear-gradient(to right, #2ecc71, #27ae60)'; // Green
                        } else if (oilUsagePercentage < 80) {
                            document.getElementById('oil-bar').style.background = 'linear-gradient(to right, #f1c40f, #f39c12)'; // Yellow/Orange
                        } else {
                            document.getElementById('oil-bar').style.background = 'linear-gradient(to right, #e74c3c, #c0392b)'; // Red
                        }
                    } else {
                        document.getElementById('oil-status').textContent = 'N/D';
                        document.getElementById('oil-bar').style.width = '0%';
                    }

                    // Load vehicle history
                    loadVehicleHistory(vehicleId);

                    // Show vehicle details container
                    document.getElementById('vehicle-details-container').style.display = 'block';

                    // Scroll to details
                    document.getElementById('vehicle-details-container').scrollIntoView({
                        behavior: 'smooth'
                    });
                })
                .catch(error => {
                    showToast('Erro ao carregar detalhes do veículo: ' + error, 'danger');
                });
        }

        function loadVehicleHistory(vehicleId) {
            const tableBody = document.querySelector('#vehicle-history-table tbody');
            tableBody.innerHTML = '<tr><td colspan="7" style="text-align:center;">Carregando histórico...</td></tr>';

            fetch(`painel_troca_oleo.php?acao=obter_historico&veiculo_id=${vehicleId}`)
                .then(response => response.json())
                .then(data => {
                    tableBody.innerHTML = '';

                    if (data.erro) {
                        tableBody.innerHTML = `<tr><td colspan="7" style="text-align:center;">Erro: ${data.erro}</td></tr>`;
                        return;
                    }

                    if (data.length === 0) {
                        tableBody.innerHTML = '<tr><td colspan="7" style="text-align:center;">Nenhum registro de troca encontrado</td></tr>';
                        return;
                    }

                    // Sort by date (newest first)
                    data.sort((a, b) => new Date(b.data_realizacao) - new Date(a.data_realizacao));

                    // Add history rows
                    data.forEach(record => {
                        const row = document.createElement('tr');

                        // Format dates for display
                        const changeDate = record.data_realizacao ? new Date(record.data_realizacao).toLocaleDateString('pt-BR') : 'N/D';
                        const nextChangeDate = record.proxima_troca ? new Date(record.proxima_troca).toLocaleDateString('pt-BR') : 'N/D';

                        row.innerHTML = `
                            <td>${changeDate}</td>
                            <td>${record.km ? record.km.toLocaleString('pt-BR') + ' km' : 'N/D'}</td>
                            <td>${record.tipo_oleo || 'N/D'}</td>
                            <td>${record.quantidade ? record.quantidade + ' L' : 'N/D'}</td>
                            <td>${nextChangeDate}</td>
                            <td>${record.proximo_km ? record.proximo_km.toLocaleString('pt-BR') + ' km' : 'N/D'}</td>
                            <td>${record.observacoes || '-'}</td>
                        `;

                        tableBody.appendChild(row);
                    });
                })
                .catch(error => {
                    console.error('Erro ao carregar histórico do veículo:', error);
                    tableBody.innerHTML = '<tr><td colspan="7" style="text-align:center;">Erro ao carregar os dados. Tente novamente.</td></tr>';
                });
        }

        function openAddVehicleModal() {
            // Reset form
            document.getElementById('vehicle-form').reset();
            document.getElementById('vehicle-form-id').value = '';

            // Set title
            document.getElementById('vehicle-modal-title').textContent = 'Adicionar Veículo';

            // Show modal
            document.getElementById('vehicle-modal').classList.add('active');
        }

        function editVehicleModal(vehicleId) {
            fetch(`painel_troca_oleo.php?acao=obter_veiculo&id=${vehicleId}`)
                .then(response => response.json())
                .then(vehicle => {
                    if (vehicle.erro) {
                        showToast('Erro ao carregar dados do veículo: ' + vehicle.erro, 'danger');
                        return;
                    }

                    // Set title
                    document.getElementById('vehicle-modal-title').textContent = 'Editar Veículo';

                    // Store vehicle ID in hidden field
                    document.getElementById('vehicle-form-id').value = vehicleId;

                    // Fill form fields
                    document.getElementById('vehicle-form-name').value = vehicle.veiculo || '';
                    document.getElementById('vehicle-form-plate').value = vehicle.placa || '';
                    document.getElementById('vehicle-form-type').value = vehicle.tipo || '';
                    document.getElementById('vehicle-form-secretary').value = vehicle.secretaria || '';
                    document.getElementById('vehicle-form-registration').value = vehicle.matricula || '';
                    document.getElementById('vehicle-form-renavam').value = vehicle.renavam || '';
                    document.getElementById('vehicle-form-chassis').value = vehicle.chassi || '';
                    document.getElementById('vehicle-form-brand').value = vehicle.marca || '';
                    document.getElementById('vehicle-form-year').value = vehicle.ano_modelo || '';
                    document.getElementById('vehicle-form-fuel').value = vehicle.combustivel || '';
                    document.getElementById('vehicle-form-tank').value = vehicle.tanque || '';
                    document.getElementById('vehicle-form-status').value = vehicle.status || 'ativo';

                    // Show modal
                    document.getElementById('vehicle-modal').classList.add('active');
                })
                .catch(error => {
                    showToast('Erro ao carregar dados do veículo: ' + error, 'danger');
                });
        }

        function closeVehicleModal() {
            document.getElementById('vehicle-modal').classList.remove('active');
        }

        function saveVehicle() {
            // Get form values
            const vehicleId = document.getElementById('vehicle-form-id').value;
            const formData = new FormData();

            // Add form fields to FormData
            formData.append('veiculo', document.getElementById('vehicle-form-name').value);
            formData.append('placa', document.getElementById('vehicle-form-plate').value);
            formData.append('tipo', document.getElementById('vehicle-form-type').value);
            formData.append('secretaria', document.getElementById('vehicle-form-secretary').value);
            formData.append('matricula', document.getElementById('vehicle-form-registration').value);
            formData.append('renavam', document.getElementById('vehicle-form-renavam').value);
            formData.append('chassi', document.getElementById('vehicle-form-chassis').value);
            formData.append('marca', document.getElementById('vehicle-form-brand').value);
            formData.append('ano_modelo', document.getElementById('vehicle-form-year').value);
            formData.append('combustivel', document.getElementById('vehicle-form-fuel').value);
            formData.append('tanque', document.getElementById('vehicle-form-tank').value);
            formData.append('status', document.getElementById('vehicle-form-status').value);

            // Validate form
            if (!formData.get('veiculo') || !formData.get('placa') || !formData.get('tipo') ||
                !formData.get('secretaria') || !formData.get('marca') || !formData.get('ano_modelo') ||
                !formData.get('combustivel') || !formData.get('tanque')) {
                showToast('Por favor, preencha todos os campos obrigatórios.', 'warning');
                return;
            }

            let url, method;

            if (vehicleId) {
                // Edit existing vehicle
                url = 'painel_troca_oleo.php';
                method = 'POST';
                formData.append('acao', 'atualizar_veiculo');
                formData.append('id', vehicleId);
            } else {
                // Add new vehicle
                url = 'painel_troca_oleo.php';
                method = 'POST';
                formData.append('acao', 'adicionar_veiculo');
            }

            fetch(url, {
                method: method,
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.erro) {
                    showToast('Erro: ' + result.erro, 'danger');
                    return;
                }

                if (vehicleId) {
                    showToast('Veículo atualizado com sucesso!', 'success');
                } else {
                    showToast('Veículo adicionado com sucesso!', 'success');
                }

                // Close modal
                closeVehicleModal();

                // Refresh data
                loadDashboardStats();
                loadVehiclesTable();
                populateVehicleDropdowns();

                // If editing and details are open, refresh them
                if (vehicleId && document.getElementById('vehicle-details-container').style.display === 'block') {
                    openVehicleDetails(vehicleId);
                }
            })
            .catch(error => {
                showToast('Erro ao salvar veículo: ' + error, 'danger');
            });
        }

        function confirmDeleteVehicle(vehicleId = null) {
            // If vehicleId is provided, set it as currentVehicleId
            if (vehicleId !== null) {
                currentVehicleId = vehicleId;
            }

            // Show delete confirmation modal
            document.getElementById('delete-modal').classList.add('active');
        }

        function closeDeleteModal() {
            document.getElementById('delete-modal').classList.remove('active');
        }

        function deleteVehicle() {
            if (!currentVehicleId) {
                closeDeleteModal();
                return;
            }

            const formData = new FormData();
            formData.append('acao', 'excluir_veiculo');
            formData.append('id', currentVehicleId);

            fetch('painel_troca_oleo.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                closeDeleteModal();

                if (result.erro) {
                    showToast('Erro ao excluir veículo: ' + result.erro, 'danger');
                    return;
                }

                showToast('Veículo excluído com sucesso!', 'success');

                // Hide vehicle details if shown
                document.getElementById('vehicle-details-container').style.display = 'none';

                // Refresh data
                loadDashboardStats();
                loadVehiclesTable();
                populateVehicleDropdowns();
            })
            .catch(error => {
                closeDeleteModal();
                showToast('Erro ao excluir veículo: ' + error, 'danger');
            });
        }

        function populateOilChangeForm(vehicleId) {
            fetch(`painel_troca_oleo.php?acao=obter_veiculo&id=${vehicleId}`)
                .then(response => response.json())
                .then(vehicle => {
                    if (vehicle.erro) {
                        showToast('Erro ao carregar dados do veículo: ' + vehicle.erro, 'danger');
                        return;
                    }

                    // Get the latest oil change for this vehicle
                    fetch(`painel_troca_oleo.php?acao=obter_historico&veiculo_id=${vehicleId}&limit=1`)
                        .then(response => response.json())
                        .then(historico => {
                            // Set current km
                            document.getElementById('current-km').value = vehicle.km_atual || 0;

                            // Set suggested oil type and amount from last change
                            if (historico && historico.length > 0) {
                                document.getElementById('oil-type').value = historico[0].tipo_oleo || '';
                                document.getElementById('oil-amount').value = historico[0].quantidade || '';
                            }

                            // Set today's date
                            document.getElementById('change-date').valueAsDate = new Date("2025-05-26");

                            // Calculate and set next change date (3 months from now)
                            const nextChangeDate = new Date("2025-05-26");
                            nextChangeDate.setMonth(nextChangeDate.getMonth() + 3);
                            document.getElementById('next-change-date').valueAsDate = nextChangeDate;

                            // Set next change km (current + 5000km or appropriate value based on vehicle type)
                            let kmIncrement = 5000; // Default

                            // Adjust based on vehicle type and fuel
                            if (vehicle.tipo && vehicle.combustivel) {
                                if (vehicle.tipo === 'Caminhão' || vehicle.tipo === 'Ônibus') {
                                    kmIncrement = 10000;
                                } else if (vehicle.combustivel.includes('Diesel')) {
                                    kmIncrement = 7500;
                                } else if (vehicle.tipo === 'Motocicleta') {
                                    kmIncrement = 3000;
                                }
                            }

                            const currentKm = parseInt(document.getElementById('current-km').value) || 0;
                            document.getElementById('next-change-km').value = currentKm + kmIncrement;
                        })
                        .catch(error => {
                            showToast('Erro ao carregar histórico: ' + error, 'danger');
                        });
                })
                .catch(error => {
                    showToast('Erro ao carregar dados do veículo: ' + error, 'danger');
                });
        }

        function registerOilChange() {
            const vehicleId = parseInt(document.getElementById('vehicle-select').value);
            const currentKm = parseInt(document.getElementById('current-km').value);
            const oilType = document.getElementById('oil-type').value;
            const oilAmount = parseFloat(document.getElementById('oil-amount').value);
            const changeDate = document.getElementById('change-date').value;
            const nextChangeKm = parseInt(document.getElementById('next-change-km').value);
            const nextChangeDate = document.getElementById('next-change-date').value;
            const observations = document.getElementById('observations').value;

            // Validate form
            if (!vehicleId || !currentKm || !oilType || !oilAmount || !changeDate || !nextChangeKm || !nextChangeDate) {
                showToast('Por favor, preencha todos os campos obrigatórios.', 'warning');
                return;
            }

            // Prepare form data
            const formData = new FormData();
            formData.append('acao', 'registrar_troca_oleo');
            formData.append('veiculo_id', vehicleId);
            formData.append('km', currentKm);
            formData.append('tipo_oleo', oilType);
            formData.append('quantidade', oilAmount);
            formData.append('data_realizacao', changeDate);
            formData.append('proximo_km', nextChangeKm);
            formData.append('proxima_troca', nextChangeDate);
            formData.append('observacoes', observations);
            formData.append('realizado_por', 'lipeslt'); // Current user login

            fetch('painel_troca_oleo.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(result => {
                if (result.erro) {
                    showToast('Erro ao registrar troca: ' + result.erro, 'danger');
                    return;
                }

                showToast('Troca de óleo registrada com sucesso!', 'success');

                // Clear form
                clearForm();

                // Refresh data
                loadDashboardStats();
                loadVehiclesTable();

                // If vehicle details are open, refresh them
                if (document.getElementById('vehicle-details-container').style.display === 'block' &&
                    currentVehicleId === vehicleId) {
                    openVehicleDetails(vehicleId);
                }
            })
            .catch(error => {
                showToast('Erro ao registrar troca: ' + error, 'danger');
            });
        }

        function clearForm() {
            document.getElementById('vehicle-select').value = '';
            document.getElementById('current-km').value = '';
            document.getElementById('oil-type').value = '';
            document.getElementById('oil-amount').value = '';
            document.getElementById('change-date').valueAsDate = new Date("2025-05-26");
            document.getElementById('next-change-km').value = '';

            const nextChangeDate = new Date("2025-05-26");
            nextChangeDate.setMonth(nextChangeDate.getMonth() + 3);
            document.getElementById('next-change-date').valueAsDate = nextChangeDate;

            document.getElementById('observations').value = '';
        }

        function showToast(message, type = 'primary') {
            const toastContainer = document.getElementById('toast-container');

            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;

            let icon = 'info-circle';
            if (type === 'success') icon = 'check-circle';
            if (type === 'warning') icon = 'exclamation-triangle';
            if (type === 'danger') icon = 'times-circle';

            toast.innerHTML = `
                <div class="toast-icon">
                    <i class="fas fa-${icon}"></i>
                </div>
                <div class="toast-content">
                    ${message}
                </div>
                <button class="toast-close" onclick="this.parentNode.remove()">
                    <i class="fas fa-times"></i>
                </button>
            `;

            toastContainer.appendChild(toast);

            // Trigger animation
            setTimeout(() => {
                toast.classList.add('active');
            }, 10);

            // Auto remove after 5 seconds
            setTimeout(() => {
                toast.classList.remove('active');

                setTimeout(() => {
                    toast.remove();
                }, 300);
            }, 5000);
        }

        function printReport() {
            window.print();
        }

        function editVehicle() {
            if (currentVehicleId) {
                editVehicleModal(currentVehicleId);
            }
        }

        // Event listener for vehicle select dropdown
        document.getElementById('vehicle-select').addEventListener('change', function() {
            const vehicleId = parseInt(this.value);
            if (vehicleId) {
                populateOilChangeForm(vehicleId);
            }
        });

        function testarConexao() {
            fetch('painel_troca_oleo.php?acao=verificar_banco')
                .then(response => response.json())
                .then(data => {
                    if (data.sucesso) {
                        showToast('Conexão com o banco de dados OK!', 'success');
                    } else {
                        showToast('Erro na conexão: ' + data.erro, 'danger');
                    }
                })
                .catch(error => {
                    showToast('Erro ao verificar conexão: ' + error, 'danger');
                });
        }
    </script>
</body>
</html>
