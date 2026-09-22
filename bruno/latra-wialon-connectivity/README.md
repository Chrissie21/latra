# LATRA and Wialon connectivity checks

Open this folder as a collection in Bruno.

1. Select the `local` environment.
2. Replace the `REPLACE_WITH_...` values in `environments/local.bru` with the values from the application's `.env` file.
3. Generate `latra_basic_auth` as Base64 of `LATRA_USERNAME:LATRA_PASSWORD`, for example: `printf '%s' 'username:password' | base64 -w0`.
4. Run **Wialon token login**. A successful response contains an `eid` session ID.
5. Run **LATRA authenticated sample**. A response from the LATRA endpoint confirms network reachability and authentication. The request sends a deliberately marked sample vehicle (`BRUNO-TEST`), so run it only when the LATRA test endpoint or account can accept test telemetry.

The collection contains no real credentials. Do not commit populated environment files.
