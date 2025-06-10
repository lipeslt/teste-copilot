<?php
require_once '../conexao.php';
require_once 'troca_oleo_helper.php';

$veiculo_id = isset($_GET['veiculo_id']) ? intval($_GET['veiculo_id']) : 0;

// Busca todos os veículos para o dropdown
$veiculos = [];
$sql_veiculos = "SELECT id, veiculo, placa FROM veiculos ORDER BY veiculo";
$result_veiculos = $conn->query($sql_veiculos);
while($row = $result_veiculos->fetch_assoc()) {
    $veiculos[$row['id']] = $row;
}

// Se um veículo foi selecionado, busca o histórico
$historico = [];
if($veiculo_id > 0 && isset($veiculos[$veiculo_id])) {
    $historico = getHistoricoTrocas($conn, $veiculo_id);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Histórico de Trocas de Óleo</title>
    <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/css/bootstrap.min.css">
</head>
<body>
    <div class="container mt-4">
        <h1 class="mb-4">Histórico de Trocas de Óleo</h1>
        
        <form method="get" action="" class="mb-4">
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label for="veiculo_id">Selecione o Veículo</label>
                    <select name="veiculo_id" id="veiculo_id" class="form-control" onchange="this.form.submit()">
                        <option value="">Selecione...</option>
                        <?php foreach($veiculos as $id => $veiculo): ?>
                        <option value="<?= $id ?>" <?= $id == $veiculo_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($veiculo['veiculo']) ?> (<?= htmlspecialchars($veiculo['placa']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </form>
        
        <?php if($veiculo_id > 0): ?>
        <h3>Veículo: <?= htmlspecialchars($veiculos[$veiculo_id]['veiculo']) ?> - Placa: <?= htmlspecialchars($veiculos[$veiculo_id]['placa']) ?></h3>
        
        <?php if(count($historico) > 0): ?>
        <div class="table-responsive">
            <table class="table table-bordered table-striped">
                <thead>
                    <tr>
                        <th>Data da Troca</th>
                        <th>Quilometragem</th>
                        <th>Próxima Troca KM</th>
                        <th>Próxima Troca Data</th>
                        <th>Observação</th>
                        <th>Registrado por</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($historico as $troca): ?>
                    <tr>
                        <td><?= date('d/m/Y', strtotime($troca['data_troca'])) ?></td>
                        <td><?= number_format($troca['km_troca'], 0, ',', '.') ?> km</td>
                        <td><?= number_format($troca['km_proxima'], 0, ',', '.') ?> km</td>
                        <td><?= date('d/m/Y', strtotime($troca['data_proxima'])) ?></td>
                        <td><?= htmlspecialchars($troca['observacao'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($troca['usuario_registro']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="alert alert-info">Nenhum histórico de troca de óleo encontrado para este veículo.</div>
        <?php endif; ?>
        <?php endif; ?>
        
        <a href="painel_troca_oleo.php" class="btn btn-primary">Voltar para o Painel</a>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/popper.js@1.16.1/dist/umd/popper.min.js"></script>
    <script src="https://stackpath.bootstrapcdn.com/bootstrap/4.5.2/js/bootstrap.min.js"></script>
</body>
</html>