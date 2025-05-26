<?php
require 'db_connection.php'; // Inclui a conexão com o banco de dados

session_start();

// Verifica se o usuário está logado
if (empty($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Consultas para buscar dados do banco de dados
try {
    // Fichas de Defeito (consideradas aceitas por padrão)
    // Query para mostrar apenas as fichas que ainda não foram acopladas a um serviço
    $queryFichas = "SELECT f.*, 'ficha' AS tipo_origem 
                    FROM ficha_defeito f 
                    WHERE f.status = 'pendente' 
                    AND NOT EXISTS (
                        SELECT 1 
                        FROM servicos_mecanica sm 
                        WHERE sm.ficha_id = f.id 
                        AND sm.status IN ('aceito', 'em_andamento')
                    )";
    $stmtFichas = $conn->query($queryFichas);
    $fichas_aceitas = $stmtFichas->fetchAll(PDO::FETCH_ASSOC);

    // Pedidos Aceitos dos serviços (servicos_mecanica)
    $queryPedidos = "
        SELECT 
            sm.*,
            CASE 
                WHEN sm.ficha_id IS NOT NULL THEN 'ficha'
                WHEN sm.notificacao_id IS NOT NULL THEN 'notificacao'
                ELSE 'pedido'
            END AS tipo_origem,
            -- Dados da Notificação
            n.mensagem as notificacao_mensagem,
            n.data as notificacao_data,
            n.status as notificacao_status,
            n.prefixo as notificacao_prefixo,
            n.secretaria as notificacao_secretaria,
            -- Dados da Ficha
            f.data as ficha_data,
            f.hora as ficha_hora,
            f.nome as ficha_nome,
            f.nome_veiculo as ficha_nome_veiculo,
            f.secretaria as ficha_secretaria,
            f.km_inicial,
            f.suspensao, f.obs_suspensao,
            f.motor, f.obs_motor,
            f.freios, f.obs_freios,
            f.direcao, f.obs_direcao,
            f.sistema_eletrico, f.obs_sistema_eletrico,
            f.carroceria, f.obs_carroceria,
            f.embreagem, f.obs_embreagem,
            f.rodas, f.obs_rodas,
            f.transmissao_9500, f.obs_transmissao_9500,
            f.caixa_mudancas, f.obs_caixa_mudancas,
            f.alimentacao, f.obs_alimentacao,
            f.arrefecimento, f.obs_arrefecimento
        FROM servicos_mecanica sm
        LEFT JOIN notificacoes n ON sm.notificacao_id = n.id
        LEFT JOIN ficha_defeito f ON sm.ficha_id = f.id
        WHERE sm.mecanico_id = :mecanico_id 
        AND sm.status IN ('aceito', 'em_andamento')";
    $stmtPedidos = $conn->prepare($queryPedidos);
    $stmtPedidos->bindParam(':mecanico_id', $_SESSION['user_id']);
    $stmtPedidos->execute();
    $pedidos_ativos = $stmtPedidos->fetchAll(PDO::FETCH_ASSOC);

    // Notificações com status aceito
    $queryNotificacoes = "SELECT n.*, 'notificacao' AS tipo_origem
                          FROM notificacoes n
                          WHERE status = 'pendente'";
    $stmtNotificacoes = $conn->query($queryNotificacoes);
    $notificacoes_aceitas = $stmtNotificacoes->fetchAll(PDO::FETCH_ASSOC);

    // Serviços Finalizados
    $queryFinalizados = "
        SELECT 
            s.*,
            CASE 
                WHEN s.ficha_id IS NOT NULL THEN 'ficha'
                WHEN s.notificacao_id IS NOT NULL THEN 'notificacao'
                ELSE 'pedido'
            END AS tipo_origem,
            -- Dados da Notificação
            n.mensagem as notificacao_mensagem,
            n.data as notificacao_data,
            n.status as notificacao_status,
            n.prefixo as notificacao_prefixo,
            n.secretaria as notificacao_secretaria,
            -- Dados da Ficha
            f.data as ficha_data,
            f.hora as ficha_hora,
            f.nome as ficha_nome,
            f.nome_veiculo as ficha_nome_veiculo,
            f.secretaria as ficha_secretaria,
            f.suspensao, f.obs_suspensao,
            f.motor, f.obs_motor,
            f.freios, f.obs_freios,
            f.direcao, f.obs_direcao,
            f.sistema_eletrico, f.obs_sistema_eletrico,
            f.carroceria, f.obs_carroceria,
            f.embreagem, f.obs_embreagem,
            f.rodas, f.obs_rodas,
            f.transmissao_9500, f.obs_transmissao_9500,
            f.caixa_mudancas, f.obs_caixa_mudancas,
            f.alimentacao, f.obs_alimentacao,
            f.arrefecimento, f.obs_arrefecimento
        FROM servicos_mecanica s
        LEFT JOIN notificacoes n ON s.notificacao_id = n.id
        LEFT JOIN ficha_defeito f ON s.ficha_id = f.id
        WHERE s.mecanico_id = :mecanico_id AND s.status = 'finalizado'";
    $stmtFinalizados = $conn->prepare($queryFinalizados);
    $stmtFinalizados->bindParam(':mecanico_id', $_SESSION['user_id']);
    $stmtFinalizados->execute();
    $servicos_finalizados = $stmtFinalizados->fetchAll(PDO::FETCH_ASSOC);

    // Dados do usuário
    $queryUser = "SELECT * FROM usuarios WHERE id = :id";
    $stmtUser = $conn->prepare($queryUser);
    $stmtUser->bindParam(':id', $_SESSION['user_id']);
    $stmtUser->execute();
    $user = $stmtUser->fetch(PDO::FETCH_ASSOC);

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

  <!-- Tailwind CSS -->
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

  <!-- SweetAlert2 CSS -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">

  <!-- React e Babel -->
  <script defer src="https://unpkg.com/react@18/umd/react.development.js"></script>
  <script defer src="https://unpkg.com/react-dom@18/umd/react-dom.development.js"></script>
  <script defer src="https://unpkg.com/babel-standalone@6/babel.min.js"></script>

  <!-- Ícones -->
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
      background: white;
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
    .badge-pendente {
      background-color: #F59E0B;
      color: white;
    }
    .badge-aceito {
      background-color: #10B981;
      color: white;
    }
    .badge-em-andamento {
      background-color: #3B82F6;
      color: white;
    }
    .badge-concluido {
      background-color: #4F46E5;
      color: white;
    }
    .badge-alta {
      background-color: #EF4444;
      color: white;
    }
    .badge-media {
      background-color: #F59E0B;
      color: white;
    }
    .badge-baixa {
      background-color: #10B981;
      color: white;
    }
    .badge-notificacao {
      background-color: #3B82F6;
      color: white;
    }
    .badge-ficha {
      background-color: #8B5CF6;
      color: white;
    }
    .badge-pedido {
      background-color: #4F46E5;
      color: white;
    }
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
    @media (max-width: 640px) {
      .action-buttons {
        justify-content: flex-end;
        margin-left: auto;
      }
      .bottom-nav {
        left: 0;
        right: 0;
        margin-left: 0 !important;
        width: 100%;
      }
      main {
        padding-bottom: 5rem;
      }
      .pedido-card {
        padding: 1rem;
      }
      .pedido-card h3 {
        font-size: 1rem;
      }
      .pedido-card p {
        font-size: 0.875rem;
      }
    }
  </style>
</head>
<body>
  <div id="getdify" data-getdify="pedidos-mecanica" style="display:none;"></div>

  <!-- Declare global variables on window so they are accessible inside React -->
  <script>
    window.profilePhoto = '<?= $profilePhoto ?>';
    window.userName = <?= json_encode($userName) ?>;
    window.pedidosAtivos = <?= json_encode($pedidos_ativos) ?>;
    window.notificacoesDisponiveis = <?= json_encode($notificacoes_aceitas) ?>;
    window.fichasDisponiveis = <?= json_encode($fichas_aceitas) ?>;
    window.servicosFinalizados = <?= json_encode($servicos_finalizados) ?>;
  </script>

  <div id="root"></div>

  <!-- SweetAlert2 JS -->
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <script type="text/babel">
    function App() {
      const [activeTab, setActiveTab] = React.useState('pedidos');
      const [photoError, setPhotoError] = React.useState(false);
      const [selectedPedido, setSelectedPedido] = React.useState(null);
      const [tempoTarefa, setTempoTarefa] = React.useState('30');
      const [showModal, setShowModal] = React.useState(false);
      const [modalContent, setModalContent] = React.useState(null);
      const [filtroOrigem, setFiltroOrigem] = React.useState('todos');
      const [tempoInicial, setTempoInicial] = React.useState(null);
      const [tempoDecorrido, setTempoDecorrido] = React.useState(0);
      const [timerInterval, setTimerInterval] = React.useState({});
      const [activeServiceId, setActiveServiceId] = React.useState(null);
      const [notificacoesDisponiveisState, setNotificacoesDisponiveis] = React.useState(window.notificacoesDisponiveis);
      const [fichasDisponiveisState, setFichasDisponiveis] = React.useState(window.fichasDisponiveis);
      const [pedidosAtivosState, setPedidosAtivos] = React.useState(window.pedidosAtivos);
      const [timers, setTimers] = React.useState({});

      React.useEffect(() => {
        // Verifica se há serviços em andamento ao carregar a página
        const servicosEmAndamento = pedidosAtivosState.filter(pedido => pedido.status === 'em_andamento');
        
        servicosEmAndamento.forEach(servico => {
          if (servico.inicio_servico) {
            iniciarTimerParaServico(servico.id, new Date(servico.inicio_servico));
          }
        });

        return () => {
          // Limpa todos os timers ao desmontar o componente
          Object.keys(timerInterval).forEach(key => {
            if (timerInterval[key]) clearInterval(timerInterval[key]);
          });
        };
      }, []);

      const formatDate = (dateString) => {
        if (!dateString) return 'Sem informações';
        const options = { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' };
        return new Date(dateString).toLocaleDateString('pt-BR', options);
      };

      const iniciarTimerParaServico = (servicoId, startTime) => {
        // Garantir que startTime seja um objeto Date válido
        const inicio = startTime instanceof Date ? startTime : new Date();
        
        // Calcular tempo já decorrido (em segundos) - garantir que seja um valor positivo
        const agora = new Date();
        let tempoDesdeCriacao = Math.max(0, Math.floor((agora - inicio) / 1000));
        
        // Atualiza o estado com o tempo inicial para este serviço
        setTimers(prev => {
          const newTimers = {...prev};
          if (!newTimers[servicoId]) {
            newTimers[servicoId] = {};
          }
          newTimers[servicoId].tempoDecorrido = tempoDesdeCriacao;
          newTimers[servicoId].startTime = inicio;
          return newTimers;
        });
        
        // Inicia o timer para este serviço
        const intervalId = setInterval(() => {
          setTimers(prev => {
            const newTimers = {...prev};
            if (!newTimers[servicoId]) {
              newTimers[servicoId] = { tempoDecorrido: tempoDesdeCriacao + 1 };
            } else {
              const tempoAtual = newTimers[servicoId].tempoDecorrido || 0;
              newTimers[servicoId].tempoDecorrido = tempoAtual + 1;
            }
            return newTimers;
          });
        }, 1000);
        
        // Salva o ID do intervalo
        setTimerInterval(prev => {
          const newIntervals = {...prev};
          newIntervals[servicoId] = intervalId;
          return newIntervals;
        });
        
        return intervalId;
      };

      // Função para parar o timer de um serviço específico
      const pararTimer = (servicoId) => {
        if (timerInterval && timerInterval[servicoId]) {
          clearInterval(timerInterval[servicoId]);
          setTimerInterval(prev => {
            const newTimers = {...prev};
            delete newTimers[servicoId];
            return newTimers;
          });
        }
      };

      // Função para aceitar o serviço (apenas atualiza o status para 'aceito')
      const handleAceitarServico = async (tipo, id) => {
        try {
          const response = await fetch('aceitar_servico.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
            },
            body: JSON.stringify({ tipo, id }),
          });

          if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.message || `HTTP error! status: ${response.status}`);
          }

          const data = await response.json();
          
          if (!data.success) {
            throw new Error(data.message || 'Falha ao aceitar o serviço');
          }

          // Atualiza o estado local
          if (tipo === 'ficha') {
            setFichasDisponiveis(prev => prev.filter(f => f.id !== id));
          } else if (tipo === 'notificacao') {
            setNotificacoesDisponiveis(prev => prev.filter(n => n.id !== id));
          }

          // Recarrega os pedidos ativos
          await fetchPedidosAtivos();
          
          Swal.fire({
            title: 'Sucesso!',
            text: 'Serviço aceito com sucesso! Agora você pode iniciá-lo quando quiser.',
            icon: 'success',
            confirmButtonText: 'OK'
          });
          
        } catch (error) {
          console.error('Erro:', error);
          Swal.fire({
            title: 'Erro!',
            text: error.message || 'Erro ao aceitar serviço',
            icon: 'error',
            confirmButtonText: 'OK'
          });
        }
      };

      // Função para iniciar um serviço já aceito
      const handleIniciarServico = async (id) => {
        try {
          const response = await fetch('iniciar_servico.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
            },
            body: JSON.stringify({
              id: id
            }),
          });

          if (!response.ok) {
            const errorData = await response.json();
            throw new Error(errorData.message || `Erro HTTP! Status: ${response.status}`);
          }

          const data = await response.json();
          
          if (!data.success) {
            throw new Error(data.message || 'Erro ao iniciar serviço');
          }

          // Inicia o timer para este serviço - usando a data atual para evitar valores negativos
          iniciarTimerParaServico(id, new Date());

          // Recarrega pedidos ativos
          await fetchPedidosAtivos();

          Swal.fire({
            title: 'Sucesso!',
            text: 'Serviço iniciado com sucesso',
            icon: 'success',
            confirmButtonText: 'OK'
          });

        } catch (error) {
          console.error('Erro ao iniciar serviço:', error);
          Swal.fire({
            title: 'Erro!',
            text: error.message || 'Erro ao iniciar serviço',
            icon: 'error',
            confirmButtonText: 'OK'
          });
        }
      };

      const handleTempoTarefa = (pedidoId, tempo) => {
        fetch('atualizar_tempo.php', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
          },
          body: JSON.stringify({ pedido_id: pedidoId, tempo: tempo }),
        })
        .then(response => response.json())
        .then(data => {
          console.log('Tempo atualizado:', data);
        })
        .catch((error) => {
          console.error('Erro:', error);
        });
      };

      const fetchPedidosAtivos = async () => {
        try {
          const response = await fetch('get_pedidos_ativos.php');
          const data = await response.json();
          if (data.success) {
            setPedidosAtivos(data.pedidos);
          }
        } catch (error) {
          console.error('Erro ao buscar pedidos:', error);
        }
      };

      const handleFinalizarServico = async (pedidoId) => {
        try {
          // Obtém o tempo decorrido do timer
          let tempoTotal = 0;
          if (timers[pedidoId] && typeof timers[pedidoId].tempoDecorrido !== 'undefined') {
            tempoTotal = timers[pedidoId].tempoDecorrido;
          }
          
          // Para o timer
          pararTimer(pedidoId);
          
          // Chama a API para finalizar o serviço
          const response = await fetch('finalizar_servico.php', {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
            },
            body: JSON.stringify({
              pedido_id: pedidoId,
              finish_time: new Date().toISOString(),
              total_time: tempoTotal
            }),
          });
          
          const data = await response.json();
          if (!data.success) {
            throw new Error(data.message || 'Falha ao finalizar serviço');
          }
          
          // Remove o timer do estado
          setTimers(prev => {
            const newTimers = {...prev};
            delete newTimers[pedidoId];
            return newTimers;
          });
          
          // Recarregar a página para atualizar todos os dados
          window.location.reload();
          
          Swal.fire({
            title: 'Sucesso!',
            text: 'Serviço finalizado com sucesso!',
            icon: 'success',
            confirmButtonText: 'OK'
          });
        } catch (error) {
          console.error('Erro:', error);
          Swal.fire({
            title: 'Erro!',
            text: error.message || "Erro inesperado ao finalizar o serviço",
            icon: 'error',
            confirmButtonText: 'OK'
          });
        }
      };

      const formatarTempo = (segundos) => {
        if (typeof segundos !== 'number') return "00:00:00";
        
        const horas = Math.floor(segundos / 3600);
        const minutos = Math.floor((segundos % 3600) / 60);
        const segs = segundos % 60;
        
        return [
          horas.toString().padStart(2, '0'),
          minutos.toString().padStart(2, '0'),
          segs.toString().padStart(2, '0')
        ].join(':');
      };

      // Função para ver os detalhes do pedido/serviço
      const handleVerDetalhes = (item) => {
        setSelectedPedido(item);
        let conteudoPrincipal = [];
        let informacoesBasicas = [];

        // Exibe o tipo (Notificação, Ficha ou Pedido)
        informacoesBasicas.push({
          type: 'badge',
          label: 'Tipo',
          value: item.tipo_origem === 'notificacao' ? 'Notificação' :
                 (item.tipo_origem === 'ficha' ? 'Ficha' : 'Pedido'),
          className: item.tipo_origem === 'notificacao' ? 'badge-notificacao' :
                     (item.tipo_origem === 'ficha' ? 'badge-ficha' : 'badge-pedido')
        });

        // Status
        informacoesBasicas.push({
          type: 'badge',
          label: 'Status',
          value: item.status === 'aceito' ? 'Aceito' :
                (item.status === 'em_andamento' ? 'Em andamento' :
                (item.status === 'finalizado' ? 'Finalizado' : 'Pendente')),
          className: item.status === 'aceito' ? 'badge-aceito' :
                    (item.status === 'em_andamento' ? 'badge-em-andamento' :
                    (item.status === 'finalizado' ? 'badge-concluido' : 'badge-pendente'))
        });

        // Informações do veículo
        if (item.tipo_origem === 'notificacao') {
          informacoesBasicas.push({
            type: 'text',
            label: 'Veículo',
            value: `Prefixo: ${item.notificacao_prefixo || item.prefixo || 'N/A'} • ${item.notificacao_secretaria || item.secretaria || 'Sem secretaria'}`
          });
        } else if (item.tipo_origem === 'ficha') {
          informacoesBasicas.push({
            type: 'text',
            label: 'Veículo',
            value: `${item.ficha_nome_veiculo || 'Sem veículo'} • ${item.ficha_secretaria || item.secretaria || 'Sem secretaria'}`
          });
        } else {
          informacoesBasicas.push({
            type: 'text',
            label: 'Veículo',
            value: `Prefixo: ${item.prefixo || 'N/A'} • ${item.secretaria || 'Sem secretaria'}`
          });
        }

        // Data
        informacoesBasicas.push({
          type: 'text',
          label: 'Data',
          value: formatDate(
            item.tipo_origem === 'ficha' ? item.ficha_data :
            item.tipo_origem === 'notificacao' ? item.notificacao_data :
            item.data || item.created_at
          )
        });

        // Conteúdo principal - para notificações
        if (item.tipo_origem === 'notificacao') {
          const mensagem = item.notificacao_mensagem || item.mensagem || '';
          const partes = mensagem.split(' - ').filter(Boolean);
          
          partes.forEach(parte => {
            if (parte.includes(':')) {
              const [field, value] = parte.split(':').map(s => s.trim());
              conteudoPrincipal.push({
                label: field,
                value: value
              });
            } else {
              conteudoPrincipal.push({
                label: '',
                value: parte
              });
            }
          });

          if (partes.length === 0) {
            conteudoPrincipal.push({
              label: '',
              value: 'Todos os indicadores estão em condições normais'
            });
          }
        }
        // Conteúdo principal - para fichas
        else if (item.tipo_origem === 'ficha') {
          const camposFicha = [
            { campo: 'suspensao', label: 'Suspensão', obs: 'obs_suspensao' },
            { campo: 'motor', label: 'Motor', obs: 'obs_motor' },
            { campo: 'freios', label: 'Freios', obs: 'obs_freios' },
            { campo: 'direcao', label: 'Direção', obs: 'obs_direcao' },
            { campo: 'sistema_eletrico', label: 'Sistema Elétrico', obs: 'obs_sistema_eletrico' },
            { campo: 'carroceria', label: 'Carroceria', obs: 'obs_carroceria' },
            { campo: 'embreagem', label: 'Embreagem', obs: 'obs_embreagem' },
            { campo: 'rodas', label: 'Rodas', obs: 'obs_rodas' },
            { campo: 'transmissao_9500', label: 'Transmissão', obs: 'obs_transmissao_9500' },
            { campo: 'caixa_mudancas', label: 'Caixa de Mudanças', obs: 'obs_caixa_mudancas' },
            { campo: 'alimentacao', label: 'Alimentação', obs: 'obs_alimentacao' },
            { campo: 'arrefecimento', label: 'Arrefecimento', obs: 'obs_arrefecimento' }
          ];
          
          camposFicha.forEach(({ campo, label, obs }) => {
            if (item[campo]) {
              let texto = item[campo];
              if (item[obs]) {
                texto += ` (${item[obs]})`;
              }
              conteudoPrincipal.push({ label, value: texto });
            }
          });

          // Adiciona observações se existirem
          if (item.observacoes) {
            conteudoPrincipal.push({
              label: 'Observações',
              value: item.observacoes
            });
          }
        }
        // Conteúdo principal - para pedidos
        else {
          if (item.descricao_servico) {
            conteudoPrincipal.push({
              label: 'Descrição',
              value: item.descricao_servico
            });
          }
          if (item.observacoes) {
            conteudoPrincipal.push({
              label: 'Observações',
              value: item.observacoes
            });
          }
        }

        // Adiciona informações de tempo
        if (item.inicio_servico) {
          informacoesBasicas.push({
            type: 'text',
            label: 'Início do Serviço',
            value: formatDate(item.inicio_servico)
          });
        }
        
        if (item.data_conclusao) {
          informacoesBasicas.push({
            type: 'text',
            label: 'Conclusão do Serviço',
            value: formatDate(item.data_conclusao)
          });
        }
        
        if (item.tempo_real) {
          informacoesBasicas.push({
            type: 'text',
            label: 'Tempo Total',
            value: formatarTempo(item.tempo_real)
          });
        }

        setModalContent(
          <div className="p-6 md:p-8 max-w-2xl mx-auto bg-white rounded-xl shadow-lg max-h-[80vh] overflow-y-auto">
            <h3 className="text-2xl font-bold mb-6 border-b pb-2">Detalhes</h3>
            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
              <div className="space-y-4">
                {informacoesBasicas.map((info, index) => (
                  <div key={index} className="p-2 border-b border-gray-200">
                    <span className="font-semibold">{info.label}:</span>
                    {info.type === 'badge' ? (
                      <span className={`ml-2 px-2 py-1 rounded-full text-xs ${info.className}`}>
                        {info.value}
                      </span>
                    ) : (
                      <p className="whitespace-pre-line">{info.value}</p>
                    )}
                  </div>
                ))}
              </div>
              <div className="space-y-4">
                {conteudoPrincipal.length > 0 ? (
                  conteudoPrincipal.map((content, index) => (
                    <div key={index} className="p-2 border-b border-gray-200">
                      {content.label && <span className="font-semibold">{content.label}:</span>}
                      <p className="whitespace-pre-line">{content.value}</p>
                    </div>
                  ))
                ) : (
                  <div className="text-gray-500 italic">
                    <p>Nenhuma informação adicional disponível</p>
                  </div>
                )}
              </div>
            </div>
            <div className="mt-8">
              <button onClick={() => setShowModal(false)}
                className="w-full bg-primary hover:bg-primary/90 text-white py-2 rounded-lg transition shadow">
                Fechar
              </button>
            </div>
          </div>
        );
        setShowModal(true);
      };

      const pedidosDisponiveis = [
        ...pedidosAtivosState,
        ...notificacoesDisponiveisState,
        ...fichasDisponiveisState
      ].sort((a, b) => {
        const dateA = a.data || a.created_at;
        const dateB = b.data || b.created_at;
        return new Date(dateB) - new Date(dateA);
      });

      return (
        <div className="app-container mx-auto relative">
          {/* Header */}
          <header className="bg-primary text-white px-6 py-5 flex justify-between items-center shadow-hard">
            <div className="flex items-center gap-4">
              <div className="relative cursor-pointer" onClick={() => window.location.href='perfil.php'}>
                { window.profilePhoto && !photoError ? (
                  <img
                    src={window.profilePhoto}
                    alt="Perfil"
                    className="rounded-full w-14 h-14 object-cover profile-ring"
                    onError={() => setPhotoError(true)}
                  />
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
              <button className="p-2 hover:bg-white/10 rounded-full transition-colors"
                onClick={() => window.location.href='menu_mecanico.php'}>
                <i className="fas fa-home text-xl"></i>
              </button>
            </div>
          </header>

          {/* Tabs */}
          <div className="flex border-b border-gray-200 px-6">
            <button
              className={`px-4 py-3 font-medium ${activeTab === 'pedidos' ? 'text-primary border-b-2 border-primary' : 'text-gray-500'}`}
              onClick={() => setActiveTab('pedidos')}>
              Pedidos
            </button>
            <button
              className={`px-4 py-3 font-medium ${activeTab === 'notificacoes' ? 'text-primary border-b-2 border-primary' : 'text-gray-500'}`}
              onClick={() => setActiveTab('notificacoes')}>
              Notificações
            </button>
            <button
              className={`px-4 py-3 font-medium ${activeTab === 'fichas' ? 'text-primary border-b-2 border-primary' : 'text-gray-500'}`}
              onClick={() => setActiveTab('fichas')}>
              Fichas
            </button>
            <button
              className={`px-4 py-3 font-medium ${activeTab === 'concluidos' ? 'text-primary border-b-2 border-primary' : 'text-gray-500'}`}
              onClick={() => setActiveTab('concluidos')}>
              Concluídos
            </button>
          </div>

          {/* Main Content */}
          <main className="p-4 pb-20 overflow-y-auto">
            {activeTab === 'pedidos' && (
              <div className="space-y-4">
                {pedidosAtivosState.length === 0 ? (
                  <div className="flex flex-col items-center justify-center h-64 text-gray-500">
                    <i className="fas fa-tools text-4xl mb-4"></i>
                    <p>Nenhum pedido disponível para iniciar</p>
                  </div>
                ) : (
                  pedidosAtivosState.map((pedido) => (
                    <div key={pedido.id} className="pedido-card bg-white rounded-xl shadow-soft p-4 border border-gray-100">
                      <div className="flex justify-between items-start">
                        <div>
                          <h3 className="font-bold text-lg">
                            {pedido.tipo_origem === 'ficha' ? `Ficha #${pedido.ficha_id}` :
                             pedido.tipo_origem === 'notificacao' ? `Notificação #${pedido.notificacao_id}` :
                             `Serviço #${pedido.id}`}
                          </h3>
                          <p className="text-gray-600 text-sm">
                            {pedido.tipo_origem === 'ficha' ? 
                                `${pedido.ficha_nome_veiculo || 'Sem veículo'} • ${pedido.ficha_secretaria || 'Sem secretaria'}` :
                             pedido.tipo_origem === 'notificacao' ?
                                `Prefixo: ${pedido.notificacao_prefixo || 'N/A'} • ${pedido.notificacao_secretaria || 'Sem secretaria'}` :
                                `${pedido.nome_veiculo || 'Sem veículo'} • ${pedido.secretaria || 'Sem secretaria'}`
                            }
                          </p>
                        </div>
                        <span className={`px-2 py-1 rounded-full text-xs 
                          ${pedido.status === 'em_andamento' ? 'badge-em-andamento' : 
                           (pedido.tipo_origem === 'ficha' ? 'badge-ficha' : 
                            pedido.tipo_origem === 'notificacao' ? 'badge-notificacao' : 'badge-pedido')}`}>
                          {pedido.status === 'em_andamento' ? 'Em Andamento' : 
                           (pedido.tipo_origem === 'ficha' ? 'Ficha' : 
                            pedido.tipo_origem === 'notificacao' ? 'Notificação' : 'Pedido')}
                        </span>
                      </div>
                      <p className="my-2 text-gray-700 line-clamp-2">
                        {pedido.tipo_origem === 'notificacao' ? pedido.notificacao_mensagem :
                         pedido.tipo_origem === 'ficha' ? (
                            Object.entries({
                                'Suspensão': pedido.suspensao,
                                'Motor': pedido.motor,
                                'Freios': pedido.freios,
                                'Direção': pedido.direcao,
                                'Sistema Elétrico': pedido.sistema_eletrico
                            }).filter(([_, value]) => value)
                            .map(([key, value]) => `${key}: ${value}`).join(' | ')
                         ) : pedido.observacoes}
                      </p>
                      
                      {/* Timer Container para serviços em andamento */}
                      {pedido.status === 'em_andamento' && (
                        <div className="timer-container">
                          <div className="timer-display">
                            {formatarTempo(timers[pedido.id] ? timers[pedido.id].tempoDecorrido : 0)}
                          </div>
                          <button
                            onClick={() => handleFinalizarServico(pedido.id)}
                            className="w-full bg-primary text-white py-2 rounded-lg hover:bg-primary/90 transition flex items-center justify-center gap-2">
                            <i className="fas fa-check-circle"></i>
                            <span>Finalizar Serviço</span>
                          </button>
                        </div>
                      )}

                      <div className="flex justify-between items-center mt-3">
                        <span className="text-xs text-gray-500">
                          {formatDate(
                              pedido.tipo_origem === 'ficha' ? pedido.ficha_data :
                              pedido.tipo_origem === 'notificacao' ? pedido.notificacao_data :
                              pedido.created_at
                          )}
                        </span>
                        <div className="flex flex-wrap gap-2 ml-auto action-buttons">
                          <button onClick={() => handleVerDetalhes(pedido)}
                              className="flex items-center gap-1 bg-blue-100 text-blue-600 px-3 py-1 rounded hover:bg-blue-200 transition">
                              <i className="fas fa-eye"></i>
                              <span>Detalhes</span>
                          </button>
                          {pedido.status === 'aceito' && (
                              <button onClick={() => handleIniciarServico(pedido.id)}
                                  className="flex items-center gap-1 bg-green-100 text-green-600 px-3 py-1 rounded hover:bg-green-200 transition">
                                  <i className="fas fa-play"></i>
                                  <span>Iniciar</span>
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
                    <p>Nenhuma notificação disponível</p>
                  </div>
                ) : (
                  notificacoesDisponiveisState.map((notificacao) => (
                    <div key={notificacao.id} className="pedido-card bg-white rounded-xl shadow-soft p-4 border border-gray-100">
                      <div className="flex justify-between items-start">
                        <div>
                          <h3 className="font-bold text-lg">Notificação #{notificacao.id}</h3>
                          <p className="text-gray-600 text-sm">
                            {notificacao.prefixo ? `Prefixo: ${notificacao.prefixo}` : (notificacao.veiculo || 'Sem informações')}
                            {notificacao.secretaria ? ` • ${notificacao.secretaria}` : ''}
                          </p>
                        </div>
                        <span className="px-2 py-1 rounded-full text-xs badge-notificacao">
                          Notificação
                        </span>
                      </div>
                      <p className="my-2 text-gray-700 line-clamp-2">{notificacao.mensagem}</p>
                      <div className="flex justify-between items-center mt-3">
                        <span className="text-xs text-gray-500">
                          {formatDate(notificacao.data || notificacao.created_at)}
                        </span>
                        <div className="flex flex-wrap gap-2 ml-auto action-buttons">
                          <button onClick={() => handleVerDetalhes(notificacao)}
                              className="flex items-center gap-1 bg-blue-100 text-blue-600 px-3 py-1 rounded hover:bg-blue-200 transition">
                              <i className="fas fa-eye"></i>
                              <span>Detalhes</span>
                          </button>
                          <button onClick={() => handleAceitarServico('notificacao', notificacao.id)}
                              className="flex items-center gap-1 bg-green-100 text-green-600 px-3 py-1 rounded hover:bg-green-200 transition">
                              <i className="fas fa-check"></i>
                              <span>Aceitar</span>
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
                    <p>Nenhuma ficha disponível</p>
                  </div>
                ) : (
                  fichasDisponiveisState.map((ficha) => (
                    <div key={ficha.id} className="pedido-card bg-white rounded-xl shadow-soft p-4 border border-gray-100">
                      <div className="flex justify-between items-start">
                        <div>
                          <h3 className="font-bold text-lg">Ficha #{ficha.id}</h3>
                          <p className="text-gray-600 text-sm">
                            {ficha.veiculo_id ? `Veículo ID: ${ficha.veiculo_id}` : (ficha.veiculo || 'Sem informações')}
                            {ficha.secretaria ? ` • ${ficha.secretaria}` : ''}
                          </p>
                        </div>
                        <span className="px-2 py-1 rounded-full text-xs badge-ficha">
                          Ficha
                        </span>
                      </div>
                      <p className="my-2 text-gray-700 line-clamp-2">{ficha.descricao_servico}</p>
                      <div className="flex justify-between items-center mt-3">
                        <span className="text-xs text-gray-500">
                          {formatDate(ficha.data || ficha.created_at)}
                        </span>
                        <div className="flex flex-wrap gap-2 ml-auto action-buttons">
                          <button onClick={() => handleVerDetalhes(ficha)}
                              className="flex items-center gap-1 bg-blue-100 text-blue-600 px-3 py-1 rounded hover:bg-blue-200 transition">
                              <i className="fas fa-eye"></i>
                              <span>Detalhes</span>
                          </button>
                          <button onClick={() => handleAceitarServico('ficha', ficha.id)}
                              className="flex items-center gap-1 bg-green-100 text-green-600 px-3 py-1 rounded hover:bg-green-200 transition">
                              <i className="fas fa-check"></i>
                              <span>Aceitar</span>
                          </button>
                        </div>
                      </div>
                    </div>
                  ))
                )}
              </div>
            )}
            {activeTab === 'concluidos' && (
              <div className="space-y-4">
                {window.servicosFinalizados.length === 0 ? (
                  <div className="flex flex-col items-center justify-center h-64 text-gray-500">
                    <i className="fas fa-check-circle text-4xl mb-4"></i>
                    <p>Nenhum serviço finalizado</p>
                  </div>
                ) : (
                  window.servicosFinalizados.map((servico) => (
                    <div key={servico.id} className="pedido-card bg-white rounded-xl shadow-soft p-4 border border-gray-100">
                      <div className="flex justify-between items-start">
                        <div>
                          <h3 className="font-bold text-lg">Serviço #{servico.id}</h3>
                          <p className="text-gray-600 text-sm">
                            {servico.tipo_origem === 'ficha' ? 
                                `${servico.ficha_nome_veiculo || 'Sem veículo'} • ${servico.ficha_secretaria || 'Sem secretaria'}` :
                             servico.tipo_origem === 'notificacao' ?
                                `Prefixo: ${servico.notificacao_prefixo || 'N/A'} • ${servico.notificacao_secretaria || 'Sem secretaria'}` :
                                `${servico.nome_veiculo || 'Sem veículo'} • ${servico.secretaria || 'Sem secretaria'}`
                            }
                          </p>
                        </div>
                        <span className="px-2 py-1 rounded-full text-xs badge-concluido">
                          Concluído
                        </span>
                      </div>
                      <p className="my-2 text-gray-700 line-clamp-2">
                        {servico.tipo_origem === 'notificacao' ? servico.notificacao_mensagem :
                         servico.tipo_origem === 'ficha' ? (
                            Object.entries({
                                'Suspensão': servico.suspensao,
                                'Motor': servico.motor,
                                'Freios': servico.freios,
                                'Direção': servico.direcao,
                                'Sistema Elétrico': servico.sistema_eletrico
                            }).filter(([_, value]) => value)
                            .map(([key, value]) => `${key}: ${value}`).join(' | ')
                         ) : servico.observacoes}
                      </p>
                      <div className="flex justify-between items-center mt-3">
                        <span className="text-xs text-gray-500">{formatDate(servico.data_conclusao)}</span>
                        <div className="flex space-x-2 ml-auto">
                          <button onClick={() => handleVerDetalhes(servico)}
                              className="flex items-center gap-1 bg-blue-100 text-blue-600 px-3 py-1 rounded hover:bg-blue-200 transition">
                              <i className="fas fa-eye"></i>
                              <span>Detalhes</span>
                          </button>
                        </div>
                      </div>
                    </div>
                  ))
                )}
              </div>
            )}
          </main>

          {/* Lower Navigation */}
          <nav className="fixed bottom-0 w-full bg-white border-t border-gray-200 flex justify-around items-center h-16 shadow-hard ml-[-8px]">
            {[
              {icon: 'fa-user', label: 'Perfil', tab: 'profile'},
              {icon: 'fa-tools', label: 'Pedidos', tab: 'pedidos'},
              {icon: 'fa-sign-out-alt', label: 'Sair', tab: 'logout'}
            ].map((item) => (
              <button
                key={item.tab}
                className={`flex flex-col items-center p-2 transition-all ${item.tab === 'logout' ? 'text-red-500 hover:text-red-600' : (activeTab === item.tab ? 'text-primary' : 'text-gray-400 hover:text-gray-600')}`}
                onClick={() => {
                  if (item.tab === 'profile') {
                    window.location.href = 'perfil.php';
                  } else if (item.tab === 'logout') {
                    window.location.href = 'logout.php';
                  } else {
                    setActiveTab(item.tab);
                  }
                }}>
                <i className={`fas ${item.icon} text-xl`}></i>
                <span className="text-xs font-medium mt-1">{item.label}</span>
              </button>
            ))}
          </nav>

          {/* Modal */}
          {showModal && (
            <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
              <div className="bg-white rounded-xl w-full max-w-md animate-fade-in">
                {modalContent}
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