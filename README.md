# Candace Management System

A simple, secure income & expense tracker built for **Candince** (and just as
useful for personal or household finances) using **PHP + MySQL**.

## What it does

- Record daily income and expenses in one organized place
- Real-time dashboard: total income, total expenses, and remaining balance —
  calculated automatically, no manual math
- Categorize expenses (Food, Transportation, Rent, etc. — fully customizable)
  so you can see exactly where money goes
- Reports page with a date-range filter, an income-vs-expenses trend chart,
  and a spending-by-category breakdown
- Clean, distraction-free interface that anyone can use, technical or not
- Per-user accounts with hashed passwords, so each person's data is private
  and available any time they log in

## Requirements

- PHP 7.4+ (tested on PHP 8.3) with the **pdo_mysql** extension enabled
- MySQL 5.7+ or MariaDB 10.3+
- A web server (Apache/Nginx) or just PHP's built-in server for testing

## Setup

1. **Create the database.** Import the schema:
   ```bash
   mysql -u root -p < database/candace_system.sql
   ```
   This creates the `candace_system` database and all tables.

2. **Configure the connection.** Open `config/config.php` and edit:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'candace_system');
   define('DB_USER', 'root');       // your MySQL username
   define('DB_PASS', '');           // your MySQL password
   ```

3. **Run it.**
   - With XAMPP/WAMP/Laragon: drop this folder into `htdocs`/`www` and visit
     `http://localhost/candace/register.php`.
   - Or, for a quick local test:
     ```bash
     php -S localhost:8000
     ```
     then open `http://localhost:8000/register.php`.

4. **Create your first account** on the registration page. A starter set of
   expense categories (Food & Groceries, Transportation, Utilities, Rent,
   Education, Health, Entertainment, Others) is created for you automatically
   — you can rename, add, or delete categories any time on the Categories page.

## Project structure

```
candace/
├── config/
│   ├── config.php      # DB credentials & app settings — edit this first
│   └── db.php          # PDO connection (auto-loaded, don't need to touch)
├── includes/
│   ├── functions.php   # auth guard, CSRF helpers, formatting helpers
│   ├── header.php       # shared page shell + sidebar nav
│   └── footer.php
├── assets/css/style.css
├── database/candace_system.sql
├── register.php / login.php / logout.php
├── index.php            # dashboard
├── income.php           # add / edit / delete income
├── expenses.php         # add / edit / delete expenses
├── categories.php        # manage expense categories
└── reports.php           # charts & summaries with date filtering
```

## Security notes

- Passwords are hashed with `password_hash()` / verified with `password_verify()`
- All database queries use prepared statements (no SQL injection)
- All forms are protected with CSRF tokens
- All output is escaped with `htmlspecialchars()`
- Login attempts are rate-limited (8 tries per 15-minute window)
- Each user can only see and edit their own records

## Customizing

- **Store name / currency:** edit `STORE_NAME` in `config/config.php`. Amounts
  are formatted in Philippine peso (₱) via the `peso()` helper in
  `includes/functions.php` — change that function if you need a different
  currency symbol.
- **Default categories:** edit the `DEFAULT_CATEGORIES` array in
  `config/config.php` (only affects new accounts going forward).
- **Colors / look:** everything is in `assets/css/style.css`, controlled by
  CSS variables at the top of the file (`--ink`, `--paper`, `--positive`,
  `--negative`, etc.).

## Tested

This build was fully exercised end-to-end (registration, login/logout, adding
and editing income and expenses, category management including duplicate
handling, dashboard totals, and the reports page with real chart data) against
a live PHP 8.3 + MariaDB 10.11 instance before delivery.
-------------------------------------------------------------------

7/10/26 NEED TO ADD
- add a feature that lets all user to combine all expenses from different users through a request, accept/decline method.
- in the income and expenses tab, add a date filter to the all expenses and all income card, biggest and lowest amount and a category filter for expenses. change only the file required, its a hassle if you give the whole folder which makes me download everytime, give me only the edited files.
- add show password when logging in
-
