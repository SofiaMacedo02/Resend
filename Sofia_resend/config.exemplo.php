<?php
// Copie este arquivo para config.php e preencha com os seus dados.
// O config.php tem a chave da API: NUNCA suba ele para o Git nem mande no zip.

return [
    'api_url'   => 'https://api.resend.com/emails',
    'api_key'   => 're_COLE_SUA_CHAVE_AQUI',

    // Modo de teste: 'Meu Site <onboarding@resend.dev>'
    // Com domínio verificado: 'Meu Site <contato@seudominio.com.br>'
    'remetente' => 'Meu Site <onboarding@resend.dev>',

    // Para onde chegam as mensagens do formulário (o seu e-mail).
    // No modo de teste, precisa ser o e-mail da sua conta no Resend.
    'destino'   => 'voce@exemplo.com',

    // Opcional: URL PÚBLICA (https) de uma imagem para o cabeçalho do e-mail.
    // Deixe vazio para não mostrar logo. localhost não funciona: o Gmail não alcança.
    'logo_url'  => '',
];
