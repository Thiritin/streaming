<?php

namespace Tests\Unit\Support;

use App\Models\Recording;
use App\Support\Search;
use Tests\TestCase;

/**
 * The operator, per engine.
 *
 * The suite runs on SQLite, which folds ASCII case in LIKE all by itself, so a
 * behavioural test of "furs finds Fursuit parade" passes here whether or not the
 * operator is right and passed for as long as the bug lived. This asserts the SQL
 * instead: it is the only way to see, from a SQLite run, that Postgres is being
 * handed `ilike` and MySQL is not.
 */
class SearchTest extends TestCase
{
    public function test_postgres_gets_ilike_and_the_others_get_like(): void
    {
        $this->assertSame('ilike', Search::operator(Recording::on('pgsql')->newQuery()));
        $this->assertSame('like', Search::operator(Recording::on('mysql')->newQuery()));
        $this->assertSame('like', Search::operator(Recording::on('sqlite')->newQuery()));
    }

    public function test_the_operator_reaches_the_generated_sql(): void
    {
        $postgres = Search::any(Recording::on('pgsql')->newQuery(), ['title', 'description'], 'furs')->toSql();

        $this->assertStringContainsString('ilike', $postgres);
        $this->assertStringNotContainsString(' like ', $postgres);

        $sqlite = Search::any(Recording::on('sqlite')->newQuery(), ['title', 'description'], 'furs')->toSql();

        $this->assertStringContainsString('like', $sqlite);
        $this->assertStringNotContainsString('ilike', $sqlite);
    }

    public function test_a_relation_column_is_searched_through_the_relation(): void
    {
        $sql = Search::any(Recording::on('pgsql')->newQuery(), ['title', 'source.name'], 'stage')->toSql();

        $this->assertStringContainsString('exists', $sql);
        $this->assertSame(2, substr_count($sql, 'ilike'));
    }

    public function test_an_empty_term_narrows_nothing(): void
    {
        $bare = Recording::on('sqlite')->newQuery()->toSql();

        $this->assertSame($bare, Search::any(Recording::on('sqlite')->newQuery(), ['title'], '')->toSql());
        $this->assertSame($bare, Search::any(Recording::on('sqlite')->newQuery(), ['title'], '   ')->toSql());
        $this->assertSame($bare, Search::any(Recording::on('sqlite')->newQuery(), ['title'], null)->toSql());
    }
}
