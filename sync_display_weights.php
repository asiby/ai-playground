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

$options = getopt('', ['content-type:', 'view-mode:', 'dry-run']);

$content_type = $options['content-type'] ?? NULL;
$view_mode    = $options['view-mode'] ?? 'default';
$dry_run      = array_key_exists('dry-run', $options);

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

/** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface $form_display */
$form_display = \Drupal::entityTypeManager()
  ->getStorage('entity_form_display')
  ->load("node.{$content_type}.default");

if (!$form_display) {
  echo "ERROR: No default form display found for node.{$content_type}.\n";
  exit(1);
}

/** @var \Drupal\Core\Entity\Display\EntityViewDisplayInterface $view_display */
$view_display = \Drupal::entityTypeManager()
  ->getStorage('entity_view_display')
  ->load("node.{$content_type}.{$view_mode}");

if (!$view_display) {
  echo "ERROR: No '{$view_mode}' view display found for node.{$content_type}.\n";
  exit(1);
}

// ---------------------------------------------------------------------------
// Build weight map from form display (visible fields only)
// ---------------------------------------------------------------------------

$form_weights = [];
foreach ($form_display->getComponents() as $field_name => $settings) {
  $form_weights[$field_name] = $settings['weight'];
}

echo "Form display visible fields (" . count($form_weights) . "):\n";
asort($form_weights);
foreach ($form_weights as $name => $weight) {
  printf("  %-40s weight: %d\n", $name, $weight);
}
echo "\n";

// ---------------------------------------------------------------------------
// Update view display weights
// ---------------------------------------------------------------------------

$view_components = $view_display->getComponents();

if (empty($view_components)) {
  echo "INFO: No visible fields in the '{$view_mode}' view display. Nothing to do.\n";
  exit(0);
}

$updated  = [];
$skipped  = [];
$unchanged = [];

foreach ($view_components as $field_name => $settings) {
  if (!isset($form_weights[$field_name])) {
    // Visible in view display but not in form display (e.g. pseudo-fields).
    $skipped[] = $field_name;
    continue;
  }

  $new_weight = $form_weights[$field_name];
  $old_weight = $settings['weight'];

  if ($old_weight === $new_weight) {
    $unchanged[] = $field_name;
    continue;
  }

  $settings['weight'] = $new_weight;
  $view_display->setComponent($field_name, $settings);

  $updated[$field_name] = ['old' => $old_weight, 'new' => $new_weight];
}

// ---------------------------------------------------------------------------
// Report
// ---------------------------------------------------------------------------

if (!empty($updated)) {
  echo "Fields to update (" . count($updated) . "):\n";
  foreach ($updated as $name => $weights) {
    printf("  %-40s %d  =>  %d\n", $name, $weights['old'], $weights['new']);
  }
  echo "\n";
}
else {
  echo "No weight changes needed — view display already matches form display.\n\n";
}

if (!empty($skipped)) {
  echo "Fields skipped (visible in view display, absent from form display):\n";
  foreach ($skipped as $name) {
    echo "  - {$name}\n";
  }
  echo "\n";
}

if (!empty($unchanged)) {
  echo "Fields unchanged (weights already match):\n";
  foreach ($unchanged as $name) {
    echo "  - {$name}\n";
  }
  echo "\n";
}

// ---------------------------------------------------------------------------
// Save
// ---------------------------------------------------------------------------

if (!empty($updated)) {
  if ($dry_run) {
    echo "[DRY RUN] Changes NOT saved.\n";
  }
  else {
    $view_display->save();
    echo "View display saved successfully.\n";
  }
}
