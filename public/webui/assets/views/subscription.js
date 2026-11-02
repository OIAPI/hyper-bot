// 消息订阅：哪些事件值得处理。
//
// 这是 v1 没有页面的部分（只能手改 Config.ini 的三个小节）。
// 保留订阅的理由写在页面上：它是业务路由的第一层过滤，
// 让不关心的事件在最便宜的位置被丢弃，并且丢弃原因是可解释的。

import { h, mount } from '../lib/dom.js';
import { api } from '../lib/api.js';
import { badge, confirmDialog, ok, reportError, sectionTitle, switchControl } from '../lib/ui.js';

const SCOPES = [
  { id: 'group', label: '仅群聊', hint: '最常见：只处理群消息' },
  { id: 'private', label: '仅私聊', hint: '只处理好友/临时会话消息' },
  { id: 'all', label: '全部', hint: '群聊与私聊都处理' },
];

export async function render(container) {
  const wrap = h('div');
  mount(wrap, container);

  // 两个接口并行拉取；说明文案失败不影响主功能
  let data;
  let whyItems = [];
  try {
    [data, { why: whyItems = [] }] = await Promise.all([
      api.get('/webui/api/subscription'),
      api.get('/webui/api/subscription/explain').catch(() => ({ why: [] })),
    ]);
  } catch (e) {
    reportError(e, '读取订阅配置失败');
    return;
  }

  const sub = data.subscription ?? {};
  const catalog = sub.notice_catalog ?? {};
  const noticeTypes = new Set(sub.notice_types ?? []);

  // 本地副本：先改再保存，避免每点一下都发请求
  const draft = {
    message: !!sub.message,
    notice: !!sub.notice,
    request: !!sub.request,
    meta_event: !!sub.meta_event,
    message_scope: sub.message_scope || 'group',
    notice_types: new Set(noticeTypes),
    ignore_self: sub.ignore_self !== false,
    ignore_robots: sub.ignore_robots !== false,
  };

  const save = async () => {
    const yes = await confirmDialog({
      title: '保存订阅',
      message: '订阅会写入 Config.ini，重启后完全生效。',
      confirmLabel: '保存',
    });
    if (!yes) return;
    try {
      const res = await api.put('/webui/api/subscription', {
        message: draft.message,
        notice: draft.notice,
        request: draft.request,
        meta_event: draft.meta_event,
        message_scope: draft.message_scope,
        notice_types: [...draft.notice_types],
        ignore_self: draft.ignore_self,
        ignore_robots: draft.ignore_robots,
      });
      ok(res.needs_restart ? '已保存，重启后生效' : '已保存');
      render(container);
    } catch (e) {
      reportError(e, '保存失败');
    }
  };

  // ---- 事件类型开关 ----
  const typeRows = [
    { key: 'message',    label: '消息事件',   hint: '群消息 / 私聊消息（插件最常处理）' },
    { key: 'notice',     label: '通知事件',   hint: '进群 / 退群 / 禁言 / 撤回等' },
    { key: 'request',    label: '请求事件',   hint: '加好友 / 加群邀请' },
    { key: 'meta_event', label: '元事件',     hint: '心跳、生命周期（多数情况无需处理）' },
  ].map(row => h('div.kv-row', {},
    h('div', {},
      h('div.kv-label', {}, row.label),
      h('div.muted.small', {}, row.hint),
    ),
    switchControl(draft[row.key], v => { draft[row.key] = v; }, false),
  ));

  // ---- 消息范围 ----
  const scopeRows = SCOPES.map(scope => h('label.radio-row', {},
    h('input', {
      type: 'radio',
      name: 'scope',
      value: scope.id,
      checked: draft.message_scope === scope.id,
      onchange: () => { draft.message_scope = scope.id; },
    }),
    h('span', {}, scope.label),
    h('span.muted.small', {}, scope.hint),
  ));

  // ---- 通知类型 ----
  const noticeRows = Object.entries(catalog).map(([type, label]) => h('label.check-row', {},
    h('input', {
      type: 'checkbox',
      checked: draft.notice_types.has(type),
      disabled: !draft.notice,
      onchange: (e) => {
        if (e.target.checked) draft.notice_types.add(type);
        else draft.notice_types.delete(type);
      },
    }),
    h('span.mono', {}, type),
    h('span.muted.small', {}, label),
  ));

  mount(h('div', {},
    sectionTitle('事件类型'),
    h('div.card', {}, typeRows,
      h('p.muted.small', {}, '全部关闭时不会处理任何事件（WebUI 仍可用）。')),

    sectionTitle('消息范围'),
    h('div.card', {}, scopeRows,
      h('p.muted.small', {}, '官方机器人/Milky 的「私聊」等价于 OneBot 的 private，这里统一判定。')),

    sectionTitle('通知类型'),
    h('div.card', {},
      h('p.muted.small', {}, draft.notice
        ? '至少勾选一项；未勾选时所有通知都会被丢弃。'
        : '通知事件当前已关闭，勾选不会生效。'),
      h('div.check-grid', {}, noticeRows)),

    sectionTitle('过滤规则'),
    h('div.card', {},
      h('div.kv-row', {},
        h('div', {}, h('div.kv-label', {}, '忽略自己发的消息'),
          h('div.muted.small', {}, '机器人回复自己触发的指令时会死循环，默认开启')),
        switchControl(draft.ignore_self, v => { draft.ignore_self = v; })),
      h('div.kv-row', {},
        h('div', {}, h('div.kv-label', {}, '忽略其他机器人'),
          h('div.muted.small', {}, '按 QQ 号前缀 ' + (sub.robot_prefix || '3889') + ' 识别')),
        switchControl(draft.ignore_robots, v => { draft.ignore_robots = v; })),
    ),

    h('div.card-actions', {},
      h('button.btn.btn-primary', { onclick: save }, '保存订阅'),
      h('span.muted.small', {}, '配置来源：' + (data.configured_from === 'v2' ? 'v2 新键 [subscription]' : 'v1 老键（兼容读取）')),
    ),

    // 为什么保留订阅 —— 这个问题用户问过，答案也固化在接口里
    h('div.card.card-note', {},
      h('div.card-head', {}, h('h2', {}, '为什么保留消息订阅？')),
      (whyItems.length
        ? h('ul.small', {}, whyItems.map(t => h('li', {}, t)))
        : h('p.muted.small', {}, '说明文案加载失败，不影响订阅功能。')),
    ),
  ), wrap);
}
