# Checklist de produção — Rate Limiter / Redis

Pré-requisito para deploy em produção. Sem isso, o rate limiting degrada
silenciosamente sob carga ou falha de infra.

## 1. Confirmar Redis provisionado no ambiente de produção

- [ ] Redis rodando e acessível a partir de **todos** os hosts da aplicação
      (não apenas do host onde os testes foram feitos).
- [ ] Validar conectividade real antes do deploy:
      ```bash
      redis-cli -h $REDIS_HOST -p $REDIS_PORT ping
      # esperado: PONG
      ```
- [ ] Se Redis estiver atrás de firewall/security group, confirmar que a porta
      6379 (ou a configurada) está liberada para os hosts da aplicação —
      não só para a rede de deploy/CI.
- [ ] Se usar Redis gerenciado (ElastiCache, Redis Cloud, etc.), confirmar
      que o plano suporta o volume de conexões esperado (uma conexão por
      request/processo PHP, dependendo do modelo de deploy).

## 2. Setar RATE_LIMIT_ALLOW_FILE_FALLBACK=0 explicitamente

- [ ] `.env` de produção contém `RATE_LIMIT_ALLOW_FILE_FALLBACK=0`
      (ver `.env.production.example`).
- [ ] Confirmar que a variável está de fato chegando ao processo PHP:
      ```bash
      php -r "var_dump(getenv('RATE_LIMIT_ALLOW_FILE_FALLBACK'));"
      # esperado: string(1) "0"
      ```
- [ ] **Não depender do default do código.** O default (fallback em arquivo
      permitido) existe para dev/staging funcionar sem Redis rodando — em
      produção ele deve ser sobrescrito explicitamente, não silenciosamente
      herdado.

## 3. Healthcheck + alerta para queda do Redis

- [ ] Endpoint `GET /api/health.php` disponível e monitorado externamente
      (uptime monitor, load balancer health check, ou probe de
      liveness/readiness do orquestrador).
      - `200` + `{"ok":true,"redis":"up"}` → saudável.
      - `503` + `{"ok":false,"redis":"down"}` → Redis inacessível, **alertar**.
- [ ] Alerta configurado para disparar em qualquer resposta não-200 desse
      endpoint (não só "todas as tentativas falharam" — uma falha intermitente
      já é sinal de degradação).
- [ ] Como segundo sinal (defesa em profundidade, cobre a janela entre polls
      do healthcheck): configurar alerta baseado em log para a string
      `[RATE_LIMITER_FALLBACK_TRIGGERED]` nos logs de aplicação
      (CloudWatch Logs metric filter, Datadog Log Monitor, ou equivalente).
      Essa string é escrita toda vez que uma request bate no fallback —
      com `RATE_LIMIT_ALLOW_FILE_FALLBACK=0`, cada ocorrência corresponde a
      uma requisição de login/registro rejeitada com 503 por causa do Redis,
      o que é diretamente acionável.

## Validação pós-deploy

Simule uma falha de Redis em staging (parar o serviço Redis temporariamente)
e confirme:
1. `GET /api/health.php` retorna `503`.
2. Uma tentativa de login/registro retorna `503` com a mensagem de
   "Serviço temporariamente indisponível" — **não** um login bem-sucedido
   sem limitação.
3. O alerta configurado no passo 3 dispara.
