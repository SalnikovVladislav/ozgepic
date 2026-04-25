// src/config.js — единая конфигурация бота (env + настройки в MySQL)
const fs   = require('fs');
const path = require('path');

const DATA_DIR = path.resolve(__dirname, '..', 'data');
if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });

const LEGACY_SETTINGS_FILE = path.join(DATA_DIR, 'settings.json');

const env = {
  TELEGRAM_BOT_TOKEN:  process.env.TELEGRAM_BOT_TOKEN || '',
  API_INTERNAL_URL:    process.env.API_INTERNAL_URL   || 'http://api/kese',
  API_INTERNAL_TOKEN:  process.env.API_INTERNAL_TOKEN || '',
  BOT_ADMIN_IDS:       (process.env.BOT_ADMIN_IDS || '')
                         .split(',').map((s) => parseInt(s.trim(), 10)).filter(Boolean),
  WEBHOOK_PORT:        parseInt(process.env.WEBHOOK_PORT || '3007', 10),
};

if (!env.TELEGRAM_BOT_TOKEN) throw new Error('TELEGRAM_BOT_TOKEN is required');
if (!env.API_INTERNAL_TOKEN) throw new Error('API_INTERNAL_TOKEN is required');

const settings = {
  TICKET_PRICE: parseInt(process.env.TICKET_PRICE || '12000', 10),
  EXPECTED_IIN: process.env.EXPECTED_IIN || '',
  KASPI_URL:    process.env.KASPI_URL    || '',
};

let _dbRef = null;

async function initSettings() {
  _dbRef = require('./db');
  const rows = await _dbRef.query('SELECT name, value FROM bot_settings');

  if (rows.length === 0) {
    if (fs.existsSync(LEGACY_SETTINGS_FILE)) {
      try {
        const legacy = JSON.parse(fs.readFileSync(LEGACY_SETTINGS_FILE, 'utf8'));
        if (legacy && typeof legacy === 'object') Object.assign(settings, legacy);
        console.log('📥 Импортированы настройки из settings.json');
      } catch (e) { console.error('legacy settings import err:', e.message); }
    }
    settings.TICKET_PRICE = parseInt(settings.TICKET_PRICE, 10) || 12000;
    for (const [k, v] of Object.entries(settings)) {
      await _dbRef.query(
        `INSERT INTO bot_settings (name, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)`,
        [k, String(v)]
      );
    }
  } else {
    for (const r of rows) {
      if (r.name === 'TICKET_PRICE') settings.TICKET_PRICE = parseInt(r.value, 10) || 12000;
      else if (r.name in settings)   settings[r.name] = r.value;
    }
  }
}

function _saveSetting(name, value) {
  if (!_dbRef) return;
  _dbRef.query(
    `INSERT INTO bot_settings (name, value) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE value = VALUES(value)`,
    [name, String(value)]
  ).catch((e) => console.error(`saveSetting(${name}) err:`, e.message));
}

module.exports = {
  env,
  DATA_DIR,
  initSettings,

  get TICKET_PRICE() { return settings.TICKET_PRICE; },
  get EXPECTED_IIN() { return settings.EXPECTED_IIN; },
  get KASPI_URL()    { return settings.KASPI_URL; },

  set TICKET_PRICE(v) { settings.TICKET_PRICE = v; _saveSetting('TICKET_PRICE', v); },
  set EXPECTED_IIN(v) { settings.EXPECTED_IIN = v; _saveSetting('EXPECTED_IIN', v); },
  set KASPI_URL(v)    { settings.KASPI_URL    = v; _saveSetting('KASPI_URL',    v); },
};
