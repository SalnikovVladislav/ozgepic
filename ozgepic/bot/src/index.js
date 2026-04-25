// src/index.js — точка входа бота
const TelegramBot = require('node-telegram-bot-api');
const config      = require('./config');
const db          = require('./db');
const storage     = require('./storage');
const adminH      = require('./handlers/admin');
const userH       = require('./handlers/user');
const webhook     = require('./webhook');

(async () => {
  try {
    await db.init();
    await config.initSettings();
    await storage.loadStates();

    const bot = new TelegramBot(config.env.TELEGRAM_BOT_TOKEN, { polling: true });

    adminH.register(bot);
    userH.register(bot);
    webhook.start(bot);

    console.log('🤖 СИҚЫРЛЫ БОКС bot запущен');
    console.log(`   API:            ${config.env.API_INTERNAL_URL}`);
    console.log(`   Admins:         ${config.env.BOT_ADMIN_IDS.join(', ') || '(none)'}`);
    console.log(`   TICKET_PRICE:   ${config.TICKET_PRICE.toLocaleString()}₸`);
    console.log(`   EXPECTED_IIN:   ${config.EXPECTED_IIN}`);
    console.log(`   KASPI_URL:      ${config.KASPI_URL}`);
  } catch (e) {
    console.error('❌ Bootstrap failed:', e);
    process.exit(1);
  }
})();

process.on('unhandledRejection', (e) => console.error('UnhandledRejection:', e));
process.on('uncaughtException',  (e) => console.error('UncaughtException:', e));
