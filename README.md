AUDIMAGE - DOCUMENTAÇÃO DE INSTALAÇÃO E EXECUÇÃO
================================================

1) O QUE É ESTE PROJETO
-----------------------
O AUDIMAGE é uma aplicação web em PHP + JavaScript para visualização interativa de áudio com autenticação de usuários.
A interface principal fica em index.html e o fluxo de autenticação usa os arquivos da pasta api.

2) REQUISITOS
------------
Para executar o projeto corretamente, você precisa de:
- PHP com suporte a PDO MySQL
- MySQL ou MariaDB em execução
- Redis em execução para rate limiting (opcional, mas recomendado)
- um servidor web local, como XAMPP, WAMP, Laragon ou o próprio PHP built-in

3) STATUS ATUAL DA CONFIGURAÇÃO
--------------------------------
Os arquivos PHP foram validados com sucesso quanto à sintaxe.
Comandos verificados:
- php -l api/db.php
- php -l api/login.php
- php -l api/register.php
- php -l api/google-login.php

Resultado: nenhuma sintaxe PHP foi encontrada com erro.

4) COMO RODAR NO XAMPP (RECOMENDADO)
-------------------------------------
Passo 1 - Instale e inicie o XAMPP
- Abra o XAMPP Control Panel
- Inicie Apache e MySQL

Passo 2 - Coloque o projeto na pasta htdocs
Copie a pasta do projeto para:
- C:\xampp\htdocs\audimage

Passo 3 - Crie o banco MySQL
Abra o phpMyAdmin ou o terminal do MySQL e execute:

CREATE DATABASE audimage CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

O projeto tenta criar o banco automaticamente, mas é bom garantir que ele exista.

Passo 4 - Ajuste as credenciais, se necessário
O arquivo de conexão está em:
- api/db.php

No XAMPP, normalmente funciona com:
- host: 127.0.0.1
- usuário: root
- senha: '' (vazia)

Se a sua instalação do XAMPP usa outra senha, altere os valores correspondentes.

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
A tabela principal é users, com esta estrutura:

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

7) POSSÍVEIS PROBLEMAS
---------------------
Se o app não abrir corretamente, verifique:
- o MySQL do XAMPP está ativo
- o Redis está em execução, se configurado para rate limiting
- o banco audimage existe
- o PHP tem o módulo PDO MySQL habilitado
- o projeto está sendo aberto via localhost e não via file://
- as credenciais do banco em api/db.php estão corretas

8) LOGIN COM GOOGLE
------------------
O login com Google depende de configuração externa no Google Cloud Console.
Se o botão não funcionar, o problema pode estar relacionado ao domínio/host autorizado pelo Google.

9) RESUMO
---------
O projeto já está preparado para rodar localmente com PHP + MySQL, especialmente em XAMPP, desde que:
- o MySQL esteja ativo
- o banco audimage exista
- as credenciais do banco estejam corretas
- o app seja aberto por um servidor local
