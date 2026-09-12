/* api.js */
const OPENROUTER_API_KEY = '';

window.AgentUrbanAPI = {
    getConfig: function () {
        return { provedor: 'OpenRouter (Proxy PHP)' };
    },
    enviarPergunta: async function (mensagem, historico) {
        // Envia o histórico completo para a API PHP local
        const response = await fetch('assistente_api.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                messages: historico || [{ role: 'user', content: mensagem }],
                apiKey: OPENROUTER_API_KEY
            })
        });

        const data = await response.json().catch(() => ({}));

        if (!response.ok || data.success === false) {
            const errorMsg = data.error?.message || data.error || `Erro de Servidor (HTTP ${response.status})`;
            throw new Error(errorMsg);
        }

        // Tenta capturar a resposta em qualquer padrão retornado pelo proxy
        if (data.response) {
            return data.response;
        } else if (data.choices && data.choices[0] && data.choices[0].message) {
            return data.choices[0].message.content;
        } else if (data.candidates && data.candidates[0]) {
            return data.candidates[0].content.parts[0].text;
        }

        throw new Error('A resposta recebida da API veio em um formato inválido.');
    }
};