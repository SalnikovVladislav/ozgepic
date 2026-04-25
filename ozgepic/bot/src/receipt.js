// src/receipt.js — проверка PDF-чека Kaspi
const pdf = require('pdf-parse');
const config = require('./config');
const { isTransactionUsed, markTransactionUsed } = require('./storage');

function log(title, data) {
  console.log(`\n🟦 ${title}`);
  console.log(data);
}

async function checkReceipt(pdfBuffer) {
  try {
    const data = await pdf(pdfBuffer);
    const text = data.text;
    log('PDF TEXT', text);

    const fpPatterns = [
      /(?:ФП|ФБ)\s*[:=]?\s*(\d+)/i,
      /(?:ФП|ФБ)(\d+)/i,
      /No\s*чека\s*[A-Z]*(\d+)/i,
      /№\s*чека\s*[A-Z]*(\d+)/i,
      /Түбіртек\s*No\s*[A-Z]*(\d+)/i,
      /No[A-Z]*(\d{8,})/i,
    ];
    let fpMatch = null;
    for (const p of fpPatterns) { fpMatch = text.match(p); if (fpMatch) break; }

    const iinPatterns = [
      /(?:ИИН|ІЖН).*?продавца.*?(\d{12})/i,
      /(?:ИИН|ІЖН)[\/\s]*(?:БИН|БСН).*?продавца.*?(\d{12})/i,
      /Продавец.*?(?:ИИН|ІЖН).*?(\d{12})/i,
      /Сатушының\s+ЖСН\/БСН\s+(\d{12})/i,
      /ЖСН\/БСН\s*(\d{12})/,
      /продавца\s*(\d{12})/i,
      /БСН\s*(\d{12})/,
      /ЖСН\s*(\d{12})/,
      /(\d{12})/g,
    ];
    let iinMatch = null;
    for (const p of iinPatterns) {
      if (p.global) {
        const matches = text.match(p);
        if (matches) for (const m of matches)
          if (m === config.EXPECTED_IIN) { iinMatch = [null, m]; break; }
      } else {
        iinMatch = text.match(p);
      }
      if (iinMatch) break;
    }

    const sumMatches = [...text.matchAll(/([\d][\d\s]*)\s*₸/g)];
    const actualSum = sumMatches.reduce((max, m) => {
      const v = parseInt(m[1].replace(/\s+/g, ''), 10);
      return v > max ? v : max;
    }, 0);

    log('PARSED', { fpMatch, iinMatch, actualSum });

    if (!fpMatch)        { log('FAIL','ФБ/ФП табылмады'); return false; }
    if (!iinMatch)       { log('FAIL','ЖСН/БСН табылмады'); return false; }
    if (actualSum === 0) { log('FAIL','Сумма табылмады'); return false; }
    if (iinMatch[1] !== config.EXPECTED_IIN) { log('IIN MISMATCH', iinMatch[1]); return false; }

    const attemptsCount = Math.floor(actualSum / config.TICKET_PRICE);
    if (attemptsCount === 0) { log('FAIL', `Сумма < ${config.TICKET_PRICE}`); return false; }

    const fp = fpMatch[1];
    if (await isTransactionUsed(fp)) { log('FAIL','ФБ/ФП бұрын қолданылған'); return false; }
    // markTransactionUsed вызывается в user.js после успешного создания попыток

    return { transactionNumber: fp, sum: actualSum, attemptsCount };
  } catch (err) {
    console.error('checkReceipt error:', err.message);
    return false;
  }
}

module.exports = { checkReceipt };

