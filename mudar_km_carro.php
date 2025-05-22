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

// Função para registrar alterações no histórico
function registrarAlteracao($conn, $registro_id, $admin_id, $campo_alterado, $valor_antigo, $valor_novo) {
    // Buscar o nome do admin na tabela usuarios
    $query_admin = "SELECT name FROM usuarios WHERE id = :admin_id";
    $stmt_admin = $conn->prepare($query_admin);
    $stmt_admin->bindParam(':admin_id', $admin_id);
    $stmt_admin->execute();
    $admin = $stmt_admin->fetch(PDO::FETCH_ASSOC);
    $admin_name = $admin['name'] ?? 'Desconhecido';

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
}

// Processar atualização de KM se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registro_id'])) {
    $registro_id = $_POST['registro_id'];
    $km_inicial_novo = $_POST['km_inicial'];
    $km_final_novo = $_POST['km_final'];
    $admin_id = $_SESSION['user_id'];

    // Buscar valores atuais antes da alteração
    $query = "SELECT km_inicial, km_final FROM registros WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $registro_id);
    $stmt->execute();
    $registro = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($registro) {
        // Atualizar os valores no banco de dados
        $query = "UPDATE registros SET km_inicial = :km_inicial, km_final = :km_final WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':km_inicial', $km_inicial_novo);
        $stmt->bindParam(':km_final', $km_final_novo);
        $stmt->bindParam(':id', $registro_id);

        if ($stmt->execute()) {
            // Registrar alterações no histórico
            if ($registro['km_inicial'] != $km_inicial_novo) {
                registrarAlteracao($conn, $registro_id, $admin_id, 'km_inicial', $registro['km_inicial'], $km_inicial_novo);
            }

            if ($registro['km_final'] != $km_final_novo) {
                registrarAlteracao($conn, $registro_id, $admin_id, 'km_final', $registro['km_final'], $km_final_novo);
            }

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
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
$secretaria_admin = $_SESSION['secretaria'];
$secretaria_admin_db = isset($secretarias_map[$secretaria_admin]) ? $secretarias_map[$secretaria_admin] : null;
$role = $_SESSION['role'] ?? '';

// Buscar registros
$registros = buscarRegistros($conn, $codigo_veiculo, $data_inicial, $data_final, $secretaria_admin_db, $role);
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

        .edit-icon {
            cursor: pointer;
            color: #4F46E5;
            transition: all 0.2s;
        }

        .edit-icon:hover {
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
            max-height: 200px;
            overflow-y: auto;
            background: white;
            border: 1px solid #ddd;
            border-radius: 4px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            display: none;
        }

        .suggestion-item {
            padding: 8px 12px;
            cursor: pointer;
        }

        .suggestion-item:hover {
            background-color: #f0f0f0;
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
                                    <td>
                                        <i class="fas fa-pencil-alt edit-icon"
                                           onclick="abrirModalEdicao(
                                               '<?= $registro['id'] ?>',
                                               '<?= $registro['km_inicial'] ?>',
                                               '<?= $registro['km_final'] ?>',
                                               '<?= $registro['veiculo'] ?>',
                                               '<?= $registro['data'] ?>'
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

        // Fechar o modal se clicar fora dele
        window.onclick = function(event) {
            if (event.target == document.getElementById('modalEdicao')) {
                fecharModalEdicao();
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

        // Esconder sugestões quando clicar em outro lugar
        $(document).click(function(event) {
            if (!$(event.target).closest('.suggestions-container').length) {
                $('#suggestions').hide();
            }
        });
    </script>
</body>
</html>