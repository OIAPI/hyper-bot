import { h, mount } from '../lib/dom.js';
import { api } from '../lib/api.js';
import { badge, bytes, confirmDialog, emptyState, ok, reportError, statCard } from '../lib/ui.js';

export async function render(container) {
  const healthWrap = h('div.grid.grid-4');
  const diskWrap = h('div.card');
  const routesWrap = h('div.card');

  try {
    const [health, disk, routes] = await Promise.all([
      api.get('/webui/api/health'),
      api.get('/webui/api/system/disk'),
      api.get('/webui/api/system/routes'),
    ]);

    mount(h('div.grid.grid-4', {},
      statCard('存储驱动', health.store_driver, '当前生效'),
      statCard('Redis', health.redis_ok === null ? '未安装' : (health.redis_ok ? '在线' : '离线'),
        health.redis_ok === null ? '使用文件存储' : ''),
      statCard('runtime', health.runtime_writable ? '可写' : '不可写',
        health.runtime_writable ? '' : '日志/存储将失败'),
      statCard('Config.ini', health.config_writable ? '可写' : '只读',
        health.config_writable ? '' : '无法通过 WebUI 保存配置'),
    ), healthWrap);

    mount(h('div', {},
      h('div.card-head', {}, h('h2', {}, 'runtime 目录体积')),
      h('div.table-wrap', {}, h('table.table', {},
        h('thead', {}, h('tr', {}, h('th', {}, '目录'), h('th', {}, '大小'), h('th', {}, '文件数'), h('th', {}, '存在'))),
        h('tbody', {}, (disk.items ?? []).map(d => h('tr', {},
          h('td.mono', {}, d.path),
          h('td', {}, bytes(d.bytes)),
          h('td', {}, String(d.files)),
          h('td', {}, d.exists ? badge('是', 'ok') : badge('否')),
        ))),
      )),
    ), diskWrap);

    const items = routes.items ?? [];
    mount(h('div', {},
      h('div.card-head', {}, h('h2', {}, '已注册路由（' + items.length + '）')),
      h('div.table-wrap', { style: { maxHeight: '46vh', overflowY: 'auto' } }, h('table.table', {},
        h('thead', {}, h('tr', {}, h('th', {}, '方法'), h('th', {}, '路径'), h('th', {}, '名称'), h('th', {}, '中间件'))),
        h('tbody', {}, items.map(r => h('tr', {},
          h('td', {}, badge(r.method, 'accent')),
          h('td.mono', {}, r.pattern),
          h('td.small.subtle', {}, r.name ?? '—'),
          h('td.small.mono', {}, (r.middlewares ?? []).join(', ') || '—'),
        ))),
      )),
    ), routesWrap);
  } catch (e) {
    reportError(e, '加载系统信息失败');
  }

  mount(h('div.col', { style: { gap: '14px' } },
    h('div.card', {}, h('div.card-body', {},
      h('div.row', {},
        h('div.grow', {},
          h('h2', {}, '维护操作'),
          h('div.small.muted.mt-8', {}, '清理事件缓存与路由扫描缓存。不会删除日志与配置。'),
        ),
        h('button.btn.btn-danger', {
          onclick: async () => {
            if (!await confirmDialog({ title: '清理缓存', message: '确定清理事件缓存与路由缓存吗？', confirmLabel: '清理' })) return;
            try { await api.post('/webui/api/system/cache', { confirm: true }); ok('缓存已清理'); }
            catch (e) { reportError(e); }
          },
        }, '清理缓存'),
      ),
    )),
    healthWrap,
    diskWrap,
    routesWrap,
  ), container);
}