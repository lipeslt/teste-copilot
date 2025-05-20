<?php
session_start();
$host = "localhost";
$dbname = "workflow_system";
$username = "root";
$password = "";

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Erro de conexão: " . $e->getMessage());
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'];
$user_secretaria = $_SESSION['secretaria'] ?? null;

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

// Configuração inicial dos filtros
$mostrar_todas_secretarias = ($user_role === 'geraladm');
$secretaria_filtro = 'TODOS';

// Verifica se houve submissão de formulário com filtro de secretaria
if (isset($_POST['secretaria'])) {
    $secretaria_filtro = $_POST['secretaria'];
} elseif (isset($_GET['secretaria'])) {
    $secretaria_filtro = $_GET['secretaria'];
}

// Se for admin, só pode ver a própria secretaria
if ($user_role === 'admin') {
    $secretaria_filtro = $user_secretaria;
    $mostrar_todas_secretarias = false;
}

// Verifica se o usuário tem permissão para acessar a página
if ($user_role !== 'geraladm' && $user_role !== 'admin') {
    header("Location: index.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Média de Consumo por Veículo</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        :root {
            --primary-color: #4F46E5;
            --secondary-color: #10B981;
            --danger-color: #EF4444;
            --warning-color: #F59E0B;
        }
        
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: #f8fafc;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
            border: 1px solid #e2e8f0;
        }
        
        .consumo-badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .consumo-otimo {
            background-color: #ECFDF5;
            color: #059669;
            border: 1px solid #A7F3D0;
        }
        
        .consumo-bom {
            background-color: #FEF3C7;
            color: #B45309;
            border: 1px solid #FCD34D;
        }
        
        .consumo-ruim {
            background-color: #FEE2E2;
            color: #B91C1C;
            border: 1px solid #FCA5A5;
        }
        
        .vehicle-card {
            transition: all 0.2s ease;
        }
        
        .vehicle-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
            border-color: #cbd5e1;
        }
        
        .header-gradient {
            background: linear-gradient(135deg, #4F46E5 0%, #7C3AED 100%);
        }
        
        .filter-btn {
            transition: all 0.2s ease;
        }
        
        .filter-btn:hover {
            transform: translateY(-1px);
        }
        
        .progress-fill-otimo {
            background: linear-gradient(90deg, #10B981 0%, #34D399 100%);
        }
        
        .progress-fill-bom {
            background: linear-gradient(90deg, #F59E0B 0%, #FBBF24 100%);
        }
        
        .progress-fill-ruim {
            background: linear-gradient(90deg, #EF4444 0%, #F87171 100%);
        }
        
        .vehicle-prefixo {
            font-size: 1.25rem;
            font-weight: 700;
            color: #1e293b;
            letter-spacing: -0.5px;
        }
        
        .vehicle-desc {
            color: #64748b;
            font-size: 0.9375rem;
        }
        
        .stat-card {
            transition: all 0.2s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.05);
        }
    </style>
</head>
<body class="min-h-screen bg-gray-50">
    <!-- App Bar -->
    <div class="header-gradient text-white shadow-sm">
        <div class="container mx-auto px-4 py-4 flex justify-between items-center">
            <div class="flex items-center space-x-3" onclick="window.location.href = '<?php
                    if ($_SESSION['role'] === 'admin') {
                        echo 'relatorio_adm_abastecimento.php';
                    } elseif ($_SESSION['role'] === 'geraladm') {
                        echo 'geral_adm.php';
                    }
                ?>'">
                <i class="fas fa-arrow-left text-lg"></i>
                <h1 class="text-xl font-bold tracking-tight">
                    <?php 
                    if ($secretaria_filtro === 'TODOS') {
                        echo 'Média de Consumo - Todos Veículos';
                    } else {
                        echo 'Média de Consumo - ' . htmlspecialchars($secretaria_filtro);
                    }
                    ?>
                </h1>
            </div>
            <div class="flex items-center space-x-3">
                <span class="text-sm bg-white/10 px-3 py-1 rounded-full font-medium">
                    <?php echo htmlspecialchars($_SESSION['username'] ?? 'Usuário'); ?> (<?php echo strtoupper($user_role); ?>)
                </span>
                <button id="refresh-btn" class="p-2 rounded-full hover:bg-white/10 transition-colors">
                    <i class="fas fa-sync-alt text-lg"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Content -->
    <div class="container mx-auto px-4 py-6">
        <!-- Stats Cards -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-5 mb-6">
            <div class="card stat-card p-5 bg-white">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm text-gray-500 font-medium">Veículos Monitorados</p>
                        <h3 class="text-2xl font-bold mt-1" id="total-veiculos">0</h3>
                    </div>
                    <div class="p-3 rounded-full bg-indigo-50 text-indigo-600">
                        <i class="fas fa-car text-lg"></i>
                    </div>
                </div>
            </div>
            
            <div class="card stat-card p-5 bg-white">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm text-gray-500 font-medium">Melhor Média</p>
                        <h3 class="text-2xl font-bold mt-1" id="melhor-media">0 km/l</h3>
                        <p class="text-xs text-gray-500 mt-1" id="melhor-veiculo">-</p>
                    </div>
                    <div class="p-3 rounded-full bg-green-50 text-green-600">
                        <i class="fas fa-trophy text-lg"></i>
                    </div>
                </div>
            </div>
            
            <div class="card stat-card p-5 bg-white">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm text-gray-500 font-medium">Pior Média</p>
                        <h3 class="text-2xl font-bold mt-1" id="pior-media">0 km/l</h3>
                        <p class="text-xs text-gray-500 mt-1" id="pior-veiculo">-</p>
                    </div>
                    <div class="p-3 rounded-full bg-red-50 text-red-600">
                        <i class="fas fa-exclamation-triangle text-lg"></i>
                    </div>
                </div>
            </div>
            
            <div class="card stat-card p-5 bg-white">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-sm text-gray-500 font-medium">Média Geral</p>
                        <h3 class="text-2xl font-bold mt-1" id="media-geral">0 km/l</h3>
                    </div>
                    <div class="p-3 rounded-full bg-blue-50 text-blue-600">
                        <i class="fas fa-chart-line text-lg"></i>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filter Section -->
        <div class="card p-5 mb-6 bg-white">
            <form id="filter-form" method="post" class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div class="flex-1">
                    <label for="search" class="sr-only">Pesquisar</label>
                    <div class="relative max-w-md">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fas fa-search text-gray-400"></i>
                        </div>
                        <input type="text" id="search" name="search" class="block w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-lg focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300 placeholder-gray-400" placeholder="Pesquisar veículo..." value="<?= isset($_POST['search']) ? htmlspecialchars($_POST['search']) : '' ?>">
                    </div>
                </div>
                <div class="flex flex-wrap gap-3">
                    <?php if ($user_role === 'geraladm'): ?>
                        <select name="secretaria" id="filter-secretaria" class="border border-gray-200 rounded-lg px-4 py-2.5 focus:ring-2 focus:ring-indigo-100 focus:border-indigo-300 text-sm font-medium">
                            <option value="TODOS" <?= $secretaria_filtro === 'TODOS' ? 'selected' : '' ?>>Todas Secretarias</option>
                            <?php foreach($secretarias_map as $secretaria => $sigla): ?>
                                <option value="<?= htmlspecialchars($secretaria) ?>" <?= $secretaria_filtro === $secretaria ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($sigla) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <button type="submit" class="bg-indigo-600 text-white px-4 py-2.5 rounded-lg hover:bg-indigo-700 transition-colors text-sm font-medium flex items-center">
                        <i class="fas fa-filter mr-2"></i> Filtrar
                    </button>
                </div>
            </form>
            
            <!-- Filtros de consumo -->
            <div class="flex flex-wrap gap-2 mt-5">
                <button onclick="filterByConsumo('otimo')" class="filter-btn flex items-center px-3.5 py-1.5 rounded-full bg-green-50 text-green-800 hover:bg-green-100 text-sm font-medium border border-green-100">
                    <span>ÓTIMO: ≥11.00 km/l</span>
                    <span class="bg-green-100 text-green-800 px-2 py-0.5 rounded-full text-xs ml-2" id="otimo-count">0</span>
                </button>
                
                <button onclick="filterByConsumo('bom')" class="filter-btn flex items-center px-3.5 py-1.5 rounded-full bg-yellow-50 text-yellow-800 hover:bg-yellow-100 text-sm font-medium border border-yellow-100">
                    <span>BOM: 7.00-10.99 km/l</span>
                    <span class="bg-yellow-100 text-yellow-800 px-2 py-0.5 rounded-full text-xs ml-2" id="bom-count">0</span>
                </button>
                
                <button onclick="filterByConsumo('ruim')" class="filter-btn flex items-center px-3.5 py-1.5 rounded-full bg-red-50 text-red-800 hover:bg-red-100 text-sm font-medium border border-red-100">
                    <span>RUIM: ≤6.99 km/l</span>
                    <span class="bg-red-100 text-red-800 px-2 py-0.5 rounded-full text-xs ml-2" id="ruim-count">0</span>
                </button>
                
                <button onclick="resetFilter()" class="filter-btn flex items-center px-3.5 py-1.5 rounded-full bg-gray-50 text-gray-800 hover:bg-gray-100 text-sm font-medium border border-gray-200">
                    <span>Todos os veículos</span>
                    <span class="bg-gray-100 text-gray-800 px-2 py-0.5 rounded-full text-xs ml-2" id="total-count">0</span>
                </button>
            </div>
        </div>

        <!-- Vehicles List -->
        <div class="grid grid-cols-1 gap-4" id="vehicles-container">
            <div class="card p-5 text-center text-gray-500">
                <i class="fas fa-spinner fa-spin mr-2"></i> Carregando dados de consumo...
            </div>
        </div>
    </div>

    <script>
        // Variável global para armazenar todos os veículos
        let allVehicles = [];
        let currentFilter = 'all';

        // Função para carregar os dados de consumo
        async function loadConsumoData() {
            try {
                // Obter filtros atuais do formulário
                const formData = new FormData(document.getElementById('filter-form'));
                const secretaria = formData.get('secretaria') || '';
                const searchTerm = formData.get('search')?.toLowerCase() || '';
                
                // Construir URL da API
                let url = 'get_consumo_veiculos.php?';
                
                // Adicionar filtro de secretaria se não for "TODOS"
                if (secretaria && secretaria !== 'TODOS') {
                    url += `secretaria=${encodeURIComponent(secretaria)}&`;
                }
                
                // Adicionar parâmetros de autenticação
                url += `user_role=<?php echo $user_role; ?>&user_secretaria=<?php echo urlencode($user_secretaria ?? ''); ?>`;
                
                const response = await fetch(url);
                if (!response.ok) throw new Error(`Erro HTTP: ${response.status}`);
                
                const data = await response.json();
                if (data.error) throw new Error(data.error);
                
                // Aplicar filtro de pesquisa localmente se houver termo
                if (searchTerm.length >= 3) {
                    allVehicles = data.filter(vehicle => 
                        (vehicle.prefixo && vehicle.prefixo.toLowerCase().includes(searchTerm)) ||
                        (vehicle.secretaria && vehicle.secretaria.toLowerCase().includes(searchTerm)) ||
                        (vehicle.veiculo && vehicle.veiculo.toLowerCase().includes(searchTerm))
                    );
                } else {
                    allVehicles = data;
                }
                
                // Ordenar por consumo (melhor primeiro)
                allVehicles.sort((a, b) => b.media_consumo - a.media_consumo);
                
                updateVehicleDisplay();
                updateStats();
                
            } catch (error) {
                console.error("Erro ao carregar dados:", error);
                document.getElementById('vehicles-container').innerHTML = `
                    <div class="card p-5 text-center text-red-500">
                        <i class="fas fa-exclamation-triangle mr-2"></i>
                        ${error.message || 'Erro ao carregar dados'}
                    </div>`;
            }
        }

        // Função para determinar a classe de consumo
        function getConsumoClass(media) {
            if (media >= 11) return 'consumo-otimo';
            if (media >= 7) return 'consumo-bom';
            return 'consumo-ruim';
        }

        // Função para determinar o texto de consumo
        function getConsumoText(media) {
            if (media >= 11) return 'ÓTIMO';
            if (media >= 7) return 'BOM';
            return 'RUIM';
        }

        // Função para exibir os veículos
        function updateVehicleDisplay(filteredVehicles = null) {
            const vehiclesToDisplay = filteredVehicles || allVehicles;
            const container = document.getElementById('vehicles-container');
            
            if (vehiclesToDisplay.length === 0) {
                container.innerHTML = `
                    <div class="card p-8 text-center">
                        <i class="fas fa-car text-4xl text-gray-300 mb-4"></i>
                        <h3 class="text-lg font-medium text-gray-700">Nenhum veículo encontrado</h3>
                        <p class="text-gray-500">Nenhum veículo corresponde aos critérios de filtro</p>
                    </div>`;
                return;
            }
            
            container.innerHTML = vehiclesToDisplay.map(vehicle => {
                const description = vehicle.veiculo || 'Veículo municipal';
                
                return `
                <div class="card vehicle-card p-5 hover:border-indigo-200">
                    <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                        <div class="flex-1">
                            <h3 class="vehicle-prefixo">${vehicle.prefixo || 'N/I'}</h3>
                            <p class="vehicle-desc mt-1">${description}</p>
                            <p class="text-sm text-gray-500 mt-1">
                                <i class="fas fa-building mr-1"></i>
                                ${vehicle.secretaria || 'Secretaria não informada'}
                            </p>
                        </div>
                        <div class="flex flex-col items-end">
                            <span class="consumo-badge ${getConsumoClass(vehicle.media_consumo)}">
                                ${vehicle.media_consumo.toFixed(2)} km/l • ${getConsumoText(vehicle.media_consumo)}
                            </span>
                            <p class="text-xs text-gray-500 mt-2">
                                <i class="fas fa-gas-pump mr-1"></i>
                                ${vehicle.total_abastecimentos || 0} abastecimento${vehicle.total_abastecimentos !== 1 ? 's' : ''}
                            </p>
                        </div>
                    </div>
                </div>`;
            }).join('');
        }

        // Função para filtrar veículos por categoria de consumo
        function filterByConsumo(categoria) {
            currentFilter = categoria;
            let filtered = [];
            
            switch(categoria) {
                case 'otimo':
                    filtered = allVehicles.filter(v => v.media_consumo >= 11);
                    break;
                case 'bom':
                    filtered = allVehicles.filter(v => v.media_consumo >= 7 && v.media_consumo < 11);
                    break;
                case 'ruim':
                    filtered = allVehicles.filter(v => v.media_consumo < 7);
                    break;
                default:
                    filtered = allVehicles;
            }
            
            updateVehicleDisplay(filtered);
        }

        // Função para resetar o filtro
        function resetFilter() {
            currentFilter = 'all';
            updateVehicleDisplay();
        }

        // Função para atualizar as estatísticas
        function updateStats() {
            const total = allVehicles.length;
            const mediaGeral = total > 0 ? 
                (allVehicles.reduce((sum, v) => sum + (v.media_consumo || 0), 0) / total).toFixed(2) : 
                '0.00';
            
            // Contagens por categoria
            const otimoCount = allVehicles.filter(v => v.media_consumo >= 11).length;
            const bomCount = allVehicles.filter(v => v.media_consumo >= 7 && v.media_consumo < 11).length;
            const ruimCount = allVehicles.filter(v => v.media_consumo < 7).length;
            
            // Atualizar a UI
            document.getElementById('total-veiculos').textContent = total;
            document.getElementById('media-geral').textContent = `${mediaGeral} km/l`;
            document.getElementById('total-count').textContent = total;
            document.getElementById('otimo-count').textContent = otimoCount;
            document.getElementById('bom-count').textContent = bomCount;
            document.getElementById('ruim-count').textContent = ruimCount;
            
            // Atualizar melhores/piores médias
            if (total > 0) {
                const melhor = allVehicles[0];
                const pior = allVehicles[total - 1];
                
                document.getElementById('melhor-media').textContent = `${melhor.media_consumo.toFixed(2)} km/l`;
                document.getElementById('melhor-veiculo').textContent = melhor.prefixo || 'N/I';
                document.getElementById('pior-media').textContent = `${pior.media_consumo.toFixed(2)} km/l`;
                document.getElementById('pior-veiculo').textContent = pior.prefixo || 'N/I';
            }
        }

        // Event Listeners
        document.getElementById('refresh-btn').addEventListener('click', loadConsumoData);
        document.getElementById('filter-form').addEventListener('submit', function(e) {
            e.preventDefault();
            loadConsumoData();
        });
        
        // Listener para o campo de pesquisa
        document.getElementById('search').addEventListener('input', function(e) {
            if (allVehicles.length > 0 && e.target.value.length >= 3) {
                const term = e.target.value.toLowerCase();
                const filtered = allVehicles.filter(vehicle => 
                    (vehicle.prefixo && vehicle.prefixo.toLowerCase().includes(term)) ||
                    (vehicle.secretaria && vehicle.secretaria.toLowerCase().includes(term)) ||
                    (vehicle.veiculo && vehicle.veiculo.toLowerCase().includes(term))
                );
                updateVehicleDisplay(filtered);
            } else if (e.target.value.length === 0) {
                updateVehicleDisplay();
            }
        });

        // Carregar dados iniciais
        loadConsumoData();
    </script>
</body>
</html>