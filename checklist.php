<?php
session_start();
include 'conexao.php';

if (!isset($_SESSION['user_name'])) {
    header("Location: index.html");
    exit();
}

$user_name = $_SESSION['user_name'];
$codigo_veiculo = isset($_GET['codigo']) ? intval($_GET['codigo']) : (isset($_SESSION['codigo']) ? $_SESSION['codigo'] : null);
$veiculo = "Veículo não encontrado";

if ($codigo_veiculo) {
    $_SESSION['codigo'] = $codigo_veiculo;

    // Busca dados completos do veículo
    $query = "SELECT veiculo AS prefixo, tipo, placa, status FROM veiculos WHERE id = :codigo";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':codigo', $codigo_veiculo, PDO::PARAM_INT);
    $stmt->execute();
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($resultado) {
        $veiculo = htmlspecialchars($resultado['prefixo']);
        $tipo_veiculo = htmlspecialchars($resultado['tipo']);
        $placa_veiculo = htmlspecialchars($resultado['placa']);
        $status_veiculo = $resultado['status'];

        // Verifica se o veículo está em uso
        if ($status_veiculo != 'ativo') {
            // Busca o último usuário do veículo e seu número de telefone
            $query_usuario = "SELECT r.nome, u.number
                              FROM registros r
                              LEFT JOIN usuarios u ON r.nome = u.name
                              WHERE r.veiculo_id = :nome_veiculo
                              ORDER BY r.data DESC, r.hora DESC
                              LIMIT 1";
            $stmt_usuario = $conn->prepare($query_usuario);
            $stmt_usuario->bindValue(':nome_veiculo', $veiculo, PDO::PARAM_STR);
            $stmt_usuario->execute();
            $ultimo_registro = $stmt_usuario->fetch(PDO::FETCH_ASSOC);

            $nome_ultimo_usuario = $ultimo_registro ? $ultimo_registro['nome'] : 'Nenhum registro encontrado';
            $telefone_ultimo_usuario = $ultimo_registro && !empty($ultimo_registro['number']) ? $ultimo_registro['number'] : null;

            // Exibe a tela de veículo em uso
            ?>
            <!DOCTYPE html>
            <html lang="pt">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
                <title>Veículo em Uso</title>
                <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
                <script src="https://cdn.tailwindcss.com"></script>
                <script>
                    tailwind.config = {
                        theme: {
                            extend: {
                                colors: {
                                    'primary': '#4F46E5',
                                    'primary-dark': '#4338CA',
                                    'secondary': '#F59E0B',
                                    'accent': '#10B981',
                                    'success': '#10B981',
                                    'warning': '#F59E0B',
                                    'danger': '#EF4444',
                                },
                                boxShadow: {
                                    'soft': '0 4px 24px -6px rgba(0, 0, 0, 0.1)',
                                    'hard': '0 8px 24px -6px rgba(79, 70, 229, 0.3)'
                                }
                            }
                        }
                    }
                </script>
                <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
                <style>
                    * {
                        -webkit-tap-highlight-color: transparent;
                    }
                    body {
                        font-family: 'Inter', system-ui, -apple-system, sans-serif;
                        background-color: #f8fafc;
                    }
                    .app-container {
                        max-width: 480px;
                        height: 100dvh;
                        margin: 0 auto;
                        background: white;
                        position: relative;
                        overflow: hidden;

                    }
                    .input-field {
                        transition: all 0.2s ease;
                        position: relative;
                    }
                    .input-field:focus-within {
                        border-color: #4F46E5;
                        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
                    }
                    .btn-primary {
                        background-color: #4F46E5;
                        transition: all 0.2s ease;
                    }
                    .btn-primary:hover {
                        background-color: #4338CA;
                        transform: translateY(-1px);
                        box-shadow: 0 6px 12px rgba(79, 70, 229, 0.25);
                    }
                    .logo-container {
                        background: linear-gradient(135deg, #4F46E5 0%, #7C3AED 100%);
                    }
                    .forms-container {
                        height: calc(100dvh - 12rem);
                        overflow-y: auto;
                        -webkit-overflow-scrolling: touch;
                    }
                    .forms-container::-webkit-scrollbar {
                        display: none;
                    }
                    .nav-button {
                        width: 2.5rem;
                        height: 2.5rem;
                        border-radius: 50%;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
                        transition: all 0.2s ease;
                    }
                    .nav-button:hover {
                        transform: scale(1.05);
                    }
                    .hidden {
                        display: none;
                    }
                    .input-icon {
                        position: absolute;
                        left: 0.75rem;
                        top: 50%;
                        transform: translateY(-50%);
                        font-size: 1rem;
                        width: 1.25rem;
                        text-align: center;
                    }
                    .message-container {
                        position: fixed;
                        top: 20px;
                        left: 50%;
                        transform: translateX(-50%);
                        width: 90%;
                        max-width: 600px;
                        text-align: center;
                        background: #fff;
                        padding: 12px 20px;
                        border-radius: 8px;
                        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.1);
                        font-size: 16px;
                        font-weight: 500;
                        opacity: 1;
                        transition: opacity 0.5s ease-in-out;
                        z-index: 9999;
                    }
                    .success {
                        border-left: 5px solid #10B981;
                        color: #10B981;
                    }
                    .error {
                        border-left: 5px solid #EF4444;
                        color: #EF4444;
                    }
                    .vehicle-card {
                        background: white;
                        border-radius: 0.75rem;
                        padding: 1rem;
                        margin: 1rem 0;
                        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
                        border-left: 4px solid #EF4444;
                    }

                    .vehicle-card p {
                        display: flex;
                        align-items: center;
                        font-size: 0.875rem;
                        margin: 0.4rem 0;
                        gap: 0.5rem; /* controla o espaço entre ícone, label e valor */
                    }

                    .vehicle-card i {
                        color: #4F46E5;
                        width: 1.2rem;
                        text-align: center;
                        flex-shrink: 0;
                    }

                    .vehicle-card strong {
                        font-weight: 600;
                        white-space: nowrap;
                        flex-shrink: 0;
                    }

                    .usuario-atual {
                        transition: all 0.3s ease;
                        font-weight: 500;
                    }
                    .error-message {
                        color: #6B7280;
                        margin: 1rem 0;
                        line-height: 1.5;
                    }
                    .button-container {
                        display: flex;
                        flex-direction: column;
                        gap: 0.75rem;
                        margin-top: 1.5rem;
                    }
                    .btn {
                        display: inline-flex;
                        align-items: center;
                        justify-content: center;
                        padding: 0.75rem 1.5rem;
                        border-radius: 0.75rem;
                        font-weight: 600;
                        transition: all 0.2s ease;
                        text-decoration: none;
                    }
                    .btn i {
                        margin-right: 0.5rem;
                    }
                    .btn-back {
                        background: linear-gradient(135deg, #10B981, #059669);
                        color: white;
                    }
                    .btn-checklist {
                        background: linear-gradient(135deg, #4F46E5, #4338CA);
                        color: white;
                    }
                    .btn:hover {
                        transform: translateY(-2px);
                        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                    }
                    @keyframes pulse {
                        0% { transform: scale(1); }
                        50% { transform: scale(1.05); }
                        100% { transform: scale(1); }
                    }
                    .pulse {
                        animation: pulse 1.5s infinite;
                    }
                    .fa-whatsapp {
                        color: #25D366;
                        margin-left: 0.25rem;
                    }
                </style>
            </head>
            <body>
                <div class="app-container relative">
                    <!-- Logo Header -->
                    <div class="logo-container h-48 rounded-b-3xl flex flex-col items-center justify-center shadow-hard relative">
                        <div class="nav-button bg-white absolute top-6 left-6" onclick="window.location.href = 'carros.php'">
                            <i class="fas fa-chevron-left text-primary"></i>
                        </div>
                        <div class="nav-button bg-white absolute top-6 right-6" onclick="redirectHome()">
                            <i class="fas fa-home text-primary"></i>
                        </div>
                        <div class="bg-white/20 p-4 rounded-full mb-4">
                            <i class="fas fa-exclamation-triangle text-white text-4xl pulse"></i>
                        </div>
                        <h1 class="text-white text-2xl font-bold">Veículo em Uso</h1>
                    </div>

                    <!-- Forms Container -->
                    <div class="forms-container px-5 pb-6 -mt-8 relative">
                        <div class="bg-white rounded-2xl p-6 shadow-hard">
                            <div class="vehicle-card">
                                <p><i class="fas fa-car text-primary"></i><strong>Veículo:</strong> <?php echo htmlspecialchars($veiculo); ?></p>
                                <p><i class="fas fa-id-card text-primary"></i><strong>Placa:</strong> <?php echo htmlspecialchars($placa_veiculo); ?></p>
                                <p><i class="fas fa-tag text-primary"></i><strong>Tipo:</strong> <?php echo htmlspecialchars($tipo_veiculo); ?></p>
                                <p><i class="fas fa-info-circle text-primary"></i><strong>Status:</strong> <span class="font-bold text-danger">Em uso</span></p>
                                <p><i class="fas fa-user text-primary"></i><strong>Último usuário:</strong>
                                    <span id="ultimo-usuario" class="usuario-atual font-bold text-danger"><?php echo htmlspecialchars($nome_ultimo_usuario); ?></span></p>
                            </div>

                            <p class="error-message text-center">
                                O veículo que você está tentando usar está em uso no momento pelo motorista:
                                <span id="ultimo-usuario-text" class="font-bold text-danger"><?php echo htmlspecialchars($nome_ultimo_usuario); ?></span>.
                                <?php if ($telefone_ultimo_usuario): ?>
                                    <a href="https://wa.me/55<?php echo htmlspecialchars(preg_replace('/[^0-9]/', '', $telefone_ultimo_usuario)); ?>"
                                       target="_blank"
                                       class="text-primary hover:underline ml-1">
                                        <i class="fab fa-whatsapp"></i> Entre em contato
                                    </a>
                                <?php else: ?>
                                    Entre em contato com ele para que o veículo seja liberado.
                                <?php endif; ?>
                            </p>

                            <div class="button-container">
                                <a href="<?php
                                    if (!isset($_SESSION['role'])) {
                                        echo 'login.php';
                                    } elseif ($_SESSION['role'] === 'user') {
                                        echo 'menu.php';
                                    } elseif ($_SESSION['role'] === 'admin') {
                                        echo 'menuadm.php';
                                    } elseif ($_SESSION['role'] === 'geraladm') {
                                        echo 'menugeraladm.php';
                                    } else {
                                        echo 'login.php';
                                    }
                                ?>" class="btn btn-back">
                                    <i class="fas fa-arrow-left"></i> Voltar ao Menu
                                </a>
                                <a href="carros.php" class="btn btn-checklist">
                                    <i class="fas fa-clipboard-check"></i> Selecionar outro veículo
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                    function exibirUltimoUsuario() {
                        const elemento = document.getElementById('ultimo-usuario');
                        if (!elemento) {
                            console.error('Elemento #ultimo-usuario não encontrado!');
                            return;
                        }

                        elemento.innerHTML = '<i class="fas fa-spinner fa-pulse"></i> Carregando...';

                        fetch(`buscar_ultimo_usuario.php?nome_veiculo=${encodeURIComponent('<?php echo $veiculo; ?>')}`)
                        .then(response => {
                            if (!response.ok) throw new Error("Erro na requisição");
                            return response.json();
                        })
                        .then(data => {
                            const elemento = document.getElementById('ultimo-usuario');
                            const textElement = document.getElementById('ultimo-usuario-text');
                            if (data.success) {
                                const usuario = data.ultimo_usuario || 'Nenhum registro';
                                elemento.textContent = usuario;
                                if (textElement) textElement.textContent = usuario;
                            } else {
                                elemento.textContent = data.message || 'Erro desconhecido';
                                if (textElement) textElement.textContent = data.message || 'Erro desconhecido';
                            }
                        })
                        .catch(error => {
                            console.error("Erro:", error);
                            document.getElementById('ultimo-usuario').textContent = "Erro ao carregar";
                            const textElement = document.getElementById('ultimo-usuario-text');
                            if (textElement) textElement.textContent = "Erro ao carregar";
                        });
                    }

                    function redirectHome() {
                        let role = '<?php echo $_SESSION['role']; ?>';
                        if (role === 'user') {
                            window.location.href = 'menu.php';
                        } else if (role === 'admin') {
                            window.location.href = 'menuadm.php';
                        } else if (role === 'geraladm') {
                            window.location.href = 'menugeraladm.php';
                        }
                    }

                    document.addEventListener('DOMContentLoaded', () => {
                        exibirUltimoUsuario();
                        setInterval(exibirUltimoUsuario, 15000);
                    });
                </script>
            </body>
            </html>
            <?php
            exit();
        }

        // Query para obter o último checklist registrado para o veículo
        $checklist_query = "
            SELECT * FROM checklist
            WHERE prefixo = :prefixo
            ORDER BY created_at DESC
            LIMIT 1
        ";
        $checklist_stmt = $conn->prepare($checklist_query);
        $checklist_stmt->bindParam(':prefixo', $veiculo, PDO::PARAM_STR);
        $checklist_stmt->execute();
        $ultimo_checklist = $checklist_stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>

<!-- Mantenha o restante do HTML original do checklist.php -->
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Checklist do Veículo</title>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'primary': '#4F46E5',
                        'primary-dark': '#4338CA',
                        'secondary': '#F59E0B',
                        'accent': '#10B981',
                        'success': '#10B981',
                        'warning': '#F59E0B',
                        'danger': '#EF4444',
                        'amber-600': '#D97706'
                    },
                    boxShadow: {
                        'soft': '0 4px 24px -6px rgba(0, 0, 0, 0.1)',
                        'hard': '0 8px 24px -6px rgba(79, 70, 229, 0.3)'
                    }
                }
            }
        }
    </script>

    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        * {
            -webkit-tap-highlight-color: transparent;
        }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: #f8fafc;
        }
        .app-container {
            max-width: 480px;
            height: 100dvh;
            margin: 0 auto;
            background: white;
            position: relative;
            overflow: hidden;
        }
        .input-field {
            transition: all 0.2s ease;
            position: relative;
        }
        .input-field:focus-within {
            border-color: #4F46E5;
            box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.1);
        }
        .btn-primary {
            background-color: #4F46E5;
            transition: all 0.2s ease;
        }
        .btn-primary:hover {
            background-color: #4338CA;
            transform: translateY(-1px);
            box-shadow: 0 6px 12px rgba(79, 70, 229, 0.25);
        }
        .logo-container {
            background: linear-gradient(135deg, #4F46E5 0%, #7C3AED 100%);
            margin-bottom: 10px;
        }
        .forms-container {
            height: calc(100dvh - 12rem);
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        .forms-container::-webkit-scrollbar {
            display: none;
        }
        .nav-button {
            width: 2.5rem;
            height: 2.5rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
            transition: all 0.2s ease;
        }
        .nav-button:hover {
            transform: scale(1.05);
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        @media (max-width: 400px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
        .hidden {
            display: none;
        }
        .select-option-0 { background-color: rgba(16, 185, 129, 0.1); }
        .select-option-1 { background-color: rgba(245, 158, 11, 0.1); }
        .select-option-2 { background-color: rgba(239, 68, 68, 0.1); }
        .observation-field {
            margin-top: 0.5rem;
            border-radius: 0.75rem;
            padding: 0.75rem;
            border: 1px solid #e5e7eb;
            background-color: #f9fafb;
            width: 100%;
            font-size: 0.875rem;
        }
        .input-icon {
            position: absolute;
            left: 0.75rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1rem;
            width: 1.25rem;
            text-align: center;
        }
        select {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
        }
        .checklist-section select {
            padding-left: 1.5rem !important;
        }
    </style>
</head>
<body>
    <div class="app-container relative">
        <!-- Logo Header -->
        <div class="logo-container h-48 rounded-b-3xl flex flex-col items-center justify-center shadow-hard relative">
            <div class="nav-button bg-white absolute top-6 left-6" onclick="window.location.href = 'carros.php'">
                <i class="fas fa-chevron-left text-primary"></i>
            </div>
            <div class="nav-button bg-white absolute top-6 right-6" onclick="redirectHome()">
                <i class="fas fa-home text-primary"></i>
            </div>
            <div class="bg-white/20 p-4 rounded-full mb-4">
                <i class="fas fa-clipboard-check text-white text-4xl"></i>
            </div>
            <h1 class="text-white text-2xl font-bold">Checklist do Veículo</h1>

        </div>

        <!-- Forms Container -->
        <div class="forms-container px-5 pb-6 -mt-10 relative">
            <div class="bg-white rounded-2xl p-6 shadow-hard">
                <form action="salvar_checklist.php" method="POST">
                    <!-- User Info -->
                    <div class="mb-5">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nome</label>
                        <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200 pl-11" onclick="this.querySelector('input').focus();">
                            <div class="input-icon text-success">
                                <i class="fas fa-user-check"></i>
                            </div>
                            <input
                                type="text"
                                name="nome"
                                class="w-full bg-transparent focus:outline-none"
                                value="<?php echo $user_name; ?>"
                                readonly
                            >
                        </div>
                    </div>

                    <!-- Vehicle Info -->
                    <div class="mb-5">
                        <label class="block text-sm font-medium text-gray-700 mb-1">Veículo</label>
                        <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200 pl-11" onclick="this.querySelector('input').focus();">
                            <div class="input-icon text-primary">
                                <i class="fas fa-car-side"></i>
                            </div>
                            <input
                                type="text"
                                name="prefixo"
                                class="w-full bg-transparent focus:outline-none"
                                value="<?php echo $veiculo; ?>"
                                readonly
                            >
                        </div>
                    </div>

                    <!-- Checklist Items -->
                    <div class="checklist-section">
                        <h3 class="text-lg font-semibold text-gray-800 mb-3">Itens de Verificação</h3>

                        <div class="form-grid">
                            <!-- Combustível -->
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Combustível</label>
                                <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                    <div class="input-icon text-warning">
                                        <i class="fas fa-gas-pump"></i>
                                    </div>
                                    <select name="combustivel" class="w-full bg-transparent focus:outline-none" onchange="updateSelectStyle(this); showObservation(this);">
                                        <option value="Tanque Cheio" <?php if (isset($ultimo_checklist['combustivel']) && $ultimo_checklist['combustivel'] == 'Tanque Cheio') echo 'selected'; ?>>Cheio</option>
                                        <option value="Meio Tanque" <?php if (isset($ultimo_checklist['combustivel']) && $ultimo_checklist['combustivel'] == 'Meio Tanque') echo 'selected'; ?>>Meio</option>
                                        <option value="Tanque Baixo" <?php if (isset($ultimo_checklist['combustivel']) && $ultimo_checklist['combustivel'] == 'Tanque Baixo') echo 'selected'; ?>>Baixo</option>
                                    </select>
                                    <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                        <i class="fas fa-chevron-down text-xs"></i>
                                    </div>
                                </div>
                                <textarea name="obs_combustivel" class="observation-field <?php if (!isset($ultimo_checklist['obs_combustivel']) || empty($ultimo_checklist['obs_combustivel'])) echo 'hidden'; ?>" placeholder="Descreva o problema"><?php echo isset($ultimo_checklist['obs_combustivel']) ? htmlspecialchars($ultimo_checklist['obs_combustivel']) : ''; ?></textarea>
                            </div>

                            <!-- Água -->
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Água</label>
                                <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                    <div class="input-icon text-blue-500">
                                        <i class="fas fa-tint"></i>
                                    </div>
                                    <select name="agua" class="w-full bg-transparent focus:outline-none" onchange="updateSelectStyle(this); showObservation(this);">
                                        <option value="Nível Recomendado" <?php if (isset($ultimo_checklist['agua']) && $ultimo_checklist['agua'] == 'Nível Recomendado') echo 'selected'; ?>>Ideal</option>
                                        <option value="Acima do Nível" <?php if (isset($ultimo_checklist['agua']) && $ultimo_checklist['agua'] == 'Acima do Nível') echo 'selected'; ?>>Acima</option>
                                        <option value="Abaixo do Nível" <?php if (isset($ultimo_checklist['agua']) && $ultimo_checklist['agua'] == 'Abaixo do Nível') echo 'selected'; ?>>Abaixo</option>
                                    </select>
                                    <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                        <i class="fas fa-chevron-down text-xs"></i>
                                    </div>
                                </div>
                                <textarea name="obs_agua" class="observation-field <?php if (!isset($ultimo_checklist['obs_agua']) || empty($ultimo_checklist['obs_agua'])) echo 'hidden'; ?>" placeholder="Descreva o problema"><?php echo isset($ultimo_checklist['obs_agua']) ? htmlspecialchars($ultimo_checklist['obs_agua']) : ''; ?></textarea>
                            </div>

                            <!-- Óleo -->
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Óleo</label>
                                <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                    <div class="input-icon text-amber-700">
                                        <i class="fas fa-oil-can"></i>
                                    </div>
                                    <select name="oleo" class="w-full bg-transparent focus:outline-none" onchange="updateSelectStyle(this); showObservation(this);">
                                        <option value="Nível Recomendado" <?php if (isset($ultimo_checklist['oleo']) && $ultimo_checklist['oleo'] == 'Nível Recomendado') echo 'selected'; ?>>Ideal</option>
                                        <option value="Acima do Nível" <?php if (isset($ultimo_checklist['oleo']) && $ultimo_checklist['oleo'] == 'Acima do Nível') echo 'selected'; ?>>Acima</option>
                                        <option value="Abaixo do Nível" <?php if (isset($ultimo_checklist['oleo']) && $ultimo_checklist['oleo'] == 'Abaixo do Nível') echo 'selected'; ?>>Abaixo</option>
                                    </select>
                                    <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                        <i class="fas fa-chevron-down text-xs"></i>
                                    </div>
                                </div>
                                <textarea name="obs_oleo" class="observation-field <?php if (!isset($ultimo_checklist['obs_oleo']) || empty($ultimo_checklist['obs_oleo'])) echo 'hidden'; ?>" placeholder="Descreva o problema"><?php echo isset($ultimo_checklist['obs_oleo']) ? htmlspecialchars($ultimo_checklist['obs_oleo']) : ''; ?></textarea>
                            </div>

                            <!-- Bateria -->
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Bateria</label>
                                <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                    <div class="input-icon text-danger">
                                        <i class="fas fa-battery-three-quarters"></i>
                                    </div>
                                    <select name="bateria" class="w-full bg-transparent focus:outline-none" onchange="updateSelectStyle(this); showObservation(this);">
                                        <option value="Ok" <?php if (isset($ultimo_checklist['bateria']) && $ultimo_checklist['bateria'] == 'Ok') echo 'selected'; ?>>OK</option>
                                        <option value="Trocar" <?php if (isset($ultimo_checklist['bateria']) && $ultimo_checklist['bateria'] == 'Trocar') echo 'selected'; ?>>Trocar</option>
                                        <option value="Necessita de Carga" <?php if (isset($ultimo_checklist['bateria']) && $ultimo_checklist['bateria'] == 'Necessita de Carga') echo 'selected'; ?>>Carga</option>
                                    </select>
                                    <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                        <i class="fas fa-chevron-down text-xs"></i>
                                    </div>
                                </div>
                                <textarea name="obs_bateria" class="observation-field <?php if (!isset($ultimo_checklist['obs_bateria']) || empty($ultimo_checklist['obs_bateria'])) echo 'hidden'; ?>" placeholder="Descreva o problema"><?php echo isset($ultimo_checklist['obs_bateria']) ? htmlspecialchars($ultimo_checklist['obs_bateria']) : ''; ?></textarea>
                            </div>

                            <!-- Pneus -->
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Pneus</label>
                                <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                    <div class="input-icon text-amber-600">
                                    <i class="fa-solid fa-car"></i>
                                    </div>
                                    <select name="pneus" class="w-full bg-transparent focus:outline-none" onchange="updateSelectStyle(this); showObservation(this);">
                                        <option value="Bons" <?php if (isset($ultimo_checklist['pneus']) && $ultimo_checklist['pneus'] == 'Bons') echo 'selected'; ?>>Bons</option>
                                        <option value="Aceitáveis" <?php if (isset($ultimo_checklist['pneus']) && $ultimo_checklist['pneus'] == 'Aceitáveis') echo 'selected'; ?>>Aceitáveis</option>
                                        <option value="Necessita de Troca" <?php if (isset($ultimo_checklist['pneus']) && $ultimo_checklist['pneus'] == 'Necessita de Troca') echo 'selected'; ?>>Trocar</option>
                                    </select>
                                    <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                        <i class="fas fa-chevron-down text-xs"></i>
                                    </div>
                                </div>
                                <textarea name="obs_pneus" class="observation-field <?php if (!isset($ultimo_checklist['obs_pneus']) || empty($ultimo_checklist['obs_pneus'])) echo 'hidden'; ?>" placeholder="Descreva o problema"><?php echo isset($ultimo_checklist['obs_pneus']) ? htmlspecialchars($ultimo_checklist['obs_pneus']) : ''; ?></textarea>
                            </div>

                            <!-- Filtro de Ar -->
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Filtro de Ar</label>
                                <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                    <div class="input-icon text-blue-400">
                                        <i class="fas fa-wind"></i>
                                    </div>
                                    <select name="filtro_ar" class="w-full bg-transparent focus:outline-none" onchange="updateSelectStyle(this); showObservation(this);">
                                        <option value="Bons" <?php if (isset($ultimo_checklist['filtro_ar']) && $ultimo_checklist['filtro_ar'] == 'Bons') echo 'selected'; ?>>Bons</option>
                                        <option value="Necessitam de Limpeza" <?php if (isset($ultimo_checklist['filtro_ar']) && $ultimo_checklist['filtro_ar'] == 'Necessitam de Limpeza') echo 'selected'; ?>>Limpeza</option>
                                        <option value="Necessitam de Troca" <?php if (isset($ultimo_checklist['filtro_ar']) && $ultimo_checklist['filtro_ar'] == 'Necessitam de Troca') echo 'selected'; ?>>Troca</option>
                                    </select>
                                    <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                        <i class="fas fa-chevron-down text-xs"></i>
                                    </div>
                                </div>
                                <textarea name="obs_filtro_ar" class="observation-field <?php if (!isset($ultimo_checklist['obs_filtro_ar']) || empty($ultimo_checklist['obs_filtro_ar'])) echo 'hidden'; ?>" placeholder="Descreva o problema"><?php echo isset($ultimo_checklist['obs_filtro_ar']) ? htmlspecialchars($ultimo_checklist['obs_filtro_ar']) : ''; ?></textarea>
                            </div>

                            <!-- Lâmpadas -->
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Lâmpadas</label>
                                <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                    <div class="input-icon text-yellow-500">
                                        <i class="fas fa-lightbulb"></i>
                                    </div>
                                    <select name="lampadas" class="w-full bg-transparent focus:outline-none" onchange="updateSelectStyle(this); showObservation(this);">
                                        <option value="Boas" <?php if (isset($ultimo_checklist['lampadas']) && $ultimo_checklist['lampadas'] == 'Boas') echo 'selected'; ?>>Boas</option>
                                        <option value="1 Ruim" <?php if (isset($ultimo_checklist['lampadas']) && $ultimo_checklist['lampadas'] == '1 Ruim') echo 'selected'; ?>>1 Ruim</option>
                                        <option value="2 ou mais ruins" <?php if (isset($ultimo_checklist['lampadas']) && $ultimo_checklist['lampadas'] == '2 ou mais ruins') echo 'selected'; ?>>2 Ruins</option>
                                    </select>
                                    <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                        <i class="fas fa-chevron-down text-xs"></i>
                                    </div>
                                </div>
                                <textarea name="obs_lampadas" class="observation-field <?php if (!isset($ultimo_checklist['obs_lampadas']) || empty($ultimo_checklist['obs_lampadas'])) echo 'hidden'; ?>" placeholder="Descreva o problema"><?php echo isset($ultimo_checklist['obs_lampadas']) ? htmlspecialchars($ultimo_checklist['obs_lampadas']) : ''; ?></textarea>
                            </div>

                            <!-- Sistema Elétrico -->
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Sistema Elétrico</label>
                                <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                    <div class="input-icon text-purple-500">
                                        <i class="fas fa-bolt"></i>
                                    </div>
                                    <select name="sistema_eletrico" class="w-full bg-transparent focus:outline-none" onchange="updateSelectStyle(this); showObservation(this);">
                                        <option value="Bom" <?php if (isset($ultimo_checklist['sistema_eletrico']) && $ultimo_checklist['sistema_eletrico'] == 'Bom') echo 'selected'; ?>>Bom</option>
                                        <option value="Necessita de Revisão" <?php if (isset($ultimo_checklist['sistema_eletrico']) && $ultimo_checklist['sistema_eletrico'] == 'Necessita de Revisão') echo 'selected'; ?>>Revisão</option>
                                        <option value="Necessita de Troca" <?php if (isset($ultimo_checklist['sistema_eletrico']) && $ultimo_checklist['sistema_eletrico'] == 'Necessita de Troca') echo 'selected'; ?>>Troca</option>
                                    </select>
                                    <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400">
                                        <i class="fas fa-chevron-down text-xs"></i>
                                    </div>
                                </div>
                                <textarea name="obs_sistema_eletrico" class="observation-field <?php if (!isset($ultimo_checklist['obs_sistema_eletrico']) || empty($ultimo_checklist['obs_sistema_eletrico'])) echo 'hidden'; ?>" placeholder="Descreva o problema"><?php echo isset($ultimo_checklist['obs_sistema_eletrico']) ? htmlspecialchars($ultimo_checklist['obs_sistema_eletrico']) : ''; ?></textarea>
                            </div>
                        </div>
                    </div>

                    <!-- Submit Button -->
                    <button
                        type="submit"
                        class="btn-primary w-full py-3 rounded-xl text-white font-bold shadow-md hover:shadow-lg transition-all mt-2"
                    >
                        <i class="fas fa-pen-fancy mr-2"></i> Assinar Checklist
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script>
        function updateSelectStyle(selectElement) {
            // Remove todas as classes de estilo
            selectElement.classList.remove('select-option-0', 'select-option-1', 'select-option-2');

            // Adiciona a classe apropriada baseada no índice selecionado
            selectElement.classList.add(`select-option-${selectElement.selectedIndex}`);

            // Atualiza a cor do ícone baseado na seleção
            const icon = selectElement.parentElement.querySelector('.input-icon i');
            if (selectElement.selectedIndex === 0) {
                icon.classList.add('text-success');
                icon.classList.remove('text-warning', 'text-danger');
            } else if (selectElement.selectedIndex === 1) {
                icon.classList.add('text-warning');
                icon.classList.remove('text-success', 'text-danger');
            } else if (selectElement.selectedIndex === 2) {
                icon.classList.add('text-danger');
                icon.classList.remove('text-success', 'text-warning');
            }
        }

        function showObservation(select) {
            const observationField = select.closest('.mb-4').querySelector('textarea');

            if (select.selectedIndex === 2) {
                observationField.classList.remove('hidden');
                observationField.required = true;
            } else {
                observationField.classList.add('hidden');
                observationField.value = '';
                observationField.required = false;
            }
        }

        function redirectHome() {
            let role = '<?php echo $_SESSION['role']; ?>';
            if (role === 'user') {
                window.location.href = 'menu.php';
            } else if (role === 'admin') {
                window.location.href = 'menuadm.php';
            } else if (role === 'geraladm') {
                window.location.href = 'menugeraladm.php';
            }
        }

        // Aplica estilos iniciais quando a página carrega
        document.addEventListener("DOMContentLoaded", function() {
            const selects = document.querySelectorAll('select');
            selects.forEach(select => {
                updateSelectStyle(select);
                showObservation(select);
            });

            // Adiciona event listener para todos os elementos com a classe 'input-field'
            const inputFields = document.querySelectorAll('.input-field');
            inputFields.forEach(function(container) {
                // Verifica se existe um elemento input interno
                const input = container.querySelector('input');
                if (input) {
                    container.addEventListener('click', function() {
                        input.focus();
                    });
                }
            });
        });
    </script>
</body>
</html>
