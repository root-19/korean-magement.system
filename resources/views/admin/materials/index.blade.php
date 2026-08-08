@extends('layouts.app')

@section('title', 'Learning Materials')
@section('heading', 'Learning Materials')
@section('subheading', 'PDFs published to instructors')

@section('content')
    <div class="grid gap-4 lg:grid-cols-3">
        {{-- ──────────────────────────────────────────────── Upload form ───── --}}
        <div class="lg:col-span-1 space-y-4">
            <x-card title="Folders" subtitle="One level, to group the library">
                <form method="POST" action="{{ route('admin.materials.folders.store') }}" class="flex gap-2">
                    @csrf
                    <input type="text" name="name" maxlength="120" required class="form-input"
                           placeholder="e.g. Grammar drills" value="{{ old('name') }}">
                    <button type="submit" class="btn-secondary shrink-0">Add</button>
                </form>
                @error('name') <p class="form-error">{{ $message }}</p> @enderror

                @if ($folders->isNotEmpty())
                    <ul class="mt-3 space-y-1.5 border-t border-gray-700 pt-3">
                        @foreach ($folders as $folder)
                            <li class="flex items-center justify-between gap-2 text-sm">
                                <span class="min-w-0 truncate text-gray-300">
                                    {{ $folder->name }}
                                    <span class="numeric text-xs text-gray-500">({{ $folder->materials_count }})</span>
                                </span>

                                {{-- Removing a folder never removes its PDFs; they
                                     fall back to Uncategorised. --}}
                                <form method="POST"
                                      action="{{ route('admin.materials.folders.destroy', $folder) }}"
                                      onsubmit="return confirm('Remove this folder? Its materials move to Uncategorised - nothing is deleted.')">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="btn-ghost btn-sm !px-1.5"
                                            aria-label="Remove folder {{ $folder->name }}">
                                        <x-icon name="trash" class="h-3.5 w-3.5" />
                                    </button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-card>

            <x-card title="Post a material" subtitle="PDF, up to 20 MB">
                <form method="POST"
                      action="{{ route('admin.materials.store') }}"
                      enctype="multipart/form-data"
                      class="space-y-4">
                    @csrf

                    <div>
                        <label for="title" class="form-label">Title</label>
                        <input id="title"
                               name="title"
                               type="text"
                               value="{{ old('title') }}"
                               required
                               maxlength="255"
                               class="form-input"
                               placeholder="e.g. Grammar drills — present perfect">
                        @error('title') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="description" class="form-label">
                            Description <span class="text-gray-500">(optional)</span>
                        </label>
                        <textarea id="description"
                                  name="description"
                                  rows="3"
                                  maxlength="5000"
                                  class="form-textarea"
                                  placeholder="What this covers, and who it is for">{{ old('description') }}</textarea>
                        @error('description') <p class="form-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="folder_id" class="form-label">
                            Folder <span class="text-gray-500">(optional)</span>
                        </label>
                        <select id="folder_id" name="folder_id" class="form-select">
                            <option value="">Uncategorised</option>
                            @foreach ($folders as $folder)
                                <option value="{{ $folder->id }}" @selected(old('folder_id') == $folder->id)>
                                    {{ $folder->name }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="file" class="form-label">PDF file</label>
                        <input id="file"
                               name="file"
                               type="file"
                               accept="application/pdf,.pdf"
                               required
                               class="form-input file:mr-3 file:rounded file:border-0 file:bg-brand-400 file:px-3 file:py-1.5 file:text-xs file:font-bold file:text-gray-900">
                        @error('file') <p class="form-error">{{ $message }}</p> @enderror
                        <p class="mt-1.5 text-xs text-gray-500">
                            Stored outside the web root and only served to signed-in staff.
                        </p>
                    </div>

                    <label class="flex items-start gap-2.5">
                        <input type="checkbox"
                               name="is_published"
                               value="1"
                               @checked(old('is_published'))
                               class="mt-0.5 h-4 w-4 rounded border-gray-600 bg-gray-900 text-brand-500 focus:ring-brand-400">
                        <span class="text-sm text-gray-300">
                            Publish immediately
                            <span class="mt-0.5 block text-xs text-gray-500">
                                Leave unticked to keep it as a draft only admins can see.
                            </span>
                        </span>
                    </label>

                    <button type="submit" class="btn-primary w-full">Post material</button>
                </form>
            </x-card>
        </div>

        {{-- ───────────────────────────────────────────────────── The list ───── --}}
        <div class="lg:col-span-2">
            <x-card flush>
                <x-slot:title>
                    {{ $materials->total() }} {{ Str::plural('material', $materials->total()) }}
                </x-slot:title>

                <x-slot:subtitle>
                    {{ $materials->getCollection()->where('is_published', true)->count() }} published on this page
                </x-slot:subtitle>

                @if ($materials->isEmpty())
                    <x-empty-state icon="book-open"
                                   title="No materials yet"
                                   message="Post a PDF and it appears on the instructors' Learning Materials page as soon as it is published." />
                @else
                    <div class="table-wrap">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Material</th>
                                    <th>Posted</th>
                                    <th>Status</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($materials as $material)
                                    <tr>
                                        <td>
                                            <a href="{{ route('instructor.materials.download', $material) }}"
                                               class="focus-ring rounded font-medium text-white hover:text-brand-400">
                                                {{ $material->title }}
                                            </a>

                                            @if ($material->description)
                                                <p class="mt-0.5 max-w-md text-xs text-gray-400">
                                                    {{ Str::limit($material->description, 120) }}
                                                </p>
                                            @endif

                                            <p class="mt-0.5 text-xs text-gray-500">
                                                <span class="badge-neutral">{{ $material->folder?->name ?? 'Uncategorised' }}</span>
                                                {{ $material->original_name }} ·
                                                <span class="numeric">{{ $material->readableSize() }}</span>
                                            </p>
                                        </td>

                                        <td class="whitespace-nowrap text-xs text-gray-400">
                                            <span class="numeric">{{ $material->created_at->format('M j, Y') }}</span>
                                            @if ($material->uploader)
                                                <span class="block text-gray-500">by {{ $material->uploader->name }}</span>
                                            @endif
                                        </td>

                                        <td>
                                            @if ($material->is_published)
                                                <span class="badge-success">Published</span>
                                            @else
                                                <span class="badge-neutral">Draft</span>
                                            @endif
                                        </td>

                                        <td>
                                            <div class="flex flex-wrap items-center justify-end gap-1.5">
                                                <form method="POST"
                                                      action="{{ route('admin.materials.published', $material) }}"
                                                      class="inline">
                                                    @csrf
                                                    @method('PATCH')
                                                    <button type="submit" class="btn-secondary btn-sm">
                                                        {{ $material->is_published ? 'Unpublish' : 'Publish' }}
                                                    </button>
                                                </form>

                                                {{-- Deleting removes the file from disk too, so it is
                                                     confirmed rather than one click away. --}}
                                                <form method="POST"
                                                      action="{{ route('admin.materials.destroy', $material) }}"
                                                      class="inline"
                                                      onsubmit="return confirm('Delete “{{ $material->title }}” and its PDF? This cannot be undone.')">
                                                    @csrf
                                                    @method('DELETE')
                                                    <button type="submit" class="btn-ghost btn-sm !px-1.5"
                                                            aria-label="Delete {{ $material->title }}">
                                                        <x-icon name="trash" class="h-4 w-4" />
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if ($materials->hasPages())
                        <div class="border-t border-gray-700 px-4 py-3">
                            {{ $materials->links() }}
                        </div>
                    @endif
                @endif
            </x-card>
        </div>
    </div>
@endsection
