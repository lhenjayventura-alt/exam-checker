<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold text-xl text-gray-800 leading-tight">Users and roles</h2>
            <a href="{{ route('admin.users.create') }}" class="inline-flex items-center rounded-md bg-gray-800 px-3 py-2 text-xs font-semibold uppercase tracking-widest text-white hover:bg-gray-700">New user</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <x-flash />
            <x-panel>
                <form method="GET" class="mb-4 flex max-w-md gap-2">
                    <x-text-input name="q" :value="request('q')" placeholder="Search name or email" class="block w-full" />
                    <x-secondary-button type="submit">Search</x-secondary-button>
                </form>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs uppercase text-gray-500">
                            <tr><th class="py-2 pe-4">Name</th><th class="pe-4">Email</th><th class="pe-4">Roles</th><th class="pe-4">Status</th><th></th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @forelse ($users as $user)
                                <tr>
                                    <td class="py-2 pe-4">{{ $user->name }}</td>
                                    <td class="pe-4">{{ $user->email }}</td>
                                    <td class="pe-4">
                                        @forelse ($user->roles as $role)
                                            <span class="me-1 inline-block rounded bg-gray-100 px-2 py-0.5 text-xs">{{ $role->name }}{{ $role->pivot->program_id ? ' · '.($programs[$role->pivot->program_id] ?? '') : '' }}</span>
                                        @empty
                                            <span class="text-xs text-gray-400">None</span>
                                        @endforelse
                                    </td>
                                    <td class="pe-4">{{ $user->is_active ? 'Active' : 'Deactivated' }}</td>
                                    <td class="text-right"><a href="{{ route('admin.users.edit', $user) }}" class="text-indigo-700 hover:underline">Edit</a></td>
                                </tr>
                            @empty
                                <tr><td colspan="5" class="py-4 text-gray-500">No users found.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $users->links() }}</div>
            </x-panel>
        </div>
    </div>
</x-app-layout>
