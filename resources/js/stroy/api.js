export class ApiError extends Error {
  constructor(status, code) {
    super(code);
    this.status = status;
    this.code = code;
  }
}

async function request(method, path, { json, form, params, meta } = {}) {
  let url = path;
  if (params) {
    const query = new URLSearchParams();
    for (const [key, value] of Object.entries(params)) {
      if (value !== undefined && value !== null && value !== '') query.set(key, value);
    }
    const text = query.toString();
    if (text) url += '?' + text;
  }
  const options = { method, credentials: 'same-origin', headers: {} };
  if (json !== undefined) {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(json);
  }
  if (form) options.body = form;

  let response;
  try {
    response = await fetch(url, options);
  } catch {
    throw new ApiError(0, 'network');
  }
  if (response.status === 204) return null;
  const total = Number(response.headers.get('X-Total-Count') || 0);
  let data = null;
  try {
    data = await response.json();
  } catch {
    data = null;
  }
  if (!response.ok) {
    if (response.status === 401 && !path.startsWith('/auth/')) {
      window.dispatchEvent(new Event('unauthorized'));
    }
    const code =
      data && typeof data.detail === 'string'
        ? data.detail
        : response.status === 422
          ? 'validation'
          : 'error';
    throw new ApiError(response.status, code);
  }
  return meta ? { items: data, total } : data;
}

export const api = {
  page: (path, params) => request('GET', path, { params, meta: true }),
  get: (path, params) => request('GET', path, { params }),
  post: (path, json) => request('POST', path, { json }),
  patch: (path, json) => request('PATCH', path, { json }),
  put: (path, json) => request('PUT', path, { json }),
  del: (path) => request('DELETE', path),
  upload: (path, form, params) => request('POST', path, { form, params }),
};
