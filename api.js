/**
 * api.js — Camada de integração com APIs de IA
 * Agente Urbano · Assistente IA
 *
 * Estrutura:
 *   1. Configuração (TROCAR PROVEDOR AQUI)
 *   2. Contexto do sistema
 *   3. enviarPergunta() — ponto de entrada único
 *   4. Implementações por provedor
 *   5. Funções de dados (banco de dados)
 *   6. Funções de relatórios e estatísticas
 *   7. Funções de IA avançada (classificação, duplicidade, insights)
 *   8. Exposição pública
 */
(function () {
    'use strict';

    /* ===========================================================
       1. CONFIGURAÇÃO
       ─────────────────────────────────────────────────────────
       Para ativar um provedor:
         1. Troque `provedor` para 'gemini', 'openai', 'ollama'
            ou 'huggingface'
         2. Insira sua chave no bloco correspondente
         3. Salve e recarregue a página
    =========================================================== */
    var CONFIG = {

        /** Provedor ativo.  Opções: 'mock' | 'gemini' | 'openai' | 'ollama' | 'huggingface' */
        provedor: 'gemini',

        /* ── Google Gemini ──────────────────────────────── */
        gemini: {
            // Crie sua chave em: https://makersuite.google.com/app/apikey
            apiKey:   'AQ.Ab8RN6LMhOIz-kSIvNGhLwKz4PHG780sO90KOkvCTWrwPRkeXg',
            modelo:   'gemini-2.0-flash-lite',
            endpoint: 'https://generativelanguage.googleapis.com/v1beta/models/{modelo}:generateContent?key={apiKey}',
        },

        /* ── OpenAI / ChatGPT ───────────────────────────── */
        openai: {
            // Crie sua chave em: https://platform.openai.com/api-keys
            apiKey:   'INSERIR_CHAVE_OPENAI_AQUI',
            modelo:   'gpt-3.5-turbo',
            endpoint: 'https://api.openai.com/v1/chat/completions',
        },

        /* ── Ollama (servidor local) ─────────────────────── */
        ollama: {
            // Execute: ollama serve   (padrão: localhost:11434)
            endpoint: 'http://localhost:11434/api/chat',
            modelo:   'llama3',
        },

        /* ── Hugging Face (via proxy PHP) ───────────────── */
        huggingface: {
            // O JavaScript chama o proxy PHP local — que por sua vez chama o HuggingFace.
            // A chave fica APENAS em assistente_api.php (nunca exposta no browser).
            // Modelo e chave são configurados em: assistente_api.php
            endpoint: 'assistente_api.php',
        },
    };

    /* ===========================================================
       2. CONTEXTO DO SISTEMA
       Enviado automaticamente em toda requisição à IA.
       Edite para ajustar o comportamento do assistente.
    =========================================================== */
    var CONTEXTO_SISTEMA =
        'Você é o Assistente IA do Agente Urbano, uma plataforma de participação cidadã '
      + 'onde moradores registram problemas urbanos, acompanham protocolos, consultam '
      + 'mapas interativos e colaboram com melhorias na cidade.\n\n'
      + 'Suas responsabilidades:\n'
      + '- Ajudar cidadãos a criar e acompanhar protocolos\n'
      + '- Consultar e resumir denúncias urbanas\n'
      + '- Gerar relatórios e estatísticas sobre problemas da cidade\n'
      + '- Identificar padrões e tendências em ocorrências urbanas\n'
      + '- Classificar e priorizar denúncias por urgência\n\n'
      + 'Responda sempre em português brasileiro. Seja objetivo, empático e prestativo. '
      + 'Use markdown para formatar respostas longas (listas, negrito, títulos). '
      + 'Quando não tiver dados específicos do banco, explique que pode buscá-los assim '
      + 'que a integração for ativada.';

    /* ===========================================================
       3. FUNÇÃO PRINCIPAL — enviarPergunta
    =========================================================== */

    /**
     * enviarPergunta
     * Ponto de entrada único para qualquer provedor de IA.
     * Troque CONFIG.provedor para mudar o backend sem tocar em outros módulos.
     *
     * @param {string} pergunta   - texto digitado pelo usuário
     * @param {Array}  historico  - [{role:'user'|'assistant', content:string}]
     * @returns {Promise<string>} - resposta em texto da IA
     */
    async function enviarPergunta(pergunta, historico) {
        historico = historico || [];

        try {
            switch (CONFIG.provedor) {
                case 'gemini':      return await _enviarGemini(pergunta, historico);
                case 'openai':      return await _enviarOpenAI(pergunta, historico);
                case 'ollama':      return await _enviarOllama(pergunta, historico);
                case 'huggingface': return await _enviarHuggingFace(pergunta, historico);
                default:            return await _respostaMock(pergunta);
            }
        } catch (err) {
            console.error('[API] Erro ao enviar pergunta:', err);
            return '⚠️ Ocorreu um erro ao consultar a IA. Verifique sua conexão ou configuração da chave em **api.js**.\n\n`' + err.message + '`';
        }
    }

    /* ===========================================================
       4. IMPLEMENTAÇÕES POR PROVEDOR
    =========================================================== */

    /** Envia para Google Gemini */
    async function _enviarGemini(pergunta, historico) {
        var cfg = CONFIG.gemini;
        var url = cfg.endpoint
            .replace('{modelo}', cfg.modelo)
            .replace('{apiKey}', cfg.apiKey);

        // Formatar histórico no padrão Gemini
        var conteudos = historico.map(function (m) {
            return {
                role: m.role === 'user' ? 'user' : 'model',
                parts: [{ text: m.content }]
            };
        });
        conteudos.push({ role: 'user', parts: [{ text: pergunta }] });

        var body = {
            system_instruction: { parts: [{ text: CONTEXTO_SISTEMA }] },
            contents: conteudos,
            generationConfig: { temperature: 0.7, maxOutputTokens: 1024 }
        };

        var res = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });

        if (!res.ok) {
            var errData = await res.json().catch(function () { return {}; });
            throw new Error('Gemini ' + res.status + ': ' + (errData.error && errData.error.message || res.statusText));
        }

        var data = await res.json();
        return (data.candidates
            && data.candidates[0]
            && data.candidates[0].content
            && data.candidates[0].content.parts
            && data.candidates[0].content.parts[0]
            && data.candidates[0].content.parts[0].text)
            || 'Sem resposta.';
    }

    /** Envia para OpenAI / ChatGPT */
    async function _enviarOpenAI(pergunta, historico) {
        var cfg = CONFIG.openai;

        var mensagens = [{ role: 'system', content: CONTEXTO_SISTEMA }];
        historico.forEach(function (m) {
            mensagens.push({ role: m.role, content: m.content });
        });
        mensagens.push({ role: 'user', content: pergunta });

        var res = await fetch(cfg.endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + cfg.apiKey
            },
            body: JSON.stringify({
                model: cfg.modelo,
                messages: mensagens,
                temperature: 0.7,
                max_tokens: 1024
            })
        });

        if (!res.ok) {
            var errData = await res.json().catch(function () { return {}; });
            throw new Error('OpenAI ' + res.status + ': ' + (errData.error && errData.error.message || res.statusText));
        }

        var data = await res.json();
        return (data.choices && data.choices[0] && data.choices[0].message && data.choices[0].message.content)
            || 'Sem resposta.';
    }

    /** Envia para Ollama (servidor local) */
    async function _enviarOllama(pergunta, historico) {
        var cfg = CONFIG.ollama;

        var mensagens = [{ role: 'system', content: CONTEXTO_SISTEMA }];
        historico.forEach(function (m) {
            mensagens.push({ role: m.role, content: m.content });
        });
        mensagens.push({ role: 'user', content: pergunta });

        var res = await fetch(cfg.endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ model: cfg.modelo, messages: mensagens, stream: false })
        });

        if (!res.ok) throw new Error('Ollama ' + res.status + ': ' + res.statusText);

        var data = await res.json();
        return (data.message && data.message.content) || 'Sem resposta.';
    }

    /**
     * _enviarHuggingFace
     * Chama o proxy PHP local (assistente_api.php) que por sua vez
     * faz a requisição ao HuggingFace no servidor — sem CORS, sem
     * expor a chave no browser.
     */
    async function _enviarHuggingFace(pergunta, historico) {
        var cfg = CONFIG.huggingface;

        // Montar histórico no formato OpenAI
        var mensagens = [{ role: 'system', content: CONTEXTO_SISTEMA }];
        historico.forEach(function (m) {
            mensagens.push({ role: m.role, content: m.content });
        });
        mensagens.push({ role: 'user', content: pergunta });

        // Chamar o proxy PHP — ele faz a chamada real ao HuggingFace
        var res = await fetch(cfg.endpoint, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ messages: mensagens })
        });

        // Tratar erros com mensagem legível
        if (!res.ok) {
            var errData = await res.json().catch(function () { return {}; });
            var errMsg  = (errData.error && (errData.error.message || errData.error))
                       || res.statusText;
            throw new Error('HuggingFace ' + res.status + ': ' + errMsg);
        }

        var data = await res.json();
        return (data.choices
            && data.choices[0]
            && data.choices[0].message
            && data.choices[0].message.content)
            || 'Sem resposta.';
    }

    /** Respostas simuladas para modo desenvolvimento (sem API real) */
    async function _respostaMock(pergunta) {
        // Simula latência de rede
        await _esperar(700 + Math.random() * 500);

        var q = pergunta.toLowerCase();

        if (/protocolo|denúncia|registrar|criar ocorrência|novo relato/.test(q)) {
            return '**Como registrar uma denúncia:**\n\n1. Clique em **Nova Denúncia** no menu\n2. Escolha a **categoria** (buraco, iluminação, lixo…)\n3. Marque o local no **mapa interativo**\n4. Descreva o problema com detalhes\n5. Adicione fotos se possível\n6. Clique em **Enviar**\n\nVocê receberá um **número de protocolo** para acompanhar o andamento. 📋';
        }

        if (/acompanhar|status|andamento|resposta/.test(q)) {
            return '**Como acompanhar sua denúncia:**\n\nAcesse **Meus Protocolos** no menu lateral.\n\nStatus disponíveis:\n- 🟡 **Pendente** — aguardando análise\n- 🔵 **Em andamento** — sendo tratado\n- 🟢 **Resolvido** — problema solucionado\n- 🔴 **Arquivado** — encerrado\n\nVocê também pode buscar por número de protocolo na barra de pesquisa do mapa.';
        }

        if (/relatório|relatorio|relatórios/.test(q)) {
            return '**Relatórios disponíveis:**\n\nPosso gerar relatórios de:\n- 📅 Período (diário, semanal, mensal, anual)\n- 📍 Bairro específico\n- 🏷️ Categoria de problema\n- 🏛️ Órgão responsável\n\nExemplo: *"Gerar relatório mensal do bairro Centro"*\n\n> 💡 A geração automática com dados reais estará disponível após integração com o banco de dados.';
        }

        if (/estatísticas|estatisticas|números|dados/.test(q)) {
            return '**Estatísticas do sistema:**\n\nAtualmente em modo demonstração. Assim que conectado ao banco, poderei exibir:\n\n| Métrica | Descrição |\n|---|---|\n| Total de protocolos | Contagem geral |\n| Taxa de resolução | % resolvidos/total |\n| Tempo médio | Dias para resolver |\n| Bairros críticos | Maior incidência |\n\nDigite: *"Estatísticas do bairro Centro"* para testar.';
        }

        if (/buraco|iluminação|iluminacao|lixo|esgoto|calçada/.test(q)) {
            return '**Problemas urbanos comuns:**\n\nO Agente Urbano aceita denúncias de:\n\n- 🕳️ **Buracos** — na pista ou calçada\n- 💡 **Iluminação** — postes apagados ou com defeito\n- 🗑️ **Lixo** — descarte irregular ou coleta não realizada\n- 💧 **Esgoto** — vazamentos ou bueiros entupidos\n- 🌳 **Poda/árvores** — galhos perigosos\n- 🚧 **Obras** — sinalização inadequada\n\nPara registrar, use o botão **Nova Denúncia** ou clique no mapa.';
        }

        if (/como funciona|o que é|sobre|ajuda/.test(q)) {
            return '**Bem-vindo ao Agente Urbano!** 🏙️\n\nSou uma plataforma de **participação cidadã** que conecta moradores com a gestão pública.\n\n**Você pode:**\n- 📍 Registrar problemas no mapa\n- 📋 Acompanhar protocolos abertos\n- 📊 Ver estatísticas da cidade\n- 🤝 Colaborar com sua comunidade\n\n**Eu (Assistente IA) posso:**\n- Responder suas dúvidas\n- Gerar relatórios\n- Resumir ocorrências\n- Classificar denúncias\n\nConfigure uma API em `api.js` para respostas com IA real! 🤖';
        }

        if (/órgão|orgao|prefeitura|responsável|responsavel/.test(q)) {
            return '**Órgãos responsáveis por categoria:**\n\n| Problema | Órgão |\n|---|---|\n| Buracos na pista | Secretaria de Obras |\n| Iluminação pública | CPFL / Ilume |\n| Coleta de lixo | Secretaria de Limpeza |\n| Esgoto e água | SAAE / SABESP |\n| Parques e árvores | Secretaria de Meio Ambiente |\n| Trânsito e sinalização | STTRANS / CET |\n\nAo registrar uma denúncia, o sistema encaminha automaticamente ao órgão correto.';
        }

        // Resposta genérica
        return 'Entendi: *"' + _truncar(pergunta, 80) + '"*\n\nSou o assistente do **Agente Urbano**. Posso ajudar com:\n\n- 📋 Criar e acompanhar protocolos\n- 📊 Relatórios e estatísticas\n- 🗺️ Informações sobre o mapa\n- 🏛️ Órgãos responsáveis\n- 💡 Como usar o sistema\n\nPara respostas com IA real, configure seu provedor em **api.js** → `CONFIG.provedor`\n\nComo mais posso ajudar? 😊';
    }

    /* ===========================================================
       5. FUNÇÕES DE DADOS
       Todas preparadas para conectar com api.php
    =========================================================== */

    /**
     * listarProtocolos
     * TODO: conectar → api.php?action=list_protocols&limit={limite}
     */
    async function listarProtocolos(limite) {
        limite = limite || 20;
        return { dados: [], total: 0, limite: limite };
    }

    /**
     * buscarPorCategoria
     * TODO: api.php?action=list_protocols&category={categoria}
     */
    async function buscarPorCategoria(categoria) {
        return { dados: [], categoria: categoria };
    }

    /**
     * buscarPorRua
     * TODO: api.php?action=list_protocols&street={rua}
     */
    async function buscarPorRua(rua) {
        return { dados: [], rua: rua };
    }

    /**
     * buscarPorBairro
     * TODO: api.php?action=list_protocols&neighborhood={bairro}
     */
    async function buscarPorBairro(bairro) {
        return { dados: [], bairro: bairro };
    }

    /**
     * buscarPorStatus
     * TODO: api.php?action=list_protocols&status={status}
     */
    async function buscarPorStatus(status) {
        return { dados: [], status: status };
    }

    /**
     * buscarPorUsuario
     * TODO: api.php?action=list_protocols&user={usuario}
     */
    async function buscarPorUsuario(usuario) {
        return { dados: [], usuario: usuario };
    }

    /* ===========================================================
       6. FUNÇÕES DE RELATÓRIOS E ESTATÍSTICAS
    =========================================================== */

    /**
     * gerarRelatorio
     * Gera um relatório de texto utilizando IA + dados do banco.
     * @param {'diario'|'semanal'|'mensal'|'anual'|'bairro'|'categoria'|'orgao'|'periodo'} tipo
     * @param {Object} params  - parâmetros adicionais (ex: { bairro: 'Centro' })
     */
    async function gerarRelatorio(tipo, params) {
        params = params || {};
        var periodos = {
            diario: 'últimas 24 horas', semanal: 'última semana',
            mensal: 'último mês',       anual:   'último ano'
        };
        // TODO: buscar dados reais do banco antes de enviar para IA
        var dados = { tipo: tipo, periodo: periodos[tipo] || tipo, params: params, totalProtocolos: 0 };
        var prompt = 'Gere um relatório resumido do tipo "' + tipo + '" para o sistema Agente Urbano. Dados: ' + JSON.stringify(dados);
        return enviarPergunta(prompt, []);
    }

    /**
     * resumirProtocolo
     * Usa IA para resumir comentários e histórico de um protocolo.
     * @param {{ id, descricao, comentarios: string[] }} protocolo
     */
    async function resumirProtocolo(protocolo) {
        var comentarios = (protocolo.comentarios || []).join(' | ');
        var prompt = 'Resuma em 2 frases curtas este protocolo urbano:\n'
            + 'Descrição: ' + protocolo.descricao + '\n'
            + 'Comentários: ' + (comentarios || 'nenhum');
        return enviarPergunta(prompt, []);
    }

    /**
     * estatisticasBairro
     * TODO: api.php?action=stats&neighborhood={bairro}
     */
    async function estatisticasBairro(bairro) {
        return { bairro: bairro, total: 0, porCategoria: {}, porStatus: {} };
    }

    /**
     * estatisticasCidade
     * TODO: api.php?action=stats
     */
    async function estatisticasCidade() {
        return { total: 0, porCategoria: {}, porBairro: {}, tendencia: [] };
    }

    /**
     * estatisticasPeriodo
     * TODO: api.php?action=stats&from={inicio}&to={fim}
     */
    async function estatisticasPeriodo(inicio, fim) {
        return { inicio: inicio, fim: fim, total: 0, dados: [] };
    }

    /**
     * compararMeses
     * TODO: api.php?action=compare&month1={mes1}&month2={mes2}
     */
    async function compararMeses(mes1, mes2) {
        return { mes1: mes1, mes2: mes2, variacao: 0, detalhes: {} };
    }

    /**
     * compararCategorias
     * TODO: api.php?action=compare_categories
     */
    async function compararCategorias(categorias) {
        return { categorias: categorias, dados: {} };
    }

    /* ===========================================================
       7. FUNÇÕES DE IA AVANÇADA
    =========================================================== */

    /**
     * classificarDenuncia
     * Classifica automaticamente uma denúncia por categoria, prioridade etc.
     * TODO: integrar com IA para classificação real
     * @param {string} descricao
     */
    async function classificarDenuncia(descricao) {
        return {
            categoria:     null,   // 'buraco' | 'iluminacao' | 'lixo' | ...
            prioridade:    null,   // 'alta' | 'media' | 'baixa'
            gravidade:     null,   // 1–5
            tipo:          null,   // 'infraestrutura' | 'servico' | 'ambiental'
            orgaoRespons:  null,   // 'Obras' | 'Iluminacao Publica' | ...
        };
    }

    /**
     * detectarDuplicidade
     * Compara uma nova denúncia com as existentes para identificar duplicatas.
     * TODO: integrar com banco e algoritmo de similaridade textual
     * @param {{ lat, lng, categoria, descricao }} nova
     */
    async function detectarDuplicidade(nova) {
        return { duplicata: false, similar: null, score: 0 };
    }

    /**
     * gerarInsights
     * Gera insights automáticos a partir dos dados do banco via IA.
     * Exemplos: "Buracos aumentaram 30% no Centro", "Iluminação lidera em Julho"
     * TODO: buscar dados reais antes de enviar para IA
     */
    async function gerarInsights() {
        return [];
    }

    /* Utilitários internos */
    function _esperar(ms) {
        return new Promise(function (resolve) { setTimeout(resolve, ms); });
    }

    function _truncar(str, n) {
        return str.length > n ? str.slice(0, n) + '…' : str;
    }

    /* ===========================================================
       8. EXPOSIÇÃO PÚBLICA — window.AgentUrbanAPI
    =========================================================== */
    window.AgentUrbanAPI = {
        // Core
        enviarPergunta:       enviarPergunta,
        CONTEXTO_SISTEMA:     CONTEXTO_SISTEMA,

        // Dados / protocolos
        listarProtocolos:     listarProtocolos,
        buscarPorCategoria:   buscarPorCategoria,
        buscarPorRua:         buscarPorRua,
        buscarPorBairro:      buscarPorBairro,
        buscarPorStatus:      buscarPorStatus,
        buscarPorUsuario:     buscarPorUsuario,

        // Relatórios
        gerarRelatorio:       gerarRelatorio,
        resumirProtocolo:     resumirProtocolo,

        // Estatísticas
        estatisticasBairro:   estatisticasBairro,
        estatisticasCidade:   estatisticasCidade,
        estatisticasPeriodo:  estatisticasPeriodo,
        compararMeses:        compararMeses,
        compararCategorias:   compararCategorias,

        // IA avançada
        classificarDenuncia:  classificarDenuncia,
        detectarDuplicidade:  detectarDuplicidade,
        gerarInsights:        gerarInsights,

        // Configuração (acesso somente leitura para debug)
        getConfig: function () { return { provedor: CONFIG.provedor }; },
    };

})();