(() => {
  'use strict';

  const state = {
    open: false,
    busy: false,
    context: null,
    conversation: [],
    model: 'gemini-3.5-flash-lite',
    mood: 'neutral',
    help: null,
    inlineRunning: false
  };

  let mimiAudioContext = null;
  function playMimiSound(kind = 'click') {
    try {
      const AudioContextClass = window.AudioContext || window.webkitAudioContext;
      if (!AudioContextClass) return;
      mimiAudioContext ||= new AudioContextClass();
      if (mimiAudioContext.state === 'suspended') mimiAudioContext.resume().catch(() => {});
      const now = mimiAudioContext.currentTime;
      const settings = kind === 'send' ? [[680, 0], [920, 0.055]] : kind === 'success' ? [[560, 0], [760, 0.06]] : kind === 'close' ? [[420, 0]] : [[520, 0]];
      settings.forEach(([frequency, delay]) => {
        const oscillator = mimiAudioContext.createOscillator();
        const gain = mimiAudioContext.createGain();
        oscillator.type = 'sine'; oscillator.frequency.setValueAtTime(frequency, now + delay);
        gain.gain.setValueAtTime(0.0001, now + delay);
        gain.gain.exponentialRampToValueAtTime(kind === 'send' ? 0.045 : 0.032, now + delay + 0.008);
        gain.gain.exponentialRampToValueAtTime(0.0001, now + delay + 0.075);
        oscillator.connect(gain); gain.connect(mimiAudioContext.destination);
        oscillator.start(now + delay); oscillator.stop(now + delay + 0.085);
      });
    } catch (_) {}
  }
  function mimiButtonSound(target) {
    if (!target) return;
    if (target.classList.contains('ai-send')) return playMimiSound('send');
    if (target.classList.contains('ai-close') || target.classList.contains('ai-upload-cancel')) return playMimiSound('close');
    if (target.classList.contains('ai-help-run') || target.classList.contains('ai-help-next') || target.classList.contains('ai-upload-confirm')) return playMimiSound('success');
    playMimiSound('click');
  }

  const css = `
    #aiBuddyRoot{--ai-bg:#11152b;--ai-card:#182044;--ai-line:#334277;--ai-text:#eef2ff;--ai-dim:#aeb8da;position:relative;z-index:9999}
    #aiBuddyRoot *{box-sizing:border-box}
    .ai-launcher{position:fixed;right:24px;bottom:26px;width:76px;height:76px;border:0;border-radius:26px;background:linear-gradient(145deg,#ff9fd1,#9075ff);box-shadow:0 16px 42px rgba(64,53,173,.48),inset 0 1px 0 rgba(255,255,255,.55);cursor:pointer;transition:transform .2s,filter .2s;padding:0;overflow:hidden}
    .ai-launcher:hover{transform:translateY(-4px) scale(1.04);filter:brightness(1.08)}
    .ai-launcher:focus-visible{outline:3px solid #fff;outline-offset:3px}
    .ai-orbit{position:absolute;inset:-6px;border:1px solid rgba(255,255,255,.22);border-radius:30px;animation:aiOrbit 18s linear infinite}
    .ai-pet-frame{position:relative;display:block;width:100%;height:100%;overflow:hidden;transform-origin:center center;animation:aiBreath 5.8s ease-in-out infinite;will-change:transform}.ai-pet{display:block;width:100%;height:100%;object-fit:contain;object-position:center;filter:drop-shadow(0 3px 3px rgba(41,20,87,.22));transition:filter .16s ease;will-change:filter}.ai-pet-frame.mood-thinking{animation:aiThinkGlow 2.2s ease-in-out infinite}.ai-pet-frame.mood-warm,.ai-pet-frame.mood-relieved{animation:aiWarmPulse 3.4s ease-in-out infinite}.ai-pet-frame.mood-curious{animation:aiCuriousPeek 2.7s ease-in-out infinite}.ai-pet-frame.mood-confused,.ai-pet-frame.mood-apology{animation:aiConfusedTilt 2.5s ease-in-out infinite}.ai-pet-frame.mood-happy,.ai-pet-frame.mood-success,.ai-pet-frame.mood-proud,.ai-pet-frame.mood-determined{animation:aiProudGlow 2.3s ease-in-out infinite}.ai-pet-frame.mood-alert{animation:aiAlertPulse 1.8s ease-in-out infinite}.ai-pet-frame.mood-help{animation:aiHelpGlow 2.7s ease-in-out infinite}.ai-pet-frame.mood-sleepy{animation:aiSleepy 5.2s ease-in-out infinite}.ai-avatar .ai-pet-frame{animation:none!important}.ai-avatar .ai-pet{filter:none}@keyframes aiBreath{0%,100%{transform:scale(1)}50%{transform:scale(1.008)}}@keyframes aiThinkGlow{0%,100%{transform:scale(1)}50%{transform:scale(1.01)}}@keyframes aiWarmPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.01)}}@keyframes aiCuriousPeek{0%,100%{transform:translateX(0) scale(1)}50%{transform:translateX(.8px) scale(1.008)}}@keyframes aiConfusedTilt{0%,100%{transform:rotate(0) scale(1)}50%{transform:rotate(-.45deg) scale(1.006)}}@keyframes aiProudGlow{0%,100%{transform:scale(1)}50%{transform:scale(1.012)}}@keyframes aiAlertPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.008)}}@keyframes aiHelpGlow{0%,100%{transform:scale(1)}50%{transform:scale(1.008)}}@keyframes aiSleepy{0%,100%{transform:scale(1);opacity:.96}50%{transform:scale(.997);opacity:.92}}@keyframes aiOrbit{to{transform:rotate(360deg)}}@media(prefers-reduced-motion:reduce){.ai-pet-frame,.ai-orbit,.ai-panel.open,.ai-guide.show{animation:none!important}}
    .ai-panel{position:fixed;right:24px;bottom:112px;width:min(360px,calc(100vw - 28px));height:min(540px,calc(100vh - 150px));display:none;flex-direction:column;overflow:hidden;background:linear-gradient(180deg,#141a36,#0f1329);border:1px solid #3b4b83;border-radius:24px;box-shadow:0 24px 80px rgba(0,0,0,.52),0 0 0 1px rgba(255,255,255,.04);color:var(--ai-text);font:13px/1.55 Inter,Segoe UI,system-ui,sans-serif}
    .ai-panel.open{display:flex;animation:aiPanelIn .22s ease}.ai-panel.minimized{height:auto;min-height:0}.ai-panel.minimized .ai-messages,.ai-panel.minimized .ai-compose{display:none}@keyframes aiPanelIn{from{opacity:0;transform:translateY(14px) scale(.97)}to{opacity:1;transform:none}}
    .ai-head{position:relative;display:flex;align-items:flex-start;gap:9px;padding:9px 8px 9px 10px;border-bottom:1px solid rgba(154,171,238,.18);background:rgba(31,42,85,.72);min-height:58px}.ai-head .mini-pet{width:40px;height:40px;flex:0 0 40px;border-radius:13px;background:linear-gradient(145deg,#ffd0e8,#8d79ff);padding:1px;overflow:hidden}.ai-head .mini-pet .ai-pet-frame{animation:none!important}.ai-head-copy{min-width:0;padding:1px 154px 0 0}.ai-title{font-weight:800;letter-spacing:-.01em}.ai-subtitle{display:block;color:#aab5df;font-size:11px;margin-top:1px;white-space:normal}.ai-head-actions{position:absolute;top:7px;right:7px;display:flex;align-items:center;gap:3px}.ai-icon{display:grid;place-items:center;border:1px solid #3b4b83;background:#202b56;color:#e7ebff;border-radius:8px;width:27px;min-width:27px;height:27px;padding:0;cursor:pointer;font-size:16px;line-height:1;font-weight:700}.ai-icon:hover{background:#2a3970;filter:brightness(1.12)}.ai-icon.ai-min{font-size:12px;letter-spacing:-2px;white-space:nowrap;overflow:hidden;line-height:1;padding:0 2px}.ai-icon:focus-visible{outline:2px solid #d8ddff;outline-offset:2px}
    .ai-messages{flex:1;overflow:auto;padding:15px 13px 10px;scroll-behavior:smooth}.ai-history-panel{position:absolute;inset:58px 0 0;background:rgba(10,14,31,.98);transform:translateX(105%);transition:transform .22s ease;z-index:5;display:flex;flex-direction:column;padding:12px;border-top:1px solid rgba(154,171,238,.18)}.ai-history-panel.open{transform:translateX(0)}.ai-history-head{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-bottom:8px}.ai-history-head b{font-size:13px}.ai-history-head-actions{display:flex;align-items:center;gap:5px}.ai-history-clear,.ai-history-close{border:1px solid #3b4b83;background:#202b56;color:#fff;border-radius:8px;width:28px;height:28px;padding:0;cursor:pointer;font-size:15px;line-height:26px}.ai-history-clear:hover{background:#5a304d}.ai-history-close{font-size:16px}.ai-history-list{overflow:auto;display:grid;gap:6px}.ai-history-item{display:flex;flex-direction:column;align-items:flex-start;gap:2px;width:100%;padding:9px 10px;border:1px solid rgba(151,169,236,.18);border-radius:10px;background:#181f3d;color:#eef2ff;text-align:left;cursor:pointer}.ai-history-item:hover,.ai-history-item.active{background:#27346b;border-color:#6576c4}.ai-history-item b{width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-size:12px}.ai-history-item small{color:#aeb8da;font-size:10px}    .ai-history-empty{color:#aeb8da;font-size:11px;line-height:1.5;padding:10px 2px}.ai-logout-confirm{position:absolute;top:55px;right:7px;z-index:7;display:flex;align-items:center;gap:6px;padding:7px 8px;border:1px solid #596aa9;border-radius:10px;background:#171f42;box-shadow:0 10px 28px #0008;color:#eef2ff;font-size:11px;transform:translateY(-10px);opacity:0;pointer-events:none;transition:transform .2s ease,opacity .2s ease}.ai-logout-confirm.open{transform:none;opacity:1;pointer-events:auto}.ai-logout-confirm span{white-space:nowrap}.ai-logout-confirm button{width:26px;height:26px;border:1px solid #53639f;border-radius:7px;background:#263362;color:#fff;cursor:pointer;font-weight:800}.ai-logout-confirm .ai-logout-yes{background:#356d58}.ai-logout-confirm .ai-logout-no{background:#58364d}.ai-msg{display:flex;gap:8px;margin:0 0 13px;animation:aiMsg .2s ease}@keyframes aiMsg{from{opacity:0;transform:translateY(5px)}to{opacity:1;transform:none}}.ai-msg.user{justify-content:flex-end}.ai-msg.user .ai-bubble{background:#3c3270;border-color:#6554b4}.ai-avatar{width:27px;height:27px;flex:0 0 27px;border-radius:10px;display:grid;place-items:center;background:linear-gradient(145deg,#ffb7d9,#7d6be9);font-size:10px;font-weight:800;overflow:hidden}.ai-avatar .ai-pet-frame{animation:none!important}.ai-msg.user .ai-avatar{background:#30395e;order:2}.ai-bubble{max-width:calc(100% - 37px);padding:9px 11px;border:1px solid rgba(151,169,236,.18);border-radius:14px;background:#181f3d;overflow-wrap:anywhere}.ai-bubble p{margin:0 0 7px}.ai-bubble p:last-child{margin-bottom:0}.ai-bubble pre{white-space:pre-wrap;overflow:auto;background:#090d1d;border:1px solid #2b3965;border-radius:9px;padding:9px;margin:8px 0 0;font:11px/1.5 ui-monospace,SFMono-Regular,Consolas,monospace;color:#d9e2ff}.ai-bubble code{font:11px ui-monospace,SFMono-Regular,Consolas,monospace;color:#c8d6ff}.ai-bubble ul,.ai-bubble ol{margin:5px 0 4px 18px;padding:0}.ai-bubble li{margin:3px 0}.ai-bubble strong{color:#fff}.ai-bubble a{color:#b9c7ff}    .ai-command,.ai-help-card{margin:8px 0 0;padding:9px;border:1px dashed #7286dc;border-radius:10px;background:rgba(98,112,219,.1)}.ai-function-icon{display:inline-grid;place-items:center;width:24px;height:24px;margin-right:5px;border-radius:8px;background:#332b69;border:1px solid #776ae0;font-size:14px;vertical-align:middle}.ai-help-card .ai-card-title{display:flex;align-items:center;gap:6px}.ai-help-actions{display:flex;justify-content:space-between;gap:8px;margin-top:9px}.ai-help-guide,.ai-help-run,.ai-help-next{flex:1;border:0;border-radius:8px;padding:8px 10px;color:#fff;font-weight:800;cursor:pointer}.ai-help-guide{background:#3c4777}.ai-help-run{background:linear-gradient(90deg,#e86fba,#7c69f4)}.ai-help-next{background:#5a66a8}.ai-help-guide:hover,.ai-help-run:hover,.ai-help-next:hover{filter:brightness(1.12)}.ai-card-locked .ai-help-guide,.ai-card-locked .ai-help-run{opacity:.58;cursor:not-allowed}.ai-help-result{margin-top:7px;color:#d8e1ff;font-size:12px;white-space:pre-wrap}.ai-help-guide,.ai-help-run,.ai-help-next,.ai-upload-confirm,.ai-upload-cancel{pointer-events:auto;touch-action:manipulation;position:relative;z-index:3}.ai-help-guide:disabled,.ai-help-run:disabled,.ai-help-next:disabled{opacity:.55;cursor:wait}.ai-inline-instructions{margin-top:9px;padding:9px;border-radius:10px;background:#0e1530;border:1px solid #3b4b83;color:#dce3ff}.ai-inline-instructions>b{display:block;color:#fff;margin-bottom:5px}.ai-instruction-list>div{padding:4px 0;border-bottom:1px solid rgba(152,169,235,.12)}.ai-instruction-list>div:last-child{border-bottom:0}.ai-inline-current{margin-top:8px;padding:8px;border-radius:8px;background:#1c2854;color:#fff;font-weight:700}.ai-inline-instructions small{display:block;color:#9eacd8;margin-top:6px}.ai-plus{position:relative;display:flex;align-items:center;justify-content:center;align-self:center;justify-self:stretch;box-sizing:border-box;width:32px;height:32px;min-width:32px;max-width:32px;transform:translateY(-2px);border:1px solid #4b5b92;border-radius:9px;background:#202b56;color:#fff;font-size:20px;line-height:1;cursor:pointer;padding:0}.ai-plus:hover{background:#344477}.ai-plus .ai-file{position:absolute;inset:0;width:100%;height:100%;opacity:0;cursor:pointer}.ai-upload-card{margin-top:8px;padding:9px;border:1px solid #5b69b0;border-radius:10px;background:rgba(91,105,176,.14)}.ai-upload-card input{width:100%;margin:6px 0;padding:7px;border:1px solid #46558b;border-radius:8px;background:#0d1430;color:#fff}.ai-upload-actions{display:grid;grid-template-columns:1fr 1fr;align-items:stretch;gap:7px}.ai-upload-actions button{width:100%;min-height:32px;padding:7px 8px;font-size:12px}.ai-command b,.ai-help-card b{display:block;color:#c9d3ff;margin-bottom:6px}.ai-run,.ai-help-start,.ai-guide-btn{border:0;border-radius:8px;padding:8px 11px;background:linear-gradient(90deg,#e86fba,#7c69f4);color:#fff;font-weight:800;cursor:pointer}.ai-run:hover,.ai-help-start:hover,.ai-guide-btn:hover{filter:brightness(1.12)}.ai-run:disabled,.ai-help-start:disabled,.ai-guide-btn:disabled{opacity:.5;cursor:wait}
    .ai-typing{display:inline-flex;gap:4px;align-items:center;height:18px}.ai-typing i{width:5px;height:5px;border-radius:50%;background:#aeb9eb;animation:aiDots 1s infinite}.ai-typing i:nth-child(2){animation-delay:.15s}.ai-typing i:nth-child(3){animation-delay:.3s}@keyframes aiDots{0%,70%,100%{opacity:.35;transform:translateY(0)}35%{opacity:1;transform:translateY(-4px)}}
    .ai-suggestions{display:flex;gap:6px;flex-wrap:wrap;margin-top:9px}.ai-suggestion{border:1px solid #3e4d83;border-radius:99px;background:#1b2550;color:#cbd6ff;padding:6px 9px;font-size:11px;cursor:pointer}.ai-suggestion:hover{border-color:#8c7fff;background:#27346b}
    .ai-compose{padding:8px 10px 10px;border-top:1px solid rgba(154,171,238,.18);background:#11172f}.ai-powered{display:flex;align-items:center;gap:5px;margin-top:5px}.ai-model{min-width:0;flex:1;height:27px;border:1px solid #354575;border-radius:8px;background:#0b1024;color:#cfd8ff;font-size:11px;padding:0 7px}.ai-model-add{width:27px;height:27px;border:1px solid #596aa9;border-radius:8px;background:#202b56;color:#fff;cursor:pointer;font-size:16px;line-height:1}.ai-model-add:hover{background:#344477}.ai-form{display:grid;grid-template-columns:minmax(0,1fr) 32px 32px 48px;align-items:center;gap:6px;background:#0b1024;border:1px solid #354575;border-radius:14px;padding:6px}.ai-input{width:100%;min-width:0;min-height:34px;max-height:120px;resize:none;border:0;outline:0;background:transparent;color:#f3f5ff;font:13px/1.45 inherit;padding:5px}.ai-input::placeholder{color:#7e8bb7}    .ai-compose-gap{width:32px;height:32px}.ai-send{align-self:center;justify-self:stretch;box-sizing:border-box;width:48px;height:32px;min-width:48px;border:0;border-radius:9px;background:#7565f4;color:#fff;font-size:12px;font-weight:700;line-height:32px;cursor:pointer;padding:0}.ai-send:disabled{opacity:.45;cursor:not-allowed}.ai-note{color:#7886b4;font-size:10px;padding:4px 2px 0}.ai-status{color:#aab6e0}.ai-error{color:#ffabbc}.ai-account-focus{outline:3px solid #ffce76!important;outline-offset:3px!important;background:rgba(255,206,118,.12)!important}.ai-account-mini{margin-top:8px;border:1px solid #3b4b83;border-radius:9px;overflow:hidden;background:#0e1530}.ai-account-mini-row{display:flex;justify-content:space-between;gap:8px;padding:7px 8px;border-bottom:1px solid rgba(152,169,235,.14);font-size:11px}.ai-account-mini-row:last-child{border-bottom:0}.ai-account-mini-row span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#f1f3ff}.ai-account-mini-row small{color:#aebbea;white-space:nowrap;font-size:10px}
    .ai-guide{position:fixed;right:18px;bottom:96px;width:min(350px,calc(100vw - 36px));display:none;z-index:10001;border:1px solid #6b5fc5;border-radius:16px;background:linear-gradient(145deg,#161d3d,#20275a);box-shadow:0 18px 55px rgba(0,0,0,.45);color:#f4f5ff;padding:10px 11px}.ai-guide.show{display:block;animation:aiGuideIn .22s ease}@keyframes aiGuideIn{from{opacity:0;transform:translateY(12px)}to{opacity:1;transform:none}}.ai-guide-top{display:flex;gap:9px;align-items:center}.ai-guide-avatar{width:42px;height:42px;flex:0 0 42px;border-radius:13px;background:linear-gradient(145deg,#ffd0e8,#8d79ff);overflow:hidden}.ai-guide-title{font-weight:800;font-size:12px}.ai-guide-step{color:#dbe0ff;font-size:12px;margin:8px 0 9px}.ai-guide-step b{color:#fff}.ai-guide-target{color:#aebbea;font-size:10px}.ai-guide-actions{display:flex;gap:7px;flex-wrap:wrap}.ai-guide-actions .ai-guide-btn,.ai-guide-actions .ai-guide-cancel{min-height:34px;padding:8px 11px;white-space:nowrap}.ai-guide-cancel{border:1px solid #53639f;border-radius:8px;padding:8px 10px;background:transparent;color:#ccd5ff;font-weight:700;cursor:pointer}.ai-target-highlight{outline:3px solid #ff9fd1!important;outline-offset:4px!important;box-shadow:0 0 0 8px rgba(255,159,209,.18)!important;transition:outline .2s,box-shadow .2s}
    @media(max-width:650px){.ai-launcher{right:14px;bottom:18px;width:66px;height:66px;border-radius:23px}.ai-panel{right:10px;bottom:94px;width:calc(100vw - 20px);height:min(560px,calc(100vh - 120px));border-radius:20px}      .ai-guide{right:10px;bottom:94px;width:calc(100vw - 20px)}}
  `;

  // Đúng 6 ảnh cố định, mỗi ảnh có mood và biểu tượng chức năng riêng.
  const assetFiles = {
    neutral: '/manus-storage/mimi_ready_bdeee600.png', warm: '/manus-storage/mimi_ready_bdeee600.png', curious: '/manus-storage/mimi_ready_bdeee600.png',
    thinking: '/manus-storage/mimi_thinking_4d62f863.png', confused: '/manus-storage/mimi_thinking_4d62f863.png', sleepy: '/manus-storage/mimi_thinking_4d62f863.png',
    help: '/manus-storage/mimi_help_52a82117.png', determined: '/manus-storage/mimi_help_52a82117.png',
    proxy: 'mimi_proxy.png', alert: 'mimi_proxy.png', apology: 'mimi_proxy.png',
    account: 'mimi_account.png',
    happy: '/manus-storage/mimi_success_2392d3db.png', success: '/manus-storage/mimi_success_2392d3db.png', proud: '/manus-storage/mimi_success_2392d3db.png', relieved: '/manus-storage/mimi_success_2392d3db.png'
  };
  function assetUrl(file) { return new URL('assets/' + file, document.baseURI).href; }
  const imageCache = {};
  function preloadAssets() {
    const run = () => [...new Set(Object.values(assetFiles))].forEach(file => { const img = new Image(); img.decoding = 'async'; img.src = assetUrl(file); imageCache[file] = img; });
    if ('requestIdleCallback' in window) window.requestIdleCallback(run, {timeout:800}); else window.setTimeout(run, 0);
  }
  function petHtml(extra = '', live = true) { return `<span class="ai-pet-frame ${live ? 'ai-live-pet ' : ''}${extra}"><img class="ai-pet" src="${assetUrl(assetFiles[state.mood] || assetFiles.neutral)}" alt="Nhân vật trợ lý AI"></span>`; }
  function setMood(mood) { const next = assetFiles[mood] ? mood : 'neutral'; state.mood = next; const file = assetFiles[next]; const url = assetUrl(file); const apply = () => requestAnimationFrame(() => { document.querySelectorAll('.ai-live-pet').forEach(frame => { frame.className = 'ai-pet-frame ai-live-pet mood-' + next; const img = frame.querySelector('.ai-pet'); if (img && img.src !== url) img.src = url; }); }); const cached = imageCache[file]; if (cached && cached.complete) apply(); else { const ready = cached || new Image(); ready.onload = apply; ready.src = url; imageCache[file] = ready; } const root = document.getElementById('aiBuddyRoot'); if (root) root.dataset.mood = next; }
  function escapeHtml(value) { return String(value ?? '').replace(/[&<>"']/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch])); }
  function functionIcon(step) { const action = String(step?.action || '').toLowerCase(); const tab = String(step?.tab || '').toLowerCase(); if (action.includes('proxy') || tab === 'proxy') return '🌐'; if (action.includes('account') || action.startsWith('acc_') || tab === 'accounts') return '👤'; if (action.includes('login')) return '🔑'; if (action.includes('create')) return '✚'; if (action === 'ping') return '◉'; if (tab === 'muathe') return '▣'; return '▶'; }
  function sanitizeAiText(value) { return String(value ?? '').replace(/\uFFFD/g, '').replace(/[\u0400-\u04FF\u0600-\u06FF\u0900-\u097F\u3040-\u30FF\u4E00-\u9FFF\uAC00-\uD7AF]/g, '').replace(/[\u{1F000}-\u{1FAFF}\u2600-\u27BF]/gu, '').replace(/[\x00-\x08\x0B\x0C\x0E-\x1F]/g, '').replace(/[ \t]{2,}/g, ' ').trim(); }
  function markdownLite(value) {
    value = sanitizeAiText(value);
    let text = escapeHtml(value), blocks = [];
    text = text.replace(/```(?:[a-zA-Z0-9_+-]+)?\n?([\s\S]*?)```/g, (_, code) => `@@CODE${blocks.push(code.trim()) - 1}@@`);
    text = text.replace(/`([^`]+)`/g, '<code>$1</code>').replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>').replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
    text = text.split(/\n\n+/).map(part => { const lines = part.split('\n').filter(Boolean); if (lines.length && lines.every(x => /^\s*\d+[.)]\s+/.test(x))) return `<ol>${lines.map(x => `<li>${x.replace(/^\s*\d+[.)]\s+/, '')}</li>`).join('')}</ol>`; if (lines.length && lines.every(x => /^\s*[-*]\s+/.test(x))) return `<ul>${lines.map(x => `<li>${x.replace(/^\s*[-*]\s+/, '')}</li>`).join('')}</ul>`; return `<p>${part.replace(/\n/g, '<br>')}</p>`; }).join('');
    return text.replace(/@@CODE(\d+)@@/g, (_, i) => `<pre>${blocks[Number(i)]}</pre>`);
  }
  function apiUrl(action) { const u = new URL(location.href); u.search = ''; u.hash = ''; u.searchParams.set('action', action); return u.toString(); }
  async function askServer(messages) { const response = await fetch(apiUrl('ai_chat'), {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','Accept':'application/json'}, body:JSON.stringify({messages, model:state.model})}); const data = await response.json().catch(() => ({})); if (!response.ok || !data.ok) throw new Error(data.error || `AI server HTTP ${response.status}`); return String(data.answer || ''); }
  async function loadAiModels() {
    const select = document.querySelector('.ai-model'); if (!select) return;
    try {
      const response = await fetch(apiUrl('mimi_models_get'), {method:'GET', credentials:'same-origin', headers:{Accept:'application/json'}, cache:'no-store'});
      const data = await response.json(); if (!response.ok || !data.ok) return;
      window.mimiCanManageModels = !!data.developer;
      const current = state.model;
      [...select.querySelectorAll('option[data-custom="1"]')].forEach(option => option.remove());
      (Array.isArray(data.models) ? data.models : []).forEach(model => {
        const option = document.createElement('option'); option.value = model.id; option.dataset.custom = '1'; option.textContent = 'Mimi · ' + model.label; select.appendChild(option);
      });
      select.value = [...select.options].some(option => option.value === current) ? current : select.options[0]?.value || '';
      const add = document.querySelector('.ai-model-add'); if (add) add.hidden = !window.mimiCanManageModels;
    } catch (_) {}
  }
  async function addCustomAiModel() {
    if (!window.mimiCanManageModels) return;
    const label = window.prompt('Đặt tên hiển thị cho model:'); if (!label || !label.trim()) return;
    const model = window.prompt('Nhập mã model Gemini, ví dụ gemini-2.5-flash:'); if (!model || !model.trim()) return;
    const apiKey = window.prompt('Dán API key. Key chỉ gửi thẳng server, không gửi cho Mimi:'); if (!apiKey || !apiKey.trim()) return;
    const response = await fetch(apiUrl('mimi_model_add'), {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json',Accept:'application/json'}, body:JSON.stringify({label:label.trim(), model:model.trim(), api_key:apiKey.trim()})});
    const data = await response.json().catch(() => ({}));
    if (!response.ok || !data.ok) { window.alert(data.error || 'Không thêm được model.'); return; }
    await loadAiModels(); const select = document.querySelector('.ai-model'); if (select && data.model?.id) { select.value = data.model.id; state.model = data.model.id; }
    playMimiSound('success');
  }
  function isHelpPlan(value) { return !!(value && typeof value === 'object' && Array.isArray(value.steps) && value.steps.length && value.steps.some(step => step && (step.action || step.click || step.wait_input))); }
  function tryJson(value) { try { const parsed = JSON.parse(String(value).trim()); return parsed && typeof parsed === 'object' ? parsed : null; } catch (_) { return null; } }
  function isUiEditRequest(text) { return mimiIdentity === 'MimiVip01' && /(?:sửa|chỉnh|đổi|thay|thiết kế|màu|font|giao diện|layout|nút|button|kích thước|bố cục|source giao diện)/i.test(String(text || '')); }
  function clearUiEditHighlights() { document.querySelectorAll('.ai-ui-edit-highlight').forEach(el => el.classList.remove('ai-ui-edit-highlight')); }
  function highlightUiEditSelectors(selectors) {
    clearUiEditHighlights();
    const found = (Array.isArray(selectors) ? selectors : []).flatMap(selector => {
      if (typeof selector !== 'string' || !/^[.#][A-Za-z][A-Za-z0-9_-]*$/.test(selector)) return [];
      try { return [...document.querySelectorAll(selector)]; } catch (_) { return []; }
    });
    const unique = [...new Set(found)];
    unique.forEach(el => el.classList.add('ai-ui-edit-highlight'));
    unique[0]?.scrollIntoView({behavior:'smooth', block:'center'});
    return unique;
  }
  function uiEditCard(data) {
    const item = addMessage('assistant', '');
    if (!item) return;
    const style = document.createElement('style');
    style.textContent = '.ai-ui-edit-highlight{outline:4px solid #ffd166!important;outline-offset:5px!important;box-shadow:0 0 0 10px rgba(255,209,102,.24),0 0 34px rgba(255,209,102,.55)!important;position:relative!important;z-index:2}.ai-ui-edit-card{margin-top:8px;padding:10px;border:1px solid #e0b94d;border-radius:12px;background:#211f32;color:#fff}.ai-ui-edit-card pre{max-height:180px;overflow:auto;white-space:pre-wrap;background:#0b1024;border:1px solid #3b4b83;border-radius:8px;padding:8px;font:11px/1.45 ui-monospace,monospace}.ai-ui-edit-card button{border:0;border-radius:8px;padding:8px 10px;margin:4px 5px 0 0;color:#fff;font-weight:700;cursor:pointer}.ai-ui-edit-apply{background:#3d8b68}.ai-ui-edit-reject{background:#765a98}.ai-ui-edit-card button:disabled{opacity:.55;cursor:wait}';
    document.head.appendChild(style);
    const card = document.createElement('div'); card.className = 'ai-ui-edit-card';
    const title = document.createElement('b'); title.textContent = 'Mimi đã quét và đánh dấu vùng giao diện'; card.appendChild(title);
    const info = document.createElement('div'); info.style.marginTop = '6px'; info.textContent = `${data.summary || 'Mimi đã chuẩn bị thay đổi.'} File: ${data.file || 'chưa xác định'}.`; card.appendChild(info);
    const diff = document.createElement('pre'); const removed = Array.isArray(data.diff?.removed) ? data.diff.removed.map(line => '- ' + line) : []; const added = Array.isArray(data.diff?.added) ? data.diff.added.map(line => '+ ' + line) : []; diff.textContent = `Dòng bắt đầu: ${data.diff?.startLine || '?'}\n${[...removed, ...added].join('\n') || '(không có diff để hiển thị)'}`; card.appendChild(diff);
    const note = document.createElement('div'); note.textContent = 'Nếu khung vàng đúng vùng cần sửa, bấm “Đúng, ghi source”. Nếu chưa đúng, bấm “Chưa đúng” và mô tả lại vùng cần di chuyển.'; card.appendChild(note);
    const apply = document.createElement('button'); apply.className = 'ai-ui-edit-apply'; apply.textContent = 'Đúng, ghi source';
    const reject = document.createElement('button'); reject.className = 'ai-ui-edit-reject'; reject.textContent = 'Chưa đúng, đổi vùng';
    const actions = document.createElement('div'); actions.append(apply, reject); card.appendChild(actions); item.bubble.appendChild(card); highlightUiEditSelectors(data.selectors);
    reject.addEventListener('click', () => { clearUiEditHighlights(); card.remove(); addMessage('assistant', 'Đã hủy bản sửa. Mimi chưa ghi source. Bạn mô tả lại vùng giao diện cần sửa nhé.'); setMood('neutral'); inputFocus(); });
    apply.addEventListener('click', async () => {
      apply.disabled = true; reject.disabled = true; apply.textContent = 'Đang ghi source…'; setMood('thinking');
      try {
        const response = await fetch(apiUrl('ui_source_apply'), {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','Accept':'application/json'}, body:JSON.stringify({token:data.token, confirm:true})});
        const result = await response.json().catch(() => ({}));
        if (!response.ok || !result.ok) throw new Error(result.error || 'Không ghi được source');
        clearUiEditHighlights(); card.replaceChildren(); const done = document.createElement('div'); done.textContent = `Đã ghi thay đổi vào ${result.file}. Cần build/redeploy hoặc reload HMR để bản giao diện mới xuất hiện.`; card.appendChild(done); addMessage('assistant', 'Mimi đã ghi source giao diện thành công.'); setMood('success');
      } catch (error) { apply.disabled = false; reject.disabled = false; apply.textContent = 'Đúng, ghi source'; const err = document.createElement('div'); err.className = 'ai-error'; err.textContent = 'Mimi chưa ghi được source: ' + (error?.message || 'lỗi không xác định'); card.appendChild(err); setMood('alert'); }
    });
  }
  async function requestUiEdit(text) {
    const typing = addTyping(); setBusy(true);
    try {
      const response = await fetch(apiUrl('ui_source_plan'), {method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','Accept':'application/json'}, body:JSON.stringify({request:text})});
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.ok) throw new Error(data.error || `UI source editor HTTP ${response.status}`);
      if (typing) typing.bubble.textContent = data.mode === 'needs_clarification' ? (data.summary || 'Mimi cần bạn mô tả rõ vùng giao diện hơn.') : 'Mimi đang chờ bạn kiểm tra khung vàng trên giao diện.';
      if (data.mode === 'preview') uiEditCard(data); else { setMood('alert'); inputFocus(); }
      setMood(data.mode === 'preview' ? 'help' : 'alert');
    } catch (error) { if (typing) typing.bubble.textContent = 'Mimi chưa quét được source giao diện: ' + (error?.message || 'lỗi không xác định'); setMood('alert'); }
    finally { setBusy(false); scrollBottom(); }
  }
  function extractLabeledJson(source, label) {
    const re = label === 'AI_HELP' ? /(?:^|\n)\s*AI_HELP\s*:?\s*/ig : /(?:^|\n)\s*AI_ACTION\s*:?\s*/ig;
    for (const match of String(source).matchAll(re)) {
      const start = match.index ?? 0;
      const open = String(source).indexOf('{', start + match[0].length);
      if (open < 0) continue;
      let depth = 0, quote = '', escaped = false;
      for (let i = open; i < String(source).length; i++) {
        const ch = String(source)[i];
        if (quote) { if (escaped) escaped = false; else if (ch === '\\') escaped = true; else if (ch === quote) quote = ''; continue; }
        if (ch === '"' || ch === "'") { quote = ch; continue; }
        if (ch === '{') depth++;
        else if (ch === '}') { depth--; if (depth === 0) { const parsed = tryJson(String(source).slice(open, i + 1)); if (parsed) return {parsed, raw: String(source).slice(start, i + 1)}; break; } }
      }
    }
    return null;
  }
  function extractPayload(raw) {
    raw = String(raw ?? '');
    const moodMatch = raw.match(/<!--AI_MOOD\s*([\s\S]*?)\s*-->/i);
    let mood = 'neutral';
    if (moodMatch) { const parsed = tryJson(moodMatch[1]); if (parsed && assetFiles[parsed.mood]) mood = parsed.mood; }
    const helpMatch = raw.match(/<!--AI_HELP\s*([\s\S]*?)\s*-->/i);
    let help = null;
    if (helpMatch) { const parsed = tryJson(helpMatch[1]); if (isHelpPlan(parsed)) help = parsed; }
    const actionMatch = raw.match(/<!--AI_ACTION\s*([\s\S]*?)\s*-->/i);
    let command = null;
    if (actionMatch) { const parsed = tryJson(actionMatch[1]); if (parsed?.action) command = parsed; }
    let text = raw.replace(moodMatch ? moodMatch[0] : '', '').replace(helpMatch ? helpMatch[0] : '', '').replace(actionMatch ? actionMatch[0] : '', '');
    // Model đôi lúc trả “AI_HELP:” rồi in JSON thuần/code block. Vẫn lấy plan
    // để các nút hoạt động, nhưng tuyệt đối không đưa nhãn hay JSON kỹ thuật ra chat.
    if (!help) {
      const labeled = extractLabeledJson(text, 'AI_HELP');
      if (labeled && isHelpPlan(labeled.parsed)) { help = labeled.parsed; text = text.replace(labeled.raw, ''); text = text.replace(/^\s*```(?:json)?\s*$/gim, ''); }
    }
    if (!command) {
      const labeledAction = extractLabeledJson(text, 'AI_ACTION');
      if (labeledAction && labeledAction.parsed?.action) { command = labeledAction.parsed; text = text.replace(labeledAction.raw, ''); text = text.replace(/^\s*```(?:json)?\s*$/gim, ''); }
    }
    if (!help) {
      const fenced = [...text.matchAll(/```(?:json)?\s*([\s\S]*?)```/gi)];
      for (const match of fenced) { const parsed = tryJson(match[1]); if (isHelpPlan(parsed)) { help = parsed; text = text.replace(match[0], ''); break; } }
    }
    if (!help) {
      const loose = text.match(/\{\s*["'](?:title|request|steps)["'][\s\S]*?\}\s*$/i);
      if (loose) { const parsed = tryJson(loose[0]); if (isHelpPlan(parsed)) { help = parsed; text = text.slice(0, loose.index).trim(); } }
    }
    text = text.replace(/^\s*(?:AI_HELP|AI_ACTION)\s*:?\s*$/gim, '').replace(/^\s*```(?:json)?\s*$/gim, '');
    return {text: text.replace(/\n{3,}/g, '\n\n').trim(), help, command, mood};
  }
  function scrollBottom() { const el = document.querySelector('.ai-messages'); if (el) requestAnimationFrame(() => { el.scrollTop = el.scrollHeight; }); }
  function updateMinButton(minimized) { const button = document.querySelector('.ai-min'); if (!button) return; button.textContent = minimized ? '↓↑' : '↑↓'; button.title = minimized ? 'Mở rộng Mimi' : 'Thu gọn Mimi'; button.setAttribute('aria-label', minimized ? 'Mở rộng Mimi' : 'Thu gọn Mimi'); }
  function toggleMinimizePanel() { const panel = document.querySelector('.ai-panel'); if (!panel) return; const minimized = panel.classList.toggle('minimized'); updateMinButton(minimized); }
  function minimizePanel() { const panel = document.querySelector('.ai-panel'); if (panel) { panel.classList.add('minimized'); updateMinButton(true); } }
  function minimizePanelForGuide() { const panel = document.querySelector('.ai-panel'); if (panel) { state.open = true; panel.classList.add('open', 'minimized'); updateMinButton(true); } } function restorePanelAfterGuide() { const panel = document.querySelector('.ai-panel'); if (panel) { state.open = true; panel.classList.add('open'); panel.classList.remove('minimized'); updateMinButton(false); } }
  function addMessage(role, text) { const list = document.querySelector('.ai-messages'); if (!list) return null; const row = document.createElement('div'); row.className = `ai-msg ${role}`; const avatar = role === 'user' ? '<span>Bạn</span>' : petHtml('ai-message-pet', false); row.innerHTML = `<div class="ai-avatar">${avatar}</div><div class="ai-bubble"></div>`; const bubble = row.querySelector('.ai-bubble'); if (text) bubble.innerHTML = role === 'assistant' ? markdownLite(text) : escapeHtml(text).replace(/\n/g, '<br>'); list.appendChild(row); scrollBottom(); return {row, bubble}; }
  function addTyping() { document.querySelectorAll('.ai-current-pet').forEach(p => p.classList.remove('ai-current-pet', 'ai-live-pet')); const item = addMessage('assistant', ''); if (item) { const pet = item.row.querySelector('.ai-pet-frame'); if (pet) pet.classList.add('ai-current-pet', 'ai-live-pet'); item.bubble.innerHTML = '<span class="ai-typing"><i></i><i></i><i></i></span>'; } setMood('thinking'); return item; }
  function setBusy(value) { state.busy = value; const input = document.querySelector('.ai-input'), send = document.querySelector('.ai-send'), status = document.querySelector('.ai-status'); if (input) input.disabled = value; if (send) send.disabled = value; if (status) status.textContent = value ? 'Đang đọc và xử lý' : 'Sẵn sàng'; if (!value && state.mood === 'thinking') setMood('neutral'); }
  function addSuggestions() { const list = document.querySelector('.ai-messages'); if (!list) return; const row = document.createElement('div'); row.className = 'ai-suggestions'; ['Liệt kê chức năng trong api.php','Giải thích một chức năng','Hướng dẫn chạy ping','Viết thêm code PHP'].forEach(label => { const b = document.createElement('button'); b.className = 'ai-suggestion'; b.type = 'button'; b.textContent = label; b.addEventListener('click', () => send(label)); row.appendChild(b); }); list.appendChild(row); scrollBottom(); }
  let mimiIdentity = 'guest';
  let memorySaveTimer = null;
  let chatSaveTimer = null;
  let chatSessions = [];
  let activeSessionId = null;
  function memoryKey() { return 'mimi.memory.' + String(mimiIdentity || 'guest').toLowerCase().replace(/[^a-z0-9_-]/g, '_'); }
  function normalizeMemory(items) { return (Array.isArray(items) ? items : []).filter(m => m && (m.role === 'user' || m.role === 'assistant') && typeof m.content === 'string' && m.content.trim()).slice(-40); }
  function chatNow() { return new Date().toISOString(); }
  function chatTitle(messages) { const first = (Array.isArray(messages) ? messages : []).find(m => m && m.role === 'user' && String(m.content || '').trim()); const text = String(first?.content || 'Cuộc trò chuyện mới').replace(/\s+/g, ' ').trim(); return text.length > 46 ? text.slice(0, 46) + '…' : text; }
  function chatLocalKey() { return 'mimi.chats.' + String(mimiIdentity || 'guest').toLowerCase().replace(/[^a-z0-9_-]/g, '_'); }
  function ensureChatSession(snapshot) { if (mimiIdentity === 'guest' || !snapshot.some(m => m.role === 'user')) return null; const now = chatNow(); if (!activeSessionId) { activeSessionId = 'chat_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 7); chatSessions.unshift({id:activeSessionId,title:chatTitle(snapshot),created_at:now,updated_at:now,messages:snapshot}); } const current = chatSessions.find(s => s.id === activeSessionId); if (current) { current.title = chatTitle(snapshot); current.updated_at = now; current.messages = snapshot; } return current; }
  function saveChatSessions() { if (mimiIdentity === 'guest') return; chatSessions = chatSessions.filter(s => s && Array.isArray(s.messages) && s.messages.some(m => m.role === 'user')).slice(0, 30); try { localStorage.setItem(chatLocalKey(), JSON.stringify(chatSessions)); } catch (_) {} clearTimeout(chatSaveTimer); chatSaveTimer = setTimeout(() => fetch(apiUrl('mimi_chats_save'), {method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'}, body:JSON.stringify({sessions:chatSessions})}).catch(() => {}), 300); }
  function archiveCurrentChat() { const snapshot = normalizeMemory(state.conversation); if (mimiIdentity !== 'guest' && snapshot.some(m => m.role === 'user')) { ensureChatSession(snapshot); saveChatSessions(); } activeSessionId = null; }
  function formatChatTime(value) { if (!value) return 'Chưa rõ thời gian'; const d = new Date(value); return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleString('vi-VN', {day:'2-digit',month:'2-digit',year:'numeric',hour:'2-digit',minute:'2-digit'}); }
  function renderChatHistory() { const box = document.querySelector('.ai-history-list'); if (!box) return; if (mimiIdentity === 'guest') { box.innerHTML = '<div class="ai-history-empty">Khách không lưu lịch sử trò chuyện.<br>Đăng nhập Mimi để sử dụng tính năng này.</div>'; return; } if (!chatSessions.length) { box.innerHTML = '<div class="ai-history-empty">Chưa có cuộc trò chuyện nào.</div>'; return; } box.innerHTML = ''; chatSessions.forEach(session => { const row = document.createElement('button'); row.type = 'button'; row.className = 'ai-history-item' + (session.id === activeSessionId ? ' active' : ''); row.innerHTML = `<b>${escapeHtml(session.title || 'Cuộc trò chuyện')}</b><small>${escapeHtml(formatChatTime(session.updated_at || session.created_at))}</small>`; row.addEventListener('click', () => loadChatSession(session.id)); box.appendChild(row); }); }
  let chatSessionsLoadPromise = null, chatSessionsLoadedAt = 0;
  async function loadChatSessions(force = false) {
    if (!force && chatSessionsLoadedAt && Date.now() - chatSessionsLoadedAt < 1800) { renderChatHistory(); return chatSessions; }
    if (chatSessionsLoadPromise) return chatSessionsLoadPromise;
    chatSessionsLoadPromise = (async () => {
      if (mimiIdentity === 'guest') { chatSessions = []; chatSessionsLoadedAt = Date.now(); renderChatHistory(); return chatSessions; }
      let loaded = [];
      try { const r = await fetch(apiUrl('mimi_chats_get'), {headers:{Accept:'application/json'}}); const j = await r.json(); if (j && j.ok && Array.isArray(j.sessions)) loaded = j.sessions; } catch (_) {}
      if (!loaded.length) { try { loaded = JSON.parse(localStorage.getItem(chatLocalKey()) || '[]'); } catch (_) {} }
      chatSessions = Array.isArray(loaded) ? loaded : []; chatSessionsLoadedAt = Date.now(); renderChatHistory(); return chatSessions;
    })();
    try { return await chatSessionsLoadPromise; } finally { chatSessionsLoadPromise = null; }
  }
  function loadChatSession(id) { const session = chatSessions.find(s => s.id === id); if (!session) return; activeSessionId = session.id; state.conversation = normalizeMemory(session.messages); const list = document.querySelector('.ai-messages'); if (list) { list.innerHTML = ''; state.conversation.forEach(m => addMessage(m.role, m.content)); } toggleChatHistory(false); setMood('neutral'); }
  function toggleChatHistory(force) { const panel = document.querySelector('.ai-history-panel'); if (!panel) return; const open = typeof force === 'boolean' ? force : !panel.classList.contains('open'); panel.classList.toggle('open', open); if (open) { renderChatHistory(); loadChatSessions(false); } }
  async function clearChatHistory() {
    if (mimiIdentity === 'guest') { addMessage('assistant', 'Khách chưa lưu lịch sử trò chuyện nào.'); setMood('neutral'); return; }
    if (!window.confirm('Xóa toàn bộ lịch sử trò chuyện của tài khoản Mimi hiện tại?')) return;
    const button = document.querySelector('.ai-history-clear');
    if (button) { button.disabled = true; button.textContent = '…'; }
    try {
      const response = await fetch(apiUrl('mimi_chats_clear'), {method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'}, body:'{}'});
      const result = await response.json();
      if (!result || !result.ok) throw new Error(result?.error || 'Không xóa được lịch sử trò chuyện');
      clearTimeout(chatSaveTimer);
      chatSessions = [];
      chatSessionsLoadedAt = 0;
      activeSessionId = null;
      try { localStorage.removeItem(chatLocalKey()); } catch (_) {}
      state.conversation = [];
      const list = document.querySelector('.ai-messages');
      if (list) { list.innerHTML = ''; addMessage('assistant', 'Đã xóa toàn bộ lịch sử trò chuyện của tài khoản này.'); addSuggestions(); }
      renderChatHistory();
      setMood('success');
    } catch (error) {
      addMessage('assistant', 'Mimi chưa xóa được lịch sử: ' + error.message);
      setMood('alert');
    } finally { if (button) { button.disabled = false; button.textContent = '🗑️'; } }
  }
  function toggleLogoutConfirm(force) { const box = document.querySelector('.ai-logout-confirm'); if (!box) return; const open = typeof force === 'boolean' ? force : !box.classList.contains('open'); box.classList.toggle('open', open); box.setAttribute('aria-hidden', open ? 'false' : 'true'); }
  function saveConversation() {
    const snapshot = normalizeMemory(state.conversation);
    if (mimiIdentity === 'guest') { try { localStorage.removeItem(memoryKey()); } catch (_) {} return; }
    try { localStorage.setItem(memoryKey(), JSON.stringify(snapshot)); } catch (_) {}
    ensureChatSession(snapshot); saveChatSessions();
    clearTimeout(memorySaveTimer);
    memorySaveTimer = setTimeout(() => fetch(apiUrl('mimi_memory_save'), {method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'}, body:JSON.stringify({messages:snapshot})}).catch(() => {}), 250);
  }
  async function renderSavedConversation() {
    const list = document.querySelector('.ai-messages'); if (!list) return;
    const sessionsPromise = loadChatSessions();
    let saved = [];
    try { const r = await fetch(apiUrl('mimi_memory_get'), {headers:{Accept:'application/json'}}); const j = await r.json(); if (j && j.ok) saved = normalizeMemory(j.messages); } catch (_) {}
    if (!saved.length) { try { saved = normalizeMemory(JSON.parse(localStorage.getItem(memoryKey()) || '[]')); } catch (_) {} }
    if (!saved.length) { resetChat(); await sessionsPromise; return; }
    state.conversation = saved; list.innerHTML = ''; state.conversation.forEach(m => addMessage(m.role, m.content)); await sessionsPromise;
  }
  async function loadIdentityAndMemory() { try { let j; if (window.fpAuthInfoPromise) j = await window.fpAuthInfoPromise; else { const r = await fetch(apiUrl('auth_status'), {headers:{Accept:'application/json'}}); j = await r.json(); } const label = document.querySelector('.ai-user'); if (j && j.authenticated && j.user) { mimiIdentity = j.user; chatSessionsLoadedAt = 0; if (label) label.textContent = ' · ' + j.user + (j.developer ? ' · Developer' : ''); await renderSavedConversation(); } else { mimiIdentity = 'guest'; chatSessionsLoadedAt = 0; if (label) label.textContent = ' · Khách'; } } catch (_) {} }
  function foldAuthText(value) { return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').replace(/đ/g, 'd').replace(/Đ/g, 'D').replace(/\s+/g, '').toLowerCase(); }
  async function handleAuthCommand(text) {
    const lower = foldAuthText(text);
    const aliases = {mimivip01:'MimiVip01', apimimi1:'Apimimi01', apimimi01:'Apimimi01', apimimi5:'Apimimi05', apimimi05:'Apimimi05', apimimi10:'Apimimi10'};
    if (['dangxuat','logout','mimilogout','thoat'].includes(lower)) {
      if (mimiIdentity === 'guest') { addMessage('assistant', 'Bạn đang ở chế độ khách rồi.'); setMood('warm'); return true; }
      try { await fetch(apiUrl('auth_logout'), {method:'POST', headers:{Accept:'application/json'}}); } catch (_) {}
      archiveCurrentChat(); clearTimeout(memorySaveTimer); memorySaveTimer = null; mimiIdentity = 'guest'; state.conversation = []; chatSessions = []; chatSessionsLoadedAt = 0; activeSessionId = null; window.mimiCanManageModules = false;
      if (typeof window.loadCustomModules === 'function') window.loadCustomModules();
      const label = document.querySelector('.ai-user'); if (label) label.textContent = ' · Khách';
      const list = document.querySelector('.ai-messages'); if (list) list.innerHTML = '';
      resetChat(); addMessage('assistant', 'Đã đăng xuất Mimi. Bạn vẫn có thể dùng giao diện ở chế độ khách.'); setMood('success'); return true;
    }
    if (lower === 'apimimi') { addMessage('assistant', 'Bạn gửi đúng tên để đăng nhập nhé: Apimimi01, Apimimi05, Apimimi10 hoặc MimiVip01.'); setMood('warm'); return true; }
    const tokenMatch = lower.match(/(mimivip01|apimimi(?:01|05|10|1|5))/);
    if (!tokenMatch) return false;
    const token = tokenMatch[1];
    const surrounding = lower.replace(token, '');
    if (surrounding && !/(dangnhap|login|vao|chon|taikhoan|mimi)/.test(surrounding)) return false;
    const name = aliases[token];
    setBusy(true);
    try {
      const response = await fetch(apiUrl('auth_login'), {method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'}, body:JSON.stringify({name})});
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.ok) throw new Error(data.error || 'Không xác minh được tên tài khoản');
      clearTimeout(memorySaveTimer); memorySaveTimer = null; mimiIdentity = data.user || name; state.conversation = []; window.mimiCanManageModules = !!data.developer; window.mimiCanManageModels = !!data.developer; chatSessions = []; chatSessionsLoadedAt = 0; activeSessionId = null;
      if (typeof window.loadCustomModules === 'function') await window.loadCustomModules();
      const label = document.querySelector('.ai-user'); if (label) label.textContent = ' · ' + mimiIdentity + (data.developer ? ' · Developer' : '');
      const list = document.querySelector('.ai-messages'); if (list) list.innerHTML = '';
      await renderSavedConversation();
      addMessage('assistant', `Đã đăng nhập ${mimiIdentity}. Mimi đã chuyển sang bộ nhớ riêng của tài khoản này.`);
      setMood('success');
    } catch (error) { addMessage('assistant', 'Mimi chưa đăng nhập được: ' + error.message); setMood('alert'); }
    finally { setBusy(false); }
    return true;
  }
  function developerTargetForRequest(request) { if (mimiIdentity !== 'MimiVip01') return ''; const match = String(request || '').match(/\b(MimiVip01|Apimimi(?:01|05|10))\b/i); if (!match) return ''; const name = match[1].toLowerCase() === 'mimivip01' ? 'MimiVip01' : ('Apimimi' + match[1].slice(-2)); return name === 'MimiVip01' ? '' : name; }
  function applyDeveloperTarget(plan, request) { const target = developerTargetForRequest(request); if (!target || !plan || !Array.isArray(plan.steps)) return plan; plan.steps = plan.steps.map(step => Object.assign({}, step, {params:Object.assign({}, step?.params || {}, {_target_user:target})})); return plan; }
  function resetChat() { state.conversation = []; state.help = null; window.aiHelpUserRequest = ''; hideGuide(); try { localStorage.removeItem(memoryKey()); } catch (_) {} saveConversation(); const list = document.querySelector('.ai-messages'); if (!list) return; list.innerHTML = ''; setMood('neutral'); addMessage('assistant', 'Mình là trợ lý AI phát triển bởi Kiệt tạo ra cho api.php. Mình sẽ giải thích ngắn gọn. Nếu bạn muốn mình làm giúp, hãy nói rõ việc cần làm.'); addSuggestions(); }
  function accountPool() { const recent = []; if (typeof lastAccounts !== 'undefined') Object.values(lastAccounts || {}).forEach(a => { if (a && (a.username || a.email)) recent.push(a); }); if (typeof ACCOUNTS !== 'undefined') (ACCOUNTS || []).slice().reverse().forEach(a => { if (a && (a.username || a.email)) recent.push(a); }); const out = []; const seen = new Set(); recent.forEach(a => { const email = String(a.email || a.username || '').trim().toLowerCase(); const key = (a.type || 'ldplayer') + '|' + email; if (email && !seen.has(key)) { seen.add(key); out.push(a); } }); return out; }
  function latestAccount() { return accountPool()[0] || null; }
  function accountType(a, fallback = '') { const raw = String(a?.type || a?.account_type || a?.kind || fallback).toLowerCase(); if (/ldplayer|ld\b/.test(raw)) return 'ldplayer'; if (/funpass|fun\b|fp\b/.test(raw)) return 'funpass'; return fallback || ''; }
  function accountTypeLabel(a, fallback = '') { return accountType(a, fallback) === 'ldplayer' ? 'Ldplayer' : 'FunPass'; }
  function accountSummary(a) { const type = accountTypeLabel(a, 'funpass'); const email = a.email || a.username || '(chưa có email)'; const pass = a.password || '(mật khẩu không được trả về)'; const when = a.created_at || a.saved; const lines = ['Tài khoản Mimi vừa tạo là:', '', type + ':', '• Mail: ' + email, '• password: ' + pass]; if (when) lines.push('• Tạo lúc: ' + when); return lines.join('\n'); }
  function accountFromActionResult(result, action = '') { const found = []; const positions = new Map(); const actionType = /^(fp_create_funpass|fp_create_funpass_batch)$/.test(action) ? 'funpass' : action === 'fp_create_ldplayer' ? 'ldplayer' : ''; const childFallback = key => /ldplayer|ld/i.test(String(key)) ? 'ldplayer' : /funpass|funpass|fp/i.test(String(key)) ? 'funpass' : ''; const visit = (value, fallbackType = '') => { if (!value || typeof value !== 'object' || found.length >= 8) return; const inferred = accountType(value, fallbackType); if (value.email || value.username || value.password) { const email = String(value.email || value.username || '').trim().toLowerCase(); if (!email) return; const type = inferred || actionType || 'funpass'; const key = type + '|' + email; const prior = positions.get(key); const score = v => ['email','username','password','uid','token','created_at','saved'].reduce((n,k) => n + (v?.[k] ? 1 : 0), 0); if (prior === undefined) { positions.set(key, found.length); found.push(Object.assign({}, value, {type})); } else if (score(value) > score(found[prior])) found[prior] = Object.assign({}, found[prior], value, {type}); return; } Object.entries(value).forEach(([key, child]) => visit(child, childFallback(key) || inferred || fallbackType)); }; visit(result?.created_accounts, actionType); visit(result?.data, actionType); visit(result?.account, actionType); visit(result?.funpass, 'funpass'); visit(result?.ldplayer, 'ldplayer'); found.sort((a, b) => (accountType(a) === 'funpass' ? 0 : 1) - (accountType(b) === 'funpass' ? 0 : 1)); return found; }
  function addCreatedAccountReply(result, action = '') { const accounts = accountFromActionResult(result, action); if (!accounts.length) return; const lines = ['Tài khoản Mimi vừa tạo là:', '']; accounts.forEach((a, i) => { lines.push(accountTypeLabel(a, action === 'fp_create_ldplayer' ? 'ldplayer' : 'funpass') + ':'); lines.push('• Mail: ' + (a.email || a.username || '(chưa có email)')); lines.push('• password: ' + (a.password || '(mật khẩu không được trả về)')); if (a.created_at || a.saved) lines.push('• Tạo lúc: ' + (a.created_at || a.saved)); if (i < accounts.length - 1) lines.push(''); }); addMessage('assistant', lines.join('\n')); setMood('success'); }
  function focusAccountRow(a) { const email = String(a.email || a.username || '').trim().toLowerCase(); const type = a.type === 'funpass' ? 'accListFp' : 'accListLd'; const go = () => { const list = document.getElementById(type); if (!list) return; const row = Array.from(list.querySelectorAll('.accitem')).find(x => x.textContent.toLowerCase().includes(email)); if (row) { row.classList.add('ai-account-focus'); row.scrollIntoView({behavior:'smooth', block:'center'}); setTimeout(() => row.classList.remove('ai-account-focus'), 7000); const arrow = row.querySelector('.btn-arrow'); if (arrow && arrow.textContent === '↓') arrow.click(); } }; activateTab('accounts'); if (typeof ACCOUNTS !== 'undefined' && Array.isArray(ACCOUNTS) && ACCOUNTS.length) go(); else if (typeof loadAccounts === 'function') Promise.resolve(loadAccounts()).then(go); else go(); }
  function attachAccountDetailButton(bubble, account) { const b = document.createElement('button'); b.className = 'ai-guide-btn'; b.type = 'button'; b.textContent = 'Mở chi tiết tài khoản'; b.addEventListener('click', () => focusAccountRow(account)); bubble.appendChild(b); }
  async function showCompactAccountList() { const item = addMessage('assistant', ''); if (!item) return; item.bubble.innerHTML = '<b>Danh sách Tài Khoản</b><div class="ai-account-mini">Đang tải danh sách...</div>'; try { const list = (typeof ACCOUNTS !== 'undefined' && Array.isArray(ACCOUNTS) && ACCOUNTS.length) ? ACCOUNTS : (typeof loadAccounts === 'function' ? await loadAccounts() : []); const box = item.bubble.querySelector('.ai-account-mini'); if (!list.length) { box.textContent = 'Chưa có tài khoản nào được lưu.'; return; } box.innerHTML = list.map(a => `<div class="ai-account-mini-row"><span>${escapeHtml(a.email || a.username || '(không có mail)')}</span><small>${escapeHtml(a.created_at || a.saved || 'chưa rõ thời gian')}</small></div>`).join(''); } catch (err) { item.bubble.querySelector('.ai-account-mini').textContent = 'Mimi chưa tải được danh sách tài khoản.'; setMood('alert'); } }
  function attachAccountListButton(bubble) { const b = document.createElement('button'); b.className = 'ai-guide-btn ai-account-list-btn'; b.type = 'button'; b.textContent = 'Tài Khoản'; b.addEventListener('click', showCompactAccountList); bubble.appendChild(b); }
  function resultValue(result) { const values = [result?.balance, result?.data?.balance, result?.data?.wallet, result?.data?.diamond, result?.data?.points, result?.earned]; return values.find(v => v !== undefined && v !== null && v !== ''); }
  function addActionResultReply(result, action, request = '') { if (!result || !result.ok || !action) return; if (/^(fp_create_funpass|fp_create_funpass_batch|fp_create_ldplayer|flow_)/.test(action)) { addCreatedAccountReply(result, action); return; } const labels = {fp_wallet:'Ví tài khoản',fp_cpi_run:'Kết quả chạy CPI',fp_login_funpass:'Đăng nhập Funpass',fp_login_ldplayer:'Đăng nhập LDPlayer',fp_create_ldplayer:'Tài khoản LDPlayer',proxy_add:'Proxy',proxy_save:'Cấu hình proxy',module_upload:'File PHP',module_delete:'File PHP',acc_save:'Tài khoản'}; const name = labels[action] || actionLabel(action); const value = resultValue(result); let text = ''; if (action === 'fp_wallet') text = /vừa tạo|mới tạo|tài khoản vừa/i.test(request) ? `Ví tài khoản vừa tạo là: ${value ?? 'chưa đọc được số dư'}.` : `Ví tài khoản là: ${value ?? 'chưa đọc được số dư'}.`; else if (action === 'fp_cpi_run') text = `${name} đã chạy xong${value !== undefined ? `, nhận được ${value} điểm` : ''}.`; else if (/^mt_/.test(action)) { const d = result.data || {}; const skuCount = Array.isArray(result.skus) ? result.skus.length : (Array.isArray(result.data) ? result.data.length : 0); const order = d.result || d; const orderNo = order.orderNo || order.order_no || order._decrypted?.orderNo || ''; const goods = order.goodsName || order._decrypted?.goodsName || d.bestSku?.name || ''; const cards = []; const scan = x => { if (!x || typeof x !== 'object' || cards.length >= 3) return; if (Array.isArray(x)) return x.forEach(scan); const c = x.config && typeof x.config === 'object' ? x.config : x; if ((c.cardNumber || c.card_no) && (c.password || c.passwd)) cards.push({number:c.cardNumber || c.card_no, password:c.password || c.passwd}); Object.values(x).forEach(y => { if (y && typeof y === 'object') scan(y); }); }; scan(order); if (action === 'mt_balance') text = `Số dư Mua Thẻ hiện tại: ${result.balance ?? 'chưa đọc được'} điểm.`; else if (action === 'mt_skus' || action === 'mt_available_skus') text = `Mimi đã tải ${skuCount} sản phẩm/SKU. Bạn có thể chọn SKU trong mục Mua Thẻ.`; else if (action === 'mt_login') text = `Đã đăng nhập Mua Thẻ. UID: ${d.uid || '—'}; Android ID: ${d.androidId || d.androidid || '—'}.`; else if (action === 'mt_create_order' || action === 'mt_buy_auto' || action === 'mt_snipe') { text = `Mua Thẻ đã hoàn tất${goods ? `: ${goods}` : ''}${orderNo ? `\nMã đơn: ${orderNo}` : ''}.`; if (cards.length) text += `\nMã thẻ: ${cards[0].number}\nMật khẩu thẻ: ${cards[0].password}`; } else if (action === 'mt_gift_list' || action === 'mt_gift_detail') text = `Mimi đã tải thông tin ${action === 'mt_gift_list' ? 'thẻ đã mua' : 'đơn hàng'} trong mục Mua Thẻ${orderNo ? `\nMã đơn: ${orderNo}` : ''}.`; else text = `${name} đã thực hiện xong.`; } else if (action === 'proxy_add' || action === 'proxy_save' || action === 'module_upload' || action === 'module_delete') text = result.msg || `${name} đã xử lý xong.`; else text = `${name} đã thực hiện xong.`; const item = addMessage('assistant', text); if (item && action === 'fp_wallet' && !/vừa tạo|mới tạo|tài khoản vừa/i.test(request)) attachAccountListButton(item.bubble); setMood('success'); }
  function handleLocalAccountQuestion(text) { if (!/(tài khoản|account|mail|mật khẩu|password)/i.test(text) || !/(vừa tạo|mới tạo|mới nhất|ở đâu|chi tiết|đầy đủ|thông tin)/i.test(text)) return false; const a = latestAccount(); if (!a) { addMessage('assistant', 'Mình chưa thấy tài khoản mới trong phiên này. Hãy tạo hoặc đăng nhập tài khoản trước, rồi hỏi mình lại nhé.'); setMood('apology'); return true; } const detail = /(chi tiết|đầy đủ|tất cả|điều hướng|mục tài khoản)/i.test(text); const answer = addMessage('assistant', detail ? `Được, mình đưa bạn tới đúng tài khoản trong mục Tài khoản.\nEmail đang tìm: ${a.email || a.username || '(chưa có email)'}` : accountSummary(a)); if (detail && answer) attachAccountDetailButton(answer.bubble, a); if (detail) setTimeout(() => focusAccountRow(a), 0); else setMood('warm'); return true; }
  function handleLocalFunctionList(text) { const n = foldAuthText(text); if (!/(danhsach|lietke|chucnang|tinhang|hotro|lamduoc|actions?)/.test(n) || !/(api|chucnang|tinhang|actions?|hotro|lamduoc|cogi)/.test(n)) return false; const answer = 'Mimi có thể giúp bạn:\n\n**Kết nối và hệ thống**\nKiểm tra app có hoạt động không, xem trạng thái và xem lịch sử thao tác.\n\n**Proxy**\nXem, thêm và quản lý proxy Mỹ hoặc Việt Nam.\n\n**FunPass và LDPlayer**\nTạo tài khoản, đăng nhập, xem ví, chạy CPI và chuyển điểm.\n\n**Khoga Flow**\nChạy quy trình Khoga hoặc một chu kỳ FunPass.\n\n**Mua thẻ**\nĐăng nhập, xem số dư, xem sản phẩm, tạo đơn và mua thẻ tự động.\n\n**Tài khoản đã lưu**\nXem, lưu và xóa tài khoản đã tạo.\n\n**File PHP riêng**\nThêm, mở và sử dụng file PHP riêng; chỉ Developer được xóa hoặc gỡ file.\n\nBạn chỉ cần nói việc muốn làm, Mimi sẽ hướng dẫn hoặc làm giúp từng bước.'; addMessage('assistant', answer); setMood('warm'); return true; }

  function attachCommand(bubble, command) { if (!validAction(command.action)) return; const card = document.createElement('div'); card.className = 'ai-command'; card.innerHTML = `<b>Chức năng đã sẵn sàng</b><button class="ai-run" type="button">Thực hiện</button>`; const btn = card.querySelector('button'); btn.addEventListener('click', async () => { btn.disabled = true; btn.textContent = 'Đang thực hiện'; setMood('thinking'); try { if (typeof window.fpApiCall !== 'function') throw new Error('API giao diện chưa sẵn sàng'); const result = await window.fpApiCall(command.action, command.params || {}); if (result && result.need_captcha) { btn.disabled = false; btn.textContent = 'Nhập captcha rồi thử lại'; setMood('alert'); return; }       btn.textContent = 'Đã thực hiện'; addActionResultReply(result, command.action, state.lastUserRequest || ''); addMessage('assistant', 'Mimi đã làm xong yêu cầu!'); setMood('success'); } catch (err) { btn.disabled = false; btn.textContent = 'Thử lại'; setMood('alert'); addMessage('assistant', `Không thực hiện được. ${err.message}`); } }); bubble.appendChild(card); }
  function tabLabel(tab) { return ({auto:'Funpass Auto',khoga:'Kho tài khoản',muathe:'Mua thẻ',proxy:'Proxy',accounts:'Tài khoản',custommod:'Module riêng'})[tab] || 'khu vực tương ứng'; }
  function targetLabel(target) { return ({'#console':'bảng Console','#m_email':'ô Email','#m_pass':'ô Mật khẩu','#m_android_id':'ô Android ID','#k_android_id':'ô Android ID của Khoga','#a_android_id':'ô Android ID của Funpass Auto','#mt_android_id':'ô Android ID Mua Thẻ','#m_create_funpass':'nút Tạo Funpass mới','#m_create_ldplayer':'nút Tạo LDPlayer','#m_login_funpass':'nút Đăng nhập Funpass','#m_login_ldplayer':'nút Đăng nhập LDPlayer','#p_us_list':'danh sách proxy Mỹ','#p_vn_list':'danh sách proxy Việt Nam','#accListFp':'danh sách tài khoản FunPass','#accListLd':'danh sách tài khoản LDPlayer','#t_fpuid':'ô UID FunPass','#mt_user':'ô tài khoản mua thẻ','#navAuto':'mục Funpass Auto','#navKhoga':'mục Khoga Flow','#navMuathe':'mục Mua Thẻ','#navProxy':'mục Proxy','#navAccounts':'mục Tài Khoản','#navCustomMods':'mục file PHP dưới Funpass Auto','#modListBox':'danh sách file PHP trong Tài Khoản','#burger':'nút menu ba gạch'})[target] || (target ? 'mục cần làm' : 'mục đang mở'); }
  function actionLabel(action) { return ({ping:'kiểm tra kết nối',proxy_add:'thêm proxy',proxy_get:'xem proxy',proxy_save:'lưu proxy',acc_list:'tải danh sách tài khoản',acc_save:'lưu tài khoản',acc_delete:'xóa tài khoản',fp_create_funpass:'tạo tài khoản FunPass',fp_create_ldplayer:'tạo tài khoản LDPlayer',module_upload:'thêm file PHP',module_delete:'xóa file PHP'}[action] || action || 'thao tác này'); }
  function stepDetails(step, index) { const fields = step?.fill && typeof step.fill === 'object' ? Object.keys(step.fill).map(id => targetLabel('#' + id)).join(', ') : ''; const action = step?.action ? 'thực hiện ' + actionLabel(step.action) : (step?.click ? 'bấm mục đang đánh dấu' : 'kiểm tra mục này'); return `${index + 1}. ${step?.label || action}. Mở mục ${tabLabel(step?.tab)}, tới ${targetLabel(step?.target)}${fields ? ', điền ' + fields : ''}.`; }

  function inlineRunStatus(card, text, mood) { const el = card.querySelector('.ai-help-status'); if (el) el.textContent = text; if (mood) setMood(mood); }
    async function runFunpassBatch(count, targetUser = '', extraParams = {}) {
    const n = Math.max(2, Math.min(5, Number(count) || 0));
    const poolUrl = apiUrl('proxy_get') + (targetUser ? '&_target_user=' + encodeURIComponent(targetUser) : '');
    const poolResponse = await fetch(poolUrl, {headers:{Accept:'application/json'}});
    const pool = await poolResponse.json();
    const bad = new Set(Array.isArray(pool.bad_us) ? pool.bad_us : []);
    const proxies = Array.from(new Set((Array.isArray(pool.proxy_us) ? pool.proxy_us : []).filter(p => p && !bad.has(p))));
    if (proxies.length < n) return {ok:false, error:`Bạn yêu cầu ${n} tài khoản nhưng hiện chỉ có ${proxies.length} proxy Mỹ dùng được. Cần thêm ${n - proxies.length} proxy US rồi thử lại.`};
    const jobs = proxies.slice(0, n).map((proxy, index) => { const payload = Object.assign({}, extraParams || {}, {proxy, _label:`Tạo Funpass ${index + 1}/${n}`}); if (targetUser) payload._target_user = targetUser; return fetch(apiUrl('fp_create_funpass'), {method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'}, body:JSON.stringify(payload)}).then(async response => { const data = await response.json().catch(() => ({})); return Object.assign(data, {batch_index:index + 1, batch_proxy:proxy}); }); });
    const settled = await Promise.allSettled(jobs);
    const results = settled.map((x, i) => x.status === 'fulfilled' ? x.value : ({ok:false, error:x.reason?.message || `Tài khoản ${i + 1} lỗi`, batch_index:i + 1}));
    const accounts = results.flatMap(accountFromActionResult);
    const errors = results.filter(x => !x || !x.ok).map(x => `Tài khoản ${x.batch_index || '?'}: ${x.error || (x.need_captcha ? 'cần nhập captcha' : 'chưa tạo được')}`);
    return {ok: errors.length === 0, accounts, results, errors};
  }
  function batchCountFromPlan(plan, step) { const raw = plan?.batch_count ?? step?.batch_count ?? step?.params?.count ?? step?.params?.quantity ?? 0; const n = Number(raw); return Number.isInteger(n) && n >= 2 && n <= 5 ? n : 0; }
  async function runActionBatch(action, params, count) { const clean = Object.assign({}, params || {}); delete clean.count; delete clean.quantity; const jobs = Array.from({length:count}, (_, i) => fetch(apiUrl(action), {method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json'}, body:JSON.stringify(Object.assign({}, clean, {_label:`${actionLabel(action)} ${i + 1}/${count}`}))}).then(async response => Object.assign(await response.json().catch(() => ({})), {batch_index:i + 1}))); const settled = await Promise.allSettled(jobs); return settled.map((x, i) => x.status === 'fulfilled' ? x.value : ({ok:false, error:x.reason?.message || `Tác vụ ${i + 1} lỗi`, batch_index:i + 1})); }
  function lockHelpCard(card, chosen) { if (!card || card.dataset.actionChosen === '1') return false; card.dataset.actionChosen = '1'; card.dataset.actionMode = chosen || ''; const guide = card.querySelector('.ai-help-guide'); const run = card.querySelector('.ai-help-run'); [guide, run].forEach(btn => { if (btn) { btn.disabled = true; btn.textContent = 'Đã Xong'; btn.setAttribute('aria-disabled','true'); } }); card.classList.add('ai-card-locked'); return true; }
  function finishHelpCard(card, success, message) { if (!card) return; card.dataset.actionFinished = success ? '1' : '0'; const result = card.querySelector('.ai-help-result'); if (result) result.textContent = success ? 'Mimi đã làm xong yêu cầu!' : (message || 'Mimi chưa làm xong yêu cầu này.'); }
  async function runPlanInsideChat(plan, card) {
    if (state.inlineRunning || !lockHelpCard(card, 'run')) return;
    const steps = Array.isArray(plan.steps) ? plan.steps.map(normalizeHelpStep) : [];
    if (!steps.length) return;
    state.inlineRunning = true;
    window.aiHelpUserRequest = String(plan.request || state.lastUserRequest || '');
    const run = card.querySelector('.ai-help-run');
    const guide = card.querySelector('.ai-help-guide');
    if (run) { run.disabled = true; run.textContent = 'Mimi đang làm...'; }
    if (guide) guide.disabled = true;
    try {
      const batchCount = batchCountFromPlan(plan, steps[0]);
      if (batchCount && steps.length === 1) {
        const batchStep = steps[0]; const current = card.querySelector('.ai-help-current');
        if (batchStep.action === 'fp_create_funpass') {
          if (current) current.textContent = `Đang tạo ${batchCount} tài khoản, mỗi tài khoản dùng một proxy US riêng...`;
          const batch = await runFunpassBatch(batchCount, developerTargetForRequest(window.aiHelpUserRequest), batchStep.params || {});
          if (batch.accounts.length) addCreatedAccountReply({created_accounts: batch.accounts});
          if (batch.errors.length) throw new Error(batch.errors.join(' | '));
        } else if (batchStep.action) {
          if (current) current.textContent = `Đang chạy ${batchCount} lần: ${actionLabel(batchStep.action)}...`;
          const results = await runActionBatch(batchStep.action, batchStep.params || {}, batchCount);
          results.filter(x => x.ok).forEach(x => addActionResultReply(x, batchStep.action, window.aiHelpUserRequest || ''));
          const errors = results.filter(x => !x.ok).map(x => `Tác vụ ${x.batch_index || '?'}: ${x.error || 'thất bại'}`);
          if (errors.length) throw new Error(errors.join(' | '));
        } else throw new Error('Chưa có action để chạy lặp');
        finishHelpCard(card, true);
        setMood('success'); addMessage('assistant', 'Mimi đã làm xong yêu cầu!');
        return;
      }
      for (let i = 0; i < steps.length; i += 1) {
        const step = steps[i];
        const current = card.querySelector('.ai-help-current');
        if (current) current.textContent = `Bước ${i + 1}/${steps.length}: ${step.label || 'đang xử lý'}`;
        if (step.fill) fillFields(step.fill);
        if (step.wait_input || step.action === 'module_delete') {
          inlineRunStatus(card, step.action === 'module_delete' ? 'Đây là bước xóa file. Hãy xác nhận nếu đúng tên file này.' : 'Mimi đang chờ bạn nhập xong rồi bấm Tiếp tục.', 'alert');
          const next = document.createElement('button'); next.className = 'ai-help-next'; next.type = 'button'; next.textContent = step.action === 'module_delete' ? 'Xác nhận xóa' : 'Tiếp tục'; card.appendChild(next);
          await new Promise(resolve => next.addEventListener('click', () => { next.remove(); resolve(); }, {once:true}));
        }
        if (step.action) {
          if (!validAction(step.action)) throw new Error('Action không có trong api.php');
          inlineRunStatus(card, `Đang làm: ${step.label || step.action}`, 'thinking');
          const result = await window.fpApiCall(step.action, step.params || {});
          if (result && result.need_captcha) throw new Error('Cần bạn nhập captcha rồi thử lại');
          if (!result) throw new Error('Action chưa trả về kết quả');
          if (result.ok === false) throw new Error(result.error || 'Chức năng trả về lỗi');
          addActionResultReply(result, step.action, window.aiHelpUserRequest || '');
          if (step.action === 'module_delete' && typeof window.loadCustomModules === 'function') await window.loadCustomModules();
        } else if (step.click && validTarget(step.target)) {
          const target = document.querySelector(step.target); if (!target) throw new Error('Không tìm thấy mục cần bấm'); target.click();
        }
        inlineRunStatus(card, `Đã xong bước ${i + 1}/${steps.length}: ${step.label || 'yêu cầu của bạn'}`, 'success');
      }
      finishHelpCard(card, true);
      setMood('success');
      addMessage('assistant', 'Mimi đã làm xong yêu cầu!');
    } catch (err) {
      const errorText = 'Mimi chưa làm xong được việc này: ' + err.message;
      inlineRunStatus(card, errorText, 'alert');
      finishHelpCard(card, false, errorText);
      addMessage('assistant', errorText);
    } finally { state.inlineRunning = false; }
  }
  async function uploadPhp(file) { if (!file) return; if (!/\.php$/i.test(file.name)) { addMessage('assistant', 'Mimi chỉ nhận file PHP có đuôi .php nha.'); setMood('alert'); return; } if (file.size > 2 * 1024 * 1024) { addMessage('assistant', 'File hơi lớn rồi. Mimi nhận tối đa 2 MB cho mỗi file PHP.'); setMood('alert'); return; } const item = addMessage('assistant', ''); if (!item) return; const safeName = escapeHtml(file.name); const defaultLabel = escapeHtml(file.name.replace(/\.php$/i, '').replace(/[_-]+/g, ' ').trim() || 'Module PHP mới'); item.bubble.innerHTML = `<div class="ai-upload-card"><b>🧩 Bạn muốn thêm một mục mới với chức năng PHP này?</b><div class="ai-status" style="margin-top:4px">File: ${safeName}</div><input class="ai-upload-label" value="${defaultLabel}" aria-label="Tên mục PHP" placeholder="Tên mục hiển thị"><div class="ai-upload-actions"><button class="ai-help-run ai-upload-confirm" type="button">Xác nhận thêm</button><button class="ai-help-guide ai-upload-cancel" type="button">Hủy</button></div></div>`; const card = item.bubble.querySelector('.ai-upload-card'); const confirmBtn = card.querySelector('.ai-upload-confirm'); const cancelBtn = card.querySelector('.ai-upload-cancel'); cancelBtn.addEventListener('click', () => { item.bubble.textContent = 'Mimi chưa thêm file này vào server.'; setMood('neutral'); }); confirmBtn.addEventListener('click', async () => { const label = card.querySelector('.ai-upload-label').value.trim() || file.name.replace(/\.php$/i, ''); confirmBtn.disabled = true; cancelBtn.disabled = true; confirmBtn.textContent = 'Đang thêm...'; setMood('thinking'); try { const form = new FormData(); form.append('module', file, file.name); form.append('label', label); const response = await fetch(location.pathname + '?action=module_upload', {method:'POST', body:form}); const result = await response.json(); if (!result.ok) throw new Error(result.error || 'Server chưa nhận được file'); if (typeof window.loadCustomModules === 'function') await window.loadCustomModules(); item.bubble.innerHTML = `Đã thêm mục <b>${escapeHtml(label)}</b> với file <code>${safeName}</code>. File đã xuất hiện dưới Funpass Auto và trong mục Tài Khoản.`; addMessage('assistant', 'Mimi đã thêm xong mục PHP cho bạn!'); setMood('success'); } catch (err) { confirmBtn.disabled = false; cancelBtn.disabled = false; confirmBtn.textContent = 'Xác nhận thêm'; item.bubble.insertAdjacentHTML('beforeend', `<div class="ai-error" style="margin-top:7px">Mimi chưa thêm được file: ${escapeHtml(err.message)}</div>`); setMood('alert'); } }); setMood('help'); }
  function attachHelpPlan(bubble, plan) { const card = document.createElement('div'); card.className = 'ai-help-card'; const first = Array.isArray(plan.steps) ? plan.steps[0] : null; card.innerHTML = `<div class="ai-card-title"><span class="ai-function-icon">${functionIcon(first)}</span><b>${escapeHtml(plan.title || 'Mimi có thể làm giúp')}</b></div><div class="ai-help-status">Mình hiểu rồi. Bạn muốn Mimi làm việc này giúp bạn.</div><div class="ai-help-current"></div><div class="ai-help-result"></div><div class="ai-help-actions"><button class="ai-help-guide" type="button">Hướng Dẫn</button><button class="ai-help-run" type="button">Làm Giúp</button></div>`; const guide = card.querySelector('.ai-help-guide'); const run = card.querySelector('.ai-help-run'); guide.onclick = ev => { ev.preventDefault(); ev.stopPropagation(); if (!lockHelpCard(card, 'guide')) return; startHelp(plan); }; run.onclick = ev => { ev.preventDefault(); ev.stopPropagation(); runPlanInsideChat(plan, card); }; bubble.appendChild(card); setMood('help'); }
  const ALLOWED_ACTIONS = new Set(['ping','proxy_get','proxy_save','proxy_add','proxy_reset_state','fp_create_funpass','fp_create_ldplayer','fp_login_funpass','fp_login_ldplayer','fp_wallet','fp_cpi_ads','fp_cpi_run','fp_transfer_detail','fp_transfer_execute','flow_khoga_full','flow_funpass_once','mt_login','mt_session','mt_logout','mt_sync','mt_balance','mt_skus','mt_available_skus','mt_gift_list','mt_gift_detail','mt_create_order','mt_buy_auto','mt_snipe','acc_list','acc_save','acc_delete','runlog_list','runlog_clear','proxy_reset_state','create_empty_file','module_upload','module_delete','modules_list','modules_remove']);
  function validTarget(target) { return typeof target === 'string' && /^#[A-Za-z0-9_-]+$/.test(target); }
  function validAction(action) { return typeof action === 'string' && ALLOWED_ACTIONS.has(action); }
  function activateTab(tab) { if (!tab || !/^[A-Za-z0-9_-]+$/.test(tab)) return; const btn = document.querySelector(`nav button[data-tab="${tab}"]`); if (btn) btn.click(); }
  function focusTarget(target) { if (!validTarget(target)) return null; document.querySelectorAll('.ai-target-highlight').forEach(x => x.classList.remove('ai-target-highlight')); const el = document.querySelector(target); if (!el) return null; el.classList.add('ai-target-highlight'); el.scrollIntoView({behavior:'smooth', block:'center'}); setTimeout(() => el.classList.remove('ai-target-highlight'), 4200); return el; }
  const ACTION_ROUTES = { ping:{tab:'auto',target:'#console'}, proxy_get:{tab:'proxy',target:'#featList'}, proxy_save:{tab:'proxy',target:'#featList'}, proxy_add:{tab:'proxy',target:'#p_us_list'}, proxy_reset_state:{tab:'proxy',target:'#featList'}, fp_create_funpass:{tab:'khoga',target:'#m_create_funpass'}, fp_create_ldplayer:{tab:'khoga',target:'#m_create_ldplayer'}, fp_login_funpass:{tab:'khoga',target:'#m_login_funpass'}, fp_login_ldplayer:{tab:'khoga',target:'#m_login_ldplayer'}, fp_wallet:{tab:'khoga',target:'#t_fpuid'}, fp_transfer_detail:{tab:'khoga',target:'#t_fpuid'}, fp_transfer_execute:{tab:'khoga',target:'#t_fpuid'}, flow_khoga_full:{tab:'khoga',target:'#k_mode'}, flow_funpass_once:{tab:'auto',target:'#a_ld_email'}, mt_login:{tab:'muathe',target:'#mt_user'}, mt_logout:{tab:'muathe',target:'#mt_sess'}, mt_session:{tab:'muathe',target:'#mt_sess'}, mt_sync:{tab:'muathe',target:'#mt_sess'}, mt_balance:{tab:'muathe',target:'#mt_sess'}, mt_skus:{tab:'muathe',target:'#mt_sku'}, mt_available_skus:{tab:'muathe',target:'#mt_sku'}, mt_create_order:{tab:'muathe',target:'#mt_sku'}, mt_buy_auto:{tab:'muathe',target:'#mt_sku'}, mt_snipe:{tab:'muathe',target:'#mt_sku'}, mt_gift_list:{tab:'muathe',target:'#mt_page'}, mt_gift_detail:{tab:'muathe',target:'#mt_order'}, acc_list:{tab:'accounts',target:'#accListFp'}, acc_save:{tab:'accounts',target:'#sv_json'}, acc_delete:{tab:'accounts',target:'#accListFp'}, runlog_list:{tab:'auto',target:'#console'}, runlog_clear:{tab:'auto',target:'#console'}, create_empty_file:{tab:'custommod',target:'#navCustomMods'}, modules_list:{tab:'custommod',target:'#navCustomMods'}, modules_remove:{tab:'custommod',target:'#navCustomMods'}, module_upload:{tab:'custommod',target:'#navCustomMods'}, module_delete:{tab:'accounts',target:'#modListBox'} };
  function normalizeHelpStep(step) { const out = Object.assign({}, step || {}); const route = ACTION_ROUTES[out.action]; if (route) { out.tab = route.tab; const pool = String(out.params?.pool || out.params?.country || '').toLowerCase(); if (out.action === 'proxy_add' && ['vn','việt nam','viet nam','vietnam'].includes(pool)) out.target = '#p_vn_list'; else if (!validTarget(out.target) || String(out.target).startsWith('#tab-') || !document.querySelector(out.target)) out.target = route.target; } if (!validTarget(out.target) || !document.querySelector(out.target)) out.target = '#tab-' + (out.tab || 'auto'); return out; }
  function fillFields(fill) { if (!fill || typeof fill !== 'object') return; Object.entries(fill).forEach(([id, value]) => { if (!/^[-A-Za-z0-9_]+$/.test(id)) return; const el = document.getElementById(id); if (!el || typeof value === 'object') return; el.value = String(value ?? ''); el.dispatchEvent(new Event('input', {bubbles:true})); el.dispatchEvent(new Event('change', {bubbles:true})); }); }
  function guideElement() { return document.querySelector('.ai-guide'); }
  function hideGuide() { const guide = guideElement(); if (guide) guide.classList.remove('show'); }
  function showGuide(step, index, total, status, actions = '') {
    let guide = guideElement();
    if (!guide) { guide = document.createElement('div'); guide.className = 'ai-guide'; document.body.appendChild(guide); }
    const text = escapeHtml(step?.label || 'Đang xử lý bước hiện tại');
    const targetText = step?.target ? `Mục cần làm: ${escapeHtml(targetLabel(step.target))}` : 'Mimi đang ở đúng luồng cho bạn';
    guide.innerHTML = `<div class="ai-guide-top"><div class="ai-guide-avatar">${petHtml()}</div><div><div class="ai-guide-title">${functionIcon(step)} Hướng Dẫn · Bước ${index + 1}/${total}</div><div class="ai-status">${escapeHtml(status || 'Sẵn sàng')}</div></div></div><div class="ai-guide-step"><b>${text}</b><br><span class="ai-guide-target">${targetText}</span></div><div class="ai-guide-actions">${actions}</div>`;
    guide.classList.add('show'); setMood(status === 'Đã xong' ? 'success' : (status === 'Cần bạn nhập' ? 'alert' : 'help'));
  }
  function finishHelpStep(index) { const help = state.help; if (!help || help.index !== index) return; const step = help.plan.steps[index];     const doneLabel = 'Đã xong: ' + (step.label || window.aiHelpUserRequest || 'yêu cầu của bạn'); const doneTarget = ['ping','runlog_list','runlog_clear'].includes(step.action) ? '#console' : null; const last = index >= help.plan.steps.length - 1; if (last) { help.index = help.plan.steps.length; showGuide({label:doneLabel,target:doneTarget}, index, help.plan.steps.length, 'Hoàn tất', '<button class="ai-guide-btn ai-close-guide" type="button">Đóng</button>'); const guide = guideElement(); guide.querySelector('.ai-close-guide').addEventListener('click', cancelHelp); } else { showGuide({label:doneLabel,target:doneTarget}, index, help.plan.steps.length, 'Đã xong', '<button class="ai-guide-btn ai-next" type="button">Bước tiếp theo</button><button class="ai-guide-cancel" type="button">Dừng</button>'); const guide = guideElement(); guide.querySelector('.ai-next').addEventListener('click', nextHelpStep); guide.querySelector('.ai-guide-cancel').addEventListener('click', cancelHelp); } if (doneTarget) focusTarget(doneTarget); setMood('success'); }
  async function executeHelpStep(index) {
    const help = state.help; if (!help || help.index !== index) return;
    const step = help.plan.steps[index];
    try {
      if (step.action) {
        if (!validAction(step.action)) throw new Error('Chức năng trong bước này không hợp lệ');
        const result = await window.fpApiCall(step.action, step.params || {});
        if (['ping','runlog_list','runlog_clear'].includes(step.action)) focusTarget('#console');
        if (result && result.need_captcha) { showGuide(step, index, help.plan.steps.length, 'Cần bạn nhập', '<button class="ai-guide-btn ai-continue" type="button">Tôi đã nhập xong</button><button class="ai-guide-cancel" type="button">Dừng</button>'); const guide = guideElement(); guide.querySelector('.ai-continue').addEventListener('click', () => executeHelpStep(index)); guide.querySelector('.ai-guide-cancel').addEventListener('click', cancelHelp); setMood('alert'); return; }
        if (result && result.risk_blocked) { showGuide(step, index, help.plan.steps.length, 'Máy chủ đang tạm dừng an toàn', '<button class="ai-guide-cancel" type="button">Đóng</button>'); const guide = guideElement(); guide.querySelector('.ai-guide-cancel').addEventListener('click', cancelHelp); setMood('alert'); return; }
        if (result && result.ok === false) throw new Error(result.error || 'Chức năng trả về lỗi');
      } else if (step.click && validTarget(step.target)) {
        const target = document.querySelector(step.target); if (!target) throw new Error('Không tìm thấy nút của bước này'); target.click();
      }
      finishHelpStep(index);
    } catch (err) {
      showGuide(step, index, help.plan.steps.length, 'Có lỗi, cần kiểm tra', '<button class="ai-guide-btn ai-retry" type="button">Thử lại</button><button class="ai-guide-cancel" type="button">Dừng</button>'); const guide = guideElement(); guide.querySelector('.ai-retry').addEventListener('click', () => executeHelpStep(index)); guide.querySelector('.ai-guide-cancel').addEventListener('click', cancelHelp); setMood('alert');
    }
  }
  function currentTabName() { const tab = document.querySelector('.tab.active'); return tab ? tab.id.replace(/^tab-/, '') : 'auto'; }
  function navTargetForTab(tab) { return ({auto:'#navAuto',khoga:'#navKhoga',muathe:'#navMuathe',proxy:'#navProxy',accounts:'#navAccounts',custommod:'#navCustomMods'})[tab] || '#navAuto'; }
  function prepareGuidePlan(plan) {
    const original = Array.isArray(plan?.steps) ? plan.steps.map(normalizeHelpStep) : [];
    if (!original.length) return Object.assign({}, plan, {steps: []});
    const targetTab = original[0].tab || 'auto';
    const current = currentTabName();
    const needsMenu = current !== targetTab;
    const prefix = [];
    if (needsMenu && document.body.classList.contains('mob')) prefix.push({label:'Mở menu 3 gạch', tab:current, target:'#burger', guideOnly:'menu_open'});
    if (needsMenu) prefix.push({label:`Chọn mục ${tabLabel(targetTab)}`, tab:targetTab, target:navTargetForTab(targetTab), guideOnly:'tab_choose'});
    return Object.assign({}, plan, {steps: prefix.concat(original)});
  }
  function runHelpStep() {
    const help = state.help; if (!help) return;
    if (window.fpRiskBlockedUntil && Date.now() < window.fpRiskBlockedUntil) { showGuide({label:'Máy chủ đang tạm dừng an toàn'}, help.index, help.plan.steps.length, 'Không tự thử lại', '<button class="ai-guide-btn ai-cancel" type="button">Đóng</button>'); const guide = guideElement(); guide.querySelector('.ai-cancel').addEventListener('click', cancelHelp); return; }
    if (help.index >= help.plan.steps.length) { showGuide({label:'Đã hoàn thành toàn bộ yêu cầu',target:null}, help.plan.steps.length - 1, help.plan.steps.length, 'Hoàn tất', '<button class="ai-guide-btn ai-open-chat" type="button">Mở trợ lý</button>'); const guide = guideElement(); guide.querySelector('.ai-open-chat').addEventListener('click', () => { const panel = document.querySelector('.ai-panel'); state.open = true; panel.classList.add('open'); panel.classList.remove('minimized'); hideGuide(); }); setMood('success'); return; }
    const index = help.index; const step = normalizeHelpStep(help.plan.steps[index]); help.plan.steps[index] = step;
    if (step.guideOnly === 'menu_open') { const burger = document.querySelector('#burger'); if (burger && !document.querySelector('nav')?.classList.contains('open')) burger.click(); }
    else if (!step.guideOnly) activateTab(step.tab);
    focusTarget(step.target);
    const actions = `<button class="ai-guide-btn ai-next" type="button">Bước tiếp theo</button><button class="ai-guide-cancel" type="button">Dừng</button>`;
    const status = step.guideOnly === 'menu_open' ? 'Mimi đã mở menu · hãy xem bước này' : (step.guideOnly === 'tab_choose' ? 'Bấm đúng mục đang được đánh dấu' : (step.action === 'module_delete' ? 'Xác nhận trước khi xóa file' : 'Đã đến đúng mục · hãy làm theo bước này'));
    showGuide(step, index, help.plan.steps.length, status, actions);
    const guide = guideElement(); guide.querySelector('.ai-guide-cancel').addEventListener('click', cancelHelp);
    guide.querySelector('.ai-next').addEventListener('click', nextHelpStep);
  }
  function startHelp(plan) { const prepared = prepareGuidePlan(plan); state.help = {plan:prepared, index:0}; window.aiHelpUserRequest = String(prepared.request || state.lastUserRequest || ''); minimizePanelForGuide(); runHelpStep(); }
  function nextHelpStep() { if (!state.help) return; state.help.index += 1; runHelpStep(); }
  function cancelHelp() { state.help = null; hideGuide(); restorePanelAfterGuide(); setMood('neutral'); }
  function handleLocalGreeting(text) { if (!/^\s*mimi\s*ơi[\s!?.]*$/i.test(text)) return false; addMessage('assistant', 'Ơi, Mimi đây, bạn cần giúp gì nè.'); setMood('warm'); return true; }
  function inputFocus() { const input = document.querySelector('.ai-input'); if (input) input.focus(); }

  async function send(text) {
    text = String(text || '').trim(); if (!text || state.busy) return; const input = document.querySelector('.ai-input'); if (input) { input.value = ''; input.style.height = 'auto'; input.blur(); }
    state.lastUserRequest = text; addMessage('user', text); state.conversation.push({role:'user', content:text}); saveConversation(); if (await handleAuthCommand(text)) return; if (handleLocalGreeting(text)) { state.conversation.push({role:'assistant', content:'Ơi, Mimi đây, bạn cần giúp gì nè.'}); saveConversation(); return; } if (handleLocalAccountQuestion(text)) { state.conversation.push({role:'assistant', content:'Đã tra cứu tài khoản trong phiên hiện tại.'}); saveConversation(); return; } if (handleLocalFunctionList(text)) { state.conversation.push({role:'assistant', content:'Mimi đã giải thích các nhóm chức năng bằng ngôn ngữ dễ hiểu.'}); saveConversation(); return; } if (isUiEditRequest(text)) { await requestUiEdit(text); return; } const typing = addTyping(); setBusy(true);
    try { const raw = sanitizeAiText(await askServer(state.conversation)).trim(); if (!raw) throw new Error('AI không trả lời'); const parsed = extractPayload(raw); if (parsed.help) { parsed.request = text; parsed.help = applyDeveloperTarget(parsed.help, text); }       if (typing) { typing.bubble.innerHTML = markdownLite(parsed.text || 'Mình đã xử lý.'); if (parsed.help) attachHelpPlan(typing.bubble, parsed.help); if (parsed.command) attachCommand(typing.bubble, parsed.command); } state.conversation.push({role:'assistant', content:parsed.text || 'Mình đã xử lý.'}); saveConversation(); setMood(parsed.help ? 'help' : parsed.mood); }
    catch (err) { const msg = String(err && err.message || 'Lỗi không xác định'); const risk = /90001|security risk|tạm dừng thao tác/i.test(msg); if (risk) window.fpRiskBlockedUntil = Date.now() + 10 * 60 * 1000; if (typing) { typing.row.classList.add('ai-error'); typing.bubble.textContent = risk ? 'Máy chủ đang tạm dừng thao tác vì lý do an toàn. Mimi không tự thử lại.' : `Lỗi: ${msg}`; } setMood('alert'); }
    finally { setBusy(false); scrollBottom(); }
  }

  function createUI() {
    const style = document.createElement('style'); style.id = 'aiBuddyStyle'; style.textContent = css; document.head.appendChild(style); preloadAssets();
    const root = document.createElement('div'); root.id = 'aiBuddyRoot'; root.innerHTML = `<button class="ai-launcher" type="button" aria-label="Mở trợ lý AI" title="Trợ lý AI api.php"><span class="ai-orbit"></span>${petHtml()}</button><section class="ai-panel" aria-label="Trợ lý AI"><div class="ai-head"><div class="mini-pet">${petHtml()}</div><div class="ai-head-copy"><div class="ai-title">Mimi Code AI</div><span class="ai-subtitle">Mimi<span class="ai-user"></span> · <span class="ai-status">Sẵn sàng</span></span></div><div class="ai-head-actions"><button class="ai-icon ai-new" type="button" title="Cuộc trò chuyện mới" aria-label="Cuộc trò chuyện mới">💬</button><button class="ai-icon ai-history-head-trigger" type="button" title="Lịch sử trò chuyện" aria-label="Lịch sử trò chuyện">📁</button><button class="ai-icon ai-min" type="button" title="Thu gọn hoặc mở rộng" aria-label="Thu gọn hoặc mở rộng">↑↓</button><button class="ai-icon ai-logout" type="button" title="Tài khoản Mimi / Đăng xuất" aria-label="Tài khoản Mimi / Đăng xuất">🪪</button><button class="ai-icon ai-close" type="button" title="Đóng Mimi" aria-label="Đóng Mimi">✖</button></div><div class="ai-logout-confirm" aria-hidden="true"><span>Thoát tài khoản Mimi?</span><button class="ai-logout-yes" type="button" title="Xác nhận thoát" aria-label="Xác nhận thoát">✓</button><button class="ai-logout-no" type="button" title="Hủy thoát" aria-label="Hủy thoát">×</button></div></div><div class="ai-messages"></div><div class="ai-history-panel" aria-label="Lịch sử trò chuyện"><div class="ai-history-head"><b>Lịch sử trò chuyện</b><div class="ai-history-head-actions"><button class="ai-history-clear" type="button" title="Xóa tất cả lịch sử trò chuyện" aria-label="Xóa tất cả lịch sử trò chuyện">🗑️</button><button class="ai-history-close" type="button" title="Đóng lịch sử" aria-label="Đóng lịch sử">×</button></div></div><div class="ai-history-list"></div></div><div class="ai-compose"><form class="ai-form"><textarea class="ai-input" rows="1" placeholder="Mimi có thể giúp gì cho bạn"></textarea><span class="ai-compose-gap" aria-hidden="true"></span><label class="ai-plus" title="Thêm file PHP cho Mimi">+<input class="ai-file" type="file" accept=".php,application/x-httpd-php" aria-label="Chọn file PHP"></label><button class="ai-send" type="submit" title="Gửi">Gửi</button></form><div class="ai-powered"><select class="ai-model" aria-label="Chọn chất lượng Mimi"><option value="gemini-3.5-flash-lite">Mimi Siêu Vip</option><option value="gemini-3.5-flash">Mimi Xịn</option><option value="gemini-2.0-flash">Mimi Cùi</option></select><button class="ai-model-add" type="button" title="Thêm model AI" aria-label="Thêm model AI" hidden>＋</button></div></div></section>`;
    document.body.appendChild(root); const panel = root.querySelector('.ai-panel'), input = root.querySelector('.ai-input');
    root.addEventListener('click', event => { const target = event.target.closest('button, .ai-plus'); if (target && root.contains(target)) mimiButtonSound(target); });
    root.addEventListener('change', event => { if (event.target.matches('select')) playMimiSound('click'); });
    root.querySelector('.ai-launcher').addEventListener('click', () => { state.open = !state.open; panel.classList.toggle('open', state.open); if (state.open) { panel.classList.remove('minimized'); updateMinButton(false); hideGuide(); } });
    root.querySelector('.ai-close').addEventListener('click', () => { state.open = false; panel.classList.remove('open'); input.blur(); toggleChatHistory(false); toggleLogoutConfirm(false); });
    root.querySelector('.ai-min').addEventListener('click', toggleMinimizePanel); root.querySelector('.ai-model-add').addEventListener('click', addCustomAiModel); root.querySelector('.ai-history-clear').addEventListener('click', clearChatHistory); root.querySelector('.ai-new').addEventListener('click', () => { toggleChatHistory(false); toggleLogoutConfirm(false); resetChat(); }); root.querySelector('.ai-history-head-trigger').addEventListener('click', () => { toggleLogoutConfirm(false); toggleChatHistory(); }); root.querySelector('.ai-logout').addEventListener('click', () => toggleLogoutConfirm()); root.querySelector('.ai-logout-yes').addEventListener('click', () => { toggleLogoutConfirm(false); handleAuthCommand('Đăng xuất'); }); root.querySelector('.ai-logout-no').addEventListener('click', () => toggleLogoutConfirm(false)); root.querySelector('.ai-history-close').addEventListener('click', () => toggleChatHistory(false)); root.querySelector('.ai-model').addEventListener('change', e => { state.model = e.target.value; });
    root.querySelector('.ai-form').addEventListener('submit', e => { e.preventDefault(); send(input.value); }); const fileInput = root.querySelector('.ai-file'); fileInput.addEventListener('change', () => { const file = fileInput.files && fileInput.files[0]; if (file) uploadPhp(file); fileInput.value = ''; }); input.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); root.querySelector('.ai-form').requestSubmit(); } }); input.addEventListener('input', () => { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight, 120) + 'px'; });
    resetChat(); setMood('neutral'); loadIdentityAndMemory().finally(loadAiModels);
  }
  window.fpApiCall = window.fpApiCall || ((action, params) => { if (typeof window.call === 'function') { const showConsole = ['ping','runlog_list','runlog_clear'].includes(action); return window.call(action, params || {}, {fromMimi:true, showConsole}); } throw new Error('API giao diện chưa sẵn sàng'); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', createUI); else createUI();
})();
