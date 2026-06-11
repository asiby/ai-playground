# Generation Prompt

This file contains the prompt that would generate `sync_display_weights.php`
directly, without iteration. It is provided as learning material showing how
to write a precise, context-rich prompt for a Drupal scripting task.

---

## Prompt

You are a Drupal 11 expert. Write a Drush PHP script (intended to be run with
`drush php:script`) that synchronises field order from the Manage Form Display
to the Manage Display for a given content type, without touching the Form
Display itself.

### Behaviour

1. Accept three CLI flags parsed from the Drush-provided `$extra` variable
   (NOT `getopt()` — Drush does not populate `$argv`, it injects extra
   arguments into `$extra`):
   - `--content-type=<machine-name>` (required)
   - `--view-mode=<mode>` (optional, default: `default`)
   - `--dry-run` (optional flag — print a diff but save nothing)

2. Validate that the content type exists; exit with a clear error if not.

3. Load both displays using `\Drupal::service('entity_display.repository')`
   — specifically `getFormDisplay()` and `getViewDisplay()`. Do NOT use
   `\Drupal::entityTypeManager()->getStorage(...)->load(...)` because that
   returns NULL for displays that have never been explicitly saved via the
   Drupal UI (Drupal generates them on the fly from defaults).

4. If the form display `isNew()`, exit with an error — there is nothing to
   read from.

5. If the view display `isNew()` (never saved via the UI), save it
   immediately to bootstrap it into the config system before proceeding.
   Do not exit with an error. Skip this save in `--dry-run` mode.

6. Build the weight map from the form display by iterating ALL field
   definitions via `\Drupal::service('entity_field.manager')->getFieldDefinitions('node', $content_type)`,
   then calling `$form_display->getComponent($field_name)` on each.
   Do NOT use `$form_display->getComponents()` — that only returns fields
   explicitly saved in the config entity and silently omits base fields
   (`title`, `status`, `uid`, `created`, etc.) and fields whose widget
   settings have never been manually saved. `getComponent()` correctly
   returns NULL for hidden fields and the settings array (including `weight`)
   for visible ones.

7. For each field visible in the form display (i.e. `getComponent()` returned
   non-null):
   - If `$view_display->getComponent($field_name)` returns NULL, the field is
     hidden in the view display — make it visible by calling
     `$view_display->setComponent($field_name, ['weight' => $new_weight])`.
     Drupal will apply its default formatter automatically.
   - If the field is already visible in the view display, update its weight
     only if it differs from the form display weight. Preserve all other
     existing display settings (label, formatter, formatter settings, etc.).

8. Leave any field completely untouched that is visible in the view display
   but has no counterpart in the form display (e.g. the `links` pseudo-field,
   `comment` fields, etc.). These fields have no widget on the form and their
   view display position should not be inferred from form display weights.

9. Save the view display at the end — unless `--dry-run` is set, or there
   were no changes.

### Output

Print a clear, aligned report to stdout covering:
- A header block showing content type, view mode, and dry-run status.
- The full list of form display visible fields sorted by weight.
- Fields made visible (were hidden in view display).
- Fields with updated weight (were visible but weight differed).
- A message when no changes were needed.
- Fields skipped (visible in view display, no form display counterpart).
- Fields unchanged (already visible with matching weight).
- A final confirmation or dry-run notice.

### Code style

- No unnecessary comments — only add one where the WHY is non-obvious.
- Use `printf("  %-40s ...", ...)` for aligned columnar output.
- Use `exit(1)` on fatal errors, `exit(0)` implicitly on success.
- PHPDoc type hints on the two display variables.
