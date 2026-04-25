// src/storage.js — состояния пользователей и used-транзакции (в MySQL)
// Состояния кэшируются в памяти для совместимости с существующим
// синхронным API (userStates[userId] = ...). Запись в БД — асинхронная.
const fs   = require('fs');
const path = require('path');
const db   = require('./db');
const { DATA_DIR } = require('./config');

const LEGACY_USED_FILE   = path.join(DATA_DIR, 'used_transactions.json');
const LEGACY_STATES_FILE = path.join(DATA_DIR, 'user_states.json');

const userStates = {};

// ─── Однократный импорт из старых JSON, если таблицы пусты ──────────────
async function migrateLegacyIfNeeded() {
  const [{ c: sCount }] = await db.query('SELECT COUNT(*) AS c FROM bot_user_states');
  if (sCount === 0 && fs.existsSync(LEGACY_STATES_FILE)) {
    try {
      const data = JSON.parse(fs.readFileSync(LEGACY_STATES_FILE, 'utf8'));
      const entries = Object.entries(data || {});
      for (const [uid, state] of entries) {
        await db.query(
          `INSERT INTO bot_user_states (userid, state) VALUES (?, ?)
           ON DUPLICATE KEY UPDATE state = VALUES(state)`,
          [uid, JSON.stringify(state || {})]
        );
      }
      if (entries.length) {
        console.log(`📥 Импортировано ${entries.length} состояний из user_states.json`);
      }
    } catch (e) { console.error('legacy states import err:', e.message); }
  }

  const [{ c: uCount }] = await db.query('SELECT COUNT(*) AS c FROM bot_used_transactions');
  if (uCount === 0 && fs.existsSync(LEGACY_USED_FILE)) {
    try {
      const list = JSON.parse(fs.readFileSync(LEGACY_USED_FILE, 'utf8'));
      if (Array.isArray(list) && list.length) {
        for (const fp of list) {
          await db.query(
            'INSERT IGNORE INTO bot_used_transactions (fp) VALUES (?)',
            [String(fp)]
          );
        }
        console.log(`📥 Импортировано ${list.length} used-транзакций из used_transactions.json`);
      }
    } catch (e) { console.error('legacy used import err:', e.message); }
  }
}

// ─── Загрузка состояний в память ────────────────────────────────────────
async function loadStates() {
  await migrateLegacyIfNeeded();
  const rows = await db.query('SELECT userid, state FROM bot_user_states');
  for (const r of rows) {
    try {
      const s = typeof r.state === 'string' ? JSON.parse(r.state) : r.state;
      userStates[r.userid] = s || {};
    } catch { /* skip corrupted */ }
  }
  console.log(`📂 Загружено ${Object.keys(userStates).length} состояний пользователей из БД`);
}

// ─── Персист одного состояния (fire-and-forget) ─────────────────────────
function persistState(userId) {
  const json = JSON.stringify(userStates[userId] || {});
  db.query(
    `INSERT INTO bot_user_states (userid, state) VALUES (?, ?)
     ON DUPLICATE KEY UPDATE state = VALUES(state)`,
    [userId, json]
  ).catch((e) => console.error('persistState err:', e.message));
}

function updateUserState(userId, patch) {
  userStates[userId] = { ...userStates[userId], ...patch };
  persistState(userId);
}

function clearUserState(userId) {
  if (userStates[userId]) {
    userStates[userId] = {
      step: 'receipt',
      registered: userStates[userId].registered || false,
    };
    persistState(userId);
  }
}

// Обратная совместимость: теперь всё сохраняется автоматически.
function saveStates() { /* no-op */ }

// ─── used-транзакции ────────────────────────────────────────────────────
async function isTransactionUsed(fp) {
  const rows = await db.query(
    'SELECT 1 FROM bot_used_transactions WHERE fp = ? LIMIT 1',
    [String(fp)]
  );
  return rows.length > 0;
}

async function markTransactionUsed(fp) {
  await db.query(
    'INSERT IGNORE INTO bot_used_transactions (fp) VALUES (?)',
    [String(fp)]
  );
}

// Атомарный «захват» ФП. Возвращает true только если МЫ первыми пометили.
// Используется ПЕРЕД созданием попыток — исключает гонку и повторное
// использование чека даже при падении бота в самый неудачный момент.
async function claimTransaction(fp) {
  const res = await db.query(
    'INSERT IGNORE INTO bot_used_transactions (fp) VALUES (?)',
    [String(fp)]
  );
  const affected = res.affectedRows ?? res?.[0]?.affectedRows ?? 0;
  return affected === 1;
}

// Откат «захвата» если создать попытки не удалось (чек снова валиден)
async function releaseTransaction(fp) {
  await db.query('DELETE FROM bot_used_transactions WHERE fp = ?', [String(fp)]);
}

module.exports = {
  loadStates, saveStates,
  userStates, updateUserState, clearUserState,
  isTransactionUsed, markTransactionUsed,
  claimTransaction, releaseTransaction,
};
