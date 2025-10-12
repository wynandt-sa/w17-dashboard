# Workshop17 Ticketing (PHP + MySQL)

## Quick start
1) Create DB & tables
```bash
mysql -u root -p < schema.sql
mysql -u root -p < seed_locations.sql
```

2) Update DB credentials in `config.php`.

3) Deploy files to your PHP server (Apache/Nginx).

4) Open `/login.php`:
- admin / admin123
- user1 / user123

## Files
- config.php, db.php, auth.php
- index.php, login.php, logout.php
- dashboard.php, tickets.php, tasks.php, users.php, locations.php
- partials/header.php, partials/footer.php
- schema.sql, seed_locations.sql

## Notes
- Brand primary color: `#88C28F`
- Ticket number format: `W17-000001` via `counters` table.
- Basic role-guarded pages; Users/Locations require admin.
