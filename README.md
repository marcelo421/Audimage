AUDIMAGE - DOCUMENTAÇÃO DE INSTALAÇÃO E EXECUÇÃO
================================================

1) O QUE É ESTE PROJETO
-----------------------
O AUDIMAGE é uma aplicação web em PHP + JavaScript para visualização interativa de áudio com autenticação de usuários.
A interface principal fica em index.html e o fluxo de autenticação usa os arquivos da pasta api.

2) REQUISITOS
------------
Para executar o projeto corretamente, você precisa de:
- PHP 8.0+ com suporte a PDO MySQL
- MySQL ou MariaDB em execução
- Redis em execução para rate limiting (recomendado; há fallback em arquivo se indisponível, ver seção 8)
- um servidor web local, como XAMPP, WAMP, Laragon ou o próprio PHP built-in

3) STATUS ATUAL DA CONFIGURAÇÃO
--------------------------------
Os arquivos PHP foram validados com sucesso quanto à sintaxe (php -l) e cobertos por testes automatizados
(ver pasta tests/, executável via `composer test`).

4) COMO RODAR NO XAMPP (RECOMENDADO)
-------------------------------------
Passo 1 - Instale e inicie o XAMPP
- Abra o XAMPP Control Panel
- Inicie Apache e MySQL

Passo 2 - Coloque o projeto na pasta htdocs
Copie a pasta do projeto para:
- C:\xampp\htdocs\audimage

Passo 3 - Crie o banco MySQL e rode as migrations
Abra o phpMyAdmin ou o terminal do MySQL e execute:

CREATE DATABASE audimage CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

Depois rode as migrations (cria tabelas users, presets, etc.):

composer migrate

Passo 4 - Configure as credenciais via variáveis de ambiente
Crie um arquivo .env na raiz do projeto (não commitado — veja .gitignore) com as
variáveis abaixo. Para envio real de e-mails, o projeto usa Resend por padrão porque é
mais simples de configurar do que SMTP:

```env
DB_HOST=127.0.0.1
DB_NAME=audimage
DB_USER=root
DB_PASS=

GOOGLE_CLIENT_ID=SEU_GOOGLE_CLIENT_ID

MAIL_DRIVER=resend
RESEND_API_KEY=sua_chave_resend
MAIL_FROM=no-reply@audimage.app
MAIL_FROM_NAME=AUDIMAGE
APP_URL=http://localhost/audimage
```

Variáveis importantes:
- DB_HOST, DB_NAME, DB_USER, DB_PASS: conexão com o MySQL
- GOOGLE_CLIENT_ID: obrigatório para login com Google
- MAIL_DRIVER: use `resend` para envio real; `log` apenas para desenvolvimento local
- RESEND_API_KEY: chave da API do Resend
- MAIL_FROM: endereço do remetente, geralmente um domínio verificado no Resend
- MAIL_FROM_NAME: nome exibido no remetente
- APP_URL: URL base do site, usada para montar o link de confirmação de e-mail

Se `MAIL_DRIVER=log`, o e-mail não é enviado de verdade; ele é gravado em um arquivo
temporário do PHP. No Windows/XAMPP isso normalmente fica em:
- %TEMP%\audimage_mail.log
- Exemplo real no ambiente atual: C:\Users\MARCEL~1\AppData\Local\Temp\audimage_mail.log

Para usar Resend:
1. Crie uma conta em https://resend.com
2. Gere uma chave de API
3. Verifique seu domínio (por exemplo: audimage.app ou um subdomínio)
4. Cole a chave em `RESEND_API_KEY`
5. Use um endereço válido em `MAIL_FROM` (por exemplo: no-reply@seu-dominio.com)

Passo 5 - Acesse o projeto no navegador
Use:
- http://localhost/audimage/index.html

5) COMO RODAR SEM XAMPP
-----------------------
Se você não quiser usar XAMPP, pode rodar diretamente com o PHP built-in:

cd C:\caminho\da\pasta\audimage
php -S localhost:8000

Depois abra:
- http://localhost:8000/index.html

6) ESTRUTURA DO BANCO
---------------------
As tabelas são criadas via migrations em migrations/ (rode `composer migrate`).
Principais tabelas: users, presets, migrations. Ver os arquivos .sql em migrations/
para a estrutura exata e atualizada.

7) POSSÍVEIS PROBLEMAS
---------------------
Se o app não abrir corretamente, verifique:
- o MySQL do XAMPP está ativo
- o Redis está em execução (rate limiting funciona em modo degradado sem ele, ver seção 8)
- o banco audimage existe e as migrations foram executadas
- o PHP tem o módulo PDO MySQL habilitado
- o projeto está sendo aberto via localhost e não via file://
- as variáveis de ambiente DB_* estão corretas

8) LOCALIZANDO E TESTANDO EMAILS (MODO LOG)
--------------------------------------------
Quando MAIL_DRIVER=log, todos os emails enviados (verificação de email, reset de senha, etc.) 
são salvos em um arquivo de log em vez de serem enviados de verdade. Isso é útil para testes locais.

Localização do arquivo:
- Windows (XAMPP): C:\Users\SEU_USUARIO\AppData\Local\Temp\audimage_mail.log
- Em variável de ambiente: %TEMP%\audimage_mail.log

Como acessar:

1) Abrir no VS Code:
   Ctrl+K Ctrl+O → Cole o caminho: %TEMP%\audimage_mail.log

2) Via PowerShell - Ver últimos 50 emails:
   Get-Content "$env:TEMP\audimage_mail.log" -Tail 50

3) Via PowerShell - Procurar por email específico:
   Select-String -Path "$env:TEMP\audimage_mail.log" -Pattern "seu@email.com" -Context 5

4) Via PowerShell - Ver todos os emails (apenas remetentes):
   Select-String -Path "$env:TEMP\audimage_mail.log" -Pattern "^To:"

5) Via PowerShell - Procurar por links de reset de senha:
   Select-String -Path "$env:TEMP\audimage_mail.log" -Pattern "reset_token" -Context 3

6) Via PowerShell - Procurar por links de verificação de email:
   Select-String -Path "$env:TEMP\audimage_mail.log" -Pattern "verify-email.php" -Context 3

Formato do arquivo:
- Cada email é separado por ==== DATA HORA ====
- Contém: To (destinatário), Subject (assunto), e o corpo HTML do email
- Links de ação (verificar email, redefinir senha) estão dentro do HTML

Exemplo de uso:
Após se cadastrar com um novo email, execute:
  Select-String -Path "$env:TEMP\audimage_mail.log" -Pattern "novo@email.com" -Context 10
Procure pelo link com token (é a URL que você deve clicar para confirmar o cadastro).

Como limpar o arquivo de log:

1) Via PowerShell - Deletar o arquivo:
   Remove-Item "$env:TEMP\audimage_mail.log" -Force

2) Via PowerShell - Esvaziar o arquivo (manter ele vazio):
   The Da gravidade, a parte que tá mais pra baixo Cresce mais, a planta cresce pra cima, se eu Hormônio, só com muita toxina Na raiz, as células têm o seu crescimento inibido. Ou seja, elas vão crescer pouco. Dessa In a scene, they are growing naturally, naturally.  Clear-Content "$env:TEMP\audimage_mail.log" -Force

3) Via PowerShell - Resetar o arquivo (delete + recriar vazio):
   Remove-Item "$env:TEMP\audimage_mail.log" -Force; "" | Set-Content "$env:TEMP\audimage_mail.log"

4) Manualmente - Abrir em um editor de texto e deletar todo o conteúdo:
   Abra %TEMP%\audimage_mail.log em um editor (Notepad, VS Code, etc.) e limpe tudo

9) RATE LIMITING
-----------------
O RateLimiter usa Redis por padrão. Se o Redis estiver indisponível:
- por padrão, cai em modo degradado com fallback em arquivo (mesmo limite aplicado)
- se RATE_LIMIT_ALLOW_FILE_FALLBACK=false, o serviço nega novas tentativas (fail-closed)
  em vez de permitir requisições ilimitadas.

10) LOGIN COM GOOGLE
------------------
O login com Google usa validação criptográfica local (não depende de endpoints de debug inseguros):

Implementação de Segurança (src/Services/GoogleTokenVerifier.php):
- Valida assinatura RS256 dos ID tokens usando chaves públicas do Google (JWKS)
- Carrega JWKS de https://www.googleapis.com/oauth2/v3/certs com cache local (TTL 1 hora)
- Fallback para cache stale se Google estiver indisponível (alta disponibilidade)
- Verifica issuer (https://accounts.google.com), audience (GOOGLE_CLIENT_ID), expiração, email_verified
- Sem dependências externas: converte JWK para PEM usando DER/ASN.1 puro
- SSL verification ativado em requisições HTTPS
- Rate limiting separado para prevenir abuso

Configuração Necessária:
- Obtenha GOOGLE_CLIENT_ID do Google Cloud Console (https://console.cloud.google.com/)
- Configure-o no .env: GOOGLE_CLIENT_ID=seu_client_id_aqui
- Autorize os domínios locais/produção no Cloud Console (OAuth 2.0 > Authorized redirect URIs)

Se o botão não funcionar:
1. Verifique GOOGLE_CLIENT_ID no .env (não pode estar vazio)
2. Confirme que o domínio está autorizado no Google Cloud Console
3. Verifique console do navegador (F12) por erros de token
4. Verifique os logs: error_log() em php.ini

11) RESUMO
---------
O projeto já está preparado para rodar localmente com PHP + MySQL, especialmente em XAMPP, desde que:
- o MySQL esteja ativo
- o banco audimage exista e as migrations tenham rodado
- as variáveis de ambiente estejam corretas
- o app seja aberto por um servidor local
