@extends('layouts.app')

@section('title', 'For Evaluation')
@section('heading', 'For Evaluation')
@section('subheading', 'Teachers with past classes that still have no attendance recorded')

@section('actions')
    <a href="{{ route('admin.evaluations.index') }}" class="btn-secondary btn-sm">
        <x-icon name="clipboard" class="h-4 w-4" />
        Evaluation List
    </a>
@endsection

@section('content')
    {{-- The same rows the teacher sees on their own roster with a "For evaluation"
         button. Classes already sent are in the Evaluation List instead. --}}
    <x-card flush>
        <x-slot:title>
            {{ $teachers->count() }} {{ Str::plural('teacher', $teachers->count()) }}
        </x-slot:title>

        <x-slot:subtitle>
            Last {{ $lookbackDays }} days, not counting today. Not yet sent for evaluation, or sent and rejected.
        </x-slot:subtitle>

        @if ($teachers->isEmpty())
            <x-empty-state icon="check-circle"
                           title="Every class is marked"
                           message="No teacher has an unmarked class from the last {{ $lookbackDays }} days." />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Teacher</th>
                            <th class="text-center">Classes</th>
                            <th>Unmarked classes</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($teachers as $item)
                            <tr>
                                <td class="whitespace-nowrap">
                                    <a href="{{ route('admin.instructors.show', $item['instructor']) }}"
                                       class="focus-ring flex items-center gap-2.5 rounded">
                                        <x-avatar :user="$item['instructor']" class="h-8 w-8" />
                                        <span class="font-medium text-white">{{ $item['instructor']->name }}</span>
                                    </a>
                                </td>

                                <td class="numeric text-center">
                                    <span class="font-medium text-danger-400">{{ $item['classes']->count() }}</span>
                                </td>

                                <td>
                                    <ul class="flex flex-wrap gap-1.5">
                                        @foreach ($item['classes'] as $row)
                                            <li class="inline-flex items-center gap-1.5 rounded-lg border border-gray-700 bg-gray-800 px-2.5 py-1 text-xs text-gray-300">
                                                {{ $row['student']->name }}
                                                <span class="numeric text-gray-500">{{ $row['date']->format('M j') }}</span>
                                                @if ($row['request']?->isRejected())
                                                    <span class="badge-danger shrink-0">Rejected</span>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-card>
@endsection
