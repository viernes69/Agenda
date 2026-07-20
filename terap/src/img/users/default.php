<?php
// Generates an SVG avatar with initials.
// Usage: default.php?n=Joaquin%20Perez
header('Content-Type: image/svg+xml; charset=utf-8');

$name = isset($_GET['n']) ? (string)$_GET['n'] : '';
$name = trim($name);
if ($name === '' && isset($_GET['name'])) {
  $name = trim((string)$_GET['name']);
}

$parts = preg_split('/\s+/', $name) ?: [];
$ini = '';
foreach ($parts as $p) {
  if ($p === '') continue;
  $ch = function_exists('mb_substr') ? mb_substr($p, 0, 1, 'UTF-8') : substr($p, 0, 1);
  $ini .= function_exists('mb_strtoupper') ? mb_strtoupper($ch, 'UTF-8') : strtoupper($ch);
  if (strlen($ini) >= 2) break;
}
if ($ini === '') { $ini = 'U'; }

// Simple gradient background, centered initials
$size = 128;
$fontSize = 52;
$bg1 = '#1f2432';
$bg2 = '#11151e';
$textColor = '#cbd5e1';

echo "<?xml version=\"1.0\" encoding=\"UTF-8\" standalone=\"no\"?>\n";
?>
<svg xmlns="http://www.w3.org/2000/svg" width="<?php echo $size; ?>" height="<?php echo $size; ?>" viewBox="0 0 <?php echo $size; ?> <?php echo $size; ?>" role="img" aria-label="Avatar">
  <defs>
    <linearGradient id="g" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0%" stop-color="<?php echo $bg1; ?>"/>
      <stop offset="100%" stop-color="<?php echo $bg2; ?>"/>
    </linearGradient>
  </defs>
  <circle cx="<?php echo $size/2; ?>" cy="<?php echo $size/2; ?>" r="<?php echo ($size/2)-2; ?>" fill="url(#g)" stroke="rgba(148,163,184,0.35)" stroke-width="2" />
  <text x="50%" y="54%" text-anchor="middle" dominant-baseline="middle" font-family="Inter, system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif" font-weight="700" font-size="<?php echo $fontSize; ?>" fill="<?php echo $textColor; ?>">
    <?php echo htmlspecialchars($ini, ENT_QUOTES, 'UTF-8'); ?>
  </text>
</svg>

