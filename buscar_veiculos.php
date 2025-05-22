<?php
header('Content-Type: application/json');
require 'conexao.php'; // Inclui a conexão com o banco de dados

session_start();


$secretarias_map = [
    "Gabinete do Prefeito" => "GABINETE DO PREFEITO",
    "Gabinete do Vice-Prefeito" => "GABINETE DO VICE-PREFEITO",
    "Secretaria Municipal da Mulher de Família" => "SECRETARIA DA MULHER",
    "Secretaria Municipal de Fazenda" => "SECRETARIA DE FAZENDA",
    "Secretaria Municipal de Educação" => "SECRETARIA DE EDUCAÇÃO",
    "Secretaria Municipal de Agricultura e Meio Ambiente" => "SECRETARIA DE AGRICULTURA E MEIO AMBIENTE",
    "Secretaria Municipal de Agricultura Familiar e Segurança Alimentar" => "SECRETARIA DE AGRICULTURA FAMILIAR",
    "Secretaria Municipal de Assistência Social" => "SECRETARIA DE ASSISTÊNCIA SOCIAL",
    "Secretaria Municipal de Desenvolvimento Econômico e Turismo" => "SECRETARIA DE DESENVOL. ECONÔMICO",
    "Secretaria Municipal de Administração" => "SECRETARIA DE ADMINISTRAÇÃO",
    "Secretaria Municipal de Governo" => "SECRETARIA DE GOVERNO",
    "Secretaria Municipal de Infraestrutura, Transportes e Saneamento" => "SECRETARIA DE INFRAESTRUTURA, TRANSPORTE E SANEAMENTO",
    "Secretaria Municipal de Esporte e Lazer e Juventude" => "SECRETARIA DE ESPORTE E LAZER",
    "Secretaria Municipal da Cidade" => "SECRETARIA DA CIDADE",
    "Secretaria Municipal de Saúde" => "SECRETARIA DE SAÚDE",
    "Secretaria Municipal de Segurança Pública, Trânsito e Defesa Civil" => "SECRETARIA DE SEGURANÇA PÚBLICA",
    "Controladoria Geral do Município" => "CONTROLADORIA GERAL",
    "Procuradoria Geral do Município" => "PROCURADORIA GERAL",
    "Secretaria Municipal de Cultura" => "SECRETARIA DE CULTURA",
    "Secretaria Municipal de Planejamento, Ciência, Tecnologia e Inovação" => "SECRETARIA DE PLANEJAMENTO E TECNOLOGIA",
    "Secretaria Municipal de Obras e Serviços Públicos" => "SECRETARIA DE OBRAS E SERVIÇOS PÚBLICOS",
];

//SECRATARIS DO USER LOGADO
$secretaria_admin = $_SESSION['secretaria'];

//MAPEIA OS DADOS DA SECRETARIA
$secretaria_admin_db = isset($secretarias_map[$secretaria_admin]) ? $secretarias_map[$secretaria_admin] : null;

//ROLE, ADM, USER OR GERALADM
$role = $_SESSION['role'] ?? '';

$termo = $_GET['termo'] ?? ''; // Obtém o termo digitado pelo usuário
$page = $_GET['page'] ?? 1; // Número da página
$limit = 10; // Número de resultados por página
$offset = ($page - 1) * $limit; // Calcula o OFFSET

try {
    if (!empty($termo)) {
        // Prepara a consulta SQL com base no papel do usuário
        if ($role === 'geraladm') {
            //  "geraladm", BUSCA TDS OS VEICULOS
            $stmt = $conn->prepare("SELECT id, veiculo, placa, tipo, secretaria
                                    FROM veiculos
                                    WHERE veiculo LIKE :termo
                                    LIMIT :limit OFFSET :offset");
            $stmt->bindValue(':termo', "%$termo%", PDO::PARAM_STR);
        } else {
            // if esle// ADM OU USER (BUSCAR SECRETARIA)
            $stmt = $conn->prepare("SELECT id, veiculo, placa, tipo, secretaria
                                    FROM veiculos
                                    WHERE veiculo LIKE :termo
                                    AND secretaria = :secretaria
                                    LIMIT :limit OFFSET :offset");
            $stmt->bindValue(':termo', "%$termo%", PDO::PARAM_STR);
            $stmt->bindValue(':secretaria', $secretaria_admin_db, PDO::PARAM_STR);
        }

        //VINCULA OS PARAMETROS DE CONSULTA
        $stmt->bindValue(':limit', (int) $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int) $offset, PDO::PARAM_INT);

        $stmt->execute();

        $veiculos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Retorna os dados corretamente
        echo json_encode($veiculos ?: []);
    } else {
        echo json_encode([]);
    }
} catch (PDOException $e) {
    echo json_encode(["erro" => "Erro ao buscar veículos: " . $e->getMessage()]);
}
?>
