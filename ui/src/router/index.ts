import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router'
import { getToken } from '@/api/client'
import { onUnauthorized } from '@/api/client'

/**
 * 路由表。meta.group 用于侧边栏分组，meta.title 同时用于面包屑与 document.title。
 */
const routes: RouteRecordRaw[] = [
  {
    path: '/login',
    name: 'login',
    component: () => import('@/views/LoginView.vue'),
    meta: { public: true, title: '登录' },
  },
  {
    path: '/',
    // 命名是为了让插件用 router.addRoute('shell', ...) 挂页面
    name: 'shell',
    component: () => import('@/layout/AppLayout.vue'),
    children: [
      {
        path: '',
        name: 'overview',
        component: () => import('@/views/OverviewView.vue'),
        meta: { title: '概览', icon: 'overview', group: '总览' },
      },
      {
        path: 'connections',
        name: 'connections',
        component: () => import('@/views/ConnectionsView.vue'),
        meta: { title: '连接', icon: 'connections', group: '总览' },
      },
      {
        path: 'events',
        name: 'events',
        component: () => import('@/views/EventsView.vue'),
        meta: { title: '事件', icon: 'events', group: '运行' },
      },
      {
        path: 'logs',
        name: 'logs',
        component: () => import('@/views/LogsView.vue'),
        meta: { title: '日志', icon: 'logs', group: '运行' },
      },
      {
        path: 'messages',
        name: 'messages',
        component: () => import('@/views/MessagesView.vue'),
        meta: { title: '消息记录', icon: 'messages', group: '运行' },
      },
      {
        path: 'groups',
        name: 'groups',
        component: () => import('@/views/GroupsView.vue'),
        meta: { title: '群管理', icon: 'groups', group: '机器人' },
      },
      {
        path: 'plugins',
        name: 'plugins',
        component: () => import('@/views/PluginsView.vue'),
        meta: { title: '插件管理', icon: 'plugins', group: '插件' },
      },
      {
        path: 'accounts',
        name: 'accounts',
        component: () => import('@/views/AccountsView.vue'),
        meta: { title: '机器人账号', icon: 'accounts', group: '机器人' },
      },
      {
        path: 'schedule',
        name: 'schedule',
        component: () => import('@/views/ScheduleView.vue'),
        meta: { title: '定时任务', icon: 'schedule', group: '自动化' },
      },
      {
        path: 'permissions',
        name: 'permissions',
        component: () => import('@/views/PermissionsView.vue'),
        meta: { title: 'QQ 权限', icon: 'permissions', group: '机器人' },
      },
      {
        path: 'subscription',
        name: 'subscription',
        component: () => import('@/views/SubscriptionView.vue'),
        meta: { title: '订阅', icon: 'subscription', group: '自动化' },
      },
      {
        path: 'config',
        name: 'config',
        component: () => import('@/views/ConfigView.vue'),
        meta: { title: '配置', icon: 'config', group: '系统' },
      },
      {
        path: 'runtime',
        name: 'runtime',
        component: () => import('@/views/RuntimeView.vue'),
        meta: { title: '运行环境', icon: 'runtime', group: '运行' },
      },
      {
        path: 'system',
        name: 'system',
        component: () => import('@/views/SystemView.vue'),
        meta: { title: '系统信息', icon: 'system', group: '系统' },
      },
      {
        path: 'ext/:plugin',
        name: 'ext-page',
        component: () => import('@/views/ExtView.vue'),
        // 没有 title：这条不进侧边栏，插件菜单由 /webui/api/ext/pages 动态注入
        meta: { group: '插件' },
      },
      {
        path: 'update',
        name: 'update',
        component: () => import('@/views/UpdateView.vue'),
        meta: { title: '程序更新', icon: 'update', group: '系统' },
      },
      {
        path: 'account',
        name: 'account',
        component: () => import('@/views/AccountView.vue'),
        meta: { title: '面板账号', icon: 'account', group: '系统' },
      },
    ],
  },
  { path: '/:pathMatch(.*)*', redirect: '/' },
]

export const router = createRouter({
  history: createWebHistory('/webui/'),
  routes,
  scrollBehavior: () => ({ top: 0 }),
})

router.beforeEach((to) => {
  const authed = getToken() !== ''
  if (!to.meta.public && !authed) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }
  if (to.name === 'login' && authed) {
    return { name: 'overview' }
  }
  return true
})

router.afterEach((to) => {
  const title = (to.meta.title as string) || ''
  document.title = title ? title + ' · Hyper Bot' : 'Hyper Bot'
})

// 任何接口返回 401 都回到登录页（带来源，登录后跳回去）
onUnauthorized(() => {
  if (router.currentRoute.value.name !== 'login') {
    void router.replace({ name: 'login', query: { redirect: router.currentRoute.value.fullPath } })
  }
})