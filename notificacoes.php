<?php
session_start();
include 'conexao.php';

// Verifica autenticação do usuário
if (!isset($_SESSION['user_name'])) {
    header("Location: index.php");
    exit();
}

// Endpoint para API de notificações pendentes
if(isset($_GET['api'])) {
    header('Content-Type: application/json');

    try {
        $user_name = $_SESSION['user_name'];
        $user_query = "SELECT id FROM usuarios WHERE name = :name LIMIT 1";
        $user_stmt = $conn->prepare($user_query);
        $user_stmt->bindParam(':name', $user_name, PDO::PARAM_STR);
        $user_stmt->execute();
        $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);

        if($user_data) {
            $user_id = $user_data['id'];
            $notificacao_query = "SELECT * FROM notificacoes WHERE usuario_id = :usuario_id AND status = 'pendente' ORDER BY data DESC";
            $notificacao_stmt = $conn->prepare($notificacao_query);
            $notificacao_stmt->bindParam(':usuario_id', $user_id);
            $notificacao_stmt->execute();
            $notificacoes = $notificacao_stmt->fetchAll(PDO::FETCH_ASSOC);
            echo json_encode($notificacoes);
        } else {
            echo json_encode([]);
        }
    } catch(PDOException $e) {
        echo json_encode([]);
    }
    exit;
}

// Processamento normal para a página HTML
$user_name = $_SESSION['user_name'];
$user_query = "SELECT id FROM usuarios WHERE name = :name LIMIT 1";
$user_stmt = $conn->prepare($user_query);
$user_stmt->bindParam(':name', $user_name, PDO::PARAM_STR);
$user_stmt->execute();
$user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);

$notificacoes = [];
if ($user_data) {
    $user_id = $user_data['id'];

    try {
        // Query corrigida para ordenar as notificações:
        // 1º critério: Notificações pendentes primeiro
        // 2º critério: Para notificações pendentes, ordena por data em ordem decrescente
        // 3º critério: Para notificações não pendentes, ordena por processed_at em ordem ascendente
        $notificacao_query = "SELECT * FROM notificacoes WHERE usuario_id = :usuario_id
                              ORDER BY
                                CASE WHEN status = 'pendente' THEN 0 ELSE 1 END,
                                IF(status = 'pendente', data, NULL) DESC,
                                IF(status != 'pendente', processed_at, NULL) ASC";
        $notificacao_stmt = $conn->prepare($notificacao_query);
        $notificacao_stmt->bindParam(':usuario_id', $user_id);
        $notificacao_stmt->execute();
        $notificacoes = $notificacao_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch(PDOException $e) {
        $error_message = "Erro ao carregar notificações: " . $e->getMessage();
    }
}

// Verifica se há feedback para mostrar
$feedback = isset($_GET['feedback']) && !empty(trim($_GET['feedback'])) ? trim($_GET['feedback']) : '';
$feedback_type = isset($_GET['type']) ? $_GET['type'] : 'success';
?>

<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Notificações</title>
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
        .hidden {
            display: none;
        }
        
        /* Estilos específicos para notificações */
        .notification-list {
            padding: 0;
            list-style: none;
        }
        .notification-item {
            padding: 1rem;
            margin-bottom: 1rem;
            border-radius: 0.75rem;
            border-left: 4px solid;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
        }
        .notification-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .notification-item.pendente {
            border-left-color: #F59E0B;
            background-color: rgba(245, 158, 11, 0.05);
        }
        .notification-item.aceito {
            border-left-color: #10B981;
            background-color: rgba(16, 185, 129, 0.05);
        }
        .notification-item.recusado {
            border-left-color: #EF4444;
            background-color: rgba(239, 68, 68, 0.05);
        }
        .notification-title {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 0.5rem;
            color: #1F2937;
        }
        .notification-meta {
            font-size: 0.875rem;
            color: #6B7280;
            margin-bottom: 0.5rem;
        }
        .notification-message {
            font-size: 0.9375rem;
            line-height: 1.5;
            color: #374151;
            margin-bottom: 1rem;
        }
        .notification-actions {
            display: flex;
            gap: 0.5rem;
        }
        .btn-accept {
            background-color: #10B981;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .btn-accept:hover {
            background-color: #059669;
            transform: translateY(-1px);
        }
        .btn-reject {
            background-color: #EF4444;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }
        .btn-reject:hover {
            background-color: #DC2626;
            transform: translateY(-1px);
        }
        .no-notifications {
            text-align: center;
            padding: 2rem;
            color: #6B7280;
            font-size: 1rem;
        }
        
        /* Novos estilos para a visualização simplificada */
        .notification-basic {
            cursor: pointer;
        }
        .notification-full {
            display: none;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid #e5e7eb;
        }
        .btn-read-more {
            background-color: #4F46E5;
            color: white;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s ease;
            margin-top: 0.5rem;
            display: inline-block;
        }
        .btn-read-more:hover {
            background-color: #4338CA;
            transform: translateY(-1px);
        }
        
        /* Sistema de Notificação Personalizado */
        .custom-alert {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1000;
            max-width: 350px;
            width: 90%;
            transition: all 0.4s ease;
            animation: slideIn 0.4s forwards;
        }
        .custom-alert.hidden {
            transform: translateX(150%);
            opacity: 0;
        }
        .custom-alert .alert-content {
            background: white;
            border-radius: 8px;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
            display: flex;
            align-items: center;
            padding: 15px;
            border-left: 5px solid;
        }
        .custom-alert .alert-icon {
            font-size: 1.8rem;
            margin-right: 15px;
            width: 30px;
            text-align: center;
        }
        .custom-alert .alert-message {
            flex: 1;
            font-size: 0.95rem;
            line-height: 1.4;
        }
        .custom-alert .alert-close {
            background: none;
            border: none;
            color: #999;
            cursor: pointer;
            font-size: 1rem;
            margin-left: 10px;
            transition: color 0.3s ease;
        }
        .custom-alert .alert-close:hover {
            color: #666;
        }
        .alert-success {
            border-left-color: #10B981;
        }
        .alert-success .alert-icon {
            color: #10B981;
        }
        .alert-error {
            border-left-color: #EF4444;
        }
        .alert-error .alert-icon {
            color: #EF4444;
        }
        .alert-warning {
            border-left-color: #F59E0B;
        }
        .alert-warning .alert-icon {
            color: #F59E0B;
        }
        
        /* Sistema de Confirmação Personalizado */
        .confirm-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 2000;
        }
        .confirm-box {
            background: white;
            border-radius: 8px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.2);
            padding: 25px;
            max-width: 400px;
            width: 90%;
            text-align: center;
            animation: fadeIn 0.3s ease;
        }
        .confirm-message {
            font-size: 1.1rem;
            margin-bottom: 20px;
            line-height: 1.4;
        }
        .confirm-buttons {
            display: flex;
            justify-content: center;
            gap: 15px;
        }
        .confirm-btn {
            padding: 8px 20px;
            border-radius: 5px;
            border: none;
            cursor: pointer;
            font-weight: bold;
            transition: all 0.3s ease;
        }
        .confirm-yes {
            background-color: #10B981;
            color: white;
        }
        .confirm-yes:hover {
            background-color: #059669;
        }
        .confirm-no {
            background-color: #f1f1f1;
            color: #333;
        }
        .confirm-no:hover {
            background-color: #ddd;
        }
        .custom-alert.hidden {
            display: none !important;
        }
        
        /* Animações */
        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOut {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(100%); opacity: 0; }
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Responsividade */
        @media (max-width: 400px) {
            .notification-actions {
                flex-direction: column;
            }
            .btn-accept, .btn-reject {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="app-container relative">
        <!-- Logo Header -->
        <div class="logo-container h-48 rounded-b-3xl flex flex-col items-center justify-center shadow-hard relative">
            <div class="nav-button bg-white absolute top-6 left-6" onclick="window.location.href = 'menuadm.php'">
                <i class="fas fa-chevron-left text-primary"></i>
            </div>
            <div class="bg-white/20 p-4 rounded-full mb-4">
                <i class="fas fa-bell text-white text-4xl"></i>
            </div>
            <h1 class="text-white text-2xl font-bold">Notificações</h1>
        </div>

        <!-- Notifications Container -->
        <div class="forms-container px-5 pb-6 -mt-10 relative">
            <div class="bg-white rounded-2xl p-6 shadow-hard">
                <?php if (isset($error_message)): ?>
                    <div class="notification-item recusado mb-4">
                        <p><?= htmlspecialchars($error_message) ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($notificacoes)): ?>
                    <ul class="notification-list">
                        <?php foreach ($notificacoes as $notificacao): ?>
                            <li class="notification-item <?= $notificacao['status'] ?>">
                                <div class="notification-basic" onclick="toggleNotification(this)">
                                    <h3 class="notification-title"><?= htmlspecialchars($notificacao['nome']) ?></h3>
                                    <p class="notification-meta">
                                        <strong>Prefixo:</strong> <?= htmlspecialchars($notificacao['prefixo']) ?><br>
                                        <strong>Secretaria:</strong> <?= htmlspecialchars($notificacao['secretaria']) ?><br>
                                        <strong>Data:</strong> <?= date('d/m/Y H:i', strtotime($notificacao['data'])) ?>
                                    </p>
                                    <button class="btn-read-more">Ler mais</button>
                                </div>
                                
                                <div class="notification-full">
                                    <p class="notification-message"><?= nl2br(htmlspecialchars($notificacao['mensagem'])) ?></p>
                                    
                                    <?php if ($notificacao['status'] == 'pendente'): ?>
                                        <div class="notification-actions">
                                            <button class="btn-accept" data-id="<?= $notificacao['id'] ?>" data-action="aceitar">
                                                <i class="fas fa-check mr-1"></i> Aceitar
                                            </button>
                                            <button class="btn-reject" data-id="<?= $notificacao['id'] ?>" data-action="recusar">
                                                <i class="fas fa-times mr-1"></i> Recusar
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <div class="no-notifications">
                        <i class="fas fa-bell-slash text-2xl mb-2 text-gray-400"></i>
                        <p>Nenhuma notificação encontrada</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Sistema de Notificação Estilizado -->
        <div id="customAlert" class="custom-alert hidden">
            <div class="alert-content">
                <div class="alert-icon">
                    <i id="alertIcon" class="fas"></i>
                </div>
                <div class="alert-message" id="alertMessage"></div>
                <button onclick="hideCustomAlert()" class="alert-close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
    </div>

    <script>
// Sistema de Notificações
class NotificationSystem {
    constructor() {
        this.initEvents();
        this.showUrlFeedback();
    }

    // Mostrar alerta customizado
    showCustomAlert(message, type = 'success', duration = 5000) {
        const alert = document.getElementById('customAlert');
        const alertIcon = document.getElementById('alertIcon');
        const alertMessage = document.getElementById('alertMessage');

        // Configura o tipo de alerta
        alert.className = `custom-alert alert-${type}`;
        alertMessage.innerHTML = message;

        // Configura o ícone
        const icons = {
            success: 'fa-check-circle',
            error: 'fa-exclamation-circle',
            warning: 'fa-exclamation-triangle',
            info: 'fa-info-circle'
        };
        alertIcon.className = `fas ${icons[type] || icons.info}`;

        // Mostra o alerta com animação
        alert.style.display = 'flex';
        setTimeout(() => {
            alert.classList.remove('hidden');
            alert.style.opacity = '1';
            alert.style.transform = 'translateX(0)';
        }, 10);

        // Esconde automaticamente após a duração
        if (duration > 0) {
            setTimeout(() => this.hideCustomAlert(), duration);
        }
    }

    // Esconder alerta customizado
    hideCustomAlert() {
        const alert = document.getElementById('customAlert');
        alert.style.opacity = '0';
        alert.style.transform = 'translateX(100%)';
        setTimeout(() => {
            alert.classList.add('hidden');
            alert.style.display = 'none';
        }, 400);
    }

    // Mostrar prompt de comentário
    showCommentPrompt(action, callback) {
        const overlay = document.createElement('div');
        overlay.className = 'confirm-overlay';
        overlay.style.animation = 'fadeIn 0.3s ease';
        
        const actionColor = action === 'aceitar' ? 'bg-success' : 'bg-danger';
        const actionIcon = action === 'aceitar' ? 'fa-check-circle' : 'fa-times-circle';
        const actionText = action === 'aceitar' ? 'Aprovar' : 'Recusar';
        
        const confirmBox = document.createElement('div');
        confirmBox.className = 'confirm-box bg-white rounded-xl overflow-hidden shadow-xl transform transition-all duration-300 scale-95';
        confirmBox.style.maxWidth = '420px';
        confirmBox.style.width = '90%';
        
        confirmBox.innerHTML = `
            <div class="${actionColor} p-4 text-white text-center">
                <i class="fas ${actionIcon} text-3xl mb-2"></i>
                <h3 class="text-xl font-bold">${actionText} Notificação</h3>
            </div>
            
            <div class="p-5">
                <div class="mb-4">
                    <label for="commentInput" class="block text-sm font-medium text-gray-700 mb-2">
                        Comentário (obrigatório)
                    </label>
                    <textarea id="commentInput" 
                        class="w-full px-4 py-3 rounded-lg border border-gray-300 focus:ring-2 focus:ring-primary focus:border-transparent transition-all duration-200"
                        rows="4" 
                        placeholder="Digite o motivo da ${action === 'aceitar' ? 'aprovação' : 'recusa'}..."
                        required></textarea>
                    <p class="text-xs text-gray-500 mt-1">Este comentário será registrado no histórico.</p>
                </div>
                
                <div class="flex flex-col sm:flex-row gap-3 mt-6">
                    <button class="confirm-no flex-1 py-3 px-4 bg-gray-100 hover:bg-gray-200 text-gray-800 font-medium rounded-lg transition-all duration-200 flex items-center justify-center gap-2">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <button class="confirm-yes flex-1 py-3 px-4 ${actionColor} hover:opacity-90 text-white font-medium rounded-lg transition-all duration-200 flex items-center justify-center gap-2">
                        <i class="fas ${actionIcon}"></i> ${actionText}
                    </button>
                </div>
            </div>
        `;

        overlay.appendChild(confirmBox);
        document.body.appendChild(overlay);

        // Anima a entrada
        setTimeout(() => {
            confirmBox.style.transform = 'scale(1)';
        }, 10);

        // Foco automático no textarea
        const commentInput = document.getElementById('commentInput');
        commentInput.focus();

        // Eventos dos botões
        confirmBox.querySelector('.confirm-yes').addEventListener('click', () => {
            const comment = commentInput.value.trim();
            if (!comment) {
                this.shakeInput(commentInput);
                this.showCustomAlert('Por favor, insira um comentário válido.', 'error');
                return;
            }
            this.closePrompt(overlay, confirmBox, () => callback(comment));
        });

        confirmBox.querySelector('.confirm-no').addEventListener('click', () => {
            this.closePrompt(overlay, confirmBox, () => callback(null));
        });

        // Fechar ao clicar fora ou pressionar ESC
        overlay.addEventListener('click', (e) => {
            if (e.target === overlay) {
                this.closePrompt(overlay, confirmBox, () => callback(null));
            }
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                this.closePrompt(overlay, confirmBox, () => callback(null));
            }
        });
    }

    // Efeito de shake para campos inválidos
    shakeInput(input) {
        input.classList.add('border-danger', 'ring-2', 'ring-danger');
        input.classList.remove('border-gray-300');
        input.style.animation = 'shake 0.5s';
        setTimeout(() => {
            input.style.animation = '';
            input.classList.remove('ring-2', 'ring-danger');
        }, 500);
    }

    // Fechar prompt com animação
    closePrompt(overlay, box, callback) {
        box.style.transform = 'scale(0.95)';
        box.style.opacity = '0';
        overlay.style.opacity = '0';
        
        setTimeout(() => {
            document.body.removeChild(overlay);
            callback();
        }, 300);
    }

    // Alternar visibilidade da notificação
    toggleNotification(element) {
        const fullContent = element.nextElementSibling;
        const isVisible = fullContent.style.display === 'block';
        const btn = element.querySelector('.btn-read-more');
        
        if (isVisible) {
            fullContent.style.animation = 'fadeOutUp 0.3s ease';
            setTimeout(() => {
                fullContent.style.display = 'none';
                btn.innerHTML = '<i class="fas fa-chevron-down mr-1"></i> Ler mais';
            }, 250);
        } else {
            fullContent.style.display = 'block';
            fullContent.style.animation = 'fadeInDown 0.3s ease';
            btn.innerHTML = '<i class="fas fa-chevron-up mr-1"></i> Ler menos';
            
            // Rola suavemente para a notificação
            setTimeout(() => {
                element.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }, 100);
        }
    }

    // Processar ação na notificação
    async processNotification(action, id, comment) {
        try {
            const response = await fetch('processar_notificacao.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, action, comment }),
                credentials: 'same-origin'
            });

            const data = await response.json();

            if (data.success) {
                const notificationElement = document.querySelector(`.notification-item [data-id="${id}"]`).closest('.notification-item');
                this.updateNotificationUI(notificationElement, action);
                this.showCustomAlert(data.message, 'success');
            } else {
                this.showCustomAlert(data.message || 'Erro ao processar notificação', 'error');
            }
        } catch (error) {
            console.error('Error:', error);
            this.showCustomAlert('Erro na comunicação com o servidor', 'error');
        }
    }

    // Atualizar UI da notificação após ação
    updateNotificationUI(element, action) {
        element.classList.remove('pendente');
        element.classList.add(action === 'aceitar' ? 'aceito' : 'recusado');

        // Remove botões de ação
        const actionsContainer = element.querySelector('.notification-actions');
        if (actionsContainer) actionsContainer.remove();

        // Move para o final da lista
        const list = document.querySelector('.notification-list');
        list.appendChild(element);

        // Adiciona badge de status
        const statusBadge = document.createElement('div');
        statusBadge.className = `status-badge text-xs font-semibold px-2 py-1 rounded-full ${action === 'aceitar' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`;
        statusBadge.innerHTML = `<i class="fas ${action === 'aceitar' ? 'fa-check' : 'fa-times'} mr-1"></i> ${action === 'aceitar' ? 'Aprovado' : 'Recusado'}`;
        
        const title = element.querySelector('.notification-title');
        if (title) {
            title.insertAdjacentElement('afterend', statusBadge);
        }
    }

    // Mostrar feedback da URL
    showUrlFeedback() {
        const urlParams = new URLSearchParams(window.location.search);
        const feedback = urlParams.get('feedback');
        const feedbackType = urlParams.get('type');

        if (feedback) {
            this.showCustomAlert(decodeURIComponent(feedback), feedbackType || 'success');
            
            // Limpa a URL sem recarregar a página
            window.history.replaceState({}, document.title, window.location.pathname);
        }
    }

    // Inicializar eventos
    initEvents() {
        // Botões de aceitar/recusar
        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.btn-accept, .btn-reject').forEach(button => {
                button.addEventListener('click', (e) => {
                    e.preventDefault();
                    const action = e.currentTarget.dataset.action;
                    const id = e.currentTarget.dataset.id;
                    
                    this.showCommentPrompt(action, (comment) => {
                        if (comment) {
                            this.processNotification(action, id, comment);
                        }
                    });
                });
            });

            // Botões de ler mais/menos
            document.querySelectorAll('.notification-basic').forEach(element => {
                element.addEventListener('click', (e) => {
                    // Evita abrir se o clique foi em um botão de ação
                    if (!e.target.closest('.btn-accept, .btn-reject')) {
                        this.toggleNotification(element);
                    }
                });
            });
        });
    }
}

// Inicializa o sistema de notificações
new NotificationSystem();

// Adiciona estilos dinâmicos para animações
const style = document.createElement('style');
style.textContent = `
    @keyframes shake {
        0%, 100% { transform: translateX(0); }
        20%, 60% { transform: translateX(-5px); }
        40%, 80% { transform: translateX(5px); }
    }
    @keyframes fadeInDown {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes fadeOutUp {
        from { opacity: 1; transform: translateY(0); }
        to { opacity: 0; transform: translateY(-10px); }
    }
    .status-badge {
        display: inline-block;
        margin-left: 8px;
    }
`;
document.head.appendChild(style);
</script>
</body>
</html>