<?php namespace App\Controllers;

use App\Controllers\BaseController;
use App\Models\CorreoModel;
use CodeIgniter\Exceptions\PageNotFoundException;

/**
 * Sirve archivos privados (adjuntos de correo) SOLO a usuarios con sesión.
 *
 * Los archivos se guardan en writable/uploads/ (fuera de public/), así que
 * NO se pueden abrir por URL directa. Este controlador va bajo el filtro
 * 'auth': si no hay sesión, ni siquiera llega aquí.
 */
class Archivo extends BaseController
{
    public function adjunto($idCorreo)
    {
        $correo = (new CorreoModel())->find($idCorreo);
        if (!$correo || empty($correo['adjunto'])) {
            throw PageNotFoundException::forPageNotFound();
        }

        // Solo el nombre del archivo (evita path traversal tipo ../../).
        $nombre = basename($correo['adjunto']);

        // Buscar primero en writable (nuevo), luego en public (archivos viejos).
        $ruta = WRITEPATH . 'uploads/adjuntos/' . $nombre;
        if (!is_file($ruta)) {
            $rutaLegacy = FCPATH . 'uploads/adjuntos/' . $nombre;
            if (is_file($rutaLegacy)) {
                $ruta = $rutaLegacy;
            } else {
                throw PageNotFoundException::forPageNotFound();
            }
        }

        $mime = function_exists('mime_content_type') ? (mime_content_type($ruta) ?: 'application/octet-stream') : 'application/octet-stream';

        return $this->response
            ->setHeader('Content-Type', $mime)
            ->setHeader('Content-Disposition', 'inline; filename="' . $nombre . '"')
            ->setHeader('X-Content-Type-Options', 'nosniff')
            ->setBody(file_get_contents($ruta));
    }
}
