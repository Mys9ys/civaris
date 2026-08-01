# Civaris

Симулятор обществ человечков-кубиков. Веб + VK Mini App. Стек: PHP + Vue + MariaDB.

## Структура

```
api/         PHP API (точка входа api/public)
web/         Vue 3 + Vite (сборка в public/)
public/      статика фронта + прокси к API на shared-хостинге
database/    schema.sql
docs/        дизайн и дорожная карта
```

## Локально (OSPanel)

1. Создай БД `civaris` и пользователя в OSPanel.
2. Скопируй `api/config/config.example.php` → `api/config/config.php`, пропиши доступ к БД.  
   В OSPanel 6 для MariaDB-11.4 хост обычно **`127.0.1.16`**, не `127.0.0.1` (см. `hosts`: `mariadb-11.4`).
3. Импортируй `database/schema.sql`.
4. Документ-рут домена `civaris.loc` укажи на папку `public`  
   (или временно на корень проекта и открой `/public/`).
5. Фронт:

```bash
cd web
npm install
npm run dev
```

Dev-сервер Vue (`vite`) проксирует `/api` на PHP.  
Для PHP в OSPanel обычно: `http://civaris.loc/api/...` если `public` = корень и внутри есть `api` symlink/copy.

Упрощённый вариант на старте: корень домена = `D:\OSPanel\home\civaris.loc\public`.

6. Сборка фронта в `public/`:

```bash
cd web
npm run build
```

## Auth

- **local** — логин/пароль (таблица `users`)
- **vk** — заглушка mock для локалки; боевой VK ID подключим отдельно

## Фаза 0 (сейчас)

- регистрация/логин (local)
- создание мира-заглушки
- кнопка «День +1» + летопись
- тонкая админка позже

См. `docs/roadmap.md`.
