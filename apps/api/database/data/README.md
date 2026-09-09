# Uganda administrative reference data

`uganda_admin_units_nodes.csv` is the canonical node export copied from
`Resources/01_uganda_administrative_data_complete/data/uganda_admin_units_nodes.csv`.
Its SHA-256 is `82f661f4bceb21607f3c8084357afed5abbd725aeb3d886bdd7a6f1f6242f095`.

The source contains an explicit `unit_class` column. The importer accepts only
`ADMINISTRATIVE` rows and records the number of rejected `ELECTORAL` rows. It
does not import constituencies or infer administrative parents from electoral
crosswalks.

Run the idempotent import after migrations:

```sh
php artisan erecruit:import-uganda-administrative-units
```

The command imports canonical nodes into `administrative_units`, rebuilds
`administrative_unit_paths` for cascading filters and village search, and logs
the source hash and row counts in `administrative_unit_imports`.
