<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Python binary for the forecasting microservice
    |--------------------------------------------------------------------------
    | On Windows with the py launcher: 'py'
    | On Linux/macOS: 'python3' or full path to venv python
    */
    'python_bin' => env('PYTHON_BIN', 'python'),

    /*
    |--------------------------------------------------------------------------
    | Process timeout for the Python forecasting subprocess (seconds)
    |--------------------------------------------------------------------------
    | The full 15-stage pipeline takes ~128s on a warm machine. Set this high
    | enough to cover cold starts and heavy SKUs (LightGBM + Prophet).
    | Override with FORECAST_PROCESS_TIMEOUT env var if needed.
    */
    'process_timeout' => (int) env('FORECAST_PROCESS_TIMEOUT', 600),
];
