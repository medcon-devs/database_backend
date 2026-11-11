# AI contact search

## Endpoint

```http
POST /api/ai-search
Authorization: Bearer <access-token>
Accept: application/json
Content-Type: application/json
```

The endpoint accepts a natural-language request, extracts a structured search
intent, then executes Medcon's ranked fuzzy contact search. The complete default
pipeline runs in Laravel and MariaDB without an external service, API key,
Typesense process, or per-request cost. It does not update data.

Request:

```json
{
  "query": "Find Ali cardiologist in UAE",
  "page": 1,
  "perPage": 30
}
```

The response uses the same contact/pagination fields as `/query-row` and adds:

```json
{
  "interpretation": {
    "original_query": "Find Ali cardiologist in UAE",
    "search_text": "Ali",
    "countries": ["United Arab Emirates"],
    "specialities": ["Cardiology"],
    "explanation": "...",
    "confidence": 0.95,
    "parser": "openai"
  }
}
```

Every name-search result also includes its ranking evidence:

```json
{
  "id": 123,
  "firstname": "Ali",
  "lastname": "Ahmad",
  "search_match": {
    "score": 0.91,
    "reasons": ["fuzzy_name"],
    "token_coverage": 0.92,
    "full_name_similarity": 0.89
  }
}
```

The top-level response includes diagnostics:

```json
{
  "search_meta": {
    "engine": "medcon_fuzzy_v2",
    "candidate_count": 240,
    "candidate_truncated": false,
    "total_is_estimate": false,
    "duration_ms": 680
  }
}
```

Existing frontends can ignore these additional fields. Results are already
returned in descending relevance order.

`parser` is `local` for the default free Medcon parser and `openai` only when
that optional provider is deliberately enabled and succeeds.

The smart local parser supports:

- English and Arabic request phrases
- canonical country and speciality aliases in both languages
- removal of command and business-role noise such as `find`, `doctor`, and `IT`
- fuzzy correction of likely country and speciality typos such as `Kuweit` and `cardilogist`
- direct extraction of email addresses and phone numbers
- common medical language such as `heart doctor`, `skin doctor`, `oncologist`, and Arabic equivalents
- dynamic confidence and a human-readable interpretation explanation

The ranked search engine supports:

- first and last names stored in separate columns
- reversed name order, such as `Ahmad Ali`
- partial names and one or more spelling mistakes, such as `Alo Ahmd`
- phonetic candidate discovery for Latin names
- exact and partial email matching
- normalized phone matching regardless of spaces, parentheses, `+`, or hyphens
- country and speciality filters expanded to all historical database aliases
- active contacts only; soft-deleted contacts are not returned

## Configuration

Add to `.env`:

```dotenv
AI_SEARCH_PROVIDER=local
```

This is the production default and makes no external AI request. The OpenAI
provider remains optional. To use it deliberately, configure:

```dotenv
AI_SEARCH_PROVIDER=openai
OPENAI_API_KEY=your-key
OPENAI_SEARCH_MODEL=gpt-4o-mini
OPENAI_BASE_URL=https://api.openai.com/v1
OPENAI_TIMEOUT_SECONDS=12
```

Then clear cached configuration:

```bash
php artisan optimize:clear
```

Never expose `OPENAI_API_KEY` to Flutter, Next.js client components, or a
`NEXT_PUBLIC_` environment variable.

For the complete Next.js implementation contract covering AI search, user invitations, and forgot password, see `docs/FRONTEND_AI_USERS_FORGOT_PASSWORD_HANDOFF.md`.

## Behavior and safety

- authenticated users only
- limited to 30 requests per minute per caller/IP
- query length 2–500 characters
- Structured Outputs constrain the model to the expected JSON schema
- only canonical country and speciality options are accepted
- OpenAI requests, when the optional provider is enabled, use `store: false`
- failures are logged without exposing provider errors to the client
- AI search remains fuzzy and ranked when Typesense is stopped or not installed
- candidate retrieval is capped at 15,000 rows; `total_is_estimate` reports the rare case where that cap is reached
