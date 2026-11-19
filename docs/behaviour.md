# Behaviour of the simulator

What dimag-sim does, and where that comes from. **Recorded** means the
response was taken from a real DIMAG and the contract test checks it on
every run. **Modelled** means it could not be provoked on the real system
(or was not tried) and follows the
[KM-SOAP documentation](https://gitlab.la-bw.de/pub/dimag-info/-/blob/master/dimag-soap.md)
or a best guess.

Recordings: DIMAG core module **3.5.0**, import XSD `LaBW_IngExt_2023-08-21.xsd`,
metadata version `2023-08-21`, endpoint `/soap/webservice_3_5_0.php`, recorded
2026-11-19 (`fixtures/3.5.0/`).

## The wire format

- RPC style without WSDL: the operation is the first element in the SOAP
  body, its parameters are taken **by position** (Inge sends `param0`,
  `param1` …; names are ignored). Booleans arrive as `xsd:boolean`.
- Responses are literal, without `xsi:type`, in the namespace
  `http://dimag.la-bw.de/WebService.wsdl`, with fields in a fixed order
  (`status`, `msg`, then `hits`/`aIDList`, `ControlFile` or `prefixList`).
  Errors are reported in `status` (HTTP-like codes) with HTTP 200, not as
  SOAP faults. *(recorded)*
- `changeStatusCode` answers as `changeStatuscodeResponse`. *(recorded)*
- A version only serves its own and older endpoints: a 3.5.0 answers
  `webservice_3_6_0.php` with HTTP 404. *(recorded; older endpoints modelled)*

## Operations

| Operation | Recorded | Modelled |
|---|---|---|
| `getStatus` | message layout (version, URL, archive name, sftp path, XSD, prefix) | — |
| `getPrefixList` | status 400 "fetch of prefixList failed" (no AFIS coupled) | coupled AFIS |
| `importDoc` | insert S/O/R/F with given AIDs; lines per object then per protocol object; parent's protocol "updated"; unchanged objects "un-changed, thus update omitted"; title update; type change refused (400); md5 mismatch (400); missing primary file (400, two blanks in the message); O without `entstehungs-zeitraum` (400); unknown `pid` (400 "getMyAcc(): type of AID is no string"); missing control file (404); unreadable control file (404, "Error 1872"); a finalized R takes a new F; the package disappears from the SFTP after a successful import; `provenienz`/`uebernahme` are dropped from F | rollback of the whole file on an error; insert below a parent of the wrong type; AIDs with the installation prefix; missing `titel`/`typ`; `async` (job ids) is ignored |
| `exportDoc` | `Export/YYYY/MM/DD/HH.MM.SS-mmm/verz_obj.xml` relative to the sftp path; layout of `verz_obj.xml` (tabs, fields sorted, blank lines, no final newline); `{aid}` and `{aid}.xml` with data; "Number Objects exported: n"; unknown AID (500 "Failed to find") | `RepOptions` first/last with several R; `LogFile` for admins; layout and count of `withStruct` exports (see below); "access rights" refusal (switch `deny`) |
| `changeStatusCode` | 10→20 per object; cascade to children (children first, younger siblings first); no change → "un-changed"; downgrade, unknown code, 50 by a non-admin → 400 "Der Status kann nicht für alle tiefer liegenden Objekte geändert werden."; unknown AID → 402; 30 by the user; 50 by an admin deletes the subtree including protocol objects (deepest level first, `<br>` without slash, double blank) and frees the AID | 40 (primary data deleted, metadata kept) |
| `moveObject` | F keeps its AID; message with the protocols of old and new parent; same parent → "un-changed"; unknown object or parent → 402; F below S → 400 "cannot move … because of typ" | moving into the own subtree |
| `getAIDforKeyValue` | `%` wildcard, `*` is literal (Inge's `getAidsWithPrefix` therefore never finds anything); type filter `File`/`Struct`/`Any`; hits sorted alphabetically; "nothing appropriate found" / "found some objects" | other keys than `aid`, `md5`, `titel`; unknown type values behave like `Any` |
| `getObjectsChangedSince` | needs `YYYY-MM-DD HH:MM:SS` (else 500 "Wrong datetime format!", empty `hits`); lists S/O/R/F of the account, no protocol objects; deleted objects are gone | the order of the list (DIMAG's own; the contract test compares sets) |
| Login | — | wrong user or password → status 401 "Error: Login failed" (not tried on the real system, to avoid locking the account) |

## Accounts and visibility

Each account has root AIDs (`DIMAG_SIM_USERS`). It sees and may change its
roots and everything below them; objects elsewhere answer as if they did
not exist ("Failed to find", 402). Protocol objects (P) are visible to
admins only. An admin sees everything and may set status 40 and 50.
*(modelled on what Inge's account could and could not do)*

## Not modelled

- `importDocAfis`, `importBag`, `recharacterizeAID`, `validateRootid`,
  `getJobStatus`, `getJobs`, `getForms`, `getFields`: answered with a SOAP
  fault "Procedure … not present".
- No WSDL is served. The LABW documentation and WSDL carry no licence, so
  dimag-sim ships neither; clients call the endpoint without WSDL, as Inge does.
- Format identification: F get `datei-format` from `finfo`, but no PUID;
  DIMAG's DROID/JHOVE results are not reproduced.
- Versions of objects (`versions-nummer` stays 1), `doVersion`.
- `withStruct=true`: DIMAG indents the export as if hidden ancestors were
  there, adds unindented closing tags and counts objects above the
  account's root; dimag-sim writes a plain nested file and counts hidden
  ancestors. Inge does not use `withStruct`. (Accepted difference in the
  contract test.)

## Error switches

`POST /_sim/faults` with a JSON object (all optional; an empty object
switches everything off):

| Switch | Effect |
|---|---|
| `"down": true` | every SOAP call gets HTTP 503 (the client throws `SoapFault`) |
| `"delayMs": 2000` | every SOAP call waits first |
| `"deny": ["aid"]` | `exportDoc` refuses with "Not enough access rights" (Inge: `DimagAccessDenied`) |
| `"failExport": ["aid"]` | `exportDoc` answers status 500 |
| `"corrupt": ["aid"]` | exported primary data no longer matches its md5 |
| `"failMoveAt": 3` | the 3rd `moveObject` after setting the switch is a SOAP fault ("connection lost") |
