<?php

/**
 * api.php
 * Endpoint único (front controller) para operações AJAX da aplicação Agente Urbano.
 *
 * Responsabilidades principais:
 * - Fornecer handlers para CRUD de relatórios, autenticação simples (JSON file),
 *   comentários e votos.
 * - Lidar com upload seguro de imagens (diretório `uploads/`) em criação/edição.
 * - Retornar JSON consistente para o front-end.
 *
 * Segurança e observações de manutenção:
 * - Em produção, substitua o armazenamento de usuários baseado em JSON por um
 *   sistema de usuários em BD com senhas e sessões seguras.
 * - Verifique permissões do diretório `uploads/` e limite tamanho/tipo de arquivos no PHP.
 */

ob_start(); // bufferiza toda a saída para evitar que warnings rompam JSON
error_reporting(E_ERROR | E_PARSE);
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    // Erros são suprimidos da saída HTTP intencionalmente para preservar JSON.
    // Eles ainda devem aparecer nos logs do servidor para depuração.
    return true;
});
session_start();
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', ''); 
define('DB_NAME', 'problemas_publicos');
define('UPLOAD_DIR', __DIR__ . '/uploads/');

function connectDB() {
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("set names utf8mb4");
        
        return $pdo;
    } catch (PDOException $e) {
        http_response_code(500);
        // Em caso de falha na conexão com o banco, retorna JSON e interrompe.
        die(json_encode(['success' => false, 'message' => "Erro de Conexão com o DB: " . $e->getMessage()]));
    }
}

/**
 * addVoteHandler
 * Handler para registrar um 'voto' de apoio a um relatório.
 *
 * Entradas esperadas:
 * - GET id (inteiro): id do relatório.
 * - POST user_name (opcional): nome do usuário, fallback para sessão/anon.
 *
 * Saída:
 * - JSON com 'success' e mensagem, ou 'already_voted' quando apropriado.
 */
function addVoteHandler($pdo) {
    $reportId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $userName = getCurrentUser() ?? ($_POST['user_name'] ?? ('anonimo_' . session_id())); 
    
    if (!$reportId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID de relatório é obrigatório.']);
        return;
    }

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM votos WHERE report_id = ? AND user_name = ?");
        $stmt->execute([$reportId, $userName]);
        if ($stmt->fetchColumn() > 0) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM votos WHERE report_id = ?");
            $stmt->execute([$reportId]);
            $newVotes = $stmt->fetchColumn();
            echo json_encode(['success' => false, 'message' => 'Você já apoiou este relatório.', 'new_votes' => (int)$newVotes, 'already_voted' => true]);
            return;
        }

        $stmt = $pdo->prepare("INSERT INTO votos (report_id, user_name, voted_at) VALUES (?, ?, NOW())");
        $stmt->execute([$reportId, $userName]);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM votos WHERE report_id = ?");
        $stmt->execute([$reportId]);
        $newVotes = $stmt->fetchColumn();

        echo json_encode(['success' => true, 'message' => 'Voto registrado com sucesso.', 'new_votes' => (int)$newVotes]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro ao registrar voto: ' . $e->getMessage()]);
    }
}

function addCommentHandler($pdo) {
    $reportId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) 
        ?? filter_input(INPUT_POST, 'report_id', FILTER_VALIDATE_INT)
        ?? filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT)
        ?? filter_input(INPUT_POST, 'relatorio_id', FILTER_VALIDATE_INT);
    
    $commentText = trim($_POST['comment_text'] ?? '');
    $userName = getCurrentUser() ?? $_POST['user_name'] ?? null;
    
    if (!$userName) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Você precisa estar logado para comentar.']);
        return;
    }

    if (!$reportId || empty($commentText)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID do relatório e texto do comentário são obrigatórios.']);
        return;
    }

    try {
        $sql = "INSERT INTO comentarios (report_id, user_name, comment_text, created_at) VALUES (?, ?, ?, NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$reportId, $userName, $commentText]);

        echo json_encode(['success' => true, 'message' => 'Comentário salvo com sucesso.', 'author' => $userName]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro ao salvar o comentário: ' . $e->getMessage()]);
    }
}

function getUsersFile() {
    return __DIR__ . '/users.json';
}

/**
 * registerUserHandler
 * Registra um usuário simples usando arquivo `users.json`.
 *
 * Observações:
 * - Armazena password com `password_hash`.
 * - Não recomendado para produção; manter apenas para protótipo/local.
 */

function loadUsers() {
    $file = getUsersFile();
    if (!file_exists($file)) return [];
    $json = file_get_contents($file);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function saveUsers($users) {
    file_put_contents(getUsersFile(), json_encode($users, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}

function registerUserHandler() {
    $input = $_POST;
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';
    if ($username === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Username e senha são obrigatórios.']);
        return;
    }
    $users = loadUsers();
    if (isset($users[$username])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Usuário já existe.']);
        return;
    }
    $users[$username] = [
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'created_at' => date('c')
    ];
    saveUsers($users);
    
    session_regenerate_id(true); 
    $_SESSION['username'] = $username;
    
    echo json_encode(['success' => true, 'message' => 'Registrado com sucesso.', 'username' => $username]);
}

function loginUserHandler() {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $users = loadUsers();
    
    if (!isset($users[$username]) || !password_verify($password, $users[$username]['password'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Usuário ou senha inválidos.']);
        return;
    }
    
    session_regenerate_id(true); 
    $_SESSION['username'] = $username;
    
    echo json_encode(['success' => true, 'message' => 'Login bem-sucedido.', 'username' => $username]);
}

function logoutUserHandler() {

    session_unset();
    
    session_destroy();

    session_write_close(); 

    setcookie(session_name(), '', time() - 3600, '/'); 
    
    echo json_encode(['success' => true, 'message' => 'Logout realizado.']);
}

function getCurrentUser() {
    return $_SESSION['username'] ?? null;
}

function getOwnersFile() {
    return __DIR__ . '/report_owners.json';
}

function loadOwners() {
    $file = getOwnersFile();
    if (!file_exists($file)) return [];
    $json = file_get_contents($file);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function saveOwners($owners) {
    file_put_contents(getOwnersFile(), json_encode($owners, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
}

function claimReportHandler() {
    $username = getCurrentUser();
    if (!$username) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Autenticação necessária.']);
        return;
    }
    $report_id = $_POST['report_id'] ?? null;
    if (!$report_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'report_id é obrigatório.']);
        return;
    }
    $owners = loadOwners();
    if (isset($owners[$report_id])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Relatório já possui proprietário.']);
        return;
    }
    $owners[$report_id] = ['username' => $username, 'claimed_at' => date('c')];
    saveOwners($owners);
    echo json_encode(['success' => true, 'message' => 'Relatório reivindicado.', 'report_id' => $report_id]);
}

function userOwnsReport($report_id) {
    $owners = loadOwners();
    $username = getCurrentUser();
    return $username && isset($owners[$report_id]) && $owners[$report_id]['username'] === $username;
}

function getMyReportsHandler() {
    $username = getCurrentUser();
    if (!$username) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Autenticação necessária.']);
        return;
    }
    $owners = loadOwners();
    $my = [];
    foreach ($owners as $rid => $info) {
        if ($info['username'] === $username) $my[] = $rid;
    }
    echo json_encode(['success' => true, 'report_ids' => $my]);
}

function editReportHandler($pdo) {
    /**
     * editReportHandler
     * Atualiza campos editáveis de um relatório existente e opcionalmente substitui a imagem.
     *
     * Entradas esperadas (POST):
     * - id (int) - id do relatório a ser editado (obrigatório)
     * - titulo, descricao, status, prioridade, endereco (opcionais)
     * - imagem_upload (file) - arquivo enviado multipart/form-data (opcional)
     *
     * Regras de segurança:
     * - Verifica se o usuário autenticado é proprietário do relatório via `userOwnsReport()`.
     * - Valida extensões de imagem permitidas (jpg, jpeg, png, gif).
     * - Salva novo arquivo em `uploads/` com nome único e tenta remover a imagem anterior
     *   somente se estiver dentro do diretório `uploads/` (uso de realpath para segurança).
     */
    $username = getCurrentUser();
    if (!$username) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Autenticação necessária.']);
        return;
    }
    $id = $_POST['id'] ?? null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID do relatório é obrigatório.']);
        return;
    }
    if (!userOwnsReport($id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Você não tem permissão para editar este relatório.']);
        return;
    }
    // Campos editáveis
    $titulo = $_POST['titulo'] ?? null;
    $descricao = $_POST['descricao'] ?? null;
    $status = $_POST['status'] ?? null;
    $prioridade = $_POST['prioridade'] ?? null;
    $endereco = $_POST['endereco'] ?? null;
    $params = [];
    $sets = [];
    if ($titulo !== null) { $sets[] = 'titulo = ?'; $params[] = $titulo; }
    if ($descricao !== null) { $sets[] = 'descricao = ?'; $params[] = $descricao; }
    if ($status !== null) { $sets[] = 'status = ?'; $params[] = $status; }
    if ($prioridade !== null) { $sets[] = 'prioridade = ?'; $params[] = $prioridade; }
    if ($endereco !== null) { $sets[] = 'endereco = ?'; $params[] = $endereco; }
    // Handle image upload for edit (optional)
    $imagem_path = null;
    if (isset($_FILES['imagem_upload']) && is_array($_FILES['imagem_upload']) && isset($_FILES['imagem_upload']['error'])) {
        if ($_FILES['imagem_upload']['error'] === UPLOAD_ERR_NO_FILE) {
            // No file uploaded - skip image update
        } elseif ($_FILES['imagem_upload']['error'] === UPLOAD_ERR_OK) {
            $file = $_FILES['imagem_upload'];
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];

            if (!in_array(strtolower($ext), $allowed_ext)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Tipo de arquivo inválido.']);
                return;
            }

            if (!is_dir(UPLOAD_DIR)) {
                if (!mkdir(UPLOAD_DIR, 0777, true)) {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Erro interno: Não foi possível criar a pasta uploads. Verifique as permissões.']);
                    return;
                }
            }

            $unique_filename = uniqid('img_', true) . '.' . $ext;
            $destination = UPLOAD_DIR . $unique_filename;

            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Erro interno ao salvar a imagem no servidor. Verifique as permissões da pasta uploads.']);
                return;
            }

            $imagem_path = 'uploads/' . $unique_filename;

            // Remove previous image file (if any) for this report, safely
            try {
                $stmtPrev = $pdo->prepare("SELECT imagem_url FROM relatorios WHERE id = ?");
                $stmtPrev->execute([$id]);
                $prev = $stmtPrev->fetchColumn();
                if ($prev) {
                    $prevRelative = ltrim($prev, '/\\');
                    $prevFull = realpath(__DIR__ . DIRECTORY_SEPARATOR . $prevRelative);
                    $uploadReal = realpath(UPLOAD_DIR);
                    if ($prevFull && $uploadReal && strpos($prevFull, $uploadReal) === 0 && file_exists($prevFull)) {
                        @unlink($prevFull);
                    }
                }
            } catch (Exception $e) {
                // non-fatal: continue
            }

            $sets[] = 'imagem_url = ?';
            $params[] = $imagem_path;
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Erro no envio da imagem. Código: ' . $_FILES['imagem_upload']['error']]);
            return;
        }
    }
    if (empty($sets)) {
        echo json_encode(['success' => false, 'message' => 'Nenhum campo para atualizar.']);
        return;
    }
    $params[] = $id;
    try {
        $sql = 'UPDATE relatorios SET ' . implode(', ', $sets) . ', data_atualizacao = NOW() WHERE id = ?';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['success' => true, 'message' => 'Relatório atualizado.']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro ao atualizar: ' . $e->getMessage()]);
    }
}

function deleteReportHandler($pdo) {
    $username = getCurrentUser();
    if (!$username) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Autenticação necessária.']);
        return;
    }
    $id = $_POST['id'] ?? null;
    if (!$id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID do relatório é obrigatório.']);
        return;
    }
    if (!userOwnsReport($id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Você não tem permissão para deletar este relatório.']);
        return;
    }
    try {
        $stmt = $pdo->prepare('DELETE FROM relatorios WHERE id = ?');
        $stmt->execute([$id]);
        $owners = loadOwners();
        if (isset($owners[$id])) {
            unset($owners[$id]);
            saveOwners($owners);
        }
        echo json_encode(['success' => true, 'message' => 'Relatório excluído.']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro ao excluir: ' . $e->getMessage()]);
    }
}

function getProblems($pdo) {
    try {

        $stmt = $pdo->query("SELECT id, titulo, latitude, longitude, tipo, descricao, status, imagem_url, prioridade, endereco, data_criacao FROM relatorios ORDER BY data_criacao DESC");
        $problems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($problems);
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['success' => false, 'message' => 'Erro ao buscar problemas: ' . $e->getMessage()]));
    }
}

function getReportDetails($pdo) {
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    if ($id === false || $id <= 0) {
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'ID de relatório inválido.']));
    }

    try {
        $sql = "SELECT id, titulo, latitude, longitude, tipo, descricao, status, 
                             imagem_url, prioridade, endereco, data_criacao, data_atualizacao, 
                             user_id 
                        FROM relatorios 
                        WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$report) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Relatório não encontrado.']);
            return;
        }
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM votos WHERE report_id = ?");
        $stmt->execute([$id]);
        $votos = $stmt->fetchColumn();
        $report['votos'] = (int)$votos;
        $stmt = $pdo->prepare("SELECT user_name AS author, comment_text AS text, created_at AS date FROM comentarios WHERE report_id = ? ORDER BY created_at DESC");
        $stmt->execute([$id]);
        $comentarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $report['comentarios'] = $comentarios;
        
        echo json_encode($report);
        
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['success' => false, 'message' => 'Erro ao buscar detalhes do relatório: ' . $e->getMessage()]));
    }
}

function reportProblem($pdo) {
    $lat = filter_input(INPUT_POST, 'latitude', FILTER_VALIDATE_FLOAT);
    $lng = filter_input(INPUT_POST, 'longitude', FILTER_VALIDATE_FLOAT);
    $tipo = filter_input(INPUT_POST, 'categoria', FILTER_SANITIZE_STRING); 
    $descricao = filter_input(INPUT_POST, 'descricao', FILTER_SANITIZE_STRING);
    $titulo = filter_input(INPUT_POST, 'titulo', FILTER_SANITIZE_STRING);
    $prioridade = filter_input(INPUT_POST, 'prioridade', FILTER_SANITIZE_STRING);
    $endereco = filter_input(INPUT_POST, 'endereco', FILTER_SANITIZE_STRING);
    
    $status = filter_input(INPUT_POST, 'status', FILTER_SANITIZE_STRING) ?? 'Pendente';

    $user_id = getCurrentUser() ?? 'anonimo';
    
    // Debug simples
    error_log("Report: categoria=[" . ($tipo ?? 'NULL') . "] titulo=[" . substr($titulo, 0, 20) . "]");

    if ($lat === false || $lng === false || empty($tipo) || empty($descricao) || empty($titulo) || empty($prioridade)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Erro: Título, Categoria, Prioridade, Descrição e Localização são obrigatórios.']);
        return;
    }

    $imagem_path = null;
    
    if (isset($_FILES['imagem_upload']) && $_FILES['imagem_upload']['error'] === UPLOAD_ERR_OK) {
        $file = $_FILES['imagem_upload'];
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif'];

        if (!in_array(strtolower($ext), $allowed_ext)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Tipo de arquivo inválido.']);
            return;
        }

        if (!is_dir(UPLOAD_DIR)) {
            if (!mkdir(UPLOAD_DIR, 0777, true)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Erro interno: Não foi possível criar a pasta uploads. Verifique as permissões.']);
                return;
            }
        }

        $unique_filename = uniqid('img_', true) . '.' . $ext;
        $destination = UPLOAD_DIR . $unique_filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Erro interno ao salvar a imagem no servidor. Verifique as permissões da pasta uploads.']);
            return;
        }
        
        $imagem_path = 'uploads/' . $unique_filename;
    }
    
    try {

        $sql = "INSERT INTO relatorios (titulo, latitude, longitude, tipo, descricao, status, data_criacao, imagem_url, prioridade, endereco, user_id)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?, ?)";
        
        $stmt = $pdo->prepare($sql);
        
        $stmt->execute([
            $titulo,
            $lat,
            $lng,
            $tipo,
            $descricao,
            $status, 
            $imagem_path,
            $prioridade, 
            $endereco,
            $user_id 
        ]);
        $lastId = $pdo->lastInsertId();
        echo json_encode(['success' => true, 'message' => 'Relatório registrado com sucesso!', 'report_id' => $lastId]);
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['success' => false, 'message' => "Erro fatal do MySQL: " . $e->getMessage()]));
    }
}

function getStatsHandler($pdo) {
    try {
        $stmt_reports = $pdo->query("SELECT COUNT(*) FROM relatorios");
        $total_reports = $stmt_reports->fetchColumn();

        $users = loadUsers();
        $total_users = count($users);

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'total_reports' => (int)$total_reports,
            'total_users' => (int)$total_users
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro ao buscar estatísticas de relatórios: ' . $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro ao buscar estatísticas de usuários: ' . $e->getMessage()]);
    }
}



function getDashboardData($pdo) {
    try {
        $statusCounts = [
            'Pendente' => 0,
            'Em Análise' => 0,
            'Em Andamento' => 0,
            'Resolvido' => 0,
        ];
        
     
        $stmt = $pdo->query("SELECT status, COUNT(*) as count FROM relatorios GROUP BY status");
        $dbCounts = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        

        foreach ($dbCounts as $status => $count) {
            if (array_key_exists($status, $statusCounts)) {
                $statusCounts[$status] = (int)$count;
            }
        }

        $total = array_sum($statusCounts);
        $resolvidos = $statusCounts['Resolvido'] ?? 0;
        $taxa_resolucao = ($total > 0) ? round(($resolvidos / $total) * 100) : 0;
        
     
        $stmt = $pdo->query("SELECT COALESCE(NULLIF(tipo, ''), 'outros') as tipo, COUNT(*) as count FROM relatorios GROUP BY COALESCE(NULLIF(tipo, ''), 'outros') ORDER BY count DESC");
        $tipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
     
        $stmt = $pdo->query("SELECT COUNT(*) FROM relatorios WHERE data_criacao >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $novos_7_dias = (int)$stmt->fetchColumn();


        return [
            'total' => $total, 
            'pendentes' => $statusCounts['Pendente'],
            'resolvidos' => $resolvidos, 
            'em_analise' => $statusCounts['Em Análise'],
            'em_andamento' => $statusCounts['Em Andamento'],
            'taxa_resolucao' => $taxa_resolucao, 
            'novos_7_dias' => $novos_7_dias, 
            'tipos' => $tipos,
            'status_counts' => $statusCounts
        ];
    } catch (PDOException $e) {
    
        return [
            'total' => 0, 'pendentes' => 0, 'resolvidos' => 0, 'em_analise' => 0,
            'em_andamento' => 0, 'taxa_resolucao' => 0, 'novos_7_dias' => 0, 'tipos' => [],
            'status_counts' => ['Pendente' => 0, 'Em Análise' => 0, 'Em Andamento' => 0, 'Resolvido' => 0]
        ];
    }
}

function getReportsOverTimeData($pdo) {
    $sql = "SELECT DATE(data_criacao) as report_date, COUNT(*) as count 
            FROM relatorios 
            WHERE data_criacao IS NOT NULL
            GROUP BY report_date 
            ORDER BY report_date ASC";
    
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

function getReportsOverTimeHandler($pdo) {
    $data = getReportsOverTimeData($pdo);
    
    $labels = [];
    $cumulative_counts = [];
    $cumulative = 0;
    

    foreach ($data as $row) {
        $labels[] = $row['report_date']; 
        $cumulative += (int)$row['count'];
        $cumulative_counts[] = $cumulative; 
    }
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'cumulative_counts' => $cumulative_counts
    ]);
}


if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    
    $action = $_GET['action'] ?? '';
    
    $pdo = connectDB();

   
    header('Content-Type: application/json');

    switch ($action) {
        case 'report_problem':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                reportProblem($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Método não permitido. Use POST para criar relatórios.']);
            }
            break;
        case 'get_problems':
            getProblems($pdo);
            break;
        case 'dashboard_data':
            echo json_encode(getDashboardData($pdo));
            break;
        
        case 'get_report_details': 
            getReportDetails($pdo);
            break; 
        
        case 'add_comment':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                addCommentHandler($pdo);
            } else {
                http_response_code(405); echo json_encode(['success' => false, 'message' => 'Use POST']);
            }
            break;
        
        case 'add_vote':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                addVoteHandler($pdo);
            } else {
                http_response_code(405); echo json_encode(['success' => false, 'message' => 'Use POST']);
            }
            break;
        
        case 'register':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') registerUserHandler();
            else { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Use POST']); }
            break;
        case 'login':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') loginUserHandler();
            else { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Use POST']); }
            break;
        case 'logout':
            logoutUserHandler();
            break;
        case 'current_user':
            echo json_encode(['username' => getCurrentUser()]);
            break;
            
        case 'get_stats':
            getStatsHandler($pdo);
            break;
        
        case 'reports_over_time':
            getReportsOverTimeHandler($pdo);
            break;
            
        case 'claim_report':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') claimReportHandler();
            else { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Use POST']); }
            break;
        case 'my_reports':
            getMyReportsHandler();
            break;
        case 'edit_report':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') editReportHandler($pdo);
            else { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Use POST']); }
            break;
        case 'delete_report':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') deleteReportHandler($pdo);
            else { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Use POST']); }
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ação de API inválida.']);
            break;
    }
}