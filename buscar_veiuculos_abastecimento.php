<?php
include 'conexao.php';

header('Content-Type: application/json');

if (isset($_GET['termo'])) {
    $termo = trim($_GET['termo']);
    $tipo = $_GET['tipo'] ?? 'prefixo'; // Padrão é prefixo
    
    try {
        if ($tipo === 'placa') {
            // Buscar veículos por placa (todos os status)
            $query = "SELECT 
                        id,
                        veiculo, 
                        placa, 
                        matricula as prefixo, 
                        secretaria, 
                        tipo,
                        combustivel,
                        status
                      FROM veiculos 
                      WHERE placa LIKE :termo
                      ORDER BY placa 
                      LIMIT 10";
        } else {
            // Buscar veículos por prefixo (matricula) (todos os status)
            $query = "SELECT 
                        id,
                        veiculo, 
                        placa, 
                        matricula as prefixo, 
                        secretaria, 
                        tipo,
                        combustivel,
                        status
                      FROM veiculos 
                      WHERE matricula LIKE :termo
                      ORDER BY matricula 
                      LIMIT 10";
        }
        
        $stmt = $conn->prepare($query);
        $stmt->bindValue(':termo', "%$termo%");
        $stmt->execute();
        
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($resultados);
    } catch (Exception $e) {
        error_log("Erro na busca de veículos: " . $e->getMessage());
        echo json_encode(['error' => 'Erro ao buscar veículos']);
    }
} else {
    echo json_encode(['error' => 'Termo de busca não fornecido']);
}
?>