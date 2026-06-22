# jb-library-maintenance
A WordPress plugin which extends Document Library Pro with custom maintenance functionality.

General process for using this plugin:

## Backups
Start by making a backup of your site. This plugin takes destructive actions, so you want to have a backup ready to go.

## Access
You will need to have access to WP_CLI and the SFTP to make full use of this plugin

## Clean Sweep
The `clean sweep` functionality of this plugin is designed to remove any Document Library Pro (`dlp_document`) posts and their corresponding media attachments (`attachment`).

- If you have run the clean sweep command before, run the options to clear out the old options and logs
`wp dlp-document-delete-clear-options`
`wp dlp-document-delete-clear-logs`

- Run the clean sweep command to remove all of the old DLP document posts and doc_tags
`wp dlp-document-delete --skip-confirmations --batch-size=15000 --for-real`

- If you’re not ready yet, you can run it with the “--for-real” tag left off to test

## PDF Media Delete
If there were any SDS or TDS attachments which weren't connected to a `dlp_document` post, they may have slipped through the clean sweep. You can delete them with the PDF Media Delete tool.

- Check for any attachment PDFs with SDS or TDS in the name
`wp pdf-media-delete-clear-options`
`wp pdf-media-delete-clear-logs`
`wp pdf-media-delete`

## Check the site
At this point, you want to check the site content to make sure everything is really gone. This includes:
- WP Admin: Check the media library and documents sections to make sure there are no posts related to SDS and TDS PDFs
- phpmyadmin: export any PDF files with SDS or TDS in the name, just to make sure you can remove those posts later.
- SFTP: If any of the files were in a specific sub-directory, check it and delete any files which are left behind. Just make sure to remove the posts too!

## Create new tags
Since the old taxonomies have been deleted with the posts, create the new `doc_tag` terms using the stock code prefixes.
- Run `wp create-stock-code-doc-tags`

## Upload New PDFs
Upload the .zip files for the SDS and TDS files to the `wp-content/uploads` directory via SFTP. Decompress the files. This will create new `SDS` and `TDS` subdirectories for the files. There will be two `SDS` .zip files, so you will need to combine them.

## Import the files
Now we are ready to import the PDFs into media attachments and document posts.

- If you have run the importer before, make sure to clear out the importer options and logs
`wp pdf-media-import-clear-options`
`wp pdf-media-import-delete-logs`

- Run a few small batches of the file imports
`wp pdf-media-scrape-and-import --subdirectory-path=SDS --batch-size=15 --for-real`
`wp pdf-media-scrape-and-import --subdirectory-path=TDS --batch-size=15 --for-real`

- If you are happy with the results, run the imports in larger batch sizes. Keep in mind, the larger the size, the longer it will take to finish

## Failed Imports
A log of files that failed to import should be printed. If you aren’t sure if it is correct, you can run the status check command
`wp check-pdf-import-status`

It will print any files which do not have corresponding `dlp_document` or `attachment` posts.

## Backfill Existing Document Content
If existing Document Library Pro posts have empty `post_content`, use the backfill command to try extracting text from the related PDF and updating the existing document post.

Start with a dry run:

`wp dlp-backfill-document-content --url=www.revchem.local --limit=100`

Run a specific document:

`wp dlp-backfill-document-content --url=www.revchem.local --post-id=40602`

Use the local fallback parser/OCR path in small batches:

`wp dlp-backfill-document-content --url=www.revchem.local --use-fallback --limit=25`

Update posts for real only after reviewing the CSV log:

`wp dlp-backfill-document-content --url=www.revchem.local --for-real --batch-size=100`

For concurrent or resumable runs, split the work by non-overlapping post ID ranges:

`wp dlp-backfill-document-content --url=www.revchem.local --use-fallback --for-real --start-post-id=29900 --end-post-id=33000`

Safety behavior:

- The command only targets `dlp_document` posts with empty content by default.
- Existing content is not overwritten unless `--force` is used.
- The bundled PHP PDF parser is used by default; local fallback parsers only run when `--use-fallback` is passed.
- Use `--start-post-id` and `--end-post-id` for concurrent runs; avoid concurrent `--limit` batches because they can select the same empty posts.
- Parsed text must pass a minimum length and document-language usability check before it is written.
- Every run writes a CSV log to `wp-content/plugins/jb-library-maintenance/logs/`.
- Use `--min-chars=<number>` to adjust the minimum cleaned text length; the default is `200`.

## Optional PDF text fallback hook
The PDF scraper first tries to read PDF text with the bundled PHP PDF parser. Some SDS files do not parse cleanly with that parser, so the scraper includes an optional hook for a local fallback parser:

`jb_library_pdf_scraper_fallback_text`

This hook is intended for local maintenance runs only. It lets a developer provide PDF text from a machine-specific tool without committing that tool path or shell command to the repository.

### Safety gates
The fallback hook will only run when both of these are true:

- The plugin is running under WP-CLI.
- `JB_LIBRARY_ENABLE_PDF_TEXT_FALLBACK` is set to `true`.

This prevents the fallback from running during normal web requests on a webhost. The local fallback file is also ignored by git.

### Local setup
Create this file locally:

`wp-content/plugins/jb-library-maintenance/jb-library-pdf-fallback-parser.local.php`

Enable the fallback for your local WP-CLI run by defining this before the plugin loads, usually in local `wp-config.php`:

```php
define( 'JB_LIBRARY_ENABLE_PDF_TEXT_FALLBACK', true );
```

Then register a callback:

```php
<?php
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

if ( ! defined( 'JB_LIBRARY_ENABLE_PDF_TEXT_FALLBACK' ) || ! JB_LIBRARY_ENABLE_PDF_TEXT_FALLBACK ) {
    return;
}

add_filter(
    'jb_library_pdf_scraper_fallback_text',
    function ( $fallback_text, string $file_path ) {
        if ( is_string( $fallback_text ) && trim( $fallback_text ) !== '' ) {
            return $fallback_text;
        }

        // Run your local parser here and return extracted text.
        return null;
    },
    10,
    2
);
```

Do not commit `*.local.php` files or generated `*.parsed.json` files. They are local cache/integration files only.
