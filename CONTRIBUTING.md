# Contributing

Thanks for your interest in the Globetrotters AI Presence plugin.

## Issues welcome, pull requests by invitation

- **Bug reports and feature requests** are welcome as [GitHub issues](../../issues/new/choose). Please use the templates.
- **Pull requests are by invitation.** The plugin follows a wire contract with the Globetrotters backend and stays behaviourally aligned with the [Symfony bundle](https://github.com/globetrotters-ai/gt-symfony-bundle), so most changes have to land in several places at once. If you would like to contribute code, open an issue first describing the change; if it fits, we will invite a pull request.
- **Security problems** go by email, never in an issue: see [SECURITY.md](SECURITY.md).

## Working on the code

Read [CLAUDE.md](CLAUDE.md) first: commands, architecture and the invariants a change must preserve. In short:

```bash
make install   # dev tooling (Composer)
make check     # PHPCS + the unit suite: the gate before pushing
```

`make test-integration` and `make plugin-check` boot a real WordPress through wp-env and need Docker; see [docs/releasing-and-local-testing.md](docs/releasing-and-local-testing.md).

The shipped plugin has **no runtime dependencies** (Composer is dev-only) and supports PHP 7.4 and WordPress 6.0 onwards (CI runs the unit suite from PHP 7.4 up). Keep it that way.

## Licence

The plugin is licensed under the GPL-2.0-or-later ([LICENSE](LICENSE)). By contributing you agree that your contribution is licensed under the same terms.
