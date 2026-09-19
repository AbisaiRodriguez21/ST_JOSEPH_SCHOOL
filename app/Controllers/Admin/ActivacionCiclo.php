<?php namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Models\ActivacionCicloModel;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Módulo de Activación de Ciclo Escolar.
 *
 * Reemplaza el proceso manual de la carpeta "ciclo_alumnos":
 * el admin sube el archivo de matrículas (CSV) del ciclo, elige el ciclo
 * destino, revisa una vista previa y confirma la activación masiva.
 */
class ActivacionCiclo extends BaseController
{
    /** Pantalla inicial: formulario de subida + selección de ciclo. */
    public function index()
    {
        $model = new ActivacionCicloModel();

        $ciclos      = $model->getCiclos();
        $cicloActivo = $model->getCicloActivo();

        // Ciclo sugerido = el que toca segun la fecha de HOY (regla de agosto),
        // no segun mesycicloactivo (que puede quedar desactualizado, p. ej.
        // justo despues de reimportar la BD).
        $cicloSugerido = $model->getCicloSugeridoPorFecha();

        if ($cicloSugerido === null) {
            // Respaldo: el catalogo no tiene de alta el ciclo que toca por fecha.
            // Se cae al siguiente del activo, como antes.
            foreach ($ciclos as $c) {
                $id = (int) $c['id_cicloEscolar'];
                if ($cicloActivo !== null && $id > $cicloActivo) {
                    if ($cicloSugerido === null || $id < $cicloSugerido) {
                        $cicloSugerido = $id;
                    }
                }
            }
        }
        if ($cicloSugerido === null) {
            $cicloSugerido = $cicloActivo; // ultimo recurso
        }

        return view('ActivacionCiclo/index', [
            'ciclos'        => $ciclos,
            'cicloActivo'   => $cicloActivo,
            'cicloSugerido' => $cicloSugerido,
        ]);
    }

    /** Paso 1: parsear el CSV y mostrar la vista previa clasificada. */
    public function previsualizar()
    {
        $model = new ActivacionCicloModel();

        $idCiclo = (int) $this->request->getPost('id_ciclo');
        if ($idCiclo <= 0) {
            return redirect()->back()->with('error', 'Debes seleccionar un ciclo escolar destino.');
        }

        // Un solo campo de archivos: acepta .csv (matriculas generales, con o
        // sin secundaria) y .xlsx (listas de grupos, formato Vicente o plantilla
        // nueva) mezclados, en cualquier combinacion. Cada archivo se procesa
        // segun su propia extension, sin necesidad de elegir el campo correcto.
        $archivos = $this->request->getFiles()['archivos'] ?? [];
        if (!is_array($archivos)) {
            $archivos = [$archivos];
        }

        $parseado = ['items' => [], 'pendientes' => []];
        $rutasXlsx = [];
        $seSubioAlgo = false;

        foreach ($archivos as $af) {
            if (!$af || !$af->isValid() || $af->hasMoved()) {
                continue;
            }
            $seSubioAlgo = true;
            $ext = strtolower($af->getExtension());
            if ($ext === 'csv') {
                $p = $model->parsearArchivo($af->getTempName());
                $parseado['items'] = array_merge($parseado['items'], $p['items']);
                $parseado['pendientes'] = array_merge($parseado['pendientes'], $p['pendientes']);
            } elseif (in_array($ext, ['xlsx', 'xls'], true)) {
                $rutasXlsx[] = $af->getTempName();
            }
        }

        if (!$seSubioAlgo) {
            return redirect()->back()->with('error', 'Sube al menos un archivo (.csv o .xlsx).');
        }

        // Listas de grupos reales: .xlsx de la escuela con el grupo A/B
        // verdadero de secundaria, para no repartirlo al azar.
        $gruposReales = [];
        if (!empty($rutasXlsx)) {
            $gruposReales = $model->parsearGruposReales($rutasXlsx);
            // Las matrículas que no vengan ya en algun CSV (p. ej. porque se
            // excluyó secundaria a propósito) se agregan directo: las listas
            // de grupos bastan por sí solas para activar a esos alumnos.
            $matriculasYaCubiertas = array_column($parseado['items'], 'matricula');
            $parseado['items'] = array_merge($parseado['items'], $model->itemsDesdeGrupos($gruposReales, $matriculasYaCubiertas));
        }

        if (empty($parseado['items'])) {
            return redirect()->back()->with('error', 'No se encontró ninguna matrícula en los archivos subidos. Revisa el formato.');
        }

        $clasificado = $model->clasificar($parseado, $idCiclo, $gruposReales);

        // Nombre legible del ciclo para mostrarlo
        $nombreCiclo = '';
        foreach ($model->getCiclos() as $c) {
            if ((int) $c['id_cicloEscolar'] === $idCiclo) {
                $nombreCiclo = $c['nombreCicloEscolar'];
                break;
            }
        }

        // Guardamos SOLO lo necesario para aplicar (no confiamos en el cliente).
        session()->set('activacion_pendiente', [
            'id_ciclo' => $idCiclo,
            'listos'   => $clasificado['listos'],
        ]);
        // Clasificación completa para el informe PDF.
        session()->set('activacion_reporte', [
            'idCiclo'     => $idCiclo,
            'nombreCiclo' => $nombreCiclo,
            'clasificado' => $clasificado,
        ]);

        return view('ActivacionCiclo/preview', [
            'idCiclo'      => $idCiclo,
            'nombreCiclo'  => $nombreCiclo,
            'r'            => $clasificado,
            'nombresGrado' => $model->getNombresGrados(),
        ]);
    }

    /** Genera el informe PDF con todos los casos, antes de confirmar. */
    public function reporte()
    {
        $rep = session()->get('activacion_reporte');
        if (empty($rep)) {
            return redirect()->to('activacion-ciclo')
                             ->with('error', 'No hay datos para el informe. Vuelve a subir el archivo.');
        }

        $model = new ActivacionCicloModel();

        $html = view('ActivacionCiclo/reporte_pdf', [
            'nombreCiclo'  => $rep['nombreCiclo'],
            'r'            => $rep['clasificado'],
            'nombresGrado' => $model->getNombresGrados(),
            'fecha'        => date('d/m/Y H:i'),
            'usuario'      => session('nombre') ?: 'Sistema',
        ]);

        $opciones = new Options();
        $opciones->set('isRemoteEnabled', true);
        $dompdf = new Dompdf($opciones);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('Letter', 'portrait');
        $dompdf->render();
        $dompdf->stream('informe_activacion_ciclo.pdf', ['Attachment' => true]);
        exit;
    }

    /** Paso 2: aplicar la activación (confirmada por el admin). */
    public function aplicar()
    {
        $pendiente = session()->get('activacion_pendiente');
        if (empty($pendiente) || empty($pendiente['listos'])) {
            return redirect()->to('activacion-ciclo')
                             ->with('error', 'La sesión expiró o no hay datos para activar. Vuelve a subir el archivo.');
        }

        $model  = new ActivacionCicloModel();
        $result = $model->activarLote($pendiente['listos'], (int) $pendiente['id_ciclo']);

        session()->remove('activacion_pendiente');
        session()->remove('activacion_reporte');

        if (!$result['ok']) {
            return redirect()->to('activacion-ciclo')->with('error', $result['msg']);
        }
        return redirect()->to('activacion-ciclo')->with('success', $result['msg']);
    }
}
