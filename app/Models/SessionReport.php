<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The instructor's post-class report. Filing one is what unlocks payment for
 * that session (see EarningsCalculator).
 */
class SessionReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'class_session_id',
        'instructor_id',
        'student_id',
        'class_date',
        'today_lesson',
        'next_lesson',
        'grammar_section',
        'pronunciation_section',
        'vocab_section',
        'teacher_comments',
        'listening_score',
        'speaking_score',
        'pronunciation_score',
        'vocabulary_score',
        'grammar_score',
    ];

    protected $casts = [
        'class_date' => 'date',
        'listening_score' => 'integer',
        'speaking_score' => 'integer',
        'pronunciation_score' => 'integer',
        'vocabulary_score' => 'integer',
        'grammar_score' => 'integer',
    ];

    /** The five assessment dimensions, in display order. */
    public const SCORE_FIELDS = [
        'listening_score' => 'Listening',
        'speaking_score' => 'Speaking',
        'pronunciation_score' => 'Pronunciation',
        'vocabulary_score' => 'Vocabulary',
        'grammar_score' => 'Grammar',
    ];

    /** The scale the form offers. Legacy only ever offered 1-5. */
    public const SCORE_MAX = 5;

    /**
     * The three correction sections are repeatable rows, not prose.
     *
     * Legacy stored each as a JSON array of two-field objects inside one TEXT
     * column, and the importer copies those columns verbatim — so these key
     * names are the on-disk format of live data, not a new invention. Renaming
     * one would orphan every imported report.
     *
     * `input` is the request key the form posts under.
     */
    public const ROW_SECTIONS = [
        'grammar_section' => ['input' => 'grammar', 'fields' => ['yourSentence', 'betterSay']],
        'pronunciation_section' => ['input' => 'pronunciation', 'fields' => ['word', 'comment']],
        'vocab_section' => ['input' => 'vocabulary', 'fields' => ['vocab', 'example']],
    ];

    // ---------------------------------------------------------------- relations

    public function classSession(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class);
    }

    public function instructor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'instructor_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    // ------------------------------------------------------------------- scopes

    public function scopeForInstructor(Builder $query, int $instructorId): Builder
    {
        return $query->where('instructor_id', $instructorId);
    }

    public function scopeBetween(Builder $query, string $start, string $end): Builder
    {
        return $query->whereBetween('class_date', [$start, $end]);
    }

    // ------------------------------------------------------------------ helpers

    /**
     * The rows stored in one of the ROW_SECTIONS columns.
     *
     * Tolerant on the way out, because three kinds of value are in these columns
     * already: legacy JSON, NULL, and free text typed into the plain textarea
     * this form used to render. Free text is lifted into the first field of a
     * single row rather than discarded — losing an instructor's note to a format
     * change would be worse than an oddly-placed one.
     *
     * @return array<int, array<string, string>>
     */
    public function rows(string $column): array
    {
        $fields = self::ROW_SECTIONS[$column]['fields'] ?? null;

        if ($fields === null) {
            return [];
        }

        $raw = trim((string) $this->{$column});

        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [[$fields[0] => $raw, $fields[1] => '']];
        }

        return array_values(array_map(
            fn ($row) => [
                $fields[0] => (string) (is_array($row) ? ($row[$fields[0]] ?? '') : $row),
                $fields[1] => (string) (is_array($row) ? ($row[$fields[1]] ?? '') : ''),
            ],
            $decoded,
        ));
    }

    /**
     * Encode submitted rows for storage, dropping the ones left entirely blank.
     *
     * Returns null rather than "[]" for an empty section, so "nothing recorded"
     * stays indistinguishable from a report filed before the section existed.
     *
     * @param  array<int, mixed>  $rows
     * @param  array<int, string>  $fields
     */
    public static function encodeRows(array $rows, array $fields): ?string
    {
        $clean = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $values = [];

            foreach ($fields as $field) {
                $values[$field] = trim((string) ($row[$field] ?? ''));
            }

            if (implode('', $values) !== '') {
                $clean[] = $values;
            }
        }

        return $clean === [] ? null : json_encode($clean, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Mean of the scores that were actually filled in, or null if none were.
     */
    public function averageScore(): ?float
    {
        $scores = array_filter(
            array_map(fn (string $field) => $this->{$field}, array_keys(self::SCORE_FIELDS)),
            fn ($score) => $score !== null
        );

        return $scores === [] ? null : round(array_sum($scores) / count($scores), 1);
    }
}
