<?php
// db_connection.php
$host = 'localhost';
$dbname = 'workflow_system';
$username = 'root';
$password = '';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Connection failed: " . $e->getMessage());
    die("Database connection error. Please try again later.");
}

// Buscar usuário por ID
function getUserById($id) {
    global $conn;
    $stmt = $conn->prepare("SELECT * FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

// Pedidos aceitos pelo mecânico
function getPedidosAceitosMecanico($mecanicoId) {
    global $conn;
    $query = "SELECT s.id, s.tempo_estimado, s.inicio_servico, s.observacoes
              FROM servicos_mecanica s
              WHERE s.mecanico_id = :mecanico_id 
              AND s.inicio_servico IS NOT NULL 
              AND s.data_conclusao IS NULL"; // Filtra apenas os pedidos em andamento
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':mecanico_id', $mecanicoId);
    $stmt->execute();
    return $stmt->fetchAll();
}

// Notificações disponíveis
function getNotificacoesDisponiveis() {
    global $conn;
    $query = "SELECT n.id, n.nome, n.mensagem, n.status 
              FROM notificacoes n
              WHERE n.status = 'pendente'"; // Busca notificações pendentes
    $stmt = $conn->query($query);
    return $stmt->fetchAll();
}

// Fichas disponíveis
function getFichasDisponiveis() {
    global $conn;
    $query = "SELECT f.id, f.nome, f.suspensao, f.secretaria, f.placa 
              FROM ficha_defeito f
              WHERE f.suspensao IS NOT NULL"; // Busca fichas com problemas identificados
    $stmt = $conn->query($query);
    return $stmt->fetchAll();
}

// Serviços finalizados pelo mecânico
function getServicosFinalizados($mecanicoId) {
    global $conn;
    $query = "SELECT s.id, s.data_conclusao, s.observacoes 
              FROM servicos_mecanica s
              WHERE s.mecanico_id = :mecanico_id 
              AND s.data_conclusao IS NOT NULL"; // Filtra os serviços concluídos
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':mecanico_id', $mecanicoId);
    $stmt->execute();
    return $stmt->fetchAll();
}
?>