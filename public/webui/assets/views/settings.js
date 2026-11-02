import { h, mount } from '../lib/dom.js';
import { api } from '../lib/api.js';
import { badge, emptyState, reportError, ok, modal } from '../lib/ui.js';

async function renderAccount(container) {
  try {
    const me = await api.get('/webui/api/auth/me');
    const user = me.user ?? {};
    const oldPass = h('input.input', { type: 'password', placeholder: '当前密码', autocomplete: 'current-password' });
    const newPass = h('input.input', { type: 'password', placeholder: '新密码（至少 8 位）', autocomplete: 'new-password' });
    const confirm = h('input.input', { type: 'password', placeholder: '确认新密码' });

    mount(h('div.card', {},
      h('div.card-head', {}, h('div.grow', {}, h('h2', {}, '账号')),
        badge(String(user.username ?? '—'), 'accent')),
      h('div.card-body.col', {},
        h('div.kv', {},
          [['用户名', user.username ?? '—'], ['角色', user.role ?? 'admin']]
            .flatMap(([k, v]) => [h('div.kv-key', {}, k), h('div.kv-val', {}, String(v))]),
        ),
        h('div.field', {}, h('div.field-label', {}, '修改密码'),
          h('div.col', { style: { gap: '8px', maxWidth: '380px' } }, oldPass, newPass, confirm),
          h('div.row.mt-8', {},
            h('button.btn.btn-primary', {
              onclick: async () => {
                if (newPass.value !== confirm.value) { reportError(new Error('两次输入的新密码不一致')); return; }
                if (newPass.value.length < 8) { reportError(new Error('新密码至少 8 位')); return; }
                try {
                  await api.post('/webui/api/auth/password', {
                    old_password: oldPass.value, new_password: newPass.value,
                  });
                  ok('密码已更新，请用新密码重新登录');
                  oldPass.value = newPass.value = confirm.value = '';
                } catch (e) { reportError(e); }
              },
            }, '更新密码'),
          ),
        ),
      ),
    ), container);
  } catch (e) { reportError(e, '加载账号信息失败'); }
}

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

  const accountWrap = h('div');

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
    accountWrap,
  ), container);

  await renderAccount(accountWrap);

  rawWrap.style.display = 'none';
  await loadTree();
}