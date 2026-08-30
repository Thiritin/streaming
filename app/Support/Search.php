<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Free-text search across a few columns, case-insensitively on every engine.
 *
 * The operator is the whole point. SQLite folds ASCII case in LIKE and MySQL's
 * default collation folds it too, so a bare `like` looks right in the suite and
 * right in production and silently misses "Fursuit parade" for "furs" on
 * Postgres, which is what everyone develops against. Postgres gets `ilike`;
 * nothing else may, since MySQL and SQLite have no such operator. That decision
 * lives here and nowhere else - it was wrong in three call sites at once.
 */
final class Search
{
    /**
     * The case-insensitive containment operator for this query's connection.
     */
    public static function operator(Builder $query): string
    {
        return $query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
    }

    /**
     * Narrow to rows where any of the columns contains the term. A column may
     * name a relation's attribute as `relation.attribute`.
     *
     * @param  array<int, string>  $columns
     */
    public static function any(Builder $query, array $columns, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '' || $columns === []) {
            return $query;
        }

        $operator = self::operator($query);
        $pattern = '%'.$term.'%';

        return $query->where(function (Builder $query) use ($columns, $operator, $pattern) {
            foreach ($columns as $column) {
                if (str_contains($column, '.')) {
                    [$relation, $attribute] = explode('.', $column, 2);
                    $query->orWhereHas($relation, fn (Builder $related) => $related->where($attribute, $operator, $pattern));

                    continue;
                }

                $query->orWhere($query->qualifyColumn($column), $operator, $pattern);
            }
        });
    }
}
