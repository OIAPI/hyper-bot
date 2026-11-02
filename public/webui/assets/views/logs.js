import { h, mount } from '../lib/dom.js';
import { api } from '../lib/api.js';
import { badge, emptyState, reportError, time, toast } from '../lib/ui.js';

export async function render(container) {
  let mode = 'tail';       // tail | day
  let level = '';
  let date = new Date().toISOString().slice(0, 10);
  let timer = null;

  const listWrap = h('div.card', { style: { maxHeight: '62vh', overflowY: 'auto' } });
  const dateWrap = h('div');
  const statsRow = h('div.row.wrap', { style: { gap: '6px' } });

  const levelSelect = h('select.select', {
    style: { width: 'auto' },
    onchange: (e) => { level = e.target.value; load(); },
  },
    h('option', { value: '' }, '全部级别'),
    ['debug', 'info', 'success', 'warning', 'error'].map(l => h('option', { value: l }, l)),
  );

  async function loadDates() {
    try {
      const data = await api.get('/webui/api/logs/dates');
      const days = data.items ?? [];
      mount(h('select.select', {
        style: { width: 'auto' },
        onchange: (e) => { date = e.target.value; load(); },
      }, days.length
        ? days.map(d => h('option', { value: d, selected: d === date }, d))
        : h('option', {}, '无日志文件')), dateWrap);
    } catch { /* ignore */ }
  }

  function fmt(r) {
    const ts = r.ts ?? null;
    return ts ? time(Math.floor(ts)) : (r.time ?? '—');
  }

  function renderLines(items) {
    if (!items.length) { mount(h('div.card-body', {}, emptyState('暂无日志')), listWrap); return; }
    mount(h('div', {}, items.map(r => h('div.log-line', {},
      h('div.log-time', {}, fmt(r)),
      h('div.log-level.' + (r.level ?? 'info'), {}, r.level ?? ''),
      h('div', {},
        h('div.log-msg', {}, r.message ?? ''),
        r.context && Object.keys(r.context).length
          ? h('div.log-ctx', {}, JSON.stringify(r.context))
          : null,
      ),
    ))), listWrap);
  }

  async function load() {
    mount(h('div.card-body', {}, h('div.skeleton', { style: { height: '60px' } })), listWrap);
    try {
      if (mode === 'tail') {
        const data = await api.get('/webui/api/logs/tail?limit=120' + (level ? '&level=' + level : ''));
        renderLines(data.items ?? []);
        mount(h('div', {}, Object.entries(data.stats ?? {})
          .filter(([k]) => k !== 'total')
          .map(([k, v]) => badge(k + ' ' + v,
            k === 'error' || k === 'fatal' ? 'danger' : k === 'warning' ? 'warn' : '')), ), statsRow);
      } else {
        const data = await api.get('/webui/api/logs/' + date + '?limit=200' + (level ? '&level=' + level : ''));
        renderLines(data.items ?? []);
      }
    } catch (e) { reportError(e, '加载日志失败'); }
  }

  const btnTail = h('button.btn.btn-sm.btn-primary', {
    onclick: () => { mode = 'tail'; dateWrap.style.display = 'none'; markMode(); load(); },
  }, '实时');
  const btnDay = h('button.btn.btn-sm', {
    onclick: () => { mode = 'day'; dateWrap.style.display = ''; markMode(); load(); },
  }, '历史');

  function markMode() {
    btnTail.className = 'btn btn-sm' + (mode === 'tail' ? ' btn-primary' : '');
    btnDay.className = 'btn btn-sm' + (mode === 'day' ? ' btn-primary' : '');
  }

  const autoBtn = h('button.btn.btn-sm', {
    onclick: () => {
      if (timer) { clearInterval(timer); timer = null; autoBtn.className = 'btn btn-sm'; toast('已暂停自动刷新', 'info'); }
      else { timer = setInterval(load, 5000); autoBtn.className = 'btn btn-sm btn-primary'; toast('自动刷新已开启（5 秒）', 'ok'); }
    },
  }, '⏱ 自动刷新');

  mount(h('div.col', { style: { gap: '14px' } },
    h('div.row.wrap', {},
      h('div.row', {}, btnTail, btnDay),
      dateWrap,
      levelSelect,
      h('div.spacer'),
      autoBtn,
      h('button.btn.btn-sm', { onclick: load }, '↻'),
    ),
    statsRow,
    listWrap,
  ), container);

  dateWrap.style.display = 'none';
  await loadDates();
  await load();

  if (timer) clearInterval(timer);
  const observer = new MutationObserver(() => {
    if (!container.contains(listWrap)) { if (timer) clearInterval(timer); observer.disconnect(); }
  });
  observer.observe(container, { childList: true });
}
