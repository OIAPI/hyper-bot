import { h, mount } from '../lib/dom.js';
import { api } from '../lib/api.js';
import { badge, emptyState, reportError } from '../lib/ui.js';

export async function render(container) {
  let date = new Date().toISOString().slice(0, 10).replace(/-/g, '');
  const listWrap = h('div.card');

  const dateInput = h('input.input', {
    style: { width: 'auto' },
    type: 'date',
    value: date.slice(0, 4) + '-' + date.slice(4, 6) + '-' + date.slice(6, 8),
    onchange: (e) => { date = e.target.value.replace(/-/g, ''); load(); },
  });

  async function load() {
    mount(h('div.card-body', {}, h('div.skeleton', { style: { height: '80px' } })), listWrap);
    try {
      const data = await api.get('/webui/api/messages?date=' + date + '&limit=100');
      const items = data.items ?? [];
      if (!items.length) {
        mount(h('div.card-body', {},
          emptyState('这一天没有消息记录', '✉'),
          h('div.tiny.subtle.center', {}, '消息日志由 Utils\MessageLog 写入 runtime/message/<年>/<月>/<日>/message.log'),
        ), listWrap);
        return;
      }
      mount(h('div.table-wrap', {}, h('table.table', {},
        h('thead', {}, h('tr', {},
          h('th', {}, '时间'),
          h('th', {}, '类型'),
          h('th', {}, '群 / 用户'),
          h('th', {}, '内容'),
        )),
        h('tbody', {}, items.map(m => h('tr', {},
          h('td.mono.nowrap', {}, m.log_time ?? '—'),
          h('td', {}, badge(m.log_type ?? m.post_type ?? 'message', 'accent')),
          h('td.mono.nowrap', {}, [m.group_id, m.user_id].filter(Boolean).join(' / ') || '—'),
          h('td', {}, h('div.truncate', { style: { maxWidth: '420px' }, title: m.raw_message ?? '' },
            m.raw_message ?? JSON.stringify(m).slice(0, 100))),
        ))),
      )), listWrap);
    } catch (e) { reportError(e, '加载消息失败'); }
  }

  mount(h('div.col', { style: { gap: '14px' } },
    h('div.row.wrap', {},
      h('div.field', {}, h('div.field-label', {}, '日期'), dateInput),
      h('div.spacer'),
      h('button.btn.btn-sm', { onclick: load }, '↻ 刷新'),
    ),
    listWrap,
  ), container);

  await load();
}
