## Glazy.org

Glazy.org is a free, open-source ceramics recipes library.
Anyone can add recipes, materials, and photos of their ceramic tests.

### Backend

The Glazy backend is based on the
[Laravel API Boilerplate (JWT Edition) for Laravel 5.5](https://github.com/francescomalatesta/laravel-api-boilerplate-jwt) project.

Laravel API Boilerplate is a "starter kit" you can use to build your first API in seconds. As you can easily imagine, it is built on top of the awesome Laravel Framework. This version is built on Laravel 5.5!

### Frontend

The Glazy frontend is a [Vue](https://vuejs.org/) Single-Page Application (SPA) 

### Inventory quantities

On `/inventory`, use **Edit** beside a material's **Amount in Stock**, enter the
amount and unit, then **Save**. **Cancel** leaves the saved amount unchanged.
Supported units are grams (`g`), kilograms (`kg`), ounces (`oz`), and pounds (`lb`).
Amounts support up to three decimal places, from 0 to 9999999999.999.

An empty amount stops tracking that material's quantity; zero means out of stock.
Changing the unit does not convert the amount: enter the amount in the new unit.
Quantities are maintained manually, not deducted when making recipes. Untracked
and out-of-stock materials remain in the Calculator until removed from inventory.
See the [Inventory guide](https://help.glazy.org/guide/inventory) for adding materials.

#### Deployment

Run `php artisan migrate` before deploying the updated inventory page. The
`2026_11_18_000000_add_stock_units_to_user_materials` migration changes
`stock_amount` to nullable `DECIMAL(13,3)` and adds nullable `stock_unit`.
Existing amounts, including zero, are preserved without assigning an assumed
unit. These entries show "unit not set" until the owner supplies a unit or clears
the amount. Newly added materials start untracked.

Back up inventory data before rolling back: the old schema cannot retain units,
untracked values, or fractions. Rollback drops units, maps untracked amounts to
zero, truncates fractions, and caps amounts at the old unsigned integer maximum.

#### API

Authenticated owners can `PATCH /api/usermaterials/{id}` with both
`stock_amount` and `stock_unit`, for example:

```json
{"stock_amount": "12.375", "stock_unit": "kg"}
```

Send both fields as `null` to stop tracking. Invalid quantities return HTTP 422
without changing saved stock. Responses expose `stockAmount` and `stockUnit`
inside the existing `data` object; database decimal amounts may be JSON strings.
Updates to price/vendor fields can omit both quantity fields.

#### Regression tests

With the existing PHP dependencies, application key, JWT secret, and a disposable
MySQL test database configured:

```sh
vendor/bin/phpunit tests/Functional/Api/V1/Controllers/UserMaterialControllerTest.php
```

The tests rebuild the database using `DatabaseMigrations`; never point them at a
production database. They cover quantity persistence, validation, ownership,
adding materials, and migration/rollback behavior.
