# Заливка проекта на GitHub

Репозиторий: https://github.com/Olivvvvvka/eldercare

## Шаг 0. Подготовка (один раз)

### Установить Git
Открой PowerShell и проверь:
```powershell
git --version
```
Если выдаёт ошибку — скачай Git for Windows с https://git-scm.com/download/win, установи с настройками по умолчанию.

### Настроить identity
```powershell
git config --global user.name "Olivvvvvka"
git config --global user.email "olivvvvvka8@gmail.com"
```

### Получить Personal Access Token (PAT)
GitHub с 2021 года не принимает пароль аккаунта при git push. Нужен токен:
1. Открой https://github.com/settings/tokens
2. «Generate new token» → «Generate new token (classic)»
3. Note: `eldercare-push`
4. Expiration: 90 days
5. Scopes: только `repo` (галочка)
6. «Generate token»
7. **Скопируй и сохрани токен в надёжное место** — он показывается один раз

## Шаг 1. Подчистить локальную папку

Прогони миграционные скрипты последний раз, чтобы база была в финальном состоянии:
- http://localhost/eldercare/clear_audit.php (если ещё не запускал)
- http://localhost/eldercare/migrate_encrypt.php (если ещё не запускал)

Затем удали оба файла:
- `C:\xampp\htdocs\eldercare\clear_audit.php`
- `C:\xampp\htdocs\eldercare\migrate_encrypt.php`

Они в `.gitignore`, так что в коммит всё равно не попадут — но руками удалить аккуратнее.

## Шаг 2. Бэкап существующего репозитория (на всякий случай)

Зайди на https://github.com/Olivvvvvka/eldercare. Если там уже есть код, нажми зелёную кнопку «Code» → «Download ZIP» — сохрани куда-нибудь как страховку. Если репозиторий пустой — пропусти этот шаг.

## Шаг 3. Инициализация git локально

В PowerShell:

```powershell
cd C:\xampp\htdocs\eldercare
git init
git branch -M main
git add .
git status
```

Команда `git status` должна показать список файлов. Убедись, что в списке **НЕТ**:
- `backend/db/eldercare.sqlite`
- `clear_audit.php`, `migrate_encrypt.php` (если ещё не удалил)
- `backend/config.php`

Если что-то из этого попало в список — значит `.gitignore` не сработал, скажи мне и разберёмся.

## Шаг 4. Первый коммит

```powershell
git commit -m "Diploma v3: audit log, MSK timezone, AES encryption, 8-char password"
```

## Шаг 5. Привязка к GitHub и пуш

```powershell
git remote add origin https://github.com/Olivvvvvka/eldercare.git
git push -u origin main --force
```

При запросе credentials:
- **Username**: `Olivvvvvka`
- **Password**: твой Personal Access Token (НЕ пароль от GitHub-аккаунта)

`--force` нужен, чтобы перезаписать всё, что было в репозитории до этого. Если хочешь сохранить старую историю — скажи, сделаем по-другому через `git pull --allow-unrelated-histories`.

После успешного push в PowerShell появится что-то вроде:
```
To https://github.com/Olivvvvvka/eldercare.git
 + 1234567...abcdef0 main -> main (forced update)
Branch 'main' set up to track remote branch 'main' from 'origin'.
```

## Шаг 6. Проверь результат

Открой https://github.com/Olivvvvvka/eldercare в браузере. Ты должна увидеть:
- README с описанием проекта
- Папки `backend/`, `frontend/`, `diploma/`
- Файлы `setup.php`, `.gitignore`, `.htaccess`
- НЕТ файлов `clear_audit.php`, `migrate_encrypt.php`
- НЕТ файла `backend/db/eldercare.sqlite`

## Дальше — обычный workflow

Когда снова поправишь что-нибудь в коде:
```powershell
cd C:\xampp\htdocs\eldercare
git add .
git commit -m "Короткое описание изменения"
git push
```

`--force` больше не нужен.

## Если что-то пошло не так

- **«fatal: not a git repository»** — ты не в той папке. Сделай `cd C:\xampp\htdocs\eldercare`
- **«remote origin already exists»** — выполни `git remote remove origin` и повтори `git remote add origin ...`
- **«Authentication failed»** — неправильный токен, либо токен без scope `repo`
- **«failed to push some refs»** без `--force` — выполни `git push --force` (только в первый раз!)
