/**
 * Demo 插件的前端脚本（v3.2 组件注入示例）
 *
 * 这是浏览器直接加载的原生 ES module，不能 import 'vue' —— 所有 Vue API
 * 都从 setup(ctx) 的参数里拿。改完这个文件刷新面板即可生效（无需重新构建前端）。
 *
 * 演示两件事：
 *  1. registerWidget('overview', …)：往概览页长一块「插件前端注入」；
 *  2. registerRoute({ path: '/demo-panel' })：往侧边栏加一个插件页面。
 */
export function setup(ctx) {
  const { h, ref, computed, onMounted, defineComponent } = ctx
  const ui = ctx.ui

  const mono = { fontFamily: 'var(--font-mono)', fontSize: '11px' }
  const dim = { fontSize: '12px', color: 'var(--text-dim)', lineHeight: '1.75' }
  const faint = { fontSize: '11px', color: 'var(--text-faint)', lineHeight: '1.7' }

  // 共享一份钩子数据：概览块和插件页都用它
  const hooks = ref([])
  const failed = ref('')

  async function loadHooks() {
    try {
      const res = await ctx.extApi.call(ctx.plugin, '/hooks', 'GET')
      hooks.value = Array.isArray(res && res.items) ? res.items : []
      failed.value = ''
    } catch (e) {
      failed.value = e && e.message ? e.message : String(e)
    }
  }

  const hookNames = computed(() => hooks.value.map((item) => String(item.name || '')).filter(Boolean))
  const watched = ['message.received', 'message.before_send', 'message.sent']

  const HookTags = defineComponent({
    setup() {
      return () =>
        h(
          'div',
          { style: { display: 'flex', flexWrap: 'wrap', gap: '6px' } },
          hookNames.value.length
            ? hookNames.value.map((name) =>
                h(ui.UiTag, { key: name, size: 'small', round: true, type: watched.indexOf(name) >= 0 ? 'ok' : 'info' }, () => name),
              )
            : [h('span', { style: faint }, '还没有读到钩子')],
        )
    },
  })

  /** 概览页注入块：一个紧凑的钩子概览 */
  const OverviewWidget = defineComponent({
    setup() {
      onMounted(loadHooks)
      return () =>
        h('div', { style: { display: 'flex', flexDirection: 'column', gap: '8px' } }, [
          h('div', { style: dim }, [
            '这块内容由插件自带的 ES module 渲染（',
            h('code', { style: mono }, 'app/Plugins/assets/Demo/panel.js'),
            '），不是后端 schema。',
          ]),
          h('div', { style: faint }, '高亮的是 v3.2 的消息钩子：收到过滤 / 发送拦截 / 已发送。'),
          h(HookTags, { key: 'tags-' + hookNames.value.length }),
          h(
            'div',
            { style: { display: 'flex', gap: '8px' } },
            [
              h(ui.UiButton, { size: 'small', onClick: loadHooks }, () => '刷新钩子'),
              h(ui.UiButton, { size: 'small', type: 'text', onClick: () => ctx.router.push('/demo-panel') }, () => '打开插件页 →'),
            ],
          ),
          failed.value ? h('div', { style: { ...faint, color: 'var(--danger, #d33)' } }, failed.value) : null,
        ])
    },
  })

  /** 独立插件页：由 ctx.registerRoute 注入侧边栏 */
  const DemoPage = defineComponent({
    setup() {
      onMounted(loadHooks)
      return () =>
        h('div', { style: { display: 'flex', flexDirection: 'column', gap: '14px' } }, [
          h(
            'div',
            null,
            [
              h('h1', { style: { margin: '0', fontSize: '19px' } }, '插件演示页'),
              h(
                'p',
                { style: { margin: '4px 0 0', ...faint } },
                '这个页面完全由插件注册：插件 JS 调用 registerRoute() 之后，侧边栏与路由同时生效。',
              ),
            ],
          ),
          h(
            ui.UiCard,
            null,
            {
              header: () => h('span', { style: { fontWeight: 600 } }, '消息钩子'),
              default: () =>
                h('div', { style: { display: 'flex', flexDirection: 'column', gap: '10px' } }, [
                  h(HookTags, { key: 'tags-' + hookNames.value.length }),
                  h('div', { style: faint }, [
                    '在群里发 ',
                    h('code', { style: mono }, '[demo:drop]'),
                    ' 开头的消息会被 message.received 丢弃；发 ',
                    h('code', { style: mono }, '[demo:block]'),
                    ' 会被 message.before_send 拦截，调用方收到失败回执。',
                  ]),
                ]),
            },
          ),
          h(
            ui.UiCard,
            null,
            {
              header: () => h('span', { style: { fontWeight: 600 } }, '钩子明细'),
              default: () =>
                h(
                  'div',
                  { style: { display: 'flex', flexDirection: 'column', gap: '6px' } },
                  hooks.value.length
                    ? hooks.value.map((item, i) =>
                        h('div', { key: 'row-' + i, style: { ...dim, display: 'flex', gap: '10px', alignItems: 'baseline' } }, [
                          h('code', { style: { ...mono, minWidth: '170px', color: 'var(--text)' } }, String(item.name || '')),
                          h('span', null, String(item.handlers || 0) + ' 个处理器'),
                          h('span', { style: faint }, String(item.owners || '')),
                        ]),
                      )
                    : [h('div', { style: faint }, '暂无数据')],
                ),
            },
          ),
          h('div', { style: { display: 'flex', gap: '8px' } }, [
            h(ui.UiButton, { size: 'small', onClick: loadHooks }, () => '刷新'),
            h(ui.UiButton, { size: 'small', type: 'text', onClick: () => ctx.toast.ok('插件按钮也能拿到面板的 toast') }, () => '测试提示'),
          ]),
          h('div', { style: faint }, '窗口里还挂了 window.hyperReloadPlugins()，改完插件 JS 可以直接重载。'),
        ])
    },
  })

  ctx.registerWidget('overview', { title: '插件前端注入', component: OverviewWidget })
  ctx.registerRoute({
    path: '/demo-panel',
    title: '插件演示',
    icon: 'plugins',
    group: '插件',
    component: DemoPage,
  })
  ctx.log('已注册 1 个注入块与 1 个页面')
}
