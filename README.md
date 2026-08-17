AUDIMAGE — DOCUMENTAÇÃO DE INSTALAÇÃO E EXECUÇÃO
================================================

1) O QUE É ESTE PROJETO
-----------------------
O AUDIMAGE é uma aplicação web em PHP + JavaScript para visualização interativa
de áudio com autenticação de usuários (login tradicional + Google OAuth 2.0) e
persistência de presets por conta.

A interface principal fica em `index.html`. O backend usa PHP 8+ com
`declare(strict_types=1)`, autoload PSR-4 (`App\` -> `src/`) e o padrão
Service-Repository com injeção de dependência manual (veja `api/dependencies.php`).

2) ARQUITETURA
--------------
- `api/*.php` — controllers HTTP finos: validam CSRF/método, delegam para um
  Service e traduzem exceções de domínio em respostas JSON. Não contêm regra
  de negócio.
- `src/Services/*` — regra de negócio pura, sem dependência de HTTP. Lançam
  exceções tipadas (`App\Exception\*`) em vez de fazer `exit()`.
- `src/Repository/*` — acesso a dados via PDO com prepared statements.
- `src/Http/*` — utilitários de request/response/CSRF/security headers.
- `src/Exception/*` — hierarquia de exceções de domínio; cada uma sabe seu
  `httpStatus()` correspondente.

Não existe mais um `api/db.php` paralelo à arquitetura OOP — toda conexão
passa por `App\Database\Connection::createFromEnv()`, configurada via
variáveis de ambiente (veja `.env.example`).

3) REQUISITOS
-------------
- PHP 8.1+ com extensões PDO MySQL e OpenSSL
- MySQL ou MariaDB
- Redis em execução (obrigatório em produção — veja seção 7)
- Composer (opcional, apenas para rodar os testes com PHPUnit)

4) COMO RODAR COM DOCKER (RECOMENDADO)
---------------------------------------
O projeto inclui um `docker-compose.yml` com PHP + MySQL + Redis prontos:

    docker compose up -d
    docker compose exec app php migrations/migrate.php

Acesse:

    http://localhost:8000/index.html

5) COMO RODAR SEM DOCKER
--------------------------
Passo 1 — Configure as variáveis de ambiente
Copie `.env.example` para `.env` (ou exporte as variáveis no seu ambiente)
e ajuste `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`, `REDIS_HOST`, `REDIS_PORT`.

Passo 2 — Crie o banco e rode as migrações

    mysql -u root -e "CREATE DATABASE audimage CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
    php migrations/migrate.php

Passo 3 — Suba um servidor local

    php -S localhost:8000

Depois abra:

    http://localhost:8000/index.html

6) ESTRUTURA DO BANCO
----------------------
Tabelas principais (ver `migrations/`):

- `users` — contas de usuário (login local + Google)
- `presets` — presets do visualizador, escopados por `user_id` (substituiu
  o antigo armazenamento em `localStorage`, que não sincronizava entre
  dispositivos e não sustentava a feature "Presets ilimitados" dos planos pagos)
- `migrations` — controle de migrações já aplicadas

7) RATE LIMITING (IMPORTANTE)
-------------------------------
O rate limiter (`App\Services\RateLimiter`) usa Redis por padrão e **falha
fechado**: se o Redis estiver indisponível, as requisições de login/registro
são bloqueadas com erro 500, em vez de silenciosamente perder a limitação.

Para desenvolvimento local sem Redis, é possível habilitar um fallback em
arquivo (com lock), definindo:

    RATE_LIMIT_ALLOW_FILE_FALLBACK=1

Isso **não deve ser usado em produção** — é apenas para não bloquear o
desenvolvimento local quando o Redis não está rodando.

8) LOGIN COM GOOGLE
----------------------
O ID token do Google é validado localmente (`App\Services\GoogleTokenVerifier`),
verificando a assinatura RS256 contra as chaves públicas JWKS do Google, em
vez de depender do endpoint de debug `tokeninfo`. Isso requer a extensão
`openssl` do PHP habilitada.

Se o botão de login não funcionar, verifique se o domínio/host está
autorizado no Google Cloud Console para o `client_id` configurado.

9) TESTES
-----------
O projeto usa PHPUnit. Com o Composer instalado:

    composer install
    composer test

Os testes cobrem CSRF, `AuthService` (com mocks, sem precisar de banco/HTTP)
e o comportamento fail-closed do `RateLimiter`.

10) POSSÍVEIS PROBLEMAS
--------------------------
Se o app não abrir corretamente, verifique:
- o MySQL está ativo e o banco `audimage` existe com as migrações aplicadas
- o Redis está em execução (ou `RATE_LIMIT_ALLOW_FILE_FALLBACK=1` está setado
  apenas em ambiente local)
- o PHP tem os módulos `pdo_mysql` e `openssl` habilitados
- o projeto está sendo aberto via `localhost`, não via `file://`
- as variáveis de ambiente do banco estão corretas
