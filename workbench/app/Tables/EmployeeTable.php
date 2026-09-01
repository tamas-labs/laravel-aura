<?php

declare(strict_types=1);

namespace Workbench\App\Tables;

use Illuminate\Database\Eloquent\Builder;
use TamasLabs\Aura\Cell\Badge;
use TamasLabs\Aura\Cell\Condition;
use TamasLabs\Aura\Cell\Progress;
use TamasLabs\Aura\Cell\Reference;
use TamasLabs\Aura\Table\Action;
use TamasLabs\Aura\Table\AuraTable;
use TamasLabs\Aura\Table\Column;
use TamasLabs\Aura\Table\TableSettings;
use Workbench\App\Enums\Status;
use Workbench\App\Models\Employee;

/**
 * The demo table — one of everything the package can do, in a browser.
 *
 * It exists to be looked at rather than asserted on, which is the one thing
 * the test suite cannot do: whether Aura's own preprocessor really generates
 * the four action links from a header field name, and whether an escalated
 * configuration renders the same as the generated one, is a question only a
 * running client answers.
 *
 * @extends AuraTable<Employee>
 */
final class EmployeeTable extends AuraTable
{
    /**
     * The escalated actions build on this. The convention-mode ones do not
     * need it — the browser has the base in its own `urlParameter`.
     */
    protected ?string $resource = 'employees';

    /**
     * @return Builder<Employee>
     */
    public function query(): Builder
    {
        return Employee::query()->with('company');
    }

    /**
     * @return list<Column>
     */
    public function columns(): array
    {
        return [
            // Aura reads the row id from this column's `field`, not its key —
            // which is why re-keying it leaves the action column free to take
            // `id` as its route placeholder.
            Column::selection()->key('select'),

            // No `globalSearch()` here: `searchableItems` names one field per
            // entry, and a combined column has none to give — the package
            // refuses it rather than emitting a name the query cannot use.
            Column::combined('name', ['first_name', 'last_name'], 'Name')
                ->sortable()
                ->searchable()
                ->reference('last_name'),

            Column::make('email')->searchable()->globalSearch(),

            // Sorted through the relation with a correlated subquery; searched
            // and filtered with `whereHas`.
            Column::make('company.name', 'Company')->sortable()->searchable()->globalSearch(),

            // `elements` for the filter dropdown comes from the enum cast; the
            // badge's colours come from the same enum's `variant()`.
            Column::make('status')->filterable()->as(Badge::fromEnum(Status::class)),

            // `currency` and the right-hand alignment come from the decimal
            // cast. The condition needs a number on both sides, so `salary` is
            // handed over as one — see `Response\NumericFields`.
            Column::make('salary')->sortable()->searchable()->as(
                Reference::make()->when(Condition::lt(50000), fn (Reference $r): Reference => $r->color('danger')),
            ),

            // `thresholds` is keyed by Bootstrap colour and valued with an
            // inclusive range — colour → [min, max], not a list of rules.
            Column::make('workload', 'Workload')->as(
                Progress::make()->max(100)->showPercent()->thresholds([
                    'success' => [0, 69],
                    'warning' => [70, 89],
                    'danger' => [90, 100],
                ]),
            ),

            // `datetime` and the range search come from the datetime cast.
            Column::make('hired_at', 'Hired')->sortable()->searchable(),

            // Convention mode on the left, escalation on the right: `show` has
            // no `columnConfigs` entry at all, `edit` has one because it was
            // given a tooltip, and `destroy` has one because it is gated.
            Column::actions(
                'id',
                Action::show(),
                Action::edit()->title('Edit this employee'),
                Action::destroy()->allowedWhen(
                    static fn (Employee $employee): bool => $employee->status !== Status::Departed,
                ),
            ),
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function settings(): TableSettings
    {
        return TableSettings::make()->stickyHeader()->striped()->hoverable();
    }
}
