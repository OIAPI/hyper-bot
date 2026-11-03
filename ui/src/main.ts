import { createApp, type Component } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import { router } from './router'
import { initUi } from './ui'
import { UI_COMPONENTS } from './ui/components'
import './styles/theme.css'
import './styles/element-theme.css'
import './styles/base.css'

const app = createApp(App)
app.use(createPinia())
app.use(router)

// 框架无关的控件壳：全局注册，视图里直接写 <UiCard>
for (const [name, component] of Object.entries(UI_COMPONENTS) as Array<[string, Component]>) {
  app.component('Ui' + name, component)
}

/**
 * 全局兜底：单个组件炸掉不应该让整个页面白屏。
 *
 * 之前的 handler 只 console.error —— 于是渲染异常时用户看到的是一整片空白，
 * 连错误信息都拿不到。现在直接把错误画到页面上（带路由、组件信息、堆栈），
 * 用户截个图就能定位问题。
 */
function showFatalError(err: unknown, info = ''): void {
  console.error('[Vue] ' + info, err)
  const text = err instanceof Error ? (err.stack || err.message) : String(err)
  let box = document.getElementById('hyper-fatal')
  if (!box) {
    box = document.createElement('div')
    box.id = 'hyper-fatal'
    box.style.cssText = 'position:fixed;inset:0;z-index:99999;background:#0f1115;color:#e6e6e6;' +
      'font:13px/1.7 -apple-system,system-ui,"Segoe UI",sans-serif;padding:24px;overflow:auto'
    box.innerHTML =
      '<div style="font-size:16px;font-weight:600;margin-bottom:6px">页面出错了</div>' +
      '<div id="hyper-fatal-where" style="color:#9aa4b2;margin-bottom:14px"></div>' +
      '<pre id="hyper-fatal-text" style="white-space:pre-wrap;word-break:break-word;background:#171a21;' +
      'border:1px solid #262b36;border-radius:8px;padding:12px;max-height:52vh;overflow:auto;color:#ffb4b4"></pre>' +
      '<div style="display:flex;gap:10px;margin-top:16px">' +
      '<button id="hyper-fatal-reload" style="padding:7px 14px;border-radius:6px;border:1px solid #3a4152;' +
      'background:#1d2230;color:#e6e6e6;cursor:pointer">刷新页面</button>' +
      '<button id="hyper-fatal-home" style="padding:7px 14px;border-radius:6px;border:1px solid #3a4152;' +
      'background:#1d2230;color:#e6e6e6;cursor:pointer">回到概览</button>' +
      '<button id="hyper-fatal-close" style="padding:7px 14px;border-radius:6px;border:1px solid #3a4152;' +
      'background:transparent;color:#9aa4b2;cursor:pointer">关掉这个提示</button></div>' +
      '<div style="margin-top:10px;color:#6b7482;font-size:12px">关掉后可以继续操作其它页面；' +
      '这条错误同时打在浏览器控制台里。</div>'
    document.body.appendChild(box)
    document.getElementById('hyper-fatal-reload')?.addEventListener('click', () => location.reload())
    document.getElementById('hyper-fatal-home')?.addEventListener('click', () => {
      box?.remove()
      location.href = '/webui/'
    })
    document.getElementById('hyper-fatal-close')?.addEventListener('click', () => box?.remove())
  }
  const where = document.getElementById('hyper-fatal-where')
  if (where) {
    where.textContent = '路由 ' + router.currentRoute.value.fullPath + (info ? ' · ' + info : '')
  }
  const pre = document.getElementById('hyper-fatal-text')
  if (pre) pre.textContent = text
}

app.config.errorHandler = (err, _instance, info) => showFatalError(err, info)
window.addEventListener('unhandledrejection', (event) => showFatalError(event.reason, 'unhandledrejection'))
router.onError((err) => showFatalError(err, 'router'))
// 换页就把遮罩摘掉：否则一个页面的渲染错误会挡住整个面板（连登录页都进不去）
router.afterEach(() => {
  document.getElementById('hyper-fatal')?.remove()
})

// 先加载当前框架的适配器再挂载，避免首屏空白
void initUi(app).finally(() => app.mount('#app'))