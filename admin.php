<?php
session_start();
$host = "localhost"; // Defina o host do banco
$dbname = "workflow_system"; // Nome do banco
$username = "root"; // Substitua pelo usuário do banco
$password = ""; // Substitua pela senha correta

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

//VARIAVEL DE ERRO DA PLACA
$error_message = "";
$is_valid = true;

// Adicionar veículo
if (isset($_POST['add_veiculo'])) {
    $veiculo = $_POST['veiculo'];
    $matricula = $_POST['matricula'];
    $placa = $_POST['placa'];
    $renavam = $_POST['renavam'];
    $chassi = $_POST['chassi'];
    $marca = $_POST['marca'];
    $ano_modelo = $_POST['ano_modelo'];
    $tipo_combustivel = $_POST['tipo_combustivel']; // Alterado de 'tipo' para 'tipo_combustivel'
    $secretaria = $_POST['secretaria'];
    $tanque = $_POST['tanque'];

    // Mapeia a secretaria para o valor correto no banco de dados
    $secretaria_db = $secretarias_map[$secretaria];

    // Validação da placa
    if (!preg_match('/^[A-Z]{3}-\d([A-Z]\d{2}|\d{3})$/', $placa)) {
        $error_message = "Placa inválida! O formato correto é ABC-1234 ou ABC-1D23.";
        $is_valid = false;
    }

    // Se a placa for válida, adiciona o veículo
    if ($is_valid) {
        // Assumindo que a coluna no banco de dados para tipo de combustível é 'tipo'
        $sql = "INSERT INTO veiculos (veiculo, matricula, placa, renavam, chassi, marca, ano_modelo, tipo, secretaria, tanque)
                VALUES (:veiculo, :matricula, :placa, :renavam, :chassi, :marca, :ano_modelo, :tipo_combustivel, :secretaria, :tanque)";

        $stmt = $conn->prepare($sql);
        if ($stmt->execute([
            ':veiculo' => $veiculo,
            ':matricula' => $matricula,
            ':placa' => $placa,
            ':renavam' => $renavam,
            ':chassi' => $chassi,
            ':marca' => $marca,
            ':ano_modelo' => $ano_modelo,
            ':tipo_combustivel' => $tipo_combustivel, // Alterado de ':tipo'
            ':secretaria' => $secretaria_db,
            ':tanque' => $tanque
        ])) {
            echo "<script>alert('Veículo adicionado com sucesso!'); window.location.href = window.location.href;</script>";
        } else {
            echo "<script>alert('Erro ao adicionar veículo.');</script>";
        }
    }
}

// Editar veículo
if (isset($_POST['edit_veiculo'])) {
    $id = $_POST['id'];
    $veiculo = $_POST['veiculo'];
    $matricula = $_POST['matricula'];
    $placa = $_POST['placa'];
    $renavam = $_POST['renavam'];
    $chassi = $_POST['chassi'];
    $marca = $_POST['marca'];
    $ano_modelo = $_POST['ano_modelo'];
    $tipo_combustivel = $_POST['tipo_combustivel']; // Alterado de 'tipo' para 'tipo_combustivel'
    $secretaria = $_POST['secretaria'];
    $tanque = $_POST['tanque'];

    // Mapeia a secretaria para o valor correto no banco de dados
    $secretaria_db = $secretarias_map[$secretaria];

    // Validação da placa
    if (!preg_match('/^[A-Z]{3}-\d([A-Z]\d{2}|\d{3})$/', $placa)) {
        $error_message = "Placa inválida! O formato correto é ABC-1234 ou ABC-1D23.";
        $is_valid = false;
    }

    // Se a placa for válida, edita o veículo
    if ($is_valid) {
        // Assumindo que a coluna no banco de dados para tipo de combustível é 'tipo'
        $sql = "UPDATE veiculos SET
                    veiculo = :veiculo,
                    matricula = :matricula,
                    placa = :placa,
                    renavam = :renavam,
                    chassi = :chassi,
                    marca = :marca,
                    ano_modelo = :ano_modelo,
                    tipo = :tipo_combustivel, /* Alterado de 'tipo' */
                    secretaria = :secretaria,
                    tanque = :tanque
                WHERE id = :id";

        $stmt = $conn->prepare($sql);
        if ($stmt->execute([
            ':id' => $id,
            ':veiculo' => $veiculo,
            ':matricula' => $matricula,
            ':placa' => $placa,
            ':renavam' => $renavam,
            ':chassi' => $chassi,
            ':marca' => $marca,
            ':ano_modelo' => $ano_modelo,
            ':tipo_combustivel' => $tipo_combustivel, // Alterado de ':tipo'
            ':secretaria' => $secretaria_db,
            ':tanque' => $tanque
        ])) {
            echo "<script>alert('Veículo editado com sucesso!'); window.location.href = window.location.href.split('?')[0];</script>";
        } else {
            echo "<script>alert('Erro ao editar veículo.');</script>";
        }
    }
}

// Excluir veículo
if (isset($_GET['delete_veiculo'])) {
    $id = $_GET['delete_veiculo'];

    $sql = "DELETE FROM veiculos WHERE id = :id";
    $stmt = $conn->prepare($sql);
    if ($stmt->execute([':id' => $id])) {
        echo "<script>alert('Veículo excluído com sucesso!'); window.location.href = window.location.href.split('?')[0];</script>";
    } else {
        echo "<script>alert('Erro ao excluir veículo.');</script>";
    }
}

$veiculo_nome = '';
$matricula = '';
$placa = '';
$renavam = '';
$chassi = '';
$marca = '';
$ano_modelo = '';
$tipo_combustivel_atual = ''; // Variável para armazenar o tipo de combustível ao editar
$secretaria_veiculo = ''; // Usar um nome diferente para evitar conflito com $secretaria_admin
$tanque = '';


// Verifica se o parâmetro de edição está presente
if (isset($_GET['editar_veiculo'])) {
    $id = $_GET['editar_veiculo'];

    $sql = "SELECT * FROM veiculos WHERE id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':id' => $id]);
    $veiculo_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($veiculo_data) {
        // Preenche os campos do formulário com os dados do veículo
        $veiculo_id = $veiculo_data['id'];
        $veiculo_nome = $veiculo_data['veiculo'];
        $matricula = $veiculo_data['matricula'];
        $placa = $veiculo_data['placa'];
        $renavam = $veiculo_data['renavam'];
        $chassi = $veiculo_data['chassi'];
        $marca = $veiculo_data['marca'];
        $ano_modelo = $veiculo_data['ano_modelo'];
        $tipo_combustivel_atual = $veiculo_data['tipo']; // Assumindo que 'tipo' é a coluna do combustível
        $secretaria_veiculo = $veiculo_data['secretaria'];
        $tanque = $veiculo_data['tanque'];
    } else {
        echo "<script>alert('Veículo não encontrado.');</script>";
    }
}

// Pega a secretaria do admin logado
$secretaria_admin = $_SESSION['secretaria'];

// Mapeia a secretaria para o valor correto no banco de dados
$secretaria_admin_db = $secretarias_map[$secretaria_admin];

// Consulta para buscar veículos da secretaria do admin
$sql = "SELECT * FROM veiculos WHERE secretaria = :secretaria";
$stmt = $conn->prepare($sql);
$stmt->bindParam(':secretaria', $secretaria_admin_db, PDO::PARAM_STR);
$stmt->execute();
$veiculos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contagem total de veículos
$stmt_total = $conn->prepare("SELECT COUNT(*) as total FROM veiculos WHERE secretaria = :secretaria_admin_db");
$stmt_total->bindParam(':secretaria_admin_db', $secretaria_admin_db, PDO::PARAM_STR);
$stmt_total->execute();
$total_veiculos = $stmt_total->fetch(PDO::FETCH_ASSOC)['total'];

// Contagem de veículos em uso
$stmt_em_uso = $conn->prepare("SELECT COUNT(*) as em_uso FROM veiculos WHERE secretaria = :secretaria_admin_db AND status = 'em uso'");
$stmt_em_uso->bindParam(':secretaria_admin_db', $secretaria_admin_db, PDO::PARAM_STR);
$stmt_em_uso->execute();
$veiculos_em_uso = $stmt_em_uso->fetch(PDO::FETCH_ASSOC)['em_uso'];


// Contagem de veículos disponíveis
$veiculos_disponiveis = $total_veiculos - $veiculos_em_uso;

$opcoes_combustivel = ['Gasolina', 'Etanol', 'Diesel-S10', 'Diesel-S500'];
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
     <link rel="icon" type="png" href="ico_nav/img.claro.png" media="(prefers-color-scheme: light)">
    <link rel="icon" type="png" href="ico_nav/img.escuro.png" media="(prefers-color-scheme: dark)">
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

        .stats-card {
            transition: all 0.3s ease;
        }

        .stats-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1);
        }

        .truncate {
            max-width: 150px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        /* Estilo para impressão */
        @media print {
            body * {
                visibility: hidden;
            }
            #printable-area, #printable-area * {
                visibility: visible;
            }
            #printable-area {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
                margin: 0;
                padding: 15px;
            }
            .no-print {
                display: none !important;
            }
            table {
                width: 100%;
                border-collapse: collapse;
            }
            th, td {
                border: 1px solid #ddd;
                padding: 8px;
            }
            th {
                background-color: #f2f2f2;
            }
        }
        .btn-yellow {
            background-color: #FFD700;
            color: #000;
            transition: all 0.2s ease;
        }

        .btn-yellow:hover {
            background-color: #FFC000;
            transform: translateY(-1px);
        }

        .btn-blue {
            background-color: #4F46E5;
            color: #000;
            transition: all 0.2s ease;
        }

        .btn-blue:hover {
            background-color: #4F46E5;
            transform: translateY(-1px);
        }
    </style>
</head>
<body class="min-h-screen">
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
                <a href="menuadm.php" class="flex items-center space-x-2 bg-red-500 hover:bg-red-600 px-4 py-2 rounded-lg transition" aria-label="Sair">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>Sair</span>
                </a>
            </div>
        </div>
    </div>

    <div class="container mx-auto px-4 py-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
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

            <div class="card stats-card p-4 bg-gradient-to-r from-green-500 to-green-600 text-black">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm opacity-80">Disponíveis</p>
                        <h3 class="text-2xl font-bold"><?= $veiculos_disponiveis ?></h3>
                    </div>
                    <div class="p-3 rounded-full bg-white/20">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
            </div>

            <div class="card stats-card p-4 bg-gradient-to-r from-red-500 to-red-600 text-black">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm opacity-80">Em Uso</p>
                        <h3 class="text-2xl font-bold"><?= $veiculos_em_uso ?></h3>
                    </div>
                    <div class="p-3 rounded-full bg-white/20">
                        <i class="fas fa-car-side"></i>
                    </div>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <div class="card p-6">
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
                               class="input-field" value="<?= htmlspecialchars($veiculo_nome) ?>" required>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="matricula" class="block text-sm font-medium text-gray-700 mb-1">Matrícula</label>
                            <input type="text" id="matricula" name="matricula" placeholder="Matrícula"
                                   class="input-field" value="<?= htmlspecialchars($matricula) ?>" required>
                        </div>
                        <div>
                            <label for="placa" class="block text-sm font-medium text-gray-700 mb-1">Placa</label>
                            <input type="text" id="placa" name="placa" placeholder="Placa"
                                   class="input-field" value="<?= htmlspecialchars($placa) ?>" required>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="renavam" class="block text-sm font-medium text-gray-700 mb-1">Renavam</label>
                            <input type="text" id="renavam" name="renavam" placeholder="Renavam"
                                   class="input-field" value="<?= htmlspecialchars($renavam) ?>">
                        </div>
                        <div>
                            <label for="chassi" class="block text-sm font-medium text-gray-700 mb-1">Chassi</label>
                            <input type="text" id="chassi" name="chassi" placeholder="Chassi"
                                   class="input-field" value="<?= htmlspecialchars($chassi) ?>">
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="marca" class="block text-sm font-medium text-gray-700 mb-1">Marca</label>
                            <input type="text" id="marca" name="marca" placeholder="Marca"
                                   class="input-field" value="<?= htmlspecialchars($marca) ?>" required>
                        </div>
                        <div>
                            <label for="ano_modelo" class="block text-sm font-medium text-gray-700 mb-1">Ano Modelo</label>
                            <input type="text" id="ano_modelo" name="ano_modelo" placeholder="Ano Modelo"
                                   class="input-field" value="<?= htmlspecialchars($ano_modelo) ?>" required>
                        </div>
                    </div>
                    
                    <div>
                        <label for="tipo_combustivel" class="block text-sm font-medium text-gray-700 mb-1">Tipo de Combustível</label>
                        <select id="tipo_combustivel" name="tipo_combustivel" class="input-field" required>
                            <option value="" disabled <?= empty($tipo_combustivel_atual) ? 'selected' : '' ?>>Selecione o tipo de combustível</option>
                            <?php foreach ($opcoes_combustivel as $opcao): ?>
                                <option value="<?= htmlspecialchars($opcao) ?>" <?= ($tipo_combustivel_atual === $opcao) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($opcao) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label for="tanque" class="block text-sm font-medium text-gray-700 mb-1">Litragem do Tanque (L)</label>
                        <input type="number" id="tanque" name="tanque" placeholder="Capacidade do tanque em litros"
                               class="input-field" value="<?= htmlspecialchars($tanque) ?>" required min="1">
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Secretaria</label>
                        <input type="text" class="input-field" value="<?= htmlspecialchars($secretaria_admin) ?>" readonly>
                        <input type="hidden" name="secretaria" value="<?= htmlspecialchars($secretaria_admin) ?>">
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

            <div class="card p-6">
                <h2 class="text-xl font-semibold text-gray-800 mb-4">Ações Rápidas</h2>
                
                <div class="space-y-4">
                    <form id="formVeiculosEmUso" method="post" action="tabela_usados.php">
                        <input type="hidden" name="secretaria" value="<?= htmlspecialchars($secretaria_admin) ?>">
                        <button type="submit" class="w-full py-3 btn-secondary rounded-lg flex items-center justify-center gap-2">
                            <i class="fas fa-car"></i> Ver Veículos em Uso
                        </button>
                    </form>
                    
                    <button onclick="window.location.href='painel_usuarios.php'" class="w-full py-3 bg-yellow-500 hover:bg-yellow-600 text-white rounded-lg flex items-center justify-center gap-2">
                        <i class="fas fa-user-check"></i> Ativar Usuários
                    </button>

                    <button onclick="window.location.href='mudar_km_carro.php'" class="w-full py-3 bg-blue-600 hover:bg-blue-700 text-white rounded-lg flex items-center justify-center gap-2">
                        <i class="fas fa-user-check"></i> Alterar KM Veículo
                    </button>

                    <button onclick="gerarPDF()" class="w-full py-3 bg-purple-600 hover:bg-purple-700 text-white rounded-lg flex items-center justify-center gap-2">
                        <i class="fas fa-file-pdf"></i> Gerar PDF da Tabela
                    </button>
                    
                    <button onclick="imprimirTabela()" class="w-full py-3 bg-gray-600 hover:bg-gray-700 text-white rounded-lg flex items-center justify-center gap-2">
                        <i class="fas fa-print"></i> Imprimir Lista
                    </button>
                </div>
                
                <div class="mt-6 pt-6 border-t border-gray-200">
                    <h3 class="text-lg font-medium text-gray-800 mb-3">Secretaria Atual</h3>
                    <div class="bg-blue-50 p-4 rounded-lg">
                        <p class="text-blue-800 font-medium"><?= htmlspecialchars($secretaria_admin) ?></p>
                        <p class="text-sm text-gray-600 mt-1">Você está gerenciando os veículos desta secretaria.</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card p-6 mt-6" id="printable-area">
            <h3 class="text-xl font-semibold text-gray-800 text-center no-print">
                Veículos da Secretaria: <span class="text-indigo-600"><?= htmlspecialchars($secretaria_admin) ?></span>
            </h3>
            
            <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-6 no-print">
                <div class="mb-4 md:mb-0">
                    <div class="relative">
                        <input type="text" id="search-veiculo" placeholder="Buscar veículo..." 
                               class="input-field pl-10" style="max-width: 300px;">
                        <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                    </div>
                </div>
                <div class="flex items-center space-x-2">
                    <span class="text-sm text-gray-500">
                        Exibindo <?= count($veiculos) ?> veículos
                    </span>
                    <a href="?" class="p-2 rounded-full hover:bg-gray-100">
                        <i class="fas fa-sync-alt text-gray-500"></i>
                    </a>
                </div>
            </div>

            <div class="table-container">
                <table class="min-w-full">
                    <thead>
                        <tr>
                            <th class="text-left">Veículo</th>
                            <th class="text-left">Placa</th>
                            <th class="text-left">Marca</th>
                            <th class="text-left">Combustível</th> <th class="text-left">Tanque (L)</th>
                            <th class="text-left">Status</th>
                            <th class="text-right no-print">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($veiculos as $veiculo_item): ?>
                        <tr>
                            <td class="py-3">
                                <div class="font-medium"><?= htmlspecialchars($veiculo_item['veiculo']) ?></div>
                                <div class="text-sm text-gray-500">Mat: <?= htmlspecialchars($veiculo_item['matricula']) ?></div>
                            </td>
                            <td><?= htmlspecialchars($veiculo_item['placa']) ?></td>
                            <td><?= htmlspecialchars($veiculo_item['marca']) ?></td>
                            <td><?= htmlspecialchars($veiculo_item['tipo']) ?></td> <td><?= htmlspecialchars($veiculo_item['tanque']) ?></td>
                            <td>
                                <span class="status-badge <?= ($veiculo_item['status'] ?? 'livre') === 'em uso' ? 'status-em-uso' : 'status-livre' ?>">
                                    <?= ($veiculo_item['status'] ?? 'livre') === 'em uso' ? 'EM USO' : 'DISPONÍVEL' ?>
                                </span>
                            </td>
                            <td class="text-right no-print">
                                <div class="flex justify-end space-x-2">
                                    <a href="?editar_veiculo=<?= $veiculo_item['id'] ?>"
                                       class="action-btn bg-blue-100 text-blue-600 hover:bg-blue-200">
                                        <i class="fas fa-edit"></i> Editar
                                    </a>
                                    <a href="?delete_veiculo=<?= $veiculo_item['id'] ?>"
                                       class="action-btn bg-red-100 text-red-600 hover:bg-red-200"
                                       onclick="return confirm('Tem certeza que deseja excluir este veículo?');">
                                        <i class="fas fa-trash"></i> Excluir
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if (empty($veiculos)): ?>
            <div class="text-center py-8">
                <i class="fas fa-car text-4xl text-gray-300 mb-4"></i>
                <h4 class="text-lg font-medium text-gray-700">Nenhum veículo cadastrado</h4>
                <p class="text-gray-500 mt-2">Adicione veículos usando o formulário acima.</p>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    // Função para gerar PDF da tabela atual
    function gerarPDF() {
        // Cria um formulário temporário
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'gerar_pdf_tabela.php';
        form.target = '_blank';
        
        // Adiciona a secretaria como parâmetro
        const secretariaInput = document.createElement('input');
        secretariaInput.type = 'hidden';
        secretariaInput.name = 'secretaria';
        secretariaInput.value = '<?= htmlspecialchars($secretaria_admin) ?>';
        form.appendChild(secretariaInput);
        
        // Adiciona os dados da tabela
        const tabelaInput = document.createElement('input');
        tabelaInput.type = 'hidden';
        tabelaInput.name = 'tabela_html';
        
        // Clone the printable area and modify for PDF
        const printableArea = document.getElementById('printable-area').cloneNode(true);
        const thElements = printableArea.querySelectorAll('th');
        // Example: Change "Combustível" header for PDF if needed, or ensure it's correct
        // For this case, it's already "Combustível" which is fine.

        tabelaInput.value = printableArea.innerHTML;
        form.appendChild(tabelaInput);
        
        // Adiciona o formulário ao corpo e submete
        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    }

    // Função para imprimir apenas a tabela (corrigida)
    function imprimirTabela() {
        // Clone a área imprimível
        const printContents = document.getElementById('printable-area').cloneNode(true);
        
        // Remove elementos que não devem ser impressos
        const noPrintElements = printContents.querySelectorAll('.no-print');
        noPrintElements.forEach(el => el.remove());
        
        // Cria uma janela temporária para impressão
        const printWindow = window.open('', '_blank');
        printWindow.document.open();
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>Relatório de Veículos - <?= htmlspecialchars($secretaria_admin) ?></title>
                <style>
                    body { font-family: Arial, sans-serif; margin: 20px; }
                    h1 { text-align: center; margin-bottom: 20px; }
                    table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                    th { background-color: #f2f2f2; }
                    .status-badge { padding: 4px 8px; border-radius: 9999px; font-size: 12px; font-weight: 600; }
                    .status-em-uso { background-color: #FEE2E2; color: #DC2626; }
                    .status-livre { background-color: #DCFCE7; color: #16A34A; }
                </style>
            </head>
            <body>
                <h1>Relatório de Veículos - <?= htmlspecialchars($secretaria_admin) ?></h1>
                ${printContents.innerHTML}
                <div style="text-align: center; margin-top: 20px; font-size: 12px;">
                    Impresso em ${new Date().toLocaleString()}
                </div>
            </body>
            </html>
        `);
        printWindow.document.close();
        
        // Espera o conteúdo carregar antes de imprimir
        printWindow.onload = function() {
            printWindow.print();
        };
    }

    // Validação da placa antes de enviar o formulário
    document.querySelector('form[method="POST"]').addEventListener('submit', function(e) {
        const placaInput = document.getElementById('placa');
        if (placaInput) { // Ensure placa input exists, as there's another form on the page
            const placa = placaInput.value;
            const placaRegex = /^[A-Z]{3}-\d([A-Z]\d{2}|\d{3})$/;
            
            if (!placaRegex.test(placa)) {
                e.preventDefault();
                alert('Formato de placa inválido! Use o formato ABC-1234 ou ABC-1D23.');
            }
        }
        // Validate fuel type selection
        const tipoCombustivelSelect = document.getElementById('tipo_combustivel');
        if (tipoCombustivelSelect && tipoCombustivelSelect.value === "") {
             e.preventDefault();
             alert('Por favor, selecione o tipo de combustível.');
        }
    });

    // Search functionality
    document.getElementById('search-veiculo').addEventListener('input', function(e) {
        const termo = e.target.value.trim().toLowerCase(); // Ensure case-insensitive search
        const tbody = document.querySelector('table tbody');
        
        if (termo.length >= 2) {
            fetch(`buscar_veiculos.php?termo=${encodeURIComponent(termo)}&secretaria=${encodeURIComponent('<?= htmlspecialchars($secretaria_admin_db) ?>')}`)
                .then(response => response.json())
                .then(data => {
                    tbody.innerHTML = '';
                    
                    if (data.length === 0) {
                        tbody.innerHTML = `
                            <tr>
                                <td colspan="7" class="text-center py-4 text-gray-500">
                                    Nenhum veículo encontrado para "${termo}"
                                </td>
                            </tr>
                        `;
                        return;
                    }
                    
                    data.forEach(veiculo => {
                        const row = document.createElement('tr');
                        // Ensure veiculo.tipo (fuel type) is displayed correctly in search results
                        row.innerHTML = `
                            <td class="py-3">
                                <div class="font-medium">${veiculo.veiculo || 'N/A'}</div>
                                <div class="text-sm text-gray-500">Mat: ${veiculo.matricula || 'N/A'}</div>
                            </td>
                            <td>${veiculo.placa || 'N/A'}</td>
                            <td>${veiculo.marca || 'N/A'}</td>
                            <td>${veiculo.tipo || 'N/A'}</td> <td>${veiculo.tanque || 'N/A'}</td>
                            <td>
                                <span class="status-badge ${(veiculo.status || 'livre') === 'em uso' ? 'status-em-uso' : 'status-livre'}">
                                    ${(veiculo.status || 'livre') === 'em uso' ? 'EM USO' : 'DISPONÍVEL'}
                                </span>
                            </td>
                            <td class="text-right no-print">
                                <div class="flex justify-end space-x-2">
                                    <a href="?editar_veiculo=${veiculo.id}"
                                       class="action-btn bg-blue-100 text-blue-600 hover:bg-blue-200">
                                        <i class="fas fa-edit"></i> Editar
                                    </a>
                                    <a href="?delete_veiculo=${veiculo.id}"
                                       class="action-btn bg-red-100 text-red-600 hover:bg-red-200"
                                       onclick="return confirm('Tem certeza que deseja excluir este veículo?');">
                                        <i class="fas fa-trash"></i> Excluir
                                    </a>
                                </div>
                            </td>
                        `;
                        tbody.appendChild(row);
                    });
                })
                .catch(error => {
                    console.error('Erro na busca:', error);
                     tbody.innerHTML = `
                        <tr>
                            <td colspan="7" class="text-center py-4 text-gray-500">
                                Erro ao buscar veículos. Tente novamente.
                            </td>
                        </tr>
                    `;
                });
        } else if (termo.length === 0) {
            // Reload the page to show all vehicles if search is cleared
            window.location.href = window.location.href.split('?')[0];
        }
    });
    </script>
</body>
</html>