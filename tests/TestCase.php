<?php

namespace Atannex\Ecosystem\Tests;

use Atannex\Ecosystem\EcosystemServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [EcosystemServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        // SQLite in memory by default; ECOSYSTEM_TEST_DB=mysql (with DB_HOST,
        // DB_DATABASE, DB_USERNAME, DB_PASSWORD) runs the suite on MySQL.
        $app['config']->set('database.default', env('ECOSYSTEM_TEST_DB', 'testing'));
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema();
    }

    private function createSchema(): void
    {
        // Persistent databases (MySQL runs) keep tables between tests.
        foreach (['comments', 'post_tag', 'tags', 'posts', 'users', 'regions'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('regions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('parent_id')->nullable();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('password')->nullable();
            $table->string('api_token')->nullable();
            $table->foreignId('region_id')->nullable();
            $table->unsignedInteger('age')->nullable();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('code')->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->timestamp('published_at')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->softDeletes();
        });

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
        });

        Schema::create('post_tag', function (Blueprint $table) {
            $table->foreignId('post_id');
            $table->foreignId('tag_id');
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id');
            $table->text('body');
            $table->softDeletes();
        });
    }
}
