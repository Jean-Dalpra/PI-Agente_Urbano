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

function columnExists($pdo, $table, $column) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function tableExists($pdo, $table) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function ensureGamificationSchema($pdo) {
    if (!columnExists($pdo, 'relatorios', 'veracidade')) {
        $pdo->exec("ALTER TABLE relatorios ADD COLUMN veracidade INT NOT NULL DEFAULT 50");
    }
    if (!columnExists($pdo, 'relatorios', 'invalidated_penalties_applied')) {
        $pdo->exec("ALTER TABLE relatorios ADD COLUMN invalidated_penalties_applied TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!columnExists($pdo, 'comentarios', 'veracidade')) {
        $pdo->exec("ALTER TABLE comentarios ADD COLUMN veracidade INT NOT NULL DEFAULT 50");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_gamification (
        username VARCHAR(120) PRIMARY KEY,
        xp INT NOT NULL DEFAULT 0,
        level_num INT NOT NULL DEFAULT 1,
        urban_points INT NOT NULL DEFAULT 0,
        reliability_rank DECIMAL(5,2) NOT NULL DEFAULT 50,
        participation_count INT NOT NULL DEFAULT 0,
        validations_total INT NOT NULL DEFAULT 0,
        validations_correct INT NOT NULL DEFAULT 0,
        validations_incorrect INT NOT NULL DEFAULT 0,
        reports_created INT NOT NULL DEFAULT 0,
        reports_verified INT NOT NULL DEFAULT 0,
        reports_invalidated INT NOT NULL DEFAULT 0,
        suspicious_score INT NOT NULL DEFAULT 0,
        monthly_score DECIMAL(8,2) NOT NULL DEFAULT 0,
        last_activity_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS report_validations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        report_id INT NOT NULL,
        username VARCHAR(120) NOT NULL,
        validation_type ENUM('confirm','deny') NOT NULL,
        comment_text TEXT NULL,
        user_latitude DECIMAL(10,8) NULL,
        user_longitude DECIMAL(11,8) NULL,
        distance_meters DECIMAL(10,2) NULL,
        base_weight INT NOT NULL DEFAULT 2,
        geo_bonus INT NOT NULL DEFAULT 0,
        effective_delta INT NOT NULL DEFAULT 0,
        was_correct TINYINT(1) NULL,
        scored_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_report_user (report_id, username),
        INDEX idx_report_validation_report (report_id),
        INDEX idx_report_validation_user (username),
        INDEX idx_report_validation_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!columnExists($pdo, 'report_validations', 'scored_at')) {
        $pdo->exec("ALTER TABLE report_validations ADD COLUMN scored_at DATETIME NULL");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS comment_validations (
        id INT AUTO_INCREMENT PRIMARY KEY,
        comment_id INT NOT NULL,
        username VARCHAR(120) NOT NULL,
        validation_type ENUM('confirm','deny') NOT NULL,
        base_weight INT NOT NULL DEFAULT 2,
        effective_delta INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_comment_user (comment_id, username),
        INDEX idx_comment_validation_comment (comment_id),
        INDEX idx_comment_validation_user (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS antifraud_events (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(120) NULL,
        event_type VARCHAR(80) NOT NULL,
        severity INT NOT NULL DEFAULT 1,
        details TEXT NULL,
        ip_hash CHAR(64) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_antifraud_user (username),
        INDEX idx_antifraud_type (event_type),
        INDEX idx_antifraud_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS urbanpoint_ledger (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(120) NOT NULL,
        amount INT NOT NULL,
        reason VARCHAR(160) NOT NULL,
        reference_type VARCHAR(40) NULL,
        reference_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ledger_user (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS rewards (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(160) NOT NULL,
        description TEXT NOT NULL,
        cost_points INT NOT NULL,
        reward_type VARCHAR(60) NOT NULL,
        category VARCHAR(60) NOT NULL DEFAULT 'cupons',
        partner VARCHAR(120) NOT NULL DEFAULT 'Agente Urbano',
        image_url VARCHAR(255) NULL,
        estimated_value DECIMAL(8,2) NOT NULL DEFAULT 0,
        inventory INT NOT NULL DEFAULT 100,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    if (!columnExists($pdo, 'rewards', 'category')) {
        $pdo->exec("ALTER TABLE rewards ADD COLUMN category VARCHAR(60) NOT NULL DEFAULT 'cupons' AFTER reward_type");
    }
    if (!columnExists($pdo, 'rewards', 'partner')) {
        $pdo->exec("ALTER TABLE rewards ADD COLUMN partner VARCHAR(120) NOT NULL DEFAULT 'Agente Urbano' AFTER category");
    }
    if (!columnExists($pdo, 'rewards', 'image_url')) {
        $pdo->exec("ALTER TABLE rewards ADD COLUMN image_url VARCHAR(255) NULL AFTER partner");
    }
    if (!columnExists($pdo, 'rewards', 'estimated_value')) {
        $pdo->exec("ALTER TABLE rewards ADD COLUMN estimated_value DECIMAL(8,2) NOT NULL DEFAULT 0 AFTER image_url");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        username VARCHAR(120) PRIMARY KEY,
        password_hash VARCHAR(255) NOT NULL,
        email VARCHAR(180) DEFAULT NULL,
        auth_source VARCHAR(50) NOT NULL DEFAULT 'local',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        last_login_at DATETIME NULL,
        INDEX idx_users_email (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS reward_redemptions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        reward_id INT NOT NULL,
        username VARCHAR(120) NOT NULL,
        code VARCHAR(40) NOT NULL UNIQUE,
        status VARCHAR(30) NOT NULL DEFAULT 'ativo',
        redeemed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_redemptions_user (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("INSERT INTO rewards (title, description, cost_points, reward_type, category, partner, image_url, estimated_value, inventory)
        SELECT 'Cupom parceiro local', 'Codigo unico para desconto em comercio parceiro cadastrado.', 120, 'cupom', 'cupons', 'Comercio Local', 'https://images.unsplash.com/photo-1607082348824-0a96f2a4b9da?auto=format&fit=crop&w=900&q=80', 10, 100
        WHERE NOT EXISTS (SELECT 1 FROM rewards WHERE title = 'Cupom parceiro local')");
    $pdo->exec("INSERT INTO rewards (title, description, cost_points, reward_type, category, partner, image_url, estimated_value, inventory)
        SELECT 'Beneficio mobilidade', 'Credito simbolico para campanhas de mobilidade urbana.', 220, 'beneficio', 'servicos', 'Mobilidade Parceira', 'https://images.unsplash.com/photo-1519003722824-194d4455a60c?auto=format&fit=crop&w=900&q=80', 18, 50
        WHERE NOT EXISTS (SELECT 1 FROM rewards WHERE title = 'Beneficio mobilidade')");
    $pdo->exec("INSERT INTO rewards (title, description, cost_points, reward_type, category, partner, image_url, estimated_value, inventory)
        SELECT 'Selo Cidadao Ouro', 'Selo publico de destaque mensal no leaderboard.', 350, 'selo', 'servicos', 'Agente Urbano', 'https://images.unsplash.com/photo-1567427017947-545c5f8d16ad?auto=format&fit=crop&w=900&q=80', 0, 25
        WHERE NOT EXISTS (SELECT 1 FROM rewards WHERE title = 'Selo Cidadao Ouro')");
    $pdo->exec("INSERT INTO rewards (title, description, cost_points, reward_type, category, partner, image_url, estimated_value, inventory)
        SELECT 'Frete gratis urbano', 'Entrega sem custo em compras elegiveis de parceiros do bairro.', 80, 'cupom', 'cupons', 'Loja Bairro+', 'https://images.unsplash.com/photo-1580674285054-bed31e145f59?auto=format&fit=crop&w=900&q=80', 12, 80
        WHERE NOT EXISTS (SELECT 1 FROM rewards WHERE title = 'Frete gratis urbano')");
    $pdo->exec("INSERT INTO rewards (title, description, cost_points, reward_type, category, partner, image_url, estimated_value, inventory)
        SELECT 'Vale cafe cidadao', 'Voucher para alimentacao em cafeteria parceira apos boas contribuicoes.', 160, 'voucher', 'alimentacao', 'Cafe Central', 'https://images.unsplash.com/photo-1495474472287-4d71bcdd2085?auto=format&fit=crop&w=900&q=80', 20, 60
        WHERE NOT EXISTS (SELECT 1 FROM rewards WHERE title = 'Vale cafe cidadao')");
    $pdo->exec("INSERT INTO rewards (title, description, cost_points, reward_type, category, partner, image_url, estimated_value, inventory)
        SELECT 'Kit eco urbano', 'Ecobag e squeeze para usuarios engajados em zeladoria urbana.', 500, 'produto', 'produtos', 'Verde Urbano', 'https://images.unsplash.com/photo-1542601906990-b4d3fb778b09?auto=format&fit=crop&w=900&q=80', 45, 30
        WHERE NOT EXISTS (SELECT 1 FROM rewards WHERE title = 'Kit eco urbano')");
    $pdo->exec("INSERT INTO rewards (title, description, cost_points, reward_type, category, partner, image_url, estimated_value, inventory)
        SELECT 'Camiseta Agente Urbano', 'Camiseta exclusiva para quem ajuda a melhorar a cidade.', 650, 'produto', 'vestuario', 'Agente Urbano', 'https://images.unsplash.com/photo-1521572163474-6864f9cf17ab?auto=format&fit=crop&w=900&q=80', 55, 25
        WHERE NOT EXISTS (SELECT 1 FROM rewards WHERE title = 'Camiseta Agente Urbano')");
    $pdo->exec("INSERT INTO rewards (title, description, cost_points, reward_type, category, partner, image_url, estimated_value, inventory)
        SELECT 'Suporte tecnico prioritario', 'Atendimento prioritario para configurar alertas e preferencias da plataforma.', 250, 'servico', 'tecnologia', 'Agente Urbano Labs', 'https://images.unsplash.com/photo-1516321318423-f06f85e504b3?auto=format&fit=crop&w=900&q=80', 30, 40
        WHERE NOT EXISTS (SELECT 1 FROM rewards WHERE title = 'Suporte tecnico prioritario')");

    $pdo->exec("UPDATE rewards SET category = 'cupons', partner = 'Comercio Local', image_url = COALESCE(image_url, 'https://images.unsplash.com/photo-1607082348824-0a96f2a4b9da?auto=format&fit=crop&w=900&q=80'), estimated_value = IF(estimated_value = 0, 10, estimated_value) WHERE title = 'Cupom parceiro local'");
    $pdo->exec("UPDATE rewards SET category = 'servicos', partner = 'Mobilidade Parceira', image_url = COALESCE(image_url, 'https://images.unsplash.com/photo-1519003722824-194d4455a60c?auto=format&fit=crop&w=900&q=80'), estimated_value = IF(estimated_value = 0, 18, estimated_value) WHERE title = 'Beneficio mobilidade'");
    $pdo->exec("UPDATE rewards SET category = 'servicos', partner = 'Agente Urbano', image_url = COALESCE(image_url, 'https://images.unsplash.com/photo-1567427017947-545c5f8d16ad?auto=format&fit=crop&w=900&q=80') WHERE title = 'Selo Cidadao Ouro'");

    migrateUsersJsonToDb($pdo);
}

function clampInt($value, $min = 0, $max = 100) {
    return max($min, min($max, (int)round($value)));
}

function ensureGameProfile($pdo, $username) {
    if (!$username) return null;
    $stmt = $pdo->prepare("INSERT IGNORE INTO user_gamification (username) VALUES (?)");
    $stmt->execute([$username]);
    $stmt = $pdo->prepare("SELECT * FROM user_gamification WHERE username = ?");
    $stmt->execute([$username]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function fetchDbUser($pdo, $username) {
    if (!$username) return null;
    $stmt = $pdo->prepare("SELECT username, password_hash, email, auth_source FROM users WHERE username = ?");
    $stmt->execute([$username]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function migrateUsersJsonToDb($pdo) {
    $file = __DIR__ . '/users.json';
    if (!file_exists($file)) {
        return;
    }
    $data = @json_decode(@file_get_contents($file), true);
    if (!is_array($data)) {
        return;
    }
    $stmt = $pdo->prepare("INSERT IGNORE INTO users (username, password_hash, created_at, updated_at) VALUES (?, ?, ?, ?)");
    foreach ($data as $username => $user) {
        if (!is_string($username) || empty($username)) {
            continue;
        }
        $passwordHash = is_string($user['password'] ?? '') ? $user['password'] : '';
        $createdAt = is_string($user['created_at'] ?? '') ? $user['created_at'] : date('Y-m-d H:i:s');
        $updatedAt = is_string($user['password_updated_at'] ?? '') ? $user['password_updated_at'] : $createdAt;
        if ($passwordHash === '') {
            continue;
        }
        $stmt->execute([$username, $passwordHash, $createdAt, $updatedAt]);
    }
}

function getValidationWeight($rank) {
    if ($rank < 30) return 2;
    if ($rank < 70) return 5;
    return 10;
}

function haversineMeters($lat1, $lon1, $lat2, $lon2) {
    if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null) return null;
    $earth = 6371000;
    $dLat = deg2rad((float)$lat2 - (float)$lat1);
    $dLon = deg2rad((float)$lon2 - (float)$lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad((float)$lat1)) * cos(deg2rad((float)$lat2)) * sin($dLon / 2) ** 2;
    return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function addXpAndPoints($pdo, $username, $xp, $points, $reason, $referenceType = null, $referenceId = null) {
    if (!$username) return;
    ensureGameProfile($pdo, $username);
    $xp = (int)$xp;
    $points = (int)$points;
    $stmt = $pdo->prepare("UPDATE user_gamification
        SET xp = GREATEST(0, xp + ?),
            urban_points = GREATEST(0, urban_points + ?),
            participation_count = participation_count + IF(? > 0, 1, 0),
            monthly_score = GREATEST(0, monthly_score + (? / 4) + ?),
            level_num = GREATEST(1, FLOOR(SQRT(GREATEST(0, xp + ?) / 100)) + 1),
            last_activity_at = NOW()
        WHERE username = ?");
    $stmt->execute([$xp, $points, $xp, $xp, $points, $xp, $username]);
    if ($points !== 0) {
        $stmt = $pdo->prepare("INSERT INTO urbanpoint_ledger (username, amount, reason, reference_type, reference_id) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$username, $points, $reason, $referenceType, $referenceId]);
    }
    recalcUserRank($pdo, $username);
}

function recalcUserRank($pdo, $username) {
    ensureGameProfile($pdo, $username);
    $stmt = $pdo->prepare("SELECT * FROM user_gamification WHERE username = ?");
    $stmt->execute([$username]);
    $g = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$g) return 50;
    $total = max(1, (int)$g['validations_total']);
    $accuracy = ((int)$g['validations_correct']) / $total;
    $participation = min(20, log(max(1, (int)$g['participation_count']) + 1) * 5);
    $history = min(15, (int)$g['reports_verified'] * 2 + (int)$g['validations_correct']);
    $penalty = min(35, (int)$g['suspicious_score'] * 3 + (int)$g['validations_incorrect'] * 1.5 + (int)$g['reports_invalidated'] * 5);
    $rank = clampInt(35 + ($accuracy * 45) + $participation + $history - $penalty);
    $stmt = $pdo->prepare("UPDATE user_gamification SET reliability_rank = ? WHERE username = ?");
    $stmt->execute([$rank, $username]);
    return $rank;
}

function logFraudEvent($pdo, $username, $type, $severity, $details = '') {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $hash = hash('sha256', $ip);
    $stmt = $pdo->prepare("INSERT INTO antifraud_events (username, event_type, severity, details, ip_hash) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$username, $type, (int)$severity, $details, $hash]);
    if ($username) {
        ensureGameProfile($pdo, $username);
        $stmt = $pdo->prepare("UPDATE user_gamification SET suspicious_score = suspicious_score + ? WHERE username = ?");
        $stmt->execute([(int)$severity, $username]);
        recalcUserRank($pdo, $username);
    }
}

function runAntifraudChecks($pdo, $username, $kind) {
    if (!$username) return;
    $table = 'report_validations';
    $field = 'username';
    $dateField = 'created_at';
    $limit = 20;
    $eventType = 'mass_validations';

    if ($kind === 'report') {
        $table = 'relatorios';
        $field = 'user_id';
        $dateField = 'data_criacao';
        $limit = 5;
        $eventType = 'spam_reports';
    } elseif ($kind === 'comment') {
        $table = 'comment_validations';
        $field = 'username';
        $dateField = 'created_at';
        $limit = 20;
        $eventType = 'mass_comment_validations';
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE $field = ? AND $dateField >= DATE_SUB(NOW(), INTERVAL 1 HOUR)");
    $stmt->execute([$username]);
    $count = (int)$stmt->fetchColumn();
    if ($count > $limit) {
        logFraudEvent($pdo, $username, $eventType, 4, "Volume em 1h: $count");
    }
    $ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT username) FROM antifraud_events WHERE ip_hash = ? AND username IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stmt->execute([$ip]);
    if ((int)$stmt->fetchColumn() >= 3) {
        logFraudEvent($pdo, $username, 'duplicate_account_pattern', 3, 'Mesmo IP associado a muitas contas/eventos recentes.');
    }
}

function recalcReportVeracity($pdo, $reportId) {
    $stmt = $pdo->prepare("SELECT id, user_id, veracidade, status, invalidated_penalties_applied FROM relatorios WHERE id = ?");
    $stmt->execute([$reportId]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$report) return null;

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(effective_delta),0) FROM report_validations WHERE report_id = ?");
    $stmt->execute([$reportId]);
    $veracity = clampInt(50 + (int)$stmt->fetchColumn());
    $status = $veracity >= 80 ? 'Verificado' : ($veracity <= 20 ? 'Invalidado' : 'Em análise');

    $stmt = $pdo->prepare("UPDATE relatorios SET veracidade = ?, status = ?, data_atualizacao = NOW() WHERE id = ?");
    $stmt->execute([$veracity, $status, $reportId]);

    $stmt = $pdo->prepare("UPDATE report_validations SET was_correct = CASE
        WHEN ? = 'Verificado' AND validation_type = 'confirm' THEN 1
        WHEN ? = 'Invalidado' AND validation_type = 'deny' THEN 1
        WHEN ? IN ('Verificado','Invalidado') THEN 0
        ELSE NULL END
        WHERE report_id = ? AND scored_at IS NULL");
    $stmt->execute([$status, $status, $status, $reportId]);

    if (in_array($status, ['Verificado', 'Invalidado'], true)) {
        $stmt = $pdo->prepare("SELECT id, username, was_correct FROM report_validations WHERE report_id = ? AND was_correct IS NOT NULL AND scored_at IS NULL");
        $stmt->execute([$reportId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            ensureGameProfile($pdo, $row['username']);
            if ((int)$row['was_correct'] === 1) {
                $pdo->prepare("UPDATE user_gamification SET validations_correct = validations_correct + 1 WHERE username = ?")->execute([$row['username']]);
                addXpAndPoints($pdo, $row['username'], 20, 8, 'Validação correta', 'report', $reportId);
            } else {
                $pdo->prepare("UPDATE user_gamification SET validations_incorrect = validations_incorrect + 1 WHERE username = ?")->execute([$row['username']]);
                addXpAndPoints($pdo, $row['username'], -8, -3, 'Validação incorreta', 'report', $reportId);
            }
            $pdo->prepare("UPDATE report_validations SET scored_at = NOW() WHERE id = ?")->execute([$row['id']]);
        }
    }

    if ($status === 'Invalidado' && (int)$report['invalidated_penalties_applied'] === 0) {
        $author = $report['user_id'] ?? null;
        if ($author && $author !== 'anonimo') {
            ensureGameProfile($pdo, $author);
            $pdo->prepare("UPDATE user_gamification SET reports_invalidated = reports_invalidated + 1 WHERE username = ?")->execute([$author]);
            addXpAndPoints($pdo, $author, -25, -12, 'Relatório invalidado', 'report', $reportId);
        }
        $pdo->prepare("UPDATE relatorios SET invalidated_penalties_applied = 1 WHERE id = ?")->execute([$reportId]);
    } elseif ($status === 'Verificado') {
        $author = $report['user_id'] ?? null;
        if ($author && $author !== 'anonimo') {
            ensureGameProfile($pdo, $author);
            $pdo->prepare("UPDATE user_gamification SET reports_verified = reports_verified + 1 WHERE username = ?")->execute([$author]);
            addXpAndPoints($pdo, $author, 35, 15, 'Relatório verificado', 'report', $reportId);
        }
    }

    return ['veracidade' => $veracity, 'status' => $status];
}

function recalcCommentVeracity($pdo, $commentId) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(effective_delta),0) FROM comment_validations WHERE comment_id = ?");
    $stmt->execute([$commentId]);
    $veracity = clampInt(50 + (int)$stmt->fetchColumn());
    $stmt = $pdo->prepare("UPDATE comentarios SET veracidade = ? WHERE id = ?");
    $stmt->execute([$veracity, $commentId]);
    return $veracity;
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
        $sql = "INSERT INTO comentarios (report_id, user_name, comment_text, veracidade, created_at) VALUES (?, ?, ?, 50, NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$reportId, $userName, $commentText]);
        addXpAndPoints($pdo, $userName, 8, 2, 'Comentário enviado', 'report', $reportId);

        echo json_encode(['success' => true, 'message' => 'Comentário salvo com sucesso.', 'author' => $userName, 'veracidade' => 50]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro ao salvar o comentário: ' . $e->getMessage()]);
    }
}

function validateReportHandler($pdo) {
    $username = getCurrentUser();
    if (!$username) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Você precisa estar logado para validar.']);
        return;
    }
    $reportId = filter_input(INPUT_POST, 'report_id', FILTER_VALIDATE_INT);
    $type = $_POST['validation_type'] ?? '';
    $comment = trim($_POST['comment_text'] ?? '');
    $lat = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? filter_var($_POST['latitude'], FILTER_VALIDATE_FLOAT) : null;
    $lng = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? filter_var($_POST['longitude'], FILTER_VALIDATE_FLOAT) : null;
    if (!$reportId || !in_array($type, ['confirm', 'deny'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Relatório e tipo de validação são obrigatórios.']);
        return;
    }

    try {
        runAntifraudChecks($pdo, $username, 'validation');
        $profile = ensureGameProfile($pdo, $username);
        $weight = getValidationWeight((float)$profile['reliability_rank']);
        $stmt = $pdo->prepare("SELECT latitude, longitude, user_id FROM relatorios WHERE id = ?");
        $stmt->execute([$reportId]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$report) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Relatório não encontrado.']);
            return;
        }
        if (($report['user_id'] ?? '') === $username) {
            logFraudEvent($pdo, $username, 'self_validation_attempt', 2, "Relatório $reportId");
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Você não pode validar o próprio relatório.']);
            return;
        }
        $distance = ($lat !== false && $lng !== false) ? haversineMeters($report['latitude'], $report['longitude'], $lat, $lng) : null;
        $geoBonus = ($distance !== null && $distance <= 60) ? 15 : 0;
        $delta = ($type === 'confirm' ? 1 : -1) * ($weight + $geoBonus);

        $stmtExists = $pdo->prepare("SELECT COUNT(*) FROM report_validations WHERE report_id = ? AND username = ?");
        $stmtExists->execute([$reportId, $username]);
        $isNewValidation = ((int)$stmtExists->fetchColumn()) === 0;

        $stmt = $pdo->prepare("INSERT INTO report_validations
            (report_id, username, validation_type, comment_text, user_latitude, user_longitude, distance_meters, base_weight, geo_bonus, effective_delta)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE validation_type = VALUES(validation_type), comment_text = VALUES(comment_text),
            user_latitude = VALUES(user_latitude), user_longitude = VALUES(user_longitude), distance_meters = VALUES(distance_meters),
            base_weight = VALUES(base_weight), geo_bonus = VALUES(geo_bonus), effective_delta = VALUES(effective_delta), updated_at = NOW()");
        $stmt->execute([$reportId, $username, $type, $comment, $lat, $lng, $distance, $weight, $geoBonus, $delta]);

        if ($isNewValidation) {
            $pdo->prepare("UPDATE user_gamification SET validations_total = validations_total + 1 WHERE username = ?")->execute([$username]);
        }
        addXpAndPoints($pdo, $username, 12, 4, 'Validação registrada', 'report', $reportId);
        $result = recalcReportVeracity($pdo, $reportId);

        echo json_encode([
            'success' => true,
            'message' => 'Validação registrada.',
            'weight' => $weight,
            'geo_bonus' => $geoBonus,
            'distance_meters' => $distance !== null ? round($distance, 2) : null,
            'report' => $result
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro ao validar relatório: ' . $e->getMessage()]);
    }
}

function validateCommentHandler($pdo) {
    $username = getCurrentUser();
    if (!$username) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Você precisa estar logado para validar comentários.']);
        return;
    }
    $commentId = filter_input(INPUT_POST, 'comment_id', FILTER_VALIDATE_INT);
    $type = $_POST['validation_type'] ?? '';
    if (!$commentId || !in_array($type, ['confirm', 'deny'], true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Comentário e tipo de validação são obrigatórios.']);
        return;
    }

    try {
        runAntifraudChecks($pdo, $username, 'comment');
        $profile = ensureGameProfile($pdo, $username);
        $weight = getValidationWeight((float)$profile['reliability_rank']);
        $delta = ($type === 'confirm' ? 1 : -1) * $weight;
        $stmtExists = $pdo->prepare("SELECT COUNT(*) FROM comment_validations WHERE comment_id = ? AND username = ?");
        $stmtExists->execute([$commentId, $username]);
        $isNewValidation = ((int)$stmtExists->fetchColumn()) === 0;

        $stmt = $pdo->prepare("INSERT INTO comment_validations (comment_id, username, validation_type, base_weight, effective_delta)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE validation_type = VALUES(validation_type), base_weight = VALUES(base_weight), effective_delta = VALUES(effective_delta), updated_at = NOW()");
        $stmt->execute([$commentId, $username, $type, $weight, $delta]);
        if ($isNewValidation) {
            $pdo->prepare("UPDATE user_gamification SET validations_total = validations_total + 1 WHERE username = ?")->execute([$username]);
        }
        addXpAndPoints($pdo, $username, 6, 2, 'Validação de comentário', 'comment', $commentId);
        $veracity = recalcCommentVeracity($pdo, $commentId);
        echo json_encode(['success' => true, 'message' => 'Comentário validado.', 'veracidade' => $veracity, 'weight' => $weight]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro ao validar comentário: ' . $e->getMessage()]);
    }
}

/**
 * registerUserHandler
 * Registra um usuário no banco de dados `users`.
 *
 * Observações:
 * - Armazena hash de senha com `password_hash`.
 * - A tabela de usuários é criada automaticamente em `ensureGamificationSchema`.
 */

function registerUserHandler($pdo) {
    $input = $_POST;
    $username = trim($input['username'] ?? '');
    $password = $input['password'] ?? '';
    if ($username === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Username e senha são obrigatórios.']);
        return;
    }
    if (strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A senha deve ter no mínimo 6 caracteres.']);
        return;
    }
    if (!preg_match('/^[\p{L}0-9 _.-]{3,120}$/u', $username)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Nome de usuário inválido. Use letras, números e alguns símbolos.']);
        return;
    }

    $exists = fetchDbUser($pdo, $username);
    if ($exists) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Usuário já existe.']);
        return;
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (?, ?)");
    $stmt->execute([$username, $hash]);
    ensureGameProfile($pdo, $username);
    session_regenerate_id(true);
    $_SESSION['username'] = $username;
    echo json_encode(['success' => true, 'message' => 'Registrado com sucesso.', 'username' => $username]);
}

function loginUserHandler($pdo) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $user = fetchDbUser($pdo, $username);
    if (!$user || !password_verify($password, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Usuário ou senha inválidos.']);
        return;
    }
    $stmt = $pdo->prepare("UPDATE users SET last_login_at = NOW() WHERE username = ?");
    $stmt->execute([$username]);
    session_regenerate_id(true);
    $_SESSION['username'] = $username;
    echo json_encode(['success' => true, 'message' => 'Login bem-sucedido.', 'username' => $username]);
}

function changePasswordHandler($pdo) {
    $username = getCurrentUser();
    if (!$username) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Você precisa estar logado para alterar a senha.']);
        return;
    }

    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';

    if ($currentPassword === '' || $newPassword === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Preencha a senha atual e a nova senha.']);
        return;
    }

    if (strlen($newPassword) < 6) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A nova senha deve ter pelo menos 6 caracteres.']);
        return;
    }

    if ($currentPassword === $newPassword) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'A nova senha deve ser diferente da senha atual.']);
        return;
    }

    $user = fetchDbUser($pdo, $username);
    if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Senha atual incorreta.']);
        return;
    }

    $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = NOW() WHERE username = ?");
    $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $username]);
    echo json_encode(['success' => true, 'message' => 'Senha alterada com sucesso.']);
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

        $stmt = $pdo->query("SELECT id, titulo, latitude, longitude, tipo, descricao, status, veracidade, imagem_url, prioridade, endereco, data_criacao FROM relatorios ORDER BY data_criacao DESC");
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
        $sql = "SELECT id, titulo, latitude, longitude, tipo, descricao, status, veracidade,
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
        $stmt = $pdo->prepare("SELECT id, user_name AS author, comment_text AS text, veracidade, created_at AS date FROM comentarios WHERE report_id = ? ORDER BY created_at DESC");
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
    
    $status = 'Em análise';

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

        $sql = "INSERT INTO relatorios (titulo, latitude, longitude, tipo, descricao, status, veracidade, data_criacao, imagem_url, prioridade, endereco, user_id)
                VALUES (?, ?, ?, ?, ?, ?, 50, NOW(), ?, ?, ?, ?)";
        
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
        if ($user_id && $user_id !== 'anonimo') {
            ensureGameProfile($pdo, $user_id);
            $pdo->prepare("UPDATE user_gamification SET reports_created = reports_created + 1 WHERE username = ?")->execute([$user_id]);
            addXpAndPoints($pdo, $user_id, 25, 10, 'Relatório criado', 'report', (int)$lastId);
            runAntifraudChecks($pdo, $user_id, 'report');
        }
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

        $stmt_users = $pdo->query("SELECT COUNT(*) FROM users");
        $total_users = $stmt_users->fetchColumn();

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
            'Verificado' => 0,
            'Invalidado' => 0,
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

function getMonthlyTier($profile) {
    $score = (float)($profile['monthly_score'] ?? 0);
    $rank = (float)($profile['reliability_rank'] ?? 0);
    if ($score >= 250 && $rank >= 75) return 'Ouro';
    if ($score >= 120 && $rank >= 55) return 'Prata';
    return 'Bronze';
}

function getUserDashboardHandler($pdo) {
    $username = getCurrentUser();
    if (!$username) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Autenticação necessária.']);
        return;
    }
    $profile = ensureGameProfile($pdo, $username);
    $level = max(1, (int)$profile['level_num']);
    $currentLevelXp = ($level - 1) * ($level - 1) * 100;
    $nextLevelXp = $level * $level * 100;
    $xp = (int)$profile['xp'];
    $progress = $nextLevelXp > $currentLevelXp ? clampInt((($xp - $currentLevelXp) / ($nextLevelXp - $currentLevelXp)) * 100) : 0;
    echo json_encode([
        'success' => true,
        'username' => $username,
        'profile' => [
            'xp' => $xp,
            'level' => $level,
            'xp_progress' => $progress,
            'next_level_xp' => $nextLevelXp,
            'urban_points' => (int)$profile['urban_points'],
            'reliability_rank' => (float)$profile['reliability_rank'],
            'monthly_tier' => getMonthlyTier($profile),
            'participation_count' => (int)$profile['participation_count'],
            'validations_total' => (int)$profile['validations_total'],
            'validations_correct' => (int)$profile['validations_correct'],
            'validations_incorrect' => (int)$profile['validations_incorrect'],
            'reports_created' => (int)$profile['reports_created'],
            'reports_verified' => (int)$profile['reports_verified'],
            'reports_invalidated' => (int)$profile['reports_invalidated'],
            'suspicious_score' => (int)$profile['suspicious_score']
        ]
    ]);
}

function leaderboardHandler($pdo) {
    $tier = $_GET['tier'] ?? 'all';
    $period = $_GET['period'] ?? 'global';
    $order = $period === 'monthly' ? 'monthly_score' : 'reliability_rank';
    $stmt = $pdo->query("SELECT username, xp, level_num, urban_points, reliability_rank, participation_count, validations_total, validations_correct, monthly_score
        FROM user_gamification ORDER BY $order DESC, validations_correct DESC, participation_count DESC LIMIT 100");
    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $index => $row) {
        $row['position'] = $index + 1;
        $row['monthly_tier'] = getMonthlyTier($row);
        if ($tier === 'all' || mb_strtolower($row['monthly_tier']) === mb_strtolower($tier)) {
            $row['accuracy'] = (int)$row['validations_total'] > 0 ? round(((int)$row['validations_correct'] / (int)$row['validations_total']) * 100, 1) : 0;
            $rows[] = $row;
        }
    }
    echo json_encode(['success' => true, 'period' => $period, 'tier' => $tier, 'leaders' => $rows]);
}

function rewardsHandler($pdo) {
    $stmt = $pdo->query("SELECT id, title, description, cost_points, reward_type, category, partner, image_url, estimated_value, inventory FROM rewards WHERE active = 1 ORDER BY partner ASC, cost_points ASC");
    echo json_encode(['success' => true, 'rewards' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function rewardCenterHandler($pdo) {
    $username = getCurrentUser();
    if (!$username) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'AutenticaÃ§Ã£o necessÃ¡ria.']);
        return;
    }

    $profile = ensureGameProfile($pdo, $username);
    $points = (int)$profile['urban_points'];
    $level = max(1, (int)$profile['level_num']);
    $currentLevelXp = ($level - 1) * ($level - 1) * 100;
    $nextLevelXp = $level * $level * 100;
    $xp = (int)$profile['xp'];
    $xpProgress = $nextLevelXp > $currentLevelXp ? clampInt((($xp - $currentLevelXp) / ($nextLevelXp - $currentLevelXp)) * 100) : 0;

    $rewardStmt = $pdo->query("SELECT id, title, description, cost_points, reward_type, category, partner, image_url, estimated_value, inventory
        FROM rewards WHERE active = 1 ORDER BY partner ASC, cost_points ASC");
    $rewards = $rewardStmt->fetchAll(PDO::FETCH_ASSOC);

    $nextReward = null;
    foreach ($rewards as $reward) {
        if ((int)$reward['cost_points'] > $points && (!$nextReward || (int)$reward['cost_points'] < (int)$nextReward['cost_points'])) {
            $nextReward = $reward;
        }
    }

    $redemptionStmt = $pdo->prepare("SELECT COUNT(*) AS total, COALESCE(SUM(r.estimated_value),0) AS savings
        FROM reward_redemptions rr
        INNER JOIN rewards r ON r.id = rr.reward_id
        WHERE rr.username = ?");
    $redemptionStmt->execute([$username]);
    $redemptionStats = $redemptionStmt->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'savings' => 0];

    $historyStmt = $pdo->prepare("SELECT amount, reason, reference_type, reference_id, created_at
        FROM urbanpoint_ledger
        WHERE username = ?
        ORDER BY created_at DESC
        LIMIT 20");
    $historyStmt->execute([$username]);

    echo json_encode([
        'success' => true,
        'username' => $username,
        'profile' => [
            'xp' => $xp,
            'level' => $level,
            'xp_progress' => $xpProgress,
            'next_level_xp' => $nextLevelXp,
            'urban_points' => $points,
            'reliability_rank' => (float)$profile['reliability_rank'],
            'monthly_tier' => getMonthlyTier($profile),
            'participation_count' => (int)$profile['participation_count'],
            'validations_total' => (int)$profile['validations_total'],
            'validations_correct' => (int)$profile['validations_correct'],
            'reports_created' => (int)$profile['reports_created'],
            'reports_verified' => (int)$profile['reports_verified']
        ],
        'summary' => [
            'redemptions_count' => (int)$redemptionStats['total'],
            'total_savings' => (float)$redemptionStats['savings'],
            'next_reward' => $nextReward,
            'points_to_next_reward' => $nextReward ? max(0, (int)$nextReward['cost_points'] - $points) : 0
        ],
        'rewards' => $rewards,
        'history' => $historyStmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
}

function redeemRewardHandler($pdo) {
    $username = getCurrentUser();
    if (!$username) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Autenticação necessária.']);
        return;
    }
    $rewardId = filter_input(INPUT_POST, 'reward_id', FILTER_VALIDATE_INT);
    if (!$rewardId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Recompensa inválida.']);
        return;
    }
    try {
        $pdo->beginTransaction();
        $profile = ensureGameProfile($pdo, $username);
        $stmt = $pdo->prepare("SELECT * FROM rewards WHERE id = ? AND active = 1 FOR UPDATE");
        $stmt->execute([$rewardId]);
        $reward = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$reward || (int)$reward['inventory'] <= 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Recompensa indisponível.']);
            return;
        }
        if ((int)$profile['urban_points'] < (int)$reward['cost_points']) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'UrbanPoints insuficientes.']);
            return;
        }
        $code = 'AU-' . strtoupper(bin2hex(random_bytes(4))) . '-' . $rewardId;
        $pdo->prepare("UPDATE rewards SET inventory = inventory - 1 WHERE id = ?")->execute([$rewardId]);
        addXpAndPoints($pdo, $username, 0, -((int)$reward['cost_points']), 'Resgate: ' . $reward['title'], 'reward', $rewardId);
        $stmt = $pdo->prepare("INSERT INTO reward_redemptions (reward_id, username, code) VALUES (?, ?, ?)");
        $stmt->execute([$rewardId, $username, $code]);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Recompensa resgatada.', 'code' => $code]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro ao resgatar recompensa: ' . $e->getMessage()]);
    }
}

function antifraudSummaryHandler($pdo) {
    $stmt = $pdo->query("SELECT event_type, COUNT(*) AS total, SUM(severity) AS severity FROM antifraud_events GROUP BY event_type ORDER BY severity DESC");
    echo json_encode(['success' => true, 'events' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}


if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    
    $action = $_GET['action'] ?? '';
    
    $pdo = connectDB();
    ensureGamificationSchema($pdo);

   
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

        case 'validate_report':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') validateReportHandler($pdo);
            else { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Use POST']); }
            break;

        case 'validate_comment':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') validateCommentHandler($pdo);
            else { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Use POST']); }
            break;
        
        case 'add_vote':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                addVoteHandler($pdo);
            } else {
                http_response_code(405); echo json_encode(['success' => false, 'message' => 'Use POST']);
            }
            break;
        
        case 'register':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') registerUserHandler($pdo);
            else { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Use POST']); }
            break;
        case 'login':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') loginUserHandler($pdo);
            else { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Use POST']); }
            break;
        case 'change_password':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') changePasswordHandler($pdo);
            else { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Use POST']); }
            break;
        case 'logout':
            logoutUserHandler();
            break;
        case 'current_user':
            $username = getCurrentUser();
            if ($username) ensureGameProfile($pdo, $username);
            echo json_encode(['username' => $username]);
            break;
            
        case 'get_stats':
            getStatsHandler($pdo);
            break;
        
        case 'reports_over_time':
            getReportsOverTimeHandler($pdo);
            break;

        case 'user_dashboard':
            getUserDashboardHandler($pdo);
            break;

        case 'leaderboard':
            leaderboardHandler($pdo);
            break;

        case 'rewards':
            rewardsHandler($pdo);
            break;

        case 'reward_center':
            rewardCenterHandler($pdo);
            break;

        case 'redeem_reward':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') redeemRewardHandler($pdo);
            else { http_response_code(405); echo json_encode(['success' => false, 'message' => 'Use POST']); }
            break;

        case 'antifraud_summary':
            antifraudSummaryHandler($pdo);
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
