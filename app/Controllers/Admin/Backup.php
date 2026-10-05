<?php namespace App\Controllers\Admin;

use App\Controllers\BaseController;

/**
 * Respaldos de Base de Datos.
 *
 * Genera dumps .sql en writable/backups/ usando mysqldump y, si no está
 * disponible (hosting compartido sin proc_open/exec), un exportador PHP
 * puro vía PDO. Mismo mecanismo que el módulo de respaldos de Yazbek.
 */
class Backup extends BaseController
{
    /** Pantalla principal: lista de respaldos disponibles + botón para generar uno. */
    public function index()
    {
        $backupDir = WRITEPATH . 'backups' . DIRECTORY_SEPARATOR;
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $archivos = [];
        foreach (glob($backupDir . 'backup_*.sql') as $file) {
            $archivos[] = [
                'nombre' => basename($file),
                'tamano' => filesize($file),
                'fecha'  => date('d/m/Y H:i:s', filemtime($file)),
                'ts'     => filemtime($file),
            ];
        }
        usort($archivos, fn($a, $b) => $b['ts'] - $a['ts']);

        return view('Backup/index', [
            'archivos' => $archivos,
        ]);
    }

    /** POST: genera un respaldo manual desde el panel. */
    public function generar()
    {
        $dbCfg  = config('Database')->default;
        $host   = $dbCfg['hostname'] ?? 'localhost';
        $port   = $dbCfg['port']     ?? 3306;
        $user   = $dbCfg['username'] ?? 'root';
        $pass   = $dbCfg['password'] ?? '';
        $dbname = $dbCfg['database'];

        $backupDir = WRITEPATH . 'backups' . DIRECTORY_SEPARATOR;
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }

        $timestamp  = date('Y-m-d_H-i-s');
        $filename   = "backup_{$dbname}_{$timestamp}.sql";
        $outputPath = $backupDir . $filename;

        // ── Intentar mysqldump primero (local/VPS) ───────────────────
        $mysqldump = $this->findMysqldump();
        $usedPhp   = false;

        if ($mysqldump && function_exists('proc_open')) {
            $args = [$mysqldump, "-h{$host}", "-P{$port}", "-u{$user}"];
            if ($pass !== '') $args[] = "-p{$pass}";
            foreach ($this->detectTablasCorrompidas($dbname) as $t) {
                $args[] = "--ignore-table={$dbname}.{$t}";
            }
            array_push($args, '--single-transaction', '--routines', '--triggers', $dbname);

            $descriptorspec = [
                0 => ['pipe', 'r'],
                1 => ['file', $outputPath, 'w'],
                2 => ['pipe', 'w'],
            ];
            $pipes    = [];
            $proc     = proc_open($args, $descriptorspec, $pipes);
            $exitCode = 1;

            if (is_resource($proc)) {
                fclose($pipes[0]);
                fclose($pipes[2]);
                $exitCode = proc_close($proc);
            }

            if ($exitCode !== 0 || !file_exists($outputPath) || filesize($outputPath) < 100) {
                if (file_exists($outputPath)) unlink($outputPath);
                // mysqldump falló — caer en fallback PHP
                $mysqldump = null;
            }
        } else {
            $mysqldump = null; // forzar fallback
        }

        // ── Fallback PHP puro (hosting compartido) ───────────────────
        if ($mysqldump === null) {
            [$ok, $errMsg] = $this->generateBackupPhp($outputPath, $host, (int) $port, $user, $pass, $dbname);
            if (!$ok) {
                if (file_exists($outputPath)) unlink($outputPath);
                return redirect()->to('backup')
                    ->with('error', 'Error al generar el respaldo: ' . $errMsg);
            }
            $usedPhp = true;
        }

        if (!file_exists($outputPath) || filesize($outputPath) < 100) {
            if (file_exists($outputPath)) unlink($outputPath);
            return redirect()->to('backup')
                ->with('error', 'El respaldo generado está vacío o es inválido.');
        }

        $size = round(filesize($outputPath) / 1024, 1);
        return redirect()->to('backup')
            ->with('success', "Respaldo generado: {$filename} ({$size} KB)");
    }

    /** GET: descarga un respaldo existente. */
    public function descargar(string $filename): void
    {
        $filename = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $filename);
        $path = WRITEPATH . 'backups' . DIRECTORY_SEPARATOR . $filename;

        if (!file_exists($path) || !str_starts_with($filename, 'backup_')) {
            show_404();
        }

        $downloadName = $filename;
        if (preg_match('/backup_.+_(\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2})\.sql$/', $filename, $m)) {
            $downloadName = 'BD-STJoseph-' . $m[1] . '.sql';
        }

        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $downloadName . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-cache');
        readfile($path);
        exit;
    }

    /** POST: elimina un respaldo puntual. */
    public function eliminar(string $filename)
    {
        $filename = preg_replace('/[^a-zA-Z0-9_\-.]/', '', $filename);
        $path = WRITEPATH . 'backups' . DIRECTORY_SEPARATOR . $filename;

        if (file_exists($path) && str_starts_with($filename, 'backup_')) {
            unlink($path);
            return redirect()->to('backup')->with('success', 'Respaldo eliminado.');
        }
        return redirect()->to('backup')->with('error', 'Archivo no encontrado.');
    }

    /** POST: elimina todos los respaldos disponibles. */
    public function eliminarTodo()
    {
        $backupDir  = WRITEPATH . 'backups' . DIRECTORY_SEPARATOR;
        $archivos   = glob($backupDir . 'backup_*.sql') ?: [];
        $eliminados = 0;

        foreach ($archivos as $file) {
            if (is_file($file)) {
                unlink($file);
                $eliminados++;
            }
        }

        return redirect()->to('backup')
            ->with('success', "Se eliminaron {$eliminados} respaldo(s).");
    }

    /**
     * Genera un dump SQL completo usando PDO — sin mysqldump ni exec().
     * Compatible con hosting compartido (Hostinger, cPanel, etc.).
     *
     * @return array{bool, string} [éxito, mensaje_error]
     */
    private function generateBackupPhp(
        string $outputPath,
        string $host,
        int    $port,
        string $user,
        string $pass,
        string $dbname
    ): array {
        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            $pdo = new \PDO($dsn, $user, $pass, [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4",
            ]);

            $fp = fopen($outputPath, 'w');
            if (!$fp) {
                return [false, 'No se pudo crear el archivo de respaldo en ' . $outputPath];
            }

            fwrite($fp, "-- ST Joseph School Database Backup (PHP native)\n");
            fwrite($fp, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
            fwrite($fp, "-- Database: {$dbname}\n\n");
            fwrite($fp, "SET NAMES utf8mb4;\n");
            fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            $tables = $pdo->query(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = " . $pdo->quote($dbname) . "
                   AND ENGINE IS NOT NULL
                 ORDER BY TABLE_NAME"
            )->fetchAll(\PDO::FETCH_COLUMN);

            foreach ($tables as $table) {
                $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(\PDO::FETCH_NUM);
                fwrite($fp, "-- --------------------------------------------------------\n");
                fwrite($fp, "-- Table: `{$table}`\n");
                fwrite($fp, "-- --------------------------------------------------------\n\n");
                fwrite($fp, "DROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($fp, $createRow[1] . ";\n\n");

                $stmt = $pdo->query("SELECT * FROM `{$table}`");
                $cols = $stmt->columnCount();
                if ($cols === 0) continue;

                $colNames = [];
                for ($i = 0; $i < $cols; $i++) {
                    $meta       = $stmt->getColumnMeta($i);
                    $colNames[] = '`' . $meta['name'] . '`';
                }
                $colList   = implode(', ', $colNames);
                $batchSize = 200;
                $batch     = [];

                while ($row = $stmt->fetch(\PDO::FETCH_NUM)) {
                    $values  = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote($v), $row);
                    $batch[] = '(' . implode(', ', $values) . ')';
                    if (count($batch) >= $batchSize) {
                        fwrite($fp, "INSERT INTO `{$table}` ({$colList}) VALUES\n"
                            . implode(",\n", $batch) . ";\n");
                        $batch = [];
                    }
                }
                if (!empty($batch)) {
                    fwrite($fp, "INSERT INTO `{$table}` ({$colList}) VALUES\n"
                        . implode(",\n", $batch) . ";\n");
                }
                fwrite($fp, "\n");
            }

            fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($fp);

            return [true, ''];
        } catch (\Throwable $e) {
            return [false, $e->getMessage()];
        }
    }

    /** Detecta tablas sin engine (corruptas/huérfanas) para omitirlas en el respaldo. */
    private function detectTablasCorrompidas(string $dbname): array
    {
        try {
            $db   = \Config\Database::connect();
            $rows = $db->query(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = ? AND ENGINE IS NULL",
                [$dbname]
            )->getResultArray();
            return array_column($rows, 'TABLE_NAME');
        } catch (\Throwable $e) {
            return [];
        }
    }

    /** Busca mysqldump en rutas habituales de XAMPP y MySQL. */
    private function findMysqldump(): ?string
    {
        $candidates = [
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
            'C:\\Program Files\\MySQL\\MySQL Server 5.7\\bin\\mysqldump.exe',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/usr/local/mysql/bin/mysqldump',
        ];
        foreach ($candidates as $p) {
            if (file_exists($p)) return $p;
        }
        if (function_exists('exec')) {
            exec('where mysqldump 2>NUL', $out, $code);
            if ($code === 0 && !empty($out[0])) return trim($out[0]);
            exec('which mysqldump 2>/dev/null', $out2, $code2);
            if ($code2 === 0 && !empty($out2[0])) return trim($out2[0]);
        }
        return null;
    }
}
