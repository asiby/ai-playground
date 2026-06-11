# drupal-sync-display-weights

A Drush PHP script for Drupal 11.3.10 that synchronises field order from the
**Form Display** (Content Type → Manage Form Display) to the **Manage Display**
(Content Type → Manage Display), without requiring any manual work in the
admin UI.

---

## The Problem

In Drupal, the order in which fields appear on an edit form (**Manage Form
Display**) and the order in which they appear when a node is rendered
(**Manage Display**) are stored independently. When a site editor carefully
arranges fields on the form, the display side is not updated to match.
Keeping both in sync manually is tedious, error-prone, and breaks again
every time a new field is added.

---

## Approach

Drupal stores both displays as configuration entities:

| Display tab | Config entity type | Example config name |
|---|---|---|
| Manage Form Display | `entity_form_display` | `core.entity_form_display.node.article.default` |
| Manage Display | `entity_view_display` | `core.entity_view_display.node.article.default` |

Each entity holds a `components` map of `field_name → settings`, where
`settings` includes a `weight` that controls rendering order.

The script:

1. Loads the **form display** via `entity_display.repository` (which handles
   displays that have never been explicitly saved — Drupal generates them
   on the fly and they would not be found by a direct `load()` call).
2. Iterates **all field definitions** for the content type and calls
   `getComponent()` on each. This catches base fields (`title`, `status`,
   `uid`, etc.) and any field whose widget settings have never been explicitly
   saved — both of which are invisible to `getComponents()`.
3. For each field that is visible in the form display:
   - If it is **already visible** in the view display → updates its weight.
   - If it is **hidden** in the view display → makes it visible using Drupal's
     default formatter and the form display weight.
4. Fields that are visible in the view display but have **no counterpart in
   the form display** (e.g. the `links` pseudo-field) are left completely
   untouched.
5. If the view display has **never been saved** via the UI, the script
   initialises it automatically rather than failing with an error.
6. Saves the updated view display (skipped in `--dry-run` mode).

The form display is never modified.

---

## Edge Cases

| Situation | Behaviour |
|---|---|
| Field hidden in the view display | Made visible with default formatter and form display weight |
| Field visible in view display but absent from form display (e.g. `links`) | Left untouched |
| View display has never been saved (only exists as a Drupal default) | Auto-initialised (saved) before proceeding |
| Form display has never been saved | Handled transparently via `entity_display.repository` |
| `--dry-run` | Full report printed, nothing saved — including the auto-initialise step |
| Non-existent content type | Exits immediately with a clear error |
| Non-existent view mode | Exits immediately with a clear error |
| Field weights already match | Reported as unchanged, no write performed |

---

## Requirements

- Drupal 11.3.10
- Drush 12+

---

## Installation

Copy `sync_display_weights.php` anywhere accessible to Drush — the Drupal
root or a `scripts/` directory are both fine.

---

## Usage

```bash
# Sync the default form display → default view display for the "article" type
drush php:script sync_display_weights.php -- --content-type=article

# Target a specific view mode (e.g. teaser)
drush php:script sync_display_weights.php -- --content-type=article --view-mode=teaser

# Preview what would change without saving anything
drush php:script sync_display_weights.php -- --content-type=article --dry-run

# Combine flags
drush php:script sync_display_weights.php -- --content-type=article --view-mode=full --dry-run
```

### Options

| Option | Default | Description |
|---|---|---|
| `--content-type` | *(required)* | Machine name of the content type |
| `--view-mode` | `default` | View mode to update |
| `--dry-run` | off | Print the diff without saving |

---

## Example Output

```
------------------------------------------------------------
Content type : article
View mode    : default
Dry run      : NO
------------------------------------------------------------

Form display visible fields (9):
  title                                    weight: -5
  uid                                      weight: -4
  created                                  weight: -3
  status                                   weight: -2
  promote                                  weight: -1
  body                                     weight: 0
  field_image                              weight: 1
  field_tags                               weight: 2
  field_summary                            weight: 3

Fields made visible (2):
  field_image                              (weight: 1)
  field_summary                            (weight: 3)

Fields with updated weight (3):
  body                                     5  =>  0
  field_tags                               10  =>  2
  uid                                      0  =>  -4

Fields skipped (visible in view display, absent from form display):
  - links

Fields unchanged (already visible with correct weight):
  - title

View display saved successfully.
```

---

## Generation Prompt

[`PROMPT.md`](./PROMPT.md) contains the single, self-contained prompt that
would reproduce this script directly — without iteration. It documents every
non-obvious technical decision (why `$extra` instead of `getopt()`, why
`getFieldDefinitions` instead of `getComponents()`, why
`entity_display.repository` instead of a direct `load()`, etc.) and is
provided as learning material for writing precise Drupal scripting prompts.

---

## Credits

Created by [Claude](https://claude.ai) (Anthropic Sonnet) with prompts
authored by [asiby](https://github.com/asiby).
