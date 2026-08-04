<?php

declare(strict_types=1);

namespace Diary\Ai;

use Diary\Diary\QuestionSet;

/**
 * Builds the pseudonymised prompt sent to the LLM provider for CBT-style
 * feedback on one Diary_Entry (Requirement 6.1).
 *
 * The prompt is built exclusively from an {@see EntryContent}, which itself
 * carries no name, email address, account id, or any other identifier - so
 * this class has nothing to leak even by mistake. It never receives an
 * `OwnerId`, a `UserId`, or a raw `DiaryEntry`; that narrowing at the type
 * level is what keeps the prompt pseudonymised rather than relying on this
 * class remembering to omit fields.
 *
 * `systemPrompt()`'s wording is a condensed, single-shot distillation of the
 * fuller CBT agent persona kept in `agents/CBT_Agent` for reference: the same
 * grounding principles (facts vs. interpretations, validating emotion without
 * automatically validating its interpretation, credible balanced thinking
 * over forced positivity, no diagnosing or medical claims, not every
 * difficulty is a thinking error) but with the multi-turn, guided-discovery
 * conversational process stripped out, since this call gets exactly one
 * exchange - one diary entry in, one JSON recommendation out - never a
 * follow-up question or a continued dialogue.
 *
 * Length and warmth matter here: a one-line reply can feel curt in a diary
 * centred on CBT support, so both JSON fields are asked for as short
 * paragraphs rather than terse slogans.
 */
final class PromptBuilder
{
    /**
     * The fixed system instruction: the response contract every provider call
     * relies on, asking for strict JSON with exactly the two expected fields.
     */
    public function systemPrompt(): string
    {
        return 'You are a CBT-informed wellbeing assistant giving a single, one-shot review of one '
            . 'day\'s diary entry. Write in a warm, respectful, human tone - never curt, dismissive, '
            . 'or clipped. Ground your response in Cognitive Behavioural Therapy: distinguish facts '
            . 'from interpretations, validate the person\'s emotion without automatically validating '
            . 'the interpretation producing it, and favour credible, evidence-based balanced thinking '
            . 'over forced positivity or empty reassurance. Not every difficulty is a thinking error '
            . '- some things are genuinely hard - so do not force a cognitive reframe where none fits. '
            . 'You are not a doctor, therapist, or emergency service: do not diagnose conditions, '
            . 'reference medication, or claim certainty about the person\'s situation. Respond with '
            . 'strict JSON only - no markdown, no commentary, no surrounding text - containing exactly '
            . 'two string fields: "positive_focus" and "suggested_change". Write "positive_focus" as '
            . 'a short paragraph of three to five sentences: name something specific and grounded in '
            . 'the entry that is worth holding onto, explain gently why it matters for wellbeing, and '
            . 'leave the person feeling seen rather than summarised. Write "suggested_change" as a '
            . 'short paragraph of three to five sentences: one small, credible, CBT-informed change '
            . 'they could try, with enough context that it feels supportive and practical rather than '
            . 'a blunt instruction. Do not add any other fields, ask a question, or invite further '
            . 'conversation - this is a single response, not the start of a dialogue. Do not ask for, '
            . 'guess, or reference the person\'s name, email address, or any other identifying '
            . 'information; you are only ever given the content of a single entry.';
    }

    /**
     * The pseudonymised entry content, labelled with the same question
     * prompts and scale descriptions the diary form itself uses, so the
     * question set stays the single source of truth (Requirement 5.1).
     */
    public function userPrompt(EntryContent $content): string
    {
        $lines = [];

        $moodQuestion = QuestionSet::moodRating();
        $lines[] = sprintf(
            '%s: %d out of %d (scale: %s)',
            $moodQuestion->prompt(),
            $content->moodRating(),
            $moodQuestion->scaleMax(),
            $moodQuestion->scaleDescription(),
        );

        if ($content->sleepQuality() !== null) {
            $sleepQuestion = QuestionSet::sleepQuality();
            $lines[] = sprintf(
                '%s: %d out of %d (scale: %s)',
                $sleepQuestion->prompt(),
                $content->sleepQuality(),
                $sleepQuestion->scaleMax(),
                $sleepQuestion->scaleDescription(),
            );
        }

        if ($content->events() !== '') {
            $lines[] = sprintf('%s %s', QuestionSet::byField(QuestionSet::EVENTS)->prompt(), $content->events());
        }

        if ($content->thoughts() !== '') {
            $lines[] = sprintf('%s %s', QuestionSet::byField(QuestionSet::THOUGHTS)->prompt(), $content->thoughts());
        }

        if ($content->emotions() !== '') {
            $lines[] = sprintf('%s %s', QuestionSet::byField(QuestionSet::EMOTIONS)->prompt(), $content->emotions());
        }

        return implode("\n", $lines);
    }
}
