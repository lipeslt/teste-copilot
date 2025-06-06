<?php
require 'db_connection.php'; // Inclui a conexão com o banco de dados

session_start();

// Define o fuso horário para Cuiabá (UTC-4)
date_default_timezone_set('America/Cuiaba');

// Verifica se o usuário está logado
if (empty($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// --- Captura de Filtros e Ordenação ---
$filtro_secretaria = isset($_GET['secretaria']) && $_GET['secretaria'] !== 'all' ? $_GET['secretaria'] : null;
$ordem = isset($_GET['ordem']) && $_GET['ordem'] === 'ASC' ? 'ASC' : 'DESC';

// Definir o número de itens por página para a paginação
$itens_por_pagina = 10;
$pagina_atual = isset($_GET['pagina']) ? intval($_GET['pagina']) : 1;
$offset = ($pagina_atual - 1) * $itens_por_pagina;
$mecanico_id_session = $_SESSION['user_id']; // Store session user_id

// Consultas para buscar dados do banco de dados
try {
    // --- Lógica de Filtro e Ordenação ---
    $where_secretaria_ficha = $filtro_secretaria ? " AND f.secretaria = :secretaria_ficha " : "";
    $where_secretaria_notif = $filtro_secretaria ? " AND n.secretaria = :secretaria_notif " : "";
    
    // Fichas de Defeito com status 'aceito' e não em processo por este mecânico
    $queryFichas = "SELECT f.*, 'ficha' AS tipo_origem, u.name as submitter_name, u.cpf as submitter_cpf, u.secretaria as submitter_secretaria
                    FROM ficha_defeito f 
                    LEFT JOIN usuarios u ON f.user_id = u.id
                    WHERE f.status = 'aceito' 
                    AND NOT EXISTS (
                        SELECT 1 
                        FROM servicos_mecanica sm 
                        WHERE sm.ficha_id = f.id 
                        AND sm.mecanico_id = :mecanico_id_ficha
                        AND sm.status IN ('aceito', 'em_andamento')
                    )
                    {$where_secretaria_ficha}
                    ORDER BY f.data {$ordem}, f.hora {$ordem}";
    $stmtFichas = $conn->prepare($queryFichas);
    $stmtFichas->bindParam(':mecanico_id_ficha', $mecanico_id_session);
    if ($filtro_secretaria) $stmtFichas->bindParam(':secretaria_ficha', $filtro_secretaria);
    $stmtFichas->execute();
    $fichas_aceitas = $stmtFichas->fetchAll(PDO::FETCH_ASSOC);

    // Pedidos Aceitos dos serviços (servicos_mecanica)
    // Para filtrar pedidos, a lógica precisa considerar a origem (ficha ou notificação)
    $where_secretaria_pedidos = $filtro_secretaria ? " AND (f.secretaria = :secretaria_pedidos OR n.secretaria = :secretaria_pedidos)" : "";
    $queryPedidos = "
        SELECT 
            sm.*, 
            CASE 
                WHEN sm.ficha_id IS NOT NULL THEN 'ficha'
                WHEN sm.notificacao_id IS NOT NULL THEN 'notificacao'
                ELSE 'pedido'
            END AS tipo_origem,
            n.mensagem as notificacao_mensagem, n.data as notificacao_data, n.prefixo as notificacao_prefixo, n.secretaria as notificacao_secretaria,
            f.data as ficha_data, f.hora as ficha_hora, f.nome as ficha_nome, f.nome_veiculo as ficha_nome_veiculo,
            f.veiculo_id as ficha_veiculo_id, f.secretaria as ficha_secretaria,
            u_ficha.name as ficha_submitter_name, u_ficha.cpf as ficha_submitter_cpf, u_ficha.secretaria as ficha_submitter_secretaria,
            u_notif.name as notif_submitter_name, u_notif.cpf as notif_submitter_cpf, u_notif.secretaria as notif_submitter_secretaria
        FROM servicos_mecanica sm
        LEFT JOIN notificacoes n ON sm.notificacao_id = n.id
        LEFT JOIN ficha_defeito f ON sm.ficha_id = f.id
        LEFT JOIN usuarios u_ficha ON f.user_id = u_ficha.id
        LEFT JOIN usuarios u_notif ON n.user_id = u_notif.id
        WHERE sm.mecanico_id = :mecanico_id 
        AND sm.status IN ('aceito', 'em_andamento')
        {$where_secretaria_pedidos}
        ORDER BY sm.created_at {$ordem}";
    $stmtPedidos = $conn->prepare($queryPedidos);
    $stmtPedidos->bindParam(':mecanico_id', $mecanico_id_session);
    if ($filtro_secretaria) $stmtPedidos->bindParam(':secretaria_pedidos', $filtro_secretaria);
    $stmtPedidos->execute();
    $pedidos_ativos = $stmtPedidos->fetchAll(PDO::FETCH_ASSOC);

    // Notificações com status 'aceito' e não em processo por este mecânico
    $queryNotificacoes = "SELECT n.*, 'notificacao' AS tipo_origem, u.name as submitter_name, u.cpf as submitter_cpf, u.secretaria as submitter_secretaria
                          FROM notificacoes n
                          LEFT JOIN usuarios u ON n.user_id = u.id
                          WHERE n.status = 'aceito' 
                          AND NOT EXISTS (
                              SELECT 1
                              FROM servicos_mecanica sm
                              WHERE sm.notificacao_id = n.id
                              AND sm.mecanico_id = :mecanico_id_notif
                              AND sm.status IN ('aceito', 'em_andamento')
                          )
                          {$where_secretaria_notif}
                          ORDER BY n.data {$ordem}"; // CORREÇÃO: Usando n.data
    $stmtNotificacoes = $conn->prepare($queryNotificacoes);
    $stmtNotificacoes->bindParam(':mecanico_id_notif', $mecanico_id_session);
    if ($filtro_secretaria) $stmtNotificacoes->bindParam(':secretaria_notif', $filtro_secretaria);
    $stmtNotificacoes->execute();
    $notificacoes_aceitas = $stmtNotificacoes->fetchAll(PDO::FETCH_ASSOC);

    // Contar total de serviços finalizados para paginação
    $join_finalizados_filtro = $filtro_secretaria ? "LEFT JOIN notificacoes n ON s.notificacao_id = n.id LEFT JOIN ficha_defeito f ON s.ficha_id = f.id" : "";
    $where_finalizados_filtro = $filtro_secretaria ? "AND (f.secretaria = :secretaria OR n.secretaria = :secretaria)" : "";
    
    $queryContarFinalizados = "
        SELECT COUNT(s.id) as total 
        FROM servicos_mecanica s
        {$join_finalizados_filtro}
        WHERE s.mecanico_id = :mecanico_id AND s.status = 'finalizado'
        {$where_finalizados_filtro}";
    $stmtContarFinalizados = $conn->prepare($queryContarFinalizados);
    $stmtContarFinalizados->bindParam(':mecanico_id', $mecanico_id_session);
    if($filtro_secretaria) $stmtContarFinalizados->bindParam(':secretaria', $filtro_secretaria);
    $stmtContarFinalizados->execute();
    $total_servicos = $stmtContarFinalizados->fetch(PDO::FETCH_ASSOC)['total'];
    
    $total_paginas = ceil($total_servicos / $itens_por_pagina);

    // Serviços Finalizados com paginação e ordenação
    $queryFinalizados = "
        SELECT 
            s.*,
            CASE 
                WHEN s.ficha_id IS NOT NULL THEN 'ficha'
                WHEN s.notificacao_id IS NOT NULL THEN 'notificacao'
                ELSE 'pedido'
            END AS tipo_origem,
            n.*, n.mensagem as notificacao_mensagem, n.data as notificacao_data_finalizado,
            f.*, f.data as ficha_data_finalizado, f.hora as ficha_hora_finalizado,
            u_ficha.name as ficha_submitter_name, u_ficha.cpf as ficha_submitter_cpf, u_ficha.secretaria as ficha_submitter_secretaria,
            u_notif.name as notif_submitter_name, u_notif.cpf as notif_submitter_cpf, u_notif.secretaria as notif_submitter_secretaria
        FROM servicos_mecanica s
        LEFT JOIN notificacoes n ON s.notificacao_id = n.id
        LEFT JOIN ficha_defeito f ON s.ficha_id = f.id
        LEFT JOIN usuarios u_ficha ON f.user_id = u_ficha.id
        LEFT JOIN usuarios u_notif ON n.user_id = u_notif.id
        WHERE s.mecanico_id = :mecanico_id AND s.status = 'finalizado'
        {$where_finalizados_filtro}
        ORDER BY s.data_conclusao {$ordem}
        LIMIT :offset, :limite";
    $stmtFinalizados = $conn->prepare($queryFinalizados);
    $stmtFinalizados->bindParam(':mecanico_id', $mecanico_id_session);
    $stmtFinalizados->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmtFinalizados->bindParam(':limite', $itens_por_pagina, PDO::PARAM_INT);
    if($filtro_secretaria) $stmtFinalizados->bindParam(':secretaria', $filtro_secretaria);
    $stmtFinalizados->execute();
    $servicos_finalizados = $stmtFinalizados->fetchAll(PDO::FETCH_ASSOC);

    // Dados do usuário
    $queryUser = "SELECT * FROM usuarios WHERE id = :id";
    $stmtUser = $conn->prepare($queryUser);
    $stmtUser->bindParam(':id', $mecanico_id_session);
    $stmtUser->execute();
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);
    
    // Lista de Secretarias para o filtro
    $querySecretarias = "(SELECT DISTINCT secretaria FROM ficha_defeito WHERE secretaria IS NOT NULL AND secretaria != '')
                         UNION
                         (SELECT DISTINCT secretaria FROM notificacoes WHERE secretaria IS NOT NULL AND secretaria != '')
                         ORDER BY secretaria ASC";
    $stmtSecretarias = $conn->prepare($querySecretarias);
    $stmtSecretarias->execute();
    $lista_secretarias = $stmtSecretarias->fetchAll(PDO::FETCH_COLUMN);


    $userName = $user['name'] ?? 'Usuário';
    $profilePhoto = $user['profile_photo'] ?? 'https://placehold.co/40x40';
} catch (PDOException $e) {
    echo "Erro ao buscar dados: " . $e->getMessage();
    exit();
}
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Pedidos Mecânica</title>

  <script src="https://cdn.tailwindcss.com?plugins=forms,typography,aspect-ratio,line-clamp"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            'primary': '#4F46E5',
            'secondary': '#F59E0B',
            'accent': '#10B981',
            'danger': '#EF4444'
          },
          boxShadow: {
            'soft': '0 4px 24px -6px rgba(0, 0, 0, 0.1)',
            'hard': '0 8px 24px -6px rgba(79, 70, 229, 0.3)'
          }
        }
      }
    };
  </script>

  <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
  <script defer src="https://unpkg.com/react@18/umd/react.development.js"></script>
  <script defer src="https://unpkg.com/react-dom@18/umd/react-dom.development.js"></script>
  <script defer src="https://unpkg.com/babel-standalone@6/babel.min.js"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

  <style>
    * {
      -webkit-tap-highlight-color: transparent;
    }
    body {
      font-family: 'Inter', system-ui, -apple-system, sans-serif;
      margin: 0;
      overflow-x: hidden;
    }
    .app-container {
      width: 100%;
      min-height: 100vh;
      background: #f8f9fa;
    }
    .profile-ring {
      box-shadow: 0 0 0 3px white, 0 0 0 6px #4F46E5;
    }
    .pedido-card {
      transition: all 0.3s ease;
    }
    .pedido-card:hover {
      transform: translateY(-2px);
    }
    .badge-pendente { background-color: #F59E0B; color: white; }
    .badge-aceito { background-color: #10B981;  color: white; }
    .badge-em-processo { background-color: #60A5FA; color: white; }
    .badge-em-andamento { background-color: #3B82F6; color: white; }
    .badge-concluido { background-color: #4F46E5; color: white; }
    .badge-alta { background-color: #EF4444; color: white; }
    .badge-media { background-color: #F59E0B; color: white; }
    .badge-baixa { background-color: #10B981; color: white; }
    .badge-notificacao { background-color: #3B82F6; color: white; }
    .badge-ficha { background-color: #8B5CF6; color: white; }
    .badge-pedido { background-color: #4F46E5; color: white; }
    .timer-container {
      background-color: #f0f9ff;
      border: 1px solid #bae6fd;
      border-radius: 0.5rem;
      padding: 0.75rem;
      margin-top: 0.75rem;
    }
    .timer-display {
      font-family: monospace;
      font-size: 1.5rem;
      font-weight: bold;
      color: #0284c7;
      text-align: center;
      margin-bottom: 0.75rem;
    }
    .pagination {
      display: flex;
      justify-content: center;
      gap: 0.5rem;
      margin-top: 2rem;
      margin-bottom: 2rem;
    }
    .pagination-item {
      display: flex;
      align-items: center;
      justify-content: center;
      width: 2.5rem;
      height: 2.5rem;
      border-radius: 0.5rem;
      background-color: white;
      color: #4F46E5;
      border: 1px solid #e5e7eb;
      cursor: pointer;
      transition: all 0.2s;
    }
    .pagination-item:hover { background-color: #f3f4f6; }
    .pagination-item.active {
      background-color: #4F46E5;
      color: white;
      border-color: #4F46E5;
    }
    .pagination-item.disabled { opacity: 0.5; cursor: not-allowed; }
    @media (max-width: 640px) {
      .action-buttons { justify-content: flex-end; margin-left: auto; }
      .bottom-nav { left: 0; right: 0; margin-left: 0 !important; width: 100%;}
      main { padding-bottom: 5rem; }
      .pedido-card { padding: 1rem; }
      .pedido-card h3 { font-size: 1rem; }
      .pedido-card p { font-size: 0.875rem; }
      .pagination-item { width: 2rem; height: 2rem; }
      .filter-bar { flex-direction: column; }
    }
  </style>
</head>
<body>
  <div id="getdify" data-getdify="pedidos-mecanica" style="display:none;"></div>

  <script>
    window.profilePhoto = <?= json_encode($profilePhoto) ?>;
    window.userName = <?= json_encode($userName) ?>;
    window.pedidosAtivos = <?= json_encode($pedidos_ativos) ?>;
    window.notificacoesDisponiveis = <?= json_encode($notificacoes_aceitas) ?>;
    window.fichasDisponiveis = <?= json_encode($fichas_aceitas) ?>;
    window.servicosFinalizados = <?= json_encode($servicos_finalizados) ?>;
    window.totalPaginas = <?= json_encode($total_paginas) ?>;
    window.paginaAtual = <?= json_encode($pagina_atual) ?>;
    window.listaSecretarias = <?= json_encode($lista_secretarias) ?>;
  </script>

  <div id="root"></div>

  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <script type="text/babel">
    function App() {
      const [activeTab, setActiveTab] = React.useState('pedidos');
      const [photoError, setPhotoError] = React.useState(false);
      const [selectedPedido, setSelectedPedido] = React.useState(null);
      const [showModal, setShowModal] = React.useState(false);
      const [modalContent, setModalContent] = React.useState(null);
      const [timerInterval, setTimerInterval] = React.useState({});
      const [notificacoesDisponiveisState, setNotificacoesDisponiveis] = React.useState(window.notificacoesDisponiveis);
      const [fichasDisponiveisState, setFichasDisponiveis] = React.useState(window.fichasDisponiveis);
      const [pedidosAtivosState, setPedidosAtivos] = React.useState(window.pedidosAtivos);
      const [servicosFinalizadosState, setServicosFinalizados] = React.useState(window.servicosFinalizados);
      const [paginaAtual, setPaginaAtual] = React.useState(window.paginaAtual);
      const [totalPaginas, setTotalPaginas] = React.useState(window.totalPaginas);
      const [timers, setTimers] = React.useState({});
      const [loading, setLoading] = React.useState(false);

      const [filters, setFilters] = React.useState({
        secretaria: new URLSearchParams(window.location.search).get('secretaria') || 'all',
        ordem: new URLSearchParams(window.location.search).get('ordem') || 'DESC'
      });

      // --- INÍCIO: Funcionalidade de Etapas ---
      const [showEtapaModal, setShowEtapaModal] = React.useState(false);
      const [selectedServicoParaEtapa, setSelectedServicoParaEtapa] = React.useState(null);
      const [currentEtapaLoading, setCurrentEtapaLoading] = React.useState(false);

      const etapaOptions = [
        { value: 'ANALISE_INICIAL', label: 'Análise Inicial', icon: 'fas fa-search-plus' },
        { value: 'AGUARDANDO_PECAS', label: 'Aguardando Peças', icon: 'fas fa-boxes' },
        { value: 'AGUARDANDO_APROVACAO', label: 'Aguardando Aprovação', icon: 'fas fa-user-check' },
        { value: 'SERVICO_AUTORIZADO', label: 'Serviço Autorizado', icon: 'fas fa-play-circle' },
        { value: 'EM_EXECUCAO_INTERNA', label: 'Em Execução Interna', icon: 'fas fa-tools' },
        { value: 'FINALIZACAO_MONTAGEM', label: 'Finalização/Montagem', icon: 'fas fa-cogs' },
        { value: 'TESTES_VALIDACAO', label: 'Testes e Validação', icon: 'fas fa-clipboard-check' },
        { value: 'PENDENCIA_TERCEIRO', label: 'Pendência Externa', icon: 'fas fa-external-link-square-alt' },
        { value: '', label: 'Limpar Etapa (Nenhuma)', icon: 'fas fa-times-circle' }
      ];

      const getEtapaLabel = (etapaValue) => {
        const option = etapaOptions.find(opt => opt.value === etapaValue);
        return option ? option.label : (etapaValue || 'Não definida');
      };

      const getEtapaIconClass = (etapaValue) => {
        const option = etapaOptions.find(opt => opt.value === etapaValue);
        return (option && option.icon) ? option.icon : 'fa-info-circle';
      };

      const handleOpenEtapaModal = (pedido) => {
        setSelectedServicoParaEtapa(pedido);
        setShowEtapaModal(true);
      };

      const handleSalvarEtapa = async (servicoId, etapaValue) => {
        if (selectedServicoParaEtapa === null || typeof servicoId === 'undefined') {
          Swal.fire('Erro', 'Serviço não selecionado.', 'error');
          return;
        }
        setCurrentEtapaLoading(true);
        try {
          const response = await fetch('definir_etapa.php', { 
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ servico_id: servicoId, etapa: etapaValue }),
          });
          const data = await response.json();
          if (!response.ok || !data.success) {
            const errorMessage = data.message || 'Falha ao definir a etapa.';
            throw new Error(errorMessage);
          }
          setShowEtapaModal(false);
          refreshPageWithFilters(); 
          Swal.fire('Sucesso!', `Etapa definida: ${getEtapaLabel(etapaValue)}`, 'success');
        } catch (error) {
          Swal.fire('Erro!', error.message, 'error');
          console.error("Erro em handleSalvarEtapa:", error);
        } finally {
          setCurrentEtapaLoading(false);
          setSelectedServicoParaEtapa(null); 
        }
      };
      // --- FIM: Funcionalidade de Etapas ---

      const refreshPageWithFilters = () => {
          const urlParams = new URLSearchParams(window.location.search);
          urlParams.set('pagina', '1');
          urlParams.set('secretaria', filters.secretaria);
          urlParams.set('ordem', filters.ordem);
          window.location.search = urlParams.toString();
      };
      
      const handleFilterChange = (e) => {
        const { name, value } = e.target;
        const newFilters = {...filters, [name]: value};
        setFilters(newFilters);
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('pagina', '1');
        urlParams.set(name, value);
        window.location.search = urlParams.toString();
      };

      const toggleSortOrder = () => {
        const newOrder = filters.ordem === 'DESC' ? 'ASC' : 'DESC';
        const newFilters = {...filters, ordem: newOrder };
        setFilters(newFilters);
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('pagina', '1');
        urlParams.set('ordem', newOrder);
        window.location.search = urlParams.toString();
      };

      const handlePageChange = (newPage) => {
        if (newPage < 1 || newPage > totalPaginas || newPage === paginaAtual) return;
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('pagina', newPage);
        window.location.search = urlParams.toString();
      };

      React.useEffect(() => {
        const servicosEmAndamento = pedidosAtivosState.filter(pedido => pedido.status === 'em_andamento');
        servicosEmAndamento.forEach(servico => {
          if (servico.inicio_servico && !timers[servico.id] && !timerInterval[servico.id]) {
            iniciarTimerParaServico(servico.id, new Date(servico.inicio_servico));
          }
        });
        return () => {
          Object.values(timerInterval).forEach(clearInterval);
        };
      }, [pedidosAtivosState]);
      
      const formatDate = (dateString, timeString = null) => {
        if (!dateString) return 'Sem informações';
        // A data do banco vem como YYYY-MM-DD
        const datePart = dateString.split(' ')[0]; 
        let fullDateString = datePart;

        if (timeString) {
            fullDateString = `${datePart}T${timeString}`;
        }
        
        const dateObj = new Date(fullDateString);

        if (isNaN(dateObj.getTime())) {
            const fallbackDateObj = new Date(dateString); 
            if(isNaN(fallbackDateObj.getTime())) return 'Data inválida';
            return fallbackDateObj.toLocaleString('pt-BR', { timeZone: 'America/Cuiaba', day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        }

        const options = { timeZone: 'America/Cuiaba', day: '2-digit', month: '2-digit', year: 'numeric' };
        if(timeString) {
          options.hour = '2-digit';
          options.minute = '2-digit';
          options.second = '2-digit';
        }
        return dateObj.toLocaleString('pt-BR', options);
      };
      
      const iniciarTimerParaServico = (servicoId, startTime) => {
        const inicio = startTime instanceof Date && !isNaN(startTime) ? startTime : new Date();
        const agora = new Date();
        let tempoDesdeCriacao = Math.max(0, Math.floor((agora - inicio) / 1000));
        
        setTimers(prev => ({
          ...prev,
          [servicoId]: { tempoDecorrido: tempoDesdeCriacao, startTime: inicio }
        }));
        
        if (timerInterval[servicoId]) clearInterval(timerInterval[servicoId]);

        const intervalId = setInterval(() => {
          setTimers(prev => {
            const currentTimer = prev[servicoId];
            if (!currentTimer) { 
              clearInterval(intervalId);
              const newIntervals = {...timerInterval};
              delete newIntervals[servicoId];
              setTimerInterval(newIntervals);
              return prev;
            }
            return {
              ...prev,
              [servicoId]: { ...currentTimer, tempoDecorrido: (currentTimer.tempoDecorrido || 0) + 1 }
            };
          });
        }, 1000);
        
        setTimerInterval(prev => ({ ...prev, [servicoId]: intervalId }));
      };

      const pararTimer = (servicoId) => {
        if (timerInterval[servicoId]) {
          clearInterval(timerInterval[servicoId]);
          setTimerInterval(prev => {
            const newIntervals = {...prev};
            delete newIntervals[servicoId];
            return newIntervals;
          });
        }
      };

      const handlePrimeiraAceitacao = async (id) => {
          setLoading(true);
          try {
            const response = await fetch('primeira_aceitacao.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ id }),
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || 'Erro ao aceitar notificação.');
            Swal.fire('Sucesso!', 'Notificação aceita! Verifique a aba de Pedidos.', 'success').then(() => refreshPageWithFilters());
            setActiveTab('pedidos');
          } catch (error) {
            Swal.fire('Erro!', error.message, 'error');
          } finally {
            setLoading(false);
          }
      };

      const handlePrimeiraAceitacaoFicha = async (id) => {
         setLoading(true);
         try {
            const response = await fetch('primeira_aceitacao_ficha.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ id }),
            });
            const data = await response.json();
            if (!response.ok || !data.success) throw new Error(data.message || 'Erro ao aceitar ficha.');
            Swal.fire('Sucesso!', 'Ficha aceita! Verifique a aba de Pedidos.', 'success').then(() => refreshPageWithFilters());
            setActiveTab('pedidos');
          } catch (error) {
            Swal.fire('Erro!', error.message, 'error');
          } finally {
            setLoading(false);
          }
      };

      const handleDismiss = (id, type) => {
          Swal.fire({
            title: 'Dispensar item?',
            text: "Esta ação não poderá ser revertida e o item não aparecerá mais para você.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Sim, dispensar!',
            cancelButtonText: 'Cancelar'
          }).then((result) => {
            if (result.isConfirmed) {
              setLoading(true);
              fetch('dispensar_item.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ id, type }),
              })
              .then(res => res.json())
              .then(data => {
                if (data.success) {
                  Swal.fire('Dispensado!', 'O item foi removido da sua lista.', 'success').then(() => refreshPageWithFilters());
                } else {
                  Swal.fire('Erro', data.message || 'Não foi possível dispensar o item.', 'error');
                }
              })
              .catch(err => Swal.fire('Erro de Conexão', err.toString(), 'error'))
              .finally(() => setLoading(false));
            }
          });
      };

      const handleIniciarServico = async (id) => {
        try {
          setLoading(true);
          const response = await fetch('iniciar_servico.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id }),
          });
          const data = await response.json();
          if (!response.ok || !data.success) throw new Error(data.message || 'Erro ao iniciar serviço.');
          const startTime = data.inicio_servico ? new Date(data.inicio_servico) : new Date();
          iniciarTimerParaServico(id, startTime);
          refreshPageWithFilters(); 
          Swal.fire('Sucesso!', 'Serviço iniciado!', 'success');
        } catch (error) {
          Swal.fire('Erro!', error.message, 'error');
        } finally {
          setLoading(false);
        }
      };
      
      const handleFinalizarServico = async (pedidoId) => {
        try {
          setLoading(true);
          let tempoTotal = 0;
          if (timers[pedidoId] && typeof timers[pedidoId].tempoDecorrido !== 'undefined') {
            tempoTotal = timers[pedidoId].tempoDecorrido;
          }
          pararTimer(pedidoId);
          const response = await fetch('finalizar_servico.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
              pedido_id: pedidoId,
              finish_time: new Date().toISOString(),
              total_time: tempoTotal 
            }),
          });
          const data = await response.json();
          if (!response.ok || !data.success) throw new Error(data.message || 'Falha ao finalizar serviço.');
          setTimers(prev => {
            const newTimers = {...prev};
            delete newTimers[pedidoId];
            return newTimers;
          });
          refreshPageWithFilters();
          Swal.fire('Sucesso!', 'Serviço finalizado!', 'success');
        } catch (error) {
          Swal.fire('Erro!', error.message, 'error');
        } finally {
          setLoading(false);
        }
      };

      const formatarTempo = (segundos) => {
        if (typeof segundos !== 'number' || isNaN(segundos) || segundos < 0) return "00:00:00";
        const horas = Math.floor(segundos / 3600);
        const minutos = Math.floor((segundos % 3600) / 60);
        const segs = Math.floor(segundos % 60);
        return [
          horas.toString().padStart(2, '0'),
          minutos.toString().padStart(2, '0'),
          segs.toString().padStart(2, '0')
        ].join(':');
      };

      const handleVerDetalhes = (item) => {
        setSelectedPedido(item);
        let conteudoPrincipal = [];
        let informacoesBasicas = [];
        let informacoesSolicitante = [];
        
        const submitterName = item.submitter_name || item.notif_submitter_name || item.ficha_submitter_name;
        const submitterCpf = item.submitter_cpf || item.notif_submitter_cpf || item.ficha_submitter_cpf;
        const submitterSecretaria = item.submitter_secretaria || item.notif_submitter_secretaria || item.ficha_submitter_secretaria;

        if(submitterName) {
            informacoesSolicitante.push({ label: 'Solicitante', value: submitterName });
            informacoesSolicitante.push({ label: 'CPF do Solicitante', value: submitterCpf });
            informacoesSolicitante.push({ label: 'Secretaria do Solicitante', value: submitterSecretaria });
        }

        informacoesBasicas.push({
          type: 'badge', label: 'Tipo',
          value: item.tipo_origem === 'notificacao' ? 'Notificação' : (item.tipo_origem === 'ficha' ? 'Ficha' : 'Pedido'),
          className: item.tipo_origem === 'notificacao' ? 'badge-notificacao' : (item.tipo_origem === 'ficha' ? 'badge-ficha' : 'badge-pedido')
        });

        informacoesBasicas.push({
          type: 'badge', label: 'Status',
          value: item.status === 'aceito' ? 'Aceito' : (item.status === 'em_andamento' ? 'Em andamento' : (item.status === 'finalizado' ? 'Finalizado' : 'Pendente')),
          className: item.status === 'aceito' ? 'badge-aceito' : (item.status === 'em_andamento' ? 'badge-em-andamento' : (item.status === 'finalizado' ? 'badge-concluido' : 'badge-pendente'))
        });
        
        let dataParaFormatar, horaParaFormatar;

        if (item.tipo_origem === 'notificacao') {
          informacoesBasicas.push({ type: 'text', label: 'Veículo', value: `${item.prefixo || 'N/A'} • ${item.secretaria || 'Sem secretaria'}` });
          dataParaFormatar = item.data || item.notificacao_data_finalizado; // CORREÇÃO: Usando item.data
        } else if (item.tipo_origem === 'ficha') {
          informacoesBasicas.push({ type: 'text', label: 'Veículo', value: item.nome_veiculo || 'Sem nome de veículo' });
          informacoesBasicas.push({ type: 'text', label: 'ID do Veículo', value: item.veiculo_id || 'N/A'});
          informacoesBasicas.push({ type: 'text', label: 'Secretaria do Veículo', value: item.secretaria || 'Sem secretaria'});
          dataParaFormatar = item.data || item.ficha_data_finalizado;
          horaParaFormatar = item.hora || item.ficha_hora_finalizado;
        }
        
        informacoesBasicas.push({ type: 'text', label: 'Data do Evento', value: formatDate(dataParaFormatar, horaParaFormatar) });

        if (item.prioridade && (item.status === 'aceito' || item.status === 'em_andamento' || item.status === 'finalizado')) {
            informacoesBasicas.push({ type: 'text', label: 'Etapa Atual', value: getEtapaLabel(item.prioridade), className: 'text-purple-700 font-semibold' });
        }

        if (item.tipo_origem === 'notificacao') {
            const camposNotificacao = [
                { key: 'combustivel', label: 'Combustível'}, { key: 'agua', label: 'Água'},
                { key: 'oleo', label: 'Óleo'}, { key: 'bateria', label: 'Bateria'},
                { key: 'pneus', label: 'Pneus'}, { key: 'filtro_ar', label: 'Filtro de Ar'},
                { key: 'lampadas', label: 'Lâmpadas'}, { key: 'sistema_eletrico', label: 'Sistema Elétrico'}
            ];
            camposNotificacao.forEach(({key, label}) => {
                const obsKey = `obs_${key}`;
                if (item[obsKey] && item[obsKey].trim() !== '') {
                    conteudoPrincipal.push({ label: label, value: `${item[key]} (${item[obsKey]})`});
                }
            });
            if (conteudoPrincipal.length === 0) {
                conteudoPrincipal.push({ label: 'Situação', value: 'Nenhuma observação registrada nos itens do checklist.'});
            }
        } else if (item.tipo_origem === 'ficha') {
          const camposFicha = [
            { campo: 'suspensao', label: 'Suspensão', obs: 'obs_suspensao' }, { campo: 'motor', label: 'Motor', obs: 'obs_motor' },
            { campo: 'freios', label: 'Freios', obs: 'obs_freios' }, { campo: 'direcao', label: 'Direção', obs: 'obs_direcao' },
            { campo: 'sistema_eletrico', label: 'Sistema Elétrico', obs: 'obs_sistema_eletrico' }, { campo: 'carroceria', label: 'Carroceria', obs: 'obs_carroceria' },
            { campo: 'embreagem', label: 'Embreagem', obs: 'obs_embreagem' }, { campo: 'rodas', label: 'Rodas', obs: 'obs_rodas' },
            { campo: 'transmissao_9500', label: 'Transmissão', obs: 'obs_transmissao_9500' }, { campo: 'caixa_mudancas', label: 'Caixa de Mudanças', obs: 'obs_caixa_mudancas' },
            { campo: 'alimentacao', label: 'Alimentação', obs: 'obs_alimentacao' }, { campo: 'arrefecimento', label: 'Arrefecimento', obs: 'obs_arrefecimento' },
            { campo: 'lavagem_lubrificantes', label: 'Lavagem/Lubrificantes', obs: 'obs_lavagem_lubrificantes' }, { campo: 'acessorios', label: 'Acessórios', obs: 'obs_acessorios' }
          ];
          camposFicha.forEach(({ campo, label, obs }) => {
            if (item[campo] || item[obs]) {
              let textoPrincipal = item[campo] ? item[campo].trim() : ""; 
              let textoObs = item[obs] ? item[obs].trim() : ""; 
              if(textoPrincipal && textoObs) { conteudoPrincipal.push({ label, value: `${textoPrincipal} (${textoObs})` }); }
              else if (textoPrincipal) { conteudoPrincipal.push({ label, value: textoPrincipal }); }
              else if (textoObs) { conteudoPrincipal.push({ label, value: `Obs: ${textoObs}` }); }
            }
          });
        }

        if (item.inicio_servico) { informacoesBasicas.push({ type: 'text', label: 'Início do Serviço', value: formatDate(item.inicio_servico) }); }
        if (item.data_conclusao) { informacoesBasicas.push({ type: 'text', label: 'Conclusão', value: formatDate(item.data_conclusao) }); }
        if (item.tempo_real && item.status === 'finalizado') { informacoesBasicas.push({ type: 'text', label: 'Tempo Total Gasto', value: formatarTempo(item.tempo_real) }); }

        setModalContent(
          <div className="p-6 md:p-8 max-w-3xl mx-auto bg-white rounded-xl shadow-lg max-h-[85vh] overflow-y-auto">
            <h3 className="text-2xl font-bold mb-6 border-b pb-2 text-gray-700">Detalhes do Item</h3>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4">
              <div className="space-y-3">
                <h4 className="text-lg font-semibold text-gray-700 border-b pb-1">Informações do Veículo</h4>
                {informacoesBasicas.map((info, index) => (
                  <div key={`info-${index}`} className="pb-2 border-b border-gray-200 last:border-b-0">
                    <span className="font-semibold text-gray-600">{info.label}:</span>
                    {info.type === 'badge' ? (
                      <span className={`ml-2 px-2.5 py-0.5 rounded-full text-xs font-medium ${info.className}`}>
                        {info.value}
                      </span>
                    ) : (
                      <p className={`text-gray-800 whitespace-pre-line ml-1 inline ${info.className || ''}`}>{info.value || "N/A"}</p>
                    )}
                  </div>
                ))}
                {informacoesSolicitante.length > 0 && (
                    <div className="mt-4 pt-3 border-t">
                        <h4 className="text-lg font-semibold text-gray-700 border-b pb-1">Informações do Solicitante</h4>
                        {informacoesSolicitante.map((info, index) => (
                           <div key={`solic-${index}`} className="pb-2 border-b border-gray-200 last:border-b-0 mt-2">
                                <span className="font-semibold text-gray-600">{info.label}:</span>
                                <p className="text-gray-800 whitespace-pre-line ml-1 inline">{info.value || "N/A"}</p>
                           </div>
                        ))}
                    </div>
                )}
              </div>
              <div className="space-y-3">
                 <h4 className="text-lg font-semibold text-gray-700 mb-2 border-b pb-1">
                    {item.tipo_origem === 'ficha' ? 'Checklist da Ficha' : 'Checklist da Notificação'}
                </h4>
                {conteudoPrincipal.length > 0 ? (
                  conteudoPrincipal.map((content, index) => (
                    <div key={`content-${index}`} className="pb-2 border-b border-gray-200 last:border-b-0">
                      {content.label && <span className="font-semibold text-gray-600">{content.label}:</span>}
                      <p className={`whitespace-pre-line text-gray-800 ${content.label ? 'ml-1' : ''}`}>{content.value || "N/A"}</p>
                    </div>
                  ))
                ) : ( <p className="text-gray-500 italic">Nenhuma informação detalhada disponível.</p> )}
              </div>
            </div>
            <div className="mt-8 pt-4 border-t">
              <button onClick={() => setShowModal(false)}
                className="w-full bg-primary hover:bg-primary/90 text-white font-semibold py-2.5 rounded-lg transition shadow-md hover:shadow-lg">
                Fechar
              </button>
            </div>
          </div>
        );
        setShowModal(true);
      };
      
      const Pagination = ({ currentPage, totalPages, onPageChange }) => {
        if (totalPages <= 1) return null;
        const pageNumbers = [];
        const maxVisiblePages = 5; 
        const pageBuffer = 2; 

        if (totalPages <= maxVisiblePages) {
          for (let i = 1; i <= totalPages; i++) pageNumbers.push(i);
        } else {
          pageNumbers.push(1);
          if (currentPage > pageBuffer + 1) pageNumbers.push('...');
          let startPage = Math.max(2, currentPage - (pageBuffer -1));
          let endPage = Math.min(totalPages - 1, currentPage + (pageBuffer-1));
          if(currentPage <= pageBuffer) endPage = Math.min(totalPages -1, maxVisiblePages-1);
          if(currentPage > totalPages - pageBuffer) startPage = Math.max(2, totalPages - (maxVisiblePages-2));
          for (let i = startPage; i <= endPage; i++) pageNumbers.push(i);
          if (currentPage < totalPages - pageBuffer) pageNumbers.push('...');
          pageNumbers.push(totalPages);
        }
        const uniquePageNumbers = [...new Set(pageNumbers)];

        return (
          <div className="pagination">
            <button onClick={() => onPageChange(currentPage - 1)} disabled={currentPage === 1} className={`pagination-item ${currentPage === 1 ? 'disabled' : ''}`}>
              <i className="fas fa-chevron-left"></i>
            </button>
            {uniquePageNumbers.map((page, index) => (
              page === '...' ? 
                <span key={`ellipsis-${index}`} className="pagination-item disabled px-1">...</span> :
                <button key={`page-${page}`} onClick={() => onPageChange(page)} className={`pagination-item ${currentPage === page ? 'active' : ''}`}>
                  {page}
                </button>
            ))}
            <button onClick={() => onPageChange(currentPage + 1)} disabled={currentPage === totalPages} className={`pagination-item ${currentPage === totalPages ? 'disabled' : ''}`}>
              <i className="fas fa-chevron-right"></i>
            </button>
          </div>
        );
      };

      const FilterBar = () => (
        <div className="bg-white p-3 border-b border-gray-200 flex gap-3 items-center filter-bar">
            <div className="flex-grow">
                <label htmlFor="secretaria" className="text-sm font-medium text-gray-700 mr-2">Filtrar por Secretaria:</label>
                <select 
                    id="secretaria"
                    name="secretaria"
                    value={filters.secretaria}
                    onChange={handleFilterChange}
                    className="rounded-md border-gray-300 shadow-sm focus:border-indigo-300 focus:ring focus:ring-indigo-200 focus:ring-opacity-50 text-sm"
                >
                    <option value="all">Todas as Secretarias</option>
                    {window.listaSecretarias.map(s => <option key={s} value={s}>{s}</option>)}
                </select>
            </div>
            <button 
                onClick={toggleSortOrder}
                className="flex items-center gap-2 px-3 py-1.5 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition"
                title={filters.ordem === 'DESC' ? 'Mudar para mais antigos primeiro' : 'Mudar para mais novos primeiro'}
            >
                <span>{filters.ordem === 'DESC' ? 'Mais Recentes' : 'Mais Antigos'}</span>
                <i className={`fas ${filters.ordem === 'DESC' ? 'fa-arrow-down' : 'fa-arrow-up'}`}></i>
            </button>
        </div>
      );

      return (
        <div className="app-container mx-auto relative">
          <header className="bg-primary text-white px-6 py-5 flex justify-between items-center shadow-hard">
            <div className="flex items-center gap-4">
              <div className="relative cursor-pointer" onClick={() => window.location.href='../perfil.php'}>
                { window.profilePhoto && !photoError ? (
                  <img src={window.profilePhoto} alt="Perfil" className="rounded-full w-14 h-14 object-cover profile-ring" onError={() => setPhotoError(true)} />
                ) : (
                  <div className="rounded-full w-14 h-14 bg-primary/20 flex items-center justify-center profile-ring">
                    <i className="fas fa-user text-white text-2xl"></i>
                  </div>
                )}
              </div>
              <div>
                <h1 className="font-bold text-lg leading-tight">{window.userName}</h1>
                <p className="text-opacity-90 text-sm">Pedidos Mecânica</p>
              </div>
            </div>
            <div className="flex space-x-4">
              <button className="p-2 hover:bg-white/10 rounded-full transition-colors" onClick={() => window.location.href='../menu_mecanico.php'}>
                <i className="fas fa-home text-xl"></i>
              </button>
            </div>
          </header>

          <div className="flex border-b border-gray-200 px-2 sm:px-6 bg-white">
            {['pedidos', 'notificacoes', 'fichas', 'concluidos'].map(tab => (
              <button
                key={tab}
                className={`flex-1 sm:flex-none px-3 sm:px-4 py-3 font-medium capitalize text-sm sm:text-base ${activeTab === tab ? 'text-primary border-b-2 border-primary' : 'text-gray-500 hover:text-gray-700'}`}
                onClick={() => setActiveTab(tab)}>
                {tab}
              </button>
            ))}
          </div>

          <FilterBar />

          <main className="p-4 pb-20 overflow-y-auto">
            {loading && !showModal && !showEtapaModal && (
              <div className="fixed top-4 right-4 bg-blue-600 text-white px-4 py-2 rounded-lg shadow-lg z-50 flex items-center gap-2 animate-pulse">
                <i className="fas fa-spinner fa-spin"></i>
                <span>Carregando...</span>
              </div>
            )}
            
            {activeTab === 'pedidos' && (
              <div className="space-y-4">
                {pedidosAtivosState.length === 0 ? (
                  <div className="flex flex-col items-center justify-center h-64 text-gray-500">
                    <i className="fas fa-tools text-4xl mb-4"></i>
                    <p>Nenhum pedido ativo no momento.</p>
                  </div>
                ) : (
                  pedidosAtivosState.map((pedido) => (
                    <div key={`pedido-${pedido.id}`} className="pedido-card bg-white rounded-xl shadow-soft p-4 border border-gray-100 hover:shadow-md">
                      <div className="flex justify-between items-start">
                        <div>
                          <h3 className="font-bold text-gray-800 text-lg">
                            {pedido.tipo_origem === 'ficha' ? `Ficha #${pedido.ficha_id}` :
                             pedido.tipo_origem === 'notificacao' ? `Notificação #${pedido.notificacao_id}` :
                             `Serviço #${pedido.id}`}
                          </h3>
                          <p className="text-gray-600 text-sm">
                            {pedido.tipo_origem === 'ficha' ? 
                                `${pedido.ficha_nome_veiculo || 'Veículo não especificado'} • ${pedido.ficha_secretaria || 'N/A Sec.'}` :
                             pedido.tipo_origem === 'notificacao' ?
                                `${pedido.notificacao_prefixo ? `Prefixo: ${pedido.notificacao_prefixo}`: 'N/A'} • ${pedido.notificacao_secretaria || 'N/A Sec.'}` :
                                `${pedido.nome_veiculo || 'Veículo não especificado'} • ${pedido.secretaria || 'N/A Sec.'}`
                            }
                          </p>
                        </div>
                        <span className={`px-2.5 py-1 rounded-full text-xs font-semibold
                          ${pedido.status === 'em_andamento' ? 'badge-em-andamento' : 
                           (pedido.status === 'aceito' ? 'badge-aceito' : 'badge-pedido')}`}>
                          {pedido.status === 'em_andamento' ? 'Em Andamento' : 
                           (pedido.status === 'aceito' ? 'Aceito' : 'Status Desconhecido')}
                        </span>
                      </div>
                      <p className="my-2.5 text-gray-700 line-clamp-2">
                        {pedido.tipo_origem === 'notificacao' ? (pedido.notificacao_mensagem || 'Sem descrição') :
                         pedido.tipo_origem === 'ficha' ? (
                            [pedido.suspensao, pedido.motor, pedido.freios, pedido.direcao, pedido.sistema_eletrico]
                                .filter(Boolean).map(val => val.length > 20 ? val.substring(0,17)+'...' : val).join(' | ') || 'Verificar detalhes da ficha.'
                         ) : (pedido.observacoes || pedido.descricao_servico || 'Sem descrição adicional.')}
                      </p>

                      {pedido.prioridade && pedido.prioridade !== '' && (
                        <div className="mt-2 pt-2 border-t border-gray-100">
                            <p className="text-xs text-purple-700 font-semibold flex items-center gap-1.5">
                                <i className={`fas ${getEtapaIconClass(pedido.prioridade)} fa-fw`}></i>
                                Etapa: {getEtapaLabel(pedido.prioridade)}
                            </p>
                        </div>
                      )}
                      
                      {pedido.status === 'em_andamento' && (
                        <div className="timer-container">
                          <div className="timer-display">
                            {formatarTempo(timers[pedido.id] ? timers[pedido.id].tempoDecorrido : 0)}
                          </div>
                          <button onClick={() => handleFinalizarServico(pedido.id)}
                            className="w-full bg-red-500 hover:bg-red-600 text-white font-semibold py-2 rounded-lg transition flex items-center justify-center gap-2 shadow hover:shadow-md">
                            <i className="fas fa-stop-circle"></i> Finalizar Serviço
                          </button>
                        </div>
                      )}

                      <div className="flex justify-between items-center mt-3 pt-2 border-t border-gray-100">
                        <span className="text-xs text-gray-500">
                           {/* CORREÇÃO: Usando o campo de data correto para cada tipo */}
                          {formatDate(
                              pedido.ficha_data || pedido.notificacao_data,
                              pedido.tipo_origem === 'ficha' ? pedido.ficha_hora : null
                          )}
                        </span>
                        <div className="flex flex-wrap gap-2 ml-auto action-buttons">
                          <button onClick={() => handleVerDetalhes(pedido)} className="flex items-center gap-1.5 bg-blue-500 hover:bg-blue-600 text-white px-3 py-1.5 rounded-md text-xs font-medium shadow hover:shadow-md transition">
                              <i className="fas fa-eye"></i> Detalhes
                          </button>
                          {(pedido.status === 'aceito' || pedido.status === 'em_andamento') && (
                            <button
                              onClick={() => handleOpenEtapaModal(pedido)}
                              title={pedido.prioridade ? `Alterar Etapa Atual: ${getEtapaLabel(pedido.prioridade)}` : 'Definir Etapa do Serviço'}
                              className="flex items-center gap-1.5 bg-purple-500 hover:bg-purple-600 text-white px-3 py-1.5 rounded-md text-xs font-medium shadow hover:shadow-md transition"
                            >
                              <i className="fas fa-tasks"></i> 
                              {pedido.prioridade && pedido.prioridade !== '' ? 'Etapa' : 'Definir Etapa'}
                            </button>
                          )}
                          {pedido.status === 'aceito' && (
                              <button onClick={() => handleIniciarServico(pedido.id)} className="flex items-center gap-1.5 bg-green-500 hover:bg-green-600 text-white px-3 py-1.5 rounded-md text-xs font-medium shadow hover:shadow-md transition">
                                  <i className="fas fa-play"></i> Iniciar
                              </button>
                          )}
                        </div>
                      </div>
                    </div>
                  ))
                )}
              </div>
            )}
            {activeTab === 'notificacoes' && (
              <div className="space-y-4">
                {notificacoesDisponiveisState.length === 0 ? (
                  <div className="flex flex-col items-center justify-center h-64 text-gray-500">
                    <i className="fas fa-bell-slash text-4xl mb-4"></i>
                    <p>Nenhuma notificação disponível.</p>
                  </div>
                ) : (
                  notificacoesDisponiveisState.map((notificacao) => (
                    <div key={`notif-${notificacao.id}`} className="pedido-card bg-white rounded-xl shadow-soft p-4 border border-gray-100 hover:shadow-md">
                      <div className="flex justify-between items-start">
                        <div>
                          <h3 className="font-bold text-gray-800 text-lg">Notificação #{notificacao.id}</h3>
                          <p className="text-gray-600 text-sm">
                            {notificacao.prefixo ? `Prefixo: ${notificacao.prefixo}` : (notificacao.veiculo || 'Veículo N/A')}
                            {notificacao.secretaria ? ` • ${notificacao.secretaria}` : ' • Sec. N/A'}
                          </p>
                        </div>
                        <span className="px-2.5 py-1 rounded-full text-xs font-semibold badge-notificacao">Notificação</span>
                      </div>
                      <p className="my-2.5 text-gray-700 line-clamp-2">{notificacao.mensagem || 'Sem mensagem.'}</p>
                      <div className="flex justify-between items-center mt-3 pt-2 border-t border-gray-100">
                        <span className="text-xs text-gray-500">{formatDate(notificacao.data)}</span>
                        <div className="flex flex-wrap gap-2 ml-auto action-buttons">
                          <button onClick={() => handleVerDetalhes(notificacao)} className="flex items-center gap-1.5 bg-blue-500 hover:bg-blue-600 text-white px-3 py-1.5 rounded-md text-xs font-medium shadow hover:shadow-md transition">
                            <i className="fas fa-eye"></i> Detalhes
                          </button>
                          <button onClick={() => handlePrimeiraAceitacao(notificacao.id)} className="flex items-center gap-1.5 bg-green-500 hover:bg-green-600 text-white px-3 py-1.5 rounded-md text-xs font-medium shadow hover:shadow-md transition">
                            <i className="fas fa-check"></i> Aceitar
                          </button>
                           <button onClick={() => handleDismiss(notificacao.id, 'notificacao')} className="flex items-center gap-1.5 bg-gray-400 hover:bg-gray-500 text-white px-3 py-1.5 rounded-md text-xs font-medium shadow hover:shadow-md transition" title="Dispensar Notificação">
                            <i className="fas fa-trash-alt"></i> Dispensar
                          </button>
                        </div>
                      </div>
                    </div>
                  ))
                )}
              </div>
            )}
            {activeTab === 'fichas' && (
              <div className="space-y-4">
                {fichasDisponiveisState.length === 0 ? (
                  <div className="flex flex-col items-center justify-center h-64 text-gray-500">
                    <i className="fas fa-file-alt text-4xl mb-4"></i>
                    <p>Nenhuma ficha disponível.</p>
                  </div>
                ) : (
                  fichasDisponiveisState.map((ficha) => (
                    <div key={`ficha-${ficha.id}`} className="pedido-card bg-white rounded-xl shadow-soft p-4 border border-gray-100 hover:shadow-md">
                      <div className="flex justify-between items-start">
                        <div>
                          <h3 className="font-bold text-gray-800 text-lg">Ficha #{ficha.id}</h3>
                          <p className="text-gray-600 text-sm">
                            {ficha.nome_veiculo || 'Veículo N/A'}
                            {ficha.secretaria ? ` • ${ficha.secretaria}` : ' • Sec. N/A'}
                          </p>
                        </div>
                        <span className="px-2.5 py-1 rounded-full text-xs font-semibold badge-ficha">Ficha</span>
                      </div>
                      <p className="my-2.5 text-gray-700 line-clamp-2">
                        { [ficha.suspensao, ficha.motor, ficha.freios, ficha.direcao, ficha.sistema_eletrico, ficha.observacoes]
                            .filter(Boolean).map(val => val.length > 20 ? val.substring(0,17)+'...' : val).join(' | ') || 'Verificar detalhes.'
                        }
                      </p>
                      <div className="flex justify-between items-center mt-3 pt-2 border-t border-gray-100">
                        <span className="text-xs text-gray-500">{formatDate(ficha.data, ficha.hora)}</span>
                        <div className="flex flex-wrap gap-2 ml-auto action-buttons">
                          <button onClick={() => handleVerDetalhes(ficha)} className="flex items-center gap-1.5 bg-blue-500 hover:bg-blue-600 text-white px-3 py-1.5 rounded-md text-xs font-medium shadow hover:shadow-md transition">
                            <i className="fas fa-eye"></i> Detalhes
                          </button>
                          <button onClick={() => handlePrimeiraAceitacaoFicha(ficha.id)} className="flex items-center gap-1.5 bg-green-500 hover:bg-green-600 text-white px-3 py-1.5 rounded-md text-xs font-medium shadow hover:shadow-md transition">
                            <i className="fas fa-check"></i> Aceitar
                          </button>
                           <button onClick={() => handleDismiss(ficha.id, 'ficha')} className="flex items-center gap-1.5 bg-gray-400 hover:bg-gray-500 text-white px-3 py-1.5 rounded-md text-xs font-medium shadow hover:shadow-md transition" title="Dispensar Ficha">
                            <i className="fas fa-trash-alt"></i> Dispensar
                          </button>
                        </div>
                      </div>
                    </div>
                  ))
                )}
              </div>
            )}
            {activeTab === 'concluidos' && (
              <div>
                <div className="space-y-4 mb-8">
                  {servicosFinalizadosState.length === 0 ? (
                    <div className="flex flex-col items-center justify-center h-64 text-gray-700">
                      <i className="fas fa-check-circle text-4xl mb-4"></i>
                      <p>Nenhum serviço finalizado.</p>
                    </div>
                  ) : (
                    servicosFinalizadosState.map((servico) => (
                      <div key={`concluido-${servico.id}`} className="pedido-card bg-white rounded-xl shadow-soft p-4 border border-gray-100 hover:shadow-md">
                        <div className="flex justify-between items-start">
                          <div>
                            <h3 className="font-bold text-gray-800 text-lg">Serviço #{servico.id}</h3>
                            <p className="text-gray-600 text-sm">
                              {servico.tipo_origem === 'ficha' ? 
                                  `${servico.ficha_nome_veiculo || 'Veículo N/A'} • ${servico.ficha_secretaria || 'Sec N/A'}` :
                               servico.tipo_origem === 'notificacao' ?
                                  `${servico.notificacao_prefixo || 'N/A'} • ${servico.notificacao_secretaria || 'Sec N/A'}` :
                                  `${servico.nome_veiculo || 'Veículo N/A'} • ${servico.secretaria || 'Sec N/A'}`
                              }
                            </p>
                          </div>
                          <span className="px-2.5 py-1 rounded-full text-xs font-semibold badge-concluido">Concluído</span>
                        </div>
                        <p className="my-2.5 text-gray-700 line-clamp-2">
                          {servico.tipo_origem === 'notificacao' ? (servico.notificacao_mensagem || "Ver detalhes") :
                           servico.tipo_origem === 'ficha' ? (
                              [servico.suspensao, servico.motor, servico.freios, servico.direcao]
                                .filter(Boolean).map(val => val.length > 20 ? val.substring(0,17)+'...' : val).join(' | ') || 'Ver detalhes da ficha.'
                           ) : (servico.observacoes || servico.descricao_servico || "Ver detalhes")}
                        </p>
                        {servico.prioridade && servico.prioridade !== '' && (
                            <p className="text-xs text-purple-700 font-semibold mt-1">
                                Etapa Final: {getEtapaLabel(servico.prioridade)}
                            </p>
                        )}
                        <div className="flex justify-between items-center mt-3 pt-2 border-t border-gray-100">
                          <span className="text-xs text-gray-500">{formatDate(servico.data_conclusao)}</span>
                           { servico.tempo_real != null && <span className="text-xs text-gray-500">Tempo: {formatarTempo(servico.tempo_real)}</span>}
                          <div className="flex space-x-2 ml-auto">
                            <button onClick={() => handleVerDetalhes(servico)} className="flex items-center gap-1.5 bg-blue-500 hover:bg-blue-600 text-white px-3 py-1.5 rounded-md text-xs font-medium shadow hover:shadow-md transition">
                                <i className="fas fa-eye"></i> Detalhes
                            </button>
                          </div>
                        </div>
                      </div>
                    ))
                  )}
                </div>
                {totalPaginas > 1 && activeTab === 'concluidos' && (
                  <Pagination currentPage={paginaAtual} totalPages={totalPaginas} onPageChange={handlePageChange} />
                )}
              </div>
            )}
          </main>

          <nav className="fixed bottom-0 w-full bg-white border-t border-gray-300 flex justify-around items-center h-16 shadow-hard ml-[-8px]">
            {[
              {icon: 'fa-user', label: 'Perfil', tab: 'profile', action: () => window.location.href = '../perfil.php'},
              {icon: 'fa-tools', label: 'Pedidos', tab: 'pedidos', action: () => setActiveTab('pedidos')},
              {icon: 'fa-sign-out-alt', label: 'Sair', tab: 'logout', action: () => window.location.href = 'logout.php'}
            ].map((item) => (
              <button
                key={item.tab}
                className={`flex flex-col items-center justify-center w-1/3 h-full p-1 transition-all 
                            ${item.tab === 'logout' ? 'text-red-500 hover:text-red-700' : 
                            (activeTab === item.tab && item.tab !== 'profile' ? 'text-primary' : 'text-gray-500 hover:text-primary')}`}
                onClick={item.action}>
                <i className={`fas ${item.icon} text-xl`}></i>
                <span className="text-xs font-medium mt-0.5">{item.label}</span>
              </button>
            ))}
          </nav>

          {showModal && (
            <div className="fixed inset-0 bg-black bg-opacity-60 flex items-center justify-center z-50 p-4 transition-opacity duration-300 ease-in-out opacity-100" onClick={() => setShowModal(false)}>
              <div className="bg-white rounded-xl w-full max-w-3xl shadow-xl transform transition-all duration-300 ease-in-out scale-100" onClick={e => e.stopPropagation()}> 
                {modalContent}
              </div>
            </div>
          )}

          {showEtapaModal && selectedServicoParaEtapa && (
            <div 
              className="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-[60] p-4 transition-opacity duration-300 ease-in-out opacity-100"
              onClick={() => { if(!currentEtapaLoading) setShowEtapaModal(false); }}
            >
              <div 
                className="bg-white rounded-xl w-full max-w-lg shadow-2xl transform transition-all duration-300 ease-in-out scale-100 p-6 md:p-8 max-h-[90vh] overflow-y-auto" 
                onClick={e => e.stopPropagation()}
              >
                <div className="flex justify-between items-center mb-4">
                  <h3 className="text-xl sm:text-2xl font-bold text-gray-800">
                      Definir Etapa do Serviço
                  </h3>
                  <button onClick={() => { if(!currentEtapaLoading) setShowEtapaModal(false); }} disabled={currentEtapaLoading} className="text-gray-400 hover:text-gray-600 transition-colors">
                      <i className="fas fa-times fa-lg"></i>
                  </button>
                </div>
                
                <div className="mb-4 p-3 bg-indigo-50 rounded-lg border border-indigo-200">
                  <p className="text-sm text-gray-700">
                    <span className="font-semibold">Serviço ID:</span> {selectedServicoParaEtapa.id}
                  </p>
                  <p className="text-sm text-gray-700">
                    <span className="font-semibold">Veículo:</span> {
                      selectedServicoParaEtapa.tipo_origem === 'ficha' ? 
                      `${selectedServicoParaEtapa.ficha_nome_veiculo || 'N/A'}` :
                      (selectedServicoParaEtapa.notificacao_prefixo || selectedServicoParaEtapa.nome_veiculo || 'N/A')
                    }
                  </p>
                  {selectedServicoParaEtapa.prioridade && selectedServicoParaEtapa.prioridade !== '' && (
                       <p className="text-sm text-indigo-700 mt-1">
                          <span className="font-semibold">Etapa Atual:</span> {getEtapaLabel(selectedServicoParaEtapa.prioridade)}
                       </p>
                  )}
                </div>

                {currentEtapaLoading && (
                  <div className="absolute inset-0 bg-white/70 flex flex-col items-center justify-center rounded-xl z-10">
                      <i className="fas fa-spinner fa-spin text-primary text-3xl"></i>
                      <p className="mt-2 text-primary font-medium">Salvando etapa...</p>
                  </div>
                )}

                <div className={`grid grid-cols-1 sm:grid-cols-2 gap-3 ${currentEtapaLoading ? 'opacity-50 pointer-events-none' : ''}`}>
                  {etapaOptions.map(option => (
                    <button
                      key={option.value}
                      disabled={currentEtapaLoading}
                      onClick={() => handleSalvarEtapa(selectedServicoParaEtapa.id, option.value)}
                      title={`Definir etapa como: ${option.label}`}
                      className={`w-full flex items-center gap-3 text-left p-3 rounded-lg border-2 transition-all duration-150 ease-in-out focus:outline-none focus:ring-2 focus:ring-opacity-50
                                  ${selectedServicoParaEtapa.prioridade === option.value 
                                    ? 'bg-primary border-primary text-white shadow-md ring-primary/60' 
                                    : 'bg-white border-gray-300 hover:border-primary hover:bg-primary/5 text-gray-700 hover:text-primary focus:ring-primary'
                                  }
                                  ${option.value === '' ? (selectedServicoParaEtapa.prioridade === option.value ? 'bg-red-600 border-red-600 text-white' : 'border-red-400 hover:border-red-500 hover:bg-red-500/10 hover:text-red-600 focus:ring-red-500') : ''}
                                  `}
                    >
                      <i className={`${option.icon} fa-fw text-base ${selectedServicoParaEtapa.prioridade === option.value ? 'text-white' : (option.value === '' ? (selectedServicoParaEtapa.prioridade === option.value ? 'text-white':'text-red-500') : 'text-primary/80')}`}></i>
                      <span className="font-medium text-sm">{option.label}</span>
                    </button>
                  ))}
                </div>
                <button 
                  onClick={() => { if(!currentEtapaLoading) setShowEtapaModal(false); }} 
                  disabled={currentEtapaLoading}
                  className={`mt-6 w-full bg-gray-100 hover:bg-gray-200 text-gray-700 font-semibold py-2.5 rounded-lg transition ${currentEtapaLoading ? 'opacity-50' : ''}`}
                >
                  Cancelar
                </button>
              </div>
            </div>
          )}
        </div>
      );
    }

    const root = ReactDOM.createRoot(document.getElementById('root'));
    root.render(<App />);
  </script> 
</body> 
</html>
