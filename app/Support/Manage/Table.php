<?php

namespace App\Support\Manage;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Builds the list-page envelope every manage index sends to the client:
 * rows, columns, filters, sort, search, meta, and the three action sets.
 *
 * All seven modules share this so sorting, searching, filtering, pagination and
 * column-visibility behave identically and are worth testing only once.
 *
 * Request contract: ?search=&sort=&dir=&page=&per_page=&filter[key]=
 */
final class Table
{
    private string $name = 'table';

    /** @var array<int, Column> */
    private array $columns = [];

    /** @var array<int, Filter> */
    private array $filters = [];

    private ?string $defaultSortKey = null;

    private string $defaultSortDir = 'asc';

    private ?Closure $rowsUsing = null;

    private ?Closure $recordUrlUsing = null;

    private ?Closure $rowActionsUsing = null;

    /** @var array<int, Action> */
    private array $bulkActions = [];

    /** @var array<int, Action> */
    private array $pageActions = [];

    private int $perPage = 25;

    /** @var array<int, int> */
    private array $perPageOptions = [10, 25, 50, 100];

    private function __construct(private readonly Builder $query) {}

    public static function make(Builder $query): self
    {
        return new self($query);
    }

    /**
     * Identifies the table for per-user column-visibility persistence.
     */
    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @param  array<int, Column>  $columns
     */
    public function columns(array $columns): self
    {
        $this->columns = $columns;

        return $this;
    }

    /**
     * @param  array<int, Filter>  $filters
     */
    public function filters(array $filters): self
    {
        $this->filters = $filters;

        return $this;
    }

    public function defaultSort(string $key, string $dir = 'asc'): self
    {
        $this->defaultSortKey = $key;
        $this->defaultSortDir = $dir;

        return $this;
    }

    /**
     * Maps a record to its cell values, keyed by column key.
     *
     * @param  Closure(Model): array<string, mixed>  $callback
     */
    public function rows(Closure $callback): self
    {
        $this->rowsUsing = $callback;

        return $this;
    }

    /**
     * Where clicking the row navigates.
     *
     * @param  Closure(Model): ?string  $callback
     */
    public function recordUrl(Closure $callback): self
    {
        $this->recordUrlUsing = $callback;

        return $this;
    }

    /**
     * @param  Closure(Model): array<int, Action>  $callback
     */
    public function rowActions(Closure $callback): self
    {
        $this->rowActionsUsing = $callback;

        return $this;
    }

    /**
     * @param  array<int, Action>  $actions
     */
    public function bulkActions(array $actions): self
    {
        $this->bulkActions = $actions;

        return $this;
    }

    /**
     * @param  array<int, Action>  $actions
     */
    public function pageActions(array $actions): self
    {
        $this->pageActions = $actions;

        return $this;
    }

    public function perPage(int $perPage): self
    {
        $this->perPage = $perPage;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $search = trim((string) $request->input('search', ''));
        $filterValues = $this->resolveFilterValues($request);

        $this->applyFilters($filterValues);
        $this->applySearch($search);
        $sort = $this->applySort($request);

        $perPage = $this->resolvePerPage($request);
        $paginator = $this->query->paginate($perPage)->withQueryString();

        return [
            'name' => $this->name,
            'rows' => collect($paginator->items())->map(fn (Model $record) => [
                'id' => $record->getKey(),
                'url' => $this->recordUrlUsing ? ($this->recordUrlUsing)($record) : null,
                'cells' => $this->rowsUsing ? ($this->rowsUsing)($record) : $record->attributesToArray(),
                'actions' => $this->rowActionsUsing
                    ? array_map(fn (Action $action) => $action->toArray(), array_values(($this->rowActionsUsing)($record)))
                    : [],
            ])->all(),
            'columns' => array_map(fn (Column $column) => $column->toArray(), $this->columns),
            'hiddenColumns' => $this->hiddenColumns(),
            'filters' => array_map(
                fn (Filter $filter) => $filter->toArray() + ['value' => $filterValues[$filter->key]],
                $this->filters,
            ),
            'sort' => $sort,
            'search' => $search,
            'meta' => [
                'page' => $paginator->currentPage(),
                'perPage' => $paginator->perPage(),
                'perPageOptions' => $this->perPageOptions,
                'total' => $paginator->total(),
                'lastPage' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'bulkActions' => array_map(fn (Action $action) => $action->toArray(), $this->bulkActions),
            'pageActions' => array_map(fn (Action $action) => $action->toArray(), $this->pageActions),
        ];
    }

    /**
     * Column keys the current user has hidden, defaulting to the declared hidden set.
     *
     * @return array<int, string>
     */
    private function hiddenColumns(): array
    {
        $stored = session("manage.table.{$this->name}.hidden");

        if (is_array($stored)) {
            return array_values($stored);
        }

        return collect($this->columns)
            ->filter(fn (Column $column) => $column->isHiddenByDefault())
            ->map(fn (Column $column) => $column->key)
            ->values()
            ->all();
    }

    /**
     * A filter absent from the request falls back to its declared default, which is how
     * "hide ended shows" stays on until the operator explicitly turns it off.
     *
     * @return array<string, mixed>
     */
    private function resolveFilterValues(Request $request): array
    {
        $values = [];

        foreach ($this->filters as $filter) {
            $raw = $request->input("filter.{$filter->key}");

            $values[$filter->key] = $raw === null
                ? $filter->defaultValue()
                : $filter->normalize($raw);
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function applyFilters(array $values): void
    {
        foreach ($this->filters as $filter) {
            $value = $values[$filter->key];

            if ($filter->isActive($value)) {
                $filter->applyTo($this->query, $value);
            }
        }
    }

    private function applySearch(string $search): void
    {
        if ($search === '') {
            return;
        }

        $searchable = array_filter($this->columns, fn (Column $column) => $column->isSearchable());

        if ($searchable === []) {
            return;
        }

        $operator = $this->query->getConnection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';
        $term = '%'.$search.'%';

        $this->query->where(function (Builder $query) use ($searchable, $operator, $term) {
            foreach ($searchable as $column) {
                $key = $column->resolvedSearchKey();

                if (str_contains($key, '.')) {
                    [$relation, $attribute] = explode('.', $key, 2);
                    $query->orWhereHas($relation, fn (Builder $q) => $q->where($attribute, $operator, $term));

                    continue;
                }

                $query->orWhere($query->qualifyColumn($key), $operator, $term);
            }
        });
    }

    /**
     * @return array{key: string|null, dir: string}
     */
    private function applySort(Request $request): array
    {
        $requestedKey = $request->input('sort');
        $dir = strtolower((string) $request->input('dir', $this->defaultSortDir)) === 'desc' ? 'desc' : 'asc';

        $column = collect($this->columns)->first(
            fn (Column $column) => $column->isSortable() && $column->key === $requestedKey
        );

        if (! $column) {
            if ($this->defaultSortKey) {
                $this->query->orderBy($this->defaultSortKey, $this->defaultSortDir);
            }

            return ['key' => $this->defaultSortKey, 'dir' => $this->defaultSortDir];
        }

        if ($callback = $column->sortCallback()) {
            $callback($this->query, $dir);
        } else {
            $this->query->orderBy($column->resolvedSortKey(), $dir);
        }

        return ['key' => $column->key, 'dir' => $dir];
    }

    private function resolvePerPage(Request $request): int
    {
        $requested = (int) $request->input('per_page', $this->perPage);

        return in_array($requested, $this->perPageOptions, true) ? $requested : $this->perPage;
    }
}
