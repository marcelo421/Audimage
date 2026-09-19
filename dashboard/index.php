<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/bootstrap.php';

$sessionUser = $_SESSION['user'] ?? null;
if (!is_array($sessionUser) || ($sessionUser['username'] ?? '') !== 'adm_audimage') {
    http_response_code(empty($sessionUser) ? 401 : 403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"><title>Acesso restrito</title></head><body><h1>Acesso restrito</h1><p>Esta área está disponível somente para o usuário autorizado.</p></body></html>';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
$username = htmlspecialchars((string)$sessionUser['username'], ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | AUDIMAGE</title>
    <style>
        :root { color-scheme: light; font-family: Sora, system-ui, sans-serif; background: #f4f1ed; color: #25211f; }
        body { margin: 0; min-height: 100vh; }
        header { display: flex; align-items: center; justify-content: space-between; padding: 24px 6vw; background: #191614; color: #fff; }
        .brand { letter-spacing: .16em; font-weight: 700; }
        .account { color: #d8c8b7; font-size: .9rem; }
        main { width: min(1080px, 88vw); margin: 56px auto; }
        h1 { margin-bottom: 8px; font-size: clamp(2rem, 5vw, 3.8rem); }
        .intro { color: #665d57; margin-bottom: 36px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 18px; }
        .card { padding: 24px; background: #fff; border: 1px solid #e3ddd7; border-radius: 8px; box-shadow: 0 12px 30px rgba(45, 34, 25, .06); }
        .card strong { display: block; margin-bottom: 10px; font-size: 1.8rem; }
        .card span { color: #756b64; }
        a { color: #8a3f2d; }
        @media (max-width: 600px) { header { padding: 20px 6vw; } main { margin: 36px auto; } }
    </style>
</head>
<body>
    <header>
        <div class="brand">AUDIMAGE</div>
        <div class="account">Usuário: <?= $username ?></div>
    </header>
    <main>
        <h1>Dashboard</h1>
        <p class="intro">Área administrativa autenticada.</p>
        <section class="grid" aria-label="Resumo administrativo">
            <article class="card"><strong>Ativo</strong><span>Status do painel</span></article>
            <article class="card"><strong>Seguro</strong><span>Acesso validado pela sessão</span></article>
            <article class="card"><strong>Admin</strong><span>Perfil autorizado</span></article>
        </section>
        <p><a href="../index.html">Voltar para o AUDIMAGE</a></p>
    </main>
</body>
</html>
