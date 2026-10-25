<?php

declare(strict_types=1);

namespace Atannex\Ecosystem\Console;

use Atannex\Ecosystem\Search\SearchHit;
use Atannex\Ecosystem\Search\SearchManager;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Try a search from the terminal, e.g. to tune field weights.
 */
class SearchCommand extends Command
{
    protected $signature = 'ecosystem:search
        {query : What to search for, with the same syntax as visitors use}
        {--model=* : Searchable model classes (several for a global search)}
        {--limit=10 : Results per model}
        {--sql : Print the SQL of each model\'s search}';

    protected $description = 'Run a search and show the ranked results';

    public function handle(SearchManager $search): int
    {
        $models = (array) $this->option('model');

        if ($models === []) {
            $this->components->error('Give at least one --model.');

            return self::FAILURE;
        }

        $parsed = $search->parse((string) $this->argument('query'));

        $this->components->twoColumnDetail('Terms', implode(', ', array_map(fn ($t) => ($t->negated ? '-' : '').$t->raw(), $parsed->terms)) ?: '(none)');

        if ($this->option('sql')) {
            foreach ($models as $class) {
                $this->line($class::query()->search($parsed)->toRawSql());
                $this->newLine();
            }
        }

        $hits = $search->across($models)->query($parsed)->perType((int) $this->option('limit'))->get();

        if ($hits->isEmpty()) {
            $this->components->warn('No results.');

            return self::SUCCESS;
        }

        $this->table(
            ['Score', 'Type', 'ID', 'Title'],
            $hits->map(fn (SearchHit $hit) => [
                round($hit->score, 2),
                $hit->type,
                $hit->model instanceof Model ? $hit->model->getKey() : '',
                mb_strimwidth($hit->title, 0, 70, '…'),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
