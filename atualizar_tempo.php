<?php
require 'db_connection.php';

$data = json_decode(file_get_contents('php://input'), true);
$pedido_id = $data['pedido_id'];
$tempo = $data['tempo'];

$query = "UPDATE servicos_mecanico SET tempo_estimado = :tempo WHERE id = :pedido_id";
$stmt = $conn->prepare($query);
$stmt->bindParam(':tempo', $tempo);
$stmt->bindParam(':pedido_id', $pedido_id);
$stmt->execute();

echo json_encode(['success' => true]);
?>
