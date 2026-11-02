// 极简 DOM 构造器：无虚拟 DOM、无依赖，直接生成真实节点。
// 用法：h('div.card', { onclick: fn }, [h('span', 'text')])

const SVG_NS = 'http://www.w3.org/2000/svg';

export function h(tag, props, ...children) {
  // 支持 h('div', 'text') 与 h('div', [child])
  if (props === null || props === undefined || typeof props === 'string' || typeof props === 'number' || Array.isArray(props) || props instanceof Node) {
    children = [props, ...children];
    props = {};
  }

  let el;
  let tagName = tag;
  let classes = [];

  // 解析 "div.card.primary#id"
  if (typeof tag === 'string') {
    const idMatch = tag.match(/#([^.\s]+)/);
    const id = idMatch ? idMatch[1] : null;
    const parts = tag.replace(/#[^.\s]+/, '').split('.');
    tagName = parts.shift() || 'div';
    classes = parts.filter(Boolean);
    el = document.createElement(tagName);
    if (id) el.id = id;
  } else {
    el = tag;
  }

  if (classes.length) el.classList.add(...classes);

  for (const [key, value] of Object.entries(props || {})) {
    if (value === null || value === undefined || value === false) continue;
    if (key === 'class') { el.classList.add(...String(value).split(/\s+/).filter(Boolean)); }
    else if (key === 'style' && typeof value === 'object') { Object.assign(el.style, value); }
    else if (key === 'html') { el.innerHTML = value; }
    else if (key === 'dataset') { Object.assign(el.dataset, value); }
    else if (key.startsWith('on') && typeof value === 'function') {
      el.addEventListener(key.slice(2).toLowerCase(), value);
    } else if (key === 'value') { el.value = value; }
    else if (key === 'checked' || key === 'disabled' || key === 'selected' || key === 'readOnly') { el[key] = Boolean(value); }
    else if (key === 'ref' && typeof value === 'function') { value(el); }
    else { el.setAttribute(key, value === true ? '' : value); }
  }

  append(el, children);
  return el;
}

function append(el, children) {
  for (const child of children) {
    if (child === null || child === undefined || child === false || child === true) continue;
    if (Array.isArray(child)) { append(el, child); continue; }
    if (child instanceof Node) { el.appendChild(child); continue; }
    el.appendChild(document.createTextNode(String(child)));
  }
}

export function frag(...children) {
  const f = document.createDocumentFragment();
  append(f, children);
  return f;
}

export function clear(node) {
  while (node.firstChild) node.removeChild(node.firstChild);
  return node;
}

export function mount(node, target) {
  clear(target);
  target.appendChild(node);
  return node;
}

/** 事件委托：在 root 上监听某选择器 */
export function on(root, event, selector, handler) {
  root.addEventListener(event, (e) => {
    const target = e.target.closest(selector);
    if (target && root.contains(target)) handler(e, target);
  });
}

export const $ = (sel, root = document) => root.querySelector(sel);
export const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
