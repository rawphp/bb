# Contributing

Thanks for helping. Bodybuilder is at the spec stage, so the most useful contributions right now are questions and corrections to [docs/spec/](docs/spec/00-overview.md).

## Ground rules

1. **The spec comes first.** Every behaviour has a requirement ID (for example `NUT-003`). A change that adds or alters behaviour updates the spec in the same pull request.
2. **Rules live on the server.** No business logic in the Vue app. If a screen needs a number, a capability returns it. See [docs/architecture.md](docs/architecture.md).
3. **Every action is a capability.** A new thing a user can do is a new capability, not a new controller. The agent then gets it for free.
4. **Tests first.** Write the failing test, then the code. Name tests after the requirement they prove: `it('NUT-003 adds workout energy to the day')`. Most tests are unit tests; feature tests only cover the HTTP and auth seams.

## Issues

- Bugs: use the bug template. Say what you did, what you expected and what happened.
- Features: use the feature template and point at the requirement ID it changes, or say it is new.
- Security issues: do **not** open a public issue. See [SECURITY.md](SECURITY.md).

## Pull requests

- One logical change per pull request.
- Run the project gate before you push. The gate command is listed in [docs/architecture.md](docs/architecture.md) once the app exists.
- Keep commits and pull request text about the change only.

## Code of conduct

This project follows the [Contributor Covenant, version 2.1](https://www.contributor-covenant.org/version/2/1/code_of_conduct/). Report unacceptable behaviour to the maintainers through a private GitHub Security Advisory on this repository, or to the email on the maintainer's GitHub profile.
