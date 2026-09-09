/**
 * assistente.js — Coordenador principal do Assistente IA
 * Agente Urbano · Assistente IA
 */
(function () {
    'use strict';

    let _inicializado = false;
    let _processandoMensagem = false;

    function init() {
        if (_inicializado) return;

        if (window.AgenteUrbanoChatUI && typeof window.AgenteUrbanoChatUI.initUI === 'function') {
            window.AgenteUrbanoChatUI.initUI();
        }

        if (window.AgenteUrbanoChatUI && typeof window.AgenteUrbanoChatUI.renderizarHistorico === 'function') {
            window.AgenteUrbanoChatUI.renderizarHistorico();
        }

        document.addEventListener('au:enviarMensagem', _onEnviarMensagem);

        _inicializado = true;
        console.info('[Assistente] Agente Urbano IA pronto e operacional.');
    }

    async function _onEnviarMensagem(ev) {
        const texto = ev && ev.detail && ev.detail.texto;
        if (!texto || !texto.trim() || _processandoMensagem) return;

        const UI   = window.AgenteUrbanoChatUI;
        const Chat = window.AgenteUrbanoChat;
        const API  = window.AgentUrbanAPI;

        _processandoMensagem = true;

        try {
            if (UI && typeof UI.mostrarBoasVindas === 'function') {
                UI.mostrarBoasVindas(false);
            }

            // Renderiza e persiste a pergunta do usuário
            if (UI) UI.renderizarMensagem('user', texto);
            if (Chat) Chat.adicionarMensagem('user', texto);

            if (UI) {
                UI.bloquearEnvio();
                UI.mostrarDigitando();
            }

            let resposta;
            try {
                const historico = Chat ? Chat.obterHistoricoParaAPI() : [];
                resposta = await API.enviarPergunta(texto, historico);
            } catch (err) {
                console.error('[Assistente] Erro na API:', err);
                resposta = '⚠️ Erro ao contatar a IA: ' + (err.message || 'Erro desconhecido') +
                           '\n\nPor favor, verifique sua conexão ou tente novamente em instantes.';
            }

            if (UI) {
                UI.ocultarDigitando();
                UI.renderizarMensagem('assistant', resposta);
            }
            if (Chat) {
                Chat.adicionarMensagem('assistant', resposta);
            }

            if (UI && typeof UI.isOpen === 'function' && !UI.isOpen()) {
                UI.incrementarBadge();
            }

        } catch (erroInesperado) {
            console.error('[Assistente] Falha crítica:', erroInesperado);
        } finally {
            if (UI && typeof UI.ocultarDigitando === 'function') UI.ocultarDigitando();
            if (UI && typeof UI.liberarEnvio === 'function') UI.liberarEnvio();
            _processandoMensagem = false;
        }
    }

    function enviarMensagemProgramatica(texto) {
        if (!texto) return;
        if (window.AgenteUrbanoChatUI && typeof window.AgenteUrbanoChatUI.abrirJanela === 'function') {
            window.AgenteUrbanoChatUI.abrirJanela();
        }
        const ev = new CustomEvent('au:enviarMensagem', { detail: { texto: texto } });
        document.dispatchEvent(ev);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    window.AgenteUrbano = {
        init: init,
        enviar: enviarMensagemProgramatica,
        abrir: function () { window.AgenteUrbanoChatUI && window.AgenteUrbanoChatUI.abrirJanela(); },
        fechar: function () { window.AgenteUrbanoChatUI && window.AgenteUrbanoChatUI.fecharJanela(); }
    };
})();