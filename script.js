/*
    script.js - Lógica principal do lado do cliente para a aplicação de mapa

    Responsabilidades:
    - Inicializar o mapa Leaflet e suas camadas
    - Carregar problemas a partir de `api.php` e renderizar marcadores
    - Fornecer helpers de UI: modais, toasts e construtores de formulários
    - Tratar criação/edição/remoção de relatórios e interações com endpoints do servidor
    - Integrar Street View e Places Autocomplete do Google para a busca

    Observações:
    - Manter as funções helper de UI (showToast, showMessage, createFormModal, createConfirmModal)
      genéricas para reutilização em diferentes fluxos.
*/

let map;
let newProblemMarker = null;
let isSelectingLocation = false;
let problemsLayerGroup = null;
let geocoder;
let streetLayer;
let satelliteLayer;
let currentUser = null;
let panorama;
let streetViewMarkers = [];
let allProblemsData = [];
let ownedReportIds = [];
let isMapInitialized = false;
let isAutocompleteInitialized = false;

// cached coordenadas do usuário para usar em recenter rápido
let userCoords = null;

const STREET_VIEW_RADIUS = 200;
const PIN_PATH = 'M 12,2 C 8.13,2 5,5.13 5,9 c 0,5.25 7,13 7,13 s 7,-7.75 7,-13 c 0,-3.87 -3.13,-7 -7,-7 z';
const svModal = document.getElementById('streetview-modal');
const svBtn = document.getElementById('mode-streetview-btn');
const svCloseBtn = document.getElementById('close-streetview-btn');
const svPanoDiv = document.getElementById('streetview-pano');
// helpers leves de UI (modais/toasts) para substituir alert/prompt/confirm nativos
const uiMessageModal = document.getElementById('ui-message-modal');
const uiMessageTitle = document.getElementById('ui-message-title');
const uiMessageBody = document.getElementById('ui-message-body');
const uiToastContainer = document.getElementById('ui-toast-container');

/**
 * showToast
 * Exibe uma notificação transitória (toast) na UI.
 *
 * Propósito:
 * - Substituir usos de `alert()` simples com uma experiência non-blocking.
 * - Usado para feedback rápido de sucesso/erro/aviso ao usuário.
 *
 * Parâmetros:
 * - text (string): texto a ser exibido no toast.
 * - type (string): tipo de estilo (ex.: 'info', 'success', 'error') — controla classes CSS.
 * - timeout (number): tempo em ms até ocultar automaticamente.
 *
 * Efeitos colaterais:
 * - Adiciona/removes elementos DOM em `uiToastContainer`.
 * - Não retorna valor.
 */
function showToast(text, type = 'info', timeout = 3000) {
    if (!uiToastContainer) {
        console.log(text);
        return;
    }
    const toast = document.createElement('div');
    toast.className = `ui-toast ${type}`;
    toast.innerHTML = `<span>${text}</span>`;
    uiToastContainer.appendChild(toast);
    requestAnimationFrame(() => toast.classList.add('show'));
    setTimeout(() => {
        toast.classList.remove('show');
        setTimeout(() => { try { uiToastContainer.removeChild(toast); } catch (e) { } }, 300);
    }, timeout);
}

/**
 * showMessage
 * Abre um modal simples de mensagem com título e conteúdo HTML.
 *
 * Parâmetros:
 * - title (string): título do modal.
 * - html (string|Node): conteúdo HTML ou texto a ser exibido.
 * - options (object): opções adicionais, p.ex. { onClose: fn, autoClose: true, timeout: 3000 }
 *
 * Comportamento:
 * - Se não houver o elemento `uiMessageModal` no DOM, cai para `alert()` como fallback.
 * - Chama `options.onClose()` quando o modal é fechado.
 */
function showMessage(title, html, options = {}) {
    if (!uiMessageModal) {
        alert(title + '\n\n' + (typeof html === 'string' ? html.replace(/<[^>]+>/g, '') : ''));
        if (options.onClose) options.onClose();
        return;
    }
    uiMessageTitle.innerText = title || 'Mensagem';
    uiMessageBody.innerHTML = typeof html === 'string' ? html : String(html || '');
    uiMessageModal.classList.remove('hidden');
    uiMessageModal.setAttribute('aria-hidden', 'false');

    const okBtn = uiMessageModal.querySelector('.ui-message-ok');
    const closeBtn = uiMessageModal.querySelector('.ui-message-close');

    function cleanup() {
        uiMessageModal.classList.add('hidden');
        uiMessageModal.setAttribute('aria-hidden', 'true');
        okBtn.removeEventListener('click', cleanup);
        closeBtn.removeEventListener('click', cleanup);
        if (options.onClose) options.onClose();
    }

    okBtn.addEventListener('click', cleanup);
    closeBtn.addEventListener('click', cleanup);

    if (options.autoClose) {
        setTimeout(() => { if (!uiMessageModal.classList.contains('hidden')) cleanup(); }, options.timeout || 3500);
    }
}

/**
 * createFormModal
 * Cria dinamicamente um modal com um formulário simples com campos definidos por `fields`.
 *
 * Uso / formato:
 * - `fields` é um array de objetos: { name, label, type?, value?, preview? }
 *   - type pode ser 'text' (padrão), 'textarea' ou 'file'.
 *   - preview (string) para `file` exibe imagem inicial (URL) como preview.
 * - `onSubmit(values, { close, files })` é chamado quando o usuário confirma.
 *   - `values` contém pares name->valor (strings) para inputs/textarea.
 *   - `files` contém pares name->File (ou null) para campos do tipo file.
 *
 * Observações importantes:
 * - O modal é construído como elemento DOM independente e removido após fechar.
 * - Usa `FileReader` apenas para preview no cliente; upload real deve ser feito com FormData.
 */
function createFormModal(title, fields, onSubmit) {
    const overlay = document.createElement('div');
    overlay.className = 'modal';
    overlay.style.zIndex = 4000;
    const content = document.createElement('div');
    content.className = 'modal-content';
    content.style.maxWidth = '520px';

    const closeBtn = document.createElement('span');
    closeBtn.className = 'close-btn';
    closeBtn.innerHTML = '&times;';
    content.appendChild(closeBtn);

    const h2 = document.createElement('h2');
    h2.innerText = title;
    content.appendChild(h2);

    const form = document.createElement('div');
    form.style.marginTop = '10px';

    const inputs = {};
    const files = {};

    fields.forEach(f => {
        const label = document.createElement('label');
        label.innerText = f.label;
        form.appendChild(label);

        if (f.type === 'textarea') {
            const ta = document.createElement('textarea');
            ta.rows = 4;
            ta.value = f.value || '';
            ta.style.width = '100%';
            ta.style.marginBottom = '8px';
            inputs[f.name] = ta;
            form.appendChild(ta);
            return;
        }

        if (f.type === 'file') {
            // image preview (optional)
            const preview = document.createElement('img');
            preview.className = 'modal-preview';
            if (f.preview) {
                preview.src = f.preview;
                preview.style.display = 'block';
            } else {
                preview.style.display = 'none';
            }
            form.appendChild(preview);

            const fileInput = document.createElement('input');
            fileInput.type = 'file';
            fileInput.accept = 'image/*';
            fileInput.style.width = '100%';
            fileInput.style.marginBottom = '8px';
            fileInput.addEventListener('change', () => {
                const file = fileInput.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = (ev) => {
                        preview.src = ev.target.result;
                        preview.style.display = 'block';
                    };
                    reader.readAsDataURL(file);
                } else {
                    if (f.preview) {
                        preview.src = f.preview;
                        preview.style.display = 'block';
                    } else {
                        preview.style.display = 'none';
                    }
                }
            });
            inputs[f.name] = fileInput;
            files[f.name] = null;
            form.appendChild(fileInput);
            return;
        }

        // default input
        const input = document.createElement('input');
        input.type = f.type || 'text';
        input.value = f.value || '';
        input.style.width = '100%';
        input.style.marginBottom = '8px';
        inputs[f.name] = input;
        form.appendChild(input);
    });

    content.appendChild(form);

    const footer = document.createElement('div');
    footer.style.textAlign = 'right';
    footer.style.marginTop = '10px';

    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'cancel-btn';
    cancelBtn.innerText = 'Cancelar';
    cancelBtn.style.marginRight = '8px';

    const saveBtn = document.createElement('button');
    saveBtn.className = 'submit-btn';
    saveBtn.innerText = 'Salvar';

    footer.appendChild(cancelBtn);
    footer.appendChild(saveBtn);
    content.appendChild(footer);

    overlay.appendChild(content);
    document.body.appendChild(overlay);

    function close() { try { document.body.removeChild(overlay); } catch (e) { } }

    closeBtn.addEventListener('click', close);
    cancelBtn.addEventListener('click', close);

    saveBtn.addEventListener('click', () => {
        const values = {};
        const selectedFiles = {};
        Object.keys(inputs).forEach(k => {
            const el = inputs[k];
            if (!el) return;
            if (el.type === 'file') {
                selectedFiles[k] = el.files && el.files[0] ? el.files[0] : null;
            } else if (el.tagName && el.tagName.toLowerCase() === 'textarea') {
                values[k] = el.value;
            } else {
                values[k] = el.value;
            }
        });
        onSubmit(values, { close, files: selectedFiles });
    });

    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
}

/**
 * createConfirmModal
 * Modal genérico de confirmação (OK / Cancel).
 *
 * Parâmetros:
 * - title (string): título do modal.
 * - message (string|HTML): mensagem a ser exibida.
 * - onConfirm (function): callback executado quando o usuário confirma.
 *
 * Observações:
 * - Chamadas que exigem confirmação do usuário (ex: exclusão) devem usar esse modal
 *   para evitar `confirm()` nativo e bloquear a UI.
 */
function createConfirmModal(title, message, onConfirm) {
    const overlay = document.createElement('div');
    overlay.className = 'modal';
    overlay.style.zIndex = 4000;
    const content = document.createElement('div');
    content.className = 'modal-content';
    content.style.maxWidth = '480px';
    const closeBtn = document.createElement('span');
    closeBtn.className = 'close-btn';
    closeBtn.innerHTML = '&times;';
    content.appendChild(closeBtn);
    const h2 = document.createElement('h2');
    h2.innerText = title;
    content.appendChild(h2);
    const p = document.createElement('p');
    p.style.marginTop = '8px';
    p.innerHTML = message;
    content.appendChild(p);
    const footer = document.createElement('div');
    footer.style.textAlign = 'right';
    footer.style.marginTop = '14px';
    const cancelBtn = document.createElement('button');
    cancelBtn.className = 'cancel-btn';
    cancelBtn.innerText = 'Cancelar';
    cancelBtn.style.marginRight = '8px';
    const okBtn = document.createElement('button');
    okBtn.className = 'submit-btn';
    okBtn.innerText = 'Confirmar';
    footer.appendChild(cancelBtn);
    footer.appendChild(okBtn);
    content.appendChild(footer);
    overlay.appendChild(content);
    document.body.appendChild(overlay);

    function close() { try { document.body.removeChild(overlay); } catch (e) { } }
    closeBtn.addEventListener('click', close);
    cancelBtn.addEventListener('click', close);
    okBtn.addEventListener('click', () => { onConfirm(); close(); });
    overlay.addEventListener('click', (e) => { if (e.target === overlay) close(); });
}
const reportModal = document.getElementById('report-modal');
const reportForm = document.getElementById('report-form');
const novoRelatorioBtn = document.getElementById('novo-relatorio-btn');
const closeBtn = document.querySelector('.report-close-btn');
const selectOnMapBtn = document.getElementById('select-on-map-btn');
const cancelBtn = document.getElementById('cancel-report');
const uploadBtnStyled = document.getElementById('upload-btn-styled');
const imagemUploadInput = document.getElementById('imagem_upload');
const fileNameDisplay = document.getElementById('file-name-display');
const imagemLabel = document.querySelector('label[for="imagem_upload"]');
const formLatitude = document.getElementById('form-latitude');
const formLongitude = document.getElementById('form-longitude');
const enderecoInput = document.getElementById('endereco');
const photonSearchContainer = document.getElementById('photon-search-container');
const searchInput = document.getElementById('photon-search');
const searchResults = document.getElementById('photon-results');
const modeStreetBtn = document.getElementById('mode-street-btn');
const modeSatBtn = document.getElementById('mode-sat-btn');
const recenterBtn = document.getElementById('recenter-btn');
const profileBtn = document.getElementById('profile-btn');
const userNameText = document.getElementById('user-name-text');
const userAvatar = document.getElementById('user-avatar');
const authModal = document.getElementById('auth-modal');
const authForm = document.getElementById('auth-form');
const authTitle = document.getElementById('auth-title');
const switchToRegisterBtn = document.getElementById('switch-to-register');
const authCloseBtn = document.querySelector('.auth-close-btn');
const googleLoginBtn = document.getElementById('google-login');
const profileImageInput = document.getElementById('profile-image-input');
const changeImageBtn = document.getElementById('change-image-btn');
const profileImage = document.getElementById('profile-image');
const takePhotoBtn = document.getElementById('take-photo-btn');
const profileVideo = document.getElementById('profile-video');
const capturePhotoBtn = document.getElementById('capture-photo-btn');
const filterSidebar = document.getElementById('filter-sidebar');
const openFilterBtn = document.getElementById('open-filter-btn');
const closeFilterBtn = document.getElementById('close-filter-btn');
const applyFilterBtn = document.getElementById('apply-filter-btn');
const clearFilterBtn = document.getElementById('clear-filter-btn');
const filterCategory = document.getElementById('filter-category');
const filterStatus = document.getElementById('filter-status');
const filterCity = document.getElementById('filter-city');
const filterSort = document.getElementById('filter-sort');



const problemColors = {
    'iluminacao': '#FFC107',
    'asfalto': '#0056b3',
    'limpeza': '#28A745',
    'agua-esgoto': '#DC3545',
    'transporte': '#FF6B35',
    'outros': '#6C757D',
    'assistencial': '#E91E63',
    'meteorologico': '#2196F3',
    'mobilidade': '#FF9800',
    'saude': '#4CAF50',
    'seguranca': '#F44336',
    'acessibilidade': '#9C27B0',
    'eletricidade': '#FFEB3B',
    'meio-ambiente': '#00BCD4',
    'estrutura': '#795548',
    'drenagem': '#3F51B5',
    'obras': '#FF5722',
    'ciclismo': '#8BC34A',
    'ma-gestao': '#673AB7',
    'pendente': '#dc3545',
    'em_analise': '#ffc107',
    'resolvido': '#28a745'
};

const problemIcons = {
    'iluminacao': 'fa-lightbulb',
    'asfalto': 'fa-road',
    'limpeza': 'fa-trash-alt',
    'agua-esgoto': 'fa-water',
    'transporte': 'fa-bus',
    'assistencial': 'fa-hand-holding-heart',
    'meteorologico': 'fa-cloud',
    'mobilidade': 'fa-person-walking',
    'saude': 'fa-hospital',
    'seguranca': 'fa-shield-alt',
    'acessibilidade': 'fa-wheelchair',
    'eletricidade': 'fa-bolt',
    'meio-ambiente': 'fa-leaf',
    'estrutura': 'fa-building',
    'drenagem': 'fa-droplet',
    'obras': 'fa-tools',
    'ciclismo': 'fa-bicycle',
    'ma-gestao': 'fa-exclamation-triangle',
    'outros': 'fa-map-marker-alt'
};

const DEFAULT_COORDS = [-23.5505, -46.6333];
const INITIAL_ZOOM = 12;


/**
 * initMap
 * Inicializa o mapa Leaflet, adiciona camadas base (rua/satélite), inicializa
 * grupos de camadas e dispara carregamento inicial de dados.
 *
 * Fluxo:
 * 1. Cria mapa em `#map` com coordenadas e zoom iniciais.
 * 2. Registra camadas de rua e satélite e define camada padrão.
 * 3. Cria `problemsLayerGroup` para agrupar os marcadores.
 * 4. Centraliza no usuário (se disponível) e carrega relatórios via `loadProblems()`.
 * 5. Inicializa autocomplete e controles de modo/filtragem.
 *
 * Nota: essa função é o ponto de entrada principal invocado ao carregar a página do mapa.
 */
function initMap() {
    if (isMapInitialized) {
        initAutocomplete();
        return;
    }

    if (typeof L === 'undefined') {
        console.error('Leaflet não foi carregado. Verifique os scripts externos do mapa.');
        return;
    }

    const mapElement = document.getElementById('map');
    if (!mapElement) {
        console.error('Elemento #map não encontrado no DOM.');
        return;
    }

    isMapInitialized = true;
    // inicialização normal do mapa; animações de zoom/fade ativadas para evitar bugs
    map = L.map(mapElement, {
        zoomAnimation: true,
        markerZoomAnimation: true,
        fadeAnimation: true,
        inertia: true,
        wheelDebounceTime: 16,
        wheelPxPerZoomLevel: 80
    }).setView(DEFAULT_COORDS, INITIAL_ZOOM);

    const fastTileOpts = {
        maxZoom: 19,
        keepBuffer: 10,           // mantém um anel bem maior de tiles ao redor
        unloadInvisibleTiles: false, // não descarta tiles fora da tela (menos “cinza”, mais memória)
        updateWhenIdle: false,    // continua baixando enquanto arrasta
        updateWhenZooming: true,  // pré-carrega durante zoom/fly
        updateInterval: 50,       // agenda updates de forma mais agressiva
        subdomains: ['a', 'b', 'c'],
        crossOrigin: true,
        attribution: ''
    };

    streetLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        ...fastTileOpts,
        attribution: '&copy; <a href="http://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
    });

    satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        ...fastTileOpts,
        attribution: 'Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, swisstopo, and the GIS User Community'
    });

    streetLayer.addTo(map);

    problemsLayerGroup = L.layerGroup().addTo(map);

    // pedir coordenadas iniciais em background para cache
    locateUserAndCenterMap(map, { animate: false });

    loadProblems();

    setupModeSwitching();


    initAutocomplete();
}

window.initMap = initMap;

/**
 * Alterna entre o modo Satélite e o modo Rua/Normal.
 * @param {string} mode 'street' ou 'satellite'
 */
function switchMapMode(mode) {
    if (mode === 'satellite') {
        if (map.hasLayer(streetLayer)) {
            map.removeLayer(streetLayer);
        }
        satelliteLayer.addTo(map);
        modeSatBtn.classList.add('active-mode');
        modeStreetBtn.classList.remove('active-mode');
    } else {
        if (map.hasLayer(satelliteLayer)) {
            map.removeLayer(satelliteLayer);
        }
        streetLayer.addTo(map);
        modeStreetBtn.classList.add('active-mode');
        modeSatBtn.classList.remove('active-mode');
    }
}

function setupModeSwitching() {
    if (modeStreetBtn && modeSatBtn) {
        modeStreetBtn.addEventListener('click', () => switchMapMode('street'));
        modeSatBtn.addEventListener('click', () => switchMapMode('satellite'));
        modeStreetBtn.classList.add('active-mode');
    } else {
        console.error("Botões de troca de modo do mapa (mode-street-btn ou mode-sat-btn) não foram encontrados no DOM. Verifique seu arquivo mapa.html.");
    }
}

function limparMarcadoresStreetView() {
    streetViewMarkers.forEach(marker => {
        if (marker.setMap) marker.setMap(null);
    });
    streetViewMarkers = [];
}

/**
 * Procura por relatórios próximos e os desenha dentro do panorama
 * @param {google.maps.LatLng} panoLocation 
 */
function adicionarMarcadoresNoPanorama(panoLocation) {
    limparMarcadoresStreetView();

    const BASE_WIDTH = 24;
    const BASE_HEIGHT = 30;

    let scaleFactor;

    if (window.innerWidth <= 600) {

        scaleFactor = 2.5;
    } else if (window.innerWidth <= 1200) {

        scaleFactor = 3.0;
    } else {

        scaleFactor = 3.5;
    }


    const finalWidth = BASE_WIDTH * scaleFactor;
    const finalHeight = BASE_HEIGHT * scaleFactor;
    const finalAnchorX = finalWidth / 2;
    const finalAnchorY = finalHeight;

    if (!google.maps.geometry || !google.maps.geometry.spherical) {
        console.warn("Biblioteca 'geometry' do Google Maps não carregada. Não é possível calcular distâncias.");
        return;
    }

    allProblemsData.forEach(problem => {
        const problemLatLng = new google.maps.LatLng(problem.latitude, problem.longitude);
        const distance = google.maps.geometry.spherical.computeDistanceBetween(panoLocation, problemLatLng);

        if (distance <= STREET_VIEW_RADIUS) {

            const normalizedTipo = problem.tipo.toLowerCase();
            const color = problemColors[normalizedTipo] || problemColors['outros'];

            const svgPin = `
                <svg width="${finalWidth}" height="${finalHeight}" viewBox="0 0 ${BASE_WIDTH} ${BASE_HEIGHT}" xmlns="http://www.w3.org/2000/svg">
                    <path fill="${color}" stroke="#ffffff" stroke-width="1.5" opacity="0.9"
                        d="${PIN_PATH}" transform="translate(0, 3)"/>
                    <circle cx="12" cy="12.5" r="3.5" fill="white"/> 
                </svg>`;

            const encodedSvg = 'data:image/svg+xml;charset=UTF-8,' + encodeURIComponent(svgPin);

            const svMarker = new google.maps.Marker({
                position: problemLatLng,
                map: panorama,
                title: problem.titulo,

                icon: {
                    url: encodedSvg,

                    scaledSize: new google.maps.Size(finalWidth, finalHeight),

                    anchor: new google.maps.Point(finalAnchorX, finalAnchorY)
                }
            });

            const problemIdStr = String(problem.id);
            const isOwned = ownedReportIds.includes(problemIdStr);
            const imageHtml = problem.imagem_url ?
                `<img src="${problem.imagem_url}" alt="Imagem do Problema" style="max-width: 100%; height: auto; margin-top: 10px; border-radius: 4px;">` : '';

            const actionsHtml = isOwned ?
                `<div class="actions" style="margin-top: 10px; border-top: 1px solid #ccc; padding-top: 10px;">
                    <p style="margin:0; font-size: 12px; color: #dc3545; font-weight: bold;">(Ações indisponíveis no Street View)</p>
                </div>` : '';


            const infoWindowContent = `
                <div class="info-window-google" style="font-family: Arial, sans-serif; max-width: 250px; color: #333; font-size: 14px; padding: 5px;">
                    <h4 style="margin:0 0 5px 0; font-weight: bold; font-size: 16px;">${problem.titulo || 'Problema sem título'}</h4>
                    <p style="margin:0; line-height: 1.4;">Tipo: ${problem.tipo}</p>
                    <p style="margin:0; line-height: 1.4;">Status: <strong style="color: ${problemColors[problem.status.toLowerCase().replace(' ', '_')] || '#333'};">${problem.status}</strong></p>
                    <p style="margin:5px 0; font-size: 11px; color: #666; border-bottom: 1px solid #eee; padding-bottom: 5px;">Distância: ${distance.toFixed(0)} metros</p>
                    
                    <p style="margin: 10px 0 5px 0; font-weight: bold;">Descrição:</p>
                    <p style="margin:0; font-size: 13px;">${problem.descricao || 'N/A'}</p>
                    
                    <p style="margin: 10px 0 5px 0; font-weight: bold;">Localização:</p>
                    <p style="margin:0; font-size: 13px;">Endereço: ${problem.endereco || 'N/A'}</p>
                    
                    <p style="margin: 10px 0 5px 0; font-weight: bold;">Prioridade:</p>
                    <p style="margin:0; font-size: 13px;">${problem.prioridade || 'N/A'}</p>
                    
                    ${imageHtml}
                    ${actionsHtml}
                </div>
            `;

            const svInfoWindow = new google.maps.InfoWindow({
                content: infoWindowContent
            });

            svMarker.addListener('click', () => {
                svInfoWindow.open(panorama, svMarker);
            });

            streetViewMarkers.push(svMarker);
        }
    });
}

/**
 * Inicializa e exibe o modal do Google Street View
 * @param {object} latlng - Objeto no formato { lat: number, lng: number }
 */
function mostrarStreetView(latlng) {
    if (typeof google === 'undefined' || !google.maps || !google.maps.StreetViewPanorama) {
        showMessage("Aguardando Street View", '<p>A API do Google Maps Street View ainda não carregou. Tente novamente em alguns segundos.</p>');
        return;
    }

    if (!svModal || !svPanoDiv) {
        console.error("Elementos do DOM do Street View (modal ou pano) não encontrados. Verifique seu mapa.html.");
        return;
    }

    limparMarcadoresStreetView();

    panorama = new google.maps.StreetViewPanorama(svPanoDiv, {
        position: latlng,
        pov: {
            heading: 34,
            pitch: 10
        },
        addressControl: true,
        linksControl: true,
        panControl: true,
        enableCloseButton: false
    });

    panorama.addListener('pano_changed', () => {
        const panoLocation = panorama.getPosition();
        if (panoLocation) {
            adicionarMarcadoresNoPanorama(panoLocation);
        }
    });

    panorama.addListener('position_changed', () => {
        const panoLocation = panorama.getPosition();
        if (panoLocation) {
            adicionarMarcadoresNoPanorama(panoLocation);
        }
    });

    svModal.classList.remove('hidden');

    if (photonSearchContainer) photonSearchContainer.style.display = 'none';

    adicionarMarcadoresNoPanorama(latlng);
}

function fecharStreetView() {
    if (svModal) svModal.classList.add('hidden');

    if (svPanoDiv) svPanoDiv.innerHTML = '';

    limparMarcadoresStreetView();

    if (typeof updateSearchVisibility === 'function') {
        updateSearchVisibility();
    } else if (photonSearchContainer) {
        photonSearchContainer.style.display = '';
    }
}


/**
 * Tenta usar a API de geolocalização do navegador...
 * @param {L.Map} map O objeto mapa Leaflet
 */
function locateUserAndCenterMap(map, options = {}) {
    // options.animate: se true, faz sequência de zoom out/pan/zoom in
    // options.coords: [lat, lng] já conhecidos (pula geolocalização async)
    const animate = !!options.animate;
    const providedCoords = options.coords && options.coords.length === 2 ? options.coords : null;

      const perform = (lat, lng) => {
          // atualizar cache
          userCoords = [lat, lng];

          const setUserMarker = () => {
            const userLocationIcon = L.divIcon({
                className: 'custom-div-icon',
                html: '<i class="fas fa-crosshairs" style="color:#007BFF; font-size: 24px;"></i>',
                iconSize: [20, 20],
                iconAnchor: [12, 24],
                popupAnchor: [0, -20]
            });

            L.marker([lat, lng], { icon: userLocationIcon }).addTo(map)
                .bindPopup("Você está aqui!")
                .openPopup();
        };

        if (animate) {
            // remover eventuais marcadores anteriores com ícone de cruz
              map.eachLayer(layer => {
                  if (layer.options && layer.options.icon && layer.options.icon.options.html.includes('fa-crosshairs')) {
                      map.removeLayer(layer);
                  }
              });
              // garantir que animações anteriores sejam interrompidas
              map.stop();

              const targetZoom = Math.max(map.getZoom(), 15);
              const animationOpts = {
                  duration: 1.2,
                  easeLinearity: 0.2,
                  animate: true
              };

              // desabilita botão para evitar múltiplos disparos
              if (recenterBtn) recenterBtn.disabled = true;

              map.flyTo([lat, lng], targetZoom, animationOpts);

              map.once('moveend', () => {
                  setUserMarker();
                  if (recenterBtn) recenterBtn.disabled = false;
              });
          } else {
              map.setView([lat, lng], 15);
              setUserMarker();
          }
      };

    if (providedCoords) {
        // utiliza coordenadas já disponíveis sem esperar geolocalização
        perform(providedCoords[0], providedCoords[1]);
        // também pede nova localização em segundo plano para atualizar cache
        if ('geolocation' in navigator) {
            navigator.geolocation.getCurrentPosition(pos => {
                userCoords = [pos.coords.latitude, pos.coords.longitude];
            }, () => { }, { enableHighAccuracy: true, timeout: 7000, maximumAge: 0 });
        }
    } else if ('geolocation' in navigator) {
        console.log("Geolocalização suportada. Tentando obter a localização...");

        navigator.geolocation.getCurrentPosition(
            (position) => {
                perform(position.coords.latitude, position.coords.longitude);
            },
            (error) => {
                console.warn(`Erro de Geolocalização (${error.code}): ${error.message}. Usando local padrão.`);
            },
            {
                enableHighAccuracy: true,
                timeout: 7000,
                maximumAge: 0
            }
        );
    } else {
        console.log("Geolocalização não é suportada por este navegador. Usando local padrão.");
    }
}


/**
 * Retorna um ícone personalizado com Font Awesome (pin no topo de um círculo).
 * @param {string} tipo O tipo de problema (ex: 'iluminacao', 'asfalto')
 * @returns {L.DivIcon} O objeto ícone Leaflet.
 */
function getMarkerIcon(tipo) {
    const normalizedTipo = tipo.toLowerCase();
    const color = problemColors[normalizedTipo] || problemColors['outros'];
    const iconClass = problemIcons[normalizedTipo] || problemIcons['outros'];

    const size = 24;

    const iconHtml = `
        <div class="fa-stack" style="font-size: ${size * 0.5}px; color: ${color};"> 
            <i class="fas fa-circle fa-stack-2x" style="color: ${color}; filter: drop-shadow(0 1px 1px rgba(0,0,0,0.4));"></i> 
            <i class="fas ${iconClass} fa-stack-1x fa-inverse" style="color: white; transform: translate(0px);"></i> 
        </div>
    `;

    return L.divIcon({
        className: 'custom-fa-icon-pin',
        html: iconHtml,
        iconSize: [size, size],
        iconAnchor: [size / 2, size],
        popupAnchor: [0, -size]
    });
}

/**
 * Retorna um ícone personalizado maior para hover.
 * @param {string} tipo O tipo de problema (ex: 'iluminacao', 'asfalto')
 * @returns {L.DivIcon} O objeto ícone Leaflet maior.
 */
function getHoverMarkerIcon(tipo) {
    const normalizedTipo = tipo.toLowerCase();
    const color = problemColors[normalizedTipo] || problemColors['outros'];
    const iconClass = problemIcons[normalizedTipo] || problemIcons['outros'];

    const size = 32; // Tamanho maior para hover

    const iconHtml = `
        <div class="fa-stack" style="font-size: ${size * 0.5}px; color: ${color};"> 
            <i class="fas fa-circle fa-stack-2x" style="color: ${color}; filter: drop-shadow(0 1px 1px rgba(0,0,0,0.4));"></i> 
            <i class="fas ${iconClass} fa-stack-1x fa-inverse" style="color: white; transform: translate(0px);"></i> 
        </div>
    `;

    return L.divIcon({
        className: 'custom-fa-icon-pin-hover',
        html: iconHtml,
        iconSize: [size, size],
        iconAnchor: [size / 2, size],
        popupAnchor: [0, -size]
    });
}


/**
 * loadProblems
 * Requisita dados do servidor (`api.php?action=get_problems`) e popula `allProblemsData`.
 *
 * Comportamento:
 * - Primeiro busca o usuário atual (`action=current_user`) e, se autenticado,
 *   consulta quais relatórios pertencem ao usuário (`action=my_reports`) para habilitar
 *   ações de edição/exclusão na UI.
 * - Em seguida, busca a lista de problemas e converte lat/lng para números.
 * - Chama `applyFilters()` para renderizar marcadores no mapa.
 *
 * Erros:
 * - Qualquer erro de rede ou formato de resposta inválido é capturado e logado no console.
 */
function loadProblems() {
    fetch('api.php?action=current_user')
        .then(r => r.json())
        .then(userObj => {
            currentUser = userObj.username || null;
            const username = userObj.username || null;
            if (!username) return Promise.resolve([]);
            return fetch('api.php?action=my_reports').then(r => r.json()).then(res => res.success ? res.report_ids : []);
        })
        .then(ownedIds => {
            ownedReportIds = ownedIds.map(String);

            return fetch('api.php?action=get_problems')
                .then(response => {
                    if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                    return response.json();
                })
                .then(data => {
                    if (!Array.isArray(data)) {
                        console.error('Resposta da API inválida ou vazia:', data);
                        return;
                    }
                    allProblemsData = data.map(p => ({
                        ...p,
                        latitude: parseFloat(p.latitude),
                        longitude: parseFloat(p.longitude)
                    }));

                    applyFilters();
                });
        })
        .catch(error => console.error('Erro ao carregar problemas:', error));
}

/**
 * applyFilters
 * Aplica filtros (categoria / status / ordenação) em `allProblemsData` e atualiza
 * o `problemsLayerGroup` no mapa.
 *
 * Regras principais:
 * - Filtra por `filterCategory` e `filterStatus` (valores 'all' significam sem filtro).
 * - Ordena por opção de `filterSort` (recente/antigo etc).
 * - Para cada item filtrado: cria marcador com ícone apropriado, monta conteúdo do popup
 *   (incluindo imagem e botões de ação se o relatório pertencer ao usuário) e
 *   associa eventos de click/edição/exclusão.
 */
function applyFilters() {
    problemsLayerGroup.clearLayers();

    if (allProblemsData.length === 0) {
        console.log('Nenhum dado de problema para filtrar.');
        return;
    }

    const selectedCategory = filterCategory ? filterCategory.value.trim() || 'all' : 'all';
    const selectedStatus = filterStatus ? filterStatus.value : 'all';
    const sortBy = filterSort ? filterSort.value : 'recente';

    let filteredData = allProblemsData.filter(problem => {
        const matchesCategory = selectedCategory === 'all' || (problem.tipo && problem.tipo.toLowerCase() === selectedCategory.toLowerCase());

        const matchesStatus = selectedStatus === 'all' || (problem.status && problem.status.toLowerCase().replace(' ', '_') === selectedStatus.toLowerCase().replace(' ', '_'));

        return matchesCategory && matchesStatus;
    });

    if (sortBy === 'recente' || sortBy === 'id-desc') {
        filteredData.sort((a, b) => b.id - a.id);
    } else if (sortBy === 'antigo' || sortBy === 'id-asc') {
        filteredData.sort((a, b) => a.id - b.id);
    }

    filteredData.forEach(problem => {
        const marker = L.marker([problem.latitude, problem.longitude], {
            icon: getMarkerIcon(problem.tipo)
        });

        // Adicionar atributo data-category para filtrar por categoria
        marker.on('add', function () {
            const markerElement = this.getElement();
            if (markerElement) {
                markerElement.setAttribute('data-category', problem.tipo);
                // Adicionar efeito de hover para aumentar o tamanho do pin
                markerElement.addEventListener('mouseenter', () => {
                    this.setIcon(getHoverMarkerIcon(problem.tipo));
                });
                markerElement.addEventListener('mouseleave', () => {
                    this.setIcon(getMarkerIcon(problem.tipo));
                });
            }
        });

        const problemIdStr = String(problem.id);
        const isOwned = ownedReportIds.includes(problemIdStr);
        const imageHtml = problem.imagem_url ?
            `<img src="${problem.imagem_url}" alt="Imagem do Problema" style="max-width: 100%; height: auto; margin-top: 10px;">` : '';

        const actionsHtml = isOwned ?
            `<div class="actions" style="margin-top: 10px; border-top: 1px solid #ccc; padding-top: 10px;">
                <button class="edit-btn" data-id="${problem.id}" style="background: #007bff; color: white; border: none; padding: 5px 10px; margin-right: 5px; border-radius: 3px; cursor: pointer;">Editar</button>
                <button class="del-btn" data-id="${problem.id}" style="background: #dc3545; color: white; border: none; padding: 5px 10px; border-radius: 3px; cursor: pointer;">Excluir</button>
            </div>` : '';

        const infoWindowContent = `
            <div class="info-window">
                <h4>${problem.titulo || 'Problema sem título'}</h4>
                <p>Tipo: ${problem.tipo}</p>
                <p>Descrição: ${problem.descricao}</p>
                <p>Endereço: ${problem.endereco || 'Não informado'}</p>
                <p>Status: <strong>${problem.status}</strong></p>
                <p>Prioridade: ${problem.prioridade}</p>
                ${imageHtml}
                ${actionsHtml}
            </div>
        `;

        marker.bindPopup(infoWindowContent);
        marker.reportId = problem.id;

        marker.on('popupopen', function (e) {
            const popup = e.popup;
            const container = popup.getElement ? popup.getElement() : document.querySelector('.leaflet-popup');
            if (!container) return;
            const editBtn = container.querySelector('.edit-btn[data-id="' + problem.id + '"]');
            const delBtn = container.querySelector('.del-btn[data-id="' + problem.id + '"]');

            if (editBtn) {
                editBtn.addEventListener('click', () => {
                    createFormModal('Editar Relatório', [
                        { name: 'titulo', label: 'Título', value: problem.titulo || '' },
                        { name: 'descricao', label: 'Descrição', type: 'textarea', value: problem.descricao || '' },
                        { name: 'imagem_upload', label: 'Imagem (opcional)', type: 'file', preview: problem.imagem_url || '' }
                    ], (values, { close, files }) => {
                        // use FormData so we can optionally upload a new image
                        const formData = new FormData();
                        formData.append('id', problem.id);
                        formData.append('titulo', values.titulo || '');
                        formData.append('descricao', values.descricao || '');
                        if (files && files.imagem_upload) {
                            formData.append('imagem_upload', files.imagem_upload);
                        }

                        fetch('api.php?action=edit_report', { method: 'POST', body: formData })
                            .then(r => r.json())
                            .then(resp => { showToast(resp.message || 'Atualizado', resp.success ? 'success' : 'error'); loadProblems(); close(); })
                            .catch(err => { console.error(err); showMessage('Erro', '<p>Erro ao editar o relatório.</p>'); });
                    });
                });
            }

            if (delBtn) {
                delBtn.addEventListener('click', () => {
                    createConfirmModal('Confirmar exclusão', 'Confirma exclusão deste relatório?', () => {
                        const form = new URLSearchParams();
                        form.append('id', problem.id);
                        fetch('api.php?action=delete_report', { method: 'POST', body: form })
                            .then(r => r.json())
                            .then(resp => { showToast(resp.message || 'Excluído', resp.success ? 'success' : 'error'); loadProblems(); })
                            .catch(err => { console.error(err); showMessage('Erro', '<p>Não foi possível excluir o relatório.</p>'); });
                    });
                });
            }
        });

        marker.addTo(problemsLayerGroup);
    });

    closeFilterSidebar();
}

function openFilterSidebar() {
    if (filterSidebar) {
        filterSidebar.classList.add('open');
        if (photonSearchContainer) photonSearchContainer.style.display = 'none';
        if (reportModal && !reportModal.classList.contains('hidden')) reportModal.classList.add('hidden');
    }
}

function closeFilterSidebar() {
    if (filterSidebar) {
        filterSidebar.classList.remove('open');
        if (typeof updateSearchVisibility === 'function') {
            updateSearchVisibility();
        } else {
            if (photonSearchContainer) photonSearchContainer.style.display = '';
        }
    }
}

function openReportModal() {
    if (reportModal) reportModal.classList.remove('hidden');
    if (photonSearchContainer) photonSearchContainer.style.display = 'none';
}

function closeReportModal() {
    if (reportModal) reportModal.classList.add('hidden');
    toggleMapSelectionMode(false);
    try { map.off('click', handleMapSelection); } catch (e) { }
    if (newProblemMarker) {
        try { map.removeLayer(newProblemMarker); } catch (e) { }
        newProblemMarker = null;
    }
    if (photonSearchContainer) photonSearchContainer.style.display = '';
    if (reportForm) reportForm.reset();
}

function toggleMapSelectionMode(enable) {
    isSelectingLocation = !!enable;
    if (isSelectingLocation) {
        if (reportModal) reportModal.classList.add('hidden');
        map.on('click', handleMapSelection);
    } else {
        try { map.off('click', handleMapSelection); } catch (e) { }
    }
}

function handleMapSelection(e) {
    if (!isSelectingLocation) return;

    const lat = e.latlng.lat;
    const lng = e.latlng.lng;

    if (newProblemMarker) {
        map.removeLayer(newProblemMarker);
    }

    newProblemMarker = L.marker([lat, lng], {
        icon: getMarkerIcon('outros')
    }).addTo(map)
        .bindPopup("Local Selecionado").openPopup();

    formLatitude.value = lat;
    formLongitude.value = lng;

    enderecoInput.value = `Coordenadas: ${lat.toFixed(6)}, ${lng.toFixed(6)}`;

    reportModal.classList.remove('hidden');
    toggleMapSelectionMode(false);
}

novoRelatorioBtn.addEventListener('click', (e) => {
    e.preventDefault();
    openReportModal();
});

closeBtn.addEventListener('click', closeReportModal);
cancelBtn.addEventListener('click', closeReportModal);
window.addEventListener('click', (e) => {
    if (e.target === reportModal) {
        closeReportModal();
    }
});

selectOnMapBtn.addEventListener('click', (e) => {
    e.preventDefault();
    if (!isSelectingLocation) {
        toggleMapSelectionMode(true);
    }
});


reportForm.addEventListener('submit', function (e) {
    e.preventDefault();

    const lat = formLatitude.value;
    const lng = formLongitude.value;

    if (!lat || !lng) {
        showMessage('Localização necessária', '<p>Por favor, selecione a localização do problema no mapa antes de enviar.</p>');
        return;
    }

    const formData = new FormData(this);

    if (!formData.has('status')) {
        formData.append('status', 'Pendente');
    }

    // Debug: Log da categoria sendo enviada
    console.log('=== SUBMISSÃO REPORT ===');
    console.log('Categoria value:', document.getElementById('tipo')?.value);
    console.log('FormData categoria:', formData.get('categoria'));
    console.log('========================');

    fetch('api.php?action=report_problem', {
        method: 'POST',
        body: formData
    })
        .then(response => {
            const clonedResponse = response.clone();

            if (!response.ok) {
                console.error('Erro de servidor:', response.status, response.statusText);

                clonedResponse.text().then(text => {
                    console.error("Resposta Bruta do Servidor (Possível erro PHP/MySQL ou poluição de output):", text);
                    try {
                        const errorJson = JSON.parse(text);
                        showMessage('Erro do servidor', `<p>${errorJson.message}</p>`);
                    } catch {
                        showMessage('Erro de comunicação', `<p>Erro de JSON! A API PHP está enviando um erro (${response.status}) em formato HTML. Verifique se o seu 'api.php' não tem NADA antes de &lt;?php.</p>`);
                    }
                });
                return Promise.reject('Erro no servidor.');
            }

            return response.json();
        })
        .then(result => {
            if (result.success) {
                showToast('Relatório enviado com sucesso!', 'success', 3000);
                if (reportForm) reportForm.reset();
                closeReportModal();
                if (result.report_id && currentUser) {
                    console.log('Tentando reivindicar relatório:', result.report_id);
                    fetch('api.php?action=claim_report', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: `report_id=${encodeURIComponent(result.report_id)}`
                    })
                        .then(r => r.json())
                        .then(claimResult => {
                            console.log('Resposta da reivindicação:', claimResult);
                            loadProblems();
                        })
                        .catch(err => {
                            console.error('Erro ao reivindicar:', err);
                            loadProblems();
                        });
                } else {
                    console.log('Não tentou reivindicar:', { report_id: result.report_id, currentUser });
                    loadProblems();
                }
            } else {
                showMessage('Erro ao enviar relatório', `<p>${result.message || 'Ocorreu um erro.'}</p>`);
            }
        })
        .catch(error => console.error('Erro de rede ou JSON inválido:', error));
});

if (imagemLabel) {
    imagemLabel.addEventListener('click', function (e) {
        e.preventDefault();
    });
}

if (uploadBtnStyled && imagemUploadInput) {
    uploadBtnStyled.addEventListener('click', function (e) {
        e.preventDefault();
        imagemUploadInput.click();
    });

    imagemUploadInput.addEventListener('change', function () {
        if (fileNameDisplay) {
            const fileName = this.files.length > 0 ? this.files[0].name : "Nenhum arquivo selecionado";
            fileNameDisplay.value = fileName;
        }
    });
}

if (recenterBtn) {
    recenterBtn.addEventListener('click', () => {
        // remove marcadores anteriores de localização
        map.eachLayer(layer => {
            if (layer.options && layer.options.icon && layer.options.icon.options.html.includes('fa-crosshairs')) {
                map.removeLayer(layer);
            }
        });

        // se tivermos coordenadas em cache, anima imediatamente
        if (userCoords) {
            locateUserAndCenterMap(map, { animate: true, coords: userCoords });
        } else {
            locateUserAndCenterMap(map, { animate: true });
        }
    });
}

function initAutocomplete() {
    if (isAutocompleteInitialized || !searchInput) {
        return;
    }

    if (typeof google !== 'undefined' && google.maps && google.maps.places && typeof google.maps.places.Autocomplete === 'function') {

        if (searchResults) searchResults.style.display = 'none';

        const autocomplete = new google.maps.places.Autocomplete(searchInput, {
            types: ['geocode', 'establishment'],
            fields: ['geometry', 'formatted_address', 'name']
        });

        autocomplete.addListener('place_changed', function () {
            const place = autocomplete.getPlace();

            if (!place.geometry || !place.geometry.location) {
                console.error("Local selecionado não possui coordenadas válidas.");
                searchInput.value = '';
                return;
            }

            const lat = place.geometry.location.lat();
            const lon = place.geometry.location.lng();

            if (typeof map !== 'undefined') {
                if (place.geometry.viewport) {
                    map.fitBounds([
                        [place.geometry.viewport.getSouthWest().lat(), place.geometry.viewport.getSouthWest().lng()],
                        [place.geometry.viewport.getNorthEast().lat(), place.geometry.viewport.getNorthEast().lng()]
                    ]);
                } else {
                    map.setView([lat, lon], 15);
                }
            }

            if (typeof L !== 'undefined') {
                if (window.newProblemMarker) {
                    window.newProblemMarker.setLatLng([lat, lon]);
                } else {
                    window.newProblemMarker = L.marker([lat, lon], { icon: getMarkerIcon('outros') }).addTo(map);
                }
            }

            searchInput.value = place.name || place.formatted_address || '';
        });

        isAutocompleteInitialized = true;

    } else {
        console.warn('Google Maps Places Autocomplete não pôde ser inicializado. Verifique se a API Key e a biblioteca "places" estão carregadas.');
    }
}


window.addEventListener('DOMContentLoaded', function () {
    initMap();


    (function initAuth() {
        let mediaStream = null;

        function updateAuthUI() {
            if (!profileBtn || !userNameText || !userAvatar) return;
            if (currentUser) {
                userNameText.textContent = currentUser;
                profileBtn.style.cursor = 'pointer';
                const savedAvatar = localStorage.getItem(`avatar_${currentUser}`);
                if (savedAvatar) {
                    userAvatar.src = savedAvatar;
                    if (profileImage) profileImage.src = savedAvatar;
                }
                document.querySelector('.profile-image-container').style.display = 'block';
            } else {
                userNameText.textContent = 'Entrar';
                userAvatar.src = 'https://www.gravatar.com/avatar/?d=mp';
                const profileImageContainer = document.querySelector('.profile-image-container');
                if (profileImageContainer) profileImageContainer.style.display = 'none';
                document.querySelectorAll('.edit-btn, .del-btn').forEach(btn => {
                    btn.style.display = 'none';
                });
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

        let authMode = 'login';

        if (takePhotoBtn && profileVideo && capturePhotoBtn) {
            takePhotoBtn.addEventListener('click', async () => {
                try {
                    mediaStream = await navigator.mediaDevices.getUserMedia({ video: true });
                    profileVideo.srcObject = mediaStream;
                    profileVideo.style.display = 'block';
                    capturePhotoBtn.style.display = 'inline-block';
                    if (profileImage) profileImage.style.display = 'none';
                } catch (err) {
                    showMessage('Erro de câmera', `<p>Não foi possível acessar a câmera: ${err.message}</p>`);
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
                    mediaStream = null;
                }
            });
        }

        if (profileBtn) profileBtn.addEventListener('click', (e) => {
            e.preventDefault();
            const authUsernameInput = document.getElementById('auth-username');

            if (currentUser) {
                authMode = 'profile';
                authTitle.textContent = 'Meu Perfil';
                authUsernameInput.value = currentUser;

                authUsernameInput.disabled = true;
                if (changeImageBtn) changeImageBtn.style.display = 'none';
                if (takePhotoBtn) takePhotoBtn.style.display = 'none';

                if (profileVideo) {
                    profileVideo.style.display = 'none';
                }
                if (capturePhotoBtn) {
                    capturePhotoBtn.style.display = 'none';
                }
                if (mediaStream) {
                    mediaStream.getTracks().forEach(track => track.stop());
                    mediaStream = null;
                }

                if (profileImage) {
                    profileImage.style.display = 'block';

                    const savedAvatar = localStorage.getItem(`avatar_${currentUser}`);
                    if (savedAvatar) {
                        profileImage.src = savedAvatar;
                    } else {
                        profileImage.src = 'https://www.gravatar.com/avatar/?d=mp';
                    }
                }

                document.getElementById('auth-password').closest('.form-group').style.display = 'none';
                document.getElementById('auth-submit').style.display = 'none';
                switchToRegisterBtn.textContent = 'Deslogar';
                document.querySelector('.profile-image-container').style.display = 'block';

            } else {

                authMode = 'login';
                if (authTitle) authTitle.textContent = 'Entrar';
                authUsernameInput.value = '';
                authUsernameInput.disabled = false;
                if (changeImageBtn) changeImageBtn.style.display = 'none';
                if (takePhotoBtn) takePhotoBtn.style.display = 'none';

                document.getElementById('auth-password').closest('.form-group').style.display = 'block';
                document.getElementById('auth-submit').textContent = 'Entrar';
                document.getElementById('auth-submit').style.display = 'block';
                switchToRegisterBtn.textContent = 'Registrar';
                document.querySelector('.profile-image-container').style.display = 'none';
            }
            if (authModal) {
                authModal.classList.remove('hidden');
                if (photonSearchContainer) photonSearchContainer.style.display = 'none';
            }
        });

        if (authCloseBtn) authCloseBtn.addEventListener('click', () => {
            if (authModal) {
                authModal.classList.add('hidden');
                if (profileVideo && mediaStream) {
                    profileVideo.srcObject = null;
                    mediaStream.getTracks().forEach(track => track.stop());
                    mediaStream = null;
                }
                updateSearchVisibility();
            }
        });

        if (authModal) {
            authModal.addEventListener('click', (e) => {
                if (e.target === authModal) {
                    authModal.classList.add('hidden');
                    if (profileVideo && mediaStream) {
                        profileVideo.srcObject = null;
                        mediaStream.getTracks().forEach(track => track.stop());
                        mediaStream = null;
                    }
                    updateSearchVisibility();
                }
            });
        }

        if (switchToRegisterBtn) switchToRegisterBtn.addEventListener('click', () => {
            if (authMode === 'profile') {
                createConfirmModal('Sair', 'Deseja realmente sair?', () => {
                    fetch('api.php?action=logout').then(r => r.json()).then(() => {
                        currentUser = null;
                        window.location.reload();
                    });
                });
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
            profileImageInput.addEventListener('change', function (e) {
                const file = this.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = function (e) {
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

        if (googleLoginBtn) googleLoginBtn.addEventListener('click', () => {
            showToast('Em breve: Login com Google!', 'info', 2500);
        });

        if (authForm) authForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const formData = new FormData(authForm);
            const url = authMode === 'login' ? 'api.php?action=login' : 'api.php?action=register';

            if (authMode === 'register' && profileImageInput.files[0]) {
                formData.append('avatar', profileImageInput.files[0]);
            }

            fetch(url, { method: 'POST', body: formData })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        currentUser = res.username;

                        if (authMode === 'register' && profileImage.src !== 'https://www.gravatar.com/avatar/?d=mp') {
                            localStorage.setItem(`avatar_${currentUser}`, profileImage.src);
                        }

                        showToast(res.message || 'Sucesso', 'success', 1500);
                        setTimeout(() => window.location.reload(), 900);
                    } else {
                        showMessage('Erro', `<p>${res.message || 'Erro no login/registro'}</p>`);
                    }
                }).catch(err => console.error(err));
        });

        fetchCurrentUser();

        function updateSearchVisibility() {
            if (!photonSearchContainer) return;
            const authOpen = authModal && !authModal.classList.contains('hidden');
            const reportOpen = reportModal && !reportModal.classList.contains('hidden');
            const filterOpen = filterSidebar && filterSidebar.classList.contains('open');
            const svOpen = svModal && !svModal.classList.contains('hidden');

            if (authOpen || reportOpen || filterOpen || svOpen) {
                photonSearchContainer.style.display = 'none';
            } else {
                photonSearchContainer.style.display = '';
            }
        }

        [authModal, reportModal, filterSidebar, svModal].filter(Boolean).forEach(el => {
            try {
                const mo = new MutationObserver(() => updateSearchVisibility());
                mo.observe(el, { attributes: true, attributeFilter: ['class'] });
            } catch (e) {
            }
        });
        updateSearchVisibility();
    })();

    if (openFilterBtn) openFilterBtn.addEventListener('click', openFilterSidebar);
    if (closeFilterBtn) closeFilterBtn.addEventListener('click', closeFilterSidebar);
    if (applyFilterBtn) applyFilterBtn.addEventListener('click', applyFilters);

    if (clearFilterBtn) clearFilterBtn.addEventListener('click', () => {
        if (filterCategory) filterCategory.value = 'all';
        if (filterStatus) filterStatus.value = 'all';
        if (filterCity) filterCity.value = 'all';
        if (filterSort) filterSort.value = 'recente';
        applyFilters();
    });

    if (svBtn) {
        svBtn.addEventListener('click', () => {
            showMessage('Modo Street View Ativado', '<p>Clique no mapa para ver a imagem do Street View. Para sair, feche o modal.</p>');

            const mapContainer = document.getElementById('map');
            if (mapContainer) mapContainer.style.cursor = 'crosshair';

            if (map) {
                map.once('click', function (e) {
                    const googleLatLng = { lat: e.latlng.lat, lng: e.latlng.lng };

                    mostrarStreetView(googleLatLng);

                    if (mapContainer) mapContainer.style.cursor = '';
                });
            }
        });
    }

    if (svCloseBtn) {
        svCloseBtn.addEventListener('click', fecharStreetView);
    }

    if (svModal) {
        svModal.addEventListener('click', (e) => {
            if (e.target === svModal) {
                fecharStreetView();
            }
        });
    }
});
