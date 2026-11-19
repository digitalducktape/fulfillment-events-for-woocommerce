# Manual testing checklist

Staging checks that unit tests can't cover. Each milestone appends its checks
here.

## M1: plugin core

- [ ] Activate on a fresh site: the three fe_ tables exist; re-activating or bumping the plugin does not re-ALTER columns (check the debug log for dbDelta queries).
- [ ] The fe_event post type is registered (no menu yet): wp-admin/edit.php?post_type=fe_event lists events and the editor opens.
- [ ] Save a location as default, then save another as default: only the second stays default.
- [ ] Link an event to a product and to a category; a variation of the product resolves to the parent product links and categories.

## M2: order split

## M4: add-ons and polish
