<?php

namespace App\Http\Controllers\Instructor;

use App\Enums\BookingStatus;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Booking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Trial-class requests from the instructor's public profile page.
 *
 * Replaces app/views/instructor/bookings.php and the legacy BookingController.
 * The `bookings` table was previously created at runtime by
 * BookingModel::ensureTable() on every instantiation; it is a migration now.
 */
class BookingController extends Controller
{
    public function index(Request $request): View
    {
        $instructor = $request->user();

        $status = BookingStatus::tryFrom((string) $request->query('status'));

        $bookings = Booking::query()
            ->where('instructor_id', $instructor->id)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderByDesc('session_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('instructor.bookings.index', [
            'bookings' => $bookings,
            'status' => $status,
            'counts' => $this->counts($instructor->id),
        ]);
    }

    public function updateStatus(Request $request, Booking $booking): RedirectResponse
    {
        abort_unless($booking->instructor_id === $request->user()->id, 403);

        $data = $request->validate([
            'status' => ['required', 'string', 'in:pending,confirmed,cancelled'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $booking->update([
            'status' => BookingStatus::from($data['status']),
            'notes' => $data['notes'] ?? $booking->notes,
        ]);

        AuditLog::record(
            action: 'booking.'.$data['status'],
            subject: $booking,
            targetName: $booking->student_name,
            userId: $request->user()->id,
        );

        return back()->with('success', "{$booking->student_name} marked {$booking->status->label()}.");
    }

    /**
     * @return array<string, int>
     */
    private function counts(int $instructorId): array
    {
        $rows = Booking::query()
            ->selectRaw('status, COUNT(*) as total')
            ->where('instructor_id', $instructorId)
            ->groupBy('status')
            ->pluck('total', 'status');

        $counts = ['all' => (int) $rows->sum()];

        foreach (BookingStatus::cases() as $case) {
            $counts[$case->value] = (int) ($rows[$case->value] ?? 0);
        }

        return $counts;
    }
}
