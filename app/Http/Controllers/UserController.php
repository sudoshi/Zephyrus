<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Audit\UserAuditRecorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules;
use Inertia\Inertia;

class UserController extends Controller
{
    public function __construct(private readonly UserAuditRecorder $audit) {}

    /**
     * Display a listing of the users.
     */
    public function index()
    {
        $users = User::select('id', 'name', 'email', 'username', 'role', 'is_active', 'created_at')
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Users/Index', [
            'users' => $users,
        ]);
    }

    /**
     * Show the form for creating a new user.
     */
    public function create()
    {
        return Inertia::render('Admin/Users/Create');
    }

    /**
     * Store a newly created user in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')],
            'username' => ['required', 'string', 'max:255', Rule::unique(User::class, 'username')],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'role' => 'required|string|in:user,admin,superuser',
            'is_active' => 'boolean',
        ]);

        DB::transaction(function () use ($request, $validated): void {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'username' => $validated['username'],
                'password' => Hash::make($validated['password']),
                'role' => $validated['role'],
                'is_active' => $request->boolean('is_active'),
            ]);

            $this->audit->record('administration.user.created', 'administration', 'success', [
                'request' => $request,
                'target_type' => 'user',
                'target_id' => $user->getKey(),
                'changes' => [
                    'role' => ['from' => null, 'to' => (string) $user->role],
                    'is_active' => ['from' => null, 'to' => (bool) $user->is_active],
                    'must_change_password' => ['from' => null, 'to' => (bool) $user->must_change_password],
                ],
                'metadata' => [
                    'changed_fields' => ['display_name', 'email_address', 'username', 'credentials', 'role', 'is_active'],
                ],
            ]);
        });

        return redirect()->route('users.index')
            ->with('message', 'User created successfully.');
    }

    /**
     * Show the form for editing the specified user.
     */
    public function edit(User $user)
    {
        return Inertia::render('Admin/Users/Edit', [
            'user' => $user->only(['id', 'name', 'email', 'username', 'role', 'is_active']),
        ]);
    }

    /**
     * Update the specified user in storage.
     */
    public function update(Request $request, User $user)
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class, 'email')->ignore($user)],
            'username' => ['required', 'string', 'max:255', Rule::unique(User::class, 'username')->ignore($user)],
            'role' => 'required|string|in:user,admin,superuser',
            'is_active' => 'boolean',
        ];

        // Only validate password if it's provided
        if ($request->filled('password')) {
            $rules['password'] = ['confirmed', Rules\Password::defaults()];
        }

        $validated = $request->validate($rules);

        // Update user data
        $userData = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'username' => $validated['username'],
            'role' => $validated['role'],
            'is_active' => $request->boolean('is_active'),
        ];

        // Only update password if it's provided
        if ($request->filled('password')) {
            $userData['password'] = Hash::make($request->password);
        }

        DB::transaction(function () use ($request, $user, $userData): void {
            $before = $user->only(['name', 'email', 'username', 'role', 'is_active', 'must_change_password']);
            $user->update($userData);

            $changedFields = [];
            $fieldLabels = [
                'name' => 'display_name',
                'email' => 'email_address',
                'username' => 'username',
                'role' => 'role',
                'is_active' => 'is_active',
            ];
            foreach ($fieldLabels as $field => $label) {
                if ($user->wasChanged($field)) {
                    $changedFields[] = $label;
                }
            }
            if ($user->wasChanged('password')) {
                $changedFields[] = 'credentials';
            }

            $changes = [];
            foreach (['role', 'is_active', 'must_change_password'] as $field) {
                if ($user->wasChanged($field)) {
                    $changes[$field] = [
                        'from' => $before[$field],
                        'to' => $user->{$field},
                    ];
                }
            }

            $this->audit->record('administration.user.updated', 'administration', 'success', [
                'request' => $request,
                'target_type' => 'user',
                'target_id' => $user->getKey(),
                'changes' => $changes,
                'metadata' => ['changed_fields' => $changedFields],
            ]);
        });

        return redirect()->route('users.index')
            ->with('message', 'User updated successfully.');
    }

    /**
     * Remove the specified user from storage.
     */
    public function destroy(Request $request, User $user)
    {
        DB::transaction(function () use ($request, $user): void {
            $targetId = $user->getKey();
            $before = $user->only(['role', 'is_active', 'must_change_password']);
            $user->delete();

            $this->audit->record('administration.user.deleted', 'administration', 'success', [
                'request' => $request,
                'target_type' => 'user',
                'target_id' => $targetId,
                'changes' => [
                    'role' => ['from' => $before['role'], 'to' => null],
                    'is_active' => ['from' => (bool) $before['is_active'], 'to' => null],
                    'must_change_password' => ['from' => (bool) $before['must_change_password'], 'to' => null],
                ],
                'metadata' => [
                    'changed_fields' => ['display_name', 'email_address', 'username', 'credentials', 'role', 'is_active'],
                ],
            ]);
        });

        return redirect()->route('users.index')
            ->with('message', 'User deleted successfully.');
    }
}
