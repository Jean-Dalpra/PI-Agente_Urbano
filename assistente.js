/**
 * assistente.js — Coordenador principal do Assistente IA
 * Agente Urbano · Assistente IA
 *
 * Depende de (nesta ordem):
 *   api.js   → window.AgentUrbanAPI
 *   chat.js  → window.AgenteUrbanoChat
 *   ui.js    → window.AgenteUrbanoChatUI
 *
 * Responsabilidades:
 *   - Inicializar todos os módulos em sequência
 *   - Orquestrar o fluxo completo de envio/recebimento
 *   - Conectar eventos da UI ao backend de IA
 *   - Garantir idempotência (inicializado apenas uma vez)
 *   - Expor API pública para uso externo (window.AgenteUrbano)
 */
(function () {
    'use strict';

    var _inicializado = false;

    /* ===========================================================
       INICIALIZAÇÃO
    =========================================================== */

    /**
     * init
     * Ponto de entrada único. Inicializa a UI, carrega o histórico
     * e registra todos os event listeners.
     * Idempotente — chamadas subsequentes são ignoradas.
     */
    function init() {
        if (_inicializado) return;

        // Verificar se os módulos estão presentes
        if (!window.AgentUrbanAPI || !window.AgenteUrbanoChat || !window.AgenteUrbanoChatUI) {
            console.error(
                '[Assistente] Módulos ausentes. Verifique se api.js, chat.js e ui.js '
              + 'estão carregados ANTES de assistente.js.'
            );
            return;
        }

        // 1. Inicializar a UI (resolve DOM refs + event listeners da interface)
        window.AgenteUrbanoChatUI.initUI();

        // 2. Carregar e renderizar histórico salvo
        window.AgenteUrbanoChatUI.renderizarHistorico();

        // 3. Escutar o evento de envio de mensagem disparado pela UI
        document.addEventListener('au:enviarMensagem', _onEnviarMensagem);

        _inicializado = true;
        console.info('[Assistente] Agente Urbano IA inicializado. Provedor:', window.AgentUrbanAPI.getConfig().provedor);
    }

    /* ===========================================================
       FLUXO DE MENSAGEM
    =========================================================== */

    /**
     * _onEnviarMensagem
     * Handler do evento 'au:enviarMensagem' disparado por ui.js.
     * Orquestra: renderizar → salvar → chamar API → exibir resposta.
     *
     * @param {CustomEvent} ev - ev.detail.texto contém o texto digitado
     */
    async function _onEnviarMensagem(ev) {
        var texto = ev && ev.detail && ev.detail.texto;
        if (!texto || !texto.trim()) return;

        var UI   = window.AgenteUrbanoChatUI;
        var Chat = window.AgenteUrbanoChat;
        var API  = window.AgentUrbanAPI;

        // Ocultar sugestões / boas-vindas na primeira mensagem
        UI.mostrarBoasVindas(false);

        // ── 1. Exibir mensagem do usuário ──────────────────────
        UI.renderizarMensagem('user', texto);
        Chat.adicionarMensagem('user', texto);

        // ── 2. Bloquear input e mostrar "digitando..." ─────────
        UI.bloquearEnvio();
        UI.mostrarDigitando();

        // ── 3. Chamar a API de IA ──────────────────────────────
        var resposta;
        try {
            var historico = Chat.obterHistoricoParaAPI();
            resposta = await API.enviarPergunta(texto, historico);
        } catch (err) {
            console.error('[Assistente] Erro na chamada à API:', err);
            resposta = '⚠️ Erro ao contatar a IA: ' + (err.message || 'desconhecido') + '\n\nVerifique as configurações em **api.js**.';
        }

        // ── 4. Ocultar "digitando..." e exibir resposta ────────
        UI.ocultarDigitando();
        UI.renderizarMensagem('assistant', resposta);
        Chat.adicionarMensagem('assistant', resposta);

        // ── 5. Liberar input ────────────────────────────────────
        UI.liberarEnvio();

        // ── 6. Badge (se janela fechada) ────────────────────────
        if (!UI.isOpen()) {
            UI.incrementarBadge();
        }
    }

    /**
     * enviarMensagemProgramatica
     * Permite enviar uma mensagem por código (sem interação do usuário).
     * Útil para comandos rápidos de outras partes do sistema.
     *
     * Exemplo:
     *   window.AgenteUrbano.enviar('Gerar relatório semanal');
     *
     * @param {string} texto
     */
    function enviarMensagemProgramatica(texto) {
        if (!texto) return;
        window.AgenteUrbanoChatUI && window.AgenteUrbanoChatUI.abrirJanela();
        var ev = new CustomEvent('au:enviarMensagem', { detail: { texto: texto } });
        document.dispatchEvent(ev);
    }

    /**
     * abrirComSugestao
     * Abre o chat e preenche o input com uma sugestão pré-definida.
     *
     * @param {string} texto - sugestão a preencher no input
     */
    function abrirComSugestao(texto) {
        window.AgenteUrbanoChatUI && window.AgenteUrbanoChatUI.abrirJanela();
        window.AgenteUrbanoChatUI && window.AgenteUrbanoChatUI.preencherInput(texto);
    }

    /* ===========================================================
       EXPANSÕES FUTURAS (estrutura preparada)
    =========================================================== */

    /**
     * enviarComContextoProtocolo
     * Envia automaticamente uma pergunta com dados de um protocolo específico.
     * Útil para integração futura com a página de detalhes do protocolo.
     *
     * @param {Object} protocolo - { id, descricao, categoria, status, bairro }
     */
    async function enviarComContextoProtocolo(protocolo) {
        var prompt = 'Analise este protocolo e forneça um resumo e sugestão de ação:\n'
            + JSON.stringify(protocolo, null, 2);
        enviarMensagemProgramatica(prompt);
    }

    /**
     * solicitarRelatorio
     * Solicita um relatório diretamente ao assistente.
     *
     * @param {'diario'|'semanal'|'mensal'|'anual'} tipo
     * @param {Object} params - parâmetros adicionais
     */
    async function solicitarRelatorio(tipo, params) {
        var texto = 'Gerar relatório ' + tipo;
        if (params && params.bairro)    texto += ' do bairro ' + params.bairro;
        if (params && params.categoria) texto += ' de ' + params.categoria;
        enviarMensagemProgramatica(texto);
    }

    /* ===========================================================
       ENTRY POINT
    =========================================================== */
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        // DOM já pronto (script carregado após DOMContentLoaded)
        init();
    }

    /* ===========================================================
       EXPOSIÇÃO PÚBLICA — window.AgenteUrbano
    =========================================================== */
    window.AgenteUrbano = {
        // Controle
        init:   init,
        isAtivo: function () { return _inicializado; },

        // Envio
        enviar:                       enviarMensagemProgramatica,
        abrirComSugestao:             abrirComSugestao,
        enviarComContextoProtocolo:   enviarComContextoProtocolo,
        solicitarRelatorio:           solicitarRelatorio,

        // Janela
        abrir:   function () { window.AgenteUrbanoChatUI && window.AgenteUrbanoChatUI.abrirJanela(); },
        fechar:  function () { window.AgenteUrbanoChatUI && window.AgenteUrbanoChatUI.fecharJanela(); },

        // Acesso aos sub-módulos
        api:  function () { return window.AgentUrbanAPI; },
        chat: function () { return window.AgenteUrbanoChat; },
        ui:   function () { return window.AgenteUrbanoChatUI; },
    };

})();
