<div>
    <x-input-label for="title" value="Title" />
    <x-text-input id="title" name="title" class="mt-1 block w-full" :value="old('title', $exam?->title)" required maxlength="255" />
    <x-input-error :messages="$errors->get('title')" class="mt-1" />
</div>
<div class="grid gap-4 sm:grid-cols-3">
    <div>
        <x-input-label for="exam_type" value="Type" />
        <select id="exam_type" name="exam_type" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500" required>
            @foreach (\App\Models\Exam::TYPES as $value => $label)
                <option value="{{ $value }}" @selected(old('exam_type', $exam?->exam_type) === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <x-input-label for="exam_date" value="Exam date" />
        <x-text-input id="exam_date" name="exam_date" type="date" class="mt-1 block w-full" :value="old('exam_date', $exam?->exam_date?->format('Y-m-d'))" />
    </div>
    <div>
        <x-input-label for="passing_percentage" value="Passing score (%)" />
        <x-text-input id="passing_percentage" name="passing_percentage" type="number" step="0.01" min="0" max="100" class="mt-1 block w-full" :value="old('passing_percentage', $exam?->passing_percentage ?? 60)" required />
    </div>
</div>
<div>
    <x-input-label for="instructions" value="Instructions" />
    <textarea id="instructions" name="instructions" rows="3" maxlength="5000" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">{{ old('instructions', $exam?->instructions) }}</textarea>
</div>
