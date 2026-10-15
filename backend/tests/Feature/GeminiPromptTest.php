<?php

namespace Tests\Feature;

use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The AI prompts carry the rules the whole scan rests on: the quarter-step mark
 * scale, the row-by-row reading, and the context of the exact control/exam being
 * scanned. These tests lock the wording so a later edit cannot silently drop a
 * rule and let a 14,7 through again.
 */
class GeminiPromptTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // One configured key is enough for tryKeys() to attempt a call, which the
        // fake below intercepts before any network access.
        config()->set('services.gemini.key_formateur', 'test-key');
        config()->set('services.gemini.key_students', '');
        config()->set('services.gemini.key_unites', '');
        config()->set('services.gemini.key_trial', '');
        config()->set('services.gemini.ca_bundle', '');
    }

    private function fakeGemini(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => '[]']]]],
                ],
            ], 200),
        ]);
    }

    /** The prompt text actually sent to the model on the first captured call. */
    private function sentPrompt(): string
    {
        $recorded = Http::recorded();
        $this->assertNotEmpty($recorded, 'No request was sent to Gemini.');

        $data = $recorded[0][0]->data();

        return (string) ($data['contents'][0]['parts'][0]['text'] ?? '');
    }

    public function test_notes_prompt_carries_the_control_context_and_the_quarter_rule(): void
    {
        $this->fakeGemini();

        app(GeminiService::class)->scanNotes(base64_encode('%PDF-1.4'), 'CONTEXTE_UNIQUE_ABC123');

        $prompt = $this->sentPrompt();

        $this->assertStringContainsString('CONTEXTE_UNIQUE_ABC123', $prompt);
        $this->assertStringContainsStringIgnoringCase('quarter step', $prompt);
        $this->assertStringContainsString('0.25', $prompt);
        $this->assertStringContainsString('ROW BY ROW', $prompt);
        // A non-quarter read must be reported as seen, not rounded.
        $this->assertStringContainsString('14.9', $prompt);
        $this->assertStringContainsString('never round', mb_strtolower($prompt));
    }

    public function test_examens_prompt_carries_the_exam_context_and_the_quarter_rule(): void
    {
        $this->fakeGemini();

        app(GeminiService::class)->scanExamens([base64_encode('%PDF-1.4')], 'EXAMEN_UNIQUE_XYZ789');

        $prompt = $this->sentPrompt();

        $this->assertStringContainsString('EXAMEN_UNIQUE_XYZ789', $prompt);
        $this->assertStringContainsStringIgnoringCase('quarter step', $prompt);
        $this->assertStringContainsString('ROW BY ROW', $prompt);
    }

    public function test_cin_prompt_spells_out_the_field_rules(): void
    {
        $this->fakeGemini();

        app(GeminiService::class)->scanCin(base64_encode('%PDF-1.4'));

        $prompt = $this->sentPrompt();

        // Field-by-field instructions the normalizer relies on.
        $this->assertStringContainsString('date_naissance', $prompt);
        $this->assertStringContainsString('cin_ar', $prompt);
        $this->assertStringContainsString('Never invent a CIN', $prompt);
        // A blurry card must yield an empty value, never a guessed number.
        $this->assertStringContainsString('empty', $prompt);
    }
}
