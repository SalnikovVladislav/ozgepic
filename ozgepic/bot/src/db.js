// src/db.js — пул подключений к MySQL + миграции таблиц бота
const mysql = require('mysql2/promise');

let pool = null;

async function init() {
  pool = mysql.createPool({
    host:     process.env.DB_HOST     || 'db',
    port:     parseInt(process.env.DB_PORT || '3306', 10),
    user:     process.env.DB_USER     || 'kese',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME     || 'kese',
    waitForConnections: true,
    connectionLimit:    5,
    charset: 'utf8mb4',
  });

  // Ждём пока БД поднимется (до 60 секунд)
  let lastErr;
  for (let i = 0; i < 30; i++) {
    try { await pool.query('SELECT 1'); lastErr = null; break; }
    catch (e) {
      lastErr = e;
      console.log(`⏳ DB not ready (${i + 1}/30): ${e.code || e.message}`);
      await new Promise((r) => setTimeout(r, 2000));
    }
  }
  if (lastErr) throw lastErr;

  await migrate();
  console.log('🗄️  DB pool готов');
}

async function migrate() {
  await pool.query(`CREATE TABLE IF NOT EXISTS bot_used_transactions (
    fp VARCHAR(100) NOT NULL PRIMARY KEY,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);

  await pool.query(`CREATE TABLE IF NOT EXISTS bot_user_states (
    userid BIGINT NOT NULL PRIMARY KEY,
    state JSON NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);

  await pool.query(`CREATE TABLE IF NOT EXISTS bot_settings (
    name VARCHAR(50) NOT NULL PRIMARY KEY,
    value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4`);
}

async function query(sql, params) {
  if (!pool) throw new Error('DB pool is not initialized');
  const [rows] = await pool.query(sql, params);
  return rows;
}

module.exports = { init, query };

