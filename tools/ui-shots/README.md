# UI screenshots

Before/after captures for the design overhaul. For local use only. Nothing here
runs in production, and images stay in `out/`, which isn't committed.

It covers the marketing site, login and signup, every owner screen with every
feature on, a limited-role cashier and the admin panel, each at 1366x768 and
390x844. For each screen it also records HTTP status, script errors, sideways
overflow and which table columns are cut off.

## Setup, once

    cd tools/ui-shots
    npm install

It drives the Chrome already installed on the machine.

## Each capture

Run from the project root in Git Bash. These use the testing database, so **don't
run tests at the same time**, and the next test run wipes the demo data.

    # 1. Fresh demo data. The script refuses any database but the testing one.
    DB_DATABASE=mobile_shop_pos_testing php artisan migrate:fresh --seed --force
    DB_DATABASE=mobile_shop_pos_testing php tools/ui-shots/seed.php

    # 2. Build, because views use compiled CSS, then serve. --no-reload is what
    #    keeps the app on the testing database.
    npm run build
    DB_DATABASE=mobile_shop_pos_testing php artisan serve --port=8765 --no-reload

    # 3. In a second terminal
    cd tools/ui-shots
    node capture.cjs after-d1

    # 4. Compare with an earlier run
    node compare.cjs before-d0 after-d1

Images land in `out/<label>/<width>/`, measurements in `out/<label>/metrics.json`.
`--only=sales` limits a run to matching screens.

Stop the server when done. End only that process, not every PHP process. Tests
will fail against a shared database if it's still running.
