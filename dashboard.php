<?php
require_once 'api.php'; 
$pdo = connectDB(); 
$data = getDashboardData($pdo);
$currentUser = getCurrentUser(); 
function calculateBarWidth($current, $total) {
    return ($total > 0) ? round(($current / $total) * 100) : 0;
}
$status_counts = $data['status_counts'] ?? [];
$chart_labels = json_encode(array_keys($status_counts));
$chart_data = json_encode(array_values($status_counts));

$status_colors_map = [
    'Pendente' => 'rgb(220, 53, 69)', 
    'Em Análise' => 'rgb(0, 123, 255)', 
    'Em Andamento' => 'rgb(255, 193, 7)', 
    'Resolvido' => 'rgb(40, 167, 69)', 
];
$chart_colors = [];
foreach (array_keys($status_counts) as $status) {
    $chart_colors[] = $status_colors_map[$status] ?? 'rgb(100, 149, 237)'; 
}
$chart_colors_json = json_encode($chart_colors);
$total_reports = $data['total'] ?? 0;
$in_review_count = $data['em_analise'] ?? 0; 
$pending_count = $data['pendentes'] ?? 0;
$resolved_count = $data['resolvidos'] ?? 0;
$in_progress_count = $data['em_andamento'] ?? 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - Agente Urbano</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <link rel="shortcut icon" href="./imagens/urbanoide.png" type="image/x-icon">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        (function applyInitialTheme() {
            const savedTheme = localStorage.getItem('agenteurbano_theme');
            const legacyTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            const theme = savedTheme || legacyTheme;
            const useDark = theme ? theme === 'dark' : prefersDark;

            document.documentElement.classList.toggle('dark-mode', useDark);
        })();
    </script>
    <div vw class="enabled">
        <div vw-access-button class="active"></div>
        <div vw-plugin-wrapper>
            <div class="vw-plugin-top-wrapper"></div>
        </div>
    </div>
    <script src="https://vlibras.gov.br/app/vlibras-plugin.js"></script>
    <script>
        new window.VLibras.Widget('https://vlibras.gov.br/app');
    </script>
</head>
<body class="dashboard-page">
    <script>
        document.body.classList.toggle('dark-mode', document.documentElement.classList.contains('dark-mode'));
    </script>
        <div class="header-nav">
            <div class="logo">
                <a href="index.html">
                    <img src="imagens/urbanoide.png" alt="Urbanoide" class="logo-icon">
                    Agente Urbano
                </a>
            </div>
        <nav class="main-menu">
            <a href="dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="mapa.html"><i class="fas fa-map"></i> Mapa</a>
            <a href="relatorios.html" class=""><i class="fas fa-list-alt"></i> Relatórios</a>
        </nav>
        <div class="user-actions">
            <a href="mapa.html" class="new-report-btn">+ Novo Problema</a>
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

        <a href="usuario.html" class="profile-button">
            <img src="https://www.gravatar.com/avatar/?d=mp" alt="Avatar" class="avatar">
            <div>
                <div class="user-name">Minha página</div>
                <div class="profile-subtext">Acessar opções e reports</div>
            </div>
        </a>

        <div class="sidebar-divider"></div>

        <div class="sidebar-section-title">Navegação</div>
        <div class="sidebar-links">
            <a href="dashboard.php" class="sidebar-link"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            <a href="mapa.html" class="sidebar-link"><i class="fas fa-map"></i> Mapa</a>
            <a href="relatorios.html" class="sidebar-link"><i class="fas fa-list-alt"></i> Relatórios</a>
            <a href="settings.html" class="sidebar-link"><i class="fas fa-cog"></i> Configurações</a>
        </div>

        <div class="sidebar-section-title">Ações rápidas</div>
        <div class="sidebar-links">
            <a href="mapa.html" class="sidebar-action primary"><i class="fas fa-flag"></i> Novo problema</a>
        </div>
    </aside>
    <main class="dashboard-content">
        <section class="page-header">
            <h2 style="font-size: 4vw; margin-bottom: 0.5rem;">Dashboard</h2>
            <p class="subtitle">Visão geral dos problemas reportados na cidade</p>
        </section>
        <section class="status-cards">
            <div class="card total"> 
                <h3>Em Análise</h3> 
                <div class="card-main">
                    <span class="value"><?= $in_review_count ?></span> 
                    <i class="fas fa-search icon"></i> 
                </div>
                <p class="detail"><?= calculateBarWidth($in_review_count, $total_reports) ?>% do total</p>
            </div>
            <div class="card pendente">
                <h3>Pendentes</h3>
                <div class="card-main">
                    <span class="value"><?= $pending_count ?></span>
                    <i class="fas fa-exclamation-triangle icon"></i>
                </div>
                <p class="detail"><?= calculateBarWidth($pending_count, $total_reports) ?>% do total</p>
            </div>
            <div class="card andamento">
                <h3>Em Andamento</h3>
                <div class="card-main">
                    <span class="value"><?= $in_progress_count ?></span> 
                    <i class="fas fa-clock icon"></i>
                </div>
                <p class="detail"><?= calculateBarWidth($in_progress_count, $total_reports) ?>% do total</p>
            </div>
            <div class="card resolvido">
                <h3>Resolvidos</h3>
                <div class="card-main">
                    <span class="value"><?= $resolved_count ?></span>
                    <i class="fas fa-check-circle icon"></i>
                </div>
                <p class="detail"><?= $data['taxa_resolucao'] ?>% taxa de resolução</p>
            </div>
        </section>
        <section class="chart-grids">
            <div class="grid-item">
                <h3><i class="fas fa-map-marker-alt"></i> Problemas por Categoria</h3>
                <div class="chart-content">
                    <?php 
                    $category_names_map = [
                        'iluminacao' => 'Iluminação',
                        'asfalto' => 'Asfalto',
                        'limpeza' => 'Limpeza',
                        'agua-esgoto' => 'Água e Esgoto',
                        'transporte' => 'Transporte',
                        'assistencial' => 'Assistencial',
                        'meteorologico' => 'Meteorológico',
                        'mobilidade' => 'Mobilidade',
                        'saude' => 'Saúde',
                        'seguranca' => 'Segurança',
                        'acessibilidade' => 'Acessibilidade',
                        'eletricidade' => 'Eletricidade',
                        'meio-ambiente' => 'Meio Ambiente',
                        'estrutura' => 'Estrutura',
                        'drenagem' => 'Drenagem',
                        'obras' => 'Obras',
                        'ciclismo' => 'Ciclismo',
                        'ma-gestao' => 'Má Gestão',
                        'outros' => 'Outros'
                    ];
                    if (!empty($data['tipos'])):
                        $max_count = max(array_column($data['tipos'], 'count') ?: [1]); 
                        foreach ($data['tipos'] as $item): 
                            $width = calculateBarWidth($item['count'], $max_count); 
                            $db_key = $item['tipo'] ?? $item['category'] ?? 'N/A';
                            $display_name = $category_names_map[$db_key] ?? ucfirst($db_key); 
                    ?>
                    <div class="bar-item">
                        <span class="category"><?= htmlspecialchars($display_name) ?></span>
                        <div class="bar-container">
                            <div class="bar" style="width: <?= $width ?>%;"></div>
                        </div>
                        <span class="bar-value"><?= $item['count'] ?></span>
                    </div>
                    <?php 
                        endforeach; 
                    else:
                        echo "<p>Nenhum dado de categoria encontrado.</p>";
                    endif;
                    ?>
                </div>
            </div>
            <div class="grid-item">
                <h3><i class="fas fa-chart-pie" style="color: var(--primary-color);"></i> Status dos Problemas</h3>
                <div class="chart-content">
                    <div style="max-height: 350px;">
                        <canvas id="statusPieChart"></canvas>
                    </div>
                </div>
            </div>
        </section>
        <section class="chart-grids" style="margin-top: 20px;">
            <div class="grid-item" style="grid-column: 1 / -1;"> 
                <div class="full-width-chart-layout">
                    <div class="chart-summary-card">
                        <h4 style="font-size: 2.2vw;">Relatórios Acumulados</h4>
                        <p style="color: #555a5f; font-size: 1.5vw;">Total Geral de Registros</p>
                        <span class="total-number" style="font-size: 4vw;" ><?= $total_reports ?></span>
                        <p class="card-note" style="color: #555a5f; font-size: 1.5vw;">Crescimento total de problemas reportados ao longo do tempo.</p>
                    </div>
                    <div class="main-chart-area">
                          <h3 style="color: #007BFF;"><i class="fas fa-chart-line"></i> Crescimento de Relatórios</h3>
                        <div style="height: 350px; position: relative;">
                            <canvas id="reportsOverTimeChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>
    <div id="auth-modal" class="modal hidden">
        <div class="modal-content profile-modal"> 
            <span class="auth-close-btn">&times;</span>
            <h2 id="auth-title">Entrar</h2>
            <div class="profile-image-container" style="display: none; text-align: center; margin-bottom: 15px;">
                <img id="profile-image" src="https://www.gravatar.com/avatar/?d=mp" alt="Imagem de Perfil" style="width: 100px; height: 100px; border-radius: 50%; object-fit: cover;">
                <input type="file" id="profile-image-input" accept="image/*" style="display: none;">
                <div class="image-controls" style="margin-top: 10px;">
                    <button type="button" id="change-image-btn" style="display: none;">Escolher Foto</button>
                    <button type="button" id="take-photo-btn" style="display: none;">Tirar Foto</button>
                </div>
                <video id="profile-video" autoplay style="display: none; width: 100%; max-width: 200px; margin-top: 10px;"></video>
                <button type="button" id="capture-photo-btn" style="display: none; margin-top: 5px;">Capturar Foto</button>
            </div>
            <form id="auth-form">
                <div class="form-group">
                    <label for="auth-username">Nome de Usuário</label>
                    <input type="text" id="auth-username" name="username" required>
                </div>
                <div class="form-group">
                    <label for="auth-password">Senha</label>
                    <input type="password" id="auth-password" name="password" required>
                </div>
                <button type="submit" id="auth-submit">Entrar</button>
                <button type="button" id="switch-to-register">Registrar</button>
            </form>
        </div>
    </div>
    <script>
        const THEME_STORAGE_KEY = 'agenteurbano_theme';

        function isDarkThemeActive() {
            const savedTheme = localStorage.getItem(THEME_STORAGE_KEY);
            const legacyTheme = localStorage.getItem('theme');
            const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
            const theme = savedTheme || legacyTheme;
            return theme ? theme === 'dark' : prefersDark;
        }

        function applyStoredTheme() {
            const useDark = isDarkThemeActive();
            document.body.classList.toggle('dark-mode', useDark);
            document.documentElement.classList.toggle('dark-mode', useDark);
            return useDark;
        }

        const dashboardDarkMode = applyStoredTheme();
        const chartTextColor = dashboardDarkMode ? '#e5e7eb' : '#343a40';
        const chartMutedColor = dashboardDarkMode ? '#9ca3af' : '#6c757d';
        const chartGridColor = dashboardDarkMode ? 'rgba(148, 163, 184, 0.18)' : 'rgba(0, 0, 0, 0.1)';

        if (window.Chart) {
            Chart.defaults.color = chartTextColor;
            Chart.defaults.borderColor = chartGridColor;
        }

        const labelsPie = <?= $chart_labels ?>;
        const dataValuesPie = <?= $chart_data ?>;
        const backgroundColorsPie = <?= $chart_colors_json ?>;
        const ctxPie = document.getElementById('statusPieChart');
        if (ctxPie) {
            new Chart(ctxPie, {
                type: 'pie',
                data: {
                    labels: labelsPie, 
                    datasets: [{
                        label: 'Número de Problemas',
                        data: dataValuesPie, 
                        backgroundColor: backgroundColorsPie,
                        hoverOffset: 4
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false, 
                    plugins: {
                        legend: {
                            position: 'right',
                            labels: {
                                color: chartTextColor
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    let label = context.label || '';
                                    if (label) {
                                        label += ': ';
                                    }
                                    if (context.parsed !== null) {
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const value = context.parsed;
                                        const percentage = (total > 0) ? ((value / total) * 100).toFixed(1) + '%' : '0%';
                                        label += value + ' (' + percentage + ')';
                                    }
                                    return label;
                                }
                            }
                        }
                    }
                }
            });
        }
        function fetchReportsOverTime() {
            fetch('api.php?action=reports_over_time')
                .then(r => r.json())
                .then(data => {
                    if (data.success && data.labels && data.cumulative_counts) {
                        renderReportsOverTimeChart(data.labels, data.cumulative_counts);
                    } else {
                        console.error('Erro ao carregar dados do gráfico de tempo:', data.message);
                    }
                }).catch(err => console.error('Erro de rede ao carregar dados do gráfico de tempo:', err));
        }
        function renderReportsOverTimeChart(labels, cumulativeCounts) {
            const ctxLine = document.getElementById('reportsOverTimeChart');
            if (!ctxLine) return; 
            new Chart(ctxLine, {
                type: 'line',
                data: {
                    labels: labels, 
                    datasets: [{
                        label: 'Total Acumulado de Relatórios',
                        data: cumulativeCounts, 
                        borderColor: 'rgb(0, 123, 255)', 
                        backgroundColor: 'rgba(0, 123, 255, 0.1)', 
                        fill: true, 
                        tension: 0.3, 
                        pointBackgroundColor: 'rgb(0, 123, 255)',
                        pointRadius: 3,
                        pointHoverRadius: 5
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false, 
                    plugins: {
                        legend: {
                            display: false 
                        },
                        title: {
                            display: false 
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: {
                                color: chartTextColor
                            },
                            grid: {
                                color: chartGridColor
                            },
                            title: {
                                display: true,
                                color: chartMutedColor,
                                text: 'Nº de Relatórios'
                            }
                        },
                        x: {
                            ticks: {
                                color: chartTextColor
                            },
                            grid: {
                                color: chartGridColor
                            },
                            title: {
                                display: true,
                                color: chartMutedColor,
                                text: 'Data'
                            },
                            type: 'category', 
                        }
                    }
                }
            });
        }
        fetchReportsOverTime();
        let currentUser = null;
        const profileBtn = document.getElementById('profile-btn');
        const userNameText = document.getElementById('user-name-text');
        const userAvatar = document.getElementById('user-avatar');
        const authModal = document.getElementById('auth-modal');
        const authForm = document.getElementById('auth-form');
        const authTitle = document.getElementById('auth-title');
        const switchToRegisterBtn = document.getElementById('switch-to-register');
        const authCloseBtn = document.querySelector('.auth-close-btn');
        const profileImageInput = document.getElementById('profile-image-input');
        const changeImageBtn = document.getElementById('change-image-btn');
        const profileImage = document.getElementById('profile-image');
        const takePhotoBtn = document.getElementById('take-photo-btn');
        const profileVideo = document.getElementById('profile-video');
        const capturePhotoBtn = document.getElementById('capture-photo-btn');
        let mediaStream = null;
        let authMode = 'login'; 

        function initSidebarMenu() {
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

            sidebar.querySelectorAll('.sidebar-link, .sidebar-action').forEach(el => {
                el.addEventListener('click', closeSidebar);
            });

            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') closeSidebar();
            });
        }

        function updateAuthUI() {
            if (!profileBtn || !userNameText || !userAvatar) return;
            if (currentUser) {
                userNameText.textContent = currentUser;
                profileBtn.style.cursor = 'pointer';
                
                const savedAvatar = localStorage.getItem(`avatar_${currentUser}`);
                if (savedAvatar) {
                    userAvatar.src = savedAvatar;
                    if (profileImage) profileImage.src = savedAvatar;
                } else {
                    userAvatar.src = 'https://www.gravatar.com/avatar/?d=mp';
                    if (profileImage) profileImage.src = 'https://www.gravatar.com/avatar/?d=mp';
                }
            } else {
                userNameText.textContent = 'Entrar';
                userAvatar.src = 'https://www.gravatar.com/avatar/?d=mp';
            }
        } 
        
        function fetchCurrentUser() {
            fetch('api.php?action=current_user')
                .then(r => r.json())
                .then(data => {
                    currentUser = data.username || null;
                    updateAuthUI();
                });
        }
        
        if (takePhotoBtn && profileVideo && capturePhotoBtn) {
            takePhotoBtn.addEventListener('click', async () => {
                try {
                    mediaStream = await navigator.mediaDevices.getUserMedia({ video: true });
                    profileVideo.srcObject = mediaStream;
                    profileVideo.style.display = 'block';
                    capturePhotoBtn.style.display = 'inline-block';
                    if (profileImage) profileImage.style.display = 'none';
                } catch (err) {
                    alert('Não foi possível acessar a câmera: ' + err.message);
                }
            });

            capturePhotoBtn.addEventListener('click', () => {
                const canvas = document.createElement('canvas');
                canvas.width = profileVideo.videoWidth;
                canvas.height = profileVideo.videoHeight;
                const ctx = canvas.getContext('2d');
                ctx.drawImage(profileVideo, 0, 0, canvas.width, canvas.height);
                const imageDataUrl = canvas.toDataURL('image/png');
                if (profileImage) {
                    profileImage.src = imageDataUrl;
                    profileImage.style.display = 'block';
                }
                profileVideo.style.display = 'none';
                capturePhotoBtn.style.display = 'none';
                if (mediaStream) {
                    mediaStream.getTracks().forEach(track => track.stop());
                }
            });
        }
        
        if (profileBtn) profileBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const authUsernameInput = document.getElementById('auth-username'); 
            const profileImageContainer = document.querySelector('.profile-image-container');

            if (currentUser) {
                authMode = 'profile'; 
                authTitle.textContent = 'Meu Perfil';
                authUsernameInput.value = currentUser;
                
                authUsernameInput.disabled = true; 
                if (changeImageBtn) changeImageBtn.style.display = 'none'; 
                if (takePhotoBtn) takePhotoBtn.style.display = 'none'; 
                if (profileVideo) profileVideo.style.display = 'none';
                if (capturePhotoBtn) capturePhotoBtn.style.display = 'none';

                document.getElementById('auth-password').closest('.form-group').style.display = 'none';
                document.getElementById('auth-submit').style.display = 'none';
                switchToRegisterBtn.textContent = 'Deslogar';
                if (profileImageContainer) profileImageContainer.style.display = 'block';
                
            } else {
                authMode = 'login';
                if (authTitle) authTitle.textContent = 'Entrar';
                authUsernameInput.value = '';

                authUsernameInput.disabled = false;
                if (changeImageBtn) changeImageBtn.style.display = 'none'; 
                if (takePhotoBtn) takePhotoBtn.style.display = 'none'; 
                if (profileVideo) profileVideo.style.display = 'none';
                if (capturePhotoBtn) capturePhotoBtn.style.display = 'none';


                document.getElementById('auth-password').closest('.form-group').style.display = 'block';
                document.getElementById('auth-submit').textContent = 'Entrar';
                document.getElementById('auth-submit').style.display = 'block';
                switchToRegisterBtn.textContent = 'Registrar';
                if (profileImageContainer) profileImageContainer.style.display = 'none';
            }
            if (authModal) {
                authModal.classList.remove('hidden');
            }
        });


        if (authCloseBtn) authCloseBtn.addEventListener('click', () => { 
            if (authModal) {
                authModal.classList.add('hidden');
            }
        });

        if (authModal) {
            authModal.addEventListener('click', (e) => {
                if (e.target === authModal) {
                    authModal.classList.add('hidden');
                }
            });
        }

        if (switchToRegisterBtn) switchToRegisterBtn.addEventListener('click', () => {
            if (authMode === 'profile') {
                if (confirm('Deseja realmente sair?')) {
                    fetch('api.php?action=logout').then(r => r.json()).then(() => {
                        currentUser = null;
                        window.location.reload();
                    });
                }
                return;
            }

            authMode = authMode === 'login' ? 'register' : 'login';
            if (authTitle) authTitle.textContent = authMode === 'login' ? 'Entrar' : 'Registrar';
            
            const imageContainer = document.querySelector('.profile-image-container');
            const isRegister = authMode === 'register';

            if (imageContainer) imageContainer.style.display = isRegister ? 'block' : 'none';
            
            if (changeImageBtn) changeImageBtn.style.display = isRegister ? 'inline-block' : 'none';
            if (takePhotoBtn) takePhotoBtn.style.display = isRegister ? 'inline-block' : 'none';
            document.getElementById('auth-username').disabled = false; 

            document.getElementById('auth-password').closest('.form-group').style.display = 'block';

            const submitBtn = document.getElementById('auth-submit');
            if (submitBtn) submitBtn.textContent = authMode === 'login' ? 'Entrar' : 'Registrar';
            if (submitBtn) submitBtn.style.display = 'block'; 

            switchToRegisterBtn.textContent = authMode === 'login' ? 'Registrar' : 'Entrar';
        });

        if (changeImageBtn) changeImageBtn.addEventListener('click', () => {
            profileImageInput.click();
        });
        
        if (profileImageInput) {
            profileImageInput.addEventListener('change', function(e) {
                const file = this.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const imageDataUrl = e.target.result;
                        profileImage.src = imageDataUrl;
                        profileImage.style.display = 'block';
                        if (profileVideo) profileVideo.style.display = 'none';
                        if (capturePhotoBtn) capturePhotoBtn.style.display = 'none';
                        if (currentUser) {
                            localStorage.setItem(`avatar_${currentUser}`, imageDataUrl);
                            userAvatar.src = imageDataUrl;
                        }
                    }
                    reader.readAsDataURL(file);
                }
            });
        }
        
        if (authForm) authForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const formData = new FormData(authForm);
            const url = authMode === 'login' ? 'api.php?action=login' : 'api.php?action=register';
            
            if (authMode === 'register' && profileImage.src && !profileImage.src.includes('gravatar')) {
                const dataURL = profileImage.src;
                const arr = dataURL.split(',');
                const mime = arr[0].match(/:(.*?);/)[1];
                const bstr = atob(arr[1]);
                let n = bstr.length;
                const u8arr = new Uint8Array(n);

                while(n--){
                    u8arr[n] = bstr.charCodeAt(n);
                }

                const file = new File([u8arr], "profile_photo.png", {type: mime});
                formData.append('avatar_file', file);
            }
            
            fetch(url, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        currentUser = res.username;
                        if (profileImage.src !== 'https://www.gravatar.com/avatar/?d=mp' && profileImage.src) {
                            localStorage.setItem(`avatar_${currentUser}`, profileImage.src);
                        }
                        alert(res.message);
                        window.location.reload();
                    } else {
                        alert(res.message || 'Erro no login/registro');
                    }
                }).catch(err => console.error(err));
        });

        initSidebarMenu();
        fetchCurrentUser();
    </script>
    
    <style>
        body.dark-mode.dashboard-page {
            --primary-color: #0ea5e9;
            --secondary-color: #9ca3af;
            --success-color: #22c55e;
            --dark-text: #e5e7eb;
            --border-color: #1f2937;
            --info-color: #0ea5e9;
            --light-bg: #0b1220;
            background-color: #0b1220;
            color: #e5e7eb;
        }

        body.dark-mode.dashboard-page .dashboard-content {
            background:
                radial-gradient(circle at 10% 20%, rgba(14, 165, 233, 0.08) 1px, transparent 1px),
                radial-gradient(circle at 90% 80%, rgba(34, 197, 94, 0.06) 1px, transparent 1px),
                #0b1220;
            background-size: 40px 40px;
            min-height: calc(100vh - 12vh);
        }

        body.dark-mode.dashboard-page .page-header h2,
        body.dark-mode.dashboard-page .grid-item h3,
        body.dark-mode.dashboard-page .chart-summary-card h4,
        body.dark-mode.dashboard-page .bar-item .category,
        body.dark-mode.dashboard-page .bar-item .bar-value,
        body.dark-mode.dashboard-page .card-main .value {
            color: #e5e7eb !important;
        }

        body.dark-mode.dashboard-page .page-header .subtitle,
        body.dark-mode.dashboard-page .card h3,
        body.dark-mode.dashboard-page .card .detail,
        body.dark-mode.dashboard-page .chart-summary-card p,
        body.dark-mode.dashboard-page .chart-summary-card .card-note {
            color: #9ca3af !important;
        }

        body.dark-mode.dashboard-page .card,
        body.dark-mode.dashboard-page .grid-item,
        body.dark-mode.dashboard-page .chart-summary-card {
            background: #0f172a;
            border: 1px solid #1f2937;
            box-shadow: 0 18px 45px rgba(0, 0, 0, 0.35);
        }

        body.dark-mode.dashboard-page .grid-item h3 {
            border-bottom-color: #1f2937;
        }

        body.dark-mode.dashboard-page .bar-container {
            background-color: #111827;
        }

        body.dark-mode.dashboard-page .chart-summary-card .total-number,
        body.dark-mode.dashboard-page .main-chart-area h3,
        body.dark-mode.dashboard-page .grid-item h3 i.fa-chart-pie {
            color: #0ea5e9 !important;
        }

        body.dark-mode.dashboard-page .modal {
            background-color: rgba(0, 0, 0, 0.68);
        }

        body.dark-mode.dashboard-page .modal-content,
        body.dark-mode.dashboard-page .profile-modal {
            background: #0f172a;
            border: 1px solid #1f2937;
            color: #e5e7eb;
            box-shadow: 0 18px 45px rgba(0, 0, 0, 0.55);
        }

        body.dark-mode.dashboard-page .modal-content h2,
        body.dark-mode.dashboard-page .modal-content label {
            color: #e5e7eb;
            border-color: #1f2937;
        }

        body.dark-mode.dashboard-page .modal-content input[type="text"],
        body.dark-mode.dashboard-page .modal-content input[type="password"] {
            background: #111827;
            border-color: #1f2937;
            color: #e5e7eb;
        }

        body.dark-mode.dashboard-page .auth-close-btn {
            color: #9ca3af;
        }

        body.dark-mode.dashboard-page .auth-close-btn:hover {
            color: #e5e7eb;
        }

        html.dark-mode,
        body.dark-mode.dashboard-page {
            scrollbar-color: var(--primary-color) #0f172a;
            scrollbar-width: thin;
        }

        html.dark-mode::-webkit-scrollbar,
        body.dark-mode.dashboard-page::-webkit-scrollbar,
        body.dark-mode.dashboard-page *::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }

        html.dark-mode::-webkit-scrollbar-track,
        body.dark-mode.dashboard-page::-webkit-scrollbar-track,
        body.dark-mode.dashboard-page *::-webkit-scrollbar-track {
            background: #0f172a;
        }

        html.dark-mode::-webkit-scrollbar-thumb,
        body.dark-mode.dashboard-page::-webkit-scrollbar-thumb,
        body.dark-mode.dashboard-page *::-webkit-scrollbar-thumb {
            background-color: var(--primary-color);
            border-radius: 10px;
            border: 2px solid #0b1220;
        }

        html.dark-mode::-webkit-scrollbar-thumb:hover,
        body.dark-mode.dashboard-page::-webkit-scrollbar-thumb:hover,
        body.dark-mode.dashboard-page *::-webkit-scrollbar-thumb:hover {
            background-color: #38bdf8;
        }

        body.dark-mode.dashboard-page * {
            scrollbar-color: var(--primary-color) #0f172a;
        }

        .full-width-chart-layout {
            display: flex;
            flex-wrap: wrap; 
            gap: 20px;
        }
        .chart-summary-card {
            flex-basis: 240px; 
            flex-grow: 1; 
            background-color: #f8f9fa;
            border-radius: 8px;
            padding: 20px;
            border: 1px solid #e9ecef;
            text-align: center;
        }
        .chart-summary-card h4 {
            margin-top: 0;
            font-size: 1.1em;
            color: #333;
        }
        .chart-summary-card p {
            font-size: 0.9em;
            color: #6c757d;
            margin: 5px 0;
        }
        .chart-summary-card .total-number {
            font-size: 2.5em;
            font-weight: 700;
            color: var(--primary-color, #007bff); 
            display: block;
            margin: 10px 0;
        }
        .chart-summary-card .card-note {
            font-size: 0.8em;
            color: #adb5bd;
        }
        .main-chart-area {
            flex-basis: 300px; 
            flex-grow: 3; 
            min-height: 350px;
        }
        .main-chart-area h3 {
             margin-top: 0;
             margin-bottom: 15px;
             font-size: 1.4em;
             color: #333;
        }
    </style>
</body>
</html>
