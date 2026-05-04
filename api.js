/**
 * Hive Music - Frontend API Client
 * File JavaScript unico con tutte le classi helper per le chiamate API
 * 
 * Classi disponibili:
 * - Auth: Autenticazione (login, register, logout, me, checkUsername)
 * - Albums: Ricerca album Spotify (search)
 * - Reviews: Gestione recensioni (list, create, like)
 * - Stats: Statistiche piattaforma (get)
 * - Users: Gestione utenti e follow (following, suggestions, toggleFollow)
 * - Feed: Feed recensioni dai seguiti (get)
 * - Messages: Messaggistica (conversations, list, send, markRead)
 * - Notifications: Notifiche (get, poll)
 * - Spotify: Integrazione diretta Spotify API (solo frontend)
 */

// ============================================================
// CONFIGURAZIONE
// ============================================================

const API_BASE = 'api.php';
const SPOTIFY_CLIENT_ID = 'd3859dddd2e44b88a30790a1c1f404dd';
const SPOTIFY_CLIENT_SECRET = 'd3455ddc48414cdebed233dab5684a32';
const POLLING_INTERVAL = 12000; // 12 secondi
const WS_ENABLED = false; // WebSocket disabilitato, usiamo polling
const WS_URL = ''; // Non usato

// ============================================================
// HTTP CLIENT BASE
// ============================================================

class ApiClient {
    static async request(action, options = {}) {
        const { method = 'GET', body = null, params = {} } = options;
        
        // Build URL with query params
        const url = new URL(API_BASE, window.location.href);
        url.searchParams.set('action', action);
        Object.entries(params).forEach(([k, v]) => {
            if (v !== undefined && v !== null) url.searchParams.set(k, v);
        });
        
        // Build headers
        const headers = { 'Content-Type': 'application/json' };
        const token = localStorage.getItem('hm_token');
        if (token) headers['Authorization'] = `Bearer ${token}`;
        
        // Make request
        const fetchOptions = { method, headers };
        if (body && method !== 'GET') {
            fetchOptions.body = JSON.stringify(body);
        }
        
        try {
            const response = await fetch(url.toString(), fetchOptions);
            const data = await response.json();
            
            if (!response.ok || data.success === false) {
                throw new Error(data.error || `HTTP ${response.status}`);
            }
            
            return data;
        } catch (err) {
            console.error(`API Error [${action}]:`, err);
            throw err;
        }
    }
    
    static get(action, params = {}) {
        return this.request(action, { method: 'GET', params });
    }
    
    static post(action, body = {}, params = {}) {
        return this.request(action, { method: 'POST', body, params });
    }

    static async upload(action, formData) {
        const url = new URL(API_BASE, window.location.href);
        url.searchParams.set('action', action);
        const headers = {};
        const token = localStorage.getItem('hm_token');
        if (token) headers['Authorization'] = `Bearer ${token}`;
        try {
            const response = await fetch(url.toString(), { method: 'POST', headers, body: formData });
            const data = await response.json();
            if (!response.ok || data.success === false) throw new Error(data.error || `HTTP ${response.status}`);
            return data;
        } catch (err) {
            console.error(`API Upload Error [${action}]:`, err);
            throw err;
        }
    }
}

// ============================================================
// AUTH CLASS - Autenticazione
// ============================================================

const Auth = {
    /**
     * Login con email/username e password
     */
    async login(identifier, password) {
        const data = await ApiClient.post('login', { identifier, password });
        return { token: data.token, user: data.user };
    },
    
    /**
     * Registrazione nuovo utente
     */
    async register({ first_name, last_name, username, email, password }) {
        const data = await ApiClient.post('register', {
            first_name, last_name, username, email, password
        });
        return { token: data.token, user: data.user };
    },
    
    /**
     * Ottieni dati utente corrente
     */
    async me() {
        const data = await ApiClient.get('me');
        return data.user;
    },
    
    /**
     * Verifica disponibilità username
     */
    async checkUsername(username) {
        const data = await ApiClient.get('check_username', { username });
        return { available: data.available };
    },
    
    /**
     * Verifica se l'utente è loggato (token presente)
     */
    isLoggedIn() {
        return !!localStorage.getItem('hm_token');
    },
    
    /**
     * Salva sessione (token + user data)
     */
    saveSession(token, user) {
        localStorage.setItem('hm_token', token);
        localStorage.setItem('hm_user', JSON.stringify(user));
    },
    
    /**
     * Ottieni dati utente dalla sessione locale
     */
    getUser() {
        try {
            return JSON.parse(localStorage.getItem('hm_user'));
        } catch {
            return null;
        }
    },
    
    /**
     * Logout - rimuovi sessione
     */
    logout() {
        localStorage.removeItem('hm_token');
        localStorage.removeItem('hm_user');
        window.location.href = 'accedi.html';
    }
};

// ============================================================
// STATS CLASS - Statistiche piattaforma
// ============================================================

const Stats = {
    /**
     * Ottieni statistiche globali
     */
    async get() {
        return await ApiClient.get('stats');
    }
};

// ============================================================
// ALBUMS CLASS - Ricerca album (via backend + Spotify)
// ============================================================

const Albums = {
    /**
     * Cerca album su Spotify
     */
    async search(query) {
        const data = await ApiClient.get('albums_search', { q: query });
        return { albums: data.albums || [] };
    },

    /**
     * Ottieni le tracce di un album (con cache locale)
     */
    async getTracks(spotifyId) {
        const data = await ApiClient.get('album_tracks', { spotify_id: spotifyId });
        return data.tracks || [];
    }
};

// ============================================================
// SPOTIFY CLASS - Integrazione diretta Spotify API (Client Credentials)
// ============================================================

const Spotify = {
    _token: null,
    _tokenExpiry: 0,
    
    /**
     * Ottieni access token Spotify (Client Credentials Flow)
     */
    async getToken() {
        if (this._token && Date.now() < this._tokenExpiry) {
            return this._token;
        }
        
        const credentials = btoa(`${SPOTIFY_CLIENT_ID}:${SPOTIFY_CLIENT_SECRET}`);
        
        const response = await fetch('https://accounts.spotify.com/api/token', {
            method: 'POST',
            headers: {
                'Authorization': `Basic ${credentials}`,
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: 'grant_type=client_credentials'
        });
        
        if (!response.ok) {
            throw new Error('Errore autenticazione Spotify');
        }
        
        const data = await response.json();
        this._token = data.access_token;
        this._tokenExpiry = Date.now() + (data.expires_in - 60) * 1000;
        
        return this._token;
    },
    
    /**
     * Richiesta generica a Spotify API
     */
    async request(endpoint) {
        const token = await this.getToken();
        
        const response = await fetch(`https://api.spotify.com/v1${endpoint}`, {
            headers: { 'Authorization': `Bearer ${token}` }
        });
        
        if (!response.ok) {
            throw new Error(`Spotify API error: ${response.status}`);
        }
        
        return response.json();
    },
    
    /**
     * Cerca album
     */
    async searchAlbums(query, limit = 10) {
        const params = new URLSearchParams({
            q: query,
            type: 'album',
            limit: limit,
            market: 'IT'
        });
        
        const data = await this.request(`/search?${params}`);
        
        return (data.albums?.items || []).map(item => ({
            spotify_id: item.id,
            title: item.name,
            artist: item.artists[0]?.name || 'Sconosciuto',
            cover_url: item.images[0]?.url || null,
            release_year: item.release_date?.substring(0, 4) || '',
            tracks: [] // Verranno caricate separatamente
        }));
    },
    
    /**
     * Ottieni dettagli album con tracce
     */
    async getAlbum(albumId) {
        const data = await this.request(`/albums/${albumId}?market=IT`);
        
        return {
            spotify_id: data.id,
            title: data.name,
            artist: data.artists[0]?.name || 'Sconosciuto',
            cover_url: data.images[0]?.url || null,
            release_year: data.release_date?.substring(0, 4) || '',
            tracks: (data.tracks?.items || []).map(t => t.name)
        };
    },
    
    /**
     * Ottieni solo le tracce di un album
     */
    async getAlbumTracks(albumId) {
        const data = await this.request(`/albums/${albumId}/tracks?limit=50&market=IT`);
        return (data.items || []).map(t => t.name);
    }
};

// ============================================================
// REVIEWS CLASS - Gestione recensioni
// ============================================================

const Reviews = {
    /**
     * Lista recensioni con filtri
     * @param q Ricerca per titolo album o artista
     */
    async list({ genre = 'Tutti', sort = 'new', page = 1, limit = 8, q = '' } = {}) {
        const params = { genre, sort, page, limit };
        if (q) params.q = q;
        const data = await ApiClient.get('reviews', params);
        return { reviews: data.reviews || [], page: data.page };
    },

    /**
     * Elimina una recensione (solo autore)
     */
    async delete(reviewId) {
        return await ApiClient.post('review_delete', { review_id: reviewId });
    },
    
    /**
     * Crea nuova recensione
     */
    async create({ spotify_id, rating, body, fav_tracks = [] }) {
        const data = await ApiClient.post('review_create', {
            spotify_id, rating, body, fav_tracks
        });
        return { review_id: data.review_id };
    },
    
    /**
     * Like/Dislike recensione
     * @param reviewId ID recensione
     * @param value 1 = like, -1 = dislike, 0 = rimuovi
     */
    async like(reviewId, value) {
        const data = await ApiClient.post('review_like', {
            review_id: reviewId,
            value: value
        });
        return {
            likes_count: data.likes_count,
            dislikes_count: data.dislikes_count
        };
    },
    
    /**
     * Ottieni singola recensione (per dettaglio)
     */
    async get(reviewId) {
        const data = await ApiClient.get('review', { id: reviewId });
        return data.review;
    }
};

// ============================================================
// FEED CLASS - Feed recensioni dai seguiti
// ============================================================

const Feed = {
    /**
     * Ottieni feed recensioni
     * @param userId (opzionale) filtra per utente specifico, 'all' per tutti i seguiti
     */
    async get({ userId = null } = {}) {
        const params = {};
        if (userId && userId !== 'all') params.userId = userId;
        
        const data = await ApiClient.get('feed', params);
        return { reviews: data.reviews || [] };
    }
};

// ============================================================
// USERS CLASS - Gestione utenti e follow
// ============================================================

const Users = {
    /**
     * Lista utenti seguiti
     */
    async following() {
        const data = await ApiClient.get('following');
        return { users: data.users || [] };
    },
    
    /**
     * Suggerimenti utenti da seguire
     */
    async suggestions() {
        const data = await ApiClient.get('suggestions');
        return { users: data.users || [] };
    },
    
    /**
     * Segui/Smetti di seguire utente
     */
    async toggleFollow(userId, follow = true) {
        const data = await ApiClient.post('toggle_follow', {
            user_id: userId,
            follow: follow
        });
        return { following: data.following };
    },
    
    /**
     * Ottieni profilo utente
     */
    async get(userId) {
        const data = await ApiClient.get('user', { id: userId });
        return data.user;
    },

    /**
     * Cerca utenti per username o nome visualizzato
     */
    async search(query) {
        const data = await ApiClient.get('users_search', { q: query });
        return { users: data.users || [] };
    },

    /**
     * Aggiorna profilo utente corrente
     */
    async updateProfile({ display_name, bio, avatar_url, current_password, new_password } = {}) {
        const body = {};
        if (display_name  !== undefined) body.display_name  = display_name;
        if (bio           !== undefined) body.bio           = bio;
        if (avatar_url    !== undefined) body.avatar_url    = avatar_url;
        if (current_password)           body.current_password = current_password;
        if (new_password)               body.new_password     = new_password;
        const data = await ApiClient.post('profile_update', body);
        return data.user;
    },

    /**
     * Seguaci di un utente (pubblico)
     */
    async followers(userId) {
        const data = await ApiClient.get('user_followers', { id: userId });
        return { users: data.users || [] };
    },

    /**
     * Lista seguiti di un utente (pubblico)
     */
    async userFollowing(userId) {
        const data = await ApiClient.get('user_following', { id: userId });
        return { users: data.users || [] };
    },

    /**
     * Carica foto profilo
     */
    async uploadAvatar(file) {
        const formData = new FormData();
        formData.append('avatar', file);
        const data = await ApiClient.upload('avatar_upload', formData);
        return { user: data.user, avatar_url: data.avatar_url };
    },

    /**
     * Rimuove la foto profilo corrente
     */
    async deleteAvatar() {
        const data = await ApiClient.post('avatar_delete', {});
        return data.user;
    }
};

// ============================================================
// COMMENTS CLASS - Commenti sulle recensioni
// ============================================================

const Comments = {
    /**
     * Lista commenti di una recensione
     */
    async list(reviewId) {
        const data = await ApiClient.get('comments', { review_id: reviewId });
        return { comments: data.comments || [] };
    },

    /**
     * Pubblica un commento
     */
    async create(reviewId, body) {
        const data = await ApiClient.post('comment_create', { review_id: reviewId, body });
        return data.comment;
    }
};

// ============================================================
// MESSAGES CLASS - Messaggistica
// ============================================================

const Messages = {
    /**
     * Lista conversazioni
     */
    async conversations() {
        const data = await ApiClient.get('conversations');
        return { conversations: data.conversations || [] };
    },
    
    /**
     * Lista messaggi con un utente.
     * Se `since` è un ISO timestamp, restituisce solo i messaggi più recenti.
     */
    async list(userId, since = null) {
        const params = { userId };
        if (since) params.since = since;
        const data = await ApiClient.get('messages', params);
        return { messages: data.messages || [] };
    },
    
    /**
     * Invia messaggio
     */
    async send(receiverId, body) {
        const data = await ApiClient.post('message_send', {
            receiver_id: receiverId,
            body: body
        });
        return data.message;
    },
    
    /**
     * Segna messaggi come letti
     */
    async markRead(senderId) {
        return await ApiClient.post('messages_read', {
            sender_id: senderId
        });
    }
};

// ============================================================
// NOTIFICATIONS
// ============================================================

const Notifications = {
    /**
     * Ottieni notifiche recenti (recensioni dai seguiti nelle ultime 24h)
     * @param {string|null} since  - ISO timestamp opzionale per filtrare
     */
    async get(since = null) {
        const params = since ? { since } : {};
        const data = await ApiClient.get('notifications', params);
        return { notifications: data.notifications || [] };
    }
};

// ============================================================
// CHAT POLLING CLASS - Polling per messaggi
// ============================================================

const ChatPolling = {
    _pollTimer: null,
    _activeConversation: null,
    _conversationsCallback: null,
    _messagesCallback: null,
    
    /**
     * Avvia polling chat
     */
    start({ onConversations, onMessages, interval = POLLING_INTERVAL } = {}) {
        this.stop();
        
        this._conversationsCallback = onConversations;
        this._messagesCallback = onMessages;
        
        const poll = async () => {
            try {
                // Aggiorna lista conversazioni
                if (this._conversationsCallback) {
                    const { conversations } = await Messages.conversations();
                    this._conversationsCallback(conversations);
                }
                
                // Aggiorna messaggi conversazione attiva
                if (this._activeConversation && this._messagesCallback) {
                    const { messages } = await Messages.list(this._activeConversation);
                    this._messagesCallback(messages);
                }
            } catch (err) {
                console.error('Chat poll error:', err);
            }
        };
        
        // Prima esecuzione
        poll();
        
        // Polling periodico
        this._pollTimer = setInterval(poll, interval);
    },
    
    /**
     * Imposta conversazione attiva per il polling
     */
    setActiveConversation(userId) {
        this._activeConversation = userId;
    },
    
    /**
     * Ferma polling
     */
    stop() {
        if (this._pollTimer) {
            clearInterval(this._pollTimer);
            this._pollTimer = null;
        }
        this._activeConversation = null;
    }
};

// ============================================================
// UTILITY FUNCTIONS
// ============================================================

/**
 * Restituisce il contenuto interno di un <div class="avatar">:
 * se l'utente ha un avatar_url mostra l'immagine, altrimenti l'iniziale.
 *
 * Uso tipico:
 *   <div class="avatar" style="width:28px;height:28px;overflow:hidden;">
 *     ${avatarInner(user)}
 *   </div>
 *
 * L'oggetto deve avere almeno `display_name` o `username`, e facoltativamente `avatar_url`.
 */
function avatarInner(user) {
    const name    = (user && (user.display_name || user.username)) || '?';
    const initial = (name[0] || '?').toUpperCase();
    const url     = user && user.avatar_url ? String(user.avatar_url) : '';
    if (!url) return initial;
    // Escape attributi
    const safeUrl = url.replace(/"/g, '&quot;').replace(/</g, '&lt;');
    const safeInit = initial.replace(/'/g, "\\'").replace(/"/g, '&quot;');
    return `<img src="${safeUrl}" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;display:block;" onerror="this.onerror=null;this.parentElement.textContent='${safeInit}';">`;
}
window.avatarInner = avatarInner;

/**
 * Formatta numero con suffisso k/M
 */
function formatCount(n) {
    if (n >= 1000000) return (n / 1000000).toFixed(1).replace('.0', '') + 'M';
    if (n >= 1000) return (n / 1000).toFixed(1).replace('.0', '') + 'k';
    return String(n);
}

/**
 * Formatta data relativa
 */
function formatRelativeDate(isoString) {
    if (!isoString) return '';
    
    const date = new Date(isoString);
    const now = new Date();
    const diffSeconds = Math.floor((now - date) / 1000);
    
    if (diffSeconds < 60) return 'Adesso';
    if (diffSeconds < 3600) return `${Math.floor(diffSeconds / 60)} min fa`;
    if (diffSeconds < 86400) return `${Math.floor(diffSeconds / 3600)} ore fa`;
    if (diffSeconds < 172800) return 'Ieri';
    
    return date.toLocaleDateString('it-IT', { day: 'numeric', month: 'short' });
}

/**
 * Genera stelle rating
 */
function renderStars(rating, maxStars = 5) {
    return Array.from({ length: maxStars }, (_, i) => 
        `<span class="star${i < rating ? ' on' : ''}">★</span>`
    ).join('');
}

/**
 * Escape HTML per prevenire XSS
 */
function escapeHtml(str) {
    if (!str) return '';
    return str
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

/**
 * Debounce function
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// ============================================================
// EXPORTS (per moduli ES6, opzionale)
// ============================================================

// Se usato come modulo ES6
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        Auth, Stats, Albums, Spotify, Reviews, Feed,
        Users, Comments, Messages, Notifications, ChatPolling,
        formatCount, formatRelativeDate, renderStars, escapeHtml, debounce
    };
}
