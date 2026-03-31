/**
 * Hive Music — API Client Utility
 * Include questo file in ogni pagina con <script src="api.js"></script>
 */

const API_BASE = '/api';

async function apiFetch(path, opts = {}) {
  const token = localStorage.getItem('hm_token');
  const headers = {
    'Content-Type': 'application/json',
    ...(token ? { 'Authorization': `Bearer ${token}` } : {}),
    ...(opts.headers || {}),
  };
  const res = await fetch(API_BASE + path, { ...opts, headers });
  if (res.status === 401) {
    localStorage.removeItem('hm_token');
    localStorage.removeItem('hm_user');
    window.location.href = 'accedi.html';
    return;
  }
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.message || `Errore ${res.status}`);
  return data;
}

const Auth = {
  login(identifier, password) {
    return apiFetch('/auth/login', { method: 'POST', body: JSON.stringify({ identifier, password }) });
  },
  register(payload) {
    return apiFetch('/auth/register', { method: 'POST', body: JSON.stringify(payload) });
  },
  me() { return apiFetch('/auth/me'); },
  checkUsername(username) {
    return apiFetch(`/auth/check-username?username=${encodeURIComponent(username)}`);
  },
  saveSession(token, user) {
    localStorage.setItem('hm_token', token);
    localStorage.setItem('hm_user', JSON.stringify(user));
  },
  currentUser() {
    try { return JSON.parse(localStorage.getItem('hm_user')); } catch { return null; }
  },
  logout() {
    localStorage.removeItem('hm_token');
    localStorage.removeItem('hm_user');
    window.location.href = 'accedi.html';
  },
  isLoggedIn() { return !!localStorage.getItem('hm_token'); },
};

const Reviews = {
  list({ genre, sort, page = 1, limit = 8 } = {}) {
    const params = new URLSearchParams({ sort: sort || 'new', page, limit });
    if (genre && genre !== 'Tutti') params.set('genre', genre);
    return apiFetch(`/reviews?${params}`);
  },
  get(id) { return apiFetch(`/reviews/${id}`); },
  create(payload) { return apiFetch('/reviews', { method: 'POST', body: JSON.stringify(payload) }); },
  saveDraft(payload) { return apiFetch('/reviews/drafts', { method: 'POST', body: JSON.stringify(payload) }); },
  like(id, value) {
    if (value === 0) return apiFetch(`/reviews/${id}/like`, { method: 'DELETE' });
    return apiFetch(`/reviews/${id}/like`, { method: 'POST', body: JSON.stringify({ value }) });
  },
  addComment(reviewId, body) {
    return apiFetch(`/reviews/${reviewId}/comments`, { method: 'POST', body: JSON.stringify({ body }) });
  },
};

const Albums = {
  search(query) { return apiFetch(`/albums/search?q=${encodeURIComponent(query)}`); },
};

const Users = {
  get(id) { return apiFetch(`/users/${id}`); },
  following() { return apiFetch('/users/following'); },
  suggestions() { return apiFetch('/users/suggestions'); },
  toggleFollow(id, follow) {
    return apiFetch(`/users/${id}/follow`, { method: follow ? 'POST' : 'DELETE' });
  },
};

const Feed = {
  get({ userId, page = 1, limit = 20 } = {}) {
    const params = new URLSearchParams({ page, limit });
    if (userId) params.set('user_id', userId);
    return apiFetch(`/feed?${params}`);
  },
};

const Messages = {
  conversations() { return apiFetch('/messages/conversations'); },
  list(userId, { page = 1, limit = 50 } = {}) {
    return apiFetch(`/messages/${userId}?page=${page}&limit=${limit}`);
  },
  send(receiverId, body) {
    return apiFetch('/messages', { method: 'POST', body: JSON.stringify({ receiver_id: receiverId, body }) });
  },
  markRead(conversationWithId) {
    return apiFetch('/messages/read', { method: 'PUT', body: JSON.stringify({ conversation_with: conversationWithId }) });
  },
};

const Stats = {
  get() { return apiFetch('/stats'); },
};
