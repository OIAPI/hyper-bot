
// WebUI 前端端到端：用 jsdom 提供真实 DOM，直连运行中的 dev-server。
import { JSDOM } from 'jsdom';
import fs from 'node:fs';
import path from 'node:path';

if (!process.env.WB_PASS) {
  console.error('缺少 WB_PASS（WebUI 初始密码）。到 runtime/webui/initial-password.txt 取。');
  process.exit(2);
}

/**
 * WebUI 前端端到端自检（真实 DOM）。
 *
 * 用 jsdom 提供浏览器 DOM，加载真实的 views/lib 模块，并直连一个正在运行的
 * WebUI 服务（bin/webui-serve.php）。这样能覆盖「前端渲染 + 真实 API」整条链路，
 * 而不是只做静态检查。
 *
 * 用法：
 *   npm i jsdom                 # 一次性依赖
 *   WB_PASS=<初始密码> node tests/webui_dom_test.mjs
 *
 * 环境变量：
 *   WB_BASE  服务地址（默认 http://127.0.0.1:8095）
 *   WB_PASS  登录密码（必填）
 *   WB_TMP   临时模块目录（默认系统 temp）
 */
import { fileURLToPath } from 'node:url';

const BASE = process.env.WB_BASE || 'http://127.0.0.1:8095';
const ASSETS = process.env.WB_ASSETS
  || fileURLToPath(new URL('../public/webui/', import.meta.url)).replace(/\/$/, '');
const TMPDIR = process.env.WB_TMP || (await import('node:os')).tmpdir();

const results = [];
const ok = (n, c, extra = '') => { results.push((c ? 'OK   ' : 'FAIL ') + n + (c ? '' : ' ' + extra)); return c; };

const dom = new JSDOM('<!doctype html><html><body><div id="app"></div><div id="toasts"></div></body></html>', {
  url: BASE + '/webui/index.html',
  runScripts: 'outside-only',
});
const { window } = dom;

// jsdom 没有 fetch；用 node 的（带 cookie 罐）
let cookieJar = '';
const realFetch = globalThis.fetch;
globalThis.fetch = async (url, init = {}) => {
  const u = String(url).startsWith('http') ? String(url) : BASE + url;
  const headers = Object.assign({}, init.headers || {});
  if (cookieJar) headers['cookie'] = cookieJar;
  const res = await realFetch(u, { ...init, headers });
  const setCookie = res.headers.get('set-cookie');
  if (setCookie) cookieJar = setCookie.split(';')[0];
  return res;
};
window.fetch = globalThis.fetch;

// 最小浏览器 API 补齐
window.matchMedia = window.matchMedia || (() => ({ matches: false, addListener() {}, removeListener() {} }));
globalThis.matchMedia = window.matchMedia;
const store = new Map();
Object.defineProperty(window, 'localStorage', {
  value: { getItem: k => store.get(k) ?? null, setItem: (k, v) => store.set(k, String(v)), removeItem: k => store.delete(k) },
});
window.history.pushState = (s, t, url) => { const u = new URL(url, BASE); window.location.hash = u.hash; };

// 加载模块：把 import 重写成 file:// 绝对路径再执行
const loadModule = async (rel) => {
  const src = fs.readFileSync(path.join(ASSETS, rel), 'utf8');
  const rewritten = src.replace(/from '(\.{1,2}\/[^']+)'/g, (m, p) => {
    const abs = path.resolve(path.dirname(path.join(ASSETS, rel)), p);
    return "from 'file://" + abs + "'";
  });
  const tmp = path.join(TMPDIR, 'wb-' + Math.random().toString(36).slice(2) + '.mjs');
  fs.writeFileSync(tmp, rewritten);
  return import('file://' + tmp);
};

window.__WB_DEBUG = false;

// dom.js 里直接用了 Node / document 这类全局，jsdom 环境下要补到 globalThis
globalThis.Node = window.Node;
globalThis.document = window.document;
globalThis.window = window;
globalThis.HTMLElement = window.HTMLElement;
globalThis.MutationObserver = window.MutationObserver;
globalThis.DOMParser = window.DOMParser;
globalThis.CustomEvent = window.CustomEvent;
globalThis.getComputedStyle = window.getComputedStyle.bind(window);

// ---------- 1. 登录页渲染 ----------
const app = window.document.getElementById('app');
const { loginView } = await loadModule('assets/views/login.js');
const loginNode = loginView({ onSuccess: (user) => { window.__loggedIn = user; } });
app.appendChild(loginNode);
ok('登录页渲染出表单', loginNode.querySelectorAll('input').length === 2);

// ---------- 2. 走真实登录 API ----------
const res = await window.fetch('/webui/api/auth/login', {
  method: 'POST', headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ username: 'admin', password: process.env.WB_PASS }),
});
const payload = await res.json();
ok('登录 API 成功', res.status === 200 && payload.code === 1, JSON.stringify(payload).slice(0, 120));
ok('下发了会话 Cookie', cookieJar.startsWith('wb_session='), cookieJar.slice(0, 40));

// ---------- 3. 每个视图在真实 DOM 中渲染 ----------
const views = ['overview','groups','plugins','protocols','logs','messages','settings','system'];
for (const name of views) {
  const mod = await loadModule('assets/views/' + name + '.js');
  const box = window.document.createElement('div');
  window.document.body.appendChild(box);
  try {
    await mod.render(box, { state: { user: { username: 'admin', role: 'admin' } }, navigate: () => {} });
    const text = box.textContent.replace(/\s+/g, ' ').trim();
    const buttons = box.querySelectorAll('button').length;
    // 概览页本身没有按钮（纯展示），只要求渲染出实质内容
    const wantButtons = name !== 'overview';
    ok('视图 ' + name + ' 渲染', text.length > 20 && (!wantButtons || buttons > 0), 'len=' + text.length + ' btn=' + buttons);
    if (name === 'groups') {
      ok('群管理有登记入口', /登记群/.test(text));
    }
    if (name === 'plugins') {
      ok('插件页列出插件', /Permission|Group/.test(text));
    }
    if (name === 'protocols') {
      ok('协议页三个协议', ['OneBot11','Milky','QQ'].every(s => text.includes(s)), text.slice(0, 80));
    }
  } catch (e) {
    ok('视图 ' + name + ' 渲染', false, String(e && e.message || e));
  }
  box.remove();
}

// ---------- 4. 关键交互：登记群 -> 关闭插件 -> 复核 ----------
const { api } = await loadModule('assets/lib/api.js');
await api.post('/webui/api/groups', { group_id: '888888' });
const before = await api.get('/webui/api/groups/888888');
await api.post('/webui/api/groups/888888/plugins/App%5CPlugins%5CPermission', { enabled: false });
const after = await api.get('/webui/api/groups/888888');
ok('登记群后可读到', before.group_id === '888888');
ok('关闭插件后 plugins_on 减少', after.plugins_on < after.plugins_total, JSON.stringify(after.plugins_on) + '/' + after.plugins_total);
ok('关闭插件后 worker 有记录', after.worker['App\\Plugins\\Permission'] === false, JSON.stringify(after.worker));
await api.del('/webui/api/groups/888888');

console.log(results.join('\n'));
const failed = results.filter(r => r.startsWith('FAIL'));
console.log('\n' + (failed.length === 0 ? '全部通过' : '失败 ' + failed.length + ' 项'));
process.exit(failed.length === 0 ? 0 : 1);