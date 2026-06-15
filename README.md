# Михаил Стяжкин — портфолио

Статический необруталистичный сайт-портфолио для начинающего разработчика.

## Локальный запуск

```bash
python -m http.server 5173
```

Открыть: `http://127.0.0.1:5173/`

## Структура

- `index.html` — разметка и стили страницы.
- `assets/` — логотип, портрет и шрифты.
- `bot/webhook.php` — Telegram webhook для простого квиза.
- `robots.txt`, `sitemap.xml`, `site.webmanifest`, `favicon.svg` — SEO и служебные файлы.

## Telegram bot

Создать `bot/telegram-config.php` по примеру `bot/telegram-config.example.php`.
Реальный config с токеном не коммитится.
