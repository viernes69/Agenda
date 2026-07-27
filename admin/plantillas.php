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
    header('Location: ' . Auth::loginUrl());
    exit;
}

$flash = ['type' => '', 'msg' => ''];
$catalog = PlatformTemplates::catalog();
$templates = PlatformTemplates::all();
$branding = PlatformTemplates::emailBranding();
$tab = (string)($_GET['tab'] ?? 'email');
if (!in_array($tab, ['email', 'ultramsg'], true)) {
    $tab = 'email';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::checkRequest('plantillas_admin');
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save') {
        try {
            $payload = ['email' => [], 'ultramsg' => []];
            foreach ($catalog as $channel => $items) {
                foreach ($items as $key => $meta) {
                    foreach ((array)($meta['fields'] ?? []) as $field) {
                        $inputName = $channel . '__' . $key . '__' . $field;
                        $payload[$channel][$key][$field] = trim((string)($_POST[$inputName] ?? ''));
                    }
                }
            }
            PlatformTemplates::save($payload);
            PlatformTemplates::saveEmailBranding([
                'logo_url' => trim((string)($_POST['logo_url'] ?? '')),
                'show_logo_in_emails' => !empty($_POST['show_logo_in_emails']),
            ]);
            Auth::audit('save_platform_templates', 'platform_settings', null, null, ['channels' => ['email', 'ultramsg']]);
            $templates = PlatformTemplates::all();
            $branding = PlatformTemplates::emailBranding();
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
$previewUrl = 'api/plantillas_preview.php';
require __DIR__ . '/partials/header.php';
?>

<div data-preview-url="<?= htmlspecialchars($previewUrl, ENT_QUOTES, 'UTF-8') ?>">

<?php if ($flash['msg']): ?>
    <div class="alert alert-<?= $flash['type'] === 'error' ? 'error' : 'ok' ?>"><?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<section class="page-header">
    <h1>Plantillas</h1>
    <p>Mensajes globales de email y WhatsApp (UltraMSG). Editá el contenido y mirá la vista previa como lo recibe el cliente.</p>
    <p class="hint">Placeholders: <?= htmlspecialchars(PlatformTemplates::placeholderHelp(), ENT_QUOTES, 'UTF-8') ?></p>
</section>

<nav class="tabs" aria-label="Tipo de plantilla" style="display:flex;gap:.5rem;margin-bottom:1.25rem">
    <a href="plantillas.php?tab=email" class="btn btn-sm <?= $tab === 'email' ? 'btn-primary' : 'btn-ghost' ?>">Email (Gmail)</a>
    <a href="plantillas.php?tab=ultramsg" class="btn btn-sm <?= $tab === 'ultramsg' ? 'btn-primary' : 'btn-ghost' ?>">UltraMSG (WhatsApp)</a>
</nav>

<form method="post" id="plantillas-form">
    <?= CSRF::field('plantillas_admin') ?>
    <input type="hidden" name="action" value="save">

    <?php if ($tab === 'email'): ?>
    <article class="card" style="margin-bottom:1rem">
        <h2>Marca en emails</h2>
        <p class="hint">Insertá <code>{logo}</code> en el cuerpo o activá el logo global. También podés usar negrita, tamaños y enlaces en el editor.</p>
        <div class="form-grid">
            <div class="field">
                <label for="logo_url">Ruta del logo (relativa al sitio)</label>
                <input type="text" id="logo_url" name="logo_url" value="<?= htmlspecialchars((string)$branding['logo_url'], ENT_QUOTES, 'UTF-8') ?>" placeholder="src/media/logo/logo-horizontal.png">
            </div>
            <div class="field">
                <label><input type="checkbox" name="show_logo_in_emails" value="1" <?= !empty($branding['show_logo_in_emails']) ? 'checked' : '' ?>> Mostrar logo cuando uses <code>{logo}</code></label>
            </div>
        </div>
    </article>
    <?php endif; ?>

    <?php foreach ($catalog[$tab] as $key => $meta):
        $label = (string)($meta['label'] ?? $key);
        $hint = (string)($meta['hint'] ?? '');
        $fields = (array)($meta['fields'] ?? []);
        $values = $templates[$tab][$key] ?? [];
        $isEmail = $tab === 'email';
    ?>
    <article class="card tpl-card" style="margin-bottom:1rem" data-template-card data-channel="<?= htmlspecialchars($tab, ENT_QUOTES, 'UTF-8') ?>" data-template-key="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>">
        <h2><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></h2>
        <?php if ($hint !== ''): ?>
            <p class="hint"><?= htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <p class="hint"><code><?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?></code></p>

        <div class="tpl-layout">
            <div class="tpl-editor-col">
                <?php foreach ($fields as $field):
                    $fieldLabel = $field === 'subject' ? 'Asunto' : 'Cuerpo';
                    $value = (string)($values[$field] ?? '');
                    $isBody = $field === 'body';
                    $inputName = $tab . '__' . $key . '__' . $field;
                ?>
                <div class="field" style="margin-bottom:1rem">
                    <label for="<?= htmlspecialchars($tab . '_' . $key . '_' . $field, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($fieldLabel, ENT_QUOTES, 'UTF-8') ?></label>
                    <?php if ($isBody && $isEmail): ?>
                    <div class="tpl-editor" data-tpl-editor>
                        <div class="tpl-toolbar" role="toolbar" aria-label="Formato">
                            <button type="button" class="btn btn-ghost btn-sm" data-rich-cmd="bold" title="Negrita"><i class="bx bx-bold"></i></button>
                            <button type="button" class="btn btn-ghost btn-sm" data-rich-cmd="italic" title="Cursiva"><i class="bx bx-italic"></i></button>
                            <button type="button" class="btn btn-ghost btn-sm" data-rich-cmd="underline" title="Subrayado"><i class="bx bx-underline"></i></button>
                            <button type="button" class="btn btn-ghost btn-sm" data-rich-cmd="insertHTML" data-rich-val="<strong></strong>" title="Negrita (HTML)"><b>B</b></button>
                            <button type="button" class="btn btn-ghost btn-sm" data-rich-cmd="fontSize" data-rich-val="5" title="Texto grande">A+</button>
                            <button type="button" class="btn btn-ghost btn-sm" data-rich-cmd="fontSize" data-rich-val="3" title="Texto normal">A</button>
                            <button type="button" class="btn btn-ghost btn-sm" data-rich-cmd="formatBlock" data-rich-val="h2" title="Título">H2</button>
                            <button type="button" class="btn btn-ghost btn-sm" data-rich-cmd="link" title="Enlace"><i class="bx bx-link"></i></button>
                            <button type="button" class="btn btn-ghost btn-sm" data-rich-cmd="logo" title="Insertar logo">{logo}</button>
                        </div>
                        <div class="tpl-rich" contenteditable="true" data-rich-body id="<?= htmlspecialchars($tab . '_' . $key . '_' . $field, ENT_QUOTES, 'UTF-8') ?>_rich"></div>
                        <textarea id="<?= htmlspecialchars($tab . '_' . $key . '_' . $field, ENT_QUOTES, 'UTF-8') ?>"
                                  name="<?= htmlspecialchars($inputName, ENT_QUOTES, 'UTF-8') ?>"
                                  rows="8" spellcheck="false" hidden data-rich-source data-preview-body-source><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                    <?php elseif ($isBody): ?>
                    <textarea id="<?= htmlspecialchars($tab . '_' . $key . '_' . $field, ENT_QUOTES, 'UTF-8') ?>"
                              name="<?= htmlspecialchars($inputName, ENT_QUOTES, 'UTF-8') ?>"
                              rows="5" data-preview-body-source><?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?></textarea>
                    <?php else: ?>
                    <input type="text" id="<?= htmlspecialchars($tab . '_' . $key . '_' . $field, ENT_QUOTES, 'UTF-8') ?>"
                           name="<?= htmlspecialchars($inputName, ENT_QUOTES, 'UTF-8') ?>"
                           value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>"
                           data-preview-subject-source>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <div class="tpl-preview-col">
                <p class="hint" style="margin-top:0">Vista previa</p>
                <div class="tpl-preview" data-preview-target>
                    <p class="hint">Generando vista previa…</p>
                </div>
            </div>
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

<script src="assets/js/plantillas-editor.js" defer></script>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
