async function request(path, options = {}) {
  const res = await fetch(`/api${path}`, {
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      ...(options.headers || {}),
    },
    ...options,
  })
  const data = await res.json().catch(() => ({}))
  if (!res.ok || data.ok === false) {
    throw new Error(data.error || `HTTP ${res.status}`)
  }
  return data
}

export const api = {
  me: () => request('/me'),
  register: (login, password, email) =>
    request('/auth/register', {
      method: 'POST',
      body: JSON.stringify({ login, password, email }),
    }),
  login: (login, password) =>
    request('/auth/login', {
      method: 'POST',
      body: JSON.stringify({ login, password }),
    }),
  vkMock: (vkId, email) =>
    request('/auth/vk-mock', {
      method: 'POST',
      body: JSON.stringify({ vk_id: vkId, email }),
    }),
  logout: () => request('/auth/logout', { method: 'POST', body: '{}' }),
  worlds: () => request('/worlds'),
  createWorld: (name) =>
    request('/worlds', { method: 'POST', body: JSON.stringify({ name }) }),
  world: (id) => request(`/worlds/${id}`),
  chronicle: (id) => request(`/worlds/${id}/chronicle`),
  advanceDay: (id) =>
    request(`/worlds/${id}/advance-day`, { method: 'POST', body: '{}' }),
}
