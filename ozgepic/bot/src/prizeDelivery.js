// src/prizeDelivery.js — выдача результатов игры пользователю
const fs       = require('fs');
const path     = require('path');
const messages = require('./templates/messages');

const CELEBRATION_IMG = path.resolve(__dirname, '..', 'assets', 'celebration.jpg');

const sleep = (ms) => new Promise(r => setTimeout(r, ms));

const INVITE_CHAT_ID = -1003850082313;

async function generateInviteLink(bot) {
  try {
    const res = await bot.createChatInviteLink(INVITE_CHAT_ID, { member_limit: 1 });
    return res.invite_link;
  } catch (e) {
    console.error('generateInviteLink error:', e.message);
    return 'https://t.me/+c_mJZ_XN-YpkZGUy';
  }
}

async function sendKeseResults(bot, chatId, results, sum, attemptsCount) {
  const inviteLink = await generateInviteLink(bot);
  await bot.sendMessage(chatId, messages.resultsHeader(sum, attemptsCount, inviteLink));
  await sleep(3000);

  if (fs.existsSync(CELEBRATION_IMG)) {
    try { await bot.sendPhoto(chatId, CELEBRATION_IMG); }
    catch (e) { console.error('celebration send error:', e.message); }
  }
  await sleep(2000);

  const buttons = results.map((r, i) => [{
    text: messages.gameButtonText(i + 1),
    url:  r.wheelUrl,
  }]);
  await bot.sendMessage(chatId, messages.pickBowls, {
    reply_markup: { inline_keyboard: buttons }
  });

  await sleep(3000);
  await bot.sendMessage(chatId, messages.deliveryNote);
  await sleep(500);
  await bot.sendMessage(chatId, messages.finalThanks);
}

// Уведомление о выигрыше (webhook от api/kese.reveal)
async function notifyPrizeWin(bot, chatId, prizeName, prizeEmoji, prizeImg) {
  try {
    const text = messages.prizeWinTemplate(prizeName);
    if (prizeImg) {
      await bot.sendPhoto(chatId, prizeImg, { caption: text, parse_mode: 'MarkdownV2' });
    } else {
      await bot.sendMessage(chatId, `${prizeEmoji || '🎉'} ${text}`, { parse_mode: 'MarkdownV2' });
    }
  } catch (e) { console.error('notifyPrizeWin error:', e.message); }
}

module.exports = { sendKeseResults, notifyPrizeWin };

