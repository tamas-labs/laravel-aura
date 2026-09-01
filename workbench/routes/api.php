<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Workbench\App\Tables\EmployeeTable;

/*
|--------------------------------------------------------------------------
| The demo endpoint
|--------------------------------------------------------------------------
|
| One route, and it is the whole integration: Aura POSTs its query state here
| and renders whatever comes back. The `api/` prefix is written out because
| `workbench.discovers.api` adds none, and `config/cors.php` matches on it.
|
*/

Route::prefix('api')->group(function (): void {
    // Aura fetches with POST by default; GET is here so the payload can be
    // looked at in a browser tab without a client.
    Route::match(['get', 'post'], '/employees', fn (Request $request): array => (new EmployeeTable)->respond($request));
});
