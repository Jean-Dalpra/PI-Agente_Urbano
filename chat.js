/**
 * chat.js — Gerenciamento de mensagens e histórico
 * Agente Urbano · Assistente IA
 *
 * Responsabilidades:
 *   - Armazenar e recuperar o histórico via localStorage
 *   - Adicionar / limpar mensagens
 *   - Converter markdown para HTML (sem dependências)
 *   - Formatar timestamps
 */
(function () {
    'use strict';

    var STORAGE_KEY  = 'au_assistente_historico';
    var MAX_MSG      = 60;   // máximo de mensagens retidas no localStorage

    /** Histórico em memória: [{ role, content, timestamp }] */
    var _historico = [];

    /* ===========================================================
       HISTÓRICO — localStorage
    =========================================================== */

    /**
     * carregarHistorico
     * Lê o histórico salvo e preenche a memória interna.
     * @returns {Array} array de mensagens
     */
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

    /**
     * salvarHistorico
     * Persiste o histórico atual no localStorage,
     * mantendo no máximo MAX_MSG mensagens.
     */
    function salvarHistorico() {
        try {
            _historico = _historico.slice(-MAX_MSG);
            localStorage.setItem(STORAGE_KEY, JSON.stringify(_historico));
        } catch (e) {
            console.warn('[Chat] Falha ao salvar histórico:', e);
        }
    }

    /**
     * limparHistorico
     * Apaga histórico da memória e do localStorage.
     */
    function limparHistorico() {
        _historico = [];
        try { localStorage.removeItem(STORAGE_KEY); } catch (_) {}
    }

    /**
     * adicionarMensagem
     * Insere uma mensagem no histórico e persiste.
     * @param {'user'|'assistant'} role
     * @param {string}             content
     * @returns {{ role, content, timestamp }}
     */
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

    /**
     * obterHistoricoParaAPI
     * Retorna as últimas 10 mensagens no formato { role, content }
     * para enviar como contexto à API de IA.
     * @returns {Array<{ role: string, content: string }>}
     */
    function obterHistoricoParaAPI() {
        return _historico
            .slice(-10)
            .map(function (m) { return { role: m.role, content: m.content }; });
    }

    /**
     * obterHistorico
     * Retorna cópia completa do histórico com timestamps.
     * @returns {Array}
     */
    function obterHistorico() {
        return _historico.slice();
    }

    /* ===========================================================
       MARKDOWN PARSER — sem dependências externas
       Suporte: negrito, itálico, código inline, blocos de código,
       listas, tabelas, títulos, blockquote, parágrafos
    =========================================================== */

    /**
     * parsearMarkdown
     * Converte texto markdown em HTML seguro.
     * @param {string} texto - conteúdo em markdown
     * @returns {string}     - HTML resultante
     */
    function parsearMarkdown(texto) {
        if (!texto) return '';

        var html = _escHTML(texto);

        /* ── Blocos de código ─────────────────────────────────
           Processar ANTES de tudo para não transformar conteúdo interno */
        html = html.replace(/```(\w*)\n?([\s\S]*?)```/g, function (_, lang, code) {
            var langAttr = lang ? ' data-lang="' + _escHTML(lang) + '"' : '';
            var copyBtn  = '<button class="ai-code-copy" onclick="AgenteUrbanoChatUI&&AgenteUrbanoChatUI.copiarCodigo(this)">Copiar</button>';
            return '<div class="ai-code-block"' + langAttr + '>' + copyBtn
                 + '<pre><code>' + code.trim() + '</code></pre></div>';
        });

        /* ── Código inline ──────────────────────────────────── */
        html = html.replace(/`([^`\n]+)`/g, '<code class="ai-inline-code">$1</code>');

        /* ── Títulos ────────────────────────────────────────── */
        html = html.replace(/^### (.+)$/gm, '<h3 class="ai-md-h3">$1</h3>');
        html = html.replace(/^## (.+)$/gm,  '<h2 class="ai-md-h2">$1</h2>');
        html = html.replace(/^# (.+)$/gm,   '<h1 class="ai-md-h1">$1</h1>');

        /* ── Negrito e itálico ──────────────────────────────── */
        html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        html = html.replace(/\*(.+?)\*/g,     '<em>$1</em>');

        /* ── Blockquote ─────────────────────────────────────── */
        html = html.replace(/^&gt; (.+)$/gm,
            '<blockquote class="ai-blockquote">$1</blockquote>');

        /* ── Tabelas (| col | col |) ────────────────────────── */
        html = _parsearTabela(html);

        /* ── Listas não-ordenadas (- item) ──────────────────── */
        html = html.replace(/((?:^- .+(?:\n|$))+)/gm, function (bloco) {
            var itens = bloco.trim().split('\n').map(function (l) {
                return '<li>' + l.replace(/^- /, '') + '</li>';
            }).join('');
            return '<ul class="ai-md-ul">' + itens + '</ul>';
        });

        /* ── Listas numeradas (1. item) ─────────────────────── */
        html = html.replace(/((?:^\d+\. .+(?:\n|$))+)/gm, function (bloco) {
            var itens = bloco.trim().split('\n').map(function (l) {
                return '<li>' + l.replace(/^\d+\. /, '') + '</li>';
            }).join('');
            return '<ol class="ai-md-ol">' + itens + '</ol>';
        });

        /* ── Parágrafos (dupla quebra de linha) ─────────────── */
        // Envolver em <p> apenas blocos que não sejam listas/tabelas/headers/código
        html = html.split(/\n\n+/).map(function (bloco) {
            bloco = bloco.trim();
            if (!bloco) return '';
            // Não envolver em <p> blocos que já são elementos bloco
            if (/^<(h[1-3]|ul|ol|blockquote|div|table)/.test(bloco)) return bloco;
            return '<p class="ai-md-p">' + bloco + '</p>';
        }).join('\n');

        /* ── Quebras de linha simples → <br> ────────────────── */
        html = html.replace(/(?<!<\/[a-z]+>)\n(?!<[a-z])/g, '<br>');

        return html;
    }

    /** Parseia tabelas markdown */
    function _parsearTabela(html) {
        return html.replace(/((?:^\|.+\|[ \t]*(?:\n|$))+)/gm, function (bloco) {
            var linhas = bloco.trim().split('\n').filter(function (l) { return l.trim(); });
            if (linhas.length < 2) return bloco;

            // Linha separadora (|---|---|)
            var isSep = function (l) { return /^\|[\s\-:|]+\|/.test(l); };

            var cabecalho = linhas[0]
                .split('|')
                .filter(function (c) { return c.trim(); })
                .map(function (c) { return '<th>' + c.trim() + '</th>'; })
                .join('');

            var corpo = linhas
                .slice(2) // pular separador
                .filter(function (l) { return !isSep(l); })
                .map(function (l) {
                    var cols = l.split('|')
                        .filter(function (c) { return c.trim(); })
                        .map(function (c) { return '<td>' + c.trim() + '</td>'; })
                        .join('');
                    return '<tr>' + cols + '</tr>';
                })
                .join('');

            return '<div class="ai-table-wrapper">'
                 + '<table class="ai-md-table">'
                 + '<thead><tr>' + cabecalho + '</tr></thead>'
                 + '<tbody>' + corpo + '</tbody>'
                 + '</table></div>';
        });
    }

    /**
     * _escHTML — escapa caracteres HTML para evitar XSS
     * Chamado antes de qualquer processamento de markdown.
     */
    function _escHTML(str) {
        return str
            .replace(/&/g,  '&amp;')
            .replace(/</g,  '&lt;')
            .replace(/>/g,  '&gt;')
            .replace(/"/g,  '&quot;')
            .replace(/'/g,  '&#39;');
    }

    /* ===========================================================
       UTILITÁRIOS
    =========================================================== */

    /**
     * formatarHorario
     * Converte ISO string em "HH:MM" no fuso local.
     * @param {string} iso
     * @returns {string}
     */
    function formatarHorario(iso) {
        try {
            return new Date(iso).toLocaleTimeString('pt-BR', {
                hour: '2-digit', minute: '2-digit'
            });
        } catch (_) {
            return '';
        }
    }

    /* ===========================================================
       EXPOSIÇÃO PÚBLICA — window.AgenteUrbanoChat
    =========================================================== */
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
