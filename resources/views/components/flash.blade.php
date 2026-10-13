@if (session('status'))
    <div class="mb-4 rounded-md border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800" role="status">
        {{ session('status') }}
    </div>
@endif

@if ($errors->any())
    <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
        <p class="font-medium">Please check the following:</p>
        <ul class="mt-1 list-disc ps-5">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
