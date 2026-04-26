// src/handlers/user.js — /start, callback, текстовые сообщения, документы
const axios    = require('axios');
const config   = require('../config');
const api      = require('../api');
const messages = require('../templates/messages');
const { checkReceipt }           = require('../receipt');
const { sendKeseResults }        = require('../prizeDelivery');
const { createKeseAttempts }     = require('./admin');
const { userStates, updateUserState, clearUserState,
        claimTransaction, releaseTransaction } = require('../storage');

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

function register(bot) {
  // ─── /start ─────────────────────────────────────────────────────────────
  bot.onText(/\/start/, async (msg) => {
    const chatId = msg.chat.id, userId = msg.from.id;
    const current = userStates[userId];
    if (current && current.step !== 'receipt') {
      return bot.sendMessage(chatId, messages.resumeIncomplete(current), {
        reply_markup: { inline_keyboard: [
          [{ text: '✅ Жалғастыру', callback_data: 'continue' }],
          [{ text: '🔄 Қайта бастау', callback_data: 'restart' }]
        ]}
      });
    }
    try {
      const r = await api.checkUser(userId);
      updateUserState(userId, { step: 'receipt', registered: !!r.exists });
      bot.sendMessage(chatId, messages.welcome({
        registered: !!r.exists,
        price: config.TICKET_PRICE,
        kaspiUrl: config.KASPI_URL,
      }), {
        reply_markup: { inline_keyboard: [
          [{ text: '💳 Төлем жасау', url: config.KASPI_URL }]
        ]}
      });
    } catch (e) {
      console.error('/start error:', e.message);
      bot.sendMessage(chatId, messages.genericError);
    }
  });

  // ─── Callback (inline buttons) ──────────────────────────────────────────
  bot.on('callback_query', async (q) => {
    const chatId = q.message.chat.id, userId = q.from.id;
    if (q.data === 'continue') {
      const s = userStates[userId];
      if (!s) { bot.answerCallbackQuery(q.id, { text: 'Состояние не найдено' }); return; }
      bot.answerCallbackQuery(q.id, { text: '✅ Жалғастырамыз' });
      await sleep(1000);
      if (s.step === 'phone')    bot.sendMessage(chatId, messages.askPhone);
      if (s.step === 'fullname') bot.sendMessage(chatId, messages.askFullname);
    }
    if (q.data === 'restart') {
      bot.answerCallbackQuery(q.id, { text: '🔄 Қайта' });
      clearUserState(userId);
      await sleep(1000);
      bot.sendMessage(chatId, messages.restartInfo);
    }
  });

  // ─── Текстовые сообщения (анкета) ───────────────────────────────────────
  bot.on('message', async (msg) => {
    if (msg.document || msg.text?.startsWith('/')) return;
    const chatId = msg.chat.id, userId = msg.from.id;
    const state  = userStates[userId];
    if (!state) return bot.sendMessage(chatId, messages.mustStart);

    if (state.step === 'phone') {
      updateUserState(userId, { phone: msg.text, step: 'fullname' });
      await sleep(2000);
      return bot.sendMessage(chatId, messages.askFullname);
    }
    if (state.step === 'fullname') {
      const parts = msg.text.trim().split(/\s+/);
      if (parts.length < 2) return bot.sendMessage(chatId, messages.needFullname);
      updateUserState(userId, {
        name: parts[0], surname: parts.slice(1).join(' '), step: 'register'
      });

      // Проверяем что все данные собраны
      if (!state.phone) {
        updateUserState(userId, { step: 'phone' });
        return bot.sendMessage(chatId, '⚠️ Деректер жоғалды. Телефон нөміріңізді қайта енгізіңіз:');
      }

      const name   = parts[0];
      const surname = parts.slice(1).join(' ');
      console.log(`[register] userId=${userId} phone=${state.phone} name=${name}`);
      try {
        const r = await api.register({
          userid: userId, phone: state.phone, name, surname,
          address: '', postcode: '',
        });
        if (!r.success) return bot.sendMessage(chatId, '❌ ' + r.message);

        await bot.sendMessage(chatId, messages.dataAccepted);
        await sleep(2000);
        const claimed = await claimTransaction(state.transactionNumber);
        if (!claimed) {
          return bot.sendMessage(chatId, messages.receiptInvalid(config.TICKET_PRICE));
        }
        let results = [];
        try {
          results = await createKeseAttempts(userId, state.transactionNumber, state.attemptsCount);
        } catch (e) {
          await releaseTransaction(state.transactionNumber);
          throw e;
        }
        if (results.length > 0) {
          await sendKeseResults(bot, chatId, results, state.sum, state.attemptsCount);
        } else {
          await releaseTransaction(state.transactionNumber);
          bot.sendMessage(chatId, messages.attemptCreateFailed);
        }
        updateUserState(userId, { step: 'receipt', registered: true });
      } catch (e) {
        console.error('register err:', e.message);
        bot.sendMessage(chatId, messages.genericError);
      }
      return;
    }
  });

  // ─── Документ (PDF чек) ──────────────────────────────────────────────────
  bot.on('document', async (msg) => {
    const chatId = msg.chat.id, userId = msg.from.id;
    if (!userStates[userId] || userStates[userId].step !== 'receipt') {
      return bot.sendMessage(chatId, messages.mustStart);
    }
    bot.sendMessage(chatId, messages.receiptChecking);
    try {
      const f   = await bot.getFile(msg.document.file_id);
      const url = `https://api.telegram.org/file/bot${config.env.TELEGRAM_BOT_TOKEN}/${f.file_path}`;
      const resp = await axios.get(url, { responseType: 'arraybuffer' });

      const result = await checkReceipt(Buffer.from(resp.data));
      if (!result) return bot.sendMessage(chatId, messages.receiptInvalid(config.TICKET_PRICE));

      await sleep(2000);
      bot.sendMessage(chatId, messages.receiptAccepted(result.sum, result.attemptsCount));

      const check = await api.checkUser(userId);
      if (check.exists) {
        updateUserState(userId, {
          step: 'receipt',
          transactionNumber: result.transactionNumber,
          sum: result.sum, attemptsCount: result.attemptsCount,
        });
        await sleep(2000);
        const claimed = await claimTransaction(result.transactionNumber);
        if (!claimed) {
          return bot.sendMessage(chatId, messages.receiptInvalid(config.TICKET_PRICE));
        }
        let results = [];
        try {
          results = await createKeseAttempts(userId, result.transactionNumber, result.attemptsCount);
        } catch (e) {
          await releaseTransaction(result.transactionNumber);
          throw e;
        }
        if (results.length > 0) {
          await sendKeseResults(bot, chatId, results, result.sum, result.attemptsCount);
        } else {
          await releaseTransaction(result.transactionNumber);
          bot.sendMessage(chatId, messages.attemptCreateFailed);
        }
      } else {
        updateUserState(userId, {
          step: 'phone', registered: false,
          transactionNumber: result.transactionNumber,
          sum: result.sum, attemptsCount: result.attemptsCount,
        });
        await sleep(2000);
        bot.sendMessage(chatId, messages.askPhone);
      }
    } catch (e) {
      console.error('doc handler err:', e.message);
      bot.sendMessage(chatId, messages.pdfError);
    }
  });
}

module.exports = { register };

