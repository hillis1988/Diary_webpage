# Implementation Plan: Summary JSON Payload

## Overview

This plan implements three related changes to the existing progress-summary feature: (1) replacing the free-text prompt with a structured, `JsonSerializable` `SummaryPayload` built from date-ordered `SummaryEntryContent`/`SummaryMilestoneContent`, sent verbatim as the LLM's user message; (2) growing the response contract to a `summary` narrative plus a four-field `CbtAdvice` object, with matching parsing/validation and a two-section `CBT_Notes_Card` rendering; and (3) restyling the trend bars and normalizing mood/sleep onto a shared 0-10 axis via a new `SharedTrendAxis` helper. Work proceeds bottom-up: value objects and JSON shapes first, then the prompt builder, then the HTTPS provider's transport/parsing, then the controller/view rendering, then CSS. Property-based tests (PHPUnit + Eris, per design.md's Testing Strategy) are added as sub-tasks immediately after the code they validate.

## Tasks

- [x] 1. Make `SummaryEntryContent` and `SummaryMilestoneContent` JSON-serializable
  - [x] 1.1 Implement `jsonSerialize()` on both classes
    - Add `implements JsonSerializable` and a `jsonSerialize()` method to `SummaryEntryContent` returning `date` (ISO), `mood_rating`, `sleep_quality` (null-preserving), `events`, `thoughts`, `emotions` - no existing constructor, field, or accessor changes
    - Add `implements JsonSerializable` and a `jsonSerialize()` method to `SummaryMilestoneContent` returning `date` (ISO), `category` (enum value), `description`
    - _Requirements: 1.2, 1.3, 1.7, 1.8, 10.1_

  - [x]* 1.2 Write unit tests for `SummaryEntryContent::jsonSerialize()` and `SummaryMilestoneContent::jsonSerialize()`
    - One example asserting the exact key set for each class, plus a null-sleep-quality example and an empty-string (`events`/`thoughts`/`emotions`) example that assert the key is present with value `null`/`""` rather than absent
    - _Requirements: 1.2, 1.3, 1.7, 1.8_

  - [x]* 1.3 Write property test for record content preservation
    - **Property 2: Records preserve their source content exactly, including null and empty fields**
    - **Validates: Requirements 1.2, 1.3, 1.7, 1.8**

- [x] 2. Create the `SummaryPayload` value object
  - [x] 2.1 Implement `SummaryPayload`
    - Add `src/Ai/SummaryPayload.php`: a final, immutable class implementing `JsonSerializable`, constructed from a `DateRange`, `TrendMetrics`, a list of `SummaryEntryContent`, and a list of `SummaryMilestoneContent`
    - Implement `jsonSerialize()` returning `date_range` (`start`/`end` ISO strings), `trend_metrics.mood`/`trend_metrics.sleep` (each `count`, `mean`, `min`, `max`, `direction`, `scale.min`/`scale.max`, with `mean`/`min`/`max` as `null` when `count === 0`), `entries`, and `milestones` (the given lists, each element already `JsonSerializable`)
    - Use `QuestionSet::MOOD_MIN`/`MOOD_MAX`/`SLEEP_MIN`/`SLEEP_MAX` for the `scale` fields
    - _Requirements: 1.1, 1.5, 1.6, 3.4, 10.2, 10.3_

  - [x]* 2.2 Write property test for the payload's four required parts
    - **Property 1: The Summary_Payload always carries all four required parts**
    - **Validates: Requirements 1.1, 1.5, 1.6**

  - [x]* 2.3 Write property test for the payload's closed, non-identifying shape
    - **Property 4: The Summary_Payload never carries a field outside its closed, non-identifying shape**
    - **Validates: Requirements 10.1, 10.2, 10.3**

- [x] 3. Rewrite `SummaryPromptBuilder` to build the payload and the new system prompt
  - [x] 3.1 Implement `buildPayload()` and the new `systemPrompt()`
    - Remove `userPrompt(SummaryInput): string` and its private helpers (`seriesLine()`, `entryLine()`, `milestoneLine()`); confirm no other caller references it
    - Add `buildPayload(SummaryInput $input): SummaryPayload`, sorting `$input->entries()` and `$input->milestones()` ascending by date via a stable `usort()` (PHP 8's `usort()` is stable) before constructing the `SummaryPayload`
    - Rewrite `systemPrompt()` with the fixed wording from design.md covering: timeline framing and chronological-order reasoning instruction, the strict-JSON two-top-level-field contract (`summary` string; `advice` object with exactly `pattern`, `distortions`, `balanced_perspective`, `next_action`), the multi-sentence requirements for `summary`/`pattern`/`balanced_perspective`/`next_action`, the distortions-or-explicit-none instruction, the CBT-grounding instruction, the "do not invent statistics" instruction, and the "do not request identifying information" instruction
    - _Requirements: 1.4, 3.1, 3.2, 3.3, 3.4, 3.5, 4.1, 4.2, 4.3, 4.4, 4.5_

  - [x]* 3.2 Write unit test for `SummaryPromptBuilder::systemPrompt()`'s fixed wording
    - One example asserting each required phrase/instruction from Requirements 3.1-3.5 and 4.1-4.5 is present in the returned string
    - _Requirements: 3.1, 3.2, 3.3, 3.4, 3.5, 4.1, 4.2, 4.3, 4.4, 4.5_

  - [x]* 3.3 Write property test for chronological ordering with tie-stability
    - **Property 3: Entries and milestones are ordered chronologically, stable on ties**
    - **Validates: Requirements 1.4**
    - Generator produces entry/milestone lists with deliberately repeated dates and a shuffled input order

- [x] 4. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 5. Create the `CbtAdvice` value object and grow `ProgressSummary`
  - [x] 5.1 Implement `CbtAdvice` and update `ProgressSummary`
    - Add `src/Ai/CbtAdvice.php`: a final, immutable, four-field class (`pattern`, `distortions`, `balancedPerspective`, `nextAction`) with matching accessors
    - Modify `src/Ai/ProgressSummary.php`: add a `CbtAdvice` constructor parameter and `advice(): CbtAdvice` accessor between the existing `narrative` and `metrics` parameters; keep `narrative()` and `metrics()` unchanged
    - _Requirements: 5.4_

  - [x]* 5.2 Write unit tests for `CbtAdvice` and `ProgressSummary`
    - Construct each and assert every accessor returns exactly the constructed value
    - _Requirements: 5.4_

- [x] 6. Rewrite `HttpsSummaryProvider`'s request building to move JSON encoding inside the retry loop
  - [x] 6.1 Move payload encoding inside the attempt loop
    - Build the `SummaryPayload` once via `promptBuilder->buildPayload($input)` before the loop
    - Add a private `buildRequestBody(SummaryPayload $payload): string` that `json_encode`s the payload as the user message content (`JSON_THROW_ON_ERROR`) and wraps it in the `{ model, response_format, messages: [system, user] }` envelope, itself `json_encode`d with `JSON_THROW_ON_ERROR`
    - Move the call to `buildRequestBody()` inside the `for` loop's `try` block, alongside the transport call and response parsing, so a `JsonException` from either `json_encode` call is caught as a failed attempt
    - Update the loop's `catch` clause to catch `JsonException|HttpTransportException|ProviderError`
    - _Requirements: 2.1, 2.2, 2.3_

  - [x]* 6.2 Write property test for the JSON transport's user message content
    - **Property 5: The JSON transport sends exactly the payload's encoding as the user message**
    - **Validates: Requirements 2.1, 2.2**

- [x] 7. Rewrite `HttpsSummaryProvider::parseSummary()` for the two-section response contract
  - [x] 7.1 Implement expanded parsing and validation
    - Parse `summary` from the decoded message content, requiring a non-empty string; throw `ProviderError` otherwise
    - Parse `advice` as an object/array, requiring `pattern`, `distortions`, `balanced_perspective`, `next_action` to each be present as non-empty strings; throw `ProviderError` naming the missing/invalid field otherwise
    - Return `new ProgressSummary($summary, new CbtAdvice(...), $metrics)` on success
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5_

  - [x]* 7.2 Write property test for valid-response parsing
    - **Property 7: A valid response parses into a Progress_Summary carrying exactly its own fields and that request's metrics**
    - **Validates: Requirements 5.1, 5.4**

  - [x]* 7.3 Write property test for the retry-once-then-fail behaviour across all failure modes
    - **Property 6: Every attempt failure is retried exactly once, and only a second failure raises ProviderError**
    - **Validates: Requirements 2.3, 5.2, 5.3, 5.5, 13.1, 13.2, 13.3**
    - Generator produces, for each of the two attempt slots, one of: success with a valid randomly-generated response, or one of the five failure shapes (transport exception, non-2xx status, non-JSON body, JSON-encoding failure via invalid-UTF-8 generated entry content, or a contract-invalid body missing/blanking one of the five required string fields chosen at random)

- [x] 8. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 9. Create the `SharedTrendAxis` helper
  - [x] 9.1 Implement `SharedTrendAxis::positionOf()`
    - Add `src/Ai/SharedTrendAxis.php`: a final class with a static `positionOf(int|float $value, int $nativeMin, int $nativeMax): float` returning `($value - $nativeMin) / ($nativeMax - $nativeMin) * 10`, clamped to `[0, 10]`, and returning `0.0` when the native span is `<= 0`
    - _Requirements: 8.1, 8.2_

  - [x]* 9.2 Write property test for shared-axis positioning
    - **Property 12: A value's position on the Shared_Trend_Axis is its proportional native-scale position scaled to 0-10**
    - **Validates: Requirements 8.1, 8.2**
    - Generator produces arbitrary native scales `[min, max]` with `max > min` (including mood's `[1, 10]` and sleep's `[1, 5]`) and values within them

- [x] 10. Update `SummaryController`'s trend-bar rendering to use `SharedTrendAxis` and native-scale-suffixed labels
  - [x] 10.1 Route the trend bar's fill/mean positioning through `SharedTrendAxis` and add scale suffixes to labels
    - Replace `percentOfScale()` calls in `renderTrendBar()` with `SharedTrendAxis::positionOf($value, $scaleMin, $scaleMax) / 10 * 100` for the fill's left/width and the mean marker's left position
    - Remove the now-unused `percentOfScale()` private method
    - Change the numeric min/mean/max labels rendered in `renderSeries()`/`renderTrendBar()` to append `/10` for the mood series and `/5` for the sleep series (read from `QuestionSet::MOOD_MAX`/`QuestionSet::SLEEP_MAX`), while continuing to render each metric's own native-scale value (never a shared-axis figure)
    - Keep `renderTrendBar()`'s existing "no series data" short-circuit (returns `''` when `count() === 0`) unchanged
    - _Requirements: 7.5, 8.3, 8.4, 8.5_

  - [x]* 10.2 Write property test for numeric label correctness
    - **Property 13: Trend_Bar numeric labels always show the native-scale value with its own metric's suffix, never the normalized axis position**
    - **Validates: Requirements 7.5, 8.3, 8.4**

  - [x]* 10.3 Write property test for non-empty series always rendering fill, mean marker, and direction badge
    - **Property 10: A non-empty series' Trend_Bar always renders its fill, mean marker, and direction badge**
    - **Validates: Requirements 7.2**

  - [x]* 10.4 Write property test for distinct direction badge styling
    - **Property 11: Each TrendDirection value renders with its own distinct badge styling hook**
    - **Validates: Requirements 7.4**

- [x] 11. Add the two-section `CBT_Notes_Card` rendering to `SummaryController`
  - [x] 11.1 Implement `renderCbtNotesCard()`, its fallback, and wire them into `renderSummary()`
    - Add a private `renderCbtNotesCard(ProgressSummary $summary): string` rendering the fixed heading, divider, a `cbt-notes__section` labelled "Summary" wrapping the HTML-escaped narrative, and a `cbt-notes__section` labelled "Advice" containing four `cbt-notes__advice-part` blocks in order - "Pattern", "Cognitive distortions", "Balanced perspective", "Next step" - each wrapping its HTML-escaped `CbtAdvice` field, reusing the existing `cbt-notes`, `cbt-notes__divider`, `cbt-notes__heading`, `cbt-notes__section`, `cbt-notes__label` classes from `FeedbackView::renderNotes()`
    - Add a private `renderNotesCardFallback(): string` returning a fixed error message in the same outer structure
    - Update `renderSummary()` to call `renderCbtNotesCard()` wrapped in `try`/`catch (\Throwable)`, falling back to `renderNotesCardFallback()` on failure, then always appending `renderMetrics()`
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.6_

  - [x]* 11.2 Write unit test for reused CBT_Notes_Card CSS classes
    - Assert the rendered card markup contains `cbt-notes`, `cbt-notes__divider`, `cbt-notes__heading`, `cbt-notes__section`, `cbt-notes__label`
    - _Requirements: 6.3_

  - [x]* 11.3 Write unit test for the forced-rendering-failure fallback
    - Inject a failure double that throws while rendering the notes card; assert the fallback message renders in its place while metrics and the disclaimer still render
    - _Requirements: 6.6_

  - [x]* 11.4 Write property test for the CBT_Notes_Card's content and label ordering
    - **Property 8: The CBT_Notes_Card renders the narrative and all four advice parts, each under its label, in order**
    - **Validates: Requirements 6.1, 6.2**
    - Generator includes narrative and advice text containing `<`, `>`, `&`, `"` to confirm escaping

  - [x]* 11.5 Write property test for outcome-based rendering contiguity
    - **Property 9: A Progress_Summary outcome renders the notes card, metrics, and disclaimer contiguously; any other outcome renders only its message**
    - **Validates: Requirements 6.4, 6.5**

- [x] 12. Checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 13. Restyle the trend bar and add Advice_Section CSS in `public/assets/app.css`
  - [x] 13.1 Update `.trend`/`.trend__track`/`.trend__fill`/`.trend__mean` rules and add Advice_Section rules
    - Update the existing `.trend`, `.trend__track`, `.trend__fill`, `.trend__mean` rules to the design-token-driven styling from design.md (surface/border/radius/padding on `.trend`; inset track shadow; accent-coloured fill; distinct thin mean-marker treatment), keeping `.trend__direction` and the `.badge--direction-*` rules' existing per-direction colour treatments
    - Add new, additive `.cbt-notes__advice-part` and `.cbt-notes__advice-label` rules for the four Advice_Section parts, without renaming or removing any existing class
    - _Requirements: 7.1, 7.3, 7.4_

- [x] 14. Wire and verify the full payload-to-render path
  - [x] 14.1 Update fixtures and confirm non-regression of the gate/precedence/ephemerality behaviour
    - Confirm `AiSummaryService::summarise()` requires no code changes (it depends only on `SummaryInput`, `SummaryProvider`, `ProviderError`, `SummaryOutcome`, all unchanged in shape) and update `tests/Unit/Ai/AiSummaryServiceTest.php` and `tests/Unit/Ai/FakeSummaryProvider.php` fixtures that construct `ProgressSummary` directly to pass a `CbtAdvice` value
    - Update `tests/Unit/Http/SummaryControllerTest.php` fixtures that construct `ProgressSummary` to supply a `CbtAdvice`
    - _Requirements: 9.1, 9.2, 11.1, 11.2, 12.1, 12.2_

  - [x]* 14.2 Write property test for the minimum-entry gate and outcome precedence
    - **Property 14: The 3-entry minimum gates payload building and provider invocation, and takes precedence over any provider outcome**
    - **Validates: Requirements 11.1, 11.2, 12.1, 12.2**

- [x] 15. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

## Amendment: Trend_Line_Chart replaces Trend_Bar

Following delivery of tasks 1-15, the user reviewed the rendered `Trend_Bar` (a minimum-maximum range fill with a mean marker) and requested it be replaced with a line chart plotting mood and sleep values over time, since a range bar communicates the observed spread poorly compared to seeing the actual trajectory of entries. This is a genuine scope change, not a bug fix: `requirements.md`'s Requirement 7.2 originally forbade exactly this ("SHALL NOT replace the Trend_Bar with a line chart... or any other chart type"). `requirements.md` and `design.md` were updated in place to describe the `Trend_Line_Chart` as the current, authoritative design rather than layering a contradictory addendum on top of the old wording; this task section is an addition documenting that amendment, and the historical checkboxes for tasks 1-15 above are left untouched as a record of what was originally built and delivered.

- [x] 16. Replace `Trend_Bar` with `Trend_Line_Chart`
  - [x] 16.1 Carry dated `TrendPoint`s through the trend-metrics pipeline
    - Add `src/Ai/TrendPoint.php`: a final, immutable, two-field class (`LocalDate $date`, `int $value`) with matching accessors - one dated data point for one series, from one Diary_Entry
    - Add an optional trailing `array $points = []` parameter (a `list<TrendPoint>`) to `SeriesStats::of(...)` and a `points(): array` accessor, so every existing positional call site continues to compile unchanged
    - Update `TrendCalculator::compute()`/`seriesStats()` to build a `list<TrendPoint>` per series (one per entry for mood; one per entry only where `sleepQuality()` is non-null for sleep, mirroring the existing value-array exclusion) in the same chronological order as the input entries, and pass it through to `SeriesStats::of(...)`
    - _Requirements: 7.2 (Trend_Point glossary term, Series_Stats definition)_

  - [x] 16.2 Replace `SummaryController::renderTrendBar()` with `renderLineChart()`
    - Render a server-rendered inline SVG (`viewBox="0 0 300 60"`) plotting each `TrendPoint` as a `.trend-chart__point` circle connected by a `.trend-chart__line` polyline, in chronological order, x-position proportional to each point's date within the plotted range (centred when every point shares one date or there is only one point), y-position via `SharedTrendAxis::positionOf()` inverted for SVG's downward-growing y axis
    - Render a dashed `.trend-chart__mean-line` at the mean's shared-axis position
    - Keep the existing numeric min/mean/max labels (native-scale value, `/10` or `/5` suffix) and the direction badge markup unchanged from the `Trend_Bar` implementation
    - Keep the "no series data" short-circuit (render nothing when `count() === 0`) unchanged
    - _Requirements: 7.2, 7.3, 7.4, 7.5, 8.1, 8.2, 8.3, 8.4, 8.5_

  - [x] 16.3 Restyle `public/assets/app.css` for the line chart
    - Remove `.trend__track`, `.trend__fill`, `.trend__mean`; add `.trend-chart`, `.trend-chart__svg`, `.trend-chart__line`, `.trend-chart__mean-line`, `.trend-chart__point`, drawing from the same `:root` design tokens as before
    - Keep `.trend`, `.trend__label`, `.trend__direction`, `.badge--direction-*` unchanged
    - _Requirements: 7.1, 7.3_

  - [x] 16.4 Update tests for the line chart
    - Rewrite `tests/Property/TrendBarNonEmptySeriesPropertyTest.php` to assert `.trend-chart__line`, `.trend-chart__mean-line`, and at least one `.trend-chart__point` render for a non-empty series, alongside the direction badge (Property 10, revised)
    - Re-verify `tests/Property/TrendBarNumericLabelPropertyTest.php` and `tests/Property/DirectionBadgeDistinctStylingPropertyTest.php` against `renderLineChart()`'s output (labelling and badge markup are unchanged, so these should continue to pass with no or minimal changes)
    - Add `tests/Unit/Http/SummaryControllerEmptySeriesChartTest.php` confirming an empty series renders none of `.trend-chart`, `.trend-chart__line`, `.trend-chart__point`, `.trend-chart__mean-line`
    - _Requirements: 7.2, 7.5, 8.5_

  - [x] 17. Checkpoint - Ensure all tests pass
    - Ensure all tests pass, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP.
- Each task references specific requirement clauses for traceability.
- Property tests use PHPUnit + Eris, 100+ iterations each, tagged `// Feature: summary-json-payload, Property N: ...`, per design.md's Testing Strategy - no property test makes a network call; `SummaryProvider`/`HttpTransport` are stubbed/spied through their existing interfaces.
- Fixed system-prompt wording (Requirements 3, 4), CSS class-name reuse (Requirement 6.3), CSS design-token/colour-distinctness judgement (Requirements 7.1, 7.3), and the forced-rendering-failure fallback (Requirement 6.6) are covered by unit/example tests, not property tests, per design.md's rationale.
- Task 14 intentionally has no new production code of its own beyond fixture updates - it exists to confirm the non-regression requirements (9, 11, 12) still hold once every other task's code is wired together, since `AiSummaryService`'s control flow is untouched by design.

## Task Dependency Graph

```json
{
  "waves": [
    { "id": 0, "tasks": ["1.1", "5.1", "9.1"] },
    { "id": 1, "tasks": ["1.2", "1.3", "2.1", "5.2", "9.2", "13.1"] },
    { "id": 2, "tasks": ["2.2", "2.3", "3.1"] },
    { "id": 3, "tasks": ["3.2", "3.3", "6.1"] },
    { "id": 4, "tasks": ["6.2", "7.1"] },
    { "id": 5, "tasks": ["7.2", "7.3", "10.1"] },
    { "id": 6, "tasks": ["10.2", "10.3", "10.4", "11.1"] },
    { "id": 7, "tasks": ["11.2", "11.3", "11.4", "11.5", "14.1"] },
    { "id": 8, "tasks": ["14.2"] }
  ]
}
```
