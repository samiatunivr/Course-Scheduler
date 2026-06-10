<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database as DB;

/**
 * Enrollment forecasting. Uses weighted linear trend over enrollment
 * history (recent terms weigh more) including waitlist as latent demand.
 */
final class ForecastService
{
    /** Forecast every course with history for a target term. */
    public function forecastTerm(int $termId, bool $persist = true): array
    {
        $courses = DB::select(
            'SELECT DISTINCT c.id, c.code FROM courses c
             JOIN enrollment_history eh ON eh.course_id = c.id
             WHERE c.is_active = 1'
        );

        $results = [];
        foreach ($courses as $course) {
            $prediction = $this->forecastCourse((int) $course['id']);
            if ($prediction === null) {
                continue;
            }
            $results[] = [
                'course_id' => (int) $course['id'],
                'course' => $course['code'],
                'predicted' => $prediction['value'],
                'basis' => $prediction['basis'],
            ];
            if ($persist) {
                DB::execute(
                    'INSERT INTO enrollment_forecasts (course_id, term_id, predicted_enrollment, method)
                     VALUES (?, ?, ?, "trend")
                     ON DUPLICATE KEY UPDATE predicted_enrollment = VALUES(predicted_enrollment),
                                             generated_at = NOW()',
                    [(int) $course['id'], $termId, $prediction['value']]
                );
            }
        }

        return $results;
    }

    /** @return array{value:int, basis:string}|null */
    public function forecastCourse(int $courseId): ?array
    {
        $history = DB::select(
            'SELECT eh.enrolled, eh.waitlisted, t.start_date
             FROM enrollment_history eh JOIN terms t ON t.id = eh.term_id
             WHERE eh.course_id = ?
             ORDER BY t.start_date ASC',
            [$courseId]
        );
        if ($history === []) {
            return null;
        }

        // Latent demand = enrolled + waitlisted.
        $points = array_map(
            fn ($h) => (int) $h['enrolled'] + (int) $h['waitlisted'],
            $history
        );
        $n = count($points);

        if ($n === 1) {
            return ['value' => $points[0], 'basis' => 'single term of history (enrolled + waitlist)'];
        }

        // Weighted least-squares slope, weights 1..n favoring recent terms.
        $sumW = $sumWX = $sumWY = $sumWXY = $sumWXX = 0.0;
        foreach ($points as $i => $y) {
            $w = $i + 1;
            $sumW += $w;
            $sumWX += $w * $i;
            $sumWY += $w * $y;
            $sumWXY += $w * $i * $y;
            $sumWXX += $w * $i * $i;
        }
        $denominator = $sumW * $sumWXX - $sumWX ** 2;
        $slope = $denominator != 0.0 ? ($sumW * $sumWXY - $sumWX * $sumWY) / $denominator : 0.0;
        $intercept = ($sumWY - $slope * $sumWX) / $sumW;
        $predicted = max(0, (int) round($intercept + $slope * $n));

        return [
            'value' => $predicted,
            'basis' => sprintf('weighted trend over %d terms (slope %+.1f/term)', $n, $slope),
        ];
    }
}
