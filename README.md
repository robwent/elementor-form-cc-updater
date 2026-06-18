# Elementor Form Cc Updater

A small WordPress plugin that sets a **uniform Cc** on the email action of **every Elementor Pro form** across your whole site - in one click (or one WP-CLI command).

Elementor stores each form's settings inside the page's `_elementor_data` JSON, scattered across many posts. If forms have been built over time, their Cc fields drift out of sync - some have addresses, some don't, and the ones that do aren't consistent. This plugin finds every form on the site and forces the Cc to a single value you control.

## Why

- You need the same people Cc'd on **all** form notifications.
- You don't know how many forms there are or where they live.
- You want it to stay uniform without hand-editing dozens of forms.

## Features

- **Finds every form automatically** - recursively walks each page's element tree, including forms nested in inner sections, containers, columns, and popups, and handles **multiple forms per page**.
- **Editable, saved address list** - set the Cc addresses on the admin page; the value is stored so you can see and reuse it later.
- **Dry-run preview** - see exactly how many forms would change before writing anything.
- **Idempotent** - safe to run repeatedly; forms already matching are skipped.
- **Slash-safe writes** - writes `_elementor_data` exactly the way Elementor does (`wp_json_encode` + `wp_slash`), so escaped slashes/quotes round-trip correctly.
- **WP-CLI command** - run it from the command line on any site with shell/WP-CLI access.
- **No autoloaded options, no front-end footprint** - the only stored option is the Cc list, and it is not autoloaded.
- Mirrors the Cc onto a form's **second email action** too, but only when that form actually has `email2` enabled.

## Requirements

- WordPress 5.6+
- PHP 7.4+
- Elementor Pro (for the forms themselves; the plugin reads the data Elementor Pro stores)

## Installation

1. Download/clone this repository into `wp-content/plugins/elementor-form-cc-updater`.
2. Activate **Elementor Form Cc Updater** in *Plugins*.

Or download the repo as a ZIP and install it via *Plugins → Add New → Upload Plugin*.

## Usage

### Admin page

1. Go to **Tools → Form Cc Updater**.
2. Enter the Cc addresses (comma separated). Invalid entries are dropped and reported; duplicates are removed.
3. Click **Save & Preview (dry run)** to see how many forms would change.
4. Click **Save & Apply changes** to write the Cc to every form.

The current saved value is always shown on the page so you know what's configured.

### WP-CLI

```bash
# Preview using a given address list (also saves it):
wp form-cc --cc="one@example.com, two@example.com" --dry-run

# Apply using the saved address list:
wp form-cc

# Set and apply in one go:
wp form-cc --cc="one@example.com, two@example.com"
```

Example output:

```
Cc target: one@example.com, two@example.com
Posts scanned:         249
Posts updated:         237
Forms changed:         237
Forms already correct: 12
Errors:                0
Success: Cc applied to all forms.
```

## How it works

1. Runs a single SQL query for postmeta rows where `meta_key = '_elementor_data'` and the value contains `"widgetType":"form"`, so only pages that actually contain a form are loaded.
2. Decodes each blob and recursively walks the element tree, finding every `form` widget.
3. Compares each form's `email_to_cc` to your target value - leaving matches untouched and updating the rest.
4. On apply, re-encodes the modified page with `wp_json_encode()`, runs it through `wp_slash()`, writes it back with `update_metadata()`, and clears Elementor's CSS/file cache.

Because preview only reads and compares in memory, it's effectively instant even on sites with hundreds of forms.

## Notes & safety

- **Overwrites existing Cc values** by design, so all forms end up identical. Take a database backup first if you're unsure.
- Only the **Cc** field is touched; To, Subject, Message, and everything else are left alone.
- Settings live in `_elementor_data`, so changes are visible in the Elementor editor and survive normally.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
