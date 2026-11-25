<?php

require_once __DIR__ . '/app_env.php';
require_once __DIR__ . '/error_handling.php';

/**
 * Private directory used for temporary SQL dumps. It must stay outside the
 * web document root so a backup can never be downloaded directly by URL.
 */
function app_backup_directory(): string
{
    $documentRootPath = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $documentRoot = $documentRootPath !== '' ? realpath($documentRootPath) : false;
    if ($documentRoot === false) {
        $documentRoot = realpath(dirname(__DIR__, 2));
    }
    if ($documentRoot === false) {
        throw new RuntimeException('The web document root could not be resolved.');
    }

    $configured = trim((string)app_env('APP_BACKUP_DIR', ''));
    if ($configured === '') {
        $configured = dirname($documentRoot) . DIRECTORY_SEPARATOR . 'PBL-private-backups';
    } elseif (!preg_match('/^(?:[A-Za-z]:[\\\\\/]|\\\\\\\\|\/)/', $configured)) {
        $configured = dirname(__DIR__) . DIRECTORY_SEPARATOR . $configured;
    }

    if (!is_dir($configured) && !mkdir($configured, 0700, true) && !is_dir($configured)) {
        throw new RuntimeException('The private backup directory could not be created.');
    }

    $realDirectory = realpath($configured);
    if ($realDirectory === false || !is_writable($realDirectory)) {
        throw new RuntimeException('The private backup directory is not writable.');
    }

    $normalizedDirectory = rtrim(str_replace('\\', '/', $realDirectory), '/');
    $normalizedDocumentRoot = rtrim(str_replace('\\', '/', $documentRoot), '/');
    if (DIRECTORY_SEPARATOR === '\\') {
        $normalizedDirectory = strtolower($normalizedDirectory);
        $normalizedDocumentRoot = strtolower($normalizedDocumentRoot);
    }
    if (
        $normalizedDirectory === $normalizedDocumentRoot
        || strpos($normalizedDirectory, $normalizedDocumentRoot . '/') === 0
    ) {
        throw new RuntimeException('Backup storage must be outside the web document root.');
    }

    if (DIRECTORY_SEPARATOR !== '\\') {
        @chmod($realDirectory, 0700);
    }

    return $realDirectory;
}

/**
 * Locate the MySQL/MariaDB command-line tool that belongs to this installation.
 * XAMPP layouts differ between the CLI and Apache SAPI, so several safe
 * candidates are checked before giving up.
 */
function app_backup_mysql_tool(string $name): string
{
    $binaryName = DIRECTORY_SEPARATOR === '\\' ? $name . '.exe' : $name;
    $configuredDirectory = trim((string)app_env('MYSQL_BIN_DIR', ''));
    $candidates = [];

    if ($configuredDirectory !== '') {
        $candidates[] = rtrim($configuredDirectory, '\\/') . DIRECTORY_SEPARATOR . $binaryName;
    }

    // CLI: C:\xampp\php\php.exe -> C:\xampp\mysql\bin
    $candidates[] = dirname(PHP_BINARY, 2) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $binaryName;
    // Apache SAPI: C:\xampp\apache\bin\httpd.exe -> C:\xampp\mysql\bin
    $candidates[] = dirname(PHP_BINARY, 3) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $binaryName;

    $documentRootPath = trim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    $documentRoot = $documentRootPath !== '' ? realpath($documentRootPath) : false;
    if ($documentRoot !== false) {
        // C:\xampp\htdocs -> C:\xampp\mysql\bin
        $candidates[] = dirname($documentRoot) . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $binaryName;
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        // Standard XAMPP installation fallback.
        $candidates[] = 'C:' . DIRECTORY_SEPARATOR . 'xampp' . DIRECTORY_SEPARATOR . 'mysql' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . $binaryName;
    }

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException(
        'The MySQL tool "' . $name . '" was not found. Set MYSQL_BIN_DIR in .env to your XAMPP mysql\\bin directory.'
    );
}

function app_backup_database_name(): string
{
    $database = trim((string)app_env('DB_NAME', 'gym_system'));
    if ($database === '' || !preg_match('/^[A-Za-z0-9_$]+$/', $database)) {
        throw new RuntimeException('The configured database name is invalid.');
    }

    return $database;
}

function app_backup_option_value(string $value): string
{
    return '"' . str_replace(
        ["\\", "\"", "\r", "\n"],
        ["\\\\", "\\\"", "\\r", "\\n"],
        $value
    ) . '"';
}

/**
 * Write database credentials to a temporary MySQL option file instead of
 * putting them on the command line or in the browser output.
 */
function app_backup_write_mysql_options(string $directory): string
{
    $path = $directory . DIRECTORY_SEPARATOR . 'mysql-' . bin2hex(random_bytes(8)) . '.cnf';
    $contents = "[client]\n"
        . 'host=' . app_backup_option_value((string)app_env('DB_HOST', 'localhost')) . "\n"
        . 'port=' . (int)app_env('DB_PORT', 3306) . "\n"
        . 'user=' . app_backup_option_value((string)app_env('DB_USER', 'root')) . "\n"
        . 'password=' . app_backup_option_value((string)app_env('DB_PASS', '')) . "\n"
        . "default-character-set=utf8mb4\n";

    if (file_put_contents($path, $contents, LOCK_EX) === false) {
        throw new RuntimeException('The temporary MySQL credentials file could not be created.');
    }
    if (DIRECTORY_SEPARATOR !== '\\') {
        @chmod($path, 0600);
    }

    return $path;
}

function app_backup_process(array $command, array $descriptors): array
{
    if (!function_exists('proc_open')) {
        throw new RuntimeException('PHP proc_open is required for database backup and restore.');
    }

    $pipes = [];
    $options = DIRECTORY_SEPARATOR === '\\' ? ['bypass_shell' => true] : [];
    $process = proc_open($command, $descriptors, $pipes, null, null, $options);
    if (!is_resource($process)) {
        throw new RuntimeException('The MySQL command could not be started.');
    }

    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }
    $stdout = isset($pipes[1]) && is_resource($pipes[1]) ? stream_get_contents($pipes[1]) : '';
    if (isset($pipes[1]) && is_resource($pipes[1])) {
        fclose($pipes[1]);
    }
    $stderr = isset($pipes[2]) && is_resource($pipes[2]) ? stream_get_contents($pipes[2]) : '';
    if (isset($pipes[2]) && is_resource($pipes[2])) {
        fclose($pipes[2]);
    }

    return [
        'exit_code' => proc_close($process),
        'stdout' => (string)$stdout,
        'stderr' => (string)$stderr,
    ];
}

function app_backup_run_dump(string $optionsFile, string $sqlPath): void
{
    $database = app_backup_database_name();

    $result = app_backup_process([
        app_backup_mysql_tool('mysqldump'),
        '--defaults-extra-file=' . $optionsFile,
        '--single-transaction',
        '--quick',
        '--routines',
        '--events',
        '--triggers',
        '--default-character-set=utf8mb4',
        '--hex-blob',
        $database,
    ], [
        0 => ['pipe', 'r'],
        1 => ['file', $sqlPath, 'wb'],
        2 => ['pipe', 'w'],
    ]);

    if ($result['exit_code'] !== 0 || !is_file($sqlPath) || filesize($sqlPath) === 0) {
        throw new RuntimeException('Database export failed: ' . trim($result['stderr']));
    }
}

/**
 * Create a complete SQL dump and return the temporary path plus the filename
 * that will be offered to the browser. The caller must delete the file.
 *
 * @return array{path: string, filename: string}
 */
function app_backup_create_sql(): array
{
    $backupDirectory = app_backup_directory();
    $sqlPath = $backupDirectory . DIRECTORY_SEPARATOR . '.backup-' . bin2hex(random_bytes(8)) . '.sql';
    $optionsFile = null;
    $success = false;

    try {
        $optionsFile = app_backup_write_mysql_options($backupDirectory);
        app_backup_run_dump($optionsFile, $sqlPath);
        $success = true;

        return [
            'path' => $sqlPath,
            'filename' => 'gym_system_backup_' . date('Y-m-d_H-i-s') . '.sql',
        ];
    } finally {
        if ($optionsFile !== null && is_file($optionsFile)) {
            @unlink($optionsFile);
        }
        if (!$success && is_file($sqlPath)) {
            @unlink($sqlPath);
        }
    }
}

function app_backup_import_database(string $sqlPath, string $optionsFile): void
{
    $database = app_backup_database_name();

    $result = app_backup_process([
        app_backup_mysql_tool('mysql'),
        '--defaults-extra-file=' . $optionsFile,
        '--default-character-set=utf8mb4',
        $database,
    ], [
        0 => ['file', $sqlPath, 'rb'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ]);

    if ($result['exit_code'] !== 0) {
        throw new RuntimeException('Database restore failed: ' . trim($result['stderr']));
    }
}

/**
 * Restore a SQL file into the existing configured database. This does not
 * create or drop the database itself.
 */
function app_backup_restore_file(string $sqlPath): void
{
    if (!is_file($sqlPath) || filesize($sqlPath) === 0) {
        throw new RuntimeException('The uploaded SQL file is empty.');
    }

    $directory = app_backup_directory();
    $optionsFile = null;

    try {
        $optionsFile = app_backup_write_mysql_options($directory);
        app_backup_import_database($sqlPath, $optionsFile);
    } finally {
        if ($optionsFile !== null && is_file($optionsFile)) {
            @unlink($optionsFile);
        }
    }
}

function app_backup_validate_sql_file(string $path): void
{
    $handle = fopen($path, 'rb');
    if (!$handle) {
        throw new RuntimeException('The uploaded file could not be read.');
    }
    $sample = fread($handle, 65536);
    fclose($handle);

    if (!is_string($sample) || trim($sample) === '') {
        throw new RuntimeException('The uploaded SQL file is empty.');
    }
    if (strpos($sample, "\0") !== false) {
        throw new RuntimeException('The uploaded file is not a valid SQL text file.');
    }
    if (!preg_match('/\b(CREATE\s+TABLE|INSERT\s+INTO|DROP\s+TABLE|SET\s+FOREIGN_KEY_CHECKS)\b/i', $sample)) {
        throw new RuntimeException('The uploaded file does not look like a SQL database backup.');
    }
}

function app_backup_safe_error(Throwable $error): string
{
    $message = trim($error->getMessage());
    if ($message === '') {
        $message = 'Unknown error.';
    }

    $secrets = [
        (string)app_env('DB_PASS', ''),
        (string)app_env('DB_USER', ''),
    ];
    foreach ($secrets as $secret) {
        if (strlen($secret) >= 3) {
            $message = str_replace($secret, '[redacted]', $message);
        }
    }

    $message = preg_replace('/password\s*=\s*\S+/i', 'password=[redacted]', $message) ?? $message;
    if (strlen($message) > 700) {
        $message = substr($message, 0, 700) . '...';
    }

    return $message;
}

function app_backup_restore_max_bytes(): int
{
    return 32 * 1024 * 1024; // 32 MB, within XAMPP's default 40 MB upload limit.
}

function app_backup_format_bytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    }

    return number_format($bytes / 1024, 1) . ' KB';
}

function app_backup_uploads_directory(): string
{
    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'uploads';
    $realDirectory = realpath($directory);
    if ($realDirectory === false || !is_dir($realDirectory)) {
        throw new RuntimeException('The application uploads directory was not found.');
    }

    return $realDirectory;
}

function app_backup_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '' || $value === '-1' || $value === '0') {
        return 0;
    }

    $unit = strtolower(substr($value, -1));
    $number = (float)$value;
    $multipliers = ['k' => 1024, 'm' => 1048576, 'g' => 1073741824];
    if (isset($multipliers[$unit])) {
        $number *= $multipliers[$unit];
    }

    return (int)$number;
}

/**
 * Full backups can be larger than SQL-only backups. Use the smaller of a
 * sensible cap and the live PHP upload limits, leaving room for multipart
 * request overhead.
 */
function app_backup_full_restore_max_bytes(): int
{
    $limit = 128 * 1024 * 1024;
    foreach (['upload_max_filesize', 'post_max_size'] as $iniKey) {
        $phpLimit = app_backup_ini_bytes((string)ini_get($iniKey));
        if ($phpLimit > 0) {
            $limit = min($limit, $phpLimit);
        }
    }

    if ($limit > 2 * 1024 * 1024) {
        $limit -= 2 * 1024 * 1024;
    }

    return max(1024 * 1024, $limit);
}

/**
 * Normalize and validate a name inside a ZIP. Rejects absolute paths,
 * Windows drive paths, backslash traversal, hidden paths, and "..".
 *
 * @return array{name: string, is_directory: bool}
 */
function app_backup_normalize_zip_entry(string $entryName): array
{
    $normalized = str_replace('\\', '/', $entryName);
    if ($normalized === '' || strlen($normalized) > 1000 || strpos($normalized, "\0") !== false) {
        throw new RuntimeException('The backup ZIP contains an invalid file path.');
    }
    if ($normalized[0] === '/' || preg_match('/^[A-Za-z]:/', $normalized) || strpos($normalized, ':') !== false) {
        throw new RuntimeException('The backup ZIP contains an absolute or unsafe file path.');
    }

    $isDirectory = substr($normalized, -1) === '/';
    if ($isDirectory) {
        $normalized = rtrim($normalized, '/');
    }
    if ($normalized === '') {
        throw new RuntimeException('The backup ZIP contains an invalid file path.');
    }

    foreach (explode('/', $normalized) as $segment) {
        if ($segment === '' || $segment[0] === '.') {
            throw new RuntimeException('The backup ZIP contains an unsafe file path.');
        }
    }

    return ['name' => $normalized, 'is_directory' => $isDirectory];
}

function app_backup_remove_directory(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }

    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        app_backup_remove_directory($entry->getPathname());
    }
    @rmdir($path);
}

/**
 * Create one ZIP containing database.sql plus every file under uploads/.
 *
 * @return array{path: string, filename: string}
 */
function app_backup_create_full(): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP zip extension is required for full system backups.');
    }

    $directory = app_backup_directory();
    $zipPath = $directory . DIRECTORY_SEPARATOR . '.full-' . bin2hex(random_bytes(8)) . '.zip';
    $sql = null;
    $zip = new ZipArchive();
    $opened = false;
    $success = false;

    try {
        $sql = app_backup_create_sql();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The full backup ZIP could not be created.');
        }
        $opened = true;

        if (!$zip->addFile($sql['path'], 'database.sql')) {
            throw new RuntimeException('database.sql could not be added to the full backup.');
        }

        $manifest = [
            'format' => 'fitsense_full_backup',
            'format_version' => 1,
            'application' => 'FitSense Gym System',
            'database' => app_backup_database_name(),
            'created_at' => date(DATE_ATOM),
            'includes_uploads' => true,
        ];
        $manifestJson = json_encode($manifest, JSON_UNESCAPED_SLASHES);
        if ($manifestJson === false || !$zip->addFromString('manifest.json', $manifestJson)) {
            throw new RuntimeException('The full backup manifest could not be added.');
        }

        $uploadsDirectory = app_backup_uploads_directory();
        if (!$zip->addEmptyDir('uploads')) {
            throw new RuntimeException('The uploads folder could not be added to the full backup.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadsDirectory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }

            $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($uploadsDirectory) + 1));
            try {
                $relativePath = app_backup_normalize_zip_entry($relativePath)['name'];
            } catch (Throwable $e) {
                // Never include hidden or unsafe paths such as .env.
                continue;
            }

            if (!$zip->addFile($file->getPathname(), 'uploads/' . $relativePath)) {
                throw new RuntimeException('An uploaded file could not be added to the full backup.');
            }
        }

        $closeResult = $zip->close();
        $opened = false;
        if (!$closeResult) {
            throw new RuntimeException('The full backup ZIP could not be finalized.');
        }

        $success = true;
        return [
            'path' => $zipPath,
            'filename' => 'gym_system_full_backup_' . date('Y-m-d_H-i-s') . '.zip',
        ];
    } finally {
        if ($opened) {
            @$zip->close();
        }
        if (is_array($sql) && isset($sql['path']) && is_file($sql['path'])) {
            @unlink($sql['path']);
        }
        if (!$success && is_file($zipPath)) {
            @unlink($zipPath);
        }
    }
}

/**
 * Copy staged uploaded files over the live uploads directory. This runs only
 * after the ZIP has been validated and the database restore has succeeded.
 */
function app_backup_copy_uploads(string $source, string $destination): void
{
    $realSource = realpath($source);
    if ($realSource === false || !is_dir($realSource)) {
        return;
    }

    $realDestination = realpath($destination);
    if ($realDestination === false) {
        if (!mkdir($destination, 0755, true) && !is_dir($destination)) {
            throw new RuntimeException('The application uploads directory could not be created.');
        }
        $realDestination = realpath($destination);
    }
    if ($realDestination === false) {
        throw new RuntimeException('The application uploads directory could not be resolved.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($realSource, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isLink()) {
            continue;
        }

        $relativePath = str_replace('\\', '/', substr($item->getPathname(), strlen($realSource) + 1));
        $relativePath = app_backup_normalize_zip_entry($relativePath)['name'];
        $target = $realDestination . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                throw new RuntimeException('An uploads folder could not be restored.');
            }
            continue;
        }

        if (!$item->isFile()) {
            continue;
        }

        $parent = dirname($target);
        if (!is_dir($parent) && !mkdir($parent, 0755, true) && !is_dir($parent)) {
            throw new RuntimeException('An uploads folder could not be restored.');
        }
        if (!copy($item->getPathname(), $target)) {
            throw new RuntimeException('An uploaded file could not be restored: ' . $relativePath);
        }
    }
}

/**
 * Validate and restore a Full System Backup ZIP. Extraction happens in a
 * private staging directory first. The database is restored before any live
 * uploaded file is overwritten.
 */
function app_backup_restore_full(string $zipPath): void
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('The PHP zip extension is required for full system restores.');
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('The selected full backup ZIP is invalid or unreadable.');
    }

    $stageDirectory = app_backup_directory() . DIRECTORY_SEPARATOR . 'full-restore-' . bin2hex(random_bytes(8));
    if (!mkdir($stageDirectory, 0700)) {
        @$zip->close();
        throw new RuntimeException('A temporary full-restore directory could not be created.');
    }

    try {
        $manifestContents = $zip->getFromName('manifest.json');
        $manifest = is_string($manifestContents) ? json_decode($manifestContents, true) : null;
        if (
            !is_array($manifest)
            || ($manifest['format'] ?? '') !== 'fitsense_full_backup'
            || (int)($manifest['format_version'] ?? 0) !== 1
            || ($manifest['includes_uploads'] ?? false) !== true
        ) {
            throw new RuntimeException('The selected ZIP is not a valid Full System Backup.');
        }

        $hasDatabase = false;
        $hasUploads = false;
        $totalUncompressed = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if (!is_string($entryName)) {
                throw new RuntimeException('The backup ZIP contains an unreadable entry.');
            }

            $entry = app_backup_normalize_zip_entry($entryName);
            $name = $entry['name'];
            $isDirectory = $entry['is_directory'];

            $opsys = 0;
            $attributes = 0;
            if ($zip->getExternalAttributesIndex($i, $opsys, $attributes)) {
                $fileType = ($attributes >> 16) & 0170000;
                if ($fileType === 0120000) {
                    throw new RuntimeException('The backup ZIP contains a symbolic link.');
                }
            }

            if ($name === 'manifest.json') {
                if ($isDirectory) {
                    throw new RuntimeException('The backup ZIP manifest is invalid.');
                }
                continue;
            }

            if ($name === 'database.sql') {
                if ($isDirectory) {
                    throw new RuntimeException('database.sql is not a file in the backup ZIP.');
                }
                $hasDatabase = true;
            } elseif ($name === 'uploads') {
                if (!$isDirectory) {
                    throw new RuntimeException('The uploads entry is not a folder in the backup ZIP.');
                }
                $hasUploads = true;
            } elseif (strpos($name, 'uploads/') === 0) {
                $hasUploads = true;
            } else {
                throw new RuntimeException('The backup ZIP contains an unexpected entry: ' . $name);
            }

            $stat = $zip->statIndex($i);
            $totalUncompressed += is_array($stat) ? (int)($stat['size'] ?? 0) : 0;
            if ($totalUncompressed > 512 * 1024 * 1024) {
                throw new RuntimeException('The backup ZIP expands beyond the allowed size.');
            }

            if ($isDirectory) {
                if ($name === 'uploads' || strpos($name, 'uploads/') === 0) {
                    $relativeDirectory = $name === 'uploads' ? '' : substr($name, strlen('uploads/'));
                    $directory = $stageDirectory . DIRECTORY_SEPARATOR . 'uploads';
                    if ($relativeDirectory !== '') {
                        $directory .= DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativeDirectory);
                    }
                    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
                        throw new RuntimeException('An uploads folder could not be prepared for restore.');
                    }
                }
                continue;
            }

            if ($name === 'database.sql') {
                $destination = $stageDirectory . DIRECTORY_SEPARATOR . 'database.sql';
            } else {
                $relativePath = substr($name, strlen('uploads/'));
                $destination = $stageDirectory . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR
                    . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            }

            $parent = dirname($destination);
            if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                throw new RuntimeException('A restore directory could not be created.');
            }

            $input = $zip->getStream($entryName);
            if (!is_resource($input)) {
                throw new RuntimeException('A file in the backup ZIP could not be read.');
            }
            $output = fopen($destination, 'xb');
            if (!$output) {
                fclose($input);
                throw new RuntimeException('A file from the backup ZIP could not be extracted.');
            }

            $copied = stream_copy_to_stream($input, $output);
            fclose($input);
            fclose($output);
            if ($copied === false) {
                throw new RuntimeException('A file from the backup ZIP could not be extracted.');
            }
        }

        if (!$hasDatabase) {
            throw new RuntimeException('The full backup does not contain database.sql.');
        }
        if (!$hasUploads) {
            throw new RuntimeException('The full backup does not contain the uploads folder.');
        }

        $databasePath = $stageDirectory . DIRECTORY_SEPARATOR . 'database.sql';
        if (!is_file($databasePath) || filesize($databasePath) === 0) {
            throw new RuntimeException('The database.sql file in the full backup is empty.');
        }
        app_backup_validate_sql_file($databasePath);

        // Restore the database first. Live uploaded files are not touched if
        // this step fails.
        app_backup_restore_file($databasePath);

        $stagedUploads = $stageDirectory . DIRECTORY_SEPARATOR . 'uploads';
        if (is_dir($stagedUploads)) {
            app_backup_copy_uploads($stagedUploads, app_backup_uploads_directory());
        }
    } finally {
        @$zip->close();
        app_backup_remove_directory($stageDirectory);
    }
}
