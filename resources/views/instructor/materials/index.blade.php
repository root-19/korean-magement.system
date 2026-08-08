@extends('layouts.app')

@section('title', 'Learning Materials')
@section('heading', 'Learning Materials')
@section('subheading', $materials === null
    ? 'Teaching resources published by the academy'
    : $folderLabel)

@section('content')
    @if ($materials === null)
        {{-- ─────────────────────────────────────────────── Folder list ───── --}}
        @if ($folders->isEmpty() && $looseCount === 0)
            <x-card>
                <x-empty-state icon="book-open"
                               title="No materials published yet"
                               message="When the academy posts a teaching resource it appears here, ready to download." />
            </x-card>
        @else
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($folders as $item)
                    <a href="{{ route('instructor.materials.index', ['folder' => $item->id]) }}"
                       class="card focus-ring group flex items-center gap-3 p-5 transition hover:border-brand-400/50">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-brand-500/15 text-brand-400">
                            <x-icon name="book-open" class="h-5 w-5" />
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-semibold text-white group-hover:text-brand-400">
                                {{ $item->name }}
                            </span>
                            <span class="numeric mt-0.5 block text-xs text-gray-500">
                                {{ $item->published_count }} {{ Str::plural('material', $item->published_count) }}
                            </span>
                        </span>

                        <x-icon name="chevron-right" class="h-4 w-4 shrink-0 text-gray-500 group-hover:text-brand-400" />
                    </a>
                @endforeach

                {{-- Materials posted without a folder still need a way in. --}}
                @if ($looseCount > 0)
                    <a href="{{ route('instructor.materials.index', ['folder' => 'none']) }}"
                       class="card focus-ring group flex items-center gap-3 p-5 transition hover:border-brand-400/50">
                        <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-gray-700/60 text-gray-400">
                            <x-icon name="book-open" class="h-5 w-5" />
                        </span>

                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-semibold text-white group-hover:text-brand-400">
                                Uncategorised
                            </span>
                            <span class="numeric mt-0.5 block text-xs text-gray-500">
                                {{ $looseCount }} {{ Str::plural('material', $looseCount) }}
                            </span>
                        </span>

                        <x-icon name="chevron-right" class="h-4 w-4 shrink-0 text-gray-500 group-hover:text-brand-400" />
                    </a>
                @endif
            </div>
        @endif
    @else
        {{-- ──────────────────────────────────────── Inside one folder ───── --}}
        <a href="{{ route('instructor.materials.index') }}" class="btn-ghost btn-sm mb-4">
            <x-icon name="chevron-left" class="h-4 w-4" />
            All folders
        </a>

        @if ($materials->isEmpty())
            <x-card>
                <x-empty-state icon="book-open"
                               title="Nothing in {{ $folderLabel }} yet"
                               message="Materials published into this folder will appear here." />
            </x-card>
        @else
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($materials as $material)
                    <x-card class="flex flex-col">
                        <div class="flex items-start gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-danger-500/15 text-danger-400">
                                <x-icon name="book-open" class="h-5 w-5" />
                            </span>

                            <div class="min-w-0 flex-1">
                                <h3 class="font-semibold text-white">{{ $material->title }}</h3>

                                <p class="mt-0.5 text-xs text-gray-500">
                                    PDF · <span class="numeric">{{ $material->readableSize() }}</span>
                                    @if ($material->published_at)
                                        · <span class="numeric">{{ $material->published_at->format('M j, Y') }}</span>
                                    @endif
                                </p>
                            </div>
                        </div>

                        @if ($material->description)
                            <p class="mt-3 flex-1 text-sm text-gray-300">{{ $material->description }}</p>
                        @endif

                        <a href="{{ route('instructor.materials.download', $material) }}"
                           class="btn-secondary mt-4 w-full">
                            <x-icon name="download" class="h-4 w-4" />
                            Download
                        </a>
                    </x-card>
                @endforeach
            </div>
        @endif
    @endif
@endsection
