{{--
    Post a material of your own.

    Collapsed by default so the shelf stays the point of the page; it opens
    itself when a submission comes back with errors, and when the instructor
    is already inside a folder that folder is preselected.

    Expects: $folderOptions, and $selectedFolder (a folder id or null).
--}}
@php
    $hasErrors = $errors->hasAny(['title', 'description', 'file', 'folder_id']);
    $selectedFolder = old('folder_id', $selectedFolder ?? null);
    $maxMegabytes = (int) (\App\Models\LearningMaterial::MAX_KILOBYTES / 1024);
@endphp

<x-card class="mb-4">
    <details class="group" @if ($hasErrors) open @endif>
        <summary class="focus-ring flex cursor-pointer items-center gap-3 rounded">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-brand-500/15 text-brand-400">
                <x-icon name="plus" class="h-5 w-5" />
            </span>

            <span class="min-w-0 flex-1">
                <span class="block font-semibold text-white">{{ __('Post a material') }}</span>
                <span class="mt-0.5 block text-xs text-gray-500">
                    {{ __('PDF, up to :size MB. Visible to everyone as soon as you post it.', ['size' => $maxMegabytes]) }}
                </span>
            </span>

            <x-icon name="chevron-right" class="h-4 w-4 shrink-0 text-gray-500 transition group-open:rotate-90" />
        </summary>

        <form method="POST"
              action="{{ route('instructor.materials.store') }}"
              enctype="multipart/form-data"
              class="mt-4 space-y-4 border-t border-gray-700 pt-4">
            @csrf

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="material-title" class="form-label">{{ __('Title') }}</label>
                    <input id="material-title"
                           name="title"
                           type="text"
                           value="{{ old('title') }}"
                           required
                           maxlength="255"
                           class="form-input"
                           placeholder="{{ __('e.g. Grammar drills — present perfect') }}">
                    @error('title') <p class="form-error">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="material-folder" class="form-label">
                        {{ __('Folder') }} <span class="text-gray-500">{{ __('(optional)') }}</span>
                    </label>
                    <select id="material-folder" name="folder_id" class="form-select">
                        <option value="">{{ __('Uncategorised') }}</option>
                        @foreach ($folderOptions as $option)
                            <option value="{{ $option->id }}" @selected((string) $selectedFolder === (string) $option->id)>
                                {{ $option->name }}
                            </option>
                        @endforeach
                    </select>
                    @error('folder_id') <p class="form-error">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="material-description" class="form-label">
                    {{ __('Description') }} <span class="text-gray-500">{{ __('(optional)') }}</span>
                </label>
                <textarea id="material-description"
                          name="description"
                          rows="2"
                          maxlength="5000"
                          class="form-textarea"
                          placeholder="{{ __('What this covers, and who it is for') }}">{{ old('description') }}</textarea>
                @error('description') <p class="form-error">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="material-file" class="form-label">{{ __('PDF file') }}</label>
                <input id="material-file"
                       name="file"
                       type="file"
                       accept="application/pdf,.pdf"
                       required
                       class="form-input file:mr-3 file:rounded file:border-0 file:bg-brand-400 file:px-3 file:py-1.5 file:text-xs file:font-bold file:text-gray-900">
                @error('file') <p class="form-error">{{ $message }}</p> @enderror
                <p class="mt-1.5 text-xs text-gray-500">
                    {{ __('Stored outside the web root and only served to signed-in users.') }}
                </p>
            </div>

            <button type="submit" class="btn-primary w-full sm:w-auto">{{ __('Post material') }}</button>
        </form>
    </details>
</x-card>
