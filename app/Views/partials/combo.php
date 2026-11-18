<?php
use App\Core\View;
/**
 * Autocomplete text field (driven by [data-combo] in app.js).
 *
 * @var string      $name        name of the visible text input ('' = not submitted)
 * @var array       $items       [{v, label, sub}]
 * @var string      $value       initial text
 * @var string|null $valueName   if set: hidden input that receives the chosen item's v,
 *                               and a choice from the list is required
 * @var string      $hiddenValue initial hidden value
 * @var string      $placeholder
 * @var bool        $required
 * @var string      $context      people only: unit name whose members are listed first
 * @var string      $contextInput people only: name of the form field holding that unit name
 */
$valueName ??= null;
$hiddenValue ??= '';
$value ??= '';
$placeholder ??= '';
$required ??= false;
$context ??= '';
$contextInput ??= '';
?>
<div class="combo" data-combo<?= $valueName ? ' data-combo-strict' : '' ?><?= $context !== '' ? ' data-combo-context="' . View::e($context) . '"' : '' ?><?= $contextInput !== '' ? ' data-combo-context-input="' . View::e($contextInput) . '"' : '' ?>>
  <div class="combo-field">
    <i class="bi bi-search combo-icon"></i>
    <input type="text" class="form-control form-control-sm" data-combo-input
           <?= $name !== '' ? 'name="' . View::e($name) . '"' : '' ?>
           value="<?= View::e($value) ?>" placeholder="<?= View::e($placeholder) ?>"
           autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false"
           <?= $required ? 'required' : '' ?>>
  </div>
  <?php if ($valueName): ?>
    <input type="hidden" name="<?= View::e($valueName) ?>" value="<?= View::e($hiddenValue) ?>" data-combo-value>
  <?php endif; ?>
  <script type="application/json" data-combo-items><?= json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
  <div class="combo-menu" role="listbox" hidden></div>
</div>
