<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Workbench\App\Enums\Status;
use Workbench\App\Models\Company;
use Workbench\App\Models\Employee;

/**
 * Enough rows to page through, and enough variety to see every renderer fire.
 *
 * Deterministic rather than faked: the demo is also how a rendering question
 * gets answered ("does the badge really come out `warning` here?"), and an
 * answer that changes on reseed is not an answer.
 */
final class DatabaseSeeder extends Seeder
{
    /** Two companies, so the relation column has something to sort by. */
    private const COMPANIES = [
        ['name' => 'Northwind Trading', 'city' => 'Budapest'],
        ['name' => 'Umbra Analytics', 'city' => 'Debrecen'],
    ];

    /** first, last, status, salary, workload */
    private const PEOPLE = [
        ['Ada', 'Lovelace', Status::Active, 92000, 78],
        ['Alan', 'Turing', Status::Active, 88500, 95],
        ['Grace', 'Hopper', Status::OnLeave, 76400, 40],
        ['Katherine', 'Johnson', Status::Active, 81200, 62],
        ['Edsger', 'Dijkstra', Status::Departed, 47500, 0],
        ['Barbara', 'Liskov', Status::Active, 99000, 85],
        ['Donald', 'Knuth', Status::OnLeave, 64300, 25],
        ['Margaret', 'Hamilton', Status::Active, 87100, 71],
        ['Tony', 'Hoare', Status::Departed, 39800, 0],
        ['Frances', 'Allen', Status::Active, 73600, 55],
        ['Leslie', 'Lamport', Status::Active, 91500, 90],
        ['Radia', 'Perlman', Status::OnLeave, 68900, 33],
    ];

    public function run(): void
    {
        $companies = [];

        foreach (self::COMPANIES as $company) {
            $companies[] = Company::create($company);
        }

        $hired = Carbon::parse('2019-03-04 09:00:00');

        foreach (self::PEOPLE as $index => [$first, $last, $status, $salary, $workload]) {
            Employee::create([
                'company_id' => $companies[$index % count($companies)]->id,
                'first_name' => $first,
                'last_name' => $last,
                'email' => strtolower($first.'.'.$last).'@example.test',
                'status' => $status,
                'salary' => $salary,
                'workload' => $workload,
                // Spread over four years so the range search on `hired_at` has
                // something to bracket.
                'hired_at' => $hired->copy()->addMonths($index * 4),
            ]);
        }
    }
}
