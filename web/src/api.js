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
  changePassword: (current_password, new_password) =>
    request('/auth/password', {
      method: 'POST',
      body: JSON.stringify({ current_password, new_password }),
    }),
  settingsList: (resource) => request(`/settings/${resource}`),
  settingsCreate: (resource, body) =>
    request(`/settings/${resource}`, {
      method: 'POST',
      body: JSON.stringify(body),
    }),
  settingsUpdate: (resource, id, body) =>
    request(`/settings/${resource}/${id}`, {
      method: 'PUT',
      body: JSON.stringify(body),
    }),
  settingsDelete: (resource, id) =>
    request(`/settings/${resource}/${id}`, {
      method: 'DELETE',
      body: '{}',
    }),
  catalogList: (q, producer) => {
    const params = new URLSearchParams()
    if (q) params.set('q', q)
    if (producer) params.set('producer', producer)
    const qs = params.toString()
    return request(`/catalog/skus${qs ? `?${qs}` : ''}`)
  },
  catalogGet: (id) => request(`/catalog/skus/${id}`),
  catalogCreate: (body) =>
    request('/catalog/skus', { method: 'POST', body: JSON.stringify(body) }),
  catalogUpdate: (id, body) =>
    request(`/catalog/skus/${id}`, { method: 'PUT', body: JSON.stringify(body) }),
  catalogDelete: (id) =>
    request(`/catalog/skus/${id}`, { method: 'DELETE', body: '{}' }),
  catalogBuildings: () => request('/catalog/buildings'),
  catalogBuildingSave: (body) =>
    request('/catalog/buildings', { method: 'POST', body: JSON.stringify(body) }),
  catalogFiscal: (worldId) => {
    const qs = worldId != null ? `?world_id=${worldId}` : ''
    return request(`/catalog/fiscal${qs}`)
  },
  catalogFiscalSave: (body) =>
    request('/catalog/fiscal', { method: 'PUT', body: JSON.stringify(body) }),
  catalogDensities: (name, productKind) => {
    const params = new URLSearchParams()
    if (name) params.set('name', name)
    if (productKind) params.set('product_kind', productKind)
    const qs = params.toString()
    return request(`/catalog/densities${qs ? `?${qs}` : ''}`)
  },
  catalogDensitiesSave: (items) =>
    request('/catalog/densities', { method: 'PUT', body: JSON.stringify({ items }) }),
  catalogSeed: (force = false) =>
    request('/catalog/seed', { method: 'POST', body: JSON.stringify({ force }) }),
  catalogSeedMerge: (fillDensity = true) =>
    request('/catalog/seed-merge', {
      method: 'POST',
      body: JSON.stringify({ fill_density: fillDensity }),
    }),
  catalogFirms: () => request('/catalog/firms'),
  catalogFirmGet: (code) => request(`/catalog/firms/${encodeURIComponent(code)}`),
  catalogFirmSave: (code, body) =>
    request(`/catalog/firms/${encodeURIComponent(code)}`, {
      method: 'PUT',
      body: JSON.stringify(body),
    }),
  catalogFirmsAttachSeed: () =>
    request('/catalog/firms/attach-seed', { method: 'POST', body: '{}' }),
  catalogMaklendFirms: () => request('/catalog/maklend/firms'),
  catalogMaklendFirmSave: (id, body) =>
    request(`/catalog/maklend/firms/${id}`, {
      method: 'PUT',
      body: JSON.stringify(body),
    }),
  catalogFirmsSeed: (force = false) =>
    request('/catalog/firms/seed', { method: 'POST', body: JSON.stringify({ force }) }),
  worlds: () => request('/worlds'),
  createWorld: (payload) =>
    request('/worlds', {
      method: 'POST',
      body: JSON.stringify(typeof payload === 'string' ? { name: payload } : payload),
    }),
  world: (id) => request(`/worlds/${id}`),
  chronicle: (id) => request(`/worlds/${id}/chronicle`),
  settlementMonitor: (worldId, settlementId) =>
    request(`/worlds/${worldId}/settlements/${settlementId}/monitor`),
  layoutPlotCreate: (worldId, settlementId, body) =>
    request(`/worlds/${worldId}/settlements/${settlementId}/layout/plots`, {
      method: 'POST',
      body: JSON.stringify(body),
    }),
  layoutPlotUpdate: (worldId, settlementId, plotId, body) =>
    request(`/worlds/${worldId}/settlements/${settlementId}/layout/plots/${plotId}`, {
      method: 'PUT',
      body: JSON.stringify(body),
    }),
  layoutPlotDelete: (worldId, settlementId, plotId) =>
    request(`/worlds/${worldId}/settlements/${settlementId}/layout/plots/${plotId}`, {
      method: 'DELETE',
      body: '{}',
    }),
  layoutCanvasExpand: (worldId, settlementId, body) =>
    request(`/worlds/${worldId}/settlements/${settlementId}/layout/canvas/expand`, {
      method: 'POST',
      body: JSON.stringify(body),
    }),
  advanceDay: (id) =>
    request(`/worlds/${id}/advance-day`, { method: 'POST', body: '{}' }),
  resetWorld: (id) =>
    request(`/worlds/${id}/reset`, { method: 'POST', body: '{}' }),
}
