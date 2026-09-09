<?php
session_start();
header('Content-Type: application/json; charset=utf-8');

// Chave da OpenRouter fornecida
define('OPENROUTER_API_KEY', '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');

// Mesmas credenciais/config usadas em api.php — o assistente lê do mesmo banco.
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'problemas_publicos');

// Lista de modelos para tentativa (Fallback automático)
$modelosDisponiveis = [
    'inclusionai/ling-3.0-flash:free',
    'poolside/laguna-s-2.1:free',
    'cohere/north-mini-code:free',
    'nvidia/nemotron-3.5-content-safety:free'
];

function conectarBancoAssistente()
{
    try {
        $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("set names utf8mb4");
        return $pdo;
    } catch (PDOException $e) {
        // Se o banco não responder, o assistente ainda funciona (só sem dados reais).
        return null;
    }
}

// ── Rótulos de categoria/prioridade (mesmo mapeamento usado no front-end,
// ver formatarCategoria/formatarPrioridade em script.js) — pra a IA receber
// e mostrar pro usuário sempre com acentuação correta, nunca a chave crua. ──
function rotuloCategoriaAssistente($tipo)
{
    $mapa = [
        'iluminacao' => 'Iluminação',
        'asfalto' => 'Asfalto',
        'limpeza' => 'Limpeza',
        'agua-esgoto' => 'Água/Esgoto',
        'transporte' => 'Transporte',
        'assistencial' => 'Assistencial',
        'meteorologico' => 'Meteorológico',
        'mobilidade' => 'Mobilidade',
        'saude' => 'Saúde',
        'seguranca' => 'Segurança',
        'acessibilidade' => 'Acessibilidade',
        'eletricidade' => 'Eletricidade',
        'meio-ambiente' => 'Meio Ambiente',
        'estrutura' => 'Estrutura',
        'drenagem' => 'Drenagem',
        'obras' => 'Obras',
        'ciclismo' => 'Ciclismo',
        'ma-gestao' => 'Má Gestão',
        'outros' => 'Outros'
    ];
    $chave = strtolower(trim((string) $tipo));
    return $mapa[$chave] ?? ($tipo ?: 'Outros');
}

function rotuloPrioridadeAssistente($prioridade)
{
    $mapa = ['baixa' => 'Baixa', 'media' => 'Média', 'alta' => 'Alta', 'urgente' => 'Urgente'];
    $chave = strtolower(trim((string) $prioridade));
    return $mapa[$chave] ?? ($prioridade ?: 'Baixa');
}

// Palavras-chave em português comuns que os usuários usam pra descrever cada
// categoria — usadas pra detectar sobre qual tipo de problema a pergunta é,
// mesmo que a pessoa não use o nome exato da categoria.
function palavrasChaveCategoria()
{
    return [
        'iluminacao' => ['iluminação', 'iluminacao', 'poste', 'lâmpada', 'lampada', 'luz', 'escuro'],
        'asfalto' => ['asfalto', 'buraco', 'buracos', 'via', 'pavimento', 'rua quebrada', 'cratera'],
        'limpeza' => ['limpeza', 'lixo', 'entulho', 'sujeira', 'coleta'],
        'agua-esgoto' => ['água', 'agua', 'esgoto', 'vazamento', 'cano', 'enchente'],
        'transporte' => ['transporte', 'ônibus', 'onibus', 'ponto de ônibus', 'terminal'],
        'assistencial' => ['assistencial', 'assistência social', 'assistencia social'],
        'meteorologico' => ['meteorológico', 'meteorologico', 'chuva', 'temporal', 'clima'],
        'mobilidade' => ['mobilidade', 'trânsito', 'transito', 'semáforo', 'semaforo'],
        'saude' => ['saúde', 'saude', 'posto de saúde', 'hospital', 'ubs'],
        'seguranca' => ['segurança', 'seguranca', 'assalto', 'roubo', 'violência', 'violencia'],
        'acessibilidade' => ['acessibilidade', 'rampa', 'cadeirante', 'deficiente'],
        'eletricidade' => ['eletricidade', 'fiação', 'fiacao', 'energia', 'fio'],
        'meio-ambiente' => ['meio ambiente', 'árvore', 'arvore', 'poluição', 'poluicao', 'desmatamento'],
        'estrutura' => ['estrutura', 'prédio', 'predio', 'muro', 'ponte'],
        'drenagem' => ['drenagem', 'bueiro', 'alagamento', 'entupido'],
        'obras' => ['obra', 'obras', 'construção', 'construcao', 'reforma'],
        'ciclismo' => ['ciclismo', 'ciclovia', 'ciclofaixa', 'bicicleta'],
        'ma-gestao' => ['má gestão', 'ma gestao', 'gestão pública', 'gestao publica'],
    ];
}

function statusMencionado($pergunta)
{
    $p = mb_strtolower($pergunta, 'UTF-8');
    $mapa = [
        'Pendente' => ['pendente', 'pendentes'],
        'Em Análise' => ['em análise', 'em analise', 'analisando'],
        'Em Andamento' => ['em andamento', 'andamento'],
        'Resolvido' => ['resolvido', 'resolvidos', 'resolvida', 'concluído', 'concluido'],
        'Verificado' => ['verificado', 'verificados', 'confirmado'],
        'Invalidado' => ['invalidado', 'inválido', 'invalido', 'falso'],
    ];
    foreach ($mapa as $status => $termos) {
        foreach ($termos as $termo) {
            if (mb_strpos($p, $termo) !== false)
                return $status;
        }
    }
    return null;
}

/**
 * formatarRelatorioParaContexto
 * Formata um relatório (linha da tabela `relatorios`) num bloco de texto
 * compacto e legível pra IA usar como base real da resposta.
 */
function formatarRelatorioParaContexto($r)
{
    $data = '';
    if (!empty($r['data_criacao'])) {
        $ts = strtotime($r['data_criacao']);
        $data = $ts ? date('d/m/Y H:i', $ts) : $r['data_criacao'];
    }
    return "- ID #{$r['id']} | \"{$r['titulo']}\" | Categoria: " . rotuloCategoriaAssistente($r['tipo'])
        . " | Prioridade: " . rotuloPrioridadeAssistente($r['prioridade'])
        . " | Situação: {$r['status']}"
        . " | Endereço: " . ($r['endereco'] ?: 'não informado')
        . " | Criado em: {$data}"
        . " | Descrição: " . mb_substr((string) $r['descricao'], 0, 220, 'UTF-8')
        . (mb_strlen((string) $r['descricao'], 'UTF-8') > 220 ? '...' : '');
}

/**
 * buscarContextoRelatorios
 * Analisa a última pergunta do usuário, decide que tipo de busca faz
 * sentido (ID específico, "meus relatórios", por categoria, por situação,
 * ou busca livre por palavras-chave) e devolve um bloco de texto com dados
 * REAIS do banco pra IA usar. Sempre limitado a poucos resultados, pra não
 * inflar demais o prompt.
 */
function buscarContextoRelatorios($pdo, $pergunta, $username)
{
    if (!$pdo) {
        return "O banco de dados está indisponível no momento — não é possível consultar relatórios reais nesta mensagem.";
    }
    if (!$pergunta || trim($pergunta) === '') {
        return null;
    }

    $LIMITE = 8;
    $blocos = [];

    // 1) ID específico mencionado (#123, relatório 123, protocolo 123, id 123...)
    // Só considera "pergunta sobre um ID específico" com um gatilho
    // inequívoco (#123, "relatório 123", "protocolo 123", "report 123").
    // Removido o gatilho solto por "id" isolado — palavras comuns em
    // português como "dúvidas", "identificar", "atividade" contêm essa
    // sequência de letras e, seguidas de qualquer número por perto,
    // geravam falso positivo (ex.: "tenho dúvidas 2 sobre..." era lido
    // como "ID #2").
    if (preg_match('/(?:#(\d{1,10})|\b(?:relat[óo]rio|protocolo|report)\D{0,3}(\d{1,10})\b)/iu', $pergunta, $m)) {
        $id = (int) (!empty($m[1]) ? $m[1] : $m[2]);
        $stmt = $pdo->prepare("SELECT * FROM relatorios WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($r) {
            $blocos[] = "Relatório #{$id} encontrado:\n" . formatarRelatorioParaContexto($r);
        } else {
            $blocos[] = "Não existe nenhum relatório com o ID #{$id} no banco de dados.";
        }
    }

    // 2) "Meus relatórios" — só funciona se o usuário estiver logado.
    $p = mb_strtolower($pergunta, 'UTF-8');
    // Exige que "relatório/denúncia/report" esteja de fato perto do verbo
    // (até 3 palavras de distância) — evita disparar em frases soltas tipo
    // "eu enviei uma mensagem errada" ou "eu registrei no formulário",
    // que não têm nada a ver com relatórios da plataforma.
    $pediuMeusRelatorios = (bool) preg_match(
        '/\bmeu[s]?\s+(relat[óo]rio|report|den[úu]ncia)|\b(relat[óo]rios?|report|den[úu]ncias?)\b(?:\s+\w+){0,3}\s+que\s+eu\s+(enviei|criei|registrei|fiz)|\beu\s+(enviei|criei|registrei|fiz)\b(?:\s+\w+){0,3}\s+\b(relat[óo]rios?|report|den[úu]ncias?)\b/iu',
        $p
    );
    if ($pediuMeusRelatorios) {
        if ($username) {
            $stmt = $pdo->prepare("SELECT * FROM relatorios WHERE user_id = ? ORDER BY data_criacao DESC LIMIT ?");
            $stmt->bindValue(1, $username, PDO::PARAM_STR);
            $stmt->bindValue(2, $LIMITE, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if ($rows) {
                $texto = "Relatórios enviados pelo usuário logado (\"{$username}\"), mais recentes primeiro:\n";
                foreach ($rows as $r)
                    $texto .= formatarRelatorioParaContexto($r) . "\n";
                $blocos[] = trim($texto);
            } else {
                $blocos[] = "O usuário logado (\"{$username}\") ainda não enviou nenhum relatório.";
            }
        } else {
            $blocos[] = "O usuário não está logado nesta conversa, então não é possível buscar os relatórios pessoais dele. Sugira que ele faça login para consultar seus próprios relatórios.";
        }
    }

    // 3) Situação/status mencionado (pendente, resolvido, em análise...)
    $statusAlvo = statusMencionado($pergunta);

    // 4) Categoria mencionada (por palavra-chave em português comum)
    $categoriaAlvo = null;
    foreach (palavrasChaveCategoria() as $chave => $termos) {
        foreach ($termos as $termo) {
            if (mb_strpos($p, mb_strtolower($termo, 'UTF-8')) !== false) {
                $categoriaAlvo = $chave;
                break 2;
            }
        }
    }

    // Só dispara a busca por categoria/status/palavra-chave se ainda não
    // achou nada específico acima (evita resultado redundante).
    if (!$blocos) {
        $where = [];
        $params = [];

        if ($statusAlvo) {
            $where[] = "status = ?";
            $params[] = $statusAlvo;
        }
        if ($categoriaAlvo) {
            $where[] = "tipo = ?";
            $params[] = $categoriaAlvo;
        }

        // Busca livre: pega palavras "significativas" (mais de 3 letras, fora
        // de uma lista pequena de palavras muito comuns) da pergunta e procura
        // em título/descrição/endereço. Só entra em ação se não achou
        // categoria/status (senão o filtro já é específico o bastante).
        $paradas = [
            'relatório',
            'relatorio',
            'relatórios',
            'relatorios',
            'sobre',
            'algum',
            'alguma',
            'existe',
            'existem',
            'quantos',
            'quantas',
            'qual',
            'quais',
            'você',
            'voce',
            'para',
            'pode',
            'poderia',
            'gostaria',
            'preciso',
            'quero',
            'saber',
            'informa',
            'informação',
            'informacao',
            'tem',
            'têm',
            'está',
            'esta',
            'são',
            'sao',
            'perto',
            'onde',
            // Palavras genéricas demais do próprio domínio — aparecem em quase
            // todo relatório do banco, então usá-las na busca não filtra nada
            // de útil e só traz resultados quase aleatórios pra IA.
            'problema',
            'problemas',
            'cidade',
            'urbano',
            'urbana',
            'situação',
            'situacao',
            'relatado',
            'relato',
            'denúncia',
            'denuncia',
            'denúncias',
            'denuncias',
            'agente',
            'plataforma',
            'sistema',
            'site',
            'aplicativo',
            'app'
        ];
        $palavras = preg_split('/[^\p{L}0-9]+/u', $p, -1, PREG_SPLIT_NO_EMPTY);
        $significativas = [];
        foreach ($palavras as $palavra) {
            if (mb_strlen($palavra, 'UTF-8') > 3 && !in_array($palavra, $paradas, true)) {
                $significativas[] = $palavra;
            }
            if (count($significativas) >= 5)
                break;
        }

        if (!$statusAlvo && !$categoriaAlvo && $significativas) {
            $orParts = [];
            foreach ($significativas as $palavra) {
                $orParts[] = "(titulo LIKE ? OR descricao LIKE ? OR endereco LIKE ?)";
                $like = '%' . $palavra . '%';
                $params[] = $like;
                $params[] = $like;
                $params[] = $like;
            }
            $where[] = '(' . implode(' OR ', $orParts) . ')';
        }

        if ($where) {
            $sql = "SELECT * FROM relatorios WHERE " . implode(' AND ', $where) . " ORDER BY data_criacao DESC LIMIT ?";
            $stmt = $pdo->prepare($sql);
            $i = 1;
            foreach ($params as $val) {
                $stmt->bindValue($i++, $val, PDO::PARAM_STR);
            }
            $stmt->bindValue($i, $LIMITE, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $descCriterio = [];
            if ($statusAlvo)
                $descCriterio[] = "situação \"{$statusAlvo}\"";
            if ($categoriaAlvo)
                $descCriterio[] = "categoria \"" . rotuloCategoriaAssistente($categoriaAlvo) . "\"";
            if (!$descCriterio && $significativas)
                $descCriterio[] = "termos: " . implode(', ', $significativas);
            $criterioTexto = $descCriterio ? implode(' e ', $descCriterio) : 'critérios da pergunta';

            if ($rows) {
                $texto = "Relatórios encontrados no banco de dados para {$criterioTexto} (mais recentes primeiro):\n";
                foreach ($rows as $r)
                    $texto .= formatarRelatorioParaContexto($r) . "\n";
                if (count($rows) >= $LIMITE) {
                    $texto .= "(Pode haver mais resultados além destes {$LIMITE} — se o usuário quiser, peça pra refinar a busca.)";
                }
                $blocos[] = trim($texto);
            } else {
                $blocos[] = "Nenhum relatório foi encontrado no banco de dados para {$criterioTexto}.";
            }
        }
    }

    // 5) Estatísticas gerais — sempre úteis de ter à mão pra perguntas tipo
    // "quantos relatórios existem" / "quantos estão pendentes".
    if (preg_match('/quant[oa]s?\b|estat[íi]stica|total\s+de\s+relat/iu', $pergunta)) {
        $stmt = $pdo->query("SELECT status, COUNT(*) AS qtd FROM relatorios GROUP BY status");
        $contagens = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $totalGeral = array_sum($contagens);
        $texto = "Estatísticas reais atuais da plataforma — total de relatórios: {$totalGeral}.\n";
        foreach ($contagens as $status => $qtd) {
            $texto .= "- {$status}: {$qtd}\n";
        }
        $blocos[] = trim($texto);
    }

    if (!$blocos) {
        return null;
    }

    return implode("\n\n", $blocos);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 1. Recebe os dados de requisições JSON e FormData
    $rawInput = file_get_contents('php://input');
    $jsonInput = json_decode($rawInput, true);

    $messagesPayload = [];

    if (!empty($_POST['prompt'])) {
        $messagesPayload[] = ["role" => "user", "content" => trim($_POST['prompt'])];
    } elseif (!empty($jsonInput['messages']) && is_array($jsonInput['messages'])) {
        // Transforma o histórico do JS no formato de mensagens exigido pela OpenRouter
        foreach ($jsonInput['messages'] as $msg) {
            $role = ($msg['role'] === 'user') ? 'user' : 'assistant';
            $content = $msg['content'] ?? $msg['text'] ?? '';
            if (!empty($content)) {
                $messagesPayload[] = ["role" => $role, "content" => $content];
            }
        }
    } elseif (!empty($jsonInput['prompt'])) {
        $messagesPayload[] = ["role" => "user", "content" => trim($jsonInput['prompt'])];
    }

    if (empty($messagesPayload)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Nenhuma mensagem foi enviada.']);
        exit;
    }

    $instrucoes = file_get_contents(__DIR__ . "/system_prompt.txt");

    // ── Acesso real ao banco: busca relatórios/estatísticas relevantes pra
    // última pergunta do usuário e injeta como um segundo bloco de sistema,
    // separado da persona/instruções gerais. ──
    $pdoAssistente = conectarBancoAssistente();
    $usuarioLogado = $_SESSION['username'] ?? null;

    $ultimaPerguntaUsuario = '';
    for ($i = count($messagesPayload) - 1; $i >= 0; $i--) {
        if ($messagesPayload[$i]['role'] === 'user') {
            $ultimaPerguntaUsuario = $messagesPayload[$i]['content'];
            break;
        }
    }

    $contextoDados = buscarContextoRelatorios($pdoAssistente, $ultimaPerguntaUsuario, $usuarioLogado);

    // Uma ÚNICA mensagem de sistema (persona + dados reais, quando houver),
    // em vez de duas mensagens role:system separadas. Vários modelos
    // gratuitos/menores lidam mal com múltiplas mensagens de sistema —
    // ou ignoram a segunda, ou dão menos peso a ela — o que fazia a IA
    // "esquecer" de usar os dados reais injetados. Concatenar tudo numa
    // só, com os dados reais bem no final (mais perto da pergunta do
    // usuário), aumenta bastante a chance do modelo realmente seguir.
    $conteudoSistema = $instrucoes;
    if ($contextoDados) {
        $conteudoSistema .= "\n\n===== DADOS REAIS DO BANCO DE DADOS DO AGENTE URBANO (para esta pergunta específica) =====\n"
            . "IMPORTANTE: use exclusivamente as informações abaixo para responder sobre relatórios específicos, "
            . "estatísticas ou buscas nesta mensagem. Não invente relatórios, IDs, status, endereços ou números que "
            . "não estejam aqui. Se a busca não encontrou nada, diga isso claramente ao usuário — nunca finja ter "
            . "encontrado algo que não está listado abaixo.\n\n"
            . $contextoDados;
    }

    array_unshift($messagesPayload, [
        "role" => "system",
        "content" => $conteudoSistema
    ]);

    $sucesso = false;
    $respostaTexto = '';
    $ultimoErro = 'Erro desconhecido ao conectar com a API.';

    // 2. Loop de execução tentando os modelos da lista caso um falhe
    foreach ($modelosDisponiveis as $model) {
        $url = "https://openrouter.ai/api/v1/chat/completions";
        $payload = [
            "model" => $model,
            "messages" => $messagesPayload
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . OPENROUTER_API_KEY,
            'HTTP-Referer: http://localhost',
            'X-Title: Agente Urbano IA'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);

        if ($curlErr) {
            $ultimoErro = "Erro de conexão cURL: " . $curlErr;
            continue;
        }

        if ($httpCode === 200) {
            $data = json_decode($response, true);
            $respostaTexto = $data['choices'][0]['message']['content'] ?? '';
            if (!empty($respostaTexto)) {
                $sucesso = true;
                break; // Requisição funcionou, interrompe o loop
            }
        } else {
            $data = json_decode($response, true);
            $ultimoErro = $data['error']['message'] ?? "Status HTTP {$httpCode}";
        }
    }

    // 3. Resposta unificada para garantir compatibilidade com qualquer front-end
    if ($sucesso) {
        echo json_encode([
            'success' => true,
            'response' => trim($respostaTexto),
            'choices' => [
                ['message' => ['content' => trim($respostaTexto)]]
            ],
            'candidates' => [
                ['content' => ['parts' => [['text' => trim($respostaTexto)]]]]
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => ['message' => $ultimoErro]
        ]);
    }
    exit;
}
?>