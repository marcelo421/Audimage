<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/bootstrap.php';

use App\Http\Csrf;

$sessionUser = $_SESSION['user'] ?? null;
if (!is_array($sessionUser) || ($sessionUser['username'] ?? '') !== 'adm_audimage') {
    http_response_code(empty($sessionUser) ? 401 : 403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"><title>Acesso restrito</title></head><body><h1>Acesso restrito</h1><p>Esta área está disponível somente para o usuário autorizado.</p></body></html>';
    exit;
}

header('Content-Type: text/html; charset=UTF-8');
$username = htmlspecialchars((string)$sessionUser['username'], ENT_QUOTES, 'UTF-8');
$csrfToken = htmlspecialchars(Csrf::ensureToken(), ENT_QUOTES, 'UTF-8');
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
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr)); gap: 18px; margin-bottom: 48px; }
        .card { padding: 24px; background: #fff; border: 1px solid #e3ddd7; border-radius: 8px; box-shadow: 0 12px 30px rgba(45, 34, 25, .06); }
        .card strong { display: block; margin-bottom: 10px; font-size: 1.8rem; }
        .card span { color: #756b64; }
        a { color: #8a3f2d; }
        h2 { font-size: 1.4rem; margin-bottom: 16px; }
        table { width: 100%; border-collapse: collapse; background: #fff; border: 1px solid #e3ddd7; border-radius: 8px; overflow: hidden; }
        th, td { padding: 12px 14px; text-align: left; border-bottom: 1px solid #eee3da; font-size: .92rem; }
        th { background: #191614; color: #fff; font-weight: 600; }
        tr:last-child td { border-bottom: none; }
        select { padding: 6px 8px; border-radius: 6px; border: 1px solid #ccc; font-size: .85rem; }
        button.saveBtn { margin-left: 6px; padding: 6px 12px; border: none; border-radius: 6px; background: #8a3f2d; color: #fff; cursor: pointer; font-size: .85rem; }
        button.saveBtn:hover { background: #6f321f; }
        .status-msg { font-size: .8rem; margin-left: 8px; }
        .status-badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: .75rem; font-weight: 600; }
        .status-active, .status-trialing { background: #dcf3df; color: #1e6b2e; }
        .status-inactive, .status-past_due { background: #f7dede; color: #9c2b2b; }
        @media (max-width: 600px) { header { padding: 20px 6vw; } main { margin: 36px auto; } table { font-size: .8rem; } }
    </style>
</head>
<body data-csrf="<?= $csrfToken ?>">
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

        <h2>Usuários</h2>
        <table>
            <thead>
                <tr><th>Usuário</th><th>Email</th><th>Verificado</th><th>Permissão</th><th></th></tr>
            </thead>
            <tbody id="usersBody">
                <tr><td colspan="5">Carregando...</td></tr>
            </tbody>
        </table>

        <p><a href="../index.html">Voltar para o AUDIMAGE</a></p>
    </main>

    <script src="admin.js" defer></script>
</body>
</html>
