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
$ip_usuario = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// Função simplificada para registrar operações no histórico (apenas um registro por operação)
function registrarOperacao($conn, $registro_id, $admin_id, $tipo_operacao, $dados_antes = null, $dados_depois = null) {
    try {
        // Buscar o nome do admin na tabela usuarios
        $query_admin = "SELECT name FROM usuarios WHERE id = :admin_id";
        $stmt_admin = $conn->prepare($query_admin);
        $stmt_admin->bindParam(':admin_id', $admin_id);
        $stmt_admin->execute();
        $admin = $stmt_admin->fetch(PDO::FETCH_ASSOC);
        $admin_nome = $admin['name'] ?? 'Desconhecido';
        
        // Preparar os dados de registro completo
        $registro_completo = null;
        
        // Para alteração, preparar diferenças
        if ($tipo_operacao === 'ALTERACAO' && $dados_antes && $dados_depois) {
            $registro_completo = json_encode([
                'antes' => $dados_antes,
                'depois' => $dados_depois
            ], JSON_UNESCAPED_UNICODE);
        } 
        // Para adição, registrar dados do novo registro
        else if ($tipo_operacao === 'ADICAO' && $dados_depois) {
            $registro_completo = json_encode($dados_depois, JSON_UNESCAPED_UNICODE);
        }
        // Para exclusão, registrar dados do registro excluído
        else if ($tipo_operacao === 'EXCLUSAO' && $dados_antes) {
            $registro_completo = json_encode($dados_antes, JSON_UNESCAPED_UNICODE);
        }
        
        // Inserir no histórico
        $query = "INSERT INTO historico_abastecimento (
                    registro_id, 
                    admin_id, 
                    admin_nome, 
                    tipo_operacao,
                    campo_alterado,
                    valor_antigo,
                    valor_novo, 
                    registro_completo, 
                    data_hora, 
                    ip_usuario
                  ) VALUES (
                    :registro_id, 
                    :admin_id, 
                    :admin_nome, 
                    :tipo_operacao,
                    'REGISTRO_COMPLETO',
                    :valor_antigo,
                    :valor_novo,
                    :registro_completo, 
                    NOW(), 
                    :ip_usuario
                  )";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':registro_id', $registro_id);
        $stmt->bindParam(':admin_id', $admin_id);
        $stmt->bindParam(':admin_nome', $admin_nome);
        $stmt->bindParam(':tipo_operacao', $tipo_operacao);
        
        // Definir valores antigos e novos com base na operação
        $valor_antigo = ($tipo_operacao === 'EXCLUSAO') ? 'Registro excluído' : ($tipo_operacao === 'ALTERACAO' ? 'Registro alterado' : 'N/A');
        $valor_novo = ($tipo_operacao === 'ADICAO') ? 'Registro adicionado' : ($tipo_operacao === 'ALTERACAO' ? 'Registro alterado' : 'N/A');
        
        $stmt->bindParam(':valor_antigo', $valor_antigo);
        $stmt->bindParam(':valor_novo', $valor_novo);
        $stmt->bindParam(':registro_completo', $registro_completo);
        $stmt->bindParam(':ip_usuario', $ip_usuario);
        
        return $stmt->execute();
    } catch (Exception $e) {
        error_log("Erro ao registrar operação no histórico: " . $e->getMessage());
        return false;
    }
}

// Processar atualização de abastecimento se o formulário foi enviado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registro_id']) && !isset($_POST['action'])) {
    try {
        // Log para depuração
        error_log("Tentativa de alteração de registro: ID=" . $_POST['registro_id']);
        
        // Iniciar transação para garantir integridade
        $conn->beginTransaction();
        
        $registro_id = $_POST['registro_id'];
        $nome = trim($_POST['nome']);
        $data = $_POST['data'];
        // Garantir que a hora esteja no formato correto
        $hora = date('H:i', strtotime($_POST['hora']));
        
        // Validação da hora
        if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $hora)) {
            throw new Exception("Formato de hora inválido. Use HH:MM (ex: 07:30)");
        }
        
        $km_abastecido = !empty($_POST['km_abastecido']) ? intval($_POST['km_abastecido']) : null;
        $litros = floatval(str_replace(',', '.', $_POST['litros']));
        $combustivel = trim($_POST['combustivel']);
        $posto_gasolina = !empty($_POST['posto_gasolina']) && $_POST['posto_gasolina'] !== 'outro' ? 
                            trim($_POST['posto_gasolina']) : 
                            (!empty($_POST['outro_posto_modal']) ? trim($_POST['outro_posto_modal']) : null);
        $valor = floatval(str_replace(',', '.', $_POST['valor']));
        $nota_fiscal = !empty($_POST['nota_fiscal']) ? trim($_POST['nota_fiscal']) : null;
        $admin_id = $_SESSION['user_id'];

        // Log dos dados para depuração
        error_log("Dados para alteração: nome=$nome, data=$data, hora=$hora, km=$km_abastecido, litros=$litros, combustivel=$combustivel, posto=$posto_gasolina, valor=$valor");
        
        // Validação dos dados
        if (empty($nome) || empty($data) || empty($hora) || empty($litros) || empty($combustivel) || empty($valor)) {
            throw new Exception("Todos os campos obrigatórios devem ser preenchidos.");
        }

        // Validar que o combustível é um dos valores permitidos
        $combustiveis_validos = ['Gasolina', 'Etanol', 'Diesel-S10', 'Diesel-S500'];
        if (!in_array($combustivel, $combustiveis_validos)) {
            throw new Exception("Tipo de combustível inválido. Escolha entre: Gasolina, Etanol, Diesel-S10 ou Diesel-S500.");
        }

        // Buscar valores atuais antes da alteração
        $query = "SELECT * FROM registro_abastecimento WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $registro_id);
        $stmt->execute();
        $registro = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$registro) {
            throw new Exception("Registro não encontrado.");
        }

        $registro_antigo = $registro; // Salvar registro completo antes da alteração
        
        // Atualizar os valores no banco de dados
        $query = "UPDATE registro_abastecimento SET 
                 nome = :nome, 
                 data = :data, 
                 hora = :hora, 
                 km_abastecido = :km_abastecido, 
                 litros = :litros, 
                 combustivel = :combustivel,
                 posto_gasolina = :posto_gasolina,
                 valor = :valor,
                 nota_fiscal = :nota_fiscal
                 WHERE id = :id";
                 
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':nome', $nome);
        $stmt->bindParam(':data', $data);
        $stmt->bindParam(':hora', $hora);
        $stmt->bindParam(':km_abastecido', $km_abastecido);
        $stmt->bindParam(':litros', $litros);
        $stmt->bindParam(':combustivel', $combustivel);
        $stmt->bindParam(':posto_gasolina', $posto_gasolina);
        $stmt->bindParam(':valor', $valor);
        $stmt->bindParam(':nota_fiscal', $nota_fiscal);
        $stmt->bindParam(':id', $registro_id);

        if ($stmt->execute()) {
            // Buscar o registro atualizado
            $query = "SELECT * FROM registro_abastecimento WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':id', $registro_id);
            $stmt->execute();
            $registro_novo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Registrar a alteração (apenas um registro)
            registrarOperacao($conn, $registro_id, $admin_id, 'ALTERACAO', $registro_antigo, $registro_novo);

            // Log do resultado da alteração para depuração
            error_log("Alteração bem-sucedida: ID=$registro_id");
            
            // Confirmar transação
            $conn->commit();
            
            $_SESSION['mensagem'] = "Registro atualizado com sucesso!";
            $_SESSION['tipo_mensagem'] = "success";
        } else {
            $conn->rollBack();
            $_SESSION['mensagem'] = "Erro ao atualizar registro.";
            $_SESSION['tipo_mensagem'] = "error";
            error_log("Falha na execução do UPDATE: " . print_r($stmt->errorInfo(), true));
        }
    } catch (Exception $e) {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        $_SESSION['mensagem'] = "Erro: " . $e->getMessage();
        $_SESSION['tipo_mensagem'] = "error";
        error_log("Exceção ao alterar registro: " . $e->getMessage());
    }

    header("Location: gerenciar_abastecimentos.php?" . $_SERVER['QUERY_STRING']);
    exit;
}

// Processar adição de novo registro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'adicionar') {
    try {
        // Log para depuração
        error_log("Tentativa de adição de novo registro de abastecimento");

        $nome = isset($_POST['nome']) ? trim($_POST['nome']) : '';
        $data = isset($_POST['data']) ? $_POST['data'] : '';
        // Garantir que a hora esteja no formato correto
        $hora = isset($_POST['hora']) ? date('H:i', strtotime($_POST['hora'])) : '';
        
        // Validação da hora
        if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $hora)) {
            throw new Exception("Formato de hora inválido. Use HH:MM (ex: 07:30)");
        }
        
        $prefixo = isset($_POST['prefixo']) && !empty($_POST['prefixo']) ? trim($_POST['prefixo']) : null;
        $placa = isset($_POST['placa']) ? trim($_POST['placa']) : '';
        $veiculo = isset($_POST['veiculo']) ? trim($_POST['veiculo']) : '';
        $secretaria = isset($_POST['secretaria']) && !empty($_POST['secretaria']) ? trim($_POST['secretaria']) : null;
        $km_abastecido = isset($_POST['km_abastecido']) && !empty($_POST['km_abastecido']) ? intval($_POST['km_abastecido']) : null;
        $litros = isset($_POST['litros']) ? floatval(str_replace(',', '.', $_POST['litros'])) : 0;
        $combustivel = isset($_POST['combustivel']) ? trim($_POST['combustivel']) : '';
        $posto_gasolina = isset($_POST['posto_gasolina']) && !empty($_POST['posto_gasolina']) && $_POST['posto_gasolina'] !== 'outro' ? 
                            trim($_POST['posto_gasolina']) : 
                            (isset($_POST['outro_posto']) && !empty($_POST['outro_posto']) ? trim($_POST['outro_posto']) : null);
        $valor = isset($_POST['valor']) ? floatval(str_replace(',', '.', $_POST['valor'])) : 0;
        $nota_fiscal = isset($_POST['nota_fiscal']) && !empty($_POST['nota_fiscal']) ? trim($_POST['nota_fiscal']) : null;
        $admin_id = $_SESSION['user_id'];
        
        // Log dos dados para depuração
        error_log("Dados para adição: nome=$nome, data=$data, hora=$hora, prefixo=$prefixo, placa=$placa, veiculo=$veiculo, km=$km_abastecido, litros=$litros, combustivel=$combustivel, posto=$posto_gasolina, valor=$valor");

        // Verificando campos obrigatórios individualmente para mensagem de erro mais precisa
        $campos_faltando = [];
        if (empty($nome)) $campos_faltando[] = 'Nome do Motorista';
        if (empty($data)) $campos_faltando[] = 'Data';
        if (empty($hora)) $campos_faltando[] = 'Hora';
        if (empty($placa)) $campos_faltando[] = 'Placa do Veículo';
        if (empty($veiculo)) $campos_faltando[] = 'Veículo';
        if (empty($litros) || $litros <= 0) $campos_faltando[] = 'Litros';
        if (empty($combustivel)) $campos_faltando[] = 'Combustível';
        if (empty($valor) || $valor <= 0) $campos_faltando[] = 'Valor';
        
        // Se houver campos faltando, lança exceção com a lista
        if (!empty($campos_faltando)) {
            throw new Exception("Por favor, preencha os seguintes campos obrigatórios: " . implode(", ", $campos_faltando));
        }

        // Validar que o combustível é um dos valores permitidos
        $combustiveis_validos = ['Gasolina', 'Etanol', 'Diesel-S10', 'Diesel-S500'];
        if (!in_array($combustivel, $combustiveis_validos)) {
            throw new Exception("Tipo de combustível inválido. Escolha entre: Gasolina, Etanol, Diesel-S10 ou Diesel-S500.");
        }
        
        // Iniciar transação
        $conn->beginTransaction();
        
        // Preparar dados do registro para inserção
        $dados_registro = [
            'nome' => $nome,
            'data' => $data,
            'hora' => $hora,
            'prefixo' => $prefixo,
            'placa' => $placa,
            'veiculo' => $veiculo,
            'secretaria' => $secretaria,
            'km_abastecido' => $km_abastecido,
            'litros' => $litros,
            'combustivel' => $combustivel,
            'posto_gasolina' => $posto_gasolina,
            'valor' => $valor,
            'nota_fiscal' => $nota_fiscal
        ];
        
        // Inserir novo registro
        $query = "INSERT INTO registro_abastecimento (nome, data, hora, prefixo, placa, veiculo, secretaria, 
                 km_abastecido, litros, combustivel, posto_gasolina, valor, nota_fiscal) 
                 VALUES (:nome, :data, :hora, :prefixo, :placa, :veiculo, :secretaria, 
                 :km_abastecido, :litros, :combustivel, :posto_gasolina, :valor, :nota_fiscal)";
        $stmt = $conn->prepare($query);
        
        foreach ($dados_registro as $campo => $valor) {
            $stmt->bindValue(':' . $campo, $valor);
        }
        
        if ($stmt->execute()) {
            $registro_id = $conn->lastInsertId();
            
            // Buscar o registro completo inserido
            $query = "SELECT * FROM registro_abastecimento WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':id', $registro_id);
            $stmt->execute();
            $registro_inserido = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Registrar a adição (apenas um registro)
            registrarOperacao($conn, $registro_id, $admin_id, 'ADICAO', null, $registro_inserido);
            
            $conn->commit();
            $_SESSION['mensagem'] = "Novo registro de abastecimento adicionado com sucesso!";
            $_SESSION['tipo_mensagem'] = "success";
            error_log("Adição bem-sucedida: ID=$registro_id");
        } else {
            $conn->rollBack();
            $_SESSION['mensagem'] = "Erro ao adicionar novo registro.";
            $_SESSION['tipo_mensagem'] = "error";
            error_log("Falha na execução do INSERT: " . print_r($stmt->errorInfo(), true));
        }
    } catch (PDOException $e) {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        $_SESSION['mensagem'] = "Erro ao adicionar registro: " . $e->getMessage();
        $_SESSION['tipo_mensagem'] = "error";
        
        // Log detalhado do erro para depuração
        error_log("Erro PDO ao adicionar registro: " . $e->getMessage() . " | Código: " . $e->getCode() . " | Trace: " . $e->getTraceAsString());
    } catch (Exception $e) {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        $_SESSION['mensagem'] = "Erro: " . $e->getMessage();
        $_SESSION['tipo_mensagem'] = "error";
        
        // Log detalhado do erro para depuração
        error_log("Erro genérico ao adicionar registro: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    }
    
    header("Location: gerenciar_abastecimentos.php?" . $_SERVER['QUERY_STRING']);
    exit;
}

// Processar exclusão de registro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'excluir') {
    try {
        $registro_id = $_POST['registro_id_excluir'];
        $admin_id = $_SESSION['user_id'];
        
        // Log para depuração
        error_log("Tentativa de exclusão de registro: ID=$registro_id");
        
        if (empty($registro_id)) {
            throw new Exception("ID do registro não fornecido.");
        }
        
        // Iniciar transação
        $conn->beginTransaction();
        
        // Buscar informações do registro antes de excluir
        $query = "SELECT * FROM registro_abastecimento WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $registro_id);
        $stmt->execute();
        $registro = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$registro) {
            throw new Exception("Registro não encontrado.");
        }
        
        // Registrar a exclusão (apenas um registro)
        registrarOperacao($conn, $registro_id, $admin_id, 'EXCLUSAO', $registro, null);
        
        // Excluir o registro
        $query = "DELETE FROM registro_abastecimento WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $registro_id);
        
        if ($stmt->execute()) {
            $conn->commit();
            $_SESSION['mensagem'] = "Registro excluído com sucesso!";
            $_SESSION['tipo_mensagem'] = "success";
            error_log("Exclusão bem-sucedida: ID=$registro_id");
        } else {
            $conn->rollBack();
            $_SESSION['mensagem'] = "Erro ao excluir registro.";
            $_SESSION['tipo_mensagem'] = "error";
            error_log("Falha na execução do DELETE: " . print_r($stmt->errorInfo(), true));
        }
    } catch (PDOException $e) {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        $_SESSION['mensagem'] = "Erro ao excluir registro: " . $e->getMessage();
        $_SESSION['tipo_mensagem'] = "error";
        
        // Log detalhado do erro para depuração
        error_log("Erro PDO ao excluir registro: " . $e->getMessage() . " | Código: " . $e->getCode() . " | Trace: " . $e->getTraceAsString());
    } catch (Exception $e) {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
        }
        $_SESSION['mensagem'] = "Erro: " . $e->getMessage();
        $_SESSION['tipo_mensagem'] = "error";
        
        // Log detalhado do erro para depuração
        error_log("Erro genérico ao excluir registro: " . $e->getMessage() . " | Trace: " . $e->getTraceAsString());
    }
    
    header("Location: gerenciar_abastecimentos.php?" . $_SERVER['QUERY_STRING']);
    exit;
}

// Configuração de paginação
$registros_por_pagina = 20;
$pagina_atual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$inicio = ($pagina_atual - 1) * $registros_por_pagina;

// Buscar registros com base nos filtros
$prefixo_veiculo = $_GET['prefixo_veiculo'] ?? '';
$data_inicial = $_GET['data_inicial'] ?? '';
$data_final = $_GET['data_final'] ?? '';
$combustivel = $_GET['combustivel'] ?? '';

// Função para buscar registros com paginação
function buscarRegistros($conn, $prefixo_veiculo, $data_inicial, $data_final, $combustivel, $secretaria_admin_db, $role, $inicio, $registros_por_pagina) {
    $query = "SELECT r.* FROM registro_abastecimento r
              LEFT JOIN veiculos v ON r.placa = v.placa
              WHERE 1=1";

    $params = [];

    if ($prefixo_veiculo) {
        $query .= " AND (v.veiculo LIKE :prefixo_veiculo OR r.prefixo LIKE :prefixo_veiculo OR r.veiculo LIKE :prefixo_veiculo)";
        $params[':prefixo_veiculo'] = "%$prefixo_veiculo%";
    }

    if ($data_inicial && $data_final) {
        $query .= " AND r.data BETWEEN :data_inicial AND :data_final";
        $params[':data_inicial'] = $data_inicial;
        $params[':data_final'] = $data_final;
    }

    if ($combustivel) {
        $query .= " AND r.combustivel = :combustivel";
        $params[':combustivel'] = $combustivel;
    }

    // Filtro por secretaria se não for geraladm
    if ($role !== 'geraladm' && $secretaria_admin_db) {
        $query .= " AND r.secretaria = :secretaria";
        $params[':secretaria'] = $secretaria_admin_db;
    }

    // Contar total de registros para paginação
    $query_count = str_replace("SELECT r.*", "SELECT COUNT(*) as total", $query);
    $stmt_count = $conn->prepare($query_count);
    foreach ($params as $key => $value) {
        $stmt_count->bindValue($key, $value);
    }
    $stmt_count->execute();
    $total_registros = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];

    // Adicionar ordenação e limites para paginação
    $query .= " ORDER BY r.data DESC, r.hora DESC LIMIT :inicio, :registros_por_pagina";

    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':inicio', (int)$inicio, PDO::PARAM_INT);
    $stmt->bindValue(':registros_por_pagina', (int)$registros_por_pagina, PDO::PARAM_INT);
    $stmt->execute();
    
    return [
        'registros' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'total' => $total_registros
    ];
}

// Função para buscar secretarias disponíveis
function buscarSecretarias() {
    // Retornar apenas as secretarias na primeira fileira (com letras maiúsculas e minúsculas)
    return [
        "Gabinete do Prefeito",
        "Gabinete do Vice-Prefeito",
        "Secretaria Municipal da Mulher de Família",
        "Secretaria Municipal de Fazenda",
        "Secretaria Municipal de Educação",
        "Secretaria Municipal de Agricultura e Meio Ambiente",
        "Secretaria Municipal de Agricultura Familiar e Segurança Alimentar",
        "Secretaria Municipal de Assistência Social",
        "Secretaria Municipal de Desenvolvimento Econômico e Turismo",
        "Secretaria Municipal de Administração",
        "Secretaria Municipal de Governo",
        "Secretaria Municipal de Infraestrutura, Transportes e Saneamento",
        "Secretaria Municipal de Esporte e Lazer e Juventude",
        "Secretaria Municipal da Cidade",
        "Secretaria Municipal de Saúde",
        "Secretaria Municipal de Segurança Pública, Trânsito e Defesa Civil",
        "Controladoria Geral do Município",
        "Procuradoria Geral do Município",
        "Secretaria Municipal de Cultura",
        "Secretaria Municipal de Planejamento, Ciência, Tecnologia e Inovação",
        "Secretaria Municipal de Obras e Serviços Públicos"
    ];
}

// Função para buscar combustíveis disponíveis
function buscarCombustiveis() {
    // Retornar exatamente os combustíveis especificados
    return ['Gasolina', 'Etanol', 'Diesel-S10', 'Diesel-S500'];
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
    "Secretaria Municipal de Obras e Serviços Públicos" => "SECRETARIA DE OBRAS E SERVIÇOS PÚBLICOS"
];

// Secretaria do usuário logado
$secretaria_admin = $_SESSION['secretaria'] ?? '';
$secretaria_admin_db = isset($secretarias_map[$secretaria_admin]) ? $secretarias_map[$secretaria_admin] : null;
$role = $_SESSION['role'] ?? '';

// Buscar registros com paginação
$resultado = buscarRegistros($conn, $prefixo_veiculo, $data_inicial, $data_final, $combustivel, $secretaria_admin_db, $role, $inicio, $registros_por_pagina);
$registros = $resultado['registros'];
$total_registros = $resultado['total'];

// Calcular total de páginas
$total_paginas = ceil($total_registros / $registros_por_pagina);

// Buscar todas as secretarias disponíveis para o formulário de adição
$secretarias_disponiveis = buscarSecretarias();

// Buscar todos os combustíveis disponíveis
$combustiveis_disponiveis = buscarCombustiveis();

// Obter a data atual para o formulário de adição
$data_atual = date('Y-m-d');
$hora_atual = date('H:i');

// Buscar todos os preços de combustíveis da tabela postos_precos
try {
    $stmt_precos = $conn->prepare("SELECT * FROM postos_precos ORDER BY posto_nome, tipo_combustivel");
    $stmt_precos->execute();
    $precos_combustiveis = $stmt_precos->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar preços de combustíveis: " . $e->getMessage());
    $precos_combustiveis = [];
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Abastecimentos</title>
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
            -webkit-overflow-scrolling: touch; /* Melhora rolagem em iOS */
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
            white-space: nowrap; /* Evita quebra de linha */
        }

        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #E5E7EB;
        }

        /* Reduz espaçamento em telas pequenas */
        @media (max-width: 640px) {
            th, td {
                padding: 8px 10px;
                font-size: 0.875rem; /* Texto menor em mobile */
            }
        }

        tr:hover td {
            background-color: #F9FAFB;
        }

        .edit-icon, .delete-icon {
            cursor: pointer;
            transition: all 0.2s;
            font-size: 1.25rem; /* Ícones maiores para facilitar toque */
            padding: 8px; /* Área de toque maior */
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
            width: 90%; /* Ajuste para mobile */
            max-width: 500px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }

        /* Modal de adição precisa de mais espaço */
        #modalAdicao .modal-content {
            width: 95%;
            max-width: 700px;
            margin: 5% auto;
        }

        /* Em telas pequenas, centraliza melhor */
        @media (max-width: 640px) {
            .modal-content {
                margin: 5% auto;
                padding: 16px;
            }
        }

        .close {
            color: #aaa;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            padding: 0 8px; /* Área de toque maior */
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
            height: 40px; /* Altura padronizada para botões */
            display: inline-flex;
            align-items: center;
            justify-content: center;
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
            height: 40px; /* Altura padronizada para botões */
            display: inline-flex;
            align-items: center;
            justify-content: center;
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
            height: 40px; /* Altura padronizada para botões */
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-secondary:hover {
            background-color: #4B5563;
        }

        /* Botões adaptados para mobile */
        @media (max-width: 640px) {
            .btn-primary, .btn-danger, .btn-secondary {
                width: 100%;
                margin-bottom: 8px;
                padding: 10px;
                font-size: 1rem;
            }
            
            .flex.justify-end.space-x-2 {
                flex-direction: column;
                align-items: stretch;
            }
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
            padding: 12px; /* Maior área de toque */
            cursor: pointer;
            border-bottom: 1px solid #f3f3f3;
        }

        .suggestion-item:last-child {
            border-bottom: none;
        }

        .suggestion-item:hover {
            background-color: #f0f7ff;
        }
        
        /* Estilos específicos para mobile */
        @media (max-width: 768px) {
            /* Tabela responsiva */
            .mobile-table-cell {
                display: flex;
                flex-direction: column;
            }
            
            .mobile-label {
                font-weight: 600;
                font-size: 0.75rem;
                color: #6B7280;
                margin-bottom: 4px;
                display: none; /* Escondido por padrão, visível apenas em visualização mobile */
            }
            
            /* Estilo para campos de formulário para facilitar entrada no mobile */
            input, select, textarea {
                font-size: 16px !important; /* Previne zoom automático em iPhones */
                padding: 12px !important;
                margin-bottom: 12px;
            }
            
            /* Visualização de tabela específica para mobile */
            @media (max-width: 640px) {
                .desktop-table {
                    display: none;
                }
                
                .mobile-table {
                    display: block;
                }
                
                .mobile-card {
                    border: 1px solid #E5E7EB;
                    border-radius: 8px;
                    margin-bottom: 12px;
                    padding: 12px;
                }
                
                .mobile-table-header {
                    background-color: #F9FAFB;
                    padding: 8px 12px;
                    border-radius: 6px 6px 0 0;
                    font-weight: 600;
                }
                
                .mobile-table-body {
                    padding: 12px;
                }
                
                .mobile-table-row {
                    margin-bottom: 16px;
                    padding-bottom: 16px;
                    border-bottom: 1px solid #E5E7EB;
                }
                
                .mobile-table-row:last-child {
                    border-bottom: none;
                    margin-bottom: 0;
                    padding-bottom: 0;
                }
                
                .mobile-label {
                    display: block;
                }
                
                .mobile-actions {
                    display: flex;
                    justify-content: flex-end;
                    margin-top: 8px;
                }
            }
        }

        /* Paginação */
        .pagination {
            display: flex;
            justify-content: center;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        
        .pagination a, .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin: 0 5px;
            padding: 8px 12px;
            border-radius: 6px;
            border: 1px solid #E5E7EB;
            background-color: white;
            color: #4B5563;
            text-decoration: none;
            font-weight: 500;
            min-width: 40px;
            transition: all 0.2s;
        }
        
        .pagination a:hover {
            background-color: #F3F4F6;
            border-color: #D1D5DB;
        }
        
        .pagination .active {
            background-color: #4F46E5;
            color: white;
            border-color: #4F46E5;
        }
        
        .pagination .disabled {
            color: #D1D5DB;
            cursor: not-allowed;
        }
        
        @media (max-width: 640px) {
            .pagination a, .pagination span {
                padding: 6px 10px;
                margin: 0 2px;
                margin-bottom: 8px;
            }
        }
    </style>
</head>
<body class="min-h-screen">
    <!-- App Bar - Versão Ajustada -->
    <div class="bg-indigo-600 text-white shadow-md">
        <div class="container mx-auto px-4 py-3">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <h1 class="text-xl font-bold mb-3 md:mb-0">Gerenciar Abastecimentos</h1>
                
                <div class="flex flex-wrap w-full md:w-auto justify-center gap-2">
                    <button onclick="abrirModalAdicao()" class="bg-white/20 hover:bg-white/30 px-3 py-1.5 rounded-md transition text-sm font-medium min-w-[90px] flex items-center justify-center">
                        <i class="fas fa-plus mr-1.5"></i> Novo
                    </button>
                    <button onclick="window.location.href='historico_abastecimentos.php'" 
                        class="bg-white/20 hover:bg-white/30 px-3 py-1.5 rounded-md transition text-sm font-medium min-w-[90px] flex items-center justify-center">
                        <i class="fas fa-history mr-1.5"></i> Histórico
                    </button>
                    <a href="<?= $_SESSION['role'] === 'geraladm' ? 'geral_adm.php' : 'admin.php' ?>"
                        class="bg-white/20 hover:bg-white/30 px-3 py-1.5 rounded-md transition text-sm font-medium min-w-[90px] flex items-center justify-center">
                        <i class="fas fa-arrow-left mr-1.5"></i> Voltar
                    </a>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Content -->
    <div class="container mx-auto px-4 py-6">
        <!-- Filtros -->
        <div class="card p-4 md:p-6 mb-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Filtros</h2>
            <form method="get" class="grid grid-cols-1 gap-4">
                <div class="suggestions-container">
                    <label for="prefixo_veiculo" class="block text-sm font-medium text-gray-700 mb-2">Prefixo do Veículo</label>
                    <input type="text" id="prefixo_veiculo" name="prefixo_veiculo" class="w-full px-3 py-2 border rounded-md"
                           value="<?= htmlspecialchars($prefixo_veiculo) ?>" placeholder="Ex: C-32"
                           oninput="buscarVeiculos(this.value)">
                    <div id="suggestions" class="suggestions-list"></div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
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
                </div>
                <div>
                    <label for="combustivel" class="block text-sm font-medium text-gray-700 mb-2">Combustível</label>
                    <select id="combustivel" name="combustivel" class="w-full px-3 py-2 border rounded-md">
                        <option value="">Todos</option>
                        <?php foreach ($combustiveis_disponiveis as $comb): ?>
                            <option value="<?= htmlspecialchars($comb) ?>" <?= $combustivel === $comb ? 'selected' : '' ?>><?= htmlspecialchars($comb) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="btn-primary w-full md:w-auto">
                        <i class="fas fa-search mr-2"></i> Pesquisar
                    </button>
                </div>

                <!-- Manter a paginação nos filtros se houver -->
                <?php if (isset($_GET['pagina'])): ?>
                    <input type="hidden" name="pagina" value="1">
                <?php endif; ?>
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
        <div class="card p-4 md:p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-semibold text-gray-800">Registros de Abastecimentos</h2>
                <span class="text-sm text-gray-500">
                    Mostrando <?= count($registros) ?> de <?= $total_registros ?> registros
                </span>
            </div>

            <?php if (count($registros) > 0): ?>
                <!-- Versão Desktop da Tabela -->
                <div class="table-container desktop-table hidden md:block">
                    <table>
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Veículo</th>
                                <th>Placa</th>
                                <th>Motorista</th>
                                <th>KM</th>
                                <th>Litros</th>
                                <th>Combustível</th>
                                <th>Valor</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($registros as $registro): ?>
                                <tr>
                                    <td><?= htmlspecialchars(date('d/m/Y', strtotime($registro['data']))) ?></td>
                                    <td><?= htmlspecialchars($registro['veiculo']) ?></td>
                                    <td><?= htmlspecialchars($registro['placa']) ?></td>
                                    <td><?= htmlspecialchars($registro['nome']) ?></td>
                                    <td><?= $registro['km_abastecido'] ? htmlspecialchars(number_format($registro['km_abastecido'], 0, ',', '.')) : '-' ?></td>
                                    <td><?= htmlspecialchars(number_format($registro['litros'], 2, ',', '.')) ?></td>
                                    <td><?= htmlspecialchars($registro['combustivel']) ?></td>
                                    <td>R$ <?= htmlspecialchars(number_format($registro['valor'], 2, ',', '.')) ?></td>
                                    <td class="flex space-x-2">
                                        <i class="fas fa-pencil-alt edit-icon"
                                           onclick="abrirModalEdicao(
                                               '<?= $registro['id'] ?>',
                                               '<?= htmlspecialchars($registro['nome']) ?>',
                                               '<?= $registro['data'] ?>',
                                               '<?= $registro['hora'] ?>',
                                               '<?= $registro['km_abastecido'] ?>',
                                               '<?= $registro['litros'] ?>',
                                               '<?= htmlspecialchars($registro['combustivel']) ?>',
                                               '<?= htmlspecialchars($registro['posto_gasolina']) ?>',
                                               '<?= $registro['valor'] ?>',
                                               '<?= htmlspecialchars($registro['nota_fiscal']) ?>'
                                           )"></i>
                                        <i class="fas fa-trash-alt delete-icon"
                                           onclick="abrirModalExclusao(
                                               '<?= $registro['id'] ?>',
                                               '<?= htmlspecialchars($registro['veiculo']) ?>',
                                               '<?= htmlspecialchars($registro['placa']) ?>',
                                               '<?= $registro['data'] ?>'
                                           )"></i>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Versão Mobile da Tabela - Cards -->
                <div class="mobile-table block md:hidden">
                    <?php foreach ($registros as $registro): ?>
                        <div class="mobile-card bg-white mb-4">
                            <div class="flex justify-between items-center mb-2">
                                <div class="font-semibold"><?= htmlspecialchars($registro['veiculo']) ?></div>
                                <div class="text-sm text-gray-500"><?= htmlspecialchars(date('d/m/Y', strtotime($registro['data']))) ?></div>
                            </div>
                            
                            <div class="grid grid-cols-2 gap-2 mb-3">
                                <div>
                                    <div class="mobile-label">Placa</div>
                                    <div><?= htmlspecialchars($registro['placa']) ?></div>
                                </div>
                                <div>
                                    <div class="mobile-label">Motorista</div>
                                    <div><?= htmlspecialchars($registro['nome']) ?></div>
                                </div>
                                <div>
                                    <div class="mobile-label">KM</div>
                                    <div><?= $registro['km_abastecido'] ? htmlspecialchars(number_format($registro['km_abastecido'], 0, ',', '.')) : '-' ?></div>
                                </div>
                                <div>
                                    <div class="mobile-label">Litros</div>
                                    <div><?= htmlspecialchars(number_format($registro['litros'], 2, ',', '.')) ?></div>
                                </div>
                                <div>
                                    <div class="mobile-label">Combustível</div>
                                    <div><?= htmlspecialchars($registro['combustivel']) ?></div>
                                </div>
                                <div>
                                    <div class="mobile-label">Valor</div>
                                    <div>R$ <?= htmlspecialchars(number_format($registro['valor'], 2, ',', '.')) ?></div>
                                </div>
                            </div>
                            
                            <div class="flex justify-end space-x-4">
                                <button class="p-2 text-indigo-600" 
                                       onclick="abrirModalEdicao(
                                           '<?= $registro['id'] ?>',
                                           '<?= htmlspecialchars($registro['nome']) ?>',
                                           '<?= $registro['data'] ?>',
                                           '<?= $registro['hora'] ?>',
                                           '<?= $registro['km_abastecido'] ?>',
                                           '<?= $registro['litros'] ?>',
                                           '<?= htmlspecialchars($registro['combustivel']) ?>',
                                           '<?= htmlspecialchars($registro['posto_gasolina']) ?>',
                                           '<?= $registro['valor'] ?>',
                                           '<?= htmlspecialchars($registro['nota_fiscal']) ?>'
                                       )">
                                    <i class="fas fa-pencil-alt"></i>
                                </button>
                                <button class="p-2 text-red-600"
                                       onclick="abrirModalExclusao(
                                           '<?= $registro['id'] ?>',
                                           '<?= htmlspecialchars($registro['veiculo']) ?>',
                                           '<?= htmlspecialchars($registro['placa']) ?>',
                                           '<?= $registro['data'] ?>'
                                       )">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <!-- Paginação -->
                <?php if ($total_paginas > 1): ?>
                <div class="pagination">
                    <?php if ($pagina_atual > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => 1])) ?>" title="Primeira página">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                        <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina_atual - 1])) ?>" title="Página anterior">
                            <i class="fas fa-angle-left"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled"><i class="fas fa-angle-double-left"></i></span>
                        <span class="disabled"><i class="fas fa-angle-left"></i></span>
                    <?php endif; ?>

                    <?php
                    // Definir quantas páginas mostrar antes e depois da atual
                    $max_links = 3;
                    $start = max(1, $pagina_atual - $max_links);
                    $end = min($total_paginas, $pagina_atual + $max_links);
                    
                    // Garante sempre mostrar no mínimo 2*$max_links + 1 links (se houver)
                    if ($pagina_atual - $start < $max_links) {
                        $end = min($total_paginas, $end + ($max_links - ($pagina_atual - $start)));
                    }
                    if ($end - $pagina_atual < $max_links) {
                        $start = max(1, $start - ($max_links - ($end - $pagina_atual)));
                    }
                    
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <?php if ($i == $pagina_atual): ?>
                            <span class="active"><?= $i ?></span>
                        <?php else: ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>"><?= $i ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>

                    <?php if ($pagina_atual < $total_paginas): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina_atual + 1])) ?>" title="Próxima página">
                            <i class="fas fa-angle-right"></i>
                        </a>
                        <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $total_paginas])) ?>" title="Última página">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    <?php else: ?>
                        <span class="disabled"><i class="fas fa-angle-right"></i></span>
                        <span class="disabled"><i class="fas fa-angle-double-right"></i></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

            <?php else: ?>
                <div class="text-center py-8">
                    <i class="fas fa-gas-pump text-4xl text-gray-300 mb-4"></i>
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
            <h2 class="text-xl font-semibold mb-4">Editar Abastecimento</h2>
            <form id="formEdicao" method="post">
                <input type="hidden" id="registro_id" name="registro_id">
                <input type="hidden" id="modal_usuario_id" name="usuario_id" value="">

                <div class="mb-4 suggestions-container">
                    <label for="modal_nome" class="block text-sm font-medium text-gray-700 mb-2">Motorista</label>
                    <input type="text" id="modal_nome" name="nome" class="w-full px-3 py-2 border rounded-md" 
                           placeholder="Digite nome, CPF ou email" required oninput="buscarUsuariosModal(this.value)">
                    <div id="modal_suggestions_usuarios" class="suggestions-list"></div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="modal_data" class="block text-sm font-medium text-gray-700 mb-2">Data</label>
                        <input type="date" id="modal_data" name="data" class="w-full px-3 py-2 border rounded-md" required>
                    </div>
                    <div>
                        <label for="modal_hora" class="block text-sm font-medium text-gray-700 mb-2">Hora</label>
                        <input type="time" id="modal_hora" name="hora" class="w-full px-3 py-2 border rounded-md" 
                               required pattern="[0-9]{2}:[0-9]{2}" title="Formato deve ser HH:MM (ex: 07:30)">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="modal_km_abastecido" class="block text-sm font-medium text-gray-700 mb-2">KM</label>
                        <input type="number" id="modal_km_abastecido" name="km_abastecido" class="w-full px-3 py-2 border rounded-md">
                    </div>
                    <div>
                        <label for="modal_litros" class="block text-sm font                    <div>
                        <label for="modal_litros" class="block text-sm font-medium text-gray-700 mb-2">Litros</label>
                        <input type="number" step="0.01" id="modal_litros" name="litros" class="w-full px-3 py-2 border rounded-md" required 
                            onchange="calcularValorAbastecimentoModal()">
                    </div>
                </div>
                
                <div class="mb-4">
                    <label for="modal_combustivel" class="block text-sm font-medium text-gray-700 mb-2">Combustível</label>
                    <select id="modal_combustivel" name="combustivel" class="w-full px-3 py-2 border rounded-md" 
                        onchange="calcularValorAbastecimentoModal()">
                        <option value="">Selecione o combustível</option>
                        <option value="Gasolina">Gasolina</option>
                        <option value="Etanol">Etanol</option>
                        <option value="Diesel-S10">Diesel-S10</option>
                        <option value="Diesel-S500">Diesel-S500</option>
                    </select>
                </div>
                
                <div class="mb-4">
                    <label for="modal_posto_gasolina" class="block text-sm font-medium text-gray-700 mb-2">Posto de Combustível</label>
                    <select id="modal_posto_gasolina" name="posto_gasolina" class="w-full px-3 py-2 border rounded-md" 
                        onchange="calcularValorAbastecimentoModal()">
                        <option value="">Selecione ou digite outro posto</option>
                        <option value="ABRANTES & ABRANTES LTDA">POSTO NORDESTE - ABRANTES & ABRANTES LTDA</option>
                        <option value="ALBERTI COMERCIO DE COMBUSTIVEIS">POSTO CIDADE - ALBERTI COMERCIO DE COMBUSTIVEIS</option>
                        <option value="XAXIM COMERCIO DE COMBUSTÍVEIS LTDA">POSTO REDENTOR - XAXIM COMERCIO DE COMBUSTÍVEIS LTDA</option>
                        <option value="AUTO POSTO CHARRUA LTDA">AUTO POSTO CHARRUA LTDA</option>
                        <option value="MELOSA OBRAS">MELOSA OBRAS</option>
                        <option value="MELOSA TRANSPORTES">MELOSA TRANSPORTES</option>
                        <option value="POSTO MORADA">B&M - POSTO MORADA</option>
                        <option value="BRESCANSIN & BRESCANSIN LTDA">SMILLE - BRESCANSIN & BRESCANSIN LTDA</option>
                        <option value="outro">Outro (especificar)</option>
                    </select>
                    <div id="outro_posto_modal_container" class="mt-2" style="display: none;">
                        <input type="text" id="outro_posto_modal" class="w-full px-3 py-2 border rounded-md" 
                               placeholder="Digite o nome do posto">
                    </div>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="modal_valor" class="block text-sm font-medium text-gray-700 mb-2">Valor (R$)</label>
                        <input type="number" step="0.01" id="modal_valor" name="valor" class="w-full px-3 py-2 border rounded-md" required>
                    </div>
                    <div>
                        <label for="modal_nota_fiscal" class="block text-sm font-medium text-gray-700 mb-2">Nota Fiscal</label>
                        <input type="text" id="modal_nota_fiscal" name="nota_fiscal" class="w-full px-3 py-2 border rounded-md">
                    </div>
                </div>

                <div class="flex flex-col md:flex-row md:justify-end md:space-x-2">
                    <button type="button" onclick="fecharModalEdicao()" class="px-4 py-2 border rounded-md mb-2 md:mb-0 w-full md:w-auto">
                        Cancelar
                    </button>
                    <button type="submit" class="btn-primary w-full md:w-auto">
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
                    <p class="text-sm text-gray-600 mb-1">Deseja realmente excluir este registro de abastecimento?</p>
                    <p id="exclusao_veiculo" class="font-medium"></p>
                    <p id="exclusao_placa" class="font-medium"></p>
                    <p id="exclusao_data" class="font-medium"></p>
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

                <div class="flex flex-col md:flex-row md:justify-end md:space-x-2">
                    <button type="button" onclick="fecharModalExclusao()" class="px-4 py-2 border rounded-md mb-2 md:mb-0 w-full md:w-auto">
                        Cancelar
                    </button>
                    <button type="submit" class="btn-danger w-full md:w-auto">
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
            <h2 class="text-xl font-semibold mb-4">Adicionar Novo Abastecimento</h2>
            <form id="formAdicao" method="post">
                <input type="hidden" name="action" value="adicionar">

                <div class="grid grid-cols-1 gap-4 mb-4">
                    <div class="suggestions-container">
                        <label for="nome" class="block text-sm font-medium text-gray-700 mb-2">Nome do Motorista <span class="text-red-500">*</span></label>
                        <input type="text" id="nome" name="nome" class="w-full px-3 py-2 border rounded-md" 
                               placeholder="Digite nome, CPF ou email" required oninput="buscarUsuarios(this.value)">
                        <input type="hidden" id="usuario_id" name="usuario_id" value="">
                        <div id="suggestions_usuarios" class="suggestions-list"></div>
                    </div>
                    <div>
                        <label for="secretaria" class="block text-sm font-medium text-gray-700 mb-2">Secretaria</label>
                        <select id="secretaria" name="secretaria" class="w-full px-3 py-2 border rounded-md">
                            <option value="">Selecione a secretaria</option>
                            <?php foreach ($secretarias_disponiveis as $secretaria): ?>
                                <option value="<?= htmlspecialchars($secretaria) ?>"><?= htmlspecialchars($secretaria) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div class="suggestions-container">
                        <label for="prefixo" class="block text-sm font-medium text-gray-700 mb-2">Prefixo <span class="text-red-500">*</span></label>
                        <input type="text" id="prefixo" name="prefixo" class="w-full px-3 py-2 border rounded-md" 
                               placeholder="Ex: C-32" required oninput="buscarVeiculosPorPrefixo(this.value)">
                        <div id="suggestions_prefixo" class="suggestions-list"></div>
                    </div>
                    <div>
                        <label for="placa" class="block text-sm font-medium text-gray-700 mb-2">Placa <span class="text-red-500">*</span></label>
                        <input type="text" id="placa" name="placa" class="w-full px-3 py-2 border rounded-md" 
                               placeholder="Ex: ABC1234" required readonly>
                    </div>
                    <div>
                        <label for="veiculo" class="block text-sm font-medium text-gray-700 mb-2">Veículo <span class="text-red-500">*</span></label>
                        <input type="text" id="veiculo" name="veiculo" class="w-full px-3 py-2 border rounded-md" 
                               placeholder="Ex: Modelo do veículo" required readonly>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="data" class="block text-sm font-medium text-gray-700 mb-2">Data <span class="text-red-500">*</span></label>
                        <input type="date" id="data" name="data" class="w-full px-3 py-2 border rounded-md" 
                               value="<?= $data_atual ?>" required>
                    </div>
                    <div>
                        <label for="hora" class="block text-sm font-medium text-gray-700 mb-2">Hora <span class="text-red-500">*</span></label>
                        <input type="time" id="hora" name="hora" class="w-full px-3 py-2 border rounded-md" 
                               value="<?= $hora_atual ?>" required pattern="[0-9]{2}:[0-9]{2}" 
                               title="Formato deve ser HH:MM (ex: 07:30)">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="km_abastecido" class="block text-sm font-medium text-gray-700 mb-2">KM (opcional)</label>
                        <input type="number" id="km_abastecido" name="km_abastecido" class="w-full px-3 py-2 border rounded-md" 
                               placeholder="Ex: 123456">
                    </div>
                    <div>
                        <label for="litros" class="block text-sm font-medium text-gray-700 mb-2">Litros <span class="text-red-500">*</span></label>
                        <input type="number" step="0.01" id="litros" name="litros" class="w-full px-3 py-2 border rounded-md" 
                               placeholder="Ex: 45.50" required onchange="calcularValorAbastecimento()">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="tipo_combustivel" class="block text-sm font-medium text-gray-700 mb-2">Combustível <span class="text-red-500">*</span></label>
                        <select id="tipo_combustivel" name="combustivel" class="w-full px-3 py-2 border rounded-md" required onchange="calcularValorAbastecimento()">
                            <option value="">Selecione o combustível</option>
                            <option value="Gasolina">Gasolina</option>
                            <option value="Etanol">Etanol</option>
                            <option value="Diesel-S10">Diesel-S10</option>
                            <option value="Diesel-S500">Diesel-S500</option>
                        </select>
                    </div>
                    <div>
                        <label for="posto" class="block text-sm font-medium text-gray-700 mb-2">Posto de Combustível</label>
                        <select id="posto" name="posto_gasolina" class="w-full px-3 py-2 border rounded-md" onchange="calcularValorAbastecimento()">
                            <option value="">Selecione ou digite outro posto</option>
                            <option value="ABRANTES & ABRANTES LTDA">POSTO NORDESTE - ABRANTES & ABRANTES LTDA</option>
                            <option value="ALBERTI COMERCIO DE COMBUSTIVEIS">POSTO CIDADE - ALBERTI COMERCIO DE COMBUSTIVEIS</option>
                            <option value="XAXIM COMERCIO DE COMBUSTÍVEIS LTDA">POSTO REDENTOR - XAXIM COMERCIO DE COMBUSTÍVEIS LTDA</option>
                            <option value="AUTO POSTO CHARRUA LTDA">AUTO POSTO CHARRUA LTDA</option>
                            <option value="MELOSA OBRAS">MELOSA OBRAS</option>
                            <option value="MELOSA TRANSPORTES">MELOSA TRANSPORTES</option>
                            <option value="POSTO MORADA">B&M - POSTO MORADA</option>
                            <option value="BRESCANSIN & BRESCANSIN LTDA">SMILLE - BRESCANSIN & BRESCANSIN LTDA</option>
                            <option value="outro">Outro (especificar)</option>
                        </select>
                        <div id="outro_posto_container" class="mt-2" style="display: none;">
                            <input type="text" id="outro_posto" class="w-full px-3 py-2 border rounded-md" 
                                placeholder="Digite o nome do posto">
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="valor_abastecimento" class="block text-sm font-medium text-gray-700 mb-2">Valor (R$) <span class="text-red-500">*</span></label>
                        <input type="text" id="valor_abastecimento" name="valor" class="w-full px-3 py-2 border rounded-md" 
                               placeholder="Ex: 250,00" required>
                    </div>
                    <div>
                        <label for="nota_fiscal" class="block text-sm font-medium text-gray-700 mb-2">Nota Fiscal (opcional)</label>
                        <input type="text" id="nota_fiscal" name="nota_fiscal" class="w-full px-3 py-2 border rounded-md" 
                               placeholder="Ex: 123456789">
                    </div>
                </div>

                <div class="text-sm text-gray-500 mb-4">
                    <p>Campos marcados com <span class="text-red-500">*</span> são obrigatórios.</p>
                </div>

                <div class="flex flex-col md:flex-row md:justify-end md:space-x-2">
                    <button type="button" onclick="fecharModalAdicao()" class="px-4 py-2 border rounded-md mb-2 md:mb-0 w-full md:w-auto">
                        Cancelar
                    </button>
                    <button type="submit" class="btn-primary w-full md:w-auto">
                        <i class="fas fa-plus mr-2"></i> Adicionar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Armazenar dados de preços de combustíveis para cálculos
        const precosCombustiveis = <?= json_encode($precos_combustiveis) ?>;

        // Funções para o modal de edição
        function abrirModalEdicao(id, nome, data, hora, km_abastecido, litros, combustivel, posto_gasolina, valor, nota_fiscal) {
            console.log("Abrindo modal de edição para ID:", id);
            document.getElementById('registro_id').value = id;
            document.getElementById('modal_nome').value = nome;
            document.getElementById('modal_data').value = data;
            document.getElementById('modal_hora').value = formatarHora(hora);
            document.getElementById('modal_km_abastecido').value = km_abastecido;
            document.getElementById('modal_litros').value = litros;
            
            // Garantir que combustível tenha um valor válido
            if (!combustivel || combustivel === '') {
                combustivel = 'Gasolina'; // Valor padrão se estiver vazio
            }
            document.getElementById('modal_combustivel').value = combustivel;
            
            // Verificar se o posto de gasolina está na lista predefinida
            const postoSelect = document.getElementById('modal_posto_gasolina');
            let postoEncontrado = false;
            
            for (let i = 0; i < postoSelect.options.length; i++) {
                if (postoSelect.options[i].value === posto_gasolina) {
                    postoSelect.selectedIndex = i;
                    postoEncontrado = true;
                    break;
                }
            }
            
            // Se não encontrou na lista, selecionar "outro" e preencher o campo
            if (!postoEncontrado && posto_gasolina) {
                for (let i = 0; i < postoSelect.options.length; i++) {
                    if (postoSelect.options[i].value === 'outro') {
                        postoSelect.selectedIndex = i;
                        document.getElementById('outro_posto_modal_container').style.display = 'block';
                        document.getElementById('outro_posto_modal').value = posto_gasolina;
                        break;
                    }
                }
            }
            
            document.getElementById('modal_valor').value = valor;
            document.getElementById('modal_nota_fiscal').value = nota_fiscal;

            document.getElementById('modalEdicao').style.display = 'block';
            
            // Verificar se o combustível foi selecionado corretamente
            console.log("Combustível selecionado:", document.getElementById('modal_combustivel').value);
        }

        function fecharModalEdicao() {
            document.getElementById('modalEdicao').style.display = 'none';
        }

        // Função para formatar a hora
        function formatarHora(hora) {
            // Se a hora já estiver no formato HH:MM, retorna sem alteração
            if (/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/.test(hora)) {
                return hora;
            }
            
            // Tenta converter para HH:MM
            const partes = hora.split(':');
            if (partes.length >= 2) {
                const horas = partes[0].padStart(2, '0');
                const minutos = partes[1].padStart(2, '0');
                return `${horas}:${minutos}`;
            }
            
            // Se não conseguir converter, retorna hora atual como padrão
            return new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
        }

        // Funções para o modal de exclusão
        function abrirModalExclusao(id, veiculo, placa, data) {
            document.getElementById('registro_id_excluir').value = id;
            document.getElementById('exclusao_veiculo').textContent = 'Veículo: ' + veiculo;
            document.getElementById('exclusao_placa').textContent = 'Placa: ' + placa;
            
            // Formatar a data
            let dataFormatada = data;
            if (data.includes('-')) {
                const partes = data.split('-');
                dataFormatada = partes[2] + '/' + partes[1] + '/' + partes[0];
            }
            document.getElementById('exclusao_data').textContent = 'Data: ' + dataFormatada;

            document.getElementById('modalExclusao').style.display = 'block';
        }

        function fecharModalExclusao() {
            document.getElementById('modalExclusao').style.display = 'none';
        }

        // Funções para o modal de adição
        function abrirModalAdicao() {
            // Garantir que o combustível tenha um valor padrão selecionado
            document.getElementById('tipo_combustivel').value = 'Gasolina';
            document.getElementById('modalAdicao').style.display = 'block';
        }

        function fecharModalAdicao() {
            document.getElementById('modalAdicao').style.display = 'none';
        }

        // Fechar os modais se clicar fora deles
        window.onclick = function(event) {
            const modalEdicao = document.getElementById('modalEdicao');
            const modalExclusao = document.getElementById('modalExclusao');
            const modalAdicao = document.getElementById('modalAdicao');
            
            if (event.target == modalEdicao) {
                fecharModalEdicao();
            }
            if (event.target == modalExclusao) {
                fecharModalExclusao();
            }
            if (event.target == modalAdicao) {
                fecharModalAdicao();
            }
        }

        // Função para buscar veículos por prefixo
        function buscarVeiculos(prefixo) {
            if (prefixo.length >= 1) {
                console.log("Buscando veículos com prefixo:", prefixo);
                $.ajax({
                    url: 'buscar_veiuculos_abastecimento.php',
                    type: 'GET',
                    data: { termo: prefixo, tipo: 'prefixo' },
                    dataType: 'json',
                    success: function(data) {
                        console.log("Resultados recebidos:", data);
                        const suggestions = $('#suggestions');
                        suggestions.empty();

                        if (data && data.length > 0) {
                            data.forEach(function(veiculo) {
                                suggestions.append(
                                    `<div class="suggestion-item" onclick="selecionarVeiculo('${veiculo.prefixo}')">
                                        ${veiculo.prefixo} - ${veiculo.placa} (${veiculo.tipo || veiculo.prefixo})
                                     </div>`
                                );
                            });
                            suggestions.show();
                        } else {
                            suggestions.append('<div class="suggestion-item">Nenhum veículo encontrado</div>');
                            suggestions.show();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("Erro ao buscar veículos:", error, "Status:", status, "Resposta:", xhr.responseText);
                        $('#suggestions').html('<div class="suggestion-item">Erro ao buscar veículos</div>').show();
                    }
                });
            } else {
                $('#suggestions').hide();
            }
        }

        // Função para selecionar um veículo da lista de sugestões
        function selecionarVeiculo(prefixo) {
            $('#prefixo_veiculo').val(prefixo);
            $('#suggestions').hide();
        }

        // Função para buscar veículos por prefixo para o modal de adição
        function buscarVeiculosPorPrefixo(prefixo) {
            if (prefixo.length >= 1) {
                console.log("Buscando veículos para adição com prefixo:", prefixo);
                $.ajax({
                    url: 'buscar_veiuculos_abastecimento.php',
                    type: 'GET',
                    data: { termo: prefixo, tipo: 'prefixo' },
                    dataType: 'json',
                    success: function(data) {
                        console.log("Dados recebidos para adição:", data);
                        
                        const suggestions = $('#suggestions_prefixo');
                        suggestions.empty();

                        if (data && data.length > 0) {
                            data.forEach(function(veiculo) {
                                // Escapar valores para evitar problemas com aspas
                                const prefixoEscapado = veiculo.prefixo.replace(/'/g, "\\'").replace(/"/g, '\\"');
                                const placaEscapada = veiculo.placa.replace(/'/g, "\\'").replace(/"/g, '\\"');
                                const veiculoEscapado = (veiculo.tipo || veiculo.prefixo).replace(/'/g, "\\'").replace(/"/g, '\\"');
                                const secretariaEscapada = veiculo.secretaria ? veiculo.secretaria.replace(/'/g, "\\'").replace(/"/g, '\\"') : '';
                                
                                suggestions.append(
                                    `<div class="suggestion-item" onclick="selecionarVeiculoCompleto('${prefixoEscapado}', '${placaEscapada}', '${veiculoEscapado}', '${secretariaEscapada}')">
                                        ${veiculo.prefixo} - ${veiculo.placa} (${veiculo.tipo || veiculo.prefixo})
                                    </div>`
                                );
                            });
                            suggestions.show();
                        } else {
                            suggestions.append('<div class="suggestion-item">Nenhum veículo encontrado</div>');
                            suggestions.show();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("Erro ao buscar veículos para adição:", error, "Status:", status, "Resposta:", xhr.responseText);
                        $('#suggestions_prefixo').html('<div class="suggestion-item">Erro ao buscar veículos</div>').show();
                    }
                });
            } else {
                $('#suggestions_prefixo').hide();
            }
        }

        // Função para selecionar um veículo completo da lista de sugestões
        function selecionarVeiculoCompleto(prefixo, placa, veiculo, secretaria) {
            console.log("Veículo selecionado:", prefixo, placa, veiculo, secretaria);
            $('#prefixo').val(prefixo);
            $('#placa').val(placa);
            $('#veiculo').val(veiculo);
            
            if (secretaria && $('#secretaria').val() === '') {
                $('#secretaria').val(secretaria);
            }
            
            $('#suggestions_prefixo').hide();
            
            // Buscar último KM registrado para esse veículo, se existir função
            try {
                $.get('buscar_ultimo_km_abastecimento.php', { placa: placa }, function(data) {
                    console.log("Resposta KM:", data);
                    if (data && data.km_abastecido) {
                        $('#km_abastecido').val(data.km_abastecido);
                    }
                });
            } catch (e) {
                console.log('Função buscar_ultimo_km_abastecimento.php não disponível ou erro:', e);
            }
        }

        // Função para buscar usuários (modal de adição)
        function buscarUsuarios(termo) {
            buscarUsuariosGenerico(termo, 'suggestions_usuarios', 'nome', 'usuario_id', 'secretaria');
        }

        // Função para buscar usuários (modal de edição)
        function buscarUsuariosModal(termo) {
            buscarUsuariosGenerico(termo, 'modal_suggestions_usuarios', 'modal_nome', 'modal_usuario_id');
        }

        // Função genérica para buscar usuários
        function buscarUsuariosGenerico(termo, containerID, nomeID, idFieldID, secretariaID = null) {
            if (termo.length >= 2) {
                console.log("Buscando usuários com termo:", termo);
                $.ajax({
                    url: 'buscar_usuario.php',
                    type: 'GET',
                    data: { termo: termo },
                    dataType: 'json',
                    success: function(data) {
                        console.log("Usuários encontrados:", data);
                        const suggestions = $(`#${containerID}`);
                        suggestions.empty();

                        if (data && data.length > 0) {
                            data.forEach(function(usuario) {
                                // Formatar informações adicionais para exibição
                                let infoAdicional = [];
                                if (usuario.secretaria) infoAdicional.push(usuario.secretaria);
                                if (usuario.cpf) {
                                    // Formatar CPF se possível
                                    let cpfFormatado = usuario.cpf;
                                    if (usuario.cpf.length === 11) {
                                        cpfFormatado = usuario.cpf.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, "$1.$2.$3-$4");
                                    }
                                    infoAdicional.push(cpfFormatado);
                                }
                                if (usuario.number) infoAdicional.push(usuario.number);
                                
                                const infoText = infoAdicional.length > 0 ? ` - ${infoAdicional.join(' | ')}` : '';
                                
                                // Escapar caracteres especiais para evitar problemas com aspas
                                const nomeEscapado = usuario.name.replace(/'/g, "\\'").replace(/"/g, '\\"');
                                const secretariaEscapada = usuario.secretaria ? usuario.secretaria.replace(/'/g, "\\'").replace(/"/g, '\\"') : '';
                                
                                const onClickAction = secretariaID 
                                    ? `selecionarUsuarioCompleto('${nomeEscapado}', '${secretariaEscapada}', ${usuario.id}, '${nomeID}', '${idFieldID}', '${secretariaID}', '${containerID}')`
                                    : `selecionarUsuarioSimples('${nomeEscapado}', ${usuario.id}, '${nomeID}', '${idFieldID}', '${containerID}')`;
                                
                                suggestions.append(
                                    `<div class="suggestion-item" onclick="${onClickAction}">
                                        <div class="font-medium">${usuario.name}</div>
                                        <div class="text-xs text-gray-600">${infoText}</div>
                                    </div>`
                                );
                            });
                            suggestions.show();
                        } else {
                            suggestions.append('<div class="suggestion-item">Nenhum usuário encontrado</div>');
                            suggestions.show();
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("Erro ao buscar usuários:", error, "Status:", status, "Resposta:", xhr.responseText);
                        $(`#${containerID}`).html('<div class="suggestion-item">Erro ao buscar usuários</div>').show();
                    }
                });
            } else {
                $(`#${containerID}`).hide();
            }
        }

        // Função simplificada para selecionar usuário (modal de edição)
        function selecionarUsuarioSimples(nome, userId, nomeID, idFieldID, containerID) {
            console.log("Selecionando usuário:", nome, userId);
            $(`#${nomeID}`).val(nome);
            $(`#${idFieldID}`).val(userId);
            $(`#${containerID}`).hide();
        }

        // Função completa para selecionar usuário com secretaria (modal de adição)
        function selecionarUsuarioCompleto(nome, secretaria, userId, nomeID, idFieldID, secretariaID, containerID) {
            console.log("Selecionando usuário completo:", nome, secretaria, userId);
            // Preencher o nome e ID do usuário
            $(`#${nomeID}`).val(nome);
            $(`#${idFieldID}`).val(userId);
            $(`#${containerID}`).hide();
            
            // Preencher a secretaria automaticamente se disponível e o campo estiver vazio
            if (secretaria && $(`#${secretariaID}`).val() === '') {
                // Verificar diretamente se é uma secretaria válida
                $(`#${secretariaID}`).val(secretaria);
            }
        }

        // Lidar com a seleção de "outro" posto no modal de adição
        $('#posto').change(function() {
            if ($(this).val() === 'outro') {
                $('#outro_posto_container').show();
                $('#outro_posto').attr('required', true);
            } else {
                $('#outro_posto_container').hide();
                $('#outro_posto').attr('required', false);
                // Recalcular o valor quando o posto mudar
                calcularValorAbastecimento();
            }
        });

        // Lidar com a seleção de "outro" posto no modal de edição
        $('#modal_posto_gasolina').change(function() {
            if ($(this).val() === 'outro') {
                $('#outro_posto_modal_container').show();
                $('#outro_posto_modal').attr('required', true);
            } else {
                $('#outro_posto_modal_container').hide();
                $('#outro_posto_modal').attr('required', false);
                // Recalcular o valor quando o posto mudar
                calcularValorAbastecimentoModal();
            }
        });

        //*****CALCULO DO ABASTECIMENTO - MODAL ADICIONAR */
        function calcularValorAbastecimento() {
            const litros = parseFloat(document.getElementById('litros').value.replace(',', '.')) || 0;
            const posto = document.getElementById('posto').value;
            const tipoCombustivel = document.getElementById('tipo_combustivel').value;

            if (litros > 0 && posto && tipoCombustivel) {
                const precoCombustivel = precosCombustiveis.find(preco => preco.posto_nome === posto && preco.tipo_combustivel === tipoCombustivel);
                const preco = precoCombustivel ? parseFloat(precoCombustivel.preco) : 0;

                if (preco > 0) {
                    const valorAbastecimento = litros * preco;
                    document.getElementById('valor_abastecimento').value = valorAbastecimento.toFixed(2).replace('.', ',');
                }
            }
        }

        //*****CALCULO DO ABASTECIMENTO - MODAL EDITAR */
        function calcularValorAbastecimentoModal() {
            const litros = parseFloat(document.getElementById('modal_litros').value.replace(',', '.')) || 0;
            const posto = document.getElementById('modal_posto_gasolina').value;
            const tipoCombustivel = document.getElementById('modal_combustivel').value;

            if (litros > 0 && posto && tipoCombustivel) {
                const precoCombustivel = precosCombustiveis.find(preco => preco.posto_nome === posto && preco.tipo_combustivel === tipoCombustivel);
                const preco = precoCombustivel ? parseFloat(precoCombustivel.preco) : 0;

                if (preco > 0) {
                    const valorAbastecimento = litros * preco;
                    document.getElementById('modal_valor').value = valorAbastecimento.toFixed(2);
                }
            }
        }

        // Ajustar o formEdicao para lidar com campo "outro posto" e garantir seleção de combustível
        $("#formEdicao").on("submit", function(e) {
            console.log("Formulário de edição sendo enviado");
            
            // Verificar se um combustível foi selecionado
            var combustivel = $('#modal_combustivel').val();
            if (!combustivel || combustivel === '') {
                $('#modal_combustivel').val('Gasolina'); // Define um valor padrão
                console.log("Combustível não selecionado, definindo como Gasolina");
            }
            
            if ($('#modal_posto_gasolina').val() === 'outro' && $('#outro_posto_modal').val().trim() !== '') {
                // Substituir o valor do posto_gasolina pelo valor digitado em outro_posto_modal
                $('#modal_posto_gasolina').val($('#outro_posto_modal').val().trim());
            }
            
            // Verificação adicional dos campos obrigatórios
            let camposObrigatorios = ['modal_nome', 'modal_data', 'modal_hora', 'modal_litros', 'modal_valor'];
            let temErro = false;
            let camposFaltantes = [];
            
            camposObrigatorios.forEach(function(campo) {
                let elemento = document.getElementById(campo);
                if (elemento && !elemento.value.trim()) {
                    console.error(`Campo obrigatório não preenchido: ${campo}`);
                    elemento.classList.add('border-red-500');
                    temErro = true;
                    
                    let labelElement = document.querySelector(`label[for="${campo}"]`);
                    let labelText = labelElement ? labelElement.textContent.replace(' *', '') : campo;
                    camposFaltantes.push(labelText);
                } else if (elemento) {
                    elemento.classList.remove('border-red-500');
                }
            });

            // Verificar especificamente o campo de combustível
            if (!$('#modal_combustivel').val()) {
                $('#modal_combustivel').addClass('border-red-500');
                temErro = true;
                camposFaltantes.push('Combustível');
            } else {
                $('#modal_combustivel').removeClass('border-red-500');
            }
            
            if (temErro) {
                e.preventDefault();
                alert("Por favor, preencha os seguintes campos obrigatórios: " + camposFaltantes.join(", "));
                return false;
            }
            
            console.log("Formulário de edição válido, enviando...");
            return true;
        });

        // Ajustar o formAdicao para lidar com campo "outro posto" e garantir seleção de combustível
        $("#formAdicao").on("submit", function(e) {
            console.log("Formulário de adição sendo enviado");
            
            // Verificar se um combustível foi selecionado
            var combustivel = $('#tipo_combustivel').val();
            if (!combustivel || combustivel === '') {
                $('#tipo_combustivel').val('Gasolina'); // Define um valor padrão
                console.log("Combustível não selecionado, definindo como Gasolina");
            }
            
            // Verificar se o posto de gasolina é "outro" e ajustar o valor
            if ($('#posto').val() === 'outro' && $('#outro_posto').val().trim() !== '') {
                // Substituir o valor do posto_gasolina pelo valor digitado em outro_posto
                $('#posto').val($('#outro_posto').val().trim());
            }
            
            // Verificação adicional dos campos obrigatórios
            let camposObrigatorios = ['nome', 'prefixo', 'placa', 'veiculo', 'data', 'hora', 'litros', 'valor_abastecimento'];
            let temErro = false;
            let camposFaltantes = [];
            
            camposObrigatorios.forEach(function(campo) {
                let elemento = document.getElementById(campo);
                if (elemento && !elemento.value.trim()) {
                    console.error(`Campo obrigatório não preenchido: ${campo}`);
                    elemento.classList.add('border-red-500');
                    temErro = true;
                    
                    let labelElement = document.querySelector(`label[for="${campo}"]`);
                    let labelText = labelElement ? labelElement.textContent.replace(' *', '') : campo;
                    camposFaltantes.push(labelText);
                } else if (elemento) {
                    elemento.classList.remove('border-red-500');
                }
            });

            // Verificar especificamente o campo de combustível
            if (!$('#tipo_combustivel').val()) {
                $('#tipo_combustivel').addClass('border-red-500');
                temErro = true;
                camposFaltantes.push('Combustível');
            } else {
                $('#tipo_combustivel').removeClass('border-red-500');
            }
            
            if (temErro) {
                e.preventDefault();
                alert("Por favor, preencha os seguintes campos obrigatórios: " + camposFaltantes.join(", "));
                return false;
            }
            
            console.log("Formulário de adição válido, enviando...");
            return true;
        });

        // Esconder sugestões quando clicar em outro lugar
        $(document).click(function(event) {
            if (!$(event.target).closest('.suggestions-container').length) {
                $('.suggestions-list').hide();
            }
        });

        // Verificar se há mensagens de erro no console e inicializar a página
        $(document).ready(function() {
            console.log("Página carregada. Usuário: <?= $usuario_logado ?>, Data: <?= date('Y-m-d H:i:s') ?>");
            console.log("Verificando se os scripts de busca estão funcionando...");
            
            // Definir valor padrão para os campos de combustível
            $('#modal_combustivel').val('Gasolina');
            $('#tipo_combustivel').val('Gasolina');
            
            // Tentativa inicial de busca para verificar se estão funcionando os arquivos
            $.ajax({
                url: 'buscar_veiuculos_abastecimento.php',
                type: 'GET',
                data: { termo: '' },
                success: function(data) {
                    console.log("Conexão com buscar_veiuculos_abastecimento.php está funcionando.");
                },
                error: function(xhr, status, error) {
                    console.error("Erro na conexão com buscar_veiuculos_abastecimento.php:", error);
                    alert("Existe um problema com a busca de veículos. Por favor, contate o administrador do sistema.");
                }
            });
        });
    </script>
</body>
</html>