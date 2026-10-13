@php
    $assignedSlugs = collect($assigned)->pluck('slug')->unique()->all();
    $chairPrograms = collect($assigned)->where('slug', \App\Models\Role::PROGRAM_CHAIR)->pluck('program_id')->all();
    $selectedRoles = old('roles', $assignedSlugs);
    $selectedPrograms = array_map('intval', old('chair_program_ids', $chairPrograms));
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">{{ $user->exists ? 'Edit '.$user->name : 'New user' }}</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
            <x-flash />
            <x-panel>
                <form method="POST" action="{{ $user->exists ? route('admin.users.update', $user) : route('admin.users.store') }}" class="space-y-4"
                      x-data="{ chair: @js(in_array(\App\Models\Role::PROGRAM_CHAIR, $selectedRoles, true)) }">
                    @csrf
                    @if ($user->exists) @method('PUT') @endif

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <x-input-label for="name" value="Name" />
                            <x-text-input id="name" name="name" class="mt-1 block w-full" :value="old('name', $user->name)" required />
                        </div>
                        <div>
                            <x-input-label for="email" value="Email" />
                            <x-text-input id="email" name="email" type="email" class="mt-1 block w-full" :value="old('email', $user->email)" required />
                        </div>
                        <div>
                            <x-input-label for="password" :value="$user->exists ? 'New password (leave blank to keep)' : 'Password'" />
                            <x-text-input id="password" name="password" type="password" class="mt-1 block w-full" autocomplete="new-password" :required="! $user->exists" />
                        </div>
                        <div>
                            <x-input-label for="password_confirmation" value="Confirm password" />
                            <x-text-input id="password_confirmation" name="password_confirmation" type="password" class="mt-1 block w-full" autocomplete="new-password" />
                        </div>
                    </div>

                    <fieldset>
                        <legend class="text-sm font-medium text-gray-700">Roles</legend>
                        <div class="mt-2 flex flex-wrap gap-6 text-sm">
                            @foreach (\App\Models\Role::NAMES as $slug => $name)
                                <label class="inline-flex items-center gap-2">
                                    <input type="checkbox" name="roles[]" value="{{ $slug }}" @checked(in_array($slug, $selectedRoles, true))
                                           class="rounded border-gray-300 text-indigo-600"
                                           @if ($slug === \App\Models\Role::PROGRAM_CHAIR) @change="chair = $event.target.checked" @endif>
                                    {{ $name }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>

                    <fieldset x-show="chair" x-cloak>
                        <legend class="text-sm font-medium text-gray-700">Programs overseen as program chair</legend>
                        <div class="mt-2 flex flex-wrap gap-6 text-sm">
                            @forelse ($programs as $program)
                                <label class="inline-flex items-center gap-2">
                                    <input type="checkbox" name="chair_program_ids[]" value="{{ $program->id }}" @checked(in_array($program->id, $selectedPrograms, true)) class="rounded border-gray-300 text-indigo-600">
                                    {{ $program->code }}
                                </label>
                            @empty
                                <span class="text-gray-500">Create a program first under Academics.</span>
                            @endforelse
                        </div>
                    </fieldset>

                    <label class="inline-flex items-center gap-2 text-sm">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $user->is_active)) class="rounded border-gray-300 text-indigo-600">
                        Account active
                    </label>

                    <div class="flex justify-end gap-3">
                        <a href="{{ route('admin.users.index') }}" class="inline-flex items-center px-4 py-2 text-sm text-gray-600 hover:underline">Cancel</a>
                        <x-primary-button>{{ $user->exists ? 'Save changes' : 'Create user' }}</x-primary-button>
                    </div>
                </form>
            </x-panel>
        </div>
    </div>
</x-app-layout>
