# 🐝 Hive Music - Social Music Network

Una WebApp social dove gli utenti possono recensire album da Spotify, seguirsi a vicenda e scambiarsi messaggi.

## 📋 Requisiti

- **PHP** 7.4+ con estensioni: `pdo_mysql`, `curl`, `json`
- **MySQL/MariaDB** 5.7+
- **XAMPP** o altro server locale (Apache + MySQL)
- Connessione internet (per Spotify API)

## 🚀 Installazione

### 1. Setup Database

```bash
# Accedi a MySQL
mysql -u root -p

# Esegui lo schema
source /percorso/a/schema.sql
```

O importa `schema.sql` tramite phpMyAdmin.

### 2. Configura il Progetto

1. Copia tutti i file nella cartella `htdocs` di XAMPP (es. `C:\xampp\htdocs\hivemusic\`)

2. Modifica `config.php` se necessario:
```php
// Credenziali database (default per XAMPP)
define('DB_HOST', 'localhost');
define('DB_NAME', 'hivemusic');
define('DB_USER', 'root');
define('DB_PASS', '');
```

### 3. Avvia i Servizi

1. Avvia Apache e MySQL da XAMPP Control Panel
2. Visita: `http://localhost/hivemusic/home.html`

## 📁 Struttura File

```
hivemusic/
├── config.php          # Configurazione (DB + Spotify credentials)
├── db_connect.php      # Connessione PDO al database
├── api.php             # Backend API (tutte le route)
├── api.js              # Frontend JavaScript (classi helper)
├── shared.css          # Stili condivisi
├── schema.sql          # Schema database MySQL
│
├── home.html           # Homepage + feed recensioni + notifiche
├── accedi.html         # Login / Registrazione
├── recensione.html     # Crea nuova recensione (+ Spotify search)
├── seguiti.html        # Feed dai seguiti
├── messaggi.html       # Chat/messaggistica
├── dettaglio.html      # Dettaglio recensione
└── README.md           # Questo file
```

## 🔌 API Endpoints

| Endpoint | Metodo | Descrizione |
|----------|--------|-------------|
| `?action=register` | POST | Registrazione utente |
| `?action=login` | POST | Login |
| `?action=me` | GET | Dati utente corrente |
| `?action=check_username` | GET | Verifica disponibilità username |
| `?action=stats` | GET | Statistiche piattaforma |
| `?action=albums_search` | GET | Cerca album su Spotify |
| `?action=reviews` | GET | Lista recensioni (con filtri) |
| `?action=review_create` | POST | Crea recensione |
| `?action=review_like` | POST | Like/Dislike recensione |
| `?action=draft_save` | POST | Salva bozza |
| `?action=feed` | GET | Feed dai seguiti |
| `?action=following` | GET | Lista utenti seguiti |
| `?action=suggestions` | GET | Suggerimenti follow |
| `?action=toggle_follow` | POST | Segui/Smetti di seguire |
| `?action=conversations` | GET | Lista conversazioni |
| `?action=messages` | GET | Messaggi con utente |
| `?action=message_send` | POST | Invia messaggio |
| `?action=messages_read` | POST | Segna come letti |
| `?action=notifications` | GET | Notifiche dai seguiti |
| `?action=user` | GET | Profilo utente |

## 🔑 Autenticazione

L'API usa JWT (JSON Web Token):

1. Effettua login/registrazione per ottenere il token
2. Includi il token in tutte le richieste autenticate:
```
Authorization: Bearer <token>
```

Il token è salvato in `localStorage` come `hm_token`.

## 🎵 Integrazione Spotify

Le credenziali Spotify sono già configurate in `config.php` e `api.js`:

```javascript
// Frontend (api.js)
const SPOTIFY_CLIENT_ID = 'd3859dddd2e44b88a30790a1c1f404dd';
const SPOTIFY_CLIENT_SECRET = 'd3455ddc48414cdebed233dab5684a32';
```

Usa il **Client Credentials Flow** per cercare album senza login Spotify dell'utente.

## ⏱️ Polling

Il sistema usa JavaScript Polling per aggiornamenti in tempo reale:

- **Notifiche**: ogni 12 secondi (home.html)
- **Chat**: ogni 4 secondi quando attiva (messaggi.html)

## 🎨 Design System

Palette colori "Honeycomb/Amber":

| Token | Colore | Uso |
|-------|--------|-----|
| `--honey` | #F5A623 | Accento primario |
| `--honey-lt` | #FFD080 | Accento chiaro |
| `--mint` | #5CE0C0 | Successo/Track pills |
| `--coral` | #FF6B6B | Errori |
| `--hive-900` | #1a1510 | Background |
| `--text` | #F5F0E8 | Testo primario |

## 📱 Responsive

Il design è mobile-first con breakpoint principale a 860px.

## 🧪 Testing

1. **Registra** un nuovo utente
2. **Cerca** un album su Spotify in "Nuova Recensione"
3. **Pubblica** una recensione
4. **Segui** altri utenti dalla sezione "Seguiti"
5. **Invia** un messaggio a chi segui

## ⚠️ Note Importanti

- **Progetto didattico**: Le credenziali Spotify sono esposte nel codice (non fare in produzione!)
- **Sicurezza**: In produzione, usa HTTPS, valida meglio gli input, nascondi le credenziali
- **Database**: Lo schema include vincoli FK e indici per performance

## 📄 Licenza

Progetto a scopo didattico.

---

Creato con 🐝 per il progetto capstone ITTS.
