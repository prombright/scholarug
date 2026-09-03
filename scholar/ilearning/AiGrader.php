<?php
declare(strict_types=1);

require_once __DIR__ . '/../../ai/Claude.php';

/**
 * Grades a short-answer response against a teacher-supplied model answer
 * (+ optional rubric) via Claude. Best-effort: returns null on ANY
 * failure (network error, non-200, unparseable response) rather than
 * throwing, so the caller can gracefully fall back to manual grading for
 * just this one answer instead of failing the whole submission -- see
 * scholar/ilearning/take_assessment.php's finalize logic.
 */
final class AiGrader
{
    /**
     * @return array{score:int,feedback:string}|null
     */
    public static function gradeShortAnswer(
        string $question,
        string $modelAnswer,
        ?string $rubric,
        string $studentAnswer
    ): ?array {
        $system = 'You are grading a student\'s short-answer response for a school in Uganda. '
            . 'Compare the student\'s answer to the model answer and rubric (if given), judging '
            . 'on meaning and correctness, not exact wording -- reasonable paraphrasing that '
            . 'captures the same idea should score well. '
            . 'Respond with ONLY a single JSON object, nothing else -- no markdown fences, no '
            . 'preamble, no explanation outside the JSON. The object must have exactly two keys: '
            . '"score" (an integer from 0 to 100) and "feedback" (a short, one-or-two sentence '
            . 'correction or encouragement addressed directly to the student, pointing out what '
            . 'was missing or wrong if the score is not 100).';

        $rubricLine = $rubric !== null && trim($rubric) !== '' ? "Rubric / key points: {$rubric}\n" : '';
        $userMessage = "Question: {$question}\nModel answer: {$modelAnswer}\n{$rubricLine}"
            . "Student's answer: {$studentAnswer}";

        try {
            $raw = Claude::call($system, $userMessage, 300);
        } catch (Throwable $e) {
            error_log('AiGrader: Claude call failed: ' . $e->getMessage());
            return null;
        }

        // Defensive: strip a ```json ... ``` fence if the model wraps its
        // answer in one despite the system prompt asking it not to.
        $raw = trim($raw);
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```[a-zA-Z]*\n?/', '', $raw);
            $raw = preg_replace('/\n?```$/', '', $raw);
            $raw = trim($raw);
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || !isset($data['score'], $data['feedback'])) {
            error_log('AiGrader: unparseable response: ' . $raw);
            return null;
        }

        $score = (int) $data['score'];
        if ($score < 0 || $score > 100) {
            error_log('AiGrader: score out of range: ' . $raw);
            return null;
        }

        return ['score' => $score, 'feedback' => (string) $data['feedback']];
    }
}
