<?php

namespace App\Http\Controllers\Manage;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Support\Manage\Action;
use App\Support\Manage\Column;
use App\Support\Manage\Settings;
use App\Support\Manage\Status;
use App\Support\Manage\Table;
use App\Support\Manage\Toast;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Response;

/**
 * What kinds of thing the programme is made of - dances, theatre pieces, musical
 * performances - and nothing more. A category gates nothing: it is a label the
 * archive groups and filters on.
 */
class CategoryController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Category::class);

        $table = Table::make(Category::query()->withCount(['shows', 'recordings']))
            ->name('categories')
            ->columns([
                Column::text('name', 'Name')->searchable('name')->sortable(),
                Column::copyable('slug', 'Slug')->searchable('slug'),
                Column::number('sort_order', 'Order')->sortable(),
                Column::number('shows_count', 'Shows'),
                Column::number('recordings_count', 'Recordings'),
            ])
            ->defaultSort('sort_order', 'asc')
            ->rows(fn (Category $category) => [
                'name' => $category->name,
                'slug' => $category->slug,
                'sort_order' => $category->sort_order,
                'shows_count' => $category->shows_count,
                'recordings_count' => $category->recordings_count,
            ])
            ->recordUrl(fn (Category $category) => route('manage.categories.edit', $category))
            ->rowActions(fn (Category $category) => $this->rowActions($category))
            ->pageActions($this->pageActions());

        return inertia('Manage/Categories/Index', [
            'table' => $table->toArray($request),
            'navigation' => app(Settings::class)->navigation(),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Category::class);

        return inertia('Manage/Categories/Form', [
            'navigation' => app(Settings::class)->navigation(),
            'category' => null,
            'defaults' => [
                'name' => '',
                'slug' => '',
                'sort_order' => (int) Category::max('sort_order') + 1,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Category::class);

        $category = Category::create($this->validated($request));

        Toast::flashSuccess('Category created', "{$category->name} can now be set on a show.");

        return to_route('manage.categories.index');
    }

    public function edit(Category $category): Response
    {
        $this->authorize('view', $category);

        $category->loadCount(['shows', 'recordings']);

        return inertia('Manage/Categories/Form', [
            'navigation' => app(Settings::class)->navigation(),
            'category' => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'sort_order' => $category->sort_order,
                'shows_count' => $category->shows_count,
                'recordings_count' => $category->recordings_count,
            ],
            'actions' => array_map(
                fn (Action $action) => $action->toArray(),
                $this->rowActions($category),
            ),
        ]);
    }

    public function update(Request $request, Category $category): RedirectResponse
    {
        $this->authorize('update', $category);

        $category->update($this->validated($request, $category));

        Toast::flashSuccess('Category updated');

        return back();
    }

    /**
     * Deleting frees every show and recording that carried it: the column is
     * nullable and nothing reads it for access, so nothing goes dark.
     */
    public function destroy(Category $category): RedirectResponse
    {
        $this->authorize('delete', $category);

        $name = $category->name;
        $category->delete();

        Toast::flashSuccess('Category deleted', "Shows that were {$name} now have no category.");

        return to_route('manage.categories.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?Category $category = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'slug' => [
                'nullable',
                'string',
                'max:60',
                'regex:/^[a-z0-9-]+$/',
                Rule::unique('categories', 'slug')->ignore($category?->id),
            ],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ]);
    }

    /**
     * @return array<int, Action>
     */
    private function rowActions(Category $category): array
    {
        $actions = [
            Action::link('edit', 'Edit', route('manage.categories.edit', $category))->icon('pencil'),
        ];

        if (request()->user()->can('delete', $category)) {
            $actions[] = Action::delete('delete', 'Delete', route('manage.categories.destroy', $category))
                ->icon('trash-2')
                ->tone(Status::DANGER)
                ->confirm(
                    'Delete category',
                    "Shows and recordings labelled {$category->name} keep everything else and lose the label.",
                    'Delete',
                );
        }

        return $actions;
    }

    /**
     * @return array<int, Action>
     */
    private function pageActions(): array
    {
        if (! request()->user()->can('create', Category::class)) {
            return [];
        }

        return [
            Action::link('create', 'New Category', route('manage.categories.create'))->icon('plus'),
        ];
    }
}
