# Audit

> **Guidance for contributors (human or agent).** Read before adding to this document.
>
> **Purpose.** This is the Stage 3 hard-gate deliverable. It must contain a full audit
> of the OpenEMR system as it stands, before any AI work is layered on top. Findings
> here are the input to `ARCHITECTURE.md` — every agent design decision should be
> traceable back to something documented in this file.
>
> **Required structure.**
>
> 1. **One-page summary (~500 words) at the very top.** This is a hard requirement.
>    The brevity is intentional: surface only the most impactful findings, the ones
>    that will actually change how the agent gets built. Do not dump everything you
>    found into the summary — that is what the body sections are for.
> 2. **Body sections**, one per audit pass:
>    - **Security** — auth/authorization risks, data exposure vectors, PHI handling, HIPAA-relevant gaps.
>    - **Performance** — bottlenecks, data structure costs, anything that will affect agent latency.
>    - **Architecture** — how the system is organized, where data lives, layer boundaries, integration points for new capabilities.
>    - **Data Quality** — completeness, consistency, missing fields, duplicates, stale data — anything that becomes an agent failure mode.
>    - **Compliance & Regulatory** — audit logging requirements, retention, breach notification, BAA implications of sending PHI to an LLM.
>
> **Tone.**
>
> - Direct and specific. Cite files, tables, endpoints, line numbers when possible.
> - No hedging filler. If a finding is uncertain, say what would confirm it.
> - Impact first, then mechanism, then evidence. A reader should know within one sentence whether a finding matters.
> - Distinguish what was observed from what was inferred.
>
> **What not to include.**
>
> - Generic security/HIPAA background a reader can find anywhere.
> - Restating OpenEMR features without an audit angle.
> - Recommendations for the AI agent — those belong in `ARCHITECTURE.md`.
>
> Remove this guidance block before final submission if it gets in the way; otherwise leave it for future contributors.

## Summary

OpenEMR is a 20+ year-old PHP EHR built on a substrate of legacy procedural code (`library/`, `interface/`) with a partial PSR-4 modernization layered into `src/`. Both layers are live in production code paths simultaneously — modern services `require_once` legacy includes, and a single request commonly mixes ADODB-via-globals (`library/sql.inc.php`) and DBAL-via-`QueryUtils`. The system was designed around a synchronous, session-bound, server-rendered request, and behaviors that make sense in that model are load-bearing across the rest of the audit.

**Authorization is role-coarse.** `AclMain::aclCheckCore(section, value, user)` takes no resource ID — "physician sees their patients" cannot be enforced without new code. The REST bearer-token layer gates capability via OAuth2 scope (`user/Patient.read`), not record identity; `PatientService::search` applies caller-supplied filters with no provider/care-team predicate. Patient-launch SMART scopes *are* resource-bound (the token's subject patient UUID flows into the `WHERE` clause); `user/` scopes are not.

**No identity exists outside the request lifecycle.** The active user is read from `$_SESSION['authUser']` / `authUserID` — 546 such reads across `src/`, `library/`, and `interface/`. There is no parameter-passed principal and no PSR-7 request-scoped identity object available to a backend invoked off the request thread. A process invoked outside a request has no implicit identity, audit attribution, or facility scope.

**PHI reads are not audited unless explicitly enabled.** `EventAuditLogger` short-circuits SELECT events when `audit_events_query` is unset, and each event category (patient-record, lab-results, security-administration, ...) is independently togglable via globals. Audit coverage is configuration-determined, not code-determined. The log itself carries a per-row checksum but no hash chain, and no DB-level append-only enforcement.

**The schema has no enforced referential integrity.** Every "references" in `sql/database.sql` lives in column comments, not constraints. Clinical-fact tables are polymorphic and stringly-typed: `lists.type` (varchar) keys problems / allergies / medications with no `CHECK` constraint and no FK to `issue_types`. Medications dual-store between `lists` (`type='medication'`) and `prescriptions`. Coded fields (RxNorm, ICD, SNOMED) are optional and frequently empty; the always-populated columns are free text. `patient_data` uses `NOT NULL DEFAULT ''` on ~100 columns, conflating empty and unknown.

**Performance shape is read-heavy with thin indexing.** `forms` (encounter→form join hub) has no index on `deleted`. `prescriptions` has no `(patient_id, active)` composite. `audit_master` has only a primary key. `list_options` is queried per-dropdown render with no application cache — `ext-redis` is required as infrastructure but is used only for optional session storage and health-checks, not query caching. The `AllergyIntoleranceService` search produces Cartesian-product duplicates from a 6-way join and deduplicates in PHP via `in_array`.

**Compliance posture is configurable, not enforced.** HTTPS is not required by code (core session cookie defaults `cookie_secure => false`). MFA is opt-in per user, with no role-keyed enforcement. Encryption at rest covers audit comments and on-disk documents only; clinical columns are plaintext. Patient deletion is a hard SQL `DELETE`. Breakglass exists as an audit marker, not a privilege-elevation mechanism. `extended_log` is the schema's nearest equivalent to a disclosure register but is written non-uniformly across egress paths.

## Security

### S1. The CORS listener reflects any Origin and serves credentialed cross-origin requests

[src/RestControllers/Subscriber/CORSListener.php:44-58](../../src/RestControllers/Subscriber/CORSListener.php):

```php
public function onKernelResponse(ResponseEvent $event)
{
    $response = $event->getResponse();
    $request = $event->getRequest();

    if (!$request->headers->has('Origin')) {
        return;
    }
    // we have to allow public API clients to have CROSS ORIGIN access
    // we could tighten things up by restricting confidential clients to not have CORS, but that limits us
    // @TODO: review security implications if we need to tighten this up
    $origins = $request->getHeader('Origin');
    $response->headers->set("Access-Control-Allow-Origin", $origins[0]);
    $event->setResponse($response);
}
```

OPTIONS preflight ([same file, lines 61-77](../../src/RestControllers/Subscriber/CORSListener.php)):

```php
$response = new Response('', Response::HTTP_OK, [
    'Access-Control-Allow-Credentials' => 'true',
    "Access-Control-Allow-Headers" => "origin, authorization, accept, content-type, content-encoding, x-requested-with",
    "Access-Control-Allow-Methods", "GET, HEAD, POST, PUT, DELETE, PATCH, TRACE, OPTIONS"
]);
...
$response->headers->set("Access-Control-Allow-Origin", $origins[0]);
```

Two observations:

1. `Access-Control-Allow-Origin` is set to the request's `Origin` header verbatim with no allowlist. With `Access-Control-Allow-Credentials: true` on the preflight, any origin can issue credentialed cross-origin requests against the REST API.
2. **Bug, observed:** line 69 has `"Access-Control-Allow-Methods", "GET, HEAD, ..."` — comma where `=>` is intended. PHP silently accepts this as positional array entries, so `Access-Control-Allow-Methods` is never sent on the preflight response.

`cookie_samesite=Strict` on the core UI session limits cookie reach. **Not mitigated** for OAuth bearer tokens carried in the `Authorization` header from cross-origin XHR/fetch.

### S2. No session ID rotation on core UI login

```
$ grep -rn "session_regenerate_id\|->invalidate\|->migrate" \
    interface/login/login.php library/auth.inc.php src/Common/Auth/AuthUtils.php
(no matches)
```

`AuthorizationController.php` (OAuth2 flow) calls `$session->invalidate()` in several places, but the **core UI login path** ([interface/login/login.php](../../interface/login/login.php), [library/auth.inc.php](../../library/auth.inc.php), [src/Common/Auth/AuthUtils.php](../../src/Common/Auth/AuthUtils.php)) does not regenerate or invalidate the session ID on successful authentication. A pre-login session ID survives login. Session-fixation primitive available to anyone who can plant a session cookie pre-login.

### S3. Core UI session cookies are non-HttpOnly by deliberate design

[src/Common/Session/SessionConfigurationBuilder.php:83-90](../../src/Common/Session/SessionConfigurationBuilder.php):

```php
public static function forCore(string $webRoot = '', bool $readOnly = true): array
{
    return (new self())
        ->setName(SessionUtil::CORE_SESSION_ID)
        ->setCookiePath((!empty($webRoot)) ? $webRoot . '/' : '/')
        ->setCookieHttpOnly(false)
        ->setReadOnly($readOnly)
        ->build();
}
```

Documented rationale, [src/Common/Session/SessionUtil.php:7-16](../../src/Common/Session/SessionUtil.php):

> For core OpenEMR, need to set cookie_httponly to false, since javascript needs to be able to access/modify the cookie to support separate logins in OpenEMR. This is important to support in OpenEMR since the application needs to robustly support access of separate patients via separate logins by same users.

Trade-off accepted in the codebase: any XSS in the UI directly yields session theft. Portal/OAuth sessions are HttpOnly — this is core-UI-only.

### S4. Twig auto-escaping is disabled globally; output safety is opt-in via `text()` / `attr()`

[src/Common/Twig/TwigContainer.php:66-69](../../src/Common/Twig/TwigContainer.php):

```php
public function getTwig(): Environment
{
    $twigLoader = new FilesystemLoader($this->paths);
    $twigEnv = new Environment($twigLoader, ['autoescape' => false]);
```

Helpers exist at [library/htmlspecialchars.inc.php:209-211, :265-268](../../library/htmlspecialchars.inc.php):

```php
function text($text): string
{
    return htmlspecialchars(($text ?? ''), ENT_NOQUOTES);
}

function attr($text): string
{
    return htmlspecialchars(($text ?? ''), ENT_QUOTES);
}
```

Sampled call sites (e.g. [interface/usergroup/user_admin.php:306](../../interface/usergroup/user_admin.php)) use `attr()` correctly, but enforcement is per-template by convention. A template that omits the filter renders raw data.

### S5. REST error path logs full stack traces and returns exception messages to the client

[apis/dispatch.php:31-44](../../apis/dispatch.php):

```php
} catch (\Throwable $e) {
    error_log($e->getMessage());
    error_log($e->getTraceAsString());
    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(Response::HTTP_INTERNAL_SERVER_ERROR);
    }
    die(json_encode([
        'error' => 'An error occurred while processing the request.',
        'message' => $e->getMessage(),
    ]));
}
```

`getTraceAsString()` includes argument values for each frame. A failing query during a patient lookup will land the SQL string and bound parameters in `error_log` and the message in the JSON response body. CLAUDE.md explicitly forbids exposing `$e->getMessage()` to user-facing output; the top-level dispatcher does so.

### S6. REST authorization is two-layered — neither layer is resource-scoped for clinician (`user/`) tokens

The standard API double-gates each route: per-route `RestConfig::request_authorization_check` (role-coarse OpenEMR ACL — see Architecture A1) plus a global second PEP that fires on every dispatched route. [src/Common/Http/HttpRestRouteHandler.php:140-188](../../src/Common/Http/HttpRestRouteHandler.php):

```php
public function checkSecurity(OEHttpKernel $kernel, HttpRestRequest $restRequest, HttpRestParsedRoute $parsedRoute): ?ResponseInterface
{
    ...
    $scopeType = $restRequest->getRequestUserRole() === 'users' ? 'user' : $restRequest->getRequestUserRole();
    ...
    $restApiSecurityCheckEvent = new RestApiSecurityCheckEvent($restRequest);
    $restApiSecurityCheckEvent->setScopeType($scopeType);
    $restApiSecurityCheckEvent->setResource($resource);
    $restApiSecurityCheckEvent->setPermission($permission);
    $checkedRestApiSecurityCheckEvent = $kernel->getEventDispatcher()->dispatch(...);
```

The listener verifies an OAuth scope string ([src/RestControllers/Subscriber/AuthorizationListener.php:182-193](../../src/RestControllers/Subscriber/AuthorizationListener.php)):

```php
if (empty($event->getResource())) {
    $scope = $scopeType;
} else {
    $scope = $scopeType . '/' . $event->getResource() . '.' . $event->getPermission();
}

$scopeEntity = ScopeEntity::createFromString($scope);
if (!$restRequest->requestHasScopeEntity($scopeEntity)) {
    throw new AccessDeniedException(...);
}
```

A token without `user/Patient.read` cannot read Patient. The scope grants the *capability* to read every Patient — it does not narrow to patients assigned to the holder. `PatientService::search` ([src/Services/PatientService.php:392-416](../../src/Services/PatientService.php)) still returns all matching rows. Neither PEP answers "is this user allowed to access this patient" for `user/`-scope (clinician/admin) tokens.

### S7. Patient-launch SMART scopes are resource-scoped; `user/` scopes are not

The same listener has a separate branch for patient-context tokens. [src/RestControllers/Subscriber/AuthorizationListener.php:143-150](../../src/RestControllers/Subscriber/AuthorizationListener.php):

```php
if ($restRequest->isPatientRequest()) {
    if (empty($restRequest->getPatientUUIDString())) {
        throw new AccessDeniedException("patient", "demo", "Patient UUID is required for patient requests.");
    }
    $scopeType = 'patient';
}
```

For a SMART-on-FHIR patient-launch token (`patient/...` scopes), the bearer-token strategy populates `getPatientUUIDString()` from the token's bound subject ([src/RestControllers/Authorization/BearerTokenAuthorizationStrategy.php:227, :444](../../src/RestControllers/Authorization/BearerTokenAuthorizationStrategy.php)), and downstream FHIR controllers receive `$request->getPatientUUIDString()` as the patient filter. A patient-launch token cannot read other patients.

`user/` scopes take the other branch: no patient binding, no resource filter — back to S6.

**Net effect:** the existing surface enforces per-patient isolation only when tokens are issued with patient-launch scopes. Tokens issued with `user/` scopes bypass per-patient isolation entirely.

### S8. CSRF tokens exist but are not framework-enforced

[src/Common/Csrf/CsrfUtils.php:34-41](../../src/Common/Csrf/CsrfUtils.php) generates a per-session HMAC key. Forms opt in by emitting `<input type="hidden" name="csrf_token_form" value="<?= CsrfUtils::collectCsrfToken(...) ?>">` and handlers opt in by calling `CsrfUtils::checkCsrfInput(...)`. There is no global middleware that rejects unsafe-method requests lacking a token. Combined with S1 (reflective CORS) and S3 (non-HttpOnly cookies), the SameSite=Strict cookie is the dominant defense for the core UI; failing that, CSRF safety is per-handler-by-convention.

### S9. MFA is implemented but opt-in per user

`interface/usergroup/mfa_totp.php` and `interface/usergroup/mfa_u2f.php` exist; [library/auth.inc.php:48](../../library/auth.inc.php) keeps the cleartext password in `$passTemp` "for MFA," but the core login flow does not condition success on a second factor. Inferred (would need a runtime trace through a non-MFA-enrolled user account to confirm with certainty): a fresh user with `admin/super` has no second-factor requirement before reaching every patient record.

### S10. Two REST endpoints are explicitly unauthenticated (information disclosure)

[src/RestControllers/Subscriber/AuthorizationListener.php:95-99](../../src/RestControllers/Subscriber/AuthorizationListener.php):

```php
$skipAuthorizationStrategy->addSkipRoute('/fhir/metadata');
$skipAuthorizationStrategy->addSkipRoute('/fhir/.well-known/smart-configuration');
$skipAuthorizationStrategy->addSkipRoute('/fhir/OperationDefinition');
$skipAuthorizationStrategy->addSkipRoute('/api/version');
$skipAuthorizationStrategy->addSkipRoute('/api/product');
```

The first three are FHIR-spec-required public conformance endpoints. `/api/version` and `/api/product` are not — they expose installation version and product-registration metadata (useful for fingerprinting known-vulnerable releases) without authentication.

## Performance

### P1 — Index gaps on the highest-traffic clinical tables

Each `CREATE TABLE` was inspected directly; the index lists below are the complete set of keys defined on each table.

```
-- sql/database.sql:2460-2478 (forms)
PRIMARY KEY (`id`),
KEY `pid_encounter` (`pid`, `encounter`),
KEY `form_id` (`form_id`)
-- no index on `deleted` or `formdir`

-- sql/database.sql:8748-8751 (prescriptions)
PRIMARY KEY (`id`),
KEY `patient_id` (`patient_id`)
-- no index on `active`, `start_date`, or (patient_id, active)

-- sql/database.sql:8688-8689 (pnotes)
PRIMARY KEY (`id`),
KEY `pid` (`pid`)
-- no index on `date`, `activity`, or `deleted`

-- sql/database.sql:158-162 (audit_master)
PRIMARY KEY (`id`)
-- no index on `pid`, `user_id`, `approval_status`, `type`, or any timestamp

-- sql/database.sql:3887-3905 (list_options)
PRIMARY KEY (`list_id`, `option_id`)
-- no index on `activity`

-- sql/database.sql:8467-8472 (patient_data)
UNIQUE KEY `pid` (`pid`),
UNIQUE KEY `uuid` (`uuid`),
KEY `idx_patient_name` (`lname`, `fname`),
KEY `idx_patient_dob` (`DOB`),
KEY `id` (`id`)
-- no index on `email`, `ss`, or `pubpid`
```

`forms` is the encounter→form join hub; almost every read filters by `deleted = 0`. `prescriptions` has no support for "active medications for patient." `audit_master` has no index for any non-PK lookup. `list_options` (queried per-dropdown render — see P5) has no index on the `activity` filter every read applies. Patient duplicate-detection by SSN/email/pubpid is a full scan.

### P2 — `lists` lacks the composite that matches its workhorse query

The polymorphic `lists` table is read by `WHERE pid = ? AND type = ? AND activity = ?` for problems, allergies, medications, and surgeries. The schema offers two independent single-column indexes and no composite, and `activity` is unindexed (and nullable per D3).

```sql
-- sql/database.sql:7708-7712
PRIMARY KEY (`id`),
KEY `pid` (`pid`),
KEY `type` (`type`),
UNIQUE KEY `uuid` (`uuid`)
```

The optimizer picks one of `pid` or `type` and filters the remainder in the storage engine.

### P3 — Allergy search joins six tables and deduplicates in PHP via `in_array`

`src/Services/AllergyIntoleranceService.php:52-138` issues a 6-way `LEFT`/`RIGHT JOIN` over `lists`, `list_options` (twice), `patient_data`, `users`, and `facility`, returns Cartesian-product duplicates by construction, and deduplicates in PHP using `in_array` over a flat array.

```php
// src/Services/AllergyIntoleranceService.php:130-135 (excerpted)
foreach ($processingResult->getData() as $row) {
    if (!in_array($row['uuid'], $temp_uuid_array)) {
        $temp_uuid_array[] = $row['uuid'];
        ...
    }
}
```

The PHP-side dedup is O(N²) on the result-set size — `in_array` is a linear scan over a non-indexed array — so a patient with many allergy entries pays quadratic cost on top of the join.

### P4 — Pagination is offset-based, not keyset

```php
// src/Common/Database/QueryPagination.php:40-52 (excerpted)
$offsetId = $request->getOffset();
...
$nextOffsetId = $offsetId + $limit;
```

The pagination primitive used across the REST/FHIR layer issues `LIMIT … OFFSET …`. There is no cursor/keyset alternative. Page N of any large result requires the engine to skip `N × limit` rows before returning the requested window.

### P5 — `list_options` is queried per-dropdown with no application-level cache

`SELECT * FROM list_options WHERE list_id = ? AND activity = ?` (or near variants) appears at:

```
library/options.inc.php:233, 297, 314, 333, 500, 1063, 1106, 1154, 1234
src/Services/ImmunizationService.php:140
src/Services/EmployerService.php:89, 97
src/Services/ContactRelationService.php:321
src/Services/PractitionerRoleService.php:215
src/Services/ObservationService.php:376, 383
```

Every dropdown render issues an independent query. `list_options` has no index on `activity` (P1), so each query scans the `list_id` prefix and filters in-engine. There is no memoization layer between the call sites and the database — forms with many dropdowns produce many independent round-trips on each request.

### P6 — Redis is required but is not an application cache

`composer.json` requires `ext-redis`, and OpenEMR ships Redis-aware code, but a full search of `src/` and `library/` shows Redis used only for two purposes:

- Optional Symfony Sentinel-backed session storage — `src/Common/Session/Predis/SentinelUtil.php` (the `LockingRedisSessionHandler` referenced at lines 141-151 and 172).
- Health-check probing — `src/Health/Check/CacheCheck.php:57-98` and `src/Health/Check/SessionCheck.php:48-56`.

```php
// src/Health/Check/CacheCheck.php:43
// No Redis configured - report as healthy
```

There are no `Redis::get`/`set` calls for query results, ACL decisions, FHIR `metadata` / `smart-configuration` payloads, `list_options`, or any other application data. The "cache" health check passes when Redis is absent, consistent with Redis being optional infrastructure rather than a load-bearing cache. The caches that do exist in code (`TranslationCache`, `FormLocator::pathCache`, `CryptoGen::keyCache`) are all per-request, in-process.

### P7 — Two parallel DB surfaces still coexist

`library/sql.inc.php:59-63` instantiates an ADODB connection at request bootstrap and stores it in `$GLOBALS['adodb']['db']` and `$GLOBALS['dbh']`; legacy code calls `sqlStatement` / `sqlQuery` / `sqlFetchArray` against this global.

```php
// library/sql.inc.php:59-63
$config = DatabaseConnectionOptions::forSite($GLOBALS['OE_SITE_DIR']);
$persistent = DatabaseConnectionFactory::detectConnectionPersistenceFromGlobalState();
$database = DatabaseConnectionFactory::createAdodb($config, $persistent);
$GLOBALS['adodb']['db'] = $database;
$GLOBALS['dbh'] = $database->_connectionID;
```

The modern path (`DatabaseConnectionFactory` → DBAL via `QueryUtils`) coexists; `sqlStatement` itself now delegates to `QueryUtils::sqlStatementThrowException` (`library/sql.inc.php:96-100`). A single request commonly mixes both styles — modern services such as `EncounterService` `require_once "../../library/forms.inc.php"` and then call `sqlQuery` directly inside class methods (cite `src/Services/EncounterService.php:39-40, 449`). Bootstrap pays for ADODB regardless of whether the request ends up using it, and clinical paths interleave two different result-set abstractions.

## Architecture

### A1. Authorization is role-coarse, not resource-scoped — and the service layer does no scoping either

REST routes guard themselves with a single section/value ACL check. Example, [apis/routes/_rest_routes_standard.inc.php:99-104](../../apis/routes/_rest_routes_standard.inc.php):

```php
"GET /api/patient/:puuid" => function ($puuid, HttpRestRequest $request) {
    RestConfig::request_authorization_check($request, "patients", "demo");
    $return = (new PatientRestController())->getOne($puuid, $request);
    return $return;
},
```

`request_authorization_check` ([src/RestControllers/Config/RestConfig.php:180-194](../../src/RestControllers/Config/RestConfig.php)) resolves to:

```php
public static function request_authorization_check(HttpRestRequest $request, $section, $value, $aclPermission = ''): void
{
    self::authorization_check($section, $value, $request->getSession()->get("authUser"), $aclPermission);
}

public static function authorization_check($section, $value, $user = '', $aclPermission = ''): void
{
    $result = AclMain::aclCheckCore($section, $value, $user, $aclPermission);
    ...
}
```

`AclMain::aclCheckCore` ([src/Common/Acl/AclMain.php:166](../../src/Common/Acl/AclMain.php)) takes only `(section, value, user, return_value)` — **the patient/encounter/resource ID is never passed in**. The model is "user X has permission category Y," not "user X may access patient Z."

The service layer does not narrow either. `PatientService::getAll` / `search` ([src/Services/PatientService.php:392-416](../../src/Services/PatientService.php)) accept caller-supplied filters and run them; there is no provider-assignment, care-team, or facility predicate added by the service. Any caller that passes the role-coarse ACL receives the full result set.

**Implication:** "is this user allowed to see this patient?" is not a question the existing code answers. Any new caller that fans out across patients must answer it in the caller, not rely on the services.

### A2. Identity is session-bound; the entire stack assumes an HTTP/session context

The current user is read from the session on the request path (see `request_authorization_check` above: `$request->getSession()->get("authUser")`). `authUser` is a username string, not a numeric ID; numeric ID is `authUserID`, also session-resident.

```
$ grep -rE "session.*->get\(['\"]authUser" src interface library | wc -l
546
```

There is no parameter-passed principal anywhere in the service surface. `BaseService::__construct` ([src/Services/BaseService.php:65-73](../../src/Services/BaseService.php)) does not take a user:

```php
public function __construct(
    private $table,
    ?LoggerInterface $logger = null,
) {
    ...
    $this->eventDispatcher = OEGlobalsBag::getInstance()->getKernel()->getEventDispatcher();
}
```

The kernel itself comes from a global singleton (`OEGlobalsBag::getInstance()->getKernel()`).

**Inferred (would need runtime confirmation):** a service invoked from a non-HTTP context — CLI, queue worker, sidecar process — has no implicit identity, and most ACL checks will read `authUser` as empty and deny.

### A3. PSR-4 "modern" and `library/` "legacy" layers are interleaved at file level, not separated

`CLAUDE.md` frames `src/` as modern PSR-4 and `library/` as legacy procedural, with new code going in `src/`. In practice they are not isolated. [src/Services/EncounterService.php:39-40](../../src/Services/EncounterService.php):

```php
require_once __DIR__ . "/../../library/forms.inc.php";
require_once __DIR__ . "/../../library/encounter.inc.php";
```

Inside the same class, [src/Services/EncounterService.php:449](../../src/Services/EncounterService.php):

```php
$result = sqlQuery("SELECT sensitivity FROM form_encounter WHERE encounter = ?", [$encounter]);
```

`sqlQuery`/`sqlStatement` are legacy global functions in [library/sql.inc.php:96-103](../../library/sql.inc.php), which die on error and operate on a globally cached ADODB handle. Modern services therefore inherit legacy-layer side effects: global DB state, connection lifecycle, error-handling-by-`die`.

### A4. Two parallel request lifecycles — UI and REST — share the same DB and session layers

- **UI:** entry at any `interface/**.php` page → `require_once 'interface/globals.php'` → `SessionWrapperFactory::getInstance()->getActiveSession()` → procedural page emits HTML directly or via `TwigContainer`. No central dispatcher; each `.php` file is its own controller.
- **REST/FHIR:** entry at [apis/dispatch.php:27-30](../../apis/dispatch.php):

  ```php
  $request = HttpRestRequest::createFromGlobals();
  ...
  $apiApplication = new ApiApplication();
  $apiApplication->run($request);
  ```

  `ApiApplication` builds a Symfony `HttpKernel` and registers listeners (`SiteSetupListener`, `OAuth2AuthorizationListener`, `AuthorizationListener`, `RoutesExtensionListener`, `ViewRendererListener`). Routes are returned as a closure map from [apis/routes/_rest_routes_standard.inc.php:50](../../apis/routes/_rest_routes_standard.inc.php).

Both paths converge on `library/sql.inc.php` for DB access and `SessionWrapperFactory` for identity. There is no shared MVC framework above that.

### A5. `$GLOBALS` and the `OEGlobalsBag` singleton are pervasive shared state

```
$ grep -rE 'GLOBALS\[' src library interface | wc -l
252
```

Most-cited keys are config (`webroot`, `OE_SITE_DIR`, `fileroot`, `style`) but also include the live DB handle. [library/sql.inc.php:64-73](../../library/sql.inc.php):

```php
$config = DatabaseConnectionOptions::forSite($GLOBALS['OE_SITE_DIR']);
$persistent = DatabaseConnectionFactory::detectConnectionPersistenceFromGlobalState();
$database = DatabaseConnectionFactory::createAdodb($config, $persistent);
$GLOBALS['adodb']['db'] = $database;
$GLOBALS['dbh'] = $database->_connectionID;
```

`OEGlobalsBag` ([src/Core/OEGlobalsBag.php](../../src/Core/OEGlobalsBag.php)) is a typed wrapper around the same `$GLOBALS` array — it reads through to PHP's superglobal rather than replacing it.

**Implication:** process-level state contaminates between requests in any long-lived worker. Per-request isolation depends on PHP's "fresh process per request" assumption (PHP-FPM, mod_php) and breaks under any sticky-process model without explicit reset hooks.

### A6. Extension surfaces are EventDispatcher listeners, modules, and Twig events

Real extension points exist:

- Symfony `EventDispatcher` attached to the Kernel ([src/Core/Kernel.php:226-234](../../src/Core/Kernel.php)). Domain events fire from services (e.g. `PatientCreatedEvent`, `BeforePatientUpdatedEvent`) and from the REST kernel lifecycle (`kernel.request`, `kernel.controller`, `kernel.view`, `kernel.finish_request`).
- `TwigEnvironmentEvent::EVENT_CREATED` fires when a Twig environment is constructed ([src/Common/Twig/TwigContainer.php](../../src/Common/Twig/TwigContainer.php)) — listeners can register filters/functions.
- `TemplatePageEvent` ([src/Events/Core/TemplatePageEvent.php](../../src/Events/Core/TemplatePageEvent.php)) lets listeners modify template variables or override the template before render.
- Two module trees: `interface/modules/custom_modules/` and `interface/modules/zend_modules/` (Zend Framework MVC, legacy).

These are decorator-style hooks. None of them add per-resource access control to the data they touch — they extend behavior, they do not gate it.

### A7. ADODB is the active DB driver; Doctrine DBAL is loaded but not the runtime surface

`composer.json` declares both `adodb/adodb-php: ^5.22.11` and `doctrine/dbal: ^4.4`. The runtime path is ADODB: [library/sql.inc.php:71](../../library/sql.inc.php) creates `DatabaseConnectionFactory::createAdodb(...)`, and `QueryUtils` delegates to ADODB recordsets. Service-layer code calls legacy `sqlQuery` / `sqlStatement` functions (see A3).

Inferred from absence of DBAL `Connection` references in service code; would be confirmed by tracing the live DB handle through a request. CLAUDE.md's "MySQL via Doctrine DBAL 4.x (ADODB surface API for legacy code)" framing reads, on the code as written, as DBAL-loaded-not-DBAL-driven.

## Data Quality

### D1 — No foreign key constraints anywhere in the schema

`grep -i "FOREIGN KEY\|REFERENCES" sql/database.sql` returns only `COMMENT 'references users.id'`-style hints; there is no enforced referential integrity. Every cross-table relationship — patient → encounter, encounter → provider, list entry → patient, prescription → patient — is enforced (or not) at the application layer.

```
-- sql/database.sql:2473
`issue_id` bigint(20) NOT NULL default 0 COMMENT 'references lists.id to identify a case',
`provider_id` bigint(20) NOT NULL default 0 COMMENT 'references users.id to identify a provider',

-- sql/database.sql:10372
`provider_id` bigint(20) NOT NULL DEFAULT 0  COMMENT 'references users.id, the ordering provider',
`patient_id`  bigint(20) NOT NULL            COMMENT 'references patient_data.pid',
```

Reference targets live in column comments, not constraints. Deleting a patient does not cascade; orphan rows accumulate silently. Provider-ID columns default to `0`, so an unset author is indistinguishable from one whose ID happens to be 0.

### D2 — Triple-identifier surface on `patient_data` plus a non-unique external ID

`patient_data` carries four identifiers at once.

```sql
-- sql/database.sql:8335-8408 (excerpted)
CREATE TABLE `patient_data` (
  `id`     bigint(20)  NOT NULL auto_increment,
  `uuid`   binary(16)  DEFAULT NULL,
  ...
  `pubpid` varchar(255) NOT NULL default '',
  `pid`    bigint(20)   NOT NULL default '0',
  ...
  UNIQUE KEY `pid`  (`pid`),
  UNIQUE KEY `uuid` (`uuid`),
```

`id` (auto-increment PK), `pid` (separate `bigint`, also `UNIQUE`, and the column every other clinical table uses as its FK target), `uuid` (`binary(16)`, returned by REST/FHIR), and `pubpid` (user-facing external identifier — **not** unique, default empty string). Three internal IDs and one external ID, only one of which (`uuid`) is API-safe.

### D3 — Soft-delete pattern is inconsistent across clinical tables

The same conceptual flag is encoded four different ways.

```
-- sql/database.sql
:7688  `lists`.`activity`           tinyint(4) default NULL
:8677  `pnotes`.`activity`          tinyint(4) default NULL
:8681  `pnotes`.`deleted`           tinyint(4) default 0
:10380 `procedure_order`.`activity` tinyint(1) NOT NULL DEFAULT 1 COMMENT '0 if deleted'
:8725  `prescriptions`.`active`     int(11)    NOT NULL default '1'
```

`lists.activity` is nullable with no documented semantics for `NULL`. `pnotes` carries both `activity` and `deleted`. `prescriptions` uses a different column name (`active`) and a different type. A naive `WHERE activity = 1` filter behaves differently against each table; against `lists` it silently excludes any row where `activity IS NULL`.

### D4 — `lists` is a polymorphic clinical table with no enforced vocabulary

Problems, allergies, medications, and surgeries all live in `lists`, keyed by a free-text `type` column with no `CHECK` constraint and no FK to `issue_types`.

```sql
-- sql/database.sql:7675
`type` varchar(255) default NULL,
```

```php
// src/Services/AllergyIntoleranceService.php:270-273
$sql  = " INSERT INTO lists SET";
$sql .= "     date=NOW(),";
$sql .= "     activity=1,";
$sql .= "     type='allergy',";
```

Service code injects the literal string. A typo (`'allergee'`) or a `NULL` is silently accepted, and any downstream filter `WHERE type = 'allergy'` will miss the row. There is no DB-level constraint that `lists.type` is one of the eight or so values service code uses.

### D5 — Medications live in two parallel tables that aren't required to agree

`lists` (with `type='medication'`) holds medication entries. `prescriptions` is a separate table with its own primary key, its own soft-delete column (`active`), and its own audit columns. `lists_medication` is a junction table with an *optional* `prescription_id` linking the two.

```
-- sql/database.sql
:7671  CREATE TABLE `lists`            -- includes type='medication' rows
:8698  CREATE TABLE `prescriptions`    -- separate PK, separate soft-delete
:7717  CREATE TABLE `lists_medication` -- prescription_id BIGINT(20) DEFAULT NULL
```

A medication can be inserted into one table without the other. There is no DB-enforced invariant tying the two together; "the patient's medication list" is the union of two stores with different schemas, different audit columns, and different soft-delete semantics.

### D6 — Coded clinical fields exist but are optional and not the always-populated copy

For prescriptions:

```sql
-- sql/database.sql:8710-8711 (within prescriptions)
`drug`            varchar(150) default NULL,
`drug_id`         int(11) NOT NULL default '0',
`rxnorm_drugcode` varchar(25) DEFAULT NULL,
```

`drug` (free-text drug name) is the field that gets written on every insert; `rxnorm_drugcode` is `DEFAULT NULL` and population depends on the entry path.

```php
// src/Services/PrescriptionService.php:349-350
if ($record['rxnorm_drugcode'] != "") {
    $codes = $this->addCoding($row['rxnorm_drugcode']);
}
```

`lists.diagnosis varchar(255)` is free text with no paired code column on the table itself — structured ICD/SNOMED codes for problems, when they exist, live in adjacent code-mapping tables rather than on the row. RxNorm- or ICD-grounded search against the primary table will silently miss any entry that wasn't coded at write time.

### D7 — `patient_data` uses `NOT NULL DEFAULT ''` on ~100 demographic columns

```sql
-- sql/database.sql:8340-8408 (excerpted)
`fname`        varchar(255) NOT NULL default '',
`lname`        varchar(255) NOT NULL default '',
`street`       varchar(255) NOT NULL default '',
`postal_code`  varchar(255) NOT NULL default '',
`email`        varchar(255) NOT NULL default '',
`phone_home`   varchar(255) NOT NULL default '',
`phone_biz`    varchar(255) NOT NULL default '',
`ss`           varchar(255) NOT NULL default '',
```

`PatientValidator` enforces `fname`/`lname` on insert; the rest can persist as empty string. Empty-string and unknown-value are indistinguishable, and `IS NULL` filters won't find missing data. Tables follow this convention broadly, so "fields the user actually filled in" cannot be derived from the schema.

### D8 — Audit-column conventions vary by table

```
-- sql/database.sql
:7691-7692  `lists`.`user`         varchar(255), `groupname` varchar(255)
:8675-8676  `pnotes`.`user`        varchar(255), `groupname` varchar(255)
:3251-3252  `immunizations`.`created_by` bigint(20), `updated_by` bigint(20)
:8727,8746  `prescriptions`.`user` VARCHAR(50), `created_by` BIGINT(20), `updated_by` BIGINT(20)
:2056       `form_encounter`.`last_update` timestamp   -- no user column
```

Some tables identify the author by username string; others by numeric FK to `users.id`; `prescriptions` carries both; `form_encounter` has only a timestamp. The "who changed this row" question has no uniform answer across the schema and no schema-level guarantee that the value, where present, points at a real user.

## Compliance & Regulatory

### C1 — Audit log architecture: three tables, per-row checksum, no chain

The audit subsystem writes to three tables.

```sql
-- sql/database.sql (log)
CREATE TABLE `log` (
  `id` bigint(20) NOT NULL auto_increment,
  `date` datetime default NULL,
  `event` varchar(255), `category` varchar(255),
  `user` varchar(255), `groupname` varchar(255),
  `comments` longtext, `user_notes` longtext,
  `patient_id` bigint(20),
  `success` tinyint(1) default 1,
  `checksum` longtext,
  ...
  PRIMARY KEY (`id`), KEY `patient_id` (`patient_id`)
);

-- sql/database.sql (log_comment_encrypt)
`encrypt` enum('Yes','No') NOT NULL DEFAULT 'No',
`checksum` longtext,
`checksum_api` longtext,
`version` tinyint(4) NOT NULL DEFAULT '0' COMMENT '0 for mycrypt and 1 for openssl',

-- sql/database.sql (api_log)
`request_url` text, `request_body` longtext, `response` longtext,
`user_id`, `patient_id`, `ip_address`, `method`,
```

`log` carries a per-row `checksum` column, computed in PHP and stored alongside the data it covers. There is no `prev_hash` / chain column linking entries; integrity is per-row, not per-sequence.

### C2 — No DB-level append-only enforcement on the audit log

The `log` table has no triggers preventing UPDATE/DELETE, no separate audit-only DB user, and no column constraints enforcing immutability. A repository-wide search finds no `UPDATE log` / `DELETE FROM log` statements in source — but no schema-level barrier prevents either. Integrity rests on (a) per-row checksum (which can be recomputed by an attacker who already has DB write access) and (b) the application's own discipline.

### C3 — SELECT auditing is implemented but gated off by default

`EventAuditLogger` short-circuits SELECT events when the `audit_events_query` global is unset.

```php
// src/Common/Logging/EventAuditLogger.php:425
if (($querytype == "select") && !$this->config->queryEvents) {
    return;
}

// src/Common/Logging/EventAuditLogger.php:73
queryEvents: $bag->getBoolean('audit_events_query'),
```

The capability exists in code, but PHI **reads** are not audited unless the deployment has explicitly enabled the flag.

### C4 — Audit coverage is configured per category, not enforced by code

```php
// src/Common/Logging/EventAuditLogger.php:76-82
'patient-record'          => $bag->getBoolean('audit_events_patient-record'),
'scheduling'              => $bag->getBoolean('audit_events_scheduling'),
'order'                   => $bag->getBoolean('audit_events_order'),
'lab-order'               => $bag->getBoolean('audit_events_lab-order'),
'lab-results'             => $bag->getBoolean('audit_events_lab-results'),
'security-administration' => $bag->getBoolean('audit_events_security-administration'),
'other'                   => $bag->getBoolean('audit_events_other'),
```

Each category is independently togglable. A deployment with these off will silently audit nothing in those categories. Coverage is configuration-determined, not code-determined.

### C5 — Encryption at rest is opt-in and narrow in scope

`log_comment_encrypt.encrypt` defaults `'No'`. The application-level `CryptoGen` (`src/Common/Crypto/CryptoGen.php`) implements AES-256-CBC + HMAC-SHA384 with a dual keystore (DB-stored keys encrypted by on-disk keys, and vice versa) and versioned key rotation, but it is invoked only for audit comments (when enabled) and document storage in `sites/[site]/documents/`. Clinical tables — `patient_data`, `lists`, `prescriptions`, `pnotes`, `form_encounter` — are plaintext at rest. No `AES_ENCRYPT` or column-encryption wrapper appears in the schema or service writes.

### C6 — HTTPS is not enforced in code; core session cookie defaults to non-Secure

A repository-wide search for `force_ssl`, `HSTS`, `Strict-Transport-Security`, `require_ssl` across `src/`, `library/`, and `interface/` returns no application-level enforcement.

```php
// src/Common/Session/SessionConfigurationBuilder.php:26
'cookie_secure' => false,

// :100 (OAuth session path)  ->setCookieSecure(true)
// :110 (API session path)    ->setCookieSecure(true)
```

The OAuth and REST/API session paths set Secure; the core UI session does not. TLS termination and HTTP→HTTPS redirection are left to the deployment / reverse proxy.

### C7 — Brute-force lockout is real, MFA is opt-in, no MFA gate on PHI access

```sql
-- sql/database.sql (users_secure)
`password_history1` varchar(255), ... `password_history4` varchar(255),
`total_login_fail_counter` bigint DEFAULT 0,
`login_fail_counter` INT(11) DEFAULT '0',
`last_login_fail` datetime DEFAULT NULL,
`auto_block_emailed` tinyint DEFAULT 0,

-- sql/database.sql (login_mfa_registrations)
`user_id`, `name`, `last_challenge`, `method`,
`var1` varchar(4096) COMMENT 'Question, U2F registration etc.',
`var2` varchar(256)  COMMENT 'Answer etc.',
PRIMARY KEY (`user_id`, `name`)
```

`AuthUtils.php:1115-1320` consumes these counters and a `password_timeout_for_login_fail_counter` reset window. MFA is registered per-user; no code path requires MFA before a user can read PHI, and there is no role-keyed enforcement (e.g. clinical staff required, patients optional).

### C8 — Patient deletion is a hard SQL `DELETE`

```php
// interface/patient_file/deleter.php:82
$query = "DELETE FROM " . escape_table_name($table) . " WHERE $where";
```

The deletion path emits `EventAuditLogger::newEvent("delete", ...)` before the row is destroyed, but there is no soft-delete column, no retention hold, no trash-bin. A patient deletion is irreversible from within the application.

### C9 — Breakglass / emergency-access exists as an audit-marking subsystem only

```php
// src/Common/Logging/EventAuditLogger.php:72
forceBreakglass: $bag->getBoolean('gbl_force_log_breakglass'),

// src/Common/Logging/BreakglassChecker.php:58
$result = $this->conn->fetchOne($sql, ['breakglass', $username]);
```

`BreakglassChecker.php:39` resolves whether a user belongs to a `breakglass` ACL group, and the audit logger flags those users' events. There is no privilege-elevation API tied to breakglass — the system marks events from those users in audit, it does not gate or grant additional access. Whether a given user can read a given chart is decided by the same ACL/scope checks as any other user.

### C10 — `extended_log` is the closest thing to a disclosure-log table

```sql
-- sql/database.sql:12414 (extended_log)
CREATE TABLE `extended_log` (
  `id` bigint(20) NOT NULL auto_increment,
  `date` datetime default NULL,
  `event` varchar(255) default NULL,
  `user` varchar(255) default NULL,
  `recipient` varchar(255) default NULL,
  `description` longtext,
  `patient_id` bigint(20) default NULL,
  PRIMARY KEY (`id`), KEY `patient_id` (`patient_id`)
);
```

Schema includes `recipient` and `patient_id` — the shape of a HIPAA "accounting of disclosures" register — but no service uniformly writes to it on every PHI egress. Whether a CCDA export, FHIR `$export`, or outbound email lands a row depends on the specific code path that triggered it.
