<?php
session_start();
require 'conexao.php';

if (!isset($_SESSION['role'])) {
    header('Location: login.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Histórico de Bloqueios</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100">
    <div class="container mx-auto px-4 py-8">
        <div class="bg-white rounded-lg shadow-md p-6">
            <h1 class="text-2xl font-bold mb-6 flex items-center">
                <i class="fas fa-history text-blue-500 mr-2"></i>
                Histórico de Bloqueios/Desbloqueios
            </h1>
            
            <div class="overflow-x-auto">
                <table class="min-w-full bg-white">
                    <thead>
                        <tr class="bg-gray-200 text-gray-700">
                            <th class="py-3 px-4 text-left">Veículo</th>
                            <th class="py-3 px-4 text-left">Placa</th>
                            <th class="py-3 px-4 text-left">Ação</th>
                            <th class="py-3 px-4 text-left">Realizado por</th>
                            <th class="py-3 px-4 text-left">Data/Hora</th>
                        </tr>
                    </thead>
                    <tbody class="text-gray-600">
                        <?php
                        try {
                            $stmt = $conn->query("
                                SELECT h.*, v.veiculo, v.placa 
                                FROM historico_bloqueio h
                                JOIN veiculos v ON h.veiculo_id = v.id
                                ORDER BY h.data_hora DESC
                                LIMIT 100
                            ");
                            
                            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                                echo "<tr class='border-b border-gray-200 hover:bg-gray-50'>";
                                echo "<td class='py-3 px-4'>";
                                echo "<div class='font-medium'>" . htmlspecialchars($row['veiculo']) . "</div>";
                                echo "</td>";
                                echo "<td class='py-3 px-4 font-mono'>" . htmlspecialchars($row['placa']) . "</td>";
                                echo "<td class='py-3 px-4'>";
                                $badgeClass = $row['acao'] === 'bloqueio' ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800';
                                echo "<span class='px-2 py-1 rounded-full text-xs font-medium $badgeClass'>";
                                echo $row['acao'] === 'bloqueio' ? 'Bloqueio' : 'Desbloqueio';
                                echo "</span>";
                                echo "</td>";
                                echo "<td class='py-3 px-4'>" . htmlspecialchars($row['realizado_por']) . "</td>";
                                echo "<td class='py-3 px-4'>" . date('d/m/Y H:i', strtotime($row['data_hora'])) . "</td>";
                                echo "</tr>";
                            }
                        } catch (PDOException $e) {
                            echo "<tr><td colspan='6' class='py-3 px-4 text-center text-red-500'>Erro ao carregar histórico: " . $e->getMessage() . "</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>