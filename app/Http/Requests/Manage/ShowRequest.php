<?php

namespace App\Http\Requests\Manage;

use App\Models\Role;
use App\Models\Show;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ShowRequest extends FormRequest
{
    public const STATUSES = ['scheduled', 'live', 'ended', 'cancelled'];

    /**
     * Authorization runs through ShowPolicy in the controller.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $show = $this->route('show');

        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', Rule::unique('shows', 'slug')->ignore($show)],
            'source_id' => ['required', 'integer', Rule::exists('sources', 'id')],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')],
            'event_id' => ['nullable', 'integer', Rule::exists('events', 'id')],
            'description' => ['nullable', 'string'],

            'scheduled_start' => ['required', 'date'],
            'scheduled_end' => ['required', 'date', 'after:scheduled_start'],
            'actual_start' => ['nullable', 'date'],
            'actual_end' => ['nullable', 'date', 'after_or_equal:actual_start'],

            'auto_mode' => ['boolean'],
            'auto_stop_at' => ['nullable', 'date'],
            'publish_plan' => ['required', Rule::in(Show::PUBLISH_PLANS)],

            'visibility' => ['required', Rule::in(['public', 'private'])],
            // Only read when visibility is private; required then, because a private show
            // nobody can watch is a mistake, not a configuration.
            'required_roles' => ['array', 'required_if:visibility,private'],
            'required_roles.*' => [Rule::exists('roles', 'slug')],
        ];
    }

    /**
     * The payload to persist.
     *
     * `status` is deliberately absent: it only moves through Go Live, End Stream and
     * Cancel, each of which does more than write a column (timestamps, viewer
     * notification). A form can neither set nor clear it.
     *
     * @return array<string, mixed>
     */
    public function showData(?Show $show = null): array
    {
        $data = $this->validated();

        // The slug is the public URL of a stream people are watching right now.
        if ($show?->status === 'live') {
            $data['slug'] = $show->slug;
        }

        $data['auto_mode'] = (bool) ($data['auto_mode'] ?? false);

        // Public is stored as an empty role list, which is what canBeAccessedBy() reads.
        $data['required_roles'] = $data['visibility'] === 'private'
            ? array_values($data['required_roles'] ?? [])
            : [];

        unset($data['visibility']);

        // The hard stop only means something in auto mode, and defaults to the scheduled
        // end so the safe behaviour needs no thought. See docs/admin/auto-mode.md.
        if (! $data['auto_mode']) {
            $data['auto_stop_at'] = null;
        } elseif (empty($data['auto_stop_at'])) {
            $data['auto_stop_at'] = $data['scheduled_end'];
        }

        return $data;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'scheduled_end.after' => 'The scheduled end must be later than the scheduled start.',
            'required_roles.*.exists' => 'One of the selected roles no longer exists.',
        ];
    }

    /**
     * Role slugs, for the access-restriction checkbox list.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function roleOptions(): array
    {
        return Role::orderByDesc('priority')
            ->get(['name', 'slug'])
            ->map(fn (Role $role) => ['value' => $role->slug, 'label' => $role->name])
            ->all();
    }
}
