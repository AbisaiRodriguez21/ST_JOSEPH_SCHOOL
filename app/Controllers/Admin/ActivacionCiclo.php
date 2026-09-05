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

        // Ciclo sugerido = el SIGUIENTE al activo (al que normalmente se activa: el nuevo año).
        $cicloSugerido = null;
        foreach ($ciclos as $c) {
            $id = (int) $c['id_cicloEscolar'];
            if ($cicloActivo !== null && $id > $cicloActivo) {
                if ($cicloSugerido === null || $id < $cicloSugerido) {
                    $cicloSugerido = $id;
                }
            }
        }
        if ($cicloSugerido === null) {
            $cicloSugerido = $cicloActivo; // no hay uno más nuevo, cae al activo
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

        $file = $this->request->getFile('archivo_csv');
        if (!$file || !$file->isValid()) {
            return redirect()->back()->with('error', 'El archivo no es válido.');
        }
        if (strtolower($file->getExtension()) !== 'csv') {
            return redirect()->back()->with('error', 'El archivo debe ser CSV. Exporta el Excel de matrículas a .csv antes de subirlo.');
        }

        $parseado = $model->parsearArchivo($file->getTempName());
        if (empty($parseado['items'])) {
            return redirect()->back()->with('error', 'No se encontró ninguna matrícula en el archivo. Revisa el formato.');
        }

        $clasificado = $model->clasificar($parseado, $idCiclo);

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
