# Google Sheets connection

## Manual uploads (no Google connection)

Open Sheets > Upload a spreadsheet, choose `.xlsx` or UTF-8 comma-separated `.csv`, and click Upload & preview. Select the tab and correct header row (row 6 for the agency sample), read columns, review mappings, and save the source. Uploaded sources support the same row preview, validation, edit, and manual queue controls as linked sources.

Uploaded cell values are stored in MySQL. Editing changes the application copy, not the file on your computer or a Google Sheet. Styles, charts, and formatted dates are not imported; numeric Excel cells use their underlying values. Formulas are displayed for review and cannot be queued when mapped to email fields. Convert needed formula results to values in your source file before importing.

Limits: 5 MB compressed file, 25 MB expanded workbook, 20 tabs, 10,000 rows and 100 columns per tab, 100,000 cells total. The server's PHP upload limit can be lower; to allow the full 5 MB locally, start with:

```powershell
php -d upload_max_filesize=5M -d post_max_size=6M -S 127.0.0.1:8085 -t backend/public backend/public/index.php
```

An identical file reuses its existing import and retains edits/send history. A changed file creates a new import and can contain previously contacted recipients: review it before queueing. Cross-file campaign deduplication and exporting edited files are not implemented.

Validation: `php backend/tests/uploads.php` tests CSV/XLSX parsing, upload reuse, persisted edits, source preservation, stale-edit rejection, and duplicate queue protection. The HTTP tests include authenticated multipart upload and editing with Google disconnected.

The Google Sheets page accepts multiple spreadsheet links. Each tab is a saved source with its own header row and column mapping. Data is read live when opening or refreshing a source. Editing updates only changed mapped cells in the original sheet. A copy of the source contact list is not stored in MySQL; queued messages retain a send-time snapshot.

## One-time Google configuration

1. Create or choose a Google Cloud project and enable the Google Sheets API.
2. Configure the OAuth consent screen. For an external app in testing, add the intended Google account as a test user.
3. Create an OAuth client of type Web application.
4. Register this exact authorized redirect URI for local development:
   `http://127.0.0.1:8085/auth/google/callback`
5. Set `GOOGLE_CLIENT_ID` and `GOOGLE_CLIENT_SECRET` in `backend/.env`. Set `GOOGLE_REDIRECT_URI` to the same registered URI.
6. Sign into this application at `http://127.0.0.1:8085`, open Google Sheets, click Connect Google, and authorize access. Use the same hostname through the full login flow.

The app requests the Sheets read/write scope because sources are supplied as arbitrary existing sheet URLs. The connected account still needs permission for each source. It cannot edit a sheet shared only as a viewer or protected cells. Google may require verification for wider external deployment; test authorization can expire and require reconnection.

Tokens are encrypted in MySQL using a local key at `backend/storage/app/private/google/token.key`. Keep that key private and include it in protected backups. Disconnect revokes the Google grant and removes stored tokens; it keeps saved source mappings. This implementation shares one Google connection among the single-administrator workspace.

## Adding the agency sheet

1. Paste its Google Sheets URL and click Read tabs.
2. Select Travel agencies and header row 6, then Read columns.
3. Review the suggested mapping: ID, Agency name, Email, Email Subject, Email Content. Optionally map Send Enabled, CC, BCC, and a Drive link.
4. Save source and review the records.

Source files must be native Google Sheets. Convert an Excel upload with File > Save as Google Sheets if necessary, then use the new link.

## Editing and queueing

- Edit opens the mapped fields for a record. IDs are immutable in the app. Keep them unique and permanent in Google Sheets too.
- Blank or duplicate IDs block editing and queueing. Invalid email/content is flagged but can be repaired through Edit.
- Formula cells are displayed as formulas and cannot be overwritten through this editor. Records with formulas in mapped fields require review in Google Sheets before queueing.
- The service rereads current data before saving, locates the record by its ID, and rejects stale previews. Only changed cells are updated with literal strings, preserving other cells, formatting, and formulas.
- Google Sheets does not provide an atomic compare-and-set operation for these row edits. Avoid simultaneous sorting or editing during save: an external change between the validation read and write remains possible.
- Queue email is an explicit action. When Send Enabled is mapped, it must be YES. Without that mapping, manual queueing is allowed. No automatic bulk sending is enabled by adding or editing a source.
- A spreadsheet + tab + permanent record ID can be queued only once. Editing a sent or pending record never automatically resends or rewrites its existing message. Existing controlled retry remains available in the email queue. A new campaign/resend flow is not implemented.
- Refresh rows loads current sheet data. Scheduled sheet synchronization and automatic source queueing are not implemented in this phase. The existing cron worker processes messages already queued.
- Preview supports up to 100 columns and 10,000 grid rows per tab, with 25 records per displayed page. Reduce unused grid rows/columns if the tab exceeds the limit.

## Validation

`php backend/tests/sheets.php` exercises mapping, links, row identity, stale-edit checks, cell writes, formula protection, and idempotent queueing with an in-memory fake Google transport.

`php backend/tests/http.php` exercises authenticated HTTP behavior. Live OAuth and real Google edits must be verified after credentials and consent are configured.

References: [Google web-server OAuth](https://developers.google.com/identity/protocols/oauth2/web-server), [Sheets scopes](https://developers.google.com/workspace/sheets/api/scopes), [Sheets API](https://developers.google.com/workspace/sheets/api/reference/rest).
