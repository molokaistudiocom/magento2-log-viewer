# Magento 2 Log Viewer

A read-only viewer for the files in `var/log`, in the Magento 2 admin panel.

- Lists every `*.log` file in `var/log`, subdirectories and rotated `*.log.N` files included, newest first.
- Opens a file at its end and pages back through older lines on demand.
- Follows a file live, and starts over when it is rotated or emptied.
- Searches a file case-insensitively and shows the last matches with their line numbers.
- Downloads a whole file, behind a separate permission.

It never changes or deletes a file.

## Built for large files and untrusted content

- **Constant cost.** Files are read in bounded byte ranges: 64 KB per page, 256 KB per live-follow poll. A
  search streams the file in 1 MB blocks. Opening, paging and following a 200 MB log takes a few milliseconds
  and well under 1 MB of memory; a full-file search takes a fraction of a second.
- **No way out of `var/log`.** Every action resolves the requested name in one place. The file must be a
  regular `*.log` file whose *real* path lies inside `var/log`, so `../`, absolute paths, null bytes and
  symlinks pointing elsewhere are all refused.
- **No HTML from logs.** Log lines often contain request URLs, API payloads and exception messages. They reach
  the page as text only, never as HTML.
- **A permission on every action**, plus a separate one for downloads.

## Requirements

- Magento Open Source / Adobe Commerce 2.4.7 or later
- PHP 8.3

## Installation

```bash
composer require molokaistudiocom/magento2-log-viewer
bin/magento module:enable MolokaiStudio_LogViewer
bin/magento setup:upgrade
bin/magento cache:flush
```

In production mode, also run `bin/magento setup:di:compile` and `bin/magento setup:static-content:deploy`.

## Usage

Open **System > Tools > Log Viewer**.

## Permissions

Grant these under **System > Permissions > User Roles > Role Resources**:

| Resource | Allows |
|---|---|
| `MolokaiStudio_LogViewer::logs` | Listing, viewing, following and searching log files |
| `MolokaiStudio_LogViewer::download` | Downloading whole log files |

Logs usually hold customer data, order details and API payloads, sometimes with tokens in them. Give these
permissions to as few roles as possible, and keep two-factor authentication on for those admin accounts.

## Running the tests

The tests are left out of the Composer package. To run them, clone the repository into a Magento installation
and run them from its root:

```bash
git clone https://github.com/molokaistudiocom/magento2-log-viewer.git app/code/MolokaiStudio/LogViewer
vendor/bin/phpunit -c dev/tests/unit/phpunit.xml.dist app/code/MolokaiStudio/LogViewer/Test/Unit
```

## License

[MIT](LICENSE)
