<?php
session_start();
include 'conexao.php';

date_default_timezone_set('America/Cuiaba');

// Verificar se o usuário é admin
if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'geraladm') {
    header('Location: index.php');
    exit;
}

// Configurar conexão para usar UTF-8
$conn->exec("SET NAMES utf8");

// Obter parâmetros de filtro
$tipo_operacao = $_GET['tipo_operacao'] ?? '';
$data_inicial = $_GET['data_inicial'] ?? '';
$data_final = $_GET['data_final'] ?? '';
$registro_id = $_GET['registro_id'] ?? '';
$admin_name = $_GET['admin_name'] ?? '';
$campo_alterado = $_GET['campo_alterado'] ?? '';
$placa = $_GET['placa'] ?? '';

// Determinar limite de registros por página e página atual
$registros_por_pagina = 20;
$pagina_atual = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;
$offset = ($pagina_atual - 1) * $registros_por_pagina;

// Construir a consulta SQL base
$sql_base = "SELECT h.*, 
                    DATE_FORMAT(h.data_alteracao, '%d/%m/%Y %H:%i:%s') as data_formatada,
                    ra.placa
             FROM historico_alteracoes h 
             LEFT JOIN registro_abastecimento ra ON h.registro_id = ra.id
             WHERE h.registro_id IN (SELECT id FROM registro_abastecimento)";
$params = [];

// Adicionar filtros à consulta
if (!empty($tipo_operacao)) {
    $sql_base .= " AND h.tipo_operacao = :tipo_operacao";
    $params[':tipo_operacao'] = $tipo_operacao;
}

if (!empty($data_inicial)) {
    $sql_base .= " AND DATE(h.data_alteracao) >= :data_inicial";
    $params[':data_inicial'] = $data_inicial;
}

if (!empty($data_final)) {
    $sql_base .= " AND DATE(h.data_alteracao) <= :data_final";
    $params[':data_final'] = $data_final;
}

if (!empty($registro_id)) {
    $sql_base .= " AND h.registro_id = :registro_id";
    $params[':registro_id'] = $registro_id;
}

if (!empty($admin_name)) {
    $sql_base .= " AND h.admin_name LIKE :admin_name";
    $params[':admin_name'] = "%$admin_name%";
}

if (!empty($campo_alterado)) {
    $sql_base .= " AND h.campo_alterado LIKE :campo_alterado";
    $params[':campo_alterado'] = "%$campo_alterado%";
}

if (!empty($placa)) {
    $sql_base .= " AND ra.placa LIKE :placa";
    $params[':placa'] = "%$placa%";
}

// Consulta para contar o total de registros
$sql_count = str_replace("SELECT h.*, DATE_FORMAT(h.data_alteracao, '%d/%m/%Y %H:%i:%s') as data_formatada, ra.placa", "SELECT COUNT(*) as total", $sql_base);
$stmt_count = $conn->prepare($sql_count);
foreach ($params as $key => $value) {
    $stmt_count->bindValue($key, $value);
}
$stmt_count->execute();
$result = $stmt_count->fetch(PDO::FETCH_ASSOC);
$total_registros = $result['total'] ?? 0; // Corrigido para evitar erro de índice indefinido
$total_paginas = ceil($total_registros / $registros_por_pagina);

// Consulta para obter os registros da página atual
$sql = $sql_base . " ORDER BY h.data_alteracao DESC LIMIT :offset, :limit";
$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->bindValue(':limit', $registros_por_pagina, PDO::PARAM_INT);
$stmt->execute();
$historico = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obter lista de usuários para o filtro
$sql_usuarios = "SELECT DISTINCT admin_name FROM historico_alteracoes 
                WHERE registro_id IN (SELECT id FROM registro_abastecimento)
                ORDER BY admin_name";
$stmt_usuarios = $conn->prepare($sql_usuarios);
$stmt_usuarios->execute();
$usuarios = $stmt_usuarios->fetchAll(PDO::FETCH_COLUMN);

// Obter lista de campos alterados para o filtro
$sql_campos = "SELECT DISTINCT campo_alterado FROM historico_alteracoes 
               WHERE registro_id IN (SELECT id FROM registro_abastecimento)
               ORDER BY campo_alterado";
$stmt_campos = $conn->prepare($sql_campos);
$stmt_campos->execute();
$campos = $stmt_campos->fetchAll(PDO::FETCH_COLUMN);

// Função para determinar a cor baseada no tipo de operação
function getColorClass($tipo_operacao) {
    switch ($tipo_operacao) {
        case 'ADICAO':
            return 'bg-green-100 text-green-800';
        case 'EXCLUSAO':
            return 'bg-red-100 text-red-800';
        case 'ALTERACAO':
            return 'bg-blue-100 text-blue-800';
        default:
            return 'bg-gray-100 text-gray-800';
    }
}

// Função para truncar texto longo
function truncateText($text, $length = 100) {
    if (strlen($text) <= $length) {
        return $text;
    }
    
    return substr($text, 0, $length) . '...';
}

// Função para formatar o JSON para exibição
function formatJson($json) {
    if (empty($json) || $json == 'N/A') {
        return $json;
    }
    
    try {
        $data = json_decode($json, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            if (is_array($data)) {
                // Se for um registro completo, formatar de forma especial
                if (isset($data['id']) || isset($data['placa']) || isset($data['veiculo'])) {
                    return formatarRegistroAbastecimento($data);
                } else if (isset($data['antes']) && isset($data['depois'])) {
                    // Formato especial para comparação antes/depois
                    $html = '<div class="grid grid-cols-1 gap-2">';
                    $html .= '<div><span class="font-semibold text-gray-700">Antes:</span>' . formatarRegistroAbastecimento($data['antes']) . '</div>';
                    $html .= '<div><span class="font-semibold text-gray-700">Depois:</span>' . formatarRegistroAbastecimento($data['depois']) . '</div>';
                    $html .= '</div>';
                    return $html;
                } else {
                    // Formato padrão para outros tipos de JSON
                    return '<pre class="text-xs overflow-auto max-h-40">' . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
                }
            }
        }
    } catch (Exception $e) {
        // Falha ao decodificar, retornar o texto original
    }
    
    return $json;
}

// Função para formatar registros de abastecimentos de forma mais bonita
function formatarRegistroAbastecimento($data) {
    if (!is_array($data)) {
        return htmlspecialchars($data);
    }
    
    // Definir grupos de campos para melhor organização
    $grupos = [
        'Informações Gerais' => ['id', 'nome', 'secretaria'],
        'Veículo' => ['veiculo', 'placa', 'prefixo'],
        'Abastecimento' => ['litros', 'combustivel', 'km_abastecido'],
        'Pagamento' => ['valor', 'posto_gasolina', 'nota_fiscal'],
        'Data/Hora' => ['data', 'hora']
    ];
    
    // Rótulos personalizados para os campos
    $rotulos = [
        'id' => 'ID',
        'nome' => 'Motorista',
        'secretaria' => 'Secretaria',
        'veiculo' => 'Veículo',
        'placa' => 'Placa',
        'prefixo' => 'Prefixo',
        'km_abastecido' => 'Quilometragem',
        'litros' => 'Litros',
        'combustivel' => 'Combustível',
        'posto_gasolina' => 'Posto',
        'valor' => 'Valor (R$)',
        'nota_fiscal' => 'Nota Fiscal',
        'data' => 'Data',
        'hora' => 'Hora'
    ];
    
    // Formatar a data se existir
    if (isset($data['data']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $data['data'])) {
        $timestamp = strtotime($data['data']);
        if ($timestamp) {
            $data['data'] = date('d/m/Y', $timestamp);
        }
    }
    
    // Formatar valores numéricos
    if (isset($data['km_abastecido']) && is_numeric($data['km_abastecido'])) {
        $data['km_abastecido'] = number_format($data['km_abastecido'], 0, ',', '.');
    }
    
    if (isset($data['litros']) && is_numeric($data['litros'])) {
        $data['litros'] = number_format($data['litros'], 2, ',', '.');
    }
    
    if (isset($data['valor']) && is_numeric($data['valor'])) {
        $data['valor'] = number_format($data['valor'], 2, ',', '.');
    }
    
    // Construir HTML
    $html = '<div class="bg-white rounded-lg overflow-hidden border border-gray-200">';
    
    foreach ($grupos as $nomeGrupo => $campos) {
        $temCampo = false;
        $conteudoGrupo = '';
        
        foreach ($campos as $campo) {
            if (isset($data[$campo]) && $data[$campo] !== '') {
                $temCampo = true;
                $valor = htmlspecialchars($data[$campo]);
                $conteudoGrupo .= '<div class="flex justify-between py-1">';
                $conteudoGrupo .= '<span class="text-gray-600">' . ($rotulos[$campo] ?? $campo) . ':</span>';
                $conteudoGrupo .= '<span class="font-medium text-right">' . $valor . '</span>';
                $conteudoGrupo .= '</div>';
            }
        }
        
        if ($temCampo) {
            $html .= '<div class="px-3 py-2 border-b border-gray-200 last:border-0">';
            $html .= '<div class="text-xs font-semibold uppercase tracking-wider text-indigo-600 mb-1">' . $nomeGrupo . '</div>';
            $html .= $conteudoGrupo;
            $html .= '</div>';
        }
    }
    
    // Adicionar quaisquer campos que não estejam nos grupos definidos
    $camposDefinidos = array_merge(...array_values($grupos));
    $camposExtras = array_diff(array_keys($data), $camposDefinidos);
    if (!empty($camposExtras)) {
        $html .= '<div class="px-3 py-2 border-b border-gray-200">';
        $html .= '<div class="text-xs font-semibold uppercase tracking-wider text-indigo-600 mb-1">Outros Campos</div>';
        
        foreach ($camposExtras as $campo) {
            if ($campo === 'registro_completo') continue; // Pular este campo especial
            
            $valor = is_array($data[$campo]) ? json_encode($data[$campo], JSON_UNESCAPED_UNICODE) : $data[$campo];
            $valor = htmlspecialchars($valor);
            $html .= '<div class="flex justify-between py-1">';
            $html .= '<span class="text-gray-600">' . $campo . ':</span>';
            $html .= '<span class="font-medium text-right">' . $valor . '</span>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
    }
    
    $html .= '</div>';
    return $html;
}

// Função para obter o ícone baseado no tipo de operação
function getOperationIcon($tipo_operacao) {
    switch ($tipo_operacao) {
        case 'ADICAO':
            return '<i class="fas fa-plus-circle text-green-500"></i>';
        case 'EXCLUSAO':
            return '<i class="fas fa-trash-alt text-red-500"></i>';
        case 'ALTERACAO':
            return '<i class="fas fa-edit text-blue-500"></i>';
        default:
            return '<i class="fas fa-question-circle text-gray-500"></i>';
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Histórico de Abastecimentos</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background-color: #f3f4f6;
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        .timeline {
            position: relative;
        }

        .timeline::before {
            content: '';
            position: absolute;
            top: 0;
            left: 24px;
            height: 100%;
            width: 2px;
            background-color: #e5e7eb;
        }

        .timeline-item {
            position: relative;
            margin-left: 50px;
        }

        .timeline-dot {
            position: absolute;
            left: -35px;
            display: flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background-color: white;
            border: 2px solid #e5e7eb;
            z-index: 10;
        }

        .timeline-dot i {
            font-size: 12px;
        }

        .active-filter {
            background-color: #f0f7ff;
            border-color: #3b82f6;
        }

        /* Animações */
        .fade-in {
            animation: fadeIn 0.5s ease-in-out;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Personalização do scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }

        ::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb {
            background: #c5c5c5;
            border-radius: 10px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: #a3a3a3;
        }

        /* Tooltip personalizado */
        .tooltip {
            position: relative;
            display: inline-block;
        }

        .tooltip .tooltiptext {
            visibility: hidden;
            width: 200px;
            background-color: #333;
            color: #fff;
            text-align: center;
            border-radius: 6px;
            padding: 5px;
            position: absolute;
            z-index: 1;
            bottom: 125%;
            left: 50%;
            margin-left: -100px;
            opacity: 0;
            transition: opacity 0.3s;
            font-size: 0.75rem;
        }

        .tooltip:hover .tooltiptext {
            visibility: visible;
            opacity: 1;
        }

        /* Badge para status */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            font-size: 0.75rem;
            font-weight: 600;
            border-radius: 9999px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* Estilos para tabela expandível */
        .details-row {
            display: none;
        }

        .details-row.active {
            display: table-row;
        }

        /* Botão de voltar ao topo */
        .back-to-top {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background-color: #4f46e5;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            opacity: 0;
            transition: opacity 0.3s;
            box-shadow: 0 2px 5px rgba(0,0,0,0.2);
            z-index: 100;
        }

        .back-to-top.visible {
            opacity: 1;
        }

        /* Estilo para os cards */
        .history-card {
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .history-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
    </style>
</head>
<body class="min-h-screen">
    <!-- App Bar -->
    <div class="bg-indigo-600 text-white shadow-md">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <div class="flex items-center space-x-3">
                <h1 class="text-xl font-bold">Histórico de Abastecimentos</h1>
            </div>
            <div>
                <a href="alterar_abastecimentos.php"
                   class="flex items-center space-x-2 bg-white/20 hover:bg-white/30 px-4 py-2 rounded-lg transition"
                   aria-label="Voltar">
                    <i class="fas fa-arrow-left"></i>
                    <span>Voltar</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container mx-auto px-4 py-6">
        <!-- Filtros -->
        <div class="card p-6 mb-6 fade-in">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Filtros</h2>
            <form method="get" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="tipo_operacao" class="block text-sm font-medium text-gray-700 mb-2">Tipo de Operação</label>
                    <select id="tipo_operacao" name="tipo_operacao" class="w-full px-3 py-2 border rounded-md">
                        <option value="">Todos</option>
                        <option value="ADICAO" <?= $tipo_operacao === 'ADICAO' ? 'selected' : '' ?>>Adição</option>
                        <option value="ALTERACAO" <?= $tipo_operacao === 'ALTERACAO' ? 'selected' : '' ?>>Alteração</option>
                        <option value="EXCLUSAO" <?= $tipo_operacao === 'EXCLUSAO' ? 'selected' : '' ?>>Exclusão</option>
                    </select>
                </div>
                
                <div>
                    <label for="data_inicial" class="block text-sm font-medium text-gray-700 mb-2">Data Inicial</label>
                    <input type="date" id="data_inicial" name="data_inicial" class="w-full px-3 py-2 border rounded-md"
                           value="<?= htmlspecialchars($data_inicial) ?>">
                </div>
                
                <div>
                    <label for="data_final" class="block text-sm font-medium text-gray-700 mb-2">Data Final</label>
                    <input type="date" id="data_final" name="data_final" class="w-full px-3 py-2 border rounded-md"
                           value="<?= htmlspecialchars($data_final) ?>">
                </div>
                
                <div>
                    <label for="registro_id" class="block text-sm font-medium text-gray-700 mb-2">ID do Registro</label>
                    <input type="number" id="registro_id" name="registro_id" class="w-full px-3 py-2 border rounded-md"
                           value="<?= htmlspecialchars($registro_id) ?>" placeholder="Ex: 123">
                </div>
                
                <div>
                    <label for="placa" class="block text-sm font-medium text-gray-700 mb-2">Placa do Veículo</label>
                    <input type="text" id="placa" name="placa" class="w-full px-3 py-2 border rounded-md"
                           value="<?= htmlspecialchars($placa) ?>" placeholder="Ex: ABC1234">
                </div>
                
                <div>
                    <label for="admin_name" class="block text-sm font-medium text-gray-700 mb-2">Usuário</label>
                    <select id="admin_name" name="admin_name" class="w-full px-3 py-2 border rounded-md">
                        <option value="">Todos</option>
                        <?php foreach ($usuarios as $usuario): ?>
                            <option value="<?= htmlspecialchars($usuario) ?>" <?= $admin_name === $usuario ? 'selected' : '' ?>><?= htmlspecialchars($usuario) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div>
                    <label for="campo_alterado" class="block text-sm font-medium text-gray-700 mb-2">Campo Alterado</label>
                    <select id="campo_alterado" name="campo_alterado" class="w-full px-3 py-2 border rounded-md">
                        <option value="">Todos</option>
                        <?php foreach ($campos as $campo): ?>
                            <option value="<?= htmlspecialchars($campo) ?>" <?= $campo_alterado === $campo ? 'selected' : '' ?>><?= htmlspecialchars($campo) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="md:col-span-3 flex justify-between">
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-md transition">
                        <i class="fas fa-search mr-2"></i> Pesquisar
                    </button>
                    
                    <a href="historico_abastecimentos.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded-md transition">
                        <i class="fas fa-undo mr-2"></i> Limpar Filtros
                    </a>
                </div>
            </form>
        </div>

        <!-- Resumo -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6 fade-in">
            <div class="card p-6 flex items-center">
                <div class="rounded-full bg-green-100 p-3 mr-4">
                    <i class="fas fa-plus text-green-600"></i>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-gray-800">Adições</h3>
                    <?php
                    $stmt_count_add = $conn->prepare("SELECT COUNT(*) FROM historico_alteracoes 
                                                     WHERE tipo_operacao = 'ADICAO' 
                                                     AND registro_id IN (SELECT id FROM registro_abastecimento)");
                    $stmt_count_add->execute();
                    $count_add = $stmt_count_add->fetchColumn();
                    ?>
                    <p class="text-2xl font-bold text-gray-700"><?= number_format($count_add, 0, ',', '.') ?></p>
                </div>
            </div>
            
            <div class="card p-6 flex items-center">
                <div class="rounded-full bg-blue-100 p-3 mr-4">
                    <i class="fas fa-edit text-blue-600"></i>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-gray-800">Alterações</h3>
                    <?php
                    $stmt_count_edit = $conn->prepare("SELECT COUNT(*) FROM historico_alteracoes 
                                                      WHERE tipo_operacao = 'ALTERACAO' 
                                                      AND registro_id IN (SELECT id FROM registro_abastecimento)");
                    $stmt_count_edit->execute();
                    $count_edit = $stmt_count_edit->fetchColumn();
                    ?>
                    <p class="text-2xl font-bold text-gray-700"><?= number_format($count_edit, 0, ',', '.') ?></p>
                </div>
            </div>
            
            <div class="card p-6 flex items-center">
                <div class="rounded-full bg-red-100 p-3 mr-4">
                    <i class="fas fa-trash-alt text-red-600"></i>
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-gray-800">Exclusões</h3>
                    <?php
                    $stmt_count_del = $conn->prepare("SELECT COUNT(*) FROM historico_alteracoes 
                                                     WHERE tipo_operacao = 'EXCLUSAO' 
                                                     AND registro_id IN (SELECT id FROM registro_abastecimento)");
                    $stmt_count_del->execute();
                    $count_del = $stmt_count_del->fetchColumn();
                    ?>
                    <p class="text-2xl font-bold text-gray-700"><?= number_format($count_del, 0, ',', '.') ?></p>
                </div>
            </div>
        </div>

        <!-- Histórico -->
        <div class="card p-6 mb-6 fade-in">
            <div class="flex justify-between items-center mb-6">
                <h2 class="text-xl font-semibold text-gray-800">Registro de Alterações</h2>
                <div class="flex space-x-2">
                    <button id="toggleView" class="bg-indigo-100 hover:bg-indigo-200 text-indigo-700 px-3 py-1 rounded-md text-sm transition">
                        <i class="fas fa-th-list mr-1"></i> Alternar Visualização
                    </button>
                    <button id="exportCSV" class="bg-green-100 hover:bg-green-200 text-green-700 px-3 py-1 rounded-md text-sm transition">
                        <i class="fas fa-file-csv mr-1"></i> Exportar CSV
                    </button>
                </div>
            </div>
            
            <?php if (count($historico) > 0): ?>
                <!-- Visualização em Tabela (padrão) -->
                <div id="tableView" class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data/Hora</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Usuário</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Operação</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Placa</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Campo</th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <?php foreach ($historico as $index => $item): ?>
                                <tr class="hover:bg-gray-50 transition" data-id="<?= $item['id'] ?>">
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= $item['data_formatada'] ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <div class="flex items-center">
                                            <div class="flex-shrink-0 h-8 w-8 bg-indigo-100 rounded-full flex items-center justify-center text-indigo-800 font-bold">
                                                <?= strtoupper(substr($item['admin_name'], 0, 1)) ?>
                                            </div>
                                            <div class="ml-4">
                                                <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($item['admin_name']) ?></div>
                                                <div class="text-xs text-gray-500">ID: <?= $item['admin_id'] ?></div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <span class="badge <?= getColorClass($item['tipo_operacao']) ?>">
                                            <?= getOperationIcon($item['tipo_operacao']) ?> 
                                            <span class="ml-1"><?= $item['tipo_operacao'] ?></span>
                                        </span>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?= htmlspecialchars($item['placa'] ?? '-') ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                                        <?= $item['registro_id'] ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                                        <?= htmlspecialchars($item['campo_alterado']) ?>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                        <button class="text-indigo-600 hover:text-indigo-900 toggle-details" data-id="<?= $item['id'] ?>">
                                            <i class="fas fa-chevron-down"></i> Detalhes
                                        </button>
                                    </td>
                                </tr>
                                <tr class="details-row bg-gray-50" id="details-<?= $item['id'] ?>">
                                    <td colspan="7" class="px-6 py-4">
                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <h4 class="font-semibold text-gray-700 mb-2">Valor Antigo</h4>
                                                <div class="bg-white p-3 rounded border border-gray-200 text-sm text-gray-700 overflow-auto max-h-60">
                                                    <?= formatJson($item['valor_antigo']) ?>
                                                </div>
                                            </div>
                                            <div>
                                                <h4 class="font-semibold text-gray-700 mb-2">Valor Novo</h4>
                                                <div class="bg-white p-3 rounded border border-gray-200 text-sm text-gray-700 overflow-auto max-h-60">
                                                    <?= formatJson($item['valor_novo']) ?>
                                                </div>
                                            </div>
                                        </div>
                                        <?php if (!empty($item['registro_completo']) && $item['registro_completo'] != 'null'): ?>
                                            <div class="mt-4">
                                                <h4 class="font-semibold text-gray-700 mb-2">Registro Completo</h4>
                                                <div class="bg-white p-3 rounded border border-gray-200 text-sm text-gray-700 overflow-auto max-h-60">
                                                    <?= formatJson($item['registro_completo']) ?>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Visualização em Cards (alternativa) -->
                <div id="cardView" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" style="display: none;">
                    <?php foreach ($historico as $item): ?>
                        <div class="history-card card p-4 border-l-4 <?= $item['tipo_operacao'] == 'ADICAO' ? 'border-green-500' : ($item['tipo_operacao'] == 'EXCLUSAO' ? 'border-red-500' : 'border-blue-500') ?>">
                            <div class="flex justify-between items-start mb-3">
                                <div>
                                    <span class="badge <?= getColorClass($item['tipo_operacao']) ?>">
                                        <?= getOperationIcon($item['tipo_operacao']) ?> 
                                        <span class="ml-1"><?= $item['tipo_operacao'] ?></span>
                                    </span>
                                </div>
                                <div class="text-xs text-gray-500"><?= $item['data_formatada'] ?></div>
                            </div>
                            
                            <div class="flex items-center mb-3">
                                <div class="flex-shrink-0 h-8 w-8 bg-indigo-100 rounded-full flex items-center justify-center text-indigo-800 font-bold">
                                    <?= strtoupper(substr($item['admin_name'], 0, 1)) ?>
                                </div>
                                <div class="ml-2">
                                    <div class="text-sm font-medium text-gray-900"><?= htmlspecialchars($item['admin_name']) ?></div>
                                    <div class="text-xs text-gray-500">ID: <?= $item['admin_id'] ?></div>
                                </div>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-2 mb-3">
                                <div>
                                    <div class="text-xs font-medium text-gray-500 uppercase">Placa</div>
                                    <div class="text-sm font-semibold"><?= htmlspecialchars($item['placa'] ?? '-') ?></div>
                                </div>
                                <div>
                                    <div class="text-xs font-medium text-gray-500 uppercase">ID Registro</div>
                                    <div class="text-sm"><?= $item['registro_id'] ?></div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="text-xs font-medium text-gray-500 uppercase">Campo</div>
                                <div class="text-sm font-semibold"><?= htmlspecialchars($item['campo_alterado']) ?></div>
                            </div>
                            
                            <div class="border-t border-gray-200 pt-3 mt-3">
                                <button class="text-indigo-600 hover:text-indigo-900 text-sm font-medium show-card-details" data-id="<?= $item['id'] ?>">
                                    <i class="fas fa-eye mr-1"></i> Ver Detalhes
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Modal de Detalhes para Cards -->
                <div id="detailsModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" style="display: none;">
                    <div class="bg-white rounded-lg max-w-3xl w-full max-h-screen overflow-y-auto p-6">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-xl font-semibold text-gray-900">Detalhes da Alteração</h3>
                            <button id="closeModal" class="text-gray-500 hover:text-gray-700">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        
                        <div id="modalContent">
                            <!-- Conteúdo será preenchido via JavaScript -->
                        </div>
                    </div>
                </div>

                <!-- Paginação -->
                <?php if ($total_paginas > 1): ?>
                    <div class="flex justify-center mt-6">
                        <nav class="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 rounded-md">
                            <div class="flex-1 flex justify-between items-center">
                                <?php if ($pagina_atual > 1): ?>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina_atual - 1])) ?>"
                                       class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                        Anterior
                                    </a>
                                <?php else: ?>
                                    <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-300 bg-gray-50 cursor-not-allowed">
                                        Anterior
                                    </span>
                                <?php endif; ?>
                                
                                <div class="hidden md:flex">
                                    <?php for ($i = max(1, $pagina_atual - 2); $i <= min($total_paginas, $pagina_atual + 2); $i++): ?>
                                        <?php if ($i == $pagina_atual): ?>
                                            <span class="relative inline-flex items-center px-4 py-2 mx-1 border border-indigo-500 text-sm font-medium rounded-md text-white bg-indigo-600">
                                                <?= $i ?>
                                            </span>
                                        <?php else: ?>
                                            <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>"
                                               class="relative inline-flex items-center px-4 py-2 mx-1 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                                <?= $i ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php endfor; ?>
                                </div>
                                
                                <span class="md:hidden text-sm text-gray-700">
                                    Página <?= $pagina_atual ?> de <?= $total_paginas ?>
                                </span>
                                
                                <?php if ($pagina_atual < $total_paginas): ?>
                                    <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina_atual + 1])) ?>"
                                       class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                                        Próxima
                                    </a>
                                <?php else: ?>
                                    <span class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-300 bg-gray-50 cursor-not-allowed">
                                        Próxima
                                    </span>
                                <?php endif; ?>
                            </div>
                        </nav>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="text-center py-12">
                    <i class="fas fa-gas-pump text-5xl text-gray-300 mb-4"></i>
                    <h3 class="text-xl font-medium text-gray-700 mb-2">Nenhum registro encontrado</h3>
                    <p class="text-gray-500">Não foram encontrados registros de histórico de abastecimentos com os filtros aplicados.</p>
                    <?php if (!empty($tipo_operacao) || !empty($data_inicial) || !empty($data_final) || !empty($registro_id) || !empty($admin_name) || !empty($campo_alterado) || !empty($placa)): ?>
                        <a href="historico_abastecimentos.php" class="mt-4 inline-block bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-md transition">
                            Limpar Filtros
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Botão de voltar ao topo -->
    <div class="back-to-top" id="backToTop">
        <i class="fas fa-arrow-up"></i>
    </div>

    <script>
    $(document).ready(function() {
        // Toggle para detalhes na tabela
        $('.toggle-details').on('click', function() {
            const id = $(this).data('id');
            const detailsRow = $(`#details-${id}`);
            const icon = $(this).find('i');
            
            if (detailsRow.hasClass('active')) {
                detailsRow.removeClass('active');
                icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
            } else {
                detailsRow.addClass('active');
                icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
            }
        });
        
        // Alternar entre visualizações (tabela e cards)
        $('#toggleView').on('click', function() {
            const tableView = $('#tableView');
            const cardView = $('#cardView');
            
            if (tableView.is(':visible')) {
                tableView.hide();
                cardView.show();
                $(this).html('<i class="fas fa-table mr-1"></i> Visualizar Tabela');
            } else {
                cardView.hide();
                tableView.show();
                $(this).html('<i class="fas fa-th-list mr-1"></i> Visualizar Cards');
            }
        });
        
        // Mostrar modal de detalhes para cards
        $('.show-card-details').on('click', function() {
            const id = $(this).data('id');
            const detailsRow = $(`#details-${id}`);
            
            // Clonar o conteúdo da linha de detalhes para o modal
            $('#modalContent').html(detailsRow.find('td').html());
            
            // Mostrar o modal
            $('#detailsModal').fadeIn(300);
        });
        
        // Fechar modal
        $('#closeModal').on('click', function() {
            $('#detailsModal').fadeOut(300);
        });
        
        // Fechar modal ao clicar fora
        $('#detailsModal').on('click', function(e) {
            if (e.target === this) {
                $(this).fadeOut(300);
            }
        });
        
        // Botão de voltar ao topo
        const backToTop = $('#backToTop');
        
        $(window).scroll(function() {
            if ($(this).scrollTop() > 300) {
                backToTop.addClass('visible');
            } else {
                backToTop.removeClass('visible');
            }
        });
        
        backToTop.on('click', function() {
            $('html, body').animate({scrollTop: 0}, 500);
            return false;
        });
        
        // Exportar para CSV
        $('#exportCSV').on('click', function() {
            // Construir URL com os parâmetros de filtro atuais
            let url = 'csv_abastecimentos.php?';
            const params = new URLSearchParams(window.location.search);
            url += params.toString();
            
            // Redirecionar para o script de exportação
            window.location.href = url;
        });
    });
    </script>
</body>
</html>