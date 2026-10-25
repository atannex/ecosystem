<?php

declare(strict_types=1);

namespace Atannex\Ecosystem;

use Atannex\Ecosystem\Console\SearchCommand;
use Atannex\Ecosystem\Search\Highlighter;
use Atannex\Ecosystem\Search\QueryParser;
use Atannex\Ecosystem\Search\SearchEngine;
use Atannex\Ecosystem\Search\SearchManager;
use Illuminate\Support\ServiceProvider;

class EcosystemServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ecosystem.php', 'ecosystem');

        $this->app->singleton(QueryParser::class, fn ($app) => new QueryParser(
            (int) $app['config']->get('ecosystem.search.max_query_length', 200),
            (int) $app['config']->get('ecosystem.search.max_terms', 8),
            (int) $app['config']->get('ecosystem.search.min_term_length', 2),
            (array) $app['config']->get('ecosystem.search.stopwords', []),
        ));

        $this->app->singleton(SearchEngine::class, fn ($app) => new SearchEngine(
            $app->make(QueryParser::class),
            (array) $app['config']->get('ecosystem.search.scores', []),
            (bool) $app['config']->get('ecosystem.search.translatable', true),
        ));

        $this->app->singleton(Highlighter::class, fn ($app) => new Highlighter(
            (string) $app['config']->get('ecosystem.search.highlight_tag', 'mark'),
            $app['config']->get('ecosystem.search.highlight_class'),
        ));

        $this->app->singleton(SearchManager::class, fn ($app) => new SearchManager(
            $app->make(SearchEngine::class),
            $app->make(Highlighter::class),
            (int) $app['config']->get('ecosystem.search.per_type', 5),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/ecosystem.php' => config_path('ecosystem.php')], 'ecosystem-config');

            $this->commands([SearchCommand::class]);
        }
    }
}
