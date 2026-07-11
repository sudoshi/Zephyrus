<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Audit\UserEvent;
use App\Services\Audit\UserAuditPresenter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class UserAuditController extends Controller
{
    public function __construct(private readonly UserAuditPresenter $presenter) {}

    public function index(Request $request): Response
    {
        $filters = validator($request->query(), [
            'search' => ['nullable', 'string', 'max:120'],
            'action' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:40'],
            'outcome' => ['nullable', 'string', 'max:40'],
            'auth_method' => ['nullable', 'string', 'max:40'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        $query = UserEvent::query()->with('actor:id,name,username,email,role');
        $this->applyFilters($query, $filters);

        $today = now()->startOfDay();
        $loginEvents = UserEvent::query()
            ->whereIn('action', ['auth.login', 'mobile.auth.token_exchange'])
            ->where('occurred_at', '>=', $today);

        $stats = [
            'totalEvents' => (clone $query)->count(),
            'loginsToday' => (clone $loginEvents)->where('outcome', 'success')->count(),
            'failedLoginsToday' => (clone $loginEvents)->whereIn('outcome', ['failure', 'denied'])->count(),
            'activeUsers7d' => UserEvent::query()
                ->whereNotNull('actor_user_id')
                ->where('outcome', 'success')
                ->where('occurred_at', '>=', now()->subDays(7))
                ->distinct()
                ->count('actor_user_id'),
        ];

        $paginator = $query
            ->orderByDesc('event_cursor')
            ->paginate((int) ($filters['per_page'] ?? 25))
            ->withQueryString();

        $records = collect($paginator->items())
            ->map(fn (UserEvent $event): array => $this->presenter->present($event))
            ->all();

        $events = $paginator->toArray();
        $events['data'] = $records;

        return Inertia::render('Admin/UserAudit', [
            'events' => $events,
            'stats' => $stats,
            'filters' => [
                'search' => $filters['search'] ?? '',
                'action' => $filters['action'] ?? '',
                'category' => $filters['category'] ?? '',
                'outcome' => $filters['outcome'] ?? '',
                'auth_method' => $filters['auth_method'] ?? '',
                'date_from' => $filters['date_from'] ?? '',
                'date_to' => $filters['date_to'] ?? '',
                'per_page' => (int) ($filters['per_page'] ?? 25),
            ],
            'options' => [
                'categories' => $this->distinctOptions('category'),
                'outcomes' => $this->distinctOptions('outcome'),
                'authMethods' => $this->distinctOptions('auth_method'),
                'actions' => $this->distinctOptions('action'),
            ],
        ]);
    }

    /**
     * @param  Builder<UserEvent>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (isset($filters['search']) && trim((string) $filters['search']) !== '') {
            $needle = '%'.Str::lower(str_replace(['%', '_'], '', trim((string) $filters['search']))).'%';
            $query->where(function (Builder $search) use ($needle): void {
                $search->whereRaw('lower(action) like ?', [$needle])
                    ->orWhereRaw("lower(coalesce(route_name, '')) like ?", [$needle])
                    ->orWhereRaw("lower(coalesce(uri_template, '')) like ?", [$needle])
                    ->orWhereRaw("lower(coalesce(client_ip::text, '')) like ?", [$needle])
                    ->orWhereHas('actor', function (Builder $actor) use ($needle): void {
                        $actor->where(function (Builder $identity) use ($needle): void {
                            $identity->whereRaw('lower(name) like ?', [$needle])
                                ->orWhereRaw('lower(username) like ?', [$needle])
                                ->orWhereRaw('lower(email) like ?', [$needle]);
                        });
                    });
            });
        }

        foreach (['action', 'category', 'outcome', 'auth_method'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }

        if (isset($filters['date_from'])) {
            $query->where('occurred_at', '>=', CarbonImmutable::createFromFormat('Y-m-d', $filters['date_from'])->startOfDay());
        }
        if (isset($filters['date_to'])) {
            $query->where('occurred_at', '<=', CarbonImmutable::createFromFormat('Y-m-d', $filters['date_to'])->endOfDay());
        }
    }

    /** @return list<string> */
    private function distinctOptions(string $column): array
    {
        return UserEvent::query()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();
    }
}
