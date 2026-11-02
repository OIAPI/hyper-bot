import { h, mount } from '../lib/dom.js';
import { api } from '../lib/api.js';
import { badge, emptyState, reportError, ok, modal, switchControl } from '../lib/ui.js';

const LABELS = { onebot: 'OneBot11', milky: 'Milky', qqbot: 'QQ 官方机器人' };

export async function render(container) {
  const cards = h('div.grid.grid-3');
  mount(h('div.col', { style: { gap: '14px' } },
    h('div.card', {}, h('div.card-body', {},
      h('p.muted', { style: { margin: 0 } },
        '协议接入的配置保存在 Config.ini。修改后需重启服务才能生效；下方展示的是当前生效状态。'),
    )),
    cards,
  ), container);

  try {
    const data = await api.get('/webui/api/protocols');
    mount(h('div.grid.grid-3', {}, Object.entries(data).map(([key, p]) => card(key, p))), cards);
  } catch (e) {
    reportError(e, '加载协议状态失败');
  }

  function card(key, p) {
    const detail = p.detail ?? {};
    return h('div.card', {},
      h('div.card-head', {},
        h('div.grow', {},
          h('div.row', {},
            h('span.dot' + (p.enabled ? '.dot-ok' : '.dot-off')),
            h('h2', {}, p.label),
          ),
          h('div.subtle.small', {}, p.mode || '—'),
        ),
        p.enabled ? badge('启用', 'ok') : badge('未启用'),
      ),
      h('div.card-body.col', {},
        h('div.kv', {},
          [
            key === 'onebot' ? ['正向', detail.forward_running ? '在线：' + (detail.forward_url || '') : '离线'] : null,
            key === 'onebot' ? ['反向会话', String(detail.reverse_sessions ?? 0)] : null,
            key === 'milky' ? ['API', detail.api || '—'] : null,
            key === 'milky' ? ['WS 订阅', String(detail.ws_in ?? 0)] : null,
            key === 'qqbot' ? ['Gateway', detail.status?.connected ? '在线' : '离线'] : null,
            key === 'qqbot' ? ['AppID', detail.app_id || '—'] : null,
          ].filter(Boolean).flatMap(([k, v]) => [h('div.kv-key', {}, k), h('div.kv-val', {}, v)]),
        ),
        h('div.row.mt-8', {},
          h('button.btn.btn-sm.grow', { onclick: () => showDetail(key) }, '配置与端点'),
          h('button.btn.btn-sm', { onclick: () => showActions(key) }, '能力'),
        ),
      ),
    );
  }

  async function showDetail(key) {
    let info;
    try { info = await api.get('/webui/api/protocols/' + key); }
    catch (e) { reportError(e); return; }

    const config = info.config ?? {};
    const fields = Object.entries(config).map(([k, v]) => h('div.field', {},
      h('div.field-label', {}, k),
      h('div.kv-val', {}, typeof v === 'object' ? JSON.stringify(v) : String(v ?? '')),
    ));
    const endpoints = (info.endpoints ?? []).map(ep => h('div', {},
      h('div.tiny.subtle', {}, ep.label),
      h('div.code', { style: { padding: '8px 10px', marginTop: '4px' } }, ep.url),
    ));

    modal({
      title: LABELS[key] + ' 详情',
      width: '720px',
      body: h('div.col', {},
        h('div', {},
          h('h3', { style: { marginBottom: '8px' } }, '端点'),
          h('div.col', { style: { gap: '10px' } }, endpoints.length ? endpoints : emptyState('无')),
        ),
        h('div', {},
          h('h3', { style: { marginBottom: '8px' } }, '当前配置'),
          h('div.col', { style: { gap: '8px' } }, fields.length ? fields : emptyState('无配置')),
        ),
        h('div.field-hint', {}, '如需修改，请到「配置」页或直接编辑 Config.ini。'),
      ),
      actions: [{ label: '关闭', variant: 'btn-primary' }],
    });
  }

  async function showActions(key) {
    if (key === 'onebot') { ok('OneBot11 动作全部透传给实现端，无降级'); return; }
    let data;
    try { data = await api.get('/webui/api/protocols/' + key + '/actions'); }
    catch (e) { reportError(e); return; }

    const unsupported = data.unsupported ?? [];
    const supported = data.supported ?? [];

    modal({
      title: LABELS[key] + ' 能力矩阵',
      width: '720px',
      body: h('div.col', {},
        h('div', {},
          h('h3', { style: { marginBottom: '8px' } }, '不支持的动作（' + unsupported.length + '）'),
          unsupported.length
            ? h('div.table-wrap', {}, h('table.table', {},
                h('thead', {}, h('tr', {}, h('th', {}, '动作'), h('th', {}, '原因'))),
                h('tbody', {}, unsupported.map(u => h('tr', {},
                  h('td.mono', {}, u.action),
                  h('td.small', {}, u.reason),
                ))),
              ))
            : emptyState('全部支持'),
        ),
        supported.length
          ? h('div', {},
              h('h3', { style: { marginBottom: '8px' } }, '已映射（' + supported.length + '）'),
              h('div.row.wrap', { style: { gap: '6px' } },
                supported.map(s => h('span.badge', {}, s.action)),
              ),
            )
          : null,
      ),
      actions: [{ label: '关闭', variant: 'btn-primary' }],
    });
  }
}
