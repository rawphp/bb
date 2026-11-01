# AGENTS.md

Bodybuilder: an open-source training and nutrition PWA. Laravel 13 holds every rule; Vue 3 only displays. Every action is a capability (`rawphp/laravel-capabilities`); the optional agent runs on `rawphp/laravel-capabilities-ai`.

## Read first

- `docs/spec/00-overview.md`: product spec and requirement IDs.
- `docs/architecture.md`: decisions every change follows. It wins over this file on conflict.
- `~/EA/projects/AGENTS.md`: folder rules for testing, TDD and Warp. Read it at the start of every session.

## Rules

- No business rule in `resources/js`. If a screen needs a computed value, a capability returns it.
- No write path outside a capability. No controller writes domain state.
- Tests are named after the requirement they prove. Unit tests first.
- This is a public repo. Never commit secrets, real personal data or production dumps.
- Commits and pull requests contain the change only. No tool bylines or co-author trailers.

## Sources this project was built from

- `~/EA/projects/rp-clone`: original product spec, SwiftUI screens and Swift rules (`ios/Sources/RPCore/Domain`) being ported to PHP.
- `~/EA/projects/mesoprep`: agent behaviour and `docs/capabilities-host-lessons-learned.md`, the host setup checklist.
- `~/EA/projects/laravel-capabilities-monorepo`: the capability packages.
