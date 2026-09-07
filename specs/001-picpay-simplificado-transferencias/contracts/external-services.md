# External Service Contracts

## Authorizer

- **Request**: `GET https://util.devi.tools/api/v2/authorize`
- **Success observed during planning**: HTTP `200` with JSON:

  ```json
  { "status": "success", "data": { "authorization": true } }
  ```

- **Application behavior**: only `data.authorization == true` authorizes a
  transfer. Missing, malformed, false, timeout, and upstream 5xx responses do
  not authorize a transfer.
- **Financial effect**: no balance mutation occurs unless authorization is
  accepted.
- **Testing**: contract tests cover authorized, denied, malformed, timeout,
  and 5xx responses.

## Notification Provider

- **Request**: `POST https://util.devi.tools/api/v1/notify`
- **Request header**: `Content-Type: application/json`
- **Success observed during planning**: HTTP `204` with an empty body.
- **Application behavior**: notification is sent asynchronously from the
  transactional Outbox. Non-2xx results are recorded and retried up to three
  times; notification failure never reverses a completed transfer.
- **Testing**: contract tests cover 204, 4xx, 5xx, timeout, malformed response,
  and repeated delivery attempts.

## TLS Requirement

The application MUST use normal certificate verification for both HTTPS
services. The planning environment returned an expired certificate during a
diagnostic request; disabling verification is not an acceptable workaround.
