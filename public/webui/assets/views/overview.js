import { h, mount, clear } from '../lib/dom.js';
import { api } from '../lib/api.js';
import { statCard, badge, time, duration, bytes, emptyState, reportError } from '../lib/ui.js';

export async function render(container) {
  mount(h('div.col', {}, h('div.skeleton', { style: { height: '80px' } })), container);

  let data;
  try {
    data = await api.get('/webui/api/overview');
  } catch (e) {
    reportError(e, '加载概览失败');
    return;
  }

  const { app, runtime, counts, protocol, recent, php } = data;

  const protoCards = Object.entries(protocol).map(([key, p]) => {
    const detail = p.detail ?? {};
    const live = key === 'onebot'
      ? (detail.forward_running || (detail.reverse_sessions ?? 0) > 0)
      : key === 'milky'
        ? (detail.ws_in ?? 0) > 0
        : Boolean(detail.status?.connected);
    return h('div.card', {},
      h('div.card-head', {},
        h('div.grow', {},
          h('div.row', {},
            h('span.dot' + (p.enabled ? (live ? '.dot-ok' : '.dot-off') : '.dot-off')),
            h('h2', {}, p.label),
          ),
          h('div.subtle.small', {}, p.mode),
        ),
        p.enabled ? badge('已启用', 'ok') : badge('未启用'),
      ),
      h('div.card-body', {},
        h('div.kv', {},
          [
            ['模式', p.mode || '—'],
            key === 'onebot' ? ['正向连接', detail.forward_running ? '在线' : '离线'] : null,
            key === 'onebot' ? ['反向会话', String(detail.reverse_sessions ?? 0)] : null,
            key === 'milky' ? ['API 地址', detail.api || '—'] : null,
            key === 'qqbot' ? ['Gateway', detail.status?.connected ? '在线' : '离线'] : null,
            key === 'qqbot' ? ['AppID', detail.app_id || '—'] : null,
          ].filter(Boolean).flatMap(([k, v]) => [h('div.kv-key', {}, k), h('div.kv-val', {}, v)]),
        ),
      ),
    );
  });

  const timeline = recent.length
    ? h('div', {}, recent.map(e => h('div.log-line', {},
        h('div.log-time', {}, time(e.received ?? e.time)),
        h('div', {}, badge(e.post_type || 'event', 'accent')),
        h('div', {},
          h('div.log-msg', {}, e.raw_message || [e.message_type, e.notice_type, e.sub_type].filter(Boolean).join(' / ') || '(无内容)'),
          h('div.log-ctx', {}, [
            e.group_id ? '群 ' + e.group_id : null,
            e.user_id ? '用户 ' + e.user_id : null,
            e.protocol ? e.protocol : null,
          ].filter(Boolean).join(' · ') || '—'),
        ),
      )))
    : emptyState('还没有收到任何事件', '◌');

  mount(h('div.col', { style: { gap: '16px' } },
    // 统计卡
    h('div.grid.grid-4', {},
      statCard('群', counts.groups, '已登记', '⚉'),
      statCard('插件', counts.plugins, counts.plugins_on + ' 个启用中', '◈'),
      statCard('事件', counts.events, '最近 2000 条', '⇄'),
      statCard('协议', counts.protocols, '已启用', '⚙'),
    ),

    // 运行时
    h('div.grid.grid-2', {},
      h('div.card', {},
        h('div.card-head', {}, h('h2', {}, '运行状态')),
        h('div.card-body', {},
          h('div.kv', {},
            [
              ['已运行', duration(runtime.uptime)],
              ['启动时间', time(runtime.started_at)],
              ['内存占用', bytes(runtime.memory)],
              ['峰值内存', bytes(runtime.peak)],
              ['进程号', String(runtime.pid)],
              ['运行环境', runtime.server],
            ].flatMap(([k, v]) => [h('div.kv-key', {}, k), h('div.kv-val', {}, v)]),
          ),
        ),
      ),
      h('div.card', {},
        h('div.card-head', {}, h('h2', {}, '应用与依赖')),
        h('div.card-body', {},
          h('div.kv', {},
            [
              ['应用', app.name],
              ['PHP', app.php],
              ['Swoole', app.swoole ?? '未安装（WebUI 仍可运行）'],
              ['sodium', app.sodium ? '已安装' : '未安装（QQ 验签不可用）'],
              ['Redis', app.redis ? '已安装' : '未安装（使用文件存储）'],
              ['存储驱动', app.store],
              ['调试模式', app.debug ? '开启' : '关闭'],
            ].flatMap(([k, v]) => [h('div.kv-key', {}, k), h('div.kv-val', {}, v)]),
          ),
        ),
      ),
    ),

    // 协议
    h('div.card', {},
      h('div.card-head', {}, h('div.grow', {}, h('h2', {}, '协议接入'))),
      h('div.card-body', {}, h('div.grid.grid-3', {}, protoCards)),
    ),

    // 事件时间线
    h('div.card', {},
      h('div.card-head', {}, h('div.grow', {}, h('h2', {}, '最近事件'))),
      h('div.card-body.tight', {}, timeline),
    ),
  ), container);
}
