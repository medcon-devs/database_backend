# Typesense contact search

Typesense is the typo-tolerant search index for `csv_data`. MariaDB remains the
source of truth and is used automatically if Typesense is unavailable.

## Local development

Start Typesense in one terminal:

```sh
./scripts/start-typesense.sh
```

Start Laravel in another terminal:

```sh
php artisan serve
```

The first time a database is loaded, or whenever the entire index needs to be
rebuilt, run:

```sh
php artisan scout:flush "App\Models\CSVData"
php artisan scout:import "App\Models\CSVData"
```

New contacts and later edits, deletes, and restores synchronize automatically.
If synchronization fails, Laravel logs a warning but still saves the database
change. Re-running `scout:import` safely repairs the index.

## Environment

Use a strong, private `TYPESENSE_API_KEY` outside local development. Set
`SCOUT_DRIVER=database` to disable Typesense and use the database-only search.
