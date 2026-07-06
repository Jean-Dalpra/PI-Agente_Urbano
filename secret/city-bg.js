// ═══════════════════════════════════════════════════════════════════
//  CITY BACKGROUND — Minimalist Parallax Skyline (Optimized)
//  Reads: phaseIdx (0-2), bossPhase (0-2), gameOver, bossDefeated
//  Placed BEHIND #root, fills the whole viewport
// ═══════════════════════════════════════════════════════════════════

(function () {
  "use strict";

  // ── Canvas setup ────────────────────────────────────────────────
  const canvas = document.createElement("canvas");
  canvas.id = "city-bg-canvas";
  canvas.style.cssText = [
    "position:fixed", "inset:0", "width:100%", "height:100%",
    "pointer-events:none", "z-index:0", "image-rendering:pixelated"
  ].join(";");
  document.body.insertBefore(canvas, document.body.firstChild);

  const ctx = canvas.getContext("2d");
  let W, H;

  function resize() {
    W = canvas.width  = window.innerWidth;
    H = canvas.height = window.innerHeight;
    buildCity();
  }
  window.addEventListener("resize", resize);

  // ── Phase colour palettes ────────────────────────────────────────
  const PALETTES = [
    // Phase 0 — purple dusk
    {
      skyTop:    [8,  1, 22],
      skyBot:    [30, 6, 60],
      fog:       [40, 10, 80, 0.35],
      bldBase:   [15, 5, 35],
      moonCol:   [200, 160, 255],
    },
    // Phase 1 — orange dusk / burning city
    {
      skyTop:    [20, 4, 4],
      skyBot:    [80, 30, 0],
      fog:       [120, 40, 0, 0.30],
      bldBase:   [30, 10, 5],
      moonCol:   [255, 180, 80],
    },
    // Phase 2 — deep red apocalypse
    {
      skyTop:    [18, 0, 0],
      skyBot:    [80, 8, 8],
      fog:       [140, 10, 10, 0.40],
      bldBase:   [28, 4, 4],
      moonCol:   [255, 80, 80],
    },
  ];

  // Smooth Interpolation between palettes
  function lerpPal(a, b, t) {
    const lerp3 = (x, y) => x.map((v, i) => v + (y[i] - v) * t);
    return {
      skyTop:  lerp3(a.skyTop,  b.skyTop),
      skyBot:  lerp3(a.skyBot,  b.skyBot),
      fog:     lerp3(a.fog,     b.fog),
      bldBase: lerp3(a.bldBase, b.bldBase),
      moonCol: lerp3(a.moonCol, b.moonCol),
    };
  }

  function rgb(arr) { return `rgb(${arr[0]|0},${arr[1]|0},${arr[2]|0})`; }
  function rgba(arr, a) {
    const aa = arr.length === 4 ? arr[3] : 1;
    return `rgba(${arr[0]|0},${arr[1]|0},${arr[2]|0},${(a !== undefined ? a : aa).toFixed(2)})`;
  }

  // ── City geometry (Clean Silhouettes) ────────────────────────────
  const LAYERS = [
    { count: 10, minH: 0.20, maxH: 0.40, minW: 40, maxW: 70 },
    { count: 8,  minH: 0.35, maxH: 0.58, minW: 60, maxW: 100 },
    { count: 6,  minH: 0.45, maxH: 0.75, minW: 80, maxW: 140 },
  ];

  let _seed = 0xdeadbeef;
  function sr() { _seed = (_seed ^ _seed << 13) >>> 0; _seed = (_seed ^ _seed >> 17) >>> 0; _seed = (_seed ^ _seed << 5) >>> 0; return _seed / 0xffffffff; }
  function resetSeed() { _seed = 0xdeadbeef; }

  const buildings = [];

  function buildCity() {
    resetSeed();
    buildings.length = 0;
    const groundY = H * 0.75; 

    LAYERS.forEach((cfg, li) => {
      const spacing = W / cfg.count;
      for (let i = 0; i < cfg.count; i++) {
        const bH = (cfg.minH + sr() * (cfg.maxH - cfg.minH)) * H;
        const bW = cfg.minW + sr() * (cfg.maxW - cfg.minW);
        const bX = i * spacing + (sr() - 0.5) * spacing * 0.4 - bW * 0.5;
        const bY = groundY - bH;

        buildings.push({ layer: li, x: bX, y: bY, w: bW, h: bH });
      }
    });
  }

  // ── Stars ────────────────────────────────────────────────────────
  const STARS = [];
  for (let i = 0; i < 45; i++) {
    STARS.push({ rx: Math.random(), ry: Math.random() * 0.50, r: 0.8 + Math.random() * 1.0 });
  }

  let _healT = -1;
  const scroll = [0, 0, 0];
  const scrollSpeeds = [0.08, 0.18, 0.35]; // Smooth subtle movement
  let lastTs = 0;

  // State control for phase transition fixes
  let currentPal = { ...PALETTES[0] };
  let lastTargetIdx = 0;

  function updatePalette(ts, dt) {
    let targetIdx = (typeof phaseIdx !== "undefined") ? Math.min(phaseIdx, 2) : 0;
    
    // Override target if game is healing/ending
    if (_healT >= 0) {
      targetIdx = 0;
    }

    const targetPal = PALETTES[targetIdx];
    
    // Frame-rate independent continuous lerp for perfect smooth transitions
    const transitionSpeed = 0.0015 * dt; // Adjust value to make transitions faster/slower
    currentPal = lerpPal(currentPal, targetPal, Math.min(1, transitionSpeed));
  }

  function draw(ts) {
    if (!W || !H) { resize(); }

    const dt = Math.min(ts - lastTs, 50);
    lastTs = ts;

    // Fix and update phases smoothly
    const isOver = (typeof gameOver !== "undefined") && gameOver;
    if (isOver && _healT < 0) {
      _healT = ts;
    }
    updatePalette(ts, dt);

    ctx.clearRect(0, 0, W, H);

    // ── SKY ───────────────────────────────────────────────────────
    const skyGrad = ctx.createLinearGradient(0, 0, 0, H * 0.75);
    skyGrad.addColorStop(0, rgb(currentPal.skyTop));
    skyGrad.addColorStop(1, rgb(currentPal.skyBot));
    ctx.fillStyle = skyGrad;
    ctx.fillRect(0, 0, W, H);

    // ── Stars ─────────────────────────────────────────────────────
    ctx.save();
    ctx.globalAlpha = 0.6;
    ctx.fillStyle = "#ffffff";
    STARS.forEach(s => {
      ctx.beginPath();
      ctx.arc(s.rx * W, s.ry * H, s.r, 0, Math.PI * 2);
      ctx.fill();
    });
    ctx.restore();

    // ── Moon ──────────────────────────────────────────────────────
    const moonX = W * 0.80, moonY = H * 0.15;
    const moonR = 16;
    ctx.fillStyle = rgba(currentPal.moonCol, 0.1);
    ctx.beginPath(); ctx.arc(moonX, moonY, moonR * 3, 0, Math.PI * 2); ctx.fill();
    ctx.fillStyle = rgb(currentPal.moonCol);
    ctx.beginPath(); ctx.arc(moonX, moonY, moonR, 0, Math.PI * 2); ctx.fill();

    // ── Ground ────────────────────────────────────────────────────
    const groundY = H * 0.75;
    ctx.fillStyle = rgb(currentPal.bldBase);
    ctx.fillRect(0, groundY, W, H - groundY);

    // ── Parallax scroll update ────────────────────────────────────
    for (let li = 0; li < 3; li++) {
      scroll[li] = (scroll[li] + scrollSpeeds[li] * (dt / 16.67)) % W;
    }

    // ── Draw Minimalist Buildings ──────────────────────────────────
    const alphas = [0.25, 0.50, 0.85];
    
    for (let li = 0; li < 3; li++) {
      ctx.save();
      ctx.globalAlpha = alphas[li];
      ctx.fillStyle = rgb(currentPal.bldBase);
      const sx = scroll[li];

      buildings.filter(b => b.layer === li).forEach(b => {
        for (let pass = 0; pass < 2; pass++) {
          const dx = (pass === 0) ? -sx : W - sx;
          const bx = b.x + dx;

          if (bx + b.w < -10 || bx > W + 10) continue;

          ctx.fillRect(bx, b.y, b.w, b.h);
        }
      });
      ctx.restore();
    }

    // ── Fog / Atmosphere ──────────────────────────────────────────
    const fogGrad = ctx.createLinearGradient(0, H * 0.45, 0, H * 0.76);
    fogGrad.addColorStop(0, rgba(currentPal.fog, 0));
    fogGrad.addColorStop(1, rgba(currentPal.fog, currentPal.fog[3]));
    ctx.fillStyle = fogGrad;
    ctx.fillRect(0, H * 0.45, W, H * 0.55);

    // ── End Game Flash Sweep ──────────────────────────────────────
    if (_healT >= 0) {
      const hf = Math.min(1, (ts - _healT) / 800);
      if (hf < 0.5) {
        ctx.save();
        ctx.globalAlpha = (0.5 - hf) * 0.4;
        ctx.fillStyle = "#ffffff";
        ctx.fillRect(0, 0, W, H);
        ctx.restore();
      }
    }

    requestAnimationFrame(draw);
  }

  // Initializers
  window.addEventListener("load", () => {
    resize();
    requestAnimationFrame(draw);
  });

  if (document.readyState === "complete") {
    resize();
    requestAnimationFrame(draw);
  }
})();