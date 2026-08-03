# Requirements Document

## Introduction

This document defines requirements for modifications to the existing AI-generated progress summary feature on the Diary_App's summary page. Today, `AiSummaryService::summarise()` gathers pseudonymised diary entry and milestone content for a user-selected date range, computes trend metrics deterministically, and sends a prompt to the LLM provider, which returns content rendered on the summary page alongside the computed trend metrics.

This document covers three related changes to that feature:

1. **Structured JSON payload.** The outgoing free-text prompt is replaced with a structured JSON payload that carries each diary entry and milestone alongside its own date, explicitly organised as a date-ordered timeline so the LLM can reason about improvement or decline across the range rather than treating entries as isolated facts (Requirements 1-3).
2. **Richer, two-section AI content.** The LLM's response contract is deepened and split into two rendered sections. A "Summary" section keeps the descriptive progress narrative - what happened, how the metrics moved - now written with real depth across multiple sentences rather than a single terse sentence. A separate "Advice" section is explicitly grounded in Cognitive Behavioural Therapy (the same grounding discipline already used for one-shot diary-entry feedback: facts vs. interpretations, validating emotion without validating interpretation, credible balanced thinking over forced positivity, no diagnosing, not every difficulty is a thinking error) and is broken into four labelled parts: a pattern or formulation observed across the date range, any cognitive distortions noticed (or an explicit statement that none were), a balanced and credible alternative perspective, and one concrete next action. The response contract therefore grows from a single narrative string to a narrative plus a structured, four-part advice object, and the parsing, prompt-building, outcome, and rendering layers all change to carry that two-section shape (Requirements 4-6).
3. **Trend line chart and shared axis.** The mood and sleep trend visualisations are replaced with a server-rendered inline SVG line chart plotting each series' values over time by entry date, styled consistent with the Diary_App's existing dark editorial theme, and normalized so both metrics render against a common 0-to-10 axis rather than each metric's own native scale, while the numeric labels shown to the Primary_User remain truthful to each metric's own real, measured range. The direction badge is kept; the previous minimum-maximum range-bar visual is replaced by the line chart (Requirements 7-8).

This remains a payload-format, response-contract, and presentation change to an existing feature, not a new endpoint or new data flow. The summary remains ephemeral (never persisted), the payload remains built exclusively from pseudonymised content, the minimum-entry gate and outcome precedence are unchanged, and the provider's retry-once-then-fail behaviour is unchanged for the expanded response shape (Requirements 9-13).

## Glossary

- **Diary_App**: The complete web-based mental health diary application, including its user interface, application logic, and data storage.
- **Storage_Service**: The component responsible for persisting application data securely at rest.
- **AI_Summary_Service**: The component that gathers pseudonymised diary entry and milestone content for a date range, computes trend metrics, and coordinates with a Summary_Provider to produce a progress summary.
- **Summary_Provider**: The interface through which AI_Summary_Service requests a progress summary from an LLM.
- **Https_Summary_Provider**: The HTTPS adapter implementing Summary_Provider that sends the Summary_Payload to the LLM and parses its response.
- **Summary_Prompt_Builder**: The component that builds the Summary_Payload and the system prompt instructing the LLM how to use it.
- **Summary_Payload**: The structured JSON object sent to the Summary_Provider for one `summarise()` call, containing the selected date range, computed Trend_Metrics, an ordered list of Summary_Entry_Records, and an ordered list of Summary_Milestone_Records.
- **Summary_Entry_Record**: One JSON object within the Summary_Payload's entries list, carrying a single Diary_Entry's date together with its pseudonymised mood rating, sleep quality, events, thoughts, and emotions content.
- **Summary_Milestone_Record**: One JSON object within the Summary_Payload's milestones list, carrying a single Milestone's date together with its pseudonymised category and description content.
- **Trend_Metrics**: The mood and sleep Series_Stats (count, mean, minimum, maximum, direction) computed deterministically from Diary_Entry records, never computed by the LLM.
- **Series_Stats**: The per-metric statistics (count, mean, minimum, maximum, direction, and an ordered list of Trend_Points) computed for one series - mood rating or sleep quality - within Trend_Metrics.
- **Trend_Point**: One dated data point within a Series_Stats: a single Diary_Entry's date together with its value for that series (mood rating or sleep quality) on the series' own Native_Scale.
- **Trend_Line_Chart**: The server-rendered inline SVG line-chart visual for one Series_Stats: a line and point markers plotting that series' Trend_Points over time (earliest date at the left edge, latest at the right edge), a dashed reference line at the mean's position on the Shared_Trend_Axis, and a badge naming the trend direction.
- **Shared_Trend_Axis**: The fixed 0-to-10 scale that both the mood Trend_Line_Chart and the sleep Trend_Line_Chart position their plotted values against, so the two metrics can be compared visually despite having different Native_Scales.
- **Native_Scale**: A metric's own measurement scale used by the Diary_App's diary entry questions: 1 to 10 for mood rating, 1 to 5 for sleep quality. A Trend_Line_Chart's numeric labels are always expressed in a metric's Native_Scale, never in Shared_Trend_Axis units.
- **Progress_Summary**: The Summary_Narrative, the CBT_Advice, and the Trend_Metrics returned when a Summary_Provider request succeeds.
- **Summary_Narrative**: The multi-sentence descriptive text within a Progress_Summary describing how the Primary_User's mood and sleep metrics moved across the selected date range.
- **CBT_Advice**: The structured, CBT-grounded advice within a Progress_Summary, comprising a Pattern_Observation, a Distortion_Note, a Balanced_Perspective, and a Next_Action.
- **Pattern_Observation**: The CBT_Advice field describing, as a short paragraph, the pattern or formulation the Summary_Provider observes across the selected date range's entries.
- **Distortion_Note**: The CBT_Advice field naming, as a short paragraph, any cognitive distortions the Summary_Provider identifies across the selected date range's entries, or explicitly stating that none were identified.
- **Balanced_Perspective**: The CBT_Advice field offering, as a short paragraph, a balanced and credible alternative perspective on the pattern described in the Pattern_Observation.
- **Next_Action**: The CBT_Advice field describing, as a short paragraph, one concrete action the Primary_User could take next.
- **Summary_Outcome**: The result of `AI_Summary_Service::summarise()`: a Progress_Summary, InsufficientData, or Unavailable.
- **CBT_Notes_Card**: The shared visual presentation (heading, divider, and labelled section styling) already used to display AI-generated CBT-style content, such as the existing "AI CBT Therapist's notes" card.
- **Summary_Section**: The labelled section of the CBT_Notes_Card presenting a Progress_Summary's Summary_Narrative.
- **Advice_Section**: The labelled section of the CBT_Notes_Card presenting a Progress_Summary's CBT_Advice, broken into four labelled parts.
- **Diary_Entry**: A single dated record containing the Primary_User's structured responses for a given day.
- **Milestone**: A user-recorded significant life event associated with a date.
- **OwnerId**: The internal identifier of the account whose diary data is being summarised.

## Requirements

### Requirement 1: Structured JSON Payload Shape

**User Story:** As the AI_Summary_Service, I want diary entries and milestones sent to the LLM as a structured JSON payload with each item's own date attached, so that the LLM can see a timeline rather than a flat list of disconnected facts.

#### Acceptance Criteria

1. WHEN AI_Summary_Service invokes the Summary_Provider, THE Summary_Prompt_Builder SHALL build a Summary_Payload containing the selected date range, the computed Trend_Metrics, an ordered list of Summary_Entry_Records, and an ordered list of Summary_Milestone_Records.
2. THE Summary_Prompt_Builder SHALL represent each Diary_Entry within the Summary_Payload as a Summary_Entry_Record carrying that entry's date together with its mood rating, sleep quality, events, thoughts, and emotions content.
3. THE Summary_Prompt_Builder SHALL represent each Milestone within the Summary_Payload as a Summary_Milestone_Record carrying that milestone's date together with its category and description content.
4. THE Summary_Prompt_Builder SHALL order the Summary_Entry_Records and the Summary_Milestone_Records within the Summary_Payload in ascending (earliest-first) chronological order by date, preserving original retrieval order among records sharing the same date.
5. WHERE no Milestone records exist within the selected date range, THE Summary_Prompt_Builder SHALL represent the Summary_Payload's milestones list as an empty list.
6. WHERE no Diary_Entry records exist within the selected date range at the point the Summary_Payload is built, THE Summary_Prompt_Builder SHALL represent the Summary_Payload's entries list as an empty list.
7. WHERE a Diary_Entry's sleep quality question was left unanswered, THE Summary_Prompt_Builder SHALL represent that Summary_Entry_Record's sleep quality field as null rather than omitting the field.
8. WHERE a Diary_Entry's events, thoughts, or emotions content is an empty string, THE Summary_Prompt_Builder SHALL represent that Summary_Entry_Record's corresponding field as an empty string rather than omitting the field or substituting placeholder text.

### Requirement 2: JSON Transport to the LLM

**User Story:** As the Https_Summary_Provider, I want to send the Summary_Payload to the LLM as JSON rather than as formatted free text, so that the structure of the timeline survives transport intact.

#### Acceptance Criteria

1. WHEN AI_Summary_Service invokes the Summary_Provider, THE Https_Summary_Provider SHALL encode the Summary_Payload as JSON text and send that JSON text as the entirety of the user message content, with no additional free-text lines outside the JSON structure.
2. THE Https_Summary_Provider SHALL send the Summary_Payload's JSON encoding as the entirety of the user message content, with no additional free-text lines outside the JSON structure. This criterion imposes no constraint on the user message content in the case where encoding fails (Criterion 3 governs that case instead).
3. IF the Summary_Payload cannot be encoded as JSON text because it contains invalid content (for example, a value that is not valid UTF-8), THEN THE Https_Summary_Provider SHALL treat that as a failed attempt and retry exactly once - meaning one additional attempt beyond the original, for a total of two attempts - following the same retry-once-then-fail behaviour already defined for a failed request attempt, and SHALL raise a ProviderError exactly when that retry limit of two total attempts is reached without a successful encoding.

### Requirement 3: Prompt Instructions for Timeline Reasoning

**User Story:** As a user, I want the AI's summary to reason about the whole date range using the order of my entries and milestones, so that it can offer insight into improvement or decline rather than describing isolated days.

#### Acceptance Criteria

1. THE Summary_Prompt_Builder SHALL state, within the system prompt sent to the Summary_Provider, that the Summary_Payload's entries and milestones are provided as a timeline ordered chronologically from earliest to latest date.
2. THE Summary_Prompt_Builder SHALL instruct, within the system prompt, that the Summary_Provider must use the chronological order of the Summary_Payload's entries and milestones to assess whether the trends across the date range show improvement, decline, or stability.
3. THE Summary_Prompt_Builder SHALL instruct, within the system prompt, that the Summary_Provider must respond with strict JSON conforming to the response contract defined in Requirement 4, with no markdown formatting, commentary, or other text outside that JSON object.
4. THE Summary_Prompt_Builder SHALL instruct, within the system prompt, that the Summary_Provider must not invent statistics beyond the Trend_Metrics provided in the Summary_Payload.
5. THE Summary_Prompt_Builder SHALL instruct, within the system prompt, that the Summary_Provider must not request, guess, or reference the Primary_User's name, email address, or any other identifying information.

### Requirement 4: AI Response Contract - Summary Narrative and Structured CBT Advice

**User Story:** As a user, I want the AI's response to give me real depth - a fuller narrative of how my metrics moved, plus a structured CBT breakdown of any pattern, distortions, balanced perspective, and next action - rather than a single terse sentence, so that the summary page gives me something substantive to reflect on.

#### Acceptance Criteria

1. THE Summary_Prompt_Builder SHALL instruct, within the system prompt, that the Summary_Provider must respond with strict JSON containing exactly two top-level fields: a string field named "summary", and an object field named "advice" containing exactly four string fields named "pattern", "distortions", "balanced_perspective", and "next_action".
2. THE Summary_Prompt_Builder SHALL instruct, within the system prompt, that the "summary" field must be written as multiple sentences describing how the mood and sleep trends moved across the date range, and must not be a single terse sentence.
3. THE Summary_Prompt_Builder SHALL instruct, within the system prompt, that the "pattern", "balanced_perspective", and "next_action" fields must each be written as a short paragraph of multiple sentences, and must not be a single terse line.
4. THE Summary_Prompt_Builder SHALL instruct, within the system prompt, that the "distortions" field must be written as a short paragraph of multiple sentences naming any cognitive distortions identified across the date range's entries when one or more apply, or must explicitly state that no cognitive distortion was identified when none applies, and must not fabricate a distortion where none fits.
5. THE Summary_Prompt_Builder SHALL instruct, within the system prompt, that the "summary" and "advice" fields must be grounded in Cognitive Behavioural Therapy by distinguishing facts from interpretations, validating the Primary_User's emotion without automatically validating the interpretation producing it, favouring credible balanced thinking over forced positivity, and not diagnosing any condition.

### Requirement 5: Response Parsing and Validation for the Two-Part Contract

**User Story:** As the AI_Summary_Service, I want the response parsing to validate both the narrative and the structured advice fields, so that a malformed or incomplete response is retried or rejected rather than silently rendering missing content.

#### Acceptance Criteria

1. WHEN Https_Summary_Provider receives a successful response, THE Https_Summary_Provider SHALL parse a string field named "summary" and an object field named "advice" containing string fields named "pattern", "distortions", "balanced_perspective", and "next_action" from the decoded response's message content.
2. IF the decoded response's message content does not contain a string field named "summary", or contains a "summary" field that is null, an empty string, or not a string type, THEN THE Https_Summary_Provider SHALL treat that as a failed attempt and retry exactly once, following the same retry-once-then-fail behaviour already defined for other failed attempts, before raising a ProviderError.
3. IF the decoded response's message content does not contain an object field named "advice", or the "advice" object is missing any of the "pattern", "distortions", "balanced_perspective", or "next_action" fields, or any of those four fields is null, an empty string, or not a string type, THEN THE Https_Summary_Provider SHALL treat that as a failed attempt and retry exactly once, following the same retry-once-then-fail behaviour already defined for other failed attempts, before raising a ProviderError.
4. WHEN Https_Summary_Provider successfully parses the "summary" field and the "advice" object's four fields, THE Https_Summary_Provider SHALL return a Progress_Summary carrying a Summary_Narrative built from the "summary" field, a CBT_Advice built from the "advice" object's "pattern", "distortions", "balanced_perspective", and "next_action" fields, and the Trend_Metrics that were sent in the Summary_Payload for that request.
5. THE Https_Summary_Provider SHALL treat parsing of a single attempt as successful only when the "summary" field satisfies Criterion 2 and all four "advice" object fields satisfy Criterion 3; WHEN either validation fails for an attempt, THE Https_Summary_Provider SHALL NOT return a Progress_Summary from that attempt.

### Requirement 6: Two-Section CBT Notes Presentation - Summary and Advice

**User Story:** As a user, I want my progress summary presented as a proper CBT-notes card split into a Summary section and a separate, structured Advice section, so that the richer narrative and the CBT breakdown are each easy to find and read rather than blended into one bare paragraph.

#### Acceptance Criteria

1. WHEN AI_Summary_Service returns a Summary_Outcome carrying a Progress_Summary, THE Diary_App SHALL render that Progress_Summary inside a CBT_Notes_Card containing the heading "AI CBT Therapist's notes", a divider separating the card from the content above it, a Summary_Section labelled "Summary" wrapping the Progress_Summary's Summary_Narrative, and an Advice_Section labelled "Advice" wrapping the Progress_Summary's CBT_Advice.
2. THE Diary_App SHALL render the Advice_Section as four labelled parts, in this order: a part labelled "Pattern" wrapping the CBT_Advice's Pattern_Observation, a part labelled "Cognitive distortions" wrapping the CBT_Advice's Distortion_Note, a part labelled "Balanced perspective" wrapping the CBT_Advice's Balanced_Perspective, and a part labelled "Next step" wrapping the CBT_Advice's Next_Action.
3. THE Diary_App SHALL render the CBT_Notes_Card, the Summary_Section, and the Advice_Section's four labelled parts using the same heading, divider, and section styling classes as the existing AI CBT Therapist's notes card rendered on the diary entry and calendar pages.
4. WHEN AI_Summary_Service returns a Summary_Outcome carrying a Progress_Summary, THE Diary_App SHALL render the existing trend metrics immediately after the CBT_Notes_Card with no other content interposed, followed immediately by the existing disclaimer notice with no other content interposed.
5. IF the Summary_Outcome is InsufficientData or Unavailable, THEN THE Diary_App SHALL render the existing plain message for that outcome, with no CBT_Notes_Card and no trend metrics rendered.
6. IF AI_Summary_Service returns a Summary_Outcome carrying a Progress_Summary but the Diary_App encounters an error while rendering the CBT_Notes_Card, THEN THE Diary_App SHALL render a fallback error message in place of the CBT_Notes_Card, and SHALL still render the existing trend metrics and disclaimer notice as in Criterion 4.

### Requirement 7: Trend Line Chart Visual

**User Story:** As a user, I want the mood and sleep trends plotted as a line chart over time rather than shown as a bare minimum-maximum range, so that I can see how each metric actually moved across the date range, not just its overall spread.

#### Acceptance Criteria

1. THE Diary_App SHALL render each Series_Stats as a Trend_Line_Chart using the CSS custom properties already defined under `:root` in `public/assets/app.css` (the Diary_App's colour, radius, spacing, and shadow design tokens), so the Trend_Line_Chart's visual style stays consistent with the Diary_App's existing dark editorial theme.
2. THE Diary_App SHALL render a Trend_Line_Chart as a server-rendered inline SVG line chart plotting each of the Series_Stats' Trend_Points over time by entry date, with a marker at each Trend_Point and a dashed reference line at the mean's position, and SHALL NOT replace the Trend_Line_Chart with a minimum-maximum range bar or any other chart type.
3. THE Diary_App SHALL render a Trend_Line_Chart's plotted line, point markers, and mean reference line with visually distinct colour and stroke treatments from one another, so the plotted trend, the individual data points, and the mean position remain distinguishable at a glance.
4. THE Diary_App SHALL render the Trend_Line_Chart's direction badge with a colour treatment distinct per TrendDirection value (Improving, Declining, Stable).
5. THE Diary_App SHALL retain the Trend_Line_Chart's numeric minimum, mean, and maximum labels and its direction badge text alongside the plotted chart, so trend information remains available without relying on the chart's visual shape alone.

### Requirement 8: Shared 0-10 Axis Normalization for Mood and Sleep Trend Line Charts

**User Story:** As a user, I want the mood and sleep trend line charts to share one visual scale, so that I can compare the two metrics' charts directly, while still trusting that the numbers shown for sleep reflect sleep's real 1-to-5 range rather than being silently rescaled to look like mood's 1-to-10 range.

#### Acceptance Criteria

1. THE Diary_App SHALL position each Trend_Point plotted on the mood Trend_Line_Chart and the sleep Trend_Line_Chart, and each chart's mean reference line, vertically according to one Shared_Trend_Axis spanning 0 to 10, rather than according to each metric's own Native_Scale.
2. THE Diary_App SHALL compute a Series_Stats value's position on the Shared_Trend_Axis as that value's proportional position within its own Native_Scale, scaled onto the 0-to-10 range, so a value at its Native_Scale's minimum positions at 0 and a value at its Native_Scale's maximum positions at 10 regardless of whether the metric is mood or sleep.
3. THE Diary_App SHALL render the numeric minimum, mean, and maximum labels shown on the mood Trend_Line_Chart and the sleep Trend_Line_Chart using each metric's own Native_Scale value, not the value's normalized position on the Shared_Trend_Axis, so a displayed sleep number always reflects sleep's true 1-to-5 measured range.
4. THE Diary_App SHALL render the sleep Trend_Line_Chart's minimum, mean, and maximum numeric labels suffixed with sleep's Native_Scale maximum ("/5"), and the mood Trend_Line_Chart's minimum, mean, and maximum numeric labels suffixed with mood's Native_Scale maximum ("/10"), so the two metrics' displayed numbers are never confused with one another.
5. WHILE a Series_Stats has no data (count is 0), THE Diary_App SHALL render no Trend_Line_Chart for that metric, consistent with the existing behaviour when a series has no minimum, maximum, mean, or Trend_Point to plot on the axis.

### Requirement 9: Ephemerality Non-Regression

**User Story:** As the Primary_User, I want the date-range summary to remain fully ephemeral after these changes, so that no additional health data starts being written to the database.

#### Acceptance Criteria

1. WHEN AI_Summary_Service computes a Summary_Outcome for a date range, THE AI_Summary_Service SHALL hold the Summary_Payload, the Progress_Summary, and the Summary_Outcome only in memory for the duration of that request, and SHALL NOT write any of them to the Storage_Service or to any other persistent store at any point during that same request, regardless of which Summary_Outcome variant results.
2. WHEN the same Primary_User requests a Summary_Outcome for the same date range again, THE AI_Summary_Service SHALL gather that Primary_User's Diary_Entry and Milestone records for that date range afresh and invoke the Summary_Provider again, rather than returning a Summary_Outcome computed for an earlier request.

### Requirement 10: Pseudonymisation Non-Regression

**User Story:** As the Primary_User, I want the JSON payload and the richer response contract to carry the same pseudonymisation guarantee as before, so that no identifying information reaches the LLM and none is required to produce the deeper narrative or the structured advice.

#### Acceptance Criteria

1. THE Summary_Prompt_Builder SHALL build each Summary_Entry_Record within the Summary_Payload using only the date, mood rating, sleep quality, events, thoughts, and emotions fields exposed by SummaryEntryContent, and SHALL NOT include any other field in a Summary_Entry_Record. THE Summary_Prompt_Builder SHALL build each Summary_Milestone_Record within the Summary_Payload using only the date, description, and category fields exposed by SummaryMilestoneContent, and SHALL NOT include any other field in a Summary_Milestone_Record.
2. THE Summary_Prompt_Builder SHALL NOT include a raw Diary_Entry object, a raw Milestone object, an OwnerId, or a UserId as a field within the Summary_Payload under any circumstance.
3. THE Summary_Payload SHALL NOT contain any field carrying the Primary_User's account identifier (an OwnerId or a UserId), email address, or name, whether at the top level of the Summary_Payload or within any Summary_Entry_Record or Summary_Milestone_Record.

### Requirement 11: Minimum-Entry Gate Non-Regression

**User Story:** As the Primary_User, I want the three-entry minimum to still apply after these changes, so that a summary - narrative and structured advice alike - is never generated from too little data.

#### Acceptance Criteria

1. IF fewer than 3 Diary_Entry records belonging to the account whose data is being summarised exist in the selected date range, THEN THE AI_Summary_Service SHALL return InsufficientData without building a Summary_Payload or invoking the Summary_Provider.
2. WHILE 3 or more Diary_Entry records belonging to the account whose data is being summarised exist in the selected date range, THE AI_Summary_Service SHALL build a Summary_Payload and invoke the Summary_Provider.

### Requirement 12: Outcome Precedence Non-Regression

**User Story:** As the Primary_User, I want InsufficientData to still take precedence over a provider failure, so that the two failure states remain distinguishable exactly as before.

#### Acceptance Criteria

1. WHILE 3 or more Diary_Entry records exist in the selected date range, IF the Summary_Provider raises a ProviderError, THEN THE AI_Summary_Service SHALL return Unavailable.
2. IF fewer than 3 Diary_Entry records exist in the selected date range, THEN THE AI_Summary_Service SHALL return InsufficientData regardless of whether the Summary_Provider would raise a ProviderError, and this InsufficientData precedence SHALL hold even if both conditions (insufficient entries and a provider error) would otherwise apply simultaneously. This precedence applies uniformly across every count below 3, including a count of 0 Diary_Entry records.

### Requirement 13: Retry-Once Behaviour Non-Regression

**User Story:** As the Primary_User, I want the provider's existing retry behaviour preserved for the expanded response contract, so that a single transient failure or a single malformed narrative-or-advice response does not surface as Unavailable when a retry would have succeeded.

#### Acceptance Criteria

1. WHEN a Summary_Provider request attempt fails due to a transport error, a non-2xx status, a response body that is not valid JSON, or a successfully-decoded response that does not satisfy the response contract defined in Requirement 4 (a missing or invalid "summary" field, or a missing or invalid "advice" field), THE Https_Summary_Provider SHALL retry the request exactly once, sending the same JSON-encoded Summary_Payload that was sent in the failed attempt.
2. WHEN the single retry attempt succeeds, THE Https_Summary_Provider SHALL return the Progress_Summary derived from that successful retry response instead of raising a ProviderError, without logging or otherwise recording that the original attempt failed.
3. IF both the original attempt and the single retry fail for any of the reasons described in Criterion 1, THEN THE Https_Summary_Provider SHALL raise a ProviderError and make no further attempts.
