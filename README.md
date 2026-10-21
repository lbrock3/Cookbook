# Family Cookbook

The Family Cookbook website (cookv2.com): all recipes, search with filters,
favorite menus, personal favorites, recipe scaling, printable recipe cards,
a grocery list builder (with send-to-phone links), recipe photos, "I made
this" notes, comments and discussion, recipe submissions with keeper
approval, a keeper-only recipe editor, and automatic daily backups.

## Files

| File | What it does |
|---|---|
| `index.html` | The whole cookbook (recipes, search, menus, grocery list, editor screens) |
| `api.php` | Saves and loads comments, recipe edits, photos and submissions (PHP 8+, SQLite, GD) |
| `config.example.php` | Copy to `config.php` on the server and set your keeper password |
| `RECIPE-EDITOR.txt` | How to use the recipe editor |

## Install (GoDaddy cPanel hosting)

1. Copy `config.example.php` to `config.php` and replace `CHANGE-THIS-PASSWORD` with your own password.
2. In cPanel, open File Manager, then `public_html`.
3. Upload `index.html`, `api.php` and `config.php`.
4. Use PHP 8.0 or newer with the `pdo_sqlite` and `gd` extensions (cPanel > Select PHP Version).
5. Visit the site. The first comment or photo creates the `data/` and `photos/` folders automatically.

## Updating

Upload the new `index.html` (and `api.php` if the update includes one).
Never overwrite `config.php`, `data/` or `photos/`.

## Keep private

`config.php` (your password), `data/` (comments, edits, submissions) and `photos/` (uploaded photos)
are listed in `.gitignore` and must never be committed.
