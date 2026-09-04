<?php

require_once 'api.php';

$pdo = connectDB();

$username = getCurrentUser();

if (!$username) {
    header('Location: usuario.html');
    exit;
}

$stmt = $pdo->prepare("
    SELECT reliability_rank, urban_points
    FROM user_gamification
    WHERE username = ?
");

$stmt->execute([$username]);

$profile = $stmt->fetch(PDO::FETCH_ASSOC);

$ranking = $profile['reliability_rank'] ?? 50;
$urbanPoints = $profile['urban_points'] ?? 0;

if ($ranking >= 85) {
    $classe = "Elite";
} elseif ($ranking >= 70) {
    $classe = "Diamante";
} elseif ($ranking >= 60) {
    $classe = "Ouro";
} elseif ($ranking >= 40) {
    $classe = "Prata";
} elseif ($ranking >= 20) {
    $classe = "Bronze";
} else {
    $classe = "Banido";
}
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gamificação - Agente Urbano</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="style.css">
    <link rel="shortcut icon" href="./imagens/urbanoide.png" type="image/x-icon">
    <style>
        :root {
            --primary: #007bff;
            --success: #28a745;
            --warning: #ffc107;
            --danger: #dc3545;
            --dark: #0f172a;
            --light: #f8f9fa;
            --border: #dbe5ef;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        html, body {
            font-family: 'Poppins', sans-serif;
        }

        body {
            background: linear-gradient(135deg, #f4f8fb 0%, #e8f1f8 100%);
            color: #1f2937;
            padding-top: 12vh;
            min-height: 100vh;
        }

        body.dark-mode {
            background: linear-gradient(135deg, #0f172a 0%, #1a1f35 100%);
            color: #e5e7eb;
        }

        .game-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 0 16px;
        }

        .game-header {
            background: linear-gradient(135deg, var(--primary) 0%, #0056b3 100%);
            color: white;
            padding: 48px 32px;
            border-radius: 12px;
            margin-bottom: 32px;
            box-shadow: 0 20px 40px rgba(0, 123, 255, 0.2);
            position: relative;
            overflow: hidden;
            margin-top: 6vh;
        }

        .game-header::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 300px;
            height: 300px;
            background: rgba(255,255,255,0.1);
            border-radius: 50%;
            transform: translate(50%, -50%);
        }

        .game-header-content {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 32px;
            align-items: center;
        }

        .game-header h1 {
            font-size: clamp(2rem, 5vw, 3.2rem);
            margin-bottom: 8px;
            font-weight: 800;
        }

        .game-header p {
            font-size: 1.1rem;
            opacity: 0.95;
            margin-bottom: 20px;
        }

        .user-profile-mini {
            background: rgba(255,255,255,0.15);
            padding: 16px 24px;
            border-radius: 12px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            text-align: center;
            min-width: 220px;
        }

        .user-profile-mini .username {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-bottom: 8px;
        }

        .user-profile-mini .user-level {
            font-size: 2.2rem;
            font-weight: 800;
            margin-bottom: 4px;
        }

        .user-profile-mini .level-label {
            font-size: 0.85rem;
            opacity: 0.85;
        }

        .profile-link {
            display: inline-block;
            margin-top: 12px;
            padding: 10px 20px;
            background: rgba(255,255,255,0.2);
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 8px;
            color: white;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
        }

        .profile-link:hover {
            background: rgba(255,255,255,0.3);
            border-color: rgba(255,255,255,0.5);
        }

        .hero-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 18px;
        }

        .reward-cta,
        .secondary-cta {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            min-height: 46px;
            padding: 12px 18px;
            border-radius: 8px;
            font-weight: 800;
            text-decoration: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
        }

        .reward-cta {
            background: #ffffff;
            color: var(--primary);
            box-shadow: 0 14px 28px rgba(15, 23, 42, 0.18);
            font-size: 1rem;
        }

        .secondary-cta {
            color: #ffffff;
            border: 1px solid rgba(255,255,255,0.34);
            background: rgba(255,255,255,0.14);
        }

        .reward-cta:hover,
        .secondary-cta:hover {
            transform: translateY(-2px);
            box-shadow: 0 18px 34px rgba(15, 23, 42, 0.22);
        }

        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }

        .metric-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            transition: all 0.3s;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        body.dark-mode .metric-card {
            background: #111827;
            border-color: #1f2937;
        }

        .metric-card:hover {
            box-shadow: 0 12px 24px rgba(0,123,255,0.1);
            border-color: var(--primary);
        }

        .metric-label {
            font-size: 0.85rem;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        body.dark-mode .metric-label {
            color: #9ca3af;
        }

        .metric-value {
            font-size: 2.2rem;
            font-weight: 800;
            color: var(--primary);
            display: flex;
            align-items: baseline;
            gap: 8px;
        }

        .metric-detail {
            font-size: 0.8rem;
            color: #94a3b8;
        }

        .xp-progress {
            background: white;
            padding: 20px;
            border-radius: 12px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            margin-bottom: 32px;
        }

        body.dark-mode .xp-progress {
            background: #111827;
            border-color: #1f2937;
        }

        .xp-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .xp-header h3 {
            margin: 0;
            color: #1f2937;
            font-size: 1.1rem;
        }

        body.dark-mode .xp-header h3 {
            color: #e5e7eb;
        }

        .xp-label {
            font-size: 0.9rem;
            color: #64748b;
            font-weight: 600;
        }

        .xp-bar-container {
            background: #f0f4f8;
            height: 16px;
            border-radius: 999px;
            overflow: hidden;
            margin-bottom: 12px;
        }

        body.dark-mode .xp-bar-container {
            background: #1f2937;
        }

        .xp-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--primary), var(--success));
            border-radius: 999px;
            transition: width 0.6s ease;
        }

        .xp-text {
            font-size: 0.85rem;
            color: #64748b;
            text-align: center;
        }

        body.dark-mode .xp-text {
            color: #9ca3af;
        }

        .content-grid {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 360px;
            gap: 20px;
            margin-bottom: 32px;
        }

        @media (max-width: 1024px) {
            .content-grid {
                grid-template-columns: 1fr;
            }
        }

        .panel {
            background: white;
            border-radius: 12px;
            border: 1px solid var(--border);
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
            overflow: hidden;
            margin-bottom: 32px;
        }

        body.dark-mode .panel {
            background: #111827;
            border-color: #1f2937;
        }

        .panel-header {
            background: linear-gradient(135deg, #f8fbff 0%, #f0f7ff 100%);
            padding: 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        body.dark-mode .panel-header {
            background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
            border-bottom-color: #1f2937;
        }

        .panel-header h2 {
            margin: 0;
            font-size: 1.2rem;
            color: #1f2937;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        body.dark-mode .panel-header h2 {
            color: #e5e7eb;
        }

        .panel-header i {
            color: var(--primary);
            font-size: 1.3rem;
        }

        .panel-body {
            padding: 20px;
        }

        .toolbar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .toolbar select,
        .toolbar button {
            padding: 10px 14px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: white;
            color: #1f2937;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Poppins', sans-serif;
        }

        body.dark-mode .toolbar select,
        body.dark-mode .toolbar button {
            background: #1f2937;
            color: #e5e7eb;
            border-color: #374151;
        }

        .toolbar button:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .cost-badge {
            background: var(--primary);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 700;
        }

        .coupon-hub-card {
            position: relative;
            overflow: hidden;
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 22px;
            align-items: center;
            margin-bottom: 32px;
            padding: 28px;
            border-radius: 12px;
            border: 1px solid rgba(0, 123, 255, 0.18);
            background:
                linear-gradient(135deg, rgba(0, 123, 255, 0.12), rgba(40, 167, 69, 0.09)),
                #ffffff;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.09);
        }

        body.dark-mode .coupon-hub-card {
            background:
                linear-gradient(135deg, rgba(14, 165, 233, 0.16), rgba(34, 197, 94, 0.10)),
                #111827;
            border-color: #1f2937;
        }

        .coupon-hub-card::after {
            content: "";
            position: absolute;
            right: -80px;
            top: -100px;
            width: 260px;
            height: 260px;
            border-radius: 50%;
            background: rgba(0, 123, 255, 0.10);
        }

        .coupon-hub-content {
            position: relative;
            z-index: 1;
        }

        .coupon-hub-kicker {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            color: var(--primary);
            font-size: 0.8rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .coupon-hub-card h2 {
            margin: 0;
            color: #1f2937;
            font-size: clamp(1.6rem, 4vw, 2.4rem);
            line-height: 1.12;
        }

        body.dark-mode .coupon-hub-card h2 {
            color: #e5e7eb;
        }

        .coupon-hub-card p {
            max-width: 720px;
            margin: 10px 0 0;
            color: #64748b;
            line-height: 1.6;
        }

        body.dark-mode .coupon-hub-card p {
            color: #9ca3af;
        }

        .big-coupon-button {
            position: relative;
            z-index: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            min-width: 260px;
            min-height: 68px;
            padding: 18px 26px;
            border-radius: 10px;
            background: linear-gradient(135deg, #ff6a00, #ff3d00);
            color: #ffffff;
            font-size: 1.1rem;
            font-weight: 800;
            text-decoration: none;
            box-shadow: 0 18px 34px rgba(255, 92, 0, 0.28);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .big-coupon-button:hover {
            transform: translateY(-3px);
            box-shadow: 0 24px 42px rgba(255, 92, 0, 0.34);
        }

        .achievement-grid,
        .challenge-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 14px;
        }

        .achievement-card,
        .challenge-card {
            display: grid;
            grid-template-columns: 48px minmax(0, 1fr);
            gap: 13px;
            align-items: center;
            padding: 15px;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: linear-gradient(135deg, #f8fbff 0%, #f0f7ff 100%);
            transition: transform 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
        }

        body.dark-mode .achievement-card,
        body.dark-mode .challenge-card {
            background: linear-gradient(135deg, #1f2937 0%, #111827 100%);
            border-color: #374151;
        }

        .achievement-card:hover,
        .challenge-card:hover {
            transform: translateY(-3px);
            border-color: var(--primary);
            box-shadow: 0 12px 24px rgba(0,123,255,0.12);
        }

        .achievement-icon,
        .challenge-icon {
            width: 48px;
            height: 48px;
            display: grid;
            place-items: center;
            border-radius: 8px;
            background: #ffffff;
            color: var(--primary);
            box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
        }

        body.dark-mode .achievement-icon,
        body.dark-mode .challenge-icon {
            background: #0f172a;
        }

        .achievement-card.locked {
            opacity: 0.58;
        }

        .achievement-card strong,
        .challenge-card strong {
            display: block;
            color: #1f2937;
            font-size: 0.95rem;
        }

        body.dark-mode .achievement-card strong,
        body.dark-mode .challenge-card strong {
            color: #e5e7eb;
        }

        .achievement-card span,
        .challenge-card span {
            display: block;
            margin-top: 3px;
            color: #64748b;
            font-size: 0.8rem;
            line-height: 1.4;
        }

        body.dark-mode .achievement-card span,
        body.dark-mode .challenge-card span {
            color: #9ca3af;
        }

        .points-flash {
            animation: pointsFlash 0.75s ease;
        }

        @keyframes pointsFlash {
            0% { transform: scale(1); }
            35% { transform: scale(1.08); color: var(--success); }
            100% { transform: scale(1); }
        }

        .toast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: var(--dark);
            color: white;
            padding: 16px 20px;
            border-radius: 8px;
            box-shadow: 0 12px 24px rgba(0,0,0,0.3);
            z-index: 9999;
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.3s;
            pointer-events: none;
            max-width: 400px;
        }

        .toast.show {
            opacity: 1;
            transform: translateY(0);
            pointer-events: auto;
        }

        .toast.success {
            background: var(--success);
        }

        .toast.error {
            background: var(--danger);
        }

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
        }

        .empty-state i {
            font-size: 3rem;
            margin-bottom: 12px;
            opacity: 0.4;
        }

        body.dark-mode .empty-state {
            color: #6b7280;
        }

        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(0,0,0,0.1);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        @media (max-width: 768px) {
            .game-header-content {
                grid-template-columns: 1fr;
            }

            .game-header {
                padding: 32px 16px;
            }

            .metrics-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 12px;
            }

            .metric-value {
                font-size: 1.8rem;
            }

            .toolbar {
                flex-direction: column;
            }

            .toolbar select,
            .toolbar button {
                width: 100%;
            }

            .coupon-hub-card {
                grid-template-columns: 1fr;
            }

            .big-coupon-button {
                width: 100%;
                min-width: 0;
            }
        }

        @media (max-width: 480px) {
            .metrics-grid {
                grid-template-columns: 1fr;
            }

            .user-profile-mini {
                min-width: 100%;
            }
        }
    </style>
    <script>
        (function applyInitialTheme() {
            const savedTheme = localStorage.getItem('agenteurbano_theme');
            const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            const useDark = savedTheme ? savedTheme === 'dark' : prefersDark;
            document.documentElement.classList.toggle('dark-mode', useDark);
        })();
    </script>
</head>
<body>
    <div class="header-nav">
        <div class="logo">
            <a href="index.html"><img src="imagens/urbanoide.png" alt="Urbanoide" class="logo-icon"> Agente Urbano</a>
        </div>
        <nav class="main-menu">
            <a href="dashboard.php"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="mapa.html"><i class="fas fa-map"></i> Mapa</a>
            <a href="relatorios.html"><i class="fas fa-list-alt"></i> Relatórios</a>
        </nav>
        <div class="user-actions">
            <a href="mapa.html" class="new-report-btn">+ Novo Relatório</a>
            <button id="menu-toggle" class="hamburger-btn" aria-label="Abrir menu lateral">
                <span class="hamburger-icon" aria-hidden="true">
                    <span></span>
                    <span></span>
                    <span></span>
                </span>
            </button>
        </div>
    </div>

    <div id="sidebar-overlay" class="sidebar-overlay"></div>
    <aside id="sidebar-panel" class="sidebar-panel" aria-label="Menu lateral">
        <div class="sidebar-header">
            <div class="sidebar-brand">
                <img src="imagens/urbanoide.png" alt="Logotipo">
                <span>Agente Urbano</span>
            </div>
            <button id="sidebar-close" class="sidebar-close" aria-label="Fechar menu">&times;</button>
        </div>

        <a href="usuario.html" class="profile-button" id="sidebar-profile-btn">
            <img src="https://www.gravatar.com/avatar/?d=mp" alt="Avatar" class="avatar" id="user-avatar">
            <div>
                <div class="user-name" id="user-name-text">Minha página</div>
                <div class="profile-subtext" id="sidebar-profile-subtext">Acessar opções e reports</div>
            </div>
        </a>

        <div class="sidebar-divider"></div>

        <div class="sidebar-section-title">Navegação</div>
        <div class="sidebar-links">
            <a href="dashboard.php" class="sidebar-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="mapa.html" class="sidebar-link"><i class="fas fa-map"></i> Mapa</a>
            <a href="relatorios.html" class="sidebar-link"><i class="fas fa-list-alt"></i> Relatórios</a>
            <a href="gamificacao.html" class="sidebar-link"><i class="fas fa-gamepad"></i> Gamificação</a>
            <a href="recompensas.html" class="sidebar-link"><i class="fas fa-ticket"></i> Recompensas</a>
            <a href="ranking.html" class="sidebar-link"><i class="fas fa-trophy"></i> Ranking</a>
            <a href="settings.html" class="sidebar-link"><i class="fas fa-cog"></i> Configurações</a>
        </div>

        <div class="sidebar-section-title">Ações rápidas</div>
        <div class="sidebar-links">
            <a href="mapa.html" class="sidebar-action primary"><i class="fas fa-flag"></i> Novo problema</a>
        </div>
    </aside>

    <div class="game-container">
        <div class="game-header">
            <div class="game-header-content">
                <div>
                    <h1><i class="fas fa-gamepad" style="margin-right: 12px;"></i>Gamificação Urbana</h1>
                    <p>Suba de nível, ganhe UrbanPoints, participe do ranking e desbloqueie cupons em uma área exclusiva</p>
                    <div class="hero-actions">
                        <a href="recompensas.html" class="reward-cta"><i class="fas fa-ticket"></i> Central de Cupons</a>
                        <a href="ranking.html" class="secondary-cta"><i class="fas fa-trophy"></i> Ranking Mensal</a>
                        <a href="mapa.html" class="secondary-cta"><i class="fas fa-location-dot"></i> Ganhar UrbanPoints</a>
                    </div>
                </div>
                <div class="user-profile-mini">
                    <div class="username" id="header-username">Faça login para ver seu perfil</div>
                    <div class="user-level" id="header-level">-</div>
                    <div class="level-label">Nível</div>
                    <a href="usuario.html" class="profile-link"><i class="fas fa-user-circle"></i> Meu Perfil</a>
                </div>
            </div>
        </div>

        <div class="metrics-grid">            
            <div class="metric-card">
                <span class="metric-label"><i class="fas fa-medal"></i> Ranking</span>
                <div class="metric-value" id="metric-rank">
                    <?= $profile['reliability_rank'] ?? 0 ?>
                </div>
                <span class="metric-detail">Confiabilidade</span>
            </div>
            <div class="metric-card">
                <span class="metric-label"><i class="fas fa-coins"></i> UrbanPoints</span>
                <div class="metric-value" id="metric-points"><?= $urbanPoints ?></div>
                <span class="metric-detail">Para resgatar</span>
            </div>
            <div class="metric-card">
                <span class="metric-label"><i class="fas fa-crown"></i> Classe Mensal</span>
                <div class="metric-value" id="metric-tier"><?= $classe ?>   </div>
                <span class="metric-detail" id="metric-tier-detail">-</span>
            </div>
        </div>

        <div class="xp-progress">
            <div class="xp-header">
                <h3><i class="fas fa-chart-line"></i> Progresso para Próximo Nível</h3>
                <span class="xp-label" id="xp-percentage">0%</span>
            </div>
            <div class="xp-bar-container">
                <div class="xp-bar-fill" id="xp-bar-fill" style="width: 0%"></div>
            </div>
            <div class="xp-text" id="xp-text">0 / 100 XP</div>
        </div>

        <section class="panel">
            <div class="panel-header">
                <i class="fas fa-medal"></i>
                <h2>Conquistas e Medalhas</h2>
            </div>
            <div class="panel-body">
                <div id="achievements-container" class="achievement-grid">
                    <div class="empty-state"><div class="spinner"></div><p>Carregando conquistas...</p></div>
                </div>
            </div>
        </section>

        <section class="coupon-hub-card" aria-labelledby="coupon-hub-title">
            <div class="coupon-hub-content">
                <span class="coupon-hub-kicker"><i class="fas fa-ticket"></i> Cupons, descontos e missões</span>
                <h2 id="coupon-hub-title">Entre na Central de Cupons</h2>
                <p>Use seus UrbanPoints em uma experiência separada, inspirada em cupons e mini-jogos de marketplace, com ofertas de parceiros de exemplo, bônus diários e missões rápidas.</p>
            </div>
            <a href="recompensas.html" class="big-coupon-button"><i class="fas fa-gift"></i> Abrir Central</a>
        </section>

        <section class="coupon-hub-card" aria-labelledby="ranking-hub-title">
            <div class="coupon-hub-content">
                <span class="coupon-hub-kicker"><i class="fas fa-trophy"></i> Ranking mensal estilo liga</span>
                <h2 id="ranking-hub-title">Veja sua posição no Ranking</h2>
                <p>Pódio dos 3 primeiros, liga do mês (Bronze/Prata/Ouro) e comparação por pontuação, relatórios e validações — tudo numa página dedicada.</p>
            </div>
            <a href="ranking.html" class="big-coupon-button"><i class="fas fa-ranking-star"></i> Abrir Ranking</a>
        </section>

        <div class="content-grid">
            <section class="panel" style="grid-column: 1 / -1;">
                <div class="panel-header">
                    <i class="fas fa-bolt"></i>
                    <h2>Desafios Ativos</h2>
                </div>
                <div class="panel-body">
                    <div id="challenges-container" class="challenge-grid">
                        <div class="empty-state"><div class="spinner"></div><p>Carregando desafios...</p></div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <div id="toast" class="toast"></div>

    <script>
        document.body.classList.toggle('dark-mode', document.documentElement.classList.contains('dark-mode'));

        const el = id => document.getElementById(id);
        let currentProfile = null;

        function escapeHtml(value) {
            return String(value ?? '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        function toast(message, type = 'info', duration = 3500) {
            const toastEl = el('toast');
            toastEl.textContent = message;
            toastEl.className = `toast ${type} show`;
            clearTimeout(toast.timer);
            toast.timer = setTimeout(() => { toastEl.classList.remove('show'); }, duration);
        }

        async function loadUserProfile() {
            try {
                const response = await fetch('api.php?action=user_dashboard');
                if (!response.ok) {
                    el('header-username').textContent = 'Deslogado';
                    el('header-level').textContent = '-';
                    return;
                }
                const data = await response.json();
                if (!data.success) return;

                const p = data.profile || {};
                currentProfile = p;
                el('header-username').textContent = data.username || 'Usuário';
                el('header-level').textContent = p.level || 1;
                el('metric-xp').textContent = p.xp || 0;
                el('metric-xp-detail').textContent = `Nível ${p.level || 1}`;
                el('metric-rank').textContent = Math.round(Number(p.reliability_rank || 50));
                el('metric-points').textContent = p.urban_points || 0;
                el('metric-points').classList.remove('points-flash');
                void el('metric-points').offsetWidth;
                el('metric-points').classList.add('points-flash');
                el('metric-tier').textContent = p.monthly_tier || 'Bronze';
                el('metric-tier-detail').innerHTML = getTierEmoji(p.monthly_tier) + ' ' + (p.monthly_tier || 'Bronze');

                const nextLevelXp = p.next_level_xp || 100;
                const currentLevelXp = ((p.level || 1) - 1) ** 2 * 100;
                const xpInLevel = (p.xp || 0) - currentLevelXp;
                const xpNeeded = nextLevelXp - currentLevelXp;
                const percentage = Math.min(100, Math.max(0, (xpInLevel / xpNeeded) * 100));

                el('xp-percentage').textContent = Math.round(percentage) + '%';
                el('xp-bar-fill').style.width = percentage + '%';
                el('xp-text').textContent = `${Math.round(xpInLevel)} / ${Math.round(xpNeeded)} XP`;
                renderAchievements(p);
                renderChallenges(p);
            } catch (error) {
                console.error('Erro ao carregar perfil:', error);
            }
        }

        function renderAchievements(profile = {}) {
            const achievements = [
                {
                    icon: 'fa-seedling',
                    title: 'Primeiro Impacto',
                    desc: 'Criou o primeiro relatório urbano.',
                    unlocked: Number(profile.reports_created || 0) >= 1
                },
                {
                    icon: 'fa-shield-halved',
                    title: 'Validador Confiável',
                    desc: 'Realizou pelo menos 5 validações.',
                    unlocked: Number(profile.validations_total || 0) >= 5
                },
                {
                    icon: 'fa-ranking-star',
                    title: 'Precisão Alta',
                    desc: 'Alcançou 70 pontos de confiabilidade.',
                    unlocked: Number(profile.reliability_rank || 0) >= 70
                },
                {
                    icon: 'fa-crown',
                    title: 'Classe Prata',
                    desc: 'Chegou à classe mensal Prata ou superior.',
                    unlocked: ['Prata', 'Ouro'].includes(profile.monthly_tier)
                },
                {
                    icon: 'fa-city',
                    title: 'Guardião Urbano',
                    desc: 'Teve 3 relatórios verificados.',
                    unlocked: Number(profile.reports_verified || 0) >= 3
                },
                {
                    icon: 'fa-gem',
                    title: 'Colecionador',
                    desc: 'Acumulou 500 UrbanPoints.',
                    unlocked: Number(profile.urban_points || 0) >= 500
                }
            ];

            el('achievements-container').innerHTML = achievements.map(item => `
                <div class="achievement-card ${item.unlocked ? '' : 'locked'}">
                    <span class="achievement-icon"><i class="fas ${item.icon}"></i></span>
                    <div>
                        <strong>${escapeHtml(item.title)} ${item.unlocked ? '<i class="fas fa-check-circle" style="color: var(--success);"></i>' : '<i class="fas fa-lock"></i>'}</strong>
                        <span>${escapeHtml(item.desc)}</span>
                    </div>
                </div>
            `).join('');
        }

        function renderChallenges(profile = {}) {
            const validationsLeft = Math.max(0, 5 - Number(profile.validations_total || 0));
            const reportsLeft = Math.max(0, 3 - Number(profile.reports_created || 0));
            const rankLeft = Math.max(0, 75 - Math.round(Number(profile.reliability_rank || 0)));
            const challenges = [
                {
                    icon: 'fa-calendar-day',
                    title: 'Desafio diário',
                    desc: reportsLeft === 0 ? 'Meta de criação bem encaminhada.' : `Crie ${reportsLeft} relatório(s) completo(s).`
                },
                {
                    icon: 'fa-check-double',
                    title: 'Desafio semanal',
                    desc: validationsLeft === 0 ? 'Validações semanais em bom ritmo.' : `Faça mais ${validationsLeft} validação(ões) úteis.`
                },
                {
                    icon: 'fa-fire-flame-curved',
                    title: 'Bônus de sequência',
                    desc: 'Volte em dias seguidos para manter sua presença na comunidade.'
                },
                {
                    icon: 'fa-arrow-trend-up',
                    title: 'Rumo ao Ouro',
                    desc: rankLeft === 0 ? 'Confiabilidade em patamar premium.' : `Faltam ${rankLeft} ponto(s) de ranking para mirar Ouro.`
                }
            ];

            el('challenges-container').innerHTML = challenges.map(item => `
                <div class="challenge-card">
                    <span class="challenge-icon"><i class="fas ${item.icon}"></i></span>
                    <div>
                        <strong>${escapeHtml(item.title)}</strong>
                        <span>${escapeHtml(item.desc)}</span>
                    </div>
                </div>
            `).join('');
        }

        function getTierEmoji(tier) {
            const tiers = { 'Bronze': '🥉', 'Prata': '🥈', 'Ouro': '🥇' };
            return tiers[tier] || '🏅';
        }

        document.addEventListener('DOMContentLoaded', () => {
            loadUserProfile();

            setInterval(() => { loadUserProfile(); }, 30000);
        });
    </script>

    <script>
        // Sidebar toggle
        (function () {
            document.addEventListener('DOMContentLoaded', function () {
                const menuToggle = document.getElementById('menu-toggle');
                const sidebar = document.getElementById('sidebar-panel');
                const overlay = document.getElementById('sidebar-overlay');
                const closeBtn = document.getElementById('sidebar-close');
                if (!menuToggle || !sidebar || !overlay) return;

                const openSidebar = () => {
                    sidebar.classList.add('open');
                    overlay.classList.add('show');
                    document.body.classList.add('no-scroll');
                };
                const closeSidebar = () => {
                    sidebar.classList.remove('open');
                    overlay.classList.remove('show');
                    document.body.classList.remove('no-scroll');
                };

                menuToggle.addEventListener('click', openSidebar);
                if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
                overlay.addEventListener('click', closeSidebar);
                document.addEventListener('keydown', e => { if (e.key === 'Escape') closeSidebar(); });

                // Sync profile button with logged-in user
                fetch('api.php?action=current_user')
                    .then(r => r.json())
                    .then(data => {
                        const currentUser = data.username || null;
                        const userNameText = document.getElementById('user-name-text');
                        const userAvatar = document.getElementById('user-avatar');
                        const sidebarProfileSubtext = document.getElementById('sidebar-profile-subtext');
                        if (!userNameText || !userAvatar || !currentUser) return;
                        userNameText.textContent = currentUser;
                        if (sidebarProfileSubtext) sidebarProfileSubtext.textContent = 'Ver meu perfil';
                        const savedAvatar = localStorage.getItem('avatar_' + currentUser);
                        if (savedAvatar) userAvatar.src = savedAvatar;
                    })
                    .catch(() => {});
            });
        })();
    </script>
</body>
</html>