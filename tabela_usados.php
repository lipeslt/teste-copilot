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

// Verifica se foi enviado via POST o parâmetro secretaria
$secretaria_filtro = $_POST['secretaria'] ?? 'TODOS';

// Mapeia a secretaria para o valor correto no banco de dados
$secretarias_map = [
    "Gabinete do Prefeito" => "GABINETE DO PREFEITO",
    "Gabinete do Vice-Prefeito" => "GABINETE DO VICE-PREFEITO",
    "Secretaria Municipal da Mulher de Família" => "SECRETARIA DA MULHER",
    "Secretaria Municipal de Fazenda" => "SECRETARIA DE FAZENDA",
    "Secretaria Municipal de Educação" => "SECRETARIA DE EDUCAÇÃO",
    "Secretaria Municipal de Agricultura e Meio Ambiente" => "SECRETARIA DE AGRICULTURA E MEIO AMBIENTE",
    "Secretaria Municipal de Agricultura Familiar e Segurança Alimentar" => "SECRETARIA DE AGRICULTURA FAMILIAR",
    "Secretaria Municipal de Assistência Social" => "SECRETARIA DE ASSISTÊNCIA SOCIAL",
    "Secretaria Municipal de Desenvolvimento Econômico e Turismo" => "SECRETARIA DE DESENVOL. ECONÔMICO",
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

// Função para mapear o status "ativo" para "livre"
function mapStatus($status) {
    if ($status === "ativo") {
        return "livre";
    }
    return $status;
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Veículos em Uso</title>
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
        
        .vehicle-card {
            transition: all 0.2s ease;
            border-left: 4px solid transparent;
        }
        
        .vehicle-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }
        
        .vehicle-card.em-uso {
            border-left-color: #DC2626;
        }
        
        .vehicle-card.livre {
            border-left-color: #16A34A;
        }
        
        .header-gradient {
            background: linear-gradient(135deg, #4F46E5 0%, #7C3AED 100%);
        }
        
        .refresh-button {
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body class="min-h-screen">
    <!-- App Bar -->
    <div class="header-gradient text-white shadow-md">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <div class="flex items-center space-x-3" onclick="window.location.href = '<?php
                    if ($_SESSION['role'] === 'admin') {
                        echo 'admin.php';
                    } elseif ($_SESSION['role'] === 'geraladm') {
                        echo 'geral_adm.php';
                    }
                ?>'"> <i class="fas fa-arrow-left"></i>
                </button>
                <h1 class="text-xl font-bold">
                    <?php echo $secretaria_filtro === 'TODOS' ? 'Todos os Veículos' : htmlspecialchars($secretaria_filtro); ?>
                </h1>
            </div>
            <div class="flex items-center space-x-2">
                <button id="refresh-btn" class="p-2 rounded-full hover:bg-white/10">
                    <i class="fas fa-sync-alt"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Content -->
    <div class="container mx-auto px-4 py-6">
        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
            <div class="card p-4">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm text-gray-500">Total de Veículos</p>
                        <h3 class="text-2xl font-bold" id="total-veiculos">0</h3>
                    </div>
                    <div class="p-3 rounded-full bg-indigo-100 text-indigo-600">
                        <i class="fas fa-car"></i>
                    </div>
                </div>
            </div>
            
            <div class="card p-4">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm text-gray-500">Em Uso</p>
                        <h3 class="text-2xl font-bold" id="em-uso">0</h3>
                    </div>
                    <div class="p-3 rounded-full bg-red-100 text-red-600">
                        <i class="fas fa-road"></i>
                    </div>
                </div>
            </div>
            
            <div class="card p-4">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm text-gray-500">Disponíveis</p>
                        <h3 class="text-2xl font-bold" id="disponiveis">0</h3>
                    </div>
                    <div class="p-3 rounded-full bg-green-100 text-green-600">
                        <i class="fas fa-check-circle"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter -->
        <div class="card p-4 mb-6">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between space-y-3 md:space-y-0">
                <div class="flex-1">
                    <label for="search" class="sr-only">Pesquisar</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-search text-gray-400"></i>
                        </div>
                        <input type="text" id="search" class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-lg focus:ring-indigo-500 focus:border-indigo-500" placeholder="Pesquisar veículo...">
                    </div>
                </div>
                <div class="flex space-x-2">
                    <select id="filter-status" class="border border-gray-300 rounded-lg px-3 py-2 focus:ring-indigo-500 focus:border-indigo-500">
                        <option value="all">Todos os status</option>
                        <option value="em uso">Em uso</option>
                        <option value="livre">Disponível</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- Vehicles List -->
        <div class="space-y-4" id="vehicles-container">
            <!-- Veículos serão carregados aqui via AJAX -->
            <div class="card p-4 text-center text-gray-500">
                <i class="fas fa-spinner fa-spin"></i> Carregando veículos...
            </div>
        </div>
    </div>

    <script>
    // Variável global para armazenar todos os veículos
    let allVehicles = [];
    
    // Função para carregar os veículos
    async function loadVehicles() {
        try {
            const refreshBtn = document.getElementById('refresh-btn');
            refreshBtn.innerHTML = '<i class="fas fa-sync-alt refresh-button"></i>';
            
            const secretaria = '<?php echo $secretaria_filtro === "TODOS" ? "" : $secretarias_map[$secretaria_filtro] ?? ""; ?>';
            const url = 'get_veiculos.php' + (secretaria ? `?secretaria=${encodeURIComponent(secretaria)}` : '?todos=1');
            
            const response = await fetch(url);
            const data = await response.json();
            
            if (data.error) {
                throw new Error(data.error);
            }
            
            // Ordenar veículos em uso primeiro
            data.sort((a, b) => {
                if (a.status === 'em uso' && b.status !== 'em uso') return -1;
                if (a.status !== 'em uso' && b.status === 'em uso') return 1;
                return 0;
            });
            
            allVehicles = data;
            updateVehicleDisplay();
            updateStats();
            
            refreshBtn.innerHTML = '<i class="fas fa-sync-alt"></i>';
        } catch (error) {
            console.error('Erro:', error);
            document.getElementById('vehicles-container').innerHTML = `
                <div class="card p-4 text-center text-red-500">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    ${error.message || 'Erro ao carregar veículos'}
                </div>`;
        }
    }
    
    // Função para filtrar e exibir os veículos
    function updateVehicleDisplay() {
        const searchTerm = document.getElementById('search').value.toLowerCase();
        const statusFilter = document.getElementById('filter-status').value;
        
        const filteredVehicles = allVehicles.filter(vehicle => {
            const matchesSearch = 
                vehicle.veiculo.toLowerCase().includes(searchTerm) ||
                (vehicle.motorista && vehicle.motorista.toLowerCase().includes(searchTerm)) ||
                (vehicle.destino && vehicle.destino.toLowerCase().includes(searchTerm));
            
            const matchesStatus = statusFilter === 'all' || vehicle.status === statusFilter;
            
            return matchesSearch && matchesStatus;
        });
        
        const container = document.getElementById('vehicles-container');
        
        if (filteredVehicles.length === 0) {
            container.innerHTML = `
                <div class="card p-8 text-center">
                    <i class="fas fa-car text-4xl text-gray-300 mb-4"></i>
                    <h3 class="text-lg font-medium text-gray-700">Nenhum veículo encontrado</h3>
                    <p class="text-gray-500">Tente ajustar seus filtros de pesquisa</p>
                </div>`;
            return;
        }
        
        container.innerHTML = filteredVehicles.map(vehicle => `
            <div class="card vehicle-card p-4 ${vehicle.status === 'em uso' ? 'em-uso' : 'livre'}">
                <div class="flex justify-between items-start">
                    <div class="flex-1">
                        <div class="flex items-center space-x-3">
                            <h3 class="font-bold text-lg">${vehicle.veiculo}</h3>
                            <span class="status-badge ${vehicle.status === 'em uso' ? 'status-em-uso' : 'status-livre'}">
                                ${vehicle.status === 'em uso' ? 'EM USO' : 'DISPONÍVEL'}
                            </span>
                        </div>
                        <p class="text-sm text-gray-500 mt-1">${vehicle.tipo}</p>
                    </div>
                    <div class="text-right">
                        ${vehicle.status === 'em uso' ? `
                            <p class="text-sm"><span class="font-medium">Motorista:</span> ${vehicle.motorista || '-'}</p>
                            <p class="text-sm"><span class="font-medium">Destino:</span> ${vehicle.destino || '-'}</p>
                        ` : `
                            <p class="text-sm text-gray-500">Último destino: ${vehicle.ponto_parada || 'N/A'}</p>
                        `}
                    </div>
                </div>
                
                ${vehicle.status === 'em uso' ? `
                <div class="mt-3 pt-3 border-t border-gray-100 flex justify-between items-center">
                    <div class="text-sm">
                        <i class="fas fa-clock mr-1"></i> Em uso desde: ${vehicle.hora_saida || 'N/A'}
                    </div>
                    <button class="text-sm text-indigo-600 hover:text-indigo-800">
                        <i class="fas fa-info-circle mr-1"></i> Detalhes
                    </button>
                </div>
                ` : ''}
            </div>
        `).join('');
    }
    
    // Função para atualizar as estatísticas
    function updateStats() {
        const total = allVehicles.length;
        const emUso = allVehicles.filter(v => v.status === 'em uso').length;
        const disponiveis = total - emUso;
        
        document.getElementById('total-veiculos').textContent = total;
        document.getElementById('em-uso').textContent = emUso;
        document.getElementById('disponiveis').textContent = disponiveis;
    }
    
    // Event Listeners
    document.getElementById('refresh-btn').addEventListener('click', loadVehicles);
    document.getElementById('search').addEventListener('input', updateVehicleDisplay);
    document.getElementById('filter-status').addEventListener('change', updateVehicleDisplay);
    
    // Carregar inicialmente
    loadVehicles();
    
    // Atualizar a cada 1 segundos
    setInterval(loadVehicles, 1000);

    
    </script>
</body>
</html>