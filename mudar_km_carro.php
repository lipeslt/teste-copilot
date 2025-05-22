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

// Variáveis para registrar o usuário logado e data atual
$usuario_logado = $_SESSION['username'] ?? 'desconhecido';
$data_hora_atual = date('Y-m-d H:i:s');

// Função para registrar alterações no histórico - Versão simplificada
function registrarAlteracao($conn, $registro_id, $admin_id, $campo_alterado, $valor_antigo, $valor_novo) {
    // Buscar o nome do admin na tabela usuarios
    $query_admin = "SELECT name FROM usuarios WHERE id = :admin_id";
    $stmt_admin = $conn->prepare($query_admin);
    $stmt_admin->bindParam(':admin_id', $admin_id);
    $stmt_admin->execute();
    $admin = $stmt_admin->fetch(PDO::FETCH_ASSOC);
    $admin_name = $admin['name'] ?? 'Desconhecido';
    
    // Data e hora atual
    $data_hora = date('Y-m-d H:i:s');
    $login = $_SESSION['username'] ?? 'sistema';

    // Verificar tipos e converter para string se necessário
    if (is_array($valor_antigo) || is_object($valor_antigo)) {
        $valor_antigo = json_encode($valor_antigo, JSON_UNESCAPED_UNICODE);
    }
    
    if (is_array($valor_novo) || is_object($valor_novo)) {
        $valor_novo = json_encode($valor_novo, JSON_UNESCAPED_UNICODE);
    }
    
    // Truncar valores muito longos
    $valor_antigo = substr((string)$valor_antigo, 0, 65000);
    $valor_novo = substr((string)$valor_novo, 0, 65000);

    try {
        $query = "INSERT INTO historico_alteracoes (registro_id, admin_id, admin_name, campo_alterado, valor_antigo, valor_novo)
                VALUES (:registro_id, :admin_id, :admin_name, :campo_alterado, :valor_antigo, :valor_novo)";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':registro_id', $registro_id);
        $stmt->bindParam(':admin_id', $admin_id);
        $stmt->bindParam(':admin_name', $admin_name);
        $stmt->bindParam(':campo_alterado', $campo_alterado);
        $stmt->bindParam(':valor_antigo', $valor_antigo);
        $stmt->bindParam(':valor_novo', $valor_novo);
        return $stmt->execute();
    } catch (PDOException $e) {
        error_log("Erro ao registrar alteração: " . $e->getMessage());
        return false;
    }
}

// Função para registrar adição completa
function registrarAdicaoCompleta($conn, $registro_id, $admin_id, $dados_registro) {
    // Registrar a adição como um único registro com todos os dados
    return registrarAlteracao($conn, $registro_id, $admin_id, 'ADICAO_COMPLETA', 'N/A', json_encode($dados_registro));
}

// Função para registrar exclusão completa
function registrarExclusaoCompleta($conn, $registro_id, $admin_id, $dados_registro) {
    // Registrar a exclusão como um único registro com todos os dados
    return registrarAlteracao($conn, $registro_id, $admin_id, 'EXCLUSAO_COMPLETA', json_encode($dados_registro), 'N/A');
}

// Verificar e remover a restrição de chave estrangeira se existir
try {
    // Verificar se existe restrição de chave estrangeira
    $query_check = "
        SELECT CONSTRAINT_NAME
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'historico_alteracoes'
        AND REFERENCED_TABLE_NAME = 'registros'
        AND REFERENCED_COLUMN_NAME = 'id'
    ";
    $stmt_check = $conn->prepare($query_check);
    $stmt_check->execute();
    $constraint = $stmt_check->fetch(PDO::FETCH_ASSOC);
    
    if ($constraint) {
        // Remover a restrição de chave estrangeira
        $query_drop = "ALTER TABLE historico_alteracoes DROP FOREIGN KEY " . $constraint['CONSTRAINT_NAME'];
        $conn->exec($query_drop);
    }
} catch (PDOException $e) {
    // Ignorar erros, apenas seguir em frente
}

// Processar atualização de KM se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registro_id'])) {
    $registro_id = $_POST['registro_id'];
    $km_inicial_novo = $_POST['km_inicial'];
    $km_final_novo = $_POST['km_final'];
    $admin_id = $_SESSION['user_id'];

    // Buscar valores atuais antes da alteração
    $query = "SELECT * FROM registros WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $registro_id);
    $stmt->execute();
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($registro) {
        $registro_antigo = $registro; // Salvar registro completo antes da alteração
        
        // Atualizar os valores no banco de dados
        $query = "UPDATE registros SET km_inicial = :km_inicial, km_final = :km_final WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':km_inicial', $km_inicial_novo);
        $stmt->bindParam(':km_final', $km_final_novo);
        $stmt->bindParam(':id', $registro_id);

        if ($stmt->execute()) {
            // Buscar o registro atualizado
            $query = "SELECT * FROM registros WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':id', $registro_id);
            $stmt->execute();
            $registro_novo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Registrar alterações específicas
            if ($registro['km_inicial'] != $km_inicial_novo) {
                registrarAlteracao($conn, $registro_id, $admin_id, 'km_inicial', $registro['km_inicial'], $km_inicial_novo);
            }

            if ($registro['km_final'] != $km_final_novo) {
                registrarAlteracao($conn, $registro_id, $admin_id, 'km_final', $registro['km_final'], $km_final_novo);
            }
            
            // Registrar alteração do registro completo
            registrarAlteracao(
                $conn, 
                $registro_id, 
                $admin_id, 
                'ALTERACAO_COMPLETA', 
                json_encode($registro_antigo), 
                json_encode($registro_novo)
            );

            $_SESSION['mensagem'] = "Registro atualizado com sucesso!";
            $_SESSION['tipo_mensagem'] = "success";
        } else {
            $_SESSION['mensagem'] = "Erro ao atualizar registro.";
            $_SESSION['tipo_mensagem'] = "error";
        }
    }

    header("Location: mudar_km_carro.php?" . $_SERVER['QUERY_STRING']);
    exit;
}

// Processar adição de novo registro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'adicionar') {
    $nome = trim($_POST['nome']);
    $secretaria = trim($_POST['secretaria']);
    $veiculo_id = trim($_POST['veiculo_id']);
    $km_inicial = floatval($_POST['km_inicial_novo']);
    $km_final = !empty($_POST['km_final_novo']) ? floatval($_POST['km_final_novo']) : null;
    $destino = trim($_POST['destino']);
    $ponto_parada = !empty($_POST['ponto_parada']) ? trim($_POST['ponto_parada']) : null;
    $data = $_POST['data'];
    $hora = $_POST['hora'];
    $hora_final = !empty($_POST['hora_final']) ? $_POST['hora_final'] : null;
    $admin_id = $_SESSION['user_id'];
    
    // Buscar placa e nome do veículo - Usando a coluna tipo como nome_veiculo
    $query_veiculo = "SELECT placa, tipo as nome_veiculo FROM veiculos WHERE veiculo = :veiculo_id";
    $stmt_veiculo = $conn->prepare($query_veiculo);
    $stmt_veiculo->bindParam(':veiculo_id', $veiculo_id);
    $stmt_veiculo->execute();
    $veiculo = $stmt_veiculo->fetch(PDO::FETCH_ASSOC);
    
    if ($veiculo) {
        $placa = $veiculo['placa'];
        $nome_veiculo = $veiculo['nome_veiculo'];
        
        try {
            // Iniciar transação
            $conn->beginTransaction();
            
            // Preparar dados do registro para inserção
            $dados_registro = [
                'nome' => $nome,
                'secretaria' => $secretaria,
                'veiculo_id' => $veiculo_id,
                'placa' => $placa,
                'nome_veiculo' => $nome_veiculo,
                'km_inicial' => $km_inicial,
                'km_final' => $km_final,
                'destino' => $destino,
                'ponto_parada' => $ponto_parada,
                'data' => $data,
                'hora' => $hora,
                'hora_final' => $hora_final
            ];
            
            // Inserir novo registro
            $query = "INSERT INTO registros (nome, secretaria, veiculo_id, placa, nome_veiculo, km_inicial, km_final, 
                     destino, ponto_parada, data, hora, hora_final) 
                     VALUES (:nome, :secretaria, :veiculo_id, :placa, :nome_veiculo, :km_inicial, :km_final, 
                     :destino, :ponto_parada, :data, :hora, :hora_final)";
            $stmt = $conn->prepare($query);
            
            foreach ($dados_registro as $campo => $valor) {
                $stmt->bindValue(':' . $campo, $valor);
            }
            
            if ($stmt->execute()) {
                $registro_id = $conn->lastInsertId();
                
                // Buscar o registro completo inserido
                $query = "SELECT * FROM registros WHERE id = :id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':id', $registro_id);
                $stmt->execute();
                $registro_inserido = $stmt->fetch(PDO::FETCH_ASSOC);
                
                // Registrar a adição completa no histórico
                registrarAdicaoCompleta($conn, $registro_id, $admin_id, $registro_inserido);
                
                $conn->commit();
                $_SESSION['mensagem'] = "Novo registro adicionado com sucesso!";
                $_SESSION['tipo_mensagem'] = "success";
            } else {
                $conn->rollBack();
                $_SESSION['mensagem'] = "Erro ao adicionar novo registro.";
                $_SESSION['tipo_mensagem'] = "error";
            }
        } catch (PDOException $e) {
            $conn->rollBack();
            $_SESSION['mensagem'] = "Erro ao adicionar registro: " . $e->getMessage();
            $_SESSION['tipo_mensagem'] = "error";
        }
    } else {
        $_SESSION['mensagem'] = "Veículo não encontrado.";
        $_SESSION['tipo_mensagem'] = "error";
    }
    
    header("Location: mudar_km_carro.php?" . $_SERVER['QUERY_STRING']);
    exit;
}

// Processar exclusão de registro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'excluir') {
    $registro_id = $_POST['registro_id_excluir'];
    $admin_id = $_SESSION['user_id'];
    
    try {
        // Iniciar transação
        $conn->beginTransaction();
        
        // Buscar informações do registro antes de excluir
        $query = "SELECT * FROM registros WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $registro_id);
        $stmt->execute();
        $registro = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($registro) {
            // Registrar a exclusão completa no histórico antes de excluir o registro
            registrarExclusaoCompleta($conn, $registro_id, $admin_id, $registro);
            
            // Excluir o registro
            $query = "DELETE FROM registros WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':id', $registro_id);
            
            if ($stmt->execute()) {
                $conn->commit();
                $_SESSION['mensagem'] = "Registro excluído com sucesso!";
                $_SESSION['tipo_mensagem'] = "success";
            } else {
                $conn->rollBack();
                $_SESSION['mensagem'] = "Erro ao excluir registro.";
                $_SESSION['tipo_mensagem'] = "error";
            }
        } else {
            $conn->rollBack();
            $_SESSION['mensagem'] = "Registro não encontrado.";
            $_SESSION['tipo_mensagem'] = "error";
        }
    } catch (PDOException $e) {
        $conn->rollBack();
        $_SESSION['mensagem'] = "Erro ao excluir registro: " . $e->getMessage();
        $_SESSION['tipo_mensagem'] = "error";
    }
    
    header("Location: mudar_km_carro.php?" . $_SERVER['QUERY_STRING']);
    exit;
}

// Buscar registros com base nos filtros
$codigo_veiculo = $_GET['codigo_veiculo'] ?? '';
$data_inicial = $_GET['data_inicial'] ?? null;
$data_final = $_GET['data_final'] ?? null;

// Função para buscar registros
function buscarRegistros($conn, $codigo_veiculo, $data_inicial, $data_final, $secretaria_admin_db, $role) {
    $query = "SELECT r.*, v.veiculo, v.placa, v.secretaria
              FROM registros r
              JOIN veiculos v ON r.veiculo_id = v.veiculo
              WHERE 1=1";

    $params = [];

    if ($codigo_veiculo) {
        $query .= " AND r.veiculo_id = :veiculo_id";
        $params[':veiculo_id'] = $codigo_veiculo;
    }

    if ($data_inicial && $data_final) {
        $query .= " AND r.data BETWEEN :data_inicial AND :data_final";
        $params[':data_inicial'] = $data_inicial;
        $params[':data_final'] = $data_final;
    }

    // Filtro por secretaria se não for geraladm
    if ($role !== 'geraladm' && $secretaria_admin_db) {
        $query .= " AND v.secretaria = :secretaria";
        $params[':secretaria'] = $secretaria_admin_db;
    }

    $query .= " ORDER BY r.data DESC, r.hora DESC";

    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Função para buscar secretarias disponíveis
function buscarSecretarias($conn) {
    $query = "SELECT DISTINCT secretaria FROM veiculos ORDER BY secretaria";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Mapeamento de secretarias
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

// Secretaria do usuário logado
$secretaria_admin = $_SESSION['secretaria'] ?? '';
$secretaria_admin_db = isset($secretarias_map[$secretaria_admin]) ? $secretarias_map[$secretaria_admin] : null;
$role = $_SESSION['role'] ?? '';

// Buscar registros
$registros = buscarRegistros($conn, $codigo_veiculo, $data_inicial, $data_final, $secretaria_admin_db, $role);

// Buscar todas as secretarias disponíveis para o formulário de adição
$secretarias_disponiveis = buscarSecretarias($conn);

// Obter a data atual para o formulário de adição
$data_atual = date('Y-m-d');
$hora_atual = date('H:i');
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editar Quilometragem</title>
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
            padding: 12px 15px;
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #E5E7EB;
        }

        tr:hover td {
            background-color: #F9FAFB;
        }

        .edit-icon, .delete-icon {
            cursor: pointer;
            transition: all 0.2s;
        }

        .edit-icon {
            color: #4F46E5;
        }

        .delete-icon {
            color: #EF4444;
        }

        .edit-icon:hover, .delete-icon:hover {
            transform: scale(1.2);
        }

        .modal {
            display: none;
            position: fixed;
            z-index: 50;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            overflow: auto;
            background-color: rgba(0,0,0,0.4);
        }

        .modal-content {
            background-color: #fefefe;
            margin: 10% auto;
            padding: 20px;
            border-radius: 8px;
            width: 80%;
            max-width: 500px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
        }

        .close:hover {
            color: black;
        }

        .btn-primary {
            background-color: #4F46E5;
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            transition: all 0.2s;
        }

        .btn-primary:hover {
            background-color: #4338CA;
        }

        .btn-danger {
            background-color: #EF4444;
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            transition: all 0.2s;
        }

        .btn-danger:hover {
            background-color: #DC2626;
        }

        .btn-secondary {
            background-color: #6B7280;
            color: white;
            padding: 8px 16px;
            border-radius: 6px;
            transition: all 0.2s;
        }

        .btn-secondary:hover {
            background-color: #4B5563;
        }

        .success-message {
            background-color: #D1FAE5;
            color: #065F46;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 16px;
        }

        .error-message {
            background-color: #FEE2E2;
            color: #B91C1C;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 16px;
        }

        .suggestions-container {
            position: relative;
        }

        .suggestions-list {
            position: absolute;
            z-index: 1000;
            width: 100%;
            max-height: 250px;
            overflow-y: auto;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
            display: none;
        }

        .suggestion-item {
            padding: 8px 12px;
            cursor: pointer;
            border-bottom: 1px solid #f3f3f3;
        }

        .suggestion-item:last-child {
            border-bottom: none;
        }

        .suggestion-item:hover {
            background-color: #f0f7ff;
        }
    </style>
</head>
<body class="min-h-screen">
        <!-- App Bar -->
        <div class="bg-indigo-600 text-white shadow-md">
            <div class="container mx-auto px-4 py-4 flex justify-between items-center">
                <div class="flex items-center space-x-3">
                    <h1 class="text-xl font-bold">Editar Quilometragem</h1>
                </div>
                <div class="flex items-center space-x-4">
                    <button onclick="abrirModalAdicao()" class="flex items-center space-x-2 bg-white/20 hover:bg-white/30 px-4 py-2 rounded-lg transition mr-2">
                        <i class="fas fa-plus"></i>
                        <span>Novo Registro</span>
                    </button>
                    <a href="<?= $_SESSION['role'] === 'geraladm' ? 'geral_adm.php' : 'admin.php' ?>"
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
        <div class="card p-6 mb-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Filtros</h2>
            <form method="get" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="suggestions-container">
                    <label for="codigo_veiculo" class="block text-sm font-medium text-gray-700 mb-2">Código do Veículo</label>
                    <input type="text" id="codigo_veiculo" name="codigo_veiculo" class="w-full px-3 py-2 border rounded-md"
                           value="<?= htmlspecialchars($codigo_veiculo) ?>" placeholder="Ex: C-32"
                           oninput="buscarVeiculos(this.value)">
                    <div id="suggestions" class="suggestions-list"></div>
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
                <div class="md:col-span-3">
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-search mr-2"></i> Pesquisar
                    </button>
                </div>
            </form>
        </div>

        <!-- Mensagens -->
        <?php if (isset($_SESSION['mensagem'])): ?>
            <div class="<?= $_SESSION['tipo_mensagem'] === 'success' ? 'success-message' : 'error-message' ?>">
                <?= $_SESSION['mensagem'] ?>
            </div>
            <?php unset($_SESSION['mensagem']); unset($_SESSION['tipo_mensagem']); ?>
        <?php endif; ?>

        <!-- Tabela de Registros -->
        <div class="card p-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Registros</h2>

            <?php if (count($registros) > 0): ?>
                <div class="table-container">
                    <table>
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Veículo</th>
                                <th>Placa</th>
                                <th>Secretaria</th>
                                <th>Motorista</th>
                                <th>KM Inicial</th>
                                <th>KM Final</th>
                                <th>Total KM</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($registros as $registro):
                                $km_percorrido = $registro['km_final'] - $registro['km_inicial'];
                            ?>
                                <tr>
                                    <td><?= htmlspecialchars($registro['data']) ?></td>
                                    <td><?= htmlspecialchars($registro['veiculo']) ?></td>
                                    <td><?= htmlspecialchars($registro['placa']) ?></td>
                                    <td><?= htmlspecialchars($registro['secretaria']) ?></td>
                                    <td><?= htmlspecialchars($registro['nome']) ?></td>
                                    <td><?= htmlspecialchars($registro['km_inicial']) ?></td>
                                    <td><?= htmlspecialchars($registro['km_final']) ?></td>
                                    <td><?= $km_percorrido ?></td>
                                    <td class="flex space-x-2">
                                        <i class="fas fa-pencil-alt edit-icon"
                                           onclick="abrirModalEdicao(
                                               '<?= $registro['id'] ?>',
                                               '<?= $registro['km_inicial'] ?>',
                                               '<?= $registro['km_final'] ?>',
                                               '<?= $registro['veiculo'] ?>',
                                               '<?= $registro['data'] ?>'
                                           )"></i>
                                        <i class="fas fa-trash-alt delete-icon"
                                           onclick="abrirModalExclusao(
                                               '<?= $registro['id'] ?>',
                                               '<?= $registro['veiculo'] ?>',
                                               '<?= $registro['data'] ?>',
                                               '<?= $registro['nome'] ?>'
                                           )"></i>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-car text-4xl text-gray-300 mb-4"></i>
                    <h4 class="text-lg font-medium text-gray-700">Nenhum registro encontrado</h4>
                    <p class="text-gray-500 mt-2">Não foram encontrados registros para os filtros selecionados.</p>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal de Edição -->
    <div id="modalEdicao" class="modal">
        <div class="modal-content">
            <span class="close" onclick="fecharModalEdicao()">&times;</span>
            <h2 class="text-xl font-semibold mb-4">Editar Quilometragem</h2>
            <form id="formEdicao" method="post">
                <input type="hidden" id="registro_id" name="registro_id">

                <div class="mb-4">
                    <p class="text-sm text-gray-600 mb-1">Veículo</p>
                    <p id="modal_veiculo" class="font-medium"></p>
                </div>

                <div class="mb-4">
                    <p class="text-sm text-gray-600 mb-1">Data</p>
                    <p id="modal_data" class="font-medium"></p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="modal_km_inicial" class="block text-sm font-medium text-gray-700 mb-2">KM Inicial</label>
                        <input type="number" id="modal_km_inicial" name="km_inicial" class="w-full px-3 py-2 border rounded-md" required>
                    </div>
                    <div>
                        <label for="modal_km_final" class="block text-sm font-medium text-gray-700 mb-2">KM Final</label>
                        <input type="number" id="modal_km_final" name="km_final" class="w-full px-3 py-2 border rounded-md" required>
                    </div>
                </div>

                <div class="flex justify-end space-x-2">
                    <button type="button" onclick="fecharModalEdicao()" class="px-4 py-2 border rounded-md">
                        Cancelar
                    </button>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-save mr-2"></i> Salvar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal de Exclusão -->
    <div id="modalExclusao" class="modal">
        <div class="modal-content">
            <span class="close" onclick="fecharModalExclusao()">&times;</span>
            <h2 class="text-xl font-semibold mb-4">Confirmar Exclusão</h2>
            <form id="formExclusao" method="post">
                <input type="hidden" name="action" value="excluir">
                <input type="hidden" id="registro_id_excluir" name="registro_id_excluir">

                <div class="mb-4">
                    <p class="text-sm text-gray-600 mb-1">Deseja realmente excluir este registro?</p>
                    <p id="exclusao_veiculo" class="font-medium"></p>
                    <p id="exclusao_data" class="font-medium"></p>
                    <p id="exclusao_motorista" class="font-medium"></p>
                </div>

                <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-4">
                    <div class="flex">
                        <div class="flex-shrink-0">
                            <i class="fas fa-exclamation-triangle text-yellow-400"></i>
                        </div>
                        <div class="ml-3">
                            <p class="text-sm text-yellow-700">
                                Esta ação não pode ser desfeita. Todos os dados associados a este registro serão permanentemente removidos.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end space-x-2">
                    <button type="button" onclick="fecharModalExclusao()" class="px-4 py-2 border rounded-md">
                        Cancelar
                    </button>
                    <button type="submit" class="btn-danger">
                        <i class="fas fa-trash-alt mr-2"></i> Excluir
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal de Adição -->
    <div id="modalAdicao" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <span class="close" onclick="fecharModalAdicao()">&times;</span>
            <h2 class="text-xl font-semibold mb-4">Adicionar Novo Registro</h2>
            <form id="formAdicao" method="post">
                <input type="hidden" name="action" value="adicionar">

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="suggestions-container">
                        <label for="nome" class="block text-sm font-medium text-gray-700 mb-2">Nome do Motorista</label>
                        <input type="text" id="nome" name="nome" class="w-full px-3 py-2 border rounded-md" 
                               placeholder="Digite nome, CPF ou email" required oninput="buscarUsuarios(this.value)">
                        <input type="hidden" id="usuario_id" name="usuario_id" value="">
                        <div id="suggestions_usuarios" class="suggestions-list"></div>
                    </div>
                    <div>
                        <label for="secretaria" class="block text-sm font-medium text-gray-700 mb-2">Secretaria</label>
                        <select id="secretaria" name="secretaria" class="w-full px-3 py-2 border rounded-md" required>
                            <option value="">Selecione a secretaria</option>
                            <?php foreach ($secretarias_disponiveis as $secretaria): ?>
                                <option value="<?= htmlspecialchars($secretaria) ?>"><?= htmlspecialchars($secretaria) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div class="suggestions-container">
                        <label for="veiculo_id" class="block text-sm font-medium text-gray-700 mb-2">Código do Veículo</label>
                        <input type="text" id="veiculo_id" name="veiculo_id" class="w-full px-3 py-2 border rounded-md" 
                               placeholder="Ex: C-32" required oninput="buscarVeiculosAdicao(this.value)">
                        <div id="suggestions_adicao" class="suggestions-list"></div>
                    </div>
                    <div>
                        <label for="destino" class="block text-sm font-medium text-gray-700 mb-2">Destino</label>
                        <input type="text" id="destino" name="destino" class="w-full px-3 py-2 border rounded-md" required>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="ponto_parada" class="block text-sm font-medium text-gray-700 mb-2">Ponto de Parada (opcional)</label>
                        <input type="text" id="ponto_parada" name="ponto_parada" class="w-full px-3 py-2 border rounded-md">
                    </div>
                    <div>
                        <label for="data" class="block text-sm font-medium text-gray-700 mb-2">Data</label>
                        <input type="date" id="data" name="data" class="w-full px-3 py-2 border rounded-md" 
                               value="<?= $data_atual ?>" required>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div>
                        <label for="hora" class="block text-sm font-medium text-gray-700 mb-2">Hora Inicial</label>
                        <input type="time" id="hora" name="hora" class="w-full px-3 py-2 border rounded-md" 
                               value="<?= $hora_atual ?>" required>
                    </div>
                    <div>
                        <label for="hora_final" class="block text-sm font-medium text-gray-700 mb-2">Hora Final (opcional)</label>
                        <input type="time" id="hora_final" name="hora_final" class="w-full px-3 py-2 border rounded-md">
                    </div>
                    <div>
                        <label for="km_inicial_novo" class="block text-sm font-medium text-gray-700 mb-2">KM Inicial</label>
                        <input type="number" id="km_inicial_novo" name="km_inicial_novo" class="w-full px-3 py-2 border rounded-md" required>
                    </div>
                </div>

                <div class="mb-4">
                    <label for="km_final_novo" class="block text-sm font-medium text-gray-700 mb-2">KM Final (opcional)</label>
                    <input type="number" id="km_final_novo" name="km_final_novo" class="w-full px-3 py-2 border rounded-md">
                </div>

                <div class="flex justify-end space-x-2">
                    <button type="button" onclick="fecharModalAdicao()" class="px-4 py-2 border rounded-md">
                        Cancelar
                    </button>
                    <button type="submit" class="btn-primary">
                        <i class="fas fa-plus mr-2"></i> Adicionar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Funções para o modal de edição
        function abrirModalEdicao(id, km_inicial, km_final, veiculo, data) {
            document.getElementById('registro_id').value = id;
            document.getElementById('modal_km_inicial').value = km_inicial;
            document.getElementById('modal_km_final').value = km_final;
            document.getElementById('modal_veiculo').textContent = veiculo;
            document.getElementById('modal_data').textContent = data;

            document.getElementById('modalEdicao').style.display = 'block';
        }

        function fecharModalEdicao() {
            document.getElementById('modalEdicao').style.display = 'none';
        }

        // Funções para o modal de exclusão
        function abrirModalExclusao(id, veiculo, data, motorista) {
            document.getElementById('registro_id_excluir').value = id;
            document.getElementById('exclusao_veiculo').textContent = 'Veículo: ' + veiculo;
            document.getElementById('exclusao_data').textContent = 'Data: ' + data;
            document.getElementById('exclusao_motorista').textContent = 'Motorista: ' + motorista;

            document.getElementById('modalExclusao').style.display = 'block';
        }

        function fecharModalExclusao() {
            document.getElementById('modalExclusao').style.display = 'none';
        }

        // Funções para o modal de adição
        function abrirModalAdicao() {
            document.getElementById('modalAdicao').style.display = 'block';
        }

        function fecharModalAdicao() {
            document.getElementById('modalAdicao').style.display = 'none';
        }

        // Fechar os modais se clicar fora deles
        window.onclick = function(event) {
            if (event.target == document.getElementById('modalEdicao')) {
                fecharModalEdicao();
            }
            if (event.target == document.getElementById('modalExclusao')) {
                fecharModalExclusao();
            }
            if (event.target == document.getElementById('modalAdicao')) {
                fecharModalAdicao();
            }
        }

        // Função para buscar veículos por prefixo usando buscar_veiculos.php
        function buscarVeiculos(prefixo) {
            if (prefixo.length >= 1) {
                $.get('buscar_veiculos.php', { termo: prefixo }, function(data) {
                    const suggestions = $('#suggestions');
                    suggestions.empty();

                    if (data.length > 0) {
                        data.forEach(function(veiculo) {
                            suggestions.append(
                                `<div class="suggestion-item" onclick="selecionarVeiculo('${veiculo.veiculo}')">
                                    ${veiculo.veiculo} - ${veiculo.placa} (${veiculo.tipo})
                                 </div>`
                            );
                        });
                        suggestions.show();
                    } else {
                        suggestions.hide();
                    }
                }).fail(function() {
                    $('#suggestions').hide();
                });
            } else {
                $('#suggestions').hide();
            }
        }

        // Função para selecionar um veículo da lista de sugestões
        function selecionarVeiculo(veiculo) {
            $('#codigo_veiculo').val(veiculo);
            $('#suggestions').hide();
        }

        // Função para buscar veículos por prefixo para o modal de adição
        function buscarVeiculosAdicao(prefixo) {
            if (prefixo.length >= 1) {
                $.get('buscar_veiculos.php', { termo: prefixo }, function(data) {
                    const suggestions = $('#suggestions_adicao');
                    suggestions.empty();

                    if (data.length > 0) {
                        data.forEach(function(veiculo) {
                            suggestions.append(
                                `<div class="suggestion-item" onclick="selecionarVeiculoAdicao('${veiculo.veiculo}', '${veiculo.secretaria}')">
                                    ${veiculo.veiculo} - ${veiculo.placa} (${veiculo.tipo})
                                 </div>`
                            );
                        });
                        suggestions.show();
                    } else {
                        suggestions.hide();
                    }
                }).fail(function() {
                    $('#suggestions_adicao').hide();
                });
            } else {
                $('#suggestions_adicao').hide();
            }
        }

        // Função para selecionar um veículo da lista de sugestões para o modal de adição
        function selecionarVeiculoAdicao(veiculo, secretaria) {
            $('#veiculo_id').val(veiculo);
            $('#suggestions_adicao').hide();
            
            // Preencher a secretaria automaticamente se disponível
            if (secretaria) {
                $('#secretaria').val(secretaria);
            }
            
            // Buscar último KM registrado para esse veículo
            $.get('buscar_ultimo_km.php', { veiculo_id: veiculo }, function(data) {
                if (data && data.km_final) {
                    $('#km_inicial_novo').val(data.km_final);
                }
            });
        }

        // Função para buscar usuários - VERSÃO SIMPLIFICADA
        function buscarUsuarios(termo) {
            console.log("Buscando usuários com termo: " + termo);
            
            if (termo.length >= 2) {
                $.ajax({
                    url: 'buscar_usuarios.php',
                    type: 'GET',
                    data: { termo: termo },
                    dataType: 'json',
                    success: function(data) {
                        console.log("Dados recebidos:", data);
                        
                        const suggestions = $('#suggestions_usuarios');
                        suggestions.empty();

                        if (data && data.length > 0) {
                            data.forEach(function(usuario) {
                                suggestions.append(
                                    `<div class="suggestion-item" onclick="selecionarUsuario('${usuario.name}')">
                                        ${usuario.name}
                                     </div>`
                                );
                            });
                            suggestions.show();
                        } else {
                            suggestions.hide();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("Erro na busca de usuários:", error);
                        console.error("Status:", status);
                        console.error("Resposta:", xhr.responseText);
                        $('#suggestions_usuarios').hide();
                    }
                });
            } else {
                $('#suggestions_usuarios').hide();
            }
        }

        // Função simplificada para selecionar um usuário
        function selecionarUsuario(nome) {
            console.log("Selecionando usuário:", nome);
            $('#nome').val(nome);
            $('#suggestions_usuarios').hide();
        }

        // Esconder sugestões quando clicar em outro lugar
        $(document).click(function(event) {
            if (!$(event.target).closest('.suggestions-container').length) {
                $('.suggestions-list').hide();
            }
        });
    </script>
</body>
</html>