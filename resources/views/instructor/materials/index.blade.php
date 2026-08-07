@extends('layouts.app')

@section('title', 'Learning Materials')
@section('heading', 'Learning Materials')
@section('subheading', 'Teaching resources published by the academy')

@section('content')
    @if ($materials->isEmpty())
        <x-card>
            <x-empty-state icon="book-open"
                           title="No materials published yet"
                           message="When the academy posts a teaching resource it appears here, ready to download." />
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
                            <h2 class="font-semibold text-white">{{ $material->title }}</h2>

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

        @if ($materials->hasPages())
            <div class="mt-4">
                {{ $materials->links() }}
            </div>
        @endif
    @endif
@endsection
