@extends('layouts.app')

@section('title', 'Reports')
@section('heading', 'Class reports')
@section('subheading', $reports->total().' filed')

@section('content')
    <x-card flush>
        @if ($reports->isEmpty())
            <x-empty-state icon="clipboard"
                           title="No reports filed yet"
                           message="File a report from the class list after marking a session — it is what releases payment for that class.">
                <a href="{{ route('instructor.classes.index') }}" class="btn-primary btn-sm">Go to class list</a>
            </x-empty-state>
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Class date</th>
                            <th>Student</th>
                            <th>Lesson</th>
                            <th class="text-center">Avg score</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($reports as $report)
                            <tr>
                                <td class="numeric whitespace-nowrap">
                                    {{ $report->class_date->format('D, M j, Y') }}
                                </td>

                                <td>
                                    @php
                                        // The student page is bound to a live student, so an
                                        // archived one is shown as a label rather than a link
                                        // into a 404. The report itself stays editable.
                                        $linkable = $report->student && ! $report->student->trashed();
                                    @endphp

                                    @if ($linkable)
                                        <a href="{{ route('instructor.students.show', $report->student_id) }}"
                                           class="focus-ring flex items-center gap-2.5 rounded">
                                            @include('instructor.reports._student', ['student' => $report->student])
                                        </a>
                                    @else
                                        <span class="flex items-center gap-2.5">
                                            @include('instructor.reports._student', ['student' => $report->student])
                                        </span>
                                    @endif
                                </td>

                                <td class="max-w-sm truncate text-gray-500">
                                    {{ $report->today_lesson ?: '—' }}
                                </td>

                                <td class="numeric text-center">
                                    @if ($report->averageScore() !== null)
                                        <span class="font-medium text-brand-400">
                                            {{ $report->averageScore() }}
                                        </span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>

                                <td class="text-right">
                                    <a href="{{ route('instructor.reports.edit', $report) }}"
                                       class="btn-secondary btn-sm">
                                        <x-icon name="pencil" class="h-3.5 w-3.5" />
                                        Edit
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($reports->hasPages())
                <div class="border-t border-gray-700 px-4 py-3">
                    {{ $reports->links() }}
                </div>
            @endif
        @endif
    </x-card>
@endsection
