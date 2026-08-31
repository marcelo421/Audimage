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
A conexão é feita por src/Database/Connection.php, que lê as seguintes variáveis
de ambiente (com valores padrão para XAMPP local entre parênteses):
- DB_HOST (127.0.0.1)
- DB_NAME (audimage)
- DB_USER (root)
- DB_PASS ('')
- GOOGLE_CLIENT_ID (obrigatório para login com Google — sem fallback embutido)

Defina-as no seu .env local (nunca commitado — veja .gitignore) ou nas variáveis
de ambiente do seu servidor web.

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

8) RATE LIMITING
-----------------
O RateLimiter usa Redis por padrão. Se o Redis estiver indisponível:
- por padrão, cai em modo degradado com fallback em arquivo (mesmo limite aplicado)
- se RATE_LIMIT_ALLOW_FILE_FALLBACK=false, o serviço nega novas tentativas (fail-closed)
  em vez de permitir requisições ilimitadas.

9) LOGIN COM GOOGLE
------------------
O login com Google valida o ID token localmente contra as chaves públicas (JWKS)
do Google — não depende mais do endpoint de debug tokeninfo.
É necessário configurar a variável de ambiente GOOGLE_CLIENT_ID com o Client ID
do Google Cloud Console; não há mais valor padrão embutido no código.
Se o botão não funcionar, verifique o domínio/host autorizado no Google Cloud Console.

10) RESUMO
---------
O projeto já está preparado para rodar localmente com PHP + MySQL, especialmente em XAMPP, desde que:
- o MySQL esteja ativo
- o banco audimage exista e as migrations tenham rodado
- as variáveis de ambiente estejam corretas
- o app seja aberto por um servidor local
