<?php
require_once '../conexao.php';
require_once 'troca_oleo_helper.php';

// Busca veículos com troca de óleo vencida ou próxima do vencimento
$sql = "SELECT v.*, 
        (SELECT MAX(km_final) FROM usuarios WHERE veiculo_id = v.id AND km_final IS NOT NULL AND km_final > 0) as ultimo_km,
        DATEDIFF(v.data_proxima_troca, CURRENT_DATE()) as dias_restantes
        FROM veiculos v
        WHERE 
        (v.data_proxima_troca IS NOT NULL AND v.data_proxima_troca <= DATE_ADD(CURRENT_DATE(), INTERVAL 30 DAY))
        OR
        ((SELECT MAX(km_final) FROM usuarios WHERE veiculo_id = v.id) >= (v.km_proxima_troca - 1000))
        ORDER BY dias_restantes ASC";

$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alertas de Troca de Óleo</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
    <style>
        .vencido {
            background-color: #ffcccc;
        }
        .proximo {
            background-color: #ffffcc;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <h1 class="mb-4">Alertas de Troca de Óleo</h1>
        
        <?php if($result->num_rows > 0): ?>
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Veículo</th>
                        <th>Placa</th>
                        <th>Último KM</th>
                        <th>KM para Troca</th>
                        <th>Data para Troca</th>
                        <th>Status</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($veiculo = $result->fetch_assoc()): 
                        $km_restantes = $veiculo['km_proxima_troca'] ? $veiculo['km_proxima_troca'] - $veiculo['ultimo_km'] : null;
                        
                        $status_class = "";
                        $status_texto = "";
                        
                        if($veiculo['data_proxima_troca'] && strtotime($veiculo['data_proxima_troca']) < time()) {
                            $status_class = "vencido";
                            $status_texto = "Vencido por data";
                        } elseif($km_restantes !== null && $km_restantes <= 0) {
                            $status_class = "vencido";
                            $status_texto = "Vencido por KM";
                        } elseif($veiculo['data_proxima_troca'] && strtotime($veiculo['data_proxima_troca']) < strtotime('+30 days')) {
                            $status_class = "proximo";
                            $status_texto = "Próximo da data";
                        } elseif($km_restantes !== null && $km_restantes < 1000) {
                            $status_class = "proximo";
                            $status_texto = "Próximo do KM";
                        }
                    ?>
                    <tr class="<?= $status_class ?>">
                        <td><?= htmlspecialchars($veiculo['veiculo']) ?></td>
                        <td><?= htmlspecialchars($veiculo['placa']) ?></td>
                        <td><?= number_format($veiculo['ultimo_km'], 0, ',', '.') ?> km</td>
                        <td><?= number_format($veiculo['km_proxima_troca'], 0, ',', '.') ?> km</td>
                        <td><?= date('d/m/Y', strtotime($veiculo['data_proxima_troca'])) ?></td>
                        <td>
                            <strong><?= $status_texto ?></strong><br>
                            <?php if($km_restantes !== null): ?>
                                <?php if($km_restantes <= 0): ?>
                                    Vencido por <?= number_format(abs($km_restantes), 0, ',', '.') ?> km<br>
                                <?php else: ?>
                                    Faltam <?= number_format($km_restantes, 0, ',', '.') ?> km<br>
                                <?php endif; ?>
                            <?php endif; ?>
                            
                            <?php if($veiculo['dias_restantes'] !== null): ?>
                                <?php if($veiculo['dias_restantes'] < 0): ?>
                                    Vencido há <?= abs($veiculo['dias_restantes']) ?> dias
                                <?php else: ?>
                                    Faltam <?= $veiculo['dias_restantes'] ?> dias
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="painel_troca_oleo.php" class="btn btn-sm btn-primary">Registrar Troca</a>
                            <a href="historico_trocas.php?veiculo_id=<?= $veiculo['id'] ?>" class="btn btn-sm btn-info">Histórico</a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="alert alert-success">
            <h4>Tudo em dia!</h4>
            <p>Não há veículos com troca de óleo vencida ou próxima do vencimento.</p>
        </div>
        <?php endif; ?>
        
        <a href="painel_troca_oleo.php" class="btn btn-primary">Voltar para o Painel</a>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>