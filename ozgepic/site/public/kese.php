<?php
$token = $_GET['token'] ?? '';
?>
<!DOCTYPE html>
<html lang="kk">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>СИҚЫРЛЫ БОКС</title>
<style>
  * { margin:0; padding:0; box-sizing:border-box; }

  body {
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: flex-start;
    overflow-x: hidden;
    position: relative;
    background: #0a0604;
  }

  /* ─── MAIN BACKGROUND ─── */
  .bg-main {
    position: fixed;
    inset: 0;
    background-image: url('assets/images/фон_общии_.png');
    background-size: cover;
    background-position: center top;
    background-repeat: no-repeat;
    z-index: 0;
  }

  /* ─── CONTENT WRAPPER ─── */
  #mainScreen {
    display: flex;
    flex-direction: column;
    align-items: center;
    position: relative;
    z-index: 10;
    padding: 20px 16px 30px;
    width: 100%;
    max-width: 480px;
  }

  /* ─── TITLE IMAGE ─── */
  .title-img {
    width: 88%;
    max-width: 370px;
    display: block;
    margin: 12px auto 8px;
    filter: drop-shadow(0 4px 18px rgba(0,0,0,0.65));
  }

  /* ─── DESCRIPTION BLOCK ─── */
  .desc-block {
    position: relative;
    width: 92%;
    max-width: 400px;
    margin: 0 auto 4px;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .desc-block .desc-text-img {
    width: 100%;
    display: block;
    border-radius: 12px;
    padding-bottom: 40px;
  }

  /* ─── SESSION BADGE ─── */
  .session-badge {
    display: none;
  }

  /* ─── BOWL AREA ─── */
  .bowls-container {
    position: relative;
    width: 86%;
    max-width: 360px;
    margin-bottom: 14px;
  }

  .bowls-bg {
    position: absolute;
    inset: 0;
    background-image: url('assets/images/фон_кесе.png');
    background-size: cover;
    background-position: center;
    border-radius: 12px;
    z-index: 0;
    box-shadow: none;
    background-color: transparent;
    transform: scale(1.15);
    transform-origin: center center;
  }

  /* ─── БАНТИК ─── */
  .bantik-wrap-top {
    position: fixed;
    top: 0;
    right: 0;
    width: 400px;
    display: flex;
    justify-content: flex-end;
    pointer-events: none;
    z-index: 5;
  }

  .bantik-wrap-bottom {
    position: fixed;
    bottom: 0;
    left: 0;
    width: 400px;
    display: flex;
    justify-content: flex-start;
    pointer-events: none;
    z-index: 5;
  }

  .bantik {
    max-width: 250px;
    min-width: 90px;
    pointer-events: none;
  }

  .bowls-area {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 2px;
    padding: 6px;
    position: relative;
    z-index: 1;
  }

  .bowl-slot {
    aspect-ratio: 1;
    position: relative;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .bowl-wrapper {
    position: absolute;
    width: 108%;
    height: 108%;
    cursor: default;
    transition: opacity 0.3s;
    will-change: transform;
  }

  .bowl-inner {
    position: relative;
    width: 100%;
    height: 100%;
    transition: filter 0.3s, transform 0.3s;
    filter: drop-shadow(0 4px 12px rgba(100,60,0,0.45));
    overflow: visible;
  }

  .bowl-img {
    width: 100%;
    height: 100%;
    border-radius: 0;
    position: absolute;
    top: 0; left: 0;
    transition: opacity 0.4s;
    object-fit: contain;
  }

  .open-img   { opacity: 1; z-index: 1; }
  .closed-img { opacity: 0; z-index: 1; width: 90%; height: 90%; top: 5%; left: 5%; }

  .prize-inside {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    pointer-events: none;
    transition: opacity 0.4s;
    opacity: 1;
    z-index: 2;
    padding: 8%;
  }

  .prize-inside img.prize-photo {
    width: 70%;
    height: 70%;
    object-fit: contain;
/*     border-radius: 50%; */
/*     border: 2px solid #d4a017; */
/*     box-shadow: 0 0 14px rgba(212,160,23,0.9); */
            padding-top: 27px;
  }

  .prize-inside .prize-emoji {
    font-size: 2rem;
    filter: drop-shadow(0 0 6px gold);
  }

  .bowl-wrapper.closed .prize-inside { opacity: 0; }

  /* ─── BUTTON ─── */
  .btn-wrap {
    width: 92%;
    max-width: 380px;
    cursor: pointer;
    margin-bottom: 6px;
    transition: transform 0.15s, filter 0.15s;
  }

  .btn-wrap img { width: 100%; display: block; }
  .btn-wrap.disabled { opacity: 0.4; pointer-events: none; }
  .btn-wrap:not(.disabled):hover  { filter: brightness(1.07); transform: translateY(-2px); }
  .btn-wrap:not(.disabled):active { transform: translateY(0); filter: brightness(0.97); }

  /* ─── MESSAGE ─── */
  .message {
    margin-top: 6px;
    font-family: 'Helvetica Neue', Arial, sans-serif;
    font-size: 0.85rem;
    color: rgba(255,235,150,0.9);
    letter-spacing: 1px;
    text-align: center;
    min-height: 22px;
    text-shadow: 0 1px 4px rgba(0,0,0,0.8);
  }

  /* ─── BOWL STATES ─── */
  @keyframes glow-pulse {
    0%,100% { filter: drop-shadow(0 0 8px rgba(212,160,23,0.5)) brightness(1); }
    50%      { filter: drop-shadow(0 0 22px rgba(212,160,23,0.9)) brightness(1.18); }
  }
  .hoverable { cursor: pointer !important; }
  .hoverable .bowl-inner { animation: glow-pulse 1.4s ease-in-out infinite; }
  .not-selected { opacity: 0.32 !important; pointer-events: none !important; }
  .selected .bowl-inner {
    filter: drop-shadow(0 0 35px gold) brightness(1.5) !important;
    transform: scale(1.12) !important;
    animation: none !important;
  }

  /* ═══════════════════════════════════════
     ─── WINNER OVERLAY ───
  ═══════════════════════════════════════ */
  .winner-overlay {
    position: fixed; inset: 0;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    background: rgba(0,0,0,0.88);
    z-index: 500;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.5s;
    padding: 24px 20px;
    gap: 14px;
  }
  .winner-overlay.show { opacity: 1; pointer-events: all; }

  .winner-overlay::before {
    content: '✦  ✦  ✦  ✦  ✦';
    position: absolute;
    top: 12%;
    font-size: 0.9rem;
    letter-spacing: 18px;
    color: rgba(212,160,23,0.25);
    pointer-events: none;
  }
  .winner-overlay::after {
    content: '✦  ✦  ✦  ✦  ✦';
    position: absolute;
    bottom: 12%;
    font-size: 0.9rem;
    letter-spacing: 18px;
    color: rgba(212,160,23,0.25);
    pointer-events: none;
  }

  .congrats-card {
    width: 92%;
    max-width: 400px;
    background: linear-gradient(160deg, #fef9e8 0%, #f8e8a0 50%, #f2d870 100%);
    border-radius: 22px;
    border: 2px solid rgba(212,160,23,0.65);
    box-shadow:
      0 0 0 1px rgba(255,220,80,0.25),
      0 10px 50px rgba(0,0,0,0.55),
      inset 0 1px 0 rgba(255,255,255,0.75);
    padding: 30px 28px 26px;
    text-align: center;
    position: relative;
    overflow: hidden;
    opacity: 0;
    transform: translateY(24px) scale(0.95);
    transition: opacity 0.5s 0.05s, transform 0.55s cubic-bezier(0.175,0.885,0.32,1.275) 0.05s;
  }
  .winner-overlay.show .congrats-card {
    opacity: 1;
    transform: translateY(0) scale(1);
  }

  .congrats-card::before {
    content: '';
    position: absolute;
    top: 0; left: 8%; right: 8%;
    height: 3px;
    background: linear-gradient(90deg, transparent, #c8920a, #ffe44d, #c8920a, transparent);
    border-radius: 0 0 4px 4px;
  }
  .congrats-card::after {
    content: '❧ ✦ ❦';
    position: absolute;
    bottom: 10px; left: 0; right: 0;
    text-align: center;
    font-size: 0.75rem;
    color: rgba(160,100,10,0.4);
    letter-spacing: 6px;
  }

  .congrats-badge {
    display: inline-block;
    font-family: 'Helvetica Neue', Arial, sans-serif;
    font-size: 0.6rem;
    letter-spacing: 3.5px;
    color: rgba(140,85,10,0.75);
    border: 1px solid rgba(180,120,20,0.4);
    border-radius: 20px;
    padding: 3px 14px;
    margin-bottom: 14px;
    text-transform: uppercase;
  }

  .congrats-title {
    font-family: 'Georgia', 'Times New Roman', serif;
    font-size: clamp(1.9rem, 7.5vw, 2.6rem);
    font-weight: 700;
    color: #2c1200;
    letter-spacing: -0.5px;
    text-shadow: 0 1px 0 rgba(255,255,255,0.6), 0 2px 6px rgba(180,100,0,0.2);
    margin-bottom: 12px;
    line-height: 1.1;
  }

  .congrats-divider {
    width: 60%;
    margin: 0 auto 14px;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(180,120,10,0.5), transparent);
  }

  .congrats-subtitle {
    font-family: 'Helvetica Neue', Arial, sans-serif;
    font-size: clamp(0.88rem, 3.5vw, 1.05rem);
    color: rgba(70,35,5,0.82);
    line-height: 1.6;
    padding-bottom: 18px;
  }
  .congrats-subtitle strong {
    color: #2c1200;
    font-weight: 700;
  }

  .prize-card {
    width: 92%;
    max-width: 400px;
    background: linear-gradient(145deg, #fdf5d8, #f6e4a0, #efd882);
    border-radius: 18px;
    border: 2px solid rgba(212,160,23,0.6);
    box-shadow:
      0 6px 32px rgba(0,0,0,0.45),
      inset 0 1px 0 rgba(255,255,255,0.65);
    padding: 16px 18px;
    display: flex;
    align-items: center;
    gap: 16px;
    position: relative;
    overflow: hidden;
    opacity: 0;
    transform: translateY(24px) scale(0.95);
    transition: opacity 0.5s 0.15s, transform 0.55s cubic-bezier(0.175,0.885,0.32,1.275) 0.15s;
  }
  .winner-overlay.show .prize-card {
    opacity: 1;
    transform: translateY(0) scale(1);
  }

  .prize-card::before {
    content: '';
    position: absolute;
    top: 0; left: 6%; right: 6%;
    height: 2px;
    background: linear-gradient(90deg, transparent, #d4a017, transparent);
  }

  .pstar {
    position: absolute;
    color: rgba(180,130,10,0.35);
    font-size: 0.7rem;
    line-height: 1;
  }
  .pstar.tl { top: 7px;  left: 10px; }
  .pstar.tr { top: 7px;  right: 10px; }
  .pstar.bl { bottom: 7px; left: 10px; }
  .pstar.br { bottom: 7px; right: 10px; }

  .prize-media {
    width: 74px;
    height: 74px;
    border-radius: 14px;
    overflow: hidden;
    flex-shrink: 0;
    border: 2px solid rgba(200,140,10,0.55);
    box-shadow: 0 4px 14px rgba(0,0,0,0.22);
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(212,160,23,0.12);
  }
  .prize-media img {
    width: 100%; height: 100%; object-fit: cover; display: none;
  }
  .prize-media .pm-emoji {
    font-size: 2.5rem; line-height: 1;
  }

  .prize-info {
    flex: 1;
    min-width: 0;
  }
  .prize-label {
    font-family: 'Helvetica Neue', Arial, sans-serif;
    font-size: 0.6rem;
    letter-spacing: 2.5px;
    color: rgba(130,80,10,0.75);
    text-transform: uppercase;
    margin-bottom: 5px;
  }
  .prize-name {
    font-family: 'Georgia', 'Times New Roman', serif;
    font-size: clamp(1rem, 4.5vw, 1.35rem);
    font-weight: 700;
    color: #2c1200;
    line-height: 1.25;
    word-break: break-word;
  }

  /* ─── ANIMATIONS ─── */
  @keyframes bounce { from{transform:translateY(0)} to{transform:translateY(-6px)} }

  /* ─── PARTICLES ─── */
  .particle {
    position: fixed; border-radius: 50%; pointer-events: none; z-index: 600;
    animation: pfx 2s ease-out forwards;
  }
  @keyframes pfx {
    0%   { transform: translate(0,0) rotate(0deg); opacity: 1; }
    100% { transform: translate(var(--dx),var(--dy)) rotate(720deg); opacity: 0; }
  }

  /* ─── LOADING / ERROR ─── */
  #loadingScreen {
    position: fixed; inset: 0; z-index: 900;
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    background: rgba(10,6,4,0.97);
  }
  .spinner {
    width: 48px; height: 48px;
    border: 4px solid rgba(212,160,23,0.2); border-top-color: #d4a017;
    border-radius: 50%; animation: spin 0.9s linear infinite; margin-bottom: 16px;
  }
  @keyframes spin { to{ transform: rotate(360deg) } }
  .load-text { font-family: Arial, sans-serif; color: #d4a017; letter-spacing: 3px; font-size: 0.85rem; }

  #errorScreen {
    position: fixed; inset: 0; z-index: 900;
    display: none; flex-direction: column; align-items: center; justify-content: center;
    background: rgba(10,6,4,0.97); padding: 30px; text-align: center;
  }
  .err-icon  { font-size: 4rem; margin-bottom: 16px; }
  .err-title { font-family: Arial, sans-serif; color: #d4a017; font-size: 1.3rem; letter-spacing: 3px; margin-bottom: 12px; }
  .err-msg   { color: rgba(212,160,23,0.6); font-size: 1rem; line-height: 1.6; font-family: Arial, sans-serif; }
</style>
</head>
<body>

<div class="bg-main"></div>
<div class="bantik-wrap-top">
  <img class="bantik" src="assets/images/бантик.png" alt="">
</div>
<div class="bantik-wrap-bottom">
  <img class="bantik" src="assets/images/бантик2.png" alt="">
</div>

<div id="loadingScreen">
  <div class="spinner"></div>
  <div class="load-text">ЖҮКТЕЛУДЕ...</div>
</div>

<div id="errorScreen">
  <div class="err-icon">⚠️</div>
  <div class="err-title">ҚАТЕ</div>
  <div class="err-msg" id="errMsg">Сілтеме жарамсыз немесе мүмкіндік бітті.</div>
</div>

<div id="mainScreen" style="display:none;">

  <img class="title-img" src="assets/images/Алтын_кесе.png" alt="СИҚЫРЛЫ БОКС">

  <!-- ─── DESCRIPTION BLOCK: новое изображение вместо текста ─── -->
  <div class="desc-block">
    <img class="desc-text-img" src="assets/images/описание_новое.png" alt="Описание">
  </div>

  <div class="session-badge" id="sessionBadge">СЕССИЯ · ЖҮКТЕЛУДЕ</div>

  <div class="bowls-container">
    <div class="bowls-bg"></div>
    <div class="bowls-area" id="bowlsArea"></div>
  </div>

  <div class="message" id="message"></div>

  <div class="btn-wrap" id="btnStartWrap" onclick="startGame()">
    <img src="assets/images/кнопка.png" alt="Бастау">
  </div>

</div>

<!-- ═══ WINNER OVERLAY ═══ -->
<div class="winner-overlay" id="winnerOverlay">

  <div class="congrats-card">
    <div class="congrats-badge">СИҚЫРЛЫ БОКС</div>
    <div class="congrats-title">Құттықтаймын!</div>
    <div class="congrats-divider"></div>
    <div class="congrats-subtitle">
      Мынау сіздің <strong>СИҚЫРЛЫ БОКСтан</strong><br>ұтқан ұтысыңыз:
    </div>
  </div>

  <div class="prize-card">
    <span class="pstar tl">✦</span>
    <span class="pstar tr">✦</span>
    <span class="pstar bl">✦</span>
    <span class="pstar br">✦</span>

    <div class="prize-media">
      <img id="winnerImg" src="" alt="">
      <span id="winnerEmoji" class="pm-emoji">🎁</span>
    </div>

    <div class="prize-info">
      <div class="prize-label">Сіздің ұтысыңыз</div>
      <div class="prize-name" id="winnerPrize">Сыйлық!</div>
    </div>
  </div>

</div>

<script>
const TOKEN   = <?php echo json_encode($token); ?>;
const API_URL = 'api/public.php';
const OPEN_SRC   = "assets/images/open_bowl.png";
const CLOSED_SRC = "assets/images/closed_bowl.png";

let sessionNum   = 1;
let prizes       = [];
let prizeOrder   = [];
let bowlWrappers = [];
let gameState    = 'loading';

// ─── INIT ─────────────────────────────────────────────────────────────────
window.addEventListener('DOMContentLoaded', async () => {
  if (!TOKEN) { showError('Сілтеме жарамсыз: token табылмады.'); return; }
  try {
    const res  = await fetch(API_URL, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'check_token',token:TOKEN})});
    const data = await res.json();
    if (!data.success) { showError(data.message || 'Сілтеме жарамсыз.'); return; }
    sessionNum = data.session_num || 1;
    await loadPrizes();
  } catch(e) { showError('Серверге қосылу мүмкін болмады.'); }
});

async function loadPrizes() {
  const res  = await fetch(API_URL, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'get_session_prizes',session:sessionNum})});
  const data = await res.json();
  if (!data.success || !data.prizes.length) { showError('Жүлделер табылмады.'); return; }

  prizes = data.prizes;
  while (prizes.length < 9) prizes.push({id:null, name:'—', emoji:'🎁', img_data:null});

  document.getElementById('sessionBadge').textContent = `----------`;
  document.getElementById('loadingScreen').style.display = 'none';
  document.getElementById('mainScreen').style.display    = 'flex';
  gameState = 'idle';
  createBowls();
}

function showError(msg) {
  document.getElementById('loadingScreen').style.display = 'none';
  document.getElementById('errMsg').textContent = msg;
  document.getElementById('errorScreen').style.display  = 'flex';
}

// ─── BOWL CREATION ─────────────────────────────────────────────────────────
function createBowls() {
  const area = document.getElementById('bowlsArea');
  area.innerHTML = '';
  bowlWrappers = [];
  prizeOrder = [0,1,2,3,4,5,6,7,8];
  shuffleArr(prizeOrder);

  for (let i = 0; i < 9; i++) {
    const prize = prizes[prizeOrder[i]];
    const slot  = document.createElement('div');
    slot.className = 'bowl-slot';
    slot.id = `slot-${i}`;

    const w = document.createElement('div');
    w.className = 'bowl-wrapper';
    w.id = `bowl-${i}`;

    let prizeHTML = '';
    if (prize && prize.img_data) {
      prizeHTML = `<img class="prize-photo" src="${prize.img_data}" alt="${prize.name}">`;
    } else if (prize) {
      prizeHTML = `<span class="prize-emoji">${prize.emoji || '🎁'}</span>`;
    }

    w.innerHTML = `
      <div class="bowl-inner">
        <img class="bowl-img open-img"   src="${OPEN_SRC}"   alt="open">
        <img class="bowl-img closed-img" src="${CLOSED_SRC}" alt="closed">
        <div class="prize-inside">${prizeHTML}</div>
      </div>`;

    w.addEventListener('click', () => {
      const idx = bowlWrappers.indexOf(w);
      if (idx !== -1) pickBowl(idx);
    });

    slot.appendChild(w);
    area.appendChild(slot);
    bowlWrappers.push(w);
  }
}

// ─── GAME START ────────────────────────────────────────────────────────────
async function startGame() {
  if (gameState !== 'idle') return;
  gameState = 'shuffling';
  document.getElementById('btnStartWrap').classList.add('disabled');

  setMsg('бокстар жабылуда...');
  for (let i = 0; i < 9; i++) { await sleep(110 * i); closeVisual(bowlWrappers[i]); }
  await sleep(900);

  setMsg('бокстар жиналуда...');
  const ctr = getSlotCenter(4);
  for (let i = 0; i < 9; i++) {
    bowlWrappers[i].style.zIndex = String(10 + i);
    flyTo(bowlWrappers[i], ctr.x, ctr.y, 450, 'ease-in');
    bowlWrappers[i].style.transition += ', opacity 0.45s 0.2s';
    await sleep(55);
  }
  await sleep(700);

  for (let i = 0; i < 9; i++) bowlWrappers[i].style.opacity = i === 4 ? '1' : '0';
  const sInner = bowlWrappers[4].querySelector('.bowl-inner');
  sInner.style.transition = 'filter 0.2s,transform 0.2s';
  sInner.style.filter = 'drop-shadow(0 0 40px gold) brightness(1.8)';
  sInner.style.transform = 'scale(1.3)';
  await sleep(350);
  sInner.style.filter = ''; sInner.style.transform = '';
  await sleep(250);

  for (let i = 0; i < 9; i++) {
    teleportTo(bowlWrappers[i], ctr.x, ctr.y);
    bowlWrappers[i].style.zIndex = '5';
    const inn = bowlWrappers[i].querySelector('.bowl-inner');
    inn.style.transition = 'none'; inn.style.filter = ''; inn.style.transform = '';
  }
  await sleep(30);
  for (let i = 0; i < 9; i++) {
    bowlWrappers[i].style.opacity = '0';
    bowlWrappers[i].style.transition = `transform 0.5s cubic-bezier(0.175,0.885,0.32,1.275) ${i*70}ms,opacity 0.3s ${i*70}ms`;
    bowlWrappers[i].style.transform = '';
    bowlWrappers[i].style.opacity   = '1';
    bowlWrappers[i].style.zIndex    = '';
  }
  await sleep(800);

  setMsg('Айналдырып корейк...');
  for (let r = 0; r < 12; r++) { await swapTwo(); await sleep(110 + r * 12); }

  await sleep(300);
  setMsg('Бір бокс таңдаңыз!');
  gameState = 'pick';
  bowlWrappers.forEach(w => w.classList.add('hoverable'));
  document.getElementById('btnStartWrap').style.visibility = 'hidden';
}

function closeVisual(wrapper) {
  const o = wrapper.querySelector('.open-img');
  const c = wrapper.querySelector('.closed-img');
  o.style.transition = c.style.transition = 'opacity 0.35s';
  o.style.opacity = '0'; c.style.opacity = '1';
  wrapper.classList.add('closed');
}

// ─── PICK BOWL ─────────────────────────────────────────────────────────────
async function pickBowl(idx) {
  if (gameState !== 'pick') return;
  gameState = 'revealing';

  bowlWrappers.forEach((w, i) => {
    w.classList.remove('hoverable');
    if (i === idx) w.classList.add('selected');
    else           w.classList.add('not-selected');
  });

  try {
    const res  = await fetch(API_URL, {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'reveal',token:TOKEN})});
    const data = await res.json();
    if (!data.success) { setMsg(data.message || 'Қате орын алды.'); return; }

    const w = bowlWrappers[idx];
    const o = w.querySelector('.open-img');
    const c = w.querySelector('.closed-img');
    const p = w.querySelector('.prize-inside');

    // Сначала подставляем правильный приз (миска ещё закрыта — подмены не видно)
    if (data.prize_img) {
      p.innerHTML = `<img class="prize-photo" src="${data.prize_img}" alt="prize">`;
    } else {
      p.innerHTML = `<span class="prize-emoji">${data.prize_emoji || '🎁'}</span>`;
    }

    // Только потом открываем миску
    o.style.transition = c.style.transition = 'opacity 0.4s';
    c.style.opacity = '0'; o.style.opacity = '1';
    w.classList.remove('closed');
    p.style.opacity = '1';

    spawnParticles();
    await sleep(700);

    const wi = document.getElementById('winnerImg');
    const we = document.getElementById('winnerEmoji');
    const wn = document.getElementById('winnerPrize');

    if (data.prize_img) {
      wi.src = data.prize_img;
      wi.style.display = 'block';
      we.style.display = 'none';
    } else {
      wi.style.display = 'none';
      we.style.display = 'inline-block';
      we.textContent = data.prize_emoji || '🎁';
    }
    wn.textContent = data.prize_name || 'Сыйлық!';

    const media = document.querySelector('.prize-media');
    if (media) media.style.animation = 'bounce 0.65s ease infinite alternate';

    document.getElementById('winnerOverlay').classList.add('show');
    gameState = 'done';

  } catch(e) { setMsg('Серверге қосылу қатесі. Қайталаңыз.'); }
}

// ─── ANIMATION HELPERS ──────────────────────────────────────────────────────
function getBowlCenter(w) {
  const r = w.getBoundingClientRect();
  return { x: r.left + r.width/2, y: r.top + r.height/2 };
}
function getSlotCenter(idx) {
  const s = document.getElementById(`slot-${idx}`);
  const r = s.getBoundingClientRect();
  return { x: r.left + r.width/2, y: r.top + r.height/2 };
}
function flyTo(w, tx, ty, dur, ease='ease-in-out') {
  const c = getBowlCenter(w);
  w.style.transition = `transform ${dur}ms ${ease}`;
  w.style.transform  = `translate(${tx-c.x}px,${ty-c.y}px)`;
}
function teleportTo(w, tx, ty) {
  const s = w.parentNode; const r = s.getBoundingClientRect();
  w.style.transition = 'none';
  w.style.transform  = `translate(${tx-(r.left+r.width/2)}px,${ty-(r.top+r.height/2)}px)`;
}
async function swapTwo() {
  const i1 = Math.floor(Math.random()*9);
  let i2 = Math.floor(Math.random()*9);
  while (i2===i1) i2 = Math.floor(Math.random()*9);
  const w1=bowlWrappers[i1], w2=bowlWrappers[i2];
  const c1=getBowlCenter(w1), c2=getBowlCenter(w2);
  w1.style.zIndex='30';
  flyTo(w1,c2.x,c2.y,280); flyTo(w2,c1.x,c1.y,280);
  await sleep(300);
  w1.style.transition=w2.style.transition='none';
  w1.style.transform=w2.style.transform='';
  w1.style.zIndex='';
  const s1=w1.parentNode, s2=w2.parentNode;
  s1.appendChild(w2); s2.appendChild(w1);
  [bowlWrappers[i1],bowlWrappers[i2]]=[bowlWrappers[i2],bowlWrappers[i1]];
  [prizeOrder[i1],prizeOrder[i2]]=[prizeOrder[i2],prizeOrder[i1]];
}

function spawnParticles() {
  const colors = ['#d4a017','#f0c040','#f0e0a0','#fff8dc','#ffe066'];
  for (let i=0;i<60;i++) {
    const p=document.createElement('div'); p.className='particle';
    p.style.left=(20+Math.random()*60)+'vw';
    p.style.top=(15+Math.random()*50)+'vh';
    p.style.setProperty('--dx',(Math.random()*340-170)+'px');
    p.style.setProperty('--dy',(Math.random()*260-60)+'px');
    p.style.background=colors[Math.floor(Math.random()*colors.length)];
    const sz=4+Math.random()*9; p.style.width=p.style.height=sz+'px';
    p.style.animationDelay=(Math.random()*0.5)+'s';
    document.body.appendChild(p); setTimeout(()=>p.remove(),2700);
  }
}

function setMsg(t) { document.getElementById('message').textContent = t; }
function sleep(ms)  { return new Promise(r=>setTimeout(r,ms)); }
function shuffleArr(a) { for(let i=a.length-1;i>0;i--){const j=Math.floor(Math.random()*(i+1));[a[i],a[j]]=[a[j],a[i]];} }
</script>
</body>
</html>