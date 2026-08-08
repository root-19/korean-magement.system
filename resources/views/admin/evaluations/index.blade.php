@extends('layouts.app')

@section('title', 'Evaluation List')
@section('heading', 'Evaluation List')
@section('subheading', 'Instructors asking to mark a class that has already passed')

@section('actions')
    <div class="flex flex-wrap items-center gap-1.5">
        @foreach (['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $value => $label)
            <a href="{{ route('admin.evaluations.index', ['status' => $value]) }}"
               @class(['btn-sm', 'btn-primary' => $filter === $value, 'btn-secondary' => $filter !== $value])>
                {{ $label }}
                @if ($value === 'pending' && $pendingCount > 0)
                    <span class="numeric">({{ $pendingCount }})</span>
                @endif
            </a>
        @endforeach
    </div>
@endsection

@section('content')
    {{-- Approving reopens exactly one class. Marking it releases that session's
         payment, which is why it is a decision and not a self-service button. --}}
    <x-card flush>
        <x-slot:title>
            {{ $requests->total() }} {{ Str::plural('request', $requests->total()) }}
        </x-slot:title>

        <x-slot:subtitle>
            Approving reopens Present, Absent and Postpone for that one class only.
        </x-slot:subtitle>

        @if ($requests->isEmpty())
            <x-empty-state icon="clipboard"
                           title="Nothing waiting"
                           message="When an instructor asks to mark a class after the day has passed, it appears here." />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Class</th>
                            <th>Instructor</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th class="text-right">Decision</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($requests as $item)
                            <tr>
                                <td class="whitespace-nowrap">
                                    <span class="block font-medium text-white">{{ $item->student?->name ?? '—' }}</span>
                                    <span class="numeric text-xs text-gray-400">
                                        {{ $item->class_date->format('D, M j, Y') }}
                                    </span>
                                    <span class="block text-xs text-gray-500">
                                        asked {{ $item->created_at->diffForHumans() }}
                                    </span>
                                </td>

                                <td class="whitespace-nowrap text-sm text-gray-300">
                                    {{ $item->instructor?->name ?? '—' }}
                                </td>

                                <td>
                                    <p class="max-w-md text-sm text-gray-300">{{ $item->reason }}</p>

                                    @if ($item->decision_note)
                                        <p class="mt-1 max-w-md text-xs text-gray-500">
                                            Note: {{ $item->decision_note }}
                                        </p>
                                    @endif
                                </td>

                                <td class="whitespace-nowrap">
                                    @if ($item->isPending())
                                        <span class="badge-warning">Pending</span>
                                    @elseif ($item->isApproved())
                                        <span class="badge-success">Approved</span>
                                    @else
                                        <span class="badge-danger">Rejected</span>
                                    @endif

                                    @if ($item->decided_at)
                                        <span class="mt-0.5 block text-xs text-gray-500">
                                            by {{ $item->decidedBy?->name ?? 'system' }}
                                        </span>
                                    @endif
                                </td>

                                <td>
                                    @if ($item->isPending())
                                        <form method="POST"
                                              action="{{ route('admin.evaluations.decide', $item) }}"
                                              class="flex flex-col items-end gap-1.5">
                                            @csrf
                                            @method('PATCH')

                                            <input type="text"
                                                   name="decision_note"
                                                   maxlength="1000"
                                                   class="form-input !w-48 !py-1.5 text-xs"
                                                   placeholder="Note (optional)">

                                            <div class="flex gap-1.5">
                                                <button type="submit" name="decision" value="approved"
                                                        class="btn-primary btn-sm">Approve</button>
                                                <button type="submit" name="decision" value="rejected"
                                                        class="btn-danger btn-sm">Reject</button>
                                            </div>
                                        </form>
                                    @else
                                        <p class="text-right text-xs text-gray-500">
                                            {{ $item->decided_at?->format('M j, Y') ?? '—' }}
                                        </p>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($requests->hasPages())
                <div class="border-t border-gray-700 px-4 py-3">{{ $requests->links() }}</div>
            @endif
        @endif
    </x-card>
@endsection
