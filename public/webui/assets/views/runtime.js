// 运行架构：切换 swoole / php / webui。
//
// 这是 v2 才有的页面。v1 只有一种跑法（要求 ext-swoole），
// 换了机器就得改代码；现在架构是配置项，页面直接给「可用/不可用 + 需要重启」的实话。

import { h, mount } from '../lib/dom.js';
import { api } from '../lib/api.js';
import { badge, confirmDialog, ok, reportError, sectionTitle, statCard } from '../lib/ui.js';

export async function render(container) {
  const wrap = h('div');
  mount(wrap, container);

  let data;
  try {
    data = await api.get('/webui/api/runtime');
  } catch (e) {
    reportError(e, '读取运行架构失败');
    return;
  }

  const current = data.current ?? {};
  const modes = data.modes ?? [];

  mount(h('div.grid.grid-3', {},
    statCard('当前架构', current.label || current.mode || '-', current.requested ? '请求值 ' + current.requested : ''),
    statCard('配置值', data.configured || 'auto', 'auto = 能力检测优先'),
    statCard('降级', current.degraded ? '是' : '否', current.reason || '运行正常'),
  ), wrap);

  const cards = modes.map(mode => {
    const isCurrent = mode.id === current.mode;
    const selectable = mode.available && mode.id !== 'auto';

    const select = async () => {
      const yes = await confirmDialog({
        title: '切换运行架构',
        message: '切换到「' + mode.label + '」后需要重启服务才会生效。',
        confirmLabel: '保存',
      });
      if (!yes) return;
      try {
        const res = await api.put('/webui/api/runtime', { arch: mode.id });
        ok(res.needs_restart ? '已保存，重启后生效' : '已切换');
        render(container);
      } catch (e) {
        reportError(e, '切换失败');
      }
    };

    return h('div.card', {},
      h('div.card-head', {},
        h('h2', {}, mode.label),
        h('span.mono.tag', {}, mode.id),
        h('span', {}, isCurrent ? badge('当前', 'ok') : (mode.available ? badge('可用') : badge('不可用', 'err'))),
      ),
      h('p.muted', {}, mode.summary || ''),
      h('div.kv', {},
        h('span.muted', {}, '依赖'),
        h('span.mono', {}, mode.requires || '无'),
      ),
      (mode.capabilities ?? []).length
        ? h('div.tags', {}, (mode.capabilities ?? []).map(c => h('span.tag', {}, c)))
        : null,
      (mode.limitations ?? []).length
        ? h('div', {}, h('div.muted', {}, '限制'),
            h('ul.small', {}, (mode.limitations ?? []).map(l => h('li', {}, l))))
        : null,
      h('div.card-actions', {},
        isCurrent
          ? h('span.muted', {}, '正在运行')
          : h('button.btn.btn-primary', {
              disabled: !selectable,
              title: mode.available ? '' : (mode.requires || '当前环境不可用'),
              onclick: select,
            }, selectable ? '切换到此架构' : '不可用'),
      ),
    );
  });

  mount(h('div', {},
    sectionTitle('可选架构'),
    h('div.grid.grid-3', {}, cards),
    h('p.muted.small', {},
      '切换只写 Config.ini，不会立即改变进程模型。可用性由当前 PHP 扩展决定，'
      + '不可用的架构会被自动降级并在日志里给出原因。'),
    h('div.card', {},
      h('div.card-head', {}, h('h2', {}, '扩展状态')),
      h('div.kv-grid', {}, Object.entries(data.php?.extensions ?? {}).map(([name, enabled]) =>
        h('div.kv', {},
          h('span.mono', {}, 'ext-' + name),
          enabled ? badge('已安装', 'ok') : badge('缺失', 'err'),
        ))),
      h('p.muted.small', {}, 'PHP ' + (data.php?.version || '')),
    ),
  ), wrap);
}
