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

// 全局兜底：单个组件炸掉不应该让整个页面白屏
app.config.errorHandler = (err, _instance, info) => {
  console.error('[Vue] ' + info, err)
}

// 先加载当前框架的适配器再挂载，避免首屏空白
void initUi(app).finally(() => app.mount('#app'))