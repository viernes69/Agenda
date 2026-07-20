<?php
/**
 * Agenduy - Super Admin: Plantillas globales (email y UltraMSG)
 * Solo editable por super_admin root.
 */
declare(strict_types=1);

$config = require __DIR__ . '/../src/Core/bootstrap.php';
use Agenduy\Core\Auth;
use Agenduy\Core\CSRF;
use Agenduy\Core\PlatformTemplates;

Auth::start();
if (!Auth::check() || Auth::role() !== 'super_admin') {
    header('Location: login.php');
    exit;
}

$flash = ['type' => '', 'msg' => ''];
$catalog = PlatformTemplates::catalog();
$templates = PlatformTemplates::all();
$tab = (string)($_GET['tab'] ?? 'email');
if (!in_array($tab, ['email', 'ultramsg'], true)) {
    $tab = 'email';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::checkRequest('plantillas_admin');
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save') {
        try {
            $payload = [
                'email' => [],
                'ultramsg' => [],
            ];
            foreach ($catalog as $channel => $items) {
                foreach ($items as $key => $meta) {
                    foreach ((array)($meta['fields'] ?? []) as $field) {
                        $inputName = $channel . '__' . $key . '__' . $field;
                        $payload[$channel][$key][$field] = trim((string)($_POST[$inputName] ?? ''));
                    }
                }
            }
            PlatformTemplates::save($payload);
            Auth::audit('save_platform_templates', 'platform_settings', null, null, ['channels' => ['email', 'ultramsg']]);
            $templates = PlatformTemplates::all();
            $flash = ['type' => 'ok', 'msg' => 'Plantillas guardadas correctamente.'];
        } catch (Throwable $e) {
            error_log('[admin/plantillas.php] save: ' . $e->getMessage());
            $flash = ['type' => 'error', 'msg' => 'No se pudieron guardar las plantillas. Intentá de nuevo.'];
        }
    } elseif ($action === 'reset') {
        try {
            PlatformTemplates::save([]);
            $templates = PlatformTemplates::all();
            Auth::audit('reset_platform_templates', 'platform_settings');
            $flash = ['type' => 'ok', 'msg' => 'Plantillas restauradas a los valores por defecto.'];
        } catch (Throwable $e) {
            error_log('[admin/plantillas.php] reset: ' . $e->getMessage());
            $flash = ['type' => 'error', 'msg' => 'No se pudieron restaurar las plantillas.'];
        }
    }
}

$pageTitle = 'Plantillas';
$activeSection = 'plantillas';
require __DIR__ . '/partials/header.php';
?>

<?php if ($flash['msg']): ?>
    <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'ok' ?>"><?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<section class="page-header">
    <h1>Plantillas</h1>
    <p>Mensajes globales de email y WhatsApp (UltraMSG) para las acciones del sistema. Solo el super admin puede editarlos.</p>
    <p class="hint">Placeholders: <?= htmlspecialchars(PlatformTemplates::placeholderHelp(), ENT_QUOTES, 'UTF-8') ?></p>
</section>

<nav class="tabs" aria-label="Tipo de plantilla" style="display:flex;gap:.5rem;margin-bottom:1.25rem">
    <a href="plantillas.php?tab=email" class="btn btn-sm <?= $tab === 'email' ? 'btn-primary' : 'btn-ghost' ?>">Email</a>
    <a href="plantillas.php?tab=ultramsg" class="btn btn-sm <?= $tab === 'ultramsg' ? 'btn-primary' : 'btn-ghost' ?>">UltraMSG (WhatsApp)</a>
</nav>

<form method="post">
    <?= CSRF::field('plantillas_admin') ?>
    <input type="hidden" name="action" value="save">

    <?php foreach ($catalog[$tab] as $key => $meta):
        $label = (string)($meta['label'] ?? $key);
        $hint = (string)($meta['hint'] ?? '');
        $fields = (array)($meta['fields'] ?? []);
        $values = $templates[$tab][$key] ?? [];
    ?>
    <article class="card" style="margin-bottom:1rem">
        <h2><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></h2>
        <?php if ($hint !== ''): ?>
            <p class="hint"><?= htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <p class="hint"><code><?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?></code></p>
        <div class="form-grid">
            <?php foreach ($fields as $field):
                $fieldLabel = $field === 'subject' ? 'Asunto' : 'Cuerpo';
                $value = (string)($values[$field] ?? '');
                $isBody = $field === 'body';
            ?>
            <div class="field" style="<?= $isBody ? 'grid-column:1/-1' : '' ?>">
                <label for="<?= htmlspecialchars($tab . '_' . $key . '_' . $field, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8') ?></label>
                <?php if ($isBody && $tab === 'email' && str_contains($value, '<')): ?>
                    <textarea id="<?= htmlspecialchars($tab . '_' . $key . '_' . $field, ENT_QUOTES, 'UTF-8') ?>"
                              name="<?= htmlspecialchars($tab . '__' . $key . '__' . $field, ENT_QUOTES, 'UTF-8') ?>"
                              rows="8" spellcheck="false"><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></textarea>
                    <span class="hint">HTML permitido (magic link, bienvenida).</span>
                <?php else: ?>
                    <textarea id="<?= htmlspecialchars($tab . '_' . $key . '_' . $field, ENT_QUOTES, 'UTF-8') ?>"
                              name="<?= htmlspecialchars($tab . '__' . $key . '__' . $field, ENT_QUOTES, 'UTF-8') ?>"
                              rows="<?= $isBody ? 5 : 2 ?>"><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></textarea>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </article>
    <?php endforeach; ?>

    <div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1rem">
        <button type="submit" class="btn btn-primary">Guardar plantillas</button>
    </div>
</form>

<form method="post" style="margin-top:1rem" onsubmit="return confirm('¿Restaurar todas las plantillas globales a los valores por defecto?');">
    <?= CSRF::field('plantillas_admin') ?>
    <input type="hidden" name="action" value="reset">
    <button type="submit" class="btn btn-ghost">Restaurar valores por defecto</button>
</form>

<?php require __DIR__ . '/partials/footer.php'; ?>
