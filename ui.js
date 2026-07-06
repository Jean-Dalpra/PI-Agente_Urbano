/**
 * ui.js — Operações de interface do Assistente IA
 * Agente Urbano · Assistente IA
 *
 * Depende de: chat.js (window.AgenteUrbanoChat)
 *
 * Responsabilidades:
 *   - Renderizar mensagens do usuário e da IA
 *   - Controlar abertura / fechamento / minimização da janela
 *   - Exibir / ocultar indicador "digitando..."
 *   - Gerenciar sugestões rápidas
 *   - Auto-resize do textarea
 *   - Scroll automático
 *   - Copiar código para clipboard
 */
(function () {
    'use strict';

    /* ===========================================================
       ELEMENTOS DO DOM
       Resolvidos uma vez na inicialização (initUI)
    =========================================================== */
    var _el = {
        fab:        null,  // #ai-fab
        window:     null,  // #ai-chat-window
        messages:   null,  // .ai-messages-area
        input:      null,  // #ai-input
        sendBtn:    null,  // #ai-send-btn
        typingRow:  null,  // #ai-typing-row
        badge:      null,  // .ai-fab-badge
        suggestions:null,  // #ai-suggestions
        welcome:    null,  // .ai-welcome
        btnClose:   null,  // #ai-btn-close
        btnMin:     null,  // #ai-btn-minimize
        btnClear:   null,  // #ai-btn-clear
    };

    var _isOpen     = false;
    var _isMin      = false;
    var _badgeCount = 0;

    /* ===========================================================
       INICIALIZAÇÃO
    =========================================================== */

    /**
     * initUI
     * Resolve referências de DOM e configura os event listeners da UI.
     * Deve ser chamado uma única vez após o DOM estar pronto.
     */
    function initUI() {
        _el.fab         = document.getElementById('ai-fab');
        _el.window      = document.getElementById('ai-chat-window');
        _el.messages    = document.getElementById('ai-messages');
        _el.input       = document.getElementById('ai-input');
        _el.sendBtn     = document.getElementById('ai-send-btn');
        _el.typingRow   = document.getElementById('ai-typing-row');
        _el.badge       = document.getElementById('ai-fab-badge');
        _el.suggestions = document.getElementById('ai-suggestions');
        _el.welcome     = document.querySelector('.ai-welcome');
        _el.btnClose    = document.getElementById('ai-btn-close');
        _el.btnMin      = document.getElementById('ai-btn-minimize');
        _el.btnClear    = document.getElementById('ai-btn-clear');

        if (!_el.fab || !_el.window || !_el.messages || !_el.input) {
            console.error('[UI] Elementos do assistente não encontrados. Verifique o HTML.');
            return;
        }

        // Botão FAB: abre/fecha a janela
        _el.fab.addEventListener('click', _onFabClick);

        // Cabeçalho: fechar e minimizar
        if (_el.btnClose) _el.btnClose.addEventListener('click', fecharJanela);
        if (_el.btnMin)   _el.btnMin.addEventListener('click',   _onMinimize);
        if (_el.btnClear) _el.btnClear.addEventListener('click', _onClear);

        // Clique no header quando minimizado → restaura
        var header = _el.window.querySelector('.ai-chat-header');
        if (header) {
            header.addEventListener('click', function (e) {
                if (_isMin && !e.target.closest('.ai-hdr-btn')) {
                    _onMinimize();
                }
            });
        }

        // Textarea: auto-resize + Enter/Shift+Enter
        if (_el.input) {
            _el.input.addEventListener('input', _autoResize);
            _el.input.addEventListener('keydown', _onInputKey);
        }

        // Botão enviar
        if (_el.sendBtn) {
            _el.sendBtn.addEventListener('click', _onSend);
        }

        // Sugestões rápidas
        var cards = document.querySelectorAll('.ai-suggestion-card');
        cards.forEach(function (card) {
            card.addEventListener('click', function () {
                preencherInput(card.textContent.trim());
            });
        });
    }

    /* ===========================================================
       ABERTURA / FECHAMENTO / MINIMIZAÇÃO
    =========================================================== */

    /** Alterna abertura/fechamento ao clicar no FAB */
    function _onFabClick() {
        if (_isMin) {
            _isMin = false;
            _el.window.classList.remove('is-minimized');
        }
        if (_isOpen) {
            fecharJanela();
        } else {
            abrirJanela();
        }
    }

    /** Abre a janela do chat */
    function abrirJanela() {
        if (_isOpen) return;
        _isOpen = true;
        _isMin  = false;
        _el.window.classList.add('is-open');
        _el.window.classList.remove('is-minimized');
        _el.fab.classList.add('is-open');
        _el.fab.setAttribute('aria-expanded', 'true');
        ocultarBadge();
        scrollParaBaixo(false);
        setTimeout(function () { _el.input && _el.input.focus(); }, 350);
    }

    /** Fecha a janela do chat */
    function fecharJanela() {
        if (!_isOpen) return;
        _isOpen = false;
        _el.window.classList.remove('is-open', 'is-minimized');
        _el.fab.classList.remove('is-open');
        _el.fab.setAttribute('aria-expanded', 'false');
    }

    /** Minimiza / restaura a janela */
    function _onMinimize() {
        _isMin = !_isMin;
        _el.window.classList.toggle('is-minimized', _isMin);
        if (_el.btnMin) {
            _el.btnMin.title = _isMin ? 'Restaurar' : 'Minimizar';
            _el.btnMin.innerHTML = _isMin ? _svgRestore() : '—';
        }
    }

    /** Limpa o histórico e a UI */
    function _onClear() {
        if (!confirm('Limpar todo o histórico desta conversa?')) return;
        window.AgenteUrbanoChat && window.AgenteUrbanoChat.limparHistorico();
        _limparMensagensDOM();
        mostrarBoasVindas(true);
    }

    /* ===========================================================
       RENDERIZAÇÃO DE MENSAGENS
    =========================================================== */

    /**
     * renderizarMensagem
     * Cria e insere no DOM um balão de mensagem.
     *
     * @param {'user'|'assistant'} role
     * @param {string}             content   - texto markdown (para IA) ou plano (usuário)
     * @param {string}             [iso]     - timestamp ISO (default = agora)
     * @returns {HTMLElement}                - elemento inserido
     */
    function renderizarMensagem(role, content, iso) {
        iso = iso || new Date().toISOString();
        var horario = window.AgenteUrbanoChat
            ? window.AgenteUrbanoChat.formatarHorario(iso)
            : '';

        var isUser = (role === 'user');
        var Chat   = window.AgenteUrbanoChat;

        var msg = document.createElement('div');
        msg.className = 'ai-msg ' + (isUser ? 'ai-msg-user' : 'ai-msg-ai');

        /* Ícone */
        var icon = document.createElement('div');
        icon.className = 'ai-msg-icon';
        icon.innerHTML = isUser ? _svgUser() : _svgAI();

        /* Corpo */
        var body = document.createElement('div');
        body.className = 'ai-msg-body';

        var bubble = document.createElement('div');
        bubble.className = 'ai-msg-bubble';

        if (isUser) {
            // Usuário: texto plano com quebras de linha
            bubble.innerHTML = _escHTML(content).replace(/\n/g, '<br>');
        } else {
            // IA: processar markdown
            bubble.innerHTML = Chat
                ? Chat.parsearMarkdown(content)
                : _escHTML(content);
        }

        var time = document.createElement('div');
        time.className = 'ai-msg-time';
        time.textContent = horario;

        body.appendChild(bubble);
        body.appendChild(time);
        msg.appendChild(icon);
        msg.appendChild(body);

        _el.messages.appendChild(msg);
        scrollParaBaixo(true);

        return msg;
    }

    /**
     * renderizarHistorico
     * Lê o histórico salvo e exibe todas as mensagens na área de chat.
     * Chamado na inicialização quando há histórico existente.
     */
    function renderizarHistorico() {
        var Chat = window.AgenteUrbanoChat;
        if (!Chat) return;

        var msgs = Chat.carregarHistorico();
        if (!msgs || msgs.length === 0) {
            mostrarBoasVindas(true);
            return;
        }

        // Ocultar boas-vindas e sugestões quando há histórico
        mostrarBoasVindas(false);
        _el.messages && _el.messages.querySelectorAll('.ai-msg').forEach(function (el) {
            el.remove();
        });

        msgs.forEach(function (m) {
            renderizarMensagem(m.role, m.content, m.timestamp);
        });

        scrollParaBaixo(false);
    }

    /* ===========================================================
       INDICADOR "DIGITANDO..."
    =========================================================== */

    /** Exibe o indicador de que a IA está processando */
    function mostrarDigitando() {
        if (_el.typingRow) _el.typingRow.removeAttribute('hidden');
        scrollParaBaixo(true);
    }

    /** Oculta o indicador de digitação */
    function ocultarDigitando() {
        if (_el.typingRow) _el.typingRow.setAttribute('hidden', '');
    }

    /* ===========================================================
       SUGESTÕES RÁPIDAS / BOAS-VINDAS
    =========================================================== */

    /**
     * mostrarBoasVindas
     * Exibe ou oculta o bloco de boas-vindas + sugestões rápidas.
     * @param {boolean} mostrar
     */
    function mostrarBoasVindas(mostrar) {
        if (_el.welcome)    _el.welcome.style.display    = mostrar ? '' : 'none';
        if (_el.suggestions) _el.suggestions.style.display = mostrar ? '' : 'none';
    }

    /**
     * preencherInput
     * Coloca um texto no campo de input e move o foco para ele.
     * Usado pelos cards de sugestões e comandos externos.
     * @param {string} texto
     */
    function preencherInput(texto) {
        if (!_el.input) return;
        // Remover emoji e espaço inicial do card
        _el.input.value = texto.replace(/^[\p{Emoji}\s]+/u, '').trim();
        _el.input.focus();
        _autoResize();
        abrirJanela();
    }

    /* ===========================================================
       BADGE DE NOTIFICAÇÃO
    =========================================================== */

    /** Incrementa o badge de notificações no FAB */
    function incrementarBadge() {
        _badgeCount++;
        if (_el.badge) {
            _el.badge.textContent = _badgeCount > 9 ? '9+' : String(_badgeCount);
            _el.badge.classList.remove('hidden');
            _el.badge.removeAttribute('hidden');
        }
    }

    /** Zera e oculta o badge */
    function ocultarBadge() {
        _badgeCount = 0;
        if (_el.badge) _el.badge.classList.add('hidden');
    }

    /* ===========================================================
       ESTADO DO BOTÃO ENVIAR
    =========================================================== */

    /** Bloqueia o botão enviar durante processamento */
    function bloquearEnvio() {
        if (_el.sendBtn) _el.sendBtn.disabled = true;
        if (_el.input)   _el.input.disabled   = true;
    }

    /** Libera o botão enviar após resposta */
    function liberarEnvio() {
        if (_el.sendBtn) _el.sendBtn.disabled = false;
        if (_el.input) {
            _el.input.disabled = false;
            _el.input.focus();
        }
    }

    /* ===========================================================
       UTILITÁRIOS DE DOM
    =========================================================== */

    /** Scrolla a área de mensagens para o final */
    function scrollParaBaixo(suave) {
        if (!_el.messages) return;
        if (suave) {
            _el.messages.scrollTo({ top: _el.messages.scrollHeight, behavior: 'smooth' });
        } else {
            _el.messages.scrollTop = _el.messages.scrollHeight;
        }
    }

    /** Copia conteúdo de um bloco de código para o clipboard */
    function copiarCodigo(btn) {
        var code = btn.closest('.ai-code-block').querySelector('code');
        if (!code) return;
        var texto = code.textContent || '';
        navigator.clipboard.writeText(texto).then(function () {
            btn.textContent = '✓ Copiado';
            setTimeout(function () { btn.textContent = 'Copiar'; }, 2000);
        }).catch(function () {
            // Fallback para navegadores sem clipboard API
            var ta = document.createElement('textarea');
            ta.value = texto;
            ta.style.position = 'fixed';
            ta.style.opacity  = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            btn.textContent = '✓ Copiado';
            setTimeout(function () { btn.textContent = 'Copiar'; }, 2000);
        });
    }

    /** Remove apenas os balões de mensagem (sem remover welcome/suggestions) */
    function _limparMensagensDOM() {
        if (!_el.messages) return;
        var msgs = _el.messages.querySelectorAll('.ai-msg');
        msgs.forEach(function (m) { m.remove(); });
    }

    /* ===========================================================
       EVENT HANDLERS INTERNOS
    =========================================================== */

    /** Evento: tecla pressionada no textarea */
    function _onInputKey(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            _onSend();
        }
    }

    /**
     * _onSend
     * Dispara o evento customizado 'au:enviarMensagem'
     * (capturado pelo assistente.js para orquestrar a chamada à API).
     */
    function _onSend() {
        if (!_el.input) return;
        var texto = _el.input.value.trim();
        if (!texto) return;

        // Limpar e redefinir textarea
        _el.input.value = '';
        _autoResize();

        // Disparar evento para o coordenador (assistente.js)
        var ev = new CustomEvent('au:enviarMensagem', { detail: { texto: texto } });
        document.dispatchEvent(ev);
    }

    /** Auto-resize do textarea conforme o conteúdo */
    function _autoResize() {
        if (!_el.input) return;
        _el.input.style.height = 'auto';
        var maxH = 120;
        _el.input.style.height = Math.min(_el.input.scrollHeight, maxH) + 'px';
    }

    /* ===========================================================
       SVGs INLINE
    =========================================================== */

    function _svgAI() {
        return '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">'
            + '<circle cx="10" cy="10" r="7" stroke="white" stroke-width="1.4"/>'
            + '<circle cx="7.5" cy="8.5" r="1.2" fill="white"/>'
            + '<circle cx="12.5" cy="8.5" r="1.2" fill="white"/>'
            + '<path d="M7 12.5 Q10 14.5 13 12.5" stroke="white" stroke-width="1.2" '
            + 'stroke-linecap="round" fill="none"/>'
            + '<path d="M10 3 V1 M10 19 V17 M3 10 H1 M19 10 H17" stroke="white" '
            + 'stroke-width="1" stroke-linecap="round" opacity="0.5"/>'
            + '</svg>';
    }

    function _svgUser() {
        return '<svg viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">'
            + '<circle cx="10" cy="7" r="3.5" stroke="white" stroke-width="1.4"/>'
            + '<path d="M3 18 C3 14.134 6.134 11 10 11 C13.866 11 17 14.134 17 18" '
            + 'stroke="white" stroke-width="1.4" stroke-linecap="round"/>'
            + '</svg>';
    }

    function _svgRestore() {
        return '<svg viewBox="0 0 14 14" fill="none" xmlns="http://www.w3.org/2000/svg" '
            + 'style="width:12px;height:12px">'
            + '<path d="M2 9 L2 12 L5 12" stroke="currentColor" stroke-width="1.4" '
            + 'stroke-linecap="round" stroke-linejoin="round"/>'
            + '<path d="M12 5 L12 2 L9 2" stroke="currentColor" stroke-width="1.4" '
            + 'stroke-linecap="round" stroke-linejoin="round"/>'
            + '</svg>';
    }

    /** Escapa HTML (duplicado aqui para ui.js ser autocontido) */
    function _escHTML(str) {
        return (str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* ===========================================================
       EXPOSIÇÃO PÚBLICA — window.AgenteUrbanoChatUI
    =========================================================== */
    window.AgenteUrbanoChatUI = {
        initUI:              initUI,
        abrirJanela:         abrirJanela,
        fecharJanela:        fecharJanela,
        renderizarMensagem:  renderizarMensagem,
        renderizarHistorico: renderizarHistorico,
        mostrarDigitando:    mostrarDigitando,
        ocultarDigitando:    ocultarDigitando,
        mostrarBoasVindas:   mostrarBoasVindas,
        preencherInput:      preencherInput,
        scrollParaBaixo:     scrollParaBaixo,
        incrementarBadge:    incrementarBadge,
        ocultarBadge:        ocultarBadge,
        bloquearEnvio:       bloquearEnvio,
        liberarEnvio:        liberarEnvio,
        copiarCodigo:        copiarCodigo,
        isOpen:              function () { return _isOpen; },
    };

})();
