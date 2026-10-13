<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    public function index(Request $request): View
    {
        $users = User::with('roles')
            ->when($request->string('q')->trim()->value(), fn ($q, $term) => $q->where(
                fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%")
            ))
            ->orderBy('name')->paginate(25)->withQueryString();

        return view('admin.users.index', ['users' => $users, 'programs' => Program::pluck('code', 'id')]);
    }

    public function create(): View
    {
        return view('admin.users.form', ['user' => new User(['is_active' => true]), 'programs' => Program::orderBy('code')->get(), 'assigned' => []]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'is_active' => $data['is_active'],
            ]);
            $this->syncRoles($user, $data);
            AuditLog::record('user.created', $user, [], ['email' => $user->email, 'roles' => $data['roles'] ?? []]);

            return $user;
        });

        return redirect()->route('admin.users.index')->with('status', "Account created for {$user->name}.");
    }

    public function edit(User $user): View
    {
        $assigned = $user->roles->map(fn ($r) => ['slug' => $r->slug, 'program_id' => $r->pivot->program_id])->all();

        return view('admin.users.form', ['user' => $user, 'programs' => Program::orderBy('code')->get(), 'assigned' => $assigned]);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $this->validated($request, $user);

        if ($user->is($request->user()) && (! $data['is_active'] || ! in_array(Role::ADMIN, $data['roles'] ?? [], true))) {
            return back()->withErrors(['roles' => 'You cannot remove your own administrator role or deactivate your own account.']);
        }

        DB::transaction(function () use ($user, $data) {
            $old = ['roles' => $user->roles->pluck('slug')->all(), 'is_active' => $user->is_active];
            $user->fill(collect($data)->only(['name', 'email', 'is_active'])->all());
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->save();
            $this->syncRoles($user, $data);
            if (! $user->is_active) {
                $user->tokens()->delete();
            }
            AuditLog::record('user.updated', $user, $old, ['roles' => $data['roles'] ?? [], 'is_active' => $user->is_active]);
        });

        return redirect()->route('admin.users.index')->with('status', "{$user->name} updated.");
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'confirmed', Password::defaults()],
            'is_active' => ['boolean'],
            'roles' => ['array'],
            'roles.*' => [Rule::in(array_keys(Role::NAMES))],
            'chair_program_ids' => ['array', Rule::requiredIf(in_array(Role::PROGRAM_CHAIR, $request->input('roles', []), true))],
            'chair_program_ids.*' => ['integer', 'exists:programs,id'],
        ], ['chair_program_ids.required' => 'Select the program(s) this program chair oversees.']);
        $data['is_active'] = $request->boolean('is_active');

        return $data;
    }

    /** Global roles are stored without a program; program-chair roles once per chaired program. */
    private function syncRoles(User $user, array $data): void
    {
        $roleIds = Role::pluck('id', 'slug');
        DB::table('role_user')->where('user_id', $user->id)->delete();

        foreach (array_unique($data['roles'] ?? []) as $slug) {
            $programIds = $slug === Role::PROGRAM_CHAIR ? array_unique($data['chair_program_ids'] ?? []) : [null];
            foreach ($programIds as $programId) {
                $user->roles()->attach($roleIds[$slug], ['program_id' => $programId]);
            }
        }
        $user->forgetRoleCache();
    }
}
