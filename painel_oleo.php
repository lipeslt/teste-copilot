<?php
// Inclui os arquivos de conexão e funções auxiliares
require_once '../conexao.php';
require_once 'troca_oleo_helper.php'; // Mantido para compatibilidade com outras páginas

/**
 * (VERSÃO FINAL E CORRIGIDA) Obtém o último KM atual do veículo diretamente da tabela 'registros'.
 * Busca o km_final do registro mais recente que não seja nulo ou zero.
 *
 * @param PDO $conn Objeto de conexão PDO
 * @param int $veiculo_id ID do veículo
 * @return float Último KM válido ou 0
 */
function painelGetUltimoKm($conn, $veiculo_id) {
    // A consulta busca o km_final do registro mais recente (maior data, depois maior ID)
    // que tenha um valor de km_final válido (não nulo e maior que zero).
    $sql = "SELECT km_final 
            FROM registros 
            WHERE veiculo_id = :veiculo_id 
              AND km_final IS NOT NULL 
              AND km_final > 0 
            ORDER BY data DESC, id DESC 
            LIMIT 1";
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute([':veiculo_id' => $veiculo_id]);
        $km = $stmt->fetchColumn();
        
        // fetchColumn() retorna false se não houver linha, então retornamos 0 nesse caso.
        return $km ? (float)$km : 0;
    } catch (PDOException $e) {
        error_log("Erro ao buscar KM do veículo $veiculo_id: " . $e->getMessage());
        return 0; // Retorna 0 em caso de erro para não quebrar a página.
    }
}

/**
 * Registra uma troca de óleo e atualiza o veículo usando PDO.
 *
 * @param PDO $conn
 * @param int $veiculo_id
 * @param float $km_atual
 * @param int $intervalo_km
 * @param int $intervalo_dias
 * @param string $observacao
 * @param string $usuario
 * @return array
 */
function painelRegistrarTrocaOleo($conn, $veiculo_id, $km_atual, $intervalo_km, $intervalo_dias, $observacao, $usuario) {
    try {
        $conn->beginTransaction();

        $km_proxima = $km_atual + $intervalo_km;
        $data_proxima = date('Y-m-d', strtotime("+$intervalo_dias days"));

        $sql_update = "UPDATE veiculos SET
            km_ultima_troca = :km_atual,
            data_ultima_troca = CURRENT_DATE(),
            km_proxima_troca = :km_proxima,
            data_proxima_troca = :data_proxima,
            intervalo_km_troca = :intervalo_km
            WHERE id = :veiculo_id";
        
        $stmt_update = $conn->prepare($sql_update);
        $stmt_update->execute([
            ':km_atual' => $km_atual,
            ':km_proxima' => $km_proxima,
            ':data_proxima' => $data_proxima,
            ':intervalo_km' => $intervalo_km,
            ':veiculo_id' => $veiculo_id
        ]);

        $sql_historico = "INSERT INTO historico_trocas_oleo
                         (veiculo_id, km_troca, data_troca, km_proxima, data_proxima, observacao, usuario_registro)
                         VALUES (:veiculo_id, :km_troca, CURRENT_DATE(), :km_proxima, :data_proxima, :observacao, :usuario_registro)";

        $stmt_historico = $conn->prepare($sql_historico);
        $stmt_historico->execute([
            ':veiculo_id' => $veiculo_id,
            ':km_troca' => $km_atual,
            ':km_proxima' => $km_proxima,
            ':data_proxima' => $data_proxima,
            ':observacao' => $observacao,
            ':usuario_registro' => $usuario
        ]);

        $conn->commit();
        return ['status' => true, 'message' => 'Troca de óleo registrada com sucesso!'];

    } catch (PDOException $e) {
        $conn->rollBack();
        return ['status' => false, 'message' => 'Erro ao registrar troca: ' . $e->getMessage()];
    }
}

// --- LÓGICA PARA REGISTRO DE TROCA (AJAX) ---
if (isset($_POST['atualizar_troca']) && $_POST['atualizar_troca'] == '1') {
    $veiculo_id = $_POST['veiculo_id'] ?? null;
    $km_atual = isset($_POST['km_atual']) ? (float)str_replace(',', '.', $_POST['km_atual']) : 0;
    $intervalo_km = $_POST['intervalo_km'] ?? 10000;
    $intervalo_dias = $_POST['intervalo_dias'] ?? 180;
    $observacao = $_POST['observacao'] ?? '';
    $usuario_registro = 'Usuário do Sistema';

    if(empty($veiculo_id) || empty($km_atual)) {
        echo json_encode(['status' => false, 'message' => 'Veículo e KM atual são obrigatórios.']);
        exit;
    }

    $resultado = painelRegistrarTrocaOleo($conn, $veiculo_id, $km_atual, $intervalo_km, $intervalo_dias, $observacao, $usuario_registro);
    
    header('Content-Type: application/json');
    echo json_encode($resultado);
    exit;
}

// --- LÓGICA PRINCIPAL PARA CARREGAMENTO DO PAINEL ---
$erro = '';
$veiculos_processados = [];

try {
    $sql = "SELECT * FROM veiculos ORDER BY veiculo";
    $stmt = $conn->query($sql);
    $veiculos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $hoje = new DateTime();

    foreach ($veiculos as $veiculo) {
        // Pega o KM atual real da tabela 'registros'
        $km_atual_real = painelGetUltimoKm($conn, $veiculo['id']);
        
        // Calcula dias restantes
        $dias_restantes = null;
        if (!empty($veiculo['data_proxima_troca'])) {
            $data_proxima = new DateTime($veiculo['data_proxima_troca']);
            $diff = $hoje->diff($data_proxima);
            $dias_restantes = ($diff->invert) ? -$diff->days : $diff->days;
        }

        // Calcula KM restantes
        $km_restantes = null;
        if (!empty($veiculo['km_proxima_troca'])) {
            $km_restantes = $veiculo['km_proxima_troca'] - $km_atual_real;
        }

        // --- LÓGICA DE STATUS E CORES ---
        $status_level = 0; // 0: Ok, 1: Amarelo (Atenção), 2: Vermelho (Crítico), 3: Vencido
        
        if ($km_restantes !== null) {
            if ($km_restantes <= 0) $status_level = max($status_level, 3);
            elseif ($km_restantes <= 1000) $status_level = max($status_level, 2);
            elseif ($km_restantes <= 2000) $status_level = max($status_level, 1);
        }
        
        if ($dias_restantes !== null) {
            if ($dias_restantes < 0) $status_level = max($status_level, 3);
            elseif ($dias_restantes <= 7) $status_level = max($status_level, 2);
            elseif ($dias_restantes <= 14) $status_level = max($status_level, 1);
        }

        switch ($status_level) {
            case 3: $status = ['classe' => 'border-danger', 'texto' => 'Vencido', 'icone' => 'fa-solid fa-triangle-exclamation']; break;
            case 2: $status = ['classe' => 'border-danger', 'texto' => 'Crítico', 'icone' => 'fa-solid fa-fire']; break;
            case 1: $status = ['classe' => 'border-warning', 'texto' => 'Atenção', 'icone' => 'fa-solid fa-clock']; break;
            default: $status = ['classe' => 'border-success', 'texto' => 'Em dia', 'icone' => 'fa-solid fa-circle-check']; break;
        }

        // Define a cor das barras de progresso
        $km_bar_class = 'bg-primary';
        if ($km_restantes !== null) {
            if ($km_restantes <= 1000) $km_bar_class = 'bg-danger';
            elseif ($km_restantes <= 2000) $km_bar_class = 'bg-warning text-dark';
        }

        $days_bar_class = 'bg-info';
        if ($dias_restantes !== null) {
            if ($dias_restantes <= 7) $days_bar_class = 'bg-danger';
            elseif ($dias_restantes <= 14) $days_bar_class = 'bg-warning text-dark';
        }

        // --- CÁLCULO CORRETO DAS BARRAS DE PROGRESSO ---
        $progresso_km = 0;
        $km_da_ultima_troca = (float)($veiculo['km_ultima_troca'] ?? 0);
        $intervalo_km = (float)($veiculo['intervalo_km_troca'] ?? 1); // Evita divisão por zero
        if ($intervalo_km > 0) {
            $km_rodados_desde_troca = $km_atual_real - $km_da_ultima_troca;
            $progresso_km = ($km_rodados_desde_troca / $intervalo_km) * 100;
        }
        
        $progresso_dias = 0;
        if (!empty($veiculo['data_ultima_troca']) && !empty($veiculo['data_proxima_troca'])) {
            $data_inicio = new DateTime($veiculo['data_ultima_troca']);
            $data_fim = new DateTime($veiculo['data_proxima_troca']);
            $intervalo_total_dias = $data_inicio->diff($data_fim)->days;
            if ($intervalo_total_dias > 0) {
                $dias_passados = $hoje >= $data_inicio ? $data_inicio->diff($hoje)->days : 0;
                $progresso_dias = ($dias_passados / $intervalo_total_dias) * 100;
            }
        }
        
        // Adiciona os dados processados ao array final
        $veiculo['km_atual_real'] = $km_atual_real;
        $veiculo['km_atual_formatado'] = number_format($km_atual_real, 0, ',', '.');
        $veiculo['km_restantes'] = $km_restantes;
        $veiculo['dias_restantes'] = $dias_restantes;
        $veiculo['status'] = $status;
        $veiculo['km_bar_class'] = $km_bar_class;
        $veiculo['days_bar_class'] = $days_bar_class;
        $veiculo['progresso_km'] = max(0, min(100, $progresso_km));
        $veiculo['progresso_dias'] = max(0, min(100, $progresso_dias));
        
        $veiculos_processados[] = $veiculo;
    }
} catch (PDOException $e) {
    $erro = "Erro Crítico ao buscar veículos: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel de Troca de Óleo - Versão Final</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@300;400;500;700&display=swap" rel="stylesheet">

    <style>
        body { font-family: 'Roboto', sans-serif; background-color: #f8f9fa; }
        .navbar { box-shadow: 0 2px 4px rgba(0,0,0,.1); }
        .card-veiculo { transition: all 0.3s ease; border-left-width: 5px; border-radius: .5rem; }
        .card-veiculo:hover { transform: translateY(-5px); box-shadow: 0 4px 15px rgba(0,0,0,.1); }
        .status-badge { font-size: 0.9rem; padding: 0.5em 0.8em; }
        .progress-wrapper .progress { height: 8px; background-color: #e9ecef; }
        .progress-label { font-size: 0.8rem; font-weight: 500; color: #6c757d; }
    </style>
</head>
<body>

    <nav class="navbar navbar-expand-lg navbar-light bg-white sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold text-primary" href="#">
                <i class="fa-solid fa-oil-can"></i>
                Gestão de Frota
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link active" href="painel_oleo.php">
                            <i class="fa-solid fa-table-columns"></i> Painel
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="alertas_troca_oleo.php">
                            <i class="fa-solid fa-bell"></i> Alertas
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="historico_trocas.php">
                            <i class="fa-solid fa-history"></i> Histórico
                        </a>
                    </li>
                    <li class="nav-item ms-lg-2">
                        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#trocaModal" data-veiculo-id="">
                            <i class="fa-solid fa-plus"></i> Registrar Nova Troca
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <h1 class="mb-4">Status da Frota</h1>

        <?php if (!empty($erro)): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($erro) ?></div>
        <?php endif; ?>

        <div class="row g-4">
            <?php if (count($veiculos_processados) > 0): ?>
                <?php foreach ($veiculos_processados as $veiculo): ?>
                    <div class="col-xl-3 col-lg-4 col-md-6">
                        <div class="card card-veiculo shadow-sm h-100 <?= $veiculo['status']['classe'] ?>">
                            <div class="card-body d-flex flex-column">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <h5 class="card-title fw-bold mb-0"><?= htmlspecialchars($veiculo['veiculo']) ?></h5>
                                        <small class="text-muted"><?= htmlspecialchars($veiculo['placa']) ?></small>
                                    </div>
                                    <span class="badge rounded-pill text-bg-light status-badge">
                                        <i class="<?= $veiculo['status']['icone'] ?>"></i> <?= $veiculo['status']['texto'] ?>
                                    </span>
                                </div>

                                <p class="text-muted small mb-3">
                                    KM Atual (Registros): <strong><?= $veiculo['km_atual_formatado'] ?> km</strong>
                                </p>

                                <div class="mb-3 progress-wrapper">
                                    <div class="d-flex justify-content-between progress-label">
                                        <span><i class="fa-solid fa-road"></i> Progresso por KM</span>
                                        <span class="fw-bold">
                                            <?php if ($veiculo['km_restantes'] !== null): ?>
                                                <?= ($veiculo['km_restantes'] > 0) ? 'Faltam ' . number_format($veiculo['km_restantes'], 0, ',', '.') . ' km' : 'Vencido' ?>
                                            <?php else: echo 'N/D'; endif; ?>
                                        </span>
                                    </div>
                                    <div class="progress" role="progressbar">
                                        <div class="progress-bar <?= $veiculo['km_bar_class'] ?>" style="width: <?= $veiculo['progresso_km'] ?>%"></div>
                                    </div>
                                    <div class="d-flex justify-content-between small text-muted mt-1">
                                        <span><?= number_format($veiculo['km_ultima_troca'] ?? 0, 0, ',', '.') ?> km</span>
                                        <span><?= number_format($veiculo['km_proxima_troca'] ?? 0, 0, ',', '.') ?> km</span>
                                    </div>
                                </div>
                                
                                <div class="mb-4 progress-wrapper">
                                    <div class="d-flex justify-content-between progress-label">
                                        <span><i class="fa-solid fa-calendar-days"></i> Progresso por Data</span>
                                        <span class="fw-bold">
                                            <?php if ($veiculo['dias_restantes'] !== null): ?>
                                                <?= ($veiculo['dias_restantes'] >= 0) ? 'Faltam ' . $veiculo['dias_restantes'] . ' dias' : 'Vencido há ' . abs($veiculo['dias_restantes']) . ' dias' ?>
                                            <?php else: echo 'N/D'; endif; ?>
                                        </span>
                                    </div>
                                    <div class="progress" role="progressbar">
                                        <div class="progress-bar <?= $veiculo['days_bar_class'] ?>" style="width: <?= $veiculo['progresso_dias'] ?>%"></div>
                                    </div>
                                    <div class="d-flex justify-content-between small text-muted mt-1">
                                         <span><?= !empty($veiculo['data_ultima_troca']) ? date('d/m/Y', strtotime($veiculo['data_ultima_troca'])) : 'N/D' ?></span>
                                         <span><?= !empty($veiculo['data_proxima_troca']) ? date('d/m/Y', strtotime($veiculo['data_proxima_troca'])) : 'N/D' ?></span>
                                    </div>
                                </div>
                                
                                <div class="mt-auto text-center">
                                    <button class="btn btn-primary w-100 registrar-btn" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#trocaModal"
                                            data-veiculo-id="<?= $veiculo['id'] ?>"
                                            data-veiculo-nome="<?= htmlspecialchars($veiculo['veiculo']) ?>"
                                            data-km-atual="<?= $veiculo['km_atual_real'] ?>"
                                            data-intervalo-km="<?= $veiculo['intervalo_km_troca'] ?? 10000 ?>">
                                        <i class="fa-solid fa-gas-pump"></i> Registrar Troca
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="alert alert-info">Nenhum veículo processado.</div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <div class="modal fade" id="trocaModal" tabindex="-1" aria-labelledby="trocaModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form id="modalForm" method="POST">
                    <div class="modal-header">
                        <h5 class="modal-title" id="trocaModalLabel">Registrar Troca de Óleo</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Fechar"></button>
                    </div>
                    <div class="modal-body">
                        <div id="modal-alert" class="alert d-none"></div>
                        <input type="hidden" name="veiculo_id" id="modal_veiculo_id">
                        <input type="hidden" name="atualizar_troca" value="1">
                        <div class="mb-3">
                            <label for="modal_veiculo_select" class="form-label">Veículo</label>
                            <select name="veiculo_id_select" id="modal_veiculo_select" class="form-select" required>
                                <option value="">Selecione um veículo...</option>
                                <?php
                                if(!empty($veiculos_processados)){
                                    foreach ($veiculos_processados as $v) {
                                        echo "<option value='{$v['id']}' data-km='{$v['km_atual_real']}' data-intervalo-km='{$v['intervalo_km_troca']}'>"
                                            . htmlspecialchars($v['veiculo'] . ' (' . $v['placa'] . ')')
                                            . "</option>";
                                    }
                                }
                                ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="modal_km_atual" class="form-label">Quilometragem da Troca</label>
                            <input type="number" step="1" name="km_atual" id="modal_km_atual" class="form-control" required>
                        </div>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="modal_intervalo_km" class="form-label">Intervalo p/ Próxima (KM)</label>
                                <input type="number" step="1000" name="intervalo_km" id="modal_intervalo_km" class="form-control" value="10000" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="modal_intervalo_dias" class="form-label">Intervalo p/ Próxima (Dias)</label>
                                <input type="number" name="intervalo_dias" id="modal_intervalo_dias" class="form-control" value="180" required>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="modal_observacao" class="form-label">Observação (Opcional)</label>
                            <textarea name="observacao" id="modal_observacao" rows="2" class="form-control"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" id="submitModalForm">
                            <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                            Salvar Registro
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const trocaModalEl = document.getElementById('trocaModal');
            if (!trocaModalEl) return;
            const trocaModal = new bootstrap.Modal(trocaModalEl);
            const modalForm = document.getElementById('modalForm');
            const modalTitle = document.getElementById('trocaModalLabel');
            const veiculoSelect = document.getElementById('modal_veiculo_select');
            document.querySelectorAll('.registrar-btn').forEach(button => {
                button.addEventListener('click', function() {
                    const veiculoId = this.dataset.veiculoId;
                    modalForm.reset();
                    document.getElementById('modal-alert').classList.add('d-none');
                    if (veiculoId) {
                        const veiculoNome = this.dataset.veiculoNome;
                        const kmAtual = this.dataset.kmAtual;
                        const intervaloKm = this.dataset.intervaloKm;
                        modalTitle.textContent = `Registrar Troca - ${veiculoNome}`;
                        document.getElementById('modal_veiculo_id').value = veiculoId;
                        if(veiculoSelect) veiculoSelect.value = veiculoId;
                        document.getElementById('modal_km_atual').value = Math.round(kmAtual);
                        document.getElementById('modal_intervalo_km').value = intervaloKm || 10000;
                        document.getElementById('modal_intervalo_dias').value = 180;
                    } else { 
                        modalTitle.textContent = 'Registrar Nova Troca de Óleo';
                        if(veiculoSelect) veiculoSelect.value = '';
                    }
                });
            });
            if (veiculoSelect) {
                veiculoSelect.addEventListener('change', function() {
                    const selectedOption = this.options[this.selectedIndex];
                    if(selectedOption.value){
                        const km = selectedOption.dataset.km || 0;
                        const intervaloKm = selectedOption.dataset.intervaloKm || 10000;
                        document.getElementById('modal_veiculo_id').value = selectedOption.value;
                        document.getElementById('modal_km_atual').value = Math.round(km);
                        document.getElementById('modal_intervalo_km').value = intervaloKm;
                        document.getElementById('modal_intervalo_dias').value = 180;
                    }
                });
            }
            modalForm.addEventListener('submit', function(e) {
                e.preventDefault();
                const submitButton = document.getElementById('submitModalForm');
                const spinner = submitButton.querySelector('.spinner-border');
                const modalAlert = document.getElementById('modal-alert');
                const selectedVeiculoId = veiculoSelect ? veiculoSelect.value : null;
                if (!document.getElementById('modal_veiculo_id').value && selectedVeiculoId) {
                    document.getElementById('modal_veiculo_id').value = selectedVeiculoId;
                }
                if(!document.getElementById('modal_veiculo_id').value){
                    modalAlert.className = 'alert alert-warning';
                    modalAlert.textContent = 'Por favor, selecione um veículo.';
                    modalAlert.classList.remove('d-none');
                    return;
                }
                submitButton.disabled = true;
                spinner.classList.remove('d-none');
                modalAlert.classList.add('d-none');
                const formData = new FormData(this);
                fetch('painel_oleo.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.status) {
                        modalAlert.className = 'alert alert-success';
                        modalAlert.textContent = data.message;
                        modalAlert.classList.remove('d-none');
                        setTimeout(() => {
                            trocaModal.hide();
                            location.reload();
                        }, 1500);
                    } else {
                        throw new Error(data.message || 'Ocorreu um erro desconhecido.');
                    }
                })
                .catch(error => {
                    modalAlert.className = 'alert alert-danger';
                    modalAlert.textContent = 'Erro: ' + error.message;
                    modalAlert.classList.remove('d-none');
                    submitButton.disabled = false;
                    spinner.classList.add('d-none');
                });
            });
        });
    </script>
</body>
</html>
