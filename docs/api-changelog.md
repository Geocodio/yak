# API changelog

One section per client API, one entry per protocol number: the date, what changed, and what clients must do.

A new endpoint, optional request field, response field, event type, error code, or an enum value in a field the contract marks as open is not breaking and needs no bump. Clients ignore what they do not know and treat an unknown enum value as "other". Removing or renaming a field, changing a type or meaning, making an optional request field required, changing an error code or status, removing an enum value, or changing a push payload key or realtime event shape is breaking and raises `protocol`.

The contract fixtures in `tests/Fixtures/cli-api/v1` and `tests/Fixtures/app-api/v1` pin the response shapes. Changing one is a deliberate edit that is checked against the rule above.

## CLI API

### Protocol 1 (2026-11-13)

The first protocol: device flow sign-in, `me`, repository resolution, teleport and session files.

## App API

### Protocol 1 (2026-11-14)

The first protocol: error contract, phone devices, pairing, `instance` and `me`.

`POST device/pair` takes an optional `request_id` (a UUID). A repeat with the same code and `request_id` inside the code's 120 seconds returns the first response, so a lost response can be retried. `POST device/token` answers a repeated poll with the same response for 60 seconds after the first success.
