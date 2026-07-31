**English** · [Italiano](README.it.md) · [Español](README.es.md)

# AIManager

**AIManager lets you work with multiple artificial intelligence systems from one place:
models running on your Mac and free or paid external services.**

It keeps conversations, projects, documents, instructions, context and memory locally.
You can continue the same work with a different AI without starting over or depending on
one provider. AIManager can choose the most suitable AI and use another one when the
first is unavailable.

## Requirements

- macOS, for local single-user use;
- PHP 8.5 with SQLite, cURL and mbstring;
- Git;
- at least one provider: your personal key for an external service, or
  [LM Studio](https://lmstudio.ai/download) for local models.

**Code** also requires the PHP extensions `pcntl` and `posix`. External services do not
require a local model. For LM Studio, memory and storage requirements depend on the model;
a Mac mini M2 Pro with 16 GB of unified memory is a practical reference configuration for
smaller local models.

## Download

```bash
git clone https://github.com/acaro76/AIManager.git
cd AIManager
```

## Install

```bash
bash bin/install.sh
```

The command checks the requirements and prepares the local configuration without asking
for or displaying API keys.

## Launch

```bash
bash bin/launch.sh
```

AIManager opens in your browser at <http://127.0.0.1:8000>.

## First use

1. Open **Provider**.
2. Choose LM Studio or an external service.
3. Enter your key only when the service requires one.
4. Enable the provider, run **Test**, then press **Save**.
5. Open **New chat**.

AIManager automatically discovers the models available in LM Studio. Credentials are
configured from the interface and remain in the local configuration.

## What you can do

- progressive conversations with routing and automatic fallback between providers;
- projects, sessions, memory and context continuity;
- optional web search, attachments and image generation;
- provider configuration and verification from the interface;
- assisted work on authorized folders with **Code**, including targeted reads, change
  proposals, controlled checks and local Git.

**Code is not an operating-system security boundary.** Operations that modify files
require confirmation; there is no general shell and no implicit Git push.

## Data and control

- `.env` contains credentials and stays local;
- `storage/` contains the database, conversations, memories, attachments, logs and
  backups;
- folders opened in Code remain outside AIManager;
- `.env`, runtime data, backups and workspaces must not be published to the repository.

See the [provider guide](docs/PROVIDERS.md), [user guide](docs/USER_GUIDE.md), and
[update and rollback instructions](docs/RELEASE.md). For security boundaries, see
[SECURITY.md](SECURITY.md).

## License

AIManager is distributed under the [Apache License 2.0](LICENSE).

---

Developed by [Gennari Productions](https://gennari.es/) —
[Alessandro Gennari](https://gennari.es/alessandro-gennari.html), AI Consultant,
Las Palmas de Gran Canaria.
