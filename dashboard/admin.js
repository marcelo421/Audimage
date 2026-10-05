const dash = document.currentScript.parentElement;
const CSRF_TOKEN = document.body.dataset.csrf;
const STATUSES = ['active', 'trialing', 'inactive', 'past_due'];

async function loadUsers() {
    const body = document.getElementById('usersBody');
    try {
        const res = await fetch('../api/admin-users.php', { credentials: 'include' });
        const data = await res.json();
        if (!data.ok) {
            body.innerHTML = '<tr><td colspan="5">' + (data.message || 'Falha ao carregar usuários.') + '</td></tr>';
            return;
        }
        if (!data.users.length) {
            body.innerHTML = '<tr><td colspan="5">Nenhum usuário encontrado.</td></tr>';
            return;
        }
        body.innerHTML = data.users.map(u => rowHtml(u)).join('');
    } catch (e) {
        body.innerHTML = '<tr><td colspan="5">Erro de rede ao carregar usuários.</td></tr>';
    }
}

function rowHtml(u) {
    const isAdmin = u.username === 'adm_audimage';
    const verified = u.email_verified_at ? 'Sim' : 'Não';
    const options = STATUSES.map(s =>
        `<option value="${s}" ${s === u.subscription_status ? 'selected' : ''}>${s}</option>`
    ).join('');

    const control = isAdmin
        ? `<span class="status-badge status-${escapeAttr(u.subscription_status)}">${escapeHtml(u.subscription_status)}</span>`
        : `<select data-id="${u.id}">${options}</select>
           <button class="saveBtn" data-save-id="${u.id}">Salvar</button>
           <span class="status-msg" id="msg-${u.id}"></span>`;

    return `<tr>
        <td>${escapeHtml(u.username)}</td>
        <td>${escapeHtml(u.email)}</td>
        <td>${verified}</td>
        <td colspan="2">${control}</td>
    </tr>`;
}

async function saveStatus(id, btn) {
    const select = document.querySelector(`select[data-id="${id}"]`);
    const msg = document.getElementById('msg-' + id);
    const status = select.value;
    btn.disabled = true;
    msg.textContent = '';
    try {
        const res = await fetch('../api/admin-users.php', {
            method: 'POST',
            credentials: 'include',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({ id, status }),
        });
        const data = await res.json();
        msg.textContent = data.ok ? 'Salvo!' : (data.message || 'Falha ao salvar.');
    } catch (e) {
        msg.textContent = 'Erro de rede.';
    } finally {
        btn.disabled = false;
    }
}

// Delegação de evento: os botões "Salvar" são criados dinamicamente,
// então o listener precisa ficar num elemento estático (o tbody).
document.getElementById('usersBody').addEventListener('click', e => {
    const btn = e.target.closest('.saveBtn');
    if (!btn) return;
    saveStatus(parseInt(btn.dataset.saveId, 10), btn);
});

function escapeHtml(v) {
    return String(v ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
function escapeAttr(v) {
    return String(v ?? '').replace(/[^a-zA-Z0-9_-]/g, '');
}

loadUsers();
