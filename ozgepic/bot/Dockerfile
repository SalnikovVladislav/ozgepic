FROM node:20-alpine

WORKDIR /app

COPY package.json package-lock.json* ./
RUN npm install --omit=dev --no-audit --no-fund

COPY src ./src
COPY assets ./assets

RUN mkdir -p /app/data

ENV NODE_ENV=production
CMD ["node", "src/index.js"]

