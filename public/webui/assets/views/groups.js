import { h, mount } from '../lib/dom.js';
import { api } from '../lib/api.js';
import { badge, switchControl, emptyState, reportError, ok, confirmDialog, modal, time } from '../lib/ui.js';

export async function render(container, { navigate }) {
  let query = '';
  let page = 1;

  const listWrap = h('div.card');
  const pager = h('div.row', { style: { justifyContent: 'flex-end', gap: '8px', marginTop: '12px' } });

  async function load() {
    mount(h('div.card-body', {}, h('div.skeleton', { style: { height: '120px' } }), ), listWrap);
    try {
      const data = await api.get('/webui/api/groups?q=' + encodeURIComponent(query) + '&page=' + page + '&size=20');
      renderList(data);
    } catch (e) {
      reportError(e, '加载群列表失败');
    }
  }

  function renderList(data) {
    const items = data.items ?? [];

    if (items.length === 0) {
      mount(h('div.card-body', {},
        emptyState(query ? '没有匹配的群' : '还没有任何群，先登记一个', '⚉'),
        h('div.center.mt-16', {},
          h('button.btn.btn-primary', { onclick: () => createGroup().then(load) }, '+ 登记群'),
        ),
      ), listWrap);
      mount(h('div'), pager);
      return;
    }

    mount(h('div.table-wrap', {},
      h('table.table', {},
        h('thead', {}, h('tr', {},
          h('th', {}, '群号'),
          h('th', {}, '名称 / 备注'),
          h('th', {}, '状态'),
          h('th', {}, '插件'),
          h('th', {}, '操作'),
        )),
        h('tbody', {}, items.map(item => h('tr', {},
          h('td', {}, h('a.mono', { href: 'javascript:void 0', onclick: () => openDetail(item.group_id) }, item.group_id)),
          h('td', {},
            h('div', {}, item.name || h('span.subtle', {}, '—')),
            item.note ? h('div.tiny.subtle', {}, item.note) : null,
          ),
          h('td', {},
            switchControl(item.status === true, async (next) => {
              try {
                await api.post('/webui/api/groups/' + encodeURIComponent(item.group_id) + '/status');
                ok(next ? '已启用' : '已停用');
                item.status = next;
              } catch (e) { reportError(e); throw e; }
            }),
          ),
          h('td', {}, h('div.row', {},
            badge(item.plugins_on + '/' + item.plugins_total, item.plugins_on === item.plugins_total ? 'ok' : 'warn'),
          )),
          h('td', {},
            h('div.row', {},
              h('button.btn.btn-sm', { onclick: () => openDetail(item.group_id) }, '详情'),
              h('button.btn.btn-sm.btn-ghost', { onclick: () => editMeta(item).then(load) }, '编辑'),
              h('button.btn.btn-sm.btn-ghost', {
                onclick: async () => {
                  if (await confirmDialog({ title: '移除群', message: '确定移除群 ' + item.group_id + ' 的配置吗？', confirmLabel: '移除' })) {
                    try { await api.del('/webui/api/groups/' + encodeURIComponent(item.group_id)); ok('已移除'); load(); }
                    catch (e) { reportError(e); }
                  }
                },
              }, '移除'),
            ),
          ),
        ))),
      ),
    ), listWrap);

    const pages = data.pages ?? 1;
    mount(h('div', {},
      h('span.subtle.small.grow', {}, '共 ' + data.total + ' 个，第 ' + data.page + '/' + pages + ' 页'),
      h('button.btn.btn-sm', { disabled: page <= 1, onclick: () => { page--; load(); } }, '上一页'),
      h('button.btn.btn-sm', { disabled: page >= pages, onclick: () => { page++; load(); } }, '下一页'),
    ), pager);
  }

  async function createGroup() {
    const input = h('input.input', { placeholder: '例如 123456789' });
    const name = h('input.input', { placeholder: '（可选）备注名称' });
    return new Promise((resolve) => {
      let settled = false;
      const done = (v) => { if (!settled) { settled = true; resolve(v); } };
      modal({
        title: '登记群',
        body: h('div.col', {},
          h('div.field', {}, h('div.field-label', {}, '群号'), input),
          h('div.field', {}, h('div.field-label', {}, '名称（可选）'), name),
          h('div.field-hint', {}, '登记后会出现在列表里，并可为该群单独开关插件。'),
        ),
        actions: [
          { label: '取消', onClick: () => done(null) },
          {
            label: '登记', variant: 'btn-primary',
            onClick: async () => {
              const id = input.value.trim();
              if (!id) { reportError(new Error('请填写群号')); return false; }
              try {
                await api.post('/webui/api/groups', { group_id: id });
                if (name.value.trim()) {
                  await api.patch('/webui/api/groups/' + encodeURIComponent(id) + '/meta', { name: name.value.trim() });
                }
                ok('已登记');
                done(true);
              } catch (e) { reportError(e); return false; }
            },
          },
        ],
      });
    });
  }

  async function editMeta(item) {
    const name = h('input.input', { value: item.name ?? '' });
    const note = h('input.input', { value: item.note ?? '' });
    const tags = h('input.input', { value: (item.tags ?? []).join(', '), placeholder: '用逗号分隔' });
    return new Promise((resolve) => {
      let settled = false;
      const done = (v) => { if (!settled) { settled = true; resolve(v); } };
      modal({
        title: '编辑群 ' + item.group_id,
        body: h('div.col', {},
          h('div.field', {}, h('div.field-label', {}, '名称'), name),
          h('div.field', {}, h('div.field-label', {}, '备注'), note),
          h('div.field', {}, h('div.field-label', {}, '标签'), tags),
        ),
        actions: [
          { label: '取消', onClick: () => done(null) },
          {
            label: '保存', variant: 'btn-primary',
            onClick: async () => {
              try {
                await api.patch('/webui/api/groups/' + encodeURIComponent(item.group_id) + '/meta', {
                  name: name.value.trim(),
                  note: note.value.trim(),
                  tags: tags.value.split(',').map(s => s.trim()).filter(Boolean),
                });
                ok('已保存');
                done(true);
              } catch (e) { reportError(e); return false; }
            },
          },
        ],
      });
    });
  }

  async function openDetail(groupId) {
    let detail;
    try { detail = await api.get('/webui/api/groups/' + encodeURIComponent(groupId)); }
    catch (e) { reportError(e); return; }

    const pluginList = await api.get('/webui/api/plugins').then(d => d.items).catch(() => []);
    const worker = detail.worker ?? {};

    const rows = pluginList.map(p => {
      const isHelp = /help|sys/i.test(p.short);
      const enabled = worker[p.class] !== false;
      return h('tr', {},
        h('td', {}, h('div', {}, p.name || p.short), h('div.tiny.subtle', {}, p.class)),
        h('td', {}, h('span.small', {}, p.description || '—')),
        h('td', {},
          switchControl(enabled, async (next) => {
            try {
              await api.post('/webui/api/groups/' + encodeURIComponent(groupId) + '/plugins/' + encodeURIComponent(p.class), { enabled: next });
              ok(next ? '已开启' : '已关闭');
            } catch (e) { reportError(e); throw e; }
          }, isHelp),
        ),
        h('td', {}, isHelp ? h('span.tiny.subtle', {}, '不可关闭') : null),
      );
    });

    modal({
      title: '群 ' + groupId + (detail.name ? ' · ' + detail.name : ''),
      width: '720px',
      body: h('div.col', {},
        h('div.kv', {},
          [
            ['群号', groupId],
            ['状态', detail.status ? '已启用' : '已停用'],
            ['插件', (detail.plugins_on ?? 0) + ' / ' + (detail.plugins_total ?? 0)],
            ['备注', detail.note || '—'],
            ['更新时间', detail.updated_at ? time(detail.updated_at) : '—'],
          ].flatMap(([k, v]) => [h('div.kv-key', {}, k), h('div.kv-val', {}, String(v))]),
        ),
        h('div', { style: { marginTop: '8px' } },
          h('h3', { style: { marginBottom: '8px' } }, '插件开关'),
          h('div.table-wrap', {}, h('table.table', {},
            h('thead', {}, h('tr', {}, h('th', {}, '插件'), h('th', {}, '说明'), h('th', {}, '开关'), h('th', {}, ''))),
            h('tbody', {}, rows.length ? rows : h('tr', {}, h('td', { colspan: 4 }, emptyState('没有插件')))) ,
          )),
        ),
      ),
      actions: [{ label: '关闭', variant: 'btn-primary' }],
    });
  }

  const searchInput = h('input.input', {
    placeholder: '搜索群号或名称…',
    oninput: (e) => { query = e.target.value.trim(); page = 1; load(); },
  });

  mount(h('div.col', { style: { gap: '14px' } },
    h('div.row', {},
      h('div.grow', {}, searchInput),
      h('button.btn.btn-primary.nowrap', { onclick: () => createGroup().then(load) }, '+ 登记群'),
      h('button.btn', { onclick: load }, '↻'),
    ),
    listWrap,
    h('div.grid.grid-2', {}, h('div', { style: { gridColumn: '1 / -1' } }, pager)),
  ), container);

  await load();
}
