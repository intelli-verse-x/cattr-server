<?php

namespace App\Http\Requests\Interval;

use AllowDynamicProperties;
use App\Http\Requests\AuthorizesAfterValidation;
use App\Http\Requests\CattrFormRequest;
use App\Models\TimeInterval;
use App\Models\User;
use App\Rules\TimeIntervalDoesNotExist;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Settings;

#[AllowDynamicProperties] class CreateTimeIntervalRequest extends CattrFormRequest
{
    use AuthorizesAfterValidation;

    /**
     * Desktop clients sometimes send end_at equal to start_at (or the same wall-clock
     * second with different microseconds). Laravel's before:end_at compares at second
     * precision and rejects; overlap retries after a successful create then 422 and
     * the app shows a generic validation error. Normalize before rules run.
     */
    public static function normalizeIntervalBoundaries(?string $startRaw, ?string $endRaw, string $timezone): array
    {
        if ($startRaw === null || $endRaw === null || $startRaw === '' || $endRaw === '') {
            return [$startRaw ?? '', $endRaw ?? ''];
        }
        try {
            $s = Carbon::parse($startRaw)->setTimezone($timezone);
            $e = Carbon::parse($endRaw)->setTimezone($timezone);
            if ($s->getTimestamp() >= $e->getTimestamp()) {
                $e = $s->copy()->addSecond();
            }

            return [$s->toIso8601String(), $e->toIso8601String()];
        } catch (\Throwable) {
            return [$startRaw, $endRaw];
        }
    }

    protected function prepareForValidation(): void
    {
        $timezone = Settings::scope('core')->get('timezone', 'UTC');
        [$s, $e] = static::normalizeIntervalBoundaries(
            $this->input('start_at'),
            $this->input('end_at'),
            $timezone
        );
        if ($this->input('start_at') !== null && $this->input('end_at') !== null) {
            $this->merge(['start_at' => $s, 'end_at' => $e]);
        }
    }

    public function authorizeValidated(): bool
    {
        return $this->user()->can(
            'create',
            [
                TimeInterval::class,
                $this->get('user_id'),
                $this->get('task_id'),
                $this->get('is_manual', false),
            ],
        );
    }

    public function _rules(): array
    {
        $timezone = Settings::scope('core')->get('timezone', 'UTC');

        return [
            'task_id' => 'required|exists:tasks,id',
            'user_id' => 'required|exists:users,id',
            'start_at' => 'required|date|bail|before_or_equal:end_at',
            'end_at' => [
                'required',
                'date',
                'bail',
                'after_or_equal:start_at',
                new TimeIntervalDoesNotExist(
                    User::find($this->user_id),
                    Carbon::parse($this->start_at)->setTimezone($timezone),
                    Carbon::parse($this->end_at)->setTimezone($timezone),
                ),
            ],
            'activity_fill' => 'nullable|int|between:0,100',
            'mouse_fill' => 'nullable|int|between:0,100',
            'keyboard_fill' => 'nullable|int|between:0,100',
            'is_manual' => 'sometimes|bool',
            'location' => 'sometimes|array',
            'screenshot' => 'sometimes|required|image',
        ];
    }

    public function getRules($user_id, $start_at, $end_at): array
    {
        $this->user_id = $user_id;
        $this->start_at = $start_at;
        $this->end_at = $end_at;

        return $this->_rules();
    }

    /**
     * POST /api/time-intervals/create is retried on timeouts; the second hit overlaps
     * the row the first request already inserted. Return the existing row as success
     * instead of 422 so the desktop client does not drop the interval.
     */
    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors()->toArray();
        $endErr = $errors['end_at'] ?? [];
        $isOnlyOverlap = !empty($endErr)
            && count(array_keys($errors)) === 1
            && collect($endErr)->every(function ($msg) {
                $m = is_string($msg) ? $msg : '';

                return stripos($m, 'overlap') !== false
                    || stripos($m, 'time_interval') !== false
                    || stripos($m, 'already') !== false
                    || stripos($m, 'exist') !== false;
            });

        if ($isOnlyOverlap) {
            try {
                $timezone = Settings::scope('core')->get('timezone', 'UTC');
                $existing = TimeInterval::where('user_id', (int) $this->input('user_id'))
                    ->where('start_at', Carbon::parse($this->input('start_at'))->setTimezone($timezone))
                    ->where('end_at', Carbon::parse($this->input('end_at'))->setTimezone($timezone))
                    ->first();
                if ($existing) {
                    throw new HttpResponseException(responder()->success($existing)->respond());
                }
            } catch (HttpResponseException $e) {
                throw $e;
            } catch (\Throwable) {
                // fall through
            }
        }

        parent::failedValidation($validator);
    }
}
