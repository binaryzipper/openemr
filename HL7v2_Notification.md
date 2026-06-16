# HL7v2 ADT Notification

Push HL7v2 **ADT** messages from OpenEMR to an external HTTP API whenever
patients and encounters are created or updated.

This is provided by the custom module **ADT HL7v2 Notifier**
(`interface/modules/custom_modules/oe-module-adt-notifier`). It subscribes to
OpenEMR's patient/encounter lifecycle events, builds an HL7v2 ADT message, and
delivers it to your endpoint as JSON:

```json
{ "hl7_message": "<raw HL7v2 content>" }
```

Authentication uses the OAuth2 **client_credentials** grant: the module
exchanges a client ID/secret for a bearer token (cached for the life of the
token) and sends it as `Authorization: Bearer <token>`.

Delivery is **fire-and-forget** — failures are logged, never thrown, so a slow
or unreachable endpoint can never block or break a clinical save.

---

## Event → ADT mapping

| Trigger in OpenEMR                          | OpenEMR event                         | ADT message |
| ------------------------------------------- | ------------------------------------- | ----------- |
| New patient registered                      | `patient.created`                     | `ADT^A04`   |
| Patient demographics updated                | `patient.updated`                     | `ADT^A08`   |
| Encounter created (no discharge)            | `service.save.post` (EncounterService) | `ADT^A01`   |
| Encounter saved with a discharge disposition | `service.save.post` (EncounterService) | `ADT^A03`   |

Each message contains `MSH`, `EVN`, `PID`, and a `PV1` segment (HL7 v2.5.1).

> **Encounter caveat:** the encounter save event carries no insert-vs-update
> flag, so the message type is decided purely by whether a discharge
> disposition is present. Editing an open (non-discharged) encounter therefore
> re-emits `ADT^A01`. If that is noisy for your receiver, leave encounter events
> disabled and rely on `A04`/`A08`.

---

## Module file layout

```
interface/modules/custom_modules/oe-module-adt-notifier/
├── info.txt                              # Module display name
├── openemr.bootstrap.php                 # Entry point: registers namespace + Bootstrap
├── ModuleManagerListener.php             # Install/enable/disable lifecycle hooks
└── src/
    ├── Bootstrap.php                     # Registers Globals settings + event subscriber
    ├── GlobalConfig.php                  # Reads module settings from the globals bag
    ├── Client/AdtHttpClient.php          # OAuth token + POST {"hl7_message": ...}
    ├── Hl7/AdtMessageBuilder.php         # Builds MSH/EVN/PID/PV1 for A01/A03/A04/A08
    └── EventSubscriber/AdtNotifierSubscriber.php  # Maps events to ADT messages
```

---

## Prerequisites: start the dev stack

```bash
cd docker/development-easy
docker compose up --detach --wait
```

App URL: <http://localhost:8300/> (or <https://localhost:9300/>) — login
`admin` / `pass`.

### Apple Silicon note

The `openemr/openemr:flex` image is amd64-only. On an Apple Silicon Mac it runs
under emulation, and Apache can crash-loop with:

```
[core:emerg] (38)Function not implemented: AH00023: Couldn't create the mpm-accept mutex
```

Fix by enabling **Use Rosetta for x86_64/amd64 emulation** in Docker Desktop →
Settings → General, then `docker compose down && docker compose up -d --wait`.
(Fallback: mount an Apache config with `Mutex pthread default` into
`/etc/apache2/conf.d/` on the `openemr` service.)

---

## Enable and configure the module

1. Log in as `admin`.
2. Go to **Modules → Manage Modules**
   (`/interface/modules/zend_modules/public/Installer`).
3. Find **ADT HL7v2 Notifier** under *Unregistered* →
   **Register** → **Install** → **Enable**.
4. Open **Admin → Globals → ADT HL7v2 Notifier** tab and set:

   | Setting                          | Description                                                  |
   | -------------------------------- | ------------------------------------------------------------ |
   | ADT Endpoint URL                 | URL that receives the `{"hl7_message": "..."}` POST          |
   | OAuth Token URL                  | OAuth2 token endpoint (client_credentials grant)             |
   | OAuth Client ID / Client Secret  | Credentials issued by the receiving system (secret encrypted) |
   | OAuth Scope                      | Optional; leave blank if not required                        |
   | HL7 Sending/Receiving App & Facility | MSH-3 … MSH-6 values                                     |
   | HL7 Processing ID (MSH-11)       | `P` production or `T` test/training                          |
   | Send patient ADT (A04/A08)       | Toggle patient create/update notifications                   |
   | Send encounter ADT (A01/A03)     | Toggle encounter create/discharge notifications              |

5. **Log out and back in** so the menu/subscriber wiring reloads.

The module stays inert until the endpoint URL, token URL, client ID, and client
secret are all set.

---

## Functional test

- Create a new patient → endpoint receives a POST with an `ADT^A04` message.
- Edit a patient's demographics → `ADT^A08`.
- (If encounter events enabled) create an encounter → `ADT^A01`; set a discharge
  disposition and save → `ADT^A03`.

Watch the PHP error log for any delivery issues (they are logged, never thrown):

```bash
cd docker/development-easy
docker compose exec -T openemr tail -f /var/log/apache2/error.log
```

---

## Code quality checks

Run from the repo root. Use the in-container form if you don't have a host PHP
toolchain.

```bash
cd docker/development-easy

# PHP syntax
for f in $(find /var/www/localhost/htdocs/openemr/interface/modules/custom_modules/oe-module-adt-notifier -name '*.php'); do
  docker compose exec -T openemr php -l "$f"
done

# Code style (PSR-12 / PER-CS)
docker compose exec -T openemr composer phpcs -- interface/modules/custom_modules/oe-module-adt-notifier

# Static analysis (level 10; run whole, filter to the module)
docker compose exec -T openemr composer phpstan 2>&1 | grep -A3 oe-module-adt-notifier
```

If `openemr-cmd` is installed, the equivalents are `openemr-cmd php-parserror`,
`openemr-cmd psr12-report`, and `openemr-cmd phpstan`.
