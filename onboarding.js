/**
 * onboarding.js — Slideshow de boas-vindas / tutorial inicial
 * Agente Urbano
 *
 * O que faz:
 *   - Na primeira vez que alguém abre o site nesse navegador, mostra
 *     automaticamente uma série de slides explicando como o Agente
 *     Urbano funciona.
 *   - Da segunda vez em diante, não mostra mais sozinho (fica salvo
 *     no localStorage do navegador).
 *   - Expõe window.AgenteUrbanoOnboarding.abrir() para qualquer outra
 *     parte do site (um botão de "Ajuda", por exemplo) reabrir os
 *     mesmos slides manualmente, a qualquer momento — ignorando o
 *     "já vi isso antes".
 *
 * Como incluir em uma página:
 *   <link rel="stylesheet" href="onboarding.css">
 *   <script src="onboarding.js"></script>
 *   (sem precisar de nenhum HTML extra — o modal é montado por aqui)
 */
(function () {
    'use strict';

    var STORAGE_KEY = 'au_onboarding_visto';

    /* ===========================================================
       CONTEÚDO DOS SLIDES
       Cada slide tem um título, um texto curto e uma ilustração em
       SVG (sem depender de nenhuma imagem externa).
    =========================================================== */
    var SLIDES = [
        {
            titulo: 'Bem-vindo ao Agente Urbano',
            texto: 'Sua ferramenta para ajudar a cuidar da cidade. Reporte problemas, acompanhe soluções e participe da mudança.',
            svg: ''
                + '<svg viewBox="0 0 220 160" xmlns="http://www.w3.org/2000/svg">'
                + '<rect x="18" y="70" width="30" height="70" rx="3" fill="#93c5fd"/>'
                + '<rect x="54" y="50" width="34" height="90" rx="3" fill="#60a5fa"/>'
                + '<rect x="94" y="86" width="26" height="54" rx="3" fill="#93c5fd"/>'
                + '<rect x="26" y="80" width="8" height="8" fill="#eff6ff"/>'
                + '<rect x="26" y="96" width="8" height="8" fill="#eff6ff"/>'
                + '<rect x="26" y="112" width="8" height="8" fill="#eff6ff"/>'
                + '<rect x="63" y="60" width="8" height="8" fill="#eff6ff"/>'
                + '<rect x="63" y="76" width="8" height="8" fill="#eff6ff"/>'
                + '<rect x="63" y="92" width="8" height="8" fill="#eff6ff"/>'
                + '<rect x="63" y="108" width="8" height="8" fill="#eff6ff"/>'
                + '<rect x="76" y="60" width="8" height="8" fill="#eff6ff"/>'
                + '<rect x="76" y="76" width="8" height="8" fill="#eff6ff"/>'
                + '<rect x="76" y="92" width="8" height="8" fill="#eff6ff"/>'
                + '<circle cx="152" cy="58" r="30" fill="#0ea5e9" opacity="0.12"/>'
                + '<path d="M152 30c-14 0-25 11-25 25 0 18 25 42 25 42s25-24 25-42c0-14-11-25-25-25z" fill="#0ea5e9"/>'
                + '<circle cx="152" cy="55" r="10" fill="#ffffff"/>'
                + '</svg>'
        },
        {
            titulo: 'Reporte problemas da sua cidade',
            texto: 'Viu um buraco na rua, poste apagado ou lixo acumulado? Registre em segundos, com foto e localização.',
            svg: ''
                + '<svg viewBox="0 0 220 160" xmlns="http://www.w3.org/2000/svg">'
                + '<rect x="42" y="34" width="136" height="98" rx="14" fill="#e2e8f0"/>'
                + '<rect x="54" y="50" width="112" height="66" rx="6" fill="#0ea5e9" opacity="0.15"/>'
                + '<circle cx="110" cy="83" r="24" fill="#ffffff" stroke="#0ea5e9" stroke-width="4"/>'
                + '<circle cx="110" cy="83" r="10" fill="#0ea5e9"/>'
                + '<rect x="92" y="40" width="36" height="10" rx="4" fill="#94a3b8"/>'
                + '<circle cx="168" cy="112" r="20" fill="#f59e0b"/>'
                + '<rect x="165" y="102" width="6" height="12" fill="#ffffff"/>'
                + '<circle cx="168" cy="119" r="2.4" fill="#ffffff"/>'
                + '</svg>'
        },
        {
            titulo: 'Acompanhe o status em tempo real',
            texto: 'Do envio até a resolução: veja em qual etapa cada relatório está, direto pelo painel ou pelo mapa.',
            svg: ''
                + '<svg viewBox="0 0 220 160" xmlns="http://www.w3.org/2000/svg">'
                + '<rect x="40" y="24" width="140" height="112" rx="12" fill="#e2e8f0"/>'
                + '<rect x="56" y="42" width="108" height="14" rx="7" fill="#dc3545" opacity="0.85"/>'
                + '<rect x="56" y="68" width="108" height="14" rx="7" fill="#f59e0b" opacity="0.85"/>'
                + '<rect x="56" y="94" width="70" height="14" rx="7" fill="#0ea5e9" opacity="0.9"/>'
                + '<circle cx="150" cy="101" r="13" fill="#22c55e"/>'
                + '<path d="M144 101l4 4 8-9" stroke="#ffffff" stroke-width="2.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'
                + '</svg>'
        },
        {
            titulo: 'A comunidade valida os relatórios',
            texto: 'Outros moradores confirmam se o problema é real, dando mais confiança e prioridade para os casos verdadeiros.',
            svg: ''
                + '<svg viewBox="0 0 220 160" xmlns="http://www.w3.org/2000/svg">'
                + '<path d="M110 22l46 16v34c0 34-22 54-46 66-24-12-46-32-46-66V38z" fill="#0ea5e9" opacity="0.15"/>'
                + '<path d="M110 30l38 13v28c0 28-18 45-38 55-20-10-38-27-38-55V43z" fill="#0ea5e9"/>'
                + '<path d="M92 84l13 13 24-26" stroke="#ffffff" stroke-width="6" fill="none" stroke-linecap="round" stroke-linejoin="round"/>'
                + '<circle cx="46" cy="120" r="15" fill="#22c55e" opacity="0.18"/>'
                + '<circle cx="46" cy="120" r="8" fill="#22c55e"/>'
                + '<circle cx="176" cy="46" r="12" fill="#f59e0b" opacity="0.2"/>'
                + '<circle cx="176" cy="46" r="6" fill="#f59e0b"/>'
                + '</svg>'
        },
        {
            titulo: 'Ganhe pontos e recompensas',
            texto: 'Cada relatório enviado ou validado gera pontos. Troque por recompensas e suba no ranking dos colaboradores.',
            svg: ''
                + '<svg viewBox="0 0 220 160" xmlns="http://www.w3.org/2000/svg">'
                + '<circle cx="110" cy="80" r="52" fill="#f59e0b" opacity="0.12"/>'
                + '<path d="M84 46h52v22c0 16-11 28-26 28s-26-12-26-28z" fill="#f59e0b"/>'
                + '<rect x="70" y="46" width="14" height="16" rx="6" fill="#f59e0b"/>'
                + '<rect x="136" y="46" width="14" height="16" rx="6" fill="#f59e0b"/>'
                + '<rect x="102" y="94" width="16" height="16" fill="#fbbf24"/>'
                + '<rect x="90" y="110" width="40" height="10" rx="3" fill="#fbbf24"/>'
                + '<path d="M50 40l4 9 9 1-7 6 2 9-8-5-8 5 2-9-7-6 9-1z" fill="#0ea5e9"/>'
                + '<path d="M170 100l3.5 7.5 7.5 1-5.5 5 1.5 7.5-6.5-4-6.5 4 1.5-7.5-5.5-5 7.5-1z" fill="#0ea5e9"/>'
                + '</svg>'
        }
    ];

    var _overlay = null;
    var _track = null;
    var _dotsWrap = null;
    var _btnPrev = null;
    var _btnNext = null;
    var _slideAtual = 0;
    var _modalPronto = false;

    /* ===========================================================
       CONSTRUÇÃO DO MODAL (uma única vez, via JS)
    =========================================================== */
    function _construirModal() {
        if (_modalPronto) return;

        _overlay = document.createElement('div');
        _overlay.className = 'au-onboarding-overlay';
        _overlay.setAttribute('role', 'dialog');
        _overlay.setAttribute('aria-modal', 'true');
        _overlay.setAttribute('aria-label', 'Como o Agente Urbano funciona');

        var card = document.createElement('div');
        card.className = 'au-onboarding-card';

        var btnFechar = document.createElement('button');
        btnFechar.className = 'au-onboarding-close';
        btnFechar.setAttribute('aria-label', 'Fechar');
        btnFechar.innerHTML = '&times;';
        btnFechar.addEventListener('click', function () { fechar(true); });

        var trackWrap = document.createElement('div');
        trackWrap.className = 'au-onboarding-track-wrap';

        _track = document.createElement('div');
        _track.className = 'au-onboarding-track';

        SLIDES.forEach(function (slide) {
            var slideEl = document.createElement('div');
            slideEl.className = 'au-onboarding-slide';
            slideEl.innerHTML = ''
                + '<div class="au-onboarding-illustration">' + slide.svg + '</div>'
                + '<h3>' + slide.titulo + '</h3>'
                + '<p>' + slide.texto + '</p>';
            _track.appendChild(slideEl);
        });

        trackWrap.appendChild(_track);

        var footer = document.createElement('div');
        footer.className = 'au-onboarding-footer';

        _dotsWrap = document.createElement('div');
        _dotsWrap.className = 'au-onboarding-dots';
        SLIDES.forEach(function (_, i) {
            var dot = document.createElement('button');
            dot.className = 'au-onboarding-dot';
            dot.setAttribute('aria-label', 'Ir para o slide ' + (i + 1));
            dot.addEventListener('click', function () { _irPara(i); });
            _dotsWrap.appendChild(dot);
        });

        var actions = document.createElement('div');
        actions.className = 'au-onboarding-actions';

        _btnPrev = document.createElement('button');
        _btnPrev.type = 'button';
        _btnPrev.className = 'au-onboarding-prev';
        _btnPrev.innerHTML = '<i class="fas fa-arrow-left"></i> Voltar';
        _btnPrev.addEventListener('click', function () { _irPara(_slideAtual - 1); });

        var btnPular = document.createElement('button');
        btnPular.type = 'button';
        btnPular.className = 'au-onboarding-skip';
        btnPular.textContent = 'Pular';
        btnPular.addEventListener('click', function () { fechar(true); });

        _btnNext = document.createElement('button');
        _btnNext.type = 'button';
        _btnNext.className = 'au-onboarding-next';
        _btnNext.addEventListener('click', function () {
            if (_slideAtual === SLIDES.length - 1) {
                fechar(true);
            } else {
                _irPara(_slideAtual + 1);
            }
        });

        actions.appendChild(_btnPrev);
        actions.appendChild(btnPular);
        actions.appendChild(_btnNext);

        footer.appendChild(_dotsWrap);
        footer.appendChild(actions);

        card.appendChild(btnFechar);
        card.appendChild(trackWrap);
        card.appendChild(footer);
        _overlay.appendChild(card);

        // Fechar clicando fora do card
        _overlay.addEventListener('click', function (ev) {
            if (ev.target === _overlay) fechar(true);
        });

        // Navegação por teclado
        document.addEventListener('keydown', function (ev) {
            if (!_overlay.classList.contains('au-show')) return;
            if (ev.key === 'Escape') fechar(true);
            if (ev.key === 'ArrowRight') _irPara(_slideAtual + 1);
            if (ev.key === 'ArrowLeft') _irPara(_slideAtual - 1);
        });

        document.body.appendChild(_overlay);
        _modalPronto = true;
        _atualizarSlide();
    }

    function _irPara(indice) {
        if (indice < 0 || indice >= SLIDES.length) return;
        _slideAtual = indice;
        _atualizarSlide();
    }

    function _atualizarSlide() {
        _track.style.transform = 'translateX(-' + (_slideAtual * 100) + '%)';

        var dots = _dotsWrap.querySelectorAll('.au-onboarding-dot');
        dots.forEach(function (dot, i) {
            dot.classList.toggle('au-active', i === _slideAtual);
        });

        _btnPrev.classList.toggle('au-hidden', _slideAtual === 0);

        var ultimo = _slideAtual === SLIDES.length - 1;
        _btnNext.innerHTML = ultimo
            ? 'Começar <i class="fas fa-check"></i>'
            : 'Próximo <i class="fas fa-arrow-right"></i>';
    }

    /* ===========================================================
       API PÚBLICA
    =========================================================== */

    /**
     * abrir
     * Mostra o slideshow do início, independente de já ter sido
     * visto antes. Use isso em qualquer botão/menu de "Ajuda" ou
     * "Como funciona" que você quiser adicionar depois.
     */
    function abrir() {
        _construirModal();
        _slideAtual = 0;
        _atualizarSlide();
        requestAnimationFrame(function () {
            _overlay.classList.add('au-show');
        });
    }

    /**
     * fechar
     * Esconde o slideshow.
     * @param {boolean} marcarComoVisto - se true, salva no localStorage
     *   que o usuário já viu, para não aparecer mais automaticamente.
     */
    function fechar(marcarComoVisto) {
        if (!_overlay) return;
        _overlay.classList.remove('au-show');
        if (marcarComoVisto) {
            try { localStorage.setItem(STORAGE_KEY, '1'); } catch (_) {}
        }
    }

    /**
     * jaViuAntes
     * Verifica no localStorage deste navegador se o usuário já
     * passou pelo slideshow alguma vez.
     */
    function jaViuAntes() {
        try {
            return localStorage.getItem(STORAGE_KEY) === '1';
        } catch (_) {
            return false;
        }
    }

    /**
     * resetar
     * Apaga a marcação de "já visto" — útil para testes.
     */
    function resetar() {
        try { localStorage.removeItem(STORAGE_KEY); } catch (_) {}
    }

    /* ===========================================================
       CHECAGEM AUTOMÁTICA NA PRIMEIRA VISITA
    =========================================================== */
    function _checarPrimeiraVisita() {
        if (jaViuAntes()) return;
        // Pequeno delay para deixar a página assentar antes de
        // abrir o modal por cima de tudo.
        setTimeout(abrir, 500);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', _checarPrimeiraVisita);
    } else {
        _checarPrimeiraVisita();
    }

    /* ===========================================================
       EXPOSIÇÃO PÚBLICA — window.AgenteUrbanoOnboarding
    =========================================================== */
    window.AgenteUrbanoOnboarding = {
        abrir: abrir,
        fechar: fechar,
        jaViuAntes: jaViuAntes,
        resetar: resetar
    };

})();
