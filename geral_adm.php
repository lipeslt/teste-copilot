<?php
session_start();
$host = "localhost";
$dbname = "workflow_system";
$username = "root";
$password = "";

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro de conexão: " . $e->getMessage());
}

// MAPA DE SECRETARIAS
$secretarias_map = [
    "Gabinete do Prefeito" => "GABINETE DO PREFEITO",
    "Gabinete do Vice-Prefeito" => "GABINETE DO VICE-PREFEITO",
    "Secretaria Municipal da Mulher de Família" => "SECRETARIA DA MULHER",
    "Secretaria Municipal de Fazenda" => "SECRETARIA DE FAZENDA",
    "Secretaria Municipal de Educação" => "SECRETARIA DE EDUCAÇÃO",
    "Secretaria Municipal de Agricultura e Meio Ambiente" => "SECRETARIA DE AGRICULTURA E MEIO AMBIENTE",
    "Secretaria Municipal de Agricultura Familiar e Segurança Alimentar" => "SECRETARIA DE AGRICULTURA FAMILIAR",
    "Secretaria Municipal de Assistência Social" => "SECRETARIA DE ASSISTÊNCIA SOCIAL",
    "Secretaria Municipal de Desenvolvimento Econômico e Turismo" => "SECRETARIA DE DESENV. ECONÔMICO",
    "Secretaria Municipal de Administração" => "SECRETARIA DE ADMINISTRAÇÃO",
    "Secretaria Municipal de Governo" => "SECRETARIA DE GOVERNO",
    "Secretaria Municipal de Infraestrutura, Transportes e Saneamento" => "SECRETARIA DE INFRAESTRUTURA, TRANSPORTE E SANEAMENTO",
    "Secretaria Municipal de Esporte e Lazer e Juventude" => "SECRETARIA DE ESPORTE E LAZER",
    "Secretaria Municipal da Cidade" => "SECRETARIA DA CIDADE",
    "Secretaria Municipal de Saúde" => "SECRETARIA DE SAÚDE",
    "Secretaria Municipal de Segurança Pública, Trânsito e Defesa Civil" => "SECRETARIA DE SEGURANÇA PÚBLICA",
    "Controladoria Geral do Município" => "CONTROLADORIA GERAL",
    "Procuradoria Geral do Município" => "PROCURADORIA GERAL",
    "Secretaria Municipal de Cultura" => "SECRETARIA DE CULTURA",
    "Secretaria Municipal de Planejamento, Ciência, Tecnologia e Inovação" => "SECRETARIA DE PLANEJAMENTO E TECNOLOGIA",
    "Secretaria Municipal de Obras e Serviços Públicos" => "SECRETARIA DE OBRAS E SERVIÇOS PÚBLICOS",
];

// Verifica se o filtro de secretaria foi aplicado via GET
$mostrar_todos = isset($_GET['todos']);
$filtro_secretaria = $_GET['filtro_secretaria'] ?? '';

if (isset($_GET['buscar'])) {
    if (!empty($filtro_secretaria)) {
        $sql = "SELECT * FROM veiculos WHERE secretaria = :secretaria";
        $stmt = $conn->prepare($sql);
        $stmt->execute([':secretaria' => $secretarias_map[$filtro_secretaria] ?? $filtro_secretaria]);
        $veiculos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} elseif ($mostrar_todos) {
    $sql = "SELECT * FROM veiculos";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $veiculos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $veiculos = [];
}

// Contagem total de veículos
$stmt = $conn->query("SELECT COUNT(*) as total FROM veiculos");
$total_veiculos = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Lógica para adicionar um novo veículo
if (isset($_POST['add_veiculo'])) {
    $veiculo = $_POST['veiculo'];
    $matricula = $_POST['matricula'];
    $placa = $_POST['placa'];
    $renavam = $_POST['renavam'];
    $chassi = $_POST['chassi'];
    $marca = $_POST['marca'];
    $ano_modelo = $_POST['ano_modelo'];
    $tipo = $_POST['tipo'];
    $secretaria = $secretarias_map[$_POST['secretaria']];

    $sql = "INSERT INTO veiculos (veiculo, matricula, placa, renavam, chassi, marca, ano_modelo, tipo, secretaria) VALUES (:veiculo, :matricula, :placa, :renavam, :chassi, :marca, :ano_modelo, :tipo, :secretaria)";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':veiculo' => $veiculo,
        ':matricula' => $matricula,
        ':placa' => $placa,
        ':renavam' => $renavam,
        ':chassi' => $chassi,
        ':marca' => $marca,
        ':ano_modelo' => $ano_modelo,
        ':tipo' => $tipo,
        ':secretaria' => $secretaria
    ]);

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Lógica para editar um veículo
if (isset($_POST['edit_veiculo'])) {
    $veiculo_id = $_POST['id'];
    $veiculo = $_POST['veiculo'];
    $matricula = $_POST['matricula'];
    $placa = $_POST['placa'];
    $renavam = $_POST['renavam'];
    $chassi = $_POST['chassi'];
    $marca = $_POST['marca'];
    $ano_modelo = $_POST['ano_modelo'];
    $tipo = $_POST['tipo'];
    $secretaria = $secretarias_map[$_POST['secretaria']];

    $sql = "UPDATE veiculos SET veiculo = :veiculo, matricula = :matricula, placa = :placa, renavam = :renavam, chassi = :chassi, marca = :marca, ano_modelo = :ano_modelo, tipo = :tipo, secretaria = :secretaria WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        ':veiculo' => $veiculo,
        ':matricula' => $matricula,
        ':placa' => $placa,
        ':renavam' => $renavam,
        ':chassi' => $chassi,
        ':marca' => $marca,
        ':ano_modelo' => $ano_modelo,
        ':tipo' => $tipo,
        ':secretaria' => $secretaria,
        ':id' => $veiculo_id
    ]);

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Lógica para excluir um veículo
if (isset($_GET['delete_veiculo'])) {
    $veiculo_id = $_GET['delete_veiculo'];
    $sql = "DELETE FROM veiculos WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $veiculo_id]);

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// Lógica para preencher o formulário de edição
if (isset($_GET['editar_veiculo'])) {
    $veiculo_id = $_GET['editar_veiculo'];
    $sql = "SELECT * FROM veiculos WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $veiculo_id]);
    $veiculo = $stmt->fetch(PDO::FETCH_ASSOC);

    $veiculo_nome = $veiculo['veiculo'];
    $matricula = $veiculo['matricula'];
    $placa = $veiculo['placa'];
    $renavam = $veiculo['renavam'];
    $chassi = $veiculo['chassi'];
    $marca = $veiculo['marca'];
    $ano_modelo = $veiculo['ano_modelo'];
    $tipo = $veiculo['tipo'];
    $secretaria = array_search($veiculo['secretaria'], $secretarias_map);
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin - Gerenciar Veículos</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary-color: #4F46E5;
            --secondary-color: #10B981;
            --danger-color: #EF4444;
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background-color: #f3f4f6;
        }

        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        .header-gradient {
            background: linear-gradient(135deg, #4F46E5 0%, #7C3AED 100%);
        }

        .btn-primary {
            background-color: var(--primary-color);
            color: white;
            transition: all 0.2s ease;
        }

        .btn-primary:hover {
            background-color: #4338CA;
            transform: translateY(-1px);
        }

        .btn-secondary {
            background-color: var(--secondary-color);
            color: white;
            transition: all 0.2s ease;
        }

        .btn-secondary:hover {
            background-color: #0D9488;
            transform: translateY(-1px);
        }

        .btn-danger {
            background-color: var(--danger-color);
            color: white;
            transition: all 0.2s ease;
        }

        .btn-danger:hover {
            background-color: #DC2626;
            transform: translateY(-1px);
        }

        .input-field {
            border: 1px solid #D1D5DB;
            border-radius: 8px;
            padding: 10px 12px;
            width: 100%;
            transition: border-color 0.2s ease;
        }

        .input-field:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.2);
        }

        .table-container {
            overflow-x: auto;
            border-radius: 12px;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        th {
            position: sticky;
            top: 0;
            background-color: #F9FAFB;
            z-index: 10;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.75rem;
            letter-spacing: 0.05em;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #E5E7EB;
        }

        tr:hover td {
            background-color: #F9FAFB;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 9999px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .status-em-uso {
            background-color: #FEE2E2;
            color: #DC2626;
        }

        .status-livre {
            background-color: #DCFCE7;
            color: #16A34A;
        }

        .action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .action-btn i {
            margin-right: 4px;
        }

        .refresh-button {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        .stats-card {
            transition: all 0.3s ease;
        }

        .stats-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }
        /* Adicione esta regra CSS */
    .button-group {
        display: flex;
        flex-direction: column;
        gap: 1rem; /* Espaçamento de 16px entre os botões */
    }

    /* Mantenha o restante do CSS existente */
    :root {
        --primary-color: #4F46E5;
        --secondary-color: #10B981;
        --danger-color: #EF4444;
    }
    </style>
</head>
<body class="min-h-screen">
    <!-- App Bar -->
    <div class="header-gradient text-white shadow-md">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <div class="flex items-center space-x-3">
                <h1 class="text-xl font-bold">Admin - Gerenciamento de Veículos</h1>
            </div>
            <div class="flex items-center space-x-4">
                <a href="gerar_relatorio.php" class="flex items-center space-x-2 bg-white/20 hover:bg-white/30 px-4 py-2 rounded-lg transition" aria-label="Gerar Relatório">
                    <i class="fas fa-file-alt"></i>
                    <span>Relatório</span>
                </a>
                <a href="menugeraladm.php" class="flex items-center space-x-2 bg-red-500 hover:bg-red-600 px-4 py-2 rounded-lg transition" aria-label="Sair">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Sair</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container mx-auto px-4 py-6">
        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-6">
            <div class="card stats-card p-4 bg-gradient-to-r from-indigo-500 to-indigo-600 text-black">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm opacity-80">Total de Veículos</p>
                        <h3 class="text-2xl font-bold"><?= $total_veiculos ?></h3>
                    </div>
                    <div class="p-3 rounded-full bg-white/20">
                        <i class="fas fa-car"></i>
                    </div>
                </div>
            </div>

            <div class="card stats-card p-4 bg-gradient-to-r from-blue-500 to-blue-600 text-black">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm opacity-80">Cadastrados Hoje</p>
                        <h3 class="text-2xl font-bold">0</h3>
                    </div>
                    <div class="p-3 rounded-full bg-white/20">
                        <i class="fas fa-calendar-day"></i>
                    </div>
                </div>
            </div>

            <div class="card stats-card p-4 bg-gradient-to-r from-green-500 to-green-600 text-black">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm opacity-80">Secretarias</p>
                        <h3 class="text-2xl font-bold"><?= count($secretarias_map) ?></h3>
                    </div>
                    <div class="p-3 rounded-full bg-white/20">
                        <i class="fas fa-building"></i>
                    </div>
                </div>
            </div>

            <div class="card stats-card p-4 bg-gradient-to-r from-purple-500 to-purple-600 text-black">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm opacity-80">Filtrados</p>
                        <h3 class="text-2xl font-bold"><?= count($veiculos) ?></h3>
                    </div>
                    <div class="p-3 rounded-full bg-white/20">
                        <i class="fas fa-filter"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Form Section -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-6">
            <!-- Add Vehicle Form -->
            <div class="card p-6 lg:col-span-1">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">
                    <?= isset($veiculo_id) ? "Editar Veículo" : "Adicionar Veículo" ?>
                </h2>
                <form method="POST" class="space-y-4">
                    <?php if (isset($veiculo_id)): ?>
                        <input type="hidden" name="id" value="<?= $veiculo_id ?>">
                    <?php endif; ?>

                    <div>
                        <label for="veiculo" class="block text-sm font-medium text-gray-700 mb-1">Veículo</label>
                        <input type="text" id="veiculo" name="veiculo" placeholder="Nome do veículo"
                               class="input-field" value="<?= $veiculo_nome ?? '' ?>" required>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="matricula" class="block text-sm font-medium text-gray-700 mb-1">Matrícula</label>
                            <input type="text" id="matricula" name="matricula" placeholder="Matrícula"
                                   class="input-field" value="<?= $matricula ?? '' ?>" required>
                        </div>
                        <div>
                            <label for="placa" class="block text-sm font-medium text-gray-700 mb-1">Placa</label>
                            <input type="text" id="placa" name="placa" placeholder="Placa"
                                   class="input-field" value="<?= $placa ?? '' ?>" required>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="renavam" class="block text-sm font-medium text-gray-700 mb-1">Renavam</label>
                            <input type="text" id="renavam" name="renavam" placeholder="Renavam"
                                   class="input-field" value="<?= $renavam ?? '' ?>">
                        </div>
                        <div>
                            <label for="chassi" class="block text-sm font-medium text-gray-700 mb-1">Chassi</label>
                            <input type="text" id="chassi" name="chassi" placeholder="Chassi"
                                   class="input-field" value="<?= $chassi ?? '' ?>">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="marca" class="block text-sm font-medium text-gray-700 mb-1">Marca</label>
                            <input type="text" id="marca" name="marca" placeholder="Marca"
                                   class="input-field" value="<?= $marca ?? '' ?>" required>
                        </div>
                        <div>
                            <label for="ano_modelo" class="block text-sm font-medium text-gray-700 mb-1">Ano Modelo</label>
                            <input type="text" id="ano_modelo" name="ano_modelo" placeholder="Ano Modelo"
                                   class="input-field" value="<?= $ano_modelo ?? '' ?>" required>
                        </div>
                    </div>

                    <div>
                        <label for="tipo" class="block text-sm font-medium text-gray-700 mb-1">Tipo</label>
                        <input type="text" id="tipo" name="tipo" placeholder="Tipo"
                               class="input-field" value="<?= $tipo ?? '' ?>" required>
                    </div>

                    <div>
                        <label for="secretaria" class="block text-sm font-medium text-gray-700 mb-1">Secretaria</label>
                        <select id="secretaria" name="secretaria" class="input-field" required>
                            <option value="" disabled selected>Selecione a Secretaria</option>
                            <?php foreach ($secretarias_map as $secretaria_nome => $secretaria_db): ?>
                                <option value="<?= $secretaria_nome ?>" <?= (isset($secretaria) && $secretaria == $secretaria_nome) ? 'selected' : '' ?>>
                                    <?= $secretaria_nome ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" name="<?= isset($veiculo_id) ? 'edit_veiculo' : 'add_veiculo' ?>"
                            class="w-full py-3 btn-primary rounded-lg flex items-center justify-center gap-2">
                        <i class="fas <?= isset($veiculo_id) ? 'fa-save' : 'fa-plus' ?>"></i>
                        <?= isset($veiculo_id) ? "Salvar Alterações" : "Adicionar Veículo" ?>
                    </button>

                    <?php if (!empty($error_message)): ?>
                        <div class="text-red-500 text-sm mt-2">
                            <i class="fas fa-exclamation-circle mr-1"></i> <?= $error_message ?>
                        </div>
                    <?php endif; ?>
                </form>
            </div>

            <!-- Filter Section -->
            <div class="card p-6 lg:col-span-2">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Filtrar Veículos</h2>
                <form method="GET" class="space-y-4">
                    <div>
                        <label for="filtro_secretaria" class="block text-sm font-medium text-gray-700 mb-1">Secretaria</label>
                        <select id="filtro_secretaria" name="filtro_secretaria"
                                class="input-field">
                            <option value="" <?= empty($filtro_secretaria) ? 'selected' : '' ?>>Todas as Secretarias</option>
                            <?php foreach ($secretarias_map as $secretaria => $secretaria_db): ?>
                                <option value="<?= $secretaria ?>" <?= ($filtro_secretaria == $secretaria) ? 'selected' : '' ?>>
                                    <?= $secretaria ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <button type="submit" name="buscar"
                                class="w-full py-3 btn-secondary rounded-lg flex items-center justify-center gap-2">
                            <i class="fas fa-search"></i> Filtrar
                        </button>
                        <button type="submit" name="todos" value="1"
                                class="w-full py-3 bg-gray-600 hover:bg-gray-700 text-white rounded-lg flex items-center justify-center gap-2">
                            <i class="fas fa-list"></i> Mostrar Todos
                        </button>
                    </div>
                </form>

                <div class="mt-6 pt-6 border-t border-gray-200">
                    <div class="button-group"> <!-- Adicione esta div wrapper -->
                        <form action="tabela_usados.php" method="POST">
                            <input type="hidden" name="secretaria" value="<?= $mostrar_todos || empty($filtro_secretaria) ? 'TODOS' : htmlspecialchars($filtro_secretaria) ?>">
                            <button type="submit"
                                    class="w-full py-3 bg-purple-600 hover:bg-purple-700 text-white rounded-lg flex items-center justify-center gap-2">
                                <i class="fas fa-car"></i>
                                <?= ($mostrar_todos || empty($filtro_secretaria)) ? 'Ver Todos os Veículos em Uso' : 'Ver Veículos em Uso desta Secretaria' ?>
                            </button>
                        </form>

                        <!-- Novo botão para Relatório de horas de veículo -->
                        <form action="tempo_corrida.php" method="POST">
                            <input type="hidden" name="secretaria" value="<?= $mostrar_todos || empty($filtro_secretaria) ? 'TODOS' : htmlspecialchars($filtro_secretaria) ?>">
                            <button type="submit"
                                    class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg flex items-center justify-center gap-2">
                                <i class="fas fa-clock"></i>
                                Relatório de Horas de Veículo
                            </button>
                        </form>
                    <button onclick="window.location.href='mudar_km_carro.php'" class="w-full py-3 bg-yellow-500 hover:bg-yellow-600 text-white rounded-lg flex items-center justify-center gap-2">
                        <i class="fas fa-user-check"></i> Alterar KM Veículo
                    </button>
               <button onclick="window.location.href='bloquear_veiculo.php'" class="w-full py-3 bg-red-500 hover:bg-red-600 text-white rounded-lg flex items-center justify-center gap-2">
    <i class="fas fa-ban"></i> Bloquear Veículo
</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Vehicles Table -->
        <?php if (!empty($veiculos) || $mostrar_todos): ?>
        <div class="card p-6 mt-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6">
                <h3 class="text-xl font-semibold text-gray-800">
                    <?php if (!empty($filtro_secretaria)): ?>
                        Veículos da Secretaria: <span class="text-indigo-600"><?= htmlspecialchars($filtro_secretaria) ?></span>
                    <?php elseif ($mostrar_todos): ?>
                        Todos os Veículos Cadastrados
                    <?php endif; ?>
                </h3>
                <div class="flex items-center space-x-2 mt-4 md:mt-0">
                    <span class="text-sm text-gray-500">
                        Exibindo <?= count($veiculos) ?> de <?= $total_veiculos ?> veículos
                    </span>
                    <button class="p-2 rounded-full hover:bg-gray-100">
                        <i class="fas fa-sync-alt text-gray-500"></i>
                    </button>
                </div>
            </div>

            <div class="table-container">
                <table class="min-w-full">
                    <thead>
                        <tr>
                            <th class="text-left">Veículo</th>
                            <th class="text-left">Placa</th>
                            <th class="text-left">Marca</th>
                            <th class="text-left">Tipo</th>
                            <th class="text-left">Secretaria</th>
                            <th class="text-left">Status</th>
                            <th class="text-right">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($veiculos as $veiculo): ?>
                        <tr>
                            <td class="py-3">
                                <div class="font-medium"><?= htmlspecialchars($veiculo['veiculo']) ?></div>
                                <div class="text-sm text-gray-500">Mat: <?= htmlspecialchars($veiculo['matricula']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($veiculo['placa']) ?></td>
                            <td><?= htmlspecialchars($veiculo['marca']) ?></td>
                            <td><?= htmlspecialchars($veiculo['tipo']) ?></td>
                            <td>
                                <div class="truncate max-w-xs"><?= htmlspecialchars($veiculo['secretaria']) ?></div>
                            </td>
                            <td>
                                <span class="status-badge <?= ($veiculo['status'] ?? 'livre') === 'em uso' ? 'status-em-uso' : 'status-livre' ?>">
                                    <?= ($veiculo['status'] ?? 'livre') === 'em uso' ? 'EM USO' : 'DISPONÍVEL' ?>
                                </span>
                            </td>
                            <td class="text-right">
                                <div class="flex justify-end space-x-2">
                                    <a href="?editar_veiculo=<?= $veiculo['id'] ?>"
                                       class="action-btn bg-blue-100 text-blue-600 hover:bg-blue-200">
                                        <i class="fas fa-edit"></i> Editar
                                    </a>
                                    <a href="?delete_veiculo=<?= $veiculo['id'] ?>"
                                       class="action-btn bg-red-100 text-red-600 hover:bg-red-200">
                                        <i class="fas fa-trash"></i> Excluir
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (count($veiculos) > 10): ?>
            <div class="mt-6 flex justify-between items-center">
                <div class="text-sm text-gray-500">
                    Mostrando 1 a 10 de <?= count($veiculos) ?> registros
                </div>
                <div class="flex space-x-2">
                    <button class="px-4 py-2 border rounded-lg bg-gray-100 text-gray-600 hover:bg-gray-200">
                        Anterior
                    </button>
                    <button class="px-4 py-2 border rounded-lg bg-indigo-600 text-white hover:bg-indigo-700">
                        Próximo
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php elseif (isset($_GET['buscar']) && empty($veiculos)): ?>
        <div class="card p-8 text-center mt-6">
            <div class="text-gray-400 mb-4">
                <i class="fas fa-car text-5xl"></i>
            </div>
            <h3 class="text-lg font-medium text-gray-700">Nenhum veículo encontrado</h3>
            <p class="text-gray-500 mt-2">
                Não foram encontrados veículos para a secretaria selecionada.
            </p>
            <a href="?todos=1" class="inline-block mt-4 px-6 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
                <i class="fas fa-list mr-2"></i> Ver todos os veículos
            </a>
        </div>
        <?php else: ?>
        <div class="card p-8 text-center mt-6">
            <div class="text-gray-400 mb-4">
                <i class="fas fa-filter text-5xl"></i>
            </div>
            <h3 class="text-lg font-medium text-gray-700">Selecione um filtro</h3>
            <p class="text-gray-500 mt-2">
                Utilize os filtros acima para visualizar os veículos ou clique em "Mostrar Todos".
            </p>
        </div>
        <?php endif; ?>
    </div>

    <script>
    // Função para confirmar exclusão
    document.querySelectorAll('a[href*="delete_veiculo"]').forEach(link => {
        link.addEventListener('click', function(e) {
            if (!confirm('Tem certeza que deseja excluir este veículo?')) {
                e.preventDefault();
            }
        });
    });

    // Atualizar automaticamente a cada 30 segundos se houver filtro aplicado
    <?php if (!empty($veiculos) || isset($_GET['buscar']) || $mostrar_todos): ?>
    setTimeout(() => {
        window.location.reload();
    }, 30000);
    <?php endif; ?>
    </script>
</body>
</html>
