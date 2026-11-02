// 应用入口：持久化布局 + 视图切换 + 登录态。
//
// 设计要点：布局（侧栏 / 顶栏）只在登录后构建一次，切页只替换内容区。
// 这样避免了整页重建带来的竞态（异步视图写入旧容器、焦点与滚动丢失）。

import { h, mount, clear } from './lib/dom.js';
import { api, setUnauthorizedHandler } from './lib/api.js';
import { toast, ok, reportError } from './lib/ui.js';

import * as overviewView from './views/overview.js';
import * as groupsView from './views/groups.js';
import * as pluginsView from './views/plugins.js';
import * as protocolsView from './views/protocols.js';
import * as runtimeView from './views/runtime.js';
import * as subscriptionView from './views/subscription.js';
import * as messagesView from './views/messages.js';
import * as logsView from './views/logs.js';
import * as settingsView from './views/settings.js';
import * as systemView from './views/system.js';
import { loginView } from './views/login.js';

const ROUTES = [
  { path: '/',          title: '概览',     group: '总览', icon: '◉', view: overviewView },
  { path: '/groups',    title: '群管理',   group: '业务', icon: '⚉', view: groupsView },
  { path: '/plugins',   title: '插件',     group: '业务', icon: '◈', view: pluginsView },
  { path: '/messages',  title: '消息记录', group: '业务', icon: '✉', view: messagesView },
  { path: '/protocols', title: '协议接入', group: '接入', icon: '⇄', view: protocolsView },
  { path: '/subscription', title: '消息订阅', group: '接入', icon: '◫', view: subscriptionView },
  { path: '/runtime',   title: '运行架构', group: '接入', icon: '⧉', view: runtimeView },
  { path: '/logs',      title: '日志',     group: '运维', icon: '☰', view: logsView },
  { path: '/settings',  title: '配置',     group: '运维', icon: '⚙', view: settingsView },
  { path: '/system',    title: '系统',     group: '运维', icon: '⌗', view: systemView },
];

const state = {
  user: null,
  page: '/',
  theme: localStorage.getItem('wb-theme')
      || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'),
  sidebarOpen: false,
};

// DOM 引用（登录后填充）
const nodes = { content: null, title: null, nav: null, sidebar: null };

applyTheme(state.theme);

// 脚本异常必须可见，不能白屏。
function showFatal(label, detail) {
  let box = document.getElementById('fatal');
  if (!box) {
    box = document.createElement('div');
    box.id = 'fatal';
    box.style.cssText = 'position:fixed;left:0;right:0;bottom:0;z-index:10000;background:#cc2d39;'
      + 'color:#fff;padding:12px 16px;font:13px/1.5 ui-monospace,monospace;'
      + 'white-space:pre-wrap;max-height:40vh;overflow:auto';
    document.body.appendChild(box);
  }
  box.textContent += '[' + label + '] ' + detail + '\n';
}

window.addEventListener('error', (e) =>
  showFatal('error', (e.message || '') + ' @ ' + (e.filename || '') + ':' + (e.lineno || '')));
window.addEventListener('unhandledrejection', (e) =>
  showFatal('async', String((e.reason && e.reason.message) || e.reason)));

function applyTheme(theme) {
  document.documentElement.setAttribute('data-theme', theme);
  localStorage.setItem('wb-theme', theme);
}

function routeOf(path) {
  return ROUTES.find(r => r.path === path) ?? ROUTES[0];
}

// ---------------- 布局 ----------------

function buildSidebar() {
  const groups = new Map();
  for (const route of ROUTES) {
    if (!groups.has(route.group)) groups.set(route.group, []);
    groups.get(route.group).push(route);
  }

  const nav = h('nav.nav', {},
    Array.from(groups.entries()).map(([group, routes]) => [
      h('div.nav-group-label', {}, group),
      routes.map(route => h('button.nav-item', {
        dataset: { path: route.path },
        onclick: () => navigate(route.path),
      }, h('span.ico', {}, route.icon), h('span.grow', {}, route.title))),
    ]),
  );

  const sidebar = h('aside.sidebar', {},
    h('div.brand', {},
      h('div.brand-mark', {}, 'H'),
      h('div.brand-text.grow', {},
        h('div.brand-title', {}, 'Hyper Bot'),
        h('div.brand-sub', {}, '控制台'),
      ),
      h('button.btn.btn-ghost.btn-icon.sidebar-close', {
        style: { display: 'none' },
        onclick: () => toggleSidebar(false),
        'aria-label': '关闭菜单',
      }, '✕'),
    ),
    nav,
    h('div.sidebar-foot', {},
      h('div.row', { id: 'identity' },
        h('div.grow.truncate', {}, state.user?.username ?? ''),
        h('span.badge.badge-accent', {}, state.user?.role ?? 'admin'),
      ),
      h('div.row', {},
        h('button.btn.btn-ghost.btn-sm.grow', {
          onclick: () => {
            state.theme = state.theme === 'dark' ? 'light' : 'dark';
            applyTheme(state.theme);
            renderThemeButton();
          },
        }, themeLabel()),
        h('button.btn.btn-ghost.btn-sm', { onclick: logout, title: '退出登录' }, '⏻'),
      ),
    ),
  );

  return { sidebar, nav };
}

function themeLabel() {
  return state.theme === 'dark' ? '☀ 浅色' : '☾ 深色';
}

function renderThemeButton() {
  if (nodes.themeBtn) nodes.themeBtn.textContent = themeLabel();
}

function buildTopbar() {
  const title = h('div.topbar-title', {}, routeOf(state.page).title);
  return h('header.topbar', {},
    h('button.btn.btn-ghost.btn-icon.menu-btn', {
      onclick: () => toggleSidebar(!state.sidebarOpen),
      'aria-label': '菜单',
    }, '☰'),
    h('div', {}, title),
    h('div.topbar-actions', {},
      h('button.btn.btn-sm', { onclick: () => reload(true) }, '↻ 刷新'),
    ),
  ).__withTitle = title;
}

function buildShell() {
  const { sidebar, nav } = buildSidebar();
  const topbar = buildTopbar();
  const title = topbar.__withTitle;
  const content = h('div.content', { id: 'content' });

  const shell = h('div.shell', {},
    sidebar,
    h('div.main', {}, topbar, content),
  );

  nodes.sidebar = sidebar;
  nodes.nav = nav;
  nodes.content = content;
  nodes.title = title;
  nodes.themeBtn = sidebar.querySelector('.sidebar-foot .btn-ghost');
  return shell;
}

function toggleSidebar(open) {
  state.sidebarOpen = open;
  nodes.sidebar?.classList.toggle('open', open);
}

function syncNav() {
  if (!nodes.nav) return;
  for (const item of nodes.nav.querySelectorAll('.nav-item')) {
    item.classList.toggle('active', item.dataset.path === state.page);
  }
  if (nodes.title) nodes.title.textContent = routeOf(state.page).title;
}

// ---------------- 视图渲染 ----------------

let renderToken = 0;

async function renderContent() {
  const token = ++renderToken;
  const container = nodes.content;
  if (!container) return;

  const route = routeOf(state.page);
  clear(container);

  try {
    await route.view.render(container, { state, navigate });
  } catch (e) {
    if (token !== renderToken) return;   // 已经切到别的页了，别覆盖
    reportError(e, '页面加载失败');
    mount(h('div.card', {}, h('div.card-body', {},
      h('p.muted', {}, '页面加载失败：' + (e.message ?? e)),
      h('button.btn.mt-16', { onclick: () => reload(true) }, '重试'),
    )), container);
  }
}

function navigate(path) {
  if (path === state.page) return;
  state.page = path;
  history.pushState({}, '', '#' + path);
  syncNav();
  renderContent();
  if (window.innerWidth <= 860) toggleSidebar(false);
  nodes.content?.scrollTo?.({ top: 0 });
}

function reload(force = false) {
  if (force) {
    clear(nodes.content);
  }
  renderContent();
}

// ---------------- 鉴权 ----------------

async function logout() {
  try { await api.post('/webui/api/auth/logout'); } catch { /* 忽略 */ }
  state.user = null;
  nodes.content = null;
  nodes.nav = null;
  nodes.sidebar = null;
  renderLogin();
}

function renderLogin() {
  mount(loginView({ onSuccess: (user) => { state.user = user; renderApp(); } }), document.getElementById('app'));
}

function renderApp() {
  mount(buildShell(), document.getElementById('app'));
  syncNav();
  renderContent();
}

function render() {
  if (!state.user) { renderLogin(); return; }
  renderApp();
}

setUnauthorizedHandler(() => {
  if (state.user) {
    state.user = null;
    toast('会话已过期，请重新登录', 'warn');
    renderLogin();
  }
});

// ---------------- 启动 ----------------

async function boot() {
  const hash = location.hash.replace(/^#/, '') || '/';
  state.page = ROUTES.some(r => r.path === hash) ? hash : '/';

  try {
    const status = await api.get('/webui/api/auth/status');
    if (status?.authenticated && status.user) {
      state.user = status.user;
    }
  } catch { /* 未登录 */ }

  window.addEventListener('popstate', () => {
    const next = location.hash.replace(/^#/, '') || '/';
    if (ROUTES.some(r => r.path === next) && next !== state.page) {
      state.page = next;
      syncNav();
      renderContent();
    }
  });

  render();
}

boot();