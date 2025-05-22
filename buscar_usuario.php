<?php
include 'conexao.php';

// Para debug
error_log("Função buscar_usuarios.php chamada");

header('Content-Type: application/json');

if (isset($_GET['termo'])) {
    $termo = trim($_GET['termo']);
    $termo = "%$termo%";
    
    error_log("Buscando usuários com termo: " . $termo);
    
    try {
        // Buscar usuários que correspondem ao termo de busca
        $query = "SELECT id, name, email, cpf, secretaria, number 
                FROM usuarios 
                WHERE name LIKE :termo
                ORDER BY name 
                LIMIT 10";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':termo', $termo);
        $stmt->execute();
        
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        error_log("Encontrados " . count($resultados) . " usuários");
        error_log("Resultados: " . json_encode($resultados));
        
        echo json_encode($resultados);
    } catch (Exception $e) {
        error_log("Erro na busca de usuários: " . $e->getMessage());
        echo json_encode([]);
    }
} else {
    error_log("Nenhum termo fornecido para busca de usuários");
    echo json_encode([]);
}