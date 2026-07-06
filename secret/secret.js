const playerEl = document.getElementById("player");
const battleBox = document.getElementById("battle-box");
const hpFill = document.getElementById("hp-fill");
const hpText = document.getElementById("hp-text");
const clockEl = document.getElementById("clock");
const phaseTag = document.getElementById("phase-tag");
const dlgText = document.getElementById("dlg-text");
const flashEl = document.getElementById("damage-flash");
const deathScr = document.getElementById("death-screen");
const deathStats = document.getElementById("death-stats");
const bossCanvas = document.getElementById("boss-canvas");
const bCtx = bossCanvas.getContext("2d");
const turnBanner = document.getElementById("turn-banner");
const turnLabel = document.getElementById("turn-label");
const arenaBg = document.getElementById("arena-bg");
const abCtx = arenaBg.getContext("2d");
let blueMode = false;
let vy = 0;
const gravity = 0.45;
const jumpPower = -9.5;
const platforms = [];

// ═══════════════════════════════════════════════════════════════════
//  CONSTANTS
// ═══════════════════════════════════════════════════════════════════
const BW = 600, BH = 440;
const CW = 600, CH = 150;
const PW = 14, PH = 14;
const MAX_HP = 300;
const PHASES = [
  { time: 0, label: "FASE I", color: "#aa44ff", dmg: 5, spd: 1.0 },
  { time: 35, label: "FASE II", color: "#ff7700", dmg: 8, spd: 1.3 },
  { time: 75, label: "FASE III ★", color: "#ff1133", dmg: 11, spd: 1.45 }, // was 1.9 — now fair
];

// ═══════════════════════════════════════════════════════════════════
//  STATE
// ═══════════════════════════════════════════════════════════════════
let hp = MAX_HP;
let px = BW / 2 - 20, py = BH / 2 - 20;  // center of arena
let baseSpd = 6;
let iframes = 0;
let phaseIdx = 0;
let elapsed = 0;
let turnNum = 0;
let gameOver = false;
let inTurn = true;
let turnTimer = null;
let trailTick = 0;

const keys = {};
const projs = [];

// ── BOSS HP ────────────────────────────────────────────────────────
const BOSS_MAX_HP = 300;
let bossHp = BOSS_MAX_HP;
let bossPhase = 0;       // 0=first third, 1=second, 2=final
let bossDefeated = false;
let survivaUsed = false;
let waitingLastShot = false;  // boss dead, waiting final bullet
let _mercyTimer = null;       // timeout for mercy ending
let _mercyEnding = false;     // mercy ending in progress
let _bossRetreating = false;  // boss walking into background
let _bossRetreatScale = 1.0;  // shrinks to 0

// Boss movement state
const bossMove = { x: 0, targetX: 0, speed: 0 };

// Tab title per boss phase
const TAB_PHASE = [
  ["???"],
  ["SAIA", "S-A.IA", "S̶A̶I̶A̶", "SAI A", "sAlA", "¢AIa.", "S̶A̶I̶A̶", "$AI A", "SAIA", "aSiA.", "NÂO", "PARE", "SAIA"],
  // phase 3 uses the existing cycling titles from index.html
];
let _tabPhaseIv = null;
function startTabCycle(phase) {
  clearInterval(_tabPhaseIv);
  if (phase === 0) {
    document.title = "???";
  } else if (phase === 1) {
    let ti = 0;
    _tabPhaseIv = setInterval(() => {
      const t = TAB_PHASE[1];
      document.title = t[ti++ % t.length];
    }, 150);
  }
  // phase 2: let the existing index.html cycle take over (it runs independently)
}

// ═══════════════════════════════════════════════════════════════════
//  BOSS IMAGE
// ═══════════════════════════════════════════════════════════════════
const bossImgs = [
  "./images/boss2.png", // Fase 1
  "./images/bosslaranja2.png", // Fase 2
  "./images/bossvermelho2.png"  // Fase 3
];

const bossImg = new Image();
bossImg.src = bossImgs[0];

// ═══════════════════════════════════════════════════════════════════
//  BOSS STATE
// ═══════════════════════════════════════════════════════════════════
const boss = {
  anim: "idle",
  shakeX: 0, shakeY: 0,
  charging: false,
  chargeCol: "#cc22ff",
  bobT: 0,
  bobOffset: 0,
  bobSpeed: 1.2,
  tilt: 0,
  tiltTarget: 0,
  scale: 1.0,
  scaleTarget: 1.0,
  particleT: 0,
};

// Arm angles — lerped smoothly each frame
// lU = left upper angle, lF = left forearm (relative to upper), same for r
// All in radians; angle 0 = pointing right, PI/2 = down, PI = left, -PI/2 = up
const ARM_TARGETS = {
  //        lUpper  lFore   rUpper  rFore   tilt   bobSpd
  idle:     { lU: 3.9, lF: 0.55, rU: 5.5, rF: -0.55, tilt: 0,    bob: 1.2 },
  kneeling: { lU: 4.6, lF: 1.1,  rU: 4.8, rF: -1.1,  tilt: 0.18, bob: 0.2 },
  farewell: { lU: 1.87, lF: 0.15, rU: 1.27, rF: -0.15, tilt: 0.04, bob: 0.0 },
  cast: { lU: 2.6, lF: -0.9, rU: 0.5, rF: 0.9, tilt: 0.05, bob: 1.6 },  // arms raised, hands forward
  summon: { lU: 3.2, lF: 0.15, rU: 6.2, rF: -0.15, tilt: 0.03, bob: 0.7 },  // arms spread wide
  angry: { lU: 2.3, lF: 1.1, rU: 0.9, rF: -1.1, tilt: 0.09, bob: 3.0 },  // fists up
  hurt: { lU: 4.4, lF: 0.2, rU: 4.8, rF: -0.2, tilt: 0.14, bob: 0.4 },  // drooping
};
const arms = {
  lU: 3.9, lF: 0.55, rU: 5.5, rF: -0.55,
  tlU: 3.9, tlF: 0.55, trU: 5.5, trF: -0.55
};

//coração azul//
let _blueSoulJumpPressed = false;

function moveBlueSoul() {
  const left    = keys["a"] || keys["arrowleft"];
  const right   = keys["d"] || keys["arrowright"];
  const jumpKey = keys["w"] || keys["arrowup"];

  if (left)  px -= 4;
  if (right) px += 4;

  vy += gravity;
  py += vy;

  let grounded = false;

  // Top-surface platform collision
  for (const p of platforms) {
    if (
      px + 40 > p.x &&
      px      < p.x + p.w &&
      py + 40 >= p.y &&
      py + 40 <= p.y + p.h + Math.abs(vy) + 2 &&
      vy >= 0
    ) {
      py = p.y - 40;
      vy = 0;
      grounded = true;
      // ride moving platform
      px += p.vx || 0;
    }
  }

  // Floor — stop falling through bottom of arena (no damage, no bounce)
  if (py >= BH - 40) {
    py = BH - 40;
    vy = -16;
  }

  // Ceiling
  if (py < 0) { py = 0; vy = 0; }

  // Jump — single-press
  if (jumpKey) {
    if (!_blueSoulJumpPressed && grounded) vy = jumpPower;
    _blueSoulJumpPressed = true;
  } else {
    _blueSoulJumpPressed = false;
  }

  px = Math.max(0, Math.min(BW - 50, px));

  // aplica posição no DOM
  playerEl.style.left = px + "px";
  playerEl.style.top  = py + "px";
}
// ═══════════════════════════════════════════════════════════════════
//  ARENA BACKGROUND
// ═══════════════════════════════════════════════════════════════════
function drawArenaBg() {
  abCtx.clearRect(0, 0, BW, BH);
  abCtx.fillStyle = "#07030f";
  abCtx.fillRect(0, 0, BW, BH);
  abCtx.strokeStyle = ["rgba(100,30,200,0.09)", "rgba(255,80,0,0.09)", "rgba(255,10,30,0.09)"][phaseIdx];
  abCtx.lineWidth = 1;
  for (let x = 0; x <= BW; x += 40) { abCtx.beginPath(); abCtx.moveTo(x, 0); abCtx.lineTo(x, BH); abCtx.stroke(); }
  for (let y = 0; y <= BH; y += 40) { abCtx.beginPath(); abCtx.moveTo(0, y); abCtx.lineTo(BW, y); abCtx.stroke(); }
}
drawArenaBg();

// ═══════════════════════════════════════════════════════════════════
//  ARM DRAWING
//  Returns hand position {hx, hy} in local (pre-restore) space
// ═══════════════════════════════════════════════════════════════════
function drawArm(ctx, sx, sy, upperAngle, foreAngle, uLen, fLen, col) {
  const ex = sx + Math.cos(upperAngle) * uLen;
  const ey = sy + Math.sin(upperAngle) * uLen;
  const fa = upperAngle + foreAngle;
  const hx = ex + Math.cos(fa) * fLen;
  const hy = ey + Math.sin(fa) * fLen;

  ctx.lineCap = "round";
  ctx.lineJoin = "round";

  // Drop shadow
  ctx.strokeStyle = "rgba(0,0,0,0.45)";
  ctx.lineWidth = 13;
  ctx.beginPath(); ctx.moveTo(sx + 2, sy + 3); ctx.lineTo(ex + 2, ey + 3); ctx.stroke();
  ctx.lineWidth = 10;
  ctx.beginPath(); ctx.moveTo(ex + 2, ey + 3); ctx.lineTo(hx + 2, hy + 3); ctx.stroke();

  // Upper arm
  ctx.strokeStyle = col;
  ctx.lineWidth = 10;
  ctx.beginPath(); ctx.moveTo(sx, sy); ctx.lineTo(ex, ey); ctx.stroke();

  // Elbow cap
  ctx.fillStyle = col;
  ctx.beginPath(); ctx.arc(ex, ey, 6, 0, Math.PI * 2); ctx.fill();

  // Forearm (slightly thinner)
  ctx.lineWidth = 8;
  ctx.beginPath(); ctx.moveTo(ex, ey); ctx.lineTo(hx, hy); ctx.stroke();

  // Hand
  ctx.fillStyle = col;
  ctx.beginPath(); ctx.arc(hx, hy, 7, 0, Math.PI * 2); ctx.fill();
  // Hand highlight
  ctx.fillStyle = "rgba(255,255,255,0.25)";
  ctx.beginPath(); ctx.arc(hx - 2, hy - 2, 3, 0, Math.PI * 2); ctx.fill();

  return { hx, hy };
}

// ═══════════════════════════════════════════════════════════════════
//  BOSS FRAME (called every rAF from game loop)
// ═══════════════════════════════════════════════════════════════════
function drawBossFrame(ts) {
  bCtx.clearRect(0, 0, CW, CH);

  const t = ts / 1000;
  const cx = CW / 2;
  const cy = CH / 2 + 2;
  const IMG = 90; // display size of character image

  // ── Smooth lerp: arms ──────────────────────────────────────────
  const LR = 0.07;
  arms.lU += (arms.tlU - arms.lU) * LR;
  arms.lF += (arms.tlF - arms.lF) * LR;
  arms.rU += (arms.trU - arms.rU) * LR;
  arms.rF += (arms.trF - arms.rF) * LR;

  // ── Smooth lerp: tilt & scale ─────────────────────────────────
  boss.tilt += (boss.tiltTarget - boss.tilt) * 0.07;
  boss.scale += (boss.scaleTarget - boss.scale) * 0.10;

  // ── Bob ───────────────────────────────────────────────────────
  if (!_mercyEnding) boss.bobT += 0.025 * boss.bobSpeed;
  boss.bobOffset = _mercyEnding ? 0 : Math.sin(boss.bobT) * 4.5;

  // ── Boss lateral movement (gets wilder per phase) ─────────────
  if (!waitingLastShot && !_mercyEnding) {
    bossMove.targetX = Math.sin(t * (0.4 + bossPhase * 0.35)) * (30 + bossPhase * 35);
  } else {
    bossMove.targetX = 0; // center for last-shot and mercy poses
  }
  bossMove.x += (bossMove.targetX - bossMove.x) * (0.025 + bossPhase * 0.015);

  // ── Glitch offset (phase 2+ and during surviva) ──────────────
  let glitchX = 0, glitchY = 0;
  if (bossPhase >= 1 || _survivaGlitch) {
    const intensity = _survivaGlitch ? 18 : bossPhase * 6;
    if (Math.random() < 0.06 + bossPhase * 0.08 + (_survivaGlitch ? 0.25 : 0)) {
      glitchX = (Math.random() - .5) * intensity * 2;
      glitchY = (Math.random() - .5) * intensity;
    }
  }

  // Local-space origin of boss (shake applied here)
  const ox = cx + boss.shakeX + bossMove.x + glitchX;
  const oy = cy + boss.bobOffset + boss.shakeY + glitchY;

  // Phase arm colors
  const A_COL = ["#7722bb", "#bb4400", "#aa0011"][phaseIdx];
  const A_HI = ["#cc77ff", "#ffaa44", "#ff5566"][phaseIdx];

  if (_bossRetreating) {
    _bossRetreatScale = Math.max(0, _bossRetreatScale - 0.007);
    boss.bobSpeed = 0.3;
  }

  bCtx.save();
  bCtx.translate(ox, oy);
  bCtx.rotate(boss.tilt);
  bCtx.scale(_bossRetreatScale, _bossRetreatScale); // scale everything: arms + sprite together

  // ── ARMS (drawn BEHIND sprite) ──────────────────────────────
  const lH = drawArm(bCtx, -44, 8, arms.lU, arms.lF, 32, 26, A_COL);
  const rH = drawArm(bCtx, 44, 8, arms.rU, arms.rF, 32, 26, A_COL);

  // ── SPRITE ─────────────────────────────────────────────────
  if (bossImg.complete && bossImg.naturalWidth > 0) {
    const sc = boss.scale;
    bCtx.drawImage(bossImg, -IMG / 2 * sc, -IMG / 2 * sc, IMG * sc, IMG * sc);
  } else {
    bCtx.fillStyle = A_COL;
    bCtx.fillRect(-22, -22, 44, 44);
  }

  // ── HAND EFFECTS (drawn on top of sprite) ──────────────────
  if (boss.anim === "cast") {
    // Glowing orbs at hands — pulse rapidly
    const op = 0.55 + 0.42 * Math.sin(t * 7);
    bCtx.save();
    bCtx.globalAlpha = op;
    bCtx.fillStyle = boss.chargeCol;
    bCtx.shadowColor = boss.chargeCol;
    bCtx.shadowBlur = 12;
    bCtx.beginPath(); bCtx.arc(lH.hx, lH.hy, 10, 0, Math.PI * 2); bCtx.fill();
    bCtx.beginPath(); bCtx.arc(rH.hx, rH.hy, 10, 0, Math.PI * 2); bCtx.fill();
    bCtx.restore();
  }

  if (boss.anim === "summon") {
    // Energy tendrils shooting from palms
    bCtx.save();
    bCtx.strokeStyle = A_HI;
    bCtx.lineWidth = 2;
    for (let k = 0; k < 3; k++) {
      const op = 0.3 + 0.4 * Math.sin(t * 5 + k * 1.2);
      bCtx.globalAlpha = op;
      const aL = arms.lU + arms.lF + (k - 1) * 0.45;
      const aR = arms.rU + arms.rF + (k - 1) * 0.45;
      bCtx.beginPath();
      bCtx.moveTo(lH.hx, lH.hy);
      bCtx.lineTo(lH.hx + Math.cos(aL) * 22, lH.hy + Math.sin(aL) * 22);
      bCtx.stroke();
      bCtx.beginPath();
      bCtx.moveTo(rH.hx, rH.hy);
      bCtx.lineTo(rH.hx + Math.cos(aR) * 22, rH.hy + Math.sin(aR) * 22);
      bCtx.stroke();
    }
    bCtx.restore();
  }

  if (boss.anim === "angry") {
    // Rage aura pulsing around fists
    bCtx.save();
    const op = 0.22 + 0.2 * Math.sin(t * 11);
    bCtx.globalAlpha = op;
    bCtx.fillStyle = "#ff2244";
    bCtx.shadowColor = "#ff2244";
    bCtx.shadowBlur = 16;
    const r1 = 13 + Math.sin(t * 9) * 3;
    const r2 = 13 + Math.sin(t * 8.3) * 3;
    bCtx.beginPath(); bCtx.arc(lH.hx, lH.hy, r1, 0, Math.PI * 2); bCtx.fill();
    bCtx.beginPath(); bCtx.arc(rH.hx, rH.hy, r2, 0, Math.PI * 2); bCtx.fill();
    bCtx.restore();
  }

  bCtx.restore(); // end boss local space

  // ── CHARGE FLASH ───────────────────────────────────────────
  if (boss.charging) {
    bCtx.save();
    bCtx.globalAlpha = 0.13 + 0.1 * Math.sin(t * 15);
    bCtx.fillStyle = boss.chargeCol;
    bCtx.fillRect(0, 0, CW, CH);
    bCtx.restore();
  }

  // ── ORBITING PARTICLES (phase 2+) ─────────────────────────
  if (phaseIdx >= 1) {
    boss.particleT += 0.04;
    const n = phaseIdx === 2 ? 8 : 5;
    for (let i = 0; i < n; i++) {
      const a = boss.particleT * 2.2 + (i / n) * Math.PI * 2;
      const rx = 62, ry = 26;
      const px2 = ox + Math.cos(a) * rx;
      const py2 = oy + Math.sin(a) * ry;
      const al = 0.28 + 0.28 * Math.sin(a * 2);
      bCtx.save();
      bCtx.globalAlpha = al;
      bCtx.fillStyle = i % 2 === 0 ? A_HI : A_COL;
      bCtx.fillRect(px2 - 3, py2 - 3, 6, 6);
      bCtx.restore();
    }
  }

  // ── PHASE 3 DARK AURA ─────────────────────────────────────
  if (phaseIdx === 2) {
    const g = bCtx.createRadialGradient(ox, oy, 12, ox, oy, 85);
    const aa = 0.04 + 0.03 * Math.sin(t * 3);
    g.addColorStop(0, `rgba(220,0,40,${aa * 3})`);
    g.addColorStop(1, "rgba(0,0,0,0)");
    bCtx.fillStyle = g;
    bCtx.fillRect(0, 0, CW, CH);
  }

  // ── GLITCH SLICES (boss phase 1+) ─────────────────────────────
  if (bossPhase >= 1 || _survivaGlitch) {
    const slices = _survivaGlitch ? 5 : bossPhase * 2;
    const chance = _survivaGlitch ? 0.55 : 0.12 + bossPhase * 0.1;
    for (let s = 0; s < slices; s++) {
      if (Math.random() < chance) {
        const gy = Math.random() * CH;
        const gh = 2 + Math.random() * (bossPhase * 4 + 2);
        const gox = (Math.random() - .5) * (bossPhase * 20 + (_survivaGlitch ? 40 : 0));
        bCtx.save();
        bCtx.globalAlpha = 0.35 + Math.random() * 0.4;
        const imgData = bCtx.getImageData(0, gy, CW, gh);
        bCtx.putImageData(imgData, gox, gy);
        bCtx.restore();
      }
    }
  }

  // ── RGB SPLIT (surviva) ────────────────────────────────────────
  if (_survivaGlitch && Math.random() < 0.3) {
    bCtx.save();
    bCtx.globalAlpha = 0.18;
    bCtx.globalCompositeOperation = "screen";
    bCtx.fillStyle = "red";
    bCtx.fillRect(Math.random() * 20 - 10, 0, CW, CH);
    bCtx.fillStyle = "cyan";
    bCtx.fillRect(-(Math.random() * 20 - 10), 0, CW, CH);
    bCtx.restore();
  }

  // ── BOSS HIT EXPLOSIONS ────────────────────────────────────────
  _drawBossExplosions();
}

// ═══════════════════════════════════════════════════════════════════
//  BOSS ANIMATION HELPERS
// ═══════════════════════════════════════════════════════════════════
function bossSetAnim(name, dur = 1800) {
  boss.anim = name;
  const T = ARM_TARGETS[name] || ARM_TARGETS.idle;
  arms.tlU = T.lU; arms.tlF = T.lF;
  arms.trU = T.rU; arms.trF = T.rF;
  boss.tiltTarget = T.tilt;
  boss.bobSpeed = T.bob;
  clearTimeout(boss._animT);
  boss._animT = setTimeout(() => {
    boss.anim = "idle";
    const I = ARM_TARGETS.idle;
    arms.tlU = I.lU; arms.tlF = I.lF;
    arms.trU = I.rU; arms.trF = I.rF;
    boss.tiltTarget = 0;
    boss.bobSpeed = 1.2;
  }, dur);
}

let _shakeIv = null;
function bossCharge(col, dur = 700) {
  boss.charging = true; boss.chargeCol = col; boss.scaleTarget = 1.09;
  clearInterval(_shakeIv);
  _shakeIv = setInterval(() => {
    boss.shakeX = (Math.random() - 0.5) * 10;
    boss.shakeY = (Math.random() - 0.5) * 6;
  }, 35);
  setTimeout(() => {
    boss.charging = false; boss.shakeX = 0; boss.shakeY = 0;
    boss.scaleTarget = 1.0;
    clearInterval(_shakeIv);
  }, dur);
}

// ═══════════════════════════════════════════════════════════════════
//  DIALOGUE
// ═══════════════════════════════════════════════════════════════════
let _twIv = null;
function say(raw) {
  clearInterval(_twIv);
  const str = raw.startsWith("*") ? raw : "* " + raw;
  dlgText.textContent = "";
  let i = 0;
  _twIv = setInterval(() => {
    dlgText.textContent += str[i++];
    if (i >= str.length) clearInterval(_twIv);
  }, 24);
}
const TAUNT_HIT = ["HAH.", "Patético.", "Lento demais!", "Lento, Lento.", "Isso doeu?"];
function tauntHit() { say(TAUNT_HIT[Math.random() * TAUNT_HIT.length | 0]); }

// ═══════════════════════════════════════════════════════════════════
//  PARTICLES
// ═══════════════════════════════════════════════════════════════════
function spawnTrail() {
  const d = document.createElement("div");
  d.className = "trail";
  d.style.cssText = `left:${px + 17}px;top:${py + 17}px`;
  battleBox.appendChild(d);
  setTimeout(() => d.remove(), 140);
}
function burst(bx2, by2, col = "#ff2244", n = 7) {
  for (let i = 0; i < n; i++) {
    const d = document.createElement("span");
    d.className = "burst";
    d.style.cssText = `left:${bx2}px;top:${by2}px;background:${col}`;
    const a = (Math.PI * 2 * i) / n;
    d.style.setProperty("--dx", Math.cos(a) * 22 + "px");
    d.style.setProperty("--dy", Math.sin(a) * 22 + "px");
    battleBox.appendChild(d);
    setTimeout(() => d.remove(), 260);
  }
}

// Active explosions — drawn each frame inside drawBossFrame
const _bossExplosions = [];

function bossOrangeExplosion(cx, cy) {
  _bossExplosions.push({ cx, cy, f: 0, total: 8 });
}

function _drawBossExplosions() {
  for (let i = _bossExplosions.length - 1; i >= 0; i--) {
    const e = _bossExplosions[i];
    const progress = e.f / e.total;
    const r = 3 + progress * 13;
    const alpha = 1 - progress * 0.88;
    bCtx.save();
    bCtx.globalAlpha = alpha;
    if (e.f < 3) {
      bCtx.beginPath();
      bCtx.arc(e.cx, e.cy, r * 0.55 + 1, 0, Math.PI * 2);
      bCtx.fillStyle = "#fff5cc";
      bCtx.shadowColor = "#ffcc00";
      bCtx.shadowBlur = 10;
      bCtx.fill();
    }
    bCtx.beginPath();
    bCtx.arc(e.cx, e.cy, r, 0, Math.PI * 2);
    bCtx.strokeStyle = e.f < 3 ? "#ffdd00" : "#ff6600";
    bCtx.lineWidth = Math.max(0.5, 2.8 - progress * 2.5);
    bCtx.shadowColor = "#ff8800";
    bCtx.shadowBlur = 14;
    bCtx.stroke();
    for (let s = 0; s < 4; s++) {
      const sa = (Math.PI * 2 * s / 4) + e.f * 0.55;
      const sr = r * 0.9;
      bCtx.beginPath();
      bCtx.arc(e.cx + Math.cos(sa) * sr, e.cy + Math.sin(sa) * sr, 1.8, 0, Math.PI * 2);
      bCtx.fillStyle = s % 2 === 0 ? "#ff8800" : "#ffcc44";
      bCtx.shadowBlur = 5;
      bCtx.fill();
    }
    bCtx.restore();
    e.f++;
    if (e.f >= e.total) _bossExplosions.splice(i, 1);
  }
}
function createPlatform(x,y,w,h){

  const el = document.createElement("div");

  el.style.position = "absolute";
  el.style.left = x+"px";
  el.style.top = y+"px";
  el.style.width = w+"px";
  el.style.height = h+"px";
  el.style.background = "#66ccff";

  battleBox.appendChild(el);

  const obj = {el,x,y,w,h};

  platforms.push(obj);

  return obj;
}
function clearPlatforms(){

  for(const p of platforms){
    p.el.remove();
  }

  platforms.length = 0;
}

// ── AUTO-SCROLLER PLATFORM ATTACK ─────────────────────────────────
let _blueScrollIv = null;

function atkBluePlatform() {
  bossSetAnim("summon", 9500);
  say("* Não caia!");
  blueMode = true;
  clearPlatforms();

  // Clear any player bullets in the air
  for (const b of playerBullets) b.el.remove();
  playerBullets.length = 0;

  const DURATION   = 9500;  // total attack length (ms)
  const SCROLL_SPD = 3.2;   // px per frame platforms move left
  const PLT_H      = 12;
  // Height bands player can realistically jump between
  // jumpPower = -8.5, gravity = 0.45  →  max height ≈ 80 px
  // We keep ΔY ≤ 70 px and horizontal gap ≤ 190 px so it's always jumpable
  const MIN_W = 55, MAX_W = 95;
  const BAND_Y = [BH - 120, BH - 190, BH - 260, BH - 320]; // allowed heights

  // --- generate a guaranteed-jumpable sequence of platforms ---
  function genPlatforms(startX) {
    const count = 8;
    const result = [];
    let curX = startX;
    let curY = BAND_Y[1]; // start mid-height
    for (let i = 0; i < count; i++) {
      const w   = MIN_W + Math.random() * (MAX_W - MIN_W) | 0;
      const gap = 90  + Math.random() * 100 | 0;   // 90–190 px horizontal gap
      const dy  = (Math.floor(Math.random() * 3) - 1) * (40 + Math.random() * 30 | 0); // -1,0,+1 step
      let nextY = curY + dy;
      // clamp to valid band
      nextY = Math.max(BAND_Y[BAND_Y.length-1], Math.min(BAND_Y[0], nextY));
      result.push({ x: curX, y: nextY, w, h: PLT_H });
      curX += w + gap;
      curY  = nextY;
    }
    return result;
  }

  // --- spawn initial batch and place player on first platform ---
  function spawnBatch(startX) {
    const defs = genPlatforms(startX);
    for (const d of defs) createPlatform(d.x, d.y, d.w, d.h);
    return defs[defs.length - 1].x + defs[defs.length - 1].w; // rightmost edge
  }

  let rightEdge = spawnBatch(80);
  // Spawn player on first platform
  const first = platforms[0];
  px = first.x + 10;
  py = first.y - 40;
  vy = 0;

  // Floor laser — damages on contact for the full duration
  mkProj(0, BH - 40, 0, 0, BW, 40, "laser-blue", { laser: true, life: DURATION });

  // --- scroll loop ---
  clearInterval(_blueScrollIv);
  _blueScrollIv = setInterval(() => {
    if (!blueMode) { clearInterval(_blueScrollIv); return; }

    // move all platforms left
    for (const p of platforms) {
      p.x  -= SCROLL_SPD;
      p.vx  = -SCROLL_SPD; // so moveBlueSoul can ride them
      p.el.style.left = p.x + "px";
    }
    rightEdge -= SCROLL_SPD;

    // remove platforms that left the screen
    for (let i = platforms.length - 1; i >= 0; i--) {
      if (platforms[i].x + platforms[i].w < -10) {
        platforms[i].el.remove();
        platforms.splice(i, 1);
      }
    }

    // generate new platforms when right edge gets close
    if (rightEdge < BW + 60) {
      rightEdge = spawnBatch(rightEdge + 60) - SCROLL_SPD;
    }
  }, 1000 / 60);

  setTimeout(() => {
    clearInterval(_blueScrollIv);
    clearPlatforms();
    blueMode = false;
    vy = 0;
  }, DURATION);

  return {
    label: "PARKOUR URBANO",
    dialogue: "* Não caia!",
    color: "#66ccff",
    duration: DURATION + 400
  };
}
// ═══════════════════════════════════════════════════════════════════
//  PROJECTILE FACTORY / WARNINGS
// ═══════════════════════════════════════════════════════════════════
// ── ORB ICONS ─────────────────────────────────────────────────────
// [svgInner, bgColor] — SVG paths on 20x20 viewBox, matching map category icons exactly
const ORB_ICONS = [
  // Iluminação — yellow lightbulb
  ['<circle cx="10" cy="8.5" r="3.8" fill="none" stroke="white" stroke-width="1.5"/><line x1="10" y1="12.3" x2="10" y2="13.5" stroke="white" stroke-width="1.5"/><rect x="8.2" y="13.5" width="3.6" height="1.1" rx="0.5" fill="white"/><rect x="8.6" y="14.9" width="2.8" height="1" rx="0.5" fill="white"/><line x1="10" y1="4" x2="10" y2="3" stroke="white" stroke-width="1.3"/><line x1="6.2" y1="5.2" x2="5.5" y2="4.5" stroke="white" stroke-width="1.3"/><line x1="13.8" y1="5.2" x2="14.5" y2="4.5" stroke="white" stroke-width="1.3"/><line x1="5" y1="8.5" x2="4" y2="8.5" stroke="white" stroke-width="1.3"/><line x1="15" y1="8.5" x2="16" y2="8.5" stroke="white" stroke-width="1.3"/>', '#FFC107'],
  // Limpeza — green trash bin
  ['<rect x="6.5" y="6.5" width="7" height="9" rx="0.8" fill="none" stroke="white" stroke-width="1.4"/><line x1="6.5" y1="9" x2="13.5" y2="9" stroke="white" stroke-width="1.2"/><line x1="9" y1="9" x2="9" y2="14.5" stroke="white" stroke-width="1.1"/><line x1="11" y1="9" x2="11" y2="14.5" stroke="white" stroke-width="1.1"/><rect x="7.5" y="5" width="5" height="1.5" rx="0.6" fill="white"/>', '#28A745'],
  // Transporte — orange bus
  ['<rect x="4" y="6" width="12" height="8.5" rx="1.5" fill="none" stroke="white" stroke-width="1.4"/><rect x="5.5" y="7.5" width="3.5" height="3" rx="0.5" fill="white"/><rect x="11" y="7.5" width="3.5" height="3" rx="0.5" fill="white"/><circle cx="7" cy="15.5" r="1.4" fill="white"/><circle cx="13" cy="15.5" r="1.4" fill="white"/><line x1="4" y1="11" x2="16" y2="11" stroke="white" stroke-width="1"/>', '#FF6B35'],
  // Meteorológico — blue cloud
  ['<path d="M6.5 13.5 a3 3 0 0 1 0-6 a2.5 2.5 0 0 1 4.8-1 a2.5 2.5 0 0 1 3.2 2.4 a2 2 0 0 1-0.5 3.6z" fill="white"/><line x1="7" y1="15" x2="6.3" y2="17" stroke="white" stroke-width="1.2" stroke-linecap="round"/><line x1="10" y1="15.5" x2="9.5" y2="17" stroke="white" stroke-width="1.2" stroke-linecap="round"/><line x1="13" y1="15" x2="12.5" y2="17" stroke="white" stroke-width="1.2" stroke-linecap="round"/>', '#2196F3'],
  // Saúde — red cross/hospital
  ['<rect x="4" y="5" width="4" height="10" rx="0.5" fill="white"/><rect x="9" y="5" width="4" height="10" rx="0.5" fill="white"/><rect x="4" y="5" width="9" height="3.5" rx="0.5" fill="white"/><rect x="4" y="11.5" width="9" height="3.5" rx="0.5" fill="white"/><line x1="2" y1="16.5" x2="18" y2="16.5" stroke="white" stroke-width="1.3"/>', '#4BAC50'],
  // Acessibilidade — purple wheelchair
  ['<circle cx="10.5" cy="4.5" r="1.8" fill="white"/><path d="M10 6.5 L8.5 12 L6 15.5" stroke="white" stroke-width="1.4" fill="none" stroke-linecap="round"/><path d="M8.5 9.5 L13 9.5 L14.5 13.5" stroke="white" stroke-width="1.4" fill="none" stroke-linecap="round"/><circle cx="6.5" cy="16" r="2" fill="none" stroke="white" stroke-width="1.3"/>', '#9C27B0'],
  // Meio Ambiente — green leaf
  ['<path d="M10 16 C10 16 3.5 13 3.5 7.5 C3.5 4.5 6.5 3 10 3 C13.5 3 16.5 4.5 16.5 7.5 C16.5 13 10 16 10 16z" fill="none" stroke="white" stroke-width="1.4"/><line x1="10" y1="16" x2="10" y2="9" stroke="white" stroke-width="1.2"/><path d="M10 12.5 C8 10.5 5.5 10 5.5 10" stroke="white" stroke-width="1.1" fill="none" stroke-linecap="round"/><path d="M10 10 C12 8 14.5 7.5 14.5 7.5" stroke="white" stroke-width="1.1" fill="none" stroke-linecap="round"/>', '#00BCD4'],
  // Drenagem — blue water drop
  ['<path d="M10 3.5 C10 3.5 4.5 9.5 4.5 13 a5.5 5.5 0 0 0 11 0 C15.5 9.5 10 3.5 10 3.5z" fill="white"/>', '#3F51B5'],
  // Ciclismo — teal bicycle
  ['<circle cx="6" cy="13.5" r="3.2" fill="none" stroke="white" stroke-width="1.4"/><circle cx="14" cy="13.5" r="3.2" fill="none" stroke="white" stroke-width="1.4"/><path d="M6 13.5 L9.5 7.5 L14 13.5" stroke="white" stroke-width="1.3" fill="none"/><path d="M9.5 7.5 L12 7.5" stroke="white" stroke-width="1.3" stroke-linecap="round"/><circle cx="10.5" cy="6.2" r="1.3" fill="white"/>', '#89C04A'],
  // Outros — gray pin/location
  ['<path d="M10 3 C6.8 3 4.5 5.3 4.5 8.5 C4.5 12.8 10 18 10 18 C10 18 15.5 12.8 15.5 8.5 C15.5 5.3 13.2 3 10 3z" fill="none" stroke="white" stroke-width="1.5"/><circle cx="10" cy="8.5" r="2.5" fill="white"/>', '#6C757D'],
  // Asfalto — gray road/layers
  ['<rect x="3" y="5" width="14" height="2.5" rx="0.8" fill="white"/><rect x="3" y="9" width="14" height="2.5" rx="0.8" fill="white"/><rect x="3" y="13" width="14" height="2.5" rx="0.8" fill="white"/>', '#0056B3'],
  // Água/Esgoto — blue waves
  ['<path d="M3.5 7.5 Q5.5 5.5 7.5 7.5 Q9.5 9.5 11.5 7.5 Q13.5 5.5 15.5 7.5" stroke="white" stroke-width="1.6" fill="none" stroke-linecap="round"/><path d="M3.5 11.5 Q5.5 9.5 7.5 11.5 Q9.5 13.5 11.5 11.5 Q13.5 9.5 15.5 11.5" stroke="white" stroke-width="1.6" fill="none" stroke-linecap="round"/><path d="M3.5 15 Q5.5 13 7.5 15 Q9.5 17 11.5 15 Q13.5 13 15.5 15" stroke="white" stroke-width="1.6" fill="none" stroke-linecap="round"/>', '#DA3545'],
  // Assistencial — pink heart
  ['<path d="M10 16 C10 16 3.5 11 3.5 7 a3.5 3.5 0 0 1 6.5-1.8 A3.5 3.5 0 0 1 16.5 7 C16.5 11 10 16 10 16z" fill="white"/>', '#E71E63'],
  // Mobilidade — orange walking person
  ['<circle cx="10" cy="4.2" r="1.9" fill="white"/><line x1="10" y1="6.1" x2="9" y2="11" stroke="white" stroke-width="1.5" stroke-linecap="round"/><path d="M9 11 L6.5 14.5" stroke="white" stroke-width="1.4" stroke-linecap="round"/><path d="M9 11 L11.5 14.5" stroke="white" stroke-width="1.4" stroke-linecap="round"/><path d="M10 6.1 L7.5 9.5" stroke="white" stroke-width="1.4" stroke-linecap="round"/><path d="M10 6.1 L12.5 9" stroke="white" stroke-width="1.4" stroke-linecap="round"/>', '#FD9700'],
  // Segurança — blue shield with check
  ['<path d="M10 3 L16.5 5.5 V10 C16.5 13.8 13.5 17 10 18 C6.5 17 3.5 13.8 3.5 10 V5.5 Z" fill="none" stroke="white" stroke-width="1.5"/><path d="M7 10.5 L9 12.5 L13.5 8" stroke="white" stroke-width="1.6" fill="none" stroke-linecap="round" stroke-linejoin="round"/>', '#F44336'],
  // Eletricidade — yellow lightning bolt
  ['<path d="M12.5 3 L7 10.5 L10.5 10.5 L8 18 L15 9 L11 9 Z" fill="white"/>', '#FFEB3B'],
  // Estrutura — brown building
  ['<rect x="4" y="4" width="5" height="13" rx="0.4" fill="none" stroke="white" stroke-width="1.3"/><rect x="11" y="4" width="5" height="13" rx="0.4" fill="none" stroke="white" stroke-width="1.3"/><rect x="6" y="7" width="1.5" height="2" fill="white"/><rect x="6" y="11" width="1.5" height="2" fill="white"/><rect x="12.5" y="7" width="1.5" height="2" fill="white"/><rect x="12.5" y="11" width="1.5" height="2" fill="white"/><rect x="3.5" y="16.5" width="13" height="1" fill="white"/>', '#FF5722'],
  // Obras — red crossed tools/wrench+hammer
  ['<line x1="4" y1="16" x2="9.5" y2="10.5" stroke="white" stroke-width="1.6" stroke-linecap="round"/><line x1="16" y1="4" x2="10.5" y2="9.5" stroke="white" stroke-width="1.6" stroke-linecap="round"/><circle cx="6" cy="14" r="2.2" fill="none" stroke="white" stroke-width="1.3"/><circle cx="14" cy="6" r="2.2" fill="none" stroke="white" stroke-width="1.3"/><line x1="4" y1="4" x2="16" y2="16" stroke="white" stroke-width="1.5" stroke-linecap="round"/>', '#e74c3c'],
  // Má Gestão — orange/yellow warning triangle
  ['<path d="M10 4 L17.5 17 L2.5 17 Z" fill="none" stroke="white" stroke-width="1.5"/><line x1="10" y1="9" x2="10" y2="13.5" stroke="white" stroke-width="1.8" stroke-linecap="round"/><circle cx="10" cy="15.5" r="1" fill="white"/>', '#673AB7'],
];

function _makeOrbEl(w, h) {
  const [svgInner, color] = ORB_ICONS[Math.random() * ORB_ICONS.length | 0];
  const el = document.createElement("div");
  el.style.cssText = [
    "width:100%", "height:100%",
    "display:flex", "align-items:center", "justify-content:center",
    "border-radius:50%",
    "background:" + color,
    "box-shadow:0 0 7px 2px " + color + "bb, inset 0 1px 0 rgba(255,255,255,0.25)",
    "user-select:none",
    "pointer-events:none",
    "overflow:hidden",
  ].join(";");
  const size = Math.min(w, h);
  el.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" width="' + size * 0.82 + '" height="' + size * 0.82 + '">' + svgInner + '</svg>';
  return el;
}

function mkProj(x, y, vx, vy, w, h, cls, extra = {}) {
  const el = document.createElement("div");
  el.className = "atk " + cls;
  el.style.cssText = `width:${w}px;height:${h}px;left:${x}px;top:${y}px`;

  if (cls.startsWith("orb")) {
    el.style.background = "none";
    el.style.border = "none";
    el.style.boxShadow = "none";
    el.style.borderRadius = "50%";
    el.style.overflow = "hidden";
    el.appendChild(_makeOrbEl(w, h));
  }

  battleBox.appendChild(el);
  const o = { el, x, y, vx, vy, w, h, cls, rot: 0, ...extra };
  projs.push(o);
  return o;
}
function warnH(y, h, dur, cls = "warn-r") {
  const el = document.createElement("div");
  el.className = `warn ${cls}`;
  el.style.cssText = `left:0;width:${BW}px;top:${y}px;height:${h}px`;
  battleBox.appendChild(el);
  setTimeout(() => el.remove(), dur);
}
function warnV(x, w, dur, cls = "warn-r") {
  const el = document.createElement("div");
  el.className = `warn ${cls}`;
  el.style.cssText = `top:0;height:${BH}px;left:${x}px;width:${w}px`;
  battleBox.appendChild(el);
  setTimeout(() => el.remove(), dur);
}

function M() { return PHASES[phaseIdx].spd; }

// ═══════════════════════════════════════════════════════════════════
//  TURN SYSTEM atkBoneRain, atkBoneWall, atkBlueBones, atkSpiral, atkHoming,
//    atkKnives, atkBurst8, atkColumns, atkStarfall, atkRingWave
// ═══════════════════════════════════════════════════════════════════
let lastAttackIdx = -1;
const PHASE_ATTACKS = [
  // Phase 1
  [atkBluePlatform, atkBoneRain, atkBoneWall, atkBlueBones, atkSpiral, atkHoming,
   atkKnives, atkBurst8, atkColumns, atkStarfall, atkRingWave],
  // Phase 2
  [atkBoneRain, atkBoneWall, atkBlueBones, atkSpiral, atkHoming,
    atkKnives, atkBurst8, atkColumns, atkStarfall, atkRingWave,
    atkBlaster, atkCross, atkSweep, atkTelegraph, atkVortex, atkBluePlatform],
  // Phase 3 — same pool, attacks are individually capped/slowed; chaos appears once
  [atkBoneRain, atkBoneWall, atkBlueBones, atkSpiral, atkHoming,
    atkKnives, atkBurst8, atkColumns, atkStarfall, atkRingWave,
    atkBlaster, atkCross, atkSweep, atkTelegraph, atkVortex, atkBluePlatform, atkChaos],
];

function pickAttack() {
  const pool = PHASE_ATTACKS[phaseIdx];
  let idx;
  do { idx = Math.random() * pool.length | 0; }
  while (idx === lastAttackIdx && pool.length > 1);
  lastAttackIdx = idx;
  return pool[idx];
}
function startTurn() {
  if (gameOver) return;
  turnNum++; inTurn = true;
  clearAllProjs();
  const atk = pickAttack();
  const info = atk();
  showBanner(info.label || "ATTACK", info.color || "#fff");
  setTimeout(() => { if (!gameOver) say(info.dialogue || "..."); }, 300);
  turnTimer = setTimeout(() => { if (!gameOver) startTurn(); }, info.duration || 5000);
}
function showBanner(text, col) {
  turnLabel.textContent = text;
  turnLabel.style.color = col;
  turnLabel.style.borderColor = col;
  turnBanner.classList.add("show");
  setTimeout(() => turnBanner.classList.remove("show"), 900);
}
function clearAllProjs() {
  for (const p of projs) p.el.remove();
  projs.length = 0;
}

// ═══════════════════════════════════════════════════════════════════
//  ATTACKS
//  Phase 3 balancing rules applied to each:
//   • spd: M() is now 1.45 (was 1.9), built-in cap where needed
//   • counts: capped to avoid density that fills every pixel
//   • gaps: minimum safe zones guaranteed
//   • simultaneous patterns: reduced or staggered
// ═══════════════════════════════════════════════════════════════════

function atkBoneRain() {
  bossSetAnim("summon", 4200);
  bossCharge("#ccddff", 450);
  const count = phaseIdx < 2 ? 14 + phaseIdx * 6 : 22;   // was up to 26
  const delay = Math.max(110, 160 - phaseIdx * 25);

  for (let i = 0; i < count; i++) {
    setTimeout(() => {
      if (gameOver || !inTurn) return;
      mkProj(12 + Math.random() * (BW - 40), -28, 0, (3.5 + Math.random() * 2) * M(), 12, 30, "bone");
    }, i * delay);
  }
  // Wave 2 — same count in phase 3 as phase 2
  setTimeout(() => {
    const w2 = phaseIdx < 2 ? 8 + phaseIdx * 3 : 10;
    for (let i = 0; i < w2; i++) {
      setTimeout(() => {
        if (gameOver || !inTurn) return;
        mkProj(12 + Math.random() * (BW - 40), -28, (Math.random() - .5) * 1.2, (3 + Math.random() * 2) * M(), 10, 26, "bone");
      }, i * 120);
    }
  }, 2300);
  return { label: "DEMOLIÇÃO", dialogue: "* Destroços!", color: "#ddeeff", duration: 5500 };
}

function atkBoneWall() {
  bossSetAnim("cast", 3800);
  const waves = phaseIdx < 2 ? 2 + phaseIdx : 3;
  for (let w = 0; w < waves; w++) {
    setTimeout(() => {
      if (gameOver || !inTurn) return;
      const gL = Math.floor(Math.random() * 7), gR = Math.floor(Math.random() * 7);
      const sp = (6 + phaseIdx * 0.9) * M();
      for (let i = 0; i < 8; i++) {
        const y = i * 52 + 10;
        if (i !== gL) mkProj(-95, y, sp, 0, 90, 14, "bone");
        if (i !== gR) mkProj(BW + 10, y, -sp, 0, 90, 14, "bone");
      }
      // Vertical bones only phase 2 (not phase 3 — avoids inescapable combos)
      if (phaseIdx === 1) {
        const gV = Math.floor(Math.random() * 5);
        for (let i = 0; i < 6; i++) {
          if (i !== gV) mkProj(i * 100 + 8, -30, (Math.random() - .5) * .5, (3.5 + Math.random()) * M(), 14, 40, "bone");
        }
      }
    }, w * 2000);
  }
  return { label: "PRÉDIOS", dialogue: "* CAIA!!!", color: "#ddeeff", duration: 6000 };
}

function atkBlueBones() {
  bossSetAnim("cast", 5500);
  say("* Azul = PARAR");

  const rows = [10, 80, 150, 220, 290, 360];
  const sp = (6 + phaseIdx * 0.8) * M();

  const waveCount = phaseIdx < 2 ? 4 : 6;

  for (let wave = 0; wave < waveCount; wave++) {

    setTimeout(() => {

      if (gameOver || !inTurn) return;

      // embaralha as linhas
      const shuffled = [...rows].sort(() => Math.random() - 0.5);

      // metade azul, metade branca
      const blueRows = shuffled.slice(0, 3);
      const whiteRows = shuffled.slice(3);

      // Azuis vindo da esquerda
      blueRows.forEach(y => {
        mkProj(
          -80,
          y,
          sp,
          0,
          70,   // largura menor
          70,   // altura maior
          "bone-blue",
          { blueBone: true }
        );
      });

      // Brancos vindo da direita
      whiteRows.forEach(y => {
        mkProj(
          BW + 10,
          y,
          -sp,
          0,
          70,   // largura menor
          70,   // altura maior
          "bone"
        );
      });

    }, wave * 1300);

  }

  return {
    label: "RELATÓRIOS",
    dialogue: "* Não me mexeria se fosse você!",
    color: "#2255ff",
    duration: 9000
  };
}

function atkSpiral() {
  bossSetAnim("cast", 5200);
  bossCharge("#cc22ff", 650);
  let angle = 0, fired = 0;
  const total = phaseIdx < 2 ? 55 + phaseIdx * 20 : 72;   // was up to 95
  const step = phaseIdx < 2 ? 0.30 : 0.38;           // wider gaps in p3
  const sp = (4 + phaseIdx * 0.35) * M();
  const cls = ["orb-purple", "orb-cyan", "orb-yellow"];
  const iv = setInterval(() => {
    if (gameOver || !inTurn || fired >= total) { clearInterval(iv); return; }
    mkProj(BW / 2 - 6, BH / 2 - 6, Math.cos(angle) * sp, Math.sin(angle) * sp, 12, 12, cls[fired % cls.length]);
    if (phaseIdx >= 1)
      mkProj(BW / 2 - 6, BH / 2 - 6, Math.cos(angle + Math.PI) * sp, Math.sin(angle + Math.PI) * sp, 10, 10, cls[(fired + 1) % cls.length]);
    if (phaseIdx >= 2)   // third arm, 85% speed → more gap to slip through
      mkProj(BW / 2 - 6, BH / 2 - 6, Math.cos(angle + Math.PI * .66) * sp * .85, Math.sin(angle + Math.PI * .66) * sp * .85, 9, 9, cls[fired % 3]);
    angle += step; fired++;
  }, 42);
  return { label: "ESPIRAL SOMBRIA", dialogue: "* Vai girando", color: "#cc22ff", duration: 6000 };
}
function atkBlackHole() {

  bossSetAnim("cast", 7000);
  bossCharge("#aa44ff", 1000);

  const cx = BW / 2;
  const cy = BH / 2;

  const hole = mkProj(
    cx - 20,
    cy - 20,
    0,
    0,
    40,
    40,
    "black-hole",
    {
      blackHole:true,
      radius:30,
      life:7000
    }
  );

  // Crescimento do buraco
  const grow = setInterval(() => {

    if (gameOver || !inTurn) {
      clearInterval(grow);
      return;
    }

    hole.radius += 0.4;

    hole.w = hole.radius * 2;
    hole.h = hole.radius * 2;

    hole.el.style.width = hole.w + "px";
    hole.el.style.height = hole.h + "px";

    hole.x = cx - hole.radius;
    hole.y = cy - hole.radius;

  }, 30);

  // Bolinhas sendo sugadas
  for(let i=0;i<60;i++){

    setTimeout(()=>{

      if(gameOver || !inTurn) return;

      const angle = Math.random() * Math.PI * 2;

      const dist = 260 + Math.random()*120;

      const sx = cx + Math.cos(angle)*dist;
      const sy = cy + Math.sin(angle)*dist;

      mkProj(
        sx,
        sy,
        0,
        0,
        12,
        12,
        "orb-purple",
        {
          vortex:true
        }
      );

    }, i*90);

  }

  return {
    label:"BURACO NEGRO",
    dialogue:"* A cidade colapsa sobre si mesma!",
    color:"#aa44ff",
    duration:8000
  };
}
function atkHoming() {
  bossSetAnim("summon", 4800);
  const count = phaseIdx < 2 ? 3 + phaseIdx * 2 : 6;      // was up to 7
  const sp = (3 + phaseIdx * 0.5) * M();
  const cls = ["orb", "orb-cyan", "orb-purple"];
  for (let i = 0; i < count; i++) {
    setTimeout(() => {
      if (gameOver || !inTurn) return;
      const edges = [[-22, Math.random() * BH], [BW + 22, Math.random() * BH],
      [Math.random() * BW, -22], [Math.random() * BW, BH + 22]];
      const [sx, sy] = edges[Math.random() * 4 | 0];
      mkProj(sx, sy, 0, 0, 18, 18, cls[i % cls.length], { homing: true, homSpd: sp });
    }, i * 550);
  }
  return { label: "ORBS PENDENTES", dialogue: "* Não tem onde correr", color: "#ff2244", duration: 5500 };
}

function atkKnives() {
  bossSetAnim("summon", 4800);
  const count = phaseIdx < 2 ? 30 + phaseIdx * 12 : 44;    // was up to 32
  for (let i = 0; i < count; i++) {
    setTimeout(() => {
      if (gameOver || !inTurn) return;
      mkProj(Math.random() * BW, -14, (Math.random() - .5) * 1.8, (5 + Math.random() * 3) * M(), 5, 24, "knife", { spin: 7 + Math.random() * 6 });
    }, i * 60);
  }
  // Side knives phase 2+; cap at 5 in phase 3 and alternate sides
  if (phaseIdx >= 1) {
    const sc = phaseIdx < 2 ? 8 : 5;
    setTimeout(() => {
      for (let i = 0; i < sc; i++) {
        setTimeout(() => {
          if (gameOver || !inTurn) return;
          const y = i * (BH / sc) + 20;
          mkProj(-14, y, (4 + Math.random() * 2) * M(), (Math.random() - .5) * 1.5, 24, 5, "knife", { spin: 5 });
          if (phaseIdx < 2)  // right side only in phase 2 (not both in p3)
            mkProj(BW + 14, y + 40, -(4 + Math.random() * 2) * M(), (Math.random() - .5) * 1.5, 24, 5, "knife", { spin: -5 });
        }, i * 130);
      }
    }, 1400);
  }
  return { label: "CHUVA ÁCIDA", dialogue: "* Derreta!", color: "#ccddee", duration: 4500 };
}

function atkBurst8() {
  bossSetAnim("angry", 3200);
  bossCharge("#ff2244", 500);
  const doWave = (off = 0) => {
    for (let i = 0; i < 8; i++) {
      const a = (Math.PI * 2 * i / 8) + off;
      const sp = (5 + phaseIdx * 0.55) * M();
      mkProj(BW / 2 - 7, BH / 2 - 7, Math.cos(a) * sp, Math.sin(a) * sp, 14, 14,
        ["orb", "orb-cyan", "orb-purple", "orb-yellow"][i % 4]);
    }
  };
  const waves = phaseIdx < 2 ? 3 + phaseIdx * 2 : 5;   // was up to 7
  for (let w = 0; w < waves; w++) {
    setTimeout(() => {
      if (gameOver || !inTurn) return;
      doWave(w * 0.22);
      // Corner orbs only in phase 2 (too oppressive in p3)
      if (phaseIdx === 1 && w % 2 === 0) {
        [[0, 0], [BW, 0], [0, BH], [BW, BH]].forEach(([cx, cy]) => {
          const a = Math.atan2(BH / 2 - cy, BW / 2 - cx);
          mkProj(cx, cy, Math.cos(a) * 3 * M(), Math.sin(a) * 3 * M(), 20, 20, "orb-purple");
        });
      }
    }, w * 720);
  }
  return { label: "EXPLOSÃO", dialogue: "* KABOOM!", color: "#ff4466", duration: 5500 };
}

function atkColumns() {
  bossSetAnim("summon", 4800);
  const waves = phaseIdx < 2 ? 2 + phaseIdx : 3;
  const cols = 7;                                    // fixed (was 7+phaseIdx → very narrow)
  const minGap = 3;                                    // always 3 open columns
  for (let w = 0; w < waves; w++) {
    setTimeout(() => {
      if (gameOver || !inTurn) return;
      const gap = new Set();
      while (gap.size < minGap) gap.add(Math.random() * cols | 0);
      const cw = Math.floor(BW / cols);
      const sp = (4.5 + phaseIdx * 0.55) * M();
      for (let i = 0; i < cols; i++) {
        if (!gap.has(i)) mkProj(i * cw + 4, -40, 0, sp, cw - 8, 50, "bone");
      }
      // Bottom-up only in phases 1-2, never both simultaneously in p3
      if (w % 2 === 1 && phaseIdx < 2) {
        const g2 = new Set();
        while (g2.size < 2) g2.add(Math.random() * cols | 0);
        for (let i = 0; i < cols; i++) {
          if (!g2.has(i)) setTimeout(() => {
            if (gameOver || !inTurn) return;
            mkProj(i * cw + 4, BH + 10, 0, -sp, cw - 8, 50, "bone");
          }, 500);
        }
      }
    }, w * 2200);
  }
  return { label: "COLUNAS", dialogue: "* Desvie se conseguir!", color: "#ddeeff", duration: 6500 };
}

function atkStarfall() {
  bossSetAnim("summon", 5200);
  const count = phaseIdx < 2 ? 18 + phaseIdx * 10 : 28;   // was up to 38
  const cls = ["star", "star-red", "star-cyan", "star"];
  for (let i = 0; i < count; i++) {
    setTimeout(() => {
      if (gameOver || !inTurn) return;
      const sz = 16 + (Math.random() * 10 | 0);
      mkProj(Math.random() * BW, -16, (Math.random() - .5) * 1.5, (3.5 + Math.random() * 2) * M(), sz, sz,
        cls[i % cls.length], { spin: 4 + Math.random() * 7 });
    }, i * 70);
  }
  // Side stars: one side only in phase 3
  if (phaseIdx >= 1) {
    setTimeout(() => {
      const sc = phaseIdx < 2 ? 5 : 4;
      for (let i = 0; i < sc; i++) {
        setTimeout(() => {
          if (gameOver || !inTurn) return;
          mkProj(-20, i * (BH / sc) + 30, (2.5 + Math.random()) * M(), (Math.random() - .5) * 2, 22, 22, "star-red", { spin: 3 });
          if (phaseIdx < 2)
            mkProj(BW + 20, i * (BH / sc) + 30, -(2.5 + Math.random()) * M(), (Math.random() - .5) * 2, 22, 22, "star-cyan", { spin: -3 });
        }, i * 200);
      }
    }, 2000);
  }
  return { label: "QUEDA DE PONTUAÇÃO", dialogue: "* Estrelas também Caem!", color: "#ffee00", duration: 6000 };
}

function atkRingWave() {
  bossSetAnim("cast", 5200);
  bossCharge("#ffee00", 500);
  const rings = phaseIdx < 2 ? 3 + phaseIdx : 4;
  for (let r = 0; r < rings; r++) {
    setTimeout(() => {
      if (gameOver || !inTurn) return;
      const n = 12 + phaseIdx * 3;
      const sp = (3.5 + phaseIdx * 0.38 + r * 0.28) * M();
      const cls = ["orb", "orb-yellow", "orb-cyan", "orb-purple"];
      for (let i = 0; i < n; i++) {
        const a = (Math.PI * 2 * i / n) + r * 0.15;
        mkProj(BW / 2 - 6, BH / 2 - 6, Math.cos(a) * sp, Math.sin(a) * sp, 11, 11, cls[(r + i) % cls.length]);
      }
    }, r * 1000);
  }
  return { label: "CIRCULO URBANISTA", dialogue: "* Expansão!", color: "#ffdd00", duration: 6000 };
}

function atkBlaster() {
  bossSetAnim("cast", 5500);
  bossCharge("#cc22ff", 720);
  const shots = phaseIdx < 2 ? 2 + phaseIdx : 3;
  for (let s = 0; s < shots; s++) {
    setTimeout(() => {
      if (gameOver || !inTurn) return;
      const isH = Math.random() > 0.4;
      if (isH) {
        const yp = 45 + Math.random() * (BH - 90);
        warnH(yp - 16, 38, 420, "warn-p");
        setTimeout(() => {
          if (!gameOver && inTurn) mkProj(0, yp - 12, 0, 0, BW, 25, "laser-purple", { laser: true, life: 1200 });
        }, 1000);
      } else {
        const xp = 45 + Math.random() * (BW - 90);
        warnV(xp - 16, 38, 420, "warn-p");
        setTimeout(() => {
          if (!gameOver && inTurn) mkProj(xp - 12, 0, 0, 0, 25, BH, "laser-purple", { laser: true, life: 1200 });
        }, 1000);
      }
      const orbN = phaseIdx < 2 ? 6 : 4;
      for (let d = 0; d < orbN; d++) {
        setTimeout(() => {
          if (!gameOver && inTurn) {
            const a = Math.random() * Math.PI * 2;
            mkProj(BW / 2, BH / 2, Math.cos(a) * 2.5 * M(), Math.sin(a) * 2.5 * M(), 20, 20, "orb-purple");
          }
        }, 1020 + d * 90);
      }
    }, s * 1700);
  }
  return { label: "RAIO", dialogue: "* FOGO!", color: "#cc22ff", duration: 6500 };
}

function atkCross() {
  bossSetAnim("cast", 5200);
  bossCharge("#ff2244", 720);
  const rounds = phaseIdx < 2 ? 2 + phaseIdx : 3;
  for (let r = 0; r < rounds; r++) {
    setTimeout(() => {
      if (gameOver || !inTurn) return;
      const lcx = BW / 2 + (Math.random() - .5) * 100;
      const lcy = BH / 2 + (Math.random() - .5) * 70;
      warnH(lcy - 16, 38, 380, "warn-r");
      warnV(lcx - 16, 38, 380, "warn-r");
      setTimeout(() => {
        if (!gameOver && inTurn) {
          mkProj(0, lcy - 12, 0, 0, BW, 25, "laser", { laser: true, life: 1200 });
          mkProj(lcx - 12, 0, 0, 0, 25, BH, "laser", { laser: true, life: 1200 });
        }
      }, 950);
      // Ring burst only in phases 1-2
      if (phaseIdx < 2) {
        setTimeout(() => {
          if (!gameOver && inTurn) {
            for (let i = 0; i < 12; i++) {
              const a = Math.PI * 2 * i / 12;
              mkProj(lcx, lcy, Math.cos(a) * 2.5 * M(), Math.sin(a) * 2.5 * M(), 24, 24, "orb");
            }
          }
        }, 980);
      }
    }, r * 2100);
  }
  return { label: "SAÚDE PRECÁRIA", dialogue: "* Vai esperar na fila!", color: "#ff2244", duration: 6500 };
}

function atkSweep() {
  bossSetAnim("cast", 5500);
  bossCharge("#2255ff", 720);
  const cls = ["laser-blue", "laser-blue", "laser-blue", "laser-blue"];
  
  // Aumentado o número de ondas (ex: fase 0 = 4, fase 1 = 5, fase 2+ = 8)
  const sweeps = phaseIdx < 2 ? 4 + phaseIdx : 8; 

  for (let s = 0; s < sweeps; s++) {
    // Reduzido o intervalo de 4000ms para 1500ms para acumular mais ataques
    setTimeout(() => {
      if (gameOver || !inTurn) return;
      const fromTop = s % 2 === 0;
      
      // Velocidade base do laser aumentada de 2.4 para 4.0
      const sv = (fromTop ? 4.0 : -4.0) * (1 + phaseIdx * 0.25) * M(); 
      
      mkProj(0, fromTop ? -16 : BH + 2, 0, sv, BW, 22, "laser-blue", {
        laser: true,
        blueBone: true,
        life: 3200, // Reduzido o tempo de vida já que eles cruzam a tela mais rápido
        sweep: sv
      });

      // Reduzido o delay de 950ms para 500ms para os ossos virem colados no laser
      setTimeout(() => {
        if (!gameOver && inTurn) {
          const gap1 = Math.floor(Math.random() * 8);
          let gap2;
          do {
            gap2 = Math.floor(Math.random() * 8);
          } while (gap2 === gap1);

          const gapSet = new Set([gap1, gap2]);
          for (let i = 0; i < 8; i++) {
            if (!gapSet.has(i))
              // Aumentado o multiplicador de velocidade dos ossos de sv * 1.3 para sv * 1.6
              mkProj(i * (BW / 8) + 4, fromTop ? -28 : BH + 10, 0, sv * 1.1, BW / 8 - 8, 48, "bone");
          }
        }
      }, 700); 
    }, s * 1500); 
  }
  
  // Mantive a duração original de 6800, mas agora com 8 ondas espremidas dentro dela
  return { label: "ALAGAMENTO", dialogue: "* Nade, se conseguir!", color: "#2255ff", duration: 6800 };
}

function atkTelegraph() {
  bossSetAnim("angry", 5200);
  bossCharge("#ff8800", 720);
  const shots = phaseIdx < 2 ? 5 + phaseIdx * 3 : 7;   // was up to 11
  const interval = phaseIdx < 2 ? 600 - phaseIdx * 60 : 560;
  for (let i = 0; i < shots; i++) {
    setTimeout(() => {
      if (gameOver || !inTurn) return;
      const dx = px - BW / 2, dy = py - BH / 2;
      const d = Math.sqrt(dx * dx + dy * dy) || 1;
      const sp = (6 + phaseIdx * 0.65) * M();
      mkProj(BW / 2, BH / 2, (dx / d) * sp, (dy / d) * sp, 24, 24, "orb");
      // Flanking only phase 2 (in phase 3 it becomes unavoidable triples)
      if (phaseIdx === 1) {
        const perp = [-dy / d, dx / d];
        const ss = (3 + phaseIdx) * M();
        mkProj(BW / 2, BH / 2, perp[0] * ss, perp[1] * ss, 21, 21, "orb-purple");
        mkProj(BW / 2, BH / 2, -perp[0] * ss, -perp[1] * ss, 21, 21, "orb-cyan");
      }
    }, i * interval);
  }
  return { label: "RELATÓRIOS PRECISOS", dialogue: "* Prevejo sua ruína!", color: "#ff8800", duration: 6000 };
}

function atkVortex() {
  bossSetAnim("cast", 5500);
  bossCharge("#cc22ff", 820);
  let angle = 0, fired = 0;
  const total = phaseIdx < 2 ? 70 + phaseIdx * 20 : 80;   // was up to 110
  const maxSpeed = phaseIdx < 2 ? 999 : 7.5;              // cap speed in p3
  const iv = setInterval(() => {
    if (gameOver || !inTurn || fired >= total) { clearInterval(iv); return; }
    const sp = Math.min(maxSpeed, (2.2 + fired * 0.03) * M());
    const cl1 = fired % 3 === 0 ? "orb-purple" : fired % 3 === 1 ? "orb-cyan" : "gaster";
    mkProj(BW / 2, BH / 2, Math.cos(angle) * sp, Math.sin(angle) * sp, 11, 11, cl1);
    mkProj(BW / 2, BH / 2, Math.cos(-angle + 1) * sp * .8, Math.sin(-angle + 1) * sp * .8, 19, 19, "orb");
    if (phaseIdx >= 2 && fired % 2 === 0)   // third arm every other shot (not every shot)
      mkProj(BW / 2, BH / 2, Math.cos(angle + 2.1) * sp * .9, Math.sin(angle + 2.1) * sp * .9, 18, 18, "orb-purple");
    angle += 0.28; fired++;
  }, 34);
  return { label: "INFiNITO", dialogue: "* VÓRTICE De Informação!", color: "#aa00ff", duration: 6500 };
}

function atkChaos() {
  // Phase 3 simplified: staggered patterns with breathing room
  bossSetAnim("angry", 5800);
  bossCharge("#ffffff", 950);

  // T=200ms — ring burst
  setTimeout(() => {
    if (!gameOver && inTurn) {
      for (let i = 0; i < 16; i++) {
        const a = Math.PI * 2 * i / 16;
        mkProj(BW / 2, BH / 2, Math.cos(a) * 4 * M(), Math.sin(a) * 4 * M(), 23, 23, i % 2 === 0 ? "orb" : "orb-cyan");
      }
    }
  }, 200);
  // T=1300ms — knife curtain, alternating rows so half the screen is clear
  setTimeout(() => {
    if (!gameOver && inTurn) {
      for (let i = 0; i < 6; i++) {
        mkProj(-14, i * 100 + 20, (4 + Math.random()) * M(), 0, 24, 10, "knife", { spin: 8 });
        mkProj(BW + 14, i * 100 + 70, -(4 + Math.random()) * M(), 0, 24, 10, "knife", { spin: -8 });
      }
    }
  }, 1300);
 // T=2500ms — single laser with extra-long warning (1100ms instead of 950)
setTimeout(() => {
  if (!gameOver && inTurn) {
    let yp;

    // 50% de chance de ser no centro, 50% de chance de ser nos extremos
    if (Math.random() < 0.5) {
      // 1. ATAQUE NO CENTRO da arena (com uma leve variação de até 20px para cima ou para baixo)
      const variacaoCentro = (Math.random() - 0.5) * 40; // entre -20 e +20
      yp = (BH / 2) + variacaoCentro;
    } else {
      // 2. ATAQUE NOS EXTREMOS (5% superiores ou 5% inferiores da arena)
      const margemExtremo = 60; // distância segura do limite da arena para o laser não sumir para fora
      
      if (Math.random() < 0.5) {
        // Extremo Superior (Topo)
        yp = margemExtremo + Math.random() * 30; // Garante que nasce colado no topo
      } else {
        // Extremo Inferior (Base)
        yp = (BH - margemExtremo) - Math.random() * 30; // Garante que nasce colado na base
      }
    }

    warnH(yp - 18, 230, 300, "warn-p");
    setTimeout(() => {
      if (!gameOver && inTurn)
        mkProj(0, yp - 14, 0, 0, BW, 230, "laser-purple", { laser: true, life: 1500 });
    }, 880);
  }
}, 2500);

return { label: "☠ CHAOS URBANO ☠", dialogue: "* CAOS TOTAL AGORA!", color: "#ffffff", duration: 8800 };
}
// ═══════════════════════════════════════════════════════════════════
//  SURVIVA — BOSS FINAL ATTACK
// ═══════════════════════════════════════════════════════════════════
function triggerSurviva() {
  clearTimeout(turnTimer);
  clearAllProjs();
  bossHp = 1;
  bossSetAnim("angry", 999999);
  bossCharge("#ffffff", 1000);
  showBanner("★ SOBREVIVA ★", "#ffffff");
  say("* VOCÊ NÃO MERECE VENCER... PEREÇA!");

  const queue = [
    () => { const i = atkBoneRain();   return i.duration || 4500; },
    () => { const i = atkSpiral();     return i.duration || 5000; },
    () => { const i = atkHoming();     return i.duration || 5000; },
    () => { const i = atkBoneWall();   return i.duration || 4500; },
    () => { const i = atkKnives();     return i.duration || 4500; },
    () => { const i = atkVortex();     return i.duration || 4500; },
    () => { const i = atkBluePlatform();   return i.duration || 4500; },
    () => { const i = atkLaser();     return i.duration || 4500; },
    () => { const i = atkBurst8();     return i.duration || 4500; },
    () => { const i = atkBlackHole();  return i.duration || 6000; },
    () => { const i = atkChaos();      return i.duration || 8000; },
  ];

  let delay = 1200;
  for (const fn of queue) {
    setTimeout(() => {
      if (gameOver && !waitingLastShot) return;
      clearAllProjs();
      const dur = fn();
      // intensify glitch during surviva
      _survivaGlitch = true;
    }, delay);
    delay += (fn.name === "atkChaos" ? 8800 : 5200);
  }

  // After all attacks: boss stands still, waiting
  setTimeout(() => {
    if (gameOver && !waitingLastShot) return;
    clearAllProjs();
    clearInterval(_blueScrollIv);
    blueMode = false;
    _survivaGlitch = false;
    bossSetAnim("hurt", 999999);
    bossHp = 1;
    bossDefeated = false;
    waitingLastShot = true;
    say("* ...acabou...");
    showBanner("ÚLTIMO GOLPE", "#ff1133");
    // Mercy timer — same as in hitBoss
    clearTimeout(_mercyTimer);
    _mercyTimer = setTimeout(() => { if (waitingLastShot && !gameOver) triggerMercyEnding(); }, 10000);
  }, delay + 500);
}

let _survivaGlitch = false;

// ═══════════════════════════════════════════════════════════════════
//  COLLISION  (tight hitbox centered on sprite)
// ═══════════════════════════════════════════════════════════════════
function hits(ax, ay, aw, ah) {
  const hx = px + (40 - PW) / 2, hy = py + (40 - PH) / 2;
  return ax < hx + PW && ax + aw > hx && ay < hy + PH && ay + ah > hy;
}

// ═══════════════════════════════════════════════════════════════════
//  DAMAGE
// ═══════════════════════════════════════════════════════════════════
function damage(amt) {
  if (iframes > 0) return;
  hp = Math.max(0, hp - amt);
  const pct = hp / MAX_HP * 100;
  hpFill.style.width = pct + "%";
  hpFill.style.background = hp > MAX_HP * .6 ? "#007bff" : hp > MAX_HP * .3 ? "#45009e" : "#ff1133";
  hpText.textContent = hp + " / " + MAX_HP;
  playerEl.classList.remove("hit"); void playerEl.offsetWidth; playerEl.classList.add("hit");
  flashEl.style.opacity = "1";
  setTimeout(() => { flashEl.style.opacity = "0"; }, 90);
  burst(px + 20, py + 20, "#ff2244", 8);
  iframes = 38;
  boss.tiltTarget += (Math.random() - .5) * 0.22;
  setTimeout(() => { boss.tiltTarget = ARM_TARGETS[boss.anim]?.tilt ?? 0; }, 300);
  tauntHit();
}

// ═══════════════════════════════════════════════════════════════════
//  PHASE TRANSITION
// ═══════════════════════════════════════════════════════════════════
function checkPhase() {
  // Phase transitions based on BOSS HP (not time)
  const third = BOSS_MAX_HP / 3;
  const np = bossHp <= third ? 2 : bossHp <= third * 2 ? 1 : 0;

  if (np !== phaseIdx && !bossDefeated) {
    phaseIdx = np;

    // Swap boss image
    bossImg.src = bossImgs[np];

    const ph = PHASES[np];
    phaseTag.textContent = ph.label;
    phaseTag.style.color = ph.color;
    battleBox.style.borderColor = ph.color;

    drawArenaBg();
    clearTimeout(turnTimer);
    clearAllProjs();

    showBanner("FASE " + ["I", "II", "III"][np] + "!", ph.color);
    say(["* AGORA É PRA VALER!", "* VEJA O PODER DO CHAOS! ★"][np - 1] || "...");

    setTimeout(() => {
      if (!gameOver) startTurn();
    }, 1800);
  }
}

// ═══════════════════════════════════════════════════════════════════
//  PLAYER MOVEMENT
// ═══════════════════════════════════════════════════════════════════
function movePlr() {
  const up = keys["w"] || keys["arrowup"], dn = keys["s"] || keys["arrowdown"];
  const lt = keys["a"] || keys["arrowleft"], rt = keys["d"] || keys["arrowright"];
  if (up) py -= baseSpd; if (dn) py += baseSpd;
  if (lt) px -= baseSpd; if (rt) px += baseSpd;
  px = Math.max(0, Math.min(BW - 40, px));
  py = Math.max(0, Math.min(BH - 40, py));
  playerEl.style.left = px + "px"; playerEl.style.top = py + "px";
  if ((up || dn || lt || rt) && ++trailTick % 1 === 0) spawnTrail();
}

// ═══════════════════════════════════════════════════════════════════
//  PLAYER SHOOTING
// ═══════════════════════════════════════════════════════════════════
const playerBullets = [];
let _shootCooldown = '0';
let _lastShootKey = false;

function shootBullet() {
  const el = document.createElement("div");
  el.style.cssText = "position:absolute;width:8px;height:8px;border-radius:50%;background:#007bff;box-shadow:0 0 8px #007bff,0 0 16px #007bffaa;z-index:11;pointer-events:none;";
  el.style.left = (px + 16) + "px";
  el.style.top  = (py + 16) + "px";
  battleBox.appendChild(el);
  playerBullets.push({ el, x: px + 16, y: py + 16, vx: 0, vy: -11 });
}

function updatePlayerBullets() {
  if (blueMode) return; // no shooting in blue mode
  // Shoot on Z or Space, single press
  const shooting = keys["z"] || keys[" "];
  if (shooting && _shootCooldown <= 0 && !waitingLastShot) {
    shootBullet();
    _shootCooldown = 23; //shot cooldown
  }
  // Waiting last shot: shoot once, then boss dies
  if (waitingLastShot && !_mercyEnding && shooting && !_lastShootKey) {
    clearTimeout(_mercyTimer);
    shootBullet();
    _shootCooldown = 99;
  }
  _lastShootKey = !!shooting;
  if (_shootCooldown > 0) _shootCooldown--;

  for (let i = playerBullets.length - 1; i >= 0; i--) {
    const b = playerBullets[i];
    b.y += b.vy;
    b.el.style.top = b.y + "px";



    // Hit boss sprite — boss is drawn centered at (300, 75) in boss-area
    // boss-area is 150px above battle-box, so in battle-box coords: y = -150+75 = -75 center
    // sprite is 90px tall → top=-120, bottom=-30; x: 255..345 (accounting for bossMove.x)
    const bSprLeft  = 255 + bossMove.x - 10;
    const bSprRight = 345 + bossMove.x + 10;
    const bSprTop   = -125;
    const bSprBot   = -25;
    const inBossX = b.x >= bSprLeft && b.x <= bSprRight;
    const inBossY = b.y >= bSprTop  && b.y <= bSprBot;

    if (inBossX && inBossY && !bossDefeated) {
      // Orange explosion on boss sprite (in boss-area canvas coords)
      const hitX = CW / 2 + bossMove.x + (Math.random() - 0.5) * 40;
      const hitY = CH / 2 + (Math.random() - 0.5) * 30;
      bossOrangeExplosion(hitX, hitY);
      b.el.remove();
      playerBullets.splice(i, 1);
      hitBoss(waitingLastShot ? BOSS_MAX_HP : 1);
      continue;
    }

    // Soft homing: nudge bullet toward boss when close
    const bossCX = 300 + bossMove.x; // boss center X in battle-box coords
    const bossCY = -75;              // boss center Y in battle-box coords
    const hdx = bossCX - b.x, hdy = bossCY - b.y;
    const hdist = Math.sqrt(hdx * hdx + hdy * hdy);
    if (hdist < 160 && hdist > 1) {
      b.vy += (hdy / hdist) * 0.55;
      b.vx += (hdx / hdist) * 0.35;
      // clamp speed
      const spd = Math.sqrt(b.vx * b.vx + b.vy * b.vy);
      if (spd > 13) { b.vx = b.vx / spd * 13; b.vy = b.vy / spd * 13; }
    }
    b.x += b.vx || 0;
    b.el.style.left = b.x + "px";

    // Remove if too far above
    if (b.y < -160) {
      b.el.remove();
      playerBullets.splice(i, 1);
      continue;
    }
  }
}

function hitBoss(dmg) {
  if (bossDefeated) return;
  // Locked at 1 HP during surviva — no extra damage until attack ends
  if (survivaUsed && !waitingLastShot && bossHp <= 1) return;
  bossHp = Math.max(0, bossHp - dmg);
  const pct = bossHp / BOSS_MAX_HP * 100;
  bossHpFill.style.width = pct + "%";
  bossHpFill.style.background = bossHp > BOSS_MAX_HP * .66 ? "#cc22ff" : bossHp > BOSS_MAX_HP * .33 ? "#ff7700" : "#ff1133";
  bossHpText.textContent = "AGRAVANTE  " + bossHp + " / " + BOSS_MAX_HP;

  // Boss hurt reaction
  boss.shakeX = (Math.random() - .5) * 14;
  boss.shakeY = (Math.random() - .5) * 8;
  setTimeout(() => { boss.shakeX = 0; boss.shakeY = 0; }, 120);

  // Check boss phase transitions based on HP
  const third = BOSS_MAX_HP / 3;
  const newBossPhase = bossHp <= 0 ? 3 : bossHp <= third ? 2 : bossHp <= third * 2 ? 1 : 0;

  if (newBossPhase !== bossPhase && newBossPhase < 3) {
    bossPhase = newBossPhase;
    startTabCycle(bossPhase);
    bossSetAnim("hurt", 1200);
    say(["* ...o quê?", "* IMPOSSÍVEL!"][bossPhase - 1] || "...");
  }

  // Surviva trigger: 1/100 hp
  if (!survivaUsed && bossHp <= BOSS_MAX_HP / 100) {
    survivaUsed = true;
    triggerSurviva();
    return;
  }

  // Boss dead — enter "waiting last shot" state (bossDefeated stays false until actual kill)
  if (bossHp <= 0 && !bossDefeated && !waitingLastShot) {
    clearTimeout(turnTimer);
    clearAllProjs();
    waitingLastShot = true;
    bossMove.targetX = 0;   // freeze lateral movement to center
    bossSetAnim("kneeling", 999999);
    say("* ...eu... eu não posso perder...");
    showBanner("ÚLTIMO GOLPE", "#ff1133");
    // Mercy ending — if player doesn't shoot for 10 seconds
    _mercyTimer = setTimeout(() => { if (waitingLastShot && !gameOver) triggerMercyEnding(); }, 10000);
    bossHp = 1; // keep alive until final shot
    bossHpFill.style.width = "0.3%";
  }
}

// ── MERCY ENDING ────────────────────────────────────────────────────
function triggerMercyEnding() {
  _mercyEnding = true;
  waitingLastShot = false;
  clearInterval(_tabPhaseIv);
  bossPhase = -1; // stop index.html title interval (it only runs when bossPhase >= 2)
  document.title = "Parabéns";
  clearTimeout(turnTimer);
  clearAllProjs();
  bossDefeated = true; // stop bullet hits

  // Freeze movement
  bossMove.targetX = 0;
  bossMove.x = 0;
  // Reset to phase 1 image and colors
  bossImg.src = bossImgs[0];
  phaseIdx = 0;
  phaseTag.textContent = PHASES[0].label;
  phaseTag.style.color = PHASES[0].color;
  // Dialogue sequence
  bossSetAnim("farewell", 999999);
  say("* ...você não atirou.");
  showBanner("FIM ALTERNATIVO", "#007bff");

  setTimeout(() => say("* ...obrigado. Você poderia ter me destruído."), 2200);
  setTimeout(() => say("* Talvez... eu possa ter uma segunda chance."), 4800);
  setTimeout(() => {
    say("* Até a próxima, Agente.");
    _bossRetreating = true;
  }, 7200);

  setTimeout(() => {
    gameOver = true; // stop loop only after animation is done
    document.getElementById("death-title").textContent = "FIM ALTERNATIVO";
    document.getElementById("death-title").style.color = "#007bff";
    document.getElementById("death-quote").innerHTML = "Você poupou o <b style='color:#cc22ff'>AGRAVANTE</b>.";
    document.getElementById("death-hint").textContent = "[ R — JOGAR NOVAMENTE ]";
    deathStats.textContent = "Sobreviveu por " + elapsed + "s — e escolheu a misericórdia.";
    deathScr.classList.add("show");
  }, 10500);
}

// ── FINAL KILL ────────────────────────────────────────────────────────
function bossKilled() {
  bossDefeated = true;
  waitingLastShot = false;
  gameOver = true;
  clearInterval(_tabPhaseIv);
  bossPhase = -1; // stop index.html title interval
  document.title = "Parabéns";
  clearTimeout(turnTimer);
  clearAllProjs();
  bossHpFill.style.width = "0%";
  bossHpText.textContent = "AGRAVANTE  0 / " + BOSS_MAX_HP;

  // ── EXPLOSION ──────────────────────────────────────────────────
  // Multi-wave burst on boss-area canvas
  let expWave = 0;
  const expColors = ["#cc22ff","#ff7700","#ffee00","#ffffff","#ff1133"];
  const expIv = setInterval(() => {
    const col = expColors[expWave % expColors.length];
    // Draw explosion rings on boss canvas
    bCtx.save();
    bCtx.globalAlpha = 0.85;
    bCtx.strokeStyle = col;
    bCtx.lineWidth = 4 + expWave;
    bCtx.shadowColor = col;
    bCtx.shadowBlur = 24;
    const r = 20 + expWave * 18;
    bCtx.beginPath();
    bCtx.arc(CW / 2 + bossMove.x, CH / 2, r, 0, Math.PI * 2);
    bCtx.stroke();
    bCtx.restore();
    // Burst particles in battle-box at top
    burst(px + 20, 10, col, 10);
    expWave++;
    if (expWave >= 7) clearInterval(expIv);
  }, 120);

  // Flash screen white
  flashEl.style.background = "rgba(255,255,255,0.7)";
  flashEl.style.opacity = "1";
  setTimeout(() => { flashEl.style.opacity = "0"; flashEl.style.background = "rgba(255,0,0,0.16)"; }, 400);

  bossSetAnim("hurt", 999999);
  say("* ...você venceu... A cidade... está salva.");
  showBanner("VITÓRIA!", "#ffee00");

  setTimeout(() => {
    deathStats.textContent = "Você venceu em " + elapsed + "s!";
    document.getElementById("death-title").textContent = "VITÓRIA!";
    document.getElementById("death-title").style.color = "#ffee00";
    document.getElementById("death-quote").innerHTML = "A cidade está <b style='color:#007bff'>SALVA</b>.";
    document.getElementById("death-hint").textContent = "[ R — JOGAR NOVAMENTE ]";
    deathScr.classList.add("show");
  }, 2800);
}

// ═══════════════════════════════════════════════════════════════════
//  UPDATE PROJECTILES
// ═══════════════════════════════════════════════════════════════════
function updateProjs(dt) {
  for (let i = projs.length - 1; i >= 0; i--) {
    const p = projs[i];
    if (p.blackHole) {

  p.life -= dt;

  if (p.life <= 0) {
    p.el.remove();
    projs.splice(i,1);
    continue;
  }

  const dx = p.x + p.w/2 - (px+20);
  const dy = p.y + p.h/2 - (py+20);

  const dist = Math.sqrt(dx*dx + dy*dy);

  if (dist > 1) {

    const force = Math.max(
      0.15,
      7 / Math.max(1, dist/40)
    );

    px += dx/dist * force;
    py += dy/dist * force;
  }
}
if (p.vortex) {

  const dx = BW/2 - p.x;
  const dy = BH/2 - p.y;

  const dist = Math.sqrt(dx*dx + dy*dy) || 1;

  p.vx += dx/dist * 0.15;
  p.vy += dy/dist * 0.15;

  const max = 7;

  const sp = Math.sqrt(
    p.vx*p.vx +
    p.vy*p.vy
  );

  if (sp > max) {
    p.vx = p.vx/sp * max;
    p.vy = p.vy/sp * max;
  }

  if (dist < 35) {
    burst(p.x,p.y,"#aa44ff",4);
    p.el.remove();
    projs.splice(i,1);
    continue;
  }
}
    if (p.homing) {
      const dx = px - p.x, dy = py - p.y, d = Math.sqrt(dx * dx + dy * dy) || 1;
      p.vx += dx / d * 0.18; p.vy += dy / d * 0.18;
      const sp = Math.sqrt(p.vx * p.vx + p.vy * p.vy);
      if (sp > p.homSpd) { p.vx = p.vx / sp * p.homSpd; p.vy = p.vy / sp * p.homSpd; }
    }
    if (p.sweep !== undefined) p.vy = p.sweep;
    if (p.laser) {
      p.life -= dt;
      if (p.life <= 0) { p.el.remove(); projs.splice(i, 1); continue; }
    }
    if (p.spin) { p.rot = (p.rot || 0) + p.spin; p.el.style.transform = `rotate(${p.rot}deg)`; }
    p.x += p.vx; p.y += p.vy;
    p.el.style.left = p.x + "px"; p.el.style.top = p.y + "px";
    if (hits(p.x,p.y,p.w,p.h) && !p.hcd) {

  // Lasers azuis e ossos azuis
  if (p.blueBone) {

    const mv =
      keys["w"] || keys["s"] ||
      keys["a"] || keys["d"] ||
      keys["arrowup"] ||
      keys["arrowdown"] ||
      keys["arrowleft"] ||
      keys["arrowright"];

    // parado = sem dano
    if (!mv) continue;
  }

  damage(PHASES[phaseIdx].dmg);

  p.hcd = true;
  setTimeout(() => {
    p && (p.hcd = false);
  }, 300);
}
    if (!p.laser && (p.x < -180 || p.x > BW + 180 || p.y < -180 || p.y > BH + 180)) {
      p.el.remove(); projs.splice(i, 1);
    }
  }
}

// ═══════════════════════════════════════════════════════════════════
//  INPUT
// ═══════════════════════════════════════════════════════════════════
document.addEventListener("keydown", e => { keys[e.key.toLowerCase()] = true; e.preventDefault(); });
document.addEventListener("keyup", e => { keys[e.key.toLowerCase()] = false; });
document.addEventListener("keydown", e => { if (e.key.toLowerCase() === "r" && gameOver) location.reload(); });

// ═══════════════════════════════════════════════════════════════════
//  GAME LOOP
// ═══════════════════════════════════════════════════════════════════
let lastTs = 0;
function gameLoop(ts) {
  if (gameOver && !_mercyEnding) return;
  const dt = Math.min(ts - lastTs, 50);
  lastTs = ts;
  if (iframes > 0) iframes--;

  // Wait for player to move before counting time and starting fight
  if (_waitingForPlayerMove) {
    const anyKey = keys["w"] || keys["s"] || keys["a"] || keys["d"] ||
      keys["arrowup"] || keys["arrowdown"] || keys["arrowleft"] || keys["arrowright"] ||
      keys["z"] || keys[" "];
    if (anyKey) {
      _waitingForPlayerMove = false;
      window._startTs = ts;
      setTimeout(() => { startTurn(); }, 200);
    }
    // Still render boss entrance while waiting
    drawBossFrameWithEntrance(ts);
    requestAnimationFrame(gameLoop);
    return;
  }

  elapsed = ((ts - (window._startTs || ts)) / 1000) | 0;
  clockEl.textContent = elapsed + "s";
  checkPhase();
  if (blueMode) {
    moveBlueSoul();
  } else {
    movePlr();
  }
  updateProjs(dt);
  updatePlayerBullets();
  drawBossFrameWithEntrance(ts);   // boss on canvas, zero DOM cost

  // Final kill check — bullet was shot and cleared the screen
  if (!_mercyEnding && waitingLastShot && _shootCooldown > 50 && playerBullets.length === 0) {
    bossKilled();
    return;
  }

  if (!_mercyEnding && hp <= 0) {
    gameOver = true;
    clearInterval(_tabPhaseIv);
    bossPhase = -1; // stop index.html title interval
    document.title = "Não Desista";
    clearTimeout(turnTimer);
    clearAllProjs();
    deathStats.textContent = "Survived: " + elapsed + "s  |  Phase " + ["I", "II", "III"][phaseIdx];
    deathScr.classList.add("show");
    return;
  }
  requestAnimationFrame(gameLoop);
}

// ═══════════════════════════════════════════════════════════════════
//  START
// ═══════════════════════════════════════════════════════════════════
// Create boss HP bar
const bossHpTrack = document.createElement("div");
bossHpTrack.style.cssText = "position:absolute;bottom:6px;left:50%;transform:translateX(-50%);width:320px;height:10px;background:#1a001a;border:2px solid rgba(255,255,255,0.3);overflow:hidden;z-index:10;";
const bossHpFill = document.createElement("div");
bossHpFill.style.cssText = "height:100%;width:100%;background:#cc22ff;transition:width 80ms steps(5),background 300ms;";
bossHpTrack.appendChild(bossHpFill);
document.getElementById("boss-area").appendChild(bossHpTrack);
const bossHpText = document.createElement("div");
bossHpText.style.cssText = "position:absolute;bottom:18px;left:50%;transform:translateX(-50%);font-size:7px;color:#cc88ff;white-space:nowrap;z-index:10;font-family:'Press Start 2P',monospace;";
bossHpText.textContent = "AGRAVANTE  " + BOSS_MAX_HP + " / " + BOSS_MAX_HP;
document.getElementById("boss-area").appendChild(bossHpText);

startTabCycle(0);
inTurn = true;
say("...Então, ousa me desafiar?");
phaseTag.textContent = PHASES[0].label;
phaseTag.style.color = PHASES[0].color;

// Set initial player position in DOM
playerEl.style.left = px + "px";
playerEl.style.top  = py + "px";
// Boss entrance animation state
let _bossEntranceDone = false;
let _bossEntranceT = 0;
const _BOSS_ENTRANCE_DURATION = 1800; // ms
let _waitingForPlayerMove = true;
// Patch drawBossFrame to include entrance slide-in
const _origDrawBossFrame = drawBossFrame;
let _entranceStartTs = null;
function drawBossFrameWithEntrance(ts) {
  if (!_bossEntranceDone) {
    if (_entranceStartTs === null) _entranceStartTs = ts;
    const prog = Math.min(1, (ts - _entranceStartTs) / _BOSS_ENTRANCE_DURATION);
    // Ease out cubic
    const eased = 1 - Math.pow(1 - prog, 3);
    // Boss slides in from top: offset Y from -CH to 0
    const slideY = (1 - eased) * -(CH + 20);
    bCtx.save();
    bCtx.translate(0, slideY);
    _origDrawBossFrame(ts);
    bCtx.restore();
    if (prog >= 1) _bossEntranceDone = true;
    return;
  }
  _origDrawBossFrame(ts);
}
requestAnimationFrame(ts => {
  window._startTs = ts;
  lastTs = ts;
  // Monkey-patch drawBossFrame for entrance
  window._realDrawBossFrame = drawBossFrame;
  gameLoop(ts);
});