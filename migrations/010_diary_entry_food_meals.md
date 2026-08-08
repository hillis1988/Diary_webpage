# 010 — diary entry food meals (application payload schema v2)

This is **not** an SQL migration. Diary answers already live in
`diary_entries.payload_ciphertext` as one encrypted JSON document per day.

Version 2 of that document adds an optional `food_meals` array:

```json
{
  "mood_rating": 8,
  "sleep_quality": 4,
  "events": "...",
  "thoughts": "...",
  "emotions": "...",
  "food_meals": [
    { "type": "breakfast", "description": "Oats", "notes": "" }
  ],
  "schema_version": 2
}
```

- Meal `type` is one of: `breakfast`, `lunch`, `dinner`, `snack`, `other`.
- Rows written by schema version 1 decode with `food_meals: []`.
- Deploy: copy the new application files; **do not** run an `ALTER TABLE`.
  Existing encrypted rows remain readable.
