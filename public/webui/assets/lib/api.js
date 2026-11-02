// 统一 API 客户端：Cookie 会话 + 统一错误处理 + 超时。

class ApiError extends Error {
  constructor(message, status, payload) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.payload = payload;
  }
}

let onUnauthorized = () => {};

export function setUnauthorizedHandler(fn) { onUnauthorized = fn; }

async function request(method, path, body, options = {}) {
  const controller = new AbortController();
  const timeout = setTimeout(() => controller.abort(), options.timeout ?? 20000);

  const init = {
    method,
    credentials: 'same-origin',
    headers: { 'Accept': 'application/json' },
    signal: controller.signal,
  };
  if (body !== undefined && body !== null) {
    init.headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(body);
  }

  let response;
  try {
    response = await fetch(path, init);
  } catch (e) {
    clearTimeout(timeout);
    if (e.name === 'AbortError') throw new ApiError('请求超时，请检查服务是否在线', 0, null);
    throw new ApiError('网络错误：' + e.message, 0, null);
  }
  clearTimeout(timeout);

  let payload = null;
  const text = await response.text();
  if (text) {
    try { payload = JSON.parse(text); } catch { payload = { message: text }; }
  }

  if (response.status === 401) {
    onUnauthorized(payload?.message ?? '未登录');
    throw new ApiError(payload?.message ?? '未登录', 401, payload);
  }
  if (!response.ok) {
    throw new ApiError(payload?.message ?? ('HTTP ' + response.status), response.status, payload);
  }
  if (payload && typeof payload.code === 'number' && payload.code !== 1) {
    throw new ApiError(payload.message ?? '请求失败', response.status, payload);
  }
  return payload?.data ?? payload;
}

export const api = {
  get:    (path, options)        => request('GET', path, null, options),
  post:   (path, body, options)  => request('POST', path, body ?? {}, options),
  put:    (path, body, options)  => request('PUT', path, body ?? {}, options),
  patch:  (path, body, options)  => request('PATCH', path, body ?? {}, options),
  del:    (path, options)        => request('DELETE', path, null, options),
};

export { ApiError };
