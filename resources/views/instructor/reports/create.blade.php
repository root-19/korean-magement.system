@extends('layouts.app')

@section('title', 'Class report')
@section('heading', $report ? 'Edit class report' : 'File class report')
@section('subheading', $student->name.' · '.\Carbon\Carbon::parse($date)->format('l, F j, Y'))

@php
    use App\Models\SessionReport;

    /*
     * Presentation for the three repeatable sections. The field KEYS come from
     * SessionReport::ROW_SECTIONS — they are the on-disk JSON format — while the
     * labels, pill colours and placeholders live here because they are only ever
     * about how the form looks.
     */
    $sectionMeta = [
        'grammar_section' => [
            'title' => 'Grammar',
            'hint' => "(Please check and correct the student's grammar in this section)",
            'tone' => 'brand',
            'add' => '+ Add Grammar Row',
            // How the section is written into the copied report. Every section
            // copies the same way — a labelled line per field, a blank line
            // between rows — so only the heading and the two labels differ.
            'copy' => [
                'title' => '[GRAMMAR]',
                'labels' => ['Your Sentence: ', 'Better Say: '],
            ],
            'fields' => [
                'yourSentence' => [
                    'label' => 'Your sentence',
                    'tone' => 'brand',
                    'rows' => 1,
                    'placeholder' => 'e.g. I am very tiring',
                ],
                'betterSay' => [
                    'label' => 'Better say',
                    'tone' => 'accent',
                    'rows' => 1,
                    'placeholder' => 'e.g. I am very tired',
                ],
            ],
        ],
        'pronunciation_section' => [
            'title' => 'Pronunciation',
            'hint' => "(Please check and correct the student's pronunciation in this section)",
            'tone' => 'accent',
            'add' => '+ Add Pronunciation Row',
            'copy' => [
                'title' => '[PRONUNCIATION]',
                'labels' => ['Word to Practice: ', 'Feedback / Suggestion: '],
            ],
            'fields' => [
                'word' => [
                    'label' => 'Word to practice',
                    'tone' => 'brand',
                    'rows' => 1,
                    'placeholder' => 'e.g. ruler / ru.lar/',
                ],
                'comment' => [
                    'label' => 'Comments / suggestions',
                    'tone' => 'accent',
                    'rows' => 1,
                    'placeholder' => 'e.g. Please be careful when pronouncing your L and R sounds',
                ],
            ],
        ],
        'vocab_section' => [
            'title' => 'Vocabulary Or Expression',
            'hint' => "(Please check and correct the student's vocabulary in this section)",
            'tone' => 'brand',
            'add' => '+ Add Vocabulary Row',
            'copy' => [
                'title' => '[VOCABULARY]',
                'labels' => ['New Vocabulary / Expression: ', 'Feedback / Suggestion: '],
            ],
            'fields' => [
                'vocab' => [
                    'label' => 'New vocabulary / expression',
                    'tone' => 'accent',
                    'rows' => 1,
                    'placeholder' => 'e.g. set in stone',
                ],
                'example' => [
                    'label' => 'When to say it / example sentence',
                    'tone' => 'brand',
                    'rows' => 3,
                    'placeholder' => "We can say 'set in stone' if an agreement, policy, or rule is completely decided and cannot be changed.",
                ],
            ],
        ],
    ];

    // Alpine owns the rows so they can be added and removed; seed it from old
    // input first (so a failed validation keeps what was typed), then the saved
    // report, then one blank row to type into.
    $initialRows = [];

    foreach (SessionReport::ROW_SECTIONS as $column => $section) {
        $rows = old($section['input'], $report?->rows($column) ?? []);
        $blank = array_fill_keys($section['fields'], '');

        $initialRows[$section['input']] = $rows === [] ? [$blank] : array_values($rows);
    }

    /*
     * The correction sections as the COPIED report orders them: grammar, then
     * vocabulary, then pronunciation. Deliberately not the form's order — the
     * student reads the corrections first and the word lists last, which is the
     * running order the school's feedback has always been sent in.
     */
    $copyMeta = [];

    foreach (['grammar_section', 'vocab_section', 'pronunciation_section'] as $column) {
        $copyMeta[] = [
            'input' => SessionReport::ROW_SECTIONS[$column]['input'],
            'fields' => SessionReport::ROW_SECTIONS[$column]['fields'],
            'title' => $sectionMeta[$column]['copy']['title'],
            'labels' => $sectionMeta[$column]['copy']['labels'],
        ];
    }

    $initialScores = [];

    foreach (array_keys(SessionReport::SCORE_FIELDS) as $field) {
        $initialScores[$field] = (string) (old($field, $report?->{$field}) ?? '');
    }

    /*
     * The enrolment block that OPENS the copied report: who the report is for
     * and the shape of their classes, before any of the teaching. All of it is
     * fixed for the session, so it is laid out here in one pass rather than
     * tracked in Alpine state.
     *
     * A fact that was never recorded is left out rather than pasted as a dash —
     * a student with no timetable rows gets no "Class day" line at all.
     *
     * The day names are written out in full ("Monday, Tuesday"), not as the
     * "M/T" initials the on-screen roster uses: the student reads this pasted
     * into a chat, with none of the page around it to decode the shorthand.
     */
    $copyHeader = [];

    $facts = [
        'Student' => $student->name,
        'Start date' => $profile?->start_date?->format('F j, Y'),
        'Class day' => $student->schedules->map->dayName()->implode(', '),
        // Every class in the academy is called in Korea time.
        'Class time' => $classTime ? $classTime.' (KT)' : null,
        'Class duration' => $profile?->learning_time ? $profile->learning_time.' minutes' : null,
        'Type of class' => $profile?->teaching_method?->label(),
        // The one date that changes per report, and the only one carrying its
        // weekday — it is what the student checks the message against.
        'Class date' => \Carbon\Carbon::parse($date)->format('l, F j, Y'),
    ];

    foreach ($facts as $label => $value) {
        if ((string) $value !== '') {
            $copyHeader[] = $label.': '.$value;
        }
    }
@endphp

@section('content')
    {{-- Context the form itself does not carry: whether this session is paid, and
         what was planned last time. The legacy page had neither on screen. --}}
    <div class="mb-4 grid gap-3 sm:grid-cols-2">
        <x-card>
            <dl class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm">
                <div class="flex items-center gap-2">
                    <dt class="text-gray-500">Attendance</dt>
                    <dd>
                        @if ($session?->status)
                            <span class="{{ $session->status->badgeClass() }}">{{ $session->status->label() }}</span>
                        @else
                            <span class="badge-neutral">Not marked</span>
                        @endif
                    </dd>
                </div>

                <div class="flex items-center gap-2">
                    <dt class="text-gray-500">Sessions</dt>
                    <dd class="numeric font-medium text-white">
                        {{ $progress['attended'] }}/{{ $progress['purchased'] }}
                        <span class="text-xs font-normal text-gray-400">
                            attended · {{ $progress['remaining'] }} remaining
                            · {{ $progress['deducted'] }} deducted
                        </span>
                    </dd>
                </div>

                <div class="flex items-center gap-2">
                    <dt class="text-gray-500">Absent</dt>
                    <dd class="numeric font-medium text-white">
                        {{ $progress['student_absent'] }}
                        <span class="text-xs font-normal text-gray-400">student</span>
                        · {{ $progress['teacher_absent'] }}
                        <span class="text-xs font-normal text-gray-400">teacher</span>
                    </dd>
                </div>

                <div class="flex items-center gap-2">
                    <dt class="text-gray-500">Postponed</dt>
                    <dd class="numeric font-medium text-white">
                        {{ $progress['student_postponed'] }}
                        <span class="text-xs font-normal text-gray-400">student</span>
                        · {{ $progress['teacher_postponed'] }}
                        <span class="text-xs font-normal text-gray-400">teacher</span>
                    </dd>
                </div>

                <div class="flex items-center gap-2">
                    <dt class="text-gray-500">Session value</dt>
                    <dd class="numeric font-medium text-white">@money2($profile?->sessionValue() ?? 0)</dd>
                </div>

                @if ($session?->isEarly())
                    <div class="flex items-center gap-2">
                        <dt class="text-gray-500">Covers slot</dt>
                        <dd class="numeric font-medium text-white">{{ $session->scheduled_date->format('M j, Y') }}</dd>
                    </div>
                @endif
            </dl>

            @if ($session === null)
                <p class="mt-3 rounded-lg bg-warning-500/10 px-3 py-2 text-xs text-warning-400">
                    No attendance record for this date. The report saves either way, but the
                    session cannot be paid until attendance is marked.
                </p>
            @elseif ($session->isPayable() && ! $report)
                <p class="mt-3 rounded-lg bg-warning-500/10 px-3 py-2 text-xs text-warning-400">
                    This session is not yet paid. Filing this report releases it.
                </p>
            @endif
        </x-card>

        @if ($previous)
            <x-card>
                <p class="text-xs font-medium uppercase tracking-wide text-gray-400">
                    Previous report · {{ $previous->class_date->format('M j, Y') }}
                </p>

                @if ($previous->next_lesson)
                    <p class="mt-1.5 text-sm text-gray-300">
                        <span class="text-gray-500">Planned for today:</span> {{ $previous->next_lesson }}
                    </p>
                @endif

                @if ($previous->averageScore() !== null)
                    <p class="mt-1.5 text-sm text-gray-300">
                        <span class="text-gray-500">Average score:</span>
                        <span class="numeric font-semibold text-brand-400">
                            {{ $previous->averageScore() }}/{{ SessionReport::SCORE_MAX }}
                        </span>
                    </p>
                @endif
            </x-card>
        @endif
    </div>

    {{-- A filed report submits to its own PUT route: the natural key
         (instructor, student, class_date) is what earnings match on, so an edit
         must not be able to carry a different one. Filing a new report still
         posts the pair. --}}
    <form method="POST"
          action="{{ $report ? route('instructor.reports.update', $report) : route('instructor.reports.store') }}"
          class="space-y-4"
          x-data="{
              rows: @js($initialRows),
              scores: @js($initialScores),
              today: @js(old('today_lesson', $report?->today_lesson ?? '')),
              next: @js(old('next_lesson', $report?->next_lesson ?? '')),
              comments: @js(old('teacher_comments', $report?->teacher_comments ?? '')),
              progress: @js($progress),

              {{-- A filed report opens read-only, so a stray keystroke cannot
                   overwrite work that has already been sent to the student. --}}
              locked: {{ $report ? 'true' : 'false' }},

              sections: @js($copyMeta),

              add(input) {
                  const shape = this.sections.find((s) => s.input === input);
                  const blank = {};
                  shape.fields.forEach((f) => (blank[f] = ''));
                  this.rows[input].push(blank);
              },

              remove(input, index) {
                  this.rows[input].splice(index, 1);
                  if (this.rows[input].length === 0) this.add(input);
              },

              filled(input) {
                  return this.rows[input].filter((r) => Object.values(r).some((v) => (v || '').trim() !== ''));
              },

              {{-- One section of corrections, in the shape the school's reports
                   are written in: a labelled line per field — "Your Sentence:",
                   "Better Say:" — with a blank line between rows. --}}
              copySection(s) {
                  const rows = this.filled(s.input);

                  if (rows.length === 0) return [];

                  const out = [s.title];

                  rows.forEach((r, i) => {
                      {{-- A blank line between corrections, none before the
                           first, so the block does not open on empty space. --}}
                      if (i > 0) out.push('');

                      s.fields.forEach((f, n) => {
                          if (r[f]) out.push(s.labels[n] + r[f]);
                      });
                  });

                  return out;
              },

              {{-- The comments box holds two different things. Teachers write
                   their own message and then, under a rule of asterisks, the
                   idiom of the day the school sends out with every report —
                   both typed into the one field, because the form only offers
                   one.

                   The copy pulls them apart so the teacher's message is the
                   last thing the student reads instead of trailing off into a
                   dictionary entry. Returns [message, extra block].

                   The rule is asterisks ALONE on a line, so emphasis inside a
                   sentence — "you did ***great*** today" — is not a separator,
                   and only the first rule splits: anything the teacher wrote
                   under a second one stays with the block it belongs to. --}}
              splitComments() {
                  const rule = this.comments.match(/^[ \t]*\*{3,}[ \t]*$/m);

                  if (! rule) return [this.comments.trim(), []];

                  const message = this.comments.slice(0, rule.index).trim();
                  const tail = this.comments.slice(rule.index + rule[0].length).trim();

                  if (tail === '') return [message, []];

                  {{-- "Idiom of the Day" becomes "[IDIOM OF THE DAY]", so the
                       block is headed the way every other section is. --}}
                  const lines = tail.split('\n');
                  const heading = lines.shift().trim();
                  const body = lines.join('\n').trim();

                  return [message, [`[${heading.toUpperCase()}]`, ...(body ? [body] : [])]];
              },

              {{-- The report as plain text, for pasting into a chat with the
                   student. The layout is the school's own feedback format rather
                   than the form's: the date and who the class was for, then the
                   scores and the corrections, then the teacher's message under a
                   rule. --}}
              copyAll() {
                  {{-- Who the class was for and the shape of their enrolment,
                       so the student can place the message before reading a
                       word of it. --}}
                  const out = [...@js($copyHeader), ''];

                  {{-- Where the student is in their plan: 15/16 taught, 1 left. --}}
                  out.push(`Sessions: ${this.progress.attended}/${this.progress.purchased} attended · ${this.progress.remaining} remaining · ${this.progress.deducted} deducted`);
                  out.push(`Absent: ${this.progress.student_absent} student · ${this.progress.teacher_absent} teacher`);
                  out.push(`Postponed: ${this.progress.student_postponed} student · ${this.progress.teacher_postponed} teacher`);

                  {{-- What was taught and what comes next, in the shouted
                       heading style the rest of the report is written in. --}}
                  if (this.today || this.next) out.push('');
                  if (this.today) out.push(`TODAY'S LESSON: ${this.today}`);
                  if (this.next) out.push(`NEXT LESSON: ${this.next}`);

                  const scored = Object.entries(this.scores).filter(([, v]) => v !== '');

                  if (scored.length) {
                      out.push('', '[CLASS SCORES]');
                      scored.forEach(([k, v]) => out.push(`${@js(SessionReport::SCORE_FIELDS)[k]}: ${v}/{{ SessionReport::SCORE_MAX }}`));
                  }

                  this.sections.forEach((s) => {
                      const section = this.copySection(s);

                      if (section.length) out.push('', ...section);
                  });

                  {{-- The sign-off, and whatever the teacher wrote under the
                       asterisk rule above it — the teacher's own words are the
                       last thing the student reads. Each is pushed as one entry
                       so the line breaks they typed survive the join. --}}
                  const [message, extra] = this.splitComments();

                  if (extra.length) out.push('', ...extra);

                  if (message) out.push('', `[TEACHER’S MESSAGE FOR TODAY’S CLASS]`, message);

                  navigator.clipboard.writeText(out.join('\n'))
                      .then(() => window.notify('success', 'Report copied to clipboard.'))
                      .catch(() => window.notify('error', 'Could not copy — please select the text manually.'));
              },
          }">
        @csrf

        @if ($report)
            @method('PUT')
        @else
            <input type="hidden" name="student_id" value="{{ $student->id }}">
            <input type="hidden" name="class_date" value="{{ $date }}">
        @endif

        {{-- ─────────────────────────────────────── Lesson information ───── --}}
        <section class="card p-4">
            <h3 class="report-heading">Lesson Information</h3>

            <div class="mt-4 space-y-3">
                <div class="flex items-center gap-3">
                    <label for="today_lesson" class="report-pill-brand w-32 sm:w-40">Today's lesson</label>
                    <input id="today_lesson"
                           name="today_lesson"
                           type="text"
                           class="report-input"
                           x-model="today"
                           x-bind:disabled="locked"
                           placeholder="e.g. Can You Believe it 1 story 8 p35 (4)">
                </div>
                @error('today_lesson') <p class="form-error">{{ $message }}</p> @enderror

                <div class="flex items-center gap-3">
                    <label for="next_lesson" class="report-pill-accent w-32 sm:w-40">Next lesson</label>
                    <input id="next_lesson"
                           name="next_lesson"
                           type="text"
                           class="report-input"
                           x-model="next"
                           x-bind:disabled="locked"
                           placeholder="e.g. Can You Believe it 1 story 9 p38">
                </div>
                @error('next_lesson') <p class="form-error">{{ $message }}</p> @enderror
            </div>
        </section>

        {{-- ──────────────────────── Grammar / pronunciation / vocabulary ───── --}}
        @foreach (SessionReport::ROW_SECTIONS as $column => $section)
            @php
                $meta = $sectionMeta[$column];
                $input = $section['input'];
            @endphp

            <section class="card p-4">
                <h3 @class(['report-heading', '!text-accent-400' => $meta['tone'] === 'accent'])>
                    {{ $meta['title'] }}
                    <span class="report-hint">{{ $meta['hint'] }}</span>
                </h3>

                <div class="mt-4 space-y-4">
                    <template x-for="(row, index) in rows.{{ $input }}" x-bind:key="index">
                        <div class="report-row">
                            <button type="button"
                                    class="report-remove"
                                    x-on:click="remove('{{ $input }}', index)"
                                    x-bind:disabled="locked"
                                    aria-label="Remove this row">✕</button>

                            <div class="space-y-2">
                                @foreach ($meta['fields'] as $field => $f)
                                    <div class="flex items-start gap-3">
                                        <span class="report-pill-{{ $f['tone'] }} w-32 sm:w-44">{{ $f['label'] }}</span>

                                        @if ($f['rows'] > 1)
                                            <textarea class="report-input"
                                                      rows="{{ $f['rows'] }}"
                                                      x-bind:name="`{{ $input }}[${index}][{{ $field }}]`"
                                                      x-model="row.{{ $field }}"
                                                      x-bind:disabled="locked"
                                                      placeholder="{{ $f['placeholder'] }}"></textarea>
                                        @else
                                            <input type="text"
                                                   class="report-input"
                                                   x-bind:name="`{{ $input }}[${index}][{{ $field }}]`"
                                                   x-model="row.{{ $field }}"
                                                   x-bind:disabled="locked"
                                                   placeholder="{{ $f['placeholder'] }}">
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </template>
                </div>

                <button type="button"
                        class="report-add mt-4"
                        x-on:click="add('{{ $input }}')"
                        x-bind:disabled="locked">{{ $meta['add'] }}</button>
            </section>
        @endforeach

        {{-- ────────────────────────────────────────── Class evaluation ───── --}}
        <section class="card p-4">
            <h3 class="report-heading !text-accent-400">
                Class Evaluation
                <span class="report-hint">(Please select the scores for today's class, all scores are required)</span>
            </h3>

            <div class="mt-4 grid gap-3 sm:grid-cols-3 lg:grid-cols-5">
                @foreach (SessionReport::SCORE_FIELDS as $field => $label)
                    @php $value = old($field, $report?->{$field}); @endphp

                    <div class="text-center">
                        <label for="{{ $field }}" class="mb-2 block text-sm font-medium text-gray-200">
                            {{ $label }}
                        </label>

                        <select id="{{ $field }}"
                                name="{{ $field }}"
                                class="report-select"
                                x-model="scores.{{ $field }}"
                                x-bind:disabled="locked">
                            <option value="">Select</option>

                            @foreach (range(1, SessionReport::SCORE_MAX) as $score)
                                <option value="{{ $score }}">{{ $score }}</option>
                            @endforeach

                            {{-- A score saved while this form offered 1-10 stays
                                 selectable, so editing the report cannot silently
                                 blank it. --}}
                            @if ($value !== null && (int) $value > SessionReport::SCORE_MAX)
                                <option value="{{ (int) $value }}">{{ (int) $value }}</option>
                            @endif
                        </select>

                        @error($field) <p class="form-error">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
        </section>

        {{-- ───────────────────────────────────────── Teacher's comments ───── --}}
        <section class="card p-4">
            <h3 class="report-heading">
                Teacher's Comments
                <span class="report-hint">(Please type here any message or announcement you would like to share to the student)</span>
            </h3>

            <textarea name="teacher_comments"
                      rows="6"
                      class="report-input mt-4"
                      x-model="comments"
                      x-bind:disabled="locked"
                      placeholder="Please write at least 100 characters.

e.g. Hi Sage! Thank you for attending the class today. I admire your effort in studying English. Keep it up!

A few reminders for next week…
- Don't forget your journal!
- Quiz on Monday about tonight's lesson

Have a great weekend!"></textarea>

            <p class="mt-2 text-right text-xs text-gray-400">
                <span class="numeric font-semibold text-success-400" x-text="comments.length"></span> characters
            </p>

            @error('teacher_comments') <p class="form-error">{{ $message }}</p> @enderror
        </section>

        {{-- ──────────────────────────────────────────────── Action bar ───── --}}
        <div class="sticky bottom-0 flex flex-wrap items-center justify-end gap-3 rounded-card border border-gray-700/70 bg-gray-900/95 px-4 py-3 backdrop-blur">
            <button type="button" class="btn-primary" x-on:click="copyAll()">Copy All</button>

            @if ($report)
                <button type="button"
                        class="btn-secondary"
                        x-on:click="locked = ! locked"
                        x-text="locked ? 'Edit' : 'Cancel edit'"></button>
            @endif

            <button type="submit"
                    class="focus-ring rounded-lg bg-accent-500 px-6 py-2 text-sm font-bold text-white transition hover:bg-brand-400 hover:text-gray-900 disabled:opacity-40"
                    x-bind:disabled="locked">
                {{ $report ? 'Update Feedback' : 'Save Feedback' }}
            </button>
        </div>
    </form>
@endsection
