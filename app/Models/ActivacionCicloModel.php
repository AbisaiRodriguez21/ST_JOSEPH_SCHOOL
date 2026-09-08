<?php namespace App\Models;

use CodeIgniter\Model;

/**
 * ActivacionCicloModel
 *
 * Versión MASIVA del proceso de la carpeta "ciclo_alumnos" (recibe_excel.php).
 * El admin sube el archivo CRUDO de matrículas (agrupadas por sección de grado);
 * el módulo:
 *   1. Extrae las matrículas y su grado (por la sección) -> resuelve matrícula a id_usr.
 *   2. Hace EXACTAMENTE el mismo update que el sistema viejo, por cada alumno:
 *        - UPDATE usr (generacionactiva, activo=1, estatus=1, grado, pass reset)
 *        - INSERT calificacion  (por materia × mes)
 *        - INSERT calificacion_observaciones (momentos + general)
 */
class ActivacionCicloModel extends Model
{
    protected $table      = 'usr';
    protected $primaryKey = 'id';

    /** Sección del archivo (MAYÚSCULAS) -> id_grado. */
    private $mapaGrados = [
        'MATERNAL'      => 17,
        'PREESCOLAR 1'  => 19, '1 KINDER' => 19,
        'PREESCOLAR 2'  => 20, '2 KINDER' => 20,
        'PREESCOLAR 3'  => 21, '3 KINDER' => 21,
        'PRIMARIA 1'    => 22, '1 PRIMARIA' => 22,
        'PRIMARIA 2'    => 23, '2 PRIMARIA' => 23,
        'PRIMARIA 3'    => 24, '3 PRIMARIA' => 24,
        'PRIMARIA 4'    => 25, '4 PRIMARIA' => 25,
        'PRIMARIA 5'    => 26, '5 PRIMARIA' => 26,
        'PRIMARIA 6'    => 27, '6 PRIMARIA' => 27,
        'SECUNDARIA 1 A' => 28, 'SECUNDARIA 2 A' => 29, 'SECUNDARIA 3 A' => 30,
        'SECUNDARIA 1 B' => 34, 'SECUNDARIA 2 B' => 35, 'SECUNDARIA 3 B' => 36,
        // Bachillerato = Prepa. En el archivo suele venir como "PREPA SJS N".
        'BACHILLERATO 1' => 31, 'BACHILLERATO 2' => 32, 'BACHILLERATO 3' => 33,
        'PREPA SJS 1' => 31, 'PREPA SJS 2' => 32, 'PREPA SJS 3' => 33,
        'PREPA 1' => 31, 'PREPA 2' => 32, 'PREPA 3' => 33,
        'PREPARATORIA 1' => 31, 'PREPARATORIA 2' => 32, 'PREPARATORIA 3' => 33,
    ];

    /**
     * Secundaria por nivel -> id_grado (A y B). El archivo trae "SECUNDARIA N"
     * sin la letra; el grupo se toma del grado que el alumno ya tiene en la BD
     * (28-30 = A, 34-36 = B). Si no se puede saber, se usa A por defecto.
     */
    private $secA = [1 => 28, 2 => 29, 3 => 30];
    private $secB = [1 => 34, 2 => 35, 3 => 36];

    /** Catálogo de ciclos para el dropdown. */
    public function getCiclos()
    {
        return $this->db->table('cicloescolar')->orderBy('id_cicloEscolar', 'DESC')->get()->getResultArray();
    }

    /** Ciclo activo actual (para preseleccionar). */
    public function getCicloActivo()
    {
        $row = $this->db->table('mesycicloactivo')->where('id', 1)->get()->getRow();
        return $row ? (int) $row->id_ciclo : null;
    }

    /** Mapa id_grado => nombreGrado (para mostrar nombres en la vista/informe). */
    public function getNombresGrados()
    {
        $mapa = [];
        foreach ($this->db->table('grados')->select('id_grado, nombreGrado')->get()->getResultArray() as $r) {
            $mapa[(int) $r['id_grado']] = $r['nombreGrado'];
        }
        return $mapa;
    }

    /**
     * Interpreta una fila de sección de grado.
     * @return array|null ['tipo'=>'fijo','id'=>int] | ['tipo'=>'sec','nivel'=>int] | ['tipo'=>'desconocido'] | null
     */
    /**
     * Limpia una celda: quita espacios normales Y "no-rompibles" (nbsp: en UTF-8
     * llega como \xC2\xA0, o como \xA0). Excel/WPS suele pegar estos espacios
     * invisibles al inicio de las matrículas, y PHP trim() no los quita.
     */
    private function limpiarCelda($v)
    {
        $v = str_replace(["\xC2\xA0", "\xA0"], ' ', (string) $v);
        return trim($v);
    }

    public function interpretarSeccion($texto)
    {
        $texto = str_replace(["\xC2\xA0", "\xA0"], ' ', (string) $texto);
        $clave = strtoupper(trim(preg_replace('/\s+/', ' ', $texto)));
        if ($clave === '' || in_array($clave, ['CURP', 'MATRICULA'], true)) {
            return null;
        }
        if (isset($this->mapaGrados[$clave])) {
            return ['tipo' => 'fijo', 'id' => $this->mapaGrados[$clave]];
        }
        if (preg_match('/^SECUNDARIA ([123])$/', $clave, $m) || preg_match('/^([123]) SECUNDARIA$/', $clave, $m)) {
            return ['tipo' => 'sec', 'nivel' => (int) $m[1]];
        }
        if (preg_match('/(MATERNAL|PREESCOLAR|KINDER|PRIMARIA|SECUNDARIA|BACHILLERATO|PREPA)/', $clave)) {
            return ['tipo' => 'desconocido'];
        }
        return null;
    }

    /**
     * Lee el archivo CRUDO (CSV exportado del Excel de matrículas) de forma stateful:
     * al topar una fila de sección fija el grado actual; a cada matrícula posterior
     * le asigna ese grado, hasta la siguiente sección.
     *
     * @return array{items: array, pendientes: int}
     */
    public function parsearArchivo($rutaTemporal)
    {
        $items = [];
        $pendientes = [];  // filas de alumnos SIN matrícula (se llenan a mano con datos personales)
        $seccion = null;
        $idGrado = null;   // grado directo mapeado
        $secNivel = null;  // nivel de secundaria (1/2/3) sin grupo

        $handle = fopen($rutaTemporal, 'r');
        if ($handle === false) {
            return ['items' => [], 'pendientes' => []];
        }

        while (($fila = fgetcsv($handle)) !== false) {
            $matricula = null;
            $esPendiente = false;

            // 1. Buscar una matrícula exacta (4 díg + A/a + 3-6 díg) en la fila
            foreach ($fila as $celda) {
                $val = $this->limpiarCelda($celda);
                if (preg_match('/^\d{4}[Aa]\d{3,6}$/', $val)) {
                    $matricula = strtoupper($val);
                    break;
                }
                if (strtoupper($val) === 'PENDIENTE') {
                    $esPendiente = true; // una sola marca por fila
                }
            }

            if ($matricula !== null) {
                $items[] = [
                    'matricula' => $matricula,
                    'seccion'   => $seccion,
                    'id_grado'  => $idGrado,
                    'sec_nivel' => $secNivel,
                ];
                continue;
            }

            // 1b. Fila de alumno SIN matrícula (PENDIENTE) -> capturar sus datos para llenarlo a mano
            if ($esPendiente) {
                $datos = [];
                foreach ($fila as $celda) {
                    $v = $this->limpiarCelda($celda);
                    if ($v === '' || strtoupper($v) === 'PENDIENTE') continue;
                    if (preg_match('/^\d{1,2}$/', $v)) continue; // saltar índice/año/mes/día cortos
                    $datos[] = $v;
                }
                $pendientes[] = [
                    'seccion' => $seccion,
                    'datos'   => implode(' | ', $datos),
                ];
                continue;
            }

            // 2. ¿Fila de sección de grado?
            foreach ($fila as $celda) {
                $info = $this->interpretarSeccion($celda);
                if ($info === null) {
                    continue;
                }
                $seccion = $this->limpiarCelda($celda);
                if ($info['tipo'] === 'fijo') {
                    $idGrado = $info['id'];
                    $secNivel = null;
                } elseif ($info['tipo'] === 'sec') {
                    $idGrado = null;
                    $secNivel = $info['nivel'];
                } else {
                    $idGrado = null;
                    $secNivel = null;
                }
                break;
            }
        }
        fclose($handle);

        return ['items' => $items, 'pendientes' => $pendientes];
    }

    /** Cuenta cuántas filas PENDIENTE (sin matrícula) trae el archivo. */
    public function contarPendientes(array $parseado)
    {
        return count($parseado['pendientes'] ?? []);
    }

    /**
     * Cruza matrículas contra la BD y las clasifica en:
     * listos (se activarán) y casos que se saltan (no encontradas / grado no reconocido / duplicadas).
     */
    public function clasificar(array $parseado, int $idCiclo)
    {
        $items = $parseado['items'];
        $pendientes = $parseado['pendientes'] ?? [];  // filas sin matrícula -> llenar a mano

        // Quitar duplicados DENTRO DEL ARCHIVO (misma matrícula en 2 secciones).
        // $vistas guarda la sección de la primera aparición para poder mostrar ambas.
        $vistas = $duplicadas = $unicos = [];
        foreach ($items as $it) {
            if (isset($vistas[$it['matricula']])) {
                $it['seccion_usada']     = $vistas[$it['matricula']]; // la que sí se tomó
                $it['seccion_repetida']  = $it['seccion'];            // la que se ignora
                $duplicadas[] = $it;
                continue;
            }
            $vistas[$it['matricula']] = $it['seccion'];
            $unicos[] = $it;
        }

        // Traer todas las matrículas de un golpe
        $matriculas = array_column($unicos, 'matricula');
        $enBD = [];
        if (!empty($matriculas)) {
            $rows = $this->db->table('usr')
                             ->select('id, matricula, grado, estatus, activo, generacionactiva, Nombre, ap_Alumno, am_Alumno')
                             ->whereIn('matricula', $matriculas)
                             ->get()->getResultArray();
            foreach ($rows as $r) {
                $enBD[$r['matricula']] = $r;
            }
        }
        $nombreDe = static fn($r) => trim(($r['ap_Alumno'] ?? '') . ' ' . ($r['am_Alumno'] ?? '') . ' ' . ($r['Nombre'] ?? '')) ?: '(sin nombre)';

        $listos = $noEncontradas = $gradoNoReconocido = $yaActivos = [];
        $revueltoCounter = 0; // para repartir balanceado A/B en secundaria sin grupo

        foreach ($unicos as $it) {
            $mat = $it['matricula'];
            if (!isset($enBD[$mat])) {
                $noEncontradas[] = $it;
                continue;
            }

            // CANDADO: si ya está ACTIVO en el ciclo destino, no se vuelve a procesar
            // (solo se activan los que están "en proceso"). Evita duplicar si se sube 2 veces.
            if ((int) $enBD[$mat]['activo'] === 1
                && (int) $enBD[$mat]['estatus'] === 1
                && (int) $enBD[$mat]['generacionactiva'] === $idCiclo) {
                $it['id_usr'] = (int) $enBD[$mat]['id'];
                $it['nombre'] = $nombreDe($enBD[$mat]);
                $yaActivos[]  = $it;
                continue;
            }

            $gradoActual = (int) $enBD[$mat]['grado'];
            $revuelto = false;

            // Resolver grado destino
            if ($it['id_grado'] !== null) {
                $idGradoDestino = (int) $it['id_grado'];
            } elseif (($it['sec_nivel'] ?? null) !== null) {
                // Secundaria SIN grupo en el archivo: definir A/B
                $nivel = (int) $it['sec_nivel'];

                if (in_array($gradoActual, $this->secA, true)) {
                    $idGradoDestino = $this->secA[$nivel];        // continúa en grupo A
                } elseif (in_array($gradoActual, $this->secB, true)) {
                    $idGradoDestino = $this->secB[$nivel];        // continúa en grupo B
                } else {
                    // No tiene grupo previo (sube de primaria / nuevo) -> REVOLVER balanceado A/B.
                    // (Sí tienen matrícula, así que se activan; los manuales son los SIN matrícula.)
                    $idGradoDestino = ($revueltoCounter % 2 === 0) ? $this->secA[$nivel] : $this->secB[$nivel];
                    $revueltoCounter++;
                    $revuelto = true;
                }
            } else {
                $it['id_usr'] = (int) $enBD[$mat]['id'];
                $it['nombre'] = $nombreDe($enBD[$mat]);
                $gradoNoReconocido[] = $it;
                continue;
            }

            $listos[] = [
                'matricula'    => $mat,
                'id_usr'       => (int) $enBD[$mat]['id'],
                'nombre'       => $nombreDe($enBD[$mat]),
                'id_grado'     => $idGradoDestino,
                'seccion'      => $it['seccion'],
                'grado_actual' => $gradoActual,
                'revuelto'     => $revuelto,
            ];
        }

        return [
            'listos'            => $listos,
            'yaActivos'         => $yaActivos, // ya estaban activos en este ciclo (se saltan)
            'noEncontradas'     => $noEncontradas,
            'gradoNoReconocido' => $gradoNoReconocido,
            'duplicadas'        => $duplicadas,
            'pendientes'        => $pendientes, // lista de filas sin matrícula (manual)
        ];
    }

    /**
     * Aplica la activación masiva en una transacción.
     * Replica EXACTAMENTE el update de recibe_excel.php (usr + calificacion + observaciones).
     *
     * @return array{ok:bool, activados:int, boletas:int, msg:string}
     */
    public function activarLote(array $listos, int $idCiclo)
    {
        if (empty($listos)) {
            return ['ok' => false, 'activados' => 0, 'boletas' => 0, 'msg' => 'No hay alumnos para activar.'];
        }

        $this->db->transStart();
        $activados = 0;
        $boletas   = 0;

        // Contraseña default CIFRADA (se calcula una sola vez, no en cada alumno).
        $passDefault = password_hash('123456789', PASSWORD_DEFAULT);

        foreach ($listos as $a) {
            $idUsr  = (int) $a['id_usr'];
            $idGrado = (int) $a['id_grado'];

            // 1. Activar alumno (igual que recibe_excel.php, pero con contraseña cifrada)
            $this->db->table('usr')->where('id', $idUsr)->update([
                'generacionactiva' => $idCiclo,
                'activo'           => 1,
                'estatus'          => 1,
                'grado'            => $idGrado,
                'pass'             => $passDefault,
            ]);
            $activados++;

            // 2. Calificaciones + 3. Observaciones
            $boletas += $this->generarBoletas($idUsr, $idGrado, $idCiclo);
        }

        // Dejar el ciclo nuevo como ACTIVO en el sistema (las 3 configs de nivel),
        // para que las boletas y calificaciones ya usen este ciclo por default.
        $this->db->table('mesycicloactivo')->update(['id_ciclo' => $idCiclo]);

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return ['ok' => false, 'activados' => 0, 'boletas' => 0, 'msg' => 'Error en la transacción; no se activó a nadie.'];
        }

        return [
            'ok'        => true,
            'activados' => $activados,
            'boletas'   => $boletas,
            'msg'       => "Se activaron $activados alumno(s), se generaron $boletas calificación(es) y el ciclo quedó como activo en el sistema.",
        ];
    }

    /**
     * Genera calificaciones (materia × mes) + observaciones (momentos + general),
     * igual que recibe_excel.php. Idempotente: no duplica si ya existen.
     * @return int filas de calificación insertadas
     */
    private function generarBoletas($idUsr, $idGrado, $idCiclo)
    {
        // IDEMPOTENCIA: si el alumno YA tiene calificaciones de este grado+ciclo,
        // no volver a generar (evita duplicados si se re-activa el mismo archivo).
        $yaExisten = $this->db->table('calificacion')
                              ->where('id_usr', $idUsr)
                              ->where('id_grado', $idGrado)
                              ->where('cicloEscolar', $idCiclo)
                              ->countAllResults();
        if ($yaExisten > 0) {
            return 0;
        }

        $meses = $this->mesesDelGrado($idGrado);
        if ($meses === 0) {
            return 0;
        }

        $materias = $this->db->table('materia')->select('Id_materia')->where('id_grados', $idGrado)->get()->getResultArray();
        if (empty($materias)) {
            return 0;
        }

        // --- Calificaciones (materia × mes) ---
        $lote = [];
        foreach ($materias as $m) {
            for ($i = 1; $i <= $meses; $i++) {
                $lote[] = [
                    'id_usr'       => $idUsr,
                    'id_materia'   => (int) $m['Id_materia'],
                    'id_mes'       => $i,
                    'cicloEscolar' => $idCiclo,
                    'id_grado'     => $idGrado,
                    'calificacion' => 0,
                ];
            }
        }
        if (!empty($lote)) {
            $this->db->table('calificacion')->ignore(true)->insertBatch($lote);
        }

        // --- Observaciones (una sola vez por alumno/grado/ciclo) ---
        $yaTiene = $this->db->table('calificacion_observaciones')
                            ->where('id_usr', $idUsr)
                            ->where('id_grado', $idGrado)
                            ->where('cicloEscolar', $idCiclo)
                            ->countAllResults();
        if ($yaTiene === 0) {
            // Momentos (mes <= 3, igual que el viejo)
            $momentos = $this->db->table('meses_calificacion')->select('mes')->where('mes <=', 3)->orderBy('mes', 'ASC')->get()->getResultArray();
            $obs = [];
            foreach ($momentos as $mo) {
                $obs[] = ['id_usr' => $idUsr, 'id_momento' => (int) $mo['mes'], 'id_grado' => $idGrado, 'cicloEscolar' => $idCiclo];
            }
            // Observación general (id_momento = 0)
            $obs[] = ['id_usr' => $idUsr, 'id_momento' => 0, 'id_grado' => $idGrado, 'cicloEscolar' => $idCiclo];
            if (!empty($obs)) {
                $this->db->table('calificacion_observaciones')->insertBatch($obs);
            }
        }

        return count($lote);
    }

    /**
     * Número de meses de calificación por grado.
     * Usa grados.meses_calificacion si está definido; si no, cae a los rangos históricos.
     */
    private function mesesDelGrado($idGrado)
    {
        $row = $this->db->table('grados')->select('meses_calificacion')->where('id_grado', $idGrado)->get()->getRow();
        if ($row && (int) $row->meses_calificacion > 0) {
            return (int) $row->meses_calificacion;
        }
        if (in_array($idGrado, [22,23,24,25,26,27,28,29,30,34,35,36], true)) return 10; // Primaria/Secundaria
        if (in_array($idGrado, [31,32,33], true)) return 6;                              // Bachillerato
        if (in_array($idGrado, [17,19,20,21], true)) return 3;                           // Maternal/Preescolar
        return 0;
    }
}
