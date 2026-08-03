/**
 * video-tutorial.js — Modal de vídeo explicativo
 * Agente Urbano
 *
 * ⚠️ TROQUE O PLACEHOLDER ABAIXO PELO ID REAL DO SEU VÍDEO NO YOUTUBE.
 *
 * Como pegar o ID do YouTube: na URL do vídeo
 * https://www.youtube.com/watch?v=XXXXXXXXXXX
 *                                  ^^^^^^^^^^^ isso aqui é o ID
 *
 * Por que usar a API do YouTube em vez de só um <iframe> com
 * ?autoplay=1 na URL?
 *   Só o parâmetro na URL não é confiável — vários navegadores
 *   ignoram o autoplay de um iframe de outra origem mesmo assim.
 *   Chamando player.playVideo() diretamente pela API do YouTube,
 *   o vídeo garantidamente começa a tocar assim que carrega.
 *
 *   Autoplay com SOM ligado é bloqueado pelos navegadores em iframes
 *   de outra origem — por isso o vídeo começa mudo e aparece um
 *   botão "Ativar som" por cima, que o visitante clica pra ouvir.
 *   Isso é uma limitação dos navegadores, não do nosso código.
 *
 * Como abrir o modal em qualquer botão/link novo:
 *   Basta adicionar o atributo data-au-video-trigger nele.
 *   <button data-au-video-trigger>▶ Assista o tutorial</button>
 */
(function () {
    'use strict';

    // ── CONFIGURAÇÃO — troque aqui pelo vídeo real ────────────────
    var YOUTUBE_VIDEO_ID = 'https://youtu.be/1goAp0XmhZQ?si=BSiH608w7ZnFtAJM';

    var _overlay = null;
    var _frameWrap = null;
    var _btnSom = null;
    var _modalPronto = false;
    var _player = null;
    var _apiPronta = false;
    var _aberturaPendente = false;

    /* ===========================================================
       CARREGAMENTO DA API DO YOUTUBE (uma única vez, sob demanda)
    =========================================================== */
    function _carregarApiYoutube() {
        if (window.YT && window.YT.Player) {
            _apiPronta = true;
            return;
        }
        if (document.getElementById('au-youtube-api-script')) return;

        var script = document.createElement('script');
        script.id = 'au-youtube-api-script';
        script.src = 'https://www.youtube.com/iframe_api';
        document.head.appendChild(script);

        // Callback global exigido pela própria API do YouTube.
        var callbackAnterior = window.onYouTubeIframeAPIReady;
        window.onYouTubeIframeAPIReady = function () {
            _apiPronta = true;
            if (typeof callbackAnterior === 'function') callbackAnterior();
            if (_aberturaPendente) {
                _aberturaPendente = false;
                _criarPlayer();
            }
        };
    }

    function _construirModal() {
        if (_modalPronto) return;

        _overlay = document.createElement('div');
        _overlay.className = 'au-video-overlay';
        _overlay.setAttribute('role', 'dialog');
        _overlay.setAttribute('aria-modal', 'true');
        _overlay.setAttribute('aria-label', 'Vídeo explicativo do Agente Urbano');

        var box = document.createElement('div');
        box.className = 'au-video-modal-box';

        var btnFechar = document.createElement('button');
        btnFechar.className = 'au-video-modal-close';
        btnFechar.setAttribute('aria-label', 'Fechar vídeo');
        btnFechar.innerHTML = '&times;';
        btnFechar.addEventListener('click', fechar);

        _frameWrap = document.createElement('div');
        _frameWrap.className = 'au-video-modal-frame-wrap';

        // Elemento onde o player do YouTube vai ser montado
        var playerHost = document.createElement('div');
        playerHost.id = 'au-youtube-player-host';
        _frameWrap.appendChild(playerHost);

        _btnSom = document.createElement('button');
        _btnSom.type = 'button';
        _btnSom.className = 'au-video-unmute-btn';
        _btnSom.innerHTML = '<i class="fas fa-volume-mute"></i> Ativar som';
        _btnSom.addEventListener('click', function () {
            if (_player && typeof _player.unMute === 'function') {
                _player.unMute();
                _player.setVolume(100);
            }
            _btnSom.classList.add('au-hidden');
        });
        _frameWrap.appendChild(_btnSom);

        box.appendChild(btnFechar);
        box.appendChild(_frameWrap);
        _overlay.appendChild(box);

        _overlay.addEventListener('click', function (ev) {
            if (ev.target === _overlay) fechar();
        });

        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape' && _overlay.classList.contains('au-show')) fechar();
        });

        document.body.appendChild(_overlay);
        _modalPronto = true;
    }

    function _criarPlayer() {
        // Recria o host a cada abertura (evita conflito com player antigo destruído)
        var hostAntigo = document.getElementById('au-youtube-player-host');
        var novoHost = document.createElement('div');
        novoHost.id = 'au-youtube-player-host';
        hostAntigo.replaceWith(novoHost);

        _btnSom.classList.remove('au-hidden');

        _player = new YT.Player('au-youtube-player-host', {
            videoId: YOUTUBE_VIDEO_ID,
            playerVars: {
                autoplay: 1,
                mute: 1,       // necessário para o autoplay ser garantido
                rel: 0,
                playsinline: 1
            },
            events: {
                onReady: function (ev) {
                    ev.target.mute();
                    ev.target.playVideo();
                }
            }
        });
    }

    /**
     * abrir
     * Garante que a API do YouTube está carregada, monta o player
     * (que já chama playVideo() sozinho ao ficar pronto) e mostra
     * o modal.
     */
    function abrir() {
        _construirModal();
        _carregarApiYoutube();

        requestAnimationFrame(function () {
            _overlay.classList.add('au-show');
        });

        if (_apiPronta) {
            _criarPlayer();
        } else {
            _aberturaPendente = true;
        }
    }

    /**
     * fechar
     * Esconde o modal e destrói o player (isso já para a reprodução).
     */
    function fechar() {
        if (!_overlay) return;
        _overlay.classList.remove('au-show');
        _aberturaPendente = false;
        setTimeout(function () {
            if (_player && typeof _player.destroy === 'function') {
                _player.destroy();
                _player = null;
            }
        }, 250);
    }

    // Qualquer elemento com data-au-video-trigger abre o modal,
    // mesmo que tenha sido adicionado à página depois deste script
    // rodar (delegação de evento no document).
    document.addEventListener('click', function (ev) {
        var gatilho = ev.target.closest('[data-au-video-trigger]');
        if (gatilho) {
            ev.preventDefault();
            abrir();
        }
    });

    window.AgenteUrbanoVideoTutorial = {
        abrir: abrir,
        fechar: fechar
    };

})();