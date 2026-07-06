<?php
/**
 * assistente_api.php — Proxy PHP para a API do HuggingFace
 * Agente Urbano · Assistente IA
 *
 * Por que este arquivo existe?
 *   O browser não pode chamar o HuggingFace diretamente por dois motivos:
 *     1. CORS — a API não permite chamadas cross-origin do browser
 *     2. Segurança — a chave API ficaria exposta no código JavaScript
 *   Este proxy recebe a requisição do JavaScript, faz a chamada no servidor
 *   e devolve a resposta — a chave nunca sai do servidor.
 *
 * Requisitos do servidor:
 *   - PHP 7.4+ com extensão cURL habilitada
 *   - Acesso externo à internet (para o servidor chamar o HuggingFace)
 */

// ══════════════════════════════════════════════════════════════
//  CONFIGURAÇÃO — edite apenas esta seção
// ══════════════════════════════════════════════════════════════

// ↓↓↓ COLE SUA CHAVE AQUI (começa com hf_) ↓↓↓
define('AU_HF_API_KEY', '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');

// Modelo a utilizar.  Alternativas gratuitas:
//   'mistralai/Mistral-7B-Instruct-v0.2'
//   'microsoft/Phi-3-mini-4k-instruct'
//   'Qwen/Qwen2.5-7B-Instruct'
define('AU_HF_MODELO', 'HuggingFaceH4/zephyr-7b-beta');

// Parâmetros de geração
define('AU_HF_MAX_TOKENS',  1024);
define('AU_HF_TEMPERATURE', 0.7);
define('AU_HF_TIMEOUT',     60);    // segundos

// ══════════════════════════════════════════════════════════════
//  HEADERS DE RESPOSTA
// ══════════════════════════════════════════════════════════════
header('Content-Type: application/json; charset=utf-8');

// Permitir chamadas apenas da mesma origem (mesma pasta/domínio)
// Se precisar de outro domínio, troque '*' pelo domínio específico
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Preflight OPTIONS — responder e sair
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ══════════════════════════════════════════════════════════════
//  VALIDAÇÃO DA REQUISIÇÃO
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    _erro(405, 'Método não permitido. Use POST.');
}

$corpo = file_get_contents('php://input');
if (!$corpo) {
    _erro(400, 'Corpo da requisição vazio.');
}

$dados = json_decode($corpo, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    _erro(400, 'JSON inválido: ' . json_last_error_msg());
}

if (empty($dados['messages']) || !is_array($dados['messages'])) {
    _erro(400, 'Campo "messages" ausente ou inválido.');
}

// ══════════════════════════════════════════════════════════════
//  VERIFICAÇÃO DA CHAVE
// ══════════════════════════════════════════════════════════════
if (AU_HF_API_KEY === 'INSERIR_CHAVE_HF_AQUI' || empty(AU_HF_API_KEY)) {
    _erro(500, 'Chave da API não configurada. Edite assistente_api.php e insira sua chave hf_...');
}

// ══════════════════════════════════════════════════════════════
//  CHAMADA AO HUGGINGFACE
// ══════════════════════════════════════════════════════════════
$endpoint = 'https://api-inference.huggingface.co/models/'
          . AU_HF_MODELO
          . '/v1/chat/completions';

$payload = json_encode([
    'model'       => AU_HF_MODELO,
    'messages'    => $dados['messages'],
    'max_tokens'  => AU_HF_MAX_TOKENS,
    'temperature' => AU_HF_TEMPERATURE,
], JSON_UNESCAPED_UNICODE);

// Verificar se cURL está disponível
if (!function_exists('curl_init')) {
    _erro(500, 'A extensão cURL não está habilitada no PHP. Contate o suporte do servidor.');
}

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Authorization: Bearer ' . AU_HF_API_KEY,
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_TIMEOUT        => AU_HF_TIMEOUT,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_USERAGENT      => 'AgenteUrbano/1.0',
]);

$resposta   = curl_exec($ch);
$httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErro   = curl_error($ch);
$curlErrNum = curl_errno($ch);
curl_close($ch);

// Erro de conexão (DNS, timeout, SSL, etc.)
if ($curlErro) {
    _erro(502, 'Erro de conexão com o HuggingFace: [' . $curlErrNum . '] ' . $curlErro);
}

// Repassar o código HTTP e a resposta do HuggingFace
http_response_code($httpCode);
echo $resposta;

// ══════════════════════════════════════════════════════════════
//  HELPER — resposta de erro padronizada
// ══════════════════════════════════════════════════════════════
function _erro(int $codigo, string $mensagem): void
{
    http_response_code($codigo);
    echo json_encode([
        'error' => [
            'message' => $mensagem,
            'code'    => $codigo,
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}