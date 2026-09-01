<?php

declare(strict_types=1);

namespace TamasLabs\Aura\Console;

use Illuminate\Console\GeneratorCommand;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `php artisan make:aura-table UserTable --model=User`
 *
 * Writes a table class scaffolded from the model's own table: one column per
 * database column, with the flags its type and cast justify, plus the two
 * columns every table wants — the selection checkboxes and the resource
 * actions.
 *
 * It is a first draft on purpose. The generator declines to decide anything
 * editorial — which fields the toolbar search covers, which column renders as a
 * badge — and leaves a comment where it declined, rather than guessing and
 * being quietly wrong.
 */
#[AsCommand(name: 'make:aura-table')]
final class AuraTableMakeCommand extends GeneratorCommand
{
    /**
     * @var string
     */
    protected $name = 'make:aura-table';

    /**
     * @var string
     */
    protected $description = 'Create a new Aura table class from an Eloquent model';

    /**
     * @var string
     */
    protected $type = 'Aura table';

    /**
     * Resolved once in {@see self::handle()}, because a bad `--model` has to
     * stop the command rather than surface halfway through writing a file.
     *
     * @var class-string<Model>
     */
    private string $model;

    /** Did {@see self::handle()} decline to generate anything? */
    private bool $refused = false;

    /**
     * Refuse an unusable model before anything is written.
     */
    public function handle(): ?bool
    {
        $named = $this->namedModel();

        // `qualifyModel()` prefixes the application namespace unless the name
        // already starts with it, which turns a model from a package into
        // `App\Models\Vendor\Package\Thing`. A name that already resolves is
        // taken as it stands.
        $model = class_exists($named) ? ltrim($named, '\\') : $this->qualifyModel($named);

        if (! is_a($model, Model::class, true)) {
            $this->components->error("[{$model}] is not an Eloquent model. Name one with --model.");

            $this->refused = true;

            return false;
        }

        $this->model = $model;

        return parent::handle();
    }

    /**
     * Turn a refusal into a non-zero exit code.
     *
     * Laravel casts a command's return value with `(int)`, so the `false` every
     * generator answers with exits **0** — which is right for "the class is
     * already there" and wrong for "that model does not exist": a script
     * generating tables could not tell a typo from a written file. The mapping
     * lives here rather than in {@see self::handle()} because the parent's
     * return type is `bool|null` and cannot carry an exit code.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $code = parent::execute($input, $output);

        return $this->refused ? self::FAILURE : $code;
    }

    /**
     * The stub, from the application's own `stubs/` directory when it has one.
     */
    protected function getStub(): string
    {
        $published = $this->laravel->basePath('stubs/aura-table.stub');

        return is_file($published) ? $published : __DIR__.'/stubs/aura-table.stub';
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $rootNamespace
     */
    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\Tables';
    }

    /**
     * {@inheritDoc}
     *
     * @param  string  $name
     */
    protected function buildClass($name): string
    {
        $scaffold = ColumnScaffold::read(new ($this->model));

        $this->report($scaffold);

        return str_replace(
            ['{{ modelImport }}', '{{ modelBasename }}', '{{ columns }}'],
            ['use '.$this->model.";\n", class_basename($this->model), $scaffold->render()],
            parent::buildClass($name),
        );
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    protected function getOptions(): array
    {
        return [
            ['model', 'm', InputOption::VALUE_REQUIRED, 'The Eloquent model the table pages through'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite the class if it already exists'],
        ];
    }

    /**
     * The model the caller named, or the class name with `Table` cut off it —
     * the way `make:policy` guesses, and a table is almost always named after
     * the model it pages through.
     */
    private function namedModel(): string
    {
        $named = $this->option('model');

        if (is_string($named) && $named !== '') {
            return $named;
        }

        return preg_replace('/Table$/', '', class_basename((string) $this->argument('name'))) ?? '';
    }

    /**
     * Say what the generator could and could not read, because a table
     * scaffolded from nothing looks exactly like one scaffolded from a model
     * with no columns.
     */
    private function report(ColumnScaffold $scaffold): void
    {
        if ($scaffold->isEmpty()) {
            $this->components->warn(
                class_basename($this->model).'\'s table could not be read, so the columns are a '
                .'placeholder. Run the migrations and generate again, or write them by hand.'
            );

            return;
        }

        $this->components->info(sprintf(
            '%d column%s scaffolded from %s. Nothing is in the global search yet — see the class docblock.',
            $scaffold->count(),
            $scaffold->count() === 1 ? '' : 's',
            $this->model,
        ));
    }
}
