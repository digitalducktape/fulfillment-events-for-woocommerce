# Fulfillment Events for WooCommerce

Sell products tied to dated fulfillment events (pickup, delivery, courier). One
cart can span several events and dates, every date's fee is charged in one
payment, and the paid order splits into one order per date so each can be
completed on its own.

Status: early development (`0.1.0`). See [docs/design.md](docs/design.md) for
the design.

## Requirements

- WordPress 6.0+
- WooCommerce 8.9+
- PHP 7.4+

## Development

Tests run in Docker (`php:8.2-cli` and PHPUnit 9); with no Docker daemon,
`bin/test.sh` falls back to a local `php`.

```
bin/test.sh        # lint + unit tests
bin/build-zip.sh   # installable zip in dist/ (honors .distignore)
```

Manual checks that unit tests can't cover are in
[docs/testing-checklist.md](docs/testing-checklist.md).

## Support

If this plugin saves you time, you can [support this plugin](https://buymeacoffee.com/digitalducktape).

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
