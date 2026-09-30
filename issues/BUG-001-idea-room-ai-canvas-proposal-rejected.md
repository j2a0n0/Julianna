# BUG-001: Idea Room — AI proposes canvas nodes — Proposal is rejected

**Reporter:** Codex
**Date:** 2026-09-22
**Severity:** Medium
**Reproducibility:** Once observed in the live room; deterministic with the captured node shape
**Status:** Fixed in the local Julianna deployment on 2026-09-22; not yet released as a public source tag

---

## Summary

In Idea Room 3, an AI `proposeCanvasPatch` call returned 12 nodes, but Julianna rejected the proposal and displayed a generic error. The graph remained at version 0 with no nodes, links, or reviewable proposals.

## Expected Behavior

A valid AI suggestion for an empty canvas should be saved as a pending proposal for user review, without applying it to the graph. If the suggestion is invalid, the caller should receive an actionable validation result without exposing room content or credentials.

## Actual Behavior

The tool result reported that the canvas proposal was invalid or could not be saved. No proposal record or canvas element was created. The error did not identify the rejected fields.

## Reproduction Steps

**Prerequisites:** Julianna at commit `d2ac92bfc`, graph feature enabled, an active Idea Room with graph version 0 and no nodes or links, and an AI provider able to call `proposeCanvasPatch`.

1. In the room, ask the AI to suggest a canvas structure.
2. Have the provider call `proposeCanvasPatch` with a `patch.nodes` list containing a node shaped like `{"id":"concept-1","text":"Example","type":"concept","title":"Example"}`. The observed call had 12 nodes with the keys `id`, `text`, `type`, and `title`; its types were `concept` (1), `segment` (3), `dimension` (5), and `metric` (3). Titles and text are omitted here to protect room content.
3. Observe the tool result and the room's proposal list.
4. The result is an error, and the room still has no pending proposal.

### Minimal Reproduction

Run in the Julianna PHP container; this does not write to the database:

```php
require 'vendor/autoload.php';

$arguments = ['patch' => ['nodes' => [[
    'id' => 'concept-1',
    'text' => 'Example',
    'type' => 'concept',
    'title' => 'Example',
]]]];

$patch = Leantime\Domain\IdeaRoom\AI\CanvasPatch::fromToolArguments($arguments);
Leantime\Domain\IdeaRoom\Support\GraphInput::node($patch->patch['nodes'][0]);
```

`CanvasPatch::fromToolArguments` accepts the outer shape. `GraphInput::node` throws `InvalidArgumentException: Unknown canvas node field.` The actual room call followed this pattern and was rejected by the chat workflow.

## Environment

### Application & Agent

| Detail | Value |
|--------|-------|
| Application | Julianna, local Docker deployment, commit `d2ac92bfc` |
| Runtime | PHP 8.3.33; MySQL 8.4 container |
| Agent / client | In-app AI chat; the current browser has a temporary DeepSeek session (`deepseek-flash`), but the provider used for the historical failed call is not recorded |
| Workspace root | `/Users/j2a0n/Documents/Businesses/Zen Hub Julianna/leantime` |
| AI configuration | Production `JULIANNA_AI_PROVIDER`, `JULIANNA_AI_MODEL`, and key unset; temporary test connector enabled and an active browser session observed |
| Canvas state at failure | Room 3; `graph_version=0`; 0 nodes, 0 links, 0 proposals |

### Skills

The reporting agent invoked `filing-bug-reports` and `vercel:agent-browser`. Neither skill controls the application's AI schema or validator. Other registered skills were not used for this investigation.

### Skill Conflict Analysis

N/A — no instruction conflict among the invoked skills.

### Platform

| Detail | Value |
|--------|-------|
| OS | macOS 27.0 (Build 26A428) |
| Browser | Codex in-app browser at `http://localhost:8080/idea-room/1`; the failed call was found in room 3's stored metadata |

## Error Output

```text
The canvas proposal was invalid or could not be saved.
InvalidArgumentException: Unknown canvas node field.
```

The first line is the user-visible stored tool result. The second line is the deterministic local validation result for the observed node key set; it was not shown to the user.

## Visual Evidence

No screenshot was captured. Read-only database inspection found one `proposeCanvasPatch` call in room 3 followed immediately by the failed tool result with the same call ID. The room and canvas counts remained unchanged.

## Impact

- **Scope:** AI canvas proposals using the observed node shape; the effect on other provider outputs has not been measured.
- **Workaround:** Manually create nodes through the canvas UI, or submit a proposal matching Julianna's current node format (`clientId`, allowed `type`, `title`, numeric `x` and `y`, optional `content`).
- **Blocking:** The AI cannot present this suggestion as a reviewable canvas proposal. This does not block manual canvas use or prove that the AI provider is unreachable.

## Related Files

| File | Relevance |
|------|-----------|
| `app/Domain/IdeaRoom/AI/CanvasPatch.php` | Declares the AI tool schema and validates the outer patch shape. |
| `app/Domain/IdeaRoom/Support/GraphInput.php` | Validates individual nodes, identities, types, and coordinates. |
| `app/Domain/IdeaRoom/Services/IdeaGraph.php` | Validates and persists review proposals. |
| `app/Domain/IdeaRoom/Services/WorkspaceChat.php` | Converts proposal failures to the generic user-visible tool result. |
| `app/Domain/IdeaRoom/AI/IdeaRoomPrompt.php` | Instructs the model when to propose canvas patches. |

## Notes

- **Confirmed:** An empty graph is not itself a blocker. Existing tests cover successful proposal creation at graph version 0; a valid node passed production-code static validation during this investigation.
- **Confirmed:** The observed payload used string `id` values for all 12 nodes, no `clientId` or coordinates, an unrecognized `text` field, and types outside the validator's allowed set (`idea`, `question`, `insight`, `source`, `decision`, `next_step`). The first validation failure is the unknown `text` field; additional fields would fail if that one were removed.
- **Hypothesis:** The AI tool's node schema is too broad (`items: {type: object}`) and the system prompt does not state the required node shape. This may have permitted the provider to invent a plausible but incompatible node format. The exact reason the model selected that shape is not established.
- **Unverified:** No new paid/external AI call was made during this investigation. The stored failure and local validators were inspected read-only.

## Resolution and verification (2026-09-22)

The in-app tool now advertises bounded node/link schemas with supported types and a new-node format example. The chat path gives a safe format correction hint for validation errors, while unexpected failures remain generic. A mocked regression replays the malformed payload, then a corrected proposal; only the corrected proposal becomes pending, and the graph stays unchanged.

Verification: 86 Idea Room unit tests passed (744 assertions); the complete unit suite passed with 1,029 tests and 8 pre-existing skips. The rebuilt local app container is healthy and advertises the new schema. No new external AI request was made, so provider-specific real-model compliance remains to be checked when a test key and suitable room content are explicitly approved for transmission.
