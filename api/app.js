// ===== PALETTE SYSTEM =====
const paletteDots = document.querySelectorAll('.palette-dot');
paletteDots.forEach(dot => {
  dot.addEventListener('click', () => {
    paletteDots.forEach(d => d.classList.remove('active'));
    dot.classList.add('active');
    const p = dot.dataset.palette;
    if (p === 'default') {
      document.documentElement.removeAttribute('data-palette');
    } else {
      document.documentElement.setAttribute('data-palette', p);
    }
  });
});

// ===== USER STATE =====
let currentUser = null;
const GOOGLE_CLIENT_ID = '428028486316-ek5l780hfk56p8sekojmfbgutiu1gcjt.apps.googleusercontent.com';
let googleInitialized = false;

let csrfToken = null;

async function ensureCsrf() {
  if (csrfToken) return;
  try {
    const res = await fetch('api/csrf.php', { credentials: 'include' });
    const json = await res.json().catch(() => null);
    if (json && json.csrf) csrfToken = json.csrf;
  } catch (e) {
    // ignore — calls will fail if token missing
  }
}

async function ensureCsrfFresh() {
  // Force reload of CSRF token (used after failures)
  csrfToken = null;
  await ensureCsrf();
}

async function apiPost(path, body, method = 'POST') {
  await ensureCsrf();
  const headers = { 'Content-Type': 'application/json' };
  if (csrfToken) headers['X-CSRF-Token'] = csrfToken;

  const response = await fetch(path, {
    method,
    credentials: 'include',
    headers,
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  const data = await response.json().catch(() => null);
  if (!data) {
    throw new Error('Resposta inválida do servidor.');
  }

  // If CSRF token is invalid (403), retry once with a fresh token
  if (response.status === 403 && data.message && data.message.includes('CSRF')) {
    await ensureCsrfFresh();
    const retryHeaders = { 'Content-Type': 'application/json' };
    if (csrfToken) retryHeaders['X-CSRF-Token'] = csrfToken;

    const retryResponse = await fetch(path, {
      method,
      credentials: 'include',
      headers: retryHeaders,
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });

    const retryData = await retryResponse.json().catch(() => null);
    if (!retryData) {
      throw new Error('Resposta inválida do servidor.');
    }
    return { status: retryResponse.status, data: retryData };
  }

  return { status: response.status, data };
}

async function apiGet(path) {
  const response = await fetch(path, { credentials: 'include' });
  const data = await response.json().catch(() => null);
  if (!data) {
    throw new Error('Resposta inválida do servidor.');
  }
  return { status: response.status, data };
}

// ===== AUTH =====
function openAuth(view) {
  const overlay = document.getElementById('authOverlay');
  overlay.classList.add('open');
  switchAuth(view);
}

function closeAuth() {
  document.getElementById('authOverlay').classList.remove('open');
  clearErrors();
}

function switchAuth(view) {
  const views = ['loginView', 'registerView', 'forgotView', 'resetView'];
  views.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.style.display = id === view + 'View' ? 'block' : 'none';
  });
  clearErrors();
}

function clearErrors() {
  const errorIds = ['loginError', 'registerError', 'forgotError', 'resetError'];
  errorIds.forEach(id => {
    const el = document.getElementById(id);
    if (el) el.classList.remove('show');
  });
}

function showError(id, msg) {
  const el = document.getElementById(id);
  if (!el) return;
  el.textContent = msg;
  el.classList.add('show');
}

async function doForgotPassword() {
  const email = document.getElementById('forgotEmail').value.trim();
  if (!email) { showError('forgotError', 'Informe seu email.'); return; }

  try {
    const { data } = await apiPost('api/forgot-password.php', { email });
    if (!data.ok) {
      showError('forgotError', data.message || 'Não foi possível enviar o link.');
      return;
    }

    document.getElementById('forgotEmail').value = '';
    clearErrors();
    showToast('Se o email estiver cadastrado, enviamos o link de redefinição.');
    switchAuth('login');
  } catch (error) {
    showError('forgotError', error.message);
  }
}

async function doResetPassword() {
  const token = new URLSearchParams(window.location.search).get('reset_token') || '';
  const password = document.getElementById('resetPass').value;
  const confirm = document.getElementById('resetPassConfirm').value;

  if (!token) {
    showError('resetError', 'Token de redefinição ausente.');
    return;
  }

  if (!password || !confirm) {
    showError('resetError', 'Preencha a nova senha e a confirmação.');
    return;
  }

  if (password.length < 8 || !/[A-Za-z]/.test(password) || !/\d/.test(password)) {
    showError('resetError', 'A senha precisa ter pelo menos 8 caracteres, incluindo letras e números.');
    return;
  }

  if (password !== confirm) {
    showError('resetError', 'As senhas não conferem.');
    return;
  }

  try {
    const { data } = await apiPost('api/reset-password.php', { token, password });
    if (!data.ok) {
      showError('resetError', data.message || 'Não foi possível redefinir a senha.');
      return;
    }

    document.getElementById('resetPass').value = '';
    document.getElementById('resetPassConfirm').value = '';
    clearErrors();
    showToast(data.message || 'Senha redefinida com sucesso.');
    const url = new URL(window.location.href);
    url.searchParams.delete('reset_token');
    window.history.replaceState({}, '', url);
    switchAuth('login');
  } catch (error) {
    showError('resetError', error.message);
  }
}

async function doLogin() {
  const user = document.getElementById('loginUser').value.trim();
  const pass = document.getElementById('loginPass').value;
  if (!user || !pass) { showError('loginError', 'Preencha todos os campos.'); return; }

  try {
    const { data } = await apiPost('api/login.php', { user, pass });
    if (!data.ok) {
      showError('loginError', data.message || 'Falha ao entrar.');
      return;
    }
    loginAs(data.user);
  } catch (error) {
    showError('loginError', error.message);
  }
}

async function doRegister() {
  const user = document.getElementById('regUser').value.trim();
  const email = document.getElementById('regEmail').value.trim();
  const pass = document.getElementById('regPass').value;

  if (!user || !email || !pass) { showError('registerError', 'Preencha todos os campos.'); return; }
  // Kept in sync with backend AuthService::register (min 8 chars, letters + numbers).
  if (pass.length < 8 || !/[A-Za-z]/.test(pass) || !/\d/.test(pass)) {
    showError('registerError', 'A senha precisa ter pelo menos 8 caracteres, incluindo letras e números.');
    return;
  }
  if (!/\S+@\S+\.\S+/.test(email)) { showError('registerError', 'Email inválido.'); return; }

  try {
    const { data } = await apiPost('api/register.php', { user, email, pass });
    if (!data.ok) {
      showError('registerError', data.message || 'Falha ao cadastrar.');
      return;
    }

    document.getElementById('regUser').value = '';
    document.getElementById('regEmail').value = '';
    document.getElementById('regPass').value = '';
    clearErrors();
    closeAuth();
    showToast('Cadastro realizado! Verifique seu e-mail para ativar a conta.');
  } catch (error) {
    showError('registerError', error.message);
  }
}

function togglePassword(inputId, btnId) {
  const inp = document.getElementById(inputId);
  const btn = document.getElementById(btnId);
  if (!inp) return;
  if (inp.type === 'password') {
    inp.type = 'text';
    if (btn) { btn.textContent = '🙈'; btn.setAttribute('aria-pressed','true'); }
  } else {
    inp.type = 'password';
    if (btn) { btn.textContent = '👁️'; btn.setAttribute('aria-pressed','false'); }
  }
}

function loginGoogle() {
  if (!googleInitialized || !window.google?.accounts?.id) {
    showToast('Aguardando o login Google...');
    return;
  }
  google.accounts.id.prompt();
}

function handleGoogleCredentialResponse(response) {
  if (!response || !response.credential) {
    showToast('Falha ao autenticar com Google.');
    return;
  }
  googleLogin(response.credential);
}

async function googleLogin(credential) {
  try {
    const { data } = await apiPost('api/google-login.php', { credential });
    if (!data.ok) {
      showToast(data.message || 'Falha no login com Google.');
      return;
    }
    loginAs(data.user);
  } catch (error) {
    showToast(error.message || 'Erro no login com Google.');
  }
}

function initGoogleIdentity() {
  if (!window.google?.accounts?.id) {
    return;
  }
  google.accounts.id.initialize({
    client_id: GOOGLE_CLIENT_ID,
    callback: handleGoogleCredentialResponse,
    ux_mode: 'popup',
  });
  googleInitialized = true;
}

function loginAs(user) {
  currentUser = user;
  closeAuth();
  updateUserChip();
  showToast('Bem-vindo, ' + user.username + '!');
  goToApp();
  loadPresetsFromServer();
}

function updateUserChip() {
  if (currentUser) {
    document.getElementById('userAvatarChip').textContent = currentUser.username.charAt(0).toUpperCase();
    document.getElementById('userNameChip').textContent = currentUser.username;
  }
}

async function restoreSession() {
  try {
    const response = await fetch('api/session.php');
    const data = await response.json().catch(() => null);
    if (data && data.ok && data.user) {
      currentUser = data.user;
      updateUserChip();
      loadPresetsFromServer();
    }
  } catch (error) {
    console.warn('Falha ao restaurar sessão:', error);
  }
}

// Close modal on overlay click
document.getElementById('authOverlay').addEventListener('click', function(e) {
  if (e.target === this) closeAuth();
});

// Enter key support
['loginUser','loginPass'].forEach(id => {
  document.getElementById(id).addEventListener('keydown', e => { if (e.key === 'Enter') doLogin(); });
});
['regUser','regEmail','regPass'].forEach(id => {
  document.getElementById(id).addEventListener('keydown', e => { if (e.key === 'Enter') doRegister(); });
});
['forgotEmail'].forEach(id => {
  document.getElementById(id).addEventListener('keydown', e => { if (e.key === 'Enter') doForgotPassword(); });
});
['resetPass','resetPassConfirm'].forEach(id => {
  document.getElementById(id).addEventListener('keydown', e => { if (e.key === 'Enter') doResetPassword(); });
});

const urlParams = new URLSearchParams(window.location.search);
if (urlParams.has('reset_token')) {
  if (document.readyState === 'loading') {
    window.addEventListener('DOMContentLoaded', () => {
      openAuth('reset');
    });
  } else {
    openAuth('reset');
  }
}

// ===== SCREEN NAV =====
function showScreen(id) {
  document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
  document.getElementById(id).classList.add('active');
}

function showLanding() { showScreen('screen-landing'); }
function showPlans() { showScreen('screen-plans'); }

function goToApp() {
  if (!currentUser) { openAuth('login'); return; }
  showScreen('screen-app');
  resizeCanvas();
  startIdleAnimation();
}

async function goToLanding() {
  stopMic();
  if (currentUser) {
    // logout.php now requires POST + a valid CSRF token, so use apiPost
    // instead of a bare fetch (which previously allowed logout CSRF).
    try { await apiPost('api/logout.php', undefined); } catch (e) { /* ignore */ }
  }
  currentUser = null;
  presets = [];
  document.getElementById('userAvatarChip').textContent = 'G';
  document.getElementById('userNameChip').textContent = 'Guest';
  showScreen('screen-landing');
  showToast('Sessão encerrada');
}

// ===== STATE =====
const state = {
  shape: 'barras',
  colorMode: 'gradient',
  intensity: 100,
  theme: 'roxo',
  micActive: false,
  settingsOpen: false,
  libOpen: false,
};

const themes = {
  'roxo':    { bg: '#1a0a2e', bg2: '#120820', accent: '#c084fc', header: '#1e1035', panel: '#2d1654', viz: '#a78bfa' },
  'petróleo':{ bg: '#031c1a', bg2: '#020f0e', accent: '#14b8a6', header: '#051f1d', panel: '#082a28', viz: '#6ee7d4' },
  'verde':   { bg: '#0a1f0d', bg2: '#060f07', accent: '#4ade80', header: '#0d2410', panel: '#133219', viz: '#86efac' },
  'preto':   { bg: '#000000', bg2: '#000000', accent: '#00ff88', header: '#0a0a0a', panel: '#111111', viz: '#00ff88' },
  'cinza':   { bg: '#1a1f26', bg2: '#0f1318', accent: '#9ca3af', header: '#1f2530', panel: '#2a313c', viz: '#d1d5db' },
  'violeta': { bg: '#0f001e', bg2: '#07000e', accent: '#c084fc', header: '#160028', panel: '#1e0035', viz: '#e879f9' },
  'rosa':    { bg: '#1f0010', bg2: '#0f0008', accent: '#fb7185', header: '#280016', panel: '#35001f', viz: '#fda4af' },
  'ouro':    { bg: '#160f00', bg2: '#0a0700', accent: '#f59e0b', header: '#1c1400', panel: '#261c00', viz: '#fcd34d' },
};

// ===== AUDIO =====
let audioCtx, analyser, micStream, animId;
const FFT = 2048;
let freqData, timeData;

// ===== CANVAS =====
const canvas = document.getElementById('vizCanvas');
const ctx = canvas.getContext('2d');

function resizeCanvas() {
  const wrap = canvas.parentElement;
  canvas.width = wrap.clientWidth;
  canvas.height = wrap.clientHeight;
}
window.addEventListener('resize', resizeCanvas);
resizeCanvas();

// ===== MIC =====
async function toggleMic() {
  if (state.micActive) { stopMic(); } else { await startMic(); }
}

async function startMic() {
  try {
    if (!audioCtx) audioCtx = new (window.AudioContext || window.webkitAudioContext)();
    if (audioCtx.state === 'suspended') await audioCtx.resume();
    micStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
    const source = audioCtx.createMediaStreamSource(micStream);
    analyser = audioCtx.createAnalyser();
    analyser.fftSize = FFT;
    analyser.smoothingTimeConstant = 0.82;
    source.connect(analyser);
    freqData = new Uint8Array(analyser.frequencyBinCount);
    timeData = new Uint8Array(analyser.frequencyBinCount);
    state.micActive = true;
    const btn = document.getElementById('micBtn');
    btn.classList.add('active');
    btn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="17" height="17"><line x1="1" y1="1" x2="23" y2="23"/><path d="M9 9v3a3 3 0 0 0 5.12 2.12"/><path d="M15 9.34V5a3 3 0 0 0-5.94-.6"/><path d="M17 16.95A7 7 0 0 1 5 12v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>Desativar`;
    if (animId) cancelAnimationFrame(animId);
    render();
    showToast('Microfone ativado');
  } catch(e) {
    showToast('Erro: ' + e.message);
  }
}

function stopMic() {
  if (micStream) { micStream.getTracks().forEach(t => t.stop()); micStream = null; }
  state.micActive = false;
  const btn = document.getElementById('micBtn');
  btn.classList.remove('active');
  btn.innerHTML = `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="17" height="17"><path d="M12 2a3 3 0 0 1 3 3v7a3 3 0 0 1-6 0V5a3 3 0 0 1 3-3z"/><path d="M19 10v2a7 7 0 0 1-14 0v-2"/><line x1="12" y1="19" x2="12" y2="23"/><line x1="8" y1="23" x2="16" y2="23"/></svg>Ativar microfone`;
  if (animId) cancelAnimationFrame(animId);
  startIdleAnimation();
  showToast('Microfone desativado');
}

// ===== IDLE ANIMATION =====
let idleT = 0;
function startIdleAnimation() {
  function loop() {
    idleT += 0.012;
    const W = canvas.width, H = canvas.height;
    ctx.clearRect(0, 0, W, H);
    drawIdle(W, H, idleT, themes[state.theme]);
    animId = requestAnimationFrame(loop);
  }
  if (animId) cancelAnimationFrame(animId);
  loop();
}

function drawIdle(W, H, t, theme) {
  ctx.clearRect(0, 0, W, H);
  const color = state.colorMode === 'solid' ? theme.viz : null;
  const pulse = (Math.sin(t) * 0.5 + 0.5) * 0.4 + 0.1;

  if (state.shape === 'barras' || state.shape === 'espelho') {
    const count = 64, bw = W / count;
    for (let i = 0; i < count; i++) {
      const h = (Math.sin(i * 0.3 + t * 2) * 0.5 + 0.5) * H * 0.15 * pulse + 4;
      ctx.fillStyle = color ? hexToRgba(color, 0.7) : getNoteColor(i, count, 0.7);
      if (state.shape === 'espelho') {
        ctx.fillRect(i * bw, H / 2 - h, bw - 1, h);
        ctx.fillRect(i * bw, H / 2, bw - 1, h);
      } else {
        ctx.fillRect(i * bw, H - h, bw - 1, h);
      }
    }
  } else if (state.shape === 'onda' || state.shape === 'linha') {
    ctx.beginPath();
    for (let x = 0; x < W; x++) {
      const y = H / 2 + Math.sin(x * 0.015 + t * 3) * H * 0.05 * (Math.sin(t) * 0.3 + 0.7);
      x === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
    }
    ctx.strokeStyle = color ? hexToRgba(color, 0.8) : getNoteColorForFraction(Math.sin(t) * 0.5 + 0.5, 0.8);
    ctx.lineWidth = 2; ctx.stroke();
  } else if (state.shape === 'circulos') {
    const cx = W / 2, cy = H / 2;
    for (let r = 1; r <= 5; r++) {
      const radius = r * (Math.min(W, H) / 14) + Math.sin(t * 2 + r) * 10;
      ctx.beginPath(); ctx.arc(cx, cy, radius, 0, Math.PI * 2);
      const c = color ? hexToRgba(color, 0.15 * (6 - r)) : getNoteColor(r, 5, 0.15 * (6 - r));
      ctx.strokeStyle = c; ctx.lineWidth = 2 + r * 0.5; ctx.stroke();
    }
  } else if (state.shape === 'pontos') {
    const cols = 24, rows = 14;
    const cx = W / (cols + 1), cy = H / (rows + 1);
    for (let r = 0; r < rows; r++) {
      for (let c = 0; c < cols; c++) {
        const d = Math.hypot(c - cols / 2, r - rows / 2) / (cols / 2);
        const size = (Math.sin(t * 2 - d * 4) * 0.5 + 0.5) * 6 + 2;
        ctx.beginPath();
        ctx.arc((c + 1) * cx, (r + 1) * cy, size * pulse * 2 + 1, 0, Math.PI * 2);
        ctx.fillStyle = color ? hexToRgba(color, 0.5 + pulse * 0.3) : getNoteColor(c, cols, 0.5);
        ctx.fill();
      }
    }
  } else if (state.shape === 'radial') {
    const cx = W / 2, cy = H / 2, count = 80;
    for (let i = 0; i < count; i++) {
      const angle = (i / count) * Math.PI * 2;
      const len = (Math.sin(t * 3 + i * 0.2) * 0.5 + 0.5) * 60 * pulse + 20;
      const x1 = cx + Math.cos(angle) * 30, y1 = cy + Math.sin(angle) * 30;
      const x2 = cx + Math.cos(angle) * (30 + len), y2 = cy + Math.sin(angle) * (30 + len);
      ctx.beginPath(); ctx.moveTo(x1, y1); ctx.lineTo(x2, y2);
      ctx.strokeStyle = color ? hexToRgba(color, 0.5) : getNoteColor(i, count, 0.5);
      ctx.lineWidth = 1.5; ctx.stroke();
    }
  } else if (state.shape === 'poligonos') {
    const cx = W / 2, cy = H / 2;
    drawPolygon(cx, cy, Math.min(W, H) * 0.15 * (0.85 + pulse * 0.3), 6, t * 0.5, color || theme.viz, 0.6);
    drawPolygon(cx, cy, Math.min(W, H) * 0.1 * (0.85 + pulse * 0.3), 3, -t * 0.7, color || theme.viz, 0.5);
  }
}

const noteColors = ['#ef4444', '#f59e0b', '#38bdf8', '#b91c1c', '#fb923c', '#22c55e', '#bae6fd'];

function getNoteColor(index, total, alpha) {
  const noteIndex = total > 1 ? Math.floor(index / total * noteColors.length) % noteColors.length : 0;
  return hexToRgba(noteColors[noteIndex], alpha);
}

function getNoteColorForFraction(fraction, alpha) {
  const idx = Math.floor(Math.max(0, Math.min(1, fraction)) * noteColors.length) % noteColors.length;
  return hexToRgba(noteColors[idx], alpha);
}

function drawPolygon(cx, cy, r, sides, rot, color, alpha) {
  ctx.beginPath();
  for (let i = 0; i < sides; i++) {
    const angle = rot + (i / sides) * Math.PI * 2;
    ctx.lineTo(cx + Math.cos(angle) * r, cy + Math.sin(angle) * r);
  }
  ctx.closePath();
  ctx.strokeStyle = hexToRgba(color, alpha);
  ctx.lineWidth = 2; ctx.stroke();
}

// ===== MAIN RENDER =====
function render() {
  if (!state.micActive || !analyser) return;
  analyser.getByteFrequencyData(freqData);
  analyser.getByteTimeDomainData(timeData);
  const W = canvas.width, H = canvas.height;
  const intMult = state.intensity / 100;
  const theme = themes[state.theme];
  const baseColor = state.colorMode === 'solid' ? theme.viz : null;
  ctx.clearRect(0, 0, W, H);
  switch (state.shape) {
    case 'barras':    drawBarras(W, H, baseColor, intMult); break;
    case 'onda':      drawOnda(W, H, baseColor, intMult); break;
    case 'circulos':  drawCirculos(W, H, baseColor, intMult); break;
    case 'espelho':   drawEspelho(W, H, baseColor, intMult); break;
    case 'pontos':    drawPontos(W, H, baseColor, intMult); break;
    case 'radial':    drawRadial(W, H, baseColor, intMult); break;
    case 'poligonos': drawPoligonos(W, H, baseColor, intMult); break;
    case 'linha':     drawLinha(W, H, baseColor, intMult); break;
  }
  animId = requestAnimationFrame(render);
}

function drawBarras(W, H, color, mult) {
  const count = 80, bw = W / count;
  const bins = Math.floor(freqData.length / 2);
  for (let i = 0; i < count; i++) {
    const val = freqData[Math.floor((i / count) * bins)] / 255;
    const h = val * H * mult;
    const c = color ? hexToRgba(color, 0.85) : getNoteColor(i, count, 0.85);
    const grad = ctx.createLinearGradient(0, H, 0, H - h);
    grad.addColorStop(0, c);
    grad.addColorStop(1, color ? hexToRgba(color, 0.3) : getNoteColor(i, count, 0.4));
    ctx.fillStyle = grad;
    ctx.fillRect(i * bw + 1, H - h, bw - 2, h);
    ctx.fillStyle = color ? hexToRgba(color, 1) : getNoteColor(i, count, 1);
    ctx.fillRect(i * bw + 1, H - h - 2, bw - 2, 2);
  }
}

function drawEspelho(W, H, color, mult) {
  const count = 80, bw = W / count, bins = Math.floor(freqData.length / 2), cy = H / 2;
  for (let i = 0; i < count; i++) {
    const val = freqData[Math.floor((i / count) * bins)] / 255;
    const h = val * cy * mult;
    ctx.fillStyle = color ? hexToRgba(color, 0.85) : getNoteColor(i, count, 0.85);
    ctx.fillRect(i * bw + 1, cy - h, bw - 2, h);
    ctx.fillRect(i * bw + 1, cy, bw - 2, h);
  }
}

function drawOnda(W, H, color, mult) {
  const sliceW = W / timeData.length;
  ctx.beginPath();
  for (let i = 0; i < timeData.length; i++) {
    const v = (timeData[i] / 128.0) - 1.0;
    const y = H / 2 + v * H * 0.4 * mult;
    i === 0 ? ctx.moveTo(0, y) : ctx.lineTo(i * sliceW, y);
  }
  ctx.strokeStyle = color ? hexToRgba(color, 0.9) : getNoteColor(0, 1, 0.9);
  ctx.lineWidth = 2.5; ctx.lineJoin = 'round'; ctx.lineCap = 'round'; ctx.stroke();
  ctx.save(); ctx.filter = 'blur(6px)'; ctx.lineWidth = 6; ctx.globalAlpha = 0.2; ctx.stroke(); ctx.restore();
}

function drawLinha(W, H, color, mult) {
  const count = freqData.length / 2;
  ctx.beginPath();
  for (let i = 0; i < count; i++) {
    const x = (i / count) * W;
    const y = H - (freqData[i] / 255) * H * mult;
    i === 0 ? ctx.moveTo(x, y) : ctx.lineTo(x, y);
  }
  ctx.strokeStyle = color ? hexToRgba(color, 0.85) : getNoteColor(0, 1, 0.85);
  ctx.lineWidth = 2; ctx.stroke();
}

function drawCirculos(W, H, color, mult) {
  const cx = W / 2, cy = H / 2, maxR = Math.min(W, H) * 0.45, count = 6;
  for (let ring = 0; ring < count; ring++) {
    let avg = 0;
    const binStart = Math.floor((ring / count) * freqData.length * 0.6);
    for (let b = binStart; b < binStart + 20; b++) avg += freqData[b] || 0;
    avg = (avg / 20) / 255;
    const r = (maxR / count) * (ring + 1) * (0.6 + avg * 0.5 * mult);
    ctx.beginPath(); ctx.arc(cx, cy, r, 0, Math.PI * 2);
    const alpha = 0.7 - ring * 0.08;
    ctx.strokeStyle = color ? hexToRgba(color, alpha) : getNoteColor(ring, count, alpha);
    ctx.lineWidth = 1.5 + avg * 4 * mult; ctx.stroke();
  }
  const centerVal = freqData[1] / 255;
  ctx.beginPath(); ctx.arc(cx, cy, 5 + centerVal * 20 * mult, 0, Math.PI * 2);
  ctx.fillStyle = color ? hexToRgba(color, 0.9) : getNoteColor(0, 1, 0.9); ctx.fill();
}

function drawPontos(W, H, color, mult) {
  const cols = 28, rows = 16, cx = W / (cols + 1), cy = H / (rows + 1);
  for (let r = 0; r < rows; r++) {
    for (let c = 0; c < cols; c++) {
      const binIdx = Math.floor(((r * cols + c) / (rows * cols)) * freqData.length * 0.7);
      const val = (freqData[binIdx] || 0) / 255;
      ctx.beginPath(); ctx.arc((c + 1) * cx, (r + 1) * cy, val * 14 * mult + 2, 0, Math.PI * 2);
      ctx.fillStyle = color ? hexToRgba(color, 0.2 + val * 0.8) : getNoteColor(c, cols, 0.2 + val * 0.8);
      ctx.fill();
    }
  }
}

function drawRadial(W, H, color, mult) {
  const cx = W / 2, cy = H / 2, count = 120, bins = Math.floor(freqData.length * 0.6);
  for (let i = 0; i < count; i++) {
    const angle = (i / count) * Math.PI * 2;
    const val = (freqData[Math.floor((i / count) * bins)] || 0) / 255;
    const len = val * Math.min(W, H) * 0.4 * mult;
    ctx.beginPath(); ctx.moveTo(cx + Math.cos(angle) * 30, cy + Math.sin(angle) * 30);
    ctx.lineTo(cx + Math.cos(angle) * (30 + len), cy + Math.sin(angle) * (30 + len));
    ctx.strokeStyle = color ? hexToRgba(color, 0.6 + val * 0.4) : getNoteColor(i, count, 0.6 + val * 0.4);
    ctx.lineWidth = 1 + val * 2; ctx.stroke();
  }
}

let polyRot = 0;
function drawPoligonos(W, H, color, mult) {
  const cx = W / 2, cy = H / 2, bins = freqData.length;
  let avg = 0;
  for (let i = 0; i < 64; i++) avg += freqData[i];
  avg = avg / 64 / 255;
  polyRot += 0.008 + avg * 0.03;
  const maxR = Math.min(W, H) * 0.4;
  for (let i = 0; i < 80; i++) {
    const angle = (i / 80) * Math.PI * 2;
    const val = (freqData[Math.floor((i / 80) * bins * 0.7)] || 0) / 255;
    const len = val * maxR * 0.7 * mult;
    if (len < 2) continue;
    ctx.beginPath(); ctx.moveTo(cx + Math.cos(angle) * 40, cy + Math.sin(angle) * 40);
    ctx.lineTo(cx + Math.cos(angle) * (40 + len), cy + Math.sin(angle) * (40 + len));
    ctx.strokeStyle = color ? hexToRgba(color, 0.4 + val * 0.4) : getNoteColor(i, bins, 0.4 + val * 0.4);
    ctx.lineWidth = 1 + val * 1.5; ctx.stroke();
  }
  const outerR = (0.15 + avg * 0.1 * mult) * Math.min(W, H);
  const innerR = outerR * (0.6 + avg * 0.2);
  const clr = color || getNoteColor(Math.floor(avg * 7), 7, 1);
  drawPolygonFilled(cx, cy, outerR, 6, polyRot, clr, 0.12);
  drawPolygonStroke(cx, cy, outerR, 6, polyRot, clr, 0.8, 2);
  drawPolygonStroke(cx, cy, innerR, 3, -polyRot * 1.3, clr, 0.9, 2);
  drawPolygonStroke(cx, cy, innerR * 0.7, 3, polyRot * 2, clr, 0.7, 1.5);
  const grd = ctx.createRadialGradient(cx, cy, 0, cx, cy, innerR * 0.5);
  grd.addColorStop(0, hexToRgba(clr, 0.25 + avg * 0.3));
  grd.addColorStop(1, hexToRgba(clr, 0));
  ctx.fillStyle = grd; ctx.beginPath(); ctx.arc(cx, cy, innerR * 0.5, 0, Math.PI * 2); ctx.fill();
}

function drawPolygonFilled(cx, cy, r, sides, rot, color, alpha) {
  ctx.beginPath();
  for (let i = 0; i < sides; i++) {
    const angle = rot + (i / sides) * Math.PI * 2;
    ctx.lineTo(cx + Math.cos(angle) * r, cy + Math.sin(angle) * r);
  }
  ctx.closePath(); ctx.fillStyle = hexToRgba(color, alpha); ctx.fill();
}

function drawPolygonStroke(cx, cy, r, sides, rot, color, alpha, lw) {
  ctx.beginPath();
  for (let i = 0; i < sides; i++) {
    const angle = rot + (i / sides) * Math.PI * 2;
    ctx.lineTo(cx + Math.cos(angle) * r, cy + Math.sin(angle) * r);
  }
  ctx.closePath(); ctx.strokeStyle = hexToRgba(color, alpha); ctx.lineWidth = lw; ctx.stroke();
}

// ===== UI INTERACTIONS =====
function toggleSettings() {
  const panel = document.getElementById('settingsPanel');
  const libPanel = document.getElementById('libPanel');
  state.settingsOpen = !state.settingsOpen;
  if (state.settingsOpen && state.libOpen) {
    state.libOpen = false;
    libPanel.classList.remove('open');
    document.getElementById('btnLib').classList.remove('active');
  }
  panel.classList.toggle('open', state.settingsOpen);
  document.getElementById('btnSettings').classList.toggle('active', state.settingsOpen);
}

function toggleLib() {
  const panel = document.getElementById('libPanel');
  const settingsPanel = document.getElementById('settingsPanel');
  state.libOpen = !state.libOpen;
  if (state.libOpen && state.settingsOpen) {
    state.settingsOpen = false;
    settingsPanel.classList.remove('open');
    document.getElementById('btnSettings').classList.remove('active');
  }
  panel.classList.toggle('open', state.libOpen);
  document.getElementById('btnLib').classList.toggle('active', state.libOpen);
  renderPresets();
}

function toggleFullscreen() {
  if (!document.fullscreenElement) {
    document.documentElement.requestFullscreen();
    document.getElementById('btnFullscreen').classList.add('active');
  } else {
    document.exitFullscreen();
    document.getElementById('btnFullscreen').classList.remove('active');
  }
}

document.getElementById('shapeGrid').addEventListener('click', e => {
  const btn = e.target.closest('.shape-btn');
  if (!btn) return;
  document.querySelectorAll('.shape-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  state.shape = btn.dataset.shape;
});

function setColorMode(mode) {
  state.colorMode = mode;
  document.getElementById('pillGradient').classList.toggle('active', mode === 'gradient');
  document.getElementById('pillSolid').classList.toggle('active', mode === 'solid');
}

function setIntensity(val) {
  state.intensity = parseInt(val);
  document.getElementById('intensityVal').textContent = val + '%';
}

document.getElementById('themeGrid').addEventListener('click', e => {
  const swatch = e.target.closest('.theme-swatch');
  if (!swatch) return;
  document.querySelectorAll('.theme-swatch').forEach(s => s.classList.remove('active'));
  swatch.classList.add('active');
  applyTheme(swatch.dataset.theme);
});

function applyTheme(name) {
  state.theme = name;
  const t = themes[name];
  if (!t) return;
  document.documentElement.style.setProperty('--bg', t.bg);
  document.documentElement.style.setProperty('--bg2', t.bg2);
  document.documentElement.style.setProperty('--accent', t.accent);
}

// ===== PRESETS / LIBRARY =====
// Presets now live server-side (api/presets.php), scoped to the logged-in
// user_id. They are no longer stored in localStorage, which previously let
// any script on the page (or a shared/public machine) read or tamper with
// another session's saved presets (IDOR).
let presets = [];
let presetsLoaded = false;

async function loadPresetsFromServer() {
  if (!currentUser) { presets = []; presetsLoaded = false; return; }
  try {
    const { data } = await apiGet('api/presets.php');
    if (data.ok) {
      presets = data.presets || [];
      presetsLoaded = true;
      if (state.libOpen) renderPresets();
    }
  } catch (e) {
    console.warn('Falha ao carregar presets:', e);
  }
}

async function savePreset() {
  if (!currentUser) { showToast('Entre na sua conta para salvar presets.'); return; }
  const name = prompt('Nome do preset:');
  if (!name) return;

  const payload = {
    name,
    shape: state.shape,
    colorMode: state.colorMode,
    intensity: state.intensity,
    theme: state.theme,
    color: themes[state.theme]?.viz || '#a78bfa',
  };

  try {
    const { data } = await apiPost('api/presets.php', payload);
    if (!data.ok) {
      showToast(data.message || 'Falha ao salvar preset.');
      return;
    }
    await loadPresetsFromServer();
    renderPresets();
    showToast('Preset salvo: ' + name);
  } catch (e) {
    showToast(e.message || 'Falha ao salvar preset.');
  }
}

function loadPreset(id) {
  const p = presets.find(x => x.id === id);
  if (!p) return;
  state.shape = p.shape;
  state.colorMode = p.colorMode;
  state.intensity = p.intensity;
  state.theme = p.theme;
  document.querySelectorAll('.shape-btn').forEach(b => b.classList.toggle('active', b.dataset.shape === p.shape));
  setColorMode(p.colorMode);
  document.getElementById('intensitySlider').value = p.intensity;
  document.getElementById('intensityVal').textContent = p.intensity + '%';
  applyTheme(p.theme);
  document.querySelectorAll('.theme-swatch').forEach(s => s.classList.toggle('active', s.dataset.theme === p.theme));
  showToast('Preset carregado: ' + p.name);
}

async function deletePreset(id) {
  try {
    const { data } = await apiPost('api/presets.php?id=' + encodeURIComponent(id), undefined, 'DELETE');
    if (!data.ok) {
      showToast(data.message || 'Falha ao excluir preset.');
      return;
    }
    await loadPresetsFromServer();
    renderPresets();
  } catch (e) {
    showToast(e.message || 'Falha ao excluir preset.');
  }
}

function renderPresets() {
  const list = document.getElementById('presetList');
  if (!currentUser) {
    list.innerHTML = `<p style="color:var(--text-muted);font-size:13px;text-align:center;margin-top:20px;opacity:0.7">Entre na sua conta para ver seus presets</p>`;
    return;
  }
  if (presets.length === 0) {
    list.innerHTML = `<p style="color:var(--text-muted);font-size:13px;text-align:center;margin-top:20px;opacity:0.7">Nenhum preset salvo ainda</p>`;
    return;
  }
  list.innerHTML = presets.map(p => `
    <div class="preset-item" onclick="loadPreset(${p.id})">
      <div class="preset-dot" style="background:${escapeHtml(p.color)}"></div>
      <div class="preset-info">
        <div class="preset-name">${escapeHtml(p.name)}</div>
        <div class="preset-desc">${escapeHtml(capitalize(p.shape))} · ${escapeHtml(String(p.intensity))}%</div>
      </div>
      <button class="preset-del" onclick="event.stopPropagation();deletePreset(${p.id})" title="Excluir">✕</button>
    </div>
  `).join('');
}

// ===== TOAST =====
let toastTimer;
function showToast(msg) {
  const toast = document.getElementById('toast');
  toast.textContent = msg;
  toast.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toast.classList.remove('show'), 2800);
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

// ===== HELPERS =====
function hexToRgba(hex, alpha) {
  if (!hex || hex[0] !== '#') return `rgba(167,139,250,${alpha})`;
  const r = parseInt(hex.slice(1,3), 16);
  const g = parseInt(hex.slice(3,5), 16);
  const b = parseInt(hex.slice(5,7), 16);
  return `rgba(${r},${g},${b},${alpha})`;
}

function hslToRgba(h, s, l, a) {
  h = h % 1; if (h < 0) h += 1;
  let r, g, b;
  if (s === 0) { r = g = b = l; }
  else {
    const q = l < 0.5 ? l * (1 + s) : l + s - l * s;
    const p = 2 * l - q;
    r = hue2rgb(p, q, h + 1/3); g = hue2rgb(p, q, h); b = hue2rgb(p, q, h - 1/3);
  }
  return `rgba(${Math.round(r*255)},${Math.round(g*255)},${Math.round(b*255)},${a})`;
}

function hue2rgb(p, q, t) {
  if (t < 0) t += 1; if (t > 1) t -= 1;
  if (t < 1/6) return p + (q-p)*6*t;
  if (t < 1/2) return q;
  if (t < 2/3) return p + (q-p)*(2/3-t)*6;
  return p;
}

function capitalize(str) {
  return str ? str.charAt(0).toUpperCase() + str.slice(1) : '';
}

// ===== INIT =====
applyTheme('roxo');
restoreSession();

function ensureGoogleIdentityReady(count = 0) {
  if (window.google?.accounts?.id) {
    initGoogleIdentity();
    return;
  }
  if (count > 20) {
    return;
  }
  setTimeout(() => ensureGoogleIdentityReady(count + 1), 150);
}

ensureGoogleIdentityReady();
renderPresets();
