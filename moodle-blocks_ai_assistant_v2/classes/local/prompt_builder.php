<?php
namespace block_ai_assistant_v2\local;

defined('MOODLE_INTERNAL') || die();

class prompt_builder {
    public static function build_notes_prompt(array $context, string $usertext): string {
        $lines = [
            'You are an academic AI tutor for university-level and competitive exam preparation.',
            'Respond in well-structured markdown.',
            'Use concise headings, bullet points, worked explanation, and formula formatting when useful.',
            'Do not wrap the whole answer in code fences.',
            'If mathematics is needed, use standard LaTeX-style notation compatible with KaTeX.',
        ];

        if (!empty($context['course'])) {
            $lines[] = 'Course: ' . $context['course'];
        }
        if (!empty($context['subject'])) {
            $lines[] = 'Subject: ' . $context['subject'];
        }
        if (!empty($context['topic'])) {
            $lines[] = 'Topic: ' . $context['topic'];
        }
        if (!empty($context['lesson'])) {
            $lines[] = 'Lesson: ' . $context['lesson'];
        }

        $lines[] = 'Student request: ' . trim($usertext);
        return implode("\n", $lines);
    }

    public static function build_mcq_prompt(array $context, string $userprompt = ''): string {
        $course = trim((string)($context['course'] ?? ''));
        $subject = trim((string)($context['subject'] ?? ''));
        $topic = trim((string)($context['topic'] ?? ''));
        $lesson = trim((string)($context['lesson'] ?? ''));
        $count = (int)($context['count'] ?? 10);
        $difficulty = trim((string)($context['difficulty'] ?? 'medium'));

        return "You are a Moodle MCQ generator.\n"
            . "Course: {$course}\n"
            . "Subject: {$subject}\n"
            . "Topic: {$topic}\n"
            . "Lesson: {$lesson}\n"
            . "Difficulty: {$difficulty}\n"
            . "Question count: {$count}\n\n"
            . "Return valid JSON only in this exact structure: {\"questions\":[{\"question\":\"...\",\"options\":{\"A\":\"...\",\"B\":\"...\",\"C\":\"...\",\"D\":\"...\"},\"answer\":\"A\",\"explanation\":\"...\"}]}\n"
            . "Questions must be aligned with the supplied course context and suitable for student practice.\n"
            . "Keep one correct answer per question and provide short explanations.";
    }

}
