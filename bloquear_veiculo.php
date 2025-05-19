<?php
session_start();
require 'conexao.php';
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestão de Frota Veicular</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/animate.css@4.1.1/animate.min.css">
    <style>
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        .fade-in { animation: fadeIn 0.4s forwards; }
        .card-hover { transition: all 0.3s ease; }
        .card-hover:hover { transform: translateY(-3px); box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.08); }
        .smooth-transition { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .mobile-optimized-table { display: block; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .status-pulse { animation: pulse 2s infinite; }
        @keyframes pulse { 0% { opacity: 0.8; } 50% { opacity: 1; } 100% { opacity: 0.8; } }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-25 to-gray-75 min-h-screen font-sans">
    <!-- Main Container -->
    <div class="container mx-auto px-4 sm:px-6 lg:px-8 py-6 max-w-7xl">
        <!-- App Header -->
        <header class="mb-8">
            <div class="flex flex-col space-y-4 sm:space-y-0 sm:flex-row justify-between items-start sm:items-end">
                <div>
                    <h1 class="text-2xl sm:text-3xl font-bold text-gray-800 leading-tight">
                        <i class="fas fa-car-side text-blue-500 mr-2"></i> Gestão de Frota Municipal
                    </h1>
                    <p class="text-gray-500 text-sm sm:text-base mt-1">Controle completo da frota veicular</p>
                </div>
                <div class="flex items-center space-x-2">
                    <span class="bg-blue-100 text-blue-800 text-xs font-medium px-2.5 py-0.5 rounded-full">
                        <?php echo $_SESSION['role'] === 'geraladm' ? 'Admin Geral' : 'Administrador'; ?>
                    </span>
                    <span class="hidden sm:inline-flex items-center text-xs text-gray-500">
                        <i class="far fa-clock mr-1"></i> <?php echo date('d/m/Y H:i'); ?>
                    </span>
                </div>
            </div>
        </header>

        <!-- Search Section -->
        <section class="mb-8">
            <div class="bg-white p-5 sm:p-6 rounded-2xl shadow-xs border border-gray-100 card-hover">
                <div class="flex flex-col space-y-4 sm:space-y-0 sm:flex-row sm:items-center justify-between">
                    <h2 class="text-lg sm:text-xl font-semibold text-gray-800 flex items-center">
                        <i class="fas fa-search text-blue-400 mr-2"></i> Busca Avançada
                    </h2>
                    <div class="relative w-full sm:w-96">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-search text-gray-400"></i>
                        </div>
                        <input type="text" id="termoBusca" placeholder="Digite modelo, placa ou tipo..." 
                               class="block w-full pl-10 pr-12 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-blue-300 focus:border-transparent transition-all duration-200"
                               autocomplete="off">
                        <button id="btnBuscar" onclick="buscarVeiculos()"
                                class="absolute inset-y-0 right-0 px-4 flex items-center bg-blue-500 hover:bg-blue-600 text-white rounded-r-xl transition-all duration-200">
                            <span class="hidden sm:inline">Buscar</span>
                            <i class="fas fa-search sm:ml-2"></i>
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <!-- Results Section -->
        <section>
            <div class="bg-white rounded-2xl shadow-xs border border-gray-100 overflow-hidden card-hover">
                <!-- Table Header -->
                <div class="px-4 sm:px-6 py-4 border-b border-gray-100 flex flex-col sm:flex-row justify-between items-start sm:items-center space-y-2 sm:space-y-0">
                    <div class="flex items-center">
                        <h2 class="text-lg sm:text-xl font-semibold text-gray-800 flex items-center">
                            <i class="fas fa-list-ul text-blue-400 mr-2"></i> Veículos Cadastrados
                        </h2>
                        <span id="totalVeiculos" class="ml-2 bg-gray-100 text-gray-800 text-xs font-medium px-2.5 py-0.5 rounded-full">0</span>
                    </div>
                    <div class="flex items-center space-x-3">
                        <div id="infoPaginacao" class="text-xs sm:text-sm text-gray-500"></div>
                        <div class="flex space-x-1">
                            <button id="btnAnterior" onclick="mudarPagina(-1)" 
                                    class="p-2 sm:px-3 sm:py-1.5 border border-gray-200 rounded-lg bg-white text-gray-700 hover:bg-gray-50 disabled:opacity-40 flex items-center transition-all">
                                <i class="fas fa-chevron-left text-xs"></i>
                                <span class="hidden sm:inline ml-1">Anterior</span>
                            </button>
                            <button id="btnProximo" onclick="mudarPagina(1)" 
                                    class="p-2 sm:px-3 sm:py-1.5 border border-gray-200 rounded-lg bg-white text-gray-700 hover:bg-gray-50 disabled:opacity-40 flex items-center transition-all">
                                <span class="hidden sm:inline mr-1">Próximo</span>
                                <i class="fas fa-chevron-right text-xs"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Table Container -->
                <div class="mobile-optimized-table">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Veículo</th>
                                <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Placa</th>
                                <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                                <th class="px-4 sm:px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
                            </tr>
                        </thead>
                        <tbody id="resultadosVeiculos" class="bg-white divide-y divide-gray-200">
                            <!-- Results will be inserted here -->
                        </tbody>
                    </table>
                </div>
                
                <!-- Empty State -->
                <div id="emptyState" class="hidden px-6 py-12 text-center">
                    <i class="fas fa-car text-4xl text-gray-300 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-500">Nenhum veículo encontrado</h3>
                    <p class="mt-1 text-sm text-gray-400">Tente alterar seus termos de busca</p>
                </div>
                
                <!-- Loading State -->
                <div id="loadingState" class="hidden px-6 py-12 text-center">
                    <div class="flex justify-center">
                        <div class="animate-spin rounded-full h-12 w-12 border-t-2 border-b-2 border-blue-500"></div>
                    </div>
                    <p class="mt-4 text-gray-500">Carregando veículos...</p>
                </div>
            </div>
        </section>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmationModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden px-4">
        <div class="bg-white rounded-xl p-6 w-full max-w-md animate__animated animate__fadeIn">
            <div class="flex justify-between items-start mb-4">
                <div class="flex items-center">
                    <i id="modalIcon" class="fas fa-exclamation-circle text-xl mr-3"></i>
                    <h3 id="modalTitle" class="text-xl font-bold text-gray-800"></h3>
                </div>
                <button onclick="fecharModal()" class="text-gray-400 hover:text-gray-600">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <p id="modalMessage" class="text-gray-600 mb-6 pl-9"></p>
            <div class="flex justify-end space-x-3">
                <button onclick="fecharModal()" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 transition-all">
                    Cancelar
                </button>
                <button id="confirmActionBtn" onclick="confirmarAcao()" class="px-4 py-2 rounded-lg text-white transition-all"></button>
            </div>
        </div>
    </div>

    <!-- Notification Toast -->
    <div id="notificationToast" class="fixed bottom-4 right-4 text-white px-5 py-3 rounded-xl shadow-lg hidden z-50 animate__animated animate__fadeInUp max-w-xs">
        <div class="flex items-start">
            <i id="toastIcon" class="fas fa-check-circle mt-0.5 mr-3"></i>
            <div>
                <p id="toastTitle" class="font-medium"></p>
                <p id="toastMessage" class="text-sm opacity-90"></p>
            </div>
        </div>
    </div>

    <script>
        let paginaAtual = 1;
        let totalPaginas = 1;
        let totalVeiculos = 0;
        let termoAtual = '';
        let acaoAtual = null;
        let veiculoId = null;
        let veiculoNome = null;

        // Buscar veículos (com tratamento de loading e empty states)
        function buscarVeiculos() {
            termoAtual = document.getElementById('termoBusca').value.trim();
            paginaAtual = 1;
            carregarVeiculos();
        }

        function carregarVeiculos() {
            const tbody = document.getElementById('resultadosVeiculos');
            const emptyState = document.getElementById('emptyState');
            const loadingState = document.getElementById('loadingState');
            
            // Mostrar loading state
            tbody.innerHTML = '';
            emptyState.classList.add('hidden');
            loadingState.classList.remove('hidden');
            
            // Disable buttons during loading
            document.getElementById('btnAnterior').disabled = true;
            document.getElementById('btnProximo').disabled = true;
            document.getElementById('btnBuscar').disabled = true;
            
            fetch(`buscar_veiculos.php?termo=${encodeURIComponent(termoAtual)}&page=${paginaAtual}`)
                .then(response => response.json())
                .then(data => {
                    loadingState.classList.add('hidden');
                    document.getElementById('btnBuscar').disabled = false;
                    
                    if (!data || data.length === 0) {
                        emptyState.classList.remove('hidden');
                        totalVeiculos = 0;
                    } else {
                        renderizarVeiculos(data);
                        totalVeiculos = data.length;
                    }
                    
                    atualizarControles();
                })
                .catch(error => {
                    console.error('Erro:', error);
                    loadingState.classList.add('hidden');
                    emptyState.classList.remove('hidden');
                    mostrarNotificacao('Erro', 'Ocorreu um erro ao buscar veículos', 'red');
                    document.getElementById('btnBuscar').disabled = false;
                });
        }

        function renderizarVeiculos(veiculos) {
            const tbody = document.getElementById('resultadosVeiculos');
            tbody.innerHTML = '';
            
            veiculos.forEach(veiculo => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-gray-50 fade-in';
                
                // Mobile optimized layout
                tr.innerHTML = `
                    <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                        <div class="flex items-center">
                            <div class="flex-shrink-0 h-10 w-10 bg-blue-50 rounded-lg flex items-center justify-center">
                                <i class="fas fa-car text-blue-500"></i>
                            </div>
                            <div class="ml-4">
                                <div class="text-sm font-medium text-gray-900">${veiculo.veiculo}</div>
                                <div class="text-xs text-gray-500 sm:hidden">${veiculo.placa} • ${veiculo.tipo}</div>
                                <div class="text-xs text-gray-500 sm:hidden">${veiculo.secretaria}</div>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 sm:px-6 py-4 whitespace-nowrap hidden sm:table-cell">
                        <div class="text-sm text-gray-900 font-mono">${veiculo.placa}</div>
                    </td>
                    <td class="px-4 sm:px-6 py-4 whitespace-nowrap">
                        <span class="px-2.5 py-1 inline-flex text-xs leading-4 font-semibold rounded-full 
                            ${veiculo.status === 'bloqueado' ? 
                                'bg-red-100 text-red-800 status-pulse' : 
                                'bg-green-100 text-green-800'}">
                            ${veiculo.status === 'bloqueado' ? 'Bloqueado' : 'Ativo'}
                        </span>
                    </td>
                    <td class="px-4 sm:px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                        ${veiculo.status === 'bloqueado' ? 
                            `<button onclick="mostrarModalDesbloquear(${veiculo.id}, '${veiculo.veiculo.replace(/'/g, "\\'")}')" 
                             class="text-green-600 hover:text-green-800 mr-2 sm:mr-3 transition-all">
                                <i class="fas fa-unlock sm:mr-1"></i>
                                <span class="hidden sm:inline">Desbloquear</span>
                            </button>` : 
                            `<button onclick="mostrarModalBloquear(${veiculo.id}, '${veiculo.veiculo.replace(/'/g, "\\'")}')" 
                             class="text-red-600 hover:text-red-800 mr-2 sm:mr-3 transition-all">
                                <i class="fas fa-lock sm:mr-1"></i>
                                <span class="hidden sm:inline">Bloquear</span>
                            </button>`}
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }

        function atualizarControles() {
            document.getElementById('btnAnterior').disabled = paginaAtual <= 1;
            document.getElementById('btnProximo').disabled = paginaAtual >= totalPaginas;
            document.getElementById('infoPaginacao').textContent = `Página ${paginaAtual} de ${totalPaginas}`;
            document.getElementById('totalVeiculos').textContent = totalVeiculos;
        }

        // Modal functions
        function mostrarModalBloquear(id, nome) {
            veiculoId = id;
            veiculoNome = nome;
            acaoAtual = 'bloquear';
            
            const modalIcon = document.getElementById('modalIcon');
            modalIcon.className = 'fas fa-lock text-red-500 text-xl mr-3';
            
            document.getElementById('modalTitle').textContent = 'Confirmar Bloqueio';
            document.getElementById('modalMessage').textContent = `Tem certeza que deseja bloquear o veículo "${nome}"?`;
            document.getElementById('confirmActionBtn').innerHTML = '<i class="fas fa-lock mr-2"></i> Bloquear';
            document.getElementById('confirmActionBtn').className = 'px-4 py-2 rounded-lg text-white transition-all bg-red-500 hover:bg-red-600';
            
            document.getElementById('confirmationModal').classList.remove('hidden');
        }

        function mostrarModalDesbloquear(id, nome) {
            veiculoId = id;
            veiculoNome = nome;
            acaoAtual = 'desbloquear';
            
            const modalIcon = document.getElementById('modalIcon');
            modalIcon.className = 'fas fa-unlock text-green-500 text-xl mr-3';
            
            document.getElementById('modalTitle').textContent = 'Confirmar Desbloqueio';
            document.getElementById('modalMessage').textContent = `Tem certeza que deseja desbloquear o veículo "${nome}"?`;
            document.getElementById('confirmActionBtn').innerHTML = '<i class="fas fa-unlock mr-2"></i> Desbloquear';
            document.getElementById('confirmActionBtn').className = 'px-4 py-2 rounded-lg text-white transition-all bg-green-500 hover:bg-green-600';
            
            document.getElementById('confirmationModal').classList.remove('hidden');
        }

        function fecharModal() {
            document.getElementById('confirmationModal').classList.add('hidden');
        }

        function confirmarAcao() {
            fecharModal();
            const novoStatus = acaoAtual === 'bloquear' ? 'bloqueado' : 'ativo';
            
            fetch('alterar_status_veiculo.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ id: veiculoId, status: novoStatus })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    mostrarNotificacao(
                        acaoAtual === 'bloquear' ? 'Veículo bloqueado' : 'Veículo desbloqueado',
                        acaoAtual === 'bloquear' ? 
                            'O veículo foi bloqueado com sucesso.' : 
                            'O veículo foi liberado e está disponível para uso.',
                        acaoAtual === 'bloquear' ? 'red' : 'green'
                    );
                    carregarVeiculos();
                } else {
                    mostrarNotificacao(
                        'Erro',
                        data.message || 'Ocorreu um erro ao atualizar o status do veículo',
                        'red'
                    );
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                mostrarNotificacao('Erro', 'Falha na conexão com o servidor', 'red');
            });
        }

        // Notificação Toast
        function mostrarNotificacao(titulo, mensagem, cor) {
            const toast = document.getElementById('notificationToast');
            const icon = document.getElementById('toastIcon');
            const title = document.getElementById('toastTitle');
            const msg = document.getElementById('toastMessage');
            
            // Configura cor e ícone
            toast.className = `fixed bottom-4 right-4 text-white px-5 py-3 rounded-xl shadow-lg z-50 animate__animated animate__fadeInUp max-w-xs bg-${cor}-500`;
            icon.className = `fas ${cor === 'green' ? 'fa-check-circle' : 'fa-exclamation-circle'} mt-0.5 mr-3`;
            title.textContent = titulo;
            msg.textContent = mensagem;
            
            // Mostra o toast
            toast.classList.remove('hidden');
            
            // Esconde após 4 segundos
            setTimeout(() => {
                toast.classList.add('animate__fadeOutDown');
                setTimeout(() => {
                    toast.classList.add('hidden');
                    toast.classList.remove('animate__fadeOutDown');
                }, 500);
            }, 4000);
        }

        // Paginação
        function mudarPagina(delta) {
            const novaPagina = paginaAtual + delta;
            if (novaPagina > 0 && novaPagina <= totalPaginas) {
                paginaAtual = novaPagina;
                carregarVeiculos();
                // Scroll suave para o topo da tabela
                window.scrollTo({
                    top: document.querySelector('#resultadosVeiculos').offsetTop - 20,
                    behavior: 'smooth'
                });
            }
        }

        // Inicialização
        document.addEventListener('DOMContentLoaded', () => {
            // Carregar veículos inicialmente
            carregarVeiculos();
            
            // Buscar ao pressionar Enter
            document.getElementById('termoBusca').addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    buscarVeiculos();
                }
            });
            
            // Melhorar UX do campo de busca
            const searchInput = document.getElementById('termoBusca');
            const searchBtn = document.getElementById('btnBuscar');
            
            searchInput.addEventListener('input', () => {
                if (searchInput.value.trim() !== termoAtual) {
                    searchBtn.classList.add('bg-blue-600');
                } else {
                    searchBtn.classList.remove('bg-blue-600');
                }
            });
            
            // Focar no campo de busca ao carregar
            searchInput.focus();
        });
    </script>
</body>
</html>