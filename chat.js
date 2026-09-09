/**
 * chat.js — Gerenciamento de mensagens e histórico
 * Agente Urbano · Assistente IA
 */
(function () {
    'use strict';

    var STORAGE_KEY  = 'au_assistente_historico';
    var MAX_MSG      = 60;

    var _historico = [];

    function carregarHistorico() {
        try {
            var raw = localStorage.getItem(STORAGE_KEY);
            _historico = raw ? JSON.parse(raw) : [];
        } catch (e) {
            console.warn('[Chat] Falha ao carregar histórico:', e);
            _historico = [];
        }
        return _historico.slice();
    }

    function salvarHistorico() {
        try {
            _historico = _historico.slice(-MAX_MSG);
            localStorage.setItem(STORAGE_KEY, JSON.stringify(_historico));
        } catch (e) {
            console.warn('[Chat] Falha ao salvar histórico:', e);
        }
    }

    function limparHistorico() {
        _historico = [];
        try { localStorage.removeItem(STORAGE_KEY); } catch (_) {}
    }

    function adicionarMensagem(role, content) {
        var msg = {
            role:      role,
            content:   content,
            timestamp: new Date().toISOString()
        };
        _historico.push(msg);
        salvarHistorico();
        return msg;
    }

    function obterHistoricoParaAPI() {
        return _historico
            .slice(-10)
            .map(function (m) { return { role: m.role, content: m.content }; });
    }

    function obterHistorico() {
        return _historico.slice();
    }

    function parsearMarkdown(texto) {
        if (!texto) return '';

        var html = _escHTML(texto);

        // 1. Blocos de código ```
        html = html.replace(/```(\w*)\n?([\s\S]*?)```/g, function (_, lang, code) {
            var langAttr = lang ? ' data-lang="' + _escHTML(lang) + '"' : '';
            var copyBtn  = '<button class="ai-code-copy" onclick="AgenteUrbanoChatUI&&AgenteUrbanoChatUI.copiarCodigo(this)">Copiar</button>';
            return '<div class="ai-code-block"' + langAttr + '>' + copyBtn
                 + '<pre><code>' + code.trim() + '</code></pre></div>';
        });

        // 2. Código inline `code`
        html = html.replace(/`([^`\n]+)`/g, '<code class="ai-inline-code">$1</code>');

        // 3. Títulos #
        html = html.replace(/^### (.+)$/gm, '<h3 class="ai-md-h3">$1</h3>');
        html = html.replace(/^## (.+)$/gm,  '<h2 class="ai-md-h2">$1</h2>');
        html = html.replace(/^# (.+)$/gm,   '<h1 class="ai-md-h1">$1</h1>');

        // 4. Negrito e itálico
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/\*(.+?)\*/g,     '<em>$1</em>');

        // 5. Blockquote
        html = html.replace(/^&gt; (.+)$/gm, '<blockquote class="ai-blockquote">$1</blockquote>');

        // 6. Listas não ordenadas
        html = html.replace(/((?:^- .+(?:\n|$))+)/gm, function (bloco) {
            var itens = bloco.trim().split('\n').map(function (l) {
                return '<li>' + l.replace(/^- /, '') + '</li>';
            }).join('');
            return '<ul class="ai-md-ul">' + itens + '</ul>';
        });

        // 7. Parágrafos e quebras de linha
        html = html.split(/\n\n+/).map(function (bloco) {
            bloco = bloco.trim();
            if (!bloco) return '';
            if (/^<(h[1-3]|ul|ol|blockquote|div|table)/.test(bloco)) return bloco;
            return '<p class="ai-md-p">' + bloco.replace(/\n/g, '<br>') + '</p>';
        }).join('\n');

        return html;
    }

    function _escHTML(str) {
        return (str || '')
            .replace(/&/g,  '&amp;')
            .replace(/</g,  '&lt;')
            .replace(/>/g,  '&gt;')
            .replace(/"/g,  '&quot;')
            .replace(/'/g,  '&#39;');
    }

    function formatarHorario(iso) {
        try {
            return new Date(iso).toLocaleTimeString('pt-BR', {
                hour: '2-digit', minute: '2-digit'
            });
        } catch (_) {
            return '';
        }
    }

    window.AgenteUrbanoChat = {
        carregarHistorico:      carregarHistorico,
        salvarHistorico:        salvarHistorico,
        limparHistorico:        limparHistorico,
        adicionarMensagem:      adicionarMensagem,
        obterHistoricoParaAPI:  obterHistoricoParaAPI,
        obterHistorico:         obterHistorico,
        parsearMarkdown:        parsearMarkdown,
        formatarHorario:        formatarHorario,
    };
})();