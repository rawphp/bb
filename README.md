# Bodybuilder

One open-source app for training and nutrition, with one plan driving both. Your diet phase sets your training volume, your workouts set the day's calories and carb timing, deloads double as diet breaks, and a single weekly review reads both halves.

It is a phone-first web app you install to your home screen. It works offline in the gym. An optional agent can do anything the app can, so you can set up a whole mesocycle by describing it.

> **Status:** early build. The Laravel app runs one capability, `account_show`, end to end. Start at [docs/spec/00-overview.md](docs/spec/00-overview.md).
>
> Set up a checkout with `bash scripts/provision.sh` and run the gate with `bash scripts/check.sh`.

## How it works

- **Laravel holds every rule.** Targets, progression, the weekly review, trend weight: the server computes all of it. The Vue front end only displays.
- **Every action is a capability.** Built on [`rawphp/laravel-capabilities`](https://github.com/rawphp/laravel-capabilities). The app, the in-app agent, MCP clients and the `capabilities` CLI all call the same operations under the same rules.
- **Offline by design.** Screens are cached; actions queue in an outbox and replay when you're back online.
- **Agent optional.** Add an Anthropic API key to turn on the Coach ([`rawphp/laravel-capabilities-ai`](https://github.com/rawphp/laravel-capabilities-ai)). Without one, the app is complete.
- **Self-host it free, or use the hosted version.** Both run the same public code. Billing is a Stripe module that only the hosted version turns on.

## Stack

Laravel 13 (PHP 8.4), MySQL 8, Redis, Vue 3 with TypeScript, Tailwind CSS 4, vite-plugin-pwa. Tests: Pest, Vitest, Playwright.

## Docs

| | |
|---|---|
| [docs/spec/](docs/spec/00-overview.md) | Product spec with requirement IDs |
| [docs/architecture.md](docs/architecture.md) | Decisions every change follows |
| [docs/personas.md](docs/personas.md) | Who the app is for |

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security reports: [SECURITY.md](SECURITY.md).

## Licence

[MIT](LICENSE).

Bodybuilder is an independent project. It is not affiliated with or endorsed by Renaissance Periodization. The spec describes training and nutrition methods in its own words.
