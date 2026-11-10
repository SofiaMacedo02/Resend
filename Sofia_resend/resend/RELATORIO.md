# Relatório de Desenvolvimento — Integração Resend & PHP

## Resumo dos Desafios

* **Desafio 1 (Contato com cópia):** Refatoramos o formulário para omitir o campo de destino e fixar o e-mail recebedor no `config.php`, além de configurar o `reply_to` dinâmico com o endereço do visitante. Implementamos também o envio automático de uma cópia de confirmação para o visitante, tratando eventuais falhas sem interromper a entrega da mensagem principal.
* **Desafio 2 (Registro de envios):** Criamos a função de logging persistente `registrarEnvio()` usando `file_put_contents` com a flag `FILE_APPEND` para salvar a data/hora, o e-mail de destino, o status HTTP e o ID ou mensagem de erro retornada pela API. Bloqueamos o arquivo `envios.log` contra versionamento no `.gitignore` e contra acesso público direto no `.htaccess`.

---

## Maior Desafio Técnico e Diagnóstico

O erro que exigiu mais atenção foi o **`403 [validation_error]`** ao tentar enviar e-mails para destinatários externos utilizando o remetente gratuito de testes (`onboarding@resend.dev`). 

A causa foi identificada ao analisar a estrutura do JSON devolvido pelo servidor no corpo da resposta HTTP (no campo `message`). Descobrimos que o ambiente de testes (*sandbox*) da Resend restringe envios exclusivamente para a mesma caixa de e-mail cadastrada na conta da plataforma. Para contornar a limitação em produção, a solução definitiva exige o registro e a validação dos registros DNS (SPF, DKIM e MX) de um domínio próprio.

---

## Por que o `config.php` não está neste repositório?

O arquivo `config.php` contém dados sensíveis e credenciais de acesso à API (a `api_key` da Resend). 

Se o arquivo fosse incluído no repositório ou no arquivo `.zip`, essas chaves ficariam expostas a *scanners* automáticos na web, o que comprometeria a segurança da conta e permitiria o disparo desautorizado de e-mails por terceiros. Por esse motivo, o `config.php` é mantido apenas no ambiente local e foi adicionado ao arquivo `.gitignore`.