<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Audit log</h2>
    </x-slot>

    <div class="py-8">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <x-panel>
                <form method="GET" class="mb-4 flex max-w-md gap-2">
                    <x-text-input name="action" :value="request('action')" placeholder="Filter by action prefix, e.g. grading." class="block w-full" />
                    <x-secondary-button type="submit">Filter</x-secondary-button>
                </form>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="text-left text-xs uppercase text-gray-500">
                            <tr><th class="py-2 pe-4">When</th><th class="pe-4">User</th><th class="pe-4">Action</th><th class="pe-4">Subject</th><th class="pe-4">Before</th><th>After</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 align-top">
                            @forelse ($logs as $log)
                                <tr>
                                    <td class="py-2 pe-4 whitespace-nowrap">{{ $log->created_at?->format('Y-m-d H:i') }}</td>
                                    <td class="pe-4">{{ $log->user?->name ?? 'system' }}</td>
                                    <td class="pe-4 font-mono text-xs">{{ $log->action }}</td>
                                    <td class="pe-4 text-xs">{{ $log->auditable_type ? class_basename($log->auditable_type).' #'.$log->auditable_id : '—' }}</td>
                                    <td class="pe-4 max-w-xs break-words font-mono text-xs text-gray-600">{{ $log->old_values ? json_encode($log->old_values) : '' }}</td>
                                    <td class="max-w-xs break-words font-mono text-xs text-gray-600">{{ $log->new_values ? json_encode($log->new_values) : '' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" class="py-4 text-gray-500">No audit entries.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="mt-4">{{ $logs->links() }}</div>
            </x-panel>
        </div>
    </div>
</x-app-layout>
