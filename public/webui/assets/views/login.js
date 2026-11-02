import { h } from '../lib/dom.js';
import { api } from '../lib/api.js';

export function loginView({ onSuccess }) {
  const error = h('div', { style: { display: 'none' } });
  const username = h('input.input', { placeholder: '用户名', value: 'admin', autocomplete: 'username' });
  const password = h('input.input', { type: 'password', placeholder: '密码', autocomplete: 'current-password' });
  const submit = h('button.btn.btn-primary', { type: 'submit', style: { width: '100%' } }, '登录');

  const form = h('form.col', {
    style: { gap: '14px' },
    onsubmit: async (e) => {
      e.preventDefault();
      error.style.display = 'none';
      submit.disabled = true;
      submit.textContent = '登录中…';
      try {
        const data = await api.post('/webui/api/auth/login', {
          username: username.value.trim(),
          password: password.value,
        });
        onSuccess(data.user);
      } catch (e2) {
        error.textContent = e2.message ?? '登录失败';
        error.style.display = '';
        error.className = 'badge badge-danger';
        error.style.padding = '8px 12px';
        submit.disabled = false;
        submit.textContent = '登录';
      }
    },
  },
    h('div.field', {}, h('div.field-label', {}, '用户名'), username),
    h('div.field', {}, h('div.field-label', {}, '密码'), password),
    error,
    submit,
  );

  return h('div.login-wrap', {},
    h('div.login-card', {},
      h('div.login-head', {},
        h('div.brand-mark', {}, 'H'),
        h('h1', {}, 'Hyper Bot 控制台'),
        h('div.subtle.small.mt-8', {}, '请登录以继续'),
      ),
      form,
      h('div.subtle.tiny.center', { style: { marginTop: '16px' } },
        '首次启动的密码见 runtime/webui/initial-password.txt'),
    ),
  );
}
