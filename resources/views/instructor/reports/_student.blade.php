{{--
    The student cell of the report list: avatar, name, and — when the student has
    since been archived — a badge saying so.

    Its own partial only because the row wraps it in an <a> or a <span> depending
    on whether the student page can still be opened.
--}}

<x-avatar :user="$student" class="h-8 w-8" />

<span class="min-w-0">
    <span class="block truncate font-medium text-white">
        {{ $student?->name ?? 'Deleted student' }}
    </span>

    @if ($student?->trashed())
        {{-- Archiving a student never erases the reports the instructor wrote
             about them. --}}
        <span class="badge-neutral mt-0.5">Archived</span>
    @endif
</span>
