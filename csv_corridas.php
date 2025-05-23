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

// Obter parâmetros de filtro
$tipo_operacao = $_GET['tipo_operacao'] ?? '';
$data_inicial = $_GET['data_inicial'] ?? '';
$data_final = $_GET['data_final'] ?? '';
$registro_id = $_GET['registro_id'] ?? '';
$admin_name = $_GET['admin_name'] ?? '';
$campo_alterado = $_GET['campo_alterado'] ?? '';

// Construir a consulta SQL base
$sql_base = "SELECT h.id, h.registro_id, h.admin_id, h.admin_name, h.tipo_operacao, 
                    h.campo_alterado, h.valor_antigo, h.valor_novo, 
                    DATE_FORMAT(h.data_alteracao, '%d/%m/%Y %H:%i:%s') as data_formatada 
             FROM historico_alteracoes h 
             WHERE 1=1";
$params = [];

// Adicionar filtros à consulta
if (!empty($tipo_operacao)) {
    $sql_base .= " AND h.tipo_operacao = :tipo_operacao";
    $params[':tipo_operacao'] = $tipo_operacao;
}

if (!empty($data_inicial)) {
    $sql_base .= " AND DATE(h.data_alteracao) >= :data_inicial";
    $params[':data_inicial'] = $data_inicial;
}

if (!empty($data_final)) {
    $sql_base .= " AND DATE(h.data_alteracao) <= :data_final";
    $params[':data_final'] = $data_final;
}

if (!empty($registro_id)) {
    $sql_base .= " AND h.registro_id = :registro_id";
    $params[':registro_id'] = $registro_id;
}

if (!empty($admin_name)) {
    $sql_base .= " AND h.admin_name LIKE :admin_name";
    $params[':admin_name'] = "%$admin_name%";
}

if (!empty($campo_alterado)) {
    $sql_base .= " AND h.campo_alterado LIKE :campo_alterado";
    $params[':campo_alterado'] = "%$campo_alterado%";
}

// Consulta final para exportação
$sql = $sql_base . " ORDER BY h.data_alteracao DESC";
$stmt = $conn->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$historico = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Função para limpar valor para CSV
function limparValorCSV($valor) {
    // Remove tags HTML, quebras de linha e aspas duplas
    $valor = strip_tags($valor);
    $valor = str_replace(["\r", "\n"], ' ', $valor);
    $valor = str_replace('"', '""', $valor);
    
    // Se o valor for um JSON, tenta formatá-lo como texto plano
    if ($valor != 'N/A' && !empty($valor)) {
        $json_decoded = json_decode($valor, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json_decoded)) {
            $valor = 'Objeto JSON';
        }
    }
    
    return $valor;
}

// Preparar cabeçalho para download de CSV
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="historico_alteracoes_' . date('Y-m-d_H-i-s') . '.csv"');

// Criar arquivo CSV
$output = fopen('php://output', 'w');

// Adicionar BOM (Byte Order Mark) para Excel interpretar corretamente caracteres UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Escrever cabeçalho
fputcsv($output, [
    'ID', 
    'Data/Hora', 
    'Registro ID', 
    'Admin ID', 
    'Admin Nome', 
    'Tipo Operação', 
    'Campo Alterado', 
    'Valor Antigo', 
    'Valor Novo'
], ';');

// Escrever dados
foreach ($historico as $linha) {
    fputcsv($output, [
        $linha['id'],
        $linha['data_formatada'],
        $linha['registro_id'],
        $linha['admin_id'],
        $linha['admin_name'],
        $linha['tipo_operacao'],
        $linha['campo_alterado'],
        limparValorCSV($linha['valor_antigo']),
        limparValorCSV($linha['valor_novo'])
    ], ';');
}

fclose($output);
exit;
?>