// src/handlers/admin.js — /test, /settings, /set_price, /set_iin, /set_url, /send_message, /unused, /remind, /remind_all
const config   = require('../config');
const api      = require('../api');
const db       = require('../db');
const messages = require('../templates/messages');
const { sendKeseResults } = require('../prizeDelivery');

const ADMIN_IDS = config.env.BOT_ADMIN_IDS;
const isAdmin = (id) => ADMIN_IDS.includes(id);

async function createKeseAttempts(userId, tx, count) {
  const out = [];
  for (let i = 0; i < count; i++) {
    try {
      const r = await api.createAttempt(userId, `${tx}_${i + 1}`);
      if (r.success) {
        out.push({
          wheelUrl: r.wheel_url, numericToken: r.numeric_token,
          attemptId: r.attempt_id,
        });
      } else {
        console.error(`create_attempt[${i+1}]:`, r.message);
      }
    } catch (e) { console.error(`create_attempt[${i+1}] err:`, e.message); }
  }
  return out;
}

function register(bot) {
  // /test [count]
  bot.onText(/\/test(?:\s+(\d+))?/, async (msg, m) => {
    const chatId = msg.chat.id, userId = msg.from.id;
    if (!isAdmin(userId)) return bot.sendMessage(chatId, messages.noAccess);

    const count = parseInt(m[1]) || 1;
    await bot.sendMessage(chatId, `🧪 Тест режимі: ${count} ойын сілтемесі жасалуда...`);
    const fakeTx = `TEST_${Date.now()}`;
    const results = await createKeseAttempts(userId, fakeTx, count);
    if (results.length > 0) {
      await sendKeseResults(bot, chatId, results, config.TICKET_PRICE * count, count);
    } else {
      await bot.sendMessage(chatId, '❌ Сілтеме жасалмады.');
    }
  });

  // /settings
  bot.onText(/\/settings/, async (msg) => {
    if (!isAdmin(msg.from.id)) return bot.sendMessage(msg.chat.id, messages.noAccess);
    await bot.sendMessage(msg.chat.id, messages.settingsShow({
      price: config.TICKET_PRICE, iin: config.EXPECTED_IIN, url: config.KASPI_URL,
    }), { parse_mode: 'HTML' });
  });

  // /set_price N
  bot.onText(/\/set_price(?:\s+(\d+))?/, async (msg, m) => {
    if (!isAdmin(msg.from.id)) return bot.sendMessage(msg.chat.id, messages.noAccess);
    const v = parseInt(m[1]);
    if (!v || v <= 0) return bot.sendMessage(msg.chat.id, '❌ Формат: /set_price 15000');
    config.TICKET_PRICE = v;
    bot.sendMessage(msg.chat.id, `✅ TICKET_PRICE: *${v.toLocaleString()}₸*`, { parse_mode: 'Markdown' });
  });

  // /set_iin 12цифр
  bot.onText(/\/set_iin(?:\s+(\d{12}))?/, async (msg, m) => {
    if (!isAdmin(msg.from.id)) return bot.sendMessage(msg.chat.id, messages.noAccess);
    if (!m[1]) return bot.sendMessage(msg.chat.id, '❌ Формат: /set_iin 870317400041');
    config.EXPECTED_IIN = m[1];
    bot.sendMessage(msg.chat.id, `✅ EXPECTED_IIN: \`${m[1]}\``, { parse_mode: 'Markdown' });
  });

  // /set_url
  bot.onText(/\/set_url(?:\s+(https?:\/\/\S+))?/, async (msg, m) => {
    if (!isAdmin(msg.from.id)) return bot.sendMessage(msg.chat.id, messages.noAccess);
    if (!m[1]) return bot.sendMessage(msg.chat.id, '❌ Формат: /set_url https://pay.kaspi.kz/pay/xxx');
    config.KASPI_URL = m[1];
    bot.sendMessage(msg.chat.id, `✅ KASPI\\_URL: \`${m[1]}\``, { parse_mode: 'Markdown' });
  });

  // /send_message <id> "<text>"
  bot.onText(/\/send_message(?:\s+(\d+))?\s*"([\s\S]+)"/, async (msg, m) => {
    if (!isAdmin(msg.from.id)) return bot.sendMessage(msg.chat.id, messages.noAccess);
    const [ , target, text ] = m;
    if (!target || !text) return bot.sendMessage(msg.chat.id,
      '❌ Қате формат: /send_message 123456789 "мәтін"');
    try {
      await bot.sendMessage(target, text);
      bot.sendMessage(msg.chat.id, `✅ Жіберілді → ${target}`);
    } catch (e) { bot.sendMessage(msg.chat.id, `❌ Қате: ${e.message}`); }
  });

  // /unused — список пользователей с неиспользованными попытками
  bot.onText(/\/unused/, async (msg) => {
    const chatId = msg.chat.id;
    if (!isAdmin(msg.from.id)) return bot.sendMessage(chatId, messages.noAccess);

    try {
      const dbName = process.env.DB_NAME || 'kese';
      const countResult = await db.query('SELECT COUNT(*) AS total FROM kese_attempts WHERE used = 0');
      const totalUnused = countResult[0]?.total || 0;
      console.log(`[/unused] DB: ${dbName}, Total unused: ${totalUnused}`);

      const rows = await db.query(`
        SELECT a.userid,
               COUNT(a.id)          AS cnt,
               GROUP_CONCAT(a.numeric_token ORDER BY a.id ASC SEPARATOR ', ') AS tokens,
               u.name, u.surname, u.phone
        FROM kese_attempts a
        LEFT JOIN kese_users u ON u.userid = a.userid
        WHERE a.used = 0
        GROUP BY a.userid
        ORDER BY MIN(a.created_at) DESC
      `);

      console.log(`[/unused] Found ${rows.length} users with unused attempts`);

      if (!rows.length) {
        return bot.sendMessage(chatId, `✅ Барлығы ойнады — қалдық жоқ!`);
      }

      // Разбиваем на чанки по 30 чтобы не превысить лимит Telegram
      const CHUNK = 30;
      for (let i = 0; i < rows.length; i += CHUNK) {
        const chunk = rows.slice(i, i + CHUNK);
        const lines = chunk.map(r => {
          const name = [r.name, r.surname].filter(Boolean).join(' ') || '—';
          const phone = r.phone || '—';
          return `👤 <code>${r.userid}</code> | ${name} | ${phone}\n   🎟 ${r.cnt} попытка: #${r.tokens}\n   👉 /remind_${r.userid}`;
        });
        const header = i === 0 ? `⏳ <b>Ойнамағандар: ${rows.length} адам</b>\n\n` : '';
        await bot.sendMessage(chatId, header + lines.join('\n\n'), { parse_mode: 'HTML' });
      }
    } catch (e) {
      bot.sendMessage(chatId, `❌ Қате: ${e.message}`);
    }
  });

  // /remind_<userid> или /remind <userid> — отправить ссылки конкретному пользователю
  bot.onText(/\/remind(?:_(\d+))?(?:\s+(\d+))?/, async (msg, m) => {
    const chatId = msg.chat.id;
    if (!isAdmin(msg.from.id)) return bot.sendMessage(chatId, messages.noAccess);

    const targetId = parseInt(m[1] || m[2]);
    console.log(`[/remind] text="${msg.text}", m[1]=${m[1]}, m[2]=${m[2]}, targetId=${targetId}`);
    if (!targetId) return bot.sendMessage(chatId, '❌ Формат: /remind 123456789');

    try {
      const rows = await db.query(
        'SELECT token, numeric_token FROM kese_attempts WHERE userid = ? AND used = 0 ORDER BY id ASC',
        [targetId]
      );
      console.log(`[/remind] userid=${targetId} returned ${rows.length} rows`);
      if (!rows.length) {
        return bot.sendMessage(chatId, `ℹ️ ID ${targetId} — неиспользованных попыток нет.`);
      }

      const siteUrl = process.env.SITE_PUBLIC_URL || '';
      const buttons = rows.map((r, i) => [{
        text: messages.gameButtonText(i + 1),
        url:  `${siteUrl}/kese.php?token=${r.token}`,
      }]);

      await bot.sendMessage(targetId, messages.pickBowls, {
        reply_markup: { inline_keyboard: buttons }
      });

      bot.sendMessage(chatId, `✅ ID ${targetId} — ${rows.length} сілтеме жіберілді.`);
    } catch (e) {
      console.error(`[/remind] Error:`, e);
      bot.sendMessage(chatId, `❌ Қате: ${e.message}`);
    }
  });

  // /remind_all — отправить всем у кого есть неиспользованные попытки
  bot.onText(/\/remind_all/, async (msg) => {
    const chatId = msg.chat.id;
    if (!isAdmin(msg.from.id)) return bot.sendMessage(chatId, messages.noAccess);

    try {
      const users = await db.query(`
        SELECT userid, COUNT(id) AS cnt
        FROM kese_attempts WHERE used = 0
        GROUP BY userid
      `);
      if (!users.length) return bot.sendMessage(chatId, '✅ Барлығы ойнады!');

      await bot.sendMessage(chatId, `📣 ${users.length} пайдаланушыға жіберілуде...`);

      const siteUrl = process.env.SITE_PUBLIC_URL || '';
      let ok = 0, fail = 0;

      for (const u of users) {
        try {
          const rows = await db.query(
            'SELECT token FROM kese_attempts WHERE userid = ? AND used = 0 ORDER BY id ASC',
            [u.userid]
          );
          const buttons = rows.map((r, i) => [{
            text: messages.gameButtonText(i + 1),
            url:  `${siteUrl}/kese.php?token=${r.token}`,
          }]);
          await bot.sendMessage(u.userid, messages.pickBowls, {
            reply_markup: { inline_keyboard: buttons }
          });
          ok++;
        } catch (e) {
          fail++;
          console.error(`remind_all err [${u.userid}]:`, e.message);
        }
        // Задержка между отправками чтобы не превысить лимит Telegram
        await new Promise(r => setTimeout(r, 500));
      }

      bot.sendMessage(chatId, `✅ Готово: ${ok} жіберілді, ${fail} қате.`);
    } catch (e) {
      bot.sendMessage(chatId, `❌ Қате: ${e.message}`);
    }
  });
}

module.exports = { register, createKeseAttempts };

