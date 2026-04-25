// src/webhook.js — HTTP-сервер для уведомлений от api/kese.reveal
const http = require('http');
const { notifyPrizeWin } = require('./prizeDelivery');
const messages = require('./templates/messages');
const { env } = require('./config');

function start(bot) {
  const srv = http.createServer((req, res) => {
    if (req.method !== 'POST') {
      res.writeHead(404); return res.end('Not found');
    }
    let body = '';
    req.on('data', (c) => body += c);
    req.on('end', async () => {
      try {
        // ── /notify_prize — уведомление о выигрыше ──────────────────────────
        if (req.url === '/notify_prize') {
          const { chat_id, prize_name, prize_emoji, prize_img } = JSON.parse(body);
          if (!chat_id || !prize_name) {
            res.writeHead(400);
            return res.end(JSON.stringify({ success: false, message: 'chat_id/prize_name required' }));
          }
          await notifyPrizeWin(bot, chat_id, prize_name, prize_emoji || '🎁', prize_img || null);
          res.writeHead(200, { 'Content-Type': 'application/json' });
          return res.end(JSON.stringify({ success: true }));
        }

        // ── /notify_game — напоминание сыграть (из админки) ─────────────────
        if (req.url === '/notify_game') {
          const { chat_id, attempts } = JSON.parse(body);
          if (!chat_id || !Array.isArray(attempts) || attempts.length === 0) {
            res.writeHead(400);
            return res.end(JSON.stringify({ success: false, message: 'chat_id/attempts required' }));
          }
          const buttons = attempts.map((a, i) => [{
            text: messages.gameButtonText(i + 1),
            url:  a.wheel_url,
          }]);
          await bot.sendMessage(chat_id, messages.pickBowls, {
            reply_markup: { inline_keyboard: buttons }
          });
          res.writeHead(200, { 'Content-Type': 'application/json' });
          return res.end(JSON.stringify({ success: true }));
        }

        res.writeHead(404); return res.end('Not found');
      } catch (e) {
        res.writeHead(500);
        res.end(JSON.stringify({ success: false, error: e.message }));
      }
    });
  });
  srv.listen(env.WEBHOOK_PORT, () => {
    console.log(`🔔 Webhook server on :${env.WEBHOOK_PORT}/notify_prize`);
  });
}

module.exports = { start };

