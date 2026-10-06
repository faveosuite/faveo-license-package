# Using the licence in your app

- [The facade](#the-facade)
- [Routes and views](#routes-and-views)
- [Entitlements](#entitlements)
- [The Status object](#the-status-object)
- [Building a licence page](#building-a-licence-page)

## The facade

```php
use Faveo\LicenseClient\Laravel\Facades\License;

License::ok();                            // licence valid (active, or in grace)
License::allows('sla');                   // feature granted
License::limit('agents');                 // ?int, null = unlimited
License::withinLimit('agents', $count);   // $count <= limit
License::expiresAt('updates');            // ?DateTimeImmutable
License::for('calendar')->allows('sync'); // add-on licence

$status = License::status();              // Faveo\LicenseClient\Status
License::message($status);                // translated text for the customer
```

The status is checked at most once per request. Every method takes an add-on name as its last argument, or use `License::for('addon')` for the `Status` of a plugin.

## Routes and views

```php
Route::middleware('license')->group(...);                 // 403 unless the licence is valid
Route::middleware('license.feature:sla')->group(...);     // 403 unless the feature is granted
Route::middleware('license:calendar')->group(...);        // add-on licence
```

```blade
@licensed('reports')
    <a href="{{ route('reports.index') }}">Reports</a>
@endlicensed
```

`@licensed` with no argument checks the licence is valid. With a feature name it also checks the feature.

### Showing your own blocked page

The middleware throws `Faveo\LicenseClient\Laravel\LicenseException`, a 403 carrying `$status` and `$feature`. To show your own page, in `bootstrap/app.php`:

```php
->withExceptions(function (Exceptions $exceptions) {
    $exceptions->render(fn (\Faveo\LicenseClient\Laravel\LicenseException $e) =>
        response()->view('license.blocked', ['status' => $e->status], 403));
})
```

### Paths that are never blocked

Paths in `middleware.except` (default `license*`, `login`, `logout`) are never blocked. Keep your licence page under `/license…` so admins can reach it even with a broken licence.

## Entitlements

Entitlements are the features, limits and expiry dates that Faveo Invoicing signs for each licence.

- **No entitlements from Faveo Invoicing** (Faveo Invoicing not upgraded yet): every feature is allowed and every limit is unlimited.
- **Entitlements sent:** access is **deny by default**. Unknown features are off and unknown limits are 0. `$status->entitlements->present()` tells you which case applies.
- **Expiry dates:**
  - Only the **licence** expiry blocks the app.
  - The **updates** expiry controls which versions may be installed.
  - The **support** date is information only.
- **Enforcing limits is your code's job:**

```php
if (! License::withinLimit('agents', Agent::count() + 1)) {
    return back()->withErrors(__('Your plan allows :n agents.', ['n' => License::limit('agents')]));
}
```

## The Status object

`License::status()`, `install()`, `import()` and `verify()` all return `Faveo\LicenseClient\Status`.

| Member | Meaning |
|---|---|
| `ok()` | `true` only in the `Active` and `Grace` states |
| `state` | A `Faveo\LicenseClient\State` enum, see below |
| `case` | Stable code such as `notification_invalid_domain`. It is the translation key for the message |
| `needsActivation()` | A new licence file is needed (not activated, or the server or domain changed) |
| `allows($feature)`, `limit($name)`, `withinLimit($name, $n)`, `expiresAt($name)` | Entitlement checks. When the licence isn't `ok()`, features are denied and limits are 0 |
| `entitlements` | The `Entitlements` object, with `present()`, `features()` and `limits()` |
| `checkedAt`, `graceEndsAt`, `graceStarted`, `cached` | When the last full check ran, when grace ends, whether this check started grace, and whether this answer came from the cache |
| `errors` | Config or input codes (`config_*`, `input_*`) when the state is `Misconfigured` or the input was refused |
| `serverText` | Faveo Invoicing's own text for the answer, if it sent one |

| State | Meaning | App runs? |
|---|---|---|
| `Active` | Licence valid | Yes |
| `Grace` | Online only: product can't reach Faveo Invoicing, still within `grace_days` | Yes |
| `Expired`, `Suspended`, `Cancelled` | Faveo Invoicing's definite answer | No |
| `Invalid` | Wrong domain, server or product, or refused input | No |
| `NotInstalled` | No licence yet | No |
| `Tampered` | The cached licence was modified | No |
| `Unreachable` | Product can't reach Faveo Invoicing and grace is used up or off | No |
| `Misconfigured` | Config is wrong, see `$status->errors` | No |

## Building a licence page

Keep these routes under `/license…`, so the default `middleware.except` lets them through even when the licence is broken.

```php
use Faveo\LicenseClient\Laravel\Facades\License;

Route::prefix('license')->group(function () {

    // Show the state
    Route::get('/', fn () => view('license.index', [
        'status'  => $status = License::status(),
        'message' => License::message($status),
    ]));

    // Online licence: activate with the licence code
    Route::post('/install', function (Request $request) {
        $status = License::install(
            $request->string('email')->toString(),
            $request->string('code')->toString(),
        );

        return $status->ok()
            ? redirect('/license')->with('ok', License::message($status))
            : back()->withErrors(['code' => License::message($status)]);
    });

    // Offline licence: the customer enters their licence code,
    // you show the activation code to paste into Faveo Invoicing
    Route::post('/activation-code', fn (Request $request) => back()->with(
        'activation_code', License::activationCode($request->string('code')->toString())
    ));

    // Offline licence: upload the licence file downloaded from Faveo Invoicing
    Route::post('/import', function (Request $request) {
        $status = License::import($request->file('license')->get());

        return $status->ok()
            ? redirect('/license')
            : back()->withErrors(['license' => License::message($status)]);
    });
});
```

- `install()` and `import()` never throw on a refusal. They return a `Status`, so show `License::message($status)`.
- `activationCode()` needs the licence code the customer bought, so ask for it on the form. Show it whenever `$status->needsActivation()` is true.
- `License::uninstall()` removes the licence from this installation. `License::license()->licenseCode()` returns the stored licence code, or `null`.
