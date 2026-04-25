// src/api.js — тонкий клиент приватного API
const axios = require('axios');
const { env } = require('./config');

const client = axios.create({
  baseURL: env.API_INTERNAL_URL,
  timeout: 15000,
  headers: {
    'Content-Type': 'application/json',
    'X-API-Token':  env.API_INTERNAL_TOKEN,
  },
});

async function call(action, payload = {}) {
  const res = await client.post('', { action, ...payload });
  return res.data;
}

module.exports = {
  checkUser:    (userid) => call('check_user', { userid }),
  register:     (data)   => call('register', data),
  createAttempt:(userid, transaction_number) =>
                           call('create_attempt', { userid, transaction_number }),
  stats:        ()       => call('stats'),
};

