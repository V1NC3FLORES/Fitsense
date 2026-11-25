<?php
session_start();
require_once __DIR__ . '/../conn.php';
require_once __DIR__ . '/../config/session_security.php';
require_once __DIR__ . '/../config/backup.php';

$userSession = app_require_session_user($conn, ['admin', 'super_admin'], 'admin-login.php');
$userId = (int)($_SESSION['user_id'] ?? 0);
$restoreMaxBytes = app_backup_restore_max_bytes();
$fullRestoreMaxBytes = app_backup_full_restore_max_bytes();

/**
 * Return the current PHP upload error as an administrator-readable message.
 */
function backupUploadErrorMessage(int $code, int $maxBytes): string
{
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'The uploaded file is larger than the server upload limit (' . app_backup_format_bytes($maxBytes) . ' maximum).';
        case UPLOAD_ERR_PARTIAL:
            return 'The file was only partially uploaded. Please try again.';
        case UPLOAD_ERR_NO_FILE:
            return 'Please choose a .sql backup file to restore.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'The server upload temporary directory is unavailable.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'The server could not write the uploaded file to disk.';
        case UPLOAD_ERR_EXTENSION:
            return 'The upload was stopped by a PHP extension.';
        default:
            return 'The file upload failed. Please try again.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // PHP discards POST/FILES data when the request exceeds post_max_size.
        if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw new RuntimeException('The upload exceeded the server POST size limit. Increase post_max_size or use a smaller backup file.');
        }

        if (!app_validate_csrf_token($_POST['csrf_token'] ?? null)) {
            throw new RuntimeException('The security token is invalid or expired. Please refresh the page and try again.');
        }

        $action = (string)($_POST['action'] ?? '');

        if ($action === 'create_backup') {
            $backup = app_backup_create_sql();
            $handle = fopen($backup['path'], 'rb');
            if (!$handle) {
                @unlink($backup['path']);
                throw new RuntimeException('The generated backup file could not be opened for download.');
            }

            $_SESSION['backup_flash'] = [
                'type' => 'success',
                'message' => 'Database backup downloaded: ' . $backup['filename'],
            ];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $backup['filename'] . '"');
            header('Content-Length: ' . filesize($backup['path']));
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');

            try {
                fpassthru($handle);
            } finally {
                fclose($handle);
                @unlink($backup['path']);
            }
            exit;
        }

        if ($action === 'create_full_backup') {
            $backup = app_backup_create_full();
            $handle = fopen($backup['path'], 'rb');
            if (!$handle) {
                @unlink($backup['path']);
                throw new RuntimeException('The generated full backup file could not be opened for download.');
            }

            $_SESSION['backup_flash'] = [
                'type' => 'success',
                'message' => 'Full system backup downloaded: ' . $backup['filename'],
            ];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $backup['filename'] . '"');
            header('Content-Length: ' . filesize($backup['path']));
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('X-Content-Type-Options: nosniff');

            try {
                fpassthru($handle);
            } finally {
                fclose($handle);
                @unlink($backup['path']);
            }
            exit;
        }

        if ($action === 'restore_backup') {
            if ((string)($_POST['restore_confirmation'] ?? '') !== '1') {
                throw new RuntimeException('Restore confirmation is required. Please confirm the warning before restoring.');
            }

            if (!isset($_FILES['backup_file']) || !is_array($_FILES['backup_file'])) {
                throw new RuntimeException('Please choose a .sql backup file to restore.');
            }

            $file = $_FILES['backup_file'];
            $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError !== UPLOAD_ERR_OK) {
                throw new RuntimeException(backupUploadErrorMessage($uploadError, $restoreMaxBytes));
            }

            $originalName = basename((string)($file['name'] ?? ''));
            if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'sql') {
                throw new RuntimeException('Only .sql files are allowed.');
            }

            $fileSize = (int)($file['size'] ?? 0);
            if ($fileSize <= 0) {
                throw new RuntimeException('The uploaded SQL file is empty.');
            }
            if ($fileSize > $restoreMaxBytes) {
                throw new RuntimeException('The uploaded file is too large. Maximum allowed size is ' . app_backup_format_bytes($restoreMaxBytes) . '.');
            }

            $uploadedPath = (string)($file['tmp_name'] ?? '');
            if ($uploadedPath === '' || !is_uploaded_file($uploadedPath)) {
                throw new RuntimeException('The uploaded file could not be verified.');
            }

            $temporaryPath = app_backup_directory() . DIRECTORY_SEPARATOR . 'restore-' . bin2hex(random_bytes(8)) . '.sql';
            if (!move_uploaded_file($uploadedPath, $temporaryPath)) {
                throw new RuntimeException('The uploaded SQL file could not be moved to private temporary storage.');
            }

            try {
                $actualSize = filesize($temporaryPath);
                if ($actualSize === false || $actualSize <= 0) {
                    throw new RuntimeException('The uploaded SQL file is empty.');
                }
                if ($actualSize > $restoreMaxBytes) {
                    throw new RuntimeException('The uploaded file is too large. Maximum allowed size is ' . app_backup_format_bytes($restoreMaxBytes) . '.');
                }

                app_backup_validate_sql_file($temporaryPath);
                app_backup_restore_file($temporaryPath);
            } finally {
                if (is_file($temporaryPath)) {
                    @unlink($temporaryPath);
                }
            }

            $_SESSION['backup_flash'] = [
                'type' => 'success',
                'message' => 'Database restored successfully from ' . $originalName . '.',
            ];
            header('Location: admin-backups.php', true, 303);
            exit;
        }

        if ($action === 'restore_full_backup') {
            if ((string)($_POST['full_restore_confirmation'] ?? '') !== '1') {
                throw new RuntimeException('Full system restore confirmation is required. Please confirm the warning before restoring.');
            }

            if (!isset($_FILES['full_backup_file']) || !is_array($_FILES['full_backup_file'])) {
                throw new RuntimeException('Please choose a full system backup ZIP file to restore.');
            }

            $file = $_FILES['full_backup_file'];
            $uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError === UPLOAD_ERR_NO_FILE) {
                throw new RuntimeException('Please choose a full system backup ZIP file to restore.');
            }
            if ($uploadError !== UPLOAD_ERR_OK) {
                throw new RuntimeException(backupUploadErrorMessage($uploadError, $fullRestoreMaxBytes));
            }

            $originalName = basename((string)($file['name'] ?? ''));
            if (strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'zip') {
                throw new RuntimeException('Only .zip full system backup files are allowed.');
            }

            $fileSize = (int)($file['size'] ?? 0);
            if ($fileSize <= 0) {
                throw new RuntimeException('The uploaded full system backup is empty.');
            }
            if ($fileSize > $fullRestoreMaxBytes) {
                throw new RuntimeException('The uploaded file is too large. Maximum allowed size is ' . app_backup_format_bytes($fullRestoreMaxBytes) . '.');
            }

            $uploadedPath = (string)($file['tmp_name'] ?? '');
            if ($uploadedPath === '' || !is_uploaded_file($uploadedPath)) {
                throw new RuntimeException('The uploaded file could not be verified.');
            }

            $temporaryPath = app_backup_directory() . DIRECTORY_SEPARATOR . 'full-upload-' . bin2hex(random_bytes(8)) . '.zip';
            if (!move_uploaded_file($uploadedPath, $temporaryPath)) {
                throw new RuntimeException('The uploaded full system backup could not be moved to private temporary storage.');
            }

            try {
                $actualSize = filesize($temporaryPath);
                if ($actualSize === false || $actualSize <= 0) {
                    throw new RuntimeException('The uploaded full system backup is empty.');
                }
                if ($actualSize > $fullRestoreMaxBytes) {
                    throw new RuntimeException('The uploaded file is too large. Maximum allowed size is ' . app_backup_format_bytes($fullRestoreMaxBytes) . '.');
                }

                app_backup_restore_full($temporaryPath);
            } finally {
                if (is_file($temporaryPath)) {
                    @unlink($temporaryPath);
                }
            }

            $_SESSION['backup_flash'] = [
                'type' => 'success',
                'message' => 'Full system restored successfully from ' . $originalName . '.',
            ];
            header('Location: admin-backups.php', true, 303);
            exit;
        }

        throw new RuntimeException('Unknown backup action.');
    } catch (Throwable $e) {
        app_log_error('Admin database backup action failed.', [
            'admin_id' => $userId,
            'action' => (string)($_POST['action'] ?? ''),
            'error' => $e->getMessage(),
        ]);
        $_SESSION['backup_flash'] = [
            'type' => 'error',
            'message' => 'The operation failed: ' . app_backup_safe_error($e),
        ];
        header('Location: admin-backups.php', true, 303);
        exit;
    }
}

$flash = $_SESSION['backup_flash'] ?? null;
unset($_SESSION['backup_flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Backup &amp; Restore - FitSense Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --blue: #005b9f; --blue-dark: #00467a; --yellow: #ffce00; --bg: #f4f6f9; --text: #263445; --muted: #64748b; --border: #e5eaf0; }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font-family: "Segoe UI", Tahoma, sans-serif; }
        .topbar { background: linear-gradient(110deg, var(--blue-dark), var(--blue)); color: #fff; padding: 18px max(24px, calc((100% - 1120px) / 2)); display: flex; align-items: center; justify-content: space-between; gap: 16px; }
        .brand { display: flex; align-items: center; gap: 12px; font-weight: 800; letter-spacing: .04em; }
        .brand img { width: 34px; height: 34px; border-radius: 50%; object-fit: cover; }
        .topbar a { color: #fff; text-decoration: none; font-weight: 600; }
        main { width: min(1120px, calc(100% - 32px)); margin: 32px auto; }
        h1 { margin: 0 0 8px; font-size: clamp(24px, 4vw, 32px); }
        .lead { color: var(--muted); margin: 0 0 24px; line-height: 1.55; }
        .notice { padding: 14px 16px; border-radius: 10px; margin: 0 0 20px; line-height: 1.5; overflow-wrap: anywhere; }
        .notice.success { background: #e8f7ed; color: #17653a; border: 1px solid #bce7c9; }
        .notice.error { background: #fff0ef; color: #9c2f28; border: 1px solid #f3c7c3; }
        .grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 18px; align-items: start; }
        .card { background: #fff; border: 1px solid var(--border); border-radius: 14px; padding: 22px; box-shadow: 0 6px 20px rgba(20, 45, 70, .05); }
        .card h2 { margin: 0 0 10px; font-size: 19px; }
        .card p { color: var(--muted); line-height: 1.55; margin: 0 0 18px; }
        .button { display: inline-flex; align-items: center; gap: 8px; border: 0; border-radius: 8px; padding: 11px 16px; background: var(--blue); color: #fff; font-size: 14px; font-weight: 700; cursor: pointer; }
        .button:hover { background: var(--blue-dark); }
        .button.danger { background: #b42318; }
        .button.danger:hover { background: #8f1c13; }
        label { display: block; font-weight: 650; margin: 14px 0 7px; }
        input[type="file"] { width: 100%; padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font: inherit; background: #fff; }
        .check { display: flex; align-items: flex-start; gap: 9px; margin: 14px 0 16px; font-size: 13.5px; line-height: 1.45; color: #4b5563; }
        .check input { margin: 3px 0 0; accent-color: var(--blue); }
        .check label { margin: 0; font-weight: 600; }
        .warning { padding: 12px 14px; border-left: 4px solid #e29b12; background: #fff8e6; border-radius: 6px; color: #6d4a08; font-size: 13px; line-height: 1.5; }
        .hint { font-size: 12.5px; color: var(--muted); margin-top: 12px; line-height: 1.5; }
        code { background: #f1f5f9; padding: 1px 5px; border-radius: 4px; }
        @media (max-width: 720px) { .grid { grid-template-columns: 1fr; } .topbar { padding: 16px; } main { margin-top: 22px; } }
    </style>
<link rel="stylesheet" href="/PBL/css/icon-policy.css">
<link rel="stylesheet" href="/PBL/css/admin-content-icons.css"></head>
<body>
    <header class="topbar">
        <div class="brand"><img src="../uploads/umak-gym-logo.png" alt="UMak Gym"><span>FITSENSE · DATABASE BACKUP &amp; RESTORE</span></div>
        <a href="admin-dashboard.php"><i class="bi bi-arrow-left"></i> Dashboard</a>
    </header>
    <main>
        <h1>Database Backup &amp; Restore</h1>
        <p class="lead">Create a full SQL copy of the gym system database, or a full system backup that also includes uploaded files, and restore either one from a previous backup. Only administrators can use this page.</p>

        <?php if (is_array($flash)): ?>
            <div class="notice <?= htmlspecialchars((string)($flash['type'] ?? 'error'), ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars((string)($flash['message'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <section class="grid">
            <div class="card">
                <h2><i class="bi bi-cloud-arrow-down"></i> Database Backup</h2>
                <p>Download a complete copy of the current gym database.</p>
                <form method="post">
                    <?= app_csrf_field() ?>
                    <input type="hidden" name="action" value="create_backup">
                    <button class="button" type="submit"><i class="bi bi-plus-circle"></i> Create Database Backup</button>
                </form>
                <p class="hint">The <code>.sql</code> file includes table structures, data, indexes, AUTO_INCREMENT values, and foreign keys. It is generated with XAMPP's <code>mysqldump</code> and deleted from the server after it is sent to your browser.</p>
            </div>

            <div class="card">
                <h2><i class="bi bi-file-earmark-zip"></i> Full System Backup</h2>
                <p>Download one ZIP containing the complete database plus all uploaded profile, avatar, and logo files.</p>
                <form method="post">
                    <?= app_csrf_field() ?>
                    <input type="hidden" name="action" value="create_full_backup">
                    <button class="button" type="submit"><i class="bi bi-plus-circle"></i> Create Full System Backup</button>
                </form>
                <p class="hint">The <code>.zip</code> contains <code>database.sql</code>, a small <code>manifest.json</code>, and the <code>uploads/</code> folder with its original directory structure. It excludes <code>.env</code>, credentials, source code, and logs, and is deleted from the server after it is sent to your browser.</p>
            </div>

            <div class="card">
                <h2><i class="bi bi-arrow-counterclockwise"></i> Restore Database</h2>
                <p>Restore the database from a previously created SQL backup.</p>
                <div class="warning">Restoring a database backup will replace the current database data. Continue only if you are sure.</div>
                <form method="post" enctype="multipart/form-data" onsubmit="return confirm('Restoring a database backup will replace the current database data. Continue?');">
                    <?= app_csrf_field() ?>
                    <input type="hidden" name="action" value="restore_backup">
                    <label for="backup_file">SQL backup file</label>
                    <input id="backup_file" type="file" name="backup_file" accept=".sql,application/sql,text/plain" required>
                    <div class="check">
                        <input id="restore_confirmation" type="checkbox" name="restore_confirmation" value="1" required>
                        <label for="restore_confirmation">I understand that the current database data will be replaced.</label>
                    </div>
                    <button class="button danger" type="submit"><i class="bi bi-exclamation-triangle"></i> Restore Database</button>
                </form>
                <p class="hint">Maximum file size: <?= htmlspecialchars(app_backup_format_bytes($restoreMaxBytes), ENT_QUOTES, 'UTF-8') ?>. Only <code>.sql</code> files are accepted. The uploaded file is removed from the server after the restore attempt.</p>
            </div>

            <div class="card">
                <h2><i class="bi bi-arrow-counterclockwise"></i> Full System Restore</h2>
                <p>Restore the database and uploaded files from a Full System Backup ZIP.</p>
                <div class="warning">Restoring a full system backup will replace the current database data and overwrite uploaded files with matching names. Continue only if you are sure.</div>
                <form method="post" enctype="multipart/form-data" onsubmit="return confirm('This will restore the database and uploaded files from the selected backup. Continue?');">
                    <?= app_csrf_field() ?>
                    <input type="hidden" name="action" value="restore_full_backup">
                    <label for="full_backup_file">Full system backup file</label>
                    <input id="full_backup_file" type="file" name="full_backup_file" accept=".zip,application/zip" required>
                    <div class="check">
                        <input id="full_restore_confirmation" type="checkbox" name="full_restore_confirmation" value="1" required>
                        <label for="full_restore_confirmation">I understand that the current database data will be replaced and uploaded files may be overwritten.</label>
                    </div>
                    <button class="button danger" type="submit"><i class="bi bi-exclamation-triangle"></i> Restore Full System</button>
                </form>
                <p class="hint">Maximum file size: <?= htmlspecialchars(app_backup_format_bytes($fullRestoreMaxBytes), ENT_QUOTES, 'UTF-8') ?>. Only ZIP files created by Full System Backup are accepted. The uploaded file and any temporary restore files are removed from the server after the restore attempt.</p>
            </div>
        </section>
    </main>
<script src="/PBL/js/ui-enhancements.js"></script></body>
</html>
