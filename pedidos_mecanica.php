<?php
session_start();
include 'conexao.php'; 

if (!isset($_SESSION['user_name'])) {
    header("Location: index.php");
    exit();
}

$user_name = $_SESSION['user_name'];
$codigo_veiculo_param = isset($_GET['codigo']) ? intval($_GET['codigo']) : (isset($_SESSION['codigo_veiculo_sessao']) ? $_SESSION['codigo_veiculo_sessao'] : null);
$veiculo_display_nome = "Veículo não encontrado"; 

// Capture user role and determine form status
$user_role = isset($_SESSION['role']) ? $_SESSION['role'] : 'user';
$form_status = ($user_role === 'admin' || $user_role === 'geraladm') ? 'aceito' : 'pendente';

$km_inicial = 0;
$secretaria = '';
$veiculo_nome_identificador = ''; 
$placa = '';
$tipo_veiculo = '';
$status_veiculo = '';
$nome_ultimo_usuario = 'Nenhum registro encontrado';

// Buscar dados do usuário incluindo codigo_veiculo (ID do veículo) e secretaria
$user_query_db = "SELECT id, codigo_veiculo, secretaria FROM usuarios WHERE name = :name LIMIT 1";
$user_stmt_db = $conn->prepare($user_query_db);
$user_stmt_db->bindParam(':name', $user_name, PDO::PARAM_STR);
$user_stmt_db->execute();
$user_data = $user_stmt_db->fetch(PDO::FETCH_ASSOC);

$user_id_sess = null;
if ($user_data) {
    $user_id_sess = $user_data['id']; // ID do usuário logado
    $_SESSION['user_id'] = $user_id_sess; // Garante que user_id está na sessão para salvar_ficha.php
    $secretaria = $user_data['secretaria'];
    // Se o código do veículo não veio por GET, usa o associado ao usuário
    if ($codigo_veiculo_param === null && isset($user_data['codigo_veiculo'])) {
        $codigo_veiculo_param = $user_data['codigo_veiculo'];
    }
}

// Se temos um código de veículo (ID numérico do veículo na tabela veiculos)
if ($codigo_veiculo_param) {
    $_SESSION['codigo_veiculo_sessao'] = $codigo_veiculo_param; // Salva na sessão para persistência

    // Busca dados do veículo usando o ID (codigo_veiculo_param)
    $query_veiculo_db = "SELECT veiculo, tipo, placa, status FROM veiculos WHERE id = :codigo_id";
    $stmt_veiculo_db = $conn->prepare($query_veiculo_db);
    $stmt_veiculo_db->bindParam(':codigo_id', $codigo_veiculo_param, PDO::PARAM_INT);
    $stmt_veiculo_db->execute();
    $veiculo_info = $stmt_veiculo_db->fetch(PDO::FETCH_ASSOC);

    if ($veiculo_info) {
        $veiculo_nome_identificador = $veiculo_info['veiculo'];
        $tipo_veiculo   = $veiculo_info['tipo'];
        $placa          = $veiculo_info['placa'];
        $status_veiculo = $veiculo_info['status'];
        $veiculo_display_nome = htmlspecialchars($veiculo_nome_identificador);


        // Busca o último KM final para este veículo
        try {
            $sql_km = "SELECT km_final FROM registros WHERE veiculo_id = :veiculo_nome_id ORDER BY data DESC, hora DESC, id DESC LIMIT 1";
            $stmt_km = $conn->prepare($sql_km);
            $stmt_km->bindParam(':veiculo_nome_id', $veiculo_nome_identificador, PDO::PARAM_STR);
            $stmt_km->execute();
            $linha_km = $stmt_km->fetch(PDO::FETCH_ASSOC);
            $km_inicial = $linha_km ? $linha_km['km_final'] : 0;
        } catch (PDOException $e) {
            $_SESSION['erro_km'] = 'Erro ao buscar último Km/h: ' . $e->getMessage();
        }

        // Busca o último usuário do veículo
        $query_ultimo_usuario_db = "SELECT nome FROM registros
                                    WHERE veiculo_id = :veiculo_nome_id
                                    ORDER BY data DESC, hora DESC, id DESC
                                    LIMIT 1";
        $stmt_ultimo_usuario_db = $conn->prepare($query_ultimo_usuario_db);
        $stmt_ultimo_usuario_db->bindValue(':veiculo_nome_id', $veiculo_nome_identificador, PDO::PARAM_STR);
        $stmt_ultimo_usuario_db->execute();
        $ultimo_registro = $stmt_ultimo_usuario_db->fetch(PDO::FETCH_ASSOC);
        $nome_ultimo_usuario = $ultimo_registro ? $ultimo_registro['nome'] : 'Nenhum registro encontrado';

        // Query para obter o último checklist registrado para o veículo
        $checklist_query_db = "
            SELECT * FROM checklist
            WHERE prefixo = :prefixo_veiculo
            ORDER BY created_at DESC
            LIMIT 1
        ";
        $checklist_stmt_db = $conn->prepare($checklist_query_db);
        $checklist_stmt_db->bindParam(':prefixo_veiculo', $veiculo_nome_identificador, PDO::PARAM_STR);
        $checklist_stmt_db->execute();
        $ultimo_checklist = $checklist_stmt_db->fetch(PDO::FETCH_ASSOC);

    } else {
        $_SESSION['erro_veiculo'] = 'Erro: Veículo com ID ' . $codigo_veiculo_param . ' não encontrado.';
    }
} else {
     $_SESSION['info_veiculo'] = 'Nenhum veículo selecionado ou associado ao usuário.';
}

?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ficha de Comunicação de Defeitos</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        'sans': ['Poppins', 'system-ui', 'sans-serif'],
                    },
                    colors: {
                        'primary': '#3B82F6',
                        'primary-dark': '#2563EB',
                        'secondary': '#F97316',
                        'accent': '#10B981',
                        'success': '#10B981',
                        'warning': '#F59E0B',
                        'danger': '#EF4444',
                        'gray-50': '#F9FAFB',
                        'gray-100': '#F3F4F6', 
                        'gray-200': '#E5E7EB',
                        'gray-300': '#D1D5DB',
                        'gray-800': '#1F2937',
                        'amber-600': '#D97706'
                    },
                    boxShadow: {
                        'soft': '0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06)',
                        'hard': '0 10px 15px -3px rgba(59, 130, 246, 0.3), 0 4px 6px -2px rgba(59, 130, 246, 0.1)'
                    },
                    animation: {
                        'pulse-slow': 'pulse 3s cubic-bezier(0.4, 0, 0.6, 1) infinite',
                    },
                }
            }
        }
    </script>
    <style>
        * {
            -webkit-tap-highlight-color: transparent;
            scroll-behavior: smooth;
        }
        html, body {
            height: 100%;
            margin: 0;
            padding: 0;
            font-family: 'Poppins', sans-serif;
            background-color: #F1F5F9;
            color: #1F2937;
        }
        .app-container {
            width: 100%;
            max-width: 1024px; 
            min-height: 100vh;
            margin: 0 auto;
            background: #F1F5F9;
            position: relative;
        }
        @media (max-width: 1024px) {
            .app-container {
                max-width: 100%;
                box-shadow: none;
            }
        }
        
        .content-body {
            background: white;
            padding-bottom: 2rem;
        }
        @media (min-width: 768px) {
            .content-body {
                border-radius: 1.5rem;
                margin: -2.5rem 1.5rem 2rem 1.5rem;
                box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
            }
        }

        .input-field {
            transition: all 0.3s ease;
            position: relative;
        }
        .input-field:focus-within {
            border-color: #3B82F6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
            transform: translateY(-1px);
        }
        .btn-primary {
            background-color: #3B82F6;
            transition: all 0.3s ease;
        }
        .btn-primary:hover {
            background-color: #2563EB;
            transform: translateY(-1px);
            box-shadow: 0 6px 12px rgba(59, 130, 246, 0.25);
        }
        .btn-primary:active {
            transform: translateY(0);
        }
        /* ALTERAÇÃO: Removido o border-radius do header para ocupar a tela toda */
        .header-gradient {
            background: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%);
            position: relative;
            overflow: hidden;
            padding: 0 1rem; /* Adiciona padding lateral para o conteúdo não colar nas bordas em telas pequenas */
        }
        .header-gradient:before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 60%);
            animation: rotate 15s linear infinite;
        }
        @keyframes rotate {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .forms-container {
            overflow-y: auto;
            -webkit-overflow-scrolling: touch;
        }
        .forms-container::-webkit-scrollbar {
            width: 8px;
        }
        .forms-container::-webkit-scrollbar-track {
            background: #F9FAFB;
        }
        .forms-container::-webkit-scrollbar-thumb {
            background-color: #D1D5DB;
            border-radius: 20px;
        }
        .forms-container::-webkit-scrollbar-thumb:hover {
            background-color: #9CA3AF;
        }
        
        .nav-button {
            width: 2.75rem;
            height: 2.75rem;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
            transition: all 0.3s ease;
            backdrop-filter: blur(5px);
            background-color: rgba(255, 255, 255, 0.9);
        }
        .nav-button:hover {
            transform: scale(1.05) translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
        }
        .nav-button:active {
            transform: scale(0.98);
        }
        
        .select-option-0 { 
            background-color: rgba(16, 185, 129, 0.05) !important; 
            border-color: rgba(16, 185, 129, 0.2) !important;
        }
        .select-option-selected { 
            background-color: rgba(239, 68, 68, 0.05) !important; 
            border-color: rgba(239, 68, 68, 0.3) !important;
        }
        .observation-field {
            margin-top: 0.5rem;
            border-radius: 0.75rem;
            padding: 0.75rem 1rem;
            border: 1px solid #e5e7eb;
            background-color: #f9fafb;
            width: 100%;
            font-size: 0.875rem;
            transition: all 0.3s ease;
            max-height: 100px;
            min-height: 60px;
        }
        .observation-field:focus {
            border-color: #3B82F6;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.15);
            outline: none;
        }
        .input-icon {
            position: absolute;
            left: 0.875rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1rem;
            width: 1.25rem;
            text-align: center;
            color: #6B7280;
            transition: all 0.3s ease;
        }
        .input-field:focus-within .input-icon,
        .input-field.select-option-0 .input-icon i {
            color: #10B981;
        }
        .input-field.select-option-selected .input-icon i {
             color: #EF4444;
        }
        select {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            padding-right: 2rem !important;
            padding-left: 2.5rem !important;
            cursor: pointer;
            width: 100%;
            background: transparent;
            border: none;
            outline: none;
        }
        .select-chevron {
            pointer-events: none;
            transition: all 0.3s ease;
        }
        select:focus + .select-chevron {
            color: #3B82F6;
            transform: translateY(-50%) rotate(180deg);
        }
        .input-uniform-width {
            width: 100%;
        }
        
        .form-section {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            margin-bottom: 1.5rem;
            padding: 1.25rem;
            transition: all 0.3s ease;
            opacity: 0;
            transform: translateY(10px);
            animation: fadeInUp 0.5s forwards;
        }
        .form-section-title {
            font-weight: 600;
            font-size: 1.125rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            color: #1F2937;
        }
        .form-section-icon {
            margin-right: 0.75rem;
            color: #3B82F6;
            font-size: 1.25rem;
        }
        
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 20px;
            animation: fadeIn 0.3s ease;
        }
        .modal-container {
            background-color: white;
            border-radius: 16px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            max-width: 500px;
            width: 100%;
            animation: slideUp 0.4s ease-out;
            overflow: hidden;
        }
        .modal-header {
            background: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%);
            color: white;
            padding: 1.5rem;
            text-align: center;
        }
        .modal-body {
            padding: 2rem;
            text-align: center;
        }
        .modal-footer {
            display: flex;
            justify-content: center;
            padding: 1.5rem;
            background-color: #f9fafb;
            gap: 1rem;
        }
        .modal-icon {
            font-size: 3.5rem;
            margin-bottom: 1.5rem;
        }
        .modal-icon.success { color: #10B981; }
        .modal-icon.error { color: #EF4444; }
        .modal-icon.warning { color: #F59E0B; }
        
        .modal-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
        }
        .modal-message {
            font-size: 1rem;
            color: #4B5563;
            line-height: 1.5;
        }
        .modal-button {
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.3s ease;
            cursor: pointer;
            min-width: 140px;
        }
        .modal-button-primary {
            background-color: #3B82F6;
            color: white;
        }
        .modal-button-primary:hover {
            background-color: #2563EB;
            transform: translateY(-2px);
        }
        .modal-button-secondary {
            background-color: white;
            color: #3B82F6;
            border: 1px solid #E5E7EB;
        }
        .modal-button-secondary:hover {
            background-color: #F3F4F6;
        }
        
        @keyframes fadeInUp {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        @keyframes fadeIn {
            to { opacity: 1; }
        }
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .form-section:nth-child(1) { animation-delay: 0.1s; }
        .form-section:nth-child(2) { animation-delay: 0.2s; }
        .form-section:nth-child(3) { animation-delay: 0.3s; }
        .form-section:nth-child(4) { animation-delay: 0.4s; }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 0.25rem 0.75rem;
            border-radius: 9999px;
            font-size: 0.75rem;
            font-weight: 500;
        }
        .status-badge-success {
            background-color: rgba(16, 185, 129, 0.1);
            color: #10B981;
        }
        .status-badge-warning {
            background-color: rgba(245, 158, 11, 0.1);
            color: #F59E0B;
        }
        .status-badge-danger {
            background-color: rgba(239, 68, 68, 0.1);
            color: #EF4444;
        }
        
        .search-container {
            position: relative;
        }
        .search-input {
            width: 100%;
            padding: 0.75rem 1rem 0.75rem 2.5rem;
            border-radius: 0.75rem;
            border: 1px solid #E5E7EB;
        }
        .search-input:focus {
            outline: none;
            border-color: #3B82F6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.15);
        }
        .search-icon {
            position: absolute;
            left: 0.875rem;
            top: 50%; 
            transform: translateY(-50%);
            color: #9CA3AF;
        }
        
        .progress-container {
            width: 100%;
            height: 6px;
            background-color: rgba(255, 255, 255, 0.2);
            border-radius: 3px;
            overflow: hidden;
        }
        .progress-bar {
            height: 100%;
            background: white;
            border-radius: 3px;
            transition: width 0.5s ease;
        }
        
        .header-content {
            padding-top: 2.5rem;
            padding-bottom: 5rem;
            text-align: center;
        }
        .header-icon {
            margin-bottom: 0.75rem;
        }
        .header-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: white;
        }
        .header-subtitle {
            font-size: 0.875rem;
            color: rgba(255, 255, 255, 0.75);
        }
        
        @media (max-width: 480px) {
            .modal-footer {
                flex-direction: column;
            }
            .modal-button {
                width: 100%;
            }
        }

        .notification-toast {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 1rem 1.5rem;
            border-radius: 8px;
            color: white;
            font-size: 0.9rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
            z-index: 2000;
            display: flex;
            align-items: center;
            opacity: 0;
            transform: translateY(-20px);
            transition: opacity 0.3s ease, transform 0.3s ease;
        }
        .notification-toast.show {
            opacity: 1;
            transform: translateY(0);
        }
        .notification-toast.success { background-color: #10B981; }
        .notification-toast.error { background-color: #EF4444; }
        .notification-toast.info { background-color: #3B82F6; }
        .notification-toast i { margin-right: 0.75rem; font-size: 1.2rem; }
    </style>
</head>
<body>

    <div class="header-gradient">
        <div class="mx-auto relative" style="max-width: 1024px;">
            <div class="nav-button absolute top-6 left-0" onclick="window.location.href = 'carros_ficha.php'">
                <i class="fas fa-chevron-left text-primary"></i>
            </div>
            <div class="nav-button absolute top-6 right-0" onclick="redirectHome()">
                <i class="fas fa-home text-primary"></i>
            </div>
            
            <div class="header-content">
                <div class="header-icon bg-white/30 p-4 rounded-full mx-auto w-16 h-16 flex items-center justify-center backdrop-blur-sm shadow-md">
                    <i class="fas fa-clipboard-check text-white text-2xl"></i>
                </div>
                <h1 class="header-title">Ficha de Comunicação de Defeitos</h1>
                <p class="header-subtitle">Relate problemas encontrados no veículo: <?php echo $veiculo_display_nome; ?></p>
            </div>
            
            <div class="absolute bottom-4 left-0 w-full px-6">
                <div class="progress-container max-w-md mx-auto">
                    <div id="progress-bar" class="progress-bar" style="width: 0%"></div>
                </div>
            </div>
        </div>
    </div>


    <div class="app-container">
        <?php
        if (isset($_SESSION['mensagem'])) {
            echo '<div id="session-message-toast" class="notification-toast success show"><i class="fas fa-check-circle"></i> ' . htmlspecialchars($_SESSION['mensagem']) . '</div>';
            unset($_SESSION['mensagem']);
        }
        if (isset($_SESSION['erro'])) {
            echo '<div id="session-message-toast" class="notification-toast error show"><i class="fas fa-times-circle"></i> ' . htmlspecialchars($_SESSION['erro']) . '</div>';
            unset($_SESSION['erro']);
        }
        if (isset($_SESSION['erro_km'])) {
            echo '<div id="session-message-toast-km" class="notification-toast error show"><i class="fas fa-exclamation-triangle"></i> ' . htmlspecialchars($_SESSION['erro_km']) . '</div>';
            unset($_SESSION['erro_km']);
        }
        if (isset($_SESSION['erro_veiculo'])) {
            echo '<div id="session-message-toast-veiculo" class="notification-toast error show"><i class="fas fa-exclamation-triangle"></i> ' . htmlspecialchars($_SESSION['erro_veiculo']) . '</div>';
            unset($_SESSION['erro_veiculo']);
        }
         if (isset($_SESSION['info_veiculo'])) {
            echo '<div id="session-message-toast-info" class="notification-toast info show"><i class="fas fa-info-circle"></i> ' . htmlspecialchars($_SESSION['info_veiculo']) . '</div>';
            unset($_SESSION['info_veiculo']);
        }
        ?>

        <div class="content-body">
            <div class="forms-container px-4 md:px-6 pt-6">
                <form action="salvar_ficha.php" method="POST" id="checklistForm">
                    <input type="hidden" name="status" value="<?php echo $form_status; ?>">
                    
                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-user-circle form-section-icon"></i>
                            <span>Informações do Condutor</span>
                        </div>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nome</label>
                                <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                    <div class="input-icon"><i class="fas fa-user"></i></div>
                                    <input type="text" name="nome" class="input-uniform-width bg-transparent focus:outline-none pl-6" value="<?php echo htmlspecialchars($user_name); ?>" readonly>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Secretaria</label>
                                <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                    <div class="input-icon"><i class="fas fa-building"></i></div>
                                    <input type="text" name="secretaria" class="input-uniform-width bg-transparent focus:outline-none pl-6" value="<?php echo htmlspecialchars($secretaria); ?>" readonly>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Data</label>
                                <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                    <div class="input-icon"><i class="fas fa-calendar-day"></i></div>
                                    <input type="date" name="data" id="data" class="input-uniform-width bg-transparent focus:outline-none pl-6" value="<?php echo date('Y-m-d'); ?>" readonly>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Hora</label>
                                <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                    <div class="input-icon"><i class="fas fa-clock"></i></div>
                                    <input type="time" id="idhora" name="hora" class="input-uniform-width bg-transparent focus:outline-none pl-6" required readonly>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-car form-section-icon"></i>
                            <span>Informações do Veículo</span>
                        </div>
                        
                         <?php if (!empty($veiculo_nome_identificador)): ?>
                        <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
                            <div class="flex items-center">
                                <div class="bg-gray-100 rounded-full p-2 mr-3 flex-shrink-0">
                                    <i class="fas fa-car text-primary"></i>
                                </div>
                                <div>
                                    <h3 class="font-semibold"><?php echo htmlspecialchars($tipo_veiculo ?? 'Não definido'); ?></h3>
                                    <p class="text-xs text-gray-500"><?php echo htmlspecialchars($placa ?? 'Placa não disponível'); ?></p>
                                </div>
                            </div>
                            <?php if (isset($status_veiculo) && $status_veiculo !== ''): ?>
                                <?php
                                $status_class = 'status-badge-success';
                                $status_text_display = htmlspecialchars($status_veiculo);
                                if ($status_veiculo === 'Em manutenção') { $status_class = 'status-badge-warning'; } 
                                elseif ($status_veiculo === 'Inoperante') { $status_class = 'status-badge-danger'; }
                                ?>
                                <span class="status-badge <?php echo $status_class; ?>">
                                    <i class="fas fa-circle text-xs mr-2"></i>
                                    <?php echo $status_text_display; ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Último Km/h Registrado</label>
                                <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                    <div class="input-icon"><i class="fas fa-tachometer-alt"></i></div>
                                    <input type="text" id="kminicial" name="km_inicial" class="input-uniform-width bg-transparent focus:outline-none pl-6" value="<?php echo number_format($km_inicial, 0, ',', '.'); ?>" readonly >
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Identificação do Veículo</label>
                                <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                   <div class="input-icon"><i class="fas fa-id-card"></i></div>
                                    <input type="text" id="codigo" name="veiculo_id" class="input-uniform-width bg-transparent focus:outline-none pl-6" value="<?php echo htmlspecialchars($veiculo_nome_identificador ?? ''); ?>" readonly >
                                    <input type="hidden" name="placa" value="<?php echo htmlspecialchars($placa ?? ''); ?>">
                                    <input type="hidden" name="nome_veiculo" value="<?php echo htmlspecialchars($tipo_veiculo ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        <?php else: ?>
                            <p class="text-center text-gray-600 py-4">Nenhum veículo carregado. Por favor, selecione um veículo na <a href="carros.php" class="text-primary hover:underline">página de veículos</a>.</p>
                        <?php endif; ?>
                    </div>


                    <?php if (!empty($veiculo_nome_identificador)): ?>
                    <div class="form-section">
                        <div class="form-section-title">
                            <i class="fas fa-clipboard-list form-section-icon"></i>
                            <span>Comunicação de Defeitos</span>
                        </div>
                        
                        <div class="search-container mb-5">
                            <i class="fas fa-search search-icon"></i>
                            <input type="text" id="defect-search" class="search-input bg-gray-50" placeholder="Buscar um tipo de defeito...">
                        </div>

                        <div class="defect-categories space-y-8">
                            
                            <div class="category-section border-t pt-4">
                                <h3 class="text-md font-semibold text-gray-700 mb-4 flex items-center">
                                    <i class="fas fa-tools mr-2 text-primary"></i>
                                    Sistemas Mecânicos
                                </h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4 defect-group">
                                    <div class="defect-item">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Motor</label>
                                        <div class="relative">
                                            <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                                <div class="input-icon"><i class="fas fa-cog"></i></div>
                                                <select name="motor" class="input-uniform-width bg-transparent focus:outline-none" onchange="handleSelectChange(this);" data-category="motor">
                                                    <option value="">Sem defeitos</option>
                                                    <option value="Roncando/Alto/gando">Roncando/Alto/gando</option>
                                                    <option value="Falhando/Otando/Brita do pegar">Falhando/Otando/Difícil de pegar</option>
                                                    <option value="Super aquecimento/Motor">Superaquecimento</option>
                                                    <option value="Turbo vazando">Turbo vazando</option>
                                                    <option value="Marcha lenta irregular">Marcha lenta irregular</option>
                                                    <option value="Consumo excessivo combustível">Consumo excessivo combustível</option>
                                                    <option value="Freio motor não acionado">Freio motor não acionado</option>
                                                    <option value="Vazando óleo/Vazando água">Vazando óleo/Vazando água</option>
                                                    <option value="Top break falhando">Top break falhando</option>
                                                    <option value="Consumindo óleo">Consumindo óleo</option>
                                                    <option value="Outro">Outro defeito</option>
                                                </select>
                                                <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 select-chevron"><i class="fas fa-chevron-down text-xs"></i></div>
                                            </div>
                                            <textarea name="obs_motor" class="observation-field hidden" placeholder="Descreva o problema em detalhes"></textarea>
                                        </div>
                                    </div>
                                    <div class="defect-item">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Caixa de Mudanças</label>
                                        <div class="relative">
                                            <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                                <div class="input-icon"><i class="fas fa-cogs"></i></div>
                                                <select name="caixa_mudancas" class="input-uniform-width bg-transparent focus:outline-none" onchange="handleSelectChange(this);" data-category="caixa">
                                                    <option value="">Sem defeitos</option>
                                                    <option value="Difícil de engatar marchas">Difícil de engatar marchas</option>
                                                    <option value="Escapando marcha">Escapando marcha</option>
                                                    <option value="Ruído na marcha/lavador/marcha">Ruído na marcha</option>
                                                    <option value="Barulho no câmbio">Barulho no câmbio</option>
                                                    <option value="Outro">Outro defeito</option>
                                                </select>
                                                <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 select-chevron"><i class="fas fa-chevron-down text-xs"></i></div>
                                            </div>
                                            <textarea name="obs_caixa_mudancas" class="observation-field hidden" placeholder="Descreva o problema em detalhes"></textarea>
                                        </div>
                                    </div>
                                    <div class="defect-item">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Suspensão</label>
                                        <div class="relative">
                                            <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                                <div class="input-icon"><i class="fas fa-car-crash"></i></div>
                                                <select name="suspensao" class="input-uniform-width bg-transparent focus:outline-none" onchange="handleSelectChange(this);" data-category="suspensao">
                                                    <option value="">Sem defeitos</option>
                                                    <option value="Muito baixando/batendo">Muito baixa/batendo</option>
                                                    <option value="Mola quebrada">Mola quebrada</option>
                                                    <option value="Dura/Muito macia">Dura/Muito macia</option>
                                                    <option value="Tensores com folga">Tensores com folga</option>
                                                    <option value="Outro">Outro defeito</option>
                                                </select>
                                                <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 select-chevron"><i class="fas fa-chevron-down text-xs"></i></div>
                                            </div>
                                            <textarea name="obs_suspensao" class="observation-field hidden" placeholder="Descreva o problema em detalhes"></textarea>
                                        </div>
                                    </div>
                                    <div class="defect-item">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Direção</label>
                                        <div class="relative">
                                            <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                                <div class="input-icon"><i class="fas fa-car-side"></i></div>
                                                <select name="direcao" class="input-uniform-width bg-transparent focus:outline-none" onchange="handleSelectChange(this);" data-category="direcao">
                                                    <option value="">Sem defeitos</option>
                                                    <option value="Desalinhada">Desalinhada</option>
                                                    <option value="Com folga">Com folga</option>
                                                    <option value="Trepidando">Trepidando</option>
                                                    <option value="Dura">Dura</option>
                                                    <option value="Puxando para/lado">Puxando para um lado</option>
                                                    <option value="Nível de óleo baixo">Nível de óleo baixo</option>
                                                    <option value="Outro">Outro defeito</option>
                                                </select>
                                                <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 select-chevron"><i class="fas fa-chevron-down text-xs"></i></div>
                                            </div>
                                            <textarea name="obs_direcao" class="observation-field hidden" placeholder="Descreva o problema em detalhes"></textarea>
                                        </div>
                                    </div>
                                    <div class="defect-item">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Embreagem</label>
                                        <div class="relative">
                                            <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                                <div class="input-icon"><i class="fas fa-sliders-h"></i></div>
                                                <select name="embreagem" class="input-uniform-width bg-transparent focus:outline-none" onchange="handleSelectChange(this);" data-category="embreagem">
                                                    <option value="">Sem defeitos</option>
                                                    <option value="Embregagem patinando">Embreagem patinando</option>
                                                    <option value="Emb. trepidando">Embreagem trepidando</option>
                                                    <option value="Embregagem/alta">Embreagem alta</option>
                                                    <option value="Embregagem baixa">Embreagem baixa</option>
                                                    <option value="Cabo embr. quebrado">Cabo de embreagem quebrado</option>
                                                    <option value="Vazando/óleo">Vazando óleo</option>
                                                    <option value="Outro">Outro defeito</option>
                                                </select>
                                                <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 select-chevron"><i class="fas fa-chevron-down text-xs"></i></div>
                                            </div>
                                            <textarea name="obs_embreagem" class="observation-field hidden" placeholder="Descreva o problema em detalhes"></textarea>
                                        </div>
                                    </div>
                                    <div class="defect-item">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Freios</label>
                                        <div class="relative">
                                            <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                                <div class="input-icon"><i class="fas fa-stop-circle"></i></div>
                                                <select name="freios" class="input-uniform-width bg-transparent focus:outline-none" onchange="handleSelectChange(this);" data-category="freios">
                                                    <option value="">Sem defeitos</option>
                                                    <option value="Chiando">Chiando</option>
                                                    <option value="Puxando">Puxando</option>
                                                    <option value="Com vazamento de ar">Com vazamento de ar</option>
                                                    <option value="Trepidando">Trepidando</option>
                                                    <option value="Precisando de ajuste">Precisando de ajuste</option>
                                                    <option value="Precisando de troca">Precisando de troca</option>
                                                    <option value="Pedal alto">Pedal alto</option>
                                                    <option value="Freio de mão não segura">Freio de mão não segura</option>
                                                    <option value="Freio de mão solto">Freio de mão solto</option>
                                                    <option value="Freio de mão duro">Freio de mão duro</option>
                                                    <option value="Outro">Outro defeito</option>
                                                </select>
                                                <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 select-chevron"><i class="fas fa-chevron-down text-xs"></i></div>
                                            </div>
                                            <textarea name="obs_freios" class="observation-field hidden" placeholder="Descreva o problema em detalhes"></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="category-section border-t pt-4">
                                <h3 class="text-md font-semibold text-gray-700 mb-4 flex items-center">
                                    <i class="fas fa-car-alt mr-2 text-primary"></i>
                                    Carroceria e Sistemas Externos
                                </h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4 defect-group">
                                    <div class="defect-item">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Carroceria</label>
                                        <div class="relative">
                                            <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                                <div class="input-icon"><i class="fas fa-car"></i></div>
                                                <select name="carroceria" class="input-uniform-width bg-transparent focus:outline-none" onchange="handleSelectChange(this);" data-category="carroceria">
                                                    <option value="">Sem defeitos</option>
                                                    <option value="Portas mal ajust./fazendo barulho">Portas mal ajustadas/barulho</option>
                                                    <option value="Portas difícil de abrir/fechar">Portas difíceis de abrir/fechar</option>
                                                    <option value="Vidros fazendo/barulho/empoeirado">Vidros com barulho/empoeirados</option>
                                                    <option value="Escapamento quebrado/furado">Escapamento quebrado/furado</option>
                                                    <option value="Escapamento solto">Escapamento solto</option>
                                                    <option value="Para-choque/Para-lama amassado">Para-choque/Para-lama amassado</option>
                                                    <option value="Porta/Capô amassado">Porta/Capô amassado</option>
                                                    <option value="Outro">Outro defeito</option>
                                                </select>
                                                <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 select-chevron"><i class="fas fa-chevron-down text-xs"></i></div>
                                            </div>
                                            <textarea name="obs_carroceria" class="observation-field hidden" placeholder="Descreva o problema em detalhes"></textarea>
                                        </div>
                                    </div>
                                    <div class="defect-item">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Rodas e Pneus</label>
                                        <div class="relative">
                                            <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                                <div class="input-icon"><i class="fas fa-circle"></i></div>
                                                <select name="rodas" class="input-uniform-width bg-transparent focus:outline-none" onchange="handleSelectChange(this);" data-category="rodas">
                                                    <option value="">Sem defeitos</option>
                                                    <option value="Batendo a normal">Batendo</option>
                                                    <option value="Parafuso normal">Parafuso desgastado</option>
                                                    <option value="Parafuso/Porca/faltando">Parafuso/Porca faltando</option>
                                                    <option value="Parafuso/Porca/soltos">Parafuso/Porca soltos</option>
                                                    <option value="Pneu cortado/furado">Pneu cortado/furado</option>
                                                    <option value="Outro">Outro defeito</option>
                                                </select>
                                                <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 select-chevron"><i class="fas fa-chevron-down text-xs"></i></div>
                                            </div>
                                            <textarea name="obs_rodas" class="observation-field hidden" placeholder="Descreva o problema em detalhes"></textarea>
                                        </div>
                                    </div>
                                    <div class="defect-item">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Sistema Elétrico</label>
                                        <div class="relative">
                                            <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                                <div class="input-icon"><i class="fas fa-bolt"></i></div>
                                                <select name="sistema_eletrico" class="input-uniform-width bg-transparent focus:outline-none" onchange="handleSelectChange(this);" data-category="eletrico">
                                                    <option value="">Sem defeitos</option>
                                                    <option value="Bateria fraca">Bateria fraca</option>
                                                    <option value="Vazando solução da bateria">Vazamento de solução da bateria</option>
                                                    <option value="Terminais cabos da bat. Danificados">Terminais/cabos danificados</option>
                                                    <option value="Motor de partida/Alternador não/carrega">Motor de partida/Alternador com defeito</option>
                                                    <option value="Fios de ligação danificados">Fios danificados</option>
                                                    <option value="Buzina fraca ou não funciona">Buzina fraca/não funciona</option>
                                                    <option value="Luzes do painel/com/defeito">Luzes do painel com defeito</option>
                                                    <option value="Luzes con/super/aqüecida/queimada">Luzes queimadas</option>
                                                    <option value="Velocímetro com defeito">Velocímetro com defeito</option>
                                                    <option value="Outro">Outro defeito</option>
                                                </select>
                                                <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 select-chevron"><i class="fas fa-chevron-down text-xs"></i></div>
                                            </div>
                                            <textarea name="obs_sistema_eletrico" class="observation-field hidden" placeholder="Descreva o problema em detalhes"></textarea>
                                        </div>
                                    </div>
                                    <div class="defect-item">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Acessórios</label>
                                        <div class="relative">
                                            <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                                <div class="input-icon"><i class="fas fa-puzzle-piece"></i></div>
                                                <select name="acessorios" class="input-uniform-width bg-transparent focus:outline-none" onchange="handleSelectChange(this);" data-category="acessorios">
                                                    <option value="">Sem defeitos</option>
                                                    <option value="Rádio não funciona">Rádio não funciona</option>
                                                    <option value="Antena danificada">Antena danificada</option>
                                                    <option value="Ar condicionado o/ defeito">Ar condicionado com defeito</option>
                                                    <option value="Cinto de segurança com/defeito">Cinto de segurança com defeito</option>
                                                    <option value="Extintor/descarregado/faltando">Extintor descarregado/faltando</option>
                                                    <option value="Macaco com defeito/faltando">Macaco com defeito/faltando</option>
                                                    <option value="Triângulo com defeito/faltando">Triângulo com defeito/faltando</option>
                                                    <option value="Chave de roda/danificada/faltando">Chave de roda danificada/faltando</option>
                                                    <option value="Documentos do veículo vencido">Documentos do veículo vencidos</option>
                                                    <option value="Outro">Outro defeito</option>
                                                </select>
                                                <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 select-chevron"><i class="fas fa-chevron-down text-xs"></i></div>
                                            </div>
                                            <textarea name="obs_acessorios" class="observation-field hidden" placeholder="Descreva o problema em detalhes"></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="category-section border-t pt-4">
                                <h3 class="text-md font-semibold text-gray-700 mb-4 flex items-center">
                                    <i class="fas fa-tint mr-2 text-primary"></i>
                                    Fluidos e Refrigeração
                                </h3>
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-6 gap-y-4 defect-group">
                                    <div class="defect-item">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Sistema de Combustível</label>
                                        <div class="relative">
                                            <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                                <div class="input-icon"><i class="fas fa-gas-pump"></i></div>
                                                <select name="alimentacao" class="input-uniform-width bg-transparent focus:outline-none" onchange="handleSelectChange(this);" data-category="alimentacao">
                                                    <option value="">Sem defeitos</option>
                                                    <option value="Reservatório de comb. vazando">Reservatório vazando</option>
                                                    <option value="Cabo do acelerador enroscando">Cabo do acelerador enroscando</option>
                                                    <option value="Bomba/Boia de comb. vazando">Bomba/Boia vazando</option>
                                                    <option value="Reserv. Combustível sem/tampa">Reservatório sem tampa</option>
                                                    <option value="Outro">Outro defeito</option>
                                                </select>
                                                <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 select-chevron"><i class="fas fa-chevron-down text-xs"></i></div>
                                            </div>
                                            <textarea name="obs_alimentacao" class="observation-field hidden" placeholder="Descreva o problema em detalhes"></textarea>
                                        </div>
                                    </div>
                                    <div class="defect-item">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Arrefecimento</label>
                                        <div class="relative">
                                            <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                                <div class="input-icon"><i class="fas fa-thermometer-half"></i></div>
                                                <select name="arrefecimento" class="input-uniform-width bg-transparent focus:outline-none" onchange="handleSelectChange(this);" data-category="arrefecimento">
                                                    <option value="">Sem defeitos</option>
                                                    <option value="Radiador/Com/elavazando">Radiador vazando</option>
                                                    <option value="Tampa do radiador não veda">Tampa do radiador não veda</option>
                                                    <option value="Ventoinha não funciona">Ventoinha não funciona</option>
                                                    <option value="Correias/chando/grilando/gastas">Correias desgastadas</option>
                                                    <option value="Juntas de água com defeito">Juntas de água com defeito</option>
                                                    <option value="Fluído de radiador faltando">Fluido do radiador baixo</option>
                                                    <option value="Outro">Outro defeito</option>
                                                </select>
                                                <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 select-chevron"><i class="fas fa-chevron-down text-xs"></i></div>
                                            </div>
                                            <textarea name="obs_arrefecimento" class="observation-field hidden" placeholder="Descreva o problema em detalhes"></textarea>
                                        </div>
                                    </div>
                                    <div class="defect-item">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Lavagem/Lubrificantes</label>
                                        <div class="relative">
                                            <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                                <div class="input-icon"><i class="fas fa-shower"></i></div>
                                                <select name="lavagem_lubrificantes" class="input-uniform-width bg-transparent focus:outline-none" onchange="handleSelectChange(this);" data-category="lavagem">
                                                    <option value="">Sem defeitos</option>
                                                    <option value="Lavagem completa">Precisa de lavagem completa</option>
                                                    <option value="Lavagem interna">Precisa de lavagem interna</option>
                                                    <option value="Completar óleo do diferencial">Completar óleo do diferencial</option>
                                                    <option value="Completar óleo do câmbio">Completar óleo do câmbio</option>
                                                    <option value="Trocar óleo do motor">Trocar óleo do motor</option>
                                                    <option value="Outro">Outro serviço</option>
                                                </select>
                                                <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 select-chevron"><i class="fas fa-chevron-down text-xs"></i></div>
                                            </div>
                                            <textarea name="obs_lavagem_lubrificantes" class="observation-field hidden" placeholder="Descreva o problema em detalhes"></textarea>
                                        </div>
                                    </div>
                                    <div class="defect-item">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Transmissão</label>
                                        <div class="relative">
                                            <div class="input-field bg-gray-50 rounded-xl p-3 border border-gray-200">
                                                <div class="input-icon"><i class="fas fa-cogs"></i></div>
                                                <select name="transmissao_9500" class="input-uniform-width bg-transparent focus:outline-none" onchange="handleSelectChange(this);" data-category="transmissao">
                                                    <option value="">Sem defeitos</option>
                                                    <option value="Motor barulho anormal">Barulho anormal</option>
                                                    <option value="Vibração excessiva">Vibração excessiva</option>
                                                    <option value="Vazando óleo">Vazando óleo</option>
                                                    <option value="Outro">Outro defeito</option>
                                                </select>
                                                <div class="absolute right-3 top-1/2 transform -translate-y-1/2 text-gray-400 select-chevron"><i class="fas fa-chevron-down text-xs"></i></div>
                                            </div>
                                            <textarea name="obs_transmissao_9500" class="observation-field hidden" placeholder="Descreva o problema em detalhes"></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button type="submit" id="submit-button" class="btn-primary w-full py-4 rounded-xl text-white font-bold shadow-md hover:shadow-lg transition-all mt-8 flex items-center justify-center gap-2">
                            <i class="fas fa-clipboard-check"></i> 
                            <span>Enviar Comunicação de Defeitos</span>
                        </button>
                    </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    </div>

    <div class="modal-overlay hidden" id="confirmationModal">
        <div class="modal-container">
            <div class="modal-header">
                <h2 class="text-xl font-bold">Confirmar Envio</h2>
            </div>
            <div class="modal-body">
                <div id="modal-dynamic-icon" class="modal-icon"> <i class="fas fa-question-circle fa-beat"></i> </div>
                <h2 class="modal-title">Deseja enviar a ficha?</h2>
                <p class="modal-message">
                    Você reportou <span id="defect-count" class="font-bold text-primary">0</span> problema(s) no veículo. 
                    Confirma o envio da Ficha de Comunicação de Defeitos?
                </p>
                <p class="text-sm mt-2">
                    <?php if ($user_role === 'admin' || $user_role === 'geraladm'): ?>
                    <span class="text-success font-medium"><i class="fas fa-check-circle mr-1"></i> Esta ficha será enviada com status "aceito".</span>
                    <?php else: ?>
                    <span class="text-warning font-medium"><i class="fas fa-clock mr-1"></i> Esta ficha será enviada com status "pendente" e aguardará aprovação.</span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="modal-footer">
                <button id="confirm-submit" class="modal-button modal-button-primary"><i class="fas fa-check mr-2"></i> Confirmar</button>
                <button id="cancel-submit" class="modal-button modal-button-secondary"><i class="fas fa-times mr-2"></i> Cancelar</button>
            </div>
        </div>
    </div>

    <script>
        function handleSelectChange(selectElement) {
            updateSelectStyle(selectElement);
            showObservation(selectElement);
            updateProgress();
        }

        document.getElementById('checklistForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const selects = document.querySelectorAll('.defect-item select');
            let defectCount = 0;
            
            selects.forEach(select => {
                if (select.value && select.value !== "") {
                    defectCount++;
                }
            });
            
            document.getElementById('defect-count').textContent = defectCount;
            
            const modalIconContainer = document.getElementById('modal-dynamic-icon');
            const modalTitle = document.querySelector('#confirmationModal .modal-title');
            const modalMessage = document.querySelector('#confirmationModal .modal-message');

            if (defectCount === 0) {
                modalIconContainer.innerHTML = '<i class="fas fa-thumbs-up fa-beat success"></i>';
                modalTitle.textContent = "Nenhum defeito reportado!";
                modalMessage.innerHTML = `O veículo será registrado como "Sem defeitos". <br>Confirma o envio?`;
            } else {
                modalIconContainer.innerHTML = '<i class="fas fa-question-circle fa-beat text-primary"></i>';
                modalTitle.textContent = "Deseja enviar a ficha?";
                modalMessage.innerHTML = `Você reportou <span class="font-bold text-primary">${defectCount}</span> problema(s) no veículo. Confirma o envio?`;
            }

            document.getElementById('confirmationModal').classList.remove('hidden');
        });
        
        document.getElementById('confirm-submit').addEventListener('click', function() {
            document.getElementById('confirmationModal').classList.add('hidden');
            document.getElementById('checklistForm').submit();
        });
        
        document.getElementById('cancel-submit').addEventListener('click', function() {
            document.getElementById('confirmationModal').classList.add('hidden');
        });

        function updateSelectStyle(selectElement) {
            const inputField = selectElement.closest('.input-field');
            inputField.classList.remove('select-option-0', 'select-option-selected');
            
            if (selectElement.selectedIndex === 0) {
                inputField.classList.add('select-option-0');
            } else {
                inputField.classList.add('select-option-selected');
            }
        }

        function showObservation(select) {
            const observationField = select.closest('.defect-item').querySelector('textarea');
            if (select.selectedIndex !== 0) {
                observationField.classList.remove('hidden');
            } else {
                observationField.classList.add('hidden');
                observationField.value = '';
            }
        }

        function updateProgress() {
            const selects = document.querySelectorAll('.defect-item select');
            let filled = 0;
            
            selects.forEach(select => {
                if (select.value !== "") {
                    filled++;
                }
            });
            
            const totalFields = selects.length;
            const percentage = totalFields > 0 ? ((filled / totalFields) * 100) : 0;
            
            const progressBar = document.getElementById('progress-bar');
            if(progressBar) progressBar.style.width = percentage + '%';
            
            const submitButton = document.getElementById('submit-button');
            if (submitButton) {
                if (filled === 0) {
                    submitButton.innerHTML = '<i class="fas fa-clipboard-check"></i> <span>Enviar Sem Defeitos</span>';
                } else {
                    submitButton.innerHTML = `<i class="fas fa-exclamation-triangle"></i> <span>Enviar ${filled} Defeito(s) Reportado(s)</span>`;
                }
            }
        }

        document.getElementById('defect-search').addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const defectItems = document.querySelectorAll('.defect-item');
            const categorySections = document.querySelectorAll('.category-section');

            defectItems.forEach(item => {
                const label = item.querySelector('label').textContent.toLowerCase();
                const matches = label.includes(searchTerm);
                item.style.display = matches ? '' : 'none';
            });
            
            categorySections.forEach(section => {
                const visibleItems = Array.from(section.querySelectorAll('.defect-item'))
                    .filter(item => item.style.display !== 'none');
                section.style.display = visibleItems.length > 0 ? '' : 'none';
            });
        });

        function redirectHome() {
            let role = '<?php echo isset($_SESSION['role']) ? $_SESSION['role'] : 'user'; ?>';
            if (role === 'user') {
                window.location.href = 'menu.php';
            } else if (role === 'admin') {
                window.location.href = 'menuadm.php';
            } else if (role === 'geraladm') {
                window.location.href = 'menugeraladm.php';
            } else {
                window.location.href = 'index.php';
            }
        }

        document.addEventListener("DOMContentLoaded", function() {
            document.querySelectorAll('.defect-item select').forEach(select => {
                handleSelectChange(select);
            });
            
            updateProgress();

            function setCurrentTime() {
                const now = new Date();
                const hours = String(now.getHours()).padStart(2, "0");
                const minutes = String(now.getMinutes()).padStart(2, "0");
                const timeString = `${hours}:${minutes}`;
                const timeInput = document.getElementById("idhora");
                if (timeInput) timeInput.value = timeString;
            }
            setCurrentTime();
            
            document.querySelectorAll('.notification-toast.show').forEach(toast => {
                setTimeout(() => {
                    toast.classList.remove('show');
                }, 5000);
            });
        });
    </script>
</body>
</html>
