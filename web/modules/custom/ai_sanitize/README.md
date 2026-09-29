# AI Sanitize

Keeps sensitive data away from AI providers. Every request the
[AI module](https://www.drupal.org/project/ai) sends to a provider is scanned,
and sensitive values are replaced with placeholders such as `[IBAN_1]` before
the request leaves the site.

```
Wofür ist DE89 3704 0044 0532 0130 00? PIN 4711-9823, geb. 01.02.1980
        ↓
Wofür ist [IBAN_1]? PIN [SECRET_1], geb. [BIRTHDATE_1]
```

## Why at the provider boundary

Sensitive data does not only come from what users type. In agents and RAG it
mostly arrives through **tool results** and retrieved documents, over several
requests of an agent loop. AI Sanitize subscribes to the AI module's
`PreGenerateResponseEvent`, which fires before *any* provider call, and cleans
the whole request:

- chat: the system prompt, every message (including tool results) and the
  arguments of tool calls (also nested values);
- other operation types with text (embeddings, moderation, summarization,
  translation, text-to-speech, …): their text or prompt.

So it protects assistants, agents, chatbots, CKEditor AI, AI Search indexing
and custom code alike, without changes to them. It runs before the AI module's
guardrails (priority 100 vs. 0), so guardrails, loggers and the provider all
see the sanitized request.

Messages are changed in place, so conversation history and loggers that read
the same objects afterwards (e.g. `ai_chatlog`) store the sanitized version.

## Placeholders

The same value always gets the same placeholder within a PHP process
(`[IBAN_1]` stays `[IBAN_1]`), and numbering continues after placeholders
already in the conversation, so the model can still reason about "the same
account" without ever seeing it. The mapping never leaves memory.

## Detectors

Each detector is a plugin that only reports positions; overlap resolution and
replacement are central. To avoid damaging useful data (policy and contract
numbers, amounts, dates), detectors either validate a checksum or require a
label next to the value.

| Detector | Placeholder | How it avoids false positives |
|---|---|---|
| IBAN | `IBAN` | MOD-97 check digits, known country length; bank-masked IBANs pass through |
| Payment card | `CARD` | Card network prefix + length + Luhn |
| E-mail | `EMAIL` | PHP e-mail validation |
| KVNR (health insurance number) | `KVNR` | Check digit |
| Pension insurance number (RVNR / SV-Nummer) | `PENSION_NO` | Check digit, plausible birth date |
| Tax ID (Steuer-IdNr) | `TAX_ID` | Structure rules + ISO 7064 check digit |
| Date of birth | `BIRTHDATE` | Only dates after a birth-date label (`Geburtsdatum`, `geb.`, `DOB` …) |
| PIN / password / access code | `SECRET` | Only values after such a label that contain a digit |
| Phone (off by default) | `PHONE` | Only numbers after a phone label |

Add your own with a class in `src/Plugin/AiSanitizeDetector` carrying the
`#[AiSanitizeDetector]` attribute and implementing `DetectorInterface`
(extend `DetectorBase`).

## Configuration

*Administration → Configuration → AI → AI Sanitize*
(`/admin/config/ai/sanitize`, permission *Administer AI Sanitize*):

- which detectors run;
- **always replace** exact values (e.g. a known customer number);
- **custom patterns** as `TYPE|/regex/flags`;
- **never replace** (allowlist, e.g. shared service e-mail addresses);
- placeholder format, operation types, and request tags that bypass
  sanitizing (for trusted self-hosted models);
- logging of counts per type (values are never logged);
- **Try it**: preview what the AI would receive for a sample text.

## For other modules

- `\Drupal::service('ai_sanitize.sanitizer')->sanitize($text)` returns a
  `SanitizeResult` (text + counts), e.g. to clean text before storing it
  somewhere else.
- `TextSanitizedEvent` (`ai_sanitize.text_sanitized`) is dispatched when a
  request was changed, with counts per type (never the values), e.g. to show
  "2 values were withheld from the AI" in a chat UI.
- The request metadata key `ai_sanitize` holds the same counts.

## Limits

- Detection is pattern-based. Names and free-text descriptions of people are
  not detected; add them as exact values or custom patterns if needed.
- Images, audio and files attached to requests are not inspected.
- Placeholders are not swapped back into answers.

## Tests

```
vendor/bin/phpunit -c web/core web/modules/custom/ai_sanitize
```
