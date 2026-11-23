# Meros CRM

A Salesforce-focused CRM package for the Meros framework.

## Scope

This package owns Salesforce-specific integrations, external models, and form actions.

## Microsoft 365 email draft

`Microsoft365` is an opt-in integration draft; register it in the package before use:

```php
use MM\Meros\Crm\App\Integrations\Microsoft365;

$this->integrations(Microsoft365::class)->make();
```

In Microsoft Entra, register a web application for the tenant, add the integration's
Return URL as a web redirect URI, and grant the delegated Microsoft Graph `Mail.Send`
permission. Configure the tenant ID, client ID and client secret in Meros, enable the
integration, and connect the mailbox that should send messages. Admin consent may be
required by your organization. The connection is shared across Meros users.

Once registered and connected, use the integration through the container:

```php
app(Microsoft365::class)->sendMail(['recipient@example.com'], 'Hello', 'Message body');
```

Pass `true` as the fourth argument for an HTML body. Graph's `202 Accepted` response
means the message was accepted for processing, not that it was delivered. This draft
does not replace WordPress mail or include attachments, CC or BCC.

## Salesforce record writes

The facade supports creating and updating records using the same configured object
names and nicknames as GET queries:

```php
use MM\Meros\Crm\Facades\Salesforce;

$result = Salesforce::contacts()->create([
    'FirstName' => 'Example',
    'LastName' => 'Contact',
    'Email' => 'example@example.com',
]);

if ($result !== null) {
    $updated = Salesforce::contacts($result->id)->update(['Email' => null]);
}
```

`create(array $data)` sends a JSON POST and returns Salesforce's result object
(`id`, `success`, `errors`), or `null` on failure. `update(array $data)` requires a
selected record ID, sends a JSON PATCH, and returns `true` for a successful empty
response or `false` on failure. Neither operation performs a follow-up GET.
Creation must not have a record ID selected; updates cannot target a `where()` query
instead of an ID. These methods require an enabled, connected integration and an
object in **Queryable Objects**; metadata query selectors cannot be used for writes.
Rejected dynamic object lookups clear the previous target.

Data must be a field-name-keyed array, not a bulk record list. Field API names and
values are sent unchanged, including explicit nulls, false and zero. Empty arrays
are sent as JSON objects. The configured object field selection also restricts writes:
`All` (the editor default) permits any field, while an explicit list rejects the
entire write if any payload field is unlisted, logging the field and object before
any HTTP request. This uses the resolved query field list, including its automatically
added `Id`/`Name` fields, with case-sensitive field API names. Salesforce still
validates required fields, field permissions, and values.
Failures are logged using the integration's existing error handling. Writes reuse
the single token-refresh retry for an invalid session; other failures are not retried.

## Salesforce flow invocation
-0
`Salesforce::invokeFlow($flowName, $inputs, $awaitResponse = true)` posts a single invocation
to `/services/data/vXX.X/actions/custom/flow/{flowName}` using the connected account.
The flow must be an active autolaunched flow configured in Automated Flows.

With awaiting enabled it returns the single Salesforce action result, including
`isSuccess` and `outputValues`. Inputs and outputs are validated using the cached
Salesforce variable schemas. Only declared outputs are returned; omitted optional
outputs remain absent and explicit nulls are preserved. Numeric outputs declared as
Salesforce Number/Double/Currency/Percent are normalized to PHP floats, without
coercing strings or booleans. Collections are validated item by item.
Failures return an error string and are logged; an inactive connection or an
unconfigured flow returns `null`.

`isSuccess` indicates a successful invocation, not necessarily a finished interview:
inspect `outputValues.Flow__InterviewStatus` for `Finished` or `Waiting`.
Awaiting waits for the invocation response, not for a paused interview or later
asynchronous work. Those require a separate completion/callback design.

The components orchestrator registers an action for each configured flow. Its
**Await Response** checkbox defaults to enabled. Awaited outputs are exposed by name
to later actions through `FlowActionResponse` and the registered output schema.
The checkbox is Meros-only and is never sent as a Salesforce variable; reserve the
input name `await_response` for this control.

Salesforce variable names are case-sensitive and are preserved in API requests
(for example, `firstName`). The action editor stores its control names in snake case
(`first_name_dynamic_value`); the runners map these controls back to the original
schema names. Previously saved configurations containing mixed original/snake-case
control names are also supported without re-saving the action.

With `$awaitResponse = false`, the method returns `['queued' => true]` after
enqueueing `InvokeSalesforceFlow`. The action immediately continues with **no output
values**; subsequent actions must not depend on Salesforce outputs in this mode.
Queue failures are surfaced, not silently replaced with synchronous execution.

The CRM service provider configures a dedicated `meros_crm` database queue connection
using the `meros_crm_jobs` table and the `meros_crm` queue. It uses the application's
default database connection, matching the package table migration, and does not
change `queue.default` or require changing `QUEUE_CONNECTION`.

Install the optional **meros_crm_jobs** table through the MerosCRM package's table
management screen before enabling background mode. The service provider automatically
schedules a WP-Cron queue drain every minute. No CLI worker is needed for this default.
Processing is skipped until the table exists, and a per-site MySQL advisory lock
prevents overlapping cron drains. The lock is released when the callback finishes
or its database connection closes, without a lease expiring during a running job.

Each cron run calls Laravel's `queue:work` in-process with:

```bash
wp acorn queue:work meros_crm --queue=meros_crm --stop-when-empty --max-time=20 --sleep=0 --tries=1 --timeout=60
```

The 20-second budget is checked **between jobs**, not a hard request deadline.
Remaining work is picked up on a later tick. The 60-second job timeout requires
PCNTL, which is often unavailable in web PHP; it is not guaranteed by WP-Cron.
Hosting execution limits must allow a running job to finish.

WP-Cron depends on site traffic and working loopback requests, so processing can be
delayed on quiet sites. With `DISABLE_WP_CRON` enabled, a server scheduler must trigger
WordPress cron. For higher volume or reliable timeout enforcement, use a supervised
CLI worker instead and disable the automatic drain:

```php
add_filter('meros_crm_queue_cron_enabled', '__return_false');
```

Register this filter before WordPress `init`; the provider removes its scheduled event
when disabled. Then run:

```bash
wp acorn queue:work meros_crm --queue=meros_crm --tries=1 --timeout=60
```

The connection dispatches after database transactions commit and reserves jobs for
90 seconds, longer than the job's 60-second timeout. Failed-job logging continues
to use the application's existing `queue.failed` configuration; provision its storage
separately. The scheduled drain starts short-lived workers, not a persistent process.

Queued jobs contain the flow name, validated inputs and environment, never OAuth
tokens or integration instances. Inputs are resolved and validated before dispatch;
the worker sends that exact snapshot without re-filtering it through its cached
variable schema or resolving form dynamic values again. Normal calls should use
`invokeFlow()`; `runQueuedFlow()`
is the execution entry point for these already-validated jobs.

The worker resolves the integration from the application container, checks that the
flow is still configured and connected, and fails the job if its active environment
has changed. Restart long-lived workers after changing code or integration settings.
Jobs have one attempt because a timeout can occur after Salesforce commits changes:
do not manually retry an ambiguous
failure without checking for duplicate side effects. Queued results are not persisted
or injected into the original runner, and queuing does not provide exactly-once execution.

## Action Types

The package provides two patterns for CRM writes:

- `create_salesforce_contact`: typed, explicit action that uses a dedicated external model (`SalesforceContacts`) and a fixed contact payload contract.
- `run_crm_sync_jobs`: configurable action for one or more generic write jobs using integration handle + object/endpoint + mapping rows.

Use `create_salesforce_contact` when you want predictable contact behavior and cleaner code-level guarantees.

Use `run_crm_sync_jobs` when you need admin-configurable object targets or multiple writes without creating a new action class.

## License

MIT
