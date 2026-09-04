<?php

/**
 * api.php
 * Endpoint único (front controller) para operações AJAX da aplicação Agente Urbano.
 *
 * Responsabilidades principais:
 * - Fornecer handlers para CRUD de relatórios, autenticação (usuários e senhas
 *   armazenados na tabela `users` do MySQL, com password_hash/password_verify),
 *   comentários e votos.
 * - Controlar a propriedade de relatórios (quem pode editar/excluir) via a
 *   tabela `report_owners` do MySQL.
 * - Lidar com upload seguro de imagens (diretório `uploads/`) em criação/edição.
 * - Retornar JSON consistente para o front-end.
 *
 * Segurança e observações de manutenção:
 * - Verifique permissões do diretório `uploads/` e limite tamanho/tipo de arquivos no PHP.
 */

ob_start(); // bufferiza toda a saída para evitar que warnings rompam JSON
error_reporting(E_ERROR | E_PARSE);
set_error_handler(function ($errno, $errstr, $errfile, $errline) {
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

function connectDB()
{
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

function columnExists($pdo, $table, $column)
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function tableExists($pdo, $table)
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int) $stmt->fetchColumn() > 0;
}

function ensureGamificationSchema($pdo)
{
    if (!columnExists($pdo, 'relatorios', 'veracidade')) {
        $pdo->exec("ALTER TABLE relatorios ADD COLUMN veracidade INT NOT NULL DEFAULT 50");
    }
    if (!columnExists($pdo, 'relatorios', 'invalidated_penalties_applied')) {
        $pdo->exec("ALTER TABLE relatorios ADD COLUMN invalidated_penalties_applied TINYINT(1) NOT NULL DEFAULT 0");
    }
    if (!columnExists($pdo, 'relatorios', 'verified_reward_applied')) {
        $pdo->exec("ALTER TABLE relatorios ADD COLUMN verified_reward_applied TINYINT(1) NOT NULL DEFAULT 0");
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

    if (!columnExists($pdo, 'user_gamification', 'punishment_strikes')) {
        $pdo->exec("ALTER TABLE user_gamification ADD COLUMN punishment_strikes INT NOT NULL DEFAULT 0");
    }
    if (!columnExists($pdo, 'user_gamification', 'blocked_until')) {
        $pdo->exec("ALTER TABLE user_gamification ADD COLUMN blocked_until DATETIME NULL");
    }
    if (!columnExists($pdo, 'user_gamification', 'banned_permanently')) {
        $pdo->exec("ALTER TABLE user_gamification ADD COLUMN banned_permanently TINYINT(1) NOT NULL DEFAULT 0");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS reputation_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(120) NOT NULL,
        delta INT NOT NULL,
        reason VARCHAR(160) NOT NULL,
        reference_type VARCHAR(40) NULL,
        reference_id INT NULL,
        resulting_rank DECIMAL(5,2) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_reputation_log_user (username)
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

    $pdo->exec("CREATE TABLE IF NOT EXISTS report_owners (
        report_id INT NOT NULL PRIMARY KEY,
        username VARCHAR(120) NOT NULL,
        claimed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_report_owners_user (username),
        CONSTRAINT fk_report_owners_report FOREIGN KEY (report_id) REFERENCES relatorios (id) ON DELETE CASCADE
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

}

function clampInt($value, $min = 0, $max = 100)
{
    return max($min, min($max, (int) round($value)));
}

function ensureGameProfile($pdo, $username)
{
    if (!$username)
        return null;
    $stmt = $pdo->prepare("INSERT IGNORE INTO user_gamification (username) VALUES (?)");
    $stmt->execute([$username]);
    $stmt = $pdo->prepare("SELECT * FROM user_gamification WHERE username = ?");
    $stmt->execute([$username]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function fetchDbUser($pdo, $username)
{
    if (!$username)
        return null;
    $stmt = $pdo->prepare("SELECT username, password_hash, email, auth_source FROM users WHERE username = ?");
    $stmt->execute([$username]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}


function getValidationWeight($rank)
{
    if ($rank < 30)
        return 2;
    if ($rank < 70)
        return 5;
    return 10;
}

function haversineMeters($lat1, $lon1, $lat2, $lon2)
{
    if ($lat1 === null || $lon1 === null || $lat2 === null || $lon2 === null)
        return null;
    $earth = 6371000;
    $dLat = deg2rad((float) $lat2 - (float) $lat1);
    $dLon = deg2rad((float) $lon2 - (float) $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad((float) $lat1)) * cos(deg2rad((float) $lat2)) * sin($dLon / 2) ** 2;
    return $earth * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function addXpAndPoints($pdo, $username, $xp, $points, $reason, $referenceType = null, $referenceId = null)
{
    if (!$username)
        return;
    ensureGameProfile($pdo, $username);
    $xp = (int) $xp;
    $points = (int) $points;
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
}

function recalcUserRank($pdo, $username)
{
    ensureGameProfile($pdo, $username);
    $stmt = $pdo->prepare("SELECT reliability_rank FROM user_gamification WHERE username = ?");
    $stmt->execute([$username]);
    $rank = $stmt->fetchColumn();
    return $rank !== false ? (float) $rank : 50;
}

function adjustUserReputation($pdo, $username, $delta, $reason, $referenceType = null, $referenceId = null)
{
    if (!$username || $username === 'anonimo')
        return null;

    ensureGameProfile($pdo, $username);

    $stmt = $pdo->prepare("UPDATE user_gamification SET reliability_rank = GREATEST(0, LEAST(100, reliability_rank + ?)) WHERE username = ?");
    $stmt->execute([(int) $delta, $username]);

    $stmt = $pdo->prepare("SELECT reliability_rank FROM user_gamification WHERE username = ?");
    $stmt->execute([$username]);
    $novoRank = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare("INSERT INTO reputation_log (username, delta, reason, reference_type, reference_id, resulting_rank) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$username, (int) $delta, $reason, $referenceType, $referenceId, $novoRank]);

    checkAndApplyPunishment($pdo, $username, $novoRank);

    return $novoRank;
}

function checkAndApplyPunishment($pdo, $username, $currentRank)
{
    $stmt = $pdo->prepare("SELECT punishment_strikes, blocked_until, banned_permanently FROM user_gamification WHERE username = ?");
    $stmt->execute([$username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row)
        return;

    if ((int) $row['banned_permanently'] === 1)
        return; 

    if ((float) $currentRank >= 25)
        return; 

    $strikes = (int) $row['punishment_strikes'];
    $blockedUntil = $row['blocked_until'];
    $currentlyBlocked = $blockedUntil && strtotime($blockedUntil) > time();

    if ($currentlyBlocked) {
        return;
    }

    if ($strikes === 0) {
        $stmt = $pdo->prepare("UPDATE user_gamification SET punishment_strikes = 1, blocked_until = DATE_ADD(NOW(), INTERVAL 1 MONTH) WHERE username = ?");
        $stmt->execute([$username]);
        logFraudEvent($pdo, $username, 'reputation_block_1st', 5, 'Reputação abaixo de 25 — bloqueio de 1 mês aplicado (1ª vez).');
    } else {
        $stmt = $pdo->prepare("UPDATE user_gamification SET punishment_strikes = punishment_strikes + 1, banned_permanently = 1 WHERE username = ?");
        $stmt->execute([$username]);
        logFraudEvent($pdo, $username, 'reputation_ban_permanent', 10, 'Reputação abaixo de 25 pela 2ª vez — banimento permanente aplicado.');
    }
}

function getUserPunishmentStatus($pdo, $username)
{
    $status = ['blocked' => false, 'permanent' => false, 'until' => null, 'message' => null];
    if (!$username || $username === 'anonimo')
        return $status;

    $profile = ensureGameProfile($pdo, $username);
    if (!$profile)
        return $status;

    if ((int) ($profile['banned_permanently'] ?? 0) === 1) {
        $status['blocked'] = true;
        $status['permanent'] = true;
        $status['message'] = 'Sua conta foi banida permanentemente por reincidência de reputação abaixo de 25. Você só pode visualizar o mapa.';
        return $status;
    }

    $until = $profile['blocked_until'] ?? null;
    if ($until && strtotime($until) > time()) {
        $status['blocked'] = true;
        $status['until'] = $until;
        $status['message'] = 'Sua conta está temporariamente bloqueada até ' . date('d/m/Y', strtotime($until)) . ' devido à baixa reputação (abaixo de 25). Você só pode visualizar o mapa, sem publicar, avaliar, comentar ou apoiar relatórios.';
        return $status;
    }

    return $status;
}

function calcularPontosMensaisPorReputacao($reliabilityRank)
{
    $r = (float) $reliabilityRank;
    if ($r >= 100)
        return 3000;
    if ($r >= 90)
        return 2000;
    if ($r >= 80)
        return 1500;
    if ($r >= 70)
        return 1000;
    if ($r >= 60)
        return 500;
    return 0;
}

function processMonthlyReputationPayout($pdo, $username)
{
    if (!$username || $username === 'anonimo')
        return;

    $referenceMonth = (int) date('Ym', strtotime('-1 month'));

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM urbanpoint_ledger WHERE username = ? AND reference_type = 'monthly_reputation' AND reference_id = ?");
    $stmt->execute([$username, $referenceMonth]);
    if ((int) $stmt->fetchColumn() > 0)
        return;

    $profile = ensureGameProfile($pdo, $username);
    $rank = (float) ($profile['reliability_rank'] ?? 50);
    $points = calcularPontosMensaisPorReputacao($rank);

    if ($points > 0) {
        addXpAndPoints($pdo, $username, 0, $points, 'Pagamento mensal por reputação (' . round($rank) . '/100)', 'monthly_reputation', $referenceMonth);
    } else {
        $stmt = $pdo->prepare("INSERT INTO urbanpoint_ledger (username, amount, reason, reference_type, reference_id) VALUES (?, 0, ?, 'monthly_reputation', ?)");
        $stmt->execute([$username, 'Pagamento mensal por reputação: sem pontos (reputação abaixo de 60)', $referenceMonth]);
    }
}

function removeInvalidatedReport($pdo, $reportId)
{
    try {
        $stmt = $pdo->prepare("SELECT imagem_url FROM relatorios WHERE id = ?");
        $stmt->execute([$reportId]);
        $imagePath = $stmt->fetchColumn();

        $pdo->prepare("DELETE FROM comentarios WHERE report_id = ?")->execute([$reportId]);
        $pdo->prepare("DELETE FROM votos WHERE report_id = ?")->execute([$reportId]);
        $pdo->prepare("DELETE FROM report_validations WHERE report_id = ?")->execute([$reportId]);
        $pdo->prepare("DELETE FROM report_owners WHERE report_id = ?")->execute([$reportId]);
        $pdo->prepare("DELETE FROM relatorios WHERE id = ?")->execute([$reportId]);

        if ($imagePath) {
            $relativePath = ltrim($imagePath, '/\\');
            $fullPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . $relativePath);
            $uploadReal = realpath(UPLOAD_DIR);
            if ($fullPath && $uploadReal && strpos($fullPath, $uploadReal) === 0 && file_exists($fullPath)) {
                @unlink($fullPath);
            }
        }
    } catch (Exception $e) {
        error_log('Erro ao remover relatório reprovado #' . $reportId . ': ' . $e->getMessage());
    }
}

function logFraudEvent($pdo, $username, $type, $severity, $details = '')
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $hash = hash('sha256', $ip);
    $stmt = $pdo->prepare("INSERT INTO antifraud_events (username, event_type, severity, details, ip_hash) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$username, $type, (int) $severity, $details, $hash]);
    if ($username) {
        ensureGameProfile($pdo, $username);
        $stmt = $pdo->prepare("UPDATE user_gamification SET suspicious_score = suspicious_score + ? WHERE username = ?");
        $stmt->execute([(int) $severity, $username]);
    }
}

function runAntifraudChecks($pdo, $username, $kind)
{
    if (!$username)
        return;
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
    $count = (int) $stmt->fetchColumn();
    if ($count > $limit) {
        logFraudEvent($pdo, $username, $eventType, 4, "Volume em 1h: $count");
    }
    $ip = hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT username) FROM antifraud_events WHERE ip_hash = ? AND username IS NOT NULL AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stmt->execute([$ip]);
    if ((int) $stmt->fetchColumn() >= 3) {
        logFraudEvent($pdo, $username, 'duplicate_account_pattern', 3, 'Mesmo IP associado a muitas contas/eventos recentes.');
    }
}
function recalcReportVeracity($pdo, $reportId)
{
    $stmt = $pdo->prepare("SELECT id, user_id, veracidade, status, verified_reward_applied FROM relatorios WHERE id = ?");
    $stmt->execute([$reportId]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$report)
        return null;

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(effective_delta),0) FROM report_validations WHERE report_id = ?");
    $stmt->execute([$reportId]);
    $veracity = clampInt(50 + (int) $stmt->fetchColumn());

    $author = $report['user_id'] ?? null;
    $temAutor = $author && $author !== 'anonimo';

    if ($veracity < 25) {
        if ($temAutor) {
            adjustUserReputation($pdo, $author, -10, 'Relatório reprovado pela comunidade (reputação < 25)', 'report', $reportId);
            ensureGameProfile($pdo, $author);
            $pdo->prepare("UPDATE user_gamification SET reports_invalidated = reports_invalidated + 1 WHERE username = ?")->execute([$author]);
        }
        removeInvalidatedReport($pdo, $reportId);
        return ['removed' => true, 'veracidade' => $veracity, 'status' => 'Invalidado'];
    }

    $status = $veracity > 75 ? 'Verificado' : 'Em análise';

    $stmt = $pdo->prepare("UPDATE relatorios SET veracidade = ?, status = ?, data_atualizacao = NOW() WHERE id = ?");
    $stmt->execute([$veracity, $status, $reportId]);

    if ($status === 'Verificado' && (int) $report['verified_reward_applied'] === 0) {
        if ($temAutor) {
            adjustUserReputation($pdo, $author, 5, 'Relatório aprovado pelos moderadores (reputação > 75)', 'report', $reportId);
            ensureGameProfile($pdo, $author);
            $pdo->prepare("UPDATE user_gamification SET reports_verified = reports_verified + 1 WHERE username = ?")->execute([$author]);
        }
        $pdo->prepare("UPDATE relatorios SET verified_reward_applied = 1 WHERE id = ?")->execute([$reportId]);
    }

    return ['removed' => false, 'veracidade' => $veracity, 'status' => $status];
}

function recalcCommentVeracity($pdo, $commentId)
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(effective_delta),0) FROM comment_validations WHERE comment_id = ?");
    $stmt->execute([$commentId]);
    $veracity = clampInt(50 + (int) $stmt->fetchColumn());
    $stmt = $pdo->prepare("UPDATE comentarios SET veracidade = ? WHERE id = ?");
    $stmt->execute([$veracity, $commentId]);
    return $veracity;
}

function addVoteHandler($pdo)
{
    $reportId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    $loggedUser = getCurrentUser();
    $userName = $loggedUser ?? ($_POST['user_name'] ?? ('anonimo_' . session_id()));

    if ($loggedUser) {
        $punishment = getUserPunishmentStatus($pdo, $loggedUser);
        if ($punishment['blocked']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => $punishment['message'], 'punishment' => $punishment]);
            return;
        }
    }

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
            echo json_encode(['success' => false, 'message' => 'Você já apoiou este relatório.', 'new_votes' => (int) $newVotes, 'already_voted' => true]);
            return;
        }

        $stmt = $pdo->prepare("INSERT INTO votos (report_id, user_name, voted_at) VALUES (?, ?, NOW())");
        $stmt->execute([$reportId, $userName]);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM votos WHERE report_id = ?");
        $stmt->execute([$reportId]);
        $newVotes = $stmt->fetchColumn();

        echo json_encode(['success' => true, 'message' => 'Voto registrado com sucesso.', 'new_votes' => (int) $newVotes]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro ao registrar voto: ' . $e->getMessage()]);
    }
}

function addCommentHandler($pdo)
{
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

    $punishment = getUserPunishmentStatus($pdo, $userName);
    if ($punishment['blocked']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => $punishment['message'], 'punishment' => $punishment]);
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

function validateReportHandler($pdo)
{
    $username = getCurrentUser();
    if (!$username) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Você precisa estar logado para validar.']);
        return;
    }

    $punishment = getUserPunishmentStatus($pdo, $username);
    if ($punishment['blocked']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => $punishment['message'], 'punishment' => $punishment]);
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
        ensureGameProfile($pdo, $username);
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
        $weight = 1;
        $geoBonus = 0;
        $delta = ($type === 'confirm') ? 1 : -1;
        $distance = ($lat !== false && $lng !== false && $lat !== null && $lng !== null)
            ? haversineMeters($report['latitude'], $report['longitude'], $lat, $lng)
            : null;

        $stmtExists = $pdo->prepare("SELECT COUNT(*) FROM report_validations WHERE report_id = ? AND username = ?");
        $stmtExists->execute([$reportId, $username]);
        $isNewValidation = ((int) $stmtExists->fetchColumn()) === 0;

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
        addXpAndPoints($pdo, $username, 8, 2, 'Validação registrada', 'report', $reportId);

        $result = recalcReportVeracity($pdo, $reportId);

        if ($result && !empty($result['removed'])) {
            echo json_encode([
                'success' => true,
                'message' => 'Sua avaliação foi registrada. A reputação deste relatório caiu abaixo de 25 e ele foi reprovado e removido da plataforma.',
                'removed' => true,
                'report_id' => $reportId,
                'weight' => $weight,
                'geo_bonus' => $geoBonus
            ]);
            return;
        }

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

function validateCommentHandler($pdo)
{
    $username = getCurrentUser();
    if (!$username) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Você precisa estar logado para validar comentários.']);
        return;
    }

    $punishment = getUserPunishmentStatus($pdo, $username);
    if ($punishment['blocked']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => $punishment['message'], 'punishment' => $punishment]);
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
        $weight = getValidationWeight((float) $profile['reliability_rank']);
        $delta = ($type === 'confirm' ? 1 : -1) * $weight;
        $stmtExists = $pdo->prepare("SELECT COUNT(*) FROM comment_validations WHERE comment_id = ? AND username = ?");
        $stmtExists->execute([$commentId, $username]);
        $isNewValidation = ((int) $stmtExists->fetchColumn()) === 0;

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


function registerUserHandler($pdo)
{
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

function loginUserHandler($pdo)
{
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

function changePasswordHandler($pdo)
{
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

function logoutUserHandler()
{

    session_unset();

    session_destroy();

    session_write_close();

    setcookie(session_name(), '', time() - 3600, '/');

    echo json_encode(['success' => true, 'message' => 'Logout realizado.']);
}

function getCurrentUser()
{
    return $_SESSION['username'] ?? null;
}

function loadOwner($pdo, $report_id)
{
    $stmt = $pdo->prepare("SELECT username, claimed_at FROM report_owners WHERE report_id = ?");
    $stmt->execute([$report_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function claimReportHandler($pdo)
{
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
    if (loadOwner($pdo, $report_id)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Relatório já possui proprietário.']);
        return;
    }
    $stmt = $pdo->prepare("INSERT INTO report_owners (report_id, username, claimed_at) VALUES (?, ?, NOW())");
    $stmt->execute([$report_id, $username]);
    echo json_encode(['success' => true, 'message' => 'Relatório reivindicado.', 'report_id' => $report_id]);
}

function userOwnsReport($pdo, $report_id)
{
    $owner = loadOwner($pdo, $report_id);
    $username = getCurrentUser();
    return $username && $owner && $owner['username'] === $username;
}

function getMyReportsHandler($pdo)
{
    $username = getCurrentUser();
    if (!$username) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Autenticação necessária.']);
        return;
    }
    $stmt = $pdo->prepare("SELECT report_id FROM report_owners WHERE username = ?");
    $stmt->execute([$username]);
    $my = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    echo json_encode(['success' => true, 'report_ids' => $my]);
}

function editReportHandler($pdo)
{

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
    if (!userOwnsReport($pdo, $id)) {
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
    if ($titulo !== null) {
        $sets[] = 'titulo = ?';
        $params[] = $titulo;
    }
    if ($descricao !== null) {
        $sets[] = 'descricao = ?';
        $params[] = $descricao;
    }
    if ($status !== null) {
        $sets[] = 'status = ?';
        $params[] = $status;
    }
    if ($prioridade !== null) {
        $sets[] = 'prioridade = ?';
        $params[] = $prioridade;
    }
    if ($endereco !== null) {
        $sets[] = 'endereco = ?';
        $params[] = $endereco;
    }
    $imagem_path = null;
    if (isset($_FILES['imagem_upload']) && is_array($_FILES['imagem_upload']) && isset($_FILES['imagem_upload']['error'])) {
        if ($_FILES['imagem_upload']['error'] === UPLOAD_ERR_NO_FILE) {
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
            } catch (Exception $e) {}

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

function deleteReportHandler($pdo)
{
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
    if (!userOwnsReport($pdo, $id)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Você não tem permissão para deletar este relatório.']);
        return;
    }
    try {
        $stmt = $pdo->prepare('DELETE FROM relatorios WHERE id = ?');
        $stmt->execute([$id]);
        $pdo->prepare('DELETE FROM report_owners WHERE report_id = ?')->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Relatório excluído.']);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro ao excluir: ' . $e->getMessage()]);
    }
}

function getProblems($pdo)
{
    try {

        $stmt = $pdo->query("SELECT id, titulo, latitude, longitude, tipo, descricao, status, veracidade, imagem_url, prioridade, endereco, data_criacao FROM relatorios ORDER BY data_criacao DESC");
        $problems = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode($problems);
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['success' => false, 'message' => 'Erro ao buscar problemas: ' . $e->getMessage()]));
    }
}

function getReportDetails($pdo)
{
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
        $report['votos'] = (int) $votos;
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

function reportProblem($pdo)
{
    $lat = filter_input(INPUT_POST, 'latitude', FILTER_VALIDATE_FLOAT);
    $lng = filter_input(INPUT_POST, 'longitude', FILTER_VALIDATE_FLOAT);
    $tipo = filter_input(INPUT_POST, 'categoria', FILTER_SANITIZE_STRING);
    $descricao = filter_input(INPUT_POST, 'descricao', FILTER_SANITIZE_STRING);
    $titulo = filter_input(INPUT_POST, 'titulo', FILTER_SANITIZE_STRING);
    $prioridade = filter_input(INPUT_POST, 'prioridade', FILTER_SANITIZE_STRING);
    $endereco = filter_input(INPUT_POST, 'endereco', FILTER_SANITIZE_STRING);

    $status = 'Em análise';

    $user_id = getCurrentUser() ?? 'anonimo';

    if ($user_id !== 'anonimo') {
        $punishment = getUserPunishmentStatus($pdo, $user_id);
        if ($punishment['blocked']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => $punishment['message'], 'punishment' => $punishment]);
            return;
        }
    }

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
            addXpAndPoints($pdo, $user_id, 25, 10, 'Relatório criado', 'report', (int) $lastId);
            runAntifraudChecks($pdo, $user_id, 'report');
        }
        echo json_encode(['success' => true, 'message' => 'Relatório registrado com sucesso!', 'report_id' => $lastId]);
    } catch (PDOException $e) {
        http_response_code(500);
        die(json_encode(['success' => false, 'message' => "Erro fatal do MySQL: " . $e->getMessage()]));
    }
}

function getStatsHandler($pdo)
{
    try {
        $stmt_reports = $pdo->query("SELECT COUNT(*) FROM relatorios");
        $total_reports = $stmt_reports->fetchColumn();

        $stmt_users = $pdo->query("SELECT COUNT(*) FROM users");
        $total_users = $stmt_users->fetchColumn();

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'total_reports' => (int) $total_reports,
            'total_users' => (int) $total_users
        ]);

    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro ao buscar estatísticas de relatórios: ' . $e->getMessage()]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro ao buscar estatísticas de usuários: ' . $e->getMessage()]);
    }
}



function getDashboardData($pdo)
{
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
                $statusCounts[$status] = (int) $count;
            }
        }

        $total = array_sum($statusCounts);
        $resolvidos = $statusCounts['Resolvido'] ?? 0;
        $taxa_resolucao = ($total > 0) ? round(($resolvidos / $total) * 100) : 0;


        $stmt = $pdo->query("SELECT COALESCE(NULLIF(tipo, ''), 'outros') as tipo, COUNT(*) as count FROM relatorios GROUP BY COALESCE(NULLIF(tipo, ''), 'outros') ORDER BY count DESC");
        $tipos = $stmt->fetchAll(PDO::FETCH_ASSOC);


        $stmt = $pdo->query("SELECT COUNT(*) FROM relatorios WHERE data_criacao >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $novos_7_dias = (int) $stmt->fetchColumn();

        $priorityCounts = [
            'baixa' => 0,
            'media' => 0,
            'alta' => 0,
            'urgente' => 0,
        ];
        $stmt = $pdo->query("SELECT LOWER(COALESCE(NULLIF(prioridade, ''), 'baixa')) as prioridade, COUNT(*) as count FROM relatorios GROUP BY LOWER(COALESCE(NULLIF(prioridade, ''), 'baixa'))");
        foreach ($stmt->fetchAll(PDO::FETCH_KEY_PAIR) as $prioridade => $count) {
            if (array_key_exists($prioridade, $priorityCounts)) {
                $priorityCounts[$prioridade] = (int) $count;
            }
        }

        return [
            'total' => $total,
            'pendentes' => $statusCounts['Pendente'],
            'resolvidos' => $resolvidos,
            'em_analise' => $statusCounts['Em Análise'],
            'em_andamento' => $statusCounts['Em Andamento'],
            'taxa_resolucao' => $taxa_resolucao,
            'novos_7_dias' => $novos_7_dias,
            'tipos' => $tipos,
            'status_counts' => $statusCounts,
            'priority_counts' => $priorityCounts
        ];
    } catch (PDOException $e) {

        return [
            'total' => 0,
            'pendentes' => 0,
            'resolvidos' => 0,
            'em_analise' => 0,
            'em_andamento' => 0,
            'taxa_resolucao' => 0,
            'novos_7_dias' => 0,
            'tipos' => [],
            'status_counts' => ['Pendente' => 0, 'Em Análise' => 0, 'Em Andamento' => 0, 'Resolvido' => 0],
            'priority_counts' => ['baixa' => 0, 'media' => 0, 'alta' => 0, 'urgente' => 0]
        ];
    }
}

function getReportsOverTimeData($pdo)
{
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

function getReportsOverTimeHandler($pdo)
{
    $data = getReportsOverTimeData($pdo);

    $labels = [];
    $cumulative_counts = [];
    $cumulative = 0;


    foreach ($data as $row) {
        $labels[] = $row['report_date'];
        $cumulative += (int) $row['count'];
        $cumulative_counts[] = $cumulative;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'labels' => $labels,
        'cumulative_counts' => $cumulative_counts
    ]);
}

function getMonthlyTier($profile)
{
    $score = (float) ($profile['monthly_score'] ?? 0);
    $rank = (float) ($profile['reliability_rank'] ?? 0);
    if ($score >= 250 && $rank >= 75)
        return 'Ouro';
    if ($score >= 120 && $rank >= 55)
        return 'Prata';
    return 'Bronze';
}

function getMonthlyTierFromPoints($pontos_mes, $reliability_rank)
{
    if ($pontos_mes >= 250 && $reliability_rank >= 75)
        return 'Ouro';
    if ($pontos_mes >= 120 && $reliability_rank >= 55)
        return 'Prata';
    return 'Bronze';
}

function monthlyRankingHandler($pdo)
{
    $inicioMes = date('Y-m-01 00:00:00');
    $diasNoMes = (int) date('t');
    $diaAtual = (int) date('j');
    $diasRestantes = max(0, $diasNoMes - $diaAtual);

    $sql = "SELECT
                ug.username,
                ug.xp,
                ug.level_num,
                ug.reliability_rank,
                COALESCE(pm.pontos_mes, 0) AS pontos_mes,
                COALESCE(rm.relatorios_mes, 0) AS relatorios_mes,
                COALESCE(vm.validacoes_mes, 0) AS validacoes_mes
            FROM user_gamification ug
            LEFT JOIN (
                SELECT username, SUM(amount) AS pontos_mes
                FROM urbanpoint_ledger
                WHERE created_at >= ? AND amount > 0
                GROUP BY username
            ) pm ON pm.username = ug.username
            LEFT JOIN (
                SELECT user_id AS username, COUNT(*) AS relatorios_mes
                FROM relatorios
                WHERE data_criacao >= ?
                GROUP BY user_id
            ) rm ON rm.username = ug.username
            LEFT JOIN (
                SELECT username, COUNT(*) AS validacoes_mes
                FROM report_validations
                WHERE was_correct = 1 AND created_at >= ?
                GROUP BY username
            ) vm ON vm.username = ug.username
            HAVING pontos_mes > 0 OR relatorios_mes > 0 OR validacoes_mes > 0
            ORDER BY pontos_mes DESC, relatorios_mes DESC, validacoes_mes DESC
            LIMIT 100";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$inicioMes, $inicioMes, $inicioMes]);

    $usuarioAtual = getCurrentUser();
    $linhas = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $i => $row) {
        $row['pontos_mes'] = (int) $row['pontos_mes'];
        $row['relatorios_mes'] = (int) $row['relatorios_mes'];
        $row['validacoes_mes'] = (int) $row['validacoes_mes'];
        $row['reliability_rank'] = (float) $row['reliability_rank'];
        $row['posicao'] = $i + 1;
        $row['liga'] = getMonthlyTierFromPoints($row['pontos_mes'], $row['reliability_rank']);
        $row['sou_eu'] = ($usuarioAtual !== null && $row['username'] === $usuarioAtual);
        $linhas[] = $row;
    }

    echo json_encode([
        'success' => true,
        'mes_referencia' => date('F/Y'),
        'dias_restantes' => $diasRestantes,
        'ranking' => $linhas
    ]);
}

function getUserDashboardHandler($pdo)
{
    $username = getCurrentUser();
    if (!$username) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Autenticação necessária.']);
        return;
    }

    processMonthlyReputationPayout($pdo, $username);

    $profile = ensureGameProfile($pdo, $username);
    $level = max(1, (int) $profile['level_num']);
    $currentLevelXp = ($level - 1) * ($level - 1) * 100;
    $nextLevelXp = $level * $level * 100;
    $xp = (int) $profile['xp'];
    $progress = $nextLevelXp > $currentLevelXp ? clampInt((($xp - $currentLevelXp) / ($nextLevelXp - $currentLevelXp)) * 100) : 0;
    $reputationAtual = (float) $profile['reliability_rank'];
    $punishment = getUserPunishmentStatus($pdo, $username);

    echo json_encode([
        'success' => true,
        'username' => $username,
        'profile' => [
            'xp' => $xp,
            'level' => $level,
            'xp_progress' => $progress,
            'next_level_xp' => $nextLevelXp,
            'urban_points' => (int) $profile['urban_points'],
            'reliability_rank' => $reputationAtual,
            'monthly_tier' => getMonthlyTier($profile),
            'participation_count' => (int) $profile['participation_count'],
            'validations_total' => (int) $profile['validations_total'],
            'validations_correct' => (int) $profile['validations_correct'],
            'validations_incorrect' => (int) $profile['validations_incorrect'],
            'reports_created' => (int) $profile['reports_created'],
            'reports_verified' => (int) $profile['reports_verified'],
            'reports_invalidated' => (int) $profile['reports_invalidated'],
            'suspicious_score' => (int) $profile['suspicious_score']
        ],
        'reputacao' => [
            'valor' => $reputationAtual,
            'pontos_mes_estimados' => calcularPontosMensaisPorReputacao($reputationAtual)
        ],
        'punishment' => $punishment
    ]);
}

function leaderboardHandler($pdo)
{
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
            $row['accuracy'] = (int) $row['validations_total'] > 0 ? round(((int) $row['validations_correct'] / (int) $row['validations_total']) * 100, 1) : 0;
            $rows[] = $row;
        }
    }
    echo json_encode(['success' => true, 'period' => $period, 'tier' => $tier, 'leaders' => $rows]);
}

function rewardsHandler($pdo)
{
    $stmt = $pdo->query("SELECT id, title, description, cost_points, reward_type, category, partner, image_url, estimated_value, inventory FROM rewards WHERE active = 1 ORDER BY partner ASC, cost_points ASC");
    echo json_encode(['success' => true, 'rewards' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function rewardCenterHandler($pdo)
{
    $username = getCurrentUser();
    if (!$username) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'AutenticaÃ§Ã£o necessÃ¡ria.']);
        return;
    }

    $profile = ensureGameProfile($pdo, $username);
    $points = (int) $profile['urban_points'];
    $level = max(1, (int) $profile['level_num']);
    $currentLevelXp = ($level - 1) * ($level - 1) * 100;
    $nextLevelXp = $level * $level * 100;
    $xp = (int) $profile['xp'];
    $xpProgress = $nextLevelXp > $currentLevelXp ? clampInt((($xp - $currentLevelXp) / ($nextLevelXp - $currentLevelXp)) * 100) : 0;

    $rewardStmt = $pdo->query("SELECT id, title, description, cost_points, reward_type, category, partner, image_url, estimated_value, inventory
        FROM rewards WHERE active = 1 ORDER BY partner ASC, cost_points ASC");
    $rewards = $rewardStmt->fetchAll(PDO::FETCH_ASSOC);

    $nextReward = null;
    foreach ($rewards as $reward) {
        if ((int) $reward['cost_points'] > $points && (!$nextReward || (int) $reward['cost_points'] < (int) $nextReward['cost_points'])) {
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
            'reliability_rank' => (float) $profile['reliability_rank'],
            'monthly_tier' => getMonthlyTier($profile),
            'participation_count' => (int) $profile['participation_count'],
            'validations_total' => (int) $profile['validations_total'],
            'validations_correct' => (int) $profile['validations_correct'],
            'reports_created' => (int) $profile['reports_created'],
            'reports_verified' => (int) $profile['reports_verified']
        ],
        'summary' => [
            'redemptions_count' => (int) $redemptionStats['total'],
            'total_savings' => (float) $redemptionStats['savings'],
            'next_reward' => $nextReward,
            'points_to_next_reward' => $nextReward ? max(0, (int) $nextReward['cost_points'] - $points) : 0
        ],
        'rewards' => $rewards,
        'history' => $historyStmt->fetchAll(PDO::FETCH_ASSOC)
    ]);
}

function redeemRewardHandler($pdo)
{
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
        if (!$reward || (int) $reward['inventory'] <= 0) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Recompensa indisponível.']);
            return;
        }
        if ((int) $profile['urban_points'] < (int) $reward['cost_points']) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'UrbanPoints insuficientes.']);
            return;
        }
        $code = 'AU-' . strtoupper(bin2hex(random_bytes(4))) . '-' . $rewardId;
        $pdo->prepare("UPDATE rewards SET inventory = inventory - 1 WHERE id = ?")->execute([$rewardId]);
        addXpAndPoints($pdo, $username, 0, -((int) $reward['cost_points']), 'Resgate: ' . $reward['title'], 'reward', $rewardId);
        $stmt = $pdo->prepare("INSERT INTO reward_redemptions (reward_id, username, code) VALUES (?, ?, ?)");
        $stmt->execute([$rewardId, $username, $code]);
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Recompensa resgatada.', 'code' => $code]);
    } catch (Exception $e) {
        if ($pdo->inTransaction())
            $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erro ao resgatar recompensa: ' . $e->getMessage()]);
    }
}

function antifraudSummaryHandler($pdo)
{
    $stmt = $pdo->query("SELECT event_type, COUNT(*) AS total, SUM(severity) AS severity FROM antifraud_events GROUP BY event_type ORDER BY severity DESC");
    echo json_encode(['success' => true, 'events' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

function weeklyCheckinHandler($pdo)
{
    $username = getCurrentUser();
    if (!$username) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Autenticação necessária.']);
        return;
    }

    $hoje = new DateTime('now');
    $diaSemanaISO = (int) $hoje->format('N');
    $segunda = (clone $hoje)->modify('-' . ($diaSemanaISO - 1) . ' days')->setTime(0, 0, 0);
    $hojeStr = $hoje->format('Y-m-d');
    $nomesDias = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM relatorios WHERE user_id = ? AND DATE(data_criacao) = ?");
    $dias = [];
    $diasCompletos = 0;
    for ($i = 0; $i < 7; $i++) {
        $dia = (clone $segunda)->modify("+{$i} days");
        $diaStr = $dia->format('Y-m-d');
        $stmt->execute([$username, $diaStr]);
        $feito = ((int) $stmt->fetchColumn()) > 0;
        if ($feito)
            $diasCompletos++;
        $dias[] = [
            'label' => $nomesDias[$i],
            'data' => $diaStr,
            'feito' => $feito,
            'e_hoje' => $diaStr === $hojeStr,
            'futuro' => $diaStr > $hojeStr
        ];
    }

    $anoSemana = (int) $hoje->format('oW');
    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM urbanpoint_ledger WHERE username = ? AND reference_type = 'weekly_streak' AND reference_id = ?");
    $checkStmt->execute([$username, $anoSemana]);
    $bonusJaConcedido = ((int) $checkStmt->fetchColumn()) > 0;

    $bonusConcedidoAgora = false;
    if ($diasCompletos === 7 && !$bonusJaConcedido) {
        addXpAndPoints($pdo, $username, 30, 50, 'Sequência perfeita da semana (7/7 dias com relatório)', 'weekly_streak', $anoSemana);
        $bonusJaConcedido = true;
        $bonusConcedidoAgora = true;
    }

    echo json_encode([
        'success' => true,
        'dias' => $dias,
        'dias_completos' => $diasCompletos,
        'bonus_concedido' => $bonusJaConcedido,
        'bonus_concedido_agora' => $bonusConcedidoAgora
    ]);
}

function dailyChallengeHandler($pdo)
{
    $username = getCurrentUser();
    if (!$username) {
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Autenticação necessária.']);
        return;
    }

    $hoje = date('Y-m-d');
    $hojeInt = (int) date('Ymd');

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM relatorios WHERE user_id = ? AND DATE(data_criacao) = ?");
    $stmt->execute([$username, $hoje]);
    $reportarFeito = ((int) $stmt->fetchColumn()) > 0;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM report_validations WHERE username = ? AND DATE(created_at) = ?");
    $stmt->execute([$username, $hoje]);
    $validarFeito = ((int) $stmt->fetchColumn()) > 0;

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM comentarios WHERE user_name = ? AND DATE(created_at) = ?");
    $stmt->execute([$username, $hoje]);
    $comentarFeito = ((int) $stmt->fetchColumn()) > 0;

    $tarefas = [
        ['chave' => 'reportar', 'titulo' => 'Crie um relatório', 'icone' => 'fa-location-dot', 'feito' => $reportarFeito],
        ['chave' => 'validar', 'titulo' => 'Valide um relatório', 'icone' => 'fa-shield-halved', 'feito' => $validarFeito],
        ['chave' => 'comentar', 'titulo' => 'Comente em um relatório', 'icone' => 'fa-comment', 'feito' => $comentarFeito],
    ];

    $todasFeitas = $reportarFeito && $validarFeito && $comentarFeito;

    $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM urbanpoint_ledger WHERE username = ? AND reference_type = 'daily_challenge' AND reference_id = ?");
    $checkStmt->execute([$username, $hojeInt]);
    $bonusJaConcedido = ((int) $checkStmt->fetchColumn()) > 0;

    $bonusConcedidoAgora = false;
    if ($todasFeitas && !$bonusJaConcedido) {
        addXpAndPoints($pdo, $username, 15, 25, 'Desafios diários completos', 'daily_challenge', $hojeInt);
        $bonusJaConcedido = true;
        $bonusConcedidoAgora = true;
    }

    echo json_encode([
        'success' => true,
        'tarefas' => $tarefas,
        'todas_feitas' => $todasFeitas,
        'bonus_concedido' => $bonusJaConcedido,
        'bonus_concedido_agora' => $bonusConcedidoAgora
    ]);
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
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Use POST']);
            }
            break;

        case 'validate_report':
            if ($_SERVER['REQUEST_METHOD'] === 'POST')
                validateReportHandler($pdo);
            else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Use POST']);
            }
            break;

        case 'validate_comment':
            if ($_SERVER['REQUEST_METHOD'] === 'POST')
                validateCommentHandler($pdo);
            else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Use POST']);
            }
            break;

        case 'add_vote':
            if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                addVoteHandler($pdo);
            } else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Use POST']);
            }
            break;

        case 'register':
            if ($_SERVER['REQUEST_METHOD'] === 'POST')
                registerUserHandler($pdo);
            else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Use POST']);
            }
            break;
        case 'login':
            if ($_SERVER['REQUEST_METHOD'] === 'POST')
                loginUserHandler($pdo);
            else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Use POST']);
            }
            break;
        case 'change_password':
            if ($_SERVER['REQUEST_METHOD'] === 'POST')
                changePasswordHandler($pdo);
            else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Use POST']);
            }
            break;
        case 'logout':
            logoutUserHandler();
            break;
        case 'current_user':
            $username = getCurrentUser();
            $punishmentStatus = null;
            $reputationAtual = null;
            if ($username) {
                ensureGameProfile($pdo, $username);
                processMonthlyReputationPayout($pdo, $username);
                $punishmentStatus = getUserPunishmentStatus($pdo, $username);
                $stmtRep = $pdo->prepare("SELECT reliability_rank FROM user_gamification WHERE username = ?");
                $stmtRep->execute([$username]);
                $reputationAtual = round((float) $stmtRep->fetchColumn(), 1);
            }
            echo json_encode([
                'username' => $username,
                'reputation' => $reputationAtual,
                'punishment' => $punishmentStatus
            ]);
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

        case 'monthly_ranking':
            monthlyRankingHandler($pdo);
            break;

        case 'weekly_checkin':
            weeklyCheckinHandler($pdo);
            break;

        case 'daily_challenges':
            dailyChallengeHandler($pdo);
            break;

        case 'rewards':
            rewardsHandler($pdo);
            break;

        case 'reward_center':
            rewardCenterHandler($pdo);
            break;

        case 'redeem_reward':
            if ($_SERVER['REQUEST_METHOD'] === 'POST')
                redeemRewardHandler($pdo);
            else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Use POST']);
            }
            break;

        case 'antifraud_summary':
            antifraudSummaryHandler($pdo);
            break;

        case 'claim_report':
            if ($_SERVER['REQUEST_METHOD'] === 'POST')
                claimReportHandler($pdo);
            else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Use POST']);
            }
            break;
        case 'my_reports':
            getMyReportsHandler($pdo);
            break;
        case 'edit_report':
            if ($_SERVER['REQUEST_METHOD'] === 'POST')
                editReportHandler($pdo);
            else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Use POST']);
            }
            break;
        case 'delete_report':
            if ($_SERVER['REQUEST_METHOD'] === 'POST')
                deleteReportHandler($pdo);
            else {
                http_response_code(405);
                echo json_encode(['success' => false, 'message' => 'Use POST']);
            }
            break;
        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Ação de API inválida.']);
            break;
    }
}