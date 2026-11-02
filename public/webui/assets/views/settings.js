import { h, mount } from '../lib/dom.js';
import { api } from '../lib/api.js';
import { badge, emptyState, reportError, ok, modal } from '../lib/ui.js';

export async function render(container) {
  const treeWrap = h('div.card');
  const rawWrap = h('div.card');
  let mode = 'tree';

  async function loadTree() {
    mount(h('div.card-body', {}, h('div.skeleton', { style: { height: '120px' } })), treeWrap);
    try {
      const data = await api.get('/webui/api/settings');
      renderTree(data);
    } catch (e) { reportError(e, '加载配置失败'); }
  }

  function renderTree(data) {
    const config = data.config ?? {};
    const sections = Object.entries(config).map(([name, value]) =>
      h('div.card', { style: { marginBottom: '12px' } },
        h('div.card-head', {}, h('div.grow', {}, h('h2', {}, '[' + name + ']')),
          badge(isObject(value) ? Object.keys(value).length + ' 项' : '标量', 'accent')),
        h('div.card-body.tight', {},
          isObject(value)
            ? h('div.table-wrap', {}, h('table.table', {},
                h('thead', {}, h('tr', {}, h('th', {}, '键'), h('th', {}, '值'), h('th', {}, ''))),
                h('tbody', {}, Object.entries(value).map(([k, v]) => h('tr', {},
                  h('td.mono', {}, k),
                  h('td.mono', {}, renderValue(v)),
                  h('td.right', {}, typeof v === 'object'
                    ? null
                    : h('button.btn.btn-sm.btn-ghost', { onclick: () => edit(name + '.' + k, v) }, '修改')),
                ))),
              ))
            : h('div.card-body', {}, h('div.mono', {}, renderValue(value))),
        ),
      ));

    mount(h('div', {},
      h('div.row.wrap.mb-8', {},
        badge(data.writable ? 'Config.ini 可写' : 'Config.ini 只读', data.writable ? 'ok' : 'danger'),
        data.path ? h('span.tiny.subtle.mono', {}, data.path) : null,
        data.warnings?.length ? badge(data.warnings.length + ' 个告警', 'warn') : null,
      ),
      sections,
    ), treeWrap);
  }

  function renderValue(v) {
    if (v === null || v === undefined || v === '') return '—';
    if (typeof v === 'object') return JSON.stringify(v);
    return String(v);
  }

  function isObject(v) {
    return v !== null && typeof v === 'object' && !Array.isArray(v);
  }

  async function edit(key, current) {
    const input = h('input.input', { value: String(current ?? '') });
    modal({
      title: '修改 ' + key,
      body: h('div.col', {},
        h('div.field', {}, h('div.field-label', {}, '值'), input),
        h('div.field-hint', {}, '写入 Config.ini 后需重启服务才能生效。'),
      ),
      actions: [
        { label: '取消' },
        {
          label: '保存', variant: 'btn-primary',
          onClick: async () => {
            try {
              await api.put('/webui/api/settings', { key, value: input.value });
              ok('已保存（重启后生效）');
              await loadTree();
            } catch (e) { reportError(e); return false; }
          },
        },
      ],
    });
  }

  async function loadRaw() {
    mount(h('div.card-body', {}, h('div.skeleton', { style: { height: '160px' } })), rawWrap);
    try {
      const data = await api.get('/webui/api/settings/raw');
      mount(h('div.code', { style: { maxHeight: '62vh', overflow: 'auto' } }, data.text ?? ''), rawWrap);
    } catch (e) { reportError(e, '读取配置原文失败'); }
  }

  const btnTree = h('button.btn.btn-sm.btn-primary', { onclick: () => { mode = 'tree'; markMode(); } }, '表单视图');
  const btnRaw = h('button.btn.btn-sm', { onclick: () => { mode = 'raw'; markMode(); loadRaw(); } }, '原文');
  function markMode() {
    btnTree.className = 'btn btn-sm' + (mode === 'tree' ? ' btn-primary' : '');
    btnRaw.className = 'btn btn-sm' + (mode === 'raw' ? ' btn-primary' : '');
    treeWrap.style.display = mode === 'tree' ? '' : 'none';
    rawWrap.style.display = mode === 'raw' ? '' : 'none';
  }

  mount(h('div.col', { style: { gap: '14px' } },
    h('div.row', {},
      h('div.row', {}, btnTree, btnRaw),
      h('div.spacer'),
      h('button.btn.btn-sm', {
        onclick: async () => {
          try { const d = await api.post('/webui/api/settings/reload'); ok('已重新加载配置'); await loadTree(); }
          catch (e) { reportError(e); }
        },
      }, '↻ 重新加载'),
    ),
    treeWrap,
    rawWrap,
  ), container);

  rawWrap.style.display = 'none';
  await loadTree();
}
