<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Tests\Workbench;

use PHPUnit\Framework\Attributes\Test;
use TamasLabs\Aura\Tests\TestCase;
use TamasLabs\Aura\Tests\WorkbenchTestCase;
use Workbench\App\Models\Employee;
use Workbench\App\Tables\EmployeeTable;
use Workbench\Database\Seeders\DatabaseSeeder;

/**
 * The demo application, exercised the way a browser exercises it.
 *
 * A plain PHPUnit class rather than a Pest file, and that is not a style
 * choice: `tests/Pest.php` binds every Pest file under `tests/` to
 * {@see TestCase}, and Pest refuses to let a second
 * directory rule narrow one that is already in place. A test case that boots a
 * *different application* cannot be expressed as a Pest file here — so it is
 * expressed as the thing Pest runs alongside them.
 *
 * What this protects is the part of "run the demo" that CI can do: the routes,
 * the CORS answer, the table, and the payload the browser is handed. Whether
 * Aura then *renders* it is the question the browser answers, and the reason
 * `composer serve` exists.
 */
final class DemoAppTest extends WorkbenchTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
    }

    #[Test]
    public function it_serves_the_demo_endpoint(): void
    {
        $response = $this->postJson('/api/employees', [
            'page' => 1,
            'paginate' => 5,
            'sortable' => [['field' => 'last_name', 'direction' => 'asc']],
        ]);

        $response->assertOk();

        $payload = $response->json();
        $items = auraDigArray($payload, 'items');

        expect($items)->toHaveCount(5)
            ->and(auraDig($payload, 'meta', 'total'))->toBe(Employee::count())
            ->and(array_column($items, 'last_name'))
            ->toBe(['Allen', 'Dijkstra', 'Hamilton', 'Hoare', 'Hopper']);

        assertMatchesAuraResponseSchema(auraObject($payload));
    }

    #[Test]
    public function it_answers_a_cross_origin_preflight(): void
    {
        // The Aura dev server is on another port, so every call it makes is
        // cross-origin and starts with an `OPTIONS` no route would ever match.
        $response = $this->call('OPTIONS', '/api/employees', server: [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ]);

        $response->assertNoContent();
        $response->assertHeader('Access-Control-Allow-Origin', '*');
    }

    #[Test]
    public function it_offers_exactly_what_its_columns_allow(): void
    {
        $permissions = (new EmployeeTable)->permissions();

        expect($permissions->sortable)->toBe(['last_name', 'company.name', 'salary', 'hired_at'])
            ->and($permissions->globalSearch)->toBe(['email', 'company.name']);

        // The whitelist is the ceiling, and the query layer answers 422 above it.
        $this->postJson('/api/employees', [
            'page' => 1,
            'paginate' => 5,
            'sortable' => [['field' => 'salary_secret', 'direction' => 'asc']],
        ])->assertStatus(422);
    }

    #[Test]
    public function it_shows_the_three_action_modes_side_by_side(): void
    {
        $configs = auraDigArray((new EmployeeTable)->definition(), 'body', 'columnConfigs');

        expect($configs)
            // Convention: the browser generates it, so the server says nothing.
            ->not->toHaveKey('show_icon')
            // Escalated: a tooltip cannot be generated, so the whole entry is here.
            ->and(auraDig($configs, 'edit_icon', 'title'))->toBe('Edit this employee')
            // Gated: wrapped in a condition over a hidden per-row flag.
            ->and(auraDig($configs, 'destroy_icon', 'key'))->toBe('_allowed_destroy_icon');
    }

    #[Test]
    public function it_hides_the_delete_action_from_a_departed_employee(): void
    {
        $items = auraDigArray($this->postJson('/api/employees', [
            'page' => 1,
            'paginate' => 100,
            'filterable' => [['field' => 'status', 'values' => ['departed']]],
        ])->json(), 'items');

        $flags = array_column($items, '_allowed_destroy_icon');

        expect($flags)->not->toBeEmpty()
            // Every one of them false, and every one of them present: an absent
            // flag hides the cell too, and would not prove the gate ran.
            ->and($flags)->toHaveCount(count($items))
            ->and(array_filter($flags))->toBe([]);
    }
}
