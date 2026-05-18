# 🐝 Hive Music — Social Music Network

Hive Music è una web app sociale dove gli utenti possono recensire album cercati su Spotify, seguirsi a vicenda, commentare le recensioni e scambiarsi messaggi privati.

---

## Indice

- [Struttura del progetto](#struttura-del-progetto)
- [Requisiti](#requisiti)
- [Installazione e configurazione](#installazione-e-configurazione)
- [Schema del database](#schema-del-database)
- [Autenticazione JWT](#autenticazione-jwt)
- [API Endpoints](#api-endpoints)
- [Admin Panel](#admin-panel)
- [Client JavaScript (api.js)](#client-javascript-apijs)
- [Polling in tempo reale](#polling-in-tempo-reale)
- [Design System](#design-system)
- [Note per la produzione](#note-per-la-produzione)

---

## Struttura del progetto

```
hivemusic/
├── .env                # Credenziali sensibili (NON versionare)
├── .gitignore
├── .htaccess           # Configurazione Apache
│
├── config.php          # Carica .env e definisce le costanti globali
├── db_connect.php      # Connessione PDO singleton al database
├── api.php             # Backend: unico file con tutte le route API
├── admin_api.php       # Backend: route riservate agli amministratori
│
├── api.js              # Frontend: classi helper per chiamare l'API
├── shared.css          # Stili condivisi a tutte le pagine
│
├── home.html           # Homepage: feed globale + notifiche
├── accedi.html         # Login e registrazione
├── recensione.html     # Creazione nuova recensione (con ricerca Spotify)
├── seguiti.html        # Feed delle recensioni degli utenti seguiti
├── messaggi.html       # Chat privata
├── dettaglio.html      # Dettaglio singola recensione + commenti
├── profilo.html        # Profilo utente
├── admin.html          # Pannello di amministrazione (accesso solo admin)
│
└── uploads/
    └── avatars/        # Avatar caricati dagli utenti (creata automaticamente)
```

---

## Requisiti

- PHP 8.0 o superiore con le estensioni `pdo_mysql` e `curl` attive
- MySQL 5.7+ o MariaDB 10.3+
- Server web Apache
- Account Spotify Developer per le credenziali API

### 2. Crea il file `.env`

```
DB_HOST=localhost
DB_NAME=hivemusic
DB_USER=root
DB_PASS=

SPOTIFY_CLIENT_ID=<il-tuo-client-id>
SPOTIFY_CLIENT_SECRET=<il-tuo-client-secret>

JWT_SECRET=<stringa-casuale-lunga-e-sicura>
```

## Schema del database

Il database contiene le seguenti tabelle principali:

| Tabella | Descrizione |
|---------|-------------|
| `users` | Utenti registrati (username, email, password hash, avatar, bio, ruolo, stato ban) |
| `albums` | Album Spotify salvati localmente (spotify_id, titolo, artista, copertina, tracce) |
| `reviews` | Recensioni degli utenti (voto 1–5, testo, tracce preferite) |
| `likes` | Like/dislike sulle recensioni (value: `1` = like, `-1` = dislike) |
| `comments` | Commenti sulle recensioni |
| `followers` | Relazioni follower/following tra utenti |
| `messages` | Messaggi privati tra utenti |
| `drafts` | Bozze di recensioni non ancora pubblicate |
| `admin_log` | Audit log delle azioni eseguite dagli amministratori |

### Campi aggiuntivi della tabella `users`

Oltre ai campi di base, la tabella `users` include colonne per la gestione dei ruoli e dei ban:

| Colonna | Tipo | Descrizione |
|---------|------|-------------|
| `role` | `enum('user','admin')` | Ruolo dell'utente. Default: `user` |
| `banned_until` | `datetime` | Data di scadenza del ban. `NULL` = non bannato; `9999-12-31` = ban permanente |
| `ban_reason` | `text` | Motivazione del ban (visibile agli admin) |
---

## Autenticazione JWT

Il sistema usa **JSON Web Token (JWT)** con algoritmo HS256.

**Flusso:**
1. L'utente effettua login o registrazione → il backend restituisce un `token`.
2. Il token viene salvato in `localStorage` con la chiave `hm_token`.
3. Ogni richiesta autenticata deve includere l'header:
   ```
   Authorization: Bearer <token>
   ```
4. Il token scade dopo **7 giorni** (`JWT_EXPIRY = 86400 * 7`).
- La password è hashata con `password_hash()` di PHP (bcrypt).

---

## API Endpoints

Tutte le route passano per `api.php?action=<nome_azione>`. Il backend risponde sempre in JSON.

### 🔐 Autenticazione

| Action | Metodo | Auth | Descrizione |
|--------|--------|------|-------------|
| `register` | POST | No | Registra un nuovo utente |
| `login` | POST | No | Login con username/email e password |
| `me` | GET | ✅ | Dati dell'utente autenticato |
| `check_username` | GET | No | Verifica se uno username è disponibile |

**`register` — body:**
```json
{
  "username": "mario",
  "email": "mario@example.com",
  "password": "minimo8caratteri",
  "first_name": "Mario",
  "last_name": "Rossi"
}
```

**`login` — body:**
```json
{ "identifier": "mario", "password": "..." }
```
> `identifier` può essere username **o** email.


---

### 📊 Statistiche

| Action | Metodo | Auth | Descrizione |
|--------|--------|------|-------------|
| `stats` | GET | No | Conteggio globale di recensioni, utenti e album |

**Risposta:**
```json
{ "reviews_count": 120, "users_count": 45, "albums_count": 98 }
```

---

### 🎵 Album

| Action | Metodo | Auth | Descrizione |
|--------|--------|------|-------------|
| `albums_search` | GET | No | Cerca album su Spotify |
| `album_tracks` | GET | No | Tracce di un album (cache locale o Spotify) |

**`albums_search` — query param:** `?q=<testo>`  
**`album_tracks` — query param:** `?spotify_id=<id>`

La ricerca Spotify è limitata al mercato italiano (`market=IT`), restituisce fino a 10 risultati e usa il flusso *Client Credentials* (nessun accesso utente richiesto).

---

### ⭐ Recensioni

| Action | Metodo | Auth | Descrizione |
|--------|--------|------|-------------|
| `reviews` | GET | No | Lista recensioni con filtri e paginazione |
| `review` | GET | No | Singola recensione per ID |
| `review_create` | POST | ✅ | Crea una nuova recensione |
| `review_delete` | POST | ✅ | Elimina una recensione (solo l'autore) |
| `review_like` | POST | ✅ | Metti/togli like o dislike |

**`reviews` — query params:**

| Parametro | Default | Valori possibili |
|-----------|---------|-----------------|
| `genre` | `Tutti` | nome genere o `Tutti` |
| `sort` | `new` | `new`, `pop` (più like), `top` (voto più alto) |
| `page` | `1` | numero pagina |
| `q` | — | ricerca libera per titolo album o artista |

**`review_create` — body:**
```json
{
  "spotify_id": "4aawyAB9vmqN3uQ7FjRGTy",
  "rating": 4,
  "body": "Testo della recensione (min 10 caratteri)",
  "fav_tracks": ["Bohemian Rhapsody", "Killer Queen"]
}
```

> Un utente può recensire ogni album **una sola volta**.

**`review_like` — body:**
```json
{ "review_id": 42, "value": 1 }
```
> `value`: `1` = like · `-1` = dislike · `0` = rimuovi il voto.

---

### 💬 Commenti

| Action | Metodo | Auth | Descrizione |
|--------|--------|------|-------------|
| `comments` | GET | No | Lista commenti di una recensione |
| `comment_create` | POST | ✅ | Pubblica un commento (max 1000 caratteri) |

**`comments` — query param:** `?review_id=<id>`

**`comment_create` — body:**
```json
{ "review_id": 42, "body": "Bellissima recensione!" }
```

---

### 👥 Utenti e Follow

| Action | Metodo | Auth | Descrizione |
|--------|--------|------|-------------|
| `user` | GET | No | Profilo pubblico di un utente |
| `users_search` | GET | No | Cerca utenti per username o nome |
| `following` | GET | ✅ | Lista degli utenti che segui |
| `suggestions` | GET | ✅ | Suggerimenti di utenti da seguire |
| `toggle_follow` | POST | ✅ | Segui o smetti di seguire un utente |
| `user_followers` | GET | No | Seguaci di un utente |
| `user_following` | GET | No | Seguiti di un utente |
| `profile_update` | POST | ✅ | Aggiorna il proprio profilo |
| `avatar_upload` | POST | ✅ | Carica una nuova foto profilo |
| `avatar_delete` | POST | ✅ | Rimuove la foto profilo |

**`user` / `user_followers` / `user_following` — query param:** `?id=<user_id>`

**`users_search` — query param:** `?q=<testo>` (minimo 2 caratteri, max 12 risultati)

**`toggle_follow` — body:**
```json
{ "user_id": 7, "follow": true }
```

**`profile_update` — body** (tutti i campi sono opzionali):
```json
{
  "display_name": "Mario Rossi",
  "bio": "Amo il jazz e il post-rock.",
  "avatar_url": "https://...",
  "current_password": "vecchia",
  "new_password": "nuova-minimo8"
}
```
> Per cambiare la password è obbligatorio fornire anche `current_password`.

**`avatar_upload`** — richiesta `multipart/form-data` con il campo `avatar`. Formati accettati: JPG, PNG, GIF, WebP. Dimensione massima: **5 MB**. Il file precedente viene eliminato automaticamente.

---

### 📰 Feed e Notifiche

| Action | Metodo | Auth | Descrizione |
|--------|--------|------|-------------|
| `feed` | GET | ✅ | Recensioni degli utenti seguiti (o di uno specifico) |
| `notifications` | GET | ✅ | Nuove recensioni dei seguiti (ultime 24h o da un timestamp) |

**`feed` — query param opzionale:** `?userId=<id>` (omettere per il feed globale dei seguiti)

**`notifications` — query param opzionale:** `?since=<ISO_timestamp>` (se omesso: ultime 24 ore)

---

### ✉️ Messaggi

| Action | Metodo | Auth | Descrizione |
|--------|--------|------|-------------|
| `conversations` | GET | ✅ | Lista conversazioni con ultimo messaggio e badge non letti |
| `messages` | GET | ✅ | Messaggi di una conversazione |
| `message_send` | POST | ✅ | Invia un messaggio |
| `messages_read` | POST | ✅ | Segna i messaggi come letti |

**`messages` — query params:** `?userId=<id>` e opzionalmente `?since=<ISO_timestamp>` per caricare solo i nuovi.

**`message_send` — body:**
```json
{ "receiver_id": 5, "body": "Ciao!" }
```

**`messages_read` — body:**
```json
{ "sender_id": 5 }
```

---

---

## Admin Panel

Il pannello di amministrazione è accessibile tramite `admin.html` e comunica esclusivamente con `admin_api.php`. Tutti gli endpoint richiedono un JWT valido con `role = 'admin'` nel payload.

### Accesso

L'admin panel ha una propria schermata di login. Per promuovere un utente ad amministratore è necessario impostare manualmente `role = 'admin'` nel database, oppure usare l'endpoint `admin_set_role` una volta che esiste già almeno un admin.

### Endpoint Admin

Tutte le route passano per `admin_api.php?action=<nome_azione>`.

| Action | Metodo | Descrizione |
|--------|--------|-------------|
| `admin_users` | GET | Lista di tutti gli utenti con stato ban, ruolo e paginazione |
| `admin_ban_user` | POST | Banna un utente per una durata definita |
| `admin_unban_user` | POST | Rimuove il ban da un utente |
| `admin_delete_review` | POST | Elimina una recensione (con motivazione) |
| `admin_delete_comment` | POST | Elimina un commento (con motivazione) |
| `admin_stats` | GET | Statistiche globali per la dashboard |
| `admin_log` | GET | Audit log paginato delle azioni admin |
| `admin_set_role` | POST | Promuove o degrada un utente (`admin` ↔ `user`) |
| `admin_comments` | GET | Lista di tutti i commenti con paginazione |

**`admin_users` — query params:**

| Parametro | Default | Valori possibili |
|-----------|---------|-----------------|
| `page` | `1` | numero pagina (20 per pagina) |
| `search` | — | ricerca per username o email |
| `filter` | `all` | `all`, `banned`, `admin` |

**`admin_ban_user` — body:**
```json
{
  "userId": 7,
  "duration": "7d",
  "reason": "Spam ripetuto"
}
```
> Durate supportate: `1d`, `7d`, `30d`, `90d`, `permanent`. Non è possibile bannare altri amministratori o se stessi.

**`admin_unban_user` — body:**
```json
{ "userId": 7 }
```

**`admin_delete_review` — body:**
```json
{ "reviewId": 42, "reason": "Contenuto inappropriato" }
```

**`admin_delete_comment` — body:**
```json
{ "commentId": 15, "reason": "Insulti" }
```

**`admin_set_role` — body:**
```json
{ "userId": 7, "role": "admin" }
```
> `role` accetta solo `admin` o `user`. Non è possibile modificare il proprio ruolo.

**`admin_stats` — risposta:**
```json
{
  "total_users": 120,
  "total_reviews": 450,
  "total_comments": 1200,
  "total_messages": 3400,
  "banned_users": 3,
  "new_users_7d": 15,
  "new_reviews_7d": 42
}
```

**`admin_log` — risposta paginata** (30 voci per pagina):
```json
{
  "logs": [
    {
      "id": 1,
      "admin_id": 1,
      "admin_username": "pippobura",
      "action": "ban_user",
      "target_type": "user",
      "target_id": 7,
      "details": "Ban 7d. Motivo: Spam. Target: mario",
      "created_at": "2026-05-06T16:45:00"
    }
  ],
  "total": 50,
  "page": 1,
  "pages": 2
}
```

### Audit Log

Ogni azione admin (ban, unban, eliminazione, cambio ruolo) viene registrata automaticamente nella tabella `admin_log` tramite la funzione `auditLog()`. Il log è consultabile dalla sezione "Log" del pannello admin.

---

## Client JavaScript (api.js)

`api.js` espone classi e funzioni pronte all'uso per tutte le pagine frontend. Non richiede build step: basta includerlo prima degli script di pagina.

```html
<script src="api.js"></script>
```

### `ApiClient` — base HTTP

La classe interna `ApiClient` gestisce automaticamente il token JWT da `localStorage`. In genere non la si usa direttamente.

```js
// Usata internamente dalle classi sotto
ApiClient.get('action', { param: 'valore' });
ApiClient.post('action', { chiave: 'valore' });
ApiClient.upload('action', formData); // per file multipart
```

### Classi disponibili

#### `Auth`

```js
const { token, user } = await Auth.login('mario', 'password');
const { token, user } = await Auth.register({ first_name, last_name, username, email, password });
const user             = await Auth.me();
const { available }    = await Auth.checkUsername('mario');

Auth.saveSession(token, user); // salva in localStorage
Auth.getUser();                // legge da localStorage
Auth.isLoggedIn();             // true/false
Auth.logout();                 // cancella sessione e reindirizza ad accedi.html
```

#### `Albums`

```js
const { albums } = await Albums.search('Dark Side of the Moon');
const tracks      = await Albums.getTracks('4aawyAB9vmqN3uQ7FjRGTy');
```

#### `Reviews`

```js
const { reviews } = await Reviews.list({ genre: 'Rock', sort: 'pop', page: 2, q: 'bowie' });
const review       = await Reviews.get(42);
const { review_id } = await Reviews.create({ spotify_id, rating: 5, body, fav_tracks });
await Reviews.delete(42);
const { likes_count, dislikes_count } = await Reviews.like(42, 1); // 1 | -1 | 0
```

#### `Comments`

```js
const { comments } = await Comments.list(42);
const comment       = await Comments.create(42, 'Ottima recensione!');
```

#### `Feed`

```js
const { reviews } = await Feed.get();             // tutti i seguiti
const { reviews } = await Feed.get({ userId: 7 }); // solo utente #7
```

#### `Users`

```js
const { users }  = await Users.following();
const { users }  = await Users.suggestions();
const { users }  = await Users.search('mario');
const user        = await Users.get(7);
const { users }  = await Users.followers(7);
const { users }  = await Users.userFollowing(7);
const { following } = await Users.toggleFollow(7, true);
const updatedUser   = await Users.updateProfile({ display_name: 'Mario', bio: '...' });
const { avatar_url } = await Users.uploadAvatar(fileInput.files[0]);
await Users.deleteAvatar();
```

#### `Messages`

```js
const { conversations } = await Messages.conversations();
const { messages }       = await Messages.list(userId);
const { messages }       = await Messages.list(userId, '2024-01-01T00:00:00'); // solo nuovi
const msg                = await Messages.send(receiverId, 'Ciao!');
await Messages.markRead(senderId);
```

#### `Notifications`

```js
const { notifications } = await Notifications.get();
const { notifications } = await Notifications.get('2024-06-01T12:00:00'); // da timestamp
```

#### `ChatPolling`

```js
ChatPolling.start({
  onConversations: (conversations) => { /* aggiorna UI */ },
  onMessages:      (messages)      => { /* aggiorna UI */ },
  interval: 4000 // ms, default 12000
});

ChatPolling.setActiveConversation(userId); // imposta la chat aperta
ChatPolling.stop();
```

### Funzioni di utilità

```js
avatarInner(user)            // HTML per l'avatar (img o iniziale)
formatCount(1500)            // → "1.5k"
formatRelativeDate(isoStr)   // → "5 min fa", "Ieri", "12 giu"
renderStars(4)               // → HTML con 4 stelle piene e 1 vuota
escapeHtml(str)              // escape XSS
debounce(fn, 300)            // debounce generico
```

---

## Polling in tempo reale

Hive Music usa il **polling HTTP** (non WebSocket) per gli aggiornamenti in tempo reale:

| Contesto | Intervallo | Cosa si aggiorna |
|----------|-----------|-----------------|
| `home.html` — notifiche | 12 secondi | Nuove recensioni degli utenti seguiti |
| `messaggi.html` — chat attiva | 4 secondi | Messaggi della conversazione aperta e lista conversazioni |

Il polling è gestito da `ChatPolling` in `api.js` e da un `setInterval` dedicato in `home.html`.

---

## Design System

Palette "Honeycomb/Amber" definita in `shared.css`:

| Variabile CSS | Valore | Utilizzo |
|---------------|--------|----------|
| `--honey` | `#F5A623` | Accento primario (bottoni, highlight) |
| `--honey-lt` | `#FFD080` | Accento chiaro (hover, badge) |
| `--mint` | `#5CE0C0` | Successo, pill tracce |
| `--coral` | `#FF6B6B` | Errori, avvisi |
| `--hive-900` | `#1a1510` | Background principale |
| `--text` | `#F5F0E8` | Testo primario |