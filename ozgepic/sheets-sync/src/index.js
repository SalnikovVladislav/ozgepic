// src/index.js — каждые N минут забирает данные из приватного api/export
// и пишет их в Google Sheets.

const axios       = require('axios');
const { google }  = require('googleapis');

const {
  API_INTERNAL_URL       = 'http://api/export',
  API_INTERNAL_TOKEN     = '',
  GSHEETS_SPREADSHEET_ID = '',
  GSHEETS_SHEET_NAME     = 'Kese',
  GSHEETS_INTERVAL_MS    = '600000',
  GOOGLE_APPLICATION_CREDENTIALS = '/app/secrets/service-account.json',
} = process.env;

const INTERVAL = parseInt(GSHEETS_INTERVAL_MS, 10);

if (!API_INTERNAL_TOKEN)     throw new Error('API_INTERNAL_TOKEN required');
if (!GSHEETS_SPREADSHEET_ID) throw new Error('GSHEETS_SPREADSHEET_ID required');

const auth = new google.auth.GoogleAuth({
  keyFile: GOOGLE_APPLICATION_CREDENTIALS,
  scopes:  ['https://www.googleapis.com/auth/spreadsheets'],
});

const HEADERS = [
  'User ID (DB)','TG User ID','Имя','Фамилия','Телефон','Адрес','Индекс','Город',
  'Attempt ID','TG User ID (Attempt)','Номер транзакции','Token','Код ID (numeric)',
  'Сессия','Статус','Дата создания','Дата прокрутки',
  'Prize ID','Название приза','Эмодзи приза',
  'Выбранный бокс','Дата выбора бокса',
];

const mapRow = (i) => [
  i.user_id ?? 'нет', i.tg_user_id ?? 'нет', i.name ?? 'нет', i.surname ?? 'нет',
  i.phone ?? 'нет', i.address ?? 'нет', i.postcode ?? 'нет', i.city ?? 'нет',
  i.attempt_id ?? 'нет', i.attempt_tg_user_id ?? 'нет',
  i.transaction_number ?? 'нет', i.token ?? 'нет', i.numeric_token_id ?? 'нет',
  i.session_num ?? 'нет',
  i.used === 1 ? 'Использована' : 'Не использована',
  i.attempt_created_at ?? 'нет', i.used_at ?? 'нет',
  i.prize_id ?? 'нет', i.prize_name ?? 'нет', i.prize_emoji ?? 'нет',
  i.selected_box ?? 'нет', i.box_selected_at ?? 'нет',
];

async function fetchAndWrite() {
  const ts = new Date().toLocaleString('ru-RU', { timeZone: 'Asia/Almaty' });
  console.log(`\n[${ts}] 🔄 sync...`);
  try {
    const { data } = await axios.get(API_INTERNAL_URL, {
      headers: { 'X-API-Token': API_INTERNAL_TOKEN },
      timeout: 30000,
    });
    if (!data.success) return console.error('API:', data.error || data);
    if (!Array.isArray(data.raw) || data.raw.length === 0)
      return console.warn('⚠️ пусто');

    console.log(`📦 ${data.raw.length} rows (generated ${data.generated_at})`);
    const sorted = [...data.raw].sort((a,b) => (a.attempt_id ?? 0) - (b.attempt_id ?? 0));
    const rows = [HEADERS, ...sorted.map(mapRow)];

    const client = await auth.getClient();
    const sheets = google.sheets({ version: 'v4', auth: client });
    await sheets.spreadsheets.values.clear({
      spreadsheetId: GSHEETS_SPREADSHEET_ID,
      range: `'${GSHEETS_SHEET_NAME}'`,
    });
    await sheets.spreadsheets.values.update({
      spreadsheetId: GSHEETS_SPREADSHEET_ID,
      range: `'${GSHEETS_SHEET_NAME}'!A1`,
      valueInputOption: 'RAW',
      requestBody: { values: rows },
    });
    console.log(`✅ wrote ${rows.length - 1} rows`);
  } catch (e) {
    console.error('❌', e.message);
  }
}

console.log(`🔁 sheets-sync started. Interval: ${INTERVAL}ms`);
fetchAndWrite();
setInterval(fetchAndWrite, INTERVAL);

