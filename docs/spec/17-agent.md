# 17. The agent

An optional Coach that can do anything the user can, by being asked. It runs in-process on `rawphp/laravel-capabilities-ai` and calls the same capabilities the screens call (CAP-002). It is a fast way in, not a replacement for the UI. Every result it produces is visible and editable on a normal screen.

Behaviour comes from mesoprep's Coach (`~/EA/projects/mesoprep/docs/mesoprep-spec.md`, "Coach Chat Design"). Setup follows `mesoprep/docs/capabilities-host-lessons-learned.md`, sections 3 and 4.

## Availability (AGT)

- **AGT-001** [B] The agent must be able to call every capability the Vue app calls, except those on the CAP-031 exclusion list. CAP-030 enforces it.
- **AGT-002** [B] The agent is on only when the instance has an Anthropic API key and `CAPABILITIES_AI_LLM_DRIVER=anthropic`. With it off, no Coach button, onboarding door or mention of the agent appears. With it on, a user can still turn their own Coach off in Settings (AGT-020).
- **AGT-003** [B] The agent's tools must be the capabilities themselves: tool name = capability name, tool description = capability description (CAP-005), input schema = the capability's schema, adjusted at the tool catalog boundary for what Anthropic needs. There is no meta-tool such as `run_command`.
- **AGT-004** [B] Tools must run as the conversation's user, with `caller=agent`, through `CapabilityBus::invoke`, so the CAP-020 authorizer decides each call. A bare invoke without the conversation user as actor is a bug. `CAPABILITIES_SURFACE_AGENT=false` stops every agent tool call. Mesoprep lost weeks to it (lessons, B.8).

- **AGT-005** [B] The instance must cap agent use per user: a number of turns per day and Anthropic tokens per month, set in config (`AGENT_TURNS_PER_DAY`, `AGENT_TOKENS_PER_MONTH`; empty means no cap, the self-hosted default). Near the cap, the chat says how much is left; at the cap, it says when it resets, and every screen keeps working. The hosted instance sets the caps to keep the plan's LLM cost bounded. A day is the user's local day (SET-012), so a user can stretch the daily cap by changing time zone; the monthly token cap, in the app timezone, is what bounds the cost when it is set. A time zone change gives no fresh daily allowance: for 24 hours after it, the day's turns count from 24 hours before the change.

## Behaviour (AGT)

- **AGT-010** [B] Concrete instructions apply straight away and are confirmed after the write ("Swapped Romanian deadlift for 45° back extension in Upper A, from this week on"). Destructive or ambiguous requests need the user's say-so in chat first. A capability marked `destructive` is refused by the authorizer unless the turn carries the user's explicit confirmation of that call. An MCP call (caller `mcp`, AGT-040) has no turn, so the authorizer refuses it every destructive capability until MCP has its own confirmation step. v1 destructive capabilities: anything that deletes, `phase_end`, `mesocycle_end`, `training_weekdays_change`, `prebuilt_plan_apply`.
- **AGT-011** [B] The agent must read before it writes. Each turn's context holds the user's profile, today's `today_show`, the current phase and mesocycle, and the last 7 days' adherence. Anything else it fetches with read capabilities.
- **AGT-012** [B] The agent must never log a set, a check-in or a weigh-in the user did not report in chat. It may record what the user tells it ("I did 100 for 8, 7 and 6").
- **AGT-013** [B] Every agent write shows in the chat as a compact card: what changed, with a link to the screen and an Undo button (CAP-013). It also appears in Activity (CAP-022) with channel `agent`.
- **AGT-014** [B] The agent follows the spec's rules because the capabilities enforce them. It must not promise behaviour the server will refuse. When a capability denies a call, the agent shows the server's message (CAP-012) and offers what is allowed.
- **AGT-015** [B] The agent may answer questions about the method (why a deload, what RIR means) from the Learn content (LRN), and links to the article.

## Onboarding by chat (AGT)

- **AGT-030** [B] With the agent on, the first onboarding screen must offer two doors: "Set up step by step" (the ONB stepper) and "Talk it through with the Coach". Both end in the same `onboarding_complete` capability, so they produce the same plan.
- **AGT-031** [B] In chat onboarding, the agent asks the ONB questions in conversation, a few at a time, and records each answer with `onboarding_answer`. Health screening (SAFE-001) must not be done by chat. The agent opens the screening step as a sheet in the chat, and the user answers it there.
- **AGT-032** [B] Before `onboarding_complete`, the agent must show the same review summary as ONB-018, as a card in the chat, and the user confirms it. Afterwards the agent offers to adjust the first mesocycle ("Want me to swap any exercises or move a training day?").
- **AGT-033** [B] The user can leave chat onboarding at any point and continue in the stepper with every answer kept, because both read `onboarding_show`.

## Chat (AGT)

- **AGT-020** [B] Settings → Coach must have: on or off for this user, clear conversation, and a short note on what the Coach can and cannot do.
- **AGT-021** [B] Chat must stream the reply as it is written (the package's turn events over SSE), show each tool call as it runs ("Checking this week's meals…"), and allow cancelling a turn.
- **AGT-022** [B] A user can attach a photo (a meal, a label, a gym machine). The host stores it and adds it to the turn's context as an image block. The package does not store attachments.
- **AGT-023** [B] Offline, the chat shows its history from cache, and the input is disabled with "The Coach needs a connection" (OFF-031).
- **AGT-024** [B] One conversation per user, kept until cleared. The prompt prefix (instructions and tool catalog) is cached on Anthropic's side. Only the per-turn context changes between turns.

## MCP and CLI (AGT)

- **AGT-040** [B] The instance must serve product MCP (`laravel/mcp`) at `/mcp/bodybuilder`, exposing the same tool profile as the in-app agent, authenticated with a personal access token (CAP-021). A user with Claude Desktop or another MCP client can then run their training and nutrition from it, with no LLM cost to the instance.
- **AGT-041** [B] The `capabilities` CLI must work against an instance with a personal access token. No extra server code is needed beyond CAP-003's CLI mapping.

## Setup defaults (AGT)

From mesoprep's lessons (section 3, item 14). These are production defaults, not tuning:

| Setting | Value |
|---|---|
| `capabilities-ai.progress.driver` | `redis` |
| `capabilities-ai.llm.driver` | `anthropic` (`fake` in tests) |
| `capabilities-ai.claim_ttl` | 1800 seconds |
| `capabilities-ai.queue.name` | `agent`, with its own worker |
| `capabilities-ai.proposals.enabled` | `false` |
| Stale turn reaper | the package's `capabilities-ai:reap-stale-turns`, scheduled once by the host |
| Model | the package default (`claude-opus-5-5`), overridable by env |

## Proof (AGT)

- **AGT-050** [B] An eval set of at least 30 scripted requests must run against the fake LLM driver (tool selection and arguments) and, on demand, against the real model, with pass marks recorded per release. It must include, at minimum:
  - full chat onboarding;
  - "move tomorrow's workout to Saturday";
  - "swap bench for dumbbell press";
  - "I ate a burrito instead of meal 3";
  - "undo that";
  - a destructive request that must ask first;
  - a request for an excluded capability.
