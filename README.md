**English** · [Italiano](README.it.md) · [Español](README.es.md)

# AIManager

AIManager is a local AI hub for macOS that brings together chat, projects, memory, multiple
providers and a controlled Code environment. Application data stays on your machine; when you pick a
cloud provider, the requests and attachments it needs are sent to that service under its own terms.

The project has passed the technical gate of its first release and is usable locally. The current
distribution is a manual macOS archive: it is not an `.app`, and it includes no installer, signing,
notarization or automatic updates.

## Available features

- streaming chat with routing and fallback across providers;
- projects, sessions, memory and context continuity;
- optional web search, attachments and image generation;
- credential setup and testing from the Provider UI;
- Code on an authorized folder, with targeted reads, change proposals, curated verifications,
  read-only commands, a local PHP server and assisted Git up to the local commit.

Code is not an operating-system sandbox. Modifying operations require confirmation, and there is
neither a general shell nor an implicit Git push.

## Requirements

- macOS, local single-user use;
- PHP 8.5 with SQLite, cURL and mbstring;
- `pcntl` and `posix` for Code commands and persistent processes;
- at least one AI provider: your own cloud key, or LM Studio installed separately.

## Quick start

```bash
cp .env.example .env
bin/launch.sh
```

AIManager opens at `http://127.0.0.1:8000`. On first launch:

1. go to **Provider**;
2. choose LM Studio or a cloud provider;
3. enter endpoint, model and, if required, your key;
4. enable the provider, run **Test**, then **Save**;
5. open **Nuova chat** (New chat).

You do not need to fill keys into `.env` by hand: the Provider UI stores them locally. See the
[Provider guide](docs/PROVIDERS.md) and the [user guide](docs/USER_GUIDE.md) for the full path.
Installation, update and rollback are described in [RELEASE.md](docs/RELEASE.md).

> The application interface and the detailed guides under `docs/` are currently Italian only.
> Localization of the product is a post-launch direction, not a promise for this release.

## Data and privacy

- `.env` holds your credentials and stays local;
- `storage/` holds the database, conversations, memories, attachments, logs and backups;
- folders opened in Code remain outside AIManager;
- `.env`, runtime data, backups and workspaces must never enter a commit or a release.

For boundaries and responsible reporting see [SECURITY.md](SECURITY.md).

## Status and contributions

The priority is making the first-run experience reliable and validating it with outside users, not
adding features indiscriminately. See the [public roadmap](docs/PUBLIC_ROADMAP.md).

Read [CONTRIBUTING.md](CONTRIBUTING.md) before proposing changes.

## License

AIManager is distributed under the [Apache License 2.0](LICENSE).

---

Developed by [Gennari Productions](https://gennari.es/) — [Alessandro Gennari](https://gennari.es/alessandro-gennari.html), AI Consultant, Las Palmas de Gran Canaria.
