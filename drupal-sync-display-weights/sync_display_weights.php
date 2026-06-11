<?php

/**
 * Drush PHP script: sync field weights from Form Display to Manage Display.
 *
 * Usage:
 *   drush php:script sync_display_weights.php -- --content-type=article
 *   drush php:script sync_display_weights.php -- --content-type=article --view-mode=teaser
 *   drush php:script sync_display_weights.php -- --content-type=article --dry-run
 */

// ---------------------------------------------------------------------------
// Parse arguments
// ---------------------------------------------------------------------------
// Drush php:script exposes extra CLI args (those after --) in $extra, not
// $argv, so getopt() won't see them. Parse $extra manually instead.

$options = [];
foreach (($extra ?? []) as $arg) {
  if (preg_match('/^--([a-z-]+)=(.+)$/', $arg, $m)) {
    $options[$m[1]] = $m[2];
  }
  elseif (preg_match('/^--([a-z-]+)$/', $arg, $m)) {
    $options[$m[1]] = TRUE;
  }
}

$content_type = $options['content-type'] ?? NULL;
$view_mode    = $options['view-mode'] ?? 'default';
$dry_run      = isset($options['dry-run']);

if (empty($content_type)) {
  echo "ERROR: --content-type is required.\n";
  echo "Usage: drush php:script sync_display_weights.php -- --content-type=article [--view-mode=default] [--dry-run]\n";
  exit(1);
}

// ---------------------------------------------------------------------------
// Validate the content type exists
// ---------------------------------------------------------------------------

$node_type = \Drupal\node\Entity\NodeType::load($content_type);
if (!$node_type) {
  echo "ERROR: Content type '{$content_type}' does not exist.\n";
  exit(1);
}

echo str_repeat('-', 60) . "\n";
echo "Content type : {$content_type}\n";
echo "View mode    : {$view_mode}\n";
echo "Dry run      : " . ($dry_run ? 'YES (no changes will be saved)' : 'NO') . "\n";
echo str_repeat('-', 60) . "\n\n";

// ---------------------------------------------------------------------------
// Load entity displays
// ---------------------------------------------------------------------------
// Use entity_display.repository rather than a direct load() so that displays
// which have never been explicitly saved (Drupal generates them on the fly)
// are still returned correctly.

/** @var \Drupal\Core\Entity\EntityDisplayRepositoryInterface $display_repo */
$display_repo = \Drupal::service('entity_display.repository');

$form_display = $display_repo->getFormDisplay('node', $content_type, 'default');
if (!$form_display || $form_display->isNew()) {
  echo "ERROR: No default form display found for node.{$content_type}.\n";
  exit(1);
}

$view_display = $display_repo->getViewDisplay('node', $content_type, $view_mode);
if (!$view_display) {
  echo "ERROR: Could not load view display for node.{$content_type}.{$view_mode}.\n";
  exit(1);
}
if ($view_display->isNew()) {
  // The display has never been saved via the UI. Persist the auto-generated
  // defaults now so subsequent setComponent() calls have a stable base.
  echo "INFO: View display for '{$view_mode}' has not been saved yet — initialising it now.\n";
  if (!$dry_run) {
    $view_display->save();
  }
}

// ---------------------------------------------------------------------------
// Build weight map from form display (visible fields only)
// ---------------------------------------------------------------------------
// getComponents() only covers fields explicitly saved in the form display
// config. Base fields and fields never touched in the UI can be missing.
// We walk all field definitions instead and call getComponent() on each,
// which returns the stored settings (including weight) or NULL if hidden.

$field_definitions = \Drupal::service('entity_field.manager')
  ->getFieldDefinitions('node', $content_type);

$form_weights = [];
foreach (array_keys($field_definitions) as $field_name) {
  $component = $form_display->getComponent($field_name);
  if ($component !== NULL) {
    $form_weights[$field_name] = $component['weight'];
  }
}

echo "Form display visible fields (" . count($form_weights) . "):\n";
asort($form_weights);
foreach ($form_weights as $name => $weight) {
  printf("  %-40s weight: %d\n", $name, $weight);
}
echo "\n";

// ---------------------------------------------------------------------------
// Update view display: sync weights and make hidden fields visible
// ---------------------------------------------------------------------------

$view_components = $view_display->getComponents();

$updated   = [];  // weight changed for an already-visible field
$made_visible = []; // field was hidden in view display, now made visible
$skipped   = [];  // visible in view display but absent from form display
$unchanged = [];  // already visible with the correct weight

foreach ($form_weights as $field_name => $new_weight) {
  $existing = $view_display->getComponent($field_name);

  if ($existing === NULL) {
    // Field is hidden in the view display — make it visible with a default
    // formatter and the weight from the form display.
    $view_display->setComponent($field_name, ['weight' => $new_weight]);
    $made_visible[$field_name] = $new_weight;
    continue;
  }

  $old_weight = $existing['weight'];

  if ($old_weight === $new_weight) {
    $unchanged[] = $field_name;
    continue;
  }

  $existing['weight'] = $new_weight;
  $view_display->setComponent($field_name, $existing);
  $updated[$field_name] = ['old' => $old_weight, 'new' => $new_weight];
}

// Fields visible in the view display that have no counterpart in the form
// display (e.g. pseudo-fields like "links") are left untouched.
foreach ($view_components as $field_name => $settings) {
  if (!isset($form_weights[$field_name])) {
    $skipped[] = $field_name;
  }
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

if (!empty($made_visible)) {
  echo "Fields made visible (" . count($made_visible) . "):\n";
  foreach ($made_visible as $name => $weight) {
    printf("  %-40s (weight: %d)\n", $name, $weight);
  }
  echo "\n";
}

if (!empty($updated)) {
  echo "Fields with updated weight (" . count($updated) . "):\n";
  foreach ($updated as $name => $weights) {
    printf("  %-40s %d  =>  %d\n", $name, $weights['old'], $weights['new']);
  }
  echo "\n";
}

if (empty($made_visible) && empty($updated)) {
  echo "No changes needed — view display already matches form display.\n\n";
}

if (!empty($skipped)) {
  echo "Fields skipped (visible in view display, absent from form display):\n";
  foreach ($skipped as $name) {
    echo "  - {$name}\n";
  }
  echo "\n";
}

if (!empty($unchanged)) {
  echo "Fields unchanged (already visible with correct weight):\n";
  foreach ($unchanged as $name) {
    echo "  - {$name}\n";
  }
  echo "\n";
}

// ---------------------------------------------------------------------------
// Save
// ---------------------------------------------------------------------------

if (!empty($made_visible) || !empty($updated)) {
  if ($dry_run) {
    echo "[DRY RUN] Changes NOT saved.\n";
  }
  else {
    $view_display->save();
    echo "View display saved successfully.\n";
  }
}
