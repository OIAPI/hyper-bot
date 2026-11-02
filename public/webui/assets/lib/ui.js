// 通用 UI 组件：Toast / Modal / 确认框 / 格式化。
import { h, clear } from './dom.js';

const toastRoot = () => document.getElementById('toasts');

let toastTimer = [];

export function toast(message, kind = 'info', duration = 3200) {
  const root = toastRoot();
  if (!root) return;
  const icons = { ok: '✓', err: '✕', warn: '!', info: 'i' };
  const node = h('div.toast.' + (kind === 'info' ? '' : kind), {},
    h('div', { style: { fontWeight: '700', opacity: .8 } }, icons[kind] ?? 'i'),
    h('div.msg', {}, message),
  );
  root.appendChild(node);
  const timer = setTimeout(() => {
    node.style.opacity = '0';
    node.style.transform = 'translateX(14px)';
    node.style.transition = 'opacity .2s, transform .2s';
    setTimeout(() => node.remove(), 220);
  }, duration);
  toastTimer.push(timer);
  return () => { clearTimeout(timer); node.remove(); };
}

export const ok   = (m) => toast(m, 'ok');
export const err  = (m) => toast(m, 'err', 5000);
export const warn = (m) => toast(m, 'warn', 4200);

/** 把未知异常安全地转成用户可读消息 */
export function reportError(e, fallback = '操作失败') {
  const message = (e && e.message) ? e.message : fallback;
  err(message);
  if (window.__WB_DEBUG) console.error(e);
}

let activeModal = null;

export function modal({ title, body, actions = [], width }) {
  closeModal();

  const backdrop = h('div.modal-backdrop', {
    onclick: (e) => { if (e.target === backdrop) closeModal(); },
  });

  const foot = actions.length
    ? h('div.modal-foot', {}, actions.map(a => h('button.btn' + (a.variant ? '.' + a.variant : ''), {
        onclick: async (e) => {
          if (a.onClick) {
            const result = await a.onClick(e);
            if (result === false) return;
          }
          if (a.close !== false) closeModal();
        },
      }, a.label)))
    : null;

  const box = h('div.modal', { style: width ? { width } : {} },
    h('div.modal-head', {},
      h('div.grow', {}, h('h2', {}, title)),
      h('button.btn.btn-ghost.btn-icon', { onclick: closeModal, 'aria-label': '关闭' }, '✕'),
    ),
    h('div.modal-body', {}, body),
    foot,
  );

  backdrop.appendChild(box);
  document.body.appendChild(backdrop);
  activeModal = backdrop;

  const esc = (e) => { if (e.key === 'Escape') closeModal(); };
  document.addEventListener('keydown', esc);
  backdrop.__esc = esc;

  // 自动聚焦第一个输入框
  const firstInput = box.querySelector('input, textarea, select');
  if (firstInput) setTimeout(() => firstInput.focus(), 60);

  return { close: closeModal, box };
}

export function closeModal() {
  if (!activeModal) return;
  if (activeModal.__esc) document.removeEventListener('keydown', activeModal.__esc);
  activeModal.remove();
  activeModal = null;
}

export function confirmDialog({ title = '确认操作', message, confirmLabel = '确认', variant = 'btn-danger' }) {
  return new Promise((resolve) => {
    let settled = false;
    const done = (value) => { if (!settled) { settled = true; resolve(value); } };
    modal({
      title,
      body: h('div', {}, message),
      actions: [
        { label: '取消', onClick: () => done(false) },
        { label: confirmLabel, variant, onClick: () => done(true) },
      ],
    });
    // 关闭（X / Esc / 点遮罩）视为取消
    const observer = new MutationObserver(() => {
      if (!activeModal) { observer.disconnect(); done(false); }
    });
    observer.observe(document.body, { childList: true });
  });
}

export function promptDialog({ title = '请输入', label = '值', value = '', placeholder = '' }) {
  return new Promise((resolve) => {
    const input = h('input.input', { value, placeholder });
    let settled = false;
    const done = (v) => { if (!settled) { settled = true; resolve(v); } };
    modal({
      title,
      body: h('div.field', {}, h('div.field-label', {}, label), input),
      actions: [
        { label: '取消', onClick: () => done(null) },
        { label: '确定', variant: 'btn-primary', onClick: () => done(input.value) },
      ],
    });
  });
}

// ---------------- 格式化 ----------------

export function bytes(n) {
  if (n === null || n === undefined || Number.isNaN(Number(n))) return '—';
  n = Number(n);
  if (n < 1024) return n + ' B';
  const units = ['KB', 'MB', 'GB', 'TB'];
  let i = -1;
  do { n /= 1024; i++; } while (n >= 1024 && i < units.length - 1);
  return n.toFixed(n >= 100 ? 0 : 1) + ' ' + units[i];
}

export function duration(seconds) {
  seconds = Math.max(0, Math.floor(Number(seconds) || 0));
  const d = Math.floor(seconds / 86400);
  const hh = Math.floor((seconds % 86400) / 3600);
  const mm = Math.floor((seconds % 3600) / 60);
  const ss = seconds % 60;
  if (d > 0) return d + ' 天 ' + hh + ' 时';
  if (hh > 0) return hh + ' 时 ' + mm + ' 分';
  if (mm > 0) return mm + ' 分 ' + ss + ' 秒';
  return ss + ' 秒';
}

export function time(ts) {
  if (!ts) return '—';
  const d = new Date(Number(ts) * 1000);
  if (Number.isNaN(d.getTime())) return '—';
  const pad = (n) => String(n).padStart(2, '0');
  return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) +
         ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
}

export function relativeTime(ts) {
  const seconds = Math.floor(Date.now() / 1000 - Number(ts || 0));
  if (seconds < 60) return '刚刚';
  if (seconds < 3600) return Math.floor(seconds / 60) + ' 分钟前';
  if (seconds < 86400) return Math.floor(seconds / 3600) + ' 小时前';
  return Math.floor(seconds / 86400) + ' 天前';
}

// ---------------- 小组件 ----------------

export function badge(text, kind = '') {
  return h('span.badge' + (kind ? '.badge-' + kind : ''), {}, text);
}

export function switchControl(checked, onChange, disabled = false) {
  const btn = h('button.switch', {
    'aria-checked': checked ? 'true' : 'false',
    'aria-label': checked ? '已启用' : '已停用',
    disabled,
    onclick: async () => {
      const next = btn.getAttribute('aria-checked') !== 'true';
      btn.setAttribute('aria-checked', next ? 'true' : 'false');
      btn.disabled = true;
      try {
        await onChange(next);
      } catch (e) {
        btn.setAttribute('aria-checked', next ? 'false' : 'true');
        throw e;
      } finally {
        btn.disabled = disabled;
      }
    },
  });
  return btn;
}

export function statCard(label, value, meta, icon) {
  return h('div.card.stat', {},
    h('div.stat-label', {}, icon ? h('span', {}, icon) : null, label),
    h('div.stat-value', {}, value),
    meta ? h('div.stat-meta', {}, meta) : null,
  );
}

export function emptyState(text, icon = '∅') {
  return h('div.empty', {}, h('div.empty-ico', {}, icon), h('div', {}, text));
}

export function skeletonLines(count = 4) {
  const wrap = h('div.card-body.col', {});
  for (let i = 0; i < count; i++) {
    wrap.appendChild(h('div.skeleton', { style: { width: (55 + Math.random() * 40) + '%' } }));
  }
  return h('div.card', {}, wrap);
}

export function kvList(entries) {
  return h('div.kv', {}, entries.map(([k, v]) =>
    [h('div.kv-key', {}, k), h('div.kv-val', {}, v === null || v === undefined || v === '' ? '—' : String(v))]
  ));
}

export function sectionTitle(title, ...actions) {
  return h('div.card-head', {}, h('div.grow', {}, h('h2', {}, title)), ...actions);
}
