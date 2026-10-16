# Operation and evidence

Use an existing authorized Docker Desktop installation with Linux containers. This lab ran on Docker Engine 29.7.2. Check free memory, disk space and port 8086 before starting. Never reset Docker or prune unrelated resources.

## Existing completed lab

From this directory, `docker compose up -d` restarts the preserved application and database. Open `http://127.0.0.1:8086` using the private local administrator credential. Keep credentials out of command history, screenshots and commits. Stop with `docker compose stop`. The fixture runner deliberately refuses existing cases; do not rerun it on the preserved completed lab.

## Fresh reproduction

1. Use a separate disposable environment. Run `Initialize-LocalEnv.ps1` to generate `.env`; existing files are preserved. This does not initialize GLPI accounts or tickets.
2. Validate with `docker compose config --quiet`, then start. Confirm installation completion, `database:is_up_to_date` and required `system:check_requirements` checks using GLPI's `bin/console` inside the container.
3. Before creating records, randomize the initial glpi administrator password, disable unused initial human accounts, and turn off notifications. Preserve the system identity. This setup is required and is not automated by the runner. Store the credential privately and copy it into the container at `/tmp/atlas-admin-password.txt` with owner-only access where possible. Never publish it.
4. Copy `fixtures/cases.json` into the GLPI container as `/tmp/atlas-cases.json` and the PHP scripts into `/tmp/`. Run `php /tmp/run-exercise.php` there. The runner requires database name atlas_helpdesk, GLPI 12.0.0, notifications off and authenticated administration. It refuses unrelated or existing tickets.
5. Run `php /tmp/verify-state.php`. Export `/tmp/atlas-helpdesk-results.json` and `/tmp/atlas-verified-state.json` for review. Distinguish new results from the historical exports here. The verifier assumes initial administrator ID 2; review that assertion if a different installation allocates another ID.
6. Inspect the browser ticket list and history. Stop only this Compose project. Preserve volumes until evidence is exported. Destructive volume removal is not part of this runbook.

The targeted repair-requesters.php records the historical correction, not a generic production repair. The revised main runner has not been tested end-to-end on a second fresh installation.

## Evidence map

| File | Meaning |
|---|---|
| workflow-results.json | Initial model execution; 99 checks did not detect missing requester links |
| requester-correction.json | Actual correction of ten synthetic requester links |
| verified-state.json | Independent read-only inspection: 107 passed, 0 failed |
| runtime.json | Selected image, limit, binding and network fields; no environment secrets |
| stopped.json | Three own containers exited; volumes preserved |
| 01-ten-closed-tickets.png | Ticket table after correction; fictional data only |
| 02-reopen-history.png | HD-09 audit including reopening and later requester correction |

An initial accessibility snapshot captured zero dashboard counters while loading; the settled screenshot shows ten. Totals were independently checked against the table and stored records. Built-in demonstration mode was disabled before evidence collection. No real emails, endpoint commands, account recovery, access grants or software deployments were performed for the fictional cases.

