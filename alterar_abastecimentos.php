<?php
session_start();
include 'conexao.php'; // Assume que este arquivo configura $conn (PDO)

date_default_timezone_set('America/Cuiaba');
// Verificar se o usuário é admin
if ($_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'geraladm') {
    header('Location: index.php');
    exit;
}

// Configurar conexão para usar UTF-8
if (isset($conn)) {
    $conn->exec("SET NAMES utf8");
} else {
    // Definir mensagens de erro na sessão para exibição via AJAX ou recarregamento de página
    $_SESSION['mensagem'] = "Erro crítico: A conexão com o banco de dados não foi estabelecida.";
    $_SESSION['tipo_mensagem'] = "error";
    error_log("Conexão com o banco de dados não estabelecida em alterar_abastecimentos.php");

    // Se for uma requisição AJAX, retornar erro em JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        http_response_code(500); // Internal Server Error
        echo json_encode([
            'success' => false,
            'message' => $_SESSION['mensagem']
        ]);
        unset($_SESSION['mensagem']); 
        unset($_SESSION['tipo_mensagem']);
        exit;
    }
    // Para requisições normais, pode redirecionar ou apenas deixar a mensagem ser exibida no HTML
    // header('Location: pagina_de_erro_critico.php'); 
    // exit;
}

$usuario_logado = $_SESSION['username'] ?? 'desconhecido';
$data_hora_atual = date('Y-m-d H:i:s');
$ip_usuario = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

function registrarOperacao($conn, $registro_id, $admin_id, $tipo_operacao, $dados_antes = null, $dados_depois = null) {
    try {
        $query_admin = "SELECT name FROM usuarios WHERE id = :admin_id";
        $stmt_admin = $conn->prepare($query_admin);
        $stmt_admin->bindParam(':admin_id', $admin_id);
        $stmt_admin->execute();
        $admin = $stmt_admin->fetch(PDO::FETCH_ASSOC);
        $admin_nome = $admin['name'] ?? 'Desconhecido';
        
        $registro_completo = null;
        if ($tipo_operacao === 'ALTERACAO' && $dados_antes && $dados_depois) {
            $registro_completo = json_encode(['antes' => $dados_antes, 'depois' => $dados_depois], JSON_UNESCAPED_UNICODE);
        } else if ($tipo_operacao === 'ADICAO' && $dados_depois) {
            $registro_completo = json_encode($dados_depois, JSON_UNESCAPED_UNICODE);
        } else if ($tipo_operacao === 'EXCLUSAO' && $dados_antes) {
            $registro_completo = json_encode($dados_antes, JSON_UNESCAPED_UNICODE);
        }
        
        $query_historico = "INSERT INTO historico_abastecimento (
                    registro_id, admin_id, admin_nome, tipo_operacao,
                    campo_alterado, valor_antigo, valor_novo, 
                    registro_completo, data_hora, ip_usuario
                  ) VALUES (
                    :registro_id, :admin_id, :admin_nome, :tipo_operacao,
                    'REGISTRO_COMPLETO', :valor_antigo, :valor_novo,
                    :registro_completo, NOW(), :ip_usuario )";
        $stmt_hist = $conn->prepare($query_historico);
        $stmt_hist->bindParam(':registro_id', $registro_id);
        $stmt_hist->bindParam(':admin_id', $admin_id);
        $stmt_hist->bindParam(':admin_nome', $admin_nome);
        $stmt_hist->bindParam(':tipo_operacao', $tipo_operacao);
        $valor_antigo_hist = ($tipo_operacao === 'EXCLUSAO') ? 'Registro excluído' : (($tipo_operacao === 'ALTERACAO') ? 'Registro alterado' : 'N/A');
        $valor_novo_hist = ($tipo_operacao === 'ADICAO') ? 'Registro adicionado' : (($tipo_operacao === 'ALTERACAO') ? 'Registro alterado' : 'N/A');
        $stmt_hist->bindParam(':valor_antigo', $valor_antigo_hist);
        $stmt_hist->bindParam(':valor_novo', $valor_novo_hist);
        $stmt_hist->bindParam(':registro_completo', $registro_completo);
        $stmt_hist->bindParam(':ip_usuario', $GLOBALS['ip_usuario']);
        return $stmt_hist->execute();
    } catch (Exception $e) {
        error_log("Erro ao registrar operação no histórico: " . $e->getMessage());
        return false;
    }
}

function processarUploadNotaFiscal($file_input_name, $upload_sub_dir = "notas_fiscais/") {
    if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] == UPLOAD_ERR_OK) {
        $upload_base_dir = "uploads/";
        $upload_dir = $upload_base_dir . $upload_sub_dir;

        if (!is_dir($upload_base_dir)) { if (!mkdir($upload_base_dir, 0775, true)) { error_log("Falha ao criar diretório base: " . $upload_base_dir); return null; }}
        if (!is_dir($upload_dir)) { if (!mkdir($upload_dir, 0775, true)) { error_log("Falha ao criar subdiretório: " . $upload_dir); return null; }}
        
        // Verificação de permissão de escrita movida para depois da criação dos diretórios
        if (!is_writable($upload_base_dir)) { error_log("Erro de permissão no servidor para upload (diretório base: $upload_base_dir)."); throw new Exception("Erro de permissão no servidor para upload de nota fiscal (diretório base: $upload_base_dir).");}
        if (!is_writable($upload_dir)) { error_log("Erro de permissão no servidor para upload (diretório: $upload_dir)."); throw new Exception("Erro de permissão no servidor para upload de nota fiscal (diretório: $upload_dir).");}


        $tmp_name = $_FILES[$file_input_name]['tmp_name'];
        $original_filename = basename($_FILES[$file_input_name]['name']);
        $safe_filename = preg_replace("/[^a-zA-Z0-9.\-_]/", "", $original_filename);
        $file_extension = strtolower(pathinfo($safe_filename, PATHINFO_EXTENSION));
        $unique_filename = 'nf_' . uniqid() . '_' . time() . '.' . $file_extension;
        $target_file = $upload_dir . $unique_filename;
        $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];

        if (!in_array($file_extension, $allowed_types)) throw new Exception("Tipo de arquivo da nota fiscal inválido (Permitidos: JPG, JPEG, PNG, GIF, PDF).");
        if ($_FILES[$file_input_name]['size'] > 5 * 1024 * 1024) throw new Exception("Arquivo da nota fiscal muito grande (Máx: 5MB).");
        if (move_uploaded_file($tmp_name, $target_file)) {
            error_log("Nota fiscal carregada: " . $target_file);
            return $target_file;
        } else {
            error_log("Falha ao mover arquivo: " . $target_file . ". Erro PHP: " . $_FILES[$file_input_name]['error']);
            throw new Exception("Erro ao salvar o arquivo da nota fiscal.");
        }
    } else if (isset($_FILES[$file_input_name]) && $_FILES[$file_input_name]['error'] != UPLOAD_ERR_NO_FILE) {
        $phpFileUploadErrors = array(
            0 => 'There is no error, the file uploaded with success',
            1 => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
            2 => 'The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form',
            3 => 'The uploaded file was only partially uploaded',
            4 => 'No file was uploaded', // Should not throw an exception here if file is optional
            6 => 'Missing a temporary folder',
            7 => 'Failed to write file to disk.',
            8 => 'A PHP extension stopped the file upload.',
        );
        $error_message = $phpFileUploadErrors[$_FILES[$file_input_name]['error']] ?? "Unknown upload error";
        error_log("Erro no upload da nota fiscal. Código: " . $_FILES[$file_input_name]['error'] . ". Mensagem: " . $error_message);
        throw new Exception("Erro no upload da nota fiscal: " . $error_message . " (Código: " . $_FILES[$file_input_name]['error'] . ")");
    }
    return null; 
}

// Processar atualização de abastecimento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registro_id']) && !isset($_POST['action'])) {
    $novo_caminho_nota_fiscal = null;
    $is_xhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    try {
        if (!$conn) throw new Exception("Conexão com banco de dados falhou antes da transação.");
        $conn->beginTransaction();
        $registro_id = $_POST['registro_id'];
        $nome = trim($_POST['nome']);
        $data = $_POST['data'];
        $hora = $_POST['hora']; // Hora já vem formatada do JS H:i
        if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $hora)) {
             // Tentar formatar se vier H:i:s
            $parsed_time = strtotime($hora);
            if ($parsed_time !== false) {
                $hora = date('H:i', $parsed_time);
                if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $hora)) {
                    throw new Exception("Formato de hora inválido após tentativa de correção.");
                }
            } else {
                throw new Exception("Formato de hora inválido.");
            }
        }
        
        $km_abastecido = !empty($_POST['km_abastecido']) ? intval($_POST['km_abastecido']) : null;
        $litros = isset($_POST['litros']) ? floatval(str_replace(',', '.', $_POST['litros'])) : 0;
        $combustivel_post = trim($_POST['combustivel']);
        $posto_gasolina = !empty($_POST['posto_gasolina']) && $_POST['posto_gasolina'] !== 'outro' ? 
                            trim($_POST['posto_gasolina']) : 
                            (!empty($_POST['outro_posto_modal_input']) ? trim($_POST['outro_posto_modal_input']) : null);
        $valor = isset($_POST['valor']) ? floatval(str_replace(',', '.', $_POST['valor'])) : 0;
        $admin_id = $_SESSION['user_id'];

        if (empty($nome) || empty($data) || empty($hora) || $litros <= 0 || empty($combustivel_post) || $valor <= 0) {
            throw new Exception("Todos os campos obrigatórios devem ser preenchidos e valores numéricos devem ser positivos.");
        }
        $combustiveis_validos = ['Gasolina', 'Etanol', 'Diesel-S10', 'Diesel-S500'];
        if (!in_array($combustivel_post, $combustiveis_validos)) throw new Exception("Tipo de combustível inválido.");

        $query_select_antigo = "SELECT * FROM registro_abastecimento WHERE id = :id";
        $stmt_select_antigo = $conn->prepare($query_select_antigo);
        $stmt_select_antigo->bindParam(':id', $registro_id);
        $stmt_select_antigo->execute();
        $registro_antigo_db = $stmt_select_antigo->fetch(PDO::FETCH_ASSOC);
        if (!$registro_antigo_db) throw new Exception("Registro não encontrado.");

        $caminho_nota_fiscal_final = $registro_antigo_db['nota_fiscal']; 

        $novo_caminho_nota_fiscal = processarUploadNotaFiscal('modal_nota_fiscal_arquivo');

        if ($novo_caminho_nota_fiscal) {
            if ($registro_antigo_db['nota_fiscal'] && strpos($registro_antigo_db['nota_fiscal'], 'uploads/notas_fiscais/') === 0 && file_exists($registro_antigo_db['nota_fiscal'])) {
                unlink($registro_antigo_db['nota_fiscal']);
                error_log("Nota fiscal antiga excluída (edição): " . $registro_antigo_db['nota_fiscal']);
            }
            $caminho_nota_fiscal_final = $novo_caminho_nota_fiscal;
        }
        
        $query_update = "UPDATE registro_abastecimento SET 
                 nome = :nome, data = :data, hora = :hora, km_abastecido = :km_abastecido, 
                 litros = :litros, combustivel = :combustivel, posto_gasolina = :posto_gasolina,
                 valor = :valor, nota_fiscal = :nota_fiscal
                 WHERE id = :id";
        $stmt_update = $conn->prepare($query_update);
        $params_update = [
            ':nome' => $nome, ':data' => $data, ':hora' => $hora, ':km_abastecido' => $km_abastecido,
            ':litros' => $litros, ':combustivel' => $combustivel_post, ':posto_gasolina' => $posto_gasolina,
            ':valor' => $valor, ':nota_fiscal' => $caminho_nota_fiscal_final, ':id' => $registro_id
        ];
        
        if ($stmt_update->execute($params_update)) {
            $query_select_novo = "SELECT * FROM registro_abastecimento WHERE id = :id";
            $stmt_select_novo = $conn->prepare($query_select_novo);
            $stmt_select_novo->bindParam(':id', $registro_id);
            $stmt_select_novo->execute();
            $registro_novo_db = $stmt_select_novo->fetch(PDO::FETCH_ASSOC);
            registrarOperacao($conn, $registro_id, $admin_id, 'ALTERACAO', $registro_antigo_db, $registro_novo_db);
            $conn->commit();
            $_SESSION['mensagem'] = "Registro atualizado com sucesso!"; $_SESSION['tipo_mensagem'] = "success";
        } else {
            $conn->rollBack();
            if ($novo_caminho_nota_fiscal && file_exists($novo_caminho_nota_fiscal)) {unlink($novo_caminho_nota_fiscal); error_log("Upload NF (edição) revertido: " . $novo_caminho_nota_fiscal);}
            $_SESSION['mensagem'] = "Erro ao atualizar registro."; $_SESSION['tipo_mensagem'] = "error";
            error_log("Falha no UPDATE: " . print_r($stmt_update->errorInfo(), true));
        }
    } catch (Exception $e) {
        if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
        if ($novo_caminho_nota_fiscal && file_exists($novo_caminho_nota_fiscal)) {unlink($novo_caminho_nota_fiscal); error_log("Upload NF (edição) revertido (Exceção): " . $novo_caminho_nota_fiscal);}
        $_SESSION['mensagem'] = "Erro: " . $e->getMessage(); $_SESSION['tipo_mensagem'] = "error";
        error_log("Exceção ao alterar registro: " . $e->getMessage() . " | Linha: " . $e->getLine() . " | Arquivo: " . $e->getFile());
    }

    if ($is_xhr) {
        http_response_code(200); // Mesmo para erros de lógica de negócio, o request HTTP foi ok
        echo json_encode([
            'success' => ($_SESSION['tipo_mensagem'] ?? 'error') === 'success',
            'message' => $_SESSION['mensagem'] ?? 'Operação finalizada com status desconhecido.',
            'redirectUrl' => "alterar_abastecimentos.php?" . http_build_query($_GET) // Mantém filtros
        ]);
        unset($_SESSION['mensagem']); unset($_SESSION['tipo_mensagem']);
        exit;
    } else {
        header("Location: alterar_abastecimentos.php?" . http_build_query($_GET));
        exit;
    }
}

// Processar adição de novo registro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'adicionar') {
    $nota_fiscal_path_adicao = null; 
    $is_xhr = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    try {
        if (!$conn) throw new Exception("Conexão com banco de dados falhou antes da transação.");
        $conn->beginTransaction();

        $nome = isset($_POST['nome']) ? trim($_POST['nome']) : '';
        $data = isset($_POST['data']) ? $_POST['data'] : '';
        $hora = isset($_POST['hora']) ? $_POST['hora'] : ''; // Hora já vem formatada do JS H:i
         if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $hora)) {
            $parsed_time = strtotime($hora); // Tenta corrigir se vier H:i:s
            if ($parsed_time !== false) {
                $hora = date('H:i', $parsed_time);
                if (!preg_match('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/', $hora)) {
                    throw new Exception("Formato de hora inválido após tentativa de correção.");
                }
            } else {
                throw new Exception("Formato de hora inválido.");
            }
        }

        $prefixo = isset($_POST['prefixo']) && !empty($_POST['prefixo']) ? trim($_POST['prefixo']) : null;
        $placa = isset($_POST['placa']) ? trim($_POST['placa']) : '';
        $veiculo = isset($_POST['veiculo']) ? trim($_POST['veiculo']) : '';
        $secretaria = isset($_POST['secretaria']) && !empty($_POST['secretaria']) ? trim($_POST['secretaria']) : null;
        $km_abastecido = isset($_POST['km_abastecido']) && !empty($_POST['km_abastecido']) ? intval($_POST['km_abastecido']) : null;
        $litros = isset($_POST['litros']) ? floatval(str_replace(',', '.', $_POST['litros'])) : 0;
        $combustivel_post = isset($_POST['combustivel']) ? trim($_POST['combustivel']) : '';
        $posto_gasolina = isset($_POST['posto_gasolina']) && !empty($_POST['posto_gasolina']) && $_POST['posto_gasolina'] !== 'outro' ? 
                            trim($_POST['posto_gasolina']) : 
                            (isset($_POST['outro_posto_input']) && !empty($_POST['outro_posto_input']) ? trim($_POST['outro_posto_input']) : null);
        $valor = isset($_POST['valor']) ? floatval(str_replace(',', '.', $_POST['valor'])) : 0;
        $admin_id = $_SESSION['user_id'];

        $nota_fiscal_path_adicao = processarUploadNotaFiscal('nota_fiscal_arquivo');
        
        $campos_faltando = [];
        if (empty($nome)) $campos_faltando[] = 'Nome do Motorista'; 
        if (empty($data)) $campos_faltando[] = 'Data';
        if (empty($hora)) $campos_faltando[] = 'Hora'; 
        if (empty($placa)) $campos_faltando[] = 'Placa do Veículo';
        if (empty($veiculo)) $campos_faltando[] = 'Veículo'; 
        if (empty($litros) || $litros <= 0) $campos_faltando[] = 'Litros (>0)';
        if (empty($combustivel_post)) $campos_faltando[] = 'Combustível'; 
        if (empty($valor) || $valor <= 0) $campos_faltando[] = 'Valor (>0)';
        if (!empty($campos_faltando)) throw new Exception("Preencha os campos obrigatórios: " . implode(", ", $campos_faltando) . " e valores numéricos devem ser positivos.");
        
        $combustiveis_validos = ['Gasolina', 'Etanol', 'Diesel-S10', 'Diesel-S500'];
        if (!in_array($combustivel_post, $combustiveis_validos)) throw new Exception("Tipo de combustível inválido.");
        
        $dados_registro = [
            'nome' => $nome, 'data' => $data, 'hora' => $hora, 'prefixo' => $prefixo, 'placa' => $placa, 
            'veiculo' => $veiculo, 'secretaria' => $secretaria, 'km_abastecido' => $km_abastecido, 
            'litros' => $litros, 'combustivel' => $combustivel_post, 'posto_gasolina' => $posto_gasolina, 
            'valor' => $valor, 'nota_fiscal' => $nota_fiscal_path_adicao
        ];
        $query_insert = "INSERT INTO registro_abastecimento (nome, data, hora, prefixo, placa, veiculo, secretaria, 
                 km_abastecido, litros, combustivel, posto_gasolina, valor, nota_fiscal) 
                 VALUES (:nome, :data, :hora, :prefixo, :placa, :veiculo, :secretaria, 
                 :km_abastecido, :litros, :combustivel, :posto_gasolina, :valor, :nota_fiscal)";
        $stmt_insert = $conn->prepare($query_insert);
        // Bind dos parâmetros individualmente para melhor controle de tipo, se necessário (PDO geralmente lida bem)
        foreach ($dados_registro as $campo => $valor_campo_bind) {
             $stmt_insert->bindValue(':' . $campo, $valor_campo_bind);
        }
        
        if ($stmt_insert->execute()) {
            $registro_id_inserido = $conn->lastInsertId();
            $query_select_inserido = "SELECT * FROM registro_abastecimento WHERE id = :id";
            $stmt_select_inserido = $conn->prepare($query_select_inserido);
            $stmt_select_inserido->bindParam(':id', $registro_id_inserido);
            $stmt_select_inserido->execute();
            $registro_inserido_db = $stmt_select_inserido->fetch(PDO::FETCH_ASSOC);
            registrarOperacao($conn, $registro_id_inserido, $admin_id, 'ADICAO', null, $registro_inserido_db);
            $conn->commit();
            $_SESSION['mensagem'] = "Novo registro adicionado com sucesso!"; $_SESSION['tipo_mensagem'] = "success";
        } else {
            $conn->rollBack();
            if ($nota_fiscal_path_adicao && file_exists($nota_fiscal_path_adicao)) { unlink($nota_fiscal_path_adicao); error_log("Upload NF (adição) revertido (falha DB): " . $nota_fiscal_path_adicao); }
            $_SESSION['mensagem'] = "Erro ao adicionar novo registro."; $_SESSION['tipo_mensagem'] = "error";
            error_log("Falha no INSERT: " . print_r($stmt_insert->errorInfo(), true));
        }
    } catch (Exception $e) { 
        if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
        if ($nota_fiscal_path_adicao && file_exists($nota_fiscal_path_adicao) ) { unlink($nota_fiscal_path_adicao); error_log("Upload NF (adição) revertido (Exceção): " . $nota_fiscal_path_adicao); }
        $_SESSION['mensagem'] = "Erro: " . $e->getMessage(); $_SESSION['tipo_mensagem'] = "error";
        error_log("Erro ao adicionar: " . $e->getMessage() . " | Linha: " . $e->getLine() . " | Arquivo: " . $e->getFile() . ($e instanceof PDOException ? " | SQLSTATE: " . $e->getCode() : ""));
    }

    if ($is_xhr) {
        http_response_code(200);
        echo json_encode([
            'success' => ($_SESSION['tipo_mensagem'] ?? 'error') === 'success',
            'message' => $_SESSION['mensagem'] ?? 'Operação finalizada com status desconhecido.',
            'redirectUrl' => "alterar_abastecimentos.php?" . http_build_query($_GET)
        ]);
        unset($_SESSION['mensagem']); unset($_SESSION['tipo_mensagem']);
        exit;
    } else {
        header("Location: alterar_abastecimentos.php?" . http_build_query($_GET));
        exit;
    }
}

// Processar exclusão de registro
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'excluir') {
    try {
        if (!$conn) throw new Exception("Conexão com banco de dados falhou antes da transação.");
        $conn->beginTransaction();
        $registro_id_excluir = $_POST['registro_id_excluir']; $admin_id = $_SESSION['user_id'];
        if (empty($registro_id_excluir)) throw new Exception("ID do registro não fornecido.");
        
        $query_select_excluir = "SELECT * FROM registro_abastecimento WHERE id = :id";
        $stmt_select_excluir = $conn->prepare($query_select_excluir);
        $stmt_select_excluir->bindParam(':id', $registro_id_excluir);
        $stmt_select_excluir->execute();
        $registro_excluir_db = $stmt_select_excluir->fetch(PDO::FETCH_ASSOC);
        if (!$registro_excluir_db) throw new Exception("Registro não encontrado para exclusão.");
        
        $nota_fiscal_a_excluir = $registro_excluir_db['nota_fiscal'];
        registrarOperacao($conn, $registro_id_excluir, $admin_id, 'EXCLUSAO', $registro_excluir_db, null);
        
        $query_delete = "DELETE FROM registro_abastecimento WHERE id = :id";
        $stmt_delete = $conn->prepare($query_delete);
        $stmt_delete->bindParam(':id', $registro_id_excluir);
        
        if ($stmt_delete->execute()) {
            if ($nota_fiscal_a_excluir && strpos($nota_fiscal_a_excluir, 'uploads/notas_fiscais/') === 0 && file_exists($nota_fiscal_a_excluir)) { 
                if (unlink($nota_fiscal_a_excluir)) error_log("Arquivo NF excluído: " . $nota_fiscal_a_excluir);
                else error_log("Falha ao excluir arquivo NF: " . $nota_fiscal_a_excluir);
            }
            $conn->commit();
            $_SESSION['mensagem'] = "Registro excluído com sucesso!"; $_SESSION['tipo_mensagem'] = "success";
        } else {
            $conn->rollBack(); 
            $_SESSION['mensagem'] = "Erro ao excluir registro."; $_SESSION['tipo_mensagem'] = "error";
            error_log("Falha no DELETE: " . print_r($stmt_delete->errorInfo(), true));
        }
    } catch (Exception $e) {
        if (isset($conn) && $conn->inTransaction()) $conn->rollBack();
        $_SESSION['mensagem'] = "Erro: " . $e->getMessage(); $_SESSION['tipo_mensagem'] = "error"; 
        error_log("Erro ao excluir: " . $e->getMessage());
    }
    // Standard redirect for delete as it's not handled by AJAX with progress bar
    header("Location: alterar_abastecimentos.php?" . http_build_query($_GET));
    exit;
}

// Configuração de paginação e busca de registros
$registros_por_pagina = 20; 
$pagina_atual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1; 
if ($pagina_atual < 1) $pagina_atual = 1;

$inicio = ($pagina_atual - 1) * $registros_por_pagina; 
$prefixo_veiculo_filtro = $_GET['prefixo_veiculo'] ?? '';
$data_inicial_filtro = $_GET['data_inicial'] ?? ''; 
$data_final_filtro = $_GET['data_final'] ?? ''; 
$combustivel_filtro = $_GET['combustivel'] ?? '';

function buscarRegistros($conn, $prefixo_veiculo, $data_inicial, $data_final, $combustivel, $secretaria_admin, $role, $inicio_offset, $limite) {
    if (!$conn) { return ['registros' => [], 'total' => 0]; } // Handle no connection
    $base_query_from = "FROM registro_abastecimento r LEFT JOIN veiculos v ON r.placa = v.placa";
    $where_conditions = " WHERE 1=1"; $params = [];
    if (!empty($prefixo_veiculo)) { $where_conditions .= " AND (v.veiculo LIKE :prefixo_veiculo OR r.prefixo LIKE :prefixo_veiculo OR r.veiculo LIKE :prefixo_veiculo)"; $params[':prefixo_veiculo'] = "%$prefixo_veiculo%"; }
    if (!empty($data_inicial)) { 
        $where_conditions .= ($data_final ? " AND r.data BETWEEN :data_inicial AND :data_final" : " AND r.data >= :data_inicial"); 
        $params[':data_inicial'] = $data_inicial; 
        if ($data_final) $params[':data_final'] = $data_final;
    } elseif (!empty($data_final)) { 
        $where_conditions .= " AND r.data <= :data_final"; $params[':data_final'] = $data_final; 
    }
    if (!empty($combustivel)) { $where_conditions .= " AND r.combustivel = :combustivel_filter"; $params[':combustivel_filter'] = $combustivel; }
    if ($role !== 'geraladm' && !empty($secretaria_admin)) { $where_conditions .= " AND r.secretaria = :secretaria_filter"; $params[':secretaria_filter'] = $secretaria_admin; }
    
    $count_query_sql = "SELECT COUNT(DISTINCT r.id) as total " . $base_query_from . $where_conditions;
    $stmt_count = $conn->prepare($count_query_sql); 
    $stmt_count->execute($params);
    $total_registros = (int)($stmt_count->fetch(PDO::FETCH_ASSOC)['total'] ?? 0);
    
    $data_query_sql = "SELECT r.* " . $base_query_from . $where_conditions . " GROUP BY r.id ORDER BY r.data DESC, r.hora DESC LIMIT :inicio_offset, :limite";
    $stmt_data = $conn->prepare($data_query_sql);
    foreach ($params as $key => $value) $stmt_data->bindValue($key, $value);
    $stmt_data->bindValue(':inicio_offset', (int)$inicio_offset, PDO::PARAM_INT); 
    $stmt_data->bindValue(':limite', (int)$limite, PDO::PARAM_INT);
    $stmt_data->execute(); 
    return ['registros' => $stmt_data->fetchAll(PDO::FETCH_ASSOC), 'total' => $total_registros];
}
function buscarSecretarias() { return [ "Gabinete do Prefeito", "Gabinete do Vice-Prefeito", "Secretaria Municipal da Mulher de Família", "Secretaria Municipal de Fazenda", "Secretaria Municipal de Educação", "Secretaria Municipal de Agricultura e Meio Ambiente", "Secretaria Municipal de Agricultura Familiar e Segurança Alimentar", "Secretaria Municipal de Assistência Social", "Secretaria Municipal de Desenvolvimento Econômico e Turismo", "Secretaria Municipal de Administração", "Secretaria Municipal de Governo", "Secretaria Municipal de Infraestrutura, Transportes e Saneamento", "Secretaria Municipal de Esporte e Lazer e Juventude", "Secretaria Municipal da Cidade", "Secretaria Municipal de Saúde", "Secretaria Municipal de Segurança Pública, Trânsito e Defesa Civil", "Controladoria Geral do Município", "Procuradoria Geral do Município", "Secretaria Municipal de Cultura", "Secretaria Municipal de Planejamento, Ciência, Tecnologia e Inovação", "Secretaria Municipal de Obras e Serviços Públicos" ]; }
function buscarCombustiveis() { return ['Gasolina', 'Etanol', 'Diesel-S10', 'Diesel-S500']; }

$secretarias_map = [ "Gabinete do Prefeito" => "GABINETE DO PREFEITO", "Gabinete do Vice-Prefeito" => "GABINETE DO VICE-PREFEITO", "Secretaria Municipal da Mulher de Família" => "SECRETARIA DA MULHER", "Secretaria Municipal de Fazenda" => "SECRETARIA DE FAZENDA", "Secretaria Municipal de Educação" => "SECRETARIA DE EDUCAÇÃO", "Secretaria Municipal de Agricultura e Meio Ambiente" => "SECRETARIA DE AGRICULTURA E MEIO AMBIENTE", "Secretaria Municipal de Agricultura Familiar e Segurança Alimentar" => "SECRETARIA DE AGRICULTURA FAMILIAR", "Secretaria Municipal de Assistência Social" => "SECRETARIA DE ASSISTÊNCIA SOCIAL", "Secretaria Municipal de Desenvolvimento Econômico e Turismo" => "SECRETARIA DE DESENV. ECONÔMICO", "Secretaria Municipal de Administração" => "SECRETARIA DE ADMINISTRAÇÃO", "Secretaria Municipal de Governo" => "SECRETARIA DE GOVERNO", "Secretaria Municipal de Infraestrutura, Transportes e Saneamento" => "SECRETARIA DE INFRAESTRUTURA, TRANSPORTE E SANEAMENTO", "Secretaria Municipal de Esporte e Lazer e Juventude" => "SECRETARIA DE ESPORTE E LAZER", "Secretaria Municipal da Cidade" => "SECRETARIA DA CIDADE", "Secretaria Municipal de Saúde" => "SECRETARIA DE SAÚDE", "Secretaria Municipal de Segurança Pública, Trânsito e Defesa Civil" => "SECRETARIA DE SEGURANÇA PÚBLICA", "Controladoria Geral do Município" => "CONTROLADORIA GERAL", "Procuradoria Geral do Município" => "PROCURADORIA GERAL", "Secretaria Municipal de Cultura" => "SECRETARIA DE CULTURA", "Secretaria Municipal de Planejamento, Ciência, Tecnologia e Inovação" => "SECRETARIA DE PLANEJAMENTO E TECNOLOGIA", "Secretaria Municipal de Obras e Serviços Públicos" => "SECRETARIA DE OBRAS E SERVIÇOS PÚBLICOS" ];
$secretaria_admin_sessao = $_SESSION['secretaria'] ?? ''; 
$secretaria_admin_db = $secretarias_map[$secretaria_admin_sessao] ?? null; 
$role_sessao = $_SESSION['role'] ?? '';

$resultado = $conn ? buscarRegistros($conn, $prefixo_veiculo_filtro, $data_inicial_filtro, $data_final_filtro, $combustivel_filtro, $secretaria_admin_db, $role_sessao, $inicio, $registros_por_pagina) : ['registros' => [], 'total' => 0];
$registros = $resultado['registros']; 
$total_registros = $resultado['total'];

$total_paginas = $registros_por_pagina > 0 && $total_registros > 0 ? ceil($total_registros / $registros_por_pagina) : ($total_registros > 0 ? 1 : 0);

if ($pagina_atual > $total_paginas && $total_paginas > 0) {
    $pagina_atual = $total_paginas; 
    $inicio = ($pagina_atual - 1) * $registros_por_pagina;
    $resultado = $conn ? buscarRegistros($conn, $prefixo_veiculo_filtro, $data_inicial_filtro, $data_final_filtro, $combustivel_filtro, $secretaria_admin_db, $role_sessao, $inicio, $registros_por_pagina) : ['registros' => [], 'total' => 0];
    $registros = $resultado['registros']; 
    $total_registros = $resultado['total'];
}
$secretarias_disponiveis = buscarSecretarias(); 
$combustiveis_disponiveis = buscarCombustiveis();
$data_atual_form = date('Y-m-d'); 
$hora_atual_form = date('H:i');

$precos_combustiveis = [];
if ($conn) {
    try { 
        $stmt_precos = $conn->prepare("SELECT * FROM postos_precos ORDER BY posto_nome, tipo_combustivel"); 
        $stmt_precos->execute(); 
        $precos_combustiveis = $stmt_precos->fetchAll(PDO::FETCH_ASSOC); 
    } catch (PDOException $e) { 
        error_log("Erro ao buscar preços: " . $e->getMessage()); 
        $precos_combustiveis = []; 
    }
}
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" href="ico_nav/img.claro.png" media="(prefers-color-scheme: light)">
    <link rel="icon" type="image/png" href="ico_nav/img.escuro.png" media="(prefers-color-scheme: dark)">
    <title>Gerenciar Abastecimentos</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background-color: #f3f4f6; }
        .card { background: white; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1), 0 2px 4px -1px rgba(0,0,0,0.06); }
        .table-container { overflow-x: auto; border-radius: 12px; -webkit-overflow-scrolling: touch; }
        table { width: 100%; border-collapse: separate; border-spacing: 0; }
        th { position: sticky; top: 0; background-color: #F9FAFB; z-index: 10; font-weight: 600; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em; padding: 12px 15px; white-space: nowrap; }
        th, td { padding: 12px 15px; text-align: left; border-bottom: 1px solid #E5E7EB; }
        @media (max-width: 640px) { th, td { padding: 8px 10px; font-size: 0.875rem; } }
        tr:hover td { background-color: #F9FAFB; }
        .edit-icon, .delete-icon { cursor: pointer; transition: all 0.2s; font-size: 1.25rem; padding: 8px; }
        .edit-icon { color: #4F46E5; } .delete-icon { color: #EF4444; }
        .edit-icon:hover, .delete-icon:hover { transform: scale(1.2); }
        .modal { display: none; position: fixed; z-index: 50; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.4); }
        .modal-content { background-color: #fefefe; margin: 10% auto; padding: 20px; border-radius: 8px; width: 90%; max-width: 500px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.1),0 2px 4px -1px rgba(0,0,0,0.06); }
        #modalAdicao .modal-content, #modalEdicao .modal-content { width: 95%; max-width: 700px; margin: 5% auto; }
        @media (max-width: 640px) { .modal-content { margin: 5% auto; padding: 16px; } }
        .close { color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer; padding: 0 8px; }
        .close:hover { color: black; }
        .btn-primary, .btn-danger, .btn-secondary { padding: 8px 16px; border-radius: 6px; transition: all 0.2s; height: 40px; display: inline-flex; align-items: center; justify-content: center; color: white; }
        .btn-primary { background-color: #4F46E5; } .btn-primary:hover { background-color: #4338CA; }
        .btn-danger { background-color: #EF4444; } .btn-danger:hover { background-color: #DC2626; }
        .btn-secondary { background-color: #6B7280; } .btn-secondary:hover { background-color: #4B5563; }
        @media (max-width: 640px) { .btn-primary, .btn-danger, .btn-secondary { width: 100%; margin-bottom: 8px; padding: 10px; font-size: 1rem; } .flex.justify-end.space-x-2 { flex-direction: column; align-items: stretch; } }
        .success-message { background-color: #D1FAE5; color: #065F46; padding: 12px; border-radius: 6px; margin-bottom: 16px; }
        .error-message { background-color: #FEE2E2; color: #B91C1C; padding: 12px; border-radius: 6px; margin-bottom: 16px; }
        .suggestions-container { position: relative; }
        .suggestions-list { position: absolute; z-index: 1000; width: 100%; max-height: 250px; overflow-y: auto; background: white; border: 1px solid #ddd; border-radius: 4px; box-shadow: 0 2px 8px rgba(0,0,0,0.15); display: none; }
        .suggestion-item { padding: 12px; cursor: pointer; border-bottom: 1px solid #f3f3f3; }
        .suggestion-item:last-child { border-bottom: none; } .suggestion-item:hover { background-color: #f0f7ff; }
        @media (max-width: 768px) { .mobile-table-cell { display: flex; flex-direction: column; } .mobile-label { font-weight: 600; font-size: 0.75rem; color: #6B7280; margin-bottom: 4px; display: none; } input, select, textarea { font-size: 16px !important; padding: 12px !important; margin-bottom: 12px; }
            @media (max-width: 640px) { .desktop-table { display: none; } .mobile-table { display: block; } .mobile-card { border: 1px solid #E5E7EB; border-radius: 8px; margin-bottom: 12px; padding: 12px; } .mobile-table-header { background-color: #F9FAFB; padding: 8px 12px; border-radius: 6px 6px 0 0; font-weight: 600; } .mobile-table-body { padding: 12px; } .mobile-table-row { margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid #E5E7EB; } .mobile-table-row:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; } .mobile-label { display: block; } .mobile-actions { display: flex; justify-content: flex-end; margin-top: 8px; } } }
        .pagination { display: flex; justify-content: center; margin-top: 20px; flex-wrap: wrap; }
        .pagination a, .pagination span { display: inline-flex; align-items: center; justify-content: center; margin: 0 5px; padding: 8px 12px; border-radius: 6px; border: 1px solid #E5E7EB; background-color: white; color: #4B5563; text-decoration: none; font-weight: 500; min-width: 40px; transition: all 0.2s; }
        .pagination a:hover { background-color: #F3F4F6; border-color: #D1D5DB; }
        .pagination .active { background-color: #4F46E5; color: white; border-color: #4F46E5; }
        .pagination .disabled { color: #D1D5DB; cursor: not-allowed; }
        @media (max-width: 640px) { .pagination a, .pagination span { padding: 6px 10px; margin: 0 2px; margin-bottom: 8px; } }
        .sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border-width: 0; }

        /* Custom File Input Styles */
        .file-input-label-custom {
            display: inline-flex; 
            align-items: center; 
            background-color: white; /* Tailwind bg-white */
            color: #374151; /* Tailwind text-gray-700 */
            padding: 0.5rem 0.75rem; /* Tailwind py-2 px-3 */
            border-radius: 0.375rem; /* Tailwind rounded-md */
            border: 1px solid #D1D5DB; /* Tailwind border-gray-300 */
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05); /* Tailwind shadow-sm */
            cursor: pointer;
            font-size: 0.875rem; /* Tailwind text-sm */
            font-weight: 500; /* Tailwind font-medium */
            transition: background-color 0.2s;
        }
        .file-input-label-custom:hover {
            background-color: #F9FAFB; /* Tailwind bg-gray-50 */
        }
        .file-input-label-custom i {
            margin-right: 0.5rem; /* Tailwind mr-2 */
        }
        .file-name-display-custom {
            margin-left: 0.75rem; /* Tailwind ml-3 */
            font-size: 0.875rem; /* Tailwind text-sm */
            color: #4B5563; /* Tailwind text-gray-600 */
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            max-width: 200px; 
            vertical-align: middle;
        }
        .file-preview-container-custom {
            margin-top: 0.5rem; /* Tailwind mt-2 */
            padding: 0.5rem; /* Tailwind p-2 */
            border: 1px dashed #D1D5DB; /* Tailwind border-gray-300 */
            border-radius: 0.375rem; /* Tailwind rounded-md */
            background-color: #F9FAFB; /* Tailwind bg-gray-50 */
            min-height: 50px; /* Ensure some space for preview */
        }
        .file-preview-container-custom img {
            max-width: 100%;
            max-height: 150px;
            border-radius: 0.375rem; /* Tailwind rounded-md */
            border: 1px solid #E5E7EB; /* Tailwind border-gray-200 */
            display: block;
            margin-bottom: 0.25rem; /* Tailwind mb-1 */
        }
        .file-preview-container-custom .pdf-preview-custom {
            display: flex;
            align-items: center;
            font-size: 0.875rem; /* Tailwind text-sm */
            color: #4B5563; /* Tailwind text-gray-600 */
        }
        .file-preview-container-custom .pdf-preview-custom i {
            font-size: 1.5rem; /* fa-2x equivalent */
            margin-right: 0.5rem; /* Tailwind mr-2 */
            color: #EF4444; /* Tailwind text-red-500 */
        }
        .remove-file-button-custom {
            background-color: transparent;
            color: #EF4444; /* Tailwind text-red-500 */
            border: none;
            padding: 0.125rem 0.25rem; /* Smaller padding */
            font-size: 0.75rem; /* Tailwind text-xs */
            border-radius: 0.25rem; /* Tailwind rounded-sm */
            cursor: pointer;
            margin-top: 0.25rem; /* Tailwind mt-1 */
            display: inline-block;
        }
        .remove-file-button-custom:hover {
            color: #B91C1C; /* Tailwind text-red-700 */
            text-decoration: underline;
        }

        /* Progress Bar Styles */
        .progress-bar-container-custom {
            margin-top: 0.5rem; /* Tailwind mt-2 */
        }
        .progress-bar-bg-custom {
            background-color: #E5E7EB; /* Tailwind bg-gray-200 */
            border-radius: 0.25rem; /* Tailwind rounded */
            height: 0.5rem; /* Tailwind h-2 */
            overflow: hidden;
        }
        .progress-bar-fill-custom {
            background-color: #4F46E5; /* Tailwind bg-indigo-600 */
            height: 100%;
            transition: width .3s ease;
            border-radius: 0.25rem; /* Tailwind rounded */
        }
        .progress-text-custom {
            font-size: 0.75rem; /* Tailwind text-xs */
            color: #4B5563; /* Tailwind text-gray-600 */
            margin-top: 0.25rem; /* Tailwind mt-1 */
            display: block;
            text-align: right; /* Align text to the right of the bar */
        }
    </style>
</head>
<body class="min-h-screen">
    <div class="bg-indigo-600 text-white shadow-md">
        <div class="container mx-auto px-4 py-3">
            <div class="flex flex-col md:flex-row justify-between items-center">
                <h1 class="text-xl font-bold mb-3 md:mb-0">Gerenciar Abastecimentos</h1>
                <div class="flex flex-wrap w-full md:w-auto justify-center gap-2">
                    <button onclick="abrirModalAdicao()" class="bg-white/20 hover:bg-white/30 px-3 py-1.5 rounded-md transition text-sm font-medium min-w-[90px] flex items-center justify-center"><i class="fas fa-plus mr-1.5"></i> Novo</button>
                    <button onclick="window.location.href='historico_abastecimentos.php'" class="bg-white/20 hover:bg-white/30 px-3 py-1.5 rounded-md transition text-sm font-medium min-w-[90px] flex items-center justify-center"><i class="fas fa-history mr-1.5"></i> Histórico</button>
                    <button onclick="window.location.href='excluir_duplicados.php'" class="bg-white/20 hover:bg-white/30 px-3 py-1.5 rounded-md transition text-sm font-medium min-w-[90px] flex items-center justify-center"><i class="fas fa-clone mr-1.5"></i> Duplicados</button>
                    <a href="<?= $_SESSION['role'] === 'geraladm' ? 'geral_adm.php' : 'admin.php' ?>" class="bg-white/20 hover:bg-white/30 px-3 py-1.5 rounded-md transition text-sm font-medium min-w-[90px] flex items-center justify-center"><i class="fas fa-arrow-left mr-1.5"></i> Voltar</a>
                </div>
            </div>
        </div>
    </div>

    <div class="container mx-auto px-4 py-6">
        <div class="card p-4 md:p-6 mb-6">
            <h2 class="text-xl font-semibold text-gray-800 mb-4">Filtros</h2>
            <form method="get" class="grid grid-cols-1 gap-4">
                <div class="suggestions-container">
                    <label for="prefixo_veiculo" class="block text-sm font-medium text-gray-700 mb-2">Prefixo/Veículo</label>
                    <input type="text" id="prefixo_veiculo" name="prefixo_veiculo" class="w-full px-3 py-2 border rounded-md" value="<?= htmlspecialchars($prefixo_veiculo_filtro) ?>" placeholder="Ex: C-32 ou Onix" oninput="buscarVeiculos(this.value)">
                    <div id="suggestions" class="suggestions-list"></div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label for="data_inicial" class="block text-sm font-medium text-gray-700 mb-2">Data Inicial</label><input type="date" id="data_inicial" name="data_inicial" class="w-full px-3 py-2 border rounded-md" value="<?= htmlspecialchars($data_inicial_filtro) ?>"></div>
                    <div><label for="data_final" class="block text-sm font-medium text-gray-700 mb-2">Data Final</label><input type="date" id="data_final" name="data_final" class="w-full px-3 py-2 border rounded-md" value="<?= htmlspecialchars($data_final_filtro) ?>"></div>
                </div>
                <div>
                    <label for="combustivel_filtro_form" class="block text-sm font-medium text-gray-700 mb-2">Combustível</label>
                    <select id="combustivel_filtro_form" name="combustivel" class="w-full px-3 py-2 border rounded-md">
                        <option value="">Todos</option>
                        <?php foreach ($combustiveis_disponiveis as $comb): ?><option value="<?= htmlspecialchars($comb) ?>" <?= $combustivel_filtro === $comb ? 'selected' : '' ?>><?= htmlspecialchars($comb) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div><button type="submit" class="btn-primary w-full md:w-auto"><i class="fas fa-search mr-2"></i> Pesquisar</button></div>
                <input type="hidden" name="pagina" value="1">
            </form>
        </div>
        
        <div id="ajax-message-placeholder" class="mb-4">
            <?php if (isset($_SESSION['mensagem']) && !empty($_SESSION['mensagem'])): ?>
                <div class="<?= ($_SESSION['tipo_mensagem'] ?? 'error') === 'success' ? 'success-message' : 'error-message' ?>">
                    <?= htmlspecialchars($_SESSION['mensagem']) ?>
                </div>
                <?php unset($_SESSION['mensagem']); unset($_SESSION['tipo_mensagem']); ?>
            <?php endif; ?>
        </div>


        <div class="card p-4 md:p-6">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-semibold text-gray-800">Registros de Abastecimentos</h2>
                <span class="text-sm text-gray-500">Mostrando <?= count($registros) ?> de <?= $total_registros ?> registros</span>
            </div>

            <?php if (count($registros) > 0): ?>
                <div class="table-container desktop-table hidden md:block">
                    <table>
                        <thead><tr><th>Data</th><th>Veículo</th><th>Placa</th><th>Motorista</th><th>KM</th><th>Litros</th><th>Combustível</th><th>Valor</th><th>Nota Fiscal</th><th>Ações</th></tr></thead>
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
                                <td>
                                    <?php if ($registro['nota_fiscal'] && strpos($registro['nota_fiscal'], 'uploads/notas_fiscais/') === 0 && file_exists($registro['nota_fiscal'])): ?>
                                        <a href="<?= htmlspecialchars($registro['nota_fiscal']) ?>" target="_blank" class="text-blue-500 hover:underline" title="<?= htmlspecialchars(basename($registro['nota_fiscal'])) ?>">Ver Nota</a>
                                    <?php elseif ($registro['nota_fiscal']): ?>
                                        <span title="<?= htmlspecialchars($registro['nota_fiscal']) ?>"><?= htmlspecialchars(substr($registro['nota_fiscal'],0,15)).(strlen($registro['nota_fiscal']) > 15 ? '...' : '') ?></span>
                                    <?php else: echo '-'; endif; ?>
                                </td>
                                <td class="flex space-x-2">
                                    <i class="fas fa-pencil-alt edit-icon" onclick="abrirModalEdicao('<?= $registro['id'] ?>', '<?= htmlspecialchars(addslashes($registro['nome'])) ?>', '<?= $registro['data'] ?>', '<?= $registro['hora'] ?>', '<?= $registro['km_abastecido'] ?>', '<?= $registro['litros'] ?>', '<?= htmlspecialchars(addslashes($registro['combustivel'])) ?>', '<?= htmlspecialchars(addslashes($registro['posto_gasolina'])) ?>', '<?= $registro['valor'] ?>', '<?= htmlspecialchars(addslashes($registro['nota_fiscal'])) ?>')"></i>
                                    <i class="fas fa-trash-alt delete-icon" onclick="abrirModalExclusao('<?= $registro['id'] ?>', '<?= htmlspecialchars(addslashes($registro['veiculo'])) ?>', '<?= htmlspecialchars(addslashes($registro['placa'])) ?>', '<?= $registro['data'] ?>')"></i>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="mobile-table block md:hidden">
                    <?php foreach ($registros as $registro): ?>
                    <div class="mobile-card bg-white mb-4">
                        <div class="flex justify-between items-center mb-2"><div class="font-semibold"><?= htmlspecialchars($registro['veiculo']) ?></div><div class="text-sm text-gray-500"><?= htmlspecialchars(date('d/m/Y', strtotime($registro['data']))) ?></div></div>
                        <div class="grid grid-cols-2 gap-2 mb-3">
                            <div><div class="mobile-label">Placa</div><div><?= htmlspecialchars($registro['placa']) ?></div></div>
                            <div><div class="mobile-label">Motorista</div><div><?= htmlspecialchars($registro['nome']) ?></div></div>
                            <div><div class="mobile-label">KM</div><div><?= $registro['km_abastecido'] ? htmlspecialchars(number_format($registro['km_abastecido'],0,',','.')) : '-' ?></div></div>
                            <div><div class="mobile-label">Litros</div><div><?= htmlspecialchars(number_format($registro['litros'],2,',','.')) ?></div></div>
                            <div><div class="mobile-label">Combustível</div><div><?= htmlspecialchars($registro['combustivel']) ?></div></div>
                            <div><div class="mobile-label">Valor</div><div>R$ <?= htmlspecialchars(number_format($registro['valor'],2,',','.')) ?></div></div>
                            <div class="col-span-2"><div class="mobile-label">Nota Fiscal</div>
                                <div>
                                    <?php if ($registro['nota_fiscal'] && strpos($registro['nota_fiscal'], 'uploads/notas_fiscais/') === 0 && file_exists($registro['nota_fiscal'])): ?>
                                        <a href="<?= htmlspecialchars($registro['nota_fiscal']) ?>" target="_blank" class="text-blue-500 hover:underline" title="<?= htmlspecialchars(basename($registro['nota_fiscal'])) ?>">Ver Nota</a>
                                    <?php elseif ($registro['nota_fiscal']): ?>
                                         <span title="<?= htmlspecialchars($registro['nota_fiscal']) ?>"><?= htmlspecialchars(substr($registro['nota_fiscal'],0,20)).(strlen($registro['nota_fiscal']) > 20 ? '...' : '') ?></span>
                                    <?php else: echo '-'; endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="flex justify-end space-x-4">
                            <button class="p-2 text-indigo-600" onclick="abrirModalEdicao('<?= $registro['id'] ?>', '<?= htmlspecialchars(addslashes($registro['nome'])) ?>', '<?= $registro['data'] ?>', '<?= $registro['hora'] ?>', '<?= $registro['km_abastecido'] ?>', '<?= $registro['litros'] ?>', '<?= htmlspecialchars(addslashes($registro['combustivel'])) ?>', '<?= htmlspecialchars(addslashes($registro['posto_gasolina'])) ?>', '<?= $registro['valor'] ?>', '<?= htmlspecialchars(addslashes($registro['nota_fiscal'])) ?>')"><i class="fas fa-pencil-alt"></i></button>
                            <button class="p-2 text-red-600" onclick="abrirModalExclusao('<?= $registro['id'] ?>', '<?= htmlspecialchars(addslashes($registro['veiculo'])) ?>', '<?= htmlspecialchars(addslashes($registro['placa'])) ?>', '<?= $registro['data'] ?>')"><i class="fas fa-trash-alt"></i></button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if ($total_paginas > 1): ?>
                <div class="pagination">
                    <?php $queryParams = $_GET; ?>
                    <?php if ($pagina_atual > 1): $queryParams['pagina'] = 1; ?><a href="?<?= http_build_query($queryParams) ?>" title="Primeira"><i class="fas fa-angle-double-left"></i></a><?php $queryParams['pagina'] = $pagina_atual - 1; ?><a href="?<?= http_build_query($queryParams) ?>" title="Anterior"><i class="fas fa-angle-left"></i></a>
                    <?php else: ?><span class="disabled"><i class="fas fa-angle-double-left"></i></span><span class="disabled"><i class="fas fa-angle-left"></i></span><?php endif; ?>
                    <?php $max_links = 2; $start_page = max(1, $pagina_atual - $max_links); $end_page = min($total_paginas, $pagina_atual + $max_links);
                        if ($pagina_atual <= $max_links) $end_page = min($total_paginas, (2 * $max_links) + 1);
                        if ($pagina_atual > $total_paginas - $max_links) $start_page = max(1, $total_paginas - (2 * $max_links));
                        if ($start_page > 1) { $queryParams['pagina'] = 1; echo '<a href="?'.http_build_query($queryParams).'">1</a>'; if ($start_page > 2) echo '<span class="disabled">...</span>'; }
                        for ($i = $start_page; $i <= $end_page; $i++): $queryParams['pagina'] = $i;
                            if ($i == $pagina_atual): echo '<span class="active">'.$i.'</span>'; else: echo '<a href="?'.http_build_query($queryParams).'">'.$i.'</a>'; endif;
                        endfor;
                        if ($end_page < $total_paginas) { if ($end_page < $total_paginas - 1) echo '<span class="disabled">...</span>'; $queryParams['pagina'] = $total_paginas; echo '<a href="?'.http_build_query($queryParams).'">'.$total_paginas.'</a>'; } ?>
                    <?php if ($pagina_atual < $total_paginas): $queryParams['pagina'] = $pagina_atual + 1; ?><a href="?<?= http_build_query($queryParams) ?>" title="Próxima"><i class="fas fa-angle-right"></i></a><?php $queryParams['pagina'] = $total_paginas; ?><a href="?<?= http_build_query($queryParams) ?>" title="Última"><i class="fas fa-angle-double-right"></i></a>
                    <?php else: ?><span class="disabled"><i class="fas fa-angle-right"></i></span><span class="disabled"><i class="fas fa-angle-double-right"></i></span><?php endif; ?>
                </div>
                <?php endif; ?>
            <?php else: ?>
                 <?php if (!$conn): ?>
                    <div class="text-center py-8"><i class="fas fa-database text-4xl text-red-400 mb-4"></i><h4 class="text-lg font-medium text-gray-700">Erro de Conexão</h4><p class="text-gray-500 mt-2">Não foi possível conectar ao banco de dados para buscar os registros.</p></div>
                <?php else: ?>
                    <div class="text-center py-8"><i class="fas fa-gas-pump text-4xl text-gray-300 mb-4"></i><h4 class="text-lg font-medium text-gray-700">Nenhum registro encontrado</h4><p class="text-gray-500 mt-2">Não foram encontrados registros para os filtros aplicados ou não há registros.</p></div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <div id="modalEdicao" class="modal">
        <div class="modal-content">
            <span class="close" onclick="fecharModalEdicao()">&times;</span>
            <h2 class="text-xl font-semibold mb-4">Editar Abastecimento</h2>
            <form id="formEdicao" method="post" enctype="multipart/form-data"> 
                <input type="hidden" id="registro_id" name="registro_id"><input type="hidden" id="modal_usuario_id" name="usuario_id" value="">
                <div class="mb-4 suggestions-container">
                    <label for="modal_nome" class="block text-sm font-medium text-gray-700 mb-2">Motorista</label>
                    <input type="text" id="modal_nome" name="nome" class="w-full px-3 py-2 border rounded-md" placeholder="Nome, CPF ou email" required oninput="buscarUsuariosModal(this.value)">
                    <div id="modal_suggestions_usuarios" class="suggestions-list"></div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div><label for="modal_data" class="block text-sm font-medium text-gray-700 mb-2">Data</label><input type="date" id="modal_data" name="data" class="w-full px-3 py-2 border rounded-md" required></div>
                    <div><label for="modal_hora" class="block text-sm font-medium text-gray-700 mb-2">Hora</label><input type="time" id="modal_hora" name="hora" class="w-full px-3 py-2 border rounded-md" required pattern="[0-9]{2}:[0-9]{2}" title="HH:MM"></div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div><label for="modal_km_abastecido" class="block text-sm font-medium text-gray-700 mb-2">KM</label><input type="number" id="modal_km_abastecido" name="km_abastecido" class="w-full px-3 py-2 border rounded-md"></div>
                    <div><label for="modal_litros" class="block text-sm font-medium text-gray-700 mb-2">Litros</label><input type="number" step="0.01" id="modal_litros" name="litros" class="w-full px-3 py-2 border rounded-md" required onchange="calcularValorAbastecimentoModal()"></div>
                </div>
                <div class="mb-4">
                    <label for="modal_combustivel" class="block text-sm font-medium text-gray-700 mb-2">Combustível</label>
                    <select id="modal_combustivel" name="combustivel" class="w-full px-3 py-2 border rounded-md" required onchange="calcularValorAbastecimentoModal()"><option value="">Selecione</option><option value="Gasolina">Gasolina</option><option value="Etanol">Etanol</option><option value="Diesel-S10">Diesel-S10</option><option value="Diesel-S500">Diesel-S500</option></select>
                </div>
                <div class="mb-4">
                    <label for="modal_posto_gasolina" class="block text-sm font-medium text-gray-700 mb-2">Posto</label>
                    <select id="modal_posto_gasolina" name="posto_gasolina" class="w-full px-3 py-2 border rounded-md" onchange="handlePostoChangeModal(this)">
                        <option value="">Selecione ou digite</option>
                        <option value="ABRANTES & ABRANTES LTDA">POSTO NORDESTE - ABRANTES & ABRANTES LTDA</option>
                        <option value="ALBERTI COMERCIO DE COMBUSTIVEIS">POSTO CIDADE - ALBERTI COMERCIO DE COMBUSTIVEIS</option>
                        <option value="XAXIM COMERCIO DE COMBUSTÍVEIS LTDA">POSTO REDENTOR - XAXIM COMERCIO DE COMBUSTÍVEIS LTDA</option>
                        <option value="AUTO POSTO CHARRUA LTDA">AUTO POSTO CHARRUA LTDA</option>
                        <option value="MELOSA OBRAS">MELOSA OBRAS</option><option value="MELOSA TRANSPORTES">MELOSA TRANSPORTES</option>
                        <option value="POSTO MORADA">B&M - POSTO MORADA</option><option value="BRESCANSIN & BRESCANSIN LTDA">SMILLE - BRESCANSIN & BRESCANSIN LTDA</option>
                        <option value="outro">Outro</option>
                    </select>
                    <div id="outro_posto_modal_container" class="mt-2" style="display:none;"><input type="text" id="outro_posto_modal" name="outro_posto_modal_input" class="w-full px-3 py-2 border rounded-md" placeholder="Nome do posto"></div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div><label for="modal_valor" class="block text-sm font-medium text-gray-700 mb-2">Valor (R$)</label><input type="number" step="0.01" id="modal_valor" name="valor" class="w-full px-3 py-2 border rounded-md" required></div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nota Fiscal (Alterar)</label>
                        <div id="current_nota_fiscal_display_edit" class="text-sm text-gray-600 mb-2 p-2 border border-gray-200 rounded-md bg-gray-50 min-h-[2.5rem] flex items-center">Nenhuma nota fiscal salva.</div>
                        
                        <div class="mt-1">
                            <input type="file" name="modal_nota_fiscal_arquivo" id="modal_nota_fiscal_arquivo" class="sr-only" accept="image/*,application/pdf" capture="environment">
                            <label for="modal_nota_fiscal_arquivo" class="file-input-label-custom">
                                <i class="fas fa-upload"></i> <span>Selecionar Novo Arquivo</span>
                            </label>
                            <span id="modal_nota_fiscal_filename_display" class="file-name-display-custom">Nenhum arquivo novo</span>
                        </div>
                        <div id="modal_nota_fiscal_preview_container" class="file-preview-container-custom" style="display: none;">
                            <img id="modal_nota_fiscal_image_preview" src="#" alt="Prévia do Novo Arquivo" style="display: none;"/>
                            <div id="modal_nota_fiscal_pdf_preview" class="pdf-preview-custom" style="display: none;">
                                <i class="fas fa-file-pdf"></i>
                                <span id="modal_nota_fiscal_pdf_filename"></span>
                            </div>
                            <button type="button" id="modal_nota_fiscal_remove_button" class="remove-file-button-custom" style="display:none;">Remover arquivo</button>
                        </div>
                        <div id="modal_nota_fiscal_progress_container" class="progress-bar-container-custom" style="display:none;">
                            <div class="progress-bar-bg-custom">
                                <div id="modal_nota_fiscal_progress_bar" class="progress-bar-fill-custom" style="width: 0%;"></div>
                            </div>
                            <span id="modal_nota_fiscal_progress_text" class="progress-text-custom"></span>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">PNG, JPG, GIF, PDF até 5MB. Deixe em branco para manter o atual.</p>
                    </div>
                </div>
                <div class="flex flex-col md:flex-row md:justify-end md:space-x-2">
                    <button type="button" onclick="fecharModalEdicao()" class="btn-secondary mb-2 md:mb-0 w-full md:w-auto">Cancelar</button>
                    <button type="submit" class="btn-primary w-full md:w-auto"><i class="fas fa-save mr-2"></i> Salvar Alterações</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modalExclusao" class="modal">
        <div class="modal-content">
            <span class="close" onclick="fecharModalExclusao()">&times;</span>
            <h2 class="text-xl font-semibold mb-4">Confirmar Exclusão</h2>
            <form id="formExclusao" method="post"> 
                <input type="hidden" name="action" value="excluir"><input type="hidden" id="registro_id_excluir" name="registro_id_excluir">
                <div class="mb-4"><p class="text-sm text-gray-600 mb-1">Excluir este abastecimento?</p><p id="exclusao_veiculo" class="font-medium"></p><p id="exclusao_placa" class="font-medium"></p><p id="exclusao_data" class="font-medium"></p></div>
                <div class="bg-yellow-50 border-l-4 border-yellow-400 p-4 mb-4"><div class="flex"><div class="flex-shrink-0"><i class="fas fa-exclamation-triangle text-yellow-400"></i></div><div class="ml-3"><p class="text-sm text-yellow-700">Ação irreversível. Dados e nota fiscal (se houver) serão removidos.</p></div></div></div>
                <div class="flex flex-col md:flex-row md:justify-end md:space-x-2">
                    <button type="button" onclick="fecharModalExclusao()" class="btn-secondary mb-2 md:mb-0 w-full md:w-auto">Cancelar</button>
                    <button type="submit" class="btn-danger w-full md:w-auto"><i class="fas fa-trash-alt mr-2"></i> Excluir</button>
                </div>
            </form>
        </div>
    </div>

    <div id="modalAdicao" class="modal">
        <div class="modal-content" style="max-width: 700px;">
            <span class="close" onclick="fecharModalAdicao()">&times;</span>
            <h2 class="text-xl font-semibold mb-4">Adicionar Novo Abastecimento</h2>
            <form id="formAdicao" method="post" enctype="multipart/form-data"> 
                <input type="hidden" name="action" value="adicionar">
                <div class="grid grid-cols-1 gap-4 mb-4">
                    <div class="suggestions-container">
                        <label for="nome" class="block text-sm font-medium text-gray-700 mb-2">Motorista <span class="text-red-500">*</span></label>
                        <input type="text" id="nome" name="nome" class="w-full px-3 py-2 border rounded-md" placeholder="Nome, CPF ou email" required oninput="buscarUsuarios(this.value)">
                        <input type="hidden" id="usuario_id" name="usuario_id" value=""><div id="suggestions_usuarios" class="suggestions-list"></div>
                    </div>
                    <div>
                        <label for="secretaria" class="block text-sm font-medium text-gray-700 mb-2">Secretaria</label>
                        <select id="secretaria" name="secretaria" class="w-full px-3 py-2 border rounded-md"><option value="">Selecione</option><?php foreach ($secretarias_disponiveis as $sec):?><option value="<?= htmlspecialchars($sec) ?>"><?= htmlspecialchars($sec) ?></option><?php endforeach; ?></select>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
                    <div class="suggestions-container">
                        <label for="prefixo" class="block text-sm font-medium text-gray-700 mb-2">Prefixo</label>
                        <input type="text" id="prefixo" name="prefixo" class="w-full px-3 py-2 border rounded-md" placeholder="Ex: C-32" oninput="buscarVeiculosPorPrefixo(this.value)">
                        <div id="suggestions_prefixo" class="suggestions-list"></div>
                    </div>
                    <div><label for="placa" class="block text-sm font-medium text-gray-700 mb-2">Placa <span class="text-red-500">*</span></label><input type="text" id="placa" name="placa" class="w-full px-3 py-2 border rounded-md" placeholder="Ex: ABC1234" required readonly></div>
                    <div><label for="veiculo" class="block text-sm font-medium text-gray-700 mb-2">Veículo <span class="text-red-500">*</span></label><input type="text" id="veiculo" name="veiculo" class="w-full px-3 py-2 border rounded-md" placeholder="Modelo" required readonly></div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div><label for="data" class="block text-sm font-medium text-gray-700 mb-2">Data <span class="text-red-500">*</span></label><input type="date" id="data" name="data" class="w-full px-3 py-2 border rounded-md" value="<?= $data_atual_form ?>" required></div>
                    <div><label for="hora" class="block text-sm font-medium text-gray-700 mb-2">Hora <span class="text-red-500">*</span></label><input type="time" id="hora" name="hora" class="w-full px-3 py-2 border rounded-md" value="<?= $hora_atual_form ?>" required pattern="[0-9]{2}:[0-9]{2}" title="HH:MM"></div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div><label for="km_abastecido" class="block text-sm font-medium text-gray-700 mb-2">KM (opcional)</label><input type="number" id="km_abastecido" name="km_abastecido" class="w-full px-3 py-2 border rounded-md" placeholder="Ex: 123456"></div>
                    <div><label for="litros" class="block text-sm font-medium text-gray-700 mb-2">Litros <span class="text-red-500">*</span></label><input type="number" step="0.01" id="litros" name="litros" class="w-full px-3 py-2 border rounded-md" placeholder="Ex: 45.50" required onchange="calcularValorAbastecimento()"></div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div>
                        <label for="tipo_combustivel" class="block text-sm font-medium text-gray-700 mb-2">Combustível <span class="text-red-500">*</span></label>
                        <select id="tipo_combustivel" name="combustivel" class="w-full px-3 py-2 border rounded-md" required onchange="calcularValorAbastecimento()"><option value="">Selecione</option><option value="Gasolina">Gasolina</option><option value="Etanol">Etanol</option><option value="Diesel-S10">Diesel-S10</option><option value="Diesel-S500">Diesel-S500</option></select>
                    </div>
                    <div>
                        <label for="posto" class="block text-sm font-medium text-gray-700 mb-2">Posto</label>
                        <select id="posto" name="posto_gasolina" class="w-full px-3 py-2 border rounded-md" onchange="handlePostoChangeAdicao(this)">
                            <option value="">Selecione ou digite</option>
                            <option value="ABRANTES & ABRANTES LTDA">POSTO NORDESTE - ABRANTES & ABRANTES LTDA</option>
                            <option value="ALBERTI COMERCIO DE COMBUSTIVEIS">POSTO CIDADE - ALBERTI COMERCIO DE COMBUSTIVEIS</option>
                            <option value="XAXIM COMERCIO DE COMBUSTÍVEIS LTDA">POSTO REDENTOR - XAXIM COMERCIO DE COMBUSTÍVEIS LTDA</option>
                            <option value="AUTO POSTO CHARRUA LTDA">AUTO POSTO CHARRUA LTDA</option>
                            <option value="MELOSA OBRAS">MELOSA OBRAS</option><option value="MELOSA TRANSPORTES">MELOSA TRANSPORTES</option>
                            <option value="POSTO MORADA">B&M - POSTO MORADA</option><option value="BRESCANSIN & BRESCANSIN LTDA">SMILLE - BRESCANSIN & BRESCANSIN LTDA</option>
                            <option value="outro">Outro</option>
                        </select>
                        <div id="outro_posto_container" class="mt-2" style="display:none;"><input type="text" id="outro_posto" name="outro_posto_input" class="w-full px-3 py-2 border rounded-md" placeholder="Nome do posto"></div>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                    <div><label for="valor_abastecimento" class="block text-sm font-medium text-gray-700 mb-2">Valor (R$) <span class="text-red-500">*</span></label><input type="text" id="valor_abastecimento" name="valor" class="w-full px-3 py-2 border rounded-md" placeholder="Ex: 250,00" required></div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Nota Fiscal (Foto/Arquivo - opcional)</label>
                        <div class="mt-1">
                             <input type="file" name="nota_fiscal_arquivo" id="nota_fiscal_arquivo" class="sr-only" accept="image/*,application/pdf" capture="environment">
                             <label for="nota_fiscal_arquivo" class="file-input-label-custom">
                                <i class="fas fa-upload"></i> <span>Selecionar Arquivo</span>
                             </label>
                             <span id="nota_fiscal_filename_display" class="file-name-display-custom">Nenhum arquivo selecionado</span>
                        </div>
                        <div id="nota_fiscal_preview_container" class="file-preview-container-custom" style="display: none;">
                            <img id="nota_fiscal_image_preview" src="#" alt="Prévia da Imagem" style="display: none;"/>
                            <div id="nota_fiscal_pdf_preview" class="pdf-preview-custom" style="display: none;">
                                <i class="fas fa-file-pdf"></i>
                                <span id="nota_fiscal_pdf_filename"></span>
                            </div>
                            <button type="button" id="nota_fiscal_remove_button" class="remove-file-button-custom" style="display:none;">Remover arquivo</button>
                        </div>
                        <div id="nota_fiscal_progress_container" class="progress-bar-container-custom" style="display:none;">
                            <div class="progress-bar-bg-custom">
                                <div id="nota_fiscal_progress_bar" class="progress-bar-fill-custom" style="width: 0%;"></div>
                            </div>
                            <span id="nota_fiscal_progress_text" class="progress-text-custom"></span>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">PNG, JPG, GIF, PDF até 5MB</p>
                    </div>
                </div>
                <div class="text-sm text-gray-500 mb-4"><p>Campos com <span class="text-red-500">*</span> são obrigatórios.</p></div>
                <div class="flex flex-col md:flex-row md:justify-end md:space-x-2">
                    <button type="button" onclick="fecharModalAdicao()" class="btn-secondary mb-2 md:mb-0 w-full md:w-auto">Cancelar</button>
                    <button type="submit" class="btn-primary w-full md:w-auto"><i class="fas fa-plus mr-2"></i> Adicionar</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const precosCombustiveis = <?= json_encode($precos_combustiveis) ?>;

        function displayAjaxMessage(message, type) {
            const placeholder = $('#ajax-message-placeholder');
            const alertClass = type === 'success' ? 'success-message' : 'error-message';
            placeholder.html(`<div class="${alertClass}">${message}</div>`);
            // Scroll to message if it's out of view
            if (placeholder.offset().top < $(window).scrollTop() || placeholder.offset().top + placeholder.height() > $(window).scrollTop() + $(window).height()) {
                $('html, body').animate({
                    scrollTop: placeholder.offset().top - 20 // 20px buffer
                }, 300);
            }
            setTimeout(() => placeholder.empty(), 7000); // Clear message after 7 seconds
        }

        function setupFileInput(inputId, filenameDisplayId, previewContainerId, imagePreviewId, pdfPreviewId, pdfFilenameId, removeButtonId, progressContainerId, progressBarId, progressTextId) {
            const fileInput = $(`#${inputId}`);
            const filenameDisplay = $(`#${filenameDisplayId}`);
            const previewContainer = $(`#${previewContainerId}`);
            const imagePreview = $(`#${imagePreviewId}`);
            const pdfPreview = $(`#${pdfPreviewId}`);
            const pdfFilename = $(`#${pdfFilenameId}`);
            const removeButton = $(`#${removeButtonId}`);
            
            fileInput.on('change', function() {
                const file = this.files[0];
                if (file) {
                    filenameDisplay.text(file.name).attr('title', file.name);
                    previewContainer.show();
                    removeButton.show();

                    if (file.type.startsWith('image/')) {
                        imagePreview.show();
                        pdfPreview.hide();
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            imagePreview.attr('src', e.target.result);
                        }
                        reader.readAsDataURL(file);
                    } else if (file.type === 'application/pdf') {
                        pdfPreview.show();
                        imagePreview.hide();
                        pdfFilename.text(file.name);
                    } else { // Other file types
                        previewContainer.show(); // Keep container visible
                        imagePreview.hide();
                        pdfPreview.hide(); 
                        // Optionally, show a generic file icon and name
                        pdfFilename.text(file.name + ' (tipo não suportado para prévia)'); 
                    }
                } else {
                    filenameDisplay.text(inputId.startsWith('modal') ? 'Nenhum arquivo novo' : 'Nenhum arquivo selecionado').attr('title', '');
                    previewContainer.hide();
                    imagePreview.hide();
                    pdfPreview.hide();
                    removeButton.hide();
                }
                $(`#${progressContainerId}`).hide();
                $(`#${progressBarId}`).width('0%');
                $(`#${progressTextId}`).text('');
            });

            removeButton.on('click', function() {
                fileInput.val(''); 
                filenameDisplay.text(inputId.startsWith('modal') ? 'Nenhum arquivo novo' : 'Nenhum arquivo selecionado').attr('title', '');
                previewContainer.hide();
                imagePreview.hide().attr('src', '#');
                pdfPreview.hide();
                pdfFilename.text('');
                removeButton.hide();
                $(`#${progressContainerId}`).hide();
                $(`#${progressBarId}`).width('0%');
                $(`#${progressTextId}`).text('');
            });
        }

        $(document).ready(function() {
            // Setup for Adicionar Modal
            setupFileInput(
                'nota_fiscal_arquivo', 'nota_fiscal_filename_display', 
                'nota_fiscal_preview_container', 'nota_fiscal_image_preview', 
                'nota_fiscal_pdf_preview', 'nota_fiscal_pdf_filename', 
                'nota_fiscal_remove_button',
                'nota_fiscal_progress_container', 'nota_fiscal_progress_bar', 'nota_fiscal_progress_text'
            );

            // Setup for Editar Modal
            setupFileInput(
                'modal_nota_fiscal_arquivo', 'modal_nota_fiscal_filename_display', 
                'modal_nota_fiscal_preview_container', 'modal_nota_fiscal_image_preview', 
                'modal_nota_fiscal_pdf_preview', 'modal_nota_fiscal_pdf_filename', 
                'modal_nota_fiscal_remove_button',
                'modal_nota_fiscal_progress_container', 'modal_nota_fiscal_progress_bar', 'modal_nota_fiscal_progress_text'
            );
        });


        function abrirModalEdicao(id, nome, data, hora, km, litros, combustivel, posto, valor, notaFiscalAtual) {
            document.getElementById('formEdicao').reset(); // Reset form fields first
            document.getElementById('registro_id').value = id;
            document.getElementById('modal_nome').value = nome;
            document.getElementById('modal_data').value = data;
            document.getElementById('modal_hora').value = formatarHora(hora); // Ensure HH:MM
            document.getElementById('modal_km_abastecido').value = km === 'null' || km === null ? '' : km;
            document.getElementById('modal_litros').value = parseFloat(litros) || '';
            document.getElementById('modal_valor').value = parseFloat(valor) || '';
            document.getElementById('modal_combustivel').value = combustivel;

            const postoSelect = document.getElementById('modal_posto_gasolina');
            const outroPostoContainer = document.getElementById('outro_posto_modal_container');
            const outroPostoInput = document.getElementById('outro_posto_modal');
            outroPostoContainer.style.display = 'none'; outroPostoInput.value = '';
            let postoEncontrado = Array.from(postoSelect.options).some(opt => opt.value === posto);
            if (postoEncontrado) {
                postoSelect.value = posto;
            } else if (posto) { 
                postoSelect.value = 'outro'; 
                outroPostoContainer.style.display = 'block'; 
                outroPostoInput.value = posto; 
            } else {
                postoSelect.value = '';
            }
            
            const currentNotaDisplay = document.getElementById('current_nota_fiscal_display_edit');
            if (notaFiscalAtual && notaFiscalAtual !== 'null' && notaFiscalAtual.trim() !== '') {
                if (notaFiscalAtual.startsWith('uploads/notas_fiscais/')) {
                    const filename = notaFiscalAtual.split('/').pop();
                    currentNotaDisplay.innerHTML = `Arquivo atual: <a href="${notaFiscalAtual}" target="_blank" class="text-blue-500 hover:underline" title="${filename}">${filename.substring(0,25)}${filename.length > 25 ? '...' : ''}</a>`;
                } else { 
                    currentNotaDisplay.innerHTML = `Informação atual: <span class="font-semibold">${notaFiscalAtual.substring(0,30)}${notaFiscalAtual.length > 30 ? '...' : ''}</span>`;
                }
            } else {
                 currentNotaDisplay.innerHTML = 'Nenhuma nota fiscal salva.';
            }
            
            $('#modal_nota_fiscal_arquivo').val('');
            $('#modal_nota_fiscal_filename_display').text('Nenhum arquivo novo').attr('title', 'Nenhum arquivo novo');
            $('#modal_nota_fiscal_preview_container').hide();
            $('#modal_nota_fiscal_image_preview').hide().attr('src', '#');
            $('#modal_nota_fiscal_pdf_preview').hide();
            $('#modal_nota_fiscal_pdf_filename').text('');
            $('#modal_nota_fiscal_remove_button').hide();
            $('#modal_nota_fiscal_progress_container').hide();
            $('#modal_nota_fiscal_progress_bar').width('0%');
            $('#modal_nota_fiscal_progress_text').text('');
            
            $('.border-red-500').removeClass('border-red-500'); // Clear previous validation highlights
            document.getElementById('modalEdicao').style.display = 'block';
        }
        function fecharModalEdicao() { document.getElementById('modalEdicao').style.display = 'none'; }
        
        function formatarHora(horaStr) { // Expects HH:MM:SS or HH:MM
            if (!horaStr) return '';
            const parts = horaStr.split(':');
            if (parts.length >= 2) {
                const h = parts[0].padStart(2, '0');
                const m = parts[1].padStart(2, '0');
                return `${h}:${m}`;
            }
            return ''; // Invalid format
        }

        function abrirModalExclusao(id, veiculo, placa, data) {
            document.getElementById('registro_id_excluir').value = id;
            document.getElementById('exclusao_veiculo').textContent = 'Veículo: ' + veiculo;
            document.getElementById('exclusao_placa').textContent = 'Placa: ' + placa;
            const d = data.split('-'); 
            document.getElementById('exclusao_data').textContent = 'Data: ' + (d.length === 3 ? `${d[2]}/${d[1]}/${d[0]}` : data);
            document.getElementById('modalExclusao').style.display = 'block';
        }
        function fecharModalExclusao() { document.getElementById('modalExclusao').style.display = 'none'; }
        
        function abrirModalAdicao() {
            document.getElementById('formAdicao').reset(); 
            document.getElementById('tipo_combustivel').value = '';
            document.getElementById('secretaria').value = ''; // Ensure secretaria is reset
            document.getElementById('data').value = '<?= $data_atual_form ?>';
            document.getElementById('hora').value = '<?= $hora_atual_form ?>';
            document.getElementById('outro_posto_container').style.display = 'none';
            document.getElementById('outro_posto').value = '';

            $('#nota_fiscal_arquivo').val('');
            $('#nota_fiscal_filename_display').text('Nenhum arquivo selecionado').attr('title', 'Nenhum arquivo selecionado');
            $('#nota_fiscal_preview_container').hide();
            $('#nota_fiscal_image_preview').hide().attr('src', '#');
            $('#nota_fiscal_pdf_preview').hide();
            $('#nota_fiscal_pdf_filename').text('');
            $('#nota_fiscal_remove_button').hide();
            $('#nota_fiscal_progress_container').hide();
            $('#nota_fiscal_progress_bar').width('0%');
            $('#nota_fiscal_progress_text').text('');
            
            $('.border-red-500').removeClass('border-red-500'); // Clear previous validation highlights
            document.getElementById('modalAdicao').style.display = 'block';
        }
        function fecharModalAdicao() { document.getElementById('modalAdicao').style.display = 'none'; }

        window.onclick = function(event) {
            ['modalEdicao', 'modalExclusao', 'modalAdicao'].forEach(id => {
                const modal = document.getElementById(id);
                if (event.target == modal) {
                    modal.style.display = 'none';
                }
            });
        }

        function buscarVeiculos(termo) {
            if (termo.length < 1) { $('#suggestions').hide().empty(); return; }
            $.ajax({ url: 'buscar_veiuculos_abastecimento.php', type: 'GET', data: { termo: termo, tipo: 'prefixo' }, dataType: 'json',
                success: data => {
                    const s = $('#suggestions').empty().hide();
                    if (data && data.length > 0) {
                        data.forEach(v => {
                            const displayText = `${v.prefixo || ''} - ${v.placa || ''} (${v.tipo || v.veiculo || v.prefixo || 'N/A'})`.trim();
                            s.append(`<div class="suggestion-item" onclick="selecionarVeiculo('${(v.prefixo || v.veiculo || '').replace(/'/g, "\\'")}')">${displayText}</div>`);
                        });
                    } else {
                         s.append('<div class="suggestion-item">Nenhum veículo encontrado</div>');
                    }
                    if (s.children().length > 0) s.show(); else s.hide();
                }, error: (xhr) => $('#suggestions').html(`<div class="suggestion-item">Erro ao buscar: ${xhr.statusText}</div>`).show()
            });
        }
        function selecionarVeiculo(prefixoOuVeiculo) { $('#prefixo_veiculo').val(prefixoOuVeiculo); $('#suggestions').hide().empty(); }

        function buscarVeiculosPorPrefixo(prefixo) {
            if (prefixo.length < 1) { $('#suggestions_prefixo').hide().empty(); return; }
            $.ajax({ url: 'buscar_veiuculos_abastecimento.php', type: 'GET', data: { termo: prefixo, tipo: 'prefixo_completo' }, dataType: 'json',
                success: data => {
                    const s = $('#suggestions_prefixo').empty().hide();
                    if (data && data.length > 0) {
                        data.forEach(v => {
                            const pfx = (v.prefixo||'').replace(/'/g, "\\'"), plc = (v.placa||'').replace(/'/g, "\\'"), vei = (v.tipo||v.veiculo||v.prefixo||'').replace(/'/g, "\\'"), sec = (v.secretaria||'').replace(/'/g, "\\'");
                            const displayText = `${v.prefixo||'N/A'} - ${v.placa||'N/A'} (${v.tipo||v.veiculo||v.prefixo||'N/A'})`;
                            s.append(`<div class="suggestion-item" onclick="selecionarVeiculoCompleto('${pfx}','${plc}','${vei}','${sec}')">${displayText}</div>`);
                        });
                    } else {
                        s.append('<div class="suggestion-item">Nenhum veículo encontrado</div>');
                    }
                     if (s.children().length > 0) s.show(); else s.hide();
                }, error: (xhr) => $('#suggestions_prefixo').html(`<div class="suggestion-item">Erro: ${xhr.statusText}</div>`).show()
            });
        }
        function selecionarVeiculoCompleto(prefixo, placa, veiculo, secretaria) {
            $('#prefixo').val(prefixo); $('#placa').val(placa); $('#veiculo').val(veiculo);
            if (secretaria && $('#secretaria').val() === '') $('#secretaria').val(secretaria);
            $('#suggestions_prefixo').hide().empty();
            if (placa) {
                $.get('buscar_ultimo_km_abastecimento.php', { placa: placa }, function(data) { 
                    if (data && data.km_abastecido) {
                        $('#km_abastecido').val(data.km_abastecido);
                    }
                }, 'json').fail(function() { console.warn("Erro ao buscar KM para placa:", placa); });
            }
        }

        function buscarUsuariosGenerico(termo, containerID, nomeID, idFieldID, secretariaID = null) {
            if (termo.length < 2) { $(`#${containerID}`).hide().empty(); return; }
            $.ajax({ url: 'buscar_usuario.php', type: 'GET', data: { termo: termo }, dataType: 'json',
                success: data => {
                    const s = $(`#${containerID}`).empty().hide();
                    if (data && data.length > 0) {
                        data.forEach(u => {
                            let info = [(u.secretaria||''), (u.cpf ? u.cpf.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, "$1.$2.$3-$4") : ''), (u.number||'')].filter(Boolean).join(' | ');
                            const n = u.name.replace(/'/g, "\\'"), sec = (u.secretaria||'').replace(/'/g, "\\'");
                            const click = secretariaID ? `selecionarUsuarioCompleto('${n}','${sec}',${u.id},'${nomeID}','${idFieldID}','${secretariaID}','${containerID}')` : `selecionarUsuarioSimples('${n}',${u.id},'${nomeID}','${idFieldID}','${containerID}')`;
                            s.append(`<div class="suggestion-item" onclick="${click}"><div class="font-medium">${u.name}</div>${info ? `<div class="text-xs text-gray-600">${info}</div>` : ''}</div>`);
                        });
                    } else {
                        s.append('<div class="suggestion-item">Nenhum usuário encontrado</div>');
                    }
                    if (s.children().length > 0) s.show(); else s.hide();
                }, error: (xhr) => $(`#${containerID}`).html(`<div class="suggestion-item">Erro: ${xhr.statusText}</div>`).show()
            });
        }
        function buscarUsuarios(termo) { buscarUsuariosGenerico(termo, 'suggestions_usuarios', 'nome', 'usuario_id', 'secretaria'); }
        function buscarUsuariosModal(termo) { buscarUsuariosGenerico(termo, 'modal_suggestions_usuarios', 'modal_nome', 'modal_usuario_id'); }
        function selecionarUsuarioSimples(nome, userId, nomeID, idFieldID, containerID) { $(`#${nomeID}`).val(nome); $(`#${idFieldID}`).val(userId); $(`#${containerID}`).hide().empty(); }
        function selecionarUsuarioCompleto(nome, secretaria, userId, nomeID, idFieldID, secretariaID, containerID) {
            $(`#${nomeID}`).val(nome); $(`#${idFieldID}`).val(userId);
            if (secretaria && $(`#${secretariaID}`).val() === '') $(`#${secretariaID}`).val(secretaria);
            $(`#${containerID}`).hide().empty();
        }

        function handlePostoChange(selectElement, outroContainerId, outroInputId, callbackCalc) {
            const outroContainer = document.getElementById(outroContainerId); 
            const outroInput = document.getElementById(outroInputId);
            if (selectElement.value === 'outro') { 
                outroContainer.style.display = 'block'; 
                outroInput.required = true; 
            } else { 
                outroContainer.style.display = 'none'; 
                outroInput.required = false; 
                outroInput.value = ''; 
            }
            if (callbackCalc) callbackCalc();
        }
        function handlePostoChangeAdicao(select) { handlePostoChange(select, 'outro_posto_container', 'outro_posto', calcularValorAbastecimento); }
        function handlePostoChangeModal(select) { handlePostoChange(select, 'outro_posto_modal_container', 'outro_posto_modal', calcularValorAbastecimentoModal); }

        function calcularValorComBaseEmPreco(litrosVal, postoVal, combustivelVal, inputValorElement) {
            let combustivelParaPreco = combustivelVal; 
            if (combustivelVal === 'Diesel-S500') combustivelParaPreco = 'Diesel'; // Ajuste se 'Diesel' for a chave na lista de preços
            
            if (litrosVal > 0 && postoVal && combustivelVal && postoVal !== 'outro') {
                const precoComb = precosCombustiveis.find(p => p.posto_nome === postoVal && p.tipo_combustivel === combustivelParaPreco);
                const preco = precoComb ? parseFloat(precoComb.preco) : 0;
                if (preco > 0) {
                     const finalValue = (litrosVal * preco).toFixed(2);
                     // Para o modal de edição (modal_valor), o valor deve ser numérico puro.
                     // Para o modal de adição (valor_abastecimento), pode ser string com vírgula.
                     if (inputValorElement.id === 'modal_valor') {
                         inputValorElement.value = finalValue; 
                     } else {
                         inputValorElement.value = finalValue.replace('.', ',');
                     }
                }
            } else if (litrosVal <= 0 && inputValorElement.id !== 'modal_valor') { // Clear if litros is not positive, except for modal_valor which might be manually edited
                 // inputValorElement.value = ''; // Comentado para permitir edição manual do valor mesmo com litros 0
            }
        }
        function calcularValorAbastecimento() { 
            const litros = parseFloat(document.getElementById('litros').value.replace(',', '.')) || 0;
            const posto = document.getElementById('posto').value; 
            const combustivel = document.getElementById('tipo_combustivel').value;
            calcularValorComBaseEmPreco(litros, posto, combustivel, document.getElementById('valor_abastecimento'));
        }
        function calcularValorAbastecimentoModal() { 
            const litros = parseFloat(document.getElementById('modal_litros').value.replace(',', '.')) || 0;
            const posto = document.getElementById('modal_posto_gasolina').value; 
            const combustivel = document.getElementById('modal_combustivel').value;
            calcularValorComBaseEmPreco(litros, posto, combustivel, document.getElementById('modal_valor'));
        }
        
        function validateAndSubmitForm(formId, progressBarContainerId, progressBarId, progressTextId) {
            const form = $(`#${formId}`)[0]; // Get the DOM element
            const formData = new FormData(form);
            const progressBarContainer = $(`#${progressBarContainerId}`);
            const progressBar = $(`#${progressBarId}`);
            const progressText = $(`#${progressTextId}`);

            let isValid = true;
            let firstInvalidField = null;
            $(`#${formId} [required]`).each(function() {
                $(this).removeClass('border-red-500'); // Clear previous highlights
                if (!$(this).val() || ($(this).is('select') && $(this).val() === "")) {
                    isValid = false;
                    $(this).addClass('border-red-500');
                    if (!firstInvalidField) firstInvalidField = $(this);
                }
            });
            
            // Specific validation for numbers > 0
            $(`#${formId} input[name="litros"], #${formId} input[name="valor"]`).each(function() {
                const val = parseFloat($(this).val().replace(',', '.'));
                if (val <= 0) {
                    isValid = false;
                    $(this).addClass('border-red-500');
                     if (!firstInvalidField) firstInvalidField = $(this);
                }
            });


            if (!isValid) {
                displayAjaxMessage('Por favor, preencha todos os campos obrigatórios destacados e verifique se os valores numéricos são positivos.', 'error');
                if (firstInvalidField) firstInvalidField.focus();
                return;
            }

            $.ajax({
                url: $(form).attr('action') || window.location.pathname + window.location.search,
                type: $(form).attr('method'),
                data: formData,
                processData: false,
                contentType: false,
                dataType: 'json', 
                xhr: function() {
                    const xhr = new window.XMLHttpRequest();
                    progressBarContainer.show();
                    progressBar.width('0%');
                    progressText.text('0%');

                    xhr.upload.addEventListener('progress', function(evt) {
                        if (evt.lengthComputable) {
                            const percentComplete = Math.round((evt.loaded / evt.total) * 100);
                            progressBar.width(percentComplete + '%');
                            progressText.text(percentComplete + '% Enviado');
                        }
                    }, false);
                    return xhr;
                },
                success: function(response) {
                    progressBar.width('100%'); // Ensure it shows 100% on success before hiding
                    progressText.text('Envio Concluído!');
                    
                    if (response.success) {
                        displayAjaxMessage(response.message, 'success');
                        setTimeout(() => {
                            progressBarContainer.hide(); // Hide after a short delay
                            window.location.href = response.redirectUrl || 'alterar_abastecimentos.php';
                        }, 1000); // Short delay to see "Envio Concluído!"
                    } else {
                        displayAjaxMessage('Erro: ' + response.message, 'error');
                        progressBarContainer.hide();
                    }
                },
                error: function(jqXHR, textStatus, errorThrown) {
                    progressBarContainer.hide();
                    let errorMsg = 'Erro na requisição: ';
                    if (jqXHR.responseJSON && jqXHR.responseJSON.message) {
                        errorMsg += jqXHR.responseJSON.message;
                    } else if (jqXHR.responseText) {
                        try {
                            const errData = JSON.parse(jqXHR.responseText);
                            errorMsg += errData.message || errorThrown || textStatus;
                        } catch (e) {
                             if (jqXHR.status === 0) { errorMsg = 'Não foi possível conectar. Verifique sua rede.'; }
                             else if (jqXHR.status == 404) { errorMsg = 'Recurso não encontrado (404).'; }
                             else if (jqXHR.status == 500) { errorMsg = 'Erro interno do servidor (500).'; }
                             else if (textStatus === 'parsererror') { errorMsg = 'Erro ao analisar a resposta do servidor.'; }
                             else if (textStatus === 'timeout') { errorMsg = 'Tempo esgotado.'; }
                             else if (textStatus === 'abort') { errorMsg = 'Requisição AJAX abortada.'; }
                             else { errorMsg += jqXHR.responseText.substring(0, 100) + (jqXHR.responseText.length > 100 ? "..." : "");} // Show part of non-JSON error
                        }
                    } else {
                        errorMsg += errorThrown || textStatus;
                    }
                    displayAjaxMessage(errorMsg, 'error');
                    console.error("AJAX Error Details:", jqXHR.status, jqXHR.responseText, textStatus, errorThrown);
                }
            });
        }
        
        $(document).ready(function() {
            $('#formAdicao').on('submit', function(e) {
                e.preventDefault();
                validateAndSubmitForm('formAdicao', 'nota_fiscal_progress_container', 'nota_fiscal_progress_bar', 'nota_fiscal_progress_text');
            });

            $('#formEdicao').on('submit', function(e) {
                e.preventDefault();
                validateAndSubmitForm('formEdicao', 'modal_nota_fiscal_progress_container', 'modal_nota_fiscal_progress_bar', 'modal_nota_fiscal_progress_text');
            });
        });


        $(document).click(function(event) { 
            if (!$(event.target).closest('.suggestions-container').length && 
                !$(event.target).is('input[oninput*="buscarVeiculos"], input[oninput*="buscarUsuarios"]')) { // Check if not clicking on the input itself
                $('.suggestions-list').hide().empty(); 
            }
        });

        $(document).ready(function() { 
            console.log("Página de gerenciamento de abastecimentos carregada e pronta."); 
            // A mensagem da sessão PHP já é exibida no bloco #ajax-message-placeholder se existir.
            // Não precisa ser movida para cá se o placeholder já está no HTML com o código PHP.
        });
    </script>
</body>
</html>