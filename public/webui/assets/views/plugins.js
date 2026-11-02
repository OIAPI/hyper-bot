import { h, mount } from '../lib/dom.js';
import { api } from '../lib/api.js';
import { badge, emptyState, reportError, ok, modal } from '../lib/ui.js';

export async function render(container) {
  let query = '';
  const list = h('div.grid.grid-2');

  async function load() {
    mount(h('div.card-body', {}, h('div.skeleton', { style: { height: '160px' } })), list);
    try {
      const data = await api.get('/webui/api/plugins' + (query ? '?q=' + encodeURIComponent(query) : ''));
      renderList(data);
    } catch (e) { reportError(e, '加载插件失败'); }
  }

  function renderList(data) {
    const items = data.items ?? [];
    if (items.length === 0) {
      mount(h('div.card', {}, h('div.card-body', {}, emptyState('没有插件', '◈')), ), list);
      return;
    }
    mount(h('div.grid.grid-2', {}, items.map(p => h('div.card', {},
      h('div.card-head', {},
        h('div.grow', {},
          h('div.row', {}, h('h2', {}, p.name || p.short) , badge('v' + (p.version || '1.0'))),
          h('div.subtle.tiny.mono.truncate', { title: p.class }, p.class),
        ),
        h('button.btn.btn-sm', { onclick: () => showDetail(p) }, '详情'),
      ),
      h('div.card-body', {},
        h('div.small.muted', { style: { minHeight: '36px' } }, p.description || '（无描述）'),
        h('div.row.wrap.mt-8', { style: { gap: '6px' } },
          p.author ? badge('@' + p.author) : null,
          badge(p.hooks?.length ? p.hooks.length + ' 个触发点' : '无触发点', p.hooks?.length ? 'accent' : ''),
        ),
      ),
    ))), list);
  }

  function showDetail(p) {
    modal({
      title: p.name || p.short,
      width: '680px',
      body: h('div.col', {},
        h('div.kv', {},
          [
            ['类名', p.class],
            ['版本', p.version || '—'],
            ['作者', p.author || '—'],
            ['公开方法', String(p.methods)],
          ].flatMap(([k, v]) => [h('div.kv-key', {}, k), h('div.kv-val', {}, v)]),
        ),
        h('div', {},
          h('h3', { style: { marginBottom: '8px' } }, '触发点'),
          p.hooks?.length
            ? h('div.table-wrap', {}, h('table.table', {},
                h('thead', {}, h('tr', {}, h('th', {}, '类型'), h('th', {}, '方法'))),
                h('tbody', {}, p.hooks.map(hk => h('tr', {},
                  h('td', {}, badge(hk.type, 'accent')),
                  h('td.mono', {}, hk.method),
                ))),
              ))
            : emptyState('该插件没有声明触发点', '◌'),
        ),
      ),
      actions: [{ label: '关闭', variant: 'btn-primary' }],
    });
  }

  const search = h('input.input', { placeholder: '搜索插件…', oninput: (e) => { query = e.target.value.trim(); load(); } });

  mount(h('div.col', { style: { gap: '14px' } },
    h('div.row', {},
      h('div.grow', {}, search),
      h('button.btn', {
        onclick: async () => {
          try { const d = await api.post('/webui/api/plugins/reload'); ok('已重新扫描：' + d.total + ' 个插件'); renderList(d); }
          catch (e) { reportError(e); }
        },
      }, '↻ 重新扫描'),
    ),
    list,
  ), container);

  await load();
}
