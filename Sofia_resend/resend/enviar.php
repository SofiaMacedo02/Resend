<?php
declare(strict_types=1);

// Em produção, não exibir erros do PHP na tela
ini_set('display_errors', '0');

// data e hora do log no horário de Brasília
date_default_timezone_set('America/Sao_Paulo');

// Só aceita POST (abrir a URL direto no navegador não envia nada)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método não permitido.');
}

$config = require __DIR__ . '/config.php';
// config.php precisa ter: api_url, api_key, remetente e destino

// ---------- Funções ----------

/**
 * Grava UMA linha em envios.log:
 * data e hora | destinatário | código HTTP | id (sucesso) ou mensagem de erro
 * Nunca recebe a chave da API nem os cabeçalhos da requisição.
 * É AQUI que vai o detalhe técnico: a tela nunca mostra isso.
 */
function registrarEnvio(string $destinatario, int $status, string $detalhe): void
{
    // tira quebras de linha e "|" para ninguém conseguir forjar linhas no log
    $limpar = fn(string $s): string => trim(str_replace(["\r", "\n", '|'], ' ', $s));

    $linha = sprintf(
        "%s | %s | %d | %s\n",
        date('Y-m-d H:i:s'),
        $limpar($destinatario),
        $status,
        $limpar($detalhe)
    );

    // FILE_APPEND: acrescenta no fim (sem ele, cada envio apaga o anterior)
    // LOCK_EX: evita linhas misturadas se dois envios caírem juntos
    if (file_put_contents(__DIR__ . '/envios.log', $linha, FILE_APPEND | LOCK_EX) === false) {
        error_log('[contato] não consegui gravar em envios.log');
    }
}

/**
 * Faz um POST na API do Resend e registra a tentativa no log.
 * Devolve:
 *   status => código HTTP (0 se a API nem respondeu)
 *   json   => resposta da API como array (vazio se não for JSON)
 *   erro   => texto do erro do cURL ('' se não houve falha)
 *   rede   => true se a requisição nem chegou ao Resend
 */
function enviarResend(array $config, array $dados): array
{
    try {
        $payload = json_encode($dados, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $erro = 'JSON inválido: ' . $e->getMessage();
        registrarEnvio($dados['to'][0], 0, $erro);
        return ['status' => 0, 'json' => [], 'erro' => $erro, 'rede' => false];
    }

    $ch = curl_init($config['api_url']);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $config['api_key'],
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => $payload,
    ]);

    $resposta = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    $json = json_decode((string) $resposta, true);
    $json = is_array($json) ? $json : [];

    // registra TODA tentativa: sucesso, erro da API e falha de rede
    if ($erroCurl !== '') {
        $detalhe = $erroCurl;                                  // status 0: nem chegou na API
    } elseif ($status >= 200 && $status < 300) {
        $detalhe = $json['id'] ?? 'sem id';                    // sucesso: id do envio
    } else {
        $detalhe = $json['message'] ?? 'erro desconhecido';    // erro: mensagem da API
    }
    registrarEnvio($dados['to'][0], $status, $detalhe);

    return [
        'status' => $status,
        'json'   => $json,
        'erro'   => $erroCurl,
        'rede'   => $erroCurl !== '',
    ];
}

function sucesso(array $r): bool
{
    return !$r['rede'] && $r['erro'] === '' && $r['status'] >= 200 && $r['status'] < 300;
}

/**
 * Traduz o resultado de uma tentativa que falhou numa frase para o visitante.
 * Devolve [código HTTP da nossa resposta, frase].
 * Nunca usa o texto da API: só frases escritas aqui.
 */
function erroParaVisitante(array $r): array
{
    // a requisição nem chegou ao Resend (sem código HTTP)
    if ($r['rede']) {
        return [502, 'Não conseguimos nos conectar ao serviço de e-mail agora, então a sua mensagem não foi enviada. Tente de novo em alguns minutos.'];
    }

    $status = $r['status'];

    $frases = [
        401 => 'O site está com um problema de configuração no serviço de e-mail e a sua mensagem não foi enviada. Avise o responsável pelo site.',
        403 => 'O serviço de e-mail recusou este envio por uma restrição de configuração do site, e a sua mensagem não foi enviada. Avise o responsável pelo site.',
        422 => 'O serviço de e-mail não aceitou os dados enviados. Confira se o seu e-mail está escrito corretamente e tente de novo.',
        429 => 'Recebemos muitas tentativas em pouco tempo. Aguarde alguns minutos e tente de novo.',
    ];

    if (isset($frases[$status])) {
        // 422 e 429 mantêm o código; 401/403 são problema nosso, não do visitante
        $http = in_array($status, [422, 429], true) ? $status : 502;
        return [$http, $frases[$status]];
    }

    if ($status >= 500) {
        return [502, 'O serviço de e-mail está instável no momento. Tente novamente em alguns minutos.'];
    }

    // qualquer código que não foi previsto (o detalhe já está no envios.log)
    return [502, 'Não foi possível enviar a sua mensagem agora. Tente novamente em alguns minutos; se o problema continuar, avise o responsável pelo site.'];
}

/**
 * Monta o e-mail em HTML "de verdade": layout em <table>, estilo inline,
 * largura máxima de 600px e nenhum <style> (o Gmail corta).
 * $corpoHtml entra como está: quem chama é responsável por ter passado
 * qualquer texto do visitante por htmlspecialchars ANTES.
 */
function layoutEmail(string $titulo, string $corpoHtml, string $rodape, string $logoUrl = ''): string
{
    $titulo = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
    $rodape = htmlspecialchars($rodape, ENT_QUOTES, 'UTF-8');

    // imagem só por URL pública e sempre com alt (muitos clientes bloqueiam imagem)
    $logo = '';
    if ($logoUrl !== '') {
        $logoSeguro = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
        $logo = '<img src="' . $logoSeguro . '" alt="Logo do site" width="120" '
              . 'style="display: block; border: 0; margin: 0 0 12px 0; max-width: 120px; height: auto;">';
    }

    // bgcolor ao lado do style: o Outlook (motor do Word) respeita o atributo
    return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$titulo}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f4f4f4;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="#f4f4f4" style="background-color: #f4f4f4; border-collapse: collapse;">
<tr><td align="center" style="padding: 20px 10px;">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" bgcolor="#ffffff" style="width: 100%; max-width: 600px; font-family: Arial, sans-serif; border-collapse: collapse; background-color: #ffffff;">
<tr><td bgcolor="#0F2E5C" style="background-color: #0F2E5C; color: #ffffff; padding: 20px; font-family: Arial, sans-serif;">
{$logo}<h2 style="margin: 0; font-size: 22px; color: #ffffff; font-family: Arial, sans-serif;">{$titulo}</h2>
</td></tr>
<tr><td style="padding: 20px; color: #333333; font-size: 15px; line-height: 1.5; font-family: Arial, sans-serif;">
{$corpoHtml}
</td></tr>
<tr><td bgcolor="#f4f4f4" style="background-color: #f4f4f4; padding: 14px; font-size: 12px; color: #777777; font-family: Arial, sans-serif;">
{$rodape}
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>
HTML;
}

/**
 * Mostra a página de resultado (sucesso ou erro) e encerra.
 */
function paginaResultado(bool $ok, int $http, string $titulo, string $texto, string $idEnvio = ''): void
{
    http_response_code($http);

    $titulo  = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
    $texto   = htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
    $idEnvio = htmlspecialchars($idEnvio, ENT_QUOTES, 'UTF-8');
    $classe  = $ok ? 'ok' : 'erro';
    $icone   = $ok ? '✓' : '!';
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $titulo; ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        }
        body {
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            background-color: #0f172a;
            color: #f8fafc;
            padding: 20px;
        }
        .card {
            width: 100%;
            max-width: 440px;
            background-color: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 32px;
            text-align: center;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
        }
        .icon-box {
            width: 64px;
            height: 64px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px auto;
            font-size: 28px;
            font-weight: 700;
        }
        .icon-box.ok {
            background-color: rgba(34, 197, 94, 0.15);
            border: 1px solid #22c55e;
            color: #4ade80;
        }
        .icon-box.erro {
            background-color: rgba(239, 68, 68, 0.15);
            border: 1px solid #ef4444;
            color: #f87171;
        }
        h1 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 8px;
        }
        p {
            color: #94a3b8;
            font-size: 0.95rem;
            margin-bottom: 20px;
            line-height: 1.5;
        }
        .protocol {
            display: inline-block;
            background-color: #0f172a;
            border: 1px solid #334155;
            padding: 8px 14px;
            border-radius: 6px;
            font-family: monospace;
            font-size: 0.85rem;
            color: #38bdf8;
            margin-bottom: 24px;
            word-break: break-all;
        }
        .btn {
            display: block;
            width: 100%;
            padding: 12px 20px;
            background-color: #0284c7;
            color: #ffffff;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95rem;
            transition: background-color 0.2s ease-in-out;
        }
        .btn:hover {
            background-color: #0369a1;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon-box <?= $classe; ?>"><?= $icone; ?></div>
        <h1><?= $titulo; ?></h1>
        <p><?= $texto; ?></p>
        <?php if ($idEnvio !== ''): ?>
            <div class="protocol">ID: <?= $idEnvio; ?></div>
        <?php endif; ?>
        <a href="index.html" class="btn">Voltar ao Formulário</a>
    </div>
</body>
</html>
    <?php
    exit;
}

// ---------- Honeypot: bots costumam preencher todos os campos ----------

if (!empty($_POST['website'] ?? '')) {
    exit; // finge que deu certo, sem enviar nada
}

// ---------- 1. Leitura e validação dos campos ----------

$nome     = trim($_POST['nome'] ?? '');
$mensagem = trim($_POST['mensagem'] ?? '');
$email    = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);

// sem quebras de linha no nome (ele vai para o assunto)
$nome = str_replace(["\r", "\n"], ' ', $nome);

if ($nome === '' || $mensagem === '' || !$email) {
    paginaResultado(false, 422, 'Faltou alguma coisa', 'Preencha nome, e-mail (escrito corretamente) e mensagem, e tente de novo.');
}

if (mb_strlen($nome) > 100 || mb_strlen($mensagem) > 5000) {
    paginaResultado(false, 422, 'Texto muito longo', 'O nome pode ter até 100 caracteres e a mensagem até 5000. Reduza o texto e tente de novo.');
}

if (!mb_check_encoding($nome . $mensagem, 'UTF-8')) {
    paginaResultado(false, 422, 'Caracteres inválidos', 'Há caracteres que não conseguimos processar no texto. Reescreva a mensagem e tente de novo.');
}

$nomeSeguro     = htmlspecialchars($nome, ENT_QUOTES, 'UTF-8');
$emailSeguro    = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$mensagemSegura = nl2br(htmlspecialchars($mensagem, ENT_QUOTES, 'UTF-8'));

// ---------- 2. E-mail para VOCÊ (destino fixo + reply_to do visitante) ----------

// tudo que o visitante digitou já passou por htmlspecialchars acima
$corpoParaVoce =
    '<p style="margin: 0 0 8px 0;"><strong>Nome:</strong> ' . $nomeSeguro . '</p>'
  . '<p style="margin: 0 0 16px 0;"><strong>E-mail:</strong> '
  . '<a href="mailto:' . $emailSeguro . '" style="color: #0F2E5C;">' . $emailSeguro . '</a></p>'
  . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="border-collapse: collapse;">'
  . '<tr><td bgcolor="#f4f6fa" style="background-color: #f4f6fa; border-left: 4px solid #0F2E5C; padding: 14px; color: #333333; font-size: 15px; line-height: 1.5; font-family: Arial, sans-serif;">'
  . $mensagemSegura
  . '</td></tr></table>';

$paraVoce = [
    'from'     => $config['remetente'],
    'to'       => [$config['destino']],
    'reply_to' => [$email], // responder no Gmail já vai para o visitante
    'subject'  => 'Contato do site — ' . $nome,
    'html'     => layoutEmail(
        'Contato do site',
        $corpoParaVoce,
        'Enviado pelo formulário do site.',
        (string) ($config['logo_url'] ?? '')
    ),
    'text'     => "Nome: {$nome}\nE-mail: {$email}\n\n{$mensagem}",
];

$r = enviarResend($config, $paraVoce);

if (!sucesso($r)) {
    // o detalhe técnico já foi para o envios.log dentro de enviarResend()
    [$http, $frase] = erroParaVisitante($r);
    paginaResultado(false, $http, 'Não foi possível enviar', $frase);
}

$idEnvio = (string) ($r['json']['id'] ?? '');

// ---------- 3. Confirmação para o VISITANTE (falha aqui NÃO derruba nada) ----------

$confirmacao = [
    'from'    => $config['remetente'],
    'to'      => [$email],
    'subject' => 'Recebemos sua mensagem',
    'html'    => layoutEmail(
        'Recebemos sua mensagem',
        '<p style="margin: 0 0 12px 0;">Olá, ' . $nomeSeguro . '!</p>'
        . '<p style="margin: 0;">Recebemos a sua mensagem e vamos responder em breve.</p>',
        'Esta é uma confirmação automática do formulário do site.',
        (string) ($config['logo_url'] ?? '')
    ),
    'text'    => "Olá, {$nome}!\n\nRecebemos a sua mensagem e vamos responder em breve.",
];

// se falhar (ex.: 403 do modo de teste), a falha já fica no envios.log e seguimos
enviarResend($config, $confirmacao);

// ---------- 4. Página de sucesso ----------

paginaResultado(true, 200, 'Mensagem enviada!', "Obrigado pelo contato, {$nome}. Responderei assim que possível.", $idEnvio);