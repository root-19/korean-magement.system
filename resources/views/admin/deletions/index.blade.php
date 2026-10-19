@extends('layouts.app')

@section('title', __('Deletion Requests'))
@section('heading', __('Deletion Requests'))
@section('subheading', __('Instructors asking for a student to be removed'))

@section('actions')
    <div class="flex flex-wrap items-center gap-1.5">
        @foreach (['pending' => __('Pending'), 'approved' => __('Approved'), 'rejected' => __('Rejected'), 'all' => __('All')] as $value => $label)
            <a href="{{ route('admin.deletions.index', ['status' => $value]) }}"
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
    {{-- An instructor can ask, but only this page removes anyone. --}}
    <x-card flush>
        <x-slot:title>
            {{ trans_choice(':count request|:count requests', $requests->total()) }}
        </x-slot:title>

        <x-slot:subtitle>
            {{ __('Approving removes the student from every list, roster and login. The classes taught to them are kept, so instructor payouts do not change.') }}
        </x-slot:subtitle>

        @if ($requests->isEmpty())
            <x-empty-state icon="trash"
                           :title="__('Nothing waiting')"
                           :message="__('When an instructor asks for a student to be deleted, it appears here.')" />
        @else
            <div class="table-wrap">
                <table class="table">
                    <thead>
                        <tr>
                            <th>{{ __('Student') }}</th>
                            <th>{{ __('Instructor') }}</th>
                            <th>{{ __('Reason') }}</th>
                            <th>{{ __('On record') }}</th>
                            <th>{{ __('Status') }}</th>
                            <th class="text-right">{{ __('Decision') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($requests as $item)
                            @php $record = $records[$item->student_id] ?? null; @endphp
                            <tr>
                                <td class="whitespace-nowrap">
                                    <span class="block font-medium text-white">{{ $item->student_name }}</span>
                                    @if ($item->student)
                                        <a href="{{ route('admin.students.show', $item->student) }}"
                                           class="focus-ring rounded text-xs text-brand-400 underline underline-offset-2">
                                            {{ __('Open student') }}
                                        </a>
                                    @endif
                                    <span class="block text-xs text-gray-500">
                                        {{ __('asked :time', ['time' => $item->created_at->diffForHumans()]) }}
                                    </span>
                                </td>

                                <td class="whitespace-nowrap text-sm text-gray-300">
                                    {{ $item->instructor?->name ?? '—' }}
                                </td>

                                <td>
                                    <p class="max-w-md text-sm text-gray-300">{{ $item->reason }}</p>

                                    @if ($item->decision_note)
                                        <p class="mt-1 max-w-md text-xs text-gray-500">
                                            {{ __('Note: :note', ['note' => $item->decision_note]) }}
                                        </p>
                                    @endif
                                </td>

                                {{-- What the deletion carries. None of it is destroyed;
                                     it is here so nobody approves one blind. --}}
                                <td class="whitespace-nowrap text-xs">
                                    @if ($record === null)
                                        <span class="text-gray-400">—</span>
                                    @else
                                        <span class="numeric block text-gray-300">
                                            {{ trans_choice(':count class|:count classes', $record['sessions']) }}
                                            · {{ trans_choice(':count report|:count reports', $record['reports']) }}
                                        </span>
                                        <span class="numeric block text-gray-500">
                                            {{ __(':amount recorded pay · kept', ['amount' => money($record['earnings'])]) }}
                                        </span>
                                    @endif
                                </td>

                                <td class="whitespace-nowrap">
                                    @if ($item->isPending())
                                        <span class="badge-warning">{{ __('Pending') }}</span>
                                    @elseif ($item->isApproved())
                                        <span class="badge-danger">{{ __('Deleted') }}</span>
                                    @else
                                        <span class="badge-neutral">{{ __('Rejected') }}</span>
                                    @endif

                                    @if ($item->decided_at)
                                        <span class="mt-0.5 block text-xs text-gray-500">
                                            {{ __('by :name', ['name' => $item->decidedBy?->name ?? __('system')]) }}
                                        </span>
                                    @endif
                                </td>

                                <td>
                                    @if ($item->isPending())
                                        <form method="POST"
                                              action="{{ route('admin.deletions.decide', $item) }}"
                                              class="flex flex-col items-end gap-1.5">
                                            @csrf
                                            @method('PATCH')

                                            <input type="text"
                                                   name="decision_note"
                                                   maxlength="1000"
                                                   class="form-input !w-48 !py-1.5 text-xs"
                                                   placeholder="{{ __('Note (optional)') }}">

                                            <div class="flex gap-1.5">
                                                {{-- The confirm sits on the button, not the form:
                                                     only one of the two decisions needs it. --}}
                                                <button type="submit" name="decision" value="approved"
                                                        class="btn-danger btn-sm"
                                                        onclick="return confirm(@js(__('Delete :name? They are removed from every list and can no longer sign in.', ['name' => $item->student_name])))">{{ __('Delete student') }}</button>
                                                <button type="submit" name="decision" value="rejected"
                                                        class="btn-secondary btn-sm">{{ __('Reject') }}</button>
                                            </div>
                                        </form>
                                    @else
                                        <p class="text-right text-xs text-gray-500">
                                            {{ $item->decided_at?->translatedFormat('M j, Y') ?? '—' }}
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
