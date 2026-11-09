# Security policy

ServerOS runs on servers people care about, so we take security reports seriously and we're grateful for them.

## Reporting a vulnerability

**Please don't open a public issue, discussion or pull request for a security problem.**

Report it privately, either way works:

- On GitHub: [report a vulnerability](https://github.com/serveroshq/panel/security/advisories/new) (preferred, it keeps the whole conversation in one place)
- By email: [security@serveros.com](mailto:security@serveros.com)

Please include:

- what's affected (version, commit or URL)
- steps to reproduce, or a proof of concept
- what an attacker could do with it
- whether you'd like credit, and how to name you

## What happens next

- We reply within 3 working days to say we've got it.
- We confirm whether it's a real issue, and how serious, as soon as we can, usually within 10 working days.
- We fix it, release the fix and publish a GitHub security advisory, crediting you if you want.
- We ask that you give us 90 days, or until a fix is out if that's sooner, before you talk about it publicly. If we need longer we'll tell you why.

## What's in scope

- The panel code in this repo.
- [serveros.com](https://serveros.com) and ServerOS Cloud. Report those by email.
- The daemon has its own repo: report daemon issues at [serveroshq/daemon](https://github.com/serveroshq/daemon/security/advisories/new) or by email.

## Supported versions

Until 1.0, only the latest release gets security fixes. Please update before reporting if you can.

## Rules for testing

Test against your own install wherever you can. On serveros.com and ServerOS Cloud:

- only use accounts and servers you own
- don't read, change or delete anyone else's data, and stop as soon as you reach any
- no denial of service, spam, social engineering or physical attacks
- no automated scanning that sends large amounts of traffic

If you follow these rules and act in good faith, we won't take legal action against you or ask anyone else to, and we'll treat your research as authorised.
