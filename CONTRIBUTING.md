# Contributing to the ServerOS panel

Thanks for wanting to help. This page explains how changes get into the panel, so your time isn't wasted.

## Before you write code

- **Small fixes** (typos, docs, an obvious bug with a clear fix): just open a pull request.
- **Anything bigger** (a new feature, a change in behaviour, a refactor, a new dependency): open an [issue](https://github.com/serveroshq/panel/issues/new/choose) or a [discussion](https://github.com/serveroshq/panel/discussions) first and wait for a maintainer to agree on the approach. Pull requests for big changes nobody agreed to are likely to be closed.
- **Security problems**: never in public. Follow [SECURITY.md](SECURITY.md).

Some features belong in our paid editions, which aren't in this repo (see [LICENSING.md](LICENSING.md)). If we think that's where an idea fits, we'll say so in the issue.

## The CLA

The first time you open a pull request, a bot asks you to sign our [Contributor License Agreement](CLA.md) by posting a comment. You only do this once. You keep the copyright in your work; the CLA lets us ship it in the open source panel and in our paid editions. We can't merge a pull request until everyone who made commits in it has signed.

## Pull requests

- One change per pull request. Keep it focused and small, ideally under 500 changed lines.
- No more than 3 open pull requests at a time, please.
- Fill in the template: what changed, why, and how you tested it.
- Add or update tests for what you change.
- Don't mix in unrelated formatting or renames.
- Keep CI green. If you're new here, a maintainer has to start CI on your pull request, so it can take a little while to run.
- Write plain, short user-facing copy. No em dashes in the UI.

## How review works

- A maintainer has to approve every pull request before it's merged, and new pushes reset that approval.
- Conversations have to be resolved before merge.
- We squash-merge, so your pull request becomes one commit on `main`. The pull request title becomes the commit title, so make it say what the change does.
- We may ask for changes, push small fixes to your branch, or close a pull request that doesn't fit. We'll always say why.

## Using AI tools

You can, but you're responsible for every line you submit. Read [AI_POLICY.md](AI_POLICY.md) before you do.

## Setting up the panel locally

Setup instructions will be here when the code lands.

## Code of Conduct

Everyone taking part agrees to our [Code of Conduct](CODE_OF_CONDUCT.md).
